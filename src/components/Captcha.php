<?php

namespace Api\components;

class Captcha
{
    public static function verify(string $captchaResponse, string $secretKey): array
    {
        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => $secretKey,
            'response' => $captchaResponse
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error' => 'CAPTCHA verification failed'
                //@todo log error 'error' => 'cURL error: ' . $curlError
            ];
        }

        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'CAPTCHA verification failed'
                //@todo log error  'error' => 'HTTP code: ' . $httpCode . ', response: ' . $response
            ];
        }

        $result = json_decode($response, true);

        if (!$result['success'] ?? false) {
            return [
                'success' => false,
                'error' => 'CAPTCHA verification failed'
                //@todo log error 'error' => 'Google response: ' . ($result['error-codes'] ?? 'unknown')
            ];
        }

        return [
            'success' => true
        ];
    }
}
