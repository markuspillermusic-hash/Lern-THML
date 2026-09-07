<?php
declare(strict_types=1);

namespace ReligionPlatform;

final class Mfa
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function provisioningUri(array $user, string $secret): string
    {
        $issuer = 'Religionsunterricht Lehrerplattform';
        $label = $issuer . ':' . (string)$user['email'];
        return 'otpauth://totp/' . rawurlencode($label) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function verifySecret(string $secret, string $code, ?int $now = null): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $counter = intdiv($now ?? time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals(self::totp($secret, $counter + $offset), $code)) return true;
        }
        return false;
    }

    public static function verifyForUser(array $user, string $code): bool
    {
        if (empty($user['mfa_enabled_at'])) return true;
        $userId = (int)$user['id'];
        $secret = Vault::get('user', $userId, 'mfa_totp_secret');
        if (is_string($secret) && self::verifySecret($secret, $code)) return true;

        $normalised = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        if (strlen($normalised) !== 10) return false;
        $stored = Vault::get('user', $userId, 'mfa_recovery_codes');
        $hashes = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($hashes)) return false;
        $candidate = hash_hmac('sha256', $normalised, Vault::masterKey());
        foreach ($hashes as $index => $hash) {
            if (!is_string($hash) || !hash_equals($hash, $candidate)) continue;
            unset($hashes[$index]);
            Vault::put('user', $userId, 'mfa_recovery_codes', json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES));
            Audit::record($userId, 'auth.mfa_recovery_used', 'user', (string)$userId, ['remaining' => count($hashes)]);
            return true;
        }
        return false;
    }

    /** @return array{version:string,recovery_codes:array<int,string>} */
    public static function enable(array $user, string $secret, string $code): array
    {
        if (!preg_match('/^[A-Z2-7]{32}$/', $secret) || !self::verifySecret($secret, $code)) {
            throw new \RuntimeException('Der Bestätigungscode stimmt nicht. Bitte die Uhrzeit des Geräts und die Eingabe prüfen.');
        }
        $codes = self::generateRecoveryCodes();
        $hashes = array_map(static fn(string $value): string => hash_hmac('sha256', str_replace('-', '', $value), Vault::masterKey()), $codes);
        Vault::put('user', (int)$user['id'], 'mfa_totp_secret', $secret);
        Vault::put('user', (int)$user['id'], 'mfa_recovery_codes', json_encode($hashes, JSON_UNESCAPED_SLASHES));
        $version = bin2hex(random_bytes(16));
        $statement = Database::connection()->prepare('UPDATE users SET mfa_enabled_at=?,auth_version=?,updated_at=? WHERE id=?');
        $statement->execute([time(), $version, time(), (int)$user['id']]);
        Audit::record((int)$user['id'], 'auth.mfa_enabled', 'user', (string)$user['id']);
        return ['version' => $version, 'recovery_codes' => $codes];
    }

    public static function disable(array $user, string $password, string $code): string
    {
        if (!password_verify($password, (string)$user['password_hash'])) throw new \RuntimeException('Das Passwort stimmt nicht.');
        if (!self::verifyForUser($user, $code)) throw new \RuntimeException('Der Bestätigungscode oder Wiederherstellungscode stimmt nicht.');
        return self::reset((int)$user['id'], (int)$user['id'], 'auth.mfa_disabled');
    }

    public static function adminReset(array $admin, int $userId): void
    {
        if (!Auth::isAdmin($admin)) throw new \RuntimeException('Nur die Administration kann die Zwei-Faktor-Anmeldung zurücksetzen.');
        self::reset($userId, (int)$admin['id'], 'auth.mfa_admin_reset');
    }

    private static function reset(int $userId, int $actorId, string $event): string
    {
        Vault::delete('user', $userId, 'mfa_totp_secret');
        Vault::delete('user', $userId, 'mfa_recovery_codes');
        $version = bin2hex(random_bytes(16));
        $statement = Database::connection()->prepare('UPDATE users SET mfa_enabled_at=NULL,auth_version=?,updated_at=? WHERE id=?');
        $statement->execute([$version, time(), $userId]);
        if ($statement->rowCount() !== 1) throw new \RuntimeException('Das Lehrerkonto wurde nicht gefunden.');
        Audit::record($actorId, $event, 'user', (string)$userId);
        return $version;
    }

    private static function generateRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        return $codes;
    }

    private static function totp(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $binaryCounter = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = (unpack('N', substr($hash, $offset, 4))[1] ?? 0) & 0x7fffffff;
        return str_pad((string)($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= self::ALPHABET[bindec($chunk)];
        }
        return $result;
    }

    private static function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        $bits = '';
        foreach (str_split($value) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) throw new \RuntimeException('Ungültiges TOTP-Geheimnis.');
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $result .= chr(bindec($chunk));
        }
        return $result;
    }
}
