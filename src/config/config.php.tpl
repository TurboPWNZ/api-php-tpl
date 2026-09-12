<?php
return [
    'db' => [
        'tablePrefix' => '',
        'dsn' => 'mysql:host=eg550228.mysql.tools;dbname=;charset=utf8mb4',
        'user' => '',
        'password' => '',
    ],
    'params' => [
        'gameInitBalance' => 100,
        'gtBotToken' => '000000:LOCAL-DEV-INSECURE-NOT-A-REAL-BOT',
        'captchaSiteKey' => '6LeXXX',
        'captchaSecretKey' => '6LeXXX',
    ],
    'comfyui' => [
        // Если бэкенд работает в docker-compose (apache.dockerfile), а
        // ComfyUI — на хосте, используйте host.docker.internal, а не
        // 127.0.0.1 (тот из контейнера означает сам контейнер).
        'domain' => 'http://127.0.0.1:8188',
        'i2i' => [
            'workflow' => 'comfy/workflow/i2i_host_gen.json',
            'systemPromt' => 'make funny',
            // Подставляется вместо systemPromt, пока аккаунт в статусе 'guest'
            // (ни разу не пополнял баланс) — см. TelegramAccount::STATUS_GUEST.
            'systemPromtGuest' => 'make funny'
        ]
    ],
    'generate' => [
        'costBase' => 4,       // без описания
        'costWithPrompt' => 8, // с описанием
    ],
    'payment' => [
        'providers' => [
            'NOWPayments' => [
                'apiKey' => 'XXX',
                'publicKey' => 'XXX',
                'ipnKey' => 'XXX',
                'callbackSecret' => '',
                'minAmount' => 15,
                'maxAmount' => 200,
                'invoice' => [
                    'currency' => 'USD',
                    'description' => '',
                    'ipn_callback_url' => 'https://domain.com/v1/payment/notyfication/NOWPayments',
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
                    'currency' => 'XTR',
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
