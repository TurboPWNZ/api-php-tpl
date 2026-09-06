<?php

namespace Api;

use Api\components\Captcha;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class Middleware
{
    /**
     * JWT Authentication middleware
     * 
     * Usage in routes:
     * $r->get('/protected', function(Request $request) {
     *     $authMiddleware = new Middleware();
     *     $result = $authMiddleware->auth($request);
     *     if ($result !== true) {
     *         return $result;
     *     }
     *     // Your protected code here
     * });
     */
    public static function auth(Request $request): bool|JsonResponse
    {
        $config = Configurator::getConfig();
        JwtHelper::init($config);

        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Authorization header is missing']
            ], 401);
        }

        // Extract token from "Bearer TOKEN" format
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Invalid authorization format. Use: Authorization: Bearer TOKEN']
            ], 401);
        }

        $token = substr($authHeader, 7); // Remove "Bearer " prefix

        $validation = JwtHelper::validateToken($token);

        if (!$validation['valid']) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Invalid or expired token: ' . $validation['error']]
            ], 401);
        }

        // Store decoded user data in request attributes for controllers
        $request->attributes->set('user', $validation['payload']);

        return true;
    }

    public static function captcha(Request $request): bool|JsonResponse
    {
        $data = $request->toArray();

        $config = Configurator::getConfig();

        if (empty($data['g-recaptcha-response'])) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Please complete the CAPTCHA']
            ]);
        }

        $captchaResponse = Captcha::verify(
            $data['g-recaptcha-response'],
            $config['params']['captchaSecretKey']
        );

        if (!$captchaResponse['success']) {
            return new JsonResponse([
                'success' => false,
                'errors' => [$captchaResponse['error']]
            ]);
        }

        return true;
    }
    /**
     * Check if user has specific role
     */
    public static function role(string $requiredRole): bool|JsonResponse
    {
        // This should be called after auth() middleware
        // Usage: check $request->attributes->get('user')['role'] in controller
        return true; // Placeholder for role-based checks
    }
}
