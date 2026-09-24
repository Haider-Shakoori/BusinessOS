<?php

namespace App\Services\Imports;

/**
 * A single accepted CSV column definition (Batch 20).
 *
 * The header must reference these columns by their normalized key only —
 * there is deliberately no fuzzy matching. requiredInHeader columns must be
 * present for the file to be accepted at all; requiredPerRow columns must have
 * a non-blank value in every data row.
 */
final class ImportColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $requiredInHeader = false,
        public readonly bool $requiredPerRow = false,
    ) {
        //
    }
}
