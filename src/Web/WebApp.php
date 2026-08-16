<?php
namespace holastack\Web;

use holastack\DB\Database;
use holastack\Region\Region;
use holastack\Auth\Auth;
use holastack\Storage\DeviceProfile;
use holastack\Storage\Tenant;
use holastack\Auth\ApiKey;
use holastack\Integration\Integration;
use holastack\Core\Multicast;
use holastack\Core\LoRaWANVersion;

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
        $dpId = (int) ($p['device_profile_id'] ?? 0);
        $dp = DeviceProfile::getOrDefault($dpId);
        $macVersion = LoRaWANVersion::value($dp['mac_version'] ?? '1.0.3');
        if ($activation === 'OTAA') {
            $appKey = strtolower(preg_replace('/[^0-9a-f]/', '', $p['app_key'] ?? ''));
            if (strlen($appKey) !== 32) {
                return ['error' => 'app_key must be 32 hex chars'];
            }
            $joinEui = strtolower(preg_replace('/[^0-9a-f]/', '', $p['join_eui'] ?? ''));
            if (strlen($joinEui) !== 16) {
                return ['error' => 'join_eui must be 16 hex chars'];
            }
            // 1.1 设备需要 NwkKey（与 AppKey 分离）；缺省回退到 AppKey，保证 1.0.x 存量兼容
            $nwkKey = strtolower(preg_replace('/[^0-9a-f]/', '', $p['nwk_key'] ?? $appKey));
            Database::execute(
                "INSERT INTO devices (app_id, name, dev_eui, join_eui, activation, app_key, nwk_key, region, class, device_profile_id, mac_version, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$appId, $p['name'], $devEui, $joinEui, 'OTAA', $appKey, $nwkKey, $p['region'] ?? ELW_DEFAULT_REGION, $class, $dpId, $macVersion, 'pending', time()]
            );
        } else { // ABP
            $devAddr = strtolower(preg_replace('/[^0-9a-f]/', '', $p['dev_addr'] ?? ''));
            $nwk = strtolower(preg_replace('/[^0-9a-f]/', '', $p['nwk_s_key'] ?? ''));
            $app = strtolower(preg_replace('/[^0-9a-f]/', '', $p['app_s_key'] ?? ''));
            if (strlen($devAddr) !== 8 || strlen($nwk) !== 32 || strlen($app) !== 32) {
                return ['error' => 'ABP requires dev_addr(8), nwk_s_key(32), app_s_key(32) hex'];
            }
            Database::execute(
                "INSERT INTO devices (app_id, name, dev_eui, dev_addr, activation, nwk_s_key, app_s_key, region, class, device_profile_id, mac_version, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$appId, $p['name'], $devEui, $devAddr, 'ABP', $nwk, $app, $p['region'] ?? ELW_DEFAULT_REGION, $class, $dpId, $macVersion, 'active', time()]
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

    public static function listDownlinks(?int $devId = null, ?int $appId = null, int $limit = 200): array
    {
        $limit = (int) $limit;
        $sql = "SELECT * FROM downlinks";
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
        if (array_key_exists('device_profile_id', $p)) {
            $setParts[] = 'device_profile_id=?';
            $params[] = (int) $p['device_profile_id'];
            // 设备模板决定 MAC 版本（1.0.x / 1.1），切换模板时同步更新 mac_version
            $dp = DeviceProfile::getOrDefault((int) $p['device_profile_id']);
            $setParts[] = 'mac_version=?';
            $params[] = LoRaWANVersion::value($dp['mac_version'] ?? '1.0.3');
        }

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
        // 设备健康分布：在线 = 已激活且在离线超时窗口内最近上报；其余算离线（含未激活/pending）
        $devsOnline = Database::fetch(
            "SELECT COUNT(*) c FROM devices WHERE status='active' AND last_seen >= ?",
            [time() - self::DEV_OFFLINE_TIMEOUT]
        )['c'];
        $devsOffline = max(0, (int)$devs - (int)$devsOnline);
        // 最近 5 条设备/网关日志（events 表：dev_id>0 为设备事件，gateway_id!='' 为网关事件）
        $deviceLogs = Database::fetchAll(
            "SELECT id, type, level, dev_id, message, created_at FROM events WHERE dev_id > 0 ORDER BY id DESC LIMIT 5"
        );
        $gatewayLogs = Database::fetchAll(
            "SELECT id, type, level, gateway_id, message, created_at FROM events WHERE gateway_id != '' ORDER BY id DESC LIMIT 5"
        );
        $gwsOffline = max(0, (int)$gws - (int)$gwsOnline);
        return [
            'applications' => $apps, 'devices' => $devs, 'gateways' => $gws,
            'gateways_online' => (int) $gwsOnline, 'gateways_offline' => $gwsOffline,
            'uplinks' => $ups, 'downlinks' => $dls,
            'devices_online' => (int)$devsOnline, 'devices_offline' => $devsOffline,
            'device_logs' => $deviceLogs, 'gateway_logs' => $gatewayLogs,
        ];
    }

    public static function regions(): array
    {
        return Region::supported();
    }

    // ---------------- 设备配置模板（Device Profile） ----------------

    public static function listDeviceProfiles(): array
    {
        return DeviceProfile::list();
    }
    public static function getDeviceProfile(int $id): ?array
    {
        return DeviceProfile::get($id);
    }
    public static function createDeviceProfile(array $p): array
    {
        return DeviceProfile::create($p);
    }
    public static function updateDeviceProfile(int $id, array $p): array
    {
        return DeviceProfile::update($id, $p);
    }
    public static function deleteDeviceProfile(int $id): array
    {
        return DeviceProfile::delete($id);
    }

    // ---------------- 租户（Tenant，多租户隔离基础） ----------------

    public static function listTenants(): array
    {
        return Tenant::list();
    }
    public static function createTenant(array $p): array
    {
        return Tenant::create($p);
    }
    public static function updateTenant(int $id, array $p): array
    {
        return Tenant::update($id, $p);
    }
    public static function deleteTenant(int $id): array
    {
        return Tenant::delete($id);
    }

    // ---------------- 应用级 API Key ----------------

    public static function listApiKeys(int $applicationId): array
    {
        return ApiKey::list($applicationId);
    }
    public static function createApiKey(int $applicationId, array $p): array
    {
        return ApiKey::create($applicationId, $p['name'] ?? '');
    }
    public static function deleteApiKey(int $id): array
    {
        return ApiKey::delete($id);
    }

    // ---------------- 集成（Integrations） ----------------

    public static function listIntegrations(int $applicationId): array
    {
        return Integration::list($applicationId);
    }
    public static function createIntegration(array $p): array
    {
        return Integration::create($p);
    }
    public static function updateIntegration(int $id, array $p): array
    {
        return Integration::update($id, $p);
    }
    public static function deleteIntegration(int $id): array
    {
        return Integration::delete($id);
    }

    // ---------------- 组播组（Multicast Group） ----------------

    public static function listMulticastGroups(?int $appId = null): array
    {
        if ($appId) {
            return Database::fetchAll("SELECT * FROM multicast_groups WHERE application_id=? ORDER BY id DESC", [$appId]);
        }
        return Database::fetchAll("SELECT * FROM multicast_groups ORDER BY id DESC");
    }
    public static function getMulticastGroup(int $id): ?array
    {
        return Database::fetch("SELECT * FROM multicast_groups WHERE id=?", [$id]);
    }
    public static function createMulticastGroup(array $p): array
    {
        $appId = (int) ($p['application_id'] ?? 0);
        if ($appId <= 0) {
            return ['error' => 'application_id required'];
        }
        $region = $p['region'] ?? ELW_DEFAULT_REGION;
        if (!in_array(strtoupper($region), Region::supported(), true)) {
            return ['error' => 'unsupported region: ' . $region];
        }
        $sess = Multicast::generateSession();
        $mcAddr = strtolower(preg_replace('/[^0-9a-f]/', '', $p['mc_addr'] ?? $sess['mc_addr']));
        $mcNwk = strtolower(preg_replace('/[^0-9a-f]/', '', $p['mc_nwk_s_key'] ?? $sess['mc_nwk_s_key']));
        $mcApp = strtolower(preg_replace('/[^0-9a-f]/', '', $p['mc_app_s_key'] ?? $sess['mc_app_s_key']));
        if (strlen($mcAddr) !== 8 || strlen($mcNwk) !== 32 || strlen($mcApp) !== 32) {
            return ['error' => 'multicast keys must be mc_addr(8)/mc_nwk_s_key(32)/mc_app_s_key(32) hex'];
        }
        $type = strtoupper($p['group_type'] ?? 'C');
        if (!in_array($type, ['A', 'B', 'C'], true)) {
            $type = 'C';
        }
        Database::execute(
            "INSERT INTO multicast_groups (name, application_id, region, group_type, mc_addr, mc_nwk_s_key, mc_app_s_key, f_cnt, dr, frequency, class_b_ping_slot_periodicity, class_c_scheduling_type, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $p['name'] ?? 'Multicast', $appId, $region, $type, $mcAddr, $mcNwk, $mcApp,
                0, (int) ($p['dr'] ?? 0), (int) ($p['frequency'] ?? 0),
                (int) ($p['class_b_ping_slot_periodicity'] ?? 0),
                $p['class_c_scheduling_type'] ?? 'DELAY', time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }
    public static function updateMulticastGroup(int $id, array $p): array
    {
        $g = self::getMulticastGroup($id);
        if (!$g) {
            return ['error' => 'group not found'];
        }
        $set = [];
        $params = [];
        foreach (['name', 'region', 'group_type', 'dr', 'frequency', 'class_b_ping_slot_periodicity', 'class_c_scheduling_type'] as $c) {
            if (array_key_exists($c, $p)) {
                $set[] = "$c=?";
                $params[] = $p[$c];
            }
        }
        if (empty($set)) {
            return ['id' => $id];
        }
        $params[] = $id;
        Database::execute("UPDATE multicast_groups SET " . implode(',', $set) . " WHERE id=?", $params);
        return ['id' => $id];
    }
    public static function deleteMulticastGroup(int $id): array
    {
        Database::execute("DELETE FROM multicast_group_devices WHERE multicast_group_id=?", [$id]);
        Database::execute("DELETE FROM multicast_group_gateways WHERE multicast_group_id=?", [$id]);
        Database::execute("DELETE FROM multicast_queue WHERE multicast_group_id=?", [$id]);
        Database::execute("DELETE FROM multicast_groups WHERE id=?", [$id]);
        return ['ok' => true];
    }
    public static function enqueueMulticast(int $groupId, int $port, string $payloadHex, int $expiresAt = 0): array
    {
        $g = self::getMulticastGroup($groupId);
        if (!$g) {
            return ['error' => 'group not found'];
        }
        if ($port < 1 || $port > 223) {
            return ['error' => 'port must be 1..223'];
        }
        if (!ctype_xdigit($payloadHex) || strlen($payloadHex) % 2 !== 0) {
            return ['error' => 'payload must be even-length hex'];
        }
        Database::execute(
            "INSERT INTO multicast_queue (multicast_group_id, f_port, payload_hex, f_cnt, created_at, expires_at) VALUES (?,?,?,?,?,?)",
            [$groupId, $port, strtolower($payloadHex), (int) $g['f_cnt'], time(), $expiresAt]
        );
        return ['id' => Database::lastInsertId(), 'status' => 'queued'];
    }
    public static function multicastDevices(int $groupId): array
    {
        return Multicast::groupDevices($groupId);
    }
    public static function multicastGateways(int $groupId): array
    {
        return Multicast::groupGateways($groupId);
    }
    public static function addMulticastDevice(int $groupId, string $devEui): array
    {
        Multicast::addDevice($groupId, $devEui);
        return ['ok' => true];
    }
    public static function removeMulticastDevice(int $groupId, string $devEui): array
    {
        Multicast::removeDevice($groupId, $devEui);
        return ['ok' => true];
    }
    public static function addMulticastGateway(int $groupId, string $gwId): array
    {
        Multicast::addGateway($groupId, $gwId);
        return ['ok' => true];
    }
    public static function removeMulticastGateway(int $groupId, string $gwId): array
    {
        Multicast::removeGateway($groupId, $gwId);
        return ['ok' => true];
    }
}
