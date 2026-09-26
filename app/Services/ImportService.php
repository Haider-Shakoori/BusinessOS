<?php

namespace App\Services;

use App\Jobs\ProcessImportJob;
use App\Models\Setting;
use App\Services\Imports\CsvReader;
use App\Services\Imports\CustomerImportMapper;
use App\Services\Imports\ImportMapper;
use App\Services\Imports\ImportPreview;
use App\Services\Imports\ImportResult;
use App\Services\Imports\ImportRow;
use App\Services\Imports\ProductImportMapper;
use App\Services\Imports\SupplierImportMapper;
use App\Services\Imports\TooManyRowsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Create-only CSV import service (Batch 20).
 *
 * Two deliberately separated phases keep the data safe:
 *
 *  1. PREVIEW  — the staged file is parsed and every row validated. Nothing is
 *     written. The result is a pure read model (ImportPreview) the browser
 *     merely renders.
 *  2. EXECUTE  — the file is parsed and validated AGAIN from disk (never from
 *     client input), then all rows are created inside one transaction. Any
 *     invalid row blocks the whole import (all-or-nothing).
 *
 * Identity hardening:
 *  - The uploaded file lives under storage/app/private/imports/{business_id}/
 *    with an opaque generated name. The original filename/location is never
 *    trusted.
 *  - The browser only ever sees a random 64-char token bound in the SESSION to
 *    the user, the current business, the import type, and an expiry. A token
 *    from business A cannot be consumed in business B, a customer token cannot
 *    execute a product import, and a used-up token is a dead end.
 *  - Every reconciliation (preview, execute, cancel) re-checks the token's
 *    user/business/type binding against the CURRENT request context before
 *    touching the file.
 *
 * Large imports (above the configured threshold) re-dispatch the same
 * re-validated file to ProcessImportJob on the (database) queue; the sync
 * path runs in the request. The trade-off is documented in DECISIONS.md.
 */
class ImportService
{
    public const TOKEN_SESSION_KEY = 'import_tokens';

    /**
     * Build the mapper for the given import type and business. The product
     * mapper is told whether tax is enabled FOR THAT BUSINESS directly from
     * the settings table, so the decision is identical in the request and in a
     * queued job (where there is no "current business" session).
     */
    public function mapper(string $type, int $businessId): ImportMapper
    {
        return match ($type) {
            'customers' => new CustomerImportMapper,
            'products' => new ProductImportMapper($this->taxEnabledFor($businessId)),
            'suppliers' => new SupplierImportMapper,
            default => throw new RuntimeException("Unknown import type: {$type}."),
        };
    }

    public function supports(string $type): bool
    {
        return in_array($type, ['customers', 'products', 'suppliers'], true);
    }

    /**
     * Persist an uploaded CSV to the private imports directory. The
     * controller already enforced extension + MIME + size; this is the storage
     * layer's own belt-and-suspenders guard.
     */
    public function storeUpload(UploadedFile $file, int $businessId): string
    {
        // The controller enforces extension + MIME + size via the validator
        // (which probes real content). This is the storage layer's own belt
        // and suspenders check: a non-CSV extension can never reach disk.
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'csv') {
            throw new RuntimeException('Only .csv files may be imported.');
        }

        $name = Str::random(40).'.csv';

        $path = $file->storeAs('imports/'.$businessId, $name, 'local');

        if ($path === false) {
            throw new RuntimeException('Unable to store the uploaded CSV file.');
        }

        return $path;
    }

    /**
     * Create an opaque, session-bound token for a staged upload.
     *
     * A business keeps AT MOST ONE pending import per type at a time: any
     * previous non-expired token for the same user/business/type is retired and
     * its staged file discarded, so superseded uploads can never linger.
     */
    public function createToken(string $relativePath, int $businessId, int $userId, string $type): string
    {
        $tokens = $this->stagedTokens();
        $now = now()->timestamp;

        foreach ($tokens as $token => $payload) {
            $expired = ($payload['expires_at'] ?? 0) < $now;
            $superseded = ($payload['business_id'] ?? null) === $businessId
                && ($payload['user_id'] ?? null) === $userId
                && ($payload['type'] ?? null) === $type
                && ! $expired;

            if ($expired || $superseded) {
                unset($tokens[$token]);
                Storage::disk('local')->delete($payload['path'] ?? '');
            }
        }

        $token = Str::random(64);

        $tokens[$token] = [
            'path' => $relativePath,
            'business_id' => $businessId,
            'user_id' => $userId,
            'type' => $type,
            'expires_at' => now()->addMinutes((int) config('business.import.temp_ttl_minutes'))->timestamp,
        ];

        Session::put(self::TOKEN_SESSION_KEY, $tokens);

        return $token;
    }

    /**
     * Resolve a token payload, validating it against the CURRENT request's
     * user/business/type. Any mismatch (a cross-business or cross-type attempt,
     * or an expired token) destroys the token AND its staged file: a token
     * that fails its binding is never left around to be retried.
     *
     * @return array{path: string, business_id: int, user_id: int, type: string, expires_at: int}|null
     */
    public function tokenPayload(string $token, int $businessId, int $userId, string $type): ?array
    {
        $tokens = $this->stagedTokens();
        $payload = $tokens[$token] ?? null;

        if ($payload === null) {
            return null;
        }

        $valid = ($payload['business_id'] ?? null) === $businessId
            && ($payload['user_id'] ?? null) === $userId
            && ($payload['type'] ?? null) === $type
            && ($payload['expires_at'] ?? 0) >= now()->timestamp;

        if (! $valid || ! Storage::disk('local')->exists($payload['path'])) {
            $this->forget($token, deleteFile: true);

            return null;
        }

        return $payload;
    }

    /**
     * Validate a staged import without writing anything.
     */
    public function preview(string $token, int $businessId, int $userId, string $type): ?ImportPreview
    {
        $payload = $this->tokenPayload($token, $businessId, $userId, $type);

        if ($payload === null) {
            return null;
        }

        $mapper = $this->mapper($type, $businessId);

        return $this->scan($this->absolutePath($payload['path']), $mapper, $businessId);
    }

    /**
     * Execute a confirmed import: re-validate the staged file, then create all
     * rows in a single transaction. Never trusts anything the browser sent
     * since the upload.
     */
    public function execute(string $token, int $businessId, int $userId, string $type): ImportResult
    {
        $payload = $this->tokenPayload($token, $businessId, $userId, $type);

        if ($payload === null) {
            return ImportResult::failed();
        }

        $rows = $this->rowCount($payload['path']);

        if ($rows > (int) config('business.import.queue_threshold')) {
            ProcessImportJob::dispatch($payload['path'], $businessId, $userId, $type);

            $this->forget($token, deleteFile: false);

            return ImportResult::queued($rows);
        }

        $result = $this->executeFile($payload['path'], $businessId, $type);

        if ($result->isSuccess()) {
            $this->forget($token, deleteFile: false); // executeFile already removed the file
        }

        return $result;
    }

    /**
     * The actual execution unit — revalidates, then creates all rows
     * atomically. Used by the sync path and by ProcessImportJob alike.
     */
    public function executeFile(string $relativePath, int $businessId, string $type): ImportResult
    {
        $mapper = $this->mapper($type, $businessId);
        $preview = $this->scan($this->absolutePath($relativePath), $mapper, $businessId);

        if (! $preview->headerValid || $preview->invalidRows > 0) {
            return ImportResult::failed();
        }

        $count = 0;

        DB::transaction(function () use ($mapper, $preview, $businessId, &$count): void {
            foreach ($preview->rows as $row) {
                $mapper->createRow($row->values, $businessId);
                $count++;
            }
        });

        Storage::disk('local')->delete($relativePath);

        return ImportResult::success($count);
    }

    /**
     * Abandon a staged import: the file is deleted and the token invalidated.
     * Returns false when the token could not be resolved.
     */
    public function cancel(string $token, int $businessId, int $userId, string $type): bool
    {
        $payload = $this->tokenPayload($token, $businessId, $userId, $type);

        if ($payload === null) {
            return false;
        }

        $this->forget($token, deleteFile: true);

        return true;
    }

    /**
     * The download-ready CSV template for an import type.
     */
    public function template(string $type): string
    {
        return $this->mapper($type, -1)->template();
    }

    public function absolutePath(string $relativePath): string
    {
        return Storage::disk('local')->path($relativePath);
    }

    /**
     * Number of data rows in a staged file (header excluded). Enforced row
     * cap returns PHP_INT_MAX so the queue threshold is never bypassed by an
     * oversized file.
     */
    public function rowCount(string $relativePath): int
    {
        $reader = new CsvReader((int) config('business.import.max_rows'));
        $count = 0;

        try {
            foreach ($reader->stream($this->absolutePath($relativePath)) as $_) {
                $count++;
            }
        } catch (TooManyRowsException) {
            return PHP_INT_MAX;
        }

        return $count;
    }

    /**
     * @return array<string, array{path: string, business_id: int, user_id: int, type: string, expires_at: int}>
     */
    private function stagedTokens(): array
    {
        return (array) Session::get(self::TOKEN_SESSION_KEY, []);
    }

    /**
     * Parse + validate a staged file into an ImportPreview. The header is
     * validated first; when it fails, no row is read (columns are unknown).
     * Otherwise every row is mapped and validated, so counts reflect the WHOLE
     * file even though the UI only renders a few rows.
     */
    private function scan(string $absolutePath, ImportMapper $mapper, int $businessId): ImportPreview
    {
        $reader = new CsvReader((int) config('business.import.max_rows'));

        $headerCells = $reader->headerCells($absolutePath);
        $headerErrors = $mapper->validateHeader($headerCells);

        $header = array_map(
            static fn (string $cell) => $mapper->normalizeHeaderCell($cell),
            $headerCells,
        );

        if ($headerErrors !== []) {
            return new ImportPreview(false, $headerErrors, $header, [], 0, 0, 0, []);
        }

        $rows = [];
        $rowErrors = [];
        $invalidRows = 0;
        $total = 0;

        try {
            foreach ($reader->stream($absolutePath) as $lineNo => $cells) {
                $total++;

                [$status, $errors, $values] = array_values($mapper->readRow($cells, $headerCells, $lineNo, $businessId));

                $rows[] = new ImportRow($lineNo, $values, $errors);

                foreach ($errors as $error) {
                    $rowErrors[] = __('imports.row_error', ['row' => $lineNo, 'message' => $error]);
                }

                if (! $status) {
                    $invalidRows++;
                }
            }
        } catch (TooManyRowsException) {
            $invalidRows++;
            $rowErrors[] = __('imports.too_many_rows', ['limit' => (int) config('business.import.max_rows')]);
        }

        return new ImportPreview(
            headerValid: true,
            headerErrors: [],
            header: $header,
            rows: $rows,
            totalRows: $total,
            validRows: max(0, $total - $invalidRows),
            invalidRows: $invalidRows,
            rowErrors: $rowErrors,
        );
    }

    /**
     * Business-owned boolean override for general.tax_enabled, read directly
     * from the settings table so the result is identical in requests and in
     * queued jobs (which have no "current business" session).
     */
    private function taxEnabledFor(int $businessId): bool
    {
        $stored = Setting::query()
            ->where('business_id', $businessId)
            ->where('group', 'general')
            ->where('key', 'tax_enabled')
            ->value('value');

        if ($stored !== null) {
            return $stored === '1';
        }

        return (bool) config('settings.definitions.general.tax_enabled.default');
    }

    private function forget(string $token, bool $deleteFile): void
    {
        $tokens = $this->stagedTokens();

        if (! isset($tokens[$token])) {
            return;
        }

        if ($deleteFile) {
            Storage::disk('local')->delete($tokens[$token]['path']);
        }

        unset($tokens[$token]);
        Session::put(self::TOKEN_SESSION_KEY, $tokens);
    }
}
