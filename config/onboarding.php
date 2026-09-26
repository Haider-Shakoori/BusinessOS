<?php

return [
    'defaults' => [
        'country' => 'AF',
        'currency' => 'AFN',
        'timezone' => 'Asia/Kabul',
        'locale' => 'en',
        'appearance' => 'light',
        'invoice_preview' => 'INV-000001',
    ],

    'countries' => [
        'AF' => 'onboarding.countries.afghanistan',
    ],

    'industries' => [
        'retail_wholesale' => 'onboarding.industries.retail_wholesale',
        'services' => 'onboarding.industries.services',
        'manufacturing' => 'onboarding.industries.manufacturing',
        'distribution' => 'onboarding.industries.distribution',
        'restaurant' => 'onboarding.industries.restaurant',
        'pharmacy' => 'onboarding.industries.pharmacy',
        'other' => 'onboarding.industries.other',
    ],

    'timezones' => [
        'Asia/Kabul' => 'onboarding.timezones.kabul',
        'UTC' => 'onboarding.timezones.utc',
    ],

    /*
     * The screenshot-approved Preferences screen exposes these eight switches.
     * Future keys may be stored in business_modules before their registry batch
     * arrives; ModuleManager still refuses them until code registration exists.
     */
    'modules' => [
        'customers' => [
            'label' => 'navigation.customers',
            'description' => 'onboarding.modules.customers',
            'icon' => 'users',
            'tone' => 'blue',
        ],
        'sales' => [
            'label' => 'navigation.sales',
            'description' => 'onboarding.modules.sales',
            'icon' => 'chart-bar',
            'tone' => 'emerald',
        ],
        'products' => [
            'label' => 'navigation.products',
            'description' => 'onboarding.modules.products',
            'icon' => 'cube',
            'tone' => 'emerald',
        ],
        'inventory' => [
            'label' => 'navigation.inventory',
            'description' => 'onboarding.modules.inventory',
            'icon' => 'archive-box',
            'tone' => 'orange',
        ],
        'expenses' => [
            'label' => 'navigation.expenses',
            'description' => 'onboarding.modules.expenses',
            'icon' => 'document-text',
            'tone' => 'rose',
        ],
        'reports' => [
            'label' => 'navigation.reports',
            'description' => 'onboarding.modules.reports',
            'icon' => 'chart-bar',
            'tone' => 'blue',
        ],
        'smart-assistant' => [
            'label' => 'navigation.smart_assistant',
            'description' => 'onboarding.modules.smart_assistant',
            'icon' => 'sparkles',
            'tone' => 'indigo',
        ],
        'notifications' => [
            'label' => 'navigation.notifications',
            'description' => 'onboarding.modules.notifications',
            'icon' => 'bell',
            'tone' => 'violet',
        ],
    ],
];
