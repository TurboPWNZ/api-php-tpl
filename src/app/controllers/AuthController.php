<?php

namespace Api\app\controllers;

use Api\components\Log;
use Api\Configurator;
use Api\db\Account;
use Api\JwtHelper;
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

        return new JsonResponse([
            'success' => true,
            'message' => 'stub'
        ]);

        if (empty($data['email']) || empty($data['password'])) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Email and password are required']
            ]);
        }

        $config = Configurator::getConfig();

        $account = new Account();
        $user = $account->find('email = :email', ['email' => $data['email']]);

        if (!$user || !password_verify($data['password'], $user['password'])) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Invalid credentials']
            ]);
        }

        if (empty($user['email_verified_at'])) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Please verify your email before logging in']
            ]);
        }

        // Update last login time
        $account->update(
            'id = :id',
            [
                'id' => $user['id'],
                'dateAuth' => date('Y-m-d H:i:s')
            ]
        );

        // Generate JWT token
        JwtHelper::init($config);

        $token = JwtHelper::generateToken([
            'user_id' => (int)$user['id'],
            'email' => $user['email'],
            'role' => $user['role'] ?? 'user'
        ]);

        return new JsonResponse([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'balance' => (int)($user['balance'] ?? 0)
            ]
        ]);
    }
}
