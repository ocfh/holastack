<?php
/**
 * 端到端模拟网关测试：
 *  - 启动一个 UDP 客户端模拟 Semtech 网关
 *  - 执行 OTAA Join、解析 Join Accept、派生会话密钥
 *  - 发送数据上行，验证 NS 正确解密并存库
 *  - 入队一条应用下行，验证 NS 在 RX 窗口下发并可被正确解密
 * 运行前需先启动 NS：php bin/server.php
 * 运行：php tests/simulate_gateway.php
 */
require __DIR__ . '/../bootstrap.php';
use holastack\Crypto\LoRaWANCrypto;
use holastack\Core\Frame;
use holastack\DB\Database;

Database::migrate();

$pass = 0; $fail = 0;
function check(string $n, bool $c): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

$NS_HOST = '127.0.0.1';
$NS_PORT = ELW_GW_UDP_PORT;
$GW_EUI = hex2bin('0102030405060708');
$APP_KEY = hex2bin('2b7e151628aed2a6abf7158809cf4f3c');
$APP_EUI = hex2bin('0102030405060708');
$DEV_EUI = hex2bin('0807060504030201');
$DEV_NONCE = "\x00\x01";

// 准备 DB：应用 + OTAA 设备
Database::execute("DELETE FROM devices WHERE dev_eui=?", [bin2hex($DEV_EUI)]);
$app = Database::fetch("SELECT id FROM applications WHERE name='SimApp'");
if (!$app) {
    Database::execute("INSERT INTO applications (name, created_at) VALUES (?,?)", ['SimApp', time()]);
    $appId = Database::lastInsertId();
} else {
    $appId = $app['id'];
}
Database::execute(
    "INSERT INTO devices (app_id, name, dev_eui, join_eui, activation, app_key, region, status, created_at)
     VALUES (?,?,?,?,?,?,?,?,?)",
    [$appId, 'SimDev', bin2hex($DEV_EUI), bin2hex($APP_EUI), 'OTAA', bin2hex($APP_KEY), 'EU868', 'pending', time()]
);

// UDP 客户端
$sock = stream_socket_client("udp://$NS_HOST:$NS_PORT", $errno, $errstr, 3);
if ($sock === false) { die("Cannot connect to NS: $errstr\n"); }
stream_set_timeout($sock, 5);

function sendPull(): void {
    global $sock, $GW_EUI;
    $token = random_bytes(2);
    $pkt = "\x01" . $token . "\x02" . $GW_EUI;
    fwrite($sock, $pkt);
}
function sendPush(string $phy, int $tmst): void {
    global $sock, $GW_EUI;
    $token = random_bytes(2);
    $json = json_encode(['rxpk' => [[
        'tmst' => $tmst, 'freq' => 868.1, 'chan' => 0, 'rfch' => 0, 'powe' => 14,
        'modu' => 'LORA', 'datr' => 'SF12BW125', 'codr' => '4/5',
        'rssi' => -35, 'lsnr' => 5.0, 'size' => strlen($phy), 'data' => base64_encode($phy),
    ]]]);
    $pkt = "\x01" . $token . "\x00" . $GW_EUI . $json;
    fwrite($sock, $pkt);
}
function recvPullResp() {
    global $sock;
    for ($i = 0; $i < 30; $i++) {
        $data = fread($sock, 65535);
        if ($data === false) return null;                 // 真实错误
        if ($data === '') {                                // 超时/空读：继续等待（Class B/C 主动下行可能稍晚到达）
            $m = stream_get_meta_data($sock);
            if (!empty($m['timed_out'])) continue;
            return null;
        }
        if (strlen($data) < 4) continue;
        $id = ord($data[3]);
        if ($id === 0x03) {
            $json = json_decode(substr($data, 4), true);
            return $json['txpk']['data'] ?? null;
        }
        // 其它类型（PUSH_ACK/PULL_ACK/TX_ACK）忽略，继续收
    }
    return null;
}

// 清空当前套接字缓冲区中已到达的多余 PULL_RESP（如 Class A 的 RX2 副本），避免后续读取错位
function drain(): void {
    global $sock;
    stream_set_timeout($sock, 0, 100000);
    for ($i = 0; $i < 30; $i++) {
        $d = fread($sock, 65535);
        if ($d === false || $d === '') {
            break;
        }
    }
    stream_set_timeout($sock, 5);
}

// ---- 1. 注册网关 + OTAA Join ----
sendPull();
usleep(100000);
$tmst = (int)(microtime(true) * 1e6) & 0xFFFFFFFF;
$joinPhy = "\x00" . $APP_EUI . $DEV_EUI . $DEV_NONCE;
$mic = LoRaWANCrypto::joinRequestMIC($APP_KEY, $joinPhy);
$joinPhy .= $mic;
sendPush($joinPhy, $tmst);

$jaB64 = recvPullResp();
check('Received Join Accept PULL_RESP', $jaB64 !== null);
if ($jaB64 === null) { echo "\n==== $pass passed, $fail failed ====\n"; exit(1); }

$jaPhy = base64_decode($jaB64);
check('Join Accept MHDR=0x20', ord($jaPhy[0]) === 0x20);
$body = LoRaWANCrypto::decryptJoinAccept($APP_KEY, substr($jaPhy, 1));
$appNonce = substr($body, 0, 3);
$netId = substr($body, 3, 3);
$devAddr = substr($body, 6, 4);
$dlSettings = ord($body[10]);
$rxDelay = ord($body[11]);
check('Join Accept DevAddr valid', strlen($devAddr) === 4);
echo "      -> devAddr=" . bin2hex($devAddr) . " dlSettings=$dlSettings rxDelay=$rxDelay\n";

[$nwkSKey, $appSKey] = LoRaWANCrypto::computeSessionKeys($APP_KEY, $appNonce, $netId, $DEV_NONCE);
$devDb = Database::fetch("SELECT status, dev_addr FROM devices WHERE dev_eui=?", [bin2hex($DEV_EUI)]);
check('Device status=active in DB', ($devDb['status'] ?? '') === 'active');
check('Device dev_addr saved', ($devDb['dev_addr'] ?? '') === bin2hex($devAddr));

// ---- 2. 入队应用下行 ----
$gwPort = 20;
Database::execute(
    "INSERT INTO downlinks (dev_id, app_id, port, payload_hex, confirmed, status, created_at)
     VALUES (?,?,?,?,?,?,?)",
    [Database::fetch("SELECT id FROM devices WHERE dev_eui=?", [bin2hex($DEV_EUI)])['id'],
     $appId, $gwPort, 'aabb', 0, 'pending', time()]
);

// ---- 3. 发送数据上行 ----
$payload = 'Hello';
$enc = LoRaWANCrypto::frmPayloadCrypt($appSKey, 0, $devAddr, 1, $payload);
$without = "\x40" . $devAddr . "\x00" . pack('v', 1) . "\x0a" . $enc;
$micU = LoRaWANCrypto::dataMIC($nwkSKey, 0, $devAddr, 1, $without);
$upPhy = $without . $micU;

$tmst2 = (int)(microtime(true) * 1e6) & 0xFFFFFFFF;
sendPush($upPhy, $tmst2);

$dlB64 = recvPullResp();
check('Received app downlink PULL_RESP', $dlB64 !== null);
if ($dlB64 !== null) {
    $dlPhy = base64_decode($dlB64);
    $p = Frame::parseDataUp($dlPhy);
    check('Downlink fport matches', $p['fport'] === $gwPort);
    $decDl = LoRaWANCrypto::frmPayloadCrypt($appSKey, 1, $devAddr, Frame::fullFCnt($p['fcnt_lo'], 0), $p['frmpayload']);
    check('Downlink payload matches (aabb)', bin2hex($decDl) === 'aabb');
}

// ---- 4. 验证上行已存库 ----
drain(); // 清掉 step3 可能残留的 RX2 下行副本
usleep(200000);
$up = Database::fetch("SELECT * FROM uplinks WHERE dev_addr=? ORDER BY id DESC LIMIT 1", [bin2hex($devAddr)]);
check('Uplink stored in DB', $up !== null);
check('Uplink decrypted = Hello', ($up['decrypted_hex'] ?? '') === bin2hex($payload));

// ---- 5. Class C 立即下行验证 ----
drain(); // 确保缓冲区无残留后再开始
$cDevEui = hex2bin('aabbccddeeff0011');
Database::execute("DELETE FROM devices WHERE dev_eui=?", [bin2hex($cDevEui)]);
Database::execute(
    "INSERT INTO devices (app_id,name,dev_eui,join_eui,activation,app_key,region,class,status,created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)",
    [$appId, 'ClassCDev', bin2hex($cDevEui), bin2hex($APP_EUI), 'OTAA', bin2hex($APP_KEY), 'EU868', 'C', 'pending', time()]
);
$cJoin = "\x00" . $APP_EUI . $cDevEui . $DEV_NONCE;
$cMic = LoRaWANCrypto::joinRequestMIC($APP_KEY, $cJoin);
sendPush($cJoin . $cMic, (int)(microtime(true) * 1e6) & 0xFFFFFFFF);
$cJaB64 = recvPullResp();
check('Class C: Received Join Accept', $cJaB64 !== null);
if ($cJaB64 !== null) {
    $cJaPhy = base64_decode($cJaB64);
    $cBody = LoRaWANCrypto::decryptJoinAccept($APP_KEY, substr($cJaPhy, 1));
    $cDevAddr = substr($cBody, 6, 4);
    [$cNwk, $cApp] = LoRaWANCrypto::computeSessionKeys($APP_KEY, substr($cBody, 0, 3), substr($cBody, 3, 3), $DEV_NONCE);
    $cDevId = Database::fetch("SELECT id FROM devices WHERE dev_eui=?", [bin2hex($cDevEui)])['id'];
    // 入队一条下行：Class C 应在无需上行的情况下由 NS 主动立即下发
    Database::execute(
        "INSERT INTO downlinks (dev_id,app_id,port,payload_hex,confirmed,status,created_at) VALUES (?,?,?,?,?,?,?)",
        [$cDevId, $appId, 30, 'cafe', 0, 'pending', time()]
    );
    $cDlB64 = recvPullResp();
    check('Class C: immediate downlink PULL_RESP (no uplink needed)', $cDlB64 !== null);
    if ($cDlB64 !== null) {
        $cDlPhy = base64_decode($cDlB64);
        $cp = Frame::parseDataUp($cDlPhy);
        $cmtype = (ord($cDlPhy[0]) >> 5) & 0x07;
        echo "  [debug] cDlPhy=" . bin2hex($cDlPhy) . " mtype=$cmtype fport=" . var_export($cp['fport'], true) . " fcnt_lo=" . var_export($cp['fcnt_lo'], true) . "\n";
        check('Class C downlink fport matches (30)', $cp['fport'] === 30);
        $cDec = LoRaWANCrypto::frmPayloadCrypt($cApp, 1, $cDevAddr, Frame::fullFCnt($cp['fcnt_lo'], 0), $cp['frmpayload']);
        check('Class C downlink payload matches (cafe)', bin2hex($cDec) === 'cafe');
    }
}

// ---- 6. Class B ping 时隙下行验证 ----
drain(); // 确保缓冲区无残留后再开始
$bDevEui = hex2bin('1122334455667788');
Database::execute("DELETE FROM devices WHERE dev_eui=?", [bin2hex($bDevEui)]);
Database::execute(
    "INSERT INTO devices (app_id,name,dev_eui,join_eui,activation,app_key,region,class,status,created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)",
    [$appId, 'ClassBDev', bin2hex($bDevEui), bin2hex($APP_EUI), 'OTAA', bin2hex($APP_KEY), 'EU868', 'B', 'pending', time()]
);
$bJoin = "\x00" . $APP_EUI . $bDevEui . $DEV_NONCE;
$bMic = LoRaWANCrypto::joinRequestMIC($APP_KEY, $bJoin);
sendPush($bJoin . $bMic, (int)(microtime(true) * 1e6) & 0xFFFFFFFF);
$bJaB64 = recvPullResp();
check('Class B: Received Join Accept', $bJaB64 !== null);
if ($bJaB64 !== null) {
    $bJaPhy = base64_decode($bJaB64);
    $bBody = LoRaWANCrypto::decryptJoinAccept($APP_KEY, substr($bJaPhy, 1));
    $bDevAddr = substr($bBody, 6, 4);
    [$bNwk, $bApp] = LoRaWANCrypto::computeSessionKeys($APP_KEY, substr($bBody, 0, 3), substr($bBody, 3, 3), $DEV_NONCE);
    $bDevId = Database::fetch("SELECT id FROM devices WHERE dev_eui=?", [bin2hex($bDevEui)])['id'];
    // 入队一条下行：Class B 由 NS 在 ping 时隙（默认 beacon_epoch=now 即立即）主动下发
    Database::execute(
        "INSERT INTO downlinks (dev_id,app_id,port,payload_hex,confirmed,status,created_at) VALUES (?,?,?,?,?,?,?)",
        [$bDevId, $appId, 40, 'beef', 0, 'pending', time()]
    );
    $bDlB64 = recvPullResp();
    check('Class B: ping-slot downlink PULL_RESP (no uplink needed)', $bDlB64 !== null);
    if ($bDlB64 !== null) {
        $bDlPhy = base64_decode($bDlB64);
        $bp = Frame::parseDataUp($bDlPhy);
        $bmtype = (ord($bDlPhy[0]) >> 5) & 0x07;
        echo "  [debug] bDlPhy=" . bin2hex($bDlPhy) . " mtype=$bmtype fport=" . var_export($bp['fport'], true) . " fcnt_lo=" . var_export($bp['fcnt_lo'], true) . "\n";
        check('Class B downlink fport matches (40)', $bp['fport'] === 40);
        $bDec = LoRaWANCrypto::frmPayloadCrypt($bApp, 1, $bDevAddr, Frame::fullFCnt($bp['fcnt_lo'], 0), $bp['frmpayload']);
        check('Class B downlink payload matches (beef)', bin2hex($bDec) === 'beef');
    }
}

echo "\n==== $pass passed, $fail failed ====\n";
fclose($sock);
exit($fail > 0 ? 1 : 0);
