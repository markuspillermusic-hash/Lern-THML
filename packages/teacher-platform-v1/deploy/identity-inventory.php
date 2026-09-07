<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$db=new PDO('sqlite:/var/lib/teacher-platform/platform.sqlite',null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
echo json_encode(['organisations'=>$db->query('SELECT id,name,slug,kind,status FROM organisations')->fetchAll(),'administrators'=>$db->query('SELECT id,username FROM users WHERE role="admin" AND status="active"')->fetchAll(),'clients'=>$db->query('SELECT client_id,organisation_id,base_url,status FROM platform_clients')->fetchAll()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
