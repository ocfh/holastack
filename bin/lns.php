<?php
/**
 * Basic Station LNS 守护进程入口（Semtech LNS 协议）。
 *
 * 启动：php bin/lns.php [--port 443 --cert /path/ca.pem]
 * 依赖：composer require cboden/ratchet   （WebSocket/TLS 服务端）
 *
 * 无 Ratchet 时本脚本仅打印接入说明并退出，不会崩溃。
 */
require __DIR__ . '/../bootstrap.php';

use holastack\Core\Station;
use Ratchet\App;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;

echo "holastack  LNS (Basic Station backend)\n";
echo "DB: " . ELW_DB_DSN . "\n";

// 若未安装 Ratchet，给出明确指引
if (!class_exists('Ratchet\\App')) {
    echo "========================================\n";
    echo " 未检测到 Ratchet，无法启动 WebSocket 服务。\n";
    echo " 请先执行：composer require cboden/ratchet\n";
    echo " （holastack 已提供协议层逻辑：Station::handleMessage / Station::buildDnMsg）\n";
    echo "========================================\n";
    exit(0);
}

/**
 * LNS 协议组件：每个 Basic Station WebSocket 连接对应一个实例。
 */
class LnsComponent implements MessageComponentInterface
{
    /** @var array<int,ConnectionInterface> */
    protected $conns = [];

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->conns[$conn->resourceId] = $conn;
        echo "[LNS] station connected: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $conn, $json): void
    {
        $msg = json_decode($json, true);
        if (!is_array($msg)) {
            return;
        }
        $resp = Station::handleMessage($msg);
        if (!empty($resp['_forward'])) {
            // PHYPayload 转交 NetworkServer 处理（接入点）
            // $pdu = $resp['pdu']; NetworkServer::ingest($pdu, $gwId);
            // 下行结果通过 Station::buildDnMsg 构造成 dnmsg 回送
            echo "[LNS] forward PHYPayload (" . strlen($resp['pdu']) . " bytes) to NS\n";
            return;
        }
        if (!empty($resp['_noop'])) {
            return;
        }
        $conn->send(json_encode($resp));
    }

    public function onClose(ConnectionInterface $conn): void
    {
        unset($this->conns[$conn->resourceId]);
        echo "[LNS] station disconnected: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[LNS] error: " . $e->getMessage() . "\n";
        $conn->close();
    }
}

$port = 443;
$cert = null;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--port' && isset($argv[$i + 1])) {
        $port = (int) $argv[++$i];
    } elseif ($argv[$i] === '--cert' && isset($argv[$i + 1])) {
        $cert = $argv[++$i];
    }
}

$app = new App('0.0.0.0', $port, '0.0.0.0', null, $cert ? ['local_cert' => $cert] : []);
$app->route('/router/{id}', new LnsComponent(), ['*']);
echo "LNS listening on :$port/router/{stationId}\n";
$app->run();
