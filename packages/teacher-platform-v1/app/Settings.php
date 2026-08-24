<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class Settings
{
    public static function get(string $key, ?string $fallback = null): ?string
    {
        $statement = Database::connection()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? $fallback : (string)$value;
    }

    public static function set(string $key, string $value): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,80}$/', $key)) throw new \InvalidArgumentException('Ungültiger Einstellungsschlüssel.');
        $statement = Database::connection()->prepare('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(?,?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=excluded.updated_at');
        $statement->execute([$key, $value, time()]);
    }
}

