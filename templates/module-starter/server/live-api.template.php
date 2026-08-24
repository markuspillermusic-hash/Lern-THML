<?php
declare(strict_types=1);

const ROOM_TTL = 3628800;
const ROOM_MIN_TTL = 3600;
const ROOM_MAX_TTL = 31622400; // 365 Kalendertage plus Spielraum bis zum gewählten Tagesende.
const MAX_HISTORY = 20;
const MAX_CARD_WALL_CARDS = 120;
const SESSION_NAME = __SESSION_NAME__;
const DATA_DIRECTORY = __DATA_DIRECTORY__;


const PLATFORM_MODULE_SLUG = __MODULE_SLUG__;
$platformTeacherUser = null;
$platformBootstrap = __PLATFORM_BOOTSTRAP__;
if (is_file($platformBootstrap)) {
    require_once $platformBootstrap;
    $platformSessionName = (string)\ReligionPlatform\Config::get('session_name', 'religion_teacher_platform');
    if (isset($_COOKIE[$platformSessionName])) {
        $platformTeacherUser = \ReligionPlatform\Auth::currentUser();
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }
}

session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => __COOKIE_PATH__,
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
if (isset($_COOKIE[SESSION_NAME])) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_text(mixed $value, int $max): string {
    if (!is_string($value)) return '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function room_code(mixed $value): string {
    $value = strtoupper(clean_text($value, 6));
    return preg_match('/^[A-Z2-9]{6}$/', $value) ? $value : '';
}

function template_id(mixed $value): string {
    $value = clean_text($value, 80);
    return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $value) ? $value : '';
}

function requested_expiry(mixed $value, ?int $fallback = null): int {
    $now = time();
    $fallback ??= $now + ROOM_TTL;
    if ($value === null || $value === '') return $fallback;
    $expiry = filter_var($value, FILTER_VALIDATE_INT);
    if ($expiry === false || $expiry < $now + ROOM_MIN_TTL || $expiry > $now + ROOM_MAX_TTL) {
        respond(422, ['ok' => false, 'message' => 'Das Ablaufdatum muss zwischen einer Stunde und 365 Tagen in der Zukunft liegen.']);
    }
    return (int)$expiry;
}

function card_wall_id(mixed $value): string {
    return template_id($value);
}

function public_card_walls(array $state, bool $teacher): array {
    $result = [];
    $walls = is_array($state['cardWalls'] ?? null) ? $state['cardWalls'] : [];
    foreach ($walls as $wallId => $wall) {
        $id = card_wall_id($wallId);
        if ($id === '' || !is_array($wall)) continue;
        $cards = [];
        foreach (is_array($wall['cards'] ?? null) ? $wall['cards'] : [] as $card) {
            if (!is_array($card)) continue;
            $status = ($card['status'] ?? '') === 'approved' ? 'approved' : 'pending';
            if (!$teacher && $status !== 'approved') continue;
            $cardId = preg_replace('/[^a-f0-9]/', '', clean_text($card['id'] ?? '', 24)) ?? '';
            $category = template_id($card['category'] ?? '');
            $text = clean_text($card['text'] ?? '', 240);
            if ($cardId === '' || $category === '' || $text === '') continue;
            $cards[] = [
                'id' => $cardId,
                'category' => $category,
                'text' => $text,
                'status' => $status,
                'createdAt' => max(0, (int)($card['createdAt'] ?? 0)),
            ];
        }
        $result[$id] = ['open' => !empty($wall['open']), 'cards' => $cards];
    }
    return $result;
}

function data_dir(): string {
    $dir = rtrim(DATA_DIRECTORY, DIRECTORY_SEPARATOR);
    if ($dir === '' || $dir === DIRECTORY_SEPARATOR) {
        respond(500, ['ok' => false, 'message' => 'Das Abstimmungsverzeichnis ist nicht konfiguriert.']);
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        respond(500, ['ok' => false, 'message' => 'Der Abstimmungsraum konnte nicht angelegt werden.']);
    }
    return $dir;
}

function room_path(string $code): string {
    return data_dir() . DIRECTORY_SEPARATOR . 'room-' . $code . '.json';
}

function cleanup_rooms(): void {
    $now = time();
    foreach (glob(data_dir() . DIRECTORY_SEPARATOR . 'room-*.json') ?: [] as $path) {
        $raw = @file_get_contents($path);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || (int)($state['expiresAt'] ?? 0) < $now) @unlink($path);
    }
}

function read_room(string $code): array {
    $path = room_path($code);
    $handle = @fopen($path, 'rb');
    if ($handle === false) respond(404, ['ok' => false, 'message' => 'Dieser Abstimmungsraum wurde nicht gefunden.']);
    flock($handle, LOCK_SH);
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    $state = json_decode((string)$raw, true);
    if (!is_array($state) || (int)($state['expiresAt'] ?? 0) < time()) {
        @unlink($path);
        respond(404, ['ok' => false, 'message' => 'Dieser Abstimmungsraum ist abgelaufen.']);
    }
    return $state;
}

function update_room(string $code, callable $change): array {
    $path = room_path($code);
    $handle = @fopen($path, 'c+');
    if ($handle === false) respond(404, ['ok' => false, 'message' => 'Dieser Abstimmungsraum wurde nicht gefunden.']);
    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = stream_get_contents($handle);
    $state = json_decode((string)$raw, true);
    if (!is_array($state) || (int)($state['expiresAt'] ?? 0) < time()) {
        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink($path);
        respond(404, ['ok' => false, 'message' => 'Dieser Abstimmungsraum ist abgelaufen.']);
    }
    $state = $change($state);
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $state;
}

function timed_state(array $state): array {
    $poll = $state['poll'] ?? null;
    if (
        is_array($poll)
        && ($poll['type'] ?? '') === 'quiz'
        && empty($poll['closed'])
        && (int)($poll['deadline'] ?? 0) > 0
        && (int)$poll['deadline'] <= time()
    ) {
        $state['poll']['closed'] = true;
        $state['poll']['closedAt'] = (int)$poll['deadline'];
        if (is_array($state['timer'] ?? null)) {
            $state['timer']['running'] = false;
            $state['timer']['remaining'] = 0;
            $state['timer']['endsAt'] = null;
        }
    }
    return $state;
}

function public_state(array $state, bool $teacher): array {
    $state = timed_state($state);
    $poll = $state['poll'] ?? null;
    if (is_array($poll) && !$teacher && !($poll['reveal'] ?? false)) {
        unset($poll['counts']);
        if (($poll['type'] ?? '') === 'quiz' && is_array($poll['questions'] ?? null)) {
            foreach ($poll['questions'] as &$question) {
                unset($question['counts'], $question['correctIndex'], $question['insight']);
            }
            unset($question);
        }
    }
    $result = [
        'ok' => true,
        'room' => $state['code'],
        'label' => clean_text($state['label'] ?? '', 60),
        'expiresAt' => $state['expiresAt'],
        'poll' => $poll,
        'timer' => is_array($state['timer'] ?? null) ? $state['timer'] : null,
        'releasedStage' => current_release_stage($state),
        'presentation' => presentation_command($state['presentation'] ?? null),
        'beamerHeartbeatAt' => max(0, (int)($state['beamerHeartbeatAt'] ?? 0)),
        'cardWalls' => public_card_walls($state, $teacher),
    ];
    $result['aiFeedbackEnabled'] = !empty($state['aiFeedbackEnabled']);
    if ($teacher) {
        $result['history'] = is_array($state['history'] ?? null) ? $state['history'] : [];
        $result['aiFeedbackAvailable'] = platform_feedback_available($state);
    }
    return $result;
}

function archive_current_poll(array $state): array {
    $poll = $state['poll'] ?? null;
    if (!is_array($poll)) return $state;
    $history = is_array($state['history'] ?? null) ? $state['history'] : [];
    foreach ($history as $saved) {
        if (($saved['id'] ?? '') === ($poll['id'] ?? '')) return $state;
    }
    $poll['closed'] = true;
    $poll['closedAt'] = (int)($poll['closedAt'] ?? time());
    $history[] = $poll;
    if (count($history) > MAX_HISTORY) $history = array_slice($history, -MAX_HISTORY);
    $state['history'] = $history;
    return $state;
}

function teacher_authenticated(): bool {
    return platform_teacher() !== null;
}

function require_teacher(): void {
    if (!teacher_authenticated()) {
        respond(403, ['ok' => false, 'message' => 'Bitte zuerst die Lehreransicht anmelden.']);
    }
}

function create_room(string $label, int $expiresAt): array {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $state = [
            'code' => $code,
            'label' => $label,
            'createdAt' => time(),
            'expiresAt' => $expiresAt,
            'poll' => null,
            'timer' => null,
            'releasedStage' => release_stages()[0],
            'presentation' => null,
            'beamerHeartbeatAt' => 0,
            'ownerUserId' => (int)(platform_teacher()['id'] ?? 0),
            'aiFeedbackEnabled' => false,
            'history' => [],
            'cardWalls' => [],
        ];
        $handle = @fopen(room_path($code), 'x');
        if ($handle === false) continue;
        $ok = fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fclose($handle);
        if ($ok === false) respond(500, ['ok' => false, 'message' => 'Der Abstimmungsraum konnte nicht gespeichert werden.']);
        return $state;
    }
    respond(503, ['ok' => false, 'message' => 'Es konnte kein freier Raumcode erzeugt werden.']);
}


function platform_teacher(): ?array {
    $user = $GLOBALS['platformTeacherUser'] ?? null;
    return is_array($user) ? $user : null;
}

function platform_is_admin(?array $user): bool {
    return is_array($user) && ($user['role'] ?? '') === 'admin';
}

function platform_state_owned_by(array $state, array $user): bool {
    $ownerId = (int)($state['ownerUserId'] ?? 0);
    if (platform_is_admin($user)) return true;
    return $ownerId > 0 && $ownerId === (int)($user['id'] ?? 0);
}

function platform_require_room_owner(string $code): void {
    $user = platform_teacher();
    if ($user === null) respond(403, ['ok' => false, 'message' => 'Persönliche Lehreranmeldung erforderlich.']);
    $state = read_room($code);
    if (!platform_state_owned_by($state, $user)) {
        respond(403, ['ok' => false, 'message' => 'Dieser Klassenraum gehört zu einer anderen Lehrkraft.']);
    }
}

function platform_teacher_status(string $code): array {
    $state = read_room($code);
    $user = platform_teacher();
    if ($user === null) return $state;
    $ownerId = (int)($state['ownerUserId'] ?? 0);
    $userId = (int)($user['id'] ?? 0);
    if ($ownerId === 0) {
        $state = update_room($code, function(array $current) use ($userId): array {
            $current['ownerUserId'] = $userId;
            return $current;
        });
        $ownerId = $userId;
    }
    if ($ownerId !== $userId) {
        if (!platform_is_admin($user)) {
            respond(403, ['ok' => false, 'message' => 'Dieser Klassenraum gehört zu einer anderen Lehrkraft.']);
        }
        return $state;
    }
    platform_mirror_room($state);
    return $state;
}

function platform_mirror_room(array $state): void {
    $user = platform_teacher();
    if ($user === null || !class_exists('ReligionPlatform\\Rooms')) return;
    try {
        \ReligionPlatform\Rooms::mirror((string)$state['code'], PLATFORM_MODULE_SLUG, $user, $state);
    } catch (Throwable $error) {
        error_log('teacher-platform room mirror: ' . $error->getMessage());
        respond(503, ['ok' => false, 'message' => 'Der zentrale Klassenraum konnte nicht registriert werden.']);
    }
}

function platform_update_room(array $state): void {
    if (!class_exists('ReligionPlatform\\Rooms')) return;
    try { \ReligionPlatform\Rooms::updateMirror((string)$state['code'], $state); }
    catch (Throwable $error) { error_log('teacher-platform room update: ' . $error->getMessage()); }
}

function platform_end_room(string $code): void {
    $user = platform_teacher();
    if ($user === null || !class_exists('ReligionPlatform\\Rooms')) return;
    try { \ReligionPlatform\Rooms::end($code, $user); }
    catch (Throwable $error) { error_log('teacher-platform room end: ' . $error->getMessage()); }
}

function platform_feedback_available(array $state): bool {
    $user = platform_teacher();
    if ($user === null || !class_exists('ReligionPlatform\\Vault')) return false;
    return \ReligionPlatform\Vault::resolveOpenAiKey($user, \ReligionPlatform\Auth::defaultOrganisationId((int)$user['id'])) !== null;
}

function presentation_command(mixed $value): ?array {
    if (!is_array($value)) return null;
    $type = clean_text($value['type'] ?? '', 40);
    $moduleId = clean_text($value['moduleId'] ?? '', 80);
    $room = room_code($value['room'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/i', $type) || $moduleId === '') return null;
    $payload = is_array($value['payload'] ?? null) ? $value['payload'] : [];
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || strlen($encoded) > 6000) return null;
    $cleanPayload = json_decode($encoded, true);
    if (!is_array($cleanPayload)) $cleanPayload = [];
    return [
        'moduleId' => $moduleId,
        'room' => $room,
        'type' => $type,
        'payload' => $cleanPayload,
        'sentAt' => max(0, (int)($value['sentAt'] ?? 0)),
        'nonce' => preg_replace('/[^a-zA-Z0-9_-]/', '', clean_text($value['nonce'] ?? '', 24)) ?? '',
    ];
}

function release_stages(): array {
    return [
        __RELEASE_STAGES__
    ];
}

function valid_release_stage(mixed $value): string {
    $stage = clean_text($value, 32);
    return in_array($stage, release_stages(), true) ? $stage : '';
}

function current_release_stage(array $state): string {
    $stage = valid_release_stage($state['releasedStage'] ?? '');
    return $stage !== '' ? $stage : release_stages()[0];
}

function release_rank(string $stage): int {
    $rank = array_search($stage, release_stages(), true);
    return $rank === false ? 0 : (int)$rank;
}

function list_active_rooms(): array {
    $rooms = [];
    $platformUser = platform_teacher();
    foreach (glob(data_dir() . DIRECTORY_SEPARATOR . 'room-*.json') ?: [] as $path) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) continue;
        flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        $state = json_decode((string)$raw, true);
        if (!is_array($state)) continue;
        if ((int)($state['expiresAt'] ?? 0) < time()) {
            @unlink($path);
            continue;
        }
        $code = room_code($state['code'] ?? '');
        if ($code === '') continue;
        if ($platformUser !== null && !platform_state_owned_by($state, $platformUser)) continue;
        $poll = is_array($state['poll'] ?? null) ? $state['poll'] : null;
        $rooms[] = [
            'code' => $code,
            'label' => clean_text($state['label'] ?? '', 60),
            'createdAt' => (int)($state['createdAt'] ?? 0),
            'expiresAt' => (int)($state['expiresAt'] ?? 0),
            'pollQuestion' => $poll ? clean_text($poll['question'] ?? '', 180) : '',
            'pollClosed' => $poll ? !empty($poll['closed']) : false,
            'aiFeedbackEnabled' => !empty($state['aiFeedbackEnabled']),
        ];
    }
    usort($rooms, static fn(array $a, array $b): int => ($b['createdAt'] ?? 0) <=> ($a['createdAt'] ?? 0));
    return $rooms;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'message' => 'Methode nicht erlaubt.']);
}
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    respond(415, ['ok' => false, 'message' => 'Content-Type application/json ist erforderlich.']);
}
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 30000) respond(413, ['ok' => false, 'message' => 'Anfrage ist zu groß.']);
$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) respond(400, ['ok' => false, 'message' => 'Ungültige Anfrage.']);
$action = clean_text($payload['action'] ?? '', 30);
$isTeacher = teacher_authenticated();
cleanup_rooms();

if ($action === 'create') {
    require_teacher();
    $expiresAt = requested_expiry($payload['expiresAt'] ?? null);
    $createdRoom = create_room(clean_text($payload['label'] ?? '', 60), $expiresAt);
    platform_mirror_room($createdRoom);
    respond(201, public_state($createdRoom, true));
}


if ($action === 'list_rooms') {
    require_teacher();
    respond(200, ['ok' => true, 'rooms' => list_active_rooms()]);
}

$code = room_code($payload['room'] ?? '');
if ($code === '') respond(422, ['ok' => false, 'message' => 'Der Abstimmungscode ist ungültig.']);

if ($action === 'status') {
    $state = $isTeacher ? platform_teacher_status($code) : read_room($code);
    respond(200, public_state($state, $isTeacher));
}

if ($action === 'submit_card') {
    $wallId = card_wall_id($payload['wallId'] ?? '');
    $category = template_id($payload['category'] ?? '');
    $text = clean_text($payload['text'] ?? '', 240);
    if ($wallId === '' || $category === '' || strlen($text) < 3) {
        respond(422, ['ok' => false, 'message' => 'Bitte Kategorie und einen kurzen, sachlichen Beitrag eingeben.']);
    }
    $state = update_room($code, function(array $state) use ($wallId, $category, $text): array {
        $walls = is_array($state['cardWalls'] ?? null) ? $state['cardWalls'] : [];
        $wall = is_array($walls[$wallId] ?? null) ? $walls[$wallId] : ['open' => false, 'cards' => []];
        if (empty($wall['open'])) respond(409, ['ok' => false, 'message' => 'Die gemeinsame Kartenwand ist noch nicht geöffnet.']);
        $allowedCategories = array_values(array_filter(
            is_array($wall['categories'] ?? null) ? $wall['categories'] : [],
            static fn(mixed $value): bool => template_id($value) !== ''
        ));
        if (!in_array($category, $allowedCategories, true)) respond(422, ['ok' => false, 'message' => 'Diese Kartenkategorie ist für die Wand nicht freigegeben.']);
        $cards = is_array($wall['cards'] ?? null) ? $wall['cards'] : [];
        if (count($cards) >= MAX_CARD_WALL_CARDS) respond(409, ['ok' => false, 'message' => 'Die Kartenwand ist voll. Bitte die Lehrkraft um eine Zwischensicherung.']);
        $cards[] = [
            'id' => bin2hex(random_bytes(8)),
            'category' => $category,
            'text' => $text,
            'status' => 'pending',
            'createdAt' => time(),
        ];
        $wall['cards'] = $cards;
        $walls[$wallId] = $wall;
        $state['cardWalls'] = $walls;
        return $state;
    });
    $response = public_state($state, false);
    $response['accepted'] = true;
    respond(201, $response);
}

if ($action === 'vote') {
    $pollId = clean_text($payload['pollId'] ?? '', 32);
    $option = filter_var($payload['option'] ?? null, FILTER_VALIDATE_INT);
    $previousOption = filter_var($payload['previousOption'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
    $state = update_room($code, function(array $state) use ($pollId, $option, $previousOption): array {
        $poll = $state['poll'] ?? null;
        if (!is_array($poll) || ($poll['id'] ?? '') !== $pollId || !empty($poll['closed'])) {
            respond(409, ['ok' => false, 'message' => 'Diese Abstimmung ist nicht mehr aktiv.']);
        }
        $count = count($poll['options'] ?? []);
        if ($option === false || $option < 0 || $option >= $count) {
            respond(422, ['ok' => false, 'message' => 'Antwort ist ungültig.']);
        }
        if ($previousOption !== null && $previousOption >= 0 && $previousOption < $count && $previousOption !== $option) {
            $state['poll']['counts'][$previousOption] = max(0, (int)($state['poll']['counts'][$previousOption] ?? 0) - 1);
        }
        if ($previousOption === $option) return $state;
        $state['poll']['counts'][$option] = (int)($state['poll']['counts'][$option] ?? 0) + 1;
        return $state;
    });
    respond(200, public_state($state, false));
}

if ($action === 'vote_quiz') {
    $pollId = clean_text($payload['pollId'] ?? '', 32);
    $answers = is_array($payload['answers'] ?? null) ? array_values($payload['answers']) : [];
    $previousAnswers = is_array($payload['previousAnswers'] ?? null) ? array_values($payload['previousAnswers']) : null;
    $expired = false;
    $state = update_room($code, function(array $state) use ($pollId, $answers, $previousAnswers, &$expired): array {
        $poll = $state['poll'] ?? null;
        if (!is_array($poll) || ($poll['id'] ?? '') !== $pollId || ($poll['type'] ?? '') !== 'quiz' || !empty($poll['closed'])) {
            respond(409, ['ok' => false, 'message' => 'Dieser Klassencheck ist nicht mehr aktiv.']);
        }
        if ((int)($poll['deadline'] ?? 0) > 0 && (int)$poll['deadline'] <= time()) {
            $state = timed_state($state);
            $expired = true;
            return $state;
        }
        $questions = is_array($poll['questions'] ?? null) ? $poll['questions'] : [];
        if (count($answers) !== count($questions)) {
            respond(422, ['ok' => false, 'message' => 'Bitte alle Aufgaben beantworten.']);
        }
        $normalized = [];
        foreach ($questions as $index => $question) {
            $option = filter_var($answers[$index] ?? null, FILTER_VALIDATE_INT);
            $count = count($question['options'] ?? []);
            if ($option === false || $option < 0 || $option >= $count) {
                respond(422, ['ok' => false, 'message' => 'Mindestens eine Antwort ist ungültig.']);
            }
            $normalized[] = (int)$option;
        }
        $previous = [];
        $previousValid = is_array($previousAnswers) && count($previousAnswers) === count($questions);
        if ($previousValid) {
            foreach ($questions as $index => $question) {
                $option = filter_var($previousAnswers[$index] ?? null, FILTER_VALIDATE_INT);
                $count = count($question['options'] ?? []);
                if ($option === false || $option < 0 || $option >= $count) {
                    $previousValid = false;
                    break;
                }
                $previous[] = (int)$option;
            }
        }
        if ($previousValid && $previous === $normalized) return $state;
        if ($previousValid) {
            foreach ($previous as $index => $option) {
                $state['poll']['questions'][$index]['counts'][$option] = max(
                    0,
                    (int)($state['poll']['questions'][$index]['counts'][$option] ?? 0) - 1
                );
            }
        }
        foreach ($normalized as $index => $option) {
            $state['poll']['questions'][$index]['counts'][$option] =
                (int)($state['poll']['questions'][$index]['counts'][$option] ?? 0) + 1;
        }
        if (!$previousValid) {
            $state['poll']['submissions'] = (int)($state['poll']['submissions'] ?? 0) + 1;
        }
        return $state;
    });
    if ($expired) respond(409, ['ok' => false, 'message' => 'Die Arbeitszeit ist beendet.']);
    respond(200, public_state($state, false));
}


if ($action === 'beamer_heartbeat') {
    $state = update_room($code, function(array $state): array {
        $state['beamerHeartbeatAt'] = time();
        return $state;
    });
    respond(200, public_state($state, false));
}

require_teacher();
platform_require_room_owner($code);


if ($action === 'set_feedback') {
    $enabled = !empty($payload['enabled']);
    $user = platform_teacher();
    if ($user === null || !class_exists('ReligionPlatform\\Rooms')) {
        respond(409, ['ok' => false, 'message' => 'KI-Feedback benötigt die zentrale Lehreranmeldung.']);
    }
    try {
        \ReligionPlatform\Rooms::setFeedback($code, $user, $enabled);
    } catch (Throwable $error) {
        respond(409, ['ok' => false, 'message' => clean_text($error->getMessage(), 240)]);
    }
    $state = update_room($code, function(array $state) use ($enabled): array {
        $state['aiFeedbackEnabled'] = $enabled;
        return $state;
    });
    platform_update_room($state);
    respond(200, public_state($state, true));
}

if ($action === 'set_release') {
    $requestedStage = valid_release_stage($payload['stage'] ?? '');
    if ($requestedStage === '') respond(422, ['ok' => false, 'message' => 'Der Unterrichtsabschnitt ist ungültig.']);
    $state = update_room($code, function(array $state) use ($requestedStage): array {
        $currentStage = current_release_stage($state);
        if (release_rank($requestedStage) > release_rank($currentStage)) $state['releasedStage'] = $requestedStage;
        elseif (!isset($state['releasedStage'])) $state['releasedStage'] = $currentStage;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'set_presentation') {
    $presentation = presentation_command($payload['presentation'] ?? null);
    if ($presentation === null) respond(422, ['ok' => false, 'message' => 'Der Präsentationsbefehl ist ungültig.']);
    $presentation['room'] = $code;
    $presentation['serverRevision'] = time();
    $state = update_room($code, function(array $state) use ($presentation): array {
        $state['presentation'] = $presentation;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'set_expiry') {
    $expiresAt = requested_expiry($payload['expiresAt'] ?? null);
    $state = update_room($code, function(array $state) use ($expiresAt): array {
        $state['expiresAt'] = $expiresAt;
        return $state;
    });
    platform_update_room($state);
    respond(200, public_state($state, true));
}

if ($action === 'set_card_wall') {
    $wallId = card_wall_id($payload['wallId'] ?? '');
    $categories = [];
    foreach (is_array($payload['categories'] ?? null) ? $payload['categories'] : [] as $rawCategory) {
        $category = template_id($rawCategory);
        if ($category !== '' && !in_array($category, $categories, true)) $categories[] = $category;
    }
    if ($wallId === '' || count($categories) < 2 || count($categories) > 8) respond(422, ['ok' => false, 'message' => 'Die Kartenwand oder ihre Kategorien sind ungültig.']);
    $open = !empty($payload['open']);
    $state = update_room($code, function(array $state) use ($wallId, $open, $categories): array {
        $walls = is_array($state['cardWalls'] ?? null) ? $state['cardWalls'] : [];
        $wall = is_array($walls[$wallId] ?? null) ? $walls[$wallId] : ['cards' => []];
        $wall['open'] = $open;
        $wall['categories'] = $categories;
        $wall['cards'] = is_array($wall['cards'] ?? null) ? $wall['cards'] : [];
        $walls[$wallId] = $wall;
        $state['cardWalls'] = $walls;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'moderate_card') {
    $wallId = card_wall_id($payload['wallId'] ?? '');
    $cardId = preg_replace('/[^a-f0-9]/', '', clean_text($payload['cardId'] ?? '', 24)) ?? '';
    $decision = ($payload['decision'] ?? '') === 'approve' ? 'approve' : (($payload['decision'] ?? '') === 'reject' ? 'reject' : '');
    if ($wallId === '' || $cardId === '' || $decision === '') respond(422, ['ok' => false, 'message' => 'Die Moderationsentscheidung ist ungültig.']);
    $state = update_room($code, function(array $state) use ($wallId, $cardId, $decision): array {
        $walls = is_array($state['cardWalls'] ?? null) ? $state['cardWalls'] : [];
        $wall = is_array($walls[$wallId] ?? null) ? $walls[$wallId] : ['open' => false, 'cards' => []];
        $cards = [];
        foreach (is_array($wall['cards'] ?? null) ? $wall['cards'] : [] as $card) {
            if (!is_array($card) || ($card['id'] ?? '') !== $cardId) { $cards[] = $card; continue; }
            if ($decision === 'approve') { $card['status'] = 'approved'; $cards[] = $card; }
        }
        $wall['cards'] = array_values($cards);
        $walls[$wallId] = $wall;
        $state['cardWalls'] = $walls;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'clear_card_wall') {
    $wallId = card_wall_id($payload['wallId'] ?? '');
    if ($wallId === '') respond(422, ['ok' => false, 'message' => 'Die Kartenwand ist ungültig.']);
    $state = update_room($code, function(array $state) use ($wallId): array {
        $walls = is_array($state['cardWalls'] ?? null) ? $state['cardWalls'] : [];
        $wall = is_array($walls[$wallId] ?? null) ? $walls[$wallId] : ['open' => false];
        $wall['cards'] = [];
        $walls[$wallId] = $wall;
        $state['cardWalls'] = $walls;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'set_label') {
    $label = clean_text($payload['label'] ?? '', 60);
    $state = update_room($code, function(array $state) use ($label): array {
        $state['label'] = $label;
        return $state;
    });
    platform_update_room($state);
    respond(200, public_state($state, true));
}

if ($action === 'start_timer') {
    $seconds = filter_var($payload['seconds'] ?? null, FILTER_VALIDATE_INT);
    $label = clean_text($payload['label'] ?? 'Arbeitszeit', 60) ?: 'Arbeitszeit';
    if ($seconds === false || $seconds < 10 || $seconds > 10800) {
        respond(422, ['ok' => false, 'message' => 'Die Timerdauer ist ungültig.']);
    }
    $state = update_room($code, function(array $state) use ($seconds, $label): array {
        $now = time();
        $state['timer'] = [
            'label' => $label,
            'duration' => $seconds,
            'remaining' => $seconds,
            'running' => true,
            'startedAt' => $now,
            'endsAt' => $now + $seconds,
        ];
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'pause_timer') {
    $state = update_room($code, function(array $state): array {
        $timer = $state['timer'] ?? null;
        if (!is_array($timer)) return $state;
        $timer['remaining'] = !empty($timer['running'])
            ? max(0, (int)($timer['endsAt'] ?? time()) - time())
            : max(0, (int)($timer['remaining'] ?? 0));
        $timer['running'] = false;
        $timer['endsAt'] = null;
        $state['timer'] = $timer;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'resume_timer') {
    $state = update_room($code, function(array $state): array {
        $timer = $state['timer'] ?? null;
        if (!is_array($timer) || !empty($timer['running'])) return $state;
        $remaining = max(0, (int)($timer['remaining'] ?? 0));
        if ($remaining === 0) return $state;
        $timer['running'] = true;
        $timer['startedAt'] = time();
        $timer['endsAt'] = time() + $remaining;
        $state['timer'] = $timer;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'add_timer') {
    $seconds = filter_var($payload['seconds'] ?? null, FILTER_VALIDATE_INT);
    if ($seconds === false || $seconds < 1 || $seconds > 1800) {
        respond(422, ['ok' => false, 'message' => 'Die Zusatzzeit ist ungültig.']);
    }
    $state = update_room($code, function(array $state) use ($seconds): array {
        $timer = $state['timer'] ?? null;
        if (!is_array($timer)) return $state;
        $timer['duration'] = max(0, (int)($timer['duration'] ?? 0)) + $seconds;
        if (!empty($timer['running'])) {
            $timer['endsAt'] = max(time(), (int)($timer['endsAt'] ?? time())) + $seconds;
        } else {
            $timer['remaining'] = max(0, (int)($timer['remaining'] ?? 0)) + $seconds;
        }
        $state['timer'] = $timer;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'reset_timer') {
    $state = update_room($code, function(array $state): array {
        $state['timer'] = null;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'start') {
    $question = clean_text($payload['question'] ?? '', 180);
    $templateId = template_id($payload['templateId'] ?? 'single-poll') ?: 'single-poll';
    $resultPolicy = ($payload['resultPolicy'] ?? '') === 'beamer' ? 'beamer' : 'store';
    $rawOptions = is_array($payload['options'] ?? null) ? $payload['options'] : [];
    $options = [];
    foreach ($rawOptions as $value) {
        $option = clean_text($value, 90);
        if ($option !== '') $options[] = $option;
    }
    if (strlen($question) < 5 || count($options) < 2 || count($options) > 6) {
        respond(422, ['ok' => false, 'message' => 'Bitte eine Frage mit zwei bis sechs Antworten angeben.']);
    }
    $state = update_room($code, function(array $state) use ($question, $options, $resultPolicy, $templateId): array {
        $state = archive_current_poll($state);
        $state['poll'] = [
            'id' => bin2hex(random_bytes(8)),
            'templateId' => $templateId,
            'question' => $question,
            'options' => $options,
            'counts' => array_fill(0, count($options), 0),
            'resultPolicy' => $resultPolicy,
            'reveal' => $resultPolicy === 'beamer',
            'closed' => false,
            'startedAt' => time(),
        ];
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'start_quiz') {
    $title = clean_text($payload['title'] ?? 'Klassencheck', 180) ?: 'Klassencheck';
    $templateId = template_id($payload['templateId'] ?? 'class-check') ?: 'class-check';
    $resultPolicy = ($payload['resultPolicy'] ?? '') === 'beamer' ? 'beamer' : 'store';
    $duration = filter_var($payload['durationSeconds'] ?? 300, FILTER_VALIDATE_INT);
    $rawQuestions = is_array($payload['questions'] ?? null) ? $payload['questions'] : [];
    if ($duration === false || $duration < 30 || $duration > 3600 || count($rawQuestions) < 2 || count($rawQuestions) > 20) {
        respond(422, ['ok' => false, 'message' => 'Klassencheck oder Dauer ist ungültig.']);
    }
    $questions = [];
    foreach ($rawQuestions as $rawQuestion) {
        if (!is_array($rawQuestion)) respond(422, ['ok' => false, 'message' => 'Eine Aufgabe ist ungültig.']);
        $question = clean_text($rawQuestion['question'] ?? '', 220);
        $rawOptions = is_array($rawQuestion['options'] ?? null) ? $rawQuestion['options'] : [];
        $options = [];
        foreach ($rawOptions as $rawOption) {
            $option = clean_text($rawOption, 120);
            if ($option !== '') $options[] = $option;
        }
        $correctIndex = filter_var($rawQuestion['correctIndex'] ?? null, FILTER_VALIDATE_INT);
        if (strlen($question) < 5 || count($options) < 2 || count($options) > 6 || $correctIndex === false || $correctIndex < 0 || $correctIndex >= count($options)) {
            respond(422, ['ok' => false, 'message' => 'Mindestens eine Aufgabe ist unvollständig.']);
        }
        $questions[] = [
            'question' => $question,
            'options' => $options,
            'counts' => array_fill(0, count($options), 0),
            'correctIndex' => (int)$correctIndex,
            'insight' => clean_text($rawQuestion['insight'] ?? '', 400),
        ];
    }
    $state = update_room($code, function(array $state) use ($title, $questions, $resultPolicy, $duration, $templateId): array {
        $state = archive_current_poll($state);
        $now = time();
        $state['poll'] = [
            'id' => bin2hex(random_bytes(8)),
            'type' => 'quiz',
            'templateId' => $templateId,
            'question' => $title,
            'questions' => $questions,
            'submissions' => 0,
            'resultPolicy' => $resultPolicy,
            'reveal' => $resultPolicy === 'beamer',
            'closed' => false,
            'startedAt' => $now,
            'deadline' => $now + $duration,
        ];
        $state['timer'] = [
            'label' => 'Klassencheck',
            'duration' => $duration,
            'remaining' => $duration,
            'running' => true,
            'startedAt' => $now,
            'endsAt' => $now + $duration,
        ];
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'reveal') {
    $state = update_room($code, function(array $state): array {
        if (is_array($state['poll'] ?? null)) $state['poll']['reveal'] = true;
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'close_poll') {
    $state = update_room($code, function(array $state): array {
        if (is_array($state['poll'] ?? null)) {
            $state['poll']['closed'] = true;
            $state['poll']['closedAt'] = time();
            if (($state['poll']['type'] ?? '') === 'quiz' && is_array($state['timer'] ?? null)) {
                $state['timer']['running'] = false;
                $state['timer']['remaining'] = 0;
                $state['timer']['endsAt'] = null;
            }
        }
        return $state;
    });
    respond(200, public_state($state, true));
}

if ($action === 'end_room') {
    platform_end_room($code);
    $path = room_path($code);
    if (is_file($path)) @unlink($path);
    respond(200, ['ok' => true, 'room' => '', 'poll' => null, 'timer' => null]);
}

respond(400, ['ok' => false, 'message' => 'Unbekannte Aktion.']);
