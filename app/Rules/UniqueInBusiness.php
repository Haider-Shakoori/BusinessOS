<?php

namespace App\Rules;

use App\Services\BusinessContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Business-scoped uniqueness that respects soft deletes.
 *
 * The rule checks the given table for an active (non-soft-deleted) row with the
 * same column value inside the CURRENT business only — same names across
 * different businesses are always allowed, duplicates inside one business are
 * rejected, and a soft-deleted row never blocks re-creating the same value.
 */
class UniqueInBusiness implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column = 'name',
        private readonly ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $businessId = app(BusinessContext::class)->currentId();

        if ($businessId === null) {
            return;
        }

        $query = DB::table($this->table)
            ->where('business_id', $businessId)
            ->where($this->column, $value)
            ->whereNull('deleted_at');

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail('validation.unique');
        }
    }
}
