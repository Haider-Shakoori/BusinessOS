<?php

namespace App\Services\Imports;

/**
 * Read-only result of validating a staged CSV (Batch 20).
 *
 * A preview never writes the database — it is a pure parse-and-validate walk
 * of the server-owned staging file, exactly so the user can review totals and
 * row errors before anything is created.
 */
final class ImportPreview
{
    /**
     * @param  bool  $headerValid  header is valid (empty $headerErrors)
     * @param  list<string>  $headerErrors  header-level errors, if any
     * @param  list<string>  $header  normalized header keys in file order
     * @param  list<ImportRow>  $rows  data rows (already validated)
     * @param  list<string>  $rowErrors  "Row N — message" strings for all invalid rows
     */
    public function __construct(
        public readonly bool $headerValid,
        public readonly array $headerErrors,
        public readonly array $header,
        public readonly array $rows,
        public readonly int $totalRows,
        public readonly int $validRows,
        public readonly int $invalidRows,
        public readonly array $rowErrors,
    ) {
        //
    }

    public function importable(): bool
    {
        return $this->headerValid && $this->invalidRows === 0 && $this->totalRows > 0;
    }
}
