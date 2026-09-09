<?php
return [
    'db' => [
        'tablePrefix' => '',
        'dsn' => 'mysql:host=eg550228.mysql.tools;dbname=;charset=utf8mb4',
        'user' => '',
        'password' => '',
    ],
    'params' => [
        'gameInitBalance' => 1000,
        'captchaSiteKey' => '6LeXXX',
        'captchaSecretKey' => '6LeXXX',
        // Игровая логика (см. spincalculate.md)
        'gameMinBet' => 10,
        'gameMaxBet' => 200,
        'gameMaxLines' => 7,
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
            ],
            'TelegramStars' => [
                // Секрет для setWebhook(secret_token=...) — сверяется с заголовком
                // X-Telegram-Bot-Api-Secret-Token на входящих вебхуках от Telegram.
                'webhookSecret' => 'XXX',
                'minAmount' => 1,
                'maxAmount' => 100000,
                'invoice' => [
                    'title' => 'Top-up',
                    'description' => 'Balance top-up',
                ],
            ],
        ]
    ],
    'jwt' => [
        'secret' => 'your-super-secret-jwt-key-change-in-production', // CHANGE THIS!
        'issuer' => 'domain-api',
        'audience' => 'domain-users',
        'ttl' => 3600, // 1 hour in seconds
    ]
];
