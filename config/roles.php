<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Role Catalogue
    |--------------------------------------------------------------------------
    |
    | Roles are business-scoped: every business receives this minimal default
    | set when it is created (Business::provisionDefaultRoles). There are no
    | global/system roles and no super-admin platform roles in this batch.
    |
    | The business creator is automatically granted the role named by
    | `owner_role`. Each role maps to the globally-shared permission keys above.
    |
    */

    'owner_role' => 'owner',

    'default_roles' => [
        'owner' => [
            'name' => 'Owner',
            'is_system' => true,
            'permissions' => [
                'users.view',
                'users.manage',
                'settings.view',
                'settings.manage',
                'customers.view',
                'customers.manage',
                'categories.view',
                'categories.manage',
                'units.view',
                'units.manage',
                'taxes.view',
                'taxes.manage',
                'products.view',
                'products.manage',
                'quotations.view',
                'quotations.manage',
                'invoices.view',
                'invoices.manage',
                'payments.view',
                'payments.create',
                'payments.reverse',
                'expenses.view',
                'expenses.manage',
            ],
        ],
        'admin' => [
            'name' => 'Admin',
            'is_system' => true,
            'permissions' => [
                'users.view',
                'users.manage',
                'settings.view',
                'settings.manage',
                'customers.view',
                'customers.manage',
                'categories.view',
                'categories.manage',
                'units.view',
                'units.manage',
                'taxes.view',
                'taxes.manage',
                'products.view',
                'products.manage',
                'quotations.view',
                'quotations.manage',
                'invoices.view',
                'invoices.manage',
                'payments.view',
                'payments.create',
                'payments.reverse',
                'expenses.view',
                'expenses.manage',
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'is_system' => true,
            'permissions' => [
                'users.view',
                'settings.view',
                'customers.view',
                'invoices.view',
                'payments.view',
                'expenses.view',
            ],
        ],
    ],
];
