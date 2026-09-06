<?php
namespace Api\components;

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Log
{
    const PAYMENT = 'payment';
    const DEBUG = 'debug';
    private static array $loggers = [];

    public static function get(string $name): Logger
    {
        if (!isset(self::$loggers[$name])) {
            $logger = new Logger($name);
            $logger->pushHandler(
                new StreamHandler(__DIR__ . "/../../logs/{$name}.log", Level::Debug)
            );

            self::$loggers[$name] = $logger;
        }

        return self::$loggers[$name];
    }
}