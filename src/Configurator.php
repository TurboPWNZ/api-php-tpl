<?php
namespace Api;

class Configurator
{
    private static array $_config;

    public static function load()
    {
        if (empty(self::$_config)) {
            self::$_config = require_once __DIR__ . '/config/config.php.tpl';

            // Merge mail config
            $mailConfig = require_once __DIR__ . '/config/mail.php.tpl';
            self::$_config['mail'] = $mailConfig;
        }

        return self::$_config;
    }

    public static function getMailConfig(): array
    {
        $config = self::load();
        return $config['mail'] ?? [];
    }

    public static function getConfig(): array
    {
        return self::load();
    }
}
