<?php

namespace App\Jobs;

use App\Services\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Background execution of a large confirmed import (Batch 20).
 *
 * The upload is small and well-bounded, so only the row count that triggered
 * the queue threshold runs in a worker. The job re-validates the staged file
 * with the exact same ImportService::executeFile path a synchronous import
 * uses, so queued and sync imports are behaviorally identical: all-or-nothing,
 * create-only, business-id assigned server-side.
 *
 * Trade-off (documented in DECISIONS.md): a queued failure is logged, not
 * surfaced interactively — there is no import-jobs table by design. Operators
 * re-upload for a failed queue run.
 */
class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $relativePath,
        public readonly int $businessId,
        public readonly int $userId,
        public readonly string $type,
    ) {
        //
    }

    public function handle(ImportService $imports): void
    {
        $result = $imports->executeFile($this->relativePath, $this->businessId, $this->type);

        if (! $result->isSuccess()) {
            Log::error('Queued CSV import failed validation at execution time.', [
                'path' => $this->relativePath,
                'business_id' => $this->businessId,
                'user_id' => $this->userId,
                'type' => $this->type,
            ]);

            // No token exists to reference this file anymore; remove it so a
            // staging file never lingers on the private disk.
            Storage::disk('local')->delete($this->relativePath);
        }
    }
}
