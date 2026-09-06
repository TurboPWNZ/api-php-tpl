<?php

namespace Api\app\controllers;

use Api\components\Log;
use Api\components\TelegramAuth;
use Api\Configurator;
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
            Log::get(Log::DEBUG)->error('Telegram bot token is not configured');

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

        Log::get(Log::DEBUG)->info('Telegram login validated', [
            'telegram_user_id' => $userId,
            'username' => $user['username'] ?? null,
        ]);

        // Upsert user in DB
        $row = [
            'id' => $userId,
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'username' => $user['username'] ?? '',
            'language_code' => $user['language_code'] ?? '',
            'photo_url' => $user['photo_url'] ?? null,
            'auth_date' => date('Y-m-d H:i:s', $telegram['auth_date'] ?? time()),
            'dateAuth' => date('Y-m-d H:i:s'),
        ];

        try {
            $table = DatabaseManager::connection()->table('users');

            if ($table->where('id', $row['id'])->exists()) {
                $table->where('id', $row['id'])->update($row);
            } else {
                $table->insert(array_merge($row, [
                    'created_at' => $row['dateAuth'],
                    'updated_at' => $row['dateAuth'],
                ]));
            }
        } catch (\Throwable $e) {
            // DB is not critical for login: token is still issued
            Log::get(Log::DEBUG)->error('Failed to upsert telegram user in DB', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
        }

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
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'username' => $user['username'] ?? '',
                'language_code' => $user['language_code'] ?? '',
                'photo_url' => $user['photo_url'] ?? null,
                'chat_type' => $telegram['chat_type'] ?? null,
            ],
        ]);
    }
}
