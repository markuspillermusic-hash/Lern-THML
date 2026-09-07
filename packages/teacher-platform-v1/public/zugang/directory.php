<?php
declare(strict_types=1);
require_once getenv('TEACHER_PLATFORM_BOOTSTRAP') ?: '/websites/_protected/teacher-platform-v1/app/bootstrap.php';
use ReligionPlatform\DirectoryBridge;
use ReligionPlatform\Oidc;
use ReligionPlatform\Security;
Security::headers("default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
header('Content-Type: application/json; charset=utf-8');
try {
    if(!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D',(string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''),$match)) throw new RuntimeException('invalid_token');
    $context=Oidc::tokenContext($match[1]);
    if(!Security::rateLimit('directory',$context['identity']['subject'],60,60)) throw new RuntimeException('rate_limited');
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    if($method==='GET') $result=DirectoryBridge::snapshot($context);
    elseif($method==='POST') {
        $raw=file_get_contents('php://input',false,null,0,131073);
        if(strlen($raw)>131072) throw new InvalidArgumentException('Anfrage zu groß.');
        $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data)) throw new InvalidArgumentException('Ungültige Anfrage.');
        $result=match($data['action'] ?? '') {'preview'=>DirectoryBridge::preview($context,$data),'import'=>DirectoryBridge::import($context,$data),default=>throw new InvalidArgumentException('Unbekannte Aktion.')};
    } else {http_response_code(405);$result=['error'=>'method_not_allowed'];}
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
} catch(Throwable $error) {
    $message=$error->getMessage();
    $denied=in_array($message,['invalid_token','invalid_client','access_denied'],true);
    http_response_code($denied?403:($message==='rate_limited'?429:400));
    echo json_encode(['error'=>$error instanceof PDOException?'Die Zuordnung steht im Konflikt mit einem vorhandenen Eintrag.':($denied?'Zugriff nicht freigegeben.':$message)],JSON_UNESCAPED_UNICODE);
}
