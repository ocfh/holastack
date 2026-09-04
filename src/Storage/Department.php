<?php
namespace holastack\Storage;

use holastack\DB\Database;

/**
 * 部门/组织架构：支持父子层级，按租户隔离。用户可挂载到部门，
 * 便于按组织维度管理设备与成员。
 */
class Department
{
    public static function list(?int $tenantId = null): array
    {
        if ($tenantId !== null) {
            return Database::fetchAll("SELECT * FROM departments WHERE tenant_id=? ORDER BY parent_id ASC, id ASC", [$tenantId]);
        }
        return Database::fetchAll("SELECT * FROM departments ORDER BY parent_id ASC, id ASC");
    }

    public static function get(int $id): ?array
    {
        return Database::fetch("SELECT * FROM departments WHERE id=?", [$id]);
    }

    public static function create(array $p): array
    {
        $norm = self::normalize($p);
        if (isset($norm['error'])) {
            return $norm;
        }
        Database::execute(
            "INSERT INTO departments (tenant_id, name, parent_id, description, created_at) VALUES (?,?,?,?,?)",
            [$norm['tenant_id'], $norm['name'], $norm['parent_id'], $norm['description'], time()]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function update(int $id, array $p): array
    {
        $m = self::get($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        $name = trim((string) ($p['name'] ?? $m['name']));
        if ($name === '') {
            return ['error' => 'name_required'];
        }
        $parentId = (int) ($p['parent_id'] ?? $m['parent_id']);
        if ($parentId === $id) {
            return ['error' => 'parent_self'];
        }
        Database::execute(
            "UPDATE departments SET name=?, parent_id=?, description=? WHERE id=?",
            [$name, $parentId, (string) ($p['description'] ?? $m['description'] ?? ''), $id]
        );
        return ['id' => $id];
    }

    public static function delete(int $id): array
    {
        $m = self::get($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        $children = Database::fetchOne("SELECT COUNT(*) FROM departments WHERE parent_id=?", [$id]);
        if ((int) $children > 0) {
            return ['error' => 'has_children'];
        }
        $users = Database::fetchOne("SELECT COUNT(*) FROM users WHERE department_id=?", [$id]);
        if ((int) $users > 0) {
            return ['error' => 'department_in_use'];
        }
        Database::execute("DELETE FROM departments WHERE id=?", [$id]);
        return ['id' => $id];
    }

    /** 构建树状结构。 */
    public static function tree(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int) ($r['parent_id'] ?? 0)][] = $r + ['children' => []];
        }
        $walk = function (int $parent) use (&$walk, &$byParent): array {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $node) {
                $node['children'] = $walk((int) $node['id']);
                $out[] = $node;
            }
            return $out;
        };
        return $walk(0);
    }

    private static function normalize(array $p): array
    {
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'name_required'];
        }
        return [
            'name'        => mb_substr($name, 0, 128),
            'description' => mb_substr((string) ($p['description'] ?? ''), 0, 255),
            'parent_id'   => (int) ($p['parent_id'] ?? 0),
            'tenant_id'   => (int) ($p['tenant_id'] ?? 0),
        ];
    }
}