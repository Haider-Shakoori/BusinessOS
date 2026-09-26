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
            'icon' => 'chart-line',
            'expandable' => true,
            'active_prefixes' => ['quotations.', 'invoices.', 'payments.'],
            'module' => 'sales',
        ],
        [
            'key' => 'quotations',
            'label' => 'navigation.quotations',
            'icon' => 'documents',
            'route' => 'quotations.index',
            'module' => 'sales',
            'permission' => 'quotations.view',
        ],
        [
            'key' => 'invoices',
            'label' => 'navigation.invoices',
            'icon' => 'clipboard-document-list',
            'route' => 'invoices.index',
            'module' => 'sales',
            'permission' => 'invoices.view',
        ],
        [
            'key' => 'payments',
            'label' => 'navigation.payments',
            'icon' => 'payment-card',
            'route' => 'payments.index',
            'module' => 'sales',
            'permission' => 'payments.view',
        ],
        [
            'key' => 'expenses',
            'label' => 'navigation.expenses',
            'icon' => 'receipt-list',
            'route' => 'expenses.index',
            'module' => 'expenses',
            'permission' => 'expenses.view',
        ],
        [
            'key' => 'products',
            'label' => 'navigation.products',
            'icon' => 'gift',
            'route' => 'products.index',
            'module' => 'products',
            'permission' => 'products.view',
        ],
        [
            'key' => 'inventory',
            'label' => 'navigation.inventory',
            'icon' => 'archive-box',
            'route' => 'inventory.index',
            'module' => 'inventory',
            'permission' => 'inventory.view',
        ],
        [
            'key' => 'purchasing',
            'label' => 'navigation.purchasing',
            'icon' => 'shopping-cart',
            'route' => 'purchasing.index',
            'module' => 'purchasing',
            'permission' => 'purchasing.view',
        ],
        [
            'key' => 'pos',
            'label' => 'navigation.pos',
            'icon' => 'building-storefront',
            'route' => 'pos.index',
            'module' => 'pos',
            'permission' => 'pos.view',
        ],
        [
            'key' => 'accounting',
            'label' => 'navigation.accounting',
            'icon' => 'ledger',
            'expandable' => true,
            'route' => 'accounting.index',
            'module' => 'accounting',
            'permission' => 'accounting.view',
        ],
        [
            'key' => 'manufacturing',
            'label' => 'navigation.manufacturing',
            'icon' => 'factory',
            'route' => 'manufacturing.index',
            'module' => 'manufacturing',
            'permission' => 'manufacturing.view',
        ],
        [
            'key' => 'crm',
            'label' => 'navigation.crm',
            'icon' => 'contact-card',
            'expandable' => true,
            'route' => 'crm.index',
            'module' => 'crm',
            'permission' => 'crm.view',
        ],
        [
            'key' => 'hr-payroll',
            'label' => 'navigation.hr_payroll',
            'icon' => 'user-group',
            'route' => 'hr.index',
            'module' => 'hr',
            'permission' => 'hr.view',
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
            'route' => 'system.notifications.index',
            'permission' => 'notifications.view',
        ],
        [
            'key' => 'global-search',
            'label' => 'navigation.global_search',
            'icon' => 'search',
            'route' => 'system.search.index',
            'permission' => 'search.use',
        ],
        [
            'key' => 'activity-log',
            'label' => 'navigation.activity_log',
            'icon' => 'history',
            'route' => 'system.activity.index',
            'permission' => 'activity.view',
        ],
        [
            'key' => 'smart-assistant',
            'label' => 'navigation.smart_assistant',
            'icon' => 'light-bulb',
            'route' => 'system.assistant.index',
            'permission' => 'assistant.use',
        ],
        [
            'key' => 'saas-businesses',
            'label' => 'navigation.saas_businesses',
            'icon' => 'saas',
            'route' => 'system.businesses.index',
            'permission' => 'businesses.view',
        ],
        [
            'key' => 'users-roles',
            'label' => 'navigation.users_roles',
            'icon' => 'user-group',
            'route' => 'system.users-roles.index',
            'permission' => 'users.view',
        ],
        [
            'key' => 'modules',
            'label' => 'navigation.modules',
            'icon' => 'module-grid',
            'route' => 'system.modules.index',
            'permission' => 'modules.view',
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
