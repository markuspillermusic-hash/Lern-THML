<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

final class PasswordResets
{
    public static function request(string $identity): void
    {
        $identity = strtolower(Security::clean($identity, 190));
        $subject = Security::clientIpHash('password-reset-request|' . $identity);
        if (!Security::rateLimit('password-reset-request', $subject, 3600, 4)) return;

        $statement = Database::connection()->prepare('SELECT * FROM users WHERE (username=? OR email=?) AND status="active" LIMIT 1');
        $statement->execute([$identity, $identity]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            // Eine kleine konstante Rechenarbeit reduziert den deutlichen
            // Zeitunterschied zwischen bekannten und unbekannten Konten.
            hash_hmac('sha256', $identity, Vault::masterKey());
            return;
        }

        try {
            self::issue($user, null);
        } catch (\Throwable $error) {
            Audit::record(null, 'auth.password_reset_mail_failed', 'user', (string)$user['id'], [
                'reason' => Security::clean($error->getMessage(), 180),
            ]);
        }
    }

    public static function issueForAdmin(int $userId, array $admin): void
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE id=? AND status="active"');
        $statement->execute([$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) throw new \RuntimeException('Für dieses Konto kann kein Passwort-Reset versendet werden.');
        self::issue($user, (int)$admin['id']);
    }

    public static function validToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/', $token)) return null;
        $statement = Database::connection()->prepare(<<<'SQL'
SELECT pr.*,u.email,u.display_name,u.status
FROM password_reset_tokens pr
JOIN users u ON u.id=pr.user_id
WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.revoked_at IS NULL
  AND pr.expires_at>? AND u.status='active'
LIMIT 1
SQL);
        $statement->execute([hash('sha256', $token), time()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function complete(string $token, string $password, string $confirmation): int
    {
        if (!hash_equals($password, $confirmation)) throw new \RuntimeException('Die beiden Passwörter stimmen nicht überein.');
        Auth::validatePassword($password);
        $reset = self::validToken($token);
        if (!$reset) throw new \RuntimeException('Der Wiederherstellungslink ist ungültig oder abgelaufen.');

        $db = Database::connection();
        $now = time();
        $version = bin2hex(random_bytes(16));
        $db->beginTransaction();
        try {
            $update = $db->prepare('UPDATE users SET password_hash=?,auth_version=?,updated_at=? WHERE id=? AND status="active"');
            $update->execute([password_hash($password, PASSWORD_ARGON2ID), $version, $now, (int)$reset['user_id']]);
            if ($update->rowCount() !== 1) throw new \RuntimeException('Das Konto ist nicht mehr aktiv.');
            $used = $db->prepare('UPDATE password_reset_tokens SET used_at=? WHERE id=? AND used_at IS NULL');
            $used->execute([$now, (int)$reset['id']]);
            $revoke = $db->prepare('UPDATE password_reset_tokens SET revoked_at=? WHERE user_id=? AND used_at IS NULL AND revoked_at IS NULL');
            $revoke->execute([$now, (int)$reset['user_id']]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }

        Audit::record((int)$reset['user_id'], 'auth.password_reset_completed', 'user', (string)$reset['user_id']);
        try {
            Mailer::send(
                (string)$reset['email'],
                'Passwort geändert · Lehrerplattform',
                "Guten Tag {$reset['display_name']},\n\ndas Passwort Ihres Lehrerprofils wurde geändert. Alle vorherigen Sitzungen wurden beendet.\n\nFalls Sie diese Änderung nicht selbst vorgenommen haben, antworten Sie bitte sofort auf diese Nachricht.\n\nReligionsunterricht · Lehrerplattform"
            );
        } catch (\Throwable $error) {
            Audit::record((int)$reset['user_id'], 'auth.password_changed_notice_failed', 'user', (string)$reset['user_id']);
        }
        return (int)$reset['user_id'];
    }

    private static function issue(array $user, ?int $actorId): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = time() + 45 * 60;
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $revoke = $db->prepare('UPDATE password_reset_tokens SET revoked_at=? WHERE user_id=? AND used_at IS NULL AND revoked_at IS NULL');
            $revoke->execute([time(), (int)$user['id']]);
            $insert = $db->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,requested_ip_hash,expires_at,created_at) VALUES(?,?,?,?,?)');
            $insert->execute([
                (int)$user['id'],
                hash('sha256', $token),
                Security::clientIpHash('password-reset'),
                $expiresAt,
                time(),
            ]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }

        $url = rtrim((string)Config::get('base_url'), '/') . '/lehrer/?reset=' . rawurlencode($token);
        Mailer::send(
            (string)$user['email'],
            'Passwort zurücksetzen · Lehrerplattform',
            "Guten Tag {$user['display_name']},\n\nüber den folgenden persönlichen Link können Sie ein neues Passwort festlegen:\n\n{$url}\n\nDer Link ist 45 Minuten gültig und kann nur einmal verwendet werden. Falls Sie den Reset nicht angefordert haben, ignorieren Sie diese Nachricht. Ihr bisheriges Passwort bleibt dann unverändert.\n\nReligionsunterricht · Lehrerplattform"
        );
        Audit::record($actorId, 'auth.password_reset_issued', 'user', (string)$user['id']);
    }
}
