<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Module Registry (Batch 8)
    |--------------------------------------------------------------------------
    |
    | Each registered application module is defined here as a stable,
    | code-backed definition. Module enablement per business is stored in the
    | `business_modules` database table — NOT duplicated as master rows.
    |
    | The `label` value is a translation key resolved at runtime (never
    | hardcoded module names in the shell). Modules without a `navigation`
    | key are invisible in the sidebar and are checked only via middleware.
    |
    | `permission` (optional): when set, the module's navigation entry is
    | shown only when the user holds this permission AND the module is
    | enabled. The route middleware (`module:X`) does NOT enforce the
    | permission — that is the job of the separate `permission:X` middleware.
    |
    */

    'registry' => [
        'dashboard' => [
            'key' => 'dashboard',
            'label' => 'modules.dashboard',
            'icon' => 'chart-bar',
            'navigation' => [
                'group' => 'overview',
                'order' => 1,
                'route' => 'app.home',
            ],
        ],
        'customers' => [
            'key' => 'customers',
            'label' => 'modules.customers',
            'icon' => 'users',
            'navigation' => [
                'group' => 'business',
                'order' => 1,
                'route' => 'customers.index',
            ],
            'permission' => 'customers.view',
        ],
        'sales' => [
            'key' => 'sales',
            'label' => 'modules.sales',
            'icon' => 'document-text',
            'navigation' => [
                'group' => 'business',
                'order' => 2,
                // Batch 15: the Sales module is a document hub rendered as
                // navigation children (repository behaviour: modules without a
                // `route` render children only). Each document type is its own
                // entry gated by its own read permission:
                //   - Quotations -> quotations.index (quotations.view)
                //   - Invoices   -> invoices.index   (invoices.view)
                //   - Payments   -> payments.index   (payments.view)
                // There is intentionally NO module-level `permission` guard
                // here because the children carry different read permissions;
                // the module availability (module:sales middleware +
                // ModuleManager) still gates all of them. A Viewer therefore
                // sees Invoices + Payments without Quotations, and an Owner
                // sees all three. Payments is not its own module — it is a
                // child of the Sales hub (Batch 16), gated by the sales module.
                'children' => [
                    [
                        'label' => 'modules.quotations',
                        'icon' => 'document-text',
                        'route' => 'quotations.index',
                        'permission' => 'quotations.view',
                    ],
                    [
                        'label' => 'modules.invoices',
                        'icon' => 'receipt-percent',
                        'route' => 'invoices.index',
                        'permission' => 'invoices.view',
                    ],
                    [
                        'label' => 'modules.payments',
                        'icon' => 'banknotes',
                        'route' => 'payments.index',
                        'permission' => 'payments.view',
                    ],
                ],
            ],
        ],
        'products' => [
            'key' => 'products',
            'label' => 'modules.products',
            'icon' => 'banknotes',
            'permission' => 'products.view',
            'navigation' => [
                'group' => 'business',
                'order' => 3,
                'route' => 'products.index',
                // Batch 11: catalog reference data ships under the products
                // module (MODULES.md "Catalog" category). Each child is its own
                // navigation entry with its own permission; the Taxes entry is
                // additionally gated on the optional-tax feature setting.
                // Batch 12: the products module itself now owns a registry page
                // (products.index) in addition to its children.
                'children' => [
                    [
                        'label' => 'modules.categories',
                        'icon' => 'tag',
                        'route' => 'categories.index',
                        'permission' => 'categories.view',
                    ],
                    [
                        'label' => 'modules.units',
                        'icon' => 'cube',
                        'route' => 'units.index',
                        'permission' => 'units.view',
                    ],
                    [
                        'label' => 'modules.taxes',
                        'icon' => 'percent',
                        'route' => 'taxes.index',
                        'permission' => 'taxes.view',
                        'setting' => 'general.tax_enabled',
                    ],
                ],
            ],
        ],
        'expenses' => [
            'key' => 'expenses',
            'label' => 'modules.expenses',
            'icon' => 'arrow-trending-down',
            'permission' => 'expenses.view',
            'navigation' => [
                'group' => 'business',
                'order' => 4,
                'route' => 'expenses.index',
            ],
        ],
        'reports' => [
            'key' => 'reports',
            'label' => 'modules.reports',
            'icon' => 'inbox',
            'navigation' => [
                'group' => 'insights',
                'order' => 1,
            ],
        ],
        'settings' => [
            'key' => 'settings',
            'label' => 'modules.settings',
            'icon' => 'cog',
            'navigation' => [
                'group' => 'system',
                'order' => 1,
                'route' => 'settings.index',
            ],
            'permission' => 'settings.view',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Modules
    |--------------------------------------------------------------------------
    |
    | Modules provisioned automatically when a new business is created.
    | Only the foundation modules needed to operate the shell are enabled
    | by default. Batch 10+ modules (customers, products, etc.) are
    | available in the registry but disabled until explicitly activated
    | by the business owner (Batch 9 settings UI or future CLI).
    |
    */

    'default_enabled' => ['dashboard', 'settings'],
];
