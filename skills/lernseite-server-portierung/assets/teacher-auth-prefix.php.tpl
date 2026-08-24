<?php
declare(strict_types=1);

const TEACHER_PASSWORD_SALT = '{{PASSWORD_SALT_HEX}}';
const TEACHER_PASSWORD_HASH = '{{PASSWORD_HASH_HEX}}';
const TEACHER_PASSWORD_ITERATIONS = {{PASSWORD_ITERATIONS}};
const TEACHER_AUTH_VERSION = '{{AUTH_VERSION}}';
const TEACHER_SESSION_KEY = '{{TEACHER_SESSION_KEY}}';
const TEACHER_CSRF_KEY = '{{CSRF_SESSION_KEY}}';

session_name('{{SESSION_NAME}}');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '{{COOKIE_PATH}}',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }
    session_destroy();
    header('Location: ./');
    exit;
}

if (empty($_SESSION[TEACHER_CSRF_KEY])) {
    $_SESSION[TEACHER_CSRF_KEY] = bin2hex(random_bytes(24));
}

$error = '';
$blockedUntil = (int)($_SESSION['teacher_blocked_until'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($blockedUntil > time()) {
        $error = 'Zu viele Fehlversuche. Bitte kurz warten und dann erneut versuchen.';
    } else {
        $csrf = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $salt = hex2bin(TEACHER_PASSWORD_SALT);
        $candidate = $salt === false
            ? ''
            : hash_pbkdf2('sha256', $password, $salt, TEACHER_PASSWORD_ITERATIONS, 64, false);
        if (
            hash_equals((string)$_SESSION[TEACHER_CSRF_KEY], $csrf)
            && hash_equals(TEACHER_PASSWORD_HASH, $candidate)
        ) {
            session_regenerate_id(true);
            $_SESSION[TEACHER_SESSION_KEY] = true;
            $_SESSION[TEACHER_SESSION_KEY . '_auth_version'] = TEACHER_AUTH_VERSION;
            unset($_SESSION['teacher_attempts'], $_SESSION['teacher_blocked_until']);
            header('Location: ./');
            exit;
        }
        $attempts = (int)($_SESSION['teacher_attempts'] ?? 0) + 1;
        $_SESSION['teacher_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['teacher_blocked_until'] = time() + 30;
            $_SESSION['teacher_attempts'] = 0;
        }
        $error = 'Das Passwort stimmt nicht.';
    }
}

$teacherAuthenticated = !empty($_SESSION[TEACHER_SESSION_KEY])
    && hash_equals(
        TEACHER_AUTH_VERSION,
        (string)($_SESSION[TEACHER_SESSION_KEY . '_auth_version'] ?? '')
    );
if (!$teacherAuthenticated):
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>{{LOGIN_TITLE}}</title>
<style>
:root{color-scheme:light dark;--paper:#f6f7f9;--ink:#1e242c;--muted:#5c6773;--line:#d5dae1;--accent:#2c3a4a;--white:#fff}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--paper);color:var(--ink);font:17px/1.55 "Segoe UI",system-ui,sans-serif;padding:1rem}
main{width:min(30rem,100%);background:var(--white);border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 60px rgba(20,30,40,.14);padding:1.4rem}
h1{font:700 clamp(1.55rem,5vw,2.1rem)/1.15 Georgia,serif;margin:0 0 .65rem}p{margin:.55rem 0;color:var(--muted)}label{display:block;font-weight:700;margin:1.1rem 0 .35rem}
input{width:100%;min-height:48px;border:1px solid var(--line);border-radius:9px;padding:.65rem .75rem;background:var(--white);color:var(--ink);font:inherit}
button{width:100%;min-height:48px;margin-top:.75rem;border:0;border-radius:9px;background:var(--accent);color:#fff;font:700 1rem/1.2 inherit;cursor:pointer}
input:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #f2b84b;outline-offset:3px}.error{color:#a53b3b;font-weight:700}.back{margin-top:1rem;font-size:.9rem}
@media(prefers-color-scheme:dark){:root{--paper:#141a21;--ink:#eef2f6;--muted:#b8c1ca;--line:#3b4652;--accent:#6e9dc6;--white:#202832}button{color:#111820}}
</style>
</head>
<body>
<main>
  <h1>Lehreransicht</h1>
  <p>{{LOGIN_DESCRIPTION}}</p>
  <?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
  <form method="post" action="./">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$_SESSION[TEACHER_CSRF_KEY], ENT_QUOTES, 'UTF-8') ?>">
    <label for="password">Unterrichtspasswort</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
    <button type="submit">Lehreransicht öffnen</button>
  </form>
  <p class="back"><a href="{{PUBLIC_URL}}">Zur Schüleransicht</a></p>
</main>
</body>
</html>
<?php
exit;
endif;
?>
