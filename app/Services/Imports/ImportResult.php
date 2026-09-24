<?php

namespace App\Services\Imports;

/**
 * Result of executing a confirmed import (Batch 20).
 */
final class ImportResult
{
    private function __construct(
        public readonly string $status,
        public readonly int $count,
    ) {
        //
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public static function success(int $count): self
    {
        return new self('success', $count);
    }

    /**
     * The import was dispatched to the queue; rows are created by the worker.
     */
    public static function queued(int $count): self
    {
        return new self('queued', $count);
    }

    /**
     * Validation failed at execution time (re-validated from the staging file,
     * never from client input). Nothing was created.
     */
    public static function failed(): self
    {
        return new self('failed', 0);
    }
}
