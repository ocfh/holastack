<?php
namespace holastack\Web;

use holastack\DB\Database;
use holastack\Region\Region;
use holastack\Auth\Auth;
use holastack\Storage\DeviceProfile;
use holastack\Storage\Tenant;
use holastack\Storage\ThingModel;
use holastack\Storage\Alert;
use holastack\Storage\Automation;
use holastack\Storage\ScheduledTask;
use holastack\Storage\Role;
use holastack\Storage\Department;
use holastack\Auth\ApiKey;
use holastack\Integration\Integration;
use holastack\Core\Multicast;
use holastack\Core\Fuota;
use holastack\Core\LoRaWANVersion;






class WebApp
{
    

    const GW_OFFLINE_TIMEOUT = 300;
    

    const DEV_OFFLINE_TIMEOUT = 600;

    

    


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

    private static function canAccess(array $row, string $tenantCol = 'tenant_id'): bool
    {
        $s = self::scope();
        if ($s['is_admin'] || $s['demo']) {
            return true;
        }
        return (int) ($row[$tenantCol] ?? 0) === $s['tenant_id'];
    }

    private static function createTenantId(array $p = []): int
    {
        $s = self::scope();
        if ($s['is_admin']) {
            return (int) ($p['tenant_id'] ?? 0);
        }
        return $s['tenant_id'];
    }

    /**
     * 资源配额解析：优先取该租户下用户所绑定角色的配额（gateways_unlimited/gateways_limit/devices_limit），
     * 角色未配置（全 0）时回退到租户（用户配置）自身的 private_gateways_* 旧逻辑。
     * 返回 ['gateways_unlimited'=>bool, 'gateways_limit'=>int, 'devices_limit'=>int, 'source'=>'role'|'tenant'|'none']
     */
    public static function quotaForTenant(int $tenantId): array
    {
        if ($tenantId > 0) {
            $roleRow = Database::fetch(
                "SELECT r.devices_limit, r.gateways_limit, r.gateways_unlimited
                 FROM users u JOIN roles r ON r.id=u.role_id
                 WHERE u.tenant_id=? AND u.role_id>0 ORDER BY u.id ASC LIMIT 1",
                [$tenantId]
            );
            if ($roleRow && ((int) $roleRow['gateways_unlimited'] === 1 || (int) $roleRow['gateways_limit'] > 0 || (int) $roleRow['devices_limit'] > 0)) {
                return [
                    'gateways_unlimited' => (int) $roleRow['gateways_unlimited'] === 1,
                    'gateways_limit'     => max(0, (int) $roleRow['gateways_limit']),
                    'devices_limit'      => max(0, (int) $roleRow['devices_limit']),
                    'source'             => 'role',
                ];
            }
            $t = Tenant::get($tenantId);
            if ($t) {
                return [
                    'gateways_unlimited' => (int) ($t['private_gateways_unlimited'] ?? 0) === 1,
                    'gateways_limit'     => max(0, (int) ($t['private_gateways_limit'] ?? 0)),
                    'devices_limit'      => 0,
                    'source'             => 'tenant',
                ];
            }
        }
        return ['gateways_unlimited' => false, 'gateways_limit' => 0, 'devices_limit' => 0, 'source' => 'none'];
    }

    /** 校验租户网关配额（角色优先），超限返回错误信息，否则 null。 */
    private static function checkGatewayQuota(int $tenantId): ?string
    {
        $q = self::quotaForTenant($tenantId);
        if ($q['gateways_unlimited']) {
            return null;
        }
        $limit = $q['gateways_limit'];
        if ($limit <= 0) {
            return null;
        }
        $count = (int) Database::fetch(
            "SELECT COUNT(*) AS c FROM gateways WHERE tenant_id=?",
            [$tenantId]
        )['c'];
        if ($count >= $limit) {
            return $q['source'] === 'role'
                ? '该角色的私有网关数量已达上限（' . $limit . '），请先在「角色管理」中调整或开启无限制'
                : '该用户配置的私有网关数量已达上限（' . $limit . '），请先在「用户配置」中调整上限或开启无限制';
        }
        return null;
    }

    /** 校验租户设备配额（仅来自角色配置，租户旧字段不控设备），超限返回错误信息，否则 null。 */
    private static function checkDeviceQuota(int $tenantId): ?string
    {
        $q = self::quotaForTenant($tenantId);
        if ($q['devices_limit'] <= 0) {
            return null;
        }
        $count = (int) Database::fetch(
            "SELECT COUNT(*) AS c FROM devices WHERE tenant_id=?",
            [$tenantId]
        )['c'];
        if ($count >= $q['devices_limit']) {
            return '该角色的设备数量已达上限（' . $q['devices_limit'] . '），请先在「角色管理」中调整设备上限';
        }
        return null;
    }

    private static function appInScope(int $appId): bool
    {
        $appIds = self::visibleAppIds();
        return $appIds === null || in_array($appId, $appIds, true);
    }

    private static function visibleAppIds(?int $explicit = null): ?array
    {
        $tid = self::effectiveTenant($explicit);
        if ($tid === null) {
            return null;
        }
        $rows = Database::fetchAll("SELECT id FROM applications WHERE tenant_id=?", [$tid]);
        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    


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

    private static function demoEvents(int $n = 25, ?int $devId = null, ?string $gwId = null, ?string $type = null): array
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
            ['uplink',   'info',  '上行数据帧'],
            ['join',     'info',  '设备完成 OTAA 入网'],
            ['gateway',  'info',  '网关连接建立（Semtech UDP）'],
            ['gateway',  'warn',  '网关心跳超时'],
            ['downlink', 'info',  '下行帧已发送'],
            ['uplink',   'error', 'MIC 校验失败，丢弃数据帧'],
            ['txack',    'warn',  '网关下行发射失败'],
            ['ack',      'info',  '设备已确认下行帧'],
        ];
        

        if ($devId !== null && $devId > 0) {
            $pool = array_values(array_filter($pool, static fn($p) => !in_array($p[0], ['gateway', 'txack'], true)));
        }
        

        if ($type !== null && $type !== '') {
            $pool = array_values(array_filter($pool, static fn($p) => $p[0] === $type));
            if (!$pool) {
                return [];
            }
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            [$type, $level, $msg] = $pool[array_rand($pool)];
            $isGw = in_array($type, ['gateway', 'txack'], true);
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
            return ['error' => '应用名称已存在'];
        }
        

        $appEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['app_eui'] ?? ''));
        if ($appEui === '') {
            $appEui = bin2hex(random_bytes(8));
        } elseif (self::getApplicationByEui($appEui)) {
            return ['error' => 'AppEUI 已存在'];
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
            return ['error' => 'DevEUI 已存在'];
        }
        

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
        

        $tid = (int) ($app['tenant_id'] ?? 0);
        if ($tid <= 0) {
            $tid = self::createTenantId($p);
        }
        $devQuotaErr = $tid > 0 ? self::checkDeviceQuota($tid) : null;
        if ($devQuotaErr !== null) {
            return ['error' => $devQuotaErr];
        }
        if (Database::fetch("SELECT id FROM devices WHERE app_id=? AND name=?", [$appId, $p['name']])) {
            return ['error' => '该应用下设备名称已存在'];
        }
        $region = $p['region'] ?? ELW_DEFAULT_REGION;
        if (!in_array(strtoupper($region), Region::supported(), true)) {
            return ['error' => 'unsupported region: ' . $region];
        }
        $dpId = (int) ($p['device_profile_id'] ?? 0);
        $dp = DeviceProfile::getOrDefault($dpId);
        if (!$dp) {
            return ['error' => '请先创建设备模板（设备模板为空时不能创建设备）'];
        }
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
            

            $nwkKey = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['nwk_key'] ?? $appKey));
            Database::execute(
                "INSERT INTO devices (app_id, tenant_id, name, dev_eui, join_eui, activation, app_key, nwk_key, region, class, device_profile_id, mac_version, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$appId, $tid, $p['name'], $devEui, $joinEui, 'OTAA', $appKey, $nwkKey, $p['region'] ?? ELW_DEFAULT_REGION, $class, $dpId, $macVersion, 'pending', time()]
            );
        } else { 

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
        if (!empty($p['codec'])) {
            Database::execute("UPDATE devices SET codec=? WHERE id=?", [substr((string) $p['codec'], 0, 8192), Database::lastInsertId()]);
        }
        return ['id' => Database::lastInsertId()];
    }

    /**
     * Bulk import devices (CSV or JSON) into an application.
     * Reuses createDevice() for per-row validation so ABP/OTAA rules stay identical.
     */
    public static function importDevices(int $appId, string $raw, string $format): array
    {
        $app = self::getApplication($appId);
        if (!$app) {
            return ['error' => 'application not found'];
        }
        if (!self::canAccess($app)) {
            return ['error' => 'forbidden: application not in your tenant'];
        }
        $rows = [];
        $format = strtolower(trim($format));
        if ($format === 'json') {
            $dec = json_decode($raw, true);
            if (!is_array($dec)) {
                return ['error' => 'JSON 解析失败'];
            }
            if (isset($dec['rows']) && is_array($dec['rows'])) {
                $dec = $dec['rows'];
            }
            $rows = $dec;
        } else {
            $lines = preg_split('/\r?\n/', trim($raw));
            $lines = array_values(array_filter($lines, function ($l) { return trim($l) !== ''; }));
            if (empty($lines)) {
                return ['error' => '空内容'];
            }
            $header = str_getcsv(array_shift($lines));
            $lower = array_map('strtolower', $header);
            $hasHeader = in_array('dev_eui', $lower, true) || in_array('name', $lower, true);
            if (!$hasHeader) {
                array_unshift($lines, implode(',', $header));
                $header = ['name', 'dev_eui', 'activation', 'app_key', 'join_eui', 'nwk_s_key', 'app_s_key', 'dev_addr', 'region', 'class', 'device_profile_id'];
            }
            foreach ($lines as $ln) {
                $cells = str_getcsv($ln);
                if (count($cells) < 2) {
                    continue;
                }
                $row = [];
                foreach ($header as $i => $h) {
                    $row[strtolower(trim($h))] = $cells[$i] ?? '';
                }
                $rows[] = $row;
            }
        }
        if (empty($rows)) {
            return ['error' => '没有可导入的行'];
        }
        $tid = (int) ($app['tenant_id'] ?? 0);
        $defDp = Database::fetch("SELECT id FROM device_profiles WHERE tenant_id=? ORDER BY id ASC LIMIT 1", [$tid]);
        $defDpId = $defDp ? (int) $defDp['id'] : 0;

        $created = 0;
        $failed = 0;
        $errors = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r)) {
                $failed++;
                $errors[] = ['row' => $i + 1, 'dev_eui' => '', 'error' => '行格式错误'];
                continue;
            }
            $r['app_id'] = $appId;
            if (empty($r['device_profile_id']) && $defDpId > 0) {
                $r['device_profile_id'] = $defDpId;
            }
            $res = self::createDevice($r);
            if (isset($res['error'])) {
                $failed++;
                $errors[] = ['row' => $i + 1, 'dev_eui' => ($r['dev_eui'] ?? ''), 'error' => $res['error']];
            } else {
                $created++;
            }
        }
        return ['created' => $created, 'failed' => $failed, 'errors' => $errors];
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
        $timeout = time() - self::GW_OFFLINE_TIMEOUT; 

        foreach ($rows as &$g) {
            $g['status'] = ((int) ($g['last_seen'] ?? 0) >= $timeout) ? 'online' : 'offline';
            $g['uplinks'] = (int) ($g['uplinks'] ?? 0);
        }
        unset($g);
        return $rows;
    }

    public static function listUplinks(?int $devId = null, ?int $appId = null, int $limit = 200, ?int $tenantId = null, int $offset = 0): array
    {
        if (self::scope()['demo']) {
            return self::demoUplinks($limit > 50 ? 30 : max(1, $limit), $devId, $appId);
        }
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);
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
        $sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";
        return Database::fetchAll($sql, $params);
    }

    public static function listDownlinks(?int $devId = null, ?int $appId = null, int $limit = 200, ?int $tenantId = null, int $offset = 0): array
    {
        if (self::scope()['demo']) {
            return self::demoDownlinks($limit > 50 ? 20 : max(1, $limit), $devId, $appId);
        }
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);
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
        $sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";
        return Database::fetchAll($sql, $params);
    }

    public static function listEvents(?int $devId = null, ?string $gwId = null, ?string $type = null, int $limit = 200, ?int $tenantId = null, int $offset = 0): array
    {
        if (self::scope()['demo']) {
            return self::demoEvents($limit > 50 ? 25 : max(1, $limit), $devId, $gwId, $type);
        }
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);
        $type = ($type !== null && $type !== '') ? $type : null;
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
        if ($type) {
            $where[] = "type=?";
            $params[] = $type;
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
        $sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";
        return Database::fetchAll($sql, $params);
    }

    




    public static function countUplinks(?int $devId = null, ?int $appId = null, ?int $tenantId = null): int
    {
        if (self::scope()['demo']) { return 120; }
        $where = []; $params = [];
        if ($devId) { $where[] = 'dev_id=?'; $params[] = $devId; }
        if ($appId) { $where[] = 'app_id=?'; $params[] = $appId; }
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds !== null) { if (!$appIds) return 0; $where[] = 'app_id IN (' . implode(',', $appIds) . ')'; }
        $sql = 'SELECT COUNT(*) AS c FROM uplinks' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        return (int) Database::fetch($sql, $params)['c'];
    }

    public static function countDownlinks(?int $devId = null, ?int $appId = null, ?int $tenantId = null): int
    {
        if (self::scope()['demo']) { return 60; }
        $where = []; $params = [];
        if ($devId) { $where[] = 'dev_id=?'; $params[] = $devId; }
        if ($appId) { $where[] = 'app_id=?'; $params[] = $appId; }
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds !== null) { if (!$appIds) return 0; $where[] = 'app_id IN (' . implode(',', $appIds) . ')'; }
        $sql = 'SELECT COUNT(*) AS c FROM downlinks' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        return (int) Database::fetch($sql, $params)['c'];
    }

    public static function countEvents(?int $devId = null, ?string $gwId = null, ?string $type = null, ?int $tenantId = null): int
    {
        if (self::scope()['demo']) { return 80; }
        $type = ($type !== null && $type !== '') ? $type : null;
        $where = []; $params = [];
        if ($devId) { $where[] = 'dev_id=?';     $params[] = $devId; }
        if ($gwId)  { $where[] = 'gateway_id=?'; $params[] = $gwId; }
        if ($type)  { $where[] = 'type=?';       $params[] = $type; }
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds !== null) { if (!$appIds) return 0; $where[] = 'app_id IN (' . implode(',', $appIds) . ')'; }
        $sql = 'SELECT COUNT(*) AS c FROM events' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        return (int) Database::fetch($sql, $params)['c'];
    }

    /**
     * 按 ID 取单条上行记录（带租户隔离）。
     * 找不到或越权时返回 null。
     */
    public static function getUplink(int $id, ?int $tenantId = null): ?array
    {
        if ($id <= 0) { return null; }
        if (self::scope()['demo']) {
            $rows = self::demoUplinks(50, null, null);
            foreach ($rows as $r) { if ((int) ($r['id'] ?? 0) === $id) { return $r; } }
            return null;
        }
        $row = Database::fetch("SELECT * FROM uplinks WHERE id=?", [$id]);
        if (!$row) { return null; }
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds !== null) {
            $appId = (int) ($row['app_id'] ?? 0);
            if (!in_array($appId, $appIds, true)) { return null; }
        }
        return $row;
    }

    /**
     * 按 ID 取单条下行记录（带租户隔离）。
     */
    public static function getDownlink(int $id, ?int $tenantId = null): ?array
    {
        if ($id <= 0) { return null; }
        if (self::scope()['demo']) {
            $rows = self::demoDownlinks(50, null, null);
            foreach ($rows as $r) { if ((int) ($r['id'] ?? 0) === $id) { return $r; } }
            return null;
        }
        $row = Database::fetch("SELECT * FROM downlinks WHERE id=?", [$id]);
        if (!$row) { return null; }
        $appIds = self::visibleAppIds($tenantId);
        if ($appIds !== null) {
            $appId = (int) ($row['app_id'] ?? 0);
            if (!in_array($appId, $appIds, true)) { return null; }
        }
        return $row;
    }

    public static function enqueueDownlink(int $devId, int $port, string $payloadHex, bool $confirmed, bool $mac = false): array
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
        if ($mac) {
            $port = 0;
        } elseif ($port < 1 || $port > 223) {
            return ['error' => 'port must be 1..223'];
        }
        Database::execute(
            "INSERT INTO downlinks (dev_id, app_id, port, payload_hex, confirmed, mac, status, created_at)
             VALUES (?,?,?,?,?,?,?,?)",
            [$devId, $device['app_id'], $port, strtolower($payloadHex), $confirmed ? 1 : 0, $mac ? 1 : 0, 'pending', time()]
        );
        return ['id' => Database::lastInsertId(), 'status' => 'pending'];
    }

    


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
            return ['error' => '应用名称已存在'];
        }
        $appEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['app_eui'] ?? ''));
        if ($appEui !== '') {
            $dupEui = Database::fetch("SELECT id FROM applications WHERE app_eui=? AND id<>?", [$appEui, $id]);
            if ($dupEui) {
                return ['error' => 'AppEUI 已存在'];
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
                return ['error' => '该应用下设备名称已存在'];
            }
        }
        $class = strtoupper($p['class'] ?? $device['class']);
        if (!in_array($class, ['A', 'B', 'C'], true)) {
            return ['error' => 'class must be A/B/C'];
        }
        $region = $p['region'] ?? $device['region'];
        $setParts = ['name=?', 'class=?', 'region=?'];
        $params = [$name, $class, $region];
        if (array_key_exists('codec', $p)) {
            $setParts[] = 'codec=?';
            $params[] = substr((string) ($p['codec'] ?? ''), 0, 8192);
        }
        if (array_key_exists('device_profile_id', $p)) {
            $setParts[] = 'device_profile_id=?';
            $params[] = (int) $p['device_profile_id'];
            

            $dp = DeviceProfile::getOrDefault((int) $p['device_profile_id']);
            if (!$dp) {
                return ['error' => '请选择有效的设备模板（模板不存在或已被删除）'];
            }
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

        

        if ($device['activation'] === 'OTAA') {
            if (!empty($p['dev_eui'])) {
                $devEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['dev_eui']));
                if (strlen($devEui) === 16) {
                    if (Database::fetch("SELECT id FROM devices WHERE dev_eui=? AND id<>?", [$devEui, $id])) {
                        return ['error' => 'DevEUI 已存在'];
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
            return ['error' => '网关已存在'];
        }
        $region = strtoupper($p['region'] ?? '');
        if ($region && !in_array($region, Region::supported(), true)) {
            return ['error' => 'unsupported region'];
        }
        $tid = self::createTenantId($p);
        

        $t = $tid > 0 ? Tenant::get($tid) : null;
        $quotaErr = $tid > 0 ? self::checkGatewayQuota($tid) : null;
        if ($quotaErr !== null) {
            return ['error' => $quotaErr];
        }
        if ($t) {
            $unlimited = (int) ($t['private_gateways_unlimited'] ?? 0) === 1;
            $limit = max(0, (int) ($t['private_gateways_limit'] ?? 0));
            if (!$unlimited && $limit > 0 && self::quotaForTenant($tid)['source'] === 'tenant') {
                $count = (int) Database::fetch(
                    "SELECT COUNT(*) AS c FROM gateways WHERE tenant_id=?",
                    [$tid]
                )['c'];
                if ($count >= $limit) {
                    return ['error' => '该用户配置的私有网关数量已达上限（' . $limit . '），请先在「用户配置」中调整上限或开启无限制'];
                }
            }
        }
        Database::execute(
            "INSERT INTO gateways (gw_id, tenant_id, name, region, created_at, last_seen, ip, rf_config, latitude, longitude, altitude) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$gwId, $tid, $p['name'], $region, time(), 0, '', self::rfConfigJson($p['rf_config'] ?? null),
             self::parseCoord($p['latitude'] ?? null), self::parseCoord($p['longitude'] ?? null),
             self::parseAlt($p['altitude'] ?? null)]
        );
        return ['gw_id' => $gwId];
    }

    public static function updateGateway(string $gwId, array $p): array
    {
        

        if (!self::getGateway($gwId)) {
            return ['error' => 'gateway not found or forbidden'];
        }
        $region = strtoupper($p['region'] ?? '');
        if ($region && !in_array($region, Region::supported(), true)) {
            return ['error' => 'unsupported region'];
        }
        // 手动 GPS 坐标：不依赖网关 stat 上报（很多网关 forwarder 不发 GPS）
        Database::execute(
            "UPDATE gateways SET name=?, region=?, rf_config=?, latitude=?, longitude=?, altitude=? WHERE gw_id=?",
            [$p['name'] ?? '', $region, self::rfConfigJson($p['rf_config'] ?? null),
             self::parseCoord($p['latitude'] ?? null), self::parseCoord($p['longitude'] ?? null),
             self::parseAlt($p['altitude'] ?? null), $gwId]
        );
        return ['gw_id' => $gwId];
    }

    /**
     * 网关射频配置：接受数组/对象或 JSON 字符串，统一存为 JSON 字符串。
     */
    private static function rfConfigJson($v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if (is_string($v)) {
            $dec = json_decode($v, true);
            return ($dec !== null) ? $v : '';
        }
        if (is_array($v) || is_object($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return '';
    }

    /**
     * 解析经纬度：空/非法返回 null（前端 hasCoord 视为「无坐标」）。
     */
    private static function parseCoord($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $f = (float) $v;
        return is_finite($f) ? $f : null;
    }

    /**
     * 解析海拔（米）：空/非法返回 null。
     */
    private static function parseAlt($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $f = (float) $v;
        return is_finite($f) ? $f : null;
    }

    public static function deleteGateway(string $gwId): array
    {
        

        if (!self::getGateway($gwId)) {
            return ['error' => 'gateway not found or forbidden'];
        }
        Database::execute("DELETE FROM gateways WHERE gw_id=?", [$gwId]);
        return ['ok' => true];
    }

    


    public static function listUsers(): array
    {
        $cur = Auth::currentUser();
        if (!$cur) return [];
        

        if ($cur['role'] === Auth::ROLE_ADMIN) {
            return Database::fetchAll(
                "SELECT u.id, u.username, u.email, u.role, u.tenant_id, COALESCE(t.name,'') AS tenant_name,
                        u.role_id, u.department_id, COALESCE(r.name,'') AS role_name, COALESCE(d.name,'') AS department_name, u.created_at
                 FROM users u
                 LEFT JOIN tenants t ON t.id=u.tenant_id
                 LEFT JOIN roles r ON r.id=u.role_id
                 LEFT JOIN departments d ON d.id=u.department_id
                 ORDER BY u.id DESC"
            );
        }
        return [[
            'id' => $cur['id'], 'username' => $cur['username'], 'email' => $cur['email'] ?? '', 'role' => $cur['role'],
            'tenant_id' => (int) ($cur['tenant_id'] ?? 0), 'tenant_name' => '', 'created_at' => 0,
            'role_id' => (int) ($cur['role_id'] ?? 0), 'department_id' => (int) ($cur['department_id'] ?? 0),
            'role_name' => '', 'department_name' => '',
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
        

        if ($cur['role'] === Auth::ROLE_OPERATOR) {
            return ['error' => 'forbidden: operator is read-only'];
        }
        

        if ($cur['role'] !== Auth::ROLE_ADMIN && (int)$cur['id'] !== $targetUserId) {
            return ['error' => 'forbidden: can only change own password'];
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        Database::execute("UPDATE users SET password_hash=? WHERE id=?", [$hash, $targetUserId]);
        

        Database::execute("DELETE FROM auth_tokens WHERE user_id=?", [$targetUserId]);
        return ['ok' => true];
    }

    public static function deleteUser(int $id): array
    {
        $cur = Auth::currentUser();
        if (!$cur) {
            return ['error' => 'not authenticated'];
        }
        

        if ((int)$cur['id'] === $id) {
            return ['error' => 'cannot delete self'];
        }
        

        if ($cur['role'] !== Auth::ROLE_ADMIN) {
            return ['error' => 'forbidden'];
        }
        Database::execute("DELETE FROM users WHERE id=?", [$id]);
        return ['ok' => true];
    }

    public static function updateUser(int $id, array $p): array
    {
        $cur = Auth::currentUser();
        if (!$cur) {
            return ['error' => 'not authenticated'];
        }
        if ($cur['role'] !== Auth::ROLE_ADMIN) {
            return ['error' => 'forbidden'];
        }
        $u = Database::fetch("SELECT * FROM users WHERE id=?", [$id]);
        if (!$u) {
            return ['error' => 'user not found'];
        }
        $role = $p['role'] ?? $u['role'];
        if (!in_array($role, Auth::ROLES, true)) {
            return ['error' => 'invalid role'];
        }
        if ((int)$cur['id'] === $id && $role !== Auth::ROLE_ADMIN) {
            return ['error' => 'cannot change own role'];
        }
        $tid = (int) ($p['tenant_id'] ?? $u['tenant_id'] ?? 0);
        if ($role === Auth::ROLE_TENANT) {
            if ($tid <= 0 && !empty($p['new_tenant_name'])) {
                $name = trim($p['new_tenant_name']);
                $exists = Database::fetch("SELECT id FROM tenants WHERE name=?", [$name]);
                if ($exists) {
                    $tid = (int) $exists['id'];
                } else {
                    Database::execute(
                        "INSERT INTO tenants (name, description, private_gateways_limit, private_gateways_unlimited, created_at) VALUES (?,?,0,0,?)",
                        [$name, '', time()]
                    );
                    $tid = Database::lastInsertId();
                }
            }
            if ($tid <= 0) {
                return ['error' => 'tenant role requires a tenant'];
            }
        } else {
            $tid = 0;
        }
        $email = strtolower(trim((string) ($p['email'] ?? $u['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'invalid email'];
        }
        $roleId = (int) ($p['role_id'] ?? $u['role_id'] ?? 0);
        if ($roleId > 0 && !Role::get($roleId)) {
            return ['error' => 'invalid role_id'];
        }
        $deptId = (int) ($p['department_id'] ?? $u['department_id'] ?? 0);
        if ($deptId > 0 && !Department::get($deptId)) {
            return ['error' => 'invalid department_id'];
        }
        Database::execute("UPDATE users SET role=?, tenant_id=?, email=?, role_id=?, department_id=? WHERE id=?", [$role, $tid, $email, $roleId, $deptId, $id]);
        return ['ok' => true];
    }

    public static function avatarUrl(?string $email): string
    {
        $email = strtolower(trim((string) ($email ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'https://gravatar.webp.se/avatar/00000000000000000000000000000000?s=40&d=mp';
        }
        return 'https://gravatar.webp.se/avatar/' . md5($email) . '?s=40&d=retro';
    }

    public static function getStats(): array
    {
        

        $s = self::scope();
        if ($s['demo']) {
            $devs = 4; $gws = 3; $ups = 1280; $dls = 560;
            return [
                'applications' => 1, 'devices' => $devs, 'gateways' => $gws,
                'gateways_online' => 2, 'gateways_offline' => 1,
                'uplinks' => $ups, 'downlinks' => $dls,
                'devices_online' => 3, 'devices_offline' => 1,
                'device_profiles' => 3, 'multicast_groups' => 2,
                'device_logs' => self::demoUplinks(5),
                'gateway_logs' => self::demoEvents(5),
            ];
        }
        $tid = self::effectiveTenant();
        $appIds = self::visibleAppIds();
        $appClause = null;   

        if ($appIds !== null) {
            if (!$appIds) {
                

                return [
                    'applications' => 0, 'devices' => 0, 'gateways' => 0,
                    'gateways_online' => 0, 'gateways_offline' => 0,
                    'uplinks' => 0, 'downlinks' => 0,
                    'devices_online' => 0, 'devices_offline' => 0,
                    'device_profiles' => 0, 'multicast_groups' => 0,
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
        

        $dps = $tid !== null
            ? Database::fetch("SELECT COUNT(*) c FROM device_profiles WHERE tenant_id=?", [$tid])['c']
            : Database::fetch("SELECT COUNT(*) c FROM device_profiles")['c'];
        $mcs = $appFilter("SELECT COUNT(*) c FROM multicast_groups")['c'];
        $gwsOnline = $tid !== null
            ? Database::fetch("SELECT COUNT(*) c FROM gateways WHERE tenant_id=? AND last_seen >= ?", [$tid, time() - self::GW_OFFLINE_TIMEOUT])['c']
            : Database::fetch("SELECT COUNT(*) c FROM gateways WHERE last_seen >= ?", [time() - self::GW_OFFLINE_TIMEOUT])['c'];
        

        $devsOnline = $appFilter(
            "SELECT COUNT(*) c FROM devices WHERE status='active' AND last_seen >= ?",
            [time() - self::DEV_OFFLINE_TIMEOUT]
        )['c'];
        $devsOffline = max(0, (int)$devs - (int)$devsOnline);
        

        $deviceLogs = Database::fetchAll(
            "SELECT id, dev_id, dev_addr, fcnt, port, rssi, snr, decrypted_hex, payload_hex, received_at FROM uplinks"
            . ($appClause !== null ? " WHERE $appClause" : '') . " ORDER BY id DESC LIMIT 5"
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
            'device_profiles' => (int) $dps, 'multicast_groups' => (int) $mcs,
            'device_logs' => $deviceLogs, 'gateway_logs' => $gatewayLogs,
        ];
    }

    public static function regions(): array
    {
        return Region::supported();
    }

    


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

    public static function listThingModels(int $appId): array
    {
        return ThingModel::list($appId, self::effectiveTenant());
    }

    public static function getThingModel(int $id): ?array
    {
        $m = ThingModel::get($id);
        if ($m) {
            $m['fields'] = ThingModel::fields($m);
            $m['fields_json'] = '';
        }
        return $m;
    }

    public static function createThingModel(array $body): array
    {
        $appId = (int) ($body['application_id'] ?? 0);
        if ($appId <= 0 || !self::appInScope($appId)) {
            return ['error' => 'application_out_of_scope'];
        }
        $body['tenant_id'] = self::createTenantId($body);
        return ThingModel::create($body);
    }

    public static function updateThingModel(int $id, array $body): array
    {
        $m = ThingModel::get($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($m)) {
            return ['error' => 'forbidden: thing model not in your tenant'];
        }
        return ThingModel::update($id, $body);
    }

    public static function deleteThingModel(int $id): array
    {
        $m = ThingModel::get($id);
        if (!$m) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($m)) {
            return ['error' => 'forbidden: thing model not in your tenant'];
        }
        return ThingModel::delete($id);
    }

    public static function deviceFields(int $devId): array
    {
        $d = Database::fetch("SELECT id, app_id, tenant_id, name FROM devices WHERE id=?", [$devId]);
        if (!$d || !self::canAccess($d)) {
            return ['fields' => [], 'model' => null];
        }
        $model = ThingModel::byApp((int) $d['app_id']);
        return [
            'fields' => ThingModel::latestByDevice($devId),
            'model'  => $model ? array_merge(['fields' => ThingModel::fields($model)], $model) : null,
        ];
    }

    public static function queryDeviceReadings(int $devId, string $fieldKey, int $from, int $to): array
    {
        $d = Database::fetch("SELECT id, app_id, tenant_id, name FROM devices WHERE id=?", [$devId]);
        if (!$d || !self::canAccess($d)) {
            return ['error' => 'forbidden'];
        }
        return ['data' => ThingModel::readings($devId, $fieldKey, $from, $to)];
    }

    // ---------------- 告警管理 ----------------

    public static function listAlertRules(?int $appId): array
    {
        return Alert::listRules((int) $appId, self::effectiveTenant());
    }

    public static function createAlertRule(array $body): array
    {
        $appId = (int) ($body['application_id'] ?? 0);
        if ($appId <= 0 || !self::appInScope($appId)) {
            return ['error' => 'application_out_of_scope'];
        }
        if (trim((string) ($body['field_key'] ?? '')) === '') {
            return ['error' => 'field_key_required'];
        }
        if (!in_array(strtolower((string) ($body['operator'] ?? '')), Alert::OPERATORS, true)) {
            return ['error' => 'invalid_operator'];
        }
        $body['tenant_id'] = self::createTenantId($body);
        return Alert::createRule($body);
    }

    public static function updateAlertRule(int $id, array $body): array
    {
        $r = Alert::getRule($id);
        if (!$r) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($r)) {
            return ['error' => 'forbidden: rule not in your tenant'];
        }
        return Alert::updateRule($id, $body);
    }

    public static function deleteAlertRule(int $id): array
    {
        $r = Alert::getRule($id);
        if (!$r) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($r)) {
            return ['error' => 'forbidden: rule not in your tenant'];
        }
        return Alert::deleteRule($id);
    }

    public static function listAlerts(int $limit, int $offset, ?int $deviceId = null, string $status = ''): array
    {
        return [
            'data' => Alert::listAlerts(self::effectiveTenant(), $limit, $offset, $deviceId, $status),
            'counts' => Alert::counts(self::effectiveTenant()),
        ];
    }

    public static function activeAlerts(int $limit = 100): array
    {
        return ['data' => Alert::activeAlerts(self::effectiveTenant(), $limit)];
    }

    public static function alertCounts(): array
    {
        return Alert::counts(self::effectiveTenant());
    }

    public static function resolveAlert(int $alertId): array
    {
        $a = Database::fetch("SELECT tenant_id FROM alerts WHERE id=?", [$alertId]);
        if (!$a || !self::canAccess($a)) {
            return ['error' => 'forbidden'];
        }
        return Alert::resolve($alertId);
    }

    public static function listNotificationGroups(): array
    {
        return ['data' => Alert::listGroups(self::effectiveTenant())];
    }

    public static function createNotificationGroup(array $body): array
    {
        $body['tenant_id'] = self::createTenantId($body);
        $r = Alert::createGroup($body);
        if (isset($r['error'])) {
            return $r;
        }
        return ['id' => $r['id']];
    }

    public static function updateNotificationGroup(int $id, array $body): array
    {
        $g = Alert::getGroup($id);
        if (!$g) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($g)) {
            return ['error' => 'forbidden: group not in your tenant'];
        }
        return Alert::updateGroup($id, $body);
    }

    public static function deleteNotificationGroup(int $id): array
    {
        $g = Alert::getGroup($id);
        if (!$g) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($g)) {
            return ['error' => 'forbidden: group not in your tenant'];
        }
        return Alert::deleteGroup($id);
    }

    // ---------------- 定时任务 ----------------

    public static function listScheduledTasks(): array
    {
        $rows = ScheduledTask::list(self::effectiveTenant());
        $now = time();
        foreach ($rows as &$row) {
            $row['enabled_fmt'] = $row['enabled'] ? 1 : 0;
            $row['next_run_at'] = (int) ($row['next_run_at'] ?? 0);
            $row['last_run_at'] = (int) ($row['last_run_at'] ?? 0);
        }
        return ['data' => $rows];
    }

    public static function createScheduledTask(array $body): array
    {
        $deviceId = (int) ($body['device_id'] ?? 0);
        $d = $deviceId > 0 ? Database::fetch("SELECT id, app_id, tenant_id FROM devices WHERE id=?", [$deviceId]) : null;
        if (!$d || !self::appInScope((int) $d['app_id'])) {
            return ['error' => 'device_out_of_scope'];
        }
        $body['tenant_id'] = self::createTenantId($body);
        $r = ScheduledTask::create($body);
        if (isset($r['error'])) {
            return $r;
        }
        return $r;
    }

    public static function updateScheduledTask(int $id, array $body): array
    {
        $task = ScheduledTask::get($id);
        if (!$task) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($task)) {
            return ['error' => 'forbidden: task not in your tenant'];
        }
        if (isset($body['device_id']) && (int) $body['device_id'] > 0) {
            $d = Database::fetch("SELECT app_id, tenant_id FROM devices WHERE id=?", [(int) $body['device_id']]);
            if (!$d || !self::appInScope((int) $d['app_id'])) {
                return ['error' => 'device_out_of_scope'];
            }
        }
        return ScheduledTask::update($id, $body);
    }

    public static function deleteScheduledTask(int $id): array
    {
        $task = ScheduledTask::get($id);
        if (!$task) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($task)) {
            return ['error' => 'forbidden: task not in your tenant'];
        }
        return ScheduledTask::delete($id);
    }

    public static function toggleScheduledTask(int $id, bool $enabled): array
    {
        $task = ScheduledTask::get($id);
        if (!$task) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($task)) {
            return ['error' => 'forbidden: task not in your tenant'];
        }
        return ScheduledTask::setEnabled($id, $enabled);
    }

    public static function runScheduledTask(int $id): array
    {
        $task = ScheduledTask::get($id);
        if (!$task) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($task)) {
            return ['error' => 'forbidden: task not in your tenant'];
        }
        $r = ScheduledTask::run($task);
        if (isset($r['error'])) {
            return $r;
        }
        return ['id' => $r['id'], 'downlink_id' => $r['downlink_id'], 'next_run_at' => $r['next_run_at'], 'ok' => 1];
    }

    // ---------------- 联动模型（自动化） ----------------

    public static function listAutomations(): array
    {
        $rows = Automation::list(self::effectiveTenant());
        foreach ($rows as &$row) {
            $row['fired_count'] = (int) ($row['fired_count'] ?? 0);
            $row['last_fired_at'] = (int) ($row['last_fired_at'] ?? 0);
        }
        return ['data' => $rows];
    }

    public static function createAutomation(array $body): array
    {
        $appId = (int) ($body['application_id'] ?? 0);
        if (isset($body['trigger_device_id']) && (int) $body['trigger_device_id'] > 0) {
            $d = Database::fetch("SELECT app_id FROM devices WHERE id=?", [(int) $body['trigger_device_id']]);
            if (!$d || !self::appInScope((int) $d['app_id'])) {
                return ['error' => 'trigger_device_out_of_scope'];
            }
        }
        if (!self::appInScope($appId)) {
            return ['error' => 'application_out_of_scope'];
        }
        $body['tenant_id'] = self::createTenantId($body);
        return Automation::create($body);
    }

    public static function updateAutomation(int $id, array $body): array
    {
        $row = Automation::get($id);
        if (!$row) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($row)) {
            return ['error' => 'forbidden: automation not in your tenant'];
        }
        return Automation::update($id, $body);
    }

    public static function deleteAutomation(int $id): array
    {
        $row = Automation::get($id);
        if (!$row) {
            return ['error' => 'not_found'];
        }
        if (!self::canAccess($row)) {
            return ['error' => 'forbidden: automation not in your tenant'];
        }
        return Automation::delete($id);
    }

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

    public static function permissionCatalog(): array
    {
        return Auth::PERMISSION_CATALOG;
    }

    public static function listRoles(): array
    {
        $s = self::scope();
        $rows = Role::list($s['is_admin'] ? null : $s['tenant_id']);
        foreach ($rows as &$row) {
            $row['permissions'] = json_decode((string) ($row['permissions'] ?? ''), true) ?: [];
        }
        unset($row);
        return ['data' => $rows, 'catalog' => Auth::PERMISSION_CATALOG];
    }
    public static function createRole(array $p): array
    {
        $s = self::scope();
        if (!$s['can_write']) {
            return ['error' => 'forbidden'];
        }
        $p['tenant_id'] = $s['is_admin'] ? (int) ($p['tenant_id'] ?? 0) : $s['tenant_id'];
        return Role::create($p);
    }
    public static function updateRole(int $id, array $p): array
    {
        $s = self::scope();
        if (!$s['can_write']) {
            return ['error' => 'forbidden'];
        }
        return Role::update($id, $p);
    }
    public static function deleteRole(int $id): array
    {
        $s = self::scope();
        if (!$s['can_write']) {
            return ['error' => 'forbidden'];
        }
        return Role::delete($id);
    }

    public static function listDepartments(): array
    {
        $s = self::scope();
        $rows = Department::list($s['is_admin'] ? null : $s['tenant_id']);
        return ['data' => Department::tree($rows)];
    }
    public static function createDepartment(array $p): array
    {
        $s = self::scope();
        if (!$s['can_write']) {
            return ['error' => 'forbidden'];
        }
        $p['tenant_id'] = (int) ($p['tenant_id'] ?? ($s['is_admin'] ? 0 : $s['tenant_id']));
        return Department::create($p);
    }
    public static function updateDepartment(int $id, array $p): array
    {
        $s = self::scope();
        if (!$s['can_write']) {
            return ['error' => 'forbidden'];
        }
        return Department::update($id, $p);
    }
    public static function deleteDepartment(int $id): array
    {
        $s = self::scope();
        if (!$s['can_write']) {
            return ['error' => 'forbidden'];
        }
        return Department::delete($id);
    }

    


    public static function listApiKeys(int $applicationId, ?int $tenantId = null): array
    {
        

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

    


    public static function listIntegrations(int $applicationId, ?int $tenantId = null): array
    {
        

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
        

        $inGroup = Database::fetch(
            "SELECT multicast_group_id FROM multicast_group_devices WHERE multicast_group_id=? AND LOWER(dev_eui)=?",
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

    
    public static function clearLogs(string $target, ?int $tenantId = null): array
    {
        $tables = [
            'api' => 'api_logs',
            'uplinks' => 'uplinks',
            'downlinks' => 'downlinks',
            'events' => 'events',
        ];
        if (!isset($tables[$target])) {
            return ['error' => 'invalid log target'];
        }
        $s = self::scope();
        // 非管理员只能清理自己租户的日志；管理员若传了 tenant_id 则按租户清理，否则清理全部
        $tid = $s['is_admin']
            ? ($tenantId && $tenantId > 0 ? (int) $tenantId : null)
            : ($s['tenant_id'] ?: null);
        $tbl = $tables[$target];
        if ($tid === null) {
            Database::execute("DELETE FROM " . $tbl);
        } elseif ($target === 'api') {
            Database::execute("DELETE FROM api_logs WHERE tenant_id=?", [$tid]);
        } elseif ($target === 'events') {
            Database::execute(
                "DELETE FROM events WHERE dev_id IN (SELECT id FROM devices WHERE tenant_id=?) "
                . "OR gateway_id IN (SELECT gw_id FROM gateways WHERE tenant_id=?)",
                [$tid, $tid]
            );
        } else {
            // uplinks / downlinks：通过应用归属租户过滤
            Database::execute("DELETE FROM " . $tbl . " WHERE app_id IN (SELECT id FROM applications WHERE tenant_id=?)", [$tid]);
        }
        return ['target' => $target, 'tenant_id' => $tid, 'cleared' => true];
    }
}
