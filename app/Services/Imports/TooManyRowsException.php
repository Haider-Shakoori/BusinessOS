<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * Thrown while streaming a CSV once the configured hard row cap is exceeded.
 *
 * The reader never loads the whole file into memory, so this is the safety
 * valve that keeps a single Preview/Execute request from processing an
 * unbounded staging file (shared-hosting friendly limit, Batch 20).
 */
final class TooManyRowsException extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("The CSV file has more than {$limit} rows.");
    }
}
