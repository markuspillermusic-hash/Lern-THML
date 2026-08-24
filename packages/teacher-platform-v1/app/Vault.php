<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

final class Vault
{
    private static ?string $masterKey = null;

    public static function masterKey(): string
    {
        if (self::$masterKey !== null) return self::$masterKey;
        $path = (string)Config::get('master_key_file');
        $raw = @file_get_contents($path);
        if (!is_string($raw)) throw new \RuntimeException('Der Plattform-Hauptschlüssel fehlt.');
        $raw = trim($raw);
        $decoded = base64_decode($raw, true);
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Der Plattform-Hauptschlüssel ist ungültig.');
        }
        self::$masterKey = $decoded;
        return self::$masterKey;
    }

    public static function put(string $scopeType, int $scopeId, string $kind, string $plaintext): void
    {
        self::validateScope($scopeType, $scopeId, $kind);
        if ($plaintext === '') throw new \InvalidArgumentException('Ein leerer Schlüssel kann nicht gespeichert werden.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, self::masterKey());
        $now = time();
        $statement = Database::connection()->prepare(<<<'SQL'
INSERT INTO encrypted_secrets(scope_type, scope_id, secret_kind, ciphertext, nonce, created_at, updated_at)
VALUES(?,?,?,?,?,?,?)
ON CONFLICT(scope_type, scope_id, secret_kind)
DO UPDATE SET ciphertext=excluded.ciphertext, nonce=excluded.nonce, updated_at=excluded.updated_at
SQL);
        $statement->execute([$scopeType, $scopeId, $kind, base64_encode($cipher), base64_encode($nonce), $now, $now]);
    }

    public static function get(string $scopeType, int $scopeId, string $kind): ?string
    {
        self::validateScope($scopeType, $scopeId, $kind);
        $statement = Database::connection()->prepare('SELECT ciphertext, nonce FROM encrypted_secrets WHERE scope_type=? AND scope_id=? AND secret_kind=?');
        $statement->execute([$scopeType, $scopeId, $kind]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $cipher = base64_decode((string)$row['ciphertext'], true);
        $nonce = base64_decode((string)$row['nonce'], true);
        if (!is_string($cipher) || !is_string($nonce) || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Ein gespeichertes Geheimnis ist beschädigt.');
        }
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::masterKey());
        if (!is_string($plain)) throw new \RuntimeException('Ein gespeichertes Geheimnis konnte nicht entschlüsselt werden.');
        return $plain;
    }

    public static function has(string $scopeType, int $scopeId, string $kind): bool
    {
        $statement = Database::connection()->prepare('SELECT 1 FROM encrypted_secrets WHERE scope_type=? AND scope_id=? AND secret_kind=?');
        $statement->execute([$scopeType, $scopeId, $kind]);
        return (bool)$statement->fetchColumn();
    }

    public static function delete(string $scopeType, int $scopeId, string $kind): void
    {
        self::validateScope($scopeType, $scopeId, $kind);
        $statement = Database::connection()->prepare('DELETE FROM encrypted_secrets WHERE scope_type=? AND scope_id=? AND secret_kind=?');
        $statement->execute([$scopeType, $scopeId, $kind]);
    }

    public static function resolveOpenAiKey(array $user, ?int $organisationId = null): ?array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId > 0) {
            $key = self::get('user', $userId, 'openai_api_key');
            if (is_string($key) && $key !== '') return ['key' => $key, 'scope' => 'user', 'scopeId' => $userId];
        }
        $organisationId = $organisationId ?: Auth::defaultOrganisationId($userId);
        if ($organisationId > 0) {
            $key = self::get('organisation', $organisationId, 'openai_api_key');
            if (is_string($key) && $key !== '') return ['key' => $key, 'scope' => 'organisation', 'scopeId' => $organisationId];
        }
        return null;
    }

    private static function validateScope(string $scopeType, int $scopeId, string $kind): void
    {
        if (!in_array($scopeType, ['user', 'organisation', 'system'], true) || $scopeId < 0) {
            throw new \InvalidArgumentException('Ungültiger Geheimnisbereich.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,60}$/', $kind)) throw new \InvalidArgumentException('Ungültiger Geheimnistyp.');
    }
}

