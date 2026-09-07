<?php
declare(strict_types=1);

ob_start(); // Session headers stay testable after assertions.
$temp = sys_get_temp_dir() . '/unified-platform-test-' . bin2hex(random_bytes(8));
mkdir($temp,0700,true);
file_put_contents($temp.'/master.key',base64_encode(random_bytes(32)));
file_put_contents($temp.'/config.php','<?php return '.var_export([
    'database'=>$temp.'/platform.sqlite','data_dir'=>$temp,'master_key_file'=>$temp.'/master.key',
    'base_url'=>'https://school.example','session_name'=>'unified_platform_test','mail_mode'=>'disabled',
],true).';');
putenv('TEACHER_PLATFORM_CONFIG='.$temp.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';
set_exception_handler(static function(Throwable $error): never { fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n"); exit(1); });

use ReligionPlatform\Auth;
use ReligionPlatform\Database;
use ReligionPlatform\Identity;
use ReligionPlatform\Oidc;
use ReligionPlatform\Schema;
use ReligionPlatform\SchoolDirectory;
use ReligionPlatform\Security;

$checks = 0;
function check(bool $value,string $message): void {
    global $checks;
    if (!$value) throw new RuntimeException('FAIL: '.$message);
    $checks++;
    echo 'ok - '.$message."\n";
}
function rejects(callable $fn,string $message): void {
    try {$fn();} catch (Throwable $error) {check(true,$message);return;}
    check(false,$message);
}
function teacher(string $name,int $org,bool $admin=false): array {
    $db=Database::connection();
    $db->prepare('INSERT INTO users(username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at) VALUES(?,?,?,?,?,"active",?,?,?)')
        ->execute([$name,$name.'@example.invalid',$name,password_hash('A long test password 2026',PASSWORD_ARGON2ID),$admin?'admin':'teacher',bin2hex(random_bytes(16)),time(),time()]);
    $id=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,created_at) VALUES(?,?,"teacher",?)')->execute([$id,$org,time()]);
    return $db->query('SELECT * FROM users WHERE id='.$id)->fetch();
}
$db=Database::connection();
$db->exec("INSERT INTO organisations(id,name,slug,kind,status,created_at,updated_at) VALUES(1,'School A','a','school','active',1,1),(2,'School B','b','school','active',1,1)");
$admin=teacher('admin',1,true);
$learning=teacher('learning',1);
$assessment=teacher('assessment',1);
$both=teacher('both',1);
$foreign=teacher('foreign',2);
$a=Identity::teacher((int)$admin['id']);
$l=Identity::teacher((int)$learning['id']);
$e=Identity::teacher((int)$assessment['id']);
$b=Identity::teacher((int)$both['id']);
check(Identity::allows($l,'learning') && !Identity::allows($l,'assessment'),'existing teacher keeps learning access only');
Identity::setProducts($admin,$e['subject'],['assessment']);
Identity::setProducts($admin,$b['subject'],['learning','assessment']);
check(!Identity::allows($e,'learning') && Identity::allows($e,'assessment'),'assessment-only account has no learning permission');
check(Identity::allows($b,'learning') && Identity::allows($b,'assessment'),'combined account can use both products');
check(Identity::allows($a,'learning') && Identity::allows($a,'assessment'),'global admin can manage both products');
check(!Identity::allows($b,'assessment',2),'membership prevents access to another organisation');
rejects(fn()=>Identity::setOrganisationProducts($both,1,['learning']),'ordinary teacher cannot change organisation products');
Identity::setOrganisationProducts($admin,1,['learning']);
check(!Identity::allows($b,'assessment',1) && Identity::allows($b,'learning',1),'organisation product switch applies independently of personal grants');
Identity::setOrganisationProducts($admin,1,['learning','assessment']);
check(Identity::allows($b,'assessment',1),'restoring organisation product preserves personal grants');
rejects(fn()=>Identity::setProducts($learning,$e['subject'],['learning']),'teacher cannot change another account products');
$class=SchoolDirectory::createClass($both,1,'13 Religion','2026/27');
check(count(SchoolDirectory::classes($both))===1,'class owner can list their class');
check(count(SchoolDirectory::classes($learning))===0,'same-school colleague cannot list unassigned class');
rejects(fn()=>SchoolDirectory::assertClass($foreign,$class['id']),'other organisation cannot inspect class by ID');
SchoolDirectory::setTeacher($admin,$class['id'],(int)$learning['id'],'viewer');
check(SchoolDirectory::assertClass($learning,$class['id'])['id']===$class['id'],'assigned viewer can read class');
rejects(fn()=>SchoolDirectory::assertClass($learning,$class['id'],true),'viewer cannot write class');
rejects(fn()=>SchoolDirectory::setTeacher($admin,$class['id'],(int)$foreign['id'],'editor'),'cross-organisation teacher assignment is rejected');
$student=SchoolDirectory::createLearner($both,$class['id'],'Example Student','13R01',1,['learning','assessment']);
check(count(SchoolDirectory::learners($both,$class['id']))===1,'learner added to authorised class');
check(!array_key_exists('password_hash',SchoolDirectory::learners($both,$class['id'])[0]),'class list never exposes password hash');
$learner=SchoolDirectory::loginLearner('a','13R01',$student['start_password']);
check(Identity::current()['subject']===$student['subject'],'student signs into central identity');
check((bool)$learner['must_change_password'],'start password requires change');
SchoolDirectory::changeLearnerPassword($learner,$student['start_password'],'My personal school password 2026');
check(!Identity::current()['must_change_password'],'password change unlocks normal use');
rejects(fn()=>SchoolDirectory::loginLearner('b','13R01','My personal school password 2026'),'same alias cannot log into another organisation');
rejects(fn()=>SchoolDirectory::createLearner($learning,$class['id'],'Blocked','13R02',2,['learning']),'viewer cannot create accounts');

Oidc::provisionSigningKey($admin);
$registration=Oidc::registerClient($admin,[
    'client_id'=>'exam-a','organisation_id'=>1,'product'=>'assessment','base_url'=>'https://exam.example',
    'redirect_uris'=>['https://exam.example/api/platform/callback'],'label'=>'Exam A',
]);
$client=Oidc::authenticateClient('exam-a',$registration['client_secret']);
rejects(fn()=>Oidc::authenticateClient('exam-a','incorrect'),'client authentication rejects wrong secret');
$verifier=Oidc::base64url(random_bytes(48));
$request=['client_id'=>'exam-a','redirect_uri'=>'https://exam.example/api/platform/callback','response_type'=>'code',
    'scope'=>'openid profile','code_challenge_method'=>'S256','code_challenge'=>Oidc::base64url(hash('sha256',$verifier,true)),
    'state'=>Oidc::base64url(random_bytes(24)),'nonce'=>Oidc::base64url(random_bytes(24))];
rejects(fn()=>Oidc::authorize($request,$l,time()),'learning-only teacher cannot enter exam client');
rejects(fn()=>Oidc::authorize($request,Identity::teacher((int)$foreign['id']),time()),'foreign teacher cannot enter school client');
$bad=$request;$bad['redirect_uri']='https://exam.example.attacker.invalid/api/platform/callback';
rejects(fn()=>Oidc::validateAuthorization($bad),'redirect URI requires exact registered value');
$bad=$request;$bad['code_challenge_method']='plain';
rejects(fn()=>Oidc::validateAuthorization($bad),'plain PKCE is not accepted');
$redirect=Oidc::authorize($request,Identity::find($b['subject']),time());
parse_str((string)parse_url($redirect,PHP_URL_QUERY),$response);
check($response['state']===$request['state'] && $response['iss']===Oidc::issuer(),'authorization response preserves state and issuer');
$exchange=['grant_type'=>'authorization_code','code'=>$response['code'],'redirect_uri'=>$request['redirect_uri'],'code_verifier'=>$verifier];
$bad=$exchange;$bad['code_verifier']=str_repeat('x',64);
rejects(fn()=>Oidc::exchange($client,$bad),'wrong verifier cannot redeem code');
$tokens=Oidc::exchange($client,$exchange);
rejects(fn()=>Oidc::exchange($client,$exchange),'authorization code is single-use');
$info=Oidc::userinfo($tokens['access_token']);
check($info['sub']===$b['subject'] && $info['role']==='teacher' && $info['organisation_id']==='1','userinfo carries stable verified teacher and organisation');
$parts=explode('.',$tokens['id_token']);
$decode=static fn(string $v)=>base64_decode(strtr($v,'-_','+/'),true);
$claims=json_decode($decode($parts[1]),true,512,JSON_THROW_ON_ERROR);
check($claims['nonce']===$request['nonce'] && $claims['aud']==='exam-a' && $claims['iss']===Oidc::issuer(),'ID token binds nonce, audience and issuer');
$jwks=Oidc::jwks();
check($jwks['keys'][0]['alg']==='RS256' && !isset($jwks['keys'][0]['d']),'JWKS exposes only public signing material');
$pem=ReligionPlatform\Vault::get('system',0,'oidc_signing_private');
$details=openssl_pkey_get_details(openssl_pkey_get_private($pem));
check(openssl_verify($parts[0].'.'.$parts[1],$decode($parts[2]),$details['key'],OPENSSL_ALGO_SHA256)===1,'ID token signature verifies with public key');
check(openssl_verify($parts[0].'.tampered',$decode($parts[2]),$details['key'],OPENSSL_ALGO_SHA256)!==1,'modified token cannot verify');
Identity::setProducts($admin,$b['subject'],['learning']);
rejects(fn()=>Oidc::userinfo($tokens['access_token']),'product revocation invalidates existing token immediately');
$redirect=Oidc::authorize($request,Identity::current(),time());
parse_str((string)parse_url($redirect,PHP_URL_QUERY),$r);
$exchange['code']=$r['code'];
$studentTokens=Oidc::exchange($client,$exchange);
check(Oidc::userinfo($studentTokens['access_token'])['role']==='student','student uses same protocol without teacher privileges');
Oidc::revoke($client,$studentTokens['access_token']);
rejects(fn()=>Oidc::userinfo($studentTokens['access_token']),'revocation rejects the student access token');
$redirect=Oidc::authorize($request,Identity::current(),time());
parse_str((string)parse_url($redirect,PHP_URL_QUERY),$r);
$exchange['code']=$r['code'];
$db->prepare('UPDATE platform_oidc_codes SET expires_at=? WHERE code_hash=?')->execute([time()-1,hash('sha256',$r['code'])]);
rejects(fn()=>Oidc::exchange($client,$exchange),'expired authorization code cannot be exchanged');
Schema::migrate($db);
check((int)$db->query('SELECT COUNT(*) FROM platform_learners')->fetchColumn()===1,'repeat schema migration retains existing learner exactly once');

// Build a genuine v4 database and migrate it, preserving legacy ownership/hash.
$legacy=new PDO('sqlite:'.$temp.'/legacy.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$legacy->exec('PRAGMA foreign_keys=ON; CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY,applied_at INTEGER NOT NULL)');
foreach(['versionOne','versionTwo','versionThree','versionFour'] as $method) (new ReflectionMethod(Schema::class,$method))->invoke(null,$legacy);
$legacy->exec("INSERT INTO users(id,username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at) VALUES(41,'legacy','legacy@example.invalid','Legacy','unchanged-hash','teacher','active','unchanged-version',1,1)");
$legacy->exec("INSERT INTO rooms(code,module_slug,owner_user_id,created_at,expires_at,updated_at) VALUES('ABC234','example',41,1,9999999999,1)");
Schema::migrate($legacy);
Schema::migrate($legacy);
check($legacy->query('SELECT password_hash FROM users WHERE id=41')->fetchColumn()==='unchanged-hash','v4 migration preserves existing password hash');
check((int)$legacy->query('SELECT owner_user_id FROM rooms')->fetchColumn()===41,'v4 migration preserves room ownership');
check((int)$legacy->query('SELECT COUNT(*) FROM platform_principals WHERE teacher_user_id=41')->fetchColumn()===1,'v4 teacher receives exactly one permanent subject');
check((int)$legacy->query('SELECT enabled FROM platform_product_grants WHERE product="learning"')->fetchColumn()===1,'v4 teachers keep learning access after migration');
// Shared classes: admin sees the organisation; a colleague sees only assignments.
$context=['identity'=>Identity::teacher((int)$admin['id']),'client'=>$client];
$directory=ReligionPlatform\DirectoryBridge::snapshot($context);
check(count($directory['classes'])===1 && $directory['classes'][0]['role']==='admin','admin sees shared class without a teacher assignment');
check(ReligionPlatform\DirectoryBridge::access($e,1)===[],'unassigned teacher receives no central class access');
rejects(fn()=>ReligionPlatform\DirectoryBridge::snapshot(['identity'=>Identity::current(),'client'=>$client]),'student cannot download other learner rosters');
$input=['confirmed'=>true,'class'=>['external_id'=>'101','label'=>'12B','school_year'=>'2026/27','learners'=>[
    ['external_id'=>'201','name'=>'Imported Student','username'=>'12B01','number'=>1],
]]];
$plan=ReligionPlatform\DirectoryBridge::preview($context,$input);
check(!$plan['conflicts'] && $plan['learners'][0]['action']==='neu anlegen','import preview lists new identities without writing');
check((int)$db->query('SELECT COUNT(*) FROM platform_learners')->fetchColumn()===1,'preview does not create identities');
rejects(fn()=>ReligionPlatform\DirectoryBridge::import(['identity'=>$e,'client'=>$client],$input),'ordinary teacher cannot import or assert account identities');
$import=ReligionPlatform\DirectoryBridge::import($context,$input);
$again=ReligionPlatform\DirectoryBridge::import($context,$input);
check($again['class_id']===$import['class_id'] && $again['learners']===$import['learners'] && !$again['credentials'],'repeated import keeps the same class and learner IDs');
check((int)$db->query('SELECT COUNT(*) FROM platform_learners')->fetchColumn()===2,'repeated import never duplicates learners');
$collision=$input;$collision['class']['external_id']='102';$collision['class']['label']='12C';$collision['class']['learners'][0]['external_id']='202';
check((bool)ReligionPlatform\DirectoryBridge::preview($context,$collision)['conflicts'],'same username requires explicit identity mapping');
rejects(fn()=>ReligionPlatform\DirectoryBridge::import($context,$collision),'username collision cannot silently merge accounts');
check((int)$db->query('SELECT COUNT(*) FROM platform_classes')->fetchColumn()===2,'failed import is atomic');
$collision['class']['learners'][0]['platform_id']=$student['subject'];
$mapped=ReligionPlatform\DirectoryBridge::import($context,$collision);
check($mapped['learners'][0]['subject']===$student['subject'],'explicit admin mapping preserves an existing learner subject');
$db->prepare('UPDATE platform_principals SET status="suspended" WHERE subject=?')->execute([$l['subject']]);
check(count(ReligionPlatform\Portal::accounts($admin))>=5,'admin list remains usable after an account suspension');
// Named work uses a separate, encrypted store and explicit presentation snapshots.
Identity::setProducts($admin,$b['subject'],['learning','assessment']);
$workTeacher=Identity::teacher((int)$both['id']);
$workStudent=Identity::find($student['subject']);
$slug='kr13-1-1-ethische-grundlegung';
ReligionPlatform\Rooms::mirror('ABC234',$slug,$both,['createdAt'=>time(),'expiresAt'=>time()+3600]);
$assignment=ReligionPlatform\LearningWork::createAssignment($both,['class_id'=>$class['id'],'module_slug'=>$slug,'label'=>'Ethik QA','room'=>'ABC234']);
check(count(ReligionPlatform\LearningWork::assignments($workStudent,$slug))===1,'student sees assigned learning work');
$materialDir=$temp.'/modules/'.$slug.'/rooms';mkdir($materialDir,0700,true);
file_put_contents($materialDir.'/room-ABC234.json',json_encode(['expiresAt'=>time()+3600,'materialAccessKey'=>str_repeat('a',48)]));
check(ReligionPlatform\LearningWork::assignments($workStudent,$slug)[0]['material_access_key']===str_repeat('a',48),'authorised assignment provides the existing protected room material ticket');
check(ReligionPlatform\LearningWork::assignments(Identity::teacher((int)$foreign['id']),$slug)===[],'foreign account cannot obtain an assignment material ticket');
rejects(fn()=>ReligionPlatform\LearningWork::assertAssignment(Identity::teacher((int)$foreign['id']),$assignment),'foreign teacher cannot inspect named work');
$inputWork=['assignment_id'=>$assignment,'subject'=>$student['subject'],'base_revision'=>0,'payload'=>['version'=>2,'moduleId'=>'religion13-1-1-ethische-grundlegung','fields'=>['termCardNorm'=>'Normen sind verbindliche Erwartungen an unser Handeln.','verdictAReason'=>'Private unreleased reasoning','unknownField'=>'discard'],'ui'=>['verdictA'=>'schuldig']]];
$saved=ReligionPlatform\LearningWork::save($workStudent,$inputWork);
check($saved['revision']===1,'first named learning work receives revision 1');
$cipher=$db->query('SELECT payload_cipher FROM learning_work')->fetchColumn();
check(!str_contains((string)$cipher,'Normen') && !str_contains((string)base64_decode($cipher),'Private'),'personal learning work is not stored as plaintext');
$read=ReligionPlatform\LearningWork::read($workTeacher,$assignment,$student['subject']);
check($read['payload']['fields']['termCardNorm']===$inputWork['payload']['fields']['termCardNorm'] && !isset($read['payload']['fields']['unknownField']),'authorised teacher sees the original answer, unknown fields are dropped');
check(ReligionPlatform\LearningWork::save($workStudent,$inputWork)['revision']===1,'retry of identical payload does not create duplicate revisions');
$changed=$inputWork;$changed['payload']['fields']['termCardNorm']='Another device changed this answer.';
check(ReligionPlatform\LearningWork::save($workStudent,$changed)['conflict']===true,'stale device receives a conflict instead of overwriting work');
$changed['base_revision']=1;check(ReligionPlatform\LearningWork::save($workStudent,$changed)['revision']===2,'resolved revision can be saved');
check((int)$db->query('SELECT COUNT(*) FROM learning_work_history')->fetchColumn()===1,'previous revision remains recoverable');
$older=ReligionPlatform\LearningWork::read($workStudent,$assignment,null,1);
check($older['revision']===1 && $older['current_revision']===2 && $older['payload']['fields']['termCardNorm']===$inputWork['payload']['fields']['termCardNorm'],'student can inspect a previous own revision without overwriting the current version');
rejects(fn()=>ReligionPlatform\LearningWork::read(Identity::teacher((int)$foreign['id']),$assignment,$student['subject'],1),'version history has the same class boundary as current work');
rejects(fn()=>ReligionPlatform\LearningWork::read($workStudent,$assignment,null,999),'missing history version is rejected');
$broken=$changed;$broken['payload']['fields']='not-a-dictionary';
rejects(fn()=>ReligionPlatform\LearningWork::save($workStudent,$broken),'malformed field container cannot silently erase stored answers');
rejects(fn()=>ReligionPlatform\LearningWork::save($workStudent,array_replace($changed,['subject'=>$import['learners'][0]['subject']])),'switched browser account cannot save another student payload');
ReligionPlatform\LearningWork::publish($workTeacher,['assignment_id'=>$assignment,'subject'=>$student['subject'],'source_revision'=>2,'room'=>'ABC234','kind'=>'field','item_id'=>'termCardNorm']);
$projection=ReligionPlatform\LearningWork::projection('ABC234',$slug);
check($projection['active'] && $projection['selection']['text']===$changed['payload']['fields']['termCardNorm'],'selected answer appears in the room projection');
check(!str_contains(json_encode($projection),'Private unreleased reasoning') && $projection['selection']['name']==='','projection contains neither other notes nor the student name by default');
ReligionPlatform\LearningWork::stopProjection($both,'ABC234');
check(!ReligionPlatform\LearningWork::projection('ABC234',$slug)['active'],'teacher can withdraw the projected solution');
$db->prepare('UPDATE learning_assignments SET status="closed" WHERE id=?')->execute([$assignment]);
rejects(fn()=>ReligionPlatform\LearningWork::save($workStudent,$changed),'closed work cannot be overwritten by students');
check(ReligionPlatform\LearningWork::read($workStudent,$assignment)['revision']===2,'closed work remains readable until retention ends');
check(!in_array((int)$learning['id'],array_column(SchoolDirectory::teacherCandidates($admin,$class['id']),'id'),true),'suspended teachers do not break or appear in the assignment selector');
SchoolDirectory::setTeacher($admin,$class['id'],(int)$learning['id'],null);
check(!in_array((int)$learning['id'],array_column(SchoolDirectory::teachers($admin,$class['id']),'id'),true),'admin can remove the assignment of a suspended teacher');
SchoolDirectory::updateClass($both,$class['id'],'13 Religion renamed','2026/27','archived');
rejects(fn()=>SchoolDirectory::createLearner($both,$class['id'],'No new person','13R02',2,['learning']),'archived class cannot create learners');
SchoolDirectory::updateClass($both,$class['id'],'13 Religion','2026/27','active');
SchoolDirectory::setLearnerMembership($admin,$class['id'],$student['subject'],1,false);
rejects(fn()=>ReligionPlatform\LearningWork::read($workStudent,$assignment),'removed class membership ends the student assignment access');
check(ReligionPlatform\LearningWork::read($workTeacher,$assignment,$student['subject'])['revision']===2,'removing a class membership preserves the previous work for its teachers');
SchoolDirectory::setLearnerMembership($admin,$class['id'],$student['subject'],1,true);
check(ReligionPlatform\LearningWork::read($workStudent,$assignment)['revision']===2,'reactivation retains the same identity and work');
Auth::login('assessment','A long test password 2026');
check(Auth::currentUser()===null && Auth::currentUser(null)!==null && Identity::current()['role']==='teacher','assessment-only login cannot enter legacy lesson adapters but stays signed into shared portal');
echo 'All '.$checks.' shared-platform checks passed.' . "\n";
if (in_array('--json-contract',$argv,true)) {
    echo 'OIDC_CONTRACT=' . json_encode(['tokens'=>$tokens,'jwks'=>$jwks,'nonce'=>$request['nonce']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES) . "\n";
}
ob_end_flush();
