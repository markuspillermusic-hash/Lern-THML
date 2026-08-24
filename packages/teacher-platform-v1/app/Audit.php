<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class Audit
{
    public static function record(?int $actorId, string $event, string $entityType, string $entityId, array $metadata = []): void
    {
        $allowed = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_.-]{1,50}$/', $key)) continue;
            if (is_scalar($value) || $value === null) $allowed[$key] = $value;
        }
        $statement = Database::connection()->prepare(
            'INSERT INTO audit_log(actor_user_id,event_type,entity_type,entity_id,metadata_json,created_at) VALUES(?,?,?,?,?,?)'
        );
        $statement->execute([
            $actorId,
            Security::clean($event, 80),
            Security::clean($entityType, 60),
            Security::clean($entityId, 100),
            json_encode($allowed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            time(),
        ]);
    }
}

