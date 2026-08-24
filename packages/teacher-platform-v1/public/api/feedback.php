<?php
declare(strict_types=1);

$bootstrap = getenv('TEACHER_PLATFORM_BOOTSTRAP') ?: '/srv/teacher-platform-v1/app/bootstrap.php';
require_once $bootstrap;

use ReligionPlatform\FeedbackGateway;
use ReligionPlatform\Security;

Security::headers("default-src 'none'; frame-ancestors 'none'");
header('Content-Type: application/json; charset=utf-8');

function feedback_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    feedback_response(405, ['ok' => false, 'message' => 'Methode nicht erlaubt.']);
}
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    feedback_response(415, ['ok' => false, 'message' => 'Content-Type application/json ist erforderlich.']);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 18000) {
    feedback_response(413, ['ok' => false, 'message' => 'Die Anfrage ist zu groß.']);
}

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
if ($origin !== '') {
    $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
    if ($originHost === '' || !hash_equals($host, $originHost)) {
        feedback_response(403, ['ok' => false, 'message' => 'Anfragequelle nicht erlaubt.']);
    }
}

$endpointSubject = Security::clientIpHash('feedback-endpoint');
if (!Security::rateLimit('feedback-endpoint', $endpointSubject, 60, 180)) {
    header('Retry-After: 60');
    feedback_response(429, ['ok' => false, 'message' => 'Zu viele Anfragen. Bitte kurz warten.']);
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) feedback_response(400, ['ok' => false, 'message' => 'Ungültige Anfrage.']);

try {
    $action = Security::clean($payload['action'] ?? '', 30);
    $room = Security::clean($payload['room'] ?? '', 6);
    $task = Security::clean($payload['taskId'] ?? '', 100);
    if ($action === 'availability') {
        feedback_response(200, ['ok' => true] + FeedbackGateway::availability($room, $task));
    }
    if ($action === 'generate') {
        feedback_response(200, ['ok' => true] + FeedbackGateway::generate(
            $room,
            $task,
            (string)($payload['answer'] ?? ''),
            (string)($payload['clientId'] ?? '')
        ));
    }
    feedback_response(400, ['ok' => false, 'message' => 'Unbekannte Aktion.']);
} catch (RuntimeException $error) {
    error_log('teacher-feedback: ' . $error->getMessage());
    feedback_response(422, ['ok' => false, 'message' => Security::clean($error->getMessage(), 260)]);
} catch (Throwable $error) {
    error_log('teacher-feedback internal: ' . $error->getMessage());
    feedback_response(500, ['ok' => false, 'message' => 'Die Rückmeldung konnte gerade nicht verarbeitet werden.']);
}
