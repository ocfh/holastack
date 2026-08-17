<?php
namespace holastack\Core;

use holastack\DB\Database;
use holastack\Region\Region;

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

    // RFC 6455
    public const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    public const OP_CONT = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_BINARY = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    /**
     * RFC 6455 握手响应 key：base64(sha1(clientKey . GUID))。
     * 官方向量：key 'dGhlIHNhbXBsZSBub25jZQ==' → 's3pPLMBiTxaQ9kYGzzhZRbK+xOo='。
     */
    public static function wsAcceptKey(string $key): string
    {
        return base64_encode(sha1(trim($key) . self::WS_GUID, true));
    }

    /**
     * 服务端→客户端帧（无 mask）。$opcode 默认文本帧。
     */
    public static function wsFrame(string $payload, int $opcode = self::OP_TEXT): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | ($opcode & 0x0F)); // FIN=1
        if ($len < 126) {
            $head .= chr($len);
        } elseif ($len < 65536) {
            $head .= chr(126) . pack('n', $len);
        } else {
            $head .= chr(127) . pack('J', $len);
        }
        return $head . $payload;
    }

    /**
     * 解码一条客户端帧（客户端帧必须带 mask，RFC 6455）。
     *
     * 返回 ['fin'=>bool, 'opcode'=>int, 'payload'=>string, 'consumed'=>int, 'close_code'=>?int]；
     * 缓冲不足时返回 null（调用方应继续攒数据）。close 帧的 payload 前 2 字节为关闭码。
     */
    public static function wsDecode(string $buf): ?array
    {
        $n = strlen($buf);
        if ($n < 2) {
            return null;
        }
        $b0 = ord($buf[0]);
        $b1 = ord($buf[1]);
        $fin = (bool) ($b0 & 0x80);
        $opcode = $b0 & 0x0F;
        $masked = (bool) ($b1 & 0x80);
        $len = $b1 & 0x7F;
        $off = 2;
        if ($len === 126) {
            if ($n < 4) {
                return null;
            }
            $len = unpack('n', substr($buf, 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if ($n < 10) {
                return null;
            }
            $len = unpack('J', substr($buf, 2, 8))[1];
            $off = 10;
        }
        $maskKey = '';
        if ($masked) {
            if ($n < $off + 4) {
                return null;
            }
            $maskKey = substr($buf, $off, 4);
            $off += 4;
        }
        if ($n < $off + $len) {
            return null;
        }
        $payload = substr($buf, $off, $len);
        $closeCode = null;
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $len; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }
        if ($opcode === self::OP_CLOSE && strlen($payload) >= 2) {
            $closeCode = unpack('n', substr($payload, 0, 2))[1];
        }
        return [
            'fin'        => $fin,
            'opcode'     => $opcode,
            'payload'    => $payload,
            'consumed'   => $off + $len,
            'close_code' => $closeCode,
        ];
    }

    /**
     * 解码一条客户端帧并重组分片（FIN=0 的连续帧拼接为完整消息）。
     * 返回 ['opcode'=>int, 'payload'=>string, 'consumed'=>int, 'close_code'=>?int]；不足返回 null。
     */
    public static function wsDecodeFragmented(string $buf): ?array
    {
        $frame = self::wsDecode($buf);
        if ($frame === null) {
            return null;
        }
        if ($frame['fin']) {
            return $frame;
        }
        // 首个分片（可能为 0 数据），后续 CONT 帧补齐
        $total = $frame['payload'];
        $consumed = $frame['consumed'];
        $opcode = $frame['opcode'];
        while (!$frame['fin']) {
            $rest = substr($buf, $consumed);
            $frame = self::wsDecode($rest);
            if ($frame === null) {
                return null;
            }
            if ($frame['opcode'] !== self::OP_CONT) {
                break; // 协议异常：非 CONT 帧打断分片，直接按当前状态返回
            }
            $total .= $frame['payload'];
            $consumed += $frame['consumed'];
        }
        return [
            'fin'        => true,
            'opcode'     => $opcode,
            'payload'    => $total,
            'consumed'   => $consumed,
            'close_code' => null,
        ];
    }

    /**
     * 处理来自站的一条 JSON 消息，返回需要回送的 JSON（或转交 NS 的 PDU）。
     *
     * @param array      $msg     已 json_decode 的报文
     * @param array|null $station stations 表记录（提供 gateway_id / region / lns_secret），可为 null（demo）
     * @return array 含 '_forward' => true 表示 PHYPayload 需交给 NetworkServer；'_noop' => true 无需回送；
     *               否则为需 json_encode 后发回站的报文。
     */
    public static function handleMessage(array $msg, ?array $station = null): array
    {
        $type = $msg['msgtype'] ?? '';
        switch ($type) {
            case self::MSG_VERSION:
                // 站上报版本 → 回 router_config（region 优先取站注册值）
                return self::routerConfig([
                    'region' => ($station['region'] ?? '') ?: ($msg['region'] ?? ELW_DEFAULT_REGION),
                ]);

            case self::MSG_JREQ:
            case self::MSG_UPDF:
                // 提取 PHYPayload（base64）→ 交给 NS
                $raw = $msg[$type] ?? '';
                $pdu = base64_decode($raw, true);
                if ($pdu === false || $pdu === '') {
                    return ['_noop' => true, 'error' => 'bad pdu'];
                }
                $upinfo = is_array($msg['upinfo'] ?? null) ? $msg['upinfo'] : [];
                return [
                    '_forward' => true,
                    'msgtype'  => $type,
                    'phy'      => $pdu,
                    'upinfo'   => $upinfo,   // rssi/snr/freq/dr/xtime/rctx 原样透传，DR→datr 由 NS 映射
                    'gwEui'    => $station['gateway_id'] ?? '',
                ];

            case self::MSG_TIMESYNC:
                // 应答：返回本站 xtime（µs）。Basic Station 用 txtime 校准 GPS/网络时钟。
                return [
                    'msgtype' => self::MSG_TIMESYNC,
                    'txtime'  => (int) round(microtime(true) * 1e6),
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
     *
     * $dl 字段（对齐 NS txpk 语义）：
     *   pdu      base64 物理层帧
     *   diid     下行 ID（默认 1）
     *   freq     RX1 频率（MHz，用于 RX1Freq）
     *   datr     RX1 datr 字符串（如 'SF7BW125'，转 RX1DR 索引）
     *   rx_delay RX1 延迟秒（默认 1）
     *   region   region 名（datr→DR 索引换算用，默认 ELW_DEFAULT_REGION）
     * $xtime：期望发送的 µs 时间（0 = 立即）；$rctx：上行接收上下文；$dC：设备类别 A/B/C。
     */
    public static function buildDnMsg(array $dl, int $xtime = 0, int $rctx = 0, string $dC = 'C'): array
    {
        $region = Region::get($dl['region'] ?? ELW_DEFAULT_REGION);
        $rx1Dr = null;
        if (!empty($dl['datr'])) {
            $rx1Dr = $region->datrToDr($dl['datr']);
        }
        return [
            'msgtype' => self::MSG_DNMSG,
            'dC'      => $dC,
            'diid'    => $dl['diid'] ?? 1,
            'pdu'     => $dl['pdu'],
            'RxDelay' => (int) ($dl['rx_delay'] ?? 1),
            'RX1DR'   => $rx1Dr ?? (int) ($dl['rx1_dr'] ?? 0),
            'RX1Freq' => (float) ($dl['freq'] ?? 0),
            'xtime'   => $xtime,
            'rctx'    => $rctx,
        ];
    }

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
