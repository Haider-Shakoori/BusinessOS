<?php

namespace App\Services\Imports;

/**
 * One parsed data row from a staged CSV (Batch 20).
 */
final class ImportRow
{
    /**
     * @param  int  $number  physical 1-based line number (header = 1)
     * @param  array<string, string|null>  $values  normalized column key => value
     * @param  list<string>  $errors  human-readable row errors
     */
    public function __construct(
        public readonly int $number,
        public readonly array $values,
        public readonly array $errors,
    ) {
        //
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
