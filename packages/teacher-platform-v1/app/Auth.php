<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

final class Auth
{
    public static function userCount(): int
    {
        return (int)Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    // Existing lesson adapters use this method without an argument: they retain
    // a learning-specific contract. Only shared account pages pass null.
    public static function currentUser(?string $product = 'learning'): ?array
    {
        Security::startSession();
        $userId = (int)($_SESSION['platform_user_id'] ?? 0);
        $authVersion = (string)($_SESSION['platform_auth_version'] ?? '');
        if ($userId < 1 || $authVersion === '') return null;
        $statement = Database::connection()->prepare('SELECT u.* FROM users u LEFT JOIN platform_principals p ON p.teacher_user_id=u.id WHERE u.id=? AND u.status="active" AND (p.subject IS NULL OR p.status="active")');
        $statement->execute([$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || !hash_equals((string)$user['auth_version'], $authVersion)) {
            self::logout();
            return null;
        }
        if ($product !== null && !Identity::allows(Identity::teacher((int)$user['id']), $product)) return null;
        return $user;
    }

    public static function requireUser(?string $product = 'learning'): array
    {
        $user = self::currentUser($product);
        if (!$user) {
            $next = rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/lehrer/'));
            header('Location: /lehrer/?next=' . $next);
            exit;
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if (($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            throw new \RuntimeException('Diese Funktion ist nur für Administratoren freigegeben.');
        }
        return $user;
    }

    public static function login(string $identity, string $password, string $mfaCode = ''): array
    {
        $identity = strtolower(Security::clean($identity, 190));
        $subject = Security::clientIpHash('login|' . strtolower($identity));
        $allowed = Security::rateLimit(
            'login',
            $subject,
            (int)Config::get('login_window_seconds', 900),
            (int)Config::get('login_max_attempts', 7)
        );
        if (!$allowed) throw new \RuntimeException('Zu viele Anmeldeversuche. Bitte später erneut versuchen.');
        $accountSubject = hash_hmac('sha256', 'login-account|' . $identity, Vault::masterKey());
        if (!Security::rateLimit('login-account', $accountSubject, 900, 14)) {
            throw new \RuntimeException('Für dieses Konto gab es zu viele Anmeldeversuche. Bitte später erneut versuchen oder das Passwort zurücksetzen.');
        }
        $statement = Database::connection()->prepare('SELECT u.* FROM users u LEFT JOIN platform_principals p ON p.teacher_user_id=u.id WHERE (u.username=? OR u.email=?) AND (p.subject IS NULL OR p.status="active") LIMIT 1');
        $statement->execute([$identity, $identity]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || ($user['status'] ?? '') !== 'active' || !password_verify($password, (string)$user['password_hash'])) {
            throw new \RuntimeException('Benutzername/E-Mail oder Passwort stimmt nicht.');
        }
        if (!empty($user['mfa_enabled_at']) && !Mfa::verifyForUser($user, $mfaCode)) {
            throw new \RuntimeException('Der zusätzliche Bestätigungscode oder Wiederherstellungscode fehlt oder stimmt nicht.');
        }
        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_ARGON2ID)) {
            $rehash = Database::connection()->prepare('UPDATE users SET password_hash=?,updated_at=? WHERE id=?');
            $rehash->execute([password_hash($password, PASSWORD_ARGON2ID), time(), (int)$user['id']]);
        }
        Security::startSession();
        Oidc::revokeBrowserSession();
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['platform_user_id'] = (int)$user['id'];
        $_SESSION['platform_auth_version'] = (string)$user['auth_version'];
        $_SESSION['platform_last_seen'] = time();
        $_SESSION['platform_started_at'] = time();
        $_SESSION['platform_regenerated_at'] = time();
        $update = Database::connection()->prepare('UPDATE users SET last_login_at=?, updated_at=? WHERE id=?');
        $update->execute([time(), time(), (int)$user['id']]);
        Audit::record((int)$user['id'], 'auth.login', 'user', (string)$user['id']);
        return $user;
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) Security::startSession();
        $userId = (int)($_SESSION['platform_user_id'] ?? 0);
        Oidc::revokeBrowserSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', true, true);
        }
        session_destroy();
        if ($userId > 0) Audit::record($userId, 'auth.logout', 'user', (string)$userId);
    }

    public static function createInitialAdmin(array $input): int
    {
        if (self::userCount() !== 0) throw new \RuntimeException('Die Ersteinrichtung ist bereits abgeschlossen.');
        if (!self::verifyLegacyPassword((string)($input['legacy_password'] ?? ''))) {
            throw new \RuntimeException('Das bisherige Lehrerpasswort stimmt nicht.');
        }
        $displayName = Security::clean($input['display_name'] ?? '', 100);
        $username = strtolower(Security::clean($input['username'] ?? '', 60));
        $email = strtolower(Security::clean($input['email'] ?? '', 190));
        $password = (string)($input['password'] ?? '');
        $school = Security::clean($input['school'] ?? 'Eigene Schule', 160) ?: 'Eigene Schule';
        self::validateNewAccount($displayName, $username, $email, $password);
        $db = Database::connection();
        $now = time();
        $db->beginTransaction();
        try {
            $slug = self::uniqueOrgSlug($school);
            $org = $db->prepare('INSERT INTO organisations(name,slug,kind,status,monthly_request_limit,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
            $org->execute([$school, $slug, 'school', 'active', (int)Config::get('feedback_org_month_limit', 2000), $now, $now]);
            $orgId = (int)$db->lastInsertId();
            $user = $db->prepare('INSERT INTO users(username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at,email_verified_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $user->execute([$username, $email, $displayName, password_hash($password, PASSWORD_ARGON2ID), 'admin', 'active', bin2hex(random_bytes(16)), $now, $now, $now]);
            $userId = (int)$db->lastInsertId();
            $membership = $db->prepare('INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,is_default,created_at) VALUES(?,?,?,?,?)');
            $membership->execute([$userId, $orgId, 'owner', 1, $now]);
            $db->commit();
            Audit::record($userId, 'setup.completed', 'user', (string)$userId, ['organisation_id' => $orgId]);
            return $userId;
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    public static function createFromInvitation(string $token, array $input): int
    {
        $hash = hash('sha256', $token);
        $statement = Database::connection()->prepare('SELECT * FROM invitations WHERE token_hash=? AND used_at IS NULL AND revoked_at IS NULL AND expires_at>?');
        $statement->execute([$hash, time()]);
        $invite = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($invite)) throw new \RuntimeException('Die Einladung ist ungültig oder abgelaufen.');
        $displayName = Security::clean($input['display_name'] ?? $invite['display_name'], 100);
        $username = strtolower(Security::clean($input['username'] ?? '', 60));
        $email = strtolower((string)$invite['email']);
        $password = (string)($input['password'] ?? '');
        self::validateNewAccount($displayName, $username, $email, $password);
        $db = Database::connection();
        $duplicate = $db->prepare('SELECT username,email FROM users WHERE username=? OR email=? LIMIT 1');
        $duplicate->execute([$username, $email]);
        $existing = $duplicate->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            if (strtolower((string)$existing['email']) === $email) {
                throw new \RuntimeException('Für diese E-Mail-Adresse besteht bereits ein Lehrerprofil.');
            }
            throw new \RuntimeException('Dieser Benutzername ist bereits vergeben.');
        }
        $now = time();
        $db->beginTransaction();
        try {
            $preference = $db->prepare('SELECT platform_updates_opt_in,platform_updates_opted_at FROM access_requests WHERE invitation_id=? LIMIT 1');
            $preference->execute([(int)$invite['id']]);
            $preferenceRow = $preference->fetch(PDO::FETCH_ASSOC) ?: [];
            $updatesOptIn = empty($preferenceRow['platform_updates_opt_in']) ? 0 : 1;
            $updatesOptedAt = $updatesOptIn ? (int)($preferenceRow['platform_updates_opted_at'] ?: $now) : null;
            $user = $db->prepare('INSERT INTO users(username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at,email_verified_at,platform_updates_opt_in,platform_updates_opted_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $user->execute([$username, $email, $displayName, password_hash($password, PASSWORD_ARGON2ID), $invite['role'], 'active', bin2hex(random_bytes(16)), $now, $now, $now, $updatesOptIn, $updatesOptedAt]);
            $userId = (int)$db->lastInsertId();
            if ((int)($invite['organisation_id'] ?? 0) > 0) {
                $membership = $db->prepare('INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,is_default,created_at) VALUES(?,?,?,?,?)');
                $membership->execute([$userId, (int)$invite['organisation_id'], 'teacher', 1, $now]);
            }
            $used = $db->prepare('UPDATE invitations SET used_at=? WHERE id=? AND used_at IS NULL');
            $used->execute([$now, (int)$invite['id']]);
            $request = $db->prepare('UPDATE access_requests SET registered_user_id=?,registered_at=?,updated_at=? WHERE invitation_id=? AND status="approved" AND registered_at IS NULL');
            $request->execute([$userId, $now, $now, (int)$invite['id']]);
            $db->commit();
            Audit::record($userId, 'invite.accepted', 'invitation', (string)$invite['id']);
            return $userId;
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    public static function defaultOrganisationId(int $userId): int
    {
        if ($userId < 1) return 0;
        $statement = Database::connection()->prepare('SELECT organisation_id FROM organisation_memberships WHERE user_id=? ORDER BY is_default DESC, created_at ASC LIMIT 1');
        $statement->execute([$userId]);
        return (int)($statement->fetchColumn() ?: 0);
    }

    public static function defaultOrganisation(int $userId): ?array
    {
        $statement = Database::connection()->prepare('SELECT o.*, m.membership_role FROM organisations o JOIN organisation_memberships m ON m.organisation_id=o.id WHERE m.user_id=? ORDER BY m.is_default DESC, m.created_at ASC LIMIT 1');
        $statement->execute([$userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function isAdmin(?array $user): bool
    {
        return is_array($user) && ($user['role'] ?? '') === 'admin';
    }

    public static function validatePassword(string $password): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if ($length < 15) {
            throw new \RuntimeException('Das Passwort braucht mindestens 15 Zeichen. Ein langer, gut merkbarer Passwortsatz ist geeignet.');
        }
        if ($length > 256) throw new \RuntimeException('Das Passwort darf höchstens 256 Zeichen lang sein.');
        if (str_contains($password, "\0")) throw new \RuntimeException('Das Passwort enthält ein ungültiges Zeichen.');
    }

    private static function verifyLegacyPassword(string $password): bool
    {
        $file = (string)Config::get('legacy_auth_file');
        $source = @file_get_contents($file);
        if (!is_string($source)) throw new \RuntimeException('Die bisherige Lehreranmeldung konnte nicht geprüft werden.');
        if (!preg_match("/const\\s+TEACHER_PASSWORD_SALT\\s*=\\s*'([a-f0-9]+)'/i", $source, $saltMatch)
            || !preg_match("/const\\s+TEACHER_PASSWORD_HASH\\s*=\\s*'([a-f0-9]+)'/i", $source, $hashMatch)
            || !preg_match('/const\\s+TEACHER_PASSWORD_ITERATIONS\\s*=\\s*(\\d+)/', $source, $iterationsMatch)) {
            throw new \RuntimeException('Die bisherige Lehreranmeldung hat ein unbekanntes Format.');
        }
        $salt = hex2bin($saltMatch[1]);
        if (!is_string($salt)) return false;
        $candidate = hash_pbkdf2('sha256', $password, $salt, (int)$iterationsMatch[1], 64, false);
        return hash_equals(strtolower($hashMatch[1]), strtolower($candidate));
    }

    private static function validateNewAccount(string $displayName, string $username, string $email, string $password): void
    {
        if (strlen($displayName) < 2) throw new \RuntimeException('Bitte einen vollständigen Anzeigenamen eingeben.');
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $username)) throw new \RuntimeException('Der Benutzername braucht mindestens drei Zeichen und darf Buchstaben, Zahlen, Punkt, Unterstrich und Gedankenstrich enthalten.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Bitte eine gültige E-Mail-Adresse eingeben.');
        self::validatePassword($password);
    }

    private static function uniqueOrgSlug(string $name): string
    {
        $slug = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');
        $slug = substr($slug ?: 'schule', 0, 45);
        $candidate = $slug;
        $counter = 2;
        $check = Database::connection()->prepare('SELECT 1 FROM organisations WHERE slug=?');
        while (true) {
            $check->execute([$candidate]);
            if (!$check->fetchColumn()) return $candidate;
            $candidate = substr($slug, 0, 40) . '-' . $counter++;
        }
    }
}
