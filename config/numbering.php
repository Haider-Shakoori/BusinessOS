<?php

/*
|--------------------------------------------------------------------------
| Document Numbering — Defaults (Batch 13)
|--------------------------------------------------------------------------
|
| Default prefixes and padding for the per-business, per-document-type
| monotonic numbering sequences. Any business may override these through the
| `numbering` settings group (sparse overrides stored in the settings table).
| Only keys defined in config('settings.definitions.numbering') are eligible
| for overrides; everything here is a fallback for businesses that did not
| override.
|
| Prefixes must match the prefix_pattern; an override that does not is a
| configuration error and the service refuses to allocate rather than emit a
| malformed document number.
|
*/

return [
    'prefixes' => [
        'quotation' => 'QUO',
        'invoice' => 'INV',
        'payment' => 'PAY',
        'expense' => 'EXP',
        'purchase_order' => 'PO',
        'pos_sale' => 'POS',
        'payroll_run' => 'PRL',
        'warehouse_transfer' => 'TRF',
        'inventory_return' => 'RET',
    ],

    'padding' => 6,

    'prefix_pattern' => '/^[A-Z0-9_-]+$/',

    'max_prefix_length' => 10,

    'max_padding' => 12,
];
