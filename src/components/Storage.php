<?php

namespace Api\components;

use Api\Configurator;

/**
 * Файлы загруженных фото и результатов генерации — под /storage в корне
 * проекта. .htaccess отдаёт существующие файлы напрямую в обход index.php
 * (RewriteCond %{REQUEST_FILENAME} -f), так что относительный путь
 * "uploads/123/xxx.jpg" == публичный URL "/storage/uploads/123/xxx.jpg".
 */
class Storage
{
    private static function root(): string
    {
        return Configurator::projectRoot() . '/storage';
    }

    /**
     * Создаёт (если нет) директорию под конкретного пользователя.
     *
     * @return array{0: string, 1: string} [абсолютный путь, относительный путь для БД]
     */
    public static function userDir(string $kind, int $userId): array
    {
        $relative = "{$kind}/{$userId}";
        $absolute = self::root() . '/' . $relative;

        if (!is_dir($absolute)) {
            mkdir($absolute, 0777, true);
            // mkdir() режет права через umask, а директория смонтирована с
            // хоста (другой uid, чем www-data в контейнере) — фиксируем явно.
            chmod($absolute, 0777);
        }

        return [$absolute, $relative];
    }

    /**
     * Удаляет файл по относительному пути из БД ("uploads/123/xxx.jpg").
     * Путь проверяется на нахождение внутри /storage — на случай мусора в БД.
     * Отсутствующий файл не ошибка (уже удалён) — возвращает true.
     */
    public static function deleteFile(string $relativePath): bool
    {
        $root = realpath(self::root());
        $absolute = realpath(self::root() . '/' . ltrim($relativePath, '/'));

        if ($absolute === false) {
            return true;
        }
        if ($root === false || !str_starts_with($absolute, $root . '/') || !is_file($absolute)) {
            return false;
        }

        return unlink($absolute);
    }

    public static function randomFilename(string $originalName, string $fallbackExt = 'jpg'): string
    {
        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: '';
        if ($ext === '') {
            $ext = $fallbackExt;
        }

        return bin2hex(random_bytes(16)) . '.' . $ext;
    }
}
