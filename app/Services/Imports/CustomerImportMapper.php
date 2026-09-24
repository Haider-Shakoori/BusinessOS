<?php

namespace App\Services\Imports;

use App\Models\Customer;
use App\Support\Decimal;
use Illuminate\Support\Carbon;

/**
 * Create-only customer CSV import mapper (Batch 20).
 *
 * Field set mirrors StoreCustomerRequest exactly, so a CSV that passes preview
 * produces customers indistinguishable from the manual "Add customer" form.
 * Import adds nothing to the manual rules — the header must contain `name`,
 * every other registry field is optional.
 */
final class CustomerImportMapper extends ImportMapper
{
    public function resourceKey(): string
    {
        return 'customers';
    }

    /**
     * @return list<ImportColumn>
     */
    protected function columns(): array
    {
        return [
            new ImportColumn('name', 'customers.name', requiredInHeader: true, requiredPerRow: true),
            new ImportColumn('company_name', 'customers.company_name'),
            new ImportColumn('email', 'customers.email'),
            new ImportColumn('phone', 'customers.phone'),
            new ImportColumn('address', 'customers.address'),
            new ImportColumn('notes', 'customers.notes'),
            new ImportColumn('opening_balance', 'customers.opening_balance'),
            new ImportColumn('opening_balance_date', 'customers.opening_balance_date'),
        ];
    }

    /**
     * @param  array<string, string|null>  $values
     * @return list<string>
     */
    protected function validateRow(array &$values, int $rowNumber, int $businessId): array
    {
        $errors = [];

        if (mb_strlen((string) ($values['name'] ?? '')) > 100) {
            $errors[] = __('customers.validation.name_max');
        }

        if (($values['company_name'] ?? null) !== null && mb_strlen((string) $values['company_name']) > 100) {
            $errors[] = __('customers.validation.company_name_max');
        }

        if (($values['email'] ?? null) !== null) {
            if (! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('customers.validation.email_invalid');
            } elseif (mb_strlen((string) $values['email']) > 255) {
                $errors[] = __('customers.validation.email_max');
            }
        }

        if (($values['phone'] ?? null) !== null && mb_strlen((string) $values['phone']) > 30) {
            $errors[] = __('customers.validation.phone_max');
        }

        if (($values['address'] ?? null) !== null && mb_strlen((string) $values['address']) > 500) {
            $errors[] = __('customers.validation.address_max');
        }

        if (($values['notes'] ?? null) !== null && mb_strlen((string) $values['notes']) > 2000) {
            $errors[] = __('customers.validation.notes_max');
        }

        $balance = $values['opening_balance'] ?? null;

        if ($balance !== null) {
            if (! is_numeric($balance) || ! preg_match('/^\d{1,12}(\.\d{1,4})?$/', trim((string) $balance))) {
                $errors[] = __('customers.validation.opening_balance_invalid');
            } else {
                $values['opening_balance'] = Decimal::normalize(trim((string) $balance));
            }
        }

        $date = $values['opening_balance_date'] ?? null;

        if ($date !== null) {
            try {
                $values['opening_balance_date'] = Carbon::parse(trim((string) $date))->format('Y-m-d');
            } catch (\Throwable) {
                $errors[] = __('customers.validation.opening_balance_date_invalid');
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function createRow(array $values, int $businessId): void
    {
        $customer = new Customer($this->cleanValues($values));
        $customer->business_id = $businessId;
        $customer->save();
    }

    /**
     * Drop null values so optional absent columns behave exactly like omitted
     * form keys (the model's default/cast path handles the rest).
     *
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private function cleanValues(array $values): array
    {
        return array_filter($values, static fn ($value) => $value !== null);
    }
}
