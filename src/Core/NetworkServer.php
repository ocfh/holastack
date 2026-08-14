<?php
namespace holastack\Core;

use holastack\Crypto\LoRaWANCrypto;
use holastack\Region\Region;
use holastack\DB\Database;

/**
 * LoRaWAN 网络服务器核心：
 *  - 监听 Semtech UDP Packet Forwarder 协议（默认 1700）
 *  - 处理 PUSH_DATA（上行 + 网关状态）、PULL_DATA（下行路由保活）、TX_ACK
 *  - 处理 OTAA Join / 数据上下行 / ABP
 *  - 下行通过 PULL_RESP 下发到网关最近保活地址
 */
class NetworkServer
{
    private $sock;
    private $port;
    private $gateways = [];   // gwId(hex) => ['addr'=>peer, 'name'=>.., 'pending'=>[]]
    private $running = true;
    private $lastDlCheck = 0; // 周期下行调度节流时间戳
    private $joinBuf = [];    // Join-Request 去重缓冲：micKey => 下行候选（合并镜像频率等重复副本）

    public function __construct(int $port = ELW_GW_UDP_PORT)
    {
        $this->port = $port;
    }

    public function run(): void
    {
        Database::migrate();
        $this->log("Database ready.");
        $this->sock = stream_socket_server("udp://0.0.0.0:{$this->port}", $errno, $errstr, STREAM_SERVER_BIND);
        if ($this->sock === false) {
            fwrite(STDERR, "FATAL: cannot bind UDP :{$this->port} ($errstr)\n");
            exit(1);
        }
        stream_set_timeout($this->sock, 1);
        $this->log("NS UDP listening on :{$this->port}");

        while ($this->running) {
            // stream_select 会改写 $read，仅保留就绪的流，因此每次循环都要重建
            $read = [$this->sock];
            // Class B / Class C 主动下行调度（约每 1s 触发一次）
            if (time() - $this->lastDlCheck >= 1) {
                $this->lastDlCheck = time();
                $this->processScheduledDownlinks();
            }
            // Join-Request 去重缓冲刷新（按 MIC 合并重复副本后统一下发，避免网关 COLLISION_PACKET）
            $this->flushJoinBuffer();
            $write = $except = null;
            $n = @stream_select($read, $write, $except, 1);
            if ($n === false) {
                continue;
            }
            if ($n > 0) {
                $peer = '';
                $data = stream_socket_recvfrom($this->sock, 65535, 0, $peer);
                if ($data !== false && $data !== '') {
                    $this->handlePacket($data, $peer, microtime(true));
                }
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    // ---------------- 包分发 ----------------

    private function handlePacket(string $data, string $peer, float $rxTime = 0): void
    {
        if (strlen($data) < 4) {
            return;
        }
        $version = $data[0]; // 原始单字节（如 "\x02"），必须保留字节形式用于回显，不能 ord() 成 int（否则拼接会变成 ASCII '2'）
        $token = substr($data, 1, 2);
        $id = ord($data[3]);
        $gwEui = (strlen($data) >= 12) ? bin2hex(substr($data, 4, 8)) : '';
        // 记住网关自身使用的协议版本（多为 0x02），下行回包必须原样回显，否则部分包转发器会因版本不匹配静默丢弃
        if ($gwEui !== '') {
            $this->gateways[$gwEui] = $this->gateways[$gwEui] ?? [];
            $this->gateways[$gwEui]['version'] = $version;
        }

        switch ($id) {
            case 0x00: // PUSH_DATA
                $this->sendAck(0x01, $token, $peer, $gwEui);
                $json = json_decode(substr($data, 12), true);
                if (is_array($json)) {
                    $this->handlePush($json, $peer, $gwEui, $rxTime);
                }
                break;
            case 0x02: // PULL_DATA
                $this->sendAck(0x04, $token, $peer, $gwEui);
                $this->registerGateway($peer, $gwEui);
                // 记下本次 PULL_DATA 的 token，作为 PULL_RESP 的关联 ID（网关只原样回显到 TX_ACK，并不据此校验）
                $this->gateways[$gwEui]['pull_token'] = $token;
                $this->flushDownlink($gwEui, $peer);
                break;
            case 0x05: // TX_ACK
                $ackJson = json_decode(substr($data, 12), true);
                $ackStatus = $ackJson['txpk_ack']['error'] ?? 'ok';
                $this->log("TX_ACK gw=$gwEui status=$ackStatus");
                break;
            default:
                break;
        }
    }

    private function sendAck(int $id, string $token, string $peer, string $gwEui = ''): void
    {
        $ver = ($gwEui !== '' && isset($this->gateways[$gwEui]['version'])) ? $this->gateways[$gwEui]['version'] : "\x01";
        $pkt = $ver . $token . chr($id);
        @stream_socket_sendto($this->sock, $pkt, 0, $peer);
    }

    // ---------------- 网关管理 ----------------

    private function registerGateway(string $peer, string $gwEui): void
    {
        if ($gwEui === '') {
            $gwEui = md5($peer);
        }
        if (!isset($this->gateways[$gwEui])) {
            $this->gateways[$gwEui] = ['addr' => $peer, 'pending' => []];
            $this->log("Gateway registered: $gwEui ($peer)");
        } else {
            $this->gateways[$gwEui]['addr'] = $peer;
        }
        // 落库
        $existing = Database::fetch("SELECT gw_id FROM gateways WHERE gw_id=?", [$gwEui]);
        $now = time();
        if (!$existing) {
            Database::execute(
                "INSERT INTO gateways (gw_id, name, created_at, last_seen, ip) VALUES (?,?,?,?,?)",
                [$gwEui, 'Gateway ' . substr($gwEui, 0, 8), $now, $now, $peer]
            );
            $this->logEvent('gateway', 'info', "网关上线/注册 gw_id=$gwEui ($peer)", $gwEui);
        } else {
            Database::execute("UPDATE gateways SET last_seen=?, ip=? WHERE gw_id=?", [$now, $peer, $gwEui]);
        }
    }

    // ---------------- PUSH_DATA 处理 ----------------

    private function handlePush(array $json, string $peer, string $gwEui, float $rxTime = 0): void
    {
        // 先处理上行帧（含 Join-Accept 下发），再写网关状态统计。
        // 顺序很重要：Join-Accept 有 5s 窗口限制，DB 操作不能阻塞在前面。
        if (isset($json['rxpk']) && is_array($json['rxpk'])) {
            foreach ($json['rxpk'] as $rxpk) {
                $this->processUplink($rxpk, $peer, $gwEui, $rxTime);
            }
        }
        if (isset($json['stat'])) {
            $this->saveGatewayStat($gwEui, $json['stat']);
        }
    }

    private function saveGatewayStat(string $gwEui, array $stat): void
    {
        Database::execute(
            "UPDATE gateways SET last_seen=?, stats=? WHERE gw_id=?",
            [time(), json_encode($stat), $gwEui]
        );
    }

    // ---------------- 上行处理 ----------------

    private function processUplink(array $rxpk, string $peer, string $gwEui, float $rxTime = 0): void
    {
        if (empty($rxpk['data'])) {
            return;
        }
        $phy = base64_decode($rxpk['data'], true);
        if ($phy === false || $phy === '') {
            return;
        }
        $mtype = Frame::mtype($phy);
        $tmst = isset($rxpk['tmst']) ? (int) $rxpk['tmst'] : 0;
        $freq = isset($rxpk['freq']) ? (float) $rxpk['freq'] : 0;
        $datr = $rxpk['datr'] ?? 'SF12BW125';
        $rssi = isset($rxpk['rssi']) ? (int) $rxpk['rssi'] : 0;
        $lsnr = isset($rxpk['lsnr']) ? (float) $rxpk['lsnr'] : 0;

        if ($mtype === Frame::MTYPE_JOIN_REQUEST) {
            $this->log(sprintf("RX JoinRequest: gw=%s tmst=%d freq=%.3f datr=%s rssi=%d snr=%.1f (mtype=%d)", $gwEui, $tmst, $freq, $datr, $rssi, $lsnr, $mtype));
            $this->handleJoinRequest($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer, $rxTime);
        } elseif ($mtype === Frame::MTYPE_UNCONFIRMED_UP || $mtype === Frame::MTYPE_CONFIRMED_UP) {
            $this->handleDataUp($phy, $mtype, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer);
        } else {
            $this->log("Uplink ignored, mtype=$mtype");
        }
    }

    // ---------------- OTAA Join ----------------

    private function handleJoinRequest(string $phy, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, string $peer, float $rxTime = 0): void
    {
        $t0 = microtime(true);
        $jr = Frame::parseJoinRequest($phy);
        $devEui = bin2hex($jr['dev_eui']);
        $appEui = bin2hex($jr['app_eui']);
        $t1 = microtime(true);
        $device = Database::fetch(
            "SELECT * FROM devices WHERE dev_eui=? AND activation='OTAA'",
            [$devEui]
        );
        $t2 = microtime(true);
        if (!$device) {
            // 兼容部分模组以「反字节序」发送 DevEUI（常见 AT 指令模组）：
            $revDevEui = bin2hex(strrev($jr['dev_eui']));
            $device = Database::fetch(
                "SELECT * FROM devices WHERE dev_eui=? AND activation='OTAA'",
                [$revDevEui]
            );
        }
        $t3 = microtime(true);
        if (!$device) {
            $this->log("JOIN: unknown device devEUI=$devEui appEUI=$appEUI");
            $this->logEvent('join', 'error', "入网请求：未知设备 devEUI=$devEui appEUI=$appEui", $gwEui);
            return;
        }
        $appKey = hex2bin($device['app_key']);
        $t4 = microtime(true);
        if (!LoRaWANCrypto::verifyJoinRequestMIC($appKey, $phy)) {
            $this->log("JOIN: MIC failed for devEUI=$devEui");
            $this->logEvent('join', 'error', "入网请求：MIC 校验失败 devEUI=$devEui", $gwEui, $device['id'], $device['app_id']);
            return;
        }
        $t5 = microtime(true);

        // 去重键：Join-Request MIC（同一条物理包 → 同 MIC；网关镜像频率副本会被合并为一次下发）
        $micKey = bin2hex(LoRaWANCrypto::joinRequestMIC($appKey, substr($phy, 0, -4)));

        if (!isset($this->joinBuf[$micKey])) {
            // 首次出现：生成会话（只生成一次，避免重复副本生成不同的 devAddr / 会话密钥）
            $appNonce = random_bytes(3);
            $netId = random_bytes(3);
            $devAddr = $this->generateDevAddr();
            [$nwkSKey, $appSKey] = LoRaWANCrypto::computeSessionKeys($appKey, $appNonce, $netId, $jr['dev_nonce']);
            $dlSettings = 0x00; // RX1DRoffset=0, RX2DR=0
            $rxDelay = 0x01;    // 1s（实际表使用 region 默认，这里标记由设备侧生效）
            $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
            $joinAccept = Frame::buildJoinAccept($appKey, $appNonce, $netId, $devAddr, $dlSettings, $rxDelay, null);
            $t6 = microtime(true);

            // 保存会话（先建会话，下行在去重缓冲后统一下发，不影响 5s 窗口）
            Database::execute(
                "UPDATE devices SET dev_addr=?, nwk_s_key=?, app_s_key=?, status='active', fcnt_up=0, fcnt_down=0, join_eui=?, last_gw_id=?, last_seen=? WHERE id=?",
                [bin2hex($devAddr), bin2hex($nwkSKey), bin2hex($appSKey), $appEui, $gwEui, time(), $device['id']]
            );
            $this->log("JOIN OK devEUI=$devEui -> devAddr=" . bin2hex($devAddr) . sprintf(" (parse=%.0fms db_q=%.0fms mic=%.0fms key=%.0fms ja=%.0fms total=%.0fms)",
                ($t1-$t0)*1000, ($t3-$t2)*1000, ($t5-$t4)*1000, ($t6-$t5)*1000, 0, (microtime(true)-$t6)*1000));
            $this->logEvent('join', 'info', "入网成功 devEUI=$devEui -> devAddr=" . bin2hex($devAddr), $gwEui, $device['id'], $device['app_id']);
        } else {
            // 重复副本（如 SX130x 镜像频率）：沿用首份会话，仅更新“最强信号”副本用于下行频点选择
            $region = $this->joinBuf[$micKey]['region'];
            $joinAccept = $this->joinBuf[$micKey]['joinAccept'];
            $t6 = $t5;
        }

        // 缓冲下行：按 MIC 去重，约 80ms 后统一下发（取 RSSI 最强副本的频点/时隙），
        // 避免同一物理包被网关重复上送（真实信号 + 镜像频率）导致 NS 调度两次下行 → 网关 COLLISION_PACKET。
        $this->bufferJoinDownlink($micKey, $region, $joinAccept, $tmst, $freq, $datr, $rssi, $gwEui, $peer);
    }

    private function generateDevAddr(): string
    {
        do {
            $addr = random_bytes(4);
        } while (($addr[0] & "\xFE") === "\x00" || ($addr[0] & "\xFE") === "\xFE");
        return $addr;
    }

    // ---------------- 数据上行 ----------------

    private function handleDataUp(string $phy, int $mtype, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, string $peer): void
    {
        $p = Frame::parseDataUp($phy);
        $devAddrHex = bin2hex($p['dev_addr']);
        $device = Database::fetch("SELECT * FROM devices WHERE dev_addr=? AND status='active'", [$devAddrHex]);
        if (!$device) {
            $this->log("DATA UP: unknown devAddr=$devAddrHex");
            $this->logEvent('uplink', 'error', "上行：未知设备 devAddr=$devAddrHex", $gwEui);
            return;
        }
        Database::execute("UPDATE devices SET last_gw_id=? WHERE id=?", [$gwEui, $device['id']]);
        $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
        $nwkSKey = hex2bin($device['nwk_s_key']);
        $appSKey = hex2bin($device['app_s_key']);

        $fcnt = Frame::fullFCnt($p['fcnt_lo'], (int) $device['fcnt_up']);
        if (!Frame::verifyDataMIC($nwkSKey, 0, $p['dev_addr'], $fcnt, $p['data_without_mic'], $p['mic'])) {
            $this->log("DATA UP: MIC failed devAddr=$devAddrHex fcnt=$fcnt");
            return;
        }

        // 帧计数防重放
        if ($fcnt <= (int) $device['fcnt_up']) {
            $this->log("DATA UP: old/duplicate fcnt=$fcnt (last=" . $device['fcnt_up'] . ")");
            $this->logEvent('uplink', 'warn', "上行：重复/过期帧 devAddr=$devAddrHex fcnt=$fcnt", $gwEui, $device['id'], $device['app_id']);
            return;
        }

        // 解密应用负载
        $decrypted = '';
        if ($p['fport'] !== null && $p['frmpayload'] !== '') {
            $key = ($p['fport'] === 0) ? $nwkSKey : $appSKey;
            $decrypted = Frame::decryptFRMPayload($key, 0, $p['dev_addr'], $fcnt, $p['frmpayload']);
        }

        Database::execute(
            "UPDATE devices SET fcnt_up=?, last_seen=? WHERE id=?",
            [$fcnt, time(), $device['id']]
        );

        // 原始帧 + 网关元数据（用于前端“原始 JSON”查看与第三方对接）
        $rawJson = json_encode([
            'dev_addr'   => $devAddrHex,
            'dev_eui'    => $device['dev_eui'],
            'fcnt'       => $fcnt,
            'port'       => $p['fport'] ?? 0,
            'confirmed'  => ($mtype === Frame::MTYPE_CONFIRMED_UP) ? 1 : 0,
            'decrypted_hex' => bin2hex($decrypted),
            'phy_payload'=> bin2hex($phy),
            'gateway_id' => $gwEui,
            'rssi'       => $rssi,
            'snr'        => $lsnr,
            'frequency'  => $freq,
            'data_rate'  => $datr,
            'tmst'       => $tmst,
            'received_at'=> time(),
        ], JSON_UNESCAPED_UNICODE);

        Database::execute(
            "INSERT INTO uplinks (dev_id, app_id, dev_addr, dev_eui, fcnt, port, confirmed, payload_hex, decrypted_hex, phy_payload, data_rate, frequency, rssi, snr, gateway_id, received_at, raw_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $device['id'], $device['app_id'], $devAddrHex, $device['dev_eui'], $fcnt,
                $p['fport'] ?? 0, ($mtype === Frame::MTYPE_CONFIRMED_UP) ? 1 : 0,
                bin2hex($p['frmpayload']), bin2hex($decrypted), bin2hex($phy), $datr, $freq, $rssi, $lsnr, $gwEui, time(), $rawJson,
            ]
        );
        $this->log("DATA UP devAddr=$devAddrHex fcnt=$fcnt port=" . ($p['fport'] ?? '-') . " payload=" . bin2hex($decrypted));
        $this->logEvent('uplink', 'info', "上行接收 devAddr=$devAddrHex fcnt=$fcnt port=" . ($p['fport'] ?? '-') . " rssi=$rssi snr=$lsnr", $gwEui, $device['id'], $device['app_id']);

        // 设备遥测回调：解析电量/链路余量(GPS 等)写回设备表，并触发应用 Webhook
        $telemetry = $this->captureTelemetry($device, $p, $decrypted);
        $this->fireCallback($device['app_id'], [
            'dev_eui'    => $device['dev_eui'],
            'dev_addr'   => $devAddrHex,
            'fcnt'       => $fcnt,
            'port'       => $p['fport'] ?? 0,
            'confirmed'  => ($mtype === Frame::MTYPE_CONFIRMED_UP) ? 1 : 0,
            'payload_hex'=> bin2hex($decrypted),
            'rssi'       => $rssi,
            'snr'        => $lsnr,
            'gateway_id' => $gwEui,
            'received_at'=> time(),
        ] + $telemetry);

        // 确认帧（ConfirmedDataUp）
        if ($mtype === Frame::MTYPE_CONFIRMED_UP) {
            $downPhy = Frame::buildDataDown($nwkSKey, $appSKey, 1, $p['dev_addr'], (int) $device['fcnt_down'] + 1, false, true, null, '');
            $this->bumpDownFCnt($device['id']);
            // rxpk.tmst = end of reception, so dl_tmst = tmst + delay (no airtime)
            $this->enqueueDownlink($gwEui, $peer, $downPhy, $tmst + $region->getReceiveDelay1() * 1000, $freq, $datr, false);
        }

        // 应用层待发下行（rxpk.tmst = end of reception, no airtime needed）
        $this->dispatchPendingAppDownlinks($device, $region, $tmst, $freq, $datr, $gwEui, $peer, $p['dev_addr'], $nwkSKey, $appSKey);
    }

    private function dispatchPendingAppDownlinks(array $device, Region $region, int $tmst, float $freq, string $datr, string $gwEui, string $peer, string $devAddrBin, string $nwkSKey, string $appSKey): void
    {
        $pendings = Database::fetchAll(
            "SELECT * FROM downlinks WHERE dev_id=? AND status='pending' ORDER BY id ASC LIMIT 1",
            [$device['id']]
        );
        foreach ($pendings as $dl) {
            $payload = hex2bin($dl['payload_hex']);
            $confirmed = (int) $dl['confirmed'] === 1;
            $fcntDown = (int) $device['fcnt_down'] + 1;
            $downPhy = Frame::buildDataDown($nwkSKey, $appSKey, 1, $devAddrBin, $fcntDown, $confirmed, false, (int) $dl['port'], $payload);
            $this->bumpDownFCnt($device['id']);
            $rx1Tmst = $tmst + $region->getReceiveDelay1() * 1000;
            $this->enqueueDownlink($gwEui, $peer, $downPhy, $rx1Tmst, $freq, $datr, false);
            // Class A 的 RX2 窗口（与 RX1 相同时频规划下使用 RX2 频点/速率）
            $rx2Tmst = $tmst + $region->getReceiveDelay2() * 1000;
            $this->enqueueDownlink($gwEui, $peer, $downPhy, $rx2Tmst, $region->getRx2Frequency() / 1e6, $region->drToDatr($region->getRx2DataRate()), false);
            Database::execute("UPDATE downlinks SET status='sent', fcnt=?, sent_at=? WHERE id=?", [$fcntDown, time(), $dl['id']]);
            $this->log("APP DOWNLINK -> dev_id={$device['id']} port={$dl['port']}");
            $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} port={$dl['port']} (Class A RX1/RX2)", $gwEui, $device['id'], $device['app_id']);
        }
    }

    private function bumpDownFCnt(int $devId): void
    {
        Database::execute("UPDATE devices SET fcnt_down = fcnt_down + 1 WHERE id=?", [$devId]);
    }

    /**
     * 解析设备上行中的遥测数据并写回 devices 表，返回供 Webhook 使用的字段。
     * 约定（与前端/设备端对齐）：
     *  - 端口 0（MAC 命令）且首字节 0x06 = DevStatusAns：第 2 字节电量(0=外部供电,1..254=%)，第 3 字节链路余量(6bit 有符号, -32..31 dB)。
     *  - 端口 4 且净负载恰好 10 字节：lat(int32 LE, ×1e-6°), lon(int32 LE, ×1e-6°), alt(int16 LE, m) —— 简易 GPS 编码。
     */
    private function captureTelemetry(array $device, array $p, string $decryptedBin): array
    {
        $upd = [];
        $params = [];
        $telemetry = ['battery' => null, 'margin' => null, 'latitude' => null, 'longitude' => null, 'altitude' => null];
        $fport = $p['fport'] ?? null;

        if ($fport === 0 && strlen($decryptedBin) >= 3 && $decryptedBin[0] === "\x06") {
            $bat = ord($decryptedBin[1]); // 0=外部供电, 1..254=百分比, 255=保留
            $m = ord($decryptedBin[2]);
            $mg = $m & 0x3F;
            if ($mg > 31) {
                $mg -= 64;
            }
            $upd[] = 'battery=?';
            $params[] = $bat;
            $upd[] = 'margin=?';
            $params[] = $mg;
            $telemetry['battery'] = $bat;
            $telemetry['margin'] = $mg;
        }

        if ($fport === 4 && strlen($decryptedBin) === 10) {
            $lat = unpack('V', substr($decryptedBin, 0, 4))[1];
            if ($lat > 0x7FFFFFFF) {
                $lat -= 0x100000000;
            }
            $lon = unpack('V', substr($decryptedBin, 4, 4))[1];
            if ($lon > 0x7FFFFFFF) {
                $lon -= 0x100000000;
            }
            $alt = unpack('v', substr($decryptedBin, 8, 2))[1];
            if ($alt > 0x7FFF) {
                $alt -= 0x10000;
            }
            $lat /= 1e6;
            $lon /= 1e6;
            if (abs($lat) <= 90 && abs($lon) <= 180) {
                $upd[] = 'latitude=?';
                $params[] = $lat;
                $upd[] = 'longitude=?';
                $params[] = $lon;
                $upd[] = 'altitude=?';
                $params[] = $alt;
                $telemetry['latitude'] = $lat;
                $telemetry['longitude'] = $lon;
                $telemetry['altitude'] = $alt;
            }
        }

        if ($upd) {
            $params[] = $device['id'];
            Database::execute("UPDATE devices SET " . implode(', ', $upd) . " WHERE id=?", $params);
            $this->log("TELEMETRY dev#{$device['id']} " . implode(' ', array_filter(array_map(
                fn($k, $v) => $v === null ? '' : "$k=$v",
                array_keys($telemetry),
                $telemetry
            ), 'strlen')));
        }
        return $telemetry;
    }

    /**
     * 应用级 Webhook 回调（设备回调）。若应用配置了 callback_url，则异步 POST 上行/遥测 JSON。
     * 使用非阻塞 socket，避免在 UDP 接收循环里等待 HTTP 响应。发送失败仅记录日志，不影响主流程。
     */
    private function fireCallback(int $appId, array $data): void
    {
        $app = Database::fetch("SELECT callback_url FROM applications WHERE id=?", [$appId]);
        if (!$app || empty($app['callback_url'])) {
            return;
        }
        $url = $app['callback_url'];
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (!empty($parts['query']) ? '?' . $parts['query'] : '');
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);
        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        $fp = @stream_socket_client(
            "$transport://$host:$port",
            $errno,
            $errstr,
            1.0,
            STREAM_CLIENT_ASYNC_CONNECT
        );
        if (!$fp) {
            $this->log("CALLBACK: connect failed app#$appId ($errstr)");
            return;
        }
        stream_set_blocking($fp, false);
        $req = "POST $path HTTP/1.1\r\n"
            . "Host: $host\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        @fwrite($fp, $req);
        @fclose($fp);
    }

    // ---------------- Class B / C 主动下行调度 ----------------

    /**
     * 周期调用：处理 Class C（立即下发）与 Class B（ping 时隙）的待发下行。
     * Class A 下行仍由上行 RX1/RX2 窗口触发（见 dispatchPendingAppDownlinks）。
     */
    private function processScheduledDownlinks(): void
    {
        $rows = Database::fetchAll(
            "SELECT d.*, dev.nwk_s_key, dev.app_s_key, dev.dev_addr, dev.class, dev.last_gw_id,
                    dev.ping_period, dev.beacon_epoch, dev.region, dev.fcnt_down AS dev_fcnt_down
             FROM downlinks d
             JOIN devices dev ON dev.id = d.dev_id
             WHERE d.status='pending' AND dev.class IN ('B','C')"
        );
        $now = time();
        foreach ($rows as $dl) {
            $device = [
                'id'          => $dl['dev_id'],
                'app_id'      => (int) $dl['app_id'],
                'nwk_s_key'   => $dl['nwk_s_key'],
                'app_s_key'   => $dl['app_s_key'],
                'dev_addr'    => $dl['dev_addr'],
                'class'       => $dl['class'],
                'last_gw_id'  => $dl['last_gw_id'],
                'ping_period' => (int) $dl['ping_period'],
                'beacon_epoch'=> (int) $dl['beacon_epoch'],
                'region'      => $dl['region'],
                'fcnt_down'   => (int) $dl['dev_fcnt_down'],
            ];
            $sendAt = ($device['class'] === 'C') ? $now : $this->nextPingSlot($device, $now);
            if ($now < $sendAt) {
                continue; // 尚未到 ping 时隙，下一轮再试
            }
            $this->sendDeviceDownlink($device, $dl, true);
        }
    }

    /** 计算下一个 Class B ping 时隙（Unix 秒，>= $now）。
     *  真实部署应由网关 GPS 时间同步信标；此处用可配置的 beacon_epoch + ping_period 推算。 */
    private function nextPingSlot(array $device, int $now): int
    {
        $period = $device['ping_period'] > 0 ? $device['ping_period'] : ELW_PING_PERIOD;
        $epoch = $device['beacon_epoch'] > 0 ? $device['beacon_epoch']
            : (ELW_BEACON_EPOCH > 0 ? ELW_BEACON_EPOCH : $now);
        if ($epoch >= $now) {
            return $epoch;
        }
        $k = (int) ceil(($now - $epoch) / $period);
        $next = $epoch + $k * $period;
        return $next >= $now ? $next : $now;
    }

    /** 构造并下发单条设备下行（用于 Class B/C 主动调度）。 */
    private function sendDeviceDownlink(array $device, array $dl, bool $imme): void
    {
        $gwEui = $device['last_gw_id'];
        if ($gwEui === '') {
            return; // 尚不知服务网关，等待网关保活后再发
        }
        $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
        $nwkSKey = hex2bin($device['nwk_s_key']);
        $appSKey = hex2bin($device['app_s_key']);
        $devAddrBin = hex2bin($device['dev_addr']);
        $payload = hex2bin($dl['payload_hex']);
        $confirmed = (int) $dl['confirmed'] === 1;
        $fcntDown = (int) $device['fcnt_down'] + 1;
        $downPhy = Frame::buildDataDown($nwkSKey, $appSKey, 1, $devAddrBin, $fcntDown, $confirmed, false, (int) $dl['port'], $payload);
        $this->bumpDownFCnt($device['id']);
        $peer = $this->gateways[$gwEui]['addr'] ?? '';
        $this->enqueueDownlink($gwEui, $peer, $downPhy, 0, $region->getRx2Frequency() / 1e6, $region->drToDatr($region->getRx2DataRate()), $imme);
        Database::execute("UPDATE downlinks SET status='sent', fcnt=?, sent_at=? WHERE id=?", [$fcntDown, time(), $dl['id']]);
        $this->log("SCHED DOWNLINK -> dev_id={$device['id']} class={$device['class']} port={$dl['port']}");
        $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} class={$device['class']} port={$dl['port']}", $gwEui, $device['id'], $device['app_id']);
    }

    // ---------------- 下行入队 / 下发 ----------------

    /**
     * 估算上行帧的空口时长（µs）。
     *
     * ⚠️ 此函数目前保留供参考/调试使用，下行调度【不再调用】它。
     *
     * Semtech UDP 协议 v1.7 明确规定：rxpk.tmst = "finished receiving"（接收结束时刻），
     * 不是帧起始时刻。因此设备 RX1/RX2 窗口打开时刻 = rxpk.tmst + delay，
     * 下行 txpk.tmst = rxpk.tmst + delay，【不需要加 airtime】。
     *
     * 旧代码错误地认为 tmst 是帧起始时刻，加了 airtime，导致下行晚发 1.483s（SF12），
     * RX1 和 RX2 窗口全部错过。墙钟交叉验证确认：
     *   ul_tmst + 5s = 73302617 -> 17:19:15.635 ≈ 设备日志 RX_1 at 17:19:15.657 ✓
     *   ul_tmst + 6s = 74302617 -> 17:19:16.635 ≈ 设备日志 RX_2 at 17:19:16.655 ✓
     *
     * 公式照搬固件 RadioGetLoRaTimeOnAirNumerator (radio.c:1228) + RadioTimeOnAir (radio.c:1292)，
     * 用于诊断/验证设备空口时长，不参与下行时序计算。
     *
     * LoRaWAN 常量取自固件 Radio.SetTxConfig 调用(如 RegionEU868.c:657)：
     *   coderate = 1 (4/5)、preambleLen = 8、fixLen = false(显式头)、crcOn = true。
     */
    private function uplinkAirtimeUs(string $phy, $datr, Region $region): int
    {
        $sf = 0;
        $bw = 0;
        if (preg_match('/SF(\d+)BW(\d+)/i', (string) $datr, $m)) {
            $sf = (int) $m[1];
            $bw = (int) $m[2] * 1000; // Hz
        } elseif (is_numeric($datr)) {
            $d = $region->getDataRate((int) $datr);
            $sf = $d['sf'];
            $bw = $d['bw'] * 1000;
        }
        if ($sf <= 0 || $bw <= 0) {
            // 解析失败：回退 SF12BW125（DR0），并告警
            $this->log("WARN uplinkAirtimeUs: 无法解析 datr=" . var_export($datr, true) . "，回退 SF12BW125（下行时序可能为最保守估计）");
            $sf = 12;
            $bw = 125000;
        }
        $pl = strlen($phy);                 // PHYPayload 字节数
        // —— 以下完全照搬固件 RadioGetLoRaTimeOnAirNumerator / RadioTimeOnAir ——
        $coderate = 1;                      // LoRaWAN 4/5
        $preambleLen = 8;                   // LoRaWAN 标准前导 8 符号
        $fixLen = false;                    // 显式头(IH=0)
        $crcOn = true;                      // 开 CRC
        $crDenom = $coderate + 4;          // 5
        $lowDatareOptimize = (($bw === 125000 && ($sf === 11 || $sf === 12)) || ($bw === 250000 && $sf === 12));
        $ceilNumerator = ($pl << 3) + ($crcOn ? 16 : 0) - (4 * $sf) + ($fixLen ? 0 : 20);
        if ($sf <= 6) {
            $ceilDenominator = 4 * $sf;
        } else {
            $ceilNumerator += 8;
            $ceilDenominator = $lowDatareOptimize ? 4 * ($sf - 2) : 4 * $sf;
        }
        if ($ceilNumerator < 0) {
            $ceilNumerator = 0;
        }
        $intermediate = (int) floor(($ceilNumerator + $ceilDenominator - 1) / $ceilDenominator) * $crDenom + $preambleLen + 12;
        if ($sf <= 6) {
            $intermediate += 2;
        }
        // RadioGetLoRaTimeOnAirNumerator 返回 (4*intermediate+1) * 2^(sf-2)
        $numerator = (4 * $intermediate + 1) * (1 << ($sf - 2));
        // RadioTimeOnAir: 1000*numerator / bwHz → 毫秒；再转微秒
        $toaMs = (int) ceil((1000 * $numerator) / $bw);
        return $toaMs * 1000;
    }

    /**
     * Join-Request 下行去重缓冲。
     * - 同一 MIC（同一条物理包）只生成一次会话，重复副本合并。
     * - 记录 RSSI 最强副本，用于决定下行频点/时隙（绕过 SX130x 镜像频率误调度）。
     * - 实际下发由 flushJoinBuffer() 在 ~80ms 防抖后统一执行（合并 6µs 内的镜像副本）。
     */
    private function bufferJoinDownlink(string $micKey, Region $region, string $joinAccept, int $tmst, float $freq, string $datr, int $rssi, string $gwEui, string $peer): void
    {
        if (!isset($this->joinBuf[$micKey])) {
            $this->joinBuf[$micKey] = [
                'region'     => $region,
                'joinAccept' => $joinAccept,
                'gwEui'      => $gwEui,
                'peer'       => $peer,
                'bestRssi'   => $rssi,
                'bestTmst'   => $tmst,
                'bestFreq'   => $freq,
                'bestDatr'   => $datr,
                'firstSeen'  => microtime(true),
                'scheduled'  => false,
            ];
        } else {
            $e = &$this->joinBuf[$micKey];
            // 取 RSSI 更强（数值更大）的副本决定下行频点/时隙
            if ($rssi > $e['bestRssi']) {
                $e['bestRssi'] = $rssi;
                $e['bestTmst'] = $tmst;
                $e['bestFreq'] = $freq;
                $e['bestDatr'] = $datr;
            }
        }
    }

    /**
     * 刷新 Join-Request 去重缓冲：对停留 >= 80ms 且尚未下发的条目，按 RSSI 最强副本统一下发一次 RX1+RX2。
     * 80ms 远小于 JOIN_ACCEPT_DELAY1(5s)，不影响设备接收窗口。
     */
    private function flushJoinBuffer(): void
    {
        $now = microtime(true);
        foreach ($this->joinBuf as $micKey => $e) {
            if ($e['scheduled']) {
                if ($now - $e['firstSeen'] > 10) {
                    unset($this->joinBuf[$micKey]); // 已下发，过期清理
                }
                continue;
            }
            if ($now - $e['firstSeen'] < 0.08) {
                continue; // 仍在收集重复副本（镜像频率等），稍后再下发
            }
            $region = $e['region'];
            $joinAccept = $e['joinAccept'];
            $gwEui = $e['gwEui'];
            $peer = $e['peer'];
            $tmst = $e['bestTmst'];
            $freq = $e['bestFreq'];
            $datr = $e['bestDatr'];

            // RX1：设备在其上行频点监听（取 RSSI 最强副本的频点，避开镜像频率）
            $dlTmstRx1 = $tmst + $region->getJoinAcceptDelay1() * 1000;
            $this->log(sprintf(
                "JOIN DOWNLINK RX1: gw=%s ul_tmst=%d delay=%dms dl_tmst_rx1=%d RX1freq=%.3f RX1datr=%s (dedup rssi=%d)",
                $gwEui, $tmst, $region->getJoinAcceptDelay1(), $dlTmstRx1, $freq, $datr, $e['bestRssi']
            ));
            $this->enqueueDownlink($gwEui, $peer, $joinAccept, $dlTmstRx1, $freq, $datr, false);

            // RX2 fallback：固定频点 869.525 / SF12BW125
            // 关键：SX130x 仅单 TX 路径。若 RX1 下行空口时长 ≥ RX1→RX2 间隔(1s)，RX2(tmst+6s) 会与
            // RX1 发射尾部(约 tmst+5s+airtime)重叠，网关虽把 RX2 判 COLLISION_PACKET 不发射，但重叠调度会
            // 破坏 RX1 尾部（含 MIC 末 4 字节）→ 设备收到 RX1 但 MIC 校验失败 → JOIN FAILED。
            // 因此下行空口 ≥ 间隔时只发 RX1（设备在主窗口即入网，RX2 为冗余兜底，跳过不影响）。
            $dlGapUs = ($region->getJoinAcceptDelay2() - $region->getJoinAcceptDelay1()) * 1000; // RX1→RX2 间隔：延时为 ms，转 µs = (6000-5000)*1000 = 1,000,000 µs
            $jaAirtimeUs = $this->uplinkAirtimeUs($joinAccept, $datr, $region);
            if ($jaAirtimeUs > $dlGapUs - 20000) {
                $this->log(sprintf(
                    "JOIN DOWNLINK RX2: SKIPPED (airtime=%.0fus >= gap=%.0fus, 避免与 RX1 发射尾部冲突导致 MIC 损坏)",
                    $jaAirtimeUs, $dlGapUs
                ));
            } else {
                $dlTmstRx2 = $tmst + $region->getJoinAcceptDelay2() * 1000;
                $rx2Freq = $region->getRx2Frequency() / 1e6;
                $rx2Datr = $region->drToDatr($region->getRx2DataRate());
                $this->log(sprintf(
                    "JOIN DOWNLINK RX2: dl_tmst_rx2=%d RX2freq=%.3f RX2datr=%s",
                    $dlTmstRx2, $rx2Freq, $rx2Datr
                ));
                $this->enqueueDownlink($gwEui, $peer, $joinAccept, $dlTmstRx2, $rx2Freq, $rx2Datr, false);
            }

            $this->joinBuf[$micKey]['scheduled'] = true;
        }
    }

    private function enqueueDownlink(string $gwEui, string $peer, string $phy, int $tmst, float $freq, string $datr, bool $imme): void
    {
        if ($gwEui === '') {
            $gwEui = md5($peer);
        }
        if (!isset($this->gateways[$gwEui])) {
            $this->gateways[$gwEui] = ['addr' => $peer, 'pending' => []];
        }
        $this->gateways[$gwEui]['pending'][] = [
            'phy'  => $phy,
            'tmst' => $tmst,
            'freq' => $freq,
            'datr' => $datr,
            'imme' => $imme,
        ];
        // 若网关已保活，立即尝试下发
        if (isset($this->gateways[$gwEui]['addr'])) {
            $this->flushDownlink($gwEui, $this->gateways[$gwEui]['addr']);
        }
    }

    private function flushDownlink(string $gwEui, string $peer): void
    {
        if (!isset($this->gateways[$gwEui]) || empty($this->gateways[$gwEui]['pending'])) {
            return;
        }
        foreach ($this->gateways[$gwEui]['pending'] as $item) {
            $txpk = [
                'imme' => $item['imme'],
                'tmst' => $item['imme'] ? 0 : ($item['tmst'] & 0xFFFFFFFF),
                'freq' => $item['freq'],
                'rfch' => 0,
                // 下行功率：与 ChirpStack EU868 get_downlink_tx_power_eirp() 对齐
                // RX1 频段(863~869.2MHz) = 16dBm；RX2 频段(869.4~869.65MHz) = 29dBm
                'powe' => ($item['freq'] >= 869400000 && $item['freq'] <= 869650000) ? 29 : 16,
                'modu' => 'LORA',
                'datr' => $item['datr'],
                'codr' => '4/5',
                'ipol' => true,   // LoRaWAN 下行必须用反转 IQ（与 TTN/ChirpStack 一致）
                'size' => strlen($item['phy']),
                'data' => base64_encode($item['phy']),
            ];
            $json = json_encode(['txpk' => $txpk]);
            // Semtech UDP PULL_RESP 协议：version + token + id(0x03) + JSON
            // ★ 关键：version 必须回显网关自身使用的协议版本（现代 lora_pkt_fwd 为 0x02，见其 PROTOCOL_VERSION）。
            // 若写死 0x01，包转发器会在 parse_pull_resp 里因「协议版本不匹配」直接丢弃下行：网关不发射、不回 TX_ACK，
            // 设备表现为 JOIN FAILED。ChirpStack 正常正是因为它用了 0x02。token 仅作关联 ID，网关不校验其值。
            $ver = $this->gateways[$gwEui]['version'] ?? "\x01";
            $tok = $this->gateways[$gwEui]['pull_token'] ?? "\x00\x00";
            $pkt = $ver . $tok . "\x03" . $json;
            @stream_socket_sendto($this->sock, $pkt, 0, $peer);
            $this->log(sprintf(
                "PULL_RESP sent gw=%s peer=%s tmst=%d freq=%.3f datr=%s imme=%d phy=%s txpk=%s",
                $gwEui, $peer, $item['tmst'], $item['freq'], $item['datr'], $item['imme'] ? 1 : 0, bin2hex($item['phy']), $json
            ));
        }
        $this->gateways[$gwEui]['pending'] = [];
    }

    // ---------------- 日志 ----------------

    private function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '.' . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . '] ' . $msg . "\n";
        fwrite(STDOUT, $line);
        if (is_dir(ELW_LOG_DIR)) {
            file_put_contents(ELW_LOG_DIR . '/ns.log', $line, FILE_APPEND | LOCK_EX);
        }
    }

    /** 记录一条事件到 events 表（同时写文本日志）。失败不影响主流程。 */
    private function logEvent(string $type, string $level, string $message, string $gwId = '', ?int $devId = 0, ?int $appId = 0): void
    {
        try {
            Database::execute(
                "INSERT INTO events (type, level, gateway_id, dev_id, app_id, message, created_at) VALUES (?,?,?,?,?,?,?)",
                [$type, $level, $gwId, $devId, $appId, $message, time()]
            );
        } catch (\Throwable $e) {
            // 事件落库失败不应中断接收链路
        }
        $this->log("[$type/$level] $message");
    }
}
