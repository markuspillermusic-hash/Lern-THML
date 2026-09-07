<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require '/websites/_protected/teacher-platform-v1/app/bootstrap.php';
umask(0077);
$path='/var/lib/teacher-platform/pruefungsapp-connection.json';
if(file_exists($path))throw new RuntimeException('Connection file already exists; do not rotate implicitly.');
$db=ReligionPlatform\Database::connection();
$actor=$db->query('SELECT * FROM users WHERE id=1 AND username="mpiller" AND role="admin" AND status="active"')->fetch();
if(!$actor)throw new RuntimeException('Expected administrator not found.');
$org=$db->query('SELECT id FROM organisations WHERE id=1 AND kind="school" AND status="active"')->fetchColumn();
if(!$org)throw new RuntimeException('Expected school organisation not found.');
ReligionPlatform\Oidc::provisionSigningKey($actor);
$client=ReligionPlatform\Oidc::registerClient($actor,['client_id'=>'pruefungsapp-jmf','organisation_id'=>1,'product'=>'assessment','label'=>'Prüfungsapp · JMF-Gymnasium','base_url'=>'https://pruefungsapp.markuspiller.de','redirect_uris'=>['https://pruefungsapp.markuspiller.de/api/platform/callback']]);
$config=['issuer'=>'https://markuspiller.de/zugang','client_id'=>$client['client_id'],'client_secret'=>$client['client_secret'],'redirect_uri'=>'https://pruefungsapp.markuspiller.de/api/platform/callback','organisation_id'=>'1'];
if(file_put_contents($path,json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('Protected connection file could not be saved.');
chmod($path,0600);
echo "School installation registered; connection file written outside the webroot.\n";
