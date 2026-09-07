<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

final class AccessRequests
{
    public static function create(array $input): int
    {
        if (!empty($input['website'])) throw new \RuntimeException('Die Anfrage konnte nicht angenommen werden.');
        if (!Security::verifyFormToken((string)($input['form_token'] ?? ''), 'access-request', 7200, 3)) {
            throw new \RuntimeException('Das Formular ist abgelaufen. Bitte die Seite neu laden.');
        }
        $subject = Security::clientIpHash('access-request');
        if (!Security::rateLimit('access-request', $subject, 3600, (int)Config::get('request_form_hour_limit', 4))) {
            throw new \RuntimeException('Von diesem Anschluss kamen zu viele Anfragen. Bitte später erneut versuchen.');
        }
        $name = Security::clean($input['name'] ?? '', 100);
        $email = strtolower(Security::clean($input['email'] ?? '', 190));
        $school = Security::clean($input['school'] ?? '', 180);
        $subjects = Security::clean($input['subjects'] ?? '', 180);
        $bundesland = Security::clean($input['bundesland'] ?? '', 100);
        $accessType = in_array(($input['access_type'] ?? ''), ['own-school', 'external', 'institution'], true) ? $input['access_type'] : '';
        $reason = Security::cleanMultiline($input['reason'] ?? '', 2000);
        $updatesOptIn = empty($input['platform_updates_opt_in']) ? 0 : 1;
        if (strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($school) < 2 || strlen($subjects) < 2 || $bundesland === '' || $accessType === '' || empty($input['privacy_acknowledged'])) {
            throw new \RuntimeException('Bitte alle Pflichtfelder vollständig ausfüllen und die Datenschutzinformationen bestätigen.');
        }
        $now = time();
        $statement = Database::connection()->prepare('INSERT INTO access_requests(name,email,school,subjects,bundesland,access_type,reason,status,ip_hash,created_at,updated_at,platform_updates_opt_in,platform_updates_opted_at) VALUES(?,?,?,?,?,?,?,"pending",?,?,?,?,?)');
        $statement->execute([$name, $email, $school, $subjects, $bundesland, $accessType, $reason, $subject, $now, $now, $updatesOptIn, $updatesOptIn ? $now : null]);
        $id = (int)Database::connection()->lastInsertId();
        Audit::record(null, 'access.requested', 'access_request', (string)$id, [
            'type' => $accessType,
            'platform_updates_opt_in' => (bool)$updatesOptIn,
        ]);
        $recipient = Mailer::recipient();
        if ($recipient !== '' && Mailer::configured()) {
            try {
                Mailer::send($recipient, 'Neue Zugriffsanfrage · Lehrerplattform', "Eine neue Zugriffsanfrage (#{$id}) wurde gespeichert.\n\nBitte prüfen Sie die Angaben im geschützten Administrationsbereich:\n" . Config::get('base_url') . '/lehrer/?view=requests');
                $mailState = Database::connection()->prepare('UPDATE access_requests SET notification_sent_at=?,notification_last_error="" WHERE id=?');
                $mailState->execute([time(), $id]);
            } catch (\Throwable $error) {
                $reason = Security::clean($error->getMessage(), 300);
                $mailState = Database::connection()->prepare('UPDATE access_requests SET notification_last_error=? WHERE id=?');
                $mailState->execute([$reason, $id]);
                Audit::record(null, 'mail.failed', 'access_request', (string)$id, ['reason' => $reason]);
            }
        } elseif ($recipient !== '') {
            $mailState = Database::connection()->prepare('UPDATE access_requests SET notification_last_error=? WHERE id=?');
            $mailState->execute(['Der Mailversand ist noch nicht eingerichtet.', $id]);
        }
        return $id;
    }

    public static function list(string $stage = 'pending'): array
    {
        $stage = in_array($stage, ['pending', 'approved', 'registered', 'rejected', 'archived', 'all'], true) ? $stage : 'pending';
        $where = match ($stage) {
            'pending' => 'ar.status="pending"',
            'approved' => 'ar.status="approved" AND ar.registered_at IS NULL',
            'registered' => 'ar.status="approved" AND ar.registered_at IS NOT NULL',
            'rejected' => 'ar.status="rejected"',
            'archived' => 'ar.status="archived"',
            default => '1=1',
        };
        $statement = Database::connection()->query(<<<SQL
SELECT ar.*,
       i.expires_at AS invitation_expires_at,
       i.used_at AS invitation_used_at,
       i.email_sent_at AS invitation_email_sent_at,
       i.email_attempts AS invitation_email_attempts,
       i.email_last_error AS invitation_email_last_error,
       i.revoked_at AS invitation_revoked_at,
       u.display_name AS registered_user_name,
       d.display_name AS decided_by_name
FROM access_requests ar
LEFT JOIN invitations i ON i.id=ar.invitation_id
LEFT JOIN users u ON u.id=ar.registered_user_id
LEFT JOIN users d ON d.id=ar.decided_by
WHERE {$where}
ORDER BY ar.created_at DESC
SQL);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function counts(): array
    {
        $rows = Database::connection()->query(<<<'SQL'
SELECT
  SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
  SUM(CASE WHEN status='approved' AND registered_at IS NULL THEN 1 ELSE 0 END) AS approved,
  SUM(CASE WHEN status='approved' AND registered_at IS NOT NULL THEN 1 ELSE 0 END) AS registered,
  SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected,
  SUM(CASE WHEN status='archived' THEN 1 ELSE 0 END) AS archived
FROM access_requests
SQL)->fetch(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_map('intval', $rows) : [];
    }

    public static function updatePlatformMailPreference(array $user, bool $enabled): void
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId < 1) throw new \RuntimeException('Das Lehrerkonto wurde nicht gefunden.');
        $wasEnabled = !empty($user['platform_updates_opt_in']);
        $now = time();
        $optedAt = $enabled
            ? ($wasEnabled && !empty($user['platform_updates_opted_at']) ? (int)$user['platform_updates_opted_at'] : $now)
            : ($user['platform_updates_opted_at'] ?? null);
        $withdrawnAt = $enabled ? null : ($wasEnabled ? $now : ($user['platform_updates_withdrawn_at'] ?? null));
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $account = $db->prepare('UPDATE users SET platform_updates_opt_in=?,platform_updates_opted_at=?,platform_updates_withdrawn_at=?,updated_at=? WHERE id=?');
            $account->execute([$enabled ? 1 : 0, $optedAt, $withdrawnAt, $now, $userId]);
            $request = $db->prepare('UPDATE access_requests SET platform_updates_opt_in=?,platform_updates_opted_at=?,platform_updates_withdrawn_at=?,updated_at=? WHERE registered_user_id=?');
            $request->execute([$enabled ? 1 : 0, $optedAt, $withdrawnAt, $now, $userId]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        Audit::record($userId, 'mail.platform_updates_preference', 'user', (string)$userId, ['enabled' => $enabled]);
    }

    /** @return array{status:string,invitation:?array} */
    public static function decide(int $id, string $decision, array $admin, string $note = ''): array
    {
        if (!in_array($decision, ['approve', 'reject', 'archive'], true)) throw new \RuntimeException('Ungültige Entscheidung.');
        $statement = Database::connection()->prepare('SELECT * FROM access_requests WHERE id=?');
        $statement->execute([$id]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) throw new \RuntimeException('Die Anfrage wurde nicht gefunden.');
        if ($decision === 'approve' && ($request['status'] ?? '') !== 'pending') {
            throw new \RuntimeException('Nur eine offene Anfrage kann genehmigt werden.');
        }
        $newStatus = ['approve' => 'approved', 'reject' => 'rejected', 'archive' => 'archived'][$decision];
        $now = time();
        $invitation = null;
        if ($decision === 'approve') {
            // Nur bestätigte Kolleginnen und Kollegen der eigenen Schule werden
            // der Schulorganisation zugeordnet. Externe Konten starten bewusst
            // ohne Organisationsmitgliedschaft und erhalten dadurch niemals
            // automatisch Zugriff auf das schulische OpenAI-Kontingent.
            $organisationId = ($request['access_type'] ?? '') === 'own-school'
                ? Auth::defaultOrganisationId((int)$admin['id'])
                : 0;
            $invitation = Invitations::create(
                (string)$request['email'],
                (string)$request['name'],
                'teacher',
                $organisationId,
                (int)$admin['id'],
                14,
                $id
            );
        }
        $update = Database::connection()->prepare('UPDATE access_requests SET status=?,admin_note=?,decided_at=?,decided_by=?,invitation_id=?,updated_at=? WHERE id=?');
        $update->execute([
            $newStatus,
            Security::cleanMultiline($note, 1200),
            $now,
            (int)$admin['id'],
            $invitation['id'] ?? ($request['invitation_id'] ?? null),
            $now,
            $id,
        ]);
        Audit::record((int)$admin['id'], 'access.' . $newStatus, 'access_request', (string)$id);
        return ['status' => $newStatus, 'invitation' => $invitation];
    }

    /** @return array{id:int,url:string,email_sent:bool,expires_at:int,error:string} */
    public static function resendInvitation(int $id, array $admin): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM access_requests WHERE id=?');
        $statement->execute([$id]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request) || ($request['status'] ?? '') !== 'approved' || !empty($request['registered_at'])) {
            throw new \RuntimeException('Für diese Anfrage kann keine neue Einladung versendet werden.');
        }
        Invitations::revokeForAccessRequest($id, (int)$admin['id']);
        $organisationId = ($request['access_type'] ?? '') === 'own-school'
            ? Auth::defaultOrganisationId((int)$admin['id'])
            : 0;
        $invitation = Invitations::create(
            (string)$request['email'],
            (string)$request['name'],
            'teacher',
            $organisationId,
            (int)$admin['id'],
            14,
            $id
        );
        $update = Database::connection()->prepare('UPDATE access_requests SET invitation_id=?,updated_at=? WHERE id=?');
        $update->execute([(int)$invitation['id'], time(), $id]);
        Audit::record((int)$admin['id'], 'access.invitation_renewed', 'access_request', (string)$id, ['mail_sent' => $invitation['email_sent']]);
        return $invitation;
    }
}
