<?php
declare(strict_types=1);

namespace ReligionPlatform;

/** Small shared entry controller; product-specific work stays in its module. */
final class Portal
{
    public static function next(mixed $value): string
    {
        if (!is_string($value) || !str_starts_with($value,'/') || str_starts_with($value,'//') || preg_match('/[\x00-\x20\\\\]/',$value)) return '/zugang/';
        return $value;
    }

    public static function organisations(array $actor): array
    {
        $identity = Identity::teacher((int)$actor['id']);
        if ($identity['role']==='admin') return Database::connection()->query('SELECT id,name,slug FROM organisations WHERE status="active" ORDER BY name')->fetchAll();
        $q=Database::connection()->prepare('SELECT o.id,o.name,o.slug FROM organisations o JOIN organisation_memberships m ON m.organisation_id=o.id WHERE o.status="active" AND m.user_id=? ORDER BY o.name');
        $q->execute([(int)$actor['id']]);
        return $q->fetchAll();
    }

    public static function installations(array $identity): array
    {
        $all=Database::connection()->query('SELECT c.client_id,c.label,c.product,c.base_url,c.status,c.organisation_id,o.name AS organisation_name FROM platform_clients c JOIN organisations o ON o.id=c.organisation_id ORDER BY o.name,c.label')->fetchAll();
        return array_values(array_filter($all,fn($c)=>$identity['role']==='admin' || ($c['status']==='active' && Identity::allows($identity,$c['product'],(int)$c['organisation_id']))));
    }

    public static function accounts(array $actor): array
    {
        Identity::assertAdmin($actor);
        $db=Database::connection();
        foreach($db->query('SELECT u.id FROM users u LEFT JOIN platform_principals p ON p.teacher_user_id=u.id WHERE p.subject IS NULL AND u.status="active"')->fetchAll() as $u) Identity::teacher((int)$u['id']);
        return $db->query(<<<'SQL'
SELECT p.subject,p.kind,CASE WHEN u.status IS NOT NULL AND u.status!='active' THEN 'suspended' ELSE p.status END AS status,COALESCE(u.display_name,l.display_name) AS display_name,
       COALESCE(u.username,l.username) AS username,COALESCE(u.role,'student') AS role,
       COALESCE((SELECT enabled FROM platform_product_grants WHERE subject=p.subject AND product='learning'),0) AS learning,
       COALESCE((SELECT enabled FROM platform_product_grants WHERE subject=p.subject AND product='assessment'),0) AS assessment
FROM platform_principals p LEFT JOIN users u ON u.id=p.teacher_user_id
LEFT JOIN platform_learners l ON l.subject=p.subject ORDER BY p.kind,display_name
SQL)->fetchAll();
    }

    public static function action(array $input): string
    {
        if (!Security::verifyCsrf(is_string($input['csrf'] ?? null)?$input['csrf']:null)) throw new \RuntimeException('Das Formular ist abgelaufen. Bitte neu laden.');
        $action=(string)($input['action'] ?? '');
        if($action==='teacher_login') {
            Auth::login((string)($input['username'] ?? ''),(string)($input['password'] ?? ''),(string)($input['mfa_code'] ?? ''));
            return self::next($input['next'] ?? null);
        }
        if($action==='student_login') {
            $identity=SchoolDirectory::loginLearner((string)($input['school'] ?? ''),(string)($input['username'] ?? ''),(string)($input['password'] ?? ''));
            $next=self::next($input['next'] ?? null);
            return !empty($identity['must_change_password'])?'/zugang/?next='.rawurlencode($next):$next;
        }
        $identity=Identity::current();
        if(!$identity) throw new \RuntimeException('Bitte anmelden.');
        if($action==='logout') {Auth::logout(); return '/zugang/';}
        if($action==='student_password') {
            if(!hash_equals((string)($input['password'] ?? ''),(string)($input['confirm'] ?? ''))) throw new \RuntimeException('Die neuen Passwörter stimmen nicht überein.');
            SchoolDirectory::changeLearnerPassword($identity,(string)($input['current'] ?? ''),(string)($input['password'] ?? ''));
            return self::next($input['next'] ?? null);
        }
        if($identity['kind']!=='teacher') throw new \RuntimeException('Diese Funktion ist für Lehrkräfte vorgesehen.');
        $actor=Auth::currentUser(null);
        if(!$actor) throw new \RuntimeException('Bitte erneut anmelden.');
        if($action==='create_class') {
            $class=SchoolDirectory::createClass($actor,(int)($input['organisation_id'] ?? 0),(string)($input['label'] ?? ''),(string)($input['school_year'] ?? ''));
            return '/zugang/?view=classes&class='.$class['id'];
        }
        if($action==='create_learner') {
            $class=(string)($input['class_id'] ?? '');
            $credential=SchoolDirectory::createLearner($actor,$class,(string)($input['name'] ?? ''),(string)($input['username'] ?? ''),(int)($input['number'] ?? 0),is_array($input['products'] ?? null)?$input['products']:[]);
            $_SESSION['shared_portal_once']=$credential;
            return '/zugang/?view=classes&class='.$class;
        }
        if($action==='set_teacher') {
            SchoolDirectory::setTeacher($actor,(string)($input['class_id'] ?? ''),(int)($input['teacher_id'] ?? 0),(string)($input['role'] ?? '') ?: null);
            return '/zugang/?view=classes&class='.rawurlencode((string)$input['class_id']);
        }
        if($action==='update_class') {
            SchoolDirectory::updateClass($actor,(string)($input['class_id'] ?? ''),(string)($input['label'] ?? ''),(string)($input['school_year'] ?? ''),(string)($input['status'] ?? ''));
            return '/zugang/?view=classes&class='.rawurlencode((string)$input['class_id']);
        }
        if($action==='set_learner_membership') {
            SchoolDirectory::setLearnerMembership($actor,(string)($input['class_id'] ?? ''),(string)($input['subject'] ?? ''),(int)($input['number'] ?? 0),($input['active'] ?? '')==='1');
            return '/zugang/?view=classes&class='.rawurlencode((string)$input['class_id']);
        }
        if($action==='reset_learner') {
            $password=SchoolDirectory::resetLearner($actor,(string)($input['class_id'] ?? ''),(string)($input['subject'] ?? ''));
            $_SESSION['shared_portal_once']=['start_password'=>$password];
            return '/zugang/?view=classes&class='.rawurlencode((string)$input['class_id']);
        }
        Identity::assertAdmin($actor);
        if($action==='set_org_products') {
            Identity::setOrganisationProducts($actor,(int)($input['organisation_id'] ?? 0),is_array($input['products'] ?? null)?$input['products']:[]);
            return '/zugang/?view=admin';
        }
        if($action==='set_products') {
            Identity::setProducts($actor,(string)($input['subject'] ?? ''),is_array($input['products'] ?? null)?$input['products']:[]);
            return '/zugang/?view=admin';
        }
        if($action==='set_status') {
            $subject=(string)($input['subject'] ?? '');
            $status=(string)($input['status'] ?? '');
            if($subject===$identity['subject']) throw new \RuntimeException('Das eigene Administratorkonto kann hier nicht gesperrt werden.');
            if(!in_array($status,['active','suspended'],true)) throw new \RuntimeException('Ungültiger Kontostatus.');
            Database::connection()->prepare('UPDATE platform_principals SET status=?,auth_version=? WHERE subject=?')->execute([$status,bin2hex(random_bytes(16)),$subject]);
            Audit::record((int)$actor['id'],'identity.status_changed','principal',$subject,['status'=>$status]);
            return '/zugang/?view=admin';
        }
        if($action==='register_client') {
            Oidc::provisionSigningKey($actor);
            $input['redirect_uris']=preg_split('/\s+/',trim((string)($input['redirect_uris'] ?? ''))) ?: [];
            $_SESSION['shared_portal_once']=Oidc::registerClient($actor,$input);
            return '/zugang/?view=installations';
        }
        if($action==='client_status') {
            $status=(string)($input['status'] ?? '');
            if(!in_array($status,['active','suspended'],true)) throw new \RuntimeException('Ungültiger Status.');
            Database::connection()->prepare('UPDATE platform_clients SET status=?,updated_at=? WHERE client_id=?')->execute([$status,time(),(string)($input['client_id'] ?? '')]);
            Audit::record((int)$actor['id'],'installation.status_changed','client',(string)$input['client_id'],['status'=>$status]);
            return '/zugang/?view=installations';
        }
        throw new \RuntimeException('Unbekannte Aktion.');
    }
}
