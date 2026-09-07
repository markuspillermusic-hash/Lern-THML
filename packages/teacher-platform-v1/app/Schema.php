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
        if ($version < 2) self::versionTwo($db);
        if ($version < 3) self::versionThree($db);
        if ($version < 4) self::versionFour($db);
        if ($version < 5) PlatformSchema::migrate($db);
        if ($version < 6) LearningSchema::migrate($db);
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

    private static function versionTwo(PDO $db): void
    {
        $db->beginTransaction();
        try {
            $db->exec(<<<'SQL'
ALTER TABLE access_requests ADD COLUMN decided_at INTEGER;
ALTER TABLE access_requests ADD COLUMN decided_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE access_requests ADD COLUMN invitation_id INTEGER REFERENCES invitations(id) ON DELETE SET NULL;
ALTER TABLE access_requests ADD COLUMN registered_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE access_requests ADD COLUMN registered_at INTEGER;
ALTER TABLE access_requests ADD COLUMN notification_sent_at INTEGER;
ALTER TABLE access_requests ADD COLUMN notification_last_error TEXT NOT NULL DEFAULT '';

ALTER TABLE invitations ADD COLUMN access_request_id INTEGER REFERENCES access_requests(id) ON DELETE SET NULL;
ALTER TABLE invitations ADD COLUMN email_sent_at INTEGER;
ALTER TABLE invitations ADD COLUMN email_attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE invitations ADD COLUMN email_last_error TEXT NOT NULL DEFAULT '';
ALTER TABLE invitations ADD COLUMN revoked_at INTEGER;

CREATE INDEX idx_access_requests_invitation ON access_requests(invitation_id);
CREATE INDEX idx_access_requests_registered ON access_requests(registered_user_id);
CREATE INDEX idx_invitations_request ON invitations(access_request_id, created_at DESC);
SQL);

            // Bestehende Genehmigungen soweit möglich wieder mit ihrer Einladung
            // und einem bereits angelegten Konto verknüpfen.
            $db->exec(<<<'SQL'
UPDATE access_requests
SET invitation_id = (
  SELECT i.id FROM invitations i
  WHERE lower(i.email) = lower(access_requests.email)
    AND i.created_at >= access_requests.updated_at - 300
  ORDER BY i.created_at DESC LIMIT 1
)
WHERE status = 'approved' AND invitation_id IS NULL;

UPDATE invitations
SET access_request_id = (
  SELECT ar.id FROM access_requests ar
  WHERE ar.invitation_id = invitations.id
  LIMIT 1
)
WHERE access_request_id IS NULL;

UPDATE access_requests
SET registered_user_id = (
      SELECT u.id FROM users u WHERE lower(u.email) = lower(access_requests.email) LIMIT 1
    ),
    registered_at = COALESCE((
      SELECT i.used_at FROM invitations i WHERE i.id = access_requests.invitation_id
    ), updated_at)
WHERE status = 'approved'
  AND registered_user_id IS NULL
  AND EXISTS (SELECT 1 FROM users u WHERE lower(u.email) = lower(access_requests.email));
SQL);

            $statement = $db->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(2, ?)');
            $statement->execute([time()]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    private static function versionThree(PDO $db): void
    {
        $db->beginTransaction();
        try {
            $db->exec(<<<'SQL'
ALTER TABLE users ADD COLUMN email_verified_at INTEGER;
ALTER TABLE users ADD COLUMN mfa_enabled_at INTEGER;

CREATE TABLE password_reset_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  requested_ip_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  used_at INTEGER,
  revoked_at INTEGER,
  created_at INTEGER NOT NULL
);

CREATE INDEX idx_password_reset_user ON password_reset_tokens(user_id, created_at DESC);
CREATE INDEX idx_password_reset_expiry ON password_reset_tokens(expires_at);

CREATE TABLE support_tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id TEXT NOT NULL UNIQUE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  email TEXT NOT NULL COLLATE NOCASE,
  category TEXT NOT NULL CHECK(category IN ('login','room','presentation','ai','content','privacy','security','other')),
  subject TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','waiting_user','resolved','closed')),
  priority TEXT NOT NULL DEFAULT 'normal' CHECK(priority IN ('normal','high','urgent')),
  assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
  ip_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  closed_at INTEGER
);

CREATE INDEX idx_support_status ON support_tickets(status, updated_at DESC);
CREATE INDEX idx_support_user ON support_tickets(user_id, updated_at DESC);

CREATE TABLE support_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
  author_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  author_role TEXT NOT NULL CHECK(author_role IN ('visitor','teacher','admin','system')),
  body TEXT NOT NULL,
  is_internal INTEGER NOT NULL DEFAULT 0 CHECK(is_internal IN (0,1)),
  created_at INTEGER NOT NULL
);

CREATE INDEX idx_support_messages_ticket ON support_messages(ticket_id, created_at ASC);

CREATE TABLE ai_grants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  label TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','revoked')),
  starts_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,
  request_limit INTEGER NOT NULL DEFAULT 30,
  token_limit INTEGER NOT NULL DEFAULT 60000,
  used_requests INTEGER NOT NULL DEFAULT 0,
  used_tokens INTEGER NOT NULL DEFAULT 0,
  allowed_modules_json TEXT NOT NULL DEFAULT '[]',
  created_by INTEGER NOT NULL REFERENCES users(id),
  revoked_at INTEGER,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE INDEX idx_ai_grants_user ON ai_grants(user_id, status, expires_at DESC);

ALTER TABLE feedback_usage ADD COLUMN grant_id INTEGER REFERENCES ai_grants(id) ON DELETE SET NULL;

UPDATE users
SET email_verified_at = COALESCE(email_verified_at, created_at)
WHERE email_verified_at IS NULL;

-- Konten, die sich ausdrücklich als extern oder institutionell registriert
-- haben, dürfen keine aus der alten Genehmigungslogik geerbte
-- Schulmitgliedschaft behalten.
DELETE FROM organisation_memberships
WHERE user_id IN (
  SELECT registered_user_id
  FROM access_requests
  WHERE access_type IN ('external','institution')
    AND registered_user_id IS NOT NULL
);
SQL);

            $statement = $db->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(3, ?)');
            $statement->execute([time()]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    private static function versionFour(PDO $db): void
    {
        $db->beginTransaction();
        try {
            $db->exec(<<<'SQL'
ALTER TABLE access_requests ADD COLUMN platform_updates_opt_in INTEGER NOT NULL DEFAULT 0 CHECK(platform_updates_opt_in IN (0,1));
ALTER TABLE access_requests ADD COLUMN platform_updates_opted_at INTEGER;
ALTER TABLE access_requests ADD COLUMN platform_updates_withdrawn_at INTEGER;

ALTER TABLE users ADD COLUMN platform_updates_opt_in INTEGER NOT NULL DEFAULT 0 CHECK(platform_updates_opt_in IN (0,1));
ALTER TABLE users ADD COLUMN platform_updates_opted_at INTEGER;
ALTER TABLE users ADD COLUMN platform_updates_withdrawn_at INTEGER;
SQL);

            $statement = $db->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(4, ?)');
            $statement->execute([time()]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
