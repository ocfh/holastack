<?php
namespace holastack\Storage;

use holastack\DB\Database;
use holastack\Auth\Auth;

/**
 * 角色（RBAC）。系统内置角色(admin/tenant/operator)由 migrate 种子写入，
 * 也可按租户创建自定义角色并勾选权限。角色用于给用户授权并参与前端菜单过滤。
 */
class Role
{
    public static function list(?int $tenantId = null): array
    {
        if ($tenantId !== null) {
            return Database::fetchAll(
                "SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) AS user_count
                 FROM roles r WHERE r.is_system=0 AND r.tenant_id IN (0,?) ORDER BY r.id ASC",
                [$tenantId]
            );
        }
        return Database::fetchAll(
            "SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) AS user_count
             FROM roles r WHERE r.is_system=0 ORDER BY r.id ASC"
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
        Database::execute(
            "INSERT INTO roles (tenant_id, name, description, permissions, is_system, created_at) VALUES (?,?,?,?,0,?)",
            [$norm['tenant_id'], $norm['name'], $norm['description'], json_encode($norm['permissions'], JSON_UNESCAPED_UNICODE), time()]
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
            return ['error' => 'system_role_readonly'];
        }
        $merged = array_merge($m, array_intersect_key($p, array_flip(['name', 'description', 'permissions', 'tenant_id'])));
        $norm = self::normalize($merged, true);
        if (isset($norm['error'])) {
            return $norm;
        }
        Database::execute(
            "UPDATE roles SET name=?, description=?, permissions=?, tenant_id=? WHERE id=?",
            [$norm['name'], $norm['description'], json_encode($norm['permissions'], JSON_UNESCAPED_UNICODE), $norm['tenant_id'], $id]
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
            return ['error' => 'system_role_readonly'];
        }
        $users = Database::fetchOne("SELECT COUNT(*) FROM users WHERE role_id=?", [$id]);
        if ((int) $users > 0) {
            return ['error' => 'role_in_use'];
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
}