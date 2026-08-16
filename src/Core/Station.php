<?php
namespace holastack\Core;

use holastack\DB\Database;

/**
 * Basic Station / LNS（Semtech LNS 协议 v1/v2 后端）。
 *
 * 与基于 Semtech UDP 包的「传统网关」不同，Basic Station 通过 WebSocket(TLS) 与
 * holastack 的 LNS 端口通信，报文为 JSON（见 Semtech Basic Station 文档）：
 *   站 → NS: version / router_config(请求) / jreq / updf / timesync / rmtsh
 *   NS → 站: router_config(应答) / dnmsg / timesync(应答) / rmtsh(应答)
 *
 * 本类职责：
 *   - stations 表的 CRUD（与 Tenant 关联）；
 *   - 生成 router_config（按 region 返回协议层参数）；
 *   - 解析站上行的 jreq/updf 中的 PHYPayload（base64），交给 NetworkServer 处理；
 *   - 把 NS 要下发的帧封装为 dnmsg 回给站。
 *
 * 注意：真正的 WebSocket 监听需要 Ratchet 之类的依赖（composer 安装），本类提供
 * 协议层逻辑，bin/lns.php 给出接入骨架。
 */
class Station
{
    // Basic Station 报文类型
    public const MSG_VERSION = 'version';
    public const MSG_ROUTER_CONFIG = 'router_config';
    public const MSG_JREQ = 'jreq';
    public const MSG_UPDF = 'updf';
    public const MSG_DNMSG = 'dnmsg';
    public const MSG_TIMESYNC = 'timesync';
    public const MSG_RMTSH = 'rmtsh';

    // ---- stations 表 CRUD ----

    public static function list(): array
    {
        return Database::fetchAll("SELECT * FROM stations ORDER BY id DESC");
    }

    public static function get(int $id): ?array
    {
        return Database::fetch("SELECT * FROM stations WHERE id=?", [$id]);
    }

    public static function create(array $p): array
    {
        if (empty($p['name']) || empty($p['gateway_id'])) {
            return ['error' => 'name and gateway_id required'];
        }
        Database::execute(
            "INSERT INTO stations (tenant_id, gateway_id, name, region, lns_secret, ca_cert, created_at)
             VALUES (?,?,?,?,?,?,?)",
            [
                (int) ($p['tenant_id'] ?? 0),
                strtolower($p['gateway_id']),
                $p['name'],
                $p['region'] ?? ELW_DEFAULT_REGION,
                $p['lns_secret'] ?? bin2hex(random_bytes(16)),
                $p['ca_cert'] ?? '',
                time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function update(int $id, array $p): array
    {
        $s = self::get($id);
        if (!$s) {
            return ['error' => 'station not found'];
        }
        $set = [];
        $params = [];
        foreach (['name', 'gateway_id', 'region', 'lns_secret', 'ca_cert'] as $c) {
            if (array_key_exists($c, $p)) {
                $set[] = "$c=?";
                $params[] = $c === 'gateway_id' ? strtolower($p[$c]) : $p[$c];
            }
        }
        if (empty($set)) {
            return ['id' => $id];
        }
        $params[] = $id;
        Database::execute("UPDATE stations SET " . implode(',', $set) . " WHERE id=?", $params);
        return ['id' => $id];
    }

    public static function delete(int $id): void
    {
        Database::execute("DELETE FROM stations WHERE id=?", [$id]);
    }

    /**
     * 生成 router_config（NS→站 的应答）。
     * NetID 默认放行 0；生产应只放行本 NS 拥有的 NetID。
     */
    public static function routerConfig(array $station): array
    {
        return [
            'msgtype'   => self::MSG_ROUTER_CONFIG,
            'NetID'     => [0],
            'JoinEui'   => [],            // 空 = 接受所有 JoinEui（demo）
            'region'    => $station['region'] ?? ELW_DEFAULT_REGION,
            'hwspec'    => (object) [],
            'class'     => ['A', 'B', 'C'],
            'nocca'     => 0,
            'nodc'      => 0,
            'nodwell'   => 0,
        ];
    }

    /**
     * 处理来自站的一条 JSON 消息，返回需要回送的 JSON（或转交 NS 的 PDU）。
     * 返回结构：
     *   - 含 '_forward' => true 表示 PHYPayload 需交给 NetworkServer 处理；
     *   - 含 '_noop' => true 表示无需回送；
     *   - 否则为需 json_encode 后发回站的报文。
     */
    public static function handleMessage(array $msg): array
    {
        $type = $msg['msgtype'] ?? '';
        switch ($type) {
            case self::MSG_VERSION:
                // 站上报版本 → 回 router_config
                return self::routerConfig([
                    'region' => $msg['region'] ?? ELW_DEFAULT_REGION,
                ]);

            case self::MSG_JREQ:
            case self::MSG_UPDF:
                // 提取 PHYPayload（base64）→ 交给 NS
                $raw = $msg[$type] ?? '';
                $pdu = base64_decode($raw, true);
                if ($pdu === false) {
                    return ['_noop' => true, 'error' => 'bad pdu'];
                }
                return [
                    '_forward' => true,
                    'msgtype'  => $type,
                    'pdu'      => $pdu,
                    'upinfo'   => $msg['upinfo'] ?? null,
                    'xtime'    => $msg['xtime'] ?? null,
                    'rctx'     => $msg['rctx'] ?? null,
                    'dC'       => $msg['dC'] ?? null,
                ];

            case self::MSG_TIMESYNC:
                // 应答：返回本站 xtime（demo 置 0，生产应映射 GPS 时间）
                return [
                    'msgtype' => self::MSG_TIMESYNC,
                    'txtime'  => 0,
                ];

            case self::MSG_RMTSH:
                return [
                    'msgtype' => self::MSG_RMTSH,
                    'resp'    => (object) [],
                ];

            case self::MSG_ROUTER_CONFIG:
            default:
                return ['_noop' => true];
        }
    }

    /**
     * 把一条下行帧封装为 dnmsg（NS→站）。
     * $dl 字段：pdu(base64 物理层帧) / diid / rx_delay / rx1_dr / rx1_freq。
     */
    public static function buildDnMsg(array $dl, int $xtime, int $rctx, string $dC = 'C'): array
    {
        return [
            'msgtype' => self::MSG_DNMSG,
            'dC'      => $dC,
            'diid'    => $dl['diid'] ?? 1,
            'pdu'     => $dl['pdu'],
            'RxDelay' => $dl['rx_delay'] ?? 0,
            'RX1DR'   => $dl['rx1_dr'] ?? 0,
            'RX1Freq' => $dl['rx1_freq'] ?? 0,
            'xtime'   => $xtime,
            'rctx'    => $rctx,
        ];
    }

    /**
     * LNS 服务骨架（需 Ratchet）。
     *
     * 真实运行需：`composer require cboden/ratchet`，随后在 bin/lns.php 中以
     * Ratchet\App 暴露一个 WebSocket 路由，每个连接按 Station 协议收发包：
     *   onMessage($conn, $json):
     *     $resp = Station::handleMessage(json_decode($json, true));
     *     if ($resp['_forward']) { $pdu = $resp['pdu']; ...交给 NetworkServer::ingest($pdu, $gwId); }
     *     else if (!isset($resp['_noop'])) { $conn->send(json_encode($resp)); }
     * 本方法仅作占位，避免无依赖时启动即崩溃。
     */
    public static function serve(int $stationId): void
    {
        $s = self::get($stationId);
        if (!$s) {
            throw new \RuntimeException("station $stationId not found");
        }
        if (!class_exists('Ratchet\\App')) {
            throw new \RuntimeException(
                'LNS requires Ratchet (composer require cboden/ratchet). ' .
                'See bin/lns.php for the integration skeleton.'
            );
        }
        // 接入逻辑请参考 above docblock；此处由 Ratchet 路由驱动。
        throw new \RuntimeException('LNS serve() must be driven by bin/lns.php (Ratchet App).');
    }
}
