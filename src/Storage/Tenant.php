<?php
/**
 * 租户（Tenant）存储层。
 * 多租户隔离的基础：应用 / 设备 / 网关 / 设备模板 / 组播组 / API Key / 集成 均挂 tenant_id。
 * 当前实现为「软多租户」——同一 NS 进程服务多租户，查询按 tenant_id 过滤。
 */
namespace holastack\Storage;

use holastack\DB\Database;

class Tenant
{
    public static function list(): array
    {
        return Database::fetchAll("SELECT * FROM tenants ORDER BY id DESC");
    }

    public static function get(int $id): ?array
    {
        return Database::fetch("SELECT * FROM tenants WHERE id=?", [$id]);
    }

    public static function create(array $p): array
    {
        if (empty($p['name'])) {
            return ['error' => 'name required'];
        }
        Database::execute(
            "INSERT INTO tenants (name, description, can_have_gateways, private_gateways_limit, created_at)
             VALUES (?,?,?,?,?)",
            [
                $p['name'],
                $p['description'] ?? '',
                (int) ($p['can_have_gateways'] ?? 1),
                (int) ($p['private_gateways_limit'] ?? 0),
                time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function update(int $id, array $p): array
    {
        $t = self::get($id);
        if (!$t) {
            return ['error' => 'tenant not found'];
        }
        $set = [];
        $params = [];
        foreach (['name', 'description', 'can_have_gateways', 'private_gateways_limit'] as $c) {
            if (array_key_exists($c, $p)) {
                $set[] = "$c=?";
                $params[] = is_numeric($p[$c]) ? (int) $p[$c] : $p[$c];
            }
        }
        if (empty($set)) {
            return ['id' => $id];
        }
        $params[] = $id;
        Database::execute("UPDATE tenants SET " . implode(',', $set) . " WHERE id=?", $params);
        return ['id' => $id];
    }

    public static function delete(int $id): array
    {
        // 级联解除：将其下应用/设备/网关的 tenant_id 置 0（回到「默认租户」），不物理删除子资源
        Database::execute("UPDATE applications SET tenant_id=0 WHERE tenant_id=?", [$id]);
        Database::execute("UPDATE devices SET tenant_id=0 WHERE tenant_id=?", [$id]);
        Database::execute("UPDATE gateways SET tenant_id=0 WHERE tenant_id=?", [$id]);
        Database::execute("UPDATE device_profiles SET tenant_id=0 WHERE tenant_id=?", [$id]);
        Database::execute("UPDATE multicast_groups SET tenant_id=0 WHERE tenant_id=?", [$id]);
        Database::execute("UPDATE api_keys SET tenant_id=0 WHERE tenant_id=?", [$id]);
        Database::execute("UPDATE integrations SET tenant_id=0 WHERE tenant_id=?", [$id]);
        Database::execute("DELETE FROM tenants WHERE id=?", [$id]);
        return ['ok' => true];
    }
}
