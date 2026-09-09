<?php

namespace Api\components;

use Api\Configurator;

/**
 * Тонкий клиент Telegram Bot API поверх curl (без внешних зависимостей —
 * тот же подход, что уже используется в components/payment/providers/NOWPayments.php).
 */
class TelegramBotApi
{
    /**
     * Вызов метода Bot API. Токен берётся из config['params']['gtBotToken']
     * (тот же токен, которым уже проверяется initData в TelegramAuth).
     *
     * @return array Декодированный ответ: ['ok' => bool, 'result' => mixed, 'description' => string]
     */
    public static function call(string $method, array $params = []): array
    {
        $config = Configurator::getConfig();
        $botToken = $config['params']['gtBotToken'] ?? '';

        if ($botToken === '') {
            return ['ok' => false, 'description' => 'gtBotToken is not configured'];
        }

        $ch = curl_init("https://api.telegram.org/bot{$botToken}/{$method}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Log::get(Log::PAYMENT)->error('TelegramBotApi: curl error', ['method' => $method, 'error' => $curlError]);
            return ['ok' => false, 'description' => 'curl error: ' . $curlError];
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            Log::get(Log::PAYMENT)->error('TelegramBotApi: invalid JSON response', ['method' => $method, 'response' => $response]);
            return ['ok' => false, 'description' => 'Invalid JSON response from Bot API'];
        }

        return $decoded;
    }
}
