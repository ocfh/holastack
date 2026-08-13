<?php
namespace holastack\Web;

use holastack\DB\Database;
use holastack\Region\Region;

/**
 * Web 管理后台业务逻辑（设备 / 应用 / 网关 / 上行 / 下行）。
 * 所有方法返回数组，由 public/index.php 决定输出 JSON 或 HTML。
 */
class WebApp
{
    public static function listApplications(): array
    {
        return Database::fetchAll("SELECT * FROM applications ORDER BY id DESC");
    }

    public static function createApplication(array $p): array
    {
        if (empty($p['name'])) {
            return ['error' => 'name required'];
        }
        Database::execute(
            "INSERT INTO applications (name, description, app_eui, created_at) VALUES (?,?,?,?)",
            [$p['name'], $p['description'] ?? '', $p['app_eui'] ?? '', time()]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function listDevices(?int $appId = null): array
    {
        if ($appId) {
            return Database::fetchAll("SELECT * FROM devices WHERE app_id=? ORDER BY id DESC", [$appId]);
        }
        return Database::fetchAll("SELECT * FROM devices ORDER BY id DESC");
    }

    public static function createDevice(array $p): array
    {
        if (empty($p['name']) || empty($p['dev_eui'])) {
            return ['error' => 'name and dev_eui required'];
        }
        $activation = strtoupper($p['activation'] ?? 'OTAA');
        $devEui = strtolower(preg_replace('/[^0-9a-f]/', '', $p['dev_eui']));
        if (strlen($devEui) !== 16) {
            return ['error' => 'dev_eui must be 16 hex chars'];
        }
        // Class A/B/C（设备工作模式，决定下行调度策略）
        $class = strtoupper($p['class'] ?? 'A');
        if (!in_array($class, ['A', 'B', 'C'], true)) {
            return ['error' => 'class must be A, B or C'];
        }
        $appId = (int) ($p['app_id'] ?? 0);
        if ($activation === 'OTAA') {
            $appKey = strtolower(preg_replace('/[^0-9a-f]/', '', $p['app_key'] ?? ''));
            if (strlen($appKey) !== 32) {
                return ['error' => 'app_key must be 32 hex chars'];
            }
            $joinEui = strtolower(preg_replace('/[^0-9a-f]/', '', $p['join_eui'] ?? ''));
            if (strlen($joinEui) !== 16) {
                return ['error' => 'join_eui must be 16 hex chars'];
            }
            Database::execute(
                "INSERT INTO devices (app_id, name, dev_eui, join_eui, activation, app_key, region, class, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$appId, $p['name'], $devEui, $joinEui, 'OTAA', $appKey, $p['region'] ?? ELW_DEFAULT_REGION, $class, 'pending', time()]
            );
        } else { // ABP
            $devAddr = strtolower(preg_replace('/[^0-9a-f]/', '', $p['dev_addr'] ?? ''));
            $nwk = strtolower(preg_replace('/[^0-9a-f]/', '', $p['nwk_s_key'] ?? ''));
            $app = strtolower(preg_replace('/[^0-9a-f]/', '', $p['app_s_key'] ?? ''));
            if (strlen($devAddr) !== 8 || strlen($nwk) !== 32 || strlen($app) !== 32) {
                return ['error' => 'ABP requires dev_addr(8), nwk_s_key(32), app_s_key(32) hex'];
            }
            Database::execute(
                "INSERT INTO devices (app_id, name, dev_eui, dev_addr, activation, nwk_s_key, app_s_key, region, class, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [$appId, $p['name'], $devEui, $devAddr, 'ABP', $nwk, $app, $p['region'] ?? ELW_DEFAULT_REGION, $class, 'active', time()]
            );
        }
        return ['id' => Database::lastInsertId()];
    }

    public static function listGateways(): array
    {
        $rows = Database::fetchAll(
            "SELECT g.*, (SELECT COUNT(*) FROM uplinks u WHERE u.gateway_id=g.gw_id) AS uplinks
             FROM gateways g ORDER BY g.last_seen DESC"
        );
        $timeout = time() - 600; // 600s 内有过心跳视为在线
        foreach ($rows as &$g) {
            $g['status'] = ((int) ($g['last_seen'] ?? 0) >= $timeout) ? 'online' : 'offline';
            $g['uplinks'] = (int) ($g['uplinks'] ?? 0);
        }
        unset($g);
        return $rows;
    }

    public static function listUplinks(?int $devId = null, int $limit = 200): array
    {
        $limit = (int) $limit;
        if ($devId) {
            return Database::fetchAll("SELECT * FROM uplinks WHERE dev_id=? ORDER BY id DESC LIMIT $limit", [$devId]);
        }
        return Database::fetchAll("SELECT * FROM uplinks ORDER BY id DESC LIMIT $limit");
    }

    public static function listDownlinks(?int $devId = null, int $limit = 200): array
    {
        $limit = (int) $limit;
        if ($devId) {
            return Database::fetchAll("SELECT * FROM downlinks WHERE dev_id=? ORDER BY id DESC LIMIT $limit", [$devId]);
        }
        return Database::fetchAll("SELECT * FROM downlinks ORDER BY id DESC LIMIT $limit");
    }

    public static function listEvents(int $limit = 200): array
    {
        $limit = (int) $limit;
        return Database::fetchAll("SELECT * FROM events ORDER BY id DESC LIMIT $limit");
    }

    public static function enqueueDownlink(int $devId, int $port, string $payloadHex, bool $confirmed): array
    {
        $device = Database::fetch("SELECT * FROM devices WHERE id=?", [$devId]);
        if (!$device) {
            return ['error' => 'device not found'];
        }
        if (!ctype_xdigit($payloadHex)) {
            return ['error' => 'payload must be hex'];
        }
        if (strlen($payloadHex) % 2 !== 0) {
            return ['error' => 'payload hex length must be even'];
        }
        if ($port < 1 || $port > 223) {
            return ['error' => 'port must be 1..223'];
        }
        Database::execute(
            "INSERT INTO downlinks (dev_id, app_id, port, payload_hex, confirmed, status, created_at)
             VALUES (?,?,?,?,?,?,?)",
            [$devId, $device['app_id'], $port, strtolower($payloadHex), $confirmed ? 1 : 0, 'pending', time()]
        );
        return ['id' => Database::lastInsertId(), 'status' => 'pending'];
    }

    // ---------------- 应用 增删改查 ----------------

    public static function getApplication(int $id): ?array
    {
        return Database::fetch("SELECT * FROM applications WHERE id=?", [$id]);
    }

    public static function updateApplication(int $id, array $p): array
    {
        if (empty($p['name'])) {
            return ['error' => 'name required'];
        }
        Database::execute(
            "UPDATE applications SET name=?, description=?, app_eui=? WHERE id=?",
            [$p['name'], $p['description'] ?? '', $p['app_eui'] ?? '', $id]
        );
        return ['id' => $id];
    }

    public static function deleteApplication(int $id): array
    {
        $devIds = Database::fetchAll("SELECT id FROM devices WHERE app_id=?", [$id]);
        if (!empty($devIds)) {
            $ids = implode(',', array_map(fn($d) => (int) $d['id'], $devIds));
            Database::execute("DELETE FROM downlinks WHERE dev_id IN ($ids)");
            Database::execute("DELETE FROM uplinks WHERE dev_id IN ($ids)");
            Database::execute("DELETE FROM devices WHERE app_id=?", [$id]);
        }
        Database::execute("DELETE FROM applications WHERE id=?", [$id]);
        return ['ok' => true];
    }

    // ---------------- 设备 增删改查 ----------------

    public static function getDevice(int $id): ?array
    {
        return Database::fetch("SELECT * FROM devices WHERE id=?", [$id]);
    }

    public static function updateDevice(int $id, array $p): array
    {
        $device = self::getDevice($id);
        if (!$device) {
            return ['error' => 'device not found'];
        }
        $name = $p['name'] ?? $device['name'];
        $class = strtoupper($p['class'] ?? $device['class']);
        if (!in_array($class, ['A', 'B', 'C'], true)) {
            return ['error' => 'class must be A/B/C'];
        }
        $region = $p['region'] ?? $device['region'];
        $params = [$name, $class, $region, $id];
        $set = 'name=?, class=?, region=?';

        if ($device['activation'] === 'ABP') {
            $devAddr = strtolower(preg_replace('/[^0-9a-f]/', '', $p['dev_addr'] ?? $device['dev_addr']));
            $nwk = strtolower(preg_replace('/[^0-9a-f]/', '', $p['nwk_s_key'] ?? $device['nwk_s_key']));
            $app = strtolower(preg_replace('/[^0-9a-f]/', '', $p['app_s_key'] ?? $device['app_s_key']));
            if (strlen($devAddr) !== 8 || strlen($nwk) !== 32 || strlen($app) !== 32) {
                return ['error' => 'ABP requires dev_addr(8), nwk_s_key(32), app_s_key(32) hex'];
            }
            $set .= ', dev_addr=?, nwk_s_key=?, app_s_key=?';
            $params = [$name, $class, $region, $devAddr, $nwk, $app, $id];
        } elseif (!empty($p['app_key'])) {
            $appKey = strtolower(preg_replace('/[^0-9a-f]/', '', $p['app_key']));
            if (strlen($appKey) !== 32) {
                return ['error' => 'app_key must be 32 hex chars'];
            }
            $set .= ', app_key=?';
            $params = [$name, $class, $region, $appKey, $id];
        }
        Database::execute("UPDATE devices SET $set WHERE id=?", $params);
        return ['id' => $id];
    }

    public static function deleteDevice(int $id): array
    {
        Database::execute("DELETE FROM downlinks WHERE dev_id=?", [$id]);
        Database::execute("DELETE FROM uplinks WHERE dev_id=?", [$id]);
        Database::execute("DELETE FROM devices WHERE id=?", [$id]);
        return ['ok' => true];
    }

    // ---------------- 网关 增删改查 ----------------

    public static function getGateway(string $gwId): ?array
    {
        return Database::fetch("SELECT * FROM gateways WHERE gw_id=?", [$gwId]);
    }

    public static function createGateway(array $p): array
    {
        $gwId = strtolower(preg_replace('/[^0-9a-f]/', '', $p['gw_id'] ?? ''));
        if (strlen($gwId) !== 16 && strlen($gwId) !== 32) {
            return ['error' => 'gw_id must be 16 or 32 hex chars'];
        }
        if (empty($p['name'])) {
            return ['error' => 'name required'];
        }
        if (self::getGateway($gwId)) {
            return ['error' => 'gateway already exists'];
        }
        Database::execute(
            "INSERT INTO gateways (gw_id, name, region, created_at, last_seen, ip) VALUES (?,?,?,?,?,?)",
            [$gwId, $p['name'], $p['region'] ?? '', time(), 0, '']
        );
        return ['gw_id' => $gwId];
    }

    public static function updateGateway(string $gwId, array $p): array
    {
        Database::execute(
            "UPDATE gateways SET name=?, region=? WHERE gw_id=?",
            [$p['name'] ?? '', $p['region'] ?? '', $gwId]
        );
        return ['gw_id' => $gwId];
    }

    public static function deleteGateway(string $gwId): array
    {
        Database::execute("DELETE FROM gateways WHERE gw_id=?", [$gwId]);
        return ['ok' => true];
    }

    // ---------------- 用户管理（admin）----------------

    public static function listUsers(): array
    {
        return Database::fetchAll("SELECT id, username, role, created_at FROM users ORDER BY id DESC");
    }

    public static function deleteUser(int $id): array
    {
        Database::execute("DELETE FROM users WHERE id=?", [$id]);
        return ['ok' => true];
    }

    public static function getStats(): array
    {
        $apps = Database::fetch("SELECT COUNT(*) c FROM applications")['c'];
        $devs = Database::fetch("SELECT COUNT(*) c FROM devices")['c'];
        $gws = Database::fetch("SELECT COUNT(*) c FROM gateways")['c'];
        $ups = Database::fetch("SELECT COUNT(*) c FROM uplinks")['c'];
        $dls = Database::fetch("SELECT COUNT(*) c FROM downlinks")['c'];
        $gwsOnline = Database::fetch("SELECT COUNT(*) c FROM gateways WHERE last_seen >= ?", [time() - 600])['c'];
        return [
            'applications' => $apps, 'devices' => $devs, 'gateways' => $gws,
            'gateways_online' => (int) $gwsOnline, 'uplinks' => $ups, 'downlinks' => $dls,
        ];
    }

    public static function regions(): array
    {
        return Region::supported();
    }
}
