<?php
declare(strict_types=1);

namespace ReligionPlatform;

/** Shared classes and learners; every lookup keeps the organisation boundary. */
final class SchoolDirectory
{
    public static function classes(array $actor): array
    {
        $identity = Identity::teacher((int)$actor['id']);
        $sql = 'SELECT c.*,o.name AS organisation_name FROM platform_classes c JOIN organisations o ON o.id=c.organisation_id WHERE o.status="active"';
        $args = [];
        if ($identity['role'] !== 'admin') {
            $sql .= ' AND EXISTS(SELECT 1 FROM platform_class_teachers t WHERE t.class_id=c.id AND t.teacher_user_id=?) AND EXISTS(SELECT 1 FROM organisation_memberships m WHERE m.organisation_id=c.organisation_id AND m.user_id=?)';
            $args = [(int)$actor['id'],(int)$actor['id']];
        }
        $q = Database::connection()->prepare($sql . ' ORDER BY c.school_year DESC,c.label');
        $q->execute($args);
        return $q->fetchAll();
    }

    public static function assertClass(array $actor, string $classId, bool $write = false): array
    {
        $identity = Identity::teacher((int)$actor['id']);
        $q = Database::connection()->prepare('SELECT c.*,t.role AS class_role FROM platform_classes c LEFT JOIN platform_class_teachers t ON t.class_id=c.id AND t.teacher_user_id=? WHERE c.id=?');
        $q->execute([(int)$actor['id'],$classId]);
        $class = $q->fetch();
        if (!$class || !Identity::orgMember($identity,(int)$class['organisation_id'])
            || ($identity['role'] !== 'admin' && (!$class['class_role'] || ($write && !in_array($class['class_role'],['owner','editor'],true))))) throw new \RuntimeException('Klasse nicht gefunden oder nicht freigegeben.');
        return $class;
    }

    public static function createClass(array $actor, int $orgId, string $label, string $year): array
    {
        $identity = Identity::teacher((int)$actor['id']);
        if (!Identity::orgMember($identity,$orgId)
            || (!Identity::allows($identity,'learning',$orgId) && !Identity::allows($identity,'assessment',$orgId))) throw new \RuntimeException('Die Organisation ist nicht freigegeben.');
        $label = Security::clean($label,80);
        if ($label === '' || !preg_match('/^(20\d{2})\/(\d{2})$/D',$year,$m) || (((int)$m[1]+1)%100)!==(int)$m[2]) throw new \InvalidArgumentException('Klassenname und Schuljahr (z. B. 2026/27) werden benötigt.');
        $id = bin2hex(random_bytes(16));
        $db = Database::connection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO platform_classes(id,organisation_id,label,school_year,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,$orgId,$label,$year,(int)$actor['id'],time(),time()]);
            $db->prepare('INSERT INTO platform_class_teachers(class_id,teacher_user_id,role) VALUES(?,?,"owner")')->execute([$id,(int)$actor['id']]);
            if ($ownsTransaction) $db->commit();
        } catch (\Throwable $error) { if ($ownsTransaction) $db->rollBack(); throw $error; }
        Audit::record((int)$actor['id'],'class.created','class',$id,['organisation_id'=>$orgId]);
        return self::assertClass($actor,$id);
    }

    public static function setTeacher(array $actor, string $classId, int $teacherId, ?string $role): void
    {
        Identity::assertAdmin($actor);
        $class = self::assertClass($actor,$classId,true);
        if($role===null){Database::connection()->prepare('DELETE FROM platform_class_teachers WHERE class_id=? AND teacher_user_id=?')->execute([$classId,$teacherId]);Audit::record((int)$actor['id'],'class.teacher_changed','class',$classId,['teacher_id'=>$teacherId,'role'=>null]);return;}
        $teacher = Identity::teacher($teacherId);
        // A global administrator may support any class; other teachers need membership.
        if (!Identity::orgMember($teacher,(int)$class['organisation_id'])) throw new \RuntimeException('Die Lehrkraft gehört nicht zu dieser Organisation.');
        if ($role !== null && !in_array($role,['owner','editor','viewer'],true)) throw new \InvalidArgumentException('Ungültige Klassenrolle.');
        if ($role === null) Database::connection()->prepare('DELETE FROM platform_class_teachers WHERE class_id=? AND teacher_user_id=?')->execute([$classId,$teacherId]);
        else Database::connection()->prepare('INSERT INTO platform_class_teachers(class_id,teacher_user_id,role) VALUES(?,?,?) ON CONFLICT(class_id,teacher_user_id) DO UPDATE SET role=excluded.role')->execute([$classId,$teacherId,$role]);
        Audit::record((int)$actor['id'],'class.teacher_changed','class',$classId,['teacher_id'=>$teacherId,'role'=>$role]);
    }

    public static function learners(array $actor, string $classId): array
    {
        self::assertClass($actor,$classId);
        $q = Database::connection()->prepare('SELECT l.subject,l.username,l.display_name,l.must_change_password,p.status,r.roster_number,r.active FROM platform_learners l JOIN platform_principals p ON p.subject=l.subject JOIN platform_class_learners r ON r.subject=l.subject WHERE r.class_id=? ORDER BY r.roster_number');
        $q->execute([$classId]);
        return $q->fetchAll();
    }

    public static function updateClass(array $actor,string $classId,string $label,string $year,string $status): void
    {
        self::assertClass($actor,$classId,true);$label=Security::clean($label,80);
        if(!$label || !preg_match('/^(20\d{2})\/(\d{2})$/D',$year,$m) || (((int)$m[1]+1)%100)!==(int)$m[2] || !in_array($status,['active','archived'],true))throw new \InvalidArgumentException('Klassenname, Schuljahr und Status prüfen.');
        Database::connection()->prepare('UPDATE platform_classes SET label=?,school_year=?,status=?,updated_at=? WHERE id=?')->execute([$label,$year,$status,time(),$classId]);
        Audit::record((int)$actor['id'],'class.updated','class',$classId,['status'=>$status]);
    }

    /** Explicit subject IDs only: never merge accounts because names match. */
    public static function setLearnerMembership(array $actor,string $classId,string $subject,int $number,bool $active): void
    {
        Identity::assertAdmin($actor);$class=self::assertClass($actor,$classId,true);
        $q=Database::connection()->prepare('SELECT 1 FROM platform_learners WHERE subject=? AND organisation_id=?');$q->execute([$subject,$class['organisation_id']]);
        if(!$q->fetchColumn() || $number<1 || $number>999)throw new \InvalidArgumentException('Person und Listennummer passen nicht zu dieser Organisation.');
        Database::connection()->prepare('INSERT INTO platform_class_learners(class_id,subject,roster_number,active) VALUES(?,?,?,?) ON CONFLICT(class_id,subject) DO UPDATE SET roster_number=excluded.roster_number,active=excluded.active')->execute([$classId,$subject,$number,$active?1:0]);
        Audit::record((int)$actor['id'],'class.learner_changed','class',$classId,['subject'=>$subject,'active'=>$active]);
    }

    public static function teachers(array $actor,string $classId): array
    {
        $class=self::assertClass($actor,$classId);
        $q=Database::connection()->prepare('SELECT u.id,u.display_name,t.role,COALESCE(p.status,u.status) AS status FROM platform_class_teachers t JOIN users u ON u.id=t.teacher_user_id LEFT JOIN platform_principals p ON p.teacher_user_id=u.id WHERE t.class_id=? ORDER BY u.display_name');$q->execute([$class['id']]);return $q->fetchAll();
    }

    public static function teacherCandidates(array $actor,string $classId): array
    {
        Identity::assertAdmin($actor);$class=self::assertClass($actor,$classId);
        $q=Database::connection()->prepare('SELECT u.id,u.display_name FROM users u LEFT JOIN platform_principals p ON p.teacher_user_id=u.id WHERE u.status="active" AND (p.subject IS NULL OR p.status="active") AND (u.role="admin" OR EXISTS(SELECT 1 FROM organisation_memberships m WHERE m.user_id=u.id AND m.organisation_id=?)) ORDER BY u.display_name');$q->execute([$class['organisation_id']]);return $q->fetchAll();
    }

    public static function learnerCandidates(array $actor,string $classId): array
    {
        Identity::assertAdmin($actor);$class=self::assertClass($actor,$classId);
        $q=Database::connection()->prepare('SELECT subject,display_name,username FROM platform_learners WHERE organisation_id=? ORDER BY display_name');$q->execute([$class['organisation_id']]);return $q->fetchAll();
    }

    public static function createLearner(array $actor, string $classId, string $name, string $username, int $number, array $products): array
    {
        $class = self::assertClass($actor,$classId,true);
        if($class['status']!=='active')throw new \RuntimeException('Die Klasse ist archiviert. Bitte zuerst wieder öffnen.');
        $name = Security::clean($name,120);
        $username = strtoupper(Security::clean($username,60));
        if ($name === '' || !preg_match('/^[A-Z0-9][A-Z0-9._-]{2,59}$/D',$username) || $number<1 || $number>999 || !$products || array_diff($products,['learning','assessment'])) throw new \InvalidArgumentException('Name, Anmeldename, Listennummer und Angebote prüfen.');
        $identity = Identity::teacher((int)$actor['id']);
        foreach ($products as $product) if (!Identity::allows($identity,$product,(int)$class['organisation_id'])) throw new \RuntimeException('Dieses Angebot ist für die Lehrkraft nicht freigegeben.');
        $subject = bin2hex(random_bytes(16));
        $password = self::startPassword();
        $db = Database::connection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO platform_principals(subject,kind,auth_version,created_at) VALUES(?,"student",?,?)')->execute([$subject,bin2hex(random_bytes(16)),time()]);
            $db->prepare('INSERT INTO platform_learners(subject,organisation_id,username,display_name,password_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([$subject,(int)$class['organisation_id'],$username,$name,password_hash($password,PASSWORD_ARGON2ID),time(),time()]);
            $db->prepare('INSERT INTO platform_class_learners(class_id,subject,roster_number) VALUES(?,?,?)')->execute([$classId,$subject,$number]);
            foreach (array_unique($products) as $product) $db->prepare('INSERT INTO platform_product_grants(subject,product,enabled,updated_by,updated_at) VALUES(?,?,1,?,?)')->execute([$subject,$product,(int)$actor['id'],time()]);
            if ($ownsTransaction) $db->commit();
        } catch (\Throwable $error) { if ($ownsTransaction) $db->rollBack(); throw $error; }
        Audit::record((int)$actor['id'],'learner.created','principal',$subject,['class_id'=>$classId]);
        $org = $db->prepare('SELECT slug FROM organisations WHERE id=?');
        $org->execute([(int)$class['organisation_id']]);
        return ['subject'=>$subject,'username'=>$username,'start_password'=>$password,
            'url'=>rtrim((string)Config::get('base_url'),'/').'/zugang/?'.http_build_query(['schule'=>$org->fetchColumn(),'u'=>$username])];
    }

    public static function resetLearner(array $actor, string $classId, string $subject): string
    {
        self::assertClass($actor,$classId,true);
        $db = Database::connection();
        $q = $db->prepare('SELECT 1 FROM platform_class_learners WHERE class_id=? AND subject=? AND active=1');
        $q->execute([$classId,$subject]);
        if (!$q->fetchColumn()) throw new \RuntimeException('Lernende Person nicht in dieser Klasse.');
        $password = self::startPassword();
        self::setLearnerPassword($subject,$password,true);
        Audit::record((int)$actor['id'],'learner.password_reset','principal',$subject);
        return $password;
    }

    private static function startPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $value = '';
        for ($i=0;$i<16;$i++) $value .= $alphabet[random_int(0,strlen($alphabet)-1)];
        return $value;
    }

    public static function loginLearner(string $orgSlug, string $username, string $password): array
    {
        $username = strtoupper(Security::clean($username,60));
        $orgSlug = Security::clean($orgSlug,80);
        $subjectHash = hash_hmac('sha256',$orgSlug.'|'.$username,Vault::masterKey());
        if (!Security::rateLimit('learner-login-account',$subjectHash,900,14)
            || !Security::rateLimit('learner-login-ip',Security::clientIpHash('learner-login'),900,60)) throw new \RuntimeException('Zu viele Anmeldeversuche. Bitte später erneut versuchen.');
        $q = Database::connection()->prepare('SELECT l.* FROM platform_learners l JOIN organisations o ON o.id=l.organisation_id WHERE o.slug=? AND o.status="active" AND l.username=? COLLATE NOCASE');
        $q->execute([$orgSlug,$username]);
        $learner = $q->fetch();
        $identity = $learner ? Identity::find($learner['subject']) : null;
        if (!$identity || !password_verify($password,$learner['password_hash'])) throw new \RuntimeException('Schule, Anmeldename oder Passwort stimmt nicht.');
        Security::startSession();
        Oidc::revokeBrowserSession();
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['platform_learner_subject'] = $identity['subject'];
        $_SESSION['platform_learner_version'] = $identity['version'];
        $_SESSION['platform_started_at'] = $_SESSION['platform_last_seen'] = $_SESSION['platform_regenerated_at'] = time();
        return $identity;
    }

    public static function changeLearnerPassword(array $identity, string $old, string $password): void
    {
        $fresh = Identity::find($identity['subject']);
        if (!$fresh || $fresh['kind'] !== 'student' || !hash_equals($fresh['version'],$identity['version'])) throw new \RuntimeException('Bitte erneut anmelden.');
        $q = Database::connection()->prepare('SELECT password_hash FROM platform_learners WHERE subject=?');
        $q->execute([$fresh['subject']]);
        if (!password_verify($old,(string)$q->fetchColumn())) throw new \RuntimeException('Das bisherige Passwort stimmt nicht.');
        Auth::validatePassword($password);
        self::setLearnerPassword($fresh['subject'],$password,false);
        $_SESSION['platform_learner_version'] = Identity::find($fresh['subject'])['version'];
    }

    private static function setLearnerPassword(string $subject, string $password, bool $initial): void
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE platform_learners SET password_hash=?,must_change_password=?,updated_at=? WHERE subject=?')->execute([password_hash($password,PASSWORD_ARGON2ID),$initial?1:0,time(),$subject]);
            $db->prepare('UPDATE platform_principals SET auth_version=? WHERE subject=?')->execute([bin2hex(random_bytes(16)),$subject]);
            $db->commit();
        } catch (\Throwable $error) { $db->rollBack(); throw $error; }
    }
}
