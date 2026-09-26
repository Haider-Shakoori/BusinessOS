<?php

namespace App\Services\Imports;

use App\Models\Supplier;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class SupplierImportMapper extends ImportMapper
{
    /** @var array<string, true> */
    private array $seenCodes = [];

    public function resourceKey(): string
    {
        return 'suppliers';
    }

    /**
     * @return list<ImportColumn>
     */
    protected function columns(): array
    {
        return [
            new ImportColumn('code', 'suppliers.code'),
            new ImportColumn('name', 'suppliers.name', requiredInHeader: true, requiredPerRow: true),
            new ImportColumn('email', 'suppliers.email'),
            new ImportColumn('phone', 'suppliers.phone'),
            new ImportColumn('address', 'suppliers.address'),
            new ImportColumn('opening_balance', 'suppliers.opening_balance'),
            new ImportColumn('opening_balance_date', 'suppliers.opening_balance_date'),
            new ImportColumn('notes', 'suppliers.notes'),
        ];
    }

    /**
     * @param  array<string, string|null>  $values
     * @return list<string>
     */
    protected function validateRow(array &$values, int $rowNumber, int $businessId): array
    {
        $errors = [];

        if (mb_strlen((string) ($values['name'] ?? '')) > 255) {
            $errors[] = __('suppliers.validation.name_max');
        }

        if (($values['code'] ?? null) !== null) {
            $code = Str::upper((string) $values['code']);

            if (mb_strlen($code) > 50 || preg_match('/^[A-Z0-9_-]+$/', $code) !== 1) {
                $errors[] = __('suppliers.validation.code_invalid');
            } elseif (isset($this->seenCodes[$code])) {
                $errors[] = __('suppliers.validation.code_duplicate_file');
            } elseif (Supplier::withoutGlobalScope('business')
                ->where('business_id', $businessId)
                ->where('code', $code)
                ->exists()) {
                $errors[] = __('suppliers.validation.code_unique');
            }

            if (! in_array(__('suppliers.validation.code_invalid'), $errors, true)
                && ! in_array(__('suppliers.validation.code_duplicate_file'), $errors, true)
                && ! in_array(__('suppliers.validation.code_unique'), $errors, true)) {
                $this->seenCodes[$code] = true;
            }

            $values['code'] = $code;
        }

        if (($values['email'] ?? null) !== null) {
            if (! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('suppliers.validation.email_invalid');
            } elseif (mb_strlen((string) $values['email']) > 255) {
                $errors[] = __('suppliers.validation.email_max');
            }
        }

        if (($values['phone'] ?? null) !== null && mb_strlen((string) $values['phone']) > 100) {
            $errors[] = __('suppliers.validation.phone_max');
        }

        if (($values['address'] ?? null) !== null && mb_strlen((string) $values['address']) > 2000) {
            $errors[] = __('suppliers.validation.address_max');
        }

        if (($values['notes'] ?? null) !== null && mb_strlen((string) $values['notes']) > 4000) {
            $errors[] = __('suppliers.validation.notes_max');
        }

        $balance = $values['opening_balance'] ?? null;

        if ($balance !== null) {
            if (! is_numeric($balance) || ! preg_match('/^\d{1,12}(\.\d{1,4})?$/', trim((string) $balance))) {
                $errors[] = __('suppliers.validation.opening_balance_invalid');
            } else {
                $values['opening_balance'] = Decimal::normalize(trim((string) $balance));
            }
        }

        $date = $values['opening_balance_date'] ?? null;

        if ($date !== null) {
            try {
                $values['opening_balance_date'] = Carbon::parse(trim((string) $date))->format('Y-m-d');
            } catch (\Throwable) {
                $errors[] = __('suppliers.validation.opening_balance_date_invalid');
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function createRow(array $values, int $businessId): void
    {
        $clean = array_filter($values, static fn ($value) => $value !== null);

        $supplier = new Supplier($clean);
        $supplier->business_id = $businessId;
        $supplier->is_active = true;
        $supplier->save();

        if (empty($supplier->code)) {
            $supplier->code = 'SUP-'.str_pad((string) $supplier->id, 6, '0', STR_PAD_LEFT);
            $supplier->save();
        }
    }
}
