<?php

return [
    'brands' => [
        'zkteco' => ['label' => 'ZKTeco', 'connections' => ['zkteco_tcp', 'adms_push', 'http_api']],
        'suprema' => ['label' => 'Suprema', 'connections' => ['biostar2_api', 'push_webhook']],
        'hikvision' => ['label' => 'Hikvision', 'connections' => ['isapi', 'push_webhook']],
        'anviz' => ['label' => 'Anviz', 'connections' => ['crosschex_api', 'tcp_socket', 'push_webhook']],
        'dahua' => ['label' => 'Dahua', 'connections' => ['http_api', 'push_webhook']],
        'essl' => ['label' => 'eSSL', 'connections' => ['zkteco_tcp', 'adms_push', 'http_api']],
        'generic' => ['label' => 'Generic / Other', 'connections' => ['tcp_socket', 'http_api', 'push_webhook']],
    ],

    'connections' => [
        'zkteco_tcp' => [
            'label' => 'ZKTeco TCP/IP',
            'transport' => 'tcp',
            'default_port' => 4370,
            'requires' => ['host', 'port'],
        ],
        'adms_push' => [
            'label' => 'ADMS / Push',
            'transport' => 'push',
            'default_port' => null,
            'requires' => ['serial_number'],
        ],
        'biostar2_api' => [
            'label' => 'BioStar 2 API',
            'transport' => 'http',
            'default_port' => 443,
            'requires' => ['base_url', 'username', 'password'],
        ],
        'isapi' => [
            'label' => 'Hikvision ISAPI',
            'transport' => 'http',
            'default_port' => 443,
            'requires' => ['base_url', 'username', 'password'],
        ],
        'crosschex_api' => [
            'label' => 'CrossChex API / Cloud',
            'transport' => 'http',
            'default_port' => 443,
            'requires' => ['base_url'],
        ],
        'http_api' => [
            'label' => 'HTTP / HTTPS API',
            'transport' => 'http',
            'default_port' => 443,
            'requires' => ['base_url'],
        ],
        'tcp_socket' => [
            'label' => 'Generic TCP/IP',
            'transport' => 'tcp',
            'default_port' => null,
            'requires' => ['host', 'port'],
        ],
        'push_webhook' => [
            'label' => 'BusinessOS Push Webhook',
            'transport' => 'push',
            'default_port' => null,
            'requires' => ['serial_number'],
        ],
    ],

    'verification_types' => [
        'fingerprint' => 'Fingerprint',
        'face' => 'Face',
        'card' => 'Card / RFID',
        'pin' => 'PIN / Password',
        'palm' => 'Palm',
        'mobile' => 'Mobile',
        'unknown' => 'Unknown',
    ],
];
