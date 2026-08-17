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
use holastack\Core\Fuota;
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

    // ================= 权限作用域（三角色体系） =================
    // admin：全局管理 + 可显式按 tenant_id 筛选；tenant：仅本租户数据（可写）；operator：演示（全平台只读 + 模拟日志）

    private static function scope(): array
    {
        $u = Auth::currentUser();
        $role = $u['role'] ?? '';
        return [
            'role' => $role,
            'tenant_id' => (int) ($u['tenant_id'] ?? 0),
            'is_admin' => $role === Auth::ROLE_ADMIN,
            'can_write' => in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_TENANT], true),
            'demo' => $role === Auth::ROLE_OPERATOR,
        ];
    }

    /** 计算生效的租户过滤值；null = 不过滤（全部）。admin 可显式指定，tenant 固定本租户，operator 看全部。 */
    private static function effectiveTenant(?int $explicit = null): ?int
    {
        $s = self::scope();
        if ($s['is_admin']) {
            return ($explicit !== null && $explicit > 0) ? $explicit : null;
        }
        if ($s['demo']) {
            return null;
        }
        return $s['tenant_id'];
    }

    /** 校验当前用户能否访问该资源行（写操作/单资源操作前调用）。tenant 要求归属本租户。 */
    private static function canAccess(array $row, string $tenantCol = 'tenant_id'): bool
    {
        $s = self::scope();
        if ($s['is_admin'] || $s['demo']) {
            return true;
        }
        return (int) ($row[$tenantCol] ?? 0) === $s['tenant_id'];
    }

    /** 创建资源时写入的 tenant_id：admin 可显式指定（body.tenant_id），tenant 固定本租户。 */
    private static function createTenantId(array $p = []): int
    {
        $s = self::scope();
        if ($s['is_admin']) {
            return (int) ($p['tenant_id'] ?? 0);
        }
        return $s['tenant_id'];
    }

    /** 当前作用域内是否可见指定应用（null=全部可见时恒真）。 */
    private static function appInScope(int $appId): bool
    {
        $appIds = self::visibleAppIds();
        return $appIds === null || in_array($appId, $appIds, true);
    }

    /** 当前作用域内可见的应用 ID 列表；null = 全部可见。 */
    private static function visibleAppIds(?int $explicit = null): ?array
    {
        $tid = self::effectiveTenant($explicit);
        if ($tid === null) {
            return null;
        }
        $rows = Database::fetchAll("SELECT id FROM applications WHERE tenant_id=?", [$tid]);
        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    // ================= 演示（operator）模拟数据 =================

    private static function demoDevices(?int $appId = null): array
    {
        $now = time();
        $rows = [
            ['id' => 1, 'name' => '温湿度计-01', 'dev_eui' => '70b3d57e00000001', 'dev_addr' => '01a2b3c4',
             'app_id' => 1, 'application_id' => 1, 'activation' => 'OTAA', 'class' => 'A', 'status' => 'active',
             'created_at' => $now - 86400 * 30, 'last_seen' => $now - 60, 'battery' => 82, 'margin' => 9,
             'latitude' => 23.1291, 'longitude' => 113.2644, 'online' => 'online', 'last_seen_fmt' => date('Y-m-d H:i:s', $now - 60)],
            ['id' => 2, 'name' => '电表-A1', 'dev_eui' => '70b3d57e00000002', 'dev_addr' => '02b3c4d5',
             'app_id' => 2, 'application_id' => 2, 'activation' => 'ABP', 'class' => 'C', 'status' => 'active',
             'created_at' => $now - 86400 * 20, 'last_seen' => $now - 300, 'battery' => 100, 'margin' => 14,
             'latitude' => 0, 'longitude' => 0, 'online' => 'online', 'last_seen_fmt' => date('Y-m-d H:i:s', $now - 300)],
            ['id' => 3, 'name' => '烟感-07', 'dev_eui' => '70b3d57e00000003', 'dev_addr' => '03c4d5e6',
             'app_id' => 1, 'application_id' => 1, 'activation' => 'OTAA', 'class' => 'A', 'status' => 'active',
             'created_at' => $now - 86400 * 10, 'last_seen' => $now - 1800, 'battery' => 55, 'margin' => 5,
             'latitude' => 23.1350, 'longitude' => 113.2700, 'online' => 'online', 'last_seen_fmt' => date('Y-m-d H:i:s', $now - 1800)],
            ['id' => 4, 'name' => '门磁-B2', 'dev_eui' => '70b3d57e00000004', 'dev_addr' => '04d5e6f7',
             'app_id' => 2, 'application_id' => 2, 'activation' => 'ABP', 'class' => 'A', 'status' => 'active',
             'created_at' => $now - 86400 * 5, 'last_seen' => $now - 86400 * 2, 'battery' => 0, 'margin' => '',
             'latitude' => 0, 'longitude' => 0, 'online' => 'offline', 'last_seen_fmt' => date('Y-m-d H:i:s', $now - 86400 * 2)],
        ];
        if ($appId !== null && $appId > 0) {
            $rows = array_values(array_filter($rows, static fn($d) => (int) $d['app_id'] === (int) $appId));
        }
        return $rows;
    }

    private static function demoApplications(): array
    {
        $now = time();
        return [
            ['id' => 1, 'tenant_id' => 0, 'name' => '智能楼宇', 'description' => '楼宇环境监测（演示）',
             'app_eui' => '0101010101010101', 'callback_url' => '', 'created_at' => $now - 86400 * 30],
            ['id' => 2, 'tenant_id' => 0, 'name' => '智慧园区', 'description' => '园区能耗管理（演示）',
             'app_eui' => '0202020202020202', 'callback_url' => '', 'created_at' => $now - 86400 * 20],
            ['id' => 3, 'tenant_id' => 0, 'name' => '仓库安防', 'description' => '仓库环境与门禁（演示）',
             'app_eui' => '0303030303030303', 'callback_url' => '', 'created_at' => $now - 86400 * 10],
        ];
    }

    private static function demoGateways(): array
    {
        $now = time();
        return [
            ['gw_id' => '0080000000000001', 'name' => '楼栋A-网关', 'region' => 'EU868',
             'status' => 'online', 'uplinks' => mt_rand(800, 2000), 'last_seen' => $now - 30, 'ip' => '192.168.1.11'],
            ['gw_id' => '0080000000000002', 'name' => '园区B-网关', 'region' => 'EU868',
             'status' => 'online', 'uplinks' => mt_rand(600, 1500), 'last_seen' => $now - 120, 'ip' => '192.168.1.12'],
            ['gw_id' => '0080000000000003', 'name' => '仓库C-网关', 'region' => 'EU868',
             'status' => 'offline', 'uplinks' => mt_rand(100, 400), 'last_seen' => $now - 86400 * 2, 'ip' => '192.168.1.13'],
        ];
    }

    private static function demoDeviceProfiles(): array
    {
        return [
            ['id' => 1, 'tenant_id' => 0, 'name' => '温湿度传感器', 'region' => 'EU868', 'mac_version' => '1.0.4',
             'adr_algorithm' => 'lora_wan', 'payload_codec_runtime' => 'JS', 'supports_class_b' => 0, 'supports_class_c' => 0,
             'created_at' => time() - 86400 * 30],
            ['id' => 2, 'tenant_id' => 0, 'name' => '电表（Class C）', 'region' => 'EU868', 'mac_version' => '1.0.4',
             'adr_algorithm' => 'lora_wan', 'payload_codec_runtime' => 'JS', 'supports_class_b' => 0, 'supports_class_c' => 1,
             'created_at' => time() - 86400 * 20],
            ['id' => 3, 'tenant_id' => 0, 'name' => '烟感报警器', 'region' => 'EU868', 'mac_version' => '1.0.4',
             'adr_algorithm' => 'lora_wan', 'payload_codec_runtime' => 'JS', 'supports_class_b' => 0, 'supports_class_c' => 0,
             'created_at' => time() - 86400 * 10],
        ];
    }

    private static function demoMulticastGroups(?int $appId = null): array
    {
        $now = time();
        $rows = [
            ['id' => 1, 'tenant_id' => 0, 'application_id' => 1, 'name' => '楼宇播报组', 'region' => 'EU868',
             'group_type' => 'C', 'mc_addr' => '01020304', 'mc_nwk_s_key' => 'aabbccddeeff00112233445566778899',
             'mc_app_s_key' => '99887766554433221100ffeeddccbbaa', 'dr' => 0, 'frequency' => 868100000,
             'f_cnt' => 1024, 'created_at' => $now - 86400 * 15],
            ['id' => 2, 'tenant_id' => 0, 'application_id' => 2, 'name' => '园区广播组', 'region' => 'EU868',
             'group_type' => 'C', 'mc_addr' => '05060708', 'mc_nwk_s_key' => '11223344556677889900aabbccddeeff',
             'mc_app_s_key' => 'ffeeddccbbaa00998877665544332211', 'dr' => 1, 'frequency' => 868300000,
             'f_cnt' => 512, 'created_at' => $now - 86400 * 8],
        ];
        if ($appId !== null && $appId > 0) {
            $rows = array_values(array_filter($rows, static fn($m) => (int) $m['application_id'] === (int) $appId));
        }
        return $rows;
    }

    private static function demoStats(): array
    {
        $dev = self::demoDevices();
        $gw = self::demoGateways();
        $t = time();
        $mk = static function (string $type, string $level, string $msg, int $backMin): array {
            return [
                'id' => mt_rand(5000, 99999), 'type' => $type, 'level' => $level,
                'dev_id' => mt_rand(1, count(self::demoDevices())), 'gateway_id' => self::demoGateways()[array_rand(self::demoGateways())]['gw_id'],
                'app_id' => 1, 'message' => $msg, 'created_at' => time() - $backMin * 60,
                'raw_json' => '',
            ];
        };
        return [
            'applications' => 3, 'devices' => count($dev), 'gateways' => count($gw),
            'gateways_online' => 2, 'gateways_offline' => 1,
            'uplinks' => mt_rand(1800, 2600), 'downlinks' => mt_rand(300, 600),
            'devices_online' => 3, 'devices_offline' => 1,
            'device_logs' => [
                $mk('UPLINK', 'info', '设备上报数据帧，FPort=10', 0),
                $mk('JOIN', 'ok', '设备完成 OTAA 入网', 3),
                $mk('UPLINK', 'info', '设备上报数据帧，FPort=10', 5),
                $mk('STATUS', 'info', '设备状态请求应答：电量 78%', 12),
                $mk('UPLINK', 'warn', '重传次数 3，链路质量下降', 25),
            ],
            'gateway_logs' => [
                $mk('GW_ONLINE', 'ok', '网关连接建立（Semtech UDP）', 0),
                $mk('PUSH_ACK', 'info', 'PUSH_ACK 已应答', 0),
                $mk('GW_OFFLINE', 'warn', '网关心跳超时，标记离线', 40),
                $mk('GW_ONLINE', 'ok', '网关重新上线', 41),
                $mk('PULL_ACK', 'info', 'PULL_ACK 已应答', 55),
            ],
        ];
    }

    private static function demoUplinks(int $n = 30, ?int $devId = null, ?int $appId = null): array
    {
        $devs = self::demoDevices($appId);
        if ($devId !== null && $devId > 0) {
            $devs = array_values(array_filter($devs, static fn($d) => (int) $d['id'] === (int) $devId));
        }
        if (!$devs) {
            return [];
        }
        $gws = self::demoGateways();
        $now = time();
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $d = $devs[array_rand($devs)];
            $t = $now - $i * mt_rand(5, 90);
            $b1 = mt_rand(0, 255);
            $b2 = mt_rand(0, 255);
            $g = $gws[array_rand($gws)];
            $fcnt = mt_rand(1000, 99999);
            $port = (mt_rand(1, 4) === 1 ? 2 : 10);
            $rssi = mt_rand(-112, -62);
            $snr = mt_rand(-15, 110) / 10;
            $data = sprintf('%02x%02x', $b1, $b2);
            $tmst = $t * 1000000 + mt_rand(0, 999999);
            $out[] = [
                'id' => 100000 + $i, 'app_id' => $d['app_id'], 'dev_id' => $d['id'], 'dev_addr' => $d['dev_addr'],
                'fcnt' => $fcnt, 'port' => $port,
                'confirmed' => 0,
                'decrypted_hex' => $data,
                'phy_payload' => '40' . $d['dev_addr'] . sprintf('%04x', $fcnt) . $data,
                'gateway_id' => $g['gw_id'],
                'rssi' => $rssi, 'snr' => $snr,
                'received_at' => $t,
                'raw_json' => json_encode([
                    'rxpk' => [[
                        'tmst' => $tmst, 'time' => gmdate('Y-m-d\TH:i:s.u\Z', $t), 'freq' => 868100000,
                        'chan' => 0, 'rfch' => 0, 'stat' => 1, 'modu' => 'LORA',
                        'datr' => 'SF12BW125', 'codr' => '4/5', 'lsnr' => $snr, 'rssi' => $rssi,
                        'size' => strlen($data) / 2, 'data' => $data,
                    ]],
                ], JSON_UNESCAPED_SLASHES),
            ];
        }
        return $out;
    }

    private static function demoDownlinks(int $n = 20, ?int $devId = null, ?int $appId = null): array
    {
        $devs = self::demoDevices($appId);
        if ($devId !== null && $devId > 0) {
            $devs = array_values(array_filter($devs, static fn($d) => (int) $d['id'] === (int) $devId));
        }
        if (!$devs) {
            return [];
        }
        $now = time();
        $statuses = ['sent', 'sent', 'acknowledged', 'pending', 'failed'];
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $d = $devs[array_rand($devs)];
            $t = $now - $i * mt_rand(10, 200);
            $out[] = [
                'id' => 80000 + $i, 'app_id' => $d['app_id'], 'dev_id' => $d['id'],
                'port' => mt_rand(1, 3) === 1 ? 2 : 10, 'confirmed' => (mt_rand(1, 3) === 1),
                'payload_hex' => sprintf('%02x%02x%02x%02x', mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)),
                'status' => $statuses[array_rand($statuses)], 'transmissions' => mt_rand(1, 3),
                'created_at' => $t, 'sent_at' => $t + 1, 'acknowledged_at' => (mt_rand(0, 2) ? $t + 2 : 0),
            ];
        }
        return $out;
    }

    private static function demoEvents(int $n = 25, ?int $devId = null, ?string $gwId = null): array
    {
        $gws = self::demoGateways();
        if ($gwId !== null && $gwId !== '') {
            $gws = array_values(array_filter($gws, static fn($g) => $g['gw_id'] === $gwId));
            if (!$gws) {
                return [];
            }
        }
        $devs = self::demoDevices();
        if ($devId !== null && $devId > 0) {
            $devs = array_values(array_filter($devs, static fn($d) => (int) $d['id'] === (int) $devId));
            if (!$devs) {
                return [];
            }
        }
        $now = time();
        $pool = [
            ['UPLINK', 'info', '上行数据帧'],
            ['JOIN', 'ok', '设备完成 OTAA 入网'],
            ['GW_ONLINE', 'ok', '网关连接建立（Semtech UDP）'],
            ['GW_OFFLINE', 'warn', '网关心跳超时'],
            ['DOWNLINK', 'info', '下行帧已发送'],
            ['ERROR', 'error', 'MIC 校验失败，丢弃数据帧'],
            ['PUSH_ACK', 'info', 'PUSH_ACK 已应答'],
        ];
        // 按设备筛选时只生成设备类事件（网关事件 dev_id=0 会污染筛选结果）
        if ($devId !== null && $devId > 0) {
            $pool = array_values(array_filter($pool, static fn($p) => !in_array($p[0], ['GW_ONLINE', 'GW_OFFLINE', 'PUSH_ACK'], true)));
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            [$type, $level, $msg] = $pool[array_rand($pool)];
            $isGw = in_array($type, ['GW_ONLINE', 'GW_OFFLINE', 'PUSH_ACK'], true);
            $g = $gws[array_rand($gws)];
            $d = $devs[array_rand($devs)];
            $created = $now - $i * mt_rand(3, 120);
            $out[] = [
                'id' => 60000 + $i, 'type' => $type, 'level' => $level,
                'gateway_id' => $isGw ? $g['gw_id'] : $g['gw_id'],
                'dev_id' => $isGw ? 0 : $d['id'],
                'app_id' => $isGw ? 1 : $d['app_id'],
                'message' => $msg, 'created_at' => $created,
                'raw_json' => json_encode([
                    'event' => $type, 'level' => $level, 'message' => $msg,
                    'gateway_id' => $g['gw_id'], 'dev_id' => $isGw ? null : $d['id'],
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z', $created),
                ], JSON_UNESCAPED_SLASHES),
            ];
        }
        return $out;
    }

    public static function listApplications(?int $tenantId = null): array
    {
        if (self::scope()['demo']) {
            return self::demoApplications();
        }
        $tid = self::effectiveTenant($tenantId);
        if ($tid === null) {
            return Database::fetchAll("SELECT * FROM applications ORDER BY id DESC");
        }
        return Database::fetchAll("SELECT * FROM applications WHERE tenant_id=? ORDER BY id DESC", [$tid]);
    }

    public static function getApplicationByName(string $name): ?array
    {
        return Database::fetch("SELECT * FROM applications WHERE name=?", [$name]);
    }

    public static function getApplicationByEui(string $appEui): ?array
    {
        return Database::fetch("SELECT * FROM applications WHERE app_eui=?", [strtolower($appEui)]);
    }

    public static function createApplication(array $p): array
    {
        if (empty($p['name'])) {
            return ['error' => 'name required'];
        }
        if (self::getApplicationByName($p['name'])) {
            return ['error' => 'application name already exists'];
        }
        // 若未填写 AppEUI 则随机生成（16 hex）
        $appEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['app_eui'] ?? ''));
        if ($appEui === '') {
            $appEui = bin2hex(random_bytes(8));
        } elseif (self::getApplicationByEui($appEui)) {
            return ['error' => 'app_eui already exists'];
        }
        $callbackUrl = trim($p['callback_url'] ?? '');
        $tid = self::createTenantId($p);
        Database::execute(
            "INSERT INTO applications (name, description, app_eui, callback_url, tenant_id, created_at) VALUES (?,?,?,?,?,?)",
            [$p['name'], $p['description'] ?? '', $appEui, $callbackUrl, $tid, time()]
        );
        return ['id' => Database::lastInsertId(), 'app_eui' => $appEui];
    }

    public static function listDevices(?int $appId = null, ?int $tenantId = null): array
    {
        if (self::scope()['demo']) {
            return self::demoDevices($appId);
        }
        $tid = self::effectiveTenant($tenantId);
        if ($appId) {
            $app = self::getApplication($appId);
            if (!$app || !self::canAccess($app)) {
                return [];
            }
            $rows = Database::fetchAll("SELECT * FROM devices WHERE app_id=? ORDER BY id DESC", [$appId]);
        } elseif ($tid === null) {
            $rows = Database::fetchAll("SELECT * FROM devices ORDER BY id DESC");
        } else {
            $rows = Database::fetchAll("SELECT * FROM devices WHERE tenant_id=? ORDER BY id DESC", [$tid]);
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
        $devEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['dev_eui']));
        if (strlen($devEui) !== 16) {
            return ['error' => 'dev_eui must be 16 hex chars'];
        }
        if (Database::fetch("SELECT id FROM devices WHERE dev_eui=?", [$devEui])) {
            return ['error' => 'dev_eui already exists'];
        }
        // Class A/B/C（设备工作模式，决定下行调度策略）
        $class = strtoupper($p['class'] ?? 'A');
        if (!in_array($class, ['A', 'B', 'C'], true)) {
            return ['error' => 'class must be A, B or C'];
        }
        $appId = (int) ($p['app_id'] ?? 0);
        $app = self::getApplication($appId);
        if (!$app) {
            return ['error' => 'application not found'];
        }
        if (!self::canAccess($app)) {
            return ['error' => 'forbidden: application not in your tenant'];
        }
        // 设备归属 = 应用所属租户（应用有租户时优先继承；全局应用按当前用户规则）
        $tid = (int) ($app['tenant_id'] ?? 0);
        if ($tid <= 0) {
            $tid = self::createTenantId($p);
        }
        if (Database::fetch("SELECT id FROM devices WHERE app_id=? AND name=?", [$appId, $p['name']])) {
            return ['error' => 'device name already exists in this application'];
        }
        $region = $p['region'] ?? ELW_DEFAULT_REGION;
        if (!in_array(strtoupper($region), Region::supported(), true)) {
            return ['error' => 'unsupported region: ' . $region];
        }
        $dpId = (int) ($p['device_profile_id'] ?? 0);
        $dp = DeviceProfile::getOrDefault($dpId);
        $macVersion = LoRaWANVersion::value($dp['mac_version'] ?? '1.0.3');
        if ($activation === 'OTAA') {
            $appKey = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['app_key'] ?? ''));
            if (strlen($appKey) !== 32) {
                return ['error' => 'app_key must be 32 hex chars'];
            }
            $joinEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['join_eui'] ?? ''));
            if (strlen($joinEui) !== 16) {
                return ['error' => 'join_eui must be 16 hex chars'];
            }
            // 1.1 设备需要 NwkKey（与 AppKey 分离）；缺省回退到 AppKey，保证 1.0.x 存量兼容
            $nwkKey = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['nwk_key'] ?? $appKey));
            Database::execute(
                "INSERT INTO devices (app_id, tenant_id, name, dev_eui, join_eui, activation, app_key, nwk_key, region, class, device_profile_id, mac_version, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$appId, $tid, $p['name'], $devEui, $joinEui, 'OTAA', $appKey, $nwkKey, $p['region'] ?? ELW_DEFAULT_REGION, $class, $dpId, $macVersion, 'pending', time()]
            );
        } else { // ABP
            $devAddr = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['dev_addr'] ?? ''));
            $nwk = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['nwk_s_key'] ?? ''));
            $app = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['app_s_key'] ?? ''));
            if (strlen($devAddr) !== 8 || strlen($nwk) !== 32 || strlen($app) !== 32) {
                return ['error' => 'ABP requires dev_addr(8), nwk_s_key(32), app_s_key(32) hex'];
            }
            Database::execute(
                "INSERT INTO devices (app_id, tenant_id, name, dev_eui, dev_addr, activation, nwk_s_key, app_s_key, region, class, device_profile_id, mac_version, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$appId, $tid, $p['name'], $devEui, $devAddr, 'ABP', $nwk, $app, $p['region'] ?? ELW_DEFAULT_REGION, $class, $dpId, $macVersion, 'active', time()]
            );
        }
        return ['id' => Database::lastInsertId()];
    }

    public static function listGateways(?int $tenantId = null): array
    {
        if (self::scope()['demo']) {
            return self::demoGateways();
        }
        $tid = self::effectiveTenant($tenantId);
        if ($tid === null) {
            $rows = Database::fetchAll(
                "SELECT g.*, (SELECT COUNT(*) FROM uplinks u WHERE u.gateway_id=g.gw_id) AS uplinks
                 FROM gateways g ORDER BY g.last_seen DESC"
            );
        } else {
            $rows = Database::fetchAll(
                "SELECT g.*, (SELECT COUNT(*) FROM uplinks u WHERE u.gateway_id=g.gw_id) AS uplinks
                 FROM gateways g WHERE g.tenant_id=? ORDER BY g.last_seen DESC",
                [$tid]
            );
        }
        $timeout = time() - self::GW_OFFLINE_TIMEOUT; // 300s 心跳超时
        foreach ($rows as &$g) {
            $g['status'] = ((int) ($g['last_seen'] ?? 0) >= $timeout) ? 'online' : 'offline';
            $g['uplinks'] = (int) ($g['uplinks'] ?? 0);
        }
        unset($g);
        return $rows;
    }

    public static function listUplinks(?int $devId = null, ?int $appId = null, int $limit = 200, ?int $tenantId = null): array
    {
        if (self::scope()['demo']) {
            return self::demoUplinks($limit > 50 ? 30 : max(1, $limit), $devId, $appId);
        }
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
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds !== null) {
            if (!$appIds) {
                return [];
            }
            $where[] = 'app_id IN (' . implode(',', $appIds) . ')';
        }
        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY id DESC LIMIT $limit";
        return Database::fetchAll($sql, $params);
    }

    public static function listDownlinks(?int $devId = null, ?int $appId = null, int $limit = 200, ?int $tenantId = null): array
    {
        if (self::scope()['demo']) {
            return self::demoDownlinks($limit > 50 ? 20 : max(1, $limit), $devId, $appId);
        }
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
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds !== null) {
            if (!$appIds) {
                return [];
            }
            $where[] = 'app_id IN (' . implode(',', $appIds) . ')';
        }
        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY id DESC LIMIT $limit";
        return Database::fetchAll($sql, $params);
    }

    public static function listEvents(?int $devId = null, ?string $gwId = null, int $limit = 200, ?int $tenantId = null): array
    {
        if (self::scope()['demo']) {
            return self::demoEvents($limit > 50 ? 25 : max(1, $limit), $devId, $gwId);
        }
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
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds !== null) {
            if (!$appIds) {
                return [];
            }
            $where[] = 'app_id IN (' . implode(',', $appIds) . ')';
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
        if (!self::canAccess($device)) {
            return ['error' => 'forbidden: device not in your tenant'];
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
        $app = Database::fetch("SELECT * FROM applications WHERE id=?", [$id]);
        if ($app && !self::canAccess($app)) {
            return null;
        }
        return $app;
    }

    public static function updateApplication(int $id, array $p): array
    {
        $app = self::getApplication($id);
        if (!$app) {
            return ['error' => 'application not found'];
        }
        if (!self::canAccess($app)) {
            return ['error' => 'forbidden: application not in your tenant'];
        }
        if (empty($p['name'])) {
            return ['error' => 'name required'];
        }
        $dupName = Database::fetch("SELECT id FROM applications WHERE name=? AND id<>?", [$p['name'], $id]);
        if ($dupName) {
            return ['error' => 'application name already exists'];
        }
        $appEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['app_eui'] ?? ''));
        if ($appEui !== '') {
            $dupEui = Database::fetch("SELECT id FROM applications WHERE app_eui=? AND id<>?", [$appEui, $id]);
            if ($dupEui) {
                return ['error' => 'app_eui already exists'];
            }
        }
        Database::execute(
            "UPDATE applications SET name=?, description=?, app_eui=?, callback_url=? WHERE id=?",
            [$p['name'], $p['description'] ?? '', $appEui, trim($p['callback_url'] ?? ''), $id]
        );
        return ['id' => $id];
    }

    public static function deleteApplication(int $id): array
    {
        $app = self::getApplication($id);
        if (!$app) {
            return ['error' => 'application not found'];
        }
        if (!self::canAccess($app)) {
            return ['error' => 'forbidden: application not in your tenant'];
        }
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
        $dev = Database::fetch("SELECT * FROM devices WHERE id=?", [$id]);
        if ($dev && !self::canAccess($dev)) {
            return null;
        }
        return $dev;
    }

    public static function updateDevice(int $id, array $p): array
    {
        $device = self::getDevice($id);
        if (!$device) {
            return ['error' => 'device not found'];
        }
        if (!self::canAccess($device)) {
            return ['error' => 'forbidden: device not in your tenant'];
        }
        $name = $p['name'] ?? $device['name'];
        if (array_key_exists('name', $p) && $name !== '') {
            $dupName = Database::fetch("SELECT id FROM devices WHERE app_id=? AND name=? AND id<>?", [$device['app_id'], $name, $id]);
            if ($dupName) {
                return ['error' => 'device name already exists in this application'];
            }
        }
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
            $devAddr = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['dev_addr'] ?? $device['dev_addr']));
            $nwk = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['nwk_s_key'] ?? $device['nwk_s_key']));
            $app = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['app_s_key'] ?? $device['app_s_key']));
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
            $appKey = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['app_key']));
            if (strlen($appKey) !== 32) {
                return ['error' => 'app_key must be 32 hex chars'];
            }
            $setParts[] = 'app_key=?';
            $params[] = $appKey;
        }

        // OTAA 设备也允许编辑 DevEUI/JoinEUI
        if ($device['activation'] === 'OTAA') {
            if (!empty($p['dev_eui'])) {
                $devEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['dev_eui']));
                if (strlen($devEui) === 16) {
                    if (Database::fetch("SELECT id FROM devices WHERE dev_eui=? AND id<>?", [$devEui, $id])) {
                        return ['error' => 'dev_eui already exists'];
                    }
                    $setParts[] = 'dev_eui=?';
                    $params[] = $devEui;
                }
            }
            if (!empty($p['join_eui'])) {
                $joinEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['join_eui']));
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
        $device = self::getDevice($id);
        if (!$device) {
            return ['error' => 'device not found'];
        }
        if (!self::canAccess($device)) {
            return ['error' => 'forbidden: device not in your tenant'];
        }
        Database::execute("DELETE FROM downlinks WHERE dev_id=?", [$id]);
        Database::execute("DELETE FROM uplinks WHERE dev_id=?", [$id]);
        Database::execute("DELETE FROM devices WHERE id=?", [$id]);
        return ['ok' => true];
    }

    // ---------------- 网关 增删改查 ----------------

    public static function getGateway(string $gwId): ?array
    {
        $gw = Database::fetch("SELECT * FROM gateways WHERE gw_id=?", [$gwId]);
        if ($gw && !self::canAccess($gw)) {
            return null;
        }
        return $gw;
    }

    public static function createGateway(array $p): array
    {
        $gwId = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['gw_id'] ?? ''));
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
        $tid = self::createTenantId($p);
        Database::execute(
            "INSERT INTO gateways (gw_id, tenant_id, name, region, created_at, last_seen, ip) VALUES (?,?,?,?,?,?,?)",
            [$gwId, $tid, $p['name'], $region, time(), 0, '']
        );
        return ['gw_id' => $gwId];
    }

    public static function updateGateway(string $gwId, array $p): array
    {
        // getGateway 已做 canAccess 租户校验：越权返回 null
        if (!self::getGateway($gwId)) {
            return ['error' => 'gateway not found or forbidden'];
        }
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
        // getGateway 已做 canAccess 租户校验：越权返回 null
        if (!self::getGateway($gwId)) {
            return ['error' => 'gateway not found or forbidden'];
        }
        Database::execute("DELETE FROM gateways WHERE gw_id=?", [$gwId]);
        return ['ok' => true];
    }

    // ---------------- 用户管理 & 密码管理 ----------------

    public static function listUsers(): array
    {
        $cur = Auth::currentUser();
        if (!$cur) return [];
        // admin 可查看所有用户（含租户归属）；普通用户仅返回自己
        if ($cur['role'] === Auth::ROLE_ADMIN) {
            return Database::fetchAll(
                "SELECT u.id, u.username, u.role, u.tenant_id, COALESCE(t.name,'') AS tenant_name, u.created_at
                 FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id ORDER BY u.id DESC"
            );
        }
        return [[
            'id' => $cur['id'], 'username' => $cur['username'], 'role' => $cur['role'],
            'tenant_id' => (int) ($cur['tenant_id'] ?? 0), 'tenant_name' => '', 'created_at' => 0,
        ]];
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
        // operator（演示）为只读账号，禁止修改任何密码（含自己的）
        if ($cur['role'] === Auth::ROLE_OPERATOR) {
            return ['error' => 'forbidden: operator is read-only'];
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
        // 演示账号：返回模拟统计数据（不读真实库，避免泄露 admin 数据）
        $s = self::scope();
        if ($s['demo']) {
            $devs = 4; $gws = 3; $ups = 1280; $dls = 560;
            return [
                'applications' => 1, 'devices' => $devs, 'gateways' => $gws,
                'gateways_online' => 2, 'gateways_offline' => 1,
                'uplinks' => $ups, 'downlinks' => $dls,
                'devices_online' => 3, 'devices_offline' => 1,
                'device_logs' => self::demoEvents(5),
                'gateway_logs' => self::demoEvents(5),
            ];
        }
        $tid = self::effectiveTenant();
        $appIds = self::visibleAppIds();
        $appClause = null;   // null = 不过滤
        if ($appIds !== null) {
            if (!$appIds) {
                // 作用域内没有任何应用 → 全部计数为 0（避免空 IN() 语法错误）
                return [
                    'applications' => 0, 'devices' => 0, 'gateways' => 0,
                    'gateways_online' => 0, 'gateways_offline' => 0,
                    'uplinks' => 0, 'downlinks' => 0,
                    'devices_online' => 0, 'devices_offline' => 0,
                    'device_logs' => [], 'gateway_logs' => [],
                ];
            }
            $in = implode(',', $appIds);
            $appClause = "app_id IN ($in)";
        }
        $appFilter = static function (string $sql, array $extra = []) use ($appClause) {
            if ($appClause !== null) {
                $sql .= (strpos($sql, 'WHERE') === false ? ' WHERE ' : ' AND ') . $appClause;
            }
            return Database::fetch($sql, $extra);
        };
        $apps = $tid !== null
            ? Database::fetch("SELECT COUNT(*) c FROM applications WHERE tenant_id=?", [$tid])['c']
            : Database::fetch("SELECT COUNT(*) c FROM applications")['c'];
        $devs = $appFilter("SELECT COUNT(*) c FROM devices")['c'];
        $gws = $tid !== null
            ? Database::fetch("SELECT COUNT(*) c FROM gateways WHERE tenant_id=?", [$tid])['c']
            : Database::fetch("SELECT COUNT(*) c FROM gateways")['c'];
        $ups = $appFilter("SELECT COUNT(*) c FROM uplinks")['c'];
        $dls = $appFilter("SELECT COUNT(*) c FROM downlinks")['c'];
        $gwsOnline = $tid !== null
            ? Database::fetch("SELECT COUNT(*) c FROM gateways WHERE tenant_id=? AND last_seen >= ?", [$tid, time() - self::GW_OFFLINE_TIMEOUT])['c']
            : Database::fetch("SELECT COUNT(*) c FROM gateways WHERE last_seen >= ?", [time() - self::GW_OFFLINE_TIMEOUT])['c'];
        // 设备健康分布：在线 = 已激活且在离线超时窗口内最近上报；其余算离线（含未激活/pending）
        $devsOnline = $appFilter(
            "SELECT COUNT(*) c FROM devices WHERE status='active' AND last_seen >= ?",
            [time() - self::DEV_OFFLINE_TIMEOUT]
        )['c'];
        $devsOffline = max(0, (int)$devs - (int)$devsOnline);
        // 最近 5 条设备/网关日志（events 表：dev_id>0 为设备事件，gateway_id!='' 为网关事件）
        $deviceLogs = Database::fetchAll(
            "SELECT id, type, level, dev_id, message, created_at FROM events WHERE dev_id > 0"
            . ($appClause !== null ? " AND $appClause" : '') . " ORDER BY id DESC LIMIT 5"
        );
        $gatewayLogs = Database::fetchAll(
            "SELECT id, type, level, gateway_id, message, created_at FROM events WHERE gateway_id != ''"
            . ($appClause !== null ? " AND $appClause" : '') . " ORDER BY id DESC LIMIT 5"
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

    public static function listDeviceProfiles(?int $tenantId = null): array
    {
        if (self::scope()['demo']) {
            return self::demoDeviceProfiles();
        }
        return DeviceProfile::list(self::effectiveTenant($tenantId));
    }
    public static function getDeviceProfile(int $id): ?array
    {
        return DeviceProfile::get($id);
    }
    public static function createDeviceProfile(array $p): array
    {
        $p['tenant_id'] = self::createTenantId($p);
        return DeviceProfile::create($p);
    }
    public static function updateDeviceProfile(int $id, array $p): array
    {
        $dp = DeviceProfile::get($id);
        if ($dp && !self::canAccess($dp)) {
            return ['error' => 'forbidden: device profile not in your tenant'];
        }
        return DeviceProfile::update($id, $p);
    }
    public static function deleteDeviceProfile(int $id): array
    {
        $dp = DeviceProfile::get($id);
        if ($dp && !self::canAccess($dp)) {
            return ['error' => 'forbidden: device profile not in your tenant'];
        }
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

    public static function listApiKeys(int $applicationId, ?int $tenantId = null): array
    {
        // 演示账号：返回模拟 Key，不读真实库
        if (self::scope()['demo']) {
            $now = time();
            return [
                ['id' => 9001, 'name' => '示例应用 Key', 'application_id' => $applicationId,
                 'token_preview' => '3f9a1c2b7d4e', 'created_at' => $now - 86400 * 7],
                ['id' => 9002, 'name' => '只读监控 Key', 'application_id' => $applicationId,
                 'token_preview' => '8e6d5c4b3a29', 'created_at' => $now - 86400 * 3],
            ];
        }
        if ($applicationId > 0) {
            $appIds = self::visibleAppIds($tenantId);
            if ($appIds !== null && !in_array($applicationId, $appIds, true)) {
                return [];
            }
            return ApiKey::list($applicationId);
        }
        // 未指定应用：按租户（或全部）列出其下所有应用的 Key
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds === null || !$appIds) {
            return [];
        }
        $in = implode(',', $appIds);
        return Database::fetchAll(
            "SELECT id, name, application_id, substr(api_key,1,12) AS token_preview, created_at
             FROM api_keys WHERE application_id IN ($in) ORDER BY id DESC"
        );
    }
    public static function createApiKey(int $applicationId, array $p): array
    {
        if (!self::appInScope($applicationId)) {
            return ['error' => 'forbidden: application not in your tenant'];
        }
        return ApiKey::create($applicationId, $p['name'] ?? '');
    }
    public static function deleteApiKey(int $id): array
    {
        $row = Database::fetch("SELECT application_id FROM api_keys WHERE id=?", [$id]);
        if (!$row) {
            return ['error' => 'api key not found'];
        }
        if (!self::appInScope((int) $row['application_id'])) {
            return ['error' => 'forbidden: api key not in your tenant'];
        }
        return ApiKey::delete($id);
    }

    // ---------------- 集成（Integrations） ----------------

    public static function listIntegrations(int $applicationId, ?int $tenantId = null): array
    {
        // 演示账号：返回模拟集成，不读真实库
        if (self::scope()['demo']) {
            $now = time();
            return [
                ['id' => 8001, 'application_id' => $applicationId, 'kind' => 'HTTP', 'enabled' => 1,
                 'config_json' => json_encode(['url' => 'https://demo.example.com/uplink']),
                 'created_at' => $now - 86400 * 5],
                ['id' => 8002, 'application_id' => $applicationId, 'kind' => 'MQTT_GLOBAL', 'enabled' => 0,
                 'config_json' => json_encode(['server' => 'tcp://demo.example.com:1883', 'topic' => 'application/{app_id}/device/{dev_eui}/up']),
                 'created_at' => $now - 86400 * 2],
            ];
        }
        if ($applicationId > 0) {
            $appIds = self::visibleAppIds($tenantId);
            if ($appIds !== null && !in_array($applicationId, $appIds, true)) {
                return [];
            }
            return Integration::list($applicationId);
        }
        // 未指定应用：按租户（或全部）列出其下所有应用的集成
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds === null || !$appIds) {
            return [];
        }
        $in = implode(',', $appIds);
        return Database::fetchAll(
            "SELECT * FROM integrations WHERE application_id IN ($in) ORDER BY id DESC"
        );
    }
    public static function createIntegration(array $p): array
    {
        $appId = (int) ($p['application_id'] ?? 0);
        if (!self::appInScope($appId)) {
            return ['error' => 'forbidden: application not in your tenant'];
        }
        $p['tenant_id'] = self::createTenantId($p);
        return Integration::create($p);
    }
    public static function updateIntegration(int $id, array $p): array
    {
        $row = Database::fetch("SELECT application_id FROM integrations WHERE id=?", [$id]);
        if (!$row) {
            return ['error' => 'integration not found'];
        }
        if (!self::appInScope((int) $row['application_id'])) {
            return ['error' => 'forbidden: integration not in your tenant'];
        }
        return Integration::update($id, $p);
    }
    public static function deleteIntegration(int $id): array
    {
        $row = Database::fetch("SELECT application_id FROM integrations WHERE id=?", [$id]);
        if (!$row) {
            return ['error' => 'integration not found'];
        }
        if (!self::appInScope((int) $row['application_id'])) {
            return ['error' => 'forbidden: integration not in your tenant'];
        }
        return Integration::delete($id);
    }

    // ---------------- 组播组（Multicast Group） ----------------

    public static function listMulticastGroups(?int $appId = null, ?int $tenantId = null): array
    {
        if (self::scope()['demo']) {
            return self::demoMulticastGroups($appId);
        }
        $sql = "SELECT * FROM multicast_groups";
        $params = [];
        $where = [];
        if ($appId) {
            $where[] = "application_id=?";
            $params[] = $appId;
        }
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds !== null) {
            if (!$appIds) {
                return [];
            }
            $where[] = 'application_id IN (' . implode(',', $appIds) . ')';
        }
        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY id DESC";
        return Database::fetchAll($sql, $params);
    }
    public static function getMulticastGroup(int $id): ?array
    {
        $g = Database::fetch("SELECT * FROM multicast_groups WHERE id=?", [$id]);
        if ($g && !self::canAccess($g)) {
            return null;
        }
        return $g;
    }
    public static function createMulticastGroup(array $p): array
    {
        $appId = (int) ($p['application_id'] ?? 0);
        if ($appId <= 0) {
            return ['error' => 'application_id required'];
        }
        if (!self::appInScope($appId)) {
            return ['error' => 'forbidden: application not in your tenant'];
        }
        $tid = self::createTenantId($p);
        $region = $p['region'] ?? ELW_DEFAULT_REGION;
        if (!in_array(strtoupper($region), Region::supported(), true)) {
            return ['error' => 'unsupported region: ' . $region];
        }
        $sess = Multicast::generateSession();
        $mcAddr = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['mc_addr'] ?? $sess['mc_addr']));
        $mcNwk = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['mc_nwk_s_key'] ?? $sess['mc_nwk_s_key']));
        $mcApp = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['mc_app_s_key'] ?? $sess['mc_app_s_key']));
        if (strlen($mcAddr) !== 8 || strlen($mcNwk) !== 32 || strlen($mcApp) !== 32) {
            return ['error' => 'multicast keys must be mc_addr(8)/mc_nwk_s_key(32)/mc_app_s_key(32) hex'];
        }
        $type = strtoupper($p['group_type'] ?? 'C');
        if (!in_array($type, ['A', 'B', 'C'], true)) {
            $type = 'C';
        }
        Database::execute(
            "INSERT INTO multicast_groups (name, application_id, tenant_id, region, group_type, mc_addr, mc_nwk_s_key, mc_app_s_key, f_cnt, dr, frequency, class_b_ping_slot_periodicity, class_c_scheduling_type, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $p['name'] ?? 'Multicast', $appId, $tid, $region, $type, $mcAddr, $mcNwk, $mcApp,
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
        if (!self::canAccess($g)) {
            return ['error' => 'forbidden: group not in your tenant'];
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
        // getMulticastGroup 已做 canAccess：越权折叠为 null，必须先判 null 再删，否则越权删除
        if (!self::getMulticastGroup($id)) {
            return ['error' => 'group not found or forbidden'];
        }
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
        if (!self::canAccess($g)) {
            return ['error' => 'forbidden: group not in your tenant'];
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

    // ---------------- FUOTA（固件升级，TR005/TS005） ----------------

    public static function listFuotaCampaigns(): array
    {
        $tid = self::effectiveTenant();
        if ($tid === null) {
            return Fuota::listCampaigns(0, true);
        }
        return Fuota::listCampaigns($tid);
    }

    public static function createFuotaCampaign(array $p): array
    {
        $appId = (int) ($p['application_id'] ?? 0);
        $mgId = (int) ($p['multicast_group_id'] ?? 0);
        if (!self::appInScope($appId)) {
            return ['error' => 'application not found or forbidden'];
        }
        $mg = self::getMulticastGroup($mgId);
        if (!$mg || !self::appInScope((int) $mg['application_id'])) {
            return ['error' => 'multicast group not found or forbidden'];
        }
        $p['tenant_id'] = self::createTenantId($p);
        return Fuota::createCampaign($p);
    }

    public static function getFuotaCampaign(int $id): array
    {
        $camp = Fuota::getCampaign($id);
        if (!$camp || !self::canAccess($camp)) {
            return ['error' => 'campaign not found or forbidden'];
        }
        return Fuota::campaignDetail($id) ?? ['error' => 'campaign not found'];
    }

    public static function addFuotaDeployment(int $campaignId, int $devId): array
    {
        $camp = Fuota::getCampaign($campaignId);
        if (!$camp || !self::canAccess($camp)) {
            return ['error' => 'campaign not found or forbidden'];
        }
        if ($camp['state'] !== Fuota::STATE_PENDING) {
            return ['error' => "campaign already started (state={$camp['state']})"];
        }
        $dev = Database::fetch("SELECT id, app_id, dev_eui FROM devices WHERE id=?", [$devId]);
        if (!$dev || !self::appInScope((int) $dev['app_id'])) {
            return ['error' => 'device not found or forbidden'];
        }
        // 设备必须已加入组播组（否则收不到组播分片）
        $inGroup = Database::fetch(
            "SELECT id FROM multicast_group_devices WHERE multicast_group_id=? AND LOWER(dev_eui)=?",
            [(int) $camp['multicast_group_id'], strtolower($dev['dev_eui'] ?? '')]
        );
        if (!$inGroup) {
            return ['error' => 'device not in multicast group'];
        }
        return Fuota::addDeployment($campaignId, $devId);
    }

    public static function startFuotaCampaign(int $campaignId, array $body): array
    {
        $camp = Fuota::getCampaign($campaignId);
        if (!$camp || !self::canAccess($camp)) {
            return ['error' => 'campaign not found or forbidden'];
        }
        $fwB64 = $body['firmware_base64'] ?? '';
        $fw = base64_decode($fwB64, true);
        if ($fw === false || $fw === '') {
            return ['error' => 'firmware_base64 required (base64 encoded firmware)'];
        }
        return Fuota::startCampaign($campaignId, $fw, [
            'min_delay' => (int) ($body['min_delay'] ?? 200),
            'max_delay' => (int) ($body['max_delay'] ?? 1000),
            'timeout'   => (int) ($body['timeout'] ?? 3600),
            'mc_ke_key' => $body['mc_ke_key'] ?? '',
        ]);
    }

    public static function deleteFuotaCampaign(int $id): array
    {
        $camp = Fuota::getCampaign($id);
        if (!$camp || !self::canAccess($camp)) {
            return ['error' => 'campaign not found or forbidden'];
        }
        Database::execute("DELETE FROM fuota_fragments WHERE deployment_id IN (SELECT id FROM fuota_deployments WHERE campaign_id=?)", [$id]);
        Database::execute("DELETE FROM fuota_deployments WHERE campaign_id=?", [$id]);
        Database::execute("DELETE FROM fuota_frames WHERE campaign_id=?", [$id]);
        Database::execute("DELETE FROM fuota_campaigns WHERE id=?", [$id]);
        return ['ok' => true];
    }
}
