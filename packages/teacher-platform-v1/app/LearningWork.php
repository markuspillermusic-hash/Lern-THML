<?php
declare(strict_types=1);
namespace ReligionPlatform;

/** Personal work is deliberately separate from anonymous classroom state. */
final class LearningWork
{
    public const MAX_BYTES=6291456;

    public static function contract(string $slug): array
    {
        if(!preg_match('/^[a-z0-9][a-z0-9._-]{2,80}$/D',$slug)) throw new \RuntimeException('Lernweg nicht gefunden.');
        $path=dirname(__DIR__).'/registry/learning-contracts/'.$slug.'.json';
        if(!is_file($path)) throw new \RuntimeException('Dieser Lernweg unterstützt noch keine persönliche Synchronisation.');
        $contract=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($contract) || ($contract['version'] ?? 0)!==1 || !is_array($contract['fields'] ?? null)) throw new \RuntimeException('Ungültiger Lernstandsvertrag.');
        return $contract;
    }

    public static function assignments(array $identity,?string $slug=null): array
    {
        $fresh=Identity::find($identity['subject']);
        if(!$fresh || !Identity::allows($fresh,'learning')) return [];
        $sql='SELECT a.*,c.label AS class_label,c.status AS class_status,c.school_year,c.organisation_id,m.label AS module_label,m.public_url,u.display_name AS teacher_name FROM learning_assignments a JOIN platform_classes c ON c.id=a.class_id JOIN modules m ON m.slug=a.module_slug JOIN users u ON u.id=a.created_by WHERE a.retain_until>?';
        $args=[time()];
        if($slug!==null){$sql.=' AND a.module_slug=?';$args[]=$slug;}
        $q=Database::connection()->prepare($sql.' ORDER BY a.created_at DESC');$q->execute($args);
        $result=[];
        foreach($q->fetchAll() as $assignment) {
            try {
                self::assertAssignment($fresh,$assignment['id']);
                if($assignment['room_code'] && !Rooms::find($assignment['room_code']))$assignment['room_code']=null;
                $assignment['material_access_key']=self::materialAccess($assignment);$result[]=$assignment;
            } catch(\RuntimeException $ignored){}
        }
        return $result;
    }

    public static function assertAssignment(array $identity,string $id,bool $write=false): array
    {
        $fresh=Identity::find($identity['subject']);
        $q=Database::connection()->prepare('SELECT a.*,c.organisation_id,c.label AS class_label,c.status AS class_status FROM learning_assignments a JOIN platform_classes c ON c.id=a.class_id WHERE a.id=? AND a.retain_until>?');
        $q->execute([$id,time()]);$assignment=$q->fetch();
        if(!$fresh || !$assignment || !Identity::allows($fresh,'learning',(int)$assignment['organisation_id'])) throw new \RuntimeException('Lernstand nicht gefunden oder nicht freigegeben.');
        if($fresh['kind']==='teacher') SchoolDirectory::assertClass(['id'=>(int)$fresh['teacher_user_id']],$assignment['class_id'],$write);
        else {
            $q=Database::connection()->prepare('SELECT 1 FROM platform_class_learners WHERE class_id=? AND subject=? AND active=1');
            $q->execute([$assignment['class_id'],$fresh['subject']]);
            if(!$q->fetchColumn() || !empty($fresh['must_change_password'])) throw new \RuntimeException('Lernstand nicht gefunden oder nicht freigegeben.');
            if($write && ($assignment['status']!=='active'||$assignment['class_status']!=='active')) throw new \RuntimeException('Dieser Lernweg oder seine Klasse ist abgeschlossen. Dein Stand bleibt lesbar.');
        }
        return $assignment;
    }

    /** Only called after the personal assignment check; never part of a public room response. */
    private static function materialAccess(array $assignment): string
    {
        $code=(string)($assignment['room_code'] ?? '');$slug=(string)$assignment['module_slug'];
        if(!preg_match('/^[A-Z2-9]{6}$/D',$code)||!preg_match('/^[a-z0-9][a-z0-9._-]{2,80}$/D',$slug))return '';
        $room=Rooms::find($code);
        if(!$room||$room['module_slug']!==$slug||(int)$room['organisation_id']!==(int)$assignment['organisation_id'])return '';
        $path=rtrim((string)Config::get('data_dir'),'/').'/modules/'.$slug.'/rooms/room-'.$code.'.json';
        $file=@fopen($path,'rb');if(!$file)return '';
        try {if(!flock($file,LOCK_SH))return ''; $state=json_decode(stream_get_contents($file,2097152),true);flock($file,LOCK_UN);}
        finally {fclose($file);}
        if(!is_array($state)||(int)($state['expiresAt'] ?? 0)<=time())return '';
        $key=(string)($state['materialAccessKey'] ?? '');return preg_match('/^[a-f0-9]{48}$/D',$key)?$key:'';
    }

    public static function createAssignment(array $actor,array $input): string
    {
        $identity=Identity::teacher((int)$actor['id']);
        $class=SchoolDirectory::assertClass($actor,(string)($input['class_id'] ?? ''),true);
        if($class['status']!=='active' || !Identity::allows($identity,'learning',(int)$class['organisation_id'])) throw new \RuntimeException('Klasse oder LernHTML nicht freigegeben.');
        $slug=(string)($input['module_slug'] ?? '');self::contract($slug);
        Modules::syncRegistry();
        $q=Database::connection()->prepare('SELECT 1 FROM modules WHERE slug=? AND status="active"');$q->execute([$slug]);
        if(!$q->fetchColumn()) throw new \RuntimeException('Lernweg nicht freigegeben.');
        $days=(int)($input['retention_days'] ?? 365);
        if($days<30 || $days>730) throw new \InvalidArgumentException('Aufbewahrung: 30 bis 730 Tage.');
        $label=Security::clean((string)($input['label'] ?? ''),100);
        if(!$label) throw new \InvalidArgumentException('Bitte eine Bezeichnung eintragen.');
        $room=strtoupper((string)($input['room'] ?? ''));
        if($room) self::assertRoom($actor,$room,$slug,(int)$class['organisation_id']);
        $id=bin2hex(random_bytes(16));
        Database::connection()->prepare('INSERT INTO learning_assignments(id,class_id,module_slug,label,room_code,created_by,created_at,retain_until) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$class['id'],$slug,$label,$room ?: null,(int)$actor['id'],time(),time()+$days*86400]);
        Audit::record((int)$actor['id'],'learning.assigned','assignment',$id,['class_id'=>$class['id'],'module_slug'=>$slug,'retention_days'=>$days]);
        return $id;
    }

    private static function assertRoom(array $actor,string $room,string $slug,int $org): array
    {
        $entry=Rooms::find($room);
        if(!$entry || $entry['module_slug']!==$slug || (int)$entry['organisation_id']!==$org) throw new \RuntimeException('Der Raum gehört nicht zu diesem Lernweg und dieser Organisation.');
        Rooms::assertOwner($entry,$actor);return $entry;
    }

    private static function seal(array $payload,string $context): string
    {
        $key=hash_hmac('sha256','learning-work-v1|'.$context,Vault::masterKey(),true);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$nonce,$key));
    }

    private static function open(string $cipher,string $context): array
    {
        $raw=base64_decode($cipher,true);
        if(!is_string($raw) || strlen($raw)<40) throw new \RuntimeException('Der gespeicherte Lernstand ist beschädigt.');
        $key=hash_hmac('sha256','learning-work-v1|'.$context,Vault::masterKey(),true);
        $plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$key);
        if($plain===false) throw new \RuntimeException('Der gespeicherte Lernstand konnte nicht entschlüsselt werden.');
        return json_decode($plain,true,64,JSON_THROW_ON_ERROR);
    }

    public static function cleanPayload(array $payload,array $contract): array
    {
        if(($payload['moduleId'] ?? '')!==$contract['moduleId'] || (int)($payload['version'] ?? 0)!==2) throw new \InvalidArgumentException('Der Lernstand gehört zu einer anderen Lerneinheit oder Version.');
        if(strlen(json_encode($payload,JSON_THROW_ON_ERROR))>self::MAX_BYTES) throw new \InvalidArgumentException('Der Lernstand ist zu groß. Bitte die lokale Sicherung aufbewahren.');
        foreach(['fields','ui','checks','learningTools','conceptMaps'] as $key)if(isset($payload[$key]) && !is_array($payload[$key]))throw new \InvalidArgumentException('Ungültiger Lernstandsbereich: '.$key);
        foreach(['highlights','drawings'] as $key)if(isset($payload['learningTools'][$key]) && !is_array($payload['learningTools'][$key]))throw new \InvalidArgumentException('Ungültige Lernwerkzeuge.');
        if(isset($payload['conceptMaps']['maps']) && !is_array($payload['conceptMaps']['maps']))throw new \InvalidArgumentException('Ungültiges Begriffsnetz.');
        $fields=[];
        foreach(($payload['fields'] ?? []) as $key=>$value) {
            if(!isset($contract['fields'][$key])) continue;
            if(!is_string($value) || mb_strlen($value)>30000) throw new \InvalidArgumentException('Ein Antwortfeld ist zu groß oder ungültig.');
            $fields[$key]=$value;
        }
        $ui=[];$checks=[];
        foreach(($payload['ui'] ?? []) as $key=>$value) if(preg_match('/^[a-zA-Z0-9._-]{1,80}$/D',(string)$key) && (is_string($value) || is_bool($value) || is_int($value)) && strlen((string)$value)<2000)$ui[$key]=$value;
        foreach(($payload['checks'] ?? []) as $key=>$value) if(preg_match('/^[a-zA-Z0-9._-]{1,80}$/D',(string)$key) && (is_bool($value)||is_int($value)))$checks[$key]=$value;
        $tools=['version'=>1,'highlights'=>[],'drawings'=>[]];
        foreach(($payload['learningTools']['highlights'] ?? []) as $id=>$entries) {
            if(!isset($contract['materials'][$id]) || !is_array($entries) || count($entries)>2000) continue;
            $tools['highlights'][$id]=[];
            foreach($entries as $entry)if(is_array($entry) && is_int($entry['start'] ?? null) && is_int($entry['end'] ?? null) && $entry['start']>=0 && $entry['end']>$entry['start'] && $entry['end']<=200000 && in_array($entry['color'] ?? '',['gold','coral','teal','blue'],true))$tools['highlights'][$id][]=['start'=>$entry['start'],'end'=>$entry['end'],'color'=>$entry['color']];
        }
        foreach(($payload['learningTools']['drawings'] ?? []) as $id=>$drawing) {
            if(!in_array($id,$contract['drawings'] ?? [],true) || !is_array($drawing['strokes'] ?? null) || count($drawing['strokes'])>3000)continue;
            $strokes=[];
            foreach($drawing['strokes'] as $stroke) {
                if(!is_array($stroke) || !in_array($stroke['mode'] ?? '',['pen','marker','eraser'],true) || !preg_match('/^#[a-f0-9]{3,8}$/iD',(string)($stroke['color'] ?? '')) || !is_numeric($stroke['width'] ?? null) || !is_array($stroke['points'] ?? null) || count($stroke['points'])>20000)continue;
                $points=[];foreach($stroke['points'] as $point)if(is_array($point) && is_numeric($point['x'] ?? null) && is_numeric($point['y'] ?? null))$points[]=['x'=>max(0,min(1,(float)$point['x'])),'y'=>max(0,min(1,(float)$point['y']))];
                $strokes[]=['mode'=>$stroke['mode'],'color'=>$stroke['color'],'width'=>max(.1,min(80,(float)$stroke['width'])),'points'=>$points];
            }
            $tools['drawings'][$id]=['strokes'=>$strokes];
        }
        $maps=['version'=>1,'maps'=>[]];
        foreach(($payload['conceptMaps']['maps'] ?? []) as $id=>$map) {
            if(!isset($contract['maps'][$id]) || !is_array($map['state'] ?? null))continue;
            foreach(['nodes','edges','viewport'] as $key)if(isset($map['state'][$key]) && !is_array($map['state'][$key]))throw new \InvalidArgumentException('Ungültige Begriffsnetz-Daten.');
            $known=array_column($contract['maps'][$id]['nodes'],'id');$nodes=[];$edges=[];
            foreach(($map['state']['nodes'] ?? []) as $node)if(is_array($node) && in_array($node['id'] ?? '',$known,true))$nodes[]=['id'=>$node['id'],'x'=>max(0,min(1160,(float)($node['x'] ?? 0))),'y'=>max(0,min(650,(float)($node['y'] ?? 0))),'color'=>in_array($node['color'] ?? '',['brass','rose','sage','blue','paper'],true)?$node['color']:'paper'];
            foreach(array_slice($map['state']['edges'] ?? [],0,40) as $i=>$edge)if(is_array($edge) && in_array($edge['from'] ?? '',$known,true)&&in_array($edge['to'] ?? '',$known,true)&&$edge['from']!==$edge['to'])$edges[]=['id'=>'edge-'.$i,'from'=>$edge['from'],'to'=>$edge['to'],'label'=>Security::clean((string)($edge['label'] ?? ''),54),'reason'=>Security::cleanMultiline((string)($edge['reason'] ?? ''),500)];
            $v=$map['state']['viewport'] ?? [];
            $maps['maps'][$id]=['version'=>1,'id'=>$id,'state'=>['version'=>1,'id'=>$id,'nodes'=>$nodes,'edges'=>$edges,'viewport'=>['scale'=>max(.45,min(1.35,(float)($v['scale'] ?? .78))),'x'=>max(-1100,min(900,(float)($v['x'] ?? 18))),'y'=>max(-650,min(600,(float)($v['y'] ?? 16)))]]];
        }
        return ['version'=>2,'_formatVersion'=>2,'moduleId'=>$contract['moduleId'],'fields'=>$fields,'checks'=>$checks,'ui'=>$ui,'learningTools'=>$tools,'conceptMaps'=>$maps];
    }

    public static function read(array $identity,string $assignmentId,?string $subject=null,?int $revision=null): array
    {
        $assignment=self::assertAssignment($identity,$assignmentId);
        $subject=$identity['kind']==='student'?$identity['subject']:($subject ?? '');
        $q=Database::connection()->prepare('SELECT w.*,l.display_name FROM learning_work w JOIN platform_learners l ON l.subject=w.subject WHERE w.assignment_id=? AND w.subject=?');$q->execute([$assignmentId,$subject]);$work=$q->fetch();
        $base=['assignment_id'=>$assignmentId,'subject'=>$subject,'revision'=>0,'updated_at'=>null,'payload'=>null];
        if(!$work)return $base;
        $q=Database::connection()->prepare('SELECT revision,updated_at FROM learning_work_history WHERE assignment_id=? AND subject=? ORDER BY revision DESC');$q->execute([$assignmentId,$subject]);
        $history=array_map(static fn($r)=>['revision'=>(int)$r['revision'],'updated_at'=>(int)$r['updated_at']],$q->fetchAll());
        $base+=['current_revision'=>(int)$work['revision'],'history'=>$history];
        if($revision!==null && $revision!==(int)$work['revision']) {
            $q=Database::connection()->prepare('SELECT revision,updated_at,payload_cipher FROM learning_work_history WHERE assignment_id=? AND subject=? AND revision=?');$q->execute([$assignmentId,$subject,$revision]);$older=$q->fetch();
            if(!$older)throw new \RuntimeException('Diese Vorversion ist nicht mehr vorhanden.');
            $work=array_replace($work,$older);
        }
        return array_replace($base,['revision'=>(int)$work['revision'],'updated_at'=>(int)$work['updated_at'],'name'=>$work['display_name'],'payload'=>self::open($work['payload_cipher'],$assignmentId.'|'.$subject)]);
    }

    public static function save(array $identity,array $input): array
    {
        if($identity['kind']!=='student' || !hash_equals($identity['subject'],(string)($input['subject'] ?? '')))throw new \RuntimeException('Die angemeldete Person hat gewechselt. Bitte die Seite neu öffnen.');
        $id=(string)($input['assignment_id'] ?? '');$assignment=self::assertAssignment($identity,$id,true);
        $payload=self::cleanPayload(is_array($input['payload'] ?? null)?$input['payload']:[],self::contract($assignment['module_slug']));
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$json);
        $base=$input['base_revision'] ?? null;
        if(!is_int($base)||$base<0)throw new \InvalidArgumentException('Die Speicherrevision fehlt.');
        $db=Database::connection();$db->beginTransaction();
        try {
            $q=$db->prepare('SELECT * FROM learning_work WHERE assignment_id=? AND subject=?');$q->execute([$id,$identity['subject']]);$old=$q->fetch();
            if($old && hash_equals($old['payload_hash'],$hash)){$db->commit();return ['ok'=>true,'revision'=>(int)$old['revision'],'updated_at'=>(int)$old['updated_at']];}
            if(($old?(int)$old['revision']:0)!==$base){$db->rollBack();return ['conflict'=>true,'remote'=>self::read($identity,$id)];}
            $revision=$base+1;$now=time();$encrypted=self::seal($payload,$id.'|'.$identity['subject']);
            $count=count(array_filter($payload['fields'],static fn($v)=>trim($v)!==''));
            if($old){
                $db->prepare('INSERT INTO learning_work_history(assignment_id,subject,revision,payload_cipher,updated_at) VALUES(?,?,?,?,?)')->execute([$id,$identity['subject'],$old['revision'],$old['payload_cipher'],$old['updated_at']]);
                $db->prepare('UPDATE learning_work SET revision=?,payload_cipher=?,payload_hash=?,field_count=?,updated_at=? WHERE assignment_id=? AND subject=?')->execute([$revision,$encrypted,$hash,$count,$now,$id,$identity['subject']]);
                $db->prepare('DELETE FROM learning_work_history WHERE assignment_id=? AND subject=? AND revision<?')->execute([$id,$identity['subject'],max(1,$revision-3)]);
            } else $db->prepare('INSERT INTO learning_work(assignment_id,subject,revision,payload_cipher,payload_hash,field_count,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,$identity['subject'],$revision,$encrypted,$hash,$count,$now]);
            $db->commit();return ['ok'=>true,'revision'=>$revision,'updated_at'=>$now];
        }catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    }

    public static function overview(array $identity,string $assignmentId): array
    {
        if($identity['kind']!=='teacher')throw new \RuntimeException('Nur für Lehrkräfte.');
        $assignment=self::assertAssignment($identity,$assignmentId);
        $q=Database::connection()->prepare('SELECT l.subject,l.display_name,r.roster_number,r.active,w.revision,w.field_count,w.updated_at FROM platform_class_learners r JOIN platform_learners l ON l.subject=r.subject LEFT JOIN learning_work w ON w.subject=r.subject AND w.assignment_id=? WHERE r.class_id=? ORDER BY r.active DESC,r.roster_number');
        $q->execute([$assignmentId,$assignment['class_id']]);
        return ['assignment'=>$assignment,'learners'=>$q->fetchAll(),'contract'=>self::contract($assignment['module_slug'])];
    }

    public static function publish(array $identity,array $input): array
    {
        if($identity['kind']!=='teacher')throw new \RuntimeException('Nur für Lehrkräfte.');
        $assignment=self::assertAssignment($identity,(string)($input['assignment_id'] ?? ''),true);
        $actor=['id'=>(int)$identity['teacher_user_id']];$room=strtoupper((string)($input['room'] ?? $assignment['room_code'] ?? ''));
        $roomInfo=self::assertRoom($actor,$room,$assignment['module_slug'],(int)$assignment['organisation_id']);
        $work=self::read($identity,$assignment['id'],(string)($input['subject'] ?? ''));
        if(!$work['payload'])throw new \RuntimeException('Noch kein Lernstand vorhanden.');
        if(($input['source_revision'] ?? null)!==$work['revision'])throw new \RuntimeException('Der Schülerstand wurde inzwischen verändert. Bitte aktualisieren und vor der Beamerfreigabe erneut prüfen.');
        $contract=self::contract($assignment['module_slug']);$id=(string)($input['item_id'] ?? '');$kind=(string)($input['kind'] ?? '');
        $selection=['kind'=>$kind,'title'=>'Schülerlösung','name'=>!empty($input['show_name'])?$work['name']:'','source_revision'=>$work['revision']];
        if($kind==='field' && isset($contract['fields'][$id])){$selection['label']=$contract['fields'][$id];$selection['text']=$work['payload']['fields'][$id] ?? '';}
        elseif($kind==='map' && isset($contract['maps'][$id],$work['payload']['conceptMaps']['maps'][$id])){
            $selection['map']=$work['payload']['conceptMaps']['maps'][$id];$selection['config']=$contract['maps'][$id];$selection['fields']=[];
            foreach($selection['config']['nodes'] as $node)$selection['fields'][$node['sourceField']]=$work['payload']['fields'][$node['sourceField']] ?? '';
        } else throw new \RuntimeException('Bitte genau ein Antwortfeld oder Begriffsnetz auswählen.');
        $version=bin2hex(random_bytes(12));
        Database::connection()->prepare('INSERT INTO learning_projections(room_code,assignment_id,subject,selection_cipher,published_by,revision,expires_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(room_code) DO UPDATE SET assignment_id=excluded.assignment_id,subject=excluded.subject,selection_cipher=excluded.selection_cipher,published_by=excluded.published_by,revision=excluded.revision,expires_at=excluded.expires_at')->execute([$room,$assignment['id'],$work['subject'],self::seal($selection,'projection|'.$room),(int)$actor['id'],$version,min((int)$roomInfo['expires_at'],time()+3600)]);
        Audit::record((int)$actor['id'],'learning.presented','assignment',$assignment['id'],['room'=>$room,'kind'=>$kind,'item_id'=>$id]);
        return ['ok'=>true,'room'=>$room,'revision'=>$version];
    }

    public static function projection(string $room,string $slug): array
    {
        $entry=Rooms::find($room);
        if(!$entry || $entry['module_slug']!==$slug)return ['active'=>false];
        $q=Database::connection()->prepare('SELECT p.*,a.module_slug FROM learning_projections p JOIN learning_assignments a ON a.id=p.assignment_id WHERE p.room_code=? AND p.expires_at>? AND a.retain_until>?');
        $q->execute([$room,time(),time()]);$row=$q->fetch();
        if(!$row)return ['active'=>false];
        // Current teacher/class/product permissions remain authoritative after publication.
        try{self::assertAssignment(Identity::teacher((int)$row['published_by']),$row['assignment_id'],true);}catch(\RuntimeException $ignored){return ['active'=>false];}
        return ['active'=>true,'revision'=>$row['revision'],'selection'=>self::open($row['selection_cipher'],'projection|'.$room)];
    }

    public static function stopProjection(array $actor,string $room): void
    {
        $entry=Rooms::find($room);if(!$entry)throw new \RuntimeException('Raum nicht aktiv.');Rooms::assertOwner($entry,$actor);
        Database::connection()->prepare('DELETE FROM learning_projections WHERE room_code=?')->execute([$room]);
    }
}
