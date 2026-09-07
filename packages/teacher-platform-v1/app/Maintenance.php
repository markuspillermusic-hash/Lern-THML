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
            'learning_projections' => ['DELETE FROM learning_projections WHERE expires_at < ?', $now],
            'learning_assignments' => ['DELETE FROM learning_assignments WHERE retain_until < ?', $now],
            'oidc_codes' => ['DELETE FROM platform_oidc_codes WHERE expires_at < ?', $now - 600],
            'oidc_tokens' => ['DELETE FROM platform_oidc_tokens WHERE expires_at < ? OR revoked_at < ?', [$now - 86400, $now - 86400]],
            'rate_limits' => ['DELETE FROM rate_limits WHERE window_started_at < ?', $now - 2 * 86400],
            'invitations' => ['DELETE FROM invitations WHERE (used_at IS NOT NULL AND used_at < ?) OR (used_at IS NULL AND expires_at < ?)', [$now - 90 * 86400, $now - 30 * 86400]],
            'access_requests' => ["DELETE FROM access_requests WHERE status IN ('approved','rejected','archived') AND updated_at < ?", $now - 180 * 86400],
            'password_resets' => ['DELETE FROM password_reset_tokens WHERE expires_at < ? OR used_at < ? OR revoked_at < ?', [$now - 7 * 86400, $now - 7 * 86400, $now - 7 * 86400]],
            'support_tickets' => ["DELETE FROM support_tickets WHERE status IN ('resolved','closed') AND closed_at IS NOT NULL AND closed_at < ?", $now - 180 * 86400],
            'ai_grants' => ['DELETE FROM ai_grants WHERE (status="revoked" AND revoked_at < ?) OR expires_at < ?', [$now - 180 * 86400, $now - 180 * 86400]],
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
        $result['teaching_start_files'] = self::cleanupTeachingStarts((string)Config::get('data_dir') . '/teaching-starts', $now);
        return $result;
    }

    private static function cleanupTeachingStarts(string $root, int $now): int
    {
        $removed=0;
        foreach(glob($root.'/*.json') ?: [] as $path) {
            if(is_link($path) || !preg_match('/^[a-f0-9]{64}\.json$/D',basename($path)))continue;
            $handle=@fopen($path,'r+');if(!$handle)continue;
            try {
                if(!flock($handle,LOCK_EX|LOCK_NB))continue;
                $state=json_decode(stream_get_contents($handle,4096) ?: '{}',true);
                $expiry=is_array($state)?(int)($state['expires_at'] ?? 0):0;
                if($expiry>0 && $expiry<$now-86400 && @unlink($path))$removed++;
            } finally {flock($handle,LOCK_UN);fclose($handle);}
        }
        return $removed;
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
