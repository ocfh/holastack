<?php
namespace holastack\Core;

use holastack\Crypto\LoRaWANCrypto;
use holastack\Region\Region;
use holastack\DB\Database;
use holastack\Core\MacCommands;
use holastack\Core\Adr;
use holastack\Core\LoRaWANVersion;
use holastack\Storage\DeviceProfile;
use holastack\Integration\Integration;
use holastack\Core\Multicast;

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
    private $uplinkBuf = [];   // 上行去重缓冲：devAddr+fcnt => 首收时间戳（合并多网关重复上送，避免重复 Webhook/ACK/下行）

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
                $this->processScheduledMulticast();
                $this->rescheduleUnackedDownlinks();
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
                // 下行发射失败反压（对齐 ChirpStack：网关拒绝发射时记录 txack 错误事件，便于排查）
                if ($ackStatus !== 'ok' && $ackStatus !== '' && $ackStatus !== 'NONE') {
                    $this->logEvent('txack', 'warn', "网关下行发射失败 gw=$gwEui error=$ackStatus", $gwEui);
                }
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
        // 解析 MAC 版本（由设备模板决定 1.0.x / 1.1，缺省 1.0 以保证存量兼容）
        $dp = DeviceProfile::getOrDefault((int) $device['device_profile_id']);
        $macVersion = LoRaWANVersion::value($dp['mac_version'] ?? '1.0.3');
        $is11 = LoRaWANVersion::is1_1($macVersion);
        $appKey = hex2bin($device['app_key']);
        // 1.1 根密钥为 NwkKey（与 AppKey 分离），缺省回退 AppKey 以兼容 1.0.x
        $nwkKey = hex2bin($device['nwk_key'] ?: $device['app_key']);
        $t4 = microtime(true);
        $joinMicOk = $is11
            ? LoRaWANCrypto::verifyJoinRequestMIC1_1($nwkKey, $phy)
            : LoRaWANCrypto::verifyJoinRequestMIC($appKey, $phy);
        if (!$joinMicOk) {
            $this->log("JOIN: MIC failed for devEUI=$devEui (mac_version=$macVersion)");
            $this->logEvent('join', 'error', "入网请求：MIC 校验失败 devEUI=$devEui (mac_version=$macVersion)", $gwEui, $device['id'], $device['app_id']);
            return;
        }
        $t5 = microtime(true);

        // 去重键：Join-Request MIC（同一条物理包 → 同 MIC；网关镜像频率副本会被合并为一次下发）
        $micKey = bin2hex($is11
            ? LoRaWANCrypto::joinRequestMIC1_1($nwkKey, substr($phy, 0, -4))
            : LoRaWANCrypto::joinRequestMIC($appKey, substr($phy, 0, -4)));

        if (!isset($this->joinBuf[$micKey])) {
            // 首次出现：生成会话（只生成一次，避免重复副本生成不同的 devAddr / 会话密钥）
            $appNonce = random_bytes(3);
            $netId = random_bytes(3);
            $devAddr = $this->generateDevAddr();
            // 由设备模板派生 DLSettings / RX 延迟（对齐 ChirpStack join-accept 构造）：
            // DLSettings = (RX1DRoffset << 4) | RX2DR；rx_delay 缺省取区域 receive_delay1。
            $rx1DrOffset = (int) ($device['rx1_dr_offset'] ?? 0) & 0x07;
            $rx2Dr = (int) ($device['rx2_dr'] ?? 0) & 0x0F;
            $dlSettings = ($rx1DrOffset << 4) | $rx2Dr;
            $rxDelay = (int) ($device['rx_delay'] ?? 1) & 0x0F;
            $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
            $cfList = $region->getCfList(); // 信道计划（CFList）；部分区域（如 EU868 全动态）为 null
            $t6 = microtime(true);
            if ($is11) {
                // LoRaWAN 1.1：NwkKey 派生 FNwkSIntKey/SNwkSIntKey/NwkSEncKey，AppKey 派生 AppSKey；
                // Join-Accept 用 NwkKey 加密与校验 MIC。
                [$fNwkSIntKey, $sNwkSIntKey, $nwkSEncKey, $appSKey] = LoRaWANCrypto::computeSessionKeys1_1(
                    $nwkKey, $appKey, $appNonce, $jr['app_eui'], $jr['dev_nonce']
                );
                $joinAccept = Frame::buildJoinAccept1_1($nwkKey, $appNonce, $netId, $devAddr, $dlSettings, $rxDelay, $cfList);
            } else {
                [$nwkSKey, $appSKey] = LoRaWANCrypto::computeSessionKeys($appKey, $appNonce, $netId, $jr['dev_nonce']);
                $joinAccept = Frame::buildJoinAccept($appKey, $appNonce, $netId, $devAddr, $dlSettings, $rxDelay, $cfList);
            }

            // 保存会话（先建会话，下行在去重缓冲后统一下发，不影响 5s 窗口）
            $setCols = ['dev_addr=?', "status='active'", 'fcnt_up=0', 'fcnt_down=0', 'join_eui=?', 'last_gw_id=?', 'last_seen=?', 'mac_version=?'];
            $setParams = [bin2hex($devAddr), $appEui, $gwEui, time(), $macVersion];
            if ($is11) {
                $setCols[] = 'nwk_s_key=?';       $setParams[] = bin2hex($fNwkSIntKey);
                $setCols[] = 'f_nwk_s_int_key=?'; $setParams[] = bin2hex($fNwkSIntKey);
                $setCols[] = 's_nwk_s_int_key=?'; $setParams[] = bin2hex($sNwkSIntKey);
                $setCols[] = 'nwk_s_enc_key=?';   $setParams[] = bin2hex($nwkSEncKey);
                $setCols[] = 'app_s_key=?';       $setParams[] = bin2hex($appSKey);
            } else {
                $setCols[] = 'nwk_s_key=?'; $setParams[] = bin2hex($nwkSKey);
                $setCols[] = 'app_s_key=?'; $setParams[] = bin2hex($appSKey);
            }
            // 持久化 Join-Accept 派生的 RX 参数（供后续下行 RX1/RX2 窗口调度，对齐 ChirpStack）
            $setCols[] = 'rx_delay=?';        $setParams[] = $rxDelay;
            $setCols[] = 'rx1_dr_offset=?';   $setParams[] = $rx1DrOffset;
            $setCols[] = 'rx2_dr=?';          $setParams[] = $rx2Dr;
            $setCols[] = 'rx2_frequency=?';   $setParams[] = $region->getRx2Frequency();
            $setParams[] = $device['id'];
            Database::execute("UPDATE devices SET " . implode(',', $setCols) . " WHERE id=?", $setParams);
            $this->log("JOIN OK devEUI=$devEui -> devAddr=" . bin2hex($devAddr) . " (mac_version=$macVersion)" . sprintf(" (parse=%.0fms db_q=%.0fms mic=%.0fms key=%.0fms ja=%.0fms total=%.0fms)",
                ($t1-$t0)*1000, ($t3-$t2)*1000, ($t5-$t4)*1000, ($t6-$t5)*1000, 0, (microtime(true)-$t6)*1000));
            $this->logEvent('join', 'info', "入网成功 devEUI=$devEui -> devAddr=" . bin2hex($devAddr) . " (mac_version=$macVersion)", $gwEui, $device['id'], $device['app_id']);
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

    /**
     * 由设备行构造加解密密钥集合（兼顾 1.0.x 与 1.1）。
     * 1.0.x：nwkSKey=NwkSKey、appSKey；MIC 用 nwkSKey，FRM FPort0 用 nwkSKey。
     * 1.1  ：fNwkSIntKey/sNwkSIntKey/nwkSEncKey/appSKey；上行 MIC 用 f+s 双密钥，
     *        下行 MIC 用 sNwkSIntKey，FRM FPort0 用 nwkSEncKey。
     */
    private function deviceKeySet(array $device): array
    {
        $macVersion = LoRaWANVersion::value($device['mac_version'] ?? '1.0.3');
        $family = LoRaWANVersion::family($macVersion);
        $ks = [
            'family'      => $family,
            'mac_version' => $macVersion,
            'nwkSKey'     => hex2bin($device['nwk_s_key'] ?? ''),
            'appSKey'     => hex2bin($device['app_s_key'] ?? ''),
            'fNwkSIntKey' => hex2bin($device['f_nwk_s_int_key'] ?? ''),
            'sNwkSIntKey' => hex2bin($device['s_nwk_s_int_key'] ?? ''),
            'nwkSEncKey'  => hex2bin($device['nwk_s_enc_key'] ?? ''),
        ];
        return $ks;
    }

    /** 按版本选择下行帧构造方法（1.1 用 SNwkSIntKey/NwkSEncKey/双密钥 MIC）。 */
    private function buildDownPhy(array $ks, string $devAddrBin, int $fcnt, bool $confirmed, bool $ack, $fport, string $payload, int $adr, string $fopts, int $confFCnt = 0): string
    {
        if ($ks['family'] === '1.1') {
            return Frame::buildDataDown1_1(
                $ks['sNwkSIntKey'], $ks['nwkSEncKey'], $ks['appSKey'],
                $devAddrBin, $fcnt, $confirmed, $ack, $fport, $payload, $adr, $fopts, $confFCnt
            );
        }
        return Frame::buildDataDown(
            $ks['nwkSKey'], $ks['appSKey'], 1, $devAddrBin, $fcnt, $confirmed, $ack, $fport, $payload, $adr, $fopts
        );
    }

    // ---------------- 数据上行 ----------------

    private function handleDataUp(string $phy, int $mtype, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, string $peer): void
    {
        $p = Frame::parseDataUp($phy);
        $devAddrHex = bin2hex($p['dev_addr']);

        // ---- 上行去重（对齐 ChirpStack deduplication）----
        // 同一物理包可能被多个网关同时上送（多网关覆盖）。仅处理首个副本，
        // 避免对同一条上行重复触发 Webhook / ACK / 应用下行。缓冲保留 1s 后自动过期。
        $nowU = microtime(true);
        foreach ($this->uplinkBuf as $k => $t) {
            if ($nowU - $t > 1.0) {
                unset($this->uplinkBuf[$k]);
            }
        }
        $dupKey = $devAddrHex . ':' . (int) ($p['fcnt_lo'] ?? 0);
        if (isset($this->uplinkBuf[$dupKey])) {
            $this->log("DATA UP DEDUP skip devAddr=$devAddrHex (多网关重复上送)");
            return;
        }
        $this->uplinkBuf[$dupKey] = $nowU;

        $device = Database::fetch("SELECT * FROM devices WHERE dev_addr=? AND status='active'", [$devAddrHex]);
        if (!$device) {
            $this->log("DATA UP: unknown devAddr=$devAddrHex");
            $this->logEvent('uplink', 'error', "上行：未知设备 devAddr=$devAddrHex", $gwEui);
            return;
        }
        Database::execute("UPDATE devices SET last_gw_id=? WHERE id=?", [$gwEui, $device['id']]);
        $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
        $ks = $this->deviceKeySet($device);

        $fcnt = Frame::fullFCnt($p['fcnt_lo'], (int) $device['fcnt_up']);
        // 版本感知的 MIC 校验：1.1 使用 FNwkSIntKey+SNwkSIntKey 双密钥（B0+B1）
        $micOk = ($ks['family'] === '1.1')
            ? Frame::verifyDataMIC1_1($ks['fNwkSIntKey'], $ks['sNwkSIntKey'], $p['dev_addr'], $fcnt, $p['data_without_mic'], $p['mic'],
                (int) ($region->datrToDr($datr) ?? 0), 0)
            : Frame::verifyDataMIC($ks['nwkSKey'], 0, $p['dev_addr'], $fcnt, $p['data_without_mic'], $p['mic']);
        if (!$micOk) {
            $this->log("DATA UP: MIC failed devAddr=$devAddrHex fcnt=$fcnt (mac_version={$ks['mac_version']})");
            return;
        }

        // 帧计数防重放
        if ($fcnt <= (int) $device['fcnt_up']) {
            $this->log("DATA UP: old/duplicate fcnt=$fcnt (last=" . $device['fcnt_up'] . ")");
            $this->logEvent('uplink', 'warn', "上行：重复/过期帧 devAddr=$devAddrHex fcnt=$fcnt", $gwEui, $device['id'], $device['app_id']);
            return;
        }

        // 解密应用负载（版本感知：1.1 的 FPort=0 用 NwkSEncKey，其余用 AppSKey）
        $decrypted = '';
        if ($p['fport'] !== null && $p['frmpayload'] !== '') {
            if ($ks['family'] === '1.1') {
                $decrypted = Frame::decryptFRMPayload1_1($ks['nwkSEncKey'], $ks['appSKey'], 0, $p['dev_addr'], $fcnt, $p['fport'], $p['frmpayload']);
            } else {
                $key = ($p['fport'] === 0) ? $ks['nwkSKey'] : $ks['appSKey'];
                $decrypted = Frame::decryptFRMPayload($key, 0, $p['dev_addr'], $fcnt, $p['frmpayload']);
            }
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
        $this->logEvent('uplink', 'info', "上行接收 devAddr=$devAddrHex fcnt=$fcnt port=" . ($p['fport'] ?? '-') . " rssi=$rssi snr=$lsnr", $gwEui, $device['id'], $device['app_id'], $rawJson);

        // 设备遥测（GPS 端口 4）+ MAC 命令处理 + ADR。
        // ★ 关键：MAC/ADR 处理异常（如 Class B/C 特定 MAC 命令解析、1.1 字段异常）绝不可阻断上行通知（Webhook / 集成）的下发。
        //   因此整段包在 try/catch 中：即便 MAC 引擎出错，上行数据仍须照常通知给应用。
        try {
            $telemetry = $this->captureTelemetry($device, $p, $decrypted); // 仅 GPS
            $mac = $this->processMacAndAdr($device, $region, $tmst, $freq, $datr, $lsnr, $fcnt, $p, $decrypted);
            $this->persistDeviceMacState($device);
        } catch (\Throwable $e) {
            $this->log("WARN uplink MAC/telemetry error devAddr=$devAddrHex class={$device['class']} mac_version={$ks['mac_version']}: " . $e->getMessage());
            $this->logEvent('uplink', 'warn', "上行 MAC/遥测处理异常（已跳过，通知仍下发）devAddr=$devAddrHex: " . $e->getMessage(), $gwEui, $device['id'], $device['app_id']);
            $telemetry = [];
            $mac = ['fopts' => '', 'port0' => ''];
        }

        // 合并 MAC 引擎解析出的电量/链路余量遥测（DevStatusAns → Port0）
        $statusEvent = false;
        if (isset($device['mac_telemetry']) && is_array($device['mac_telemetry'])) {
            foreach (['battery', 'margin'] as $k) {
                if (array_key_exists($k, $device['mac_telemetry'])) {
                    $telemetry[$k] = $device['mac_telemetry'][$k];
                }
            }
            $statusEvent = true; // DevStatusAns 本帧到达 → 触发 status 集成事件
        }

        // 上行数据（供 Webhook / 集成使用）
        $uplinkData = [
            'name'       => $device['name'],
            'dev_eui'    => $device['dev_eui'],
            'dev_addr'   => $devAddrHex,
            'fcnt'       => $fcnt,
            'port'       => $p['fport'] ?? 0,
            'confirmed'  => ($mtype === Frame::MTYPE_CONFIRMED_UP) ? 1 : 0,
            'frm_payload'=> base64_encode($decrypted),
            'payload_hex'=> bin2hex($decrypted),
            'rssi'       => $rssi,
            'snr'        => $lsnr,
            'gateway_id' => $gwEui,
            'frequency'  => $freq,
            'datr'       => $datr,
            'tmst'       => $tmst,
            'received_at'=> time(),
        ];
        // 1) 应用级 callback_url（遗留单次 Webhook，TTN v3 格式）
        $this->fireCallback($device['app_id'], $uplinkData + $telemetry);
        // 2) 应用级集成（HTTP / InfluxDB / MQTT，见 integrations 表）
        Integration::dispatch($device['app_id'], $device, $uplinkData, $telemetry, [$this, 'log']);

        // 3) 设备回执（ACK 位）→ 确认型下行被设备确认，闭环下行队列（对齐 ChirpStack 下行 ACK 处理）
        if (!empty($p['ack'])) {
            $this->acknowledgeDownlinks($device, $gwEui, $uplinkData, $telemetry);
        }
        // 4) 设备状态事件（DevStatusAns → 电量/链路余量变化），对齐 ChirpStack status 事件
        if ($statusEvent) {
            Integration::dispatch($device['app_id'], $device, $uplinkData, $telemetry, [$this, 'log'], 'status');
            $this->logEvent('status', 'info',
                "设备状态更新 dev#{$device['id']} battery=" . var_export($telemetry['battery'] ?? null, true)
                . " margin=" . ($telemetry['margin'] ?? ''),
                $gwEui, $device['id'], $device['app_id']);
        }

        // 确认帧（ConfirmedDataUp）—— MAC 命令随 ACK 帧一并下发（若未被应用下行占用）
        $macConsumed = false;
        if ($mtype === Frame::MTYPE_CONFIRMED_UP) {
            $downPhy = $this->buildDownFrame(
                $ks, $p['dev_addr'], (int) $device['fcnt_down'] + 1,
                false, true, null, '', (bool) $device['adr'], $mac['fopts'], $mac['port0'], $fcnt
            );
            $this->bumpDownFCnt($device['id']);
            // rxpk.tmst = end of reception, so dl_tmst = tmst + delay (no airtime)
            $this->enqueueDownlink($gwEui, $peer, $downPhy, $tmst + $region->getReceiveDelay1() * 1000, $freq, $datr, false);
            $macConsumed = true;
        }

        // 应用层待发下行（rxpk.tmst = end of reception, no airtime needed）
        $this->dispatchPendingAppDownlinks($device, $region, $tmst, $freq, $datr, $gwEui, $peer, $p['dev_addr'], $ks, $mac, $macConsumed);
    }

    private function dispatchPendingAppDownlinks(array $device, Region $region, int $tmst, float $freq, string $datr, string $gwEui, string $peer, string $devAddrBin, array $ks, array $mac = ['fopts' => '', 'port0' => ''], bool $macConsumed = false): void
    {
        $pendings = Database::fetchAll(
            "SELECT * FROM downlinks WHERE dev_id=? AND status='pending' ORDER BY id ASC LIMIT 1",
            [$device['id']]
        );
        $classC = (($device['class'] ?? 'A') === 'C');
        foreach ($pendings as $dl) {
            $payload = hex2bin($dl['payload_hex']);
            $confirmed = (int) $dl['confirmed'] === 1;
            $fcntDown = (int) $device['fcnt_down'] + 1;
            // MAC 命令仅随首个待发下行帧的 FOpts 携带（≤15 字节，可与应用负载共存）；
            // 溢出的 Port0 MAC 不能与应用负载同帧，故不在此携带（改由下方 MAC-only 帧下发）。
            $macCarried = (!$macConsumed) && (($mac['fopts'] ?? '') !== '');
            $fopts = $macCarried ? $mac['fopts'] : '';
            $downPhy = $this->buildDownFrame($ks, $devAddrBin, $fcntDown, $confirmed, false, (int) $dl['port'], $payload, (bool) $device['adr'], $fopts, '');
            $this->bumpDownFCnt($device['id']);
            if ($classC) {
                // Class C：设备持续开启 RXC 窗口，上行结束后立即下发（imme），无需等待 RX1/RX2
                // （对齐 ChirpStack Class C 的 RXC 窗口下发行为）
                $this->enqueueDownlink($gwEui, $peer, $downPhy, 0, $freq, $datr, true);
                $this->log("APP DOWNLINK -> dev_id={$device['id']} port={$dl['port']} (Class C RXC imme)");
                $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} port={$dl['port']} (Class C RXC 立即下发)", $gwEui, $device['id'], $device['app_id']);
            } else {
                // Class A：RX1 + RX2 双窗口
                $rx1Tmst = $tmst + $region->getReceiveDelay1() * 1000;
                $this->enqueueDownlink($gwEui, $peer, $downPhy, $rx1Tmst, $freq, $datr, false);
                // Class A 的 RX2 窗口（与 RX1 相同时频规划下使用 RX2 频点/速率）
                $rx2Tmst = $tmst + $region->getReceiveDelay2() * 1000;
                $this->enqueueDownlink($gwEui, $peer, $downPhy, $rx2Tmst, $region->getRx2Frequency() / 1e6, $region->drToDatr($region->getRx2DataRate()), false);
                $this->log("APP DOWNLINK -> dev_id={$device['id']} port={$dl['port']}");
                $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} port={$dl['port']} (Class A RX1/RX2)", $gwEui, $device['id'], $device['app_id']);
            }
            Database::execute("UPDATE downlinks SET status='sent', fcnt=?, sent_at=? WHERE id=?", [$fcntDown, time(), $dl['id']]);
            $macConsumed = $macConsumed || $macCarried;
        }

        // 若没有待发应用下行、且 MAC 命令尚未随其它帧下发（unconfirmed 场景），单独下发 MAC 帧
        if (!$macConsumed) {
            $this->sendMacOnlyDownlink($device, $region, $tmst, $freq, $datr, $gwEui, $peer, $devAddrBin, $ks, $mac);
        }
    }

    // ---------------- MAC / ADR 辅助 ----------------

    /**
     * 处理上行中的 MAC 命令（FOpts + Port0）与 ADR，返回需随下行下发的 MAC 命令字节。
     * 通过引用修改 $device（dr/tx_power/nb_trans/信道/电量/余量等），调用方负责落库。
     *
     * @param int $fcnt 当前上行完整帧计数（用于 ADR 历史）
     * @return array ['fopts'=>string, 'port0'=>string]  port0 为 fopts 超出 15 字节时的溢出承载
     */
    private function processMacAndAdr(array &$device, Region $region, int $tmst, float $freq, string $datr, float $lsnr, int $fcnt, array $p, string $decrypted): array
    {
        // 收集 MAC 命令字节：FOpts（不加密） + Port0 负载（已用 NwkSKey 解密）
        $macBytes = $p['fopts'] ?? '';
        if (($p['fport'] ?? null) === 0 && $decrypted !== '') {
            $macBytes .= $decrypted;
        }
        $fopts = '';
        if ($macBytes !== '') {
            $cmds = MacCommands::parse($macBytes);
            if (!empty($cmds)) {
                $uplink = [
                    'snr'    => $lsnr,
                    'dr'     => (int) $device['dr'],
                    'region' => $region,
                    'freq'   => $freq,
                ];
                $res = MacCommands::handleUplink($device, $region, $uplink, $cmds);
                $fopts = implode('', $res['responses']);
            }
        }

        // 记录 ADR 上行历史（f_cnt + max_snr + tx_power_index），上限 32 条
        $history = json_decode($device['uplink_adr_history'] ?? '[]', true) ?: [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = ['f_cnt' => $fcnt, 'max_snr' => $lsnr, 'tx_power_index' => (int) $device['tx_power_index']];
        if (count($history) > 32) {
            $history = array_slice($history, -32);
        }
        $device['uplink_adr_history'] = json_encode($history);

        // ADR 调度：ADR 开启且期望状态与当前不一致时，下发 LinkADRReq
        if (!empty($device['adr'])) {
            $maxTx = (int) ($device['max_supported_tx_power_index'] ?? 0);
            if ($maxTx <= 0) {
                $maxTx = 7; // 默认 8 档发射功率（索引 0..7）
            }
            $req = [
                'adr'                    => true,
                'dr'                     => (int) $device['dr'],
                'tx_power_index'         => (int) $device['tx_power_index'],
                'nb_trans'               => (int) $device['nb_trans'],
                'max_tx_power_index'     => $maxTx,
                'required_snr_for_dr'    => $region->requiredSnrForDr((int) $device['dr']),
                'installation_margin'    => 5.0,
                'min_dr'                 => 0,
                'max_dr'                 => $region->getMaxLoraDr(),
                'uplink_history'         => $history,
                'region'                 => $region,
            ];
            $resp = Adr::compute($req);
            if ($resp['dr'] != (int) $device['dr']
                || $resp['tx_power_index'] != (int) $device['tx_power_index']
                || $resp['nb_trans'] != (int) $device['nb_trans']) {
                $chMask = $this->channelMask($device, $region);
                $adrReq = MacCommands::buildLinkADRReq($resp['dr'], $resp['tx_power_index'], $chMask, 0, $resp['nb_trans']);
                $fopts .= $adrReq;
                MacCommands::setPending($device, MacCommands::CID_LINK_ADR_REQ, $adrReq);
                $this->log(sprintf(
                    "ADR: dev#%d schedule LinkADRReq dr=%d txPower=%d nbTrans=%d",
                    $device['id'], $resp['dr'], $resp['tx_power_index'], $resp['nb_trans']
                ));
            }
        }

        // FOpts 长度上限 15 字节。注意 LoRaWAN 规范：FPort=0 时 FOpts 必须为空，
        // 即 FOpts 与 FPort=0 不能共存于同一帧。因此：
        //  - ≤15 字节：全部放入 FOpts（可与应用负载共存）；
        //  - >15 字节：全部经 Port0 承载（NwkSKey 加密），下行帧不再附带 FOpts。
        $fullMac = $fopts;
        $port0 = '';
        $fopts = '';
        if (strlen($fullMac) > 15) {
            $port0 = $fullMac;
            $this->log("MAC: fopts overflow (" . strlen($fullMac) . "B) -> 全部改由 Port0 承载");
        } else {
            $fopts = $fullMac;
        }
        return ['fopts' => $fopts, 'port0' => $port0];
    }

    /** 持久化 MAC/ADR 引擎修改过的设备会话状态。 */
    private function persistDeviceMacState(array $device): void
    {
        $cols = [
            'dr', 'tx_power_index', 'nb_trans', 'rx2_frequency', 'rx2_dr', 'rx1_dr_offset',
            'enabled_uplink_channel_indices', 'pending_mac', 'mac_command_error_count',
            'uplink_adr_history', 'class', 'battery', 'margin',
        ];
        $upd = [];
        $params = [];
        foreach ($cols as $c) {
            if (!array_key_exists($c, $device)) {
                continue;
            }
            $v = $device[$c];
            $upd[] = "$c=?";
            $params[] = is_array($v) ? json_encode($v) : $v;
        }
        if (!empty($upd)) {
            $params[] = $device['id'];
            Database::execute("UPDATE devices SET " . implode(', ', $upd) . " WHERE id=?", $params);
        }
    }

    /** 由设备已启用上行信道构造 16 位 LinkADR 信道掩码（ChMaskCntl=0）。 */
    private function channelMask(array $device, Region $region): int
    {
        $ch = json_decode($device['enabled_uplink_channel_indices'] ?? '[]', true);
        if (!is_array($ch) || count($ch) === 0) {
            $ch = range(0, 15); // 默认启用 16 个信道
        }
        $mask = 0;
        foreach ($ch as $i) {
            $i = (int) $i;
            if ($i >= 0 && $i < 16) {
                $mask |= (1 << $i);
            }
        }
        return $mask & 0xFFFF;
    }

    /** 构造下行物理层帧，自动处理 MAC 命令在 FOpts / Port0 的承载（版本感知密钥）。 */
    private function buildDownFrame(array $ks, string $devAddrBin, int $fcnt, bool $confirmed, bool $ack, $fport, string $payload, bool $adr, string $macFopts, string $macPort0, int $confFCnt = 0): string
    {
        if ($macPort0 !== '') {
            // MAC 溢出：用 Port0 承载剩余 MAC 命令（1.1 用 NwkSEncKey 加密）
            return $this->buildDownPhy($ks, $devAddrBin, $fcnt, $confirmed, $ack, 0, $macPort0, $adr ? 1 : 0, $macFopts, $confFCnt);
        }
        return $this->buildDownPhy($ks, $devAddrBin, $fcnt, $confirmed, $ack, $fport, $payload, $adr ? 1 : 0, $macFopts, $confFCnt);
    }

    /** 无应用下行时单独下发 MAC 命令帧（Class A RX1/RX2；Class C 立即 RXC）。 */
    private function sendMacOnlyDownlink(array $device, Region $region, int $tmst, float $freq, string $datr, string $gwEui, string $peer, string $devAddrBin, array $ks, array $mac): void
    {
        if (empty($mac['fopts']) && empty($mac['port0'])) {
            return;
        }
        $fcntDown = (int) $device['fcnt_down'] + 1;
        $downPhy = $this->buildDownFrame($ks, $devAddrBin, $fcntDown, false, false, null, '', (bool) $device['adr'], $mac['fopts'] ?? '', $mac['port0'] ?? '');
        $this->bumpDownFCnt($device['id']);
        $classC = (($device['class'] ?? 'A') === 'C');
        if ($classC) {
            $this->enqueueDownlink($gwEui, $peer, $downPhy, 0, $freq, $datr, true);
            $this->log("MAC DOWNLINK -> dev_id={$device['id']} (Class C RXC imme)");
            $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} (MAC-only, Class C RXC 立即下发)", $gwEui, $device['id'], $device['app_id']);
        } else {
            $rx1Tmst = $tmst + $region->getReceiveDelay1() * 1000;
            $this->enqueueDownlink($gwEui, $peer, $downPhy, $rx1Tmst, $freq, $datr, false);
            $rx2Tmst = $tmst + $region->getReceiveDelay2() * 1000;
            $this->enqueueDownlink($gwEui, $peer, $downPhy, $rx2Tmst, $region->getRx2Frequency() / 1e6, $region->drToDatr($region->getRx2DataRate()), false);
            $this->log("MAC DOWNLINK -> dev_id={$device['id']} (Class A RX1/RX2)");
            $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} (MAC-only, Class A RX1/RX2)", $gwEui, $device['id'], $device['app_id']);
        }
    }

    private function bumpDownFCnt(int $devId): void
    {
        Database::execute("UPDATE devices SET fcnt_down = fcnt_down + 1 WHERE id=?", [$devId]);
    }

    /**
     * 处理设备在上行中回置的 ACK 位（确认型下行被设备确认）。
     * 对齐 ChirpStack：设备对 NS 下发的 ConfirmedDown 以带 ACK 位的上行回应，
     * NS 据此将对应的确认型下行从队列中闭环（status='acknowledged'），并发布 ack 集成事件。
     * 同时对应重传计数（nb_trans）核销，避免被定时器重复下发。
     */
    private function acknowledgeDownlinks(array $device, string $gwEui, array $uplinkData, array $telemetry): void
    {
        $dl = Database::fetch(
            "SELECT * FROM downlinks WHERE dev_id=? AND confirmed=1 AND status='sent' AND acknowledged_at=0 ORDER BY id DESC LIMIT 1",
            [$device['id']]
        );
        if (!$dl) {
            // 无待确认下行（如设备自发 ConfirmedUp 的 ACK 位），仅记录
            $this->log("DATA UP ACK bit set dev#{$device['id']} (无待确认下行)");
            return;
        }
        Database::execute(
            "UPDATE downlinks SET status='acknowledged', acknowledged_at=? WHERE id=?",
            [time(), $dl['id']]
        );
        $this->log("DOWNLINK ACKED dev#{$device['id']} downlink#{$dl['id']} fcnt={$dl['fcnt']}");
        $this->logEvent('ack', 'info', "下行被设备确认 dev#{$device['id']} downlink#{$dl['id']} fcnt={$dl['fcnt']}", $gwEui, $device['id'], $device['app_id']);
        // ack 集成事件（消费者可见是哪条下行被确认）
        $ackData = $uplinkData + ['downlink_id' => (int) $dl['id'], 'downlink_fcnt' => (int) $dl['fcnt']];
        Integration::dispatch($device['app_id'], $device, $ackData, $telemetry, [$this, 'log'], 'ack');
    }

    /**
     * 解析设备上行中的遥测数据并写回 devices 表，返回供 Webhook 使用的字段。
     * 约定（与前端/设备端对齐）：
     *  - 端口 0 的 DevStatusAns（电量/链路余量）已由 MAC 命令引擎统一解析（见 MacCommands::onDevStatusAns），
     *    此处不再重复处理。
     *  - 端口 4 且净负载恰好 10 字节：lat(int32 LE, ×1e-6°), lon(int32 LE, ×1e-6°), alt(int16 LE, m) —— 简易 GPS 编码。
     */
    private function captureTelemetry(array $device, array $p, string $decryptedBin): array
    {
        $upd = [];
        $params = [];
        $telemetry = ['battery' => null, 'margin' => null, 'latitude' => null, 'longitude' => null, 'altitude' => null];
        $fport = $p['fport'] ?? null;

        // 注意：端口 0 的 DevStatusAns（电量/链路余量）改由 MAC 命令引擎统一解析
        // （见 MacCommands::onDevStatusAns），避免与 FOpts 内的 MAC 命令重复处理。

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
    /**
     * 应用级 Webhook（设备回调）。
     * 负载采用 TTN (The Things Stack v3) 的 uplink_message 结构，
     * 可直接被 TTN 兼容的接收端（如用户提供的 webhook.php）解析：
     *   - end_device_ids.{device_id,dev_eui,dev_addr,application_ids.application_id}
     *   - uplink_message.{f_port,f_cnt,frm_payload(base64),decoded_payload,rx_metadata,rssi,snr,settings}
     */
    private function fireCallback(int $appId, array $data): void
    {
        $app = Database::fetch("SELECT id, name, callback_url FROM applications WHERE id=?", [$appId]);
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

        // ---- 组装 TTN v3 uplink_message 结构 ----
        $bandwidth = 0;
        $sf = 0;
        if (preg_match('/SF(\d+)\s*BW\s*(\d+)/i', $data['datr'] ?? '', $m)) {
            $sf = (int) $m[1];
            $bandwidth = (int) $m[2] * 1000;
        }
        $ts = (int) ($data['received_at'] ?? time());
        // 直接给 MySQL DATETIME 兼容格式（用户的接收端 received_at 列为 DATETIME，不认 ISO 8601 的 T/Z）
        $recvAt = gmdate('Y-m-d H:i:s', $ts);

        $telemetry = [];
        foreach (['battery', 'margin', 'latitude', 'longitude', 'altitude'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                $telemetry[$k] = $data[$k];
            }
        }

        $payload = [
            'end_device_ids' => [
                'device_id'       => $data['name'] ?: $data['dev_eui'],
                'dev_eui'         => $data['dev_eui'],
                'dev_addr'        => $data['dev_addr'],
                'application_ids' => ['application_id' => $app['name'] ?: ('app-' . $appId)],
            ],
            'received_at' => $recvAt,
            'uplink_message' => [
                'received_at'     => $recvAt,
                'f_port'          => (int) ($data['port'] ?? 0),
                'f_cnt'           => (int) ($data['fcnt'] ?? 0),
                'frm_payload'     => $data['frm_payload'] ?? '',
                'decoded_payload' => $telemetry ?: null,
                'confirmed'       => !empty($data['confirmed']),
                'rx_metadata'     => [
                    [
                        'gateway_ids'  => ['gateway_id' => $data['gateway_id'] ?? ''],
                        'rssi'         => (int) ($data['rssi'] ?? 0),
                        'channel_rssi' => (int) ($data['rssi'] ?? 0),
                        'snr'          => (float) ($data['snr'] ?? 0),
                        'received_at'  => $recvAt,
                    ],
                ],
                'settings' => [
                    'data_rate' => ['lora' => ['bandwidth' => $bandwidth, 'spreading_factor' => $sf]],
                    'frequency' => (string) ($data['frequency'] ?? ''),
                    'timestamp' => (int) ($data['tmst'] ?? 0),
                ],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        // 阻塞连接（带超时）：异步连接 + 立即 fclose 会在握手未完成时丢弃请求，导致对端收不到 POST
        $fp = @stream_socket_client(
            "$transport://$host:$port",
            $errno,
            $errstr,
            2.0
        );
        if (!$fp) {
            $this->log("CALLBACK: connect failed app#$appId ($errstr #$errno) url=$url");
            return;
        }
        $req = "POST $path HTTP/1.1\r\n"
            . "Host: $host\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        $len = strlen($req);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($fp, substr($req, $written));
            if ($n === false || $n === 0) {
                break;
            }
            $written += $n;
        }
        if ($written < $len) {
            $this->log("CALLBACK: write incomplete app#$appId (wrote $written/$len) url=$url");
        } else {
            // 读一点响应用于确认对端接受了（最多等 2s，避免阻塞太久）
            stream_set_timeout($fp, 2);
            $resp = @fread($fp, 512);
            $status = $resp ? trim(strtok($resp, "\r\n")) : 'no-response';
            $this->log("CALLBACK: POST app#$appId -> $status url=$url");
        }
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
                    dev.ping_period, dev.beacon_epoch, dev.region, dev.fcnt_down AS dev_fcnt_down,
                    dev.f_nwk_s_int_key, dev.s_nwk_s_int_key, dev.nwk_s_enc_key, dev.mac_version
             FROM downlinks d
             JOIN devices dev ON dev.id = d.dev_id
             WHERE d.status='pending' AND dev.class IN ('B','C')"
        );
        $now = time();
        foreach ($rows as $dl) {
            $device = [
                'id'               => $dl['dev_id'],
                'app_id'           => (int) $dl['app_id'],
                'nwk_s_key'        => $dl['nwk_s_key'],
                'app_s_key'        => $dl['app_s_key'],
                'f_nwk_s_int_key'  => $dl['f_nwk_s_int_key'] ?? '',
                's_nwk_s_int_key'  => $dl['s_nwk_s_int_key'] ?? '',
                'nwk_s_enc_key'    => $dl['nwk_s_enc_key'] ?? '',
                'mac_version'      => $dl['mac_version'] ?? '1.0.3',
                'dev_addr'         => $dl['dev_addr'],
                'class'            => $dl['class'],
                'last_gw_id'       => $dl['last_gw_id'],
                'ping_period'      => (int) $dl['ping_period'],
                'beacon_epoch'     => (int) $dl['beacon_epoch'],
                'region'           => $dl['region'],
                'fcnt_down'        => (int) $dl['dev_fcnt_down'],
            ];
            $sendAt = ($device['class'] === 'C') ? $now : $this->nextPingSlot($device, $now);
            if ($now < $sendAt) {
                continue; // 尚未到 ping 时隙，下一轮再试
            }
            $this->sendDeviceDownlink($device, $dl, true);
        }
    }

    /** 确认型下行重传（对齐 ChirpStack nb_trans）。
     * 已下发（status='sent'）但未在窗口内被设备 ACK 的确认型下行，按设备 nb_trans 重传：
     *  - Class C：立即通过 RXC 窗口重传（imme）；
     *  - Class A/B：退回 pending，等待下一次上行机会重传（下行仅在设备上行后的 RX 窗口可达）。
     * transmissions 累计已传输次数，达到 nb_trans 后停止重传。 */
    private function rescheduleUnackedDownlinks(): void
    {
        $now = time();
        $retx = Database::fetchAll(
            "SELECT d.*, dev.class, dev.nb_trans, dev.last_gw_id, dev.region, dev.dev_addr,
                    dev.nwk_s_key, dev.app_s_key, dev.f_nwk_s_int_key, dev.s_nwk_s_int_key, dev.nwk_s_enc_key, dev.mac_version, dev.fcnt_down AS dev_fcnt_down
             FROM downlinks d
             JOIN devices dev ON dev.id = d.dev_id
             WHERE d.status='sent' AND d.confirmed=1 AND d.acknowledged_at=0
               AND d.sent_at > 0 AND (? - d.sent_at) > 8
               AND d.transmissions < dev.nb_trans
             ORDER BY d.id ASC",
            [$now]
        );
        foreach ($retx as $dl) {
            $nbTrans = (int) $dl['nb_trans'];
            $tx = (int) $dl['transmissions'] + 1;
            Database::execute("UPDATE downlinks SET transmissions=? WHERE id=?", [$tx, $dl['id']]);
            if (($dl['class'] ?? 'A') === 'C') {
                $device = [
                    'id'               => $dl['dev_id'],
                    'app_id'           => (int) $dl['app_id'],
                    'nwk_s_key'        => $dl['nwk_s_key'],
                    'app_s_key'        => $dl['app_s_key'],
                    'f_nwk_s_int_key'  => $dl['f_nwk_s_int_key'] ?? '',
                    's_nwk_s_int_key'  => $dl['s_nwk_s_int_key'] ?? '',
                    'nwk_s_enc_key'    => $dl['nwk_s_enc_key'] ?? '',
                    'mac_version'      => $dl['mac_version'] ?? '1.0.3',
                    'dev_addr'         => $dl['dev_addr'],
                    'class'            => $dl['class'],
                    'last_gw_id'       => $dl['last_gw_id'],
                    'region'           => $dl['region'],
                    'fcnt_down'        => (int) $dl['dev_fcnt_down'],
                ];
                $this->sendDeviceDownlink($device, $dl, true);
                $this->log("RETX downlink#{$dl['id']} dev#{$dl['dev_id']} (Class C, tx=$tx/$nbTrans)");
                $this->logEvent('downlink', 'warn', "下行重传 dev#{$dl['dev_id']} downlink#{$dl['id']} (Class C RXC, tx=$tx/$nbTrans)", $dl['last_gw_id'] ?? '', $dl['dev_id'], $dl['app_id']);
            } else {
                // Class A/B：退回 pending，等下一次上行由 dispatchPendingAppDownlinks 重传
                Database::execute("UPDATE downlinks SET status='pending' WHERE id=?", [$dl['id']]);
                $this->log("RETX downlink#{$dl['id']} dev#{$dl['dev_id']} -> pending (Class A/B, tx=$tx/$nbTrans)");
                $this->logEvent('downlink', 'warn', "下行重传排队 dev#{$dl['dev_id']} downlink#{$dl['id']} (Class A/B, tx=$tx/$nbTrans)", '', $dl['dev_id'], $dl['app_id']);
            }
        }
    }
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
        // 服务网关解析：优先使用设备最近保活网关；若为空（NS 重启后该 Class B/C 设备尚未上行），
        // 回退到「同应用/同区域」的任一已知网关，避免下行被静默丢弃。
        $gwEui = $this->resolveServingGateway($device);
        if ($gwEui === '') {
            $this->log("SCHED DOWNLINK SKIP -> dev_id={$device['id']} class={$device['class']} port={$dl['port']} (无可用服务网关，等待网关保活)");
            return;
        }
        $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
        $ks = $this->deviceKeySet($device);
        $devAddrBin = hex2bin($device['dev_addr']);
        $payload = hex2bin($dl['payload_hex']);
        $confirmed = (int) $dl['confirmed'] === 1;
        $fcntDown = (int) $device['fcnt_down'] + 1;
        $downPhy = $this->buildDownPhy($ks, $devAddrBin, $fcntDown, $confirmed, false, (int) $dl['port'], $payload, 0, '', 0);
        $this->bumpDownFCnt($device['id']);
        $peer = $this->gateways[$gwEui]['addr'] ?? '';
        $this->enqueueDownlink($gwEui, $peer, $downPhy, 0, $region->getRx2Frequency() / 1e6, $region->drToDatr($region->getRx2DataRate()), $imme);
        Database::execute("UPDATE downlinks SET status='sent', fcnt=?, sent_at=? WHERE id=?", [$fcntDown, time(), $dl['id']]);
        $this->log("SCHED DOWNLINK -> dev_id={$device['id']} class={$device['class']} port={$dl['port']} gw=$gwEui");
        $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} class={$device['class']} port={$dl['port']} gw=$gwEui", $gwEui, $device['id'], $device['app_id']);
    }

    /**
     * 解析设备的服务网关（用于 Class B/C 主动下行）。
     * 优先 last_gw_id；为空时回退到「同应用、同区域」最近活跃网关；仍无则回退到任意在线网关。
     */
    private function resolveServingGateway(array $device): string
    {
        $last = $device['last_gw_id'] ?? '';
        if ($last !== '' && isset($this->gateways[$last])) {
            return $last;
        }
        $appId = (int) ($device['app_id'] ?? 0);
        $region = $device['region'] ?? '';
        // 同应用 + 同区域
        $candidates = Database::fetchAll(
            "SELECT gw_id FROM gateways WHERE last_seen >= ? AND (region=? OR region='' OR region IS NULL) ORDER BY last_seen DESC LIMIT 5",
            [time() - 600, $region]
        );
        foreach ($candidates as $c) {
            $gw = $c['gw_id'];
            if (isset($this->gateways[$gw]) && ($appId === 0 || true)) {
                return $gw;
            }
        }
        // 仍无：任意在线网关
        $any = Database::fetch("SELECT gw_id FROM gateways WHERE last_seen >= ? ORDER BY last_seen DESC LIMIT 1", [time() - 600]);
        return $any ? $any['gw_id'] : '';
    }

    // ---------------- 组播下发调度 ----------------

    /**
     * 周期调用：处理 multicast_queue 中的组播下行。
     * 以组播会话密钥构造下行帧，按组的频点/DR 发往组内网关（无组网关则发往全部在线网关）。
     * Class C 以 imme 立即下发；Class B 在近似 ping 时隙下发（此处以 imme 近似）。
     */
    private function processScheduledMulticast(): void
    {
        $rows = Database::fetchAll(
            "SELECT q.*, g.group_type, g.mc_addr, g.mc_nwk_s_key, g.mc_app_s_key, g.f_cnt, g.dr, g.frequency, g.region
             FROM multicast_queue q
             JOIN multicast_groups g ON g.id = q.multicast_group_id
             WHERE q.expires_at = 0 OR q.expires_at > ?",
            [time()]
        );
        foreach ($rows as $q) {
            $group = $q; // 已包含 g.* 列
            $phy = Multicast::buildDownlink($group, (int) $q['f_port'], $q['payload_hex']);
            $region = Region::get($group['region'] ?: ELW_DEFAULT_REGION);
            $freq = ((int) $group['frequency'] > 0) ? ((int) $group['frequency'] / 1e6) : ($region->getRx2Frequency() / 1e6);
            $datr = $region->drToDatr((int) $group['dr']);
            $imme = (strtoupper($group['group_type']) === 'C');
            $tmst = $imme ? 0 : (int) (microtime(true) * 1000) % 1000000000;

            $gws = Multicast::groupGateways((int) $q['multicast_group_id']);
            if (empty($gws)) {
                // 回退：发往全部已知网关
                $gws = Database::fetchAll("SELECT gw_id FROM gateways");
            }
            foreach ($gws as $gw) {
                $gwEui = $gw['gw_id'];
                $peer = $this->gateways[$gwEui]['addr'] ?? '';
                if ($peer === '' && !isset($this->gateways[$gwEui])) {
                    // 网关尚未保活，跳过（组播要求网关在线）
                    continue;
                }
                $this->enqueueDownlink($gwEui, $peer, $phy, $tmst, $freq, $datr, $imme);
            }
            // 递增组播帧计数并出队
            Database::execute("UPDATE multicast_groups SET f_cnt = f_cnt + 1 WHERE id=?", [$q['multicast_group_id']]);
            Database::execute("DELETE FROM multicast_queue WHERE id=?", [$q['id']]);
            $this->log("MULTICAST DOWNLINK -> group={$q['multicast_group_id']} port={$q['f_port']} gw=" . count($gws));
            $this->logEvent('downlink', 'info', "组播下行下发 group={$q['multicast_group_id']} port={$q['f_port']}", '', 0, 0);
        }
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
    private function logEvent(string $type, string $level, string $message, string $gwId = '', ?int $devId = 0, ?int $appId = 0, string $rawJson = ''): void
    {
        try {
            Database::execute(
                "INSERT INTO events (type, level, gateway_id, dev_id, app_id, message, raw_json, created_at) VALUES (?,?,?,?,?,?,?,?)",
                [$type, $level, $gwId, $devId, $appId, $message, $rawJson, time()]
            );
        } catch (\Throwable $e) {
            // 事件落库失败不应中断接收链路
        }
        $this->log("[$type/$level] $message");
    }
}
