<?php
namespace holastack\Core;

use holastack\DB\Database;
use holastack\Crypto\AES;

/**
 * LoRaWAN 1.1 中继（Relay, TS011）。
 *
 * Relay 让信号不佳的终端（End-Device）借助附近「中继网关」转发上下行，协议层：
 *   - Relay 与 NS 之间用 Relay MAC 命令（RelayConfReq / RelayAddEdMetadataReq /
 *     RelayEDCtrlReq / RelayEndDevConfReq …）完成中继会话与终端登记；
 *   - 终端→中继的上行帧前会加上 1 字节「Relay Header」（含 EdIdType / FrameType /
 *     IsUplink / WOR），中继据此识别并二次转发给网关；
 *   - 中继→终端的下行帧同理由中继解封装后转发。
 *
 * 本类职责：relay_gateways / relay_devices 表 CRUD、中继会话/终端登记、Relay MAC
 * 命令构造、以及 ED↔Relay 帧头的封装/解封装。会话密钥直接复用终端 OTAA 派生结果。
 */
class Relay
{
    // Relay MAC 命令 CID（TS011-1.0.0）
    public const CID_RELAY_CONF_REQ            = 0x70;
    public const CID_RELAY_ADD_ED_METADATA_REQ = 0x71;
    public const CID_RELAY_ED_CTRL_REQ         = 0x72;
    public const CID_RELAY_ED_CONF_REQ         = 0x73;

    // Relay Header：EdIdType（2bit）
    public const ED_ID_NONE    = 0x00;   // 不含 DevAddr
    public const ED_ID_16BIT   = 0x01;   // 16 位短地址
    public const ED_ID_32BIT   = 0x02;   // 24/32 位 DevAddr

    // Relay Header：FrameType（2bit）
    public const FRAME_DATA = 0x00;      // 普通 LoRaWAN 数据帧
    public const FRAME_MAC  = 0x01;      // 仅 MAC 命令
    public const FRAME_PROP = 0x02;      // 厂商自定义

    // ---- relay_gateways CRUD ----

    public static function listGateways(): array
    {
        return Database::fetchAll("SELECT * FROM relay_gateways ORDER BY id DESC");
    }

    public static function getGateway(int $id): ?array
    {
        return Database::fetch("SELECT * FROM relay_gateways WHERE id=?", [$id]);
    }

    public static function addGateway(array $p): array
    {
        if (empty($p['name']) || empty($p['relay_dev_eui'])) {
            return ['error' => 'name and relay_dev_eui required'];
        }
        Database::execute(
            "INSERT INTO relay_gateways (tenant_id, name, relay_dev_eui, region, created_at)
             VALUES (?,?,?,?,?)",
            [
                (int) ($p['tenant_id'] ?? 0),
                $p['name'],
                strtolower(preg_replace('/[^0-9a-f]/', '', $p['relay_dev_eui'])),
                $p['region'] ?? ELW_DEFAULT_REGION,
                time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function deleteGateway(int $id): void
    {
        Database::execute("DELETE FROM relay_devices WHERE relay_gateway_id=?", [$id]);
        Database::execute("DELETE FROM relay_gateways WHERE id=?", [$id]);
    }

    // ---- relay_devices CRUD ----

    public static function listDevices(int $gatewayId): array
    {
        return Database::fetchAll(
            "SELECT * FROM relay_devices WHERE relay_gateway_id=? ORDER BY id DESC",
            [$gatewayId]
        );
    }

    /**
     * 把一台终端登记到中继网关下（复用终端 OTAA 派生的会话密钥）。
     * $keys 来自 Device 或 NS 的 deviceKeySet：nwk_s_key / app_s_key（1.0）或
     * f_nwk_s_int_key / s_nwk_s_int_key / nwk_s_enc_key（1.1）。
     */
    public static function provisionEndDevice(int $gatewayId, string $devEui, array $keys = []): array
    {
        $gw = self::getGateway($gatewayId);
        if (!$gw) {
            return ['error' => 'relay gateway not found'];
        }
        $devEui = strtolower(preg_replace('/[^0-9a-f]/', '', $devEui));
        if (strlen($devEui) !== 16) {
            return ['error' => 'invalid dev_eui'];
        }
        $is11 = !empty($keys['mac_version']) && LoRaWANVersion::is1_1($keys['mac_version']);
        $existing = Database::fetch(
            "SELECT id FROM relay_devices WHERE relay_gateway_id=? AND dev_eui=?",
            [$gatewayId, $devEui]
        );
        $cols = "relay_gateway_id=?, dev_eui=?, dev_addr=?, nwk_s_key=?, app_s_key=?,
                 f_nwk_s_int_key=?, s_nwk_s_int_key=?, nwk_s_enc_key=?, mac_version=?, created_at=?";
        $params = [
            $gatewayId, $devEui,
            $keys['dev_addr'] ?? '',
            $keys['nwk_s_key'] ?? '',
            $keys['app_s_key'] ?? '',
            $keys['f_nwk_s_int_key'] ?? '',
            $keys['s_nwk_s_int_key'] ?? '',
            $keys['nwk_s_enc_key'] ?? '',
            $is11 ? '1.1' : '1.0.3',
            time(),
        ];
        if ($existing) {
            $params[] = $existing['id'];
            Database::execute("UPDATE relay_devices SET $cols WHERE id=?", $params);
            return ['id' => $existing['id']];
        }
        Database::execute(
            "INSERT INTO relay_devices
             (relay_gateway_id, dev_eui, dev_addr, nwk_s_key, app_s_key,
              f_nwk_s_int_key, s_nwk_s_int_key, nwk_s_enc_key, mac_version, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            array_slice($params, 0, 10)
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function removeEndDevice(int $id): void
    {
        Database::execute("DELETE FROM relay_devices WHERE id=?", [$id]);
    }

    /**
     * 构造 RelayConfReq（CID 0x70）。
     * 字节布局（TS011）：CID + SecondChAckOffset / BackOff / RelayOnly / ... 的 1 字节配置，
     * 及 Activation 字段。此处给出最小可用布局，按规范可调。
     */
    public static function buildRelayConfReq(int $flags = 0x00, int $secondChOffset = 0, int $backoff = 0): string
    {
        // 配置字节：bit7 RelayOnly | bit6-4 SecondChAckOffset | bit3-0 BackOff
        $conf = (($flags & 0x80) << 0) | (($secondChOffset & 0x07) << 4) | ($backoff & 0x0F);
        return chr(self::CID_RELAY_CONF_REQ) . chr($conf);
    }

    /**
     * 构造 RelayAddEdMetadataReq（CID 0x71）。
     * 字节布局（TS011）：CID + EdClass + (ShortDevAddr) + (EndDevAddr) + (EdRate) + (EdDR) + (EdLimitExp)。
     * 这里给出常用字段：EdClass(1) + 32bit DevAddr(4) + EdDR(1)。
     */
    public static function buildRelayAddEdMetadataReq(string $devAddrBin, int $edClass = 0, int $edDr = 0): string
    {
        return chr(self::CID_RELAY_ADD_ED_METADATA_REQ)
            . chr($edClass & 0x01)
            . $devAddrBin
            . chr($edDr & 0x0F);
    }

    /**
     * 把终端上行帧包装为「Relay Header + 原帧」。
     * $phyPayload: 终端原始物理层帧（含 MHDR…MIC）。
     * 返回：1 字节 Relay Header + $phyPayload。
     */
    public static function wrapEdUplink(
        string $phyPayload,
        int $edIdType = self::ED_ID_32BIT,
        int $frameType = self::FRAME_DATA,
        bool $isUplink = true,
        bool $wor = false
    ): string {
        $header = (($edIdType & 0x03) << 6)
                | (($frameType & 0x03) << 4)
                | (($isUplink ? 1 : 0) << 3)
                | (($wor ? 1 : 0) << 2);
        return chr($header & 0xFF) . $phyPayload;
    }

    /**
     * 解封装中继转发来的上行帧，返回 ['header'=>int,'phy'=>原终端帧]。
     */
    public static function unwrapRelayUplink(string $relayFrame): array
    {
        if (strlen($relayFrame) < 1) {
            return ['header' => 0, 'phy' => ''];
        }
        $header = ord($relayFrame[0]);
        return [
            'header' => $header,
            'edIdType'  => ($header >> 6) & 0x03,
            'frameType' => ($header >> 4) & 0x03,
            'isUplink'  => (($header >> 3) & 0x01) === 1,
            'wor'       => (($header >> 2) & 0x01) === 1,
            'phy'      => substr($relayFrame, 1),
        ];
    }
}
