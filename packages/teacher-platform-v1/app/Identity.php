<?php
declare(strict_types=1);

namespace ReligionPlatform;

/** Stable subjects are independent of login name, class and product. */
final class Identity
{
    public static function teacher(int $userId): array
    {
        $db = Database::connection();
        $find = $db->prepare('SELECT subject FROM platform_principals WHERE teacher_user_id=?');
        $find->execute([$userId]);
        $subject = $find->fetchColumn();
        if (!$subject) {
            $subject = bin2hex(random_bytes(16));
            $db->prepare('INSERT INTO platform_principals(subject,kind,teacher_user_id,auth_version,created_at) VALUES(?,"teacher",?,?,?) ON CONFLICT(teacher_user_id) DO NOTHING')
                ->execute([$subject, $userId, bin2hex(random_bytes(16)), time()]);
            $find->execute([$userId]);
            $subject = $find->fetchColumn();
            $db->prepare('INSERT INTO platform_product_grants(subject,product,enabled,updated_at) VALUES(?,"learning",1,?) ON CONFLICT DO NOTHING')
                ->execute([$subject, time()]);
        }
        return self::find((string)$subject) ?? throw new \RuntimeException('Das Konto ist nicht aktiv.');
    }

    public static function find(string $subject): ?array
    {
        $q = Database::connection()->prepare(<<<'SQL'
SELECT p.*, u.role AS teacher_role, u.status AS teacher_status, u.auth_version AS teacher_version,
       COALESCE(u.display_name,l.display_name) AS display_name,
       COALESCE(u.username,l.username) AS username, l.organisation_id AS learner_org,
       l.must_change_password
FROM platform_principals p
LEFT JOIN users u ON u.id=p.teacher_user_id
LEFT JOIN platform_learners l ON l.subject=p.subject
WHERE p.subject=?
SQL);
        $q->execute([$subject]);
        $row = $q->fetch();
        if (!$row || $row['status'] !== 'active') return null;
        if ($row['kind'] === 'teacher' && $row['teacher_status'] !== 'active') return null;
        if ($row['kind'] === 'student' && !$row['learner_org']) return null;
        $row['role'] = $row['kind'] === 'teacher' ? $row['teacher_role'] : 'student';
        $row['version'] = hash('sha256', $row['auth_version'] . '|' . ($row['teacher_version'] ?? ''));
        return $row;
    }

    public static function current(): ?array
    {
        Security::startSession();
        if (!empty($_SESSION['platform_user_id'])) {
            $teacher = Auth::currentUser(null);
            return $teacher ? self::teacher((int)$teacher['id']) : null;
        }
        $subject = (string)($_SESSION['platform_learner_subject'] ?? '');
        if($subject && (time()-(int)($_SESSION['platform_last_seen'] ?? 0)>(int)Config::get('session_idle_seconds',28800)
            || time()-(int)($_SESSION['platform_started_at'] ?? 0)>(int)Config::get('session_absolute_seconds',43200))) {
            Oidc::revokeBrowserSession();unset($_SESSION['platform_learner_subject'],$_SESSION['platform_learner_version']);return null;
        }
        $identity = $subject ? self::find($subject) : null;
        if (!$identity || !hash_equals($identity['version'], (string)($_SESSION['platform_learner_version'] ?? ''))) return null;
        $_SESSION['platform_last_seen']=time();
        return $identity;
    }

    public static function orgMember(array $identity, int $orgId): bool
    {
        $org = Database::connection()->prepare('SELECT 1 FROM organisations WHERE id=? AND status="active"');
        $org->execute([$orgId]);
        if (!$org->fetchColumn()) return false;
        if ($identity['role'] === 'admin') return true;
        if ($identity['kind'] === 'student') return (int)$identity['learner_org'] === $orgId;
        $q = Database::connection()->prepare('SELECT 1 FROM organisation_memberships WHERE user_id=? AND organisation_id=?');
        $q->execute([(int)$identity['teacher_user_id'], $orgId]);
        return (bool)$q->fetchColumn();
    }

    public static function allows(array $identity, string $product, ?int $orgId = null): bool
    {
        if (!in_array($product, ['learning', 'assessment'], true)) return false;
        $fresh = self::find($identity['subject']);
        if (!$fresh) return false;
        if ($orgId !== null && !self::orgMember($fresh, $orgId)) return false;
        if ($orgId !== null) {
            $q = Database::connection()->prepare('SELECT enabled FROM platform_org_products WHERE organisation_id=? AND product=?');
            $q->execute([$orgId, $product]);
            $enabled = $q->fetchColumn();
            if ($enabled !== false && !(int)$enabled) return false;
        }
        if ($fresh['role'] === 'admin') return true;
        $q = Database::connection()->prepare('SELECT enabled FROM platform_product_grants WHERE subject=? AND product=?');
        $q->execute([$fresh['subject'], $product]);
        return (bool)$q->fetchColumn();
    }

    public static function assertAdmin(array $actor): void
    {
        $identity = self::teacher((int)($actor['id'] ?? 0));
        if ($identity['role'] !== 'admin') throw new \RuntimeException('Nur die Administration darf das ändern.');
    }

    public static function setProducts(array $actor, string $subject, array $products): void
    {
        self::assertAdmin($actor);
        if (array_diff($products, ['learning','assessment'])) throw new \InvalidArgumentException('Unbekanntes Angebot.');
        $db = Database::connection();
        $exists = $db->prepare('SELECT 1 FROM platform_principals WHERE subject=?');
        $exists->execute([$subject]);
        if (!$exists->fetchColumn()) throw new \RuntimeException('Konto nicht gefunden.');
        $db->beginTransaction();
        try {
            $q = $db->prepare('INSERT INTO platform_product_grants(subject,product,enabled,updated_by,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(subject,product) DO UPDATE SET enabled=excluded.enabled,updated_by=excluded.updated_by,updated_at=excluded.updated_at');
            foreach (['learning','assessment'] as $product) $q->execute([$subject,$product,in_array($product,$products,true)?1:0,(int)$actor['id'],time()]);
            $db->prepare('UPDATE platform_principals SET auth_version=? WHERE subject=?')->execute([bin2hex(random_bytes(16)),$subject]);
            $db->commit();
        } catch (\Throwable $error) { $db->rollBack(); throw $error; }
        Audit::record((int)$actor['id'], 'identity.products_changed', 'principal', $subject, ['products'=>$products]);
    }

    /** Both administration screens must change the same effective account status. */
    public static function setAccountStatus(array $actor, string $status, ?string $subject = null, ?int $teacherId = null): void
    {
        self::assertAdmin($actor);
        if (!in_array($status, ['active','suspended'], true) || (($subject === null) === ($teacherId === null))) {
            throw new \InvalidArgumentException('Ungültiger Kontostatus oder Kontobezug.');
        }
        $db = Database::connection();
        $query = $teacherId !== null
            ? 'SELECT p.subject,u.id AS teacher_user_id FROM users u LEFT JOIN platform_principals p ON p.teacher_user_id=u.id WHERE u.id=?'
            : 'SELECT subject,teacher_user_id FROM platform_principals WHERE subject=?';
        $find = $db->prepare($query);
        $find->execute([$teacherId ?? $subject]);
        $target = $find->fetch();
        if (!$target) throw new \RuntimeException('Konto nicht gefunden.');
        if ((int)$target['teacher_user_id'] === (int)$actor['id']) throw new \RuntimeException('Das eigene Administratorkonto kann hier nicht geändert werden.');
        $db->beginTransaction();
        try {
            if ($target['subject']) {
                $db->prepare('UPDATE platform_principals SET status=?,auth_version=? WHERE subject=?')
                    ->execute([$status,bin2hex(random_bytes(16)),$target['subject']]);
            }
            if ($target['teacher_user_id']) {
                $id = (int)$target['teacher_user_id'];
                $db->prepare('UPDATE users SET status=?,auth_version=?,updated_at=? WHERE id=?')
                    ->execute([$status,bin2hex(random_bytes(16)),time(),$id]);
                if ($status === 'suspended') {
                    $db->prepare('UPDATE password_reset_tokens SET revoked_at=? WHERE user_id=? AND used_at IS NULL AND revoked_at IS NULL')->execute([time(),$id]);
                    $db->prepare('UPDATE ai_grants SET status="revoked",revoked_at=?,updated_at=? WHERE user_id=? AND status="active"')->execute([time(),time(),$id]);
                }
            }
            $db->commit();
        } catch (\Throwable $error) { $db->rollBack(); throw $error; }
        Audit::record((int)$actor['id'],'identity.status_changed',$target['subject']?'principal':'user',
            (string)($target['subject'] ?? $target['teacher_user_id']),['status'=>$status]);
    }

    public static function requireLearningTeacher(array $actor): void
    {
        if (!self::allows(self::teacher((int)$actor['id']), 'learning')) {
            throw new \RuntimeException('LernHTML ist für dieses Konto nicht freigegeben.');
        }
    }

    public static function setOrganisationProducts(array $actor, int $orgId, array $products): void
    {
        self::assertAdmin($actor);
        if (array_diff($products,['learning','assessment'])) throw new \InvalidArgumentException('Unbekanntes Angebot.');
        $db=Database::connection();
        $q=$db->prepare('SELECT 1 FROM organisations WHERE id=?');$q->execute([$orgId]);
        if(!$q->fetchColumn()) throw new \RuntimeException('Organisation nicht gefunden.');
        $db->beginTransaction();
        try {
            $q=$db->prepare('INSERT INTO platform_org_products(organisation_id,product,enabled,updated_at) VALUES(?,?,?,?) ON CONFLICT(organisation_id,product) DO UPDATE SET enabled=excluded.enabled,updated_at=excluded.updated_at');
            foreach(['learning','assessment'] as $product) $q->execute([$orgId,$product,in_array($product,$products,true)?1:0,time()]);
            $db->commit();
        } catch(\Throwable $error) {$db->rollBack();throw $error;}
        Audit::record((int)$actor['id'],'organisation.products_changed','organisation',(string)$orgId,['products'=>$products]);
    }
}
