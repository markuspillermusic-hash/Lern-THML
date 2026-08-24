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
        if (strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($school) < 2 || strlen($subjects) < 2 || $bundesland === '' || $accessType === '' || strlen($reason) < 20 || empty($input['consent'])) {
            throw new \RuntimeException('Bitte alle Pflichtfelder vollständig ausfüllen und dem Kontakt zustimmen.');
        }
        $now = time();
        $statement = Database::connection()->prepare('INSERT INTO access_requests(name,email,school,subjects,bundesland,access_type,reason,status,ip_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,"pending",?,?,?)');
        $statement->execute([$name, $email, $school, $subjects, $bundesland, $accessType, $reason, $subject, $now, $now]);
        $id = (int)Database::connection()->lastInsertId();
        Audit::record(null, 'access.requested', 'access_request', (string)$id, ['type' => $accessType]);
        $recipient = Mailer::recipient();
        if ($recipient !== '') {
            try {
                Mailer::send($recipient, 'Neue Zugriffsanfrage · ' . $name, "Neue Zugriffsanfrage #{$id}\n\nName: {$name}\nE-Mail: {$email}\nSchule: {$school}\nFach: {$subjects}\nBundesland: {$bundesland}\nArt: {$accessType}\n\nGrund:\n{$reason}\n\nVerwaltung: " . Config::get('base_url') . '/lehrer/?view=requests');
            } catch (\Throwable $error) {
                Audit::record(null, 'mail.failed', 'access_request', (string)$id, ['reason' => $error->getMessage()]);
            }
        }
        return $id;
    }

    public static function list(string $status = 'pending'): array
    {
        $status = in_array($status, ['pending', 'approved', 'rejected', 'archived'], true) ? $status : 'pending';
        $statement = Database::connection()->prepare('SELECT * FROM access_requests WHERE status=? ORDER BY created_at DESC');
        $statement->execute([$status]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function decide(int $id, string $decision, array $admin, string $note = ''): ?string
    {
        if (!in_array($decision, ['approve', 'reject', 'archive'], true)) throw new \RuntimeException('Ungültige Entscheidung.');
        $statement = Database::connection()->prepare('SELECT * FROM access_requests WHERE id=?');
        $statement->execute([$id]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) throw new \RuntimeException('Die Anfrage wurde nicht gefunden.');
        $newStatus = ['approve' => 'approved', 'reject' => 'rejected', 'archive' => 'archived'][$decision];
        $update = Database::connection()->prepare('UPDATE access_requests SET status=?,admin_note=?,updated_at=? WHERE id=?');
        $update->execute([$newStatus, Security::cleanMultiline($note, 1200), time(), $id]);
        Audit::record((int)$admin['id'], 'access.' . $newStatus, 'access_request', (string)$id);
        if ($decision !== 'approve') return null;
        return Invitations::create((string)$request['email'], (string)$request['name'], 'teacher', Auth::defaultOrganisationId((int)$admin['id']), (int)$admin['id']);
    }
}

