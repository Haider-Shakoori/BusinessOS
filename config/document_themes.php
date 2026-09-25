<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Static document theme registry
    |--------------------------------------------------------------------------
    |
    | Theme keys may be persisted in business settings, but Blade view names
    | never are. This is the security boundary: user-controlled values can only
    | select a pre-registered, code-reviewed template.
    |
    */

    'default_accent' => '#2563EB',

    'documents' => [
        'invoice' => [
            'default' => 'modern',
            'setting' => 'document.invoice_theme',
            'themes' => [
                'modern' => [
                    'label' => 'documents.themes.modern',
                    'view' => 'documents.invoices.modern',
                ],
                'minimal' => [
                    'label' => 'documents.themes.minimal',
                    'view' => 'documents.invoices.minimal',
                ],
            ],
        ],
        'quotation' => [
            'default' => 'modern',
            'setting' => 'document.invoice_theme',
            'themes' => [
                'modern' => [
                    'label' => 'documents.themes.modern',
                    'view' => 'documents.quotations.modern',
                ],
                'minimal' => [
                    'label' => 'documents.themes.minimal',
                    'view' => 'documents.quotations.minimal',
                ],
            ],
        ],
        'statement' => [
            'default' => 'modern',
            'setting' => 'document.invoice_theme',
            'themes' => [
                'modern' => [
                    'label' => 'documents.themes.modern',
                    'view' => 'documents.statements.modern',
                ],
                'minimal' => [
                    'label' => 'documents.themes.minimal',
                    'view' => 'documents.statements.minimal',
                ],
            ],
        ],
    ],
];
