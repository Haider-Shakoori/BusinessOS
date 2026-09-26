<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Global Permission Keys
    |--------------------------------------------------------------------------
    |
    | The initial permission catalogue (Batch 7). Permissions are system-defined
    | capability keys shared by ALL businesses (a single `settings.manage` row
    | powers every business). Permission keys use dot notation:
    |
    |     entity.action   e.g. users.view, settings.manage
    |
    | Only the small set required to establish the authorization framework is
    | declared here. Batch 8+ modules add their own permission keys later,
    | reusing this same granularity.
    |
    */

    'groups' => [
        'users' => [
            'users.view',
            'users.manage',
        ],
        'settings' => [
            'settings.view',
            'settings.manage',
        ],
        'customers' => [
            'customers.view',
            'customers.manage',
        ],
        'categories' => [
            'categories.view',
            'categories.manage',
        ],
        'units' => [
            'units.view',
            'units.manage',
        ],
        'taxes' => [
            'taxes.view',
            'taxes.manage',
        ],
        'products' => [
            'products.view',
            'products.manage',
        ],
        'quotations' => [
            'quotations.view',
            'quotations.manage',
        ],
        'invoices' => [
            'invoices.view',
            'invoices.manage',
        ],
        'payments' => [
            'payments.view',
            'payments.create',
            'payments.reverse',
        ],
        'expenses' => [
            'expenses.view',
            'expenses.manage',
        ],
        'inventory' => [
            'inventory.view',
            'inventory.manage',
        ],
        'purchasing' => [
            'purchasing.view',
            'purchasing.manage',
        ],
        'suppliers' => [
            'suppliers.view',
            'suppliers.manage',
        ],
        'accounting' => [
            'accounting.view',
            'accounting.manage',
        ],
        'pos' => [
            'pos.view',
            'pos.sell',
            'pos.manage',
        ],
        'crm' => [
            'crm.view',
            'crm.manage',
        ],
        'manufacturing' => [
            'manufacturing.view',
            'manufacturing.manage',
        ],
        'notifications' => [
            'notifications.view',
            'notifications.manage',
        ],
        'search' => [
            'search.use',
        ],
        'activity' => [
            'activity.view',
        ],
        'assistant' => [
            'assistant.use',
        ],
        'businesses' => [
            'businesses.view',
            'businesses.manage',
        ],
        'modules' => [
            'modules.view',
            'modules.manage',
        ],
        'hr' => [
            'hr.view',
            'hr.manage',
        ],
        'payroll' => [
            'payroll.view',
            'payroll.manage',
            'payroll.finalize',
        ],
        'reports' => [
            'reports.view',
        ],
    ],
];
