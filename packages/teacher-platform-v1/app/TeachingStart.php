<?php
declare(strict_types=1);
namespace ReligionPlatform;

/** Coordinates existing class assignments and the module's one live-room engine. */
final class TeachingStart
{
    public static function start(array $actor, string $slug, array $input, callable $createRoom, callable $readRoom): array
    {
        Identity::requireLearningTeacher($actor);
        LearningWork::contract($slug);
        $mode=(string)($input['mode'] ?? '');
        if(!in_array($mode,['class','temporary'],true))throw new \InvalidArgumentException('Bitte eine Klasse oder „Ohne Klasse“ auswählen.');
        $classId=(string)($input['class_id'] ?? '');
        if($mode==='temporary' && ($classId!=='' || !empty($input['assignment_id'])))throw new \InvalidArgumentException('Ohne Klasse darf keine persönliche Unterrichtszuweisung mitgesendet werden.');
        $requestId=(string)($input['request_id'] ?? '');
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestId))throw new \InvalidArgumentException('Bitte den Unterrichtseinstieg neu laden.');
        $class=null;
        if($mode==='class') {
            $class=SchoolDirectory::assertClass($actor,$classId,true);
            if($class['status']!=='active' || !Identity::allows(Identity::teacher((int)$actor['id']),'learning',(int)$class['organisation_id']))throw new \RuntimeException('Diese Klasse ist nicht für einen Unterrichtsstart freigegeben.');
        }
        // A class/module/teacher lock serialises double clicks, devices and lost-response retries.
        $key=hash('sha256',implode('|',[(string)$actor['id'],$slug,$mode,$classId ?: $requestId]));
        $directory=rtrim((string)Config::get('data_dir'),'/').'/teaching-starts';
        if(!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory))throw new \RuntimeException('Unterricht konnte nicht vorbereitet werden.');
        $handle=fopen($directory.'/'.$key.'.json','c+');
        if(!$handle)throw new \RuntimeException('Unterricht konnte nicht vorbereitet werden.');
        chmod($directory.'/'.$key.'.json',0600);
        try {
            if(!flock($handle,LOCK_EX|LOCK_NB))throw new \RuntimeException('Dieser Unterricht wird gerade geöffnet. Bitte kurz warten und erneut starten.');
            $assignment=null;
            if($class) {
                $id=(string)($input['assignment_id'] ?? '');
                if($id!=='') {
                    $assignment=LearningWork::assertAssignment(Identity::teacher((int)$actor['id']),$id,true);
                    if($assignment['class_id']!==$classId || $assignment['module_slug']!==$slug || (int)$assignment['created_by']!==(int)$actor['id'])throw new \RuntimeException('Dieser Unterricht gehört nicht zu dieser Auswahl.');
                    if($assignment['status']!=='active')throw new \RuntimeException('Die Bearbeitung ist abgeschlossen. Bitte zuerst in den Schülerständen bewusst wieder öffnen.');
                } else {
                    $q=Database::connection()->prepare('SELECT * FROM learning_assignments WHERE class_id=? AND module_slug=? AND created_by=? AND status="active" AND retain_until>? ORDER BY created_at DESC');
                    $q->execute([$classId,$slug,(int)$actor['id'],time()]);$matches=$q->fetchAll();
                    if(count($matches)>1)throw new \RuntimeException('Für diese Klasse gibt es mehrere Unterrichtsverläufe. Bitte einen zum Fortsetzen auswählen.');
                    $assignment=$matches[0] ?? null;
                    if(!$assignment) {
                        $label=Security::clean((string)($input['label'] ?? ''),100) ?: 'Gemeinsame Erarbeitung';
                        $id=LearningWork::createAssignment($actor,['class_id'=>$classId,'module_slug'=>$slug,'label'=>$label,'retention_days'=>(int)($input['retention_days'] ?? 365)]);
                        $assignment=LearningWork::assertAssignment(Identity::teacher((int)$actor['id']),$id,true);
                    }
                }
            }
            $journal=json_decode(stream_get_contents($handle,4096) ?: '{}',true);
            $journal=is_array($journal)?$journal:[];
            $room=$assignment['room_code'] ?? (($journal['expires_at'] ?? 0)>time()?($journal['room'] ?? ''):'');
            if($assignment && !$room && ($journal['assignment_id'] ?? '')===$assignment['id'] && ($journal['expires_at'] ?? 0)>time())$room=(string)($journal['room'] ?? '');
            $entry=$room?Rooms::find((string)$room):null;
            $expectedOrg=$class?(int)$class['organisation_id']:(Auth::defaultOrganisationId((int)$actor['id']) ?: null);
            if($entry && ((int)$entry['owner_user_id']!==(int)$actor['id'] || $entry['module_slug']!==$slug || ($entry['organisation_id']!==null?(int)$entry['organisation_id']:null)!==$expectedOrg))throw new \RuntimeException('Die vorhandene Live-Verbindung passt nicht zu diesem Unterricht.');
            $resumed=(bool)$entry;
            if($entry) {
                $state=$readRoom($entry['code']);
            } else {
                // Public room labels deliberately contain neither class nor learner names.
                $state=$createRoom('Unterricht',time()+($class?42*86400:8*3600));
                if(!is_array($state) || !preg_match('/^[A-Z2-9]{6}$/D',(string)($state['code'] ?? '')) || (int)($state['ownerUserId'] ?? 0)!==(int)$actor['id'])throw new \RuntimeException('Ungültige Live-Verbindung.');
                Rooms::mirror($state['code'],$slug,$actor,$state,$expectedOrg);
                $journal=['room'=>$state['code'],'assignment_id'=>$assignment['id'] ?? null,'expires_at'=>$state['expiresAt']];
                rewind($handle);ftruncate($handle,0);
                if(fwrite($handle,json_encode($journal,JSON_THROW_ON_ERROR))===false || !fflush($handle))throw new \RuntimeException('Unterrichtsstart konnte nicht gesichert werden. Bitte erneut versuchen.');
            }
            if($assignment && $assignment['room_code']!==$state['code']) {
                Database::connection()->prepare('UPDATE learning_assignments SET room_code=? WHERE id=?')->execute([$state['code'],$assignment['id']]);
            }
            return ['state'=>$state,'teaching'=>['mode'=>$mode,'assignment_id'=>$assignment['id'] ?? null,'class_id'=>$classId ?: null,'class_label'=>$class['label'] ?? null,'label'=>$assignment['label'] ?? 'Ohne Klasse','resumed'=>$resumed]];
        } finally {flock($handle,LOCK_UN);fclose($handle);}
    }
}
