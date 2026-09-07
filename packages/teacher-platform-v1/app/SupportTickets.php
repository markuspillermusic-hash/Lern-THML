<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

final class SupportTickets
{
    private const CATEGORIES = ['login','room','presentation','ai','content','privacy','security','other'];
    private const STATUSES = ['open','in_progress','waiting_user','resolved','closed'];

    public static function create(array $input, ?array $user = null): array
    {
        if (!empty($input['website'])) throw new \RuntimeException('Die Anfrage konnte nicht angenommen werden.');
        if (!$user) {
            if (!Security::verifyFormToken((string)($input['form_token'] ?? ''), 'support-request', 7200, 3)) {
                throw new \RuntimeException('Das Formular ist abgelaufen. Bitte die Seite neu laden.');
            }
            if (empty($input['consent'])) throw new \RuntimeException('Bitte bestätigen Sie die Datenschutzinformationen.');
        }
        $subjectHash = Security::clientIpHash('support-request');
        if (!Security::rateLimit('support-request', $subjectHash, 3600, 5)) {
            throw new \RuntimeException('Von diesem Anschluss kamen zu viele Supportanfragen. Bitte später erneut versuchen.');
        }
        $email = $user ? strtolower((string)$user['email']) : strtolower(Security::clean($input['email'] ?? '', 190));
        $category = in_array(($input['category'] ?? ''), self::CATEGORIES, true) ? (string)$input['category'] : 'other';
        $subject = Security::clean($input['subject'] ?? '', 140);
        $body = Security::cleanMultiline($input['body'] ?? '', 5000);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($subject) < 5 || strlen($body) < 20) {
            throw new \RuntimeException('Bitte E-Mail-Adresse, Betreff und Beschreibung vollständig angeben.');
        }

        $publicId = self::publicId();
        $now = time();
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $ticket = $db->prepare('INSERT INTO support_tickets(public_id,user_id,email,category,subject,status,priority,ip_hash,created_at,updated_at) VALUES(?,?,?,?,?,"open","normal",?,?,?)');
            $ticket->execute([$publicId, $user['id'] ?? null, $email, $category, $subject, $subjectHash, $now, $now]);
            $ticketId = (int)$db->lastInsertId();
            $message = $db->prepare('INSERT INTO support_messages(ticket_id,author_user_id,author_role,body,is_internal,created_at) VALUES(?,?,?,?,0,?)');
            $message->execute([$ticketId, $user['id'] ?? null, $user ? 'teacher' : 'visitor', $body, $now]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }

        Audit::record($user ? (int)$user['id'] : null, 'support.created', 'support_ticket', $publicId, ['category' => $category]);
        self::notifyAdmin($publicId, $subject);
        try {
            Mailer::send($email, "Supportanfrage {$publicId} eingegangen", "Ihre Supportanfrage wurde unter der Nummer {$publicId} gespeichert.\n\nBetreff: {$subject}\n\nBitte antworten Sie nicht mit Schülernamen, Schülerantworten oder anderen sensiblen Angaben.\n\nReligionsunterricht · Lehrerplattform");
        } catch (\Throwable) {
            Audit::record(null, 'mail.failed', 'support_ticket', $publicId, ['kind' => 'receipt']);
        }
        return ['id' => $ticketId, 'public_id' => $publicId];
    }

    public static function listForUser(int $userId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM support_tickets WHERE user_id=? ORDER BY updated_at DESC');
        $statement->execute([$userId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function listAll(string $status = 'active'): array
    {
        $where = match ($status) {
            'open' => 'st.status="open"',
            'in_progress' => 'st.status="in_progress"',
            'waiting_user' => 'st.status="waiting_user"',
            'resolved' => 'st.status="resolved"',
            'closed' => 'st.status="closed"',
            'all' => '1=1',
            default => 'st.status IN ("open","in_progress","waiting_user")',
        };
        $statement = Database::connection()->query('SELECT st.*,u.display_name AS user_name,a.display_name AS assigned_name FROM support_tickets st LEFT JOIN users u ON u.id=st.user_id LEFT JOIN users a ON a.id=st.assigned_to WHERE ' . $where . ' ORDER BY CASE st.priority WHEN "urgent" THEN 0 WHEN "high" THEN 1 ELSE 2 END,st.updated_at DESC');
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function counts(): array
    {
        $row = Database::connection()->query(<<<'SQL'
SELECT
  SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) AS open,
  SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS in_progress,
  SUM(CASE WHEN status='waiting_user' THEN 1 ELSE 0 END) AS waiting_user,
  SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) AS resolved,
  SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) AS closed
FROM support_tickets
SQL)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? array_map('intval', $row) : [];
    }

    public static function get(int $id, array $actor): array
    {
        $statement = Database::connection()->prepare('SELECT st.*,u.display_name AS user_name,a.display_name AS assigned_name FROM support_tickets st LEFT JOIN users u ON u.id=st.user_id LEFT JOIN users a ON a.id=st.assigned_to WHERE st.id=?');
        $statement->execute([$id]);
        $ticket = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ticket)) throw new \RuntimeException('Der Supportfall wurde nicht gefunden.');
        if (!Auth::isAdmin($actor) && (int)($ticket['user_id'] ?? 0) !== (int)$actor['id']) {
            throw new \RuntimeException('Dieser Supportfall gehört zu einem anderen Konto.');
        }
        $messages = Database::connection()->prepare('SELECT sm.*,u.display_name AS author_name FROM support_messages sm LEFT JOIN users u ON u.id=sm.author_user_id WHERE sm.ticket_id=?' . (Auth::isAdmin($actor) ? '' : ' AND sm.is_internal=0') . ' ORDER BY sm.created_at ASC');
        $messages->execute([$id]);
        $ticket['messages'] = $messages->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $ticket;
    }

    public static function reply(int $id, array $actor, string $body, bool $internal = false): void
    {
        $ticket = self::get($id, $actor);
        $body = Security::cleanMultiline($body, 5000);
        if (strlen($body) < 2) throw new \RuntimeException('Bitte eine Nachricht eingeben.');
        if (!Auth::isAdmin($actor)) $internal = false;
        $role = Auth::isAdmin($actor) ? 'admin' : 'teacher';
        $now = time();
        $statement = Database::connection()->prepare('INSERT INTO support_messages(ticket_id,author_user_id,author_role,body,is_internal,created_at) VALUES(?,?,?,?,?,?)');
        $statement->execute([$id, (int)$actor['id'], $role, $body, $internal ? 1 : 0, $now]);
        $status = Auth::isAdmin($actor) ? ($internal ? (string)$ticket['status'] : 'waiting_user') : 'in_progress';
        $update = Database::connection()->prepare('UPDATE support_tickets SET status=?,assigned_to=COALESCE(assigned_to,?),updated_at=? WHERE id=?');
        $update->execute([$status, Auth::isAdmin($actor) ? (int)$actor['id'] : null, $now, $id]);
        Audit::record((int)$actor['id'], 'support.replied', 'support_ticket', (string)$ticket['public_id'], ['internal' => $internal]);

        if (!$internal) {
            try {
                if (Auth::isAdmin($actor)) {
                    Mailer::send((string)$ticket['email'], "Neue Antwort zu {$ticket['public_id']}", "Zu Ihrem Supportfall {$ticket['public_id']} liegt eine neue Antwort vor.\n\n{$body}\n\nAngemeldete Lehrkräfte finden den vollständigen Verlauf im Lehrerbereich unter „Support“.\n\nReligionsunterricht · Lehrerplattform");
                } else {
                    self::notifyAdmin((string)$ticket['public_id'], (string)$ticket['subject']);
                }
            } catch (\Throwable) {
                Audit::record((int)$actor['id'], 'mail.failed', 'support_ticket', (string)$ticket['public_id'], ['kind' => 'reply']);
            }
        }
    }

    public static function update(int $id, array $admin, string $status, string $priority): void
    {
        if (!Auth::isAdmin($admin)) throw new \RuntimeException('Nur die Administration kann Supportfälle verwalten.');
        if (!in_array($status, self::STATUSES, true)) throw new \RuntimeException('Ungültiger Supportstatus.');
        if (!in_array($priority, ['normal','high','urgent'], true)) throw new \RuntimeException('Ungültige Priorität.');
        $now = time();
        $statement = Database::connection()->prepare('UPDATE support_tickets SET status=?,priority=?,assigned_to=?,updated_at=?,closed_at=? WHERE id=?');
        $statement->execute([$status, $priority, (int)$admin['id'], $now, in_array($status, ['resolved','closed'], true) ? $now : null, $id]);
        Audit::record((int)$admin['id'], 'support.updated', 'support_ticket', (string)$id, ['status' => $status, 'priority' => $priority]);
    }

    private static function notifyAdmin(string $publicId, string $subject): void
    {
        $recipient = Mailer::recipient();
        if ($recipient === '' || !Mailer::configured()) return;
        try {
            Mailer::send($recipient, "Supportfall {$publicId} · Lehrerplattform", "Ein Supportfall wurde erstellt oder ergänzt.\n\nNummer: {$publicId}\nBetreff: {$subject}\n\nBitte im geschützten Bereich prüfen:\n" . rtrim((string)Config::get('base_url'), '/') . '/lehrer/?view=support-admin');
        } catch (\Throwable) {
            Audit::record(null, 'mail.failed', 'support_ticket', $publicId, ['kind' => 'admin-notice']);
        }
    }

    private static function publicId(): string
    {
        do {
            $id = 'SUP-' . gmdate('Ym') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $statement = Database::connection()->prepare('SELECT 1 FROM support_tickets WHERE public_id=?');
            $statement->execute([$id]);
        } while ($statement->fetchColumn());
        return $id;
    }
}
