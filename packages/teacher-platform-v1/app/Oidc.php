<?php
declare(strict_types=1);

namespace ReligionPlatform;

/** Restricted first-party OIDC provider: code + S256, confidential clients, RS256. */
final class Oidc
{
    public static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function issuer(): string
    {
        return rtrim((string)Config::get('base_url'), '/') . '/zugang';
    }

    public static function endpoint(string $name): string
    {
        return self::issuer() . '/oidc.php?endpoint=' . $name;
    }

    public static function discovery(): array
    {
        return [
            'issuer'=>self::issuer(), 'authorization_endpoint'=>self::endpoint('authorize'),
            'token_endpoint'=>self::endpoint('token'), 'userinfo_endpoint'=>self::endpoint('userinfo'),
            'jwks_uri'=>self::endpoint('jwks'), 'revocation_endpoint'=>self::endpoint('revoke'),
            'response_types_supported'=>['code'], 'grant_types_supported'=>['authorization_code'],
            'subject_types_supported'=>['public'], 'id_token_signing_alg_values_supported'=>['RS256'],
            'token_endpoint_auth_methods_supported'=>['client_secret_basic'],
            'code_challenge_methods_supported'=>['S256'], 'scopes_supported'=>['openid','profile'],
            'claims_supported'=>['iss','sub','aud','exp','iat','auth_time','nonce','name','preferred_username','role','organisation_id','products'],
        ];
    }

    public static function secureUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\\\\]/', $url)) return false;
        if (($parts['scheme'] ?? '') === 'https') return true;
        return Config::get('development_loopback_http', false) === true
            && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['127.0.0.1','localhost','[::1]'], true);
    }

    public static function registerClient(array $actor, array $input): array
    {
        Identity::assertAdmin($actor);
        $id = (string)($input['client_id'] ?? '');
        $product = (string)($input['product'] ?? 'assessment');
        $base = rtrim((string)($input['base_url'] ?? ''), '/');
        $redirects = $input['redirect_uris'] ?? [];
        $orgId = (int)($input['organisation_id'] ?? 0);
        if (!preg_match('/^[a-z][a-z0-9-]{2,63}$/D', $id) || !in_array($product,['learning','assessment'],true)) throw new \InvalidArgumentException('Installationskennung oder Angebot ist ungültig.');
        if (!self::secureUrl($base) || parse_url($base, PHP_URL_QUERY) !== null || !is_array($redirects) || count($redirects)<1 || count($redirects)>5) throw new \InvalidArgumentException('Bitte eine gültige HTTPS-Adresse und Rücksprungadresse eintragen.');
        foreach ($redirects as $uri) {
            if (!is_string($uri) || !self::secureUrl($uri) || !str_starts_with($uri, $base . '/')) throw new \InvalidArgumentException('Die Rücksprungadresse muss zur Installation gehören.');
        }
        $org = Database::connection()->prepare('SELECT 1 FROM organisations WHERE id=? AND status="active"');
        $org->execute([$orgId]);
        if (!$org->fetchColumn()) throw new \InvalidArgumentException('Organisation nicht gefunden.');
        $secret = self::base64url(random_bytes(32));
        $q = Database::connection()->prepare('INSERT INTO platform_clients(client_id,label,organisation_id,product,base_url,redirect_uris_json,secret_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $q->execute([$id,Security::clean($input['label'] ?? $id,160),$orgId,$product,$base,json_encode(array_values(array_unique($redirects)),JSON_THROW_ON_ERROR),hash('sha256',$secret),time(),time()]);
        Audit::record((int)$actor['id'],'installation.created','client',$id,['organisation_id'=>$orgId,'product'=>$product]);
        return ['client_id'=>$id,'client_secret'=>$secret,'issuer'=>self::issuer()];
    }

    public static function client(string $id): array
    {
        $q = Database::connection()->prepare('SELECT c.* FROM platform_clients c JOIN organisations o ON o.id=c.organisation_id WHERE c.client_id=? AND c.status="active" AND o.status="active"');
        $q->execute([$id]);
        return $q->fetch() ?: throw new \RuntimeException('invalid_client');
    }

    public static function authenticateClient(string $id, string $secret): array
    {
        $client = self::client($id);
        if ($secret === '' || !hash_equals($client['secret_hash'], hash('sha256',$secret))) throw new \RuntimeException('invalid_client');
        return $client;
    }

    public static function validateAuthorization(array $input): array
    {
        $client = self::client((string)($input['client_id'] ?? ''));
        $redirect = (string)($input['redirect_uri'] ?? '');
        if (!in_array($redirect,json_decode($client['redirect_uris_json'],true,512,JSON_THROW_ON_ERROR),true)) throw new \RuntimeException('invalid_redirect_uri');
        $scope = trim((string)($input['scope'] ?? ''));
        $scopes = explode(' ', $scope);
        if (!in_array('openid',$scopes,true) || array_diff($scopes,['openid','profile'])) throw new \RuntimeException('invalid_scope');
        if (($input['response_type'] ?? '') !== 'code' || ($input['code_challenge_method'] ?? '') !== 'S256'
            || !preg_match('/^[A-Za-z0-9_-]{43}$/D',(string)($input['code_challenge'] ?? ''))
            || !preg_match('/^[A-Za-z0-9._~-]{16,256}$/D',(string)($input['state'] ?? ''))
            || !preg_match('/^[A-Za-z0-9._~-]{16,256}$/D',(string)($input['nonce'] ?? ''))) throw new \RuntimeException('invalid_request');
        return $client;
    }

    public static function authorize(array $input, array $identity, int $authTime): string
    {
        $client = self::validateAuthorization($input);
        $fresh = Identity::find($identity['subject']);
        if (!$fresh || !Identity::allows($fresh,$client['product'],(int)$client['organisation_id']) || !empty($fresh['must_change_password'])) throw new \RuntimeException('access_denied');
        $code = self::base64url(random_bytes(32));
        Security::startSession();
        if (empty($_SESSION['platform_oidc_session'])) $_SESSION['platform_oidc_session'] = bin2hex(random_bytes(24));
        Database::connection()->prepare('INSERT INTO platform_oidc_codes(code_hash,client_id,subject,auth_version,redirect_uri,challenge,nonce,scope,auth_time,session_key,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([hash('sha256',$code),$client['client_id'],$fresh['subject'],$fresh['version'],$input['redirect_uri'],$input['code_challenge'],$input['nonce'],$input['scope'],$authTime,$_SESSION['platform_oidc_session'],time()+60]);
        return $input['redirect_uri'] . (str_contains($input['redirect_uri'],'?') ? '&' : '?')
            . http_build_query(['code'=>$code,'state'=>$input['state'],'iss'=>self::issuer()],'','&',PHP_QUERY_RFC3986);
    }

    public static function exchange(array $client, array $input): array
    {
        if (($input['grant_type'] ?? '') !== 'authorization_code') throw new \RuntimeException('unsupported_grant_type');
        $code = (string)($input['code'] ?? '');
        $verifier = (string)($input['code_verifier'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$code) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$verifier)) throw new \RuntimeException('invalid_grant');
        $db = Database::connection();
        // UPDATE..RETURNING both consumes and checks the proof atomically.
        $q = $db->prepare('UPDATE platform_oidc_codes SET consumed_at=? WHERE code_hash=? AND client_id=? AND redirect_uri=? AND challenge=? AND consumed_at IS NULL AND expires_at>? RETURNING *');
        $q->execute([time(),hash('sha256',$code),$client['client_id'],(string)($input['redirect_uri'] ?? ''),self::base64url(hash('sha256',$verifier,true)),time()]);
        $grant = $q->fetch();
        $q->closeCursor();
        if (!$grant) throw new \RuntimeException('invalid_grant');
        $identity = Identity::find($grant['subject']);
        if (!$identity || !hash_equals($identity['version'],$grant['auth_version']) || !Identity::allows($identity,$client['product'],(int)$client['organisation_id'])) throw new \RuntimeException('invalid_grant');
        $token = self::base64url(random_bytes(32));
        $expires = min(time()+43200, (int)$grant['auth_time'] + (int)Config::get('session_absolute_seconds',43200));
        if ($expires <= time()) throw new \RuntimeException('invalid_grant');
        $claims = self::claims($identity,$client,$grant['scope']) + [
            'iss'=>self::issuer(),'aud'=>$client['client_id'],'iat'=>time(),'exp'=>time()+300,
            'nonce'=>$grant['nonce'],'auth_time'=>(int)$grant['auth_time'],
            'at_hash'=>self::base64url(substr(hash('sha256',$token,true),0,16)),
        ];
        $idToken = self::sign($claims);
        $db->prepare('INSERT INTO platform_oidc_tokens(token_hash,client_id,subject,auth_version,scope,session_key,expires_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([hash('sha256',$token),$client['client_id'],$identity['subject'],$identity['version'],$grant['scope'],$grant['session_key'],$expires]);
        return ['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>$expires-time(),'id_token'=>$idToken,'scope'=>$grant['scope']];
    }

    public static function userinfo(string $token): array
    {
        $context = self::tokenContext($token);
        return self::claims($context['identity'],$context['client'],$context['scope']);
    }

    /** Backchannel APIs use the token's actual client/org, never a request-supplied org. */
    public static function tokenContext(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)) throw new \RuntimeException('invalid_token');
        $q = Database::connection()->prepare('SELECT * FROM platform_oidc_tokens WHERE token_hash=? AND expires_at>? AND revoked_at IS NULL');
        $q->execute([hash('sha256',$token),time()]);
        $row = $q->fetch();
        if (!$row) throw new \RuntimeException('invalid_token');
        $identity = Identity::find($row['subject']);
        $client = self::client($row['client_id']);
        if (!$identity || !hash_equals($identity['version'],$row['auth_version']) || !Identity::allows($identity,$client['product'],(int)$client['organisation_id'])) throw new \RuntimeException('invalid_token');
        return ['identity'=>$identity,'client'=>$client,'scope'=>$row['scope']];
    }

    private static function claims(array $identity, array $client, string $scope): array
    {
        $claims = ['sub'=>$identity['subject'],'role'=>$identity['role'],'organisation_id'=>(string)$client['organisation_id'],
            'products'=>array_values(array_filter(['learning','assessment'],fn($p)=>Identity::allows($identity,$p,(int)$client['organisation_id'])))];
        if($identity['kind']==='teacher') $claims['class_access']=DirectoryBridge::access($identity,(int)$client['organisation_id']);
        if (in_array('profile',explode(' ',$scope),true)) {
            $claims['name'] = $identity['display_name'];
            $claims['preferred_username'] = $identity['username'];
        }
        return $claims;
    }

    public static function revoke(array $client, string $token): void
    {
        Database::connection()->prepare('UPDATE platform_oidc_tokens SET revoked_at=? WHERE token_hash=? AND client_id=?')
            ->execute([time(),hash('sha256',$token),$client['client_id']]);
    }

    public static function revokeBrowserSession(): void
    {
        $sessionKey = (string)($_SESSION['platform_oidc_session'] ?? '');
        if ($sessionKey === '') return;
        $db = Database::connection();
        $db->prepare('UPDATE platform_oidc_tokens SET revoked_at=? WHERE session_key=? AND revoked_at IS NULL')->execute([time(),$sessionKey]);
        $db->prepare('UPDATE platform_oidc_codes SET consumed_at=? WHERE session_key=? AND consumed_at IS NULL')->execute([time(),$sessionKey]);
    }

    public static function provisionSigningKey(array $actor): void
    {
        Identity::assertAdmin($actor);
        if (Vault::has('system',0,'oidc_signing_private')) return;
        $key = openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key,$pem)) throw new \RuntimeException('Der Signaturschlüssel konnte nicht erstellt werden.');
        Vault::put('system',0,'oidc_signing_private',$pem);
        Audit::record((int)$actor['id'],'oidc.key_created','system','oidc');
    }

    private static function signingKey(): \OpenSSLAsymmetricKey
    {
        $pem = Vault::get('system',0,'oidc_signing_private');
        $key = is_string($pem) ? openssl_pkey_get_private($pem) : false;
        if ($key === false) throw new \RuntimeException('server_not_configured');
        return $key;
    }

    public static function jwks(): array
    {
        $details = openssl_pkey_get_details(self::signingKey());
        if (!$details || !isset($details['rsa'])) throw new \RuntimeException('server_not_configured');
        return ['keys'=>[['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>substr(hash('sha256',$details['key']),0,24),
            'n'=>self::base64url($details['rsa']['n']),'e'=>self::base64url($details['rsa']['e'])]]];
    }

    private static function sign(array $claims): string
    {
        $key = self::jwks()['keys'][0];
        $body = self::base64url(json_encode(['alg'=>'RS256','typ'=>'JWT','kid'=>$key['kid']],JSON_THROW_ON_ERROR)) . '.'
            . self::base64url(json_encode($claims,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        if (!openssl_sign($body,$signature,self::signingKey(),OPENSSL_ALGO_SHA256)) throw new \RuntimeException('server_error');
        return $body . '.' . self::base64url($signature);
    }
}
