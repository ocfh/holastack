<?php
namespace holastack\Core;

/**
 * 组播下发构造与工具（对齐 ChirpStack multicast_group）。
 * 组播使用组级会话密钥（mc_nwk_s_key / mc_app_s_key）与组地址（mc_addr），
 * 不经过任何单设备会话，直接以组播密钥构造下行帧并发往组内（或全部）网关。
 */
class Multicast
{
    /** 以组播密钥构造一条下行物理层帧。 */
    public static function buildDownlink(array $group, int $fPort, string $payloadHex): string
    {
        $mcNwk = hex2bin($group['mc_nwk_s_key']);
        $mcApp = hex2bin($group['mc_app_s_key']);
        $devAddr = hex2bin($group['mc_addr']);
        $fcnt = (int) $group['f_cnt'];
        return Frame::buildDataDown($mcNwk, $mcApp, 1, $devAddr, $fcnt, false, false, $fPort, hex2bin($payloadHex), 0, '');
    }

    /** 随机生成组播会话参数（mc_addr / mc_nwk_s_key / mc_app_s_key）。 */
    public static function generateSession(): array
    {
        return [
            'mc_addr'      => bin2hex(random_bytes(4)),
            'mc_nwk_s_key' => bin2hex(random_bytes(16)),
            'mc_app_s_key' => bin2hex(random_bytes(16)),
        ];
    }

    /** 该组播组关联的网关（无则空数组，调用方回退到全部网关）。 */
    public static function groupGateways(int $groupId): array
    {
        return \holastack\DB\Database::fetchAll(
            "SELECT gw_id FROM multicast_group_gateways WHERE multicast_group_id=?",
            [$groupId]
        );
    }

    /** 该组播组的设备（用于展示/管理，不参与单播下发）。 */
    public static function groupDevices(int $groupId): array
    {
        return \holastack\DB\Database::fetchAll(
            "SELECT dev_eui FROM multicast_group_devices WHERE multicast_group_id=?",
            [$groupId]
        );
    }

    public static function addDevice(int $groupId, string $devEui): void
    {
        $devEui = strtolower(preg_replace('/[^0-9a-f]/', '', $devEui));
        if (strlen($devEui) !== 16) {
            return;
        }
        \holastack\DB\Database::execute(
            "INSERT OR IGNORE INTO multicast_group_devices (multicast_group_id, dev_eui) VALUES (?,?)",
            [$groupId, $devEui]
        );
    }

    public static function removeDevice(int $groupId, string $devEui): void
    {
        \holastack\DB\Database::execute(
            "DELETE FROM multicast_group_devices WHERE multicast_group_id=? AND dev_eui=?",
            [$groupId, strtolower($devEui)]
        );
    }

    public static function addGateway(int $groupId, string $gwId): void
    {
        \holastack\DB\Database::execute(
            "INSERT OR IGNORE INTO multicast_group_gateways (multicast_group_id, gw_id) VALUES (?,?)",
            [$groupId, strtolower($gwId)]
        );
    }

    public static function removeGateway(int $groupId, string $gwId): void
    {
        \holastack\DB\Database::execute(
            "DELETE FROM multicast_group_gateways WHERE multicast_group_id=? AND gw_id=?",
            [$groupId, strtolower($gwId)]
        );
    }
}
