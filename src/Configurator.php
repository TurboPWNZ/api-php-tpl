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

    /**
     * Окружение очереди генераций ('prod', 'local', ...) — config queue.env.
     * БД общая для докера и прода: по нему воркер отделяет свои серверы и
     * заказы от чужих (см. GenerationQueue).
     */
    public static function queueEnv(): string
    {
        return (string)(self::getConfig()['queue']['env'] ?? 'prod');
    }

    /**
     * Абсолютный путь к корню проекта (там же лежит composer.json,
     * `comfy/`, `storage/`) — Configurator.php лежит прямо в src/.
     */
    public static function projectRoot(): string
    {
        return dirname(__DIR__);
    }
}
