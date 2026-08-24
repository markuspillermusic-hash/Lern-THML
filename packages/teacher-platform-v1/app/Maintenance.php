<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class Maintenance
{
    public static function run(?int $now = null): array
    {
        $now ??= time();
        $db = Database::connection();
        $rules = [
            'rate_limits' => ['DELETE FROM rate_limits WHERE window_started_at < ?', $now - 2 * 86400],
            'invitations' => ['DELETE FROM invitations WHERE (used_at IS NOT NULL AND used_at < ?) OR (used_at IS NULL AND expires_at < ?)', [$now - 90 * 86400, $now - 30 * 86400]],
            'access_requests' => ["DELETE FROM access_requests WHERE status IN ('approved','rejected','archived') AND updated_at < ?", $now - 180 * 86400],
            'feedback_usage' => ['DELETE FROM feedback_usage WHERE created_at < ?', $now - 400 * 86400],
            'audit_log' => ['DELETE FROM audit_log WHERE created_at < ?', $now - 400 * 86400],
            'room_mirrors' => ['DELETE FROM rooms WHERE expires_at < ? OR (ended_at IS NOT NULL AND ended_at < ?)', [$now - 30 * 86400, $now - 30 * 86400]],
        ];
        $result = [];
        foreach ($rules as $name => [$sql, $parameters]) {
            $statement = $db->prepare($sql);
            $statement->execute(is_array($parameters) ? $parameters : [$parameters]);
            $result[$name] = $statement->rowCount();
        }
        $result['room_files'] = self::cleanupRoomFiles((string)Config::get('data_dir') . '/modules', $now);
        return $result;
    }

    private static function cleanupRoomFiles(string $moduleRoot, int $now): int
    {
        if (!is_dir($moduleRoot)) return 0;
        $removed = 0;
        $pattern = $moduleRoot . '/*/rooms/room-*.json';
        foreach (glob($pattern) ?: [] as $path) {
            if (!is_file($path) || !preg_match('/^room-[A-HJ-NP-Z2-9]{6}\.json$/', basename($path))) continue;
            $raw = @file_get_contents($path);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($state)) continue;
            $expiresAt = (int)($state['expiresAt'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < $now && @unlink($path)) $removed++;
        }
        return $removed;
    }
}
