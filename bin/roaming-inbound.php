<?php
















require __DIR__ . '/../bootstrap.php';

use holastack\Core\Roaming;



Roaming::setup();

header('Content-Type: application/json');



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



$senderNetId = strtoupper((string) ($resp['SenderID'] ?? ''));
$authHex = (string) ($_SERVER['HTTP_X_DOWNLINK_AUTH'] ?? '');
if (!Roaming::verifyInboundSignature($senderNetId, $resp, $authHex)) {
    http_response_code(403);
    echo json_encode(['MessageType' => 'Error', 'Result' => 'bad signature / unknown partner']);
    error_log("roaming-inbound: rejected $messageType from $senderNetId (signature/partner check failed)");
    exit;
}



$corr = Roaming::handleInboundAns($resp);
if (!$corr['ok']) {
    http_response_code(404);
    echo json_encode(['MessageType' => 'Error', 'Result' => $corr['error'] ?? 'no correlation']);
    error_log("roaming-inbound: $messageType from $senderNetId has no pending correlation (phy=" . substr($corr['phy'] ?? '', 0, 32) . "...)");
    exit;
}



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


$dlTmst = ($ulTmst + $dlDelayMs * 1000) & 0xFFFFFFFF;



$powe = ($freq >= 869400000 && $freq <= 869650000) ? 29 : 16; 

$txpk = [
    'imme' => false,
    'tmst' => $dlTmst,
    'freq' => $freq,
    'rfch' => 0,
    'powe' => $powe,
    'modu' => 'LORA',
    'datr' => $datr,
    'codr' => '4/5',
    'ipol' => true,   

    'size' => strlen($phy),
    'data' => base64_encode($phy),
];
$json = json_encode(['txpk' => $txpk]);



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



echo json_encode([
    'SenderID'   => $resp['ReceiverID'] ?? Roaming::localNsId(),
    'ReceiverID' => $senderNetId,
    'MessageType'=> 'Error',
    'Result'     => 'OK',
]);
