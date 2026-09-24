<?php

return [
    'currency' => 'Currency',
    'base_amount' => 'Amount (:currency)',
    'rate_applied' => '1 :currency = :rate :base',

    'status_enabled' => 'Currency :currency enabled.',
    'status_disabled' => 'Currency :currency disabled.',
    'status_rate_saved' => 'Exchange rate for :currency saved.',
    'status_rate_deleted' => 'Exchange rate for :currency deleted.',

    'validation' => [
        'invalid' => 'The selected currency is not valid.',
        'required' => 'A currency is required.',
        'rate_missing' => 'No exchange rate is recorded for :currency on :date. Set one in Settings → Currencies first.',
        'is_base' => 'The base currency is always enabled and cannot be disabled.',
        'already_enabled' => 'This currency is already enabled.',
        'not_enabled' => 'Enable this currency in Settings before recording rates for it.',
        'prohibited' => 'Payments are always recorded in the invoice’s own currency.',
        'rate_required' => 'The exchange rate is required.',
        'rate_invalid' => 'The exchange rate must be a valid number with up to 8 decimals.',
        'rate_positive' => 'The exchange rate must be greater than zero.',
        'effective_date_required' => 'The effective date is required.',
        'effective_date_invalid' => 'The effective date must be a valid date.',
    ],
];
