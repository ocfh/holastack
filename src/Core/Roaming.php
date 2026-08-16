<?php
namespace holastack\Core;

use holastack\DB\Database;

/**
 * 漫游（Roaming, Backend Interface TS002）。
 *
 * 当本 NS 收到「非本网」设备的 Join/上行时，按漫游协议把报文转发给伙伴 NetServer：
 *   - Passive Roaming：本地 NS 作为 Serving NS，把 UL 转发给 Home NS（XmitDataReq /
 *     JoinReq），由 Home NS 决策；
 *   - Active Roaming：本地 NS 作为 Home NS，接收伙伴转发来的 UL。
 *
 * BI 报文为 JSON over HTTPS，由 SenderID/ReceiverID（NS NetID）+ AES-CMAC 签名。
 * 本类职责：roaming_servers 表 CRUD、构造 JoinReq / XmitDataReq（BI 1.0）、
 * 以及经 HTTPS POST 转发的骨架（签名需伙伴共享 NS 根密钥，标为 TODO）。
 */
class Roaming
{
    // BI 消息类型
    public const MSG_JOIN_REQ    = 'JoinReq';
    public const MSG_XMIT_DATA   = 'XmitDataReq';
    public const MSG_JOIN_ANS    = 'JoinAns';
    public const MSG_PR_UP_ANS   = 'PrUpdAns';

    /** 本 NS 的标识（NetID，6 hex）。生产应在 config 中定义 ELW_NS_ID。 */
    public static function localNsId(): string
    {
        return defined('ELW_NS_ID') ? ELW_NS_ID : '000000';
    }

    // ---- roaming_servers CRUD ----

    public static function listServers(): array
    {
        return Database::fetchAll("SELECT * FROM roaming_servers ORDER BY id DESC");
    }

    public static function getServer(int $id): ?array
    {
        return Database::fetch("SELECT * FROM roaming_servers WHERE id=?", [$id]);
    }

    public static function addServer(array $p): array
    {
        if (empty($p['name']) || empty($p['server'])) {
            return ['error' => 'name and server(url) required'];
        }
        Database::execute(
            "INSERT INTO roaming_servers
             (tenant_id, name, kind, protocol, server, async_timeout, enabled, created_at)
             VALUES (?,?,?,?,?,?,?,?)",
            [
                (int) ($p['tenant_id'] ?? 0),
                $p['name'],
                $p['kind'] ?? 'PASSIVE',
                $p['protocol'] ?? 'BI_1_0',
                $p['server'],
                (int) ($p['async_timeout'] ?? 250),
                (int) ($p['enabled'] ?? 1),
                time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function updateServer(int $id, array $p): array
    {
        $s = self::getServer($id);
        if (!$s) {
            return ['error' => 'server not found'];
        }
        $set = [];
        $params = [];
        foreach (['name', 'kind', 'protocol', 'server', 'async_timeout', 'enabled'] as $c) {
            if (array_key_exists($c, $p)) {
                $set[] = "$c=?";
                $params[] = is_numeric($p[$c]) ? (int) $p[$c] : $p[$c];
            }
        }
        if (empty($set)) {
            return ['id' => $id];
        }
        $params[] = $id;
        Database::execute("UPDATE roaming_servers SET " . implode(',', $set) . " WHERE id=?", $params);
        return ['id' => $id];
    }

    public static function deleteServer(int $id): void
    {
        Database::execute("DELETE FROM roaming_servers WHERE id=?", [$id]);
    }

    // ---- BI 报文构造（BI 1.0） ----

    /**
     * 构造 JoinReq（BI 1.0）。
     * $join: ['phy'=>base64 原 join-request 物理层, 'mac_version'=>str, 'opt_neg'=>bool,
     *         'dev_eui'=>str, 'dev_addr'=>str, 'dl_settings'=>str, 'rx_delay'=>int, 'cf_list'=>str]
     */
    public static function buildJoinReq(array $server, array $join): array
    {
        return [
            'SenderID'    => self::localNsId(),
            'ReceiverID'  => $server['name'],
            'MessageType' => self::MSG_JOIN_REQ,
            'PHYPayload'  => $join['phy'] ?? '',
            'MACVersion'  => $join['mac_version'] ?? '1.0.3',
            'OptNeg'      => (bool) ($join['opt_neg'] ?? false),
            'DevEUI'      => $join['dev_eui'] ?? '',
            'DevAddr'     => $join['dev_addr'] ?? '',
            'DLSettings'  => $join['dl_settings'] ?? '',
            'RxDelay'     => (int) ($join['rx_delay'] ?? 1),
            'CFList'      => $join['cf_list'] ?? '',
        ];
    }

    /**
     * 构造 XmitDataReq（BI 1.0）。
     * $ul: ['phy'=>base64, 'dev_eui'=>str, 'dev_addr'=>str, 'freq'=>float, 'dr'=>str,
     *       'recv_time'=>int, 'gw_id'=>str, 'rssi'=>int, 'snr'=>float]
     */
    public static function buildXmitDataReq(array $server, array $ul): array
    {
        return [
            'SenderID'    => self::localNsId(),
            'ReceiverID'  => $server['name'],
            'MessageType' => self::MSG_XMIT_DATA,
            'PHYPayload'  => $ul['phy'] ?? '',
            'ULMetaData'  => [
                'DevEUI'    => $ul['dev_eui'] ?? '',
                'DevAddr'   => $ul['dev_addr'] ?? '',
                'ULFreq'    => (float) ($ul['freq'] ?? 0),
                'DataRate'  => $ul['dr'] ?? '',
                'RecvTime'  => (int) ($ul['recv_time'] ?? time()),
                'GWCnt'     => 1,
                'GWInfo'    => [
                    [
                        'ID'   => $ul['gw_id'] ?? '',
                        'RSSI' => (int) ($ul['rssi'] ?? 0),
                        'SNR'  => (float) ($ul['snr'] ?? 0),
                    ],
                ],
            ],
            'NumberOfTransmissions' => 1,
        ];
    }

    /**
     * 把一条 BI 报文经 HTTPS POST 转发给伙伴 NS。
     * 签名（AES-CMAC over SenderID||ReceiverID||MessageType||...，使用共享 NS 根密钥）
     * 为 TODO：需在两边约定密钥后补上，否则伙伴 NS 会拒收。
     *
     * 返回伙伴 NS 的 JSON 应答（解码后数组），失败返回 ['error'=>...]。
     */
    public static function forward(array $server, array $message): array
    {
        if (empty($server['server'])) {
            return ['error' => 'server url empty'];
        }
        $body = json_encode($message);
        if ($body === false) {
            return ['error' => 'json encode failed'];
        }
        $ctx = stream_context_create([
            'http' => [
                'method'           => 'POST',
                'header'           => "Content-Type: application/json\r\n",
                'content'          => $body,
                'timeout'          => (int) ($server['async_timeout'] ?? 250) / 1000 + 5,
                'ignore_errors'    => true,
            ],
        ]);
        $resp = @file_get_contents($server['server'], false, $ctx);
        if ($resp === false) {
            return ['error' => 'http post failed'];
        }
        $dec = json_decode($resp, true);
        return is_array($dec) ? $dec : ['raw' => $resp];
    }

    /**
     * 解析伙伴 NS 的应答（JoinAns / PrUpdAns …）。骨架：仅做字段透传与基本校验。
     */
    public static function handleResponse(array $resp): array
    {
        $type = $resp['MessageType'] ?? '';
        switch ($type) {
            case self::MSG_JOIN_ANS:
                return ['ok' => true, 'type' => 'JoinAns', 'phy' => $resp['PHYPayload'] ?? ''];
            case self::MSG_PR_UP_ANS:
                return ['ok' => true, 'type' => 'PrUpdAns', 'result' => $resp['Result'] ?? null];
            default:
                return ['ok' => true, 'type' => $type, 'raw' => $resp];
        }
    }
}
