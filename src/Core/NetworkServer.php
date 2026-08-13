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
            $write = $except = null;
            $n = @stream_select($read, $write, $except, 1);
            if ($n === false) {
                continue;
            }
            if ($n > 0) {
                $peer = '';
                $data = stream_socket_recvfrom($this->sock, 65535, 0, $peer);
                if ($data !== false && $data !== '') {
                    $this->handlePacket($data, $peer);
                }
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    // ---------------- 包分发 ----------------

    private function handlePacket(string $data, string $peer): void
    {
        if (strlen($data) < 4) {
            return;
        }
        $version = ord($data[0]);
        $token = substr($data, 1, 2);
        $id = ord($data[3]);
        $gwEui = (strlen($data) >= 12) ? bin2hex(substr($data, 4, 8)) : '';

        switch ($id) {
            case 0x00: // PUSH_DATA
                $this->sendAck(0x01, $token, $peer);
                $json = json_decode(substr($data, 12), true);
                if (is_array($json)) {
                    $this->handlePush($json, $peer, $gwEui);
                }
                break;
            case 0x02: // PULL_DATA
                $this->sendAck(0x04, $token, $peer);
                $this->registerGateway($peer, $gwEui);
                $this->flushDownlink($gwEui, $peer);
                break;
            case 0x05: // TX_ACK
                // 可选：记录网关下行发射结果
                break;
            default:
                break;
        }
    }

    private function sendAck(int $id, string $token, string $peer): void
    {
        $pkt = "\x01" . $token . chr($id);
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

    private function handlePush(array $json, string $peer, string $gwEui): void
    {
        if (isset($json['stat'])) {
            $this->saveGatewayStat($gwEui, $json['stat']);
        }
        if (isset($json['rxpk']) && is_array($json['rxpk'])) {
            foreach ($json['rxpk'] as $rxpk) {
                $this->processUplink($rxpk, $peer, $gwEui);
            }
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

    private function processUplink(array $rxpk, string $peer, string $gwEui): void
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
            $this->handleJoinRequest($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer);
        } elseif ($mtype === Frame::MTYPE_UNCONFIRMED_UP || $mtype === Frame::MTYPE_CONFIRMED_UP) {
            $this->handleDataUp($phy, $mtype, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer);
        } else {
            $this->log("Uplink ignored, mtype=$mtype");
        }
    }

    // ---------------- OTAA Join ----------------

    private function handleJoinRequest(string $phy, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, string $peer): void
    {
        $jr = Frame::parseJoinRequest($phy);
        $devEui = bin2hex($jr['dev_eui']);
        $appEui = bin2hex($jr['app_eui']);
        $device = Database::fetch(
            "SELECT * FROM devices WHERE dev_eui=? AND activation='OTAA'",
            [$devEui]
        );
        if (!$device) {
            // 兼容部分模组以「反字节序」发送 DevEUI（常见 AT 指令模组）：
            // 空中实际字节是设备标签的反序，再试一次反序值，使按标签（规范序）注册也能入网。
            $revDevEui = bin2hex(strrev($jr['dev_eui']));
            $device = Database::fetch(
                "SELECT * FROM devices WHERE dev_eui=? AND activation='OTAA'",
                [$revDevEui]
            );
        }
        if (!$device) {
            $this->log("JOIN: unknown device devEUI=$devEui appEUI=$appEUI");
            $this->logEvent('join', 'error', "入网请求：未知设备 devEUI=$devEui appEUI=$appEui", $gwEui);
            return;
        }
        $appKey = hex2bin($device['app_key']);
        if (!LoRaWANCrypto::verifyJoinRequestMIC($appKey, $phy)) {
            $this->log("JOIN: MIC failed for devEUI=$devEui");
            $this->logEvent('join', 'error', "入网请求：MIC 校验失败 devEUI=$devEui", $gwEui, $device['id'], $device['app_id']);
            return;
        }

        // 生成会话
        $appNonce = random_bytes(3);
        $netId = random_bytes(3);
        $devAddr = $this->generateDevAddr();
        [$nwkSKey, $appSKey] = LoRaWANCrypto::computeSessionKeys($appKey, $appNonce, $netId, $jr['dev_nonce']);

        $dlSettings = 0x00; // RX1DRoffset=0, RX2DR=0
        $rxDelay = 0x01;    // 1s（实际表使用 region 默认，这里标记由设备侧生效）
        $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
        // 让设备使用区域默认 RX2：DLSettings 的 RX2 DataRate 由 NS 配置，这里用 0
        $joinAccept = Frame::buildJoinAccept($appKey, $appNonce, $netId, $devAddr, $dlSettings, $rxDelay, null);

        // 保存会话
        Database::execute(
            "UPDATE devices SET dev_addr=?, nwk_s_key=?, app_s_key=?, status='active', fcnt_up=0, fcnt_down=0, join_eui=?, last_gw_id=? WHERE id=?",
            [bin2hex($devAddr), bin2hex($nwkSKey), bin2hex($appSKey), $appEui, $gwEui, $device['id']]
        );
        $this->log("JOIN OK devEUI=$devEui -> devAddr=" . bin2hex($devAddr));
        $this->logEvent('join', 'info', "入网成功 devEUI=$devEui -> devAddr=" . bin2hex($devAddr), $gwEui, $device['id'], $device['app_id']);

        // 下发 Join Accept（RX1 窗口：上行结束 + JOIN_ACCEPT_DELAY1）
        $this->enqueueDownlink($gwEui, $peer, $joinAccept, $tmst + $this->uplinkAirtimeUs($phy, $datr) + $region->getJoinAcceptDelay1() * 1000, $freq, $datr, false);
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
            "UPDATE devices SET fcnt_up=? WHERE id=?",
            [$fcnt, $device['id']]
        );
        Database::execute(
            "INSERT INTO uplinks (dev_id, app_id, dev_addr, dev_eui, fcnt, port, confirmed, payload_hex, decrypted_hex, phy_payload, data_rate, frequency, rssi, snr, gateway_id, received_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $device['id'], $device['app_id'], $devAddrHex, $device['dev_eui'], $fcnt,
                $p['fport'] ?? 0, ($mtype === Frame::MTYPE_CONFIRMED_UP) ? 1 : 0,
                bin2hex($p['frmpayload']), bin2hex($decrypted), bin2hex($phy), $datr, $freq, $rssi, $lsnr, $gwEui, time(),
            ]
        );
        $this->log("DATA UP devAddr=$devAddrHex fcnt=$fcnt port=" . ($p['fport'] ?? '-') . " payload=" . bin2hex($decrypted));
        $this->logEvent('uplink', 'info', "上行接收 devAddr=$devAddrHex fcnt=$fcnt port=" . ($p['fport'] ?? '-') . " rssi=$rssi snr=$lsnr", $gwEui, $device['id'], $device['app_id']);

        // 确认帧（ConfirmedDataUp）
        if ($mtype === Frame::MTYPE_CONFIRMED_UP) {
            $downPhy = Frame::buildDataDown($nwkSKey, $appSKey, 1, $p['dev_addr'], (int) $device['fcnt_down'] + 1, false, true, null, '');
            $this->bumpDownFCnt($device['id']);
            $this->enqueueDownlink($gwEui, $peer, $downPhy, $tmst + $region->getReceiveDelay1() * 1000, $freq, $datr, false);
        }

        // 应用层待发下行
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
     * 估算上行帧的空口时长（µs），用于把下行窗口对齐到「设备发送结束 + 延迟」。
     * Semtech UDP 的 rxpk.tmst 是上行帧起始时刻，而设备 RX1/RX2 窗口从「发送结束」起算，
     * 因此下发 tmst 必须加上本帧空口时长，否则下行会比设备 RX 窗口早一帧时长 → 设备收不到
     * （模拟器忽略 tmst 故测不出，真实网关会因此丢 Join-Accept / 下行）。公式参照 SX1276 数据手册
     * （显式头、CRC 开、CR=1）。
     */
    private function uplinkAirtimeUs(string $phy, string $datr): int
    {
        if (!preg_match('/SF(\d+)BW(\d+)/i', $datr, $m)) {
            return 0;
        }
        $sf = (int) $m[1];
        $bw = (int) $m[2] * 1000; // Hz
        if ($bw <= 0) {
            return 0;
        }
        $pl = strlen($phy);
        $de = ($sf >= 11 && $bw === 125000) ? 1 : 0; // SF11/12 @125k 低速率优化
        $ts = (2 ** $sf) / $bw; // 每符号秒数
        $num = 8 * $pl - 4 * $sf + 44 - 20 * $de;
        $den = 4 * ($sf - 2 * $de);
        $nPayload = 8 + (int) ceil(max(0, $num) / (float) $den);
        $symbols = $nPayload + 8 + 4.25; // 载荷符号 + 前导(8 + 4.25)
        return (int) round($symbols * $ts * 1_000_000);
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
                'tmst' => $item['imme'] ? 0 : $item['tmst'],
                'freq' => $item['freq'],
                'rfch' => 0,
                'powe' => 14,
                'modu' => 'LORA',
                'datr' => $item['datr'],
                'codr' => '4/5',
                'fdev' => 0,
                'size' => strlen($item['phy']),
                'ncrc' => true,
                'data' => base64_encode($item['phy']),
            ];
            $json = json_encode(['txpk' => $txpk]);
            // Semtech UDP PULL_RESP 协议：version(0x01) + token(0x0000) + id(0x03) + JSON
            $pkt = "\x01" . "\x00\x00" . "\x03" . $json;
            @stream_socket_sendto($this->sock, $pkt, 0, $peer);
            $this->log("PULL_RESP sent to $gwEui (tmst={$item['tmst']}, " . bin2hex($item['phy']) . ")");
        }
        $this->gateways[$gwEui]['pending'] = [];
    }

    // ---------------- 日志 ----------------

    private function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
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
