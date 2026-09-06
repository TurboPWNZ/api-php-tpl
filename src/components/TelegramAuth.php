<?php

namespace Api\components;

class TelegramAuth
{
    /**
     * Maximum age of initData, seconds.
     */
    const MAX_AUTH_AGE = 86400;

    /**
     * Validate Telegram WebApp initData against the bot token.
     *
     * @param string $initData Raw initData string (query-string format)
     * @param string $botToken Bot token
     *
     * @return array|false Parsed data (without hash/signature) or false if invalid
     */
    public static function validateInitData(string $initData, string $botToken)
    {
        parse_str($initData, $data);

        if (!isset($data['hash'])) {
            return false;
        }

        $receivedHash = $data['hash'];
        unset($data['hash']);

        // signature — отдельная подпись для Ed25519, для стандартной
        // проверки через bot token она не нужна
        unset($data['signature']);

        ksort($data);

        $dataCheckString = [];
        foreach ($data as $key => $value) {
            $dataCheckString[] = $key . '=' . $value;
        }
        $dataCheckString = implode("\n", $dataCheckString);

        // Secret key for Mini Apps: HMAC-SHA256(botToken, "WebAppData")
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);

        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($receivedHash, $calculatedHash)) {
            return false;
        }

        // Freshness check
        if (isset($data['auth_date'])) {
            if (time() - (int)$data['auth_date'] > self::MAX_AUTH_AGE) {
                return false;
            }
        }

        return $data;
    }
}
