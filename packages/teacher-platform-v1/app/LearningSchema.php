<?php
declare(strict_types=1);
namespace ReligionPlatform;
final class LearningSchema
{
    public static function migrate(\PDO $db): void
    {
        $db->beginTransaction();
        try {
            $db->exec(<<<'SQL'
CREATE TABLE learning_assignments (
 id TEXT PRIMARY KEY,
 class_id TEXT NOT NULL REFERENCES platform_classes(id) ON DELETE CASCADE,
 module_slug TEXT NOT NULL REFERENCES modules(slug),
 label TEXT NOT NULL,
 room_code TEXT REFERENCES rooms(code) ON DELETE SET NULL,
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','closed')),
 created_by INTEGER NOT NULL REFERENCES users(id),
 created_at INTEGER NOT NULL,
 retain_until INTEGER NOT NULL
);
CREATE INDEX learning_assignment_class ON learning_assignments(class_id,module_slug);
CREATE TABLE learning_work (
 assignment_id TEXT NOT NULL REFERENCES learning_assignments(id) ON DELETE CASCADE,
 subject TEXT NOT NULL REFERENCES platform_learners(subject) ON DELETE CASCADE,
 revision INTEGER NOT NULL CHECK(revision>0),
 payload_cipher TEXT NOT NULL,
 payload_hash TEXT NOT NULL,
 field_count INTEGER NOT NULL,
 updated_at INTEGER NOT NULL,
 PRIMARY KEY(assignment_id,subject)
);
CREATE TABLE learning_work_history (
 assignment_id TEXT NOT NULL,
 subject TEXT NOT NULL,
 revision INTEGER NOT NULL,
 payload_cipher TEXT NOT NULL,
 updated_at INTEGER NOT NULL,
 PRIMARY KEY(assignment_id,subject,revision),
 FOREIGN KEY(assignment_id,subject) REFERENCES learning_work(assignment_id,subject) ON DELETE CASCADE
);
CREATE TABLE learning_projections (
 room_code TEXT PRIMARY KEY REFERENCES rooms(code) ON DELETE CASCADE,
 assignment_id TEXT NOT NULL,
 subject TEXT NOT NULL,
 selection_cipher TEXT NOT NULL,
 published_by INTEGER NOT NULL REFERENCES users(id),
 revision TEXT NOT NULL,
 expires_at INTEGER NOT NULL,
 FOREIGN KEY(assignment_id,subject) REFERENCES learning_work(assignment_id,subject) ON DELETE CASCADE
);
SQL);
            $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(6,?)')->execute([time()]);
            $db->commit();
        } catch(\Throwable $error) {$db->rollBack();throw $error;}
    }
}
