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
