<?php
namespace holastack\Auth;

use holastack\DB\Database;

/**
 * 应用级 API Key（对齐 ChirpStack api_key）。
 * 用于第三方以 API Key 而非用户令牌调用应用级接口（REST / MQTT 认证）。
 * 明文仅在创建时返回一次；库内只存 bcrypt 哈希。
 */
class ApiKey
{
    public static function generateToken(): string
    {
        return 'holask-' . bin2hex(random_bytes(20));
    }

    public static function create(int $applicationId, string $name): array
    {
        if ($applicationId <= 0) {
            return ['error' => 'application_id required'];
        }
        if (empty($name)) {
            return ['error' => 'name required'];
        }
        $token = self::generateToken();
        $hash = password_hash($token, PASSWORD_DEFAULT);
        $now = time();
        Database::execute(
            "INSERT INTO api_keys (name, api_key, application_id, created_at) VALUES (?,?,?,?)",
            [$name, $hash, $applicationId, $now]
        );
        return ['id' => Database::lastInsertId(), 'token' => $token, 'name' => $name, 'created_at' => $now];
    }

    public static function validate(string $token): int
    {
        if ($token === '' || $token === null) {
            return 0;
        }
        $rows = Database::fetchAll("SELECT id, api_key, application_id FROM api_keys");
        foreach ($rows as $r) {
            if (password_verify($token, $r['api_key'])) {
                return (int) $r['application_id'];
            }
        }
        return 0;
    }

    public static function tokenFromRequest(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($h) && preg_match('/Bearer\s+(\S+)/i', $h, $m)) {
            return $m[1];
        }
        if (isset($_GET['api_key']) && is_string($_GET['api_key']) && $_GET['api_key'] !== '') {
            return $_GET['api_key'];
        }
        return null;
    }

    public static function list(int $applicationId): array
    {
        return Database::fetchAll(
            "SELECT id, name, application_id, substr(api_key,1,12) AS token_preview, created_at FROM api_keys WHERE application_id=? ORDER BY id DESC",
            [$applicationId]
        );
    }

    public static function delete(int $id): array
    {
        Database::execute("DELETE FROM api_keys WHERE id=?", [$id]);
        return ['ok' => true];
    }
}
