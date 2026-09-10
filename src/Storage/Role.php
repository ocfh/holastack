<?php
namespace holastack\Storage;

use holastack\DB\Database;
use holastack\Auth\Auth;

class Role
{

    public const QUOTA_FIELDS = ['devices_limit', 'gateways_limit', 'gateways_unlimited'];

    public static function list(?int $tenantId = null): array
    {
        if ($tenantId !== null) {
            return Database::fetchAll(
                "SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) AS user_count
                 FROM roles r WHERE (r.is_system=1 OR (r.is_system=0 AND r.tenant_id IN (0,?))) ORDER BY r.is_system DESC, r.id ASC",
                [$tenantId]
            );
        }
        return Database::fetchAll(
            "SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) AS user_count
             FROM roles r WHERE r.is_system IN (0,1) ORDER BY r.is_system DESC, r.id ASC"
        );
    }

    public static function get(int $id): ?array
    {
        return Database::fetch("SELECT * FROM roles WHERE id=?", [$id]);
    }

    public static function create(array $p): array
    {
        $norm = self::normalize($p);
        if (isset($norm['error'])) {
            return $norm;
        }
        $q = self::quotaValues($p);
        Database::execute(
            "INSERT INTO roles (tenant_id, name, description, permissions, is_system, devices_limit, gateways_limit, gateways_unlimited, created_at) VALUES (?,?,?,?,0,?,?,?,?)",
            [$norm['tenant_id'], $norm['name'], $norm['description'], json_encode($norm['permissions'], JSON_UNESCAPED_UNICODE), $q['devices_limit'], $q['gateways_limit'], $q['gateways_unlimited'], time()]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function update(int $id, array $p): array
    {
        $m = self::get($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        if (!empty($m['is_system'])) {
            return ['error' => '系统内置角色不可修改'];
        }
        $merged = array_merge($m, array_intersect_key($p, array_flip(['name', 'description', 'permissions', 'tenant_id'])));
        $norm = self::normalize($merged, true);
        if (isset($norm['error'])) {
            return $norm;
        }
        $q = self::quotaValues(array_merge($m, $p));
        Database::execute(
            "UPDATE roles SET name=?, description=?, permissions=?, tenant_id=?, devices_limit=?, gateways_limit=?, gateways_unlimited=? WHERE id=?",
            [$norm['name'], $norm['description'], json_encode($norm['permissions'], JSON_UNESCAPED_UNICODE), $norm['tenant_id'], $q['devices_limit'], $q['gateways_limit'], $q['gateways_unlimited'], $id]
        );
        return ['id' => $id];
    }

    public static function delete(int $id): array
    {
        $m = self::get($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        if (!empty($m['is_system'])) {
            return ['error' => '系统内置角色不可删除'];
        }
        $users = (int) Database::fetchOne("SELECT COUNT(*) FROM users WHERE role_id=?", [$id]);
        if ($users > 0) {
            return ['error' => "该角色仍有 {$users} 个用户使用，请先在用户管理中调整这些用户的角色"];
        }
        Database::execute("DELETE FROM roles WHERE id=?", [$id]);
        return ['id' => $id];
    }

    private static function normalize(array $p, bool $isUpdate = false): array
    {
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'name_required'];
        }
        $perm = $p['permissions'] ?? [];
        if (is_string($perm)) {
            $perm = json_decode($perm, true) ?: [];
        }
        $perm = array_values(array_filter((array) $perm, static function ($k) {
            return isset(Auth::PERMISSION_CATALOG[$k]) || $k === '*';
        }));
        if ($perm === []) {
            return ['error' => 'permissions_required'];
        }
        return [
            'name'        => mb_substr($name, 0, 128),
            'description' => mb_substr((string) ($p['description'] ?? ''), 0, 255),
            'permissions' => $perm,
            'tenant_id'   => (int) ($p['tenant_id'] ?? 0),
        ];
    }

    private static function quotaValues(array $p): array
    {
        $unlimited = !empty($p['gateways_unlimited']) ? 1 : 0;
        $gw = max(0, (int) ($p['gateways_limit'] ?? 0));
        if ($unlimited) {
            $gw = 0;
        }
        return [
            'devices_limit'      => max(0, (int) ($p['devices_limit'] ?? 0)),
            'gateways_limit'     => $gw,
            'gateways_unlimited' => $unlimited,
        ];
    }
}
