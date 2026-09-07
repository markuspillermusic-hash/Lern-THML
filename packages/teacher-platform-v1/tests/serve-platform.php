<?php
declare(strict_types=1);
// Disposable localhost fixture. Never reads or migrates production data.
if(PHP_SAPI!=='cli') exit(1);
$temp=sys_get_temp_dir().'/platform-browser-'.bin2hex(random_bytes(8));
mkdir($temp,0700,true);
file_put_contents($temp.'/master.key',base64_encode(random_bytes(32)));
file_put_contents($temp.'/config.php','<?php return '.var_export([
    'database'=>$temp.'/test.sqlite','data_dir'=>$temp,'master_key_file'=>$temp.'/master.key',
    'session_name'=>'education_local_qa','base_url'=>'http://127.0.0.1:8765',
    'development_loopback_http'=>true,'mail_mode'=>'disabled',
],true).';');
putenv('TEACHER_PLATFORM_CONFIG='.$temp.'/config.php');
putenv('TEACHER_PLATFORM_BOOTSTRAP='.dirname(__DIR__).'/app/bootstrap.php');
putenv('TEACHER_PLATFORM_ROOM_DATA='.$temp.'/modules/kr13-1-1-ethische-grundlegung/rooms');
$lessonApi=dirname(__DIR__,4).'/13/13.1.1 Ethische Grundlegung/dist/api/live.php';
$source=file_get_contents($lessonApi);
$marker="if (\$action === 'create') {";
if(substr_count($source,$marker)!==1)throw new RuntimeException('QA hook marker is not unique');
$hook=var_export(dirname(__DIR__,2).'/learning-sync-v1/live-teaching-hook.php',true);
file_put_contents($temp.'/live.php',str_replace($marker,'require '.$hook.";\n".$marker,$source));
putenv('TEACHER_PLATFORM_QA_LIVE_API='.$temp.'/live.php');
require dirname(__DIR__).'/app/bootstrap.php';
use ReligionPlatform\Database;
use ReligionPlatform\Identity;
use ReligionPlatform\Oidc;
use ReligionPlatform\SchoolDirectory;
$db=Database::connection();
$db->exec("INSERT INTO organisations(id,name,slug,kind,status,created_at,updated_at) VALUES(1,'QA Schule','qa','school','active',1,1)");
$password='Development password 2026';
foreach(['admin'=>'admin','lehrkraft'=>'teacher','nur-lernhtml'=>'teacher','nur-pruefung'=>'teacher'] as $username=>$role) {
    $db->prepare('INSERT INTO users(username,email,display_name,password_hash,role,status,auth_version,created_at,updated_at) VALUES(?,?,?,?,?,"active",?,1,1)')->execute([$username,$username.'@example.invalid',$username,password_hash($password,PASSWORD_ARGON2ID),$role,bin2hex(random_bytes(16))]);
    $id=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO organisation_memberships(user_id,organisation_id,membership_role,created_at) VALUES(?,1,"teacher",1)')->execute([$id]);
    Identity::teacher($id);
}
$admin=$db->query('SELECT * FROM users WHERE username="admin"')->fetch();
foreach(['lehrkraft'=>['learning','assessment'],'nur-pruefung'=>['assessment']] as $username=>$products) {
    $id=(int)$db->query('SELECT id FROM users WHERE username="'.$username.'"')->fetchColumn();
    Identity::setProducts($admin,Identity::teacher($id)['subject'],$products);
}
$teacher=$db->query('SELECT * FROM users WHERE username="lehrkraft"')->fetch();
$class=SchoolDirectory::createClass($teacher,1,'13 Religion','2026/27');
$learner=SchoolDirectory::createLearner($teacher,$class['id'],'QA Schüler','13R01',1,['learning','assessment']);
$db->prepare('UPDATE platform_learners SET password_hash=?,must_change_password=0 WHERE subject=?')->execute([password_hash($password,PASSWORD_ARGON2ID),$learner['subject']]);
Oidc::provisionSigningKey($admin);
Oidc::registerClient($admin,['client_id'=>'qa-exam','label'=>'Prüfungsapp Test','organisation_id'=>1,'product'=>'assessment','base_url'=>'http://127.0.0.1:8766','redirect_uris'=>['http://127.0.0.1:8766/api/platform/callback']]);
$db->prepare('UPDATE platform_clients SET secret_hash=? WHERE client_id="qa-exam"')->execute([hash('sha256','local-only-test-credential-00000000000000000000')]);
echo "Local test platform: http://127.0.0.1:8765/zugang/\nOnly synthetic QA accounts are loaded.\n";
$process=proc_open([PHP_BINARY,'-S','127.0.0.1:8765','-t',dirname(__DIR__).'/public',__DIR__.'/http-router.php'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);
exit(is_resource($process)?proc_close($process):1);
