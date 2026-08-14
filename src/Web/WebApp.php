<?php
namespace holastack\Web;

use holastack\DB\Database;
use holastack\Region\Region;
use holastack\Auth\Auth;

/**
 * Web 管理后台业务逻辑（设备 / 应用 / 网关 / 上行 / 下行 / 用户 / 密码）。
 * 所有方法返回数组，由 public/index.php 决定输出 JSON 或 HTML。
 */
class WebApp
{
    /** 网关心跳超时秒数（参照 ChirpStack：超过此时间无心跳则判离线）。 */
    const GW_OFFLINE_TIMEOUT = 300;
    /** 设备离线超时秒数（无上行/入网记录超过此时间则判离线）。 */
    const DEV_OFFLINE_TIMEOUT = 600;
    public static function listApplications(): array
    {
        return Database::fetchAll("SELECT * FROM applications ORDER BY id DESC");
    }

    public static function createApplication(array $p): array
    {
        if (empty($p['name'])) {
            return ['error' => 'name required'];
        }
        // 若未填写 AppEUI 则随机生成（16 hex）
        $appEui = trim($p['app_eui'] ?? '');
        if ($appEui === '') {
            $appEui = bin2hex(random_bytes(8));
        }
        $callbackUrl = trim($p['callback_url'] ?? '');
        Database::execute(
            "INSERT INTO applications (name, description, app_eui, callback_url, created_at) VALUES (?,?,?,?,?)",
            [$p['name'], $p['description'] ?? '', $appEui, $callbackUrl, time()]
        );
        return ['id' => Database::lastInsertId(), 'app_eui' => $appEui];
    }

    public static function listDevices(?int $appId = null): array
    {
        if ($appId) {
            $rows = Database::fetchAll("SELECT * FROM devices WHERE app_id=? ORDER BY id DESC", [$appId]);
        } else {
            $rows = Database::fetchAll("SELECT * FROM devices ORDER BY id DESC");
        }
        $devTimeout = time() - self::DEV_OFFLINE_TIMEOUT;
        foreach ($rows as &$d) {
            $lastSeen = max((int)($d['last_seen'] ?? 0), (int)($d['created_at'] ?? 0));
            $d['online'] = ($d['status'] === 'active' && $lastSeen >= $devTimeout) ? 'online' : 'offline';
            $d['last_seen_fmt'] = $lastSeen ? date('Y-m-d H:i:s', $lastSeen) : '-';
        }
        unset($d);
        return $rows;
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
        $region = $p['region'] ?? ELW_DEFAULT_REGION;
        if (!in_array(strtoupper($region), Region::supported(), true)) {
            return ['error' => 'unsupported region: ' . $region];
        }
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
        $timeout = time() - self::GW_OFFLINE_TIMEOUT; // 300s 心跳超时
        foreach ($rows as &$g) {
            $g['status'] = ((int) ($g['last_seen'] ?? 0) >= $timeout) ? 'online' : 'offline';
            $g['uplinks'] = (int) ($g['uplinks'] ?? 0);
        }
        unset($g);
        return $rows;
    }

    public static function listUplinks(?int $devId = null, ?int $appId = null, int $limit = 200): array
    {
        $limit = (int) $limit;
        $sql = "SELECT * FROM uplinks";
        $params = [];
        $where = [];
        if ($devId) {
            $where[] = "dev_id=?";
            $params[] = $devId;
        }
        if ($appId) {
            $where[] = "app_id=?";
            $params[] = $appId;
        }
        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY id DESC LIMIT $limit";
        return Database::fetchAll($sql, $params);
    }

    public static function listDownlinks(?int $devId = null, int $limit = 200): array
    {
        $limit = (int) $limit;
        if ($devId) {
            return Database::fetchAll("SELECT * FROM downlinks WHERE dev_id=? ORDER BY id DESC LIMIT $limit", [$devId]);
        }
        return Database::fetchAll("SELECT * FROM downlinks ORDER BY id DESC LIMIT $limit");
    }

    public static function listEvents(?int $devId = null, ?string $gwId = null, int $limit = 200): array
    {
        $limit = (int) $limit;
        $sql = "SELECT * FROM events";
        $params = [];
        $where = [];
        if ($devId) {
            $where[] = "dev_id=?";
            $params[] = $devId;
        }
        if ($gwId) {
            $where[] = "gateway_id=?";
            $params[] = $gwId;
        }
        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY id DESC LIMIT $limit";
        return Database::fetchAll($sql, $params);
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
            "UPDATE applications SET name=?, description=?, app_eui=?, callback_url=? WHERE id=?",
            [$p['name'], $p['description'] ?? '', $p['app_eui'] ?? '', trim($p['callback_url'] ?? ''), $id]
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
        $setParts = ['name=?', 'class=?', 'region=?'];
        $params = [$name, $class, $region];

        if ($device['activation'] === 'ABP') {
            $devAddr = strtolower(preg_replace('/[^0-9a-f]/', '', $p['dev_addr'] ?? $device['dev_addr']));
            $nwk = strtolower(preg_replace('/[^0-9a-f]/', '', $p['nwk_s_key'] ?? $device['nwk_s_key']));
            $app = strtolower(preg_replace('/[^0-9a-f]/', '', $p['app_s_key'] ?? $device['app_s_key']));
            if (strlen($devAddr) !== 8 || strlen($nwk) !== 32 || strlen($app) !== 32) {
                return ['error' => 'ABP requires dev_addr(8), nwk_s_key(32), app_s_key(32) hex'];
            }
            $setParts[] = 'dev_addr=?';
            $setParts[] = 'nwk_s_key=?';
            $setParts[] = 'app_s_key=?';
            $params[] = $devAddr;
            $params[] = $nwk;
            $params[] = $app;
        } elseif (!empty($p['app_key'])) {
            $appKey = strtolower(preg_replace('/[^0-9a-f]/', '', $p['app_key']));
            if (strlen($appKey) !== 32) {
                return ['error' => 'app_key must be 32 hex chars'];
            }
            $setParts[] = 'app_key=?';
            $params[] = $appKey;
        }

        // OTAA 设备也允许编辑 DevEUI/JoinEUI
        if ($device['activation'] === 'OTAA') {
            if (!empty($p['dev_eui'])) {
                $devEui = strtolower(preg_replace('/[^0-9a-f]/', '', $p['dev_eui']));
                if (strlen($devEui) === 16) {
                    $setParts[] = 'dev_eui=?';
                    $params[] = $devEui;
                }
            }
            if (!empty($p['join_eui'])) {
                $joinEui = strtolower(preg_replace('/[^0-9a-f]/', '', $p['join_eui']));
                if (strlen($joinEui) === 16) {
                    $setParts[] = 'join_eui=?';
                    $params[] = $joinEui;
                }
            }
        }

        $params[] = $id;
        $sql = "UPDATE devices SET " . implode(', ', $setParts) . " WHERE id=?";
        Database::execute($sql, $params);
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
        $region = strtoupper($p['region'] ?? '');
        if ($region && !in_array($region, Region::supported(), true)) {
            return ['error' => 'unsupported region'];
        }
        Database::execute(
            "INSERT INTO gateways (gw_id, name, region, created_at, last_seen, ip) VALUES (?,?,?,?,?,?)",
            [$gwId, $p['name'], $region, time(), 0, '']
        );
        return ['gw_id' => $gwId];
    }

    public static function updateGateway(string $gwId, array $p): array
    {
        $region = strtoupper($p['region'] ?? '');
        if ($region && !in_array($region, Region::supported(), true)) {
            return ['error' => 'unsupported region'];
        }
        Database::execute(
            "UPDATE gateways SET name=?, region=? WHERE gw_id=?",
            [$p['name'] ?? '', $region, $gwId]
        );
        return ['gw_id' => $gwId];
    }

    public static function deleteGateway(string $gwId): array
    {
        Database::execute("DELETE FROM gateways WHERE gw_id=?", [$gwId]);
        return ['ok' => true];
    }

    // ---------------- 用户管理 & 密码管理 ----------------

    public static function listUsers(): array
    {
        $cur = Auth::currentUser();
        if (!$cur) return [];
        // admin 可查看所有用户；普通用户仅返回自己
        if ($cur['role'] === Auth::ROLE_ADMIN) {
            return Database::fetchAll("SELECT id, username, role, created_at FROM users ORDER BY id DESC");
        }
        return [['id' => $cur['id'], 'username' => $cur['username'], 'role' => $cur['role'], 'created_at' => 0]];
    }

    public static function changePassword(int $targetUserId, string $newPassword): array
    {
        if (strlen($newPassword) < 6) {
            return ['error' => 'password must be at least 6 characters'];
        }
        $cur = Auth::currentUser();
        if (!$cur) {
            return ['error' => 'not authenticated'];
        }
        // admin 可以修改任何人的密码；普通用户只能改自己的
        if ($cur['role'] !== Auth::ROLE_ADMIN && (int)$cur['id'] !== $targetUserId) {
            return ['error' => 'forbidden: can only change own password'];
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        Database::execute("UPDATE users SET password_hash=? WHERE id=?", [$hash, $targetUserId]);
        // 修改密码后清除该用户所有令牌，强制重新登录
        Database::execute("DELETE FROM auth_tokens WHERE user_id=?", [$targetUserId]);
        return ['ok' => true];
    }

    public static function deleteUser(int $id): array
    {
        $cur = Auth::currentUser();
        if (!$cur) {
            return ['error' => 'not authenticated'];
        }
        // 不允许删除自己
        if ((int)$cur['id'] === $id) {
            return ['error' => 'cannot delete self'];
        }
        // 仅 admin 可删除用户
        if ($cur['role'] !== Auth::ROLE_ADMIN) {
            return ['error' => 'forbidden'];
        }
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
        $gwsOnline = Database::fetch("SELECT COUNT(*) c FROM gateways WHERE last_seen >= ?", [time() - self::GW_OFFLINE_TIMEOUT])['c'];
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
