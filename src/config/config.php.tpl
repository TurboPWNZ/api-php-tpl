<?php
return [
    'db' => [
        'tablePrefix' => '',
        'dsn' => 'mysql:host=eg550228.mysql.tools;dbname=;charset=utf8mb4',
        'user' => '',
        'password' => '',
    ],
    'params' => [
        'captchaSiteKey' => '6LeXXX',
        'captchaSecretKey' => '6LeXXX',
    ],
    'payment' => [
        'providers' => [
            'NOWPayments' => [
                'apiKey' => 'XXX',
                'publicKey' => 'XXX',
                'ipnKey' => 'XXX',
                'callbackSecret' => '',
                'invoice' => [
                    'currency' => 'USD',
                    'description' => '',
                    'ipn_callback_url' => 'https://domain.com/payment/notyfication/NOWPayments',
                    'success_url' => 'https://domain.com/dashboard/payment/success',
                    'cancel_url' => 'https://domain.com/dashboard/payment/failed'
                ]
            ]
        ]
    ],
    'jwt' => [
        'secret' => 'your-super-secret-jwt-key-change-in-production', // CHANGE THIS!
        'issuer' => 'domain-api',
        'audience' => 'domain-users',
        'ttl' => 3600, // 1 hour in seconds
    ]
];
