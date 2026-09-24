<?php

namespace App\Services\Imports;

/**
 * Base contract + shared machinery for create-only CSV imports (Batch 20).
 *
 * Concrete mappers (customers, products) declare the accepted column set and
 * the per-row validation/normalization. Everything header-related and the
 * streaming walk glue lives here so the two mappers cannot drift in their
 * handling of BOMs, blank lines, column-count mismatches, or unknown headers.
 *
 * Rules that apply to both imports:
 *  - Exact normalized header matching only (no fuzzy mapping, no auto-rename).
 *  - A column present in the file but not in the accepted set is an error.
 *  - A required column missing from the header is an error.
 *  - A row with fewer/more cells than the header is an error.
 *  - Values are trimmed; blank becomes null. Unicode and leading zeros are
 *    preserved — nothing is silently repaired or truncated.
 */
abstract class ImportMapper
{
    /**
     * @return list<ImportColumn>
     */
    abstract protected function columns(): array;

    /**
     * Per-row validation + normalization for a concrete import.
     *
     * @param  array<string, string|null>  $values  trimmed values keyed by column key
     * @return list<string> human-readable row errors (empty = valid)
     */
    abstract protected function validateRow(array &$values, int $rowNumber, int $businessId): array;

    /**
     * Persist the single validated row for the given business. This is a
     * trusted internal path (server-side, post-validation), so business_id is
     * assigned explicitly rather than resolved from the request.
     *
     * @param  array<string, string|null>  $values
     */
    abstract public function createRow(array $values, int $businessId): void;

    /**
     * which permission-gated module this import belongs to ('customers'|'products').
     */
    abstract public function resourceKey(): string;

    /**
     * @return array<string, ImportColumn> normalized key => column
     */
    final public function columnMap(): array
    {
        $map = [];

        foreach ($this->columns() as $column) {
            $map[$column->key] = $column;
        }

        return $map;
    }

    /**
     * @return list<string> header keys in template order
     */
    final public function templateHeader(): array
    {
        return array_map(
            static fn (ImportColumn $column) => $column->key,
            $this->columns(),
        );
    }

    /**
     * @return array<string, string> normalized key => translated column label
     */
    final public function headerLabels(): array
    {
        $labels = [];

        foreach ($this->columns() as $column) {
            $labels[$column->key] = __($column->label);
        }

        return $labels;
    }

    /**
     * Build the downloadable template: a single header row, UTF-8 with BOM so
     * Excel opens it correctly. Spreadsheet's row 1 is exactly our header, so
     * "Row N" errors always line up with the user's file.
     */
    final public function template(): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $this->templateHeader());
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return CsvReader::UTF8_BOM.$csv;
    }

    /**
     * Normalize a header cell for matching: BOM strip + trim + lowercase.
     * Deliberately NOT fuzzy — after this deterministic normalization the key
     * must exist in the accepted set exactly.
     */
    final public function normalizeHeaderCell(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, CsvReader::UTF8_BOM)) {
            $value = substr($value, strlen(CsvReader::UTF8_BOM));
        }

        return mb_strtolower($value);
    }

    /**
     * Validate the parsed header cells. An unknown column, a duplicate column,
     * or a missing required column each yield one error; any error means the
     * file cannot be previewed or executed.
     *
     * @param  list<string>  $cells
     * @return list<string>
     */
    final public function validateHeader(array $cells): array
    {
        $errors = [];
        $seen = [];
        $columns = $this->columnMap();

        if ($cells === []) {
            return [__('imports.header.empty')];
        }

        foreach ($cells as $cell) {
            $key = $this->normalizeHeaderCell($cell);

            if ($key === '') {
                $errors[] = __('imports.header.blank');

                continue;
            }

            if (! isset($columns[$key])) {
                $errors[] = __('imports.header.unknown', ['column' => $cell]);

                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = __('imports.header.duplicate', ['column' => $cell]);

                continue;
            }

            $seen[$key] = true;
        }

        foreach ($columns as $column) {
            if ($column->requiredInHeader && ! isset($seen[$column->key])) {
                $errors[] = __('imports.header.missing', ['column' => __($column->label)]);
            }
        }

        return $errors;
    }

    /**
     * Map + validate one data row against the validated header.
     *
     * @param  list<string>  $cells  row cells from fgetcsv
     * @param  list<string>  $headerCells  header cells as parsed by CsvReader
     * @return array{ok: bool, errors: list<string>, values: array<string, string|null>}
     */
    final public function readRow(array $cells, array $headerCells, int $rowNumber, int $businessId): array
    {
        $columns = $this->columnMap();

        if (count($cells) !== count($headerCells)) {
            return [
                'ok' => false,
                'errors' => [__('imports.row.column_count', [
                    'expected' => count($headerCells),
                    'actual' => count($cells),
                ])],
                'values' => [],
            ];
        }

        $values = [];

        foreach ($headerCells as $index => $cell) {
            $key = $this->normalizeHeaderCell($cell);

            if (! isset($columns[$key])) {
                continue;
            }

            $raw = $cells[$index] ?? '';

            $value = trim((string) $raw);

            $values[$key] = $value === '' ? null : $value;
        }

        $errors = [];

        foreach ($this->columns() as $column) {
            if ($column->requiredPerRow && ($values[$column->key] ?? null) === null) {
                $errors[] = __('imports.row.required', ['label' => __($column->label)]);
            }
        }

        $errors = array_merge($errors, $this->validateRow($values, $rowNumber, $businessId));

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'values' => $values,
        ];
    }
}
