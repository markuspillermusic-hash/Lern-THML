<?php
declare(strict_types=1);
require_once getenv('TEACHER_PLATFORM_BOOTSTRAP') ?: '/websites/_protected/teacher-platform-v1/app/bootstrap.php';
use ReligionPlatform\Auth;
use ReligionPlatform\Database;
use ReligionPlatform\Identity;
use ReligionPlatform\LearningWork;
use ReligionPlatform\SchoolDirectory;
use ReligionPlatform\Security;
Security::headers("default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
header('Content-Type: application/json; charset=utf-8');
try {
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';$input=$_GET;
    if($method==='POST') {
        if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')),'application/json'))throw new InvalidArgumentException('JSON-Anfrage erforderlich.');
        $raw=file_get_contents('php://input',false,null,0,LearningWork::MAX_BYTES+65537);
        if(strlen($raw)>LearningWork::MAX_BYTES+65536)throw new InvalidArgumentException('Lernstand zu groß.');
        $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input))throw new InvalidArgumentException('Ungültige Anfrage.');
    }elseif($method!=='GET'){http_response_code(405);echo '{"error":"Methode nicht erlaubt."}';exit;}
    $action=(string)($input['action'] ?? 'session');
    if($action==='projection' && $method==='GET') {
        $room=strtoupper((string)($input['room'] ?? ''));
        if(!preg_match('/^[A-Z2-9]{6}$/D',$room))throw new InvalidArgumentException('Ungültiger Raum.');
        if(!Security::rateLimit('learning-projection',Security::clientIpHash('learning-projection'),60,600))throw new RuntimeException('Bitte kurz warten.');
        $result=LearningWork::projection($room,(string)($input['module_slug'] ?? ''));
    } else {
        $identity=Identity::current();
        if($action==='session' && $method==='GET') {
            $slug=(string)($input['module_slug'] ?? '');
            $result=['authenticated'=>(bool)$identity,'login_url'=>'/zugang/'];
            if($identity)$result+=['subject'=>$identity['subject'],'kind'=>$identity['kind'],'role'=>$identity['role'],'name'=>$identity['display_name'],'csrf'=>Security::csrf(),'learning_enabled'=>Identity::allows($identity,'learning'),'assignments'=>LearningWork::assignments($identity,$slug ?: null)];
            if($identity && $identity['kind']==='teacher' && Identity::allows($identity,'learning')) {
                $result['classes']=SchoolDirectory::classes(['id'=>(int)$identity['teacher_user_id']]);$result['modules']=[];
                foreach(ReligionPlatform\Modules::all() as $module){try{LearningWork::contract($module['slug']);$result['modules'][]=['slug'=>$module['slug'],'label'=>$module['label']];}catch(RuntimeException $ignored){}}
            }
        } else {
            if(!$identity){http_response_code(401);echo '{"error":"Bitte erneut anmelden."}';exit;}
            if(!Security::rateLimit('learning-work',$identity['subject'],60,120))throw new RuntimeException('Zu viele Anfragen. Bitte kurz warten.');
            $actor=$identity['kind']==='teacher'?Auth::currentUser():null;
            if($method==='POST' && !Security::verifyCsrf((string)($_SERVER['HTTP_X_PLATFORM_CSRF'] ?? ''))){http_response_code(403);echo '{"error":"Das Formular ist abgelaufen. Bitte neu laden."}';exit;}
            if($method==='GET') {
                $id=(string)($input['assignment_id'] ?? '');
                if(isset($input['revision']) && !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$input['revision']))throw new InvalidArgumentException('Ungültige Revision.');
                $result=match($action){'read'=>LearningWork::read($identity,$id,(string)($input['subject'] ?? ''),isset($input['revision'])?(int)$input['revision']:null),'overview'=>LearningWork::overview($identity,$id),default=>throw new InvalidArgumentException('Unbekannte Leseaktion.')};
            } elseif($action==='save') {
                $result=LearningWork::save($identity,$input);
                if(!empty($result['conflict']))http_response_code(409);
            } else {
                if(!$actor)throw new RuntimeException('Nur für Lehrkräfte.');
                if($action==='create')$result=['ok'=>true,'assignment_id'=>LearningWork::createAssignment($actor,$input)];
                elseif($action==='publish')$result=LearningWork::publish($identity,$input);
                elseif($action==='stop'){LearningWork::stopProjection($actor,strtoupper((string)($input['room'] ?? '')));$result=['ok'=>true];}
                elseif($action==='status') {
                    $assignment=LearningWork::assertAssignment($identity,(string)($input['assignment_id'] ?? ''),true);
                    $status=(string)($input['status'] ?? '');
                    if(!in_array($status,['active','closed'],true))throw new InvalidArgumentException('Ungültiger Status.');
                    Database::connection()->prepare('UPDATE learning_assignments SET status=? WHERE id=?')->execute([$status,$assignment['id']]);
                    $result=['ok'=>true];
                } elseif($action==='delete') {
                    Identity::assertAdmin($actor);
                    $assignment=LearningWork::assertAssignment($identity,(string)($input['assignment_id'] ?? ''),true);
                    if(($input['confirmation'] ?? '')!==$assignment['label'])throw new InvalidArgumentException('Zum Löschen die Bezeichnung des Lernwegs bestätigen.');
                    Database::connection()->prepare('DELETE FROM learning_assignments WHERE id=?')->execute([$assignment['id']]);
                    ReligionPlatform\Audit::record((int)$actor['id'],'learning.deleted','assignment',$assignment['id']);$result=['ok'=>true];
                } else throw new InvalidArgumentException('Unbekannte Aktion.');
            }
        }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException || $error instanceof JsonException?400:403);
    echo json_encode(['error'=>$error instanceof PDOException?'Der Lernstand konnte gerade nicht gespeichert werden. Die lokale Fassung bleibt erhalten.':$error->getMessage()],JSON_UNESCAPED_UNICODE);
}
