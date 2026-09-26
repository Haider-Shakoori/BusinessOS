<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Business Settings — Definitions & Defaults (Batch 9)
    |--------------------------------------------------------------------------
    |
    | Each supported business setting is defined here as a dot-keyed
    | `group.key` entry with a config default and a storage type. Only keys
    | listed here may be read as first-class settings or persisted by the
    | BusinessSettings service — unknown keys are never stored (no typo
    | settings, no arbitrary configuration injection).
    |
    | Sparse overrides: a business only stores rows that deviate from these
    | defaults. Reads resolve config default first, then a database override,
    | so a newly created business needs zero settings rows.
    |
    | Scope: Batch 9 ships General (business profile) + Regional only. Tax,
    | inventory, POS, accounting and SaaS settings arrive with their own
    | modules — do not add keys for them here. The `numbering` group is
    | Batch 13: overrides for the document numbering prefixes and padding,
    | read by DocumentNumberService. The defaults live in config/numbering.php;
    | these definitions are sparse (default null) so a business that does not
    | override stores nothing and falls back to the code defaults.
    |
    | Batch 19 adds regional.currency — the business BASE currency (AFN by
    | default in this project context). It is read by CurrencyService for
    | exchange-rate resolution and base_amount conversion and lives here so a
    | fresh business needs zero configuration rows.
    |
    */

    'definitions' => [
        'general' => [
            'address' => ['default' => null, 'type' => 'string'],
            'phone' => ['default' => null, 'type' => 'string'],
            'email' => ['default' => null, 'type' => 'string'],
            'industry' => ['default' => null, 'type' => 'string'],
            'country' => ['default' => null, 'type' => 'string'],
            'tax_enabled' => ['default' => false, 'type' => 'boolean'],
        ],

        'regional' => [
            'timezone' => ['default' => 'UTC',   'type' => 'string'],
            'date_format' => ['default' => 'Y-m-d', 'type' => 'string'],
            'time_format' => ['default' => 'H:i',   'type' => 'string'],
            'locale' => ['default' => null,    'type' => 'string'],
            'currency' => ['default' => 'AFN', 'type' => 'string'],
        ],

        'ui' => [
            'appearance' => ['default' => 'light', 'type' => 'string'],
        ],

        'attendance' => [
            'enabled' => ['default' => false, 'type' => 'boolean'],
            'payroll_source' => ['default' => 'attendance', 'type' => 'string'],
            'auto_sync_minutes' => ['default' => 5, 'type' => 'integer'],
            'require_employee_mapping' => ['default' => true, 'type' => 'boolean'],
        ],

        'numbering' => [
            'quotation_prefix' => ['default' => null, 'type' => 'string'],
            'invoice_prefix' => ['default' => null, 'type' => 'string'],
            'payment_prefix' => ['default' => null, 'type' => 'string'],
            'expense_prefix' => ['default' => null, 'type' => 'string'],
            'purchase_order_prefix' => ['default' => null, 'type' => 'string'],
            'padding' => ['default' => null, 'type' => 'integer'],
        ],

        'document' => [
            'invoice_theme' => ['default' => 'modern', 'type' => 'string'],
            'logo_path' => ['default' => null, 'type' => 'string'],
            'accent_color' => ['default' => '#2563EB', 'type' => 'string'],
            'header_text' => ['default' => null, 'type' => 'string'],
            'footer_text' => ['default' => null, 'type' => 'string'],
            'terms' => ['default' => null, 'type' => 'string'],
            'bank_details' => ['default' => null, 'type' => 'string'],
            'signature_line' => ['default' => null, 'type' => 'string'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Form Options
    |--------------------------------------------------------------------------
    |
    | Restricted value lists shared by the Settings UI and its validation.
    | Date/time formats are limited to approved PHP format strings. The locale
    | list mirrors config('localization.supported').
    |
    */

    'options' => [
        'date_formats' => [
            'Y-m-d' => 'Y-m-d',
            'd/m/Y' => 'd/m/Y',
            'm/d/Y' => 'm/d/Y',
            'j F Y' => 'j F Y',
        ],

        'time_formats' => [
            'H:i' => 'H:i',
            'g:i A' => 'g:i A',
        ],

        'attendance_sync_intervals' => [
            1 => '1 minute',
            5 => '5 minutes',
            10 => '10 minutes',
            15 => '15 minutes',
            30 => '30 minutes',
            60 => '60 minutes',
        ],
    ],
];
