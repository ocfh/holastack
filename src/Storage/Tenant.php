<?php






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
        

        

        

        

        $unlimited = !empty($p['private_gateways_unlimited']) ? 1 : 0;
        $limit = (int) ($p['private_gateways_limit'] ?? 0);
        if ($unlimited) {
            $limit = 0;
        }
        Database::execute(
            "INSERT INTO tenants (name, description, private_gateways_limit, private_gateways_unlimited, created_at)
             VALUES (?,?,?,?,?)",
            [
                $p['name'],
                $p['description'] ?? '',
                $limit,
                $unlimited,
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
        foreach (['name', 'description'] as $c) {
            if (array_key_exists($c, $p)) {
                $set[] = "$c=?";
                $params[] = is_numeric($p[$c]) ? (int) $p[$c] : $p[$c];
            }
        }
        if (array_key_exists('private_gateways_limit', $p)) {
            $set[] = 'private_gateways_limit=?';
            $params[] = max(0, (int) $p['private_gateways_limit']);
        }
        if (array_key_exists('private_gateways_unlimited', $p)) {
            $set[] = 'private_gateways_unlimited=?';
            $params[] = !empty($p['private_gateways_unlimited']) ? 1 : 0;
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
