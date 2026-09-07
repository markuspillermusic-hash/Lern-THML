<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class Security
{
    public static function headers(string $csp = ''): void
    {
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        if ($csp !== '') header('Content-Security-Policy: ' . $csp);
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name((string)Config::get('session_name', 'religion_teacher_platform'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !(Config::get('development_loopback_http', false) === true
                && in_array((string)($_SERVER['SERVER_NAME'] ?? ''), ['127.0.0.1','localhost'], true)),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
        $now = time();
        $idle = (int)Config::get('session_idle_seconds', 28800);
        $absolute = (int)Config::get('session_absolute_seconds', 43200);
        $expiredByIdle = !empty($_SESSION['platform_last_seen']) && (int)$_SESSION['platform_last_seen'] < $now - $idle;
        $expiredAbsolutely = !empty($_SESSION['platform_started_at']) && (int)$_SESSION['platform_started_at'] < $now - $absolute;
        if ($expiredByIdle || $expiredAbsolutely) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        if (empty($_SESSION['platform_started_at'])) $_SESSION['platform_started_at'] = $now;
        if (empty($_SESSION['platform_regenerated_at'])) $_SESSION['platform_regenerated_at'] = $now;
        if ((int)$_SESSION['platform_regenerated_at'] < $now - 900) {
            session_regenerate_id(true);
            $_SESSION['platform_regenerated_at'] = $now;
        }
        $_SESSION['platform_last_seen'] = $now;
    }

    public static function csrf(): string
    {
        self::startSession();
        if (empty($_SESSION['platform_csrf'])) $_SESSION['platform_csrf'] = bin2hex(random_bytes(24));
        return (string)$_SESSION['platform_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        return is_string($token) && $token !== '' && hash_equals((string)($_SESSION['platform_csrf'] ?? ''), $token);
    }

    public static function clean(mixed $value, int $max = 200): string
    {
        if (!is_string($value)) return '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    public static function cleanMultiline(mixed $value, int $max = 4000): string
    {
        if (!is_string($value)) return '';
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    public static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function clientIpHash(string $scope = 'general'): string
    {
        // Die Plattform hängt derzeit direkt hinter dem eigenen Nginx. Einen vom
        // Client gesetzten Proxy-Header zu übernehmen würde das Rate-Limit
        // umgehbar machen. Falls später ein vertrauenswürdiger Reverse-Proxy
        // vorgeschaltet wird, wird dessen Adresse explizit konfiguriert.
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return hash_hmac('sha256', $scope . '|' . $ip, Vault::masterKey());
    }

    public static function formToken(string $purpose, int $issuedAt = 0): string
    {
        $issuedAt = $issuedAt ?: time();
        $nonce = bin2hex(random_bytes(12));
        $payload = $purpose . '|' . $issuedAt . '|' . $nonce;
        $mac = hash_hmac('sha256', $payload, Vault::masterKey());
        return rtrim(strtr(base64_encode($issuedAt . '|' . $nonce . '|' . $mac), '+/', '-_'), '=');
    }

    public static function verifyFormToken(string $token, string $purpose, int $maxAge = 7200, int $minAge = 2): bool
    {
        $encoded = strtr($token, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding !== 0) $encoded .= str_repeat('=', 4 - $padding);
        $raw = base64_decode($encoded, true);
        if (!is_string($raw)) return false;
        $parts = explode('|', $raw);
        if (count($parts) !== 3) return false;
        [$issuedAt, $nonce, $mac] = $parts;
        if (!ctype_digit($issuedAt) || !preg_match('/^[a-f0-9]{24}$/', $nonce)) return false;
        $age = time() - (int)$issuedAt;
        if ($age < $minAge || $age > $maxAge) return false;
        $expected = hash_hmac('sha256', $purpose . '|' . $issuedAt . '|' . $nonce, Vault::masterKey());
        return hash_equals($expected, $mac);
    }

    public static function rateLimit(string $bucket, string $subjectHash, int $windowSeconds, int $limit): bool
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $db = Database::connection();
        $statement = $db->prepare(<<<'SQL'
INSERT INTO rate_limits(bucket,subject_hash,window_started_at,hits)
VALUES(?,?,?,1)
ON CONFLICT(bucket,subject_hash,window_started_at)
DO UPDATE SET hits=hits+1
RETURNING hits
SQL);
        $statement->execute([$bucket, $subjectHash, $window]);
        $hits = (int)$statement->fetchColumn();
        if (random_int(1, 100) === 1) {
            $cleanup = $db->prepare('DELETE FROM rate_limits WHERE window_started_at < ?');
            $cleanup->execute([time() - 172800]);
        }
        return $hits <= $limit;
    }
}
