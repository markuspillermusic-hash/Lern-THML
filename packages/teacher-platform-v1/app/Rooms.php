<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

final class Rooms
{
    public static function mirror(string $code, string $moduleSlug, array $owner, array $state): void
    {
        $code = strtoupper(Security::clean($code, 6));
        if (!preg_match('/^[A-Z2-9]{6}$/', $code) || (int)($owner['id'] ?? 0) < 1) {
            throw new \InvalidArgumentException('Raum oder Besitzer ist ungültig.');
        }
        $now = time();
        $orgId = Auth::defaultOrganisationId((int)$owner['id']) ?: null;
        $statement = Database::connection()->prepare(<<<'SQL'
INSERT INTO rooms(code,module_slug,owner_user_id,organisation_id,label,created_at,expires_at,ended_at,ai_feedback_enabled,ai_request_limit,ai_request_count,updated_at)
VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
ON CONFLICT(code) DO UPDATE SET module_slug=excluded.module_slug,label=excluded.label,expires_at=excluded.expires_at,
ended_at=NULL,ai_feedback_enabled=excluded.ai_feedback_enabled,updated_at=excluded.updated_at
SQL);
        $statement->execute([
            $code,
            $moduleSlug,
            (int)$owner['id'],
            $orgId,
            Security::clean($state['label'] ?? '', 60),
            (int)($state['createdAt'] ?? $now),
            (int)($state['expiresAt'] ?? ($now + (int)Config::get('default_room_days', 42) * 86400)),
            null,
            !empty($state['aiFeedbackEnabled']) ? 1 : 0,
            (int)($state['aiRequestLimit'] ?? Config::get('feedback_room_day_limit', 80)),
            0,
            $now,
        ]);
    }

    public static function find(string $code, bool $activeOnly = true): ?array
    {
        $statement = Database::connection()->prepare('SELECT r.*, u.display_name AS owner_name, o.name AS organisation_name FROM rooms r JOIN users u ON u.id=r.owner_user_id LEFT JOIN organisations o ON o.id=r.organisation_id WHERE r.code=?' . ($activeOnly ? ' AND r.ended_at IS NULL AND r.expires_at>?' : ''));
        $params = [strtoupper($code)];
        if ($activeOnly) $params[] = time();
        $statement->execute($params);
        $room = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($room) ? $room : null;
    }

    public static function listForUser(array $user): array
    {
        $params = [time()];
        $where = 'r.ended_at IS NULL AND r.expires_at>?';
        if (!Auth::isAdmin($user)) {
            $where .= ' AND r.owner_user_id=?';
            $params[] = (int)$user['id'];
        }
        $statement = Database::connection()->prepare('SELECT r.*,m.label AS module_label,m.teacher_url AS module_teacher_url,u.display_name AS owner_name FROM rooms r LEFT JOIN modules m ON m.slug=r.module_slug JOIN users u ON u.id=r.owner_user_id WHERE ' . $where . ' ORDER BY r.created_at DESC');
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function assertOwner(array $room, array $user): void
    {
        if ((int)$room['owner_user_id'] !== (int)$user['id'] && !Auth::isAdmin($user)) {
            throw new \RuntimeException('Dieser Raum gehört zu einer anderen Lehrkraft.');
        }
    }

    public static function setFeedback(string $code, array $user, bool $enabled): array
    {
        $room = self::find($code);
        if (!$room) throw new \RuntimeException('Der Raum ist nicht aktiv.');
        self::assertOwner($room, $user);
        if ($enabled && !Vault::resolveOpenAiKey($user, (int)($room['organisation_id'] ?? 0))) {
            throw new \RuntimeException('Es ist weder ein persönlicher noch ein schulischer API-Schlüssel hinterlegt.');
        }
        $statement = Database::connection()->prepare('UPDATE rooms SET ai_feedback_enabled=?, updated_at=? WHERE code=?');
        $statement->execute([$enabled ? 1 : 0, time(), strtoupper($code)]);
        Audit::record((int)$user['id'], $enabled ? 'room.feedback_enabled' : 'room.feedback_disabled', 'room', strtoupper($code));
        return self::find($code) ?? $room;
    }

    public static function updateMirror(string $code, array $state): void
    {
        $statement = Database::connection()->prepare('UPDATE rooms SET label=?,expires_at=?,ai_feedback_enabled=?,updated_at=? WHERE code=?');
        $statement->execute([
            Security::clean($state['label'] ?? '', 60),
            (int)($state['expiresAt'] ?? time()),
            !empty($state['aiFeedbackEnabled']) ? 1 : 0,
            time(),
            strtoupper($code),
        ]);
    }

    public static function end(string $code, array $user): void
    {
        $room = self::find($code, false);
        if (!$room) return;
        self::assertOwner($room, $user);
        $statement = Database::connection()->prepare('UPDATE rooms SET ended_at=?,updated_at=? WHERE code=?');
        $statement->execute([time(), time(), strtoupper($code)]);
        Audit::record((int)$user['id'], 'room.ended', 'room', strtoupper($code));
    }
}
