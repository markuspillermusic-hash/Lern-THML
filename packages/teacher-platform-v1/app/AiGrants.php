<?php
declare(strict_types=1);

namespace ReligionPlatform;

use PDO;

/**
 * Zeitlich und mengenmäßig begrenzte, serverseitig finanzierte KI-Zugänge.
 * Der zugrunde liegende API-Schlüssel wird nie an Lehrkräfte oder Browser
 * ausgegeben. Ein Grant ist lediglich eine widerrufbare Berechtigung.
 */
final class AiGrants
{
    private const SECRET_KIND = 'sponsored_openai_api_key';

    public static function setSystemKey(array $admin, string $key): void
    {
        if (!Auth::isAdmin($admin)) throw new \RuntimeException('Nur die Administration kann den Förderzugang verwalten.');
        $key = trim($key);
        if (!preg_match('/^sk-[A-Za-z0-9_-]{20,}$/', $key)) throw new \RuntimeException('Der OpenAI-Projektschlüssel hat kein plausibles Format.');
        Vault::put('system', 0, self::SECRET_KIND, $key);
        Audit::record((int)$admin['id'], 'ai.sponsored_key_saved', 'system', 'openai');
    }

    public static function deleteSystemKey(array $admin): void
    {
        if (!Auth::isAdmin($admin)) throw new \RuntimeException('Nur die Administration kann den Förderzugang verwalten.');
        Vault::delete('system', 0, self::SECRET_KIND);
        Audit::record((int)$admin['id'], 'ai.sponsored_key_deleted', 'system', 'openai');
    }

    public static function hasSystemKey(): bool
    {
        return Vault::has('system', 0, self::SECRET_KIND);
    }

    public static function create(array $admin, int $userId, array $input): int
    {
        if (!Auth::isAdmin($admin)) throw new \RuntimeException('Nur die Administration kann Förderkontingente vergeben.');
        if (!self::hasSystemKey()) throw new \RuntimeException('Zuerst muss ein serverseitiger OpenAI-Förderzugang hinterlegt werden.');
        $user = Database::connection()->prepare('SELECT id FROM users WHERE id=? AND status="active"');
        $user->execute([$userId]);
        if (!$user->fetchColumn()) throw new \RuntimeException('Das ausgewählte Lehrerkonto ist nicht aktiv.');

        $label = Security::clean($input['label'] ?? 'Demo-Kontingent', 100) ?: 'Demo-Kontingent';
        $startsAt = self::dateToTimestamp((string)($input['starts_at'] ?? ''), time());
        $expiresAt = self::dateToTimestamp((string)($input['expires_at'] ?? ''), time() + 14 * 86400, true);
        if ($expiresAt <= $startsAt) throw new \RuntimeException('Das Enddatum muss nach dem Beginn liegen.');
        if ($expiresAt > time() + 370 * 86400) throw new \RuntimeException('Ein Förderkontingent darf höchstens 370 Tage im Voraus reichen.');
        $requestLimit = max(1, min(5000, (int)($input['request_limit'] ?? 30)));
        $tokenLimit = max(1000, min(50_000_000, (int)($input['token_limit'] ?? 60000)));
        $modules = self::normaliseModules($input['allowed_modules'] ?? []);
        $now = time();
        $statement = Database::connection()->prepare('INSERT INTO ai_grants(user_id,label,status,starts_at,expires_at,request_limit,token_limit,allowed_modules_json,created_by,created_at,updated_at) VALUES(?,? ,"active",?,?,?,?,?,?,?,?)');
        $statement->execute([$userId, $label, $startsAt, $expiresAt, $requestLimit, $tokenLimit, json_encode($modules, JSON_UNESCAPED_SLASHES), (int)$admin['id'], $now, $now]);
        $id = (int)Database::connection()->lastInsertId();
        Audit::record((int)$admin['id'], 'ai.grant_created', 'ai_grant', (string)$id, ['user_id' => $userId, 'request_limit' => $requestLimit]);
        return $id;
    }

    public static function revoke(array $admin, int $grantId): void
    {
        if (!Auth::isAdmin($admin)) throw new \RuntimeException('Nur die Administration kann Förderkontingente widerrufen.');
        $statement = Database::connection()->prepare('UPDATE ai_grants SET status="revoked",revoked_at=?,updated_at=? WHERE id=? AND status="active"');
        $statement->execute([time(), time(), $grantId]);
        if ($statement->rowCount() !== 1) throw new \RuntimeException('Das Förderkontingent ist nicht mehr aktiv.');
        Audit::record((int)$admin['id'], 'ai.grant_revoked', 'ai_grant', (string)$grantId);
    }

    public static function resolve(int $userId, string $moduleSlug): ?array
    {
        if ($userId < 1 || !self::hasSystemKey()) return null;
        $statement = Database::connection()->prepare('SELECT * FROM ai_grants WHERE user_id=? AND status="active" AND starts_at<=? AND expires_at>? AND used_requests<request_limit AND used_tokens<token_limit ORDER BY expires_at ASC,id ASC');
        $statement->execute([$userId, time(), time()]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $grant) {
            $modules = json_decode((string)$grant['allowed_modules_json'], true);
            if (!is_array($modules)) $modules = [];
            if ($modules !== [] && !in_array($moduleSlug, $modules, true)) continue;
            $key = Vault::get('system', 0, self::SECRET_KIND);
            if (!is_string($key) || $key === '') return null;
            $grant['key'] = $key;
            return $grant;
        }
        return null;
    }

    public static function list(?int $userId = null): array
    {
        $sql = 'SELECT g.*,u.display_name,u.email FROM ai_grants g JOIN users u ON u.id=g.user_id';
        $args = [];
        if ($userId !== null) {
            $sql .= ' WHERE g.user_id=?';
            $args[] = $userId;
        }
        $sql .= ' ORDER BY g.created_at DESC';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function reserveRequest(int $grantId): bool
    {
        if ($grantId < 1) return false;
        $now = time();
        $statement = Database::connection()->prepare('UPDATE ai_grants SET used_requests=used_requests+1,updated_at=? WHERE id=? AND status="active" AND starts_at<=? AND expires_at>? AND used_requests<request_limit AND used_tokens<token_limit');
        $statement->execute([$now, $grantId, $now, $now]);
        return $statement->rowCount() === 1;
    }

    public static function recordTokens(int $grantId, int $tokens): void
    {
        if ($grantId < 1) return;
        $tokens = max(0, $tokens);
        $statement = Database::connection()->prepare('UPDATE ai_grants SET used_tokens=used_tokens+?,updated_at=? WHERE id=?');
        $statement->execute([$tokens, time(), $grantId]);
    }

    private static function normaliseModules(mixed $value): array
    {
        if (is_string($value)) $value = preg_split('/[\s,;]+/', $value) ?: [];
        if (!is_array($value)) return [];
        $modules = [];
        foreach ($value as $module) {
            $module = Security::clean((string)$module, 100);
            if ($module !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{1,99}$/', $module)) $modules[] = $module;
        }
        return array_values(array_unique($modules));
    }

    private static function dateToTimestamp(string $value, int $fallback, bool $endOfDay = false): int
    {
        if ($value === '') return $fallback;
        $suffix = $endOfDay ? ' 23:59:59' : ' 00:00:00';
        $timestamp = strtotime($value . $suffix);
        return $timestamp !== false ? $timestamp : $fallback;
    }
}
