<?php
namespace holastack\Auth;

use holastack\DB\Database;

class ApiKey
{
    private static ?string $secret = null;

    public static function secret(): string
    {
        if (self::$secret === null) {
            $f = ELW_ROOT . '/config/secret.php';
            if (is_file($f)) {
                $c = require $f;
                if (is_string($c) && $c !== '') {
                    self::$secret = $c;
                    return self::$secret;
                }
            }
            self::$secret = bin2hex(random_bytes(32));
            $code = "<?php\n// JWT secret (auto-generated; do not commit)\nreturn " . var_export(self::$secret, true) . ";\n";
            @file_put_contents($f, $code, LOCK_EX);
        }
        return self::$secret;
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        $r = strlen($s) % 4;
        if ($r) {
            $s .= str_repeat('=', 4 - $r);
        }
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }

    public static function issueJwt(array $claims): string
    {
        $claims['iat'] = time();
        $claims['iss'] = 'holastack';
        $h = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64url(json_encode($claims, JSON_UNESCAPED_UNICODE));
        $sig = hash_hmac('sha256', "$h.$p", self::secret(), true);
        return "$h.$p." . self::b64url($sig);
    }

    public static function verifyJwt(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $expect = self::b64url(hash_hmac('sha256', "$h.$p", self::secret(), true));
        if (!hash_equals($expect, $s)) {
            return null;
        }
        $claims = json_decode(self::b64urlDecode($p), true);
        if (!is_array($claims)) {
            return null;
        }
        return $claims;
    }

    public static function generateToken(): string
    {
        return self::issueJwt(['typ' => 'apikey', 'jti' => self::newUuid()]);
    }

    public static function newUuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function uuidToId(string $uuid): int
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $uuid);
        if ($hex === '' || strlen($hex) < 12) {
            return 0;
        }
        return (int) hexdec(substr($hex, -12));
    }

    public static function idToUuid(int $id): string
    {
        $hex = str_pad(dechex(max(0, $id)), 12, '0', STR_PAD_LEFT);
        return '00000000-0000-0000-0000-' . $hex;
    }

    public static function create(?int $tenantId, string $name, bool $isAdmin = false, bool $isReadOnly = false): array
    {
        if ($isAdmin && $tenantId !== null && $tenantId > 0) {
            return ['error' => 'tenant_id can not be set with is_admin set to true'];
        }
        if (!$isAdmin && ($tenantId === null || $tenantId <= 0)) {
            return ['error' => 'either is_admin or tenant_id must be set'];
        }
        if (empty($name)) {
            return ['error' => 'name required'];
        }
        $id = self::newUuid();
        $jwtId = $id;
        $token = self::issueJwt(['typ' => 'apikey', 'jti' => $jwtId, 'adm' => $isAdmin, 'tid' => $tenantId ?? 0, 'ro' => $isReadOnly]);
        $hash = password_hash($token, PASSWORD_DEFAULT);
        $now = time();
        Database::execute(
            "INSERT INTO api_keys (uuid, tenant_id, name, api_key, application_id, is_admin, is_read_only, created_at) VALUES (?,?,?,?,?,? ,?,?)",
            [$id, (int) ($tenantId ?? 0), $name, $hash, 0, $isAdmin ? 1 : 0, $isReadOnly ? 1 : 0, $now]
        );
        return ['id' => $id, 'token' => $token, 'name' => $name, 'created_at' => $now];
    }

    public static function legacyCreate(int $applicationId, string $name): array
    {
        if ($applicationId <= 0) {
            return ['error' => 'application_id required'];
        }
        if (empty($name)) {
            return ['error' => 'name required'];
        }
        $token = 'holask-' . bin2hex(random_bytes(20));
        $hash = password_hash($token, PASSWORD_DEFAULT);
        $now = time();
        Database::execute(
            "INSERT INTO api_keys (uuid, tenant_id, name, api_key, application_id, is_admin, is_read_only, created_at) VALUES ('',0,?,?,?,0,0,?)",
            [$name, $hash, $applicationId, $now]
        );
        return ['id' => Database::lastInsertId(), 'token' => $token, 'name' => $name, 'created_at' => $now];
    }

    public static function validate(string $token): array
    {
        if ($token === '' || $token === null) {
            return [];
        }
        if (strpos($token, '.') !== false) {
            $claims = self::verifyJwt($token);
            if ($claims && ($claims['typ'] ?? '') === 'apikey') {
                $row = Database::fetch("SELECT * FROM api_keys WHERE uuid=?", [(string) ($claims['jti'] ?? '')]);
                if ($row) {
                    return [
                        'kind' => 'api_key',
                        'id' => (string) $row['uuid'],
                        'name' => (string) $row['name'],
                        'is_admin' => (bool) $row['is_admin'],
                        'tenant_id' => (int) $row['tenant_id'],
                        'is_read_only' => (bool) ($row['is_read_only'] ?? 0),
                    ];
                }
                return [];
            }
            return [];
        }
        $rows = Database::fetchAll("SELECT id, uuid, name, api_key, application_id, tenant_id, is_admin, is_read_only FROM api_keys");
        foreach ($rows as $r) {
            if (password_verify($token, $r['api_key'])) {
                if ((string) $r['uuid'] !== '') {
                    return [
                        'kind' => 'api_key',
                        'id' => (string) $r['uuid'],
                        'name' => (string) $r['name'],
                        'is_admin' => (bool) $r['is_admin'],
                        'tenant_id' => (int) $r['tenant_id'],
                        'is_read_only' => (bool) ($r['is_read_only'] ?? 0),
                    ];
                }
                return ['kind' => 'legacy_app', 'id' => (string) $r['id'], 'name' => (string) $r['name'], 'is_admin' => false, 'tenant_id' => (int) $r['tenant_id'], 'is_read_only' => false, 'application_id' => (int) $r['application_id']];
            }
        }
        return [];
    }

    public static function validateApplicationToken(string $token): int
    {
        $info = self::validate($token);
        if (($info['kind'] ?? '') === 'legacy_app') {
            return (int) ($info['application_id'] ?? 0);
        }
        return 0;
    }

    public static function tokenFromRequest(): ?string
    {
        foreach (['HTTP_GRPC_METADATA_AUTHORIZATION', 'HTTP_AUTHORIZATION'] as $k) {
            $h = $_SERVER[$k] ?? '';
            if (is_string($h) && preg_match('/Bearer\s+(\S+)/i', $h, $m)) {
                return $m[1];
            }
        }
        if (isset($_GET['api_key']) && is_string($_GET['api_key']) && $_GET['api_key'] !== '') {
            return $_GET['api_key'];
        }
        return null;
    }

    public static function list(?int $tenantId = null, bool $isAdminOnly = false, bool $all = false): array
    {
        $sql = "SELECT id, uuid, tenant_id, name, is_admin, is_read_only, substr(api_key,1,12) AS token_preview, created_at FROM api_keys";
        $w = [];
        $p = [];
        if (!$all) {
            if ($isAdminOnly) {
                $w[] = "is_admin=1";
            } elseif ($tenantId !== null && $tenantId > 0) {
                $w[] = "tenant_id=?";
                $p[] = $tenantId;
            } else {
                $w[] = "application_id=0";
            }
        }
        if ($w) {
            $sql .= " WHERE " . implode(' AND ', $w);
        }
        $sql .= " ORDER BY id DESC";
        return Database::fetchAll($sql, $p);
    }

    public static function legacyList(int $applicationId): array
    {
        return Database::fetchAll(
            "SELECT id, name, application_id, substr(api_key,1,12) AS token_preview, created_at FROM api_keys WHERE application_id=? ORDER BY id DESC",
            [$applicationId]
        );
    }

    public static function delete(string $idOrUuid): array
    {
        if (strpos((string) $idOrUuid, '-') !== false) {
            Database::execute("DELETE FROM api_keys WHERE uuid=?", [(string) $idOrUuid]);
            return ['ok' => true];
        }
        Database::execute("DELETE FROM api_keys WHERE id=?", [(int) $idOrUuid]);
        return ['ok' => true];
    }
}
