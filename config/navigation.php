<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Final product sidebar blueprint
    |--------------------------------------------------------------------------
    |
    | This order mirrors the approved BusinessOS product screenshots. The
    | blueprint is presentation-only: implemented routes still keep their
    | module/permission middleware, while future modules resolve to a harmless
    | placeholder until their roadmap batch is implemented.
    |
    */

    'items' => [
        [
            'key' => 'dashboard',
            'label' => 'navigation.dashboard',
            'icon' => 'home',
            'route' => 'app.home',
            'module' => 'dashboard',
        ],
        [
            'key' => 'customers',
            'label' => 'navigation.customers',
            'icon' => 'users',
            'route' => 'customers.index',
            'module' => 'customers',
            'permission' => 'customers.view',
        ],
        [
            'key' => 'sales',
            'label' => 'navigation.sales',
            'icon' => 'chart-bar',
            'expandable' => true,
            'active_prefixes' => ['quotations.', 'invoices.', 'payments.'],
            'module' => 'sales',
        ],
        [
            'key' => 'quotations',
            'label' => 'navigation.quotations',
            'icon' => 'document-text',
            'route' => 'quotations.index',
            'module' => 'sales',
            'permission' => 'quotations.view',
            'depth' => 1,
        ],
        [
            'key' => 'invoices',
            'label' => 'navigation.invoices',
            'icon' => 'receipt-percent',
            'route' => 'invoices.index',
            'module' => 'sales',
            'permission' => 'invoices.view',
            'depth' => 1,
        ],
        [
            'key' => 'payments',
            'label' => 'navigation.payments',
            'icon' => 'banknotes',
            'route' => 'payments.index',
            'module' => 'sales',
            'permission' => 'payments.view',
            'depth' => 1,
        ],
        [
            'key' => 'expenses',
            'label' => 'navigation.expenses',
            'icon' => 'receipt-percent',
            'route' => 'expenses.index',
            'module' => 'expenses',
            'permission' => 'expenses.view',
        ],
        [
            'key' => 'products',
            'label' => 'navigation.products',
            'icon' => 'cube',
            'route' => 'products.index',
            'module' => 'products',
            'permission' => 'products.view',
        ],
        [
            'key' => 'inventory',
            'label' => 'navigation.inventory',
            'icon' => 'archive-box',
            'placeholder' => 'inventory',
        ],
        [
            'key' => 'purchasing',
            'label' => 'navigation.purchasing',
            'icon' => 'shopping-cart',
            'placeholder' => 'purchasing',
        ],
        [
            'key' => 'pos',
            'label' => 'navigation.pos',
            'icon' => 'calculator',
            'placeholder' => 'pos',
        ],
        [
            'key' => 'accounting',
            'label' => 'navigation.accounting',
            'icon' => 'credit-card',
            'expandable' => true,
            'placeholder' => 'accounting',
        ],
        [
            'key' => 'manufacturing',
            'label' => 'navigation.manufacturing',
            'icon' => 'building-office',
            'placeholder' => 'manufacturing',
        ],
        [
            'key' => 'crm',
            'label' => 'navigation.crm',
            'icon' => 'chat-bubble',
            'expandable' => true,
            'placeholder' => 'crm',
        ],
        [
            'key' => 'reports',
            'label' => 'navigation.reports',
            'icon' => 'chart-bar',
            'route' => 'reports.index',
            'module' => 'reports',
            'permission' => 'reports.view',
        ],
        [
            'key' => 'notifications',
            'label' => 'navigation.notifications',
            'icon' => 'bell',
            'placeholder' => 'notifications',
        ],
        [
            'key' => 'global-search',
            'label' => 'navigation.global_search',
            'icon' => 'search',
            'placeholder' => 'global-search',
        ],
        [
            'key' => 'activity-log',
            'label' => 'navigation.activity_log',
            'icon' => 'clock',
            'placeholder' => 'activity-log',
        ],
        [
            'key' => 'smart-assistant',
            'label' => 'navigation.smart_assistant',
            'icon' => 'sparkles',
            'placeholder' => 'smart-assistant',
        ],
        [
            'key' => 'saas-businesses',
            'label' => 'navigation.saas_businesses',
            'icon' => 'building-storefront',
            'placeholder' => 'saas-businesses',
        ],
        [
            'key' => 'users-roles',
            'label' => 'navigation.users_roles',
            'icon' => 'user-group',
            'placeholder' => 'users-roles',
        ],
        [
            'key' => 'modules',
            'label' => 'navigation.modules',
            'icon' => 'squares-2x2',
            'placeholder' => 'modules',
        ],
        [
            'key' => 'settings',
            'label' => 'navigation.settings',
            'icon' => 'cog',
            'route' => 'settings.index',
            'module' => 'settings',
            'permission' => 'settings.view',
        ],
    ],
];
