<?php

namespace Api\db;

use Illuminate\Database\Capsule\Manager as Capsule;
use Api\Configurator;

class DatabaseManager
{
    private static ?Capsule $capsule = null;

    public static function boot(): Capsule
    {
        if (self::$capsule !== null) {
            return self::$capsule;
        }

        $capsule = new Capsule();
        $config = Configurator::getConfig();

        $dsnParams = self::parseDsn($config['db']['dsn']);

        $capsule->addConnection([
            'driver'   => 'mysql',
            'host'     => $dsnParams['host'] ?? 'localhost',
            'database' => $dsnParams['dbname'] ?? '',
            'username' => $config['db']['user'],
            'password' => $config['db']['password'],
            'charset'  => $dsnParams['charset'] ?? 'utf8mb4',
            'collation'=> 'utf8mb4_unicode_ci',
            'prefix'   => $config['db']['tablePrefix'] ?? '',
            'strict'   => true,
            'engine'   => 'innodb',
            // Без этого PDO/MySQL по умолчанию считает affected rows как
            // "строки, у которых реально изменилось значение", а не "строки,
            // подошедшие под WHERE". Из-за этого ->increment('balance', 0)
            // (списание с нулевым дельта — сумма спишется и тут же
            // вернётся) возвращало 0, и код ошибочно трактовал это как
            // "Insufficient balance", хотя WHERE balance >= stake прекрасно
            // матчился.
            'options'  => [
                \PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ],
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;

        return $capsule;
    }

    public static function connection(): \Illuminate\Database\Connection
    {
        return self::boot()->getConnection();
    }

    /**
     * Parse MySQL DSN: mysql:host=xxx;dbname=yyy;charset=utf8mb4
     * Returns associative array of key => value pairs.
     */
    private static function parseDsn(string $dsn): array
    {
        // Remove "mysql:" prefix
        $str = preg_replace('/^mysql:/', '', $dsn);

        $params = [];
        foreach (explode(';', $str) as $pair) {
            if (strpos($pair, '=') !== false) {
                [$key, $value] = explode('=', $pair, 2);
                $params[trim($key)] = trim($value);
            }
        }

        return $params;
    }
}
