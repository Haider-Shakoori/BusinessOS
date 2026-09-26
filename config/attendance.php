<?php

return [
    /*
     * Compatibility profiles describe connection families BusinessOS knows how
     * to configure. Some vendors expose a native HTTP/TCP interface while
     * others are best integrated through their vendor server/SDK or the secure
     * BusinessOS push bridge.
     */
    'brands' => [
        'zkteco' => ['label' => 'ZKTeco / ZK-compatible', 'connections' => ['zkteco_tcp', 'adms_push', 'http_api', 'push_webhook']],
        'essl' => ['label' => 'eSSL', 'connections' => ['zkteco_tcp', 'adms_push', 'http_api', 'push_webhook']],
        'realtime' => ['label' => 'Realtime Biometrics', 'connections' => ['zkteco_tcp', 'http_api', 'push_webhook']],
        'bioenable' => ['label' => 'BioEnable', 'connections' => ['zkteco_tcp', 'http_api', 'push_webhook']],
        'hikvision' => ['label' => 'Hikvision', 'connections' => ['isapi', 'http_api', 'push_webhook']],
        'dahua' => ['label' => 'Dahua', 'connections' => ['http_api', 'push_webhook']],
        'suprema' => ['label' => 'Suprema', 'connections' => ['suprema_device_tcp', 'biostar2_api', 'http_api', 'push_webhook']],
        'anviz' => ['label' => 'Anviz', 'connections' => ['anviz_tcp', 'crosschex_api', 'http_api', 'push_webhook']],
        'matrix' => ['label' => 'Matrix COSEC', 'connections' => ['http_api', 'tcp_socket', 'push_webhook']],
        'cpplus' => ['label' => 'CP PLUS', 'connections' => ['http_api', 'tcp_socket', 'push_webhook']],
        'mantra' => ['label' => 'Mantra', 'connections' => ['http_api', 'tcp_socket', 'push_webhook']],
        'nitgen' => ['label' => 'Nitgen', 'connections' => ['http_api', 'tcp_socket', 'push_webhook']],
        'virdi' => ['label' => 'VIRDI / UnionCommunity', 'connections' => ['http_api', 'tcp_socket', 'push_webhook']],
        'idemia' => ['label' => 'IDEMIA / Morpho', 'connections' => ['http_api', 'tcp_socket', 'push_webhook']],
        'generic' => ['label' => 'Generic / Other', 'connections' => ['tcp_socket', 'http_api', 'push_webhook']],
    ],

    'connections' => [
        'zkteco_tcp' => [
            'label' => 'ZKTeco-compatible TCP/IP',
            'transport' => 'tcp',
            'default_port' => 4370,
            'requires' => ['host', 'port'],
        ],
        'anviz_tcp' => [
            'label' => 'Anviz device TCP/IP',
            'transport' => 'tcp',
            'default_port' => 5010,
            'requires' => ['host', 'port'],
        ],
        'suprema_device_tcp' => [
            'label' => 'Suprema device TCP/IP',
            'transport' => 'tcp',
            'default_port' => 51211,
            'requires' => ['host', 'port'],
        ],
        'adms_push' => [
            'label' => 'ADMS / Push',
            'transport' => 'push',
            'default_port' => null,
            'requires' => ['serial_number'],
        ],
        'biostar2_api' => [
            'label' => 'Suprema BioStar 2 API',
            'transport' => 'http',
            'default_port' => 443,
            'requires' => ['base_url', 'username', 'password'],
        ],
        'isapi' => [
            'label' => 'Hikvision ISAPI',
            'transport' => 'http',
            'default_port' => 80,
            'requires' => ['base_url', 'username', 'password'],
        ],
        'crosschex_api' => [
            'label' => 'Anviz CrossChex API / Server',
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

    /*
     * Discovery only performs short, read-only probes against a user supplied
     * IP. Brand detection is deliberately a confidence score rather than a
     * guarantee: OEM/rebadged devices often expose identical ports.
     */
    'discovery' => [
        'tcp_timeout_seconds' => 0.35,
        'http_timeout_seconds' => 1.25,
        'ports' => [
            4370 => ['brand' => 'zkteco', 'connection' => 'zkteco_tcp', 'weight' => 72],
            5010 => ['brand' => 'anviz', 'connection' => 'anviz_tcp', 'weight' => 80],
            51211 => ['brand' => 'suprema', 'connection' => 'suprema_device_tcp', 'weight' => 82],
            80 => ['brand' => null, 'connection' => 'http_api', 'weight' => 15],
            443 => ['brand' => null, 'connection' => 'http_api', 'weight' => 15],
            8000 => ['brand' => null, 'connection' => 'http_api', 'weight' => 12],
            8080 => ['brand' => null, 'connection' => 'http_api', 'weight' => 12],
            8443 => ['brand' => null, 'connection' => 'http_api', 'weight' => 12],
            3000 => ['brand' => 'suprema', 'connection' => 'biostar2_api', 'weight' => 35],
            3002 => ['brand' => 'suprema', 'connection' => 'biostar2_api', 'weight' => 45],
            9000 => ['brand' => 'suprema', 'connection' => 'biostar2_api', 'weight' => 30],
        ],
        'fingerprints' => [
            'hikvision' => ['hikvision', 'isapi', 'minmoe'],
            'dahua' => ['dahua'],
            'zkteco' => ['zkteco', 'zkaccess', 'iclock', 'zksoftware'],
            'essl' => ['essl'],
            'realtime' => ['realtime biometrics', 'realtime biometric'],
            'bioenable' => ['bioenable'],
            'suprema' => ['suprema', 'biostar'],
            'anviz' => ['anviz', 'crosschex'],
            'matrix' => ['matrix cosec', 'cosec'],
            'cpplus' => ['cp plus', 'cpplus'],
            'mantra' => ['mantra'],
            'nitgen' => ['nitgen'],
            'virdi' => ['virdi', 'unioncommunity', 'union community'],
            'idemia' => ['idemia', 'morpho', 'sagem'],
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
