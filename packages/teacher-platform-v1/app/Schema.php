<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

final class Schema
{
    public static function migrate(PDO $db): void
    {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  version INTEGER PRIMARY KEY,
  applied_at INTEGER NOT NULL
);
SQL);
        $version = (int)$db->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
        if ($version < 1) self::versionOne($db);
    }

    private static function versionOne(PDO $db): void
    {
        $db->beginTransaction();
        try {
            $db->exec(<<<'SQL'
CREATE TABLE organisations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  kind TEXT NOT NULL DEFAULT 'school' CHECK(kind IN ('school','independent','institution')),
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')),
  monthly_request_limit INTEGER NOT NULL DEFAULT 2000,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL COLLATE NOCASE UNIQUE,
  email TEXT NOT NULL COLLATE NOCASE UNIQUE,
  display_name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'teacher' CHECK(role IN ('admin','teacher')),
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('pending','active','suspended')),
  auth_version TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  last_login_at INTEGER
);

CREATE TABLE organisation_memberships (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
  membership_role TEXT NOT NULL DEFAULT 'teacher' CHECK(membership_role IN ('owner','manager','teacher')),
  is_default INTEGER NOT NULL DEFAULT 1 CHECK(is_default IN (0,1)),
  created_at INTEGER NOT NULL,
  PRIMARY KEY(user_id, organisation_id)
);

CREATE TABLE encrypted_secrets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scope_type TEXT NOT NULL CHECK(scope_type IN ('user','organisation','system')),
  scope_id INTEGER NOT NULL,
  secret_kind TEXT NOT NULL,
  ciphertext TEXT NOT NULL,
  nonce TEXT NOT NULL,
  key_version INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  UNIQUE(scope_type, scope_id, secret_kind)
);

CREATE TABLE access_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL COLLATE NOCASE,
  school TEXT NOT NULL,
  subjects TEXT NOT NULL,
  bundesland TEXT NOT NULL,
  access_type TEXT NOT NULL CHECK(access_type IN ('own-school','external','institution')),
  reason TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','archived')),
  admin_note TEXT NOT NULL DEFAULT '',
  ip_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE INDEX idx_access_requests_status ON access_requests(status, created_at DESC);

CREATE TABLE invitations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash TEXT NOT NULL UNIQUE,
  email TEXT NOT NULL COLLATE NOCASE,
  display_name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'teacher' CHECK(role IN ('admin','teacher')),
  organisation_id INTEGER REFERENCES organisations(id) ON DELETE SET NULL,
  created_by INTEGER NOT NULL REFERENCES users(id),
  expires_at INTEGER NOT NULL,
  used_at INTEGER,
  created_at INTEGER NOT NULL
);

CREATE TABLE modules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  public_url TEXT NOT NULL,
  teacher_url TEXT NOT NULL,
  beamer_url TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','hidden','archived')),
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE rooms (
  code TEXT PRIMARY KEY,
  module_slug TEXT NOT NULL,
  owner_user_id INTEGER NOT NULL REFERENCES users(id),
  organisation_id INTEGER REFERENCES organisations(id),
  label TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,
  ended_at INTEGER,
  ai_feedback_enabled INTEGER NOT NULL DEFAULT 0 CHECK(ai_feedback_enabled IN (0,1)),
  ai_request_limit INTEGER NOT NULL DEFAULT 80,
  ai_request_count INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL
);

CREATE INDEX idx_rooms_owner ON rooms(owner_user_id, expires_at DESC);
CREATE INDEX idx_rooms_org ON rooms(organisation_id, expires_at DESC);

CREATE TABLE feedback_usage (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  room_code TEXT NOT NULL,
  module_slug TEXT NOT NULL,
  task_id TEXT NOT NULL,
  owner_user_id INTEGER NOT NULL,
  organisation_id INTEGER,
  key_scope TEXT NOT NULL,
  model TEXT NOT NULL,
  status TEXT NOT NULL,
  input_tokens INTEGER NOT NULL DEFAULT 0,
  output_tokens INTEGER NOT NULL DEFAULT 0,
  latency_ms INTEGER NOT NULL DEFAULT 0,
  student_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL
);

CREATE INDEX idx_feedback_room ON feedback_usage(room_code, created_at DESC);
CREATE INDEX idx_feedback_user ON feedback_usage(owner_user_id, created_at DESC);
CREATE INDEX idx_feedback_org ON feedback_usage(organisation_id, created_at DESC);

CREATE TABLE rate_limits (
  bucket TEXT NOT NULL,
  subject_hash TEXT NOT NULL,
  window_started_at INTEGER NOT NULL,
  hits INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(bucket, subject_hash, window_started_at)
);

CREATE TABLE settings (
  setting_key TEXT PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_user_id INTEGER,
  event_type TEXT NOT NULL,
  entity_type TEXT NOT NULL,
  entity_id TEXT NOT NULL,
  metadata_json TEXT NOT NULL DEFAULT '{}',
  created_at INTEGER NOT NULL
);

CREATE INDEX idx_audit_created ON audit_log(created_at DESC);
SQL);
            $statement = $db->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(1, ?)');
            $statement->execute([time()]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}

