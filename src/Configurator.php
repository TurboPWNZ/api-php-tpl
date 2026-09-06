<?php
namespace Api;

class Configurator
{
    private static array $_config;

    public static function load()
    {
        if (empty(self::$_config)) {
            $configFile = __DIR__ . '/config/config.php';
            if (!file_exists($configFile)) {
                $configFile = __DIR__ . '/config/config.php.tpl';
            }
            self::$_config = require_once $configFile;

            // Merge mail config
            $mailFile = __DIR__ . '/config/mail.php';
            if (!file_exists($mailFile)) {
                $mailFile = __DIR__ . '/config/mail.php.tpl';
            }
            self::$_config['mail'] = require_once $mailFile;
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
