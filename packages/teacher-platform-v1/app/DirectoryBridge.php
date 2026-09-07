<?php
declare(strict_types=1);

namespace ReligionPlatform;

/** First-party roster bridge. One installation, one organisation, explicit ID mappings. */
final class DirectoryBridge
{
    public static function access(array $identity, int $orgId): array
    {
        if ($identity['kind']!=='teacher') return [];
        $q=Database::connection()->prepare('SELECT c.id,CASE WHEN CAST(? AS INTEGER)=1 THEN "admin" ELSE t.role END AS role FROM platform_classes c LEFT JOIN platform_class_teachers t ON t.class_id=c.id AND t.teacher_user_id=? WHERE c.organisation_id=? AND c.status="active" AND (CAST(? AS INTEGER)=1 OR t.role IS NOT NULL) ORDER BY c.id');
        $admin=$identity['role']==='admin'?1:0;
        $q->execute([$admin,(int)$identity['teacher_user_id'],$orgId,$admin]);
        return $q->fetchAll();
    }

    public static function snapshot(array $context): array
    {
        $identity=$context['identity']; $client=$context['client'];
        if ($identity['kind']!=='teacher' || $client['product']!=='assessment') throw new \RuntimeException('access_denied');
        $actor=['id'=>(int)$identity['teacher_user_id']];
        $classes=[];
        foreach (self::access($identity,(int)$client['organisation_id']) as $access) {
            $class=SchoolDirectory::assertClass($actor,$access['id']);
            $members=SchoolDirectory::learners($actor,$class['id']);
            foreach($members as &$member) {
                $person=Identity::find($member['subject']);
                $member['assessment_enabled']=$person && Identity::allows($person,'assessment',(int)$class['organisation_id']);
                unset($member['must_change_password']);
            }
            unset($member);
            $classes[]=['id'=>$class['id'],'label'=>$class['label'],'school_year'=>$class['school_year'],'role'=>$access['role'],'learners'=>$members];
        }
        return ['organisation_id'=>(string)$client['organisation_id'],'classes'=>$classes];
    }

    private static function linked(string $clientId,string $kind,string $externalId): ?string
    {
        $q=Database::connection()->prepare('SELECT platform_id FROM platform_external_links WHERE client_id=? AND entity_type=? AND external_id=?');
        $q->execute([$clientId,$kind,$externalId]);
        return $q->fetchColumn() ?: null;
    }

    private static function bind(array $context,string $kind,string $externalId,string $target): void
    {
        Database::connection()->prepare('INSERT INTO platform_external_links(client_id,entity_type,external_id,platform_id,created_by,created_at) VALUES(?,?,?,?,?,?) ON CONFLICT(client_id,entity_type,external_id) DO NOTHING')->execute([$context['client']['client_id'],$kind,$externalId,$target,(int)$context['identity']['teacher_user_id'],time()]);
    }

    public static function preview(array $context,array $input): array
    {
        $identity=$context['identity']; $client=$context['client'];
        if ($identity['kind']!=='teacher' || $client['product']!=='assessment'
            || !Identity::allows($identity,'assessment',(int)$client['organisation_id'])) throw new \RuntimeException('access_denied');
        $source=$input['class'] ?? null;
        if (!is_array($source) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)($source['external_id'] ?? '')) || !is_array($source['learners'] ?? null) || count($source['learners'])>100) throw new \InvalidArgumentException('Ungültige Klasse; höchstens 100 Personen je Übernahme.');
        $id=self::linked($client['client_id'],'class',(string)$source['external_id']) ?: (string)($source['platform_id'] ?? '');
        $actor=['id'=>(int)$identity['teacher_user_id']];
        if($id) {
            $class=SchoolDirectory::assertClass($actor,$id,true);
            if((int)$class['organisation_id']!==(int)$client['organisation_id']) throw new \RuntimeException('access_denied');
        }
        $label=Security::clean((string)($source['label'] ?? ''),80);
        $year=(string)($source['school_year'] ?? '');
        if(!$label || !preg_match('/^(20\d{2})\/(\d{2})$/D',$year,$m) || (((int)$m[1]+1)%100)!==(int)$m[2]) throw new \InvalidArgumentException('Klassenname oder Schuljahr prüfen.');
        $conflicts=[];
        if(!$id) {
            $q=Database::connection()->prepare('SELECT id FROM platform_classes WHERE organisation_id=? AND label=? AND school_year=?');
            $q->execute([(int)$client['organisation_id'],$label,$year]);
            if($q->fetchColumn()) $conflicts[]='Diese Klasse existiert bereits. Bitte ausdrücklich als Zielklasse auswählen.';
        }
        $members=[];$numbers=[];$externalIds=[];$subjects=[];$usernames=[];
        foreach($source['learners'] as $person) {
            if(!is_array($person)) throw new \InvalidArgumentException('Ungültige Person.');
            $external=(string)($person['external_id'] ?? '');
            $name=Security::clean((string)($person['name'] ?? ''),120);
            $username=strtoupper(Security::clean((string)($person['username'] ?? ''),60));
            $number=(int)($person['number'] ?? 0);
            if(!preg_match('/^[1-9][0-9]{0,9}$/D',$external) || !$name || !preg_match('/^[A-Z0-9][A-Z0-9._-]{2,59}$/D',$username) || $number<1 || $number>999 || isset($numbers[$number]) || isset($externalIds[$external]) || isset($usernames[$username])) throw new \InvalidArgumentException('Namen, Anmeldenamen, Personenkennungen und Listennummern müssen eindeutig sein.');
            $numbers[$number]=$externalIds[$external]=$usernames[$username]=true;
            $subject=self::linked($client['client_id'],'student',$external) ?: (string)($person['platform_id'] ?? '');
            if($subject) {
                $existing=Identity::find($subject);
                if(!$existing || $existing['kind']!=='student' || (int)$existing['learner_org']!==(int)$client['organisation_id'] || isset($subjects[$subject])) throw new \RuntimeException('Schülerzuordnung nicht möglich.');
                if($identity['role']!=='admin') {
                    $q=Database::connection()->prepare('SELECT 1 FROM platform_class_learners r JOIN platform_class_teachers t ON t.class_id=r.class_id WHERE r.subject=? AND r.active=1 AND t.teacher_user_id=? AND t.role IN ("owner","editor")');
                    $q->execute([$subject,(int)$identity['teacher_user_id']]);
                    $linked=self::linked($client['client_id'],'student',$external);
                    if(!$q->fetchColumn() && $linked!==$subject) throw new \RuntimeException('Schülerzuordnung nicht möglich.');
                }
                $subjects[$subject]=true;
            } else {
                $q=Database::connection()->prepare('SELECT subject FROM platform_learners WHERE organisation_id=? AND username=?');
                $q->execute([(int)$client['organisation_id'],$username]);
                if($q->fetchColumn()) $conflicts[]=$username.': Dieser Anmeldename existiert. Person ausdrücklich zuordnen oder Anmeldenamen ändern.';
            }
            if($id) {
                $q=Database::connection()->prepare('SELECT subject FROM platform_class_learners WHERE class_id=? AND roster_number=?');
                $q->execute([$id,$number]);$occupant=$q->fetchColumn();
                if($occupant && $occupant!==$subject) $conflicts[]='Listennummer '.$number.' ist in der Zielklasse belegt.';
            }
            $members[]=['external_id'=>$external,'platform_id'=>$subject,'name'=>$name,'username'=>$username,'number'=>$number,'action'=>$subject?'zuordnen':'neu anlegen'];
        }
        return ['external_id'=>(string)$source['external_id'],'platform_id'=>$id,'label'=>$label,'school_year'=>$year,'learners'=>$members,'conflicts'=>$conflicts];
    }

    public static function import(array $context,array $input): array
    {
        if(($input['confirmed'] ?? false)!==true) throw new \InvalidArgumentException('Die geprüfte Zuordnung muss bestätigt werden.');
        $db=Database::connection();$db->beginTransaction();
        try {
            $plan=self::preview($context,$input);
            if($plan['conflicts']) throw new \RuntimeException(implode(' ',$plan['conflicts']));
            $actor=['id'=>(int)$context['identity']['teacher_user_id']];
            $id=$plan['platform_id'] ?: SchoolDirectory::createClass($actor,(int)$context['client']['organisation_id'],$plan['label'],$plan['school_year'])['id'];
            self::bind($context,'class',$plan['external_id'],$id);
            $credentials=[];$maps=[];
            foreach($plan['learners'] as $person) {
                $subject=$person['platform_id'];
                if(!$subject) {
                    $credential=SchoolDirectory::createLearner($actor,$id,$person['name'],$person['username'],$person['number'],['assessment']);
                    $subject=$credential['subject'];$credentials[]=$credential;
                } else {
                    $db->prepare('INSERT INTO platform_class_learners(class_id,subject,roster_number) VALUES(?,?,?) ON CONFLICT(class_id,subject) DO UPDATE SET roster_number=excluded.roster_number,active=1')->execute([$id,$subject,$person['number']]);
                }
                self::bind($context,'student',$person['external_id'],$subject);
                $maps[]=['external_id'=>$person['external_id'],'subject'=>$subject];
            }
            $db->commit();
        } catch(\Throwable $error) {$db->rollBack();throw $error;}
        Audit::record((int)$actor['id'],'directory.imported','class',$id,['client_id'=>$context['client']['client_id'],'count'=>count($maps)]);
        return ['class_id'=>$id,'learners'=>$maps,'credentials'=>$credentials];
    }
}
