<?php
namespace holastack\Storage;

use holastack\DB\Database;
use holastack\Region\Region;







class DeviceProfile
{
    




    public static function list(?int $tenantId = null): array
    {
        if ($tenantId !== null && $tenantId > 0) {
            return Database::fetchAll(
                "SELECT * FROM device_profiles WHERE tenant_id IN (0, ?) ORDER BY id DESC",
                [$tenantId]
            );
        }
        return Database::fetchAll("SELECT * FROM device_profiles ORDER BY id DESC");
    }

    public static function get(int $id): ?array
    {
        return Database::fetch("SELECT * FROM device_profiles WHERE id=?", [$id]);
    }

    public static function getOrDefault(int $id): ?array
    {
        if ($id > 0) {
            $dp = self::get($id);
            if ($dp) {
                return $dp;
            }
        }
        

        $def = self::getDefault();
        if ($def) {
            return $def;
        }
        

        return [
            'id' => 0, 'name' => '默认模板', 'region' => ELW_DEFAULT_REGION,
            'mac_version' => '1.0.4', 'reg_params_revision' => 'RP002-1.0.3',
            'adr_algorithm' => 'default', 'payload_codec_runtime' => 'NONE',
            'payload_codec_script' => '', 'flush_queue_on_activate' => 0,
            'uplink_interval' => 0, 'device_status_req_interval' => 0,
            'supports_otaa' => 1, 'supports_class_b' => 0, 'supports_class_c' => 0,
            'class_b_timeout' => 0, 'class_b_ping_slot_periodicity' => 0, 'class_b_ping_slot_dr' => 0, 'class_b_ping_slot_freq' => 0,
            'class_c_timeout' => 0, 'abp_rx1_delay' => 1, 'abp_rx1_dr_offset' => 0, 'abp_rx2_dr' => 0, 'abp_rx2_freq' => 0,
            'allow_roaming' => 0,
        ];
    }

    public static function getDefault(): ?array
    {
        return Database::fetch("SELECT * FROM device_profiles WHERE name=? ORDER BY id ASC LIMIT 1", ['默认模板']);
    }

    public static function ensureDefault(): void
    {
        if (self::getDefault()) {
            return;
        }
        $cols = self::columns();
        $cols['name'] = '默认模板';
        $cols['region'] = 'EU868';
        $cols['created_at'] = time();
        $set = [];
        $vals = [];
        $params = [];
        foreach ($cols as $c => $def) {
            $set[] = $c;
            $vals[] = '?';
            $params[] = self::cast($c, $def);
        }
        Database::execute(
            "INSERT INTO device_profiles (" . implode(',', $set) . ") VALUES (" . implode(',', $vals) . ")",
            $params
        );
    }

    public static function create(array $p): array
    {
        if (empty($p['name'])) {
            return ['error' => 'name required'];
        }
        $region = $p['region'] ?? ELW_DEFAULT_REGION;
        if (!in_array(strtoupper($region), Region::supported(), true)) {
            return ['error' => 'unsupported region: ' . $region];
        }
        $cols = self::columns();
        $set = [];
        $vals = [];
        $params = [];
        foreach ($cols as $c => $def) {
            $set[] = $c;
            if ($c === 'region') {
                $params[] = $region;
            } elseif ($c === 'created_at') {
                $params[] = time();
            } else {
                $params[] = self::cast($c, $p[$c] ?? $def);
            }
            $vals[] = '?';
        }
        

        if (array_key_exists('tenant_id', $p)) {
            $set[] = 'tenant_id';
            $vals[] = '?';
            $params[] = (int) $p['tenant_id'];
        }
        Database::execute(
            "INSERT INTO device_profiles (" . implode(',', $set) . ") VALUES (" . implode(',', $vals) . ")",
            $params
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function update(int $id, array $p): array
    {
        $dp = self::get($id);
        if (!$dp) {
            return ['error' => 'device profile not found'];
        }
        $cols = self::columns();
        $set = [];
        $params = [];
        foreach ($cols as $c => $def) {
            if ($c === 'created_at' || $c === 'id') {
                continue;
            }
            if (array_key_exists($c, $p)) {
                $set[] = "$c=?";
                $params[] = self::cast($c, $p[$c]);
            }
        }
        if (empty($set)) {
            return ['id' => $id];
        }
        $params[] = $id;
        Database::execute("UPDATE device_profiles SET " . implode(',', $set) . " WHERE id=?", $params);
        return ['id' => $id];
    }

    public static function delete(int $id): array
    {
        

        Database::execute("UPDATE devices SET device_profile_id=0 WHERE device_profile_id=?", [$id]);
        Database::execute("DELETE FROM device_profiles WHERE id=?", [$id]);
        return ['ok' => true];
    }

    

    private static function columns(): array
    {
        return [
            'name' => '', 'description' => '', 'region' => ELW_DEFAULT_REGION,
            'mac_version' => '1.0.4', 'reg_params_revision' => 'RP002-1.0.3',
            'adr_algorithm' => 'default', 'payload_codec_runtime' => 'NONE',
            'payload_codec_script' => '', 'flush_queue_on_activate' => 0,
            'uplink_interval' => 0, 'device_status_req_interval' => 0,
            'supports_otaa' => 1, 'supports_class_b' => 0, 'supports_class_c' => 0,
            'class_b_timeout' => 0, 'class_b_ping_slot_periodicity' => 0, 'class_b_ping_slot_dr' => 0, 'class_b_ping_slot_freq' => 0,
            'class_c_timeout' => 0, 'abp_rx1_delay' => 1, 'abp_rx1_dr_offset' => 0, 'abp_rx2_dr' => 0, 'abp_rx2_freq' => 0,
            'allow_roaming' => 0, 'relay_params' => '', 'created_at' => 0,
        ];
    }

    public static function relayParams(?array $dp): ?array
    {
        if (!$dp || empty($dp['relay_params'])) {
            return null;
        }
        $j = json_decode($dp['relay_params'], true);
        return is_array($j) ? $j : null;
    }

    private static function cast(string $col, $val)
    {
        $ints = [
            'flush_queue_on_activate', 'uplink_interval', 'device_status_req_interval',
            'supports_otaa', 'supports_class_b', 'supports_class_c', 'class_b_timeout',
            'class_b_ping_slot_periodicity', 'class_b_ping_slot_dr', 'class_b_ping_slot_freq',
            'class_c_timeout', 'abp_rx1_delay', 'abp_rx1_dr_offset', 'abp_rx2_dr', 'abp_rx2_freq',
            'allow_roaming',
        ];
        if (in_array($col, $ints, true)) {
            return (int) $val;
        }
        return (string) $val;
    }
}
