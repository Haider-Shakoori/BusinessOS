<?php

namespace App\Services\Imports;

use App\Models\Supplier;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Throwable;

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
            new ImportColumn('code', 'suppliers.fields.code'),
            new ImportColumn('name', 'suppliers.fields.name', requiredInHeader: true, requiredPerRow: true),
            new ImportColumn('email', 'suppliers.fields.email'),
            new ImportColumn('phone', 'suppliers.fields.phone'),
            new ImportColumn('address', 'suppliers.fields.address'),
            new ImportColumn('opening_balance', 'suppliers.fields.opening_balance'),
            new ImportColumn('opening_balance_date', 'suppliers.fields.opening_balance_date'),
            new ImportColumn('notes', 'suppliers.fields.notes'),
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

        $code = $values['code'] ?? null;
        if ($code !== null) {
            if (mb_strlen($code) > 50) {
                $errors[] = __('suppliers.validation.code_max');
            } elseif (isset($this->seenCodes[$code])) {
                $errors[] = __('suppliers.validation.code_duplicate_file');
            } elseif (Supplier::query()->withoutGlobalScope('business')
                ->where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->where('code', $code)
                ->exists()) {
                $errors[] = __('suppliers.validation.code_unique');
            } else {
                $this->seenCodes[$code] = true;
            }
        }

        if (($values['email'] ?? null) !== null && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('suppliers.validation.email_invalid');
        }

        if (($values['phone'] ?? null) !== null && mb_strlen((string) $values['phone']) > 100) {
            $errors[] = __('suppliers.validation.phone_max');
        }

        if (($values['address'] ?? null) !== null && mb_strlen((string) $values['address']) > 2000) {
            $errors[] = __('suppliers.validation.address_max');
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
                $values['opening_balance_date'] = Carbon::parse($date)->format('Y-m-d');
            } catch (Throwable) {
                $errors[] = __('suppliers.validation.opening_balance_date_invalid');
            }
        }

        if (($values['notes'] ?? null) !== null && mb_strlen((string) $values['notes']) > 4000) {
            $errors[] = __('suppliers.validation.notes_max');
        }

        return $errors;
    }

    /**
     * @param array<string,string|null> $values
     */
    public function createRow(array $values, int $businessId): void
    {
        $supplier = new Supplier(array_filter($values, static fn ($value) => $value !== null));
        $supplier->business_id = $businessId;
        $supplier->is_active = true;
        $supplier->save();
    }
}
