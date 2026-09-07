<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__).'/public');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$lessonPrefix='/bereiche/religion-13/ethische-grundlegung/';
if($path==='/qa/teaching/'){require __DIR__.'/teaching-entry.html';return true;}
$qaAssets=['classroom-core.js'=>'classroom-v1/classroom-core.js','classroom.css'=>'classroom-v1/classroom.css','work-renderer.js'=>'learning-sync-v1/work-renderer.js','learning-sync.js'=>'learning-sync-v1/learning-sync.js','learning-sync.css'=>'learning-sync-v1/learning-sync.css','teaching-entry.js'=>'learning-sync-v1/teaching-entry.js'];
$qaAssets['qrcode-generator.js']='classroom-v1/qrcode-generator.js';
if(str_starts_with($path,'/qa/assets/')&&isset($qaAssets[basename($path)])){header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'text/javascript').'; charset=utf-8');readfile(dirname(__DIR__,2).'/'.$qaAssets[basename($path)]);return true;}
if(str_starts_with($path,$lessonPrefix)) {
    $lessonRoot=realpath(dirname(__DIR__,4).'/13/13.1.1 Ethische Grundlegung/dist');
    $relative=substr($path,strlen($lessonPrefix));
    if($relative==='lehrer/')$relative='lehrer/index.php';
    if($relative==='')$relative='index.html';
    $file=$lessonRoot?realpath($lessonRoot.'/'.$relative):false;
    if(!$file || !str_starts_with($file,$lessonRoot.DIRECTORY_SEPARATOR) || str_starts_with($relative,'server/')){http_response_code(404);return true;}
    if(in_array($relative,['lehrer/index.php','api/live.php'],true)){require ($relative==='api/live.php'&&getenv('TEACHER_PLATFORM_QA_LIVE_API')?getenv('TEACHER_PLATFORM_QA_LIVE_API'):$file);return true;}
    $type=['html'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp','json'=>'application/json'][pathinfo($file,PATHINFO_EXTENSION)] ?? '';
    if(!$type){http_response_code(404);return true;}header('Content-Type: '.$type);readfile($file);return true;
}
$routes=['/zugang/'=>'/zugang/index.php','/zugang/oidc.php'=>'/zugang/oidc.php','/zugang/.well-known/openid-configuration'=>'/zugang/oidc.php','/lehrer/'=>'/lehrer/index.php'];
$routes['/zugang/directory.php']='/zugang/directory.php';
$routes['/zugang/work.php']='/zugang/work.php';
$routes['/zugang/arbeiten/']='/zugang/arbeiten/index.php';
if(isset($routes[$path])) {
    if(str_ends_with($path,'openid-configuration')) $_GET['endpoint']='discovery';
    require $root.$routes[$path];
    return true;
}
$file=realpath($root.$path);
if($file && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && is_file($file) && in_array(pathinfo($file,PATHINFO_EXTENSION),['css','js','svg','png','jpg','woff2'],true)) return false;
http_response_code(404); echo 'Nicht gefunden.';
