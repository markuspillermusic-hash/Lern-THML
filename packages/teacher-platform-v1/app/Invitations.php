<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class Invitations
{
    /**
     * @return array{id:int,url:string,email_sent:bool,expires_at:int,error:string}
     */
    public static function create(string $email, string $displayName, string $role, int $organisationId, int $createdBy, int $days = 14, ?int $accessRequestId = null): array
    {
        $email = strtolower(Security::clean($email, 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Die Einladungsadresse ist ungültig.');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = time() + max(1, min(30, $days)) * 86400;
        $statement = Database::connection()->prepare('INSERT INTO invitations(token_hash,email,display_name,role,organisation_id,created_by,expires_at,created_at,access_request_id) VALUES(?,?,?,?,?,?,?,?,?)');
        $statement->execute([
            hash('sha256', $token),
            $email,
            Security::clean($displayName, 100),
            $role === 'admin' ? 'admin' : 'teacher',
            $organisationId ?: null,
            $createdBy,
            $expiresAt,
            time(),
            $accessRequestId,
        ]);
        $id = (int)Database::connection()->lastInsertId();
        $url = rtrim((string)Config::get('base_url'), '/') . '/lehrer/?invite=' . rawurlencode($token);
        $sent = false;
        $errorMessage = '';
        try {
            if (!Mailer::configured()) throw new \RuntimeException('Der Mailversand ist noch nicht eingerichtet.');
            Mailer::send($email, 'Einladung zum Lehrerbereich', "Guten Tag {$displayName},\n\nIhre Zugriffsanfrage für die Religions-Lernpfade wurde genehmigt. Über den folgenden persönlichen Link können Sie Ihr Lehrerprofil anlegen:\n\n{$url}\n\nDer Link ist bis zum " . date('d.m.Y H:i', $expiresAt) . " Uhr gültig und kann nur einmal verwendet werden. Falls Sie diese Einladung nicht erwartet haben, ignorieren Sie diese Nachricht.\n\nReligionsunterricht · Lehrerplattform");
            $sent = true;
        } catch (\Throwable $error) {
            $errorMessage = Security::clean($error->getMessage(), 300);
            Audit::record($createdBy, 'mail.failed', 'invitation', (string)$id, ['reason' => $errorMessage]);
        }
        $delivery = Database::connection()->prepare('UPDATE invitations SET email_sent_at=?,email_attempts=email_attempts+1,email_last_error=? WHERE id=?');
        $delivery->execute([$sent ? time() : null, $errorMessage, $id]);
        Audit::record($createdBy, 'invite.created', 'invitation', (string)$id, ['organisation_id' => $organisationId, 'mail_sent' => $sent]);
        return ['id' => $id, 'url' => $url, 'email_sent' => $sent, 'expires_at' => $expiresAt, 'error' => $errorMessage];
    }

    public static function revokeForAccessRequest(int $accessRequestId, int $actorId): void
    {
        $statement = Database::connection()->prepare('UPDATE invitations SET revoked_at=? WHERE access_request_id=? AND used_at IS NULL AND revoked_at IS NULL');
        $statement->execute([time(), $accessRequestId]);
        if ($statement->rowCount() > 0) {
            Audit::record($actorId, 'invite.revoked', 'access_request', (string)$accessRequestId, ['count' => $statement->rowCount()]);
        }
    }
}
