<?php
/**
 * 漫游入站端点（Serving NS 侧，接收伙伴 Home NS 的 JoinAns / PrUpdAns）。
 *
 * 部署：
 *   php -S 0.0.0.0:8081 bin/roaming-inbound.php        # 内置服务器
 *   或反向代理（Nginx/Apache）把 /roaming-inbound 指到这里。
 *
 * 流程（对齐 ChirpStack backend interface inbound）：
 *   1. 接收伙伴 NS 的 HTTPS POST（BI 1.0 报文：JoinAns / PrUpdAns），含签名头 X-Downlink-Auth。
 *   2. 用伙伴共享 KEK 校验 AES-CMAC 签名（防伪造 / 重放）。
 *   3. Roaming::handleInboundAns() 关联 roaming_pending，取出原服务网关 peer / ul_tmst / dl_delay / 频点。
 *   4. 把下行 PHYPayload 封装为 Semtech PULL_RESP，按 ul_tmst + dl_delay 定时回送原服务网关（UDP 直发，与 NS 主进程解耦）。
 *
 * 注意：服务 NS 在此仅扮演「透传」角色——下行由 Home NS 决策，本端点只负责把下行安全、准点地送回网关。
 */
require __DIR__ . '/../bootstrap.php';

use holastack\Core\Roaming;

// 加载伙伴注册表（用于签名校验 / 关联）
Roaming::setup();

header('Content-Type: application/json');

// 仅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['MessageType' => 'Error', 'Result' => 'method not allowed']);
    exit;
}

$raw = (string) file_get_contents('php://input');
$resp = json_decode($raw, true);
if (!is_array($resp)) {
    http_response_code(400);
    echo json_encode(['MessageType' => 'Error', 'Result' => 'invalid json']);
    exit;
}

$messageType = $resp['MessageType'] ?? '';
if (!in_array($messageType, [Roaming::MSG_JOIN_ANS, Roaming::MSG_PR_UPD_ANS], true)) {
    http_response_code(400);
    echo json_encode(['MessageType' => 'Error', 'Result' => "unexpected MessageType: $messageType"]);
    exit;
}

// ---- 签名校验（X-Downlink-Auth）----
$senderNetId = strtoupper((string) ($resp['SenderID'] ?? ''));
$authHex = (string) ($_SERVER['HTTP_X_DOWNLINK_AUTH'] ?? '');
if (!Roaming::verifyInboundSignature($senderNetId, $resp, $authHex)) {
    http_response_code(403);
    echo json_encode(['MessageType' => 'Error', 'Result' => 'bad signature / unknown partner']);
    error_log("roaming-inbound: rejected $messageType from $senderNetId (signature/partner check failed)");
    exit;
}

// ---- 关联 roaming_pending，取原服务网关与定时信息 ----
$corr = Roaming::handleInboundAns($resp);
if (!$corr['ok']) {
    http_response_code(404);
    echo json_encode(['MessageType' => 'Error', 'Result' => $corr['error'] ?? 'no correlation']);
    error_log("roaming-inbound: $messageType from $senderNetId has no pending correlation (phy=" . substr($corr['phy'] ?? '', 0, 32) . "...)");
    exit;
}

// ---- 构造 PULL_RESP 并 UDP 回送网关 ----
$phy = base64_decode($corr['phy'], true);
if ($phy === false || $phy === '') {
    http_response_code(422);
    echo json_encode(['MessageType' => 'Error', 'Result' => 'empty/invalid PHYPayload']);
    exit;
}

$peer = $corr['peer'];
$freq = (float) $corr['freq'];
$datr = $corr['datr'];
$ulTmst = (int) $corr['ul_tmst'];
$dlDelayMs = (int) $corr['dl_delay'];
// 下行定时：rxpk.tmst = 接收结束时刻，dl_tmst = ul_tmst + delay（µs，32 位回绕）
$dlTmst = ($ulTmst + $dlDelayMs * 1000) & 0xFFFFFFFF;

// Semtech UDP txpk（与 NetworkServer::flushDownlink 同构）
$powe = ($freq >= 869400000 && $freq <= 869650000) ? 29 : 16; // RX1=16dBm / RX2=29dBm（EU868）
$txpk = [
    'imme' => false,
    'tmst' => $dlTmst,
    'freq' => $freq,
    'rfch' => 0,
    'powe' => $powe,
    'modu' => 'LORA',
    'datr' => $datr,
    'codr' => '4/5',
    'ipol' => true,   // LoRaWAN 下行必须反转 IQ
    'size' => strlen($phy),
    'data' => base64_encode($phy),
];
$json = json_encode(['txpk' => $txpk]);

// PULL_RESP 协议：version(0x02) + token + id(0x03) + JSON
$ver = "\x02";
$tok = "\x00\x00";
$pkt = $ver . $tok . "\x03" . $json;

$sock = @stream_socket_client('udp://0.0.0.0:0', $errno, $errstr, 1);
$sent = false;
if ($sock !== false) {
    $r = @stream_socket_sendto($sock, $pkt, 0, $peer);
    $sent = ($r !== false && $r > 0);
    fclose($sock);
}

if (!$sent) {
    http_response_code(502);
    echo json_encode(['MessageType' => 'Error', 'Result' => "udp send to gateway failed: $errstr"]);
    error_log("roaming-inbound: FAILED to send PULL_RESP to $peer (peer unreachable?)");
    exit;
}

error_log("roaming-inbound: $messageType -> PULL_RESP peer=$peer gw={$corr['gw_id']} dl_tmst=$dlTmst freq=$freq datr=$datr phy=" . bin2hex($phy));

// 回执伙伴：Result=OK（无下行负载随应答返回，下行已直发网关）
echo json_encode([
    'SenderID'   => $resp['ReceiverID'] ?? Roaming::localNsId(),
    'ReceiverID' => $senderNetId,
    'MessageType'=> 'Error',
    'Result'     => 'OK',
]);
