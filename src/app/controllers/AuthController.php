<?php

namespace Api\app\controllers;

use Api\components\Log;
use Api\components\TelegramAuth;
use Api\Configurator;
use Api\db\Account;
use Api\JwtHelper;
use Api\db\DatabaseManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class AuthController
{
    public function check(Request $request): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
        ]);
    }

    public function getAccount(Request $request): JsonResponse
    {
        $account = (new Account())->findByPk((int)$request->attributes->get('user')['user_id']);
        return new JsonResponse([
            'success' => true,
            'user' => [
                'id' => (int)$request->attributes->get('user')['user_id'],
                'email' => $request->attributes->get('user')['email'],
                'balance' => $account['balance'],
            ]
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->toArray();

        Log::get(Log::DEBUG)->info('Login request received', $data);

        $initData = $data['initData'] ?? '';

        if (empty($initData)) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['initData is required']
            ], 400);
        }

        $config = Configurator::getConfig();

        $botToken = $config['params']['gtBotToken'] ?? '';
        if (empty($botToken)) {

            return new JsonResponse([
                'success' => false,
                'errors' => ['Server configuration error']
            ], 500);
        }

        // Validate Telegram WebApp initData signature
        $telegram = TelegramAuth::validateInitData($initData, $botToken);

        if ($telegram === false) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Invalid Telegram initData']
            ], 401);
        }

        $user = json_decode($telegram['user'] ?? '', true);

        if (!is_array($user) || empty($user['id'])) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Invalid user data in initData']
            ], 400);
        }

        $userId = (int)$user['id'];

        // Generate JWT token
        JwtHelper::init($config);

        $token = JwtHelper::generateToken([
            'user_id' => $userId,
            'provider' => 'telegram',
            'username' => $user['username'] ?? null,
        ]);

        return new JsonResponse([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $userId,
//                'first_name' => $user['first_name'] ?? '',
//                'last_name' => $user['last_name'] ?? '',
                'username' => $user['username'] ?? '',
//                'language_code' => $user['language_code'] ?? '',
//                'photo_url' => $user['photo_url'] ?? null,
//                'chat_type' => $telegram['chat_type'] ?? null,
            ],
        ]);
    }
}
