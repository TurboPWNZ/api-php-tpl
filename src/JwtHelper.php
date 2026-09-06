<?php

namespace Api;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtHelper
{
    private static array $config;

    public static function init(array $config): void
    {
        self::$config = $config['jwt'] ?? [];
    }

    public static function generateToken(array $payload): string
    {
        $config = self::$config;

        $iss = $config['issuer'];
        $aud = $config['audience'];
        $ttl = $config['ttl'];
        $secret = $config['secret'] ?? throw new \RuntimeException('JWT secret not configured');

        $token = [
            'iss' => $iss,
            'aud' => $aud,
            'iat' => time(),
            'exp' => time() + $ttl,
        ];

        // Merge user payload
        $token = array_merge($token, $payload);

        return JWT::encode($token, $secret, 'HS256');
    }

    public static function validateToken(string $token): array
    {
        $config = self::$config;
        $secret = $config['secret'] ?? throw new \RuntimeException('JWT secret not configured');

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            return [
                'valid' => true,
                'payload' => (array)$decoded
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
