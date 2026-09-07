<?php
declare(strict_types=1);

require_once getenv('TEACHER_PLATFORM_BOOTSTRAP') ?: '/websites/_protected/teacher-platform-v1/app/bootstrap.php';

use ReligionPlatform\Identity;
use ReligionPlatform\Oidc;
use ReligionPlatform\Security;

Security::headers("default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
header('Content-Type: application/json; charset=utf-8');
$endpoint = (string)($_GET['endpoint'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if (in_array($endpoint,['discovery','jwks','authorize','userinfo'],true) && $method !== 'GET') throw new RuntimeException('invalid_request');
    if (in_array($endpoint,['token','revoke'],true) && $method !== 'POST') throw new RuntimeException('invalid_request');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>8192) throw new RuntimeException('invalid_request');
    if ($endpoint === 'discovery') $result = Oidc::discovery();
    elseif ($endpoint === 'jwks') $result = Oidc::jwks();
    elseif ($endpoint === 'authorize') {
        Oidc::validateAuthorization($_GET);
        $identity = Identity::current();
        if (!$identity || !empty($identity['must_change_password'])) {
            header('Location: /zugang/?next=' . rawurlencode((string)$_SERVER['REQUEST_URI']));
            exit;
        }
        header('Location: ' . Oidc::authorize($_GET,$identity,(int)($_SESSION['platform_started_at'] ?? time())));
        exit;
    } elseif ($endpoint === 'token' || $endpoint === 'revoke') {
        if (!Security::rateLimit('oidc-token',Security::clientIpHash('oidc-token'),60,60)) throw new RuntimeException('temporarily_unavailable');
        $client = Oidc::authenticateClient(rawurldecode((string)($_SERVER['PHP_AUTH_USER'] ?? '')),rawurldecode((string)($_SERVER['PHP_AUTH_PW'] ?? '')));
        if ($endpoint === 'revoke') { Oidc::revoke($client,(string)($_POST['token'] ?? '')); $result = new stdClass(); }
        else $result = Oidc::exchange($client,$_POST);
    } elseif ($endpoint === 'userinfo') {
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D',$authorization,$match)) throw new RuntimeException('invalid_token');
        $result = Oidc::userinfo($match[1]);
    } else { http_response_code(404); $result = ['error'=>'invalid_request']; }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $known = ['invalid_request','invalid_client','invalid_redirect_uri','invalid_scope','access_denied','unsupported_grant_type','invalid_grant','invalid_token','server_not_configured','temporarily_unavailable'];
    $code = in_array($error->getMessage(),$known,true) ? $error->getMessage() : 'server_error';
    http_response_code(in_array($code,['invalid_client','invalid_token'],true) ? 401 : (in_array($code,['server_error','server_not_configured','temporarily_unavailable'],true) ? 503 : 400));
    echo json_encode(['error'=>$code]);
}
