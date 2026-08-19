<?php
namespace holastack\Core;







class Multicast
{
    public static function buildDownlink(array $group, int $fPort, string $payloadHex): string
    {
        $mcNwk = hex2bin($group['mc_nwk_s_key']);
        $mcApp = hex2bin($group['mc_app_s_key']);
        $devAddr = hex2bin($group['mc_addr']);
        $fcnt = (int) $group['f_cnt'];
        return Frame::buildDataDown($mcNwk, $mcApp, 1, $devAddr, $fcnt, false, false, $fPort, hex2bin($payloadHex), 0, '');
    }

    public static function generateSession(): array
    {
        return [
            'mc_addr'      => bin2hex(random_bytes(4)),
            'mc_nwk_s_key' => bin2hex(random_bytes(16)),
            'mc_app_s_key' => bin2hex(random_bytes(16)),
        ];
    }

    public static function groupGateways(int $groupId): array
    {
        return \holastack\DB\Database::fetchAll(
            "SELECT gw_id FROM multicast_group_gateways WHERE multicast_group_id=?",
            [$groupId]
        );
    }

    public static function groupDevices(int $groupId): array
    {
        return \holastack\DB\Database::fetchAll(
            "SELECT dev_eui FROM multicast_group_devices WHERE multicast_group_id=?",
            [$groupId]
        );
    }

    public static function addDevice(int $groupId, string $devEui): void
    {
        $devEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $devEui));
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
