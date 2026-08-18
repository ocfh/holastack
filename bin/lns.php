<?php
/**
 * Basic Station LNS 守护进程（Semtech LNS 协议，纯 PHP 实现，零外部依赖）。
 *
 * 与 Semtech UDP 包转发器不同，Basic Station 通过 WebSocket 连接本服务：
 *   站 → NS: version / jreq / updf / timesync / rmtsh（JSON 文本帧）
 *   NS → 站: router_config / dnmsg / timesync / rmtsh（JSON 文本帧）
 *
 * 架构：
 *   - 本进程内嵌一个 NetworkServer 实例（不开 UDP socket），station 网关经
 *     registerStationGateway() 注册，上行 ingestStationUp() 走标准 NS 链路，
 *     下行由 NS flushDownlink() 转 dnmsg 经 WebSocket 回送。
 *   - 无 Ratchet / composer 依赖：RFC 6455 握手与帧编解码均为内置实现
 *     （Station::wsAcceptKey / wsFrame / wsDecodeFragmented）。
 *
 * 启动：
 *   php bin/lns.php [--port 3001] [--cert /path/ca.pem]
 *   （--cert 提供时监听 wss；否则 ws）
 *
 * 站侧连接地址（Basic Station 配置）：
 *   ws://<host>:3001/router/<gateway_id>?token=<lns_secret>
 *   或生产 wss://<host>:443/router/<gateway_id>?token=<lns_secret>
 */
require __DIR__ . '/../bootstrap.php';

use holastack\Core\NetworkServer;
use holastack\Core\Station;
use holastack\DB\Database;

$port = 3001;
$cert = null;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--port' && isset($argv[$i + 1])) {
        $port = (int) $argv[++$i];
    } elseif ($argv[$i] === '--cert' && isset($argv[$i + 1])) {
        $cert = $argv[++$i];
    }
}

echo "HolaStack LNS (Basic Station backend, pure PHP WebSocket)\n";
echo "DB: " . ELW_DB_DSN . "\n";

Database::migrate();
$ns = new NetworkServer(0); // 不开 UDP：station 网关下行走 sink
echo "NS instance ready (station-only mode)\n";

// ---------------- 连接管理 ----------------
$srvOpts = [];
if ($cert) {
    $srvOpts['ssl'] = ['local_cert' => $cert];
    $proto = 'tls';
    echo "TLS cert loaded: $cert\n";
} else {
    $proto = 'tcp';
}
$srv = @stream_socket_server("$proto://0.0.0.0:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($srvOpts));
if ($srv === false) {
    fwrite(STDERR, "FATAL: cannot bind $proto :$port ($errstr)\n");
    exit(1);
}
stream_set_blocking($srv, false);
echo "LNS listening on $proto://0.0.0.0:$port/router/{gateway_id}?token=...\n";

/** @var array<int,array{sock:resource,buf:string,gwEui:string,station:?array}> $conns */
$conns = [];
$nextId = 1;

/**
 * 尝试对连接完成 WebSocket 握手；成功返回 true（并注册 NS 网关）。
 */
function tryHandshake(array &$c, NetworkServer $ns): bool
{
    $headEnd = strpos($c['buf'], "\r\n\r\n");
    if ($headEnd === false) {
        return false; // 请求头未完整，继续攒
    }
    $head = substr($c['buf'], 0, $headEnd + 4);
    $c['buf'] = substr($c['buf'], $headEnd + 4);

    if (!preg_match('#^GET ([^ ]+) HTTP/1\.1#m', $head, $m)) {
        @fwrite($c['sock'], "HTTP/1.1 400 Bad Request\r\n\r\n");
        return false;
    }
    $path = $m[1];
    if (!preg_match('#^/router/([0-9a-fA-F]+)#', $path, $rm)) {
        @fwrite($c['sock'], "HTTP/1.1 404 Not Found (expect /router/{gateway_id})\r\n\r\n");
        return false;
    }
    if (!preg_match('/Sec-WebSocket-Key: (.+)\r\n/i', $head, $km)) {
        @fwrite($c['sock'], "HTTP/1.1 400 Bad Request (missing Sec-WebSocket-Key)\r\n\r\n");
        return false;
    }

    $gwEui = strtolower($rm[1]);
    $station = Database::fetch("SELECT * FROM stations WHERE gateway_id=?", [$gwEui]);
    if (!$station) {
        echo "[LNS] reject: station $gwEui not found in stations table\n";
        @fwrite($c['sock'], "HTTP/1.1 403 Forbidden (unknown gateway)\r\n\r\n");
        return false;
    }
    parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
    $token = $q['token'] ?? '';
    if ($station['lns_secret'] !== '' && !hash_equals((string) $station['lns_secret'], $token)) {
        echo "[LNS] reject: bad token for station $gwEui\n";
        @fwrite($c['sock'], "HTTP/1.1 403 Forbidden (bad token)\r\n\r\n");
        return false;
    }

    $accept = Station::wsAcceptKey($km[1]);
    $resp = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: $accept\r\n"
        . "Sec-WebSocket-Protocol: v1\r\n\r\n";
    @fwrite($c['sock'], $resp);

    $c['gwEui'] = $gwEui;
    $c['station'] = $station;
    echo "[LNS] station connected: $gwEui (region=" . ($station['region'] ?: ELW_DEFAULT_REGION) . ")\n";

    // 注册 NS 网关：下行 dnmsg 经 sink 回送本连接
    $ns->registerStationGateway($gwEui, function (array $dn) use (&$c) {
        $frame = Station::wsFrame(json_encode($dn));
        if (@fwrite($c['sock'], $frame) === false) {
            echo "[LNS] dnmsg send failed (conn closed?): {$c['gwEui']}\n";
        }
    }, 'station://' . $gwEui, $station['region'] ?: ELW_DEFAULT_REGION);
    return true;
}

/**
 * 处理连接缓冲区中的 WebSocket 帧（文本 JSON）。返回 false 表示连接应关闭（收到 close 帧）。
 */
function processFrames(array &$c, NetworkServer $ns): bool
{
    while (($frame = Station::wsDecodeFragmented($c['buf'])) !== null) {
        $c['buf'] = substr($c['buf'], $frame['consumed']);
        $op = $frame['opcode'];
        if ($op === Station::OP_CLOSE) {
            @fwrite($c['sock'], Station::wsFrame('', Station::OP_CLOSE));
            return false;
        }
        if ($op === Station::OP_PING) {
            @fwrite($c['sock'], Station::wsFrame($frame['payload'], Station::OP_PONG));
            continue;
        }
        if ($op === Station::OP_PONG) {
            continue;
        }
        if ($op !== Station::OP_TEXT) {
            continue;
        }
        $msg = json_decode($frame['payload'], true);
        if (!is_array($msg)) {
            continue;
        }
        $resp = Station::handleMessage($msg, $c['station']);
        if (!empty($resp['_forward'])) {
            $ns->ingestStationUp($resp['phy'], $resp['upinfo'], $resp['gwEui'], 'station://' . $resp['gwEui']);
            continue;
        }
        if (!empty($resp['_noop'])) {
            continue;
        }
        @fwrite($c['sock'], Station::wsFrame(json_encode($resp)));
    }
    return true;
}

// ---------------- 主循环 ----------------
while (true) {
    $read = [$srv];
    foreach ($conns as $c) {
        $read[] = $c['sock'];
    }
    $write = null;
    $except = null;
    $n = @stream_select($read, $write, $except, 1);
    if ($n === false) {
        continue;
    }
    foreach ($read as $stream) {
        if ($stream === $srv) {
            $sock = @stream_socket_accept($srv, 0);
            if ($sock !== false) {
                stream_set_blocking($sock, false);
                $conns[$nextId++] = ['sock' => $sock, 'buf' => '', 'gwEui' => '', 'station' => null];
            }
            continue;
        }
        foreach ($conns as $id => &$c) {
            if ($c['sock'] !== $stream) {
                continue;
            }
            $data = @fread($stream, 65536);
            if ($data === '' || $data === false) {
                if ($c['gwEui'] !== '') {
                    echo "[LNS] station disconnected: {$c['gwEui']}\n";
                }
                @fclose($stream);
                unset($conns[$id]);
                continue 2;
            }
            $c['buf'] .= $data;
            if ($c['gwEui'] === '') {
                // 握手阶段：失败即关闭（避免悬挂半开连接）
                if (!tryHandshake($c, $ns) && $c['gwEui'] === '') {
                    @fclose($stream);
                    unset($conns[$id]);
                }
                continue;
            }
            if (!processFrames($c, $ns)) {
                if ($c['gwEui'] !== '') {
                    echo "[LNS] station disconnected: {$c['gwEui']}\n";
                }
                @fclose($stream);
                unset($conns[$id]);
            }
            break;
        }
        unset($c);
    }
    // 周期调度（Class B/C 下行 / 组播 / FUOTA / Join 缓冲）
    $ns->runScheduled();
}
