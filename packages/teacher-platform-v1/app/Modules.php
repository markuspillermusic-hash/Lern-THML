<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

final class Modules
{
    public static function syncRegistry(): void
    {
        $registry = require dirname(__DIR__) . '/registry/modules.php';
        if (!is_array($registry)) return;
        $statement = Database::connection()->prepare(<<<'SQL'
INSERT INTO modules(slug,label,description,public_url,teacher_url,beamer_url,status,created_at,updated_at)
VALUES(?,?,?,?,?,?,?,?,?)
ON CONFLICT(slug) DO UPDATE SET label=excluded.label,description=excluded.description,public_url=excluded.public_url,
teacher_url=excluded.teacher_url,beamer_url=excluded.beamer_url,status=excluded.status,updated_at=excluded.updated_at
SQL);
        $now = time();
        foreach ($registry as $slug => $module) {
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,80}$/', (string)$slug) || !is_array($module)) continue;
            $statement->execute([
                $slug,
                Security::clean($module['label'] ?? '', 160),
                Security::clean($module['description'] ?? '', 500),
                Security::clean($module['public_url'] ?? '', 400),
                Security::clean($module['teacher_url'] ?? '', 400),
                Security::clean($module['beamer_url'] ?? '', 400),
                in_array(($module['status'] ?? ''), ['active', 'hidden', 'archived'], true) ? $module['status'] : 'active',
                $now,
                $now,
            ]);
        }
    }

    public static function all(bool $includeHidden = false): array
    {
        self::syncRegistry();
        $sql = 'SELECT * FROM modules' . ($includeHidden ? '' : ' WHERE status="active"') . ' ORDER BY label';
        return Database::connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

