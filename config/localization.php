<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | The locales supported by the application. Each locale has a direction,
    | label, and native label for display purposes. The locale middleware
    | reads this configuration to validate and apply the active locale.
    |
    */

    'supported' => [
        'en' => [
            'direction' => 'ltr',
            'label' => 'English',
            'native' => 'English',
        ],
        'fa' => [
            'direction' => 'rtl',
            'label' => 'Dari',
            'native' => 'دری',
        ],
        'ar' => [
            'direction' => 'rtl',
            'label' => 'Arabic',
            'native' => 'العربية',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Key
    |--------------------------------------------------------------------------
    |
    | The session key used to store the user's selected locale.
    |
    */

    'session_key' => 'locale',
];
