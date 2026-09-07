<?php
declare(strict_types=1);

namespace ReligionPlatform;

/** Loads deployment configuration while keeping secrets outside the repository. */
final class Config
{
    private static ?array $values = null;

    public static function all(): array
    {
        if (self::$values !== null) return self::$values;
        $path = getenv('TEACHER_PLATFORM_CONFIG') ?: '/etc/teacher-platform/config.php';
        if (!is_file($path)) $path = dirname(__DIR__) . '/config/config.example.php';
        $config = require $path;
        if (!is_array($config)) throw new \RuntimeException('Ungültige Plattformkonfiguration.');
        self::$values = $config;
        return self::$values;
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        return self::all()[$key] ?? $fallback;
    }

    public static function resetForTests(): void
    {
        self::$values = null;
    }
}
