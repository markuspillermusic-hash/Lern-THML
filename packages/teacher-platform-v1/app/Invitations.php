<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class Invitations
{
    public static function create(string $email, string $displayName, string $role, int $organisationId, int $createdBy, int $days = 14): string
    {
        $email = strtolower(Security::clean($email, 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Die Einladungsadresse ist ungültig.');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $statement = Database::connection()->prepare('INSERT INTO invitations(token_hash,email,display_name,role,organisation_id,created_by,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?)');
        $statement->execute([
            hash('sha256', $token),
            $email,
            Security::clean($displayName, 100),
            $role === 'admin' ? 'admin' : 'teacher',
            $organisationId ?: null,
            $createdBy,
            time() + max(1, min(30, $days)) * 86400,
            time(),
        ]);
        $url = rtrim((string)Config::get('base_url'), '/') . '/lehrer/?invite=' . rawurlencode($token);
        try {
            Mailer::send($email, 'Einladung zum Lehrerbereich', "Sie wurden zum Lehrerbereich der LernHTML-Plattform eingeladen.\n\nEinladung annehmen:\n{$url}\n\nDer Link ist 14 Tage gültig. Falls Sie die Einladung nicht erwartet haben, ignorieren Sie diese Nachricht.");
        } catch (\Throwable $error) {
            Audit::record($createdBy, 'mail.failed', 'invitation', $email, ['reason' => $error->getMessage()]);
        }
        Audit::record($createdBy, 'invite.created', 'invitation', $email, ['organisation_id' => $organisationId]);
        return $url;
    }
}
