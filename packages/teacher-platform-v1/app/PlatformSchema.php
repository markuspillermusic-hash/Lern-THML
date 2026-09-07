<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

/** Additive shared identities. Existing teacher IDs, hashes and rooms survive. */
final class PlatformSchema
{
    public static function migrate(PDO $db): void
    {
        $db->beginTransaction();
        try {
            $db->exec(<<<'SQL'
CREATE TABLE platform_principals (
  subject TEXT PRIMARY KEY,
  kind TEXT NOT NULL CHECK(kind IN ('teacher','student')),
  teacher_user_id INTEGER UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')),
  auth_version TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  CHECK((kind='teacher' AND teacher_user_id IS NOT NULL) OR (kind='student' AND teacher_user_id IS NULL))
);
CREATE TABLE platform_product_grants (
  subject TEXT NOT NULL REFERENCES platform_principals(subject) ON DELETE CASCADE,
  product TEXT NOT NULL CHECK(product IN ('learning','assessment')),
  enabled INTEGER NOT NULL CHECK(enabled IN (0,1)),
  updated_by INTEGER REFERENCES users(id),
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(subject,product)
);
CREATE TABLE platform_org_products (
  organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
  product TEXT NOT NULL CHECK(product IN ('learning','assessment')),
  enabled INTEGER NOT NULL CHECK(enabled IN (0,1)),
  updated_at INTEGER NOT NULL,
  PRIMARY KEY(organisation_id,product)
);
CREATE TABLE platform_learners (
  subject TEXT PRIMARY KEY REFERENCES platform_principals(subject) ON DELETE CASCADE,
  organisation_id INTEGER NOT NULL REFERENCES organisations(id),
  username TEXT NOT NULL COLLATE NOCASE,
  display_name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  must_change_password INTEGER NOT NULL DEFAULT 1 CHECK(must_change_password IN (0,1)),
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  UNIQUE(organisation_id,username)
);
CREATE TABLE platform_classes (
  id TEXT PRIMARY KEY,
  organisation_id INTEGER NOT NULL REFERENCES organisations(id),
  label TEXT NOT NULL,
  school_year TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','archived')),
  created_by INTEGER NOT NULL REFERENCES users(id),
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  UNIQUE(organisation_id,label,school_year)
);
CREATE TABLE platform_class_teachers (
  class_id TEXT NOT NULL REFERENCES platform_classes(id) ON DELETE CASCADE,
  teacher_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK(role IN ('owner','editor','viewer')),
  PRIMARY KEY(class_id,teacher_user_id)
);
CREATE TABLE platform_class_learners (
  class_id TEXT NOT NULL REFERENCES platform_classes(id) ON DELETE CASCADE,
  subject TEXT NOT NULL REFERENCES platform_learners(subject) ON DELETE CASCADE,
  roster_number INTEGER NOT NULL CHECK(roster_number BETWEEN 1 AND 999),
  active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
  PRIMARY KEY(class_id,subject),
  UNIQUE(class_id,roster_number)
);
CREATE TABLE platform_clients (
  client_id TEXT PRIMARY KEY,
  label TEXT NOT NULL,
  organisation_id INTEGER NOT NULL REFERENCES organisations(id),
  product TEXT NOT NULL CHECK(product IN ('learning','assessment')),
  base_url TEXT NOT NULL,
  redirect_uris_json TEXT NOT NULL,
  secret_hash TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended')),
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE platform_oidc_codes (
  code_hash TEXT PRIMARY KEY,
  client_id TEXT NOT NULL REFERENCES platform_clients(client_id) ON DELETE CASCADE,
  subject TEXT NOT NULL REFERENCES platform_principals(subject) ON DELETE CASCADE,
  auth_version TEXT NOT NULL,
  redirect_uri TEXT NOT NULL,
  challenge TEXT NOT NULL,
  nonce TEXT NOT NULL,
  scope TEXT NOT NULL,
  auth_time INTEGER NOT NULL,
  session_key TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  consumed_at INTEGER
);
CREATE INDEX platform_oidc_code_expiry ON platform_oidc_codes(expires_at);
CREATE TABLE platform_oidc_tokens (
  token_hash TEXT PRIMARY KEY,
  client_id TEXT NOT NULL REFERENCES platform_clients(client_id) ON DELETE CASCADE,
  subject TEXT NOT NULL REFERENCES platform_principals(subject) ON DELETE CASCADE,
  auth_version TEXT NOT NULL,
  scope TEXT NOT NULL,
  session_key TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  revoked_at INTEGER
);
CREATE INDEX platform_oidc_token_expiry ON platform_oidc_tokens(expires_at);
CREATE TABLE platform_external_links (
  client_id TEXT NOT NULL REFERENCES platform_clients(client_id),
  entity_type TEXT NOT NULL CHECK(entity_type IN ('teacher','student','class')),
  external_id TEXT NOT NULL,
  platform_id TEXT NOT NULL,
  created_by INTEGER NOT NULL REFERENCES users(id),
  created_at INTEGER NOT NULL,
  PRIMARY KEY(client_id,entity_type,external_id),
  UNIQUE(client_id,entity_type,platform_id)
);
SQL);
            $insert = $db->prepare('INSERT INTO platform_principals(subject,kind,teacher_user_id,auth_version,created_at) VALUES(?,"teacher",?,?,?)');
            $grant = $db->prepare('INSERT INTO platform_product_grants(subject,product,enabled,updated_at) VALUES(?,"learning",1,?)');
            foreach ($db->query('SELECT id FROM users')->fetchAll(PDO::FETCH_ASSOC) as $user) {
                $subject = bin2hex(random_bytes(16));
                $insert->execute([$subject, (int)$user['id'], bin2hex(random_bytes(16)), time()]);
                $grant->execute([$subject, time()]);
            }
            $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(5,?)')->execute([time()]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
