<?php























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
$ns = new NetworkServer(0); 

echo "NS instance ready (station-only mode)\n";



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



$conns = [];
$nextId = 1;





function tryHandshake(array &$c, NetworkServer $ns): bool
{
    $headEnd = strpos($c['buf'], "\r\n\r\n");
    if ($headEnd === false) {
        return false; 

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

    

    $ns->registerStationGateway($gwEui, function (array $dn) use (&$c) {
        $frame = Station::wsFrame(json_encode($dn));
        if (@fwrite($c['sock'], $frame) === false) {
            echo "[LNS] dnmsg send failed (conn closed?): {$c['gwEui']}\n";
        }
    }, 'station://' . $gwEui, $station['region'] ?: ELW_DEFAULT_REGION);
    return true;
}





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
    

    $ns->runScheduled();
}
