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
use holastack\Core\Roaming;

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
    private $uplinkRxSets = []; // "devAddr:fcntLo" => [['snr'=>float,'gw'=>gwEui,'t'=>time()], ...]
                               // 多网关接收质量聚合（对齐 ChirpStack UplinkFrameSet.rx_info_set）：
                               // LinkCheckAns 的 margin 取所有网关最大 SNR、gw_cnt 取去重网关数。
    private $lastBeaconGps = 0; // 已调度的上一信标 GPS 秒（128s 网格，避免重复下发）
    private $beaconMacVersion = '1.0.3'; // 信标帧 MAC 版本（与设备默认 mac_version 对齐）

    // FUOTA 每个调度 tick 最多下发的组播分片帧数（节流，避免瞬间打爆网关）
    private const FUOTA_FRAMES_PER_TICK = 2;
    // SETUP 阶段 McGroupSetupReq 重发间隔（秒）
    private const FUOTA_SETUP_RESEND_INTERVAL = 30;
    // SETUP 阶段最长等待（秒），超时后未应答设备直接进入分片（其部署在收尾时置 FAILED）
    private const FUOTA_SETUP_MAX_SECONDS = 120;

    // Class B 信标调度：提前 BEACON_SCHEDULE_LEAD 秒经 PULL_RESP 下发，确保网关在 tmst 前收到；
    // 网关 concentrator 时间参考超过 BEACON_REF_MAX_AGE 秒视为过期，过期则信标以 imme 下发（失准告警）。
    private const BEACON_SCHEDULE_LEAD = 6;
    private const BEACON_REF_MAX_AGE = 60;

    public function __construct(int $port = ELW_GW_UDP_PORT)
    {
        $this->port = $port;
    }

    public function run(): void
    {
        Database::migrate();
        $this->log("Database ready.");
        // 加载漫游伙伴注册表（按 NetID 路由），必须在主循环前完成
        $nRoam = Roaming::setup();
        if ($nRoam > 0 || Roaming::isEnabled()) {
            $this->log("Roaming enabled: $nRoam partner server(s) registered (NetID=" . Roaming::localNsId() . ")");
        }
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
            // ★ 全局异常隔离：任何调度步骤/单个数据包的处理异常都只记日志，绝不中断主循环。
            //   否则一次 SQL/缺列/缺类错误就会让进程崩溃（Supervisor 重启间隙恰好吞掉
            //   Join-Accept 的 PULL_RESP → 设备 RX 窗口空等 → +EVT:JOIN FAILED，表现为“时好时坏”）。
            try {
                $this->runScheduled();
            } catch (\Throwable $e) {
                $this->log("SCHED FATAL（已隔离，主循环继续）: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
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
                    try {
                        $this->handlePacket($data, $peer, microtime(true));
                    } catch (\Throwable $e) {
                        $this->log("PACKET ERROR（已隔离，不影响后续包）peer=$peer: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
                    }
                }
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    // ---------------- Basic Station / LNS 接入 ----------------

    /**
     * 注册一个 Basic Station 网关（由 LNS 进程调用）。
     * 注册后该网关的上行（ingestStationUp）与下行（flushDownlink）全部走 station 协议：
     * 下行不再经 UDP PULL_RESP，而是把 dnmsg 数组交给 $dnSink（LNS 负责 WebSocket 回送）。
     *
     * @param callable $dnSink function(array $dnmsg): void
     */
    public function registerStationGateway(string $gwEui, callable $dnSink, string $peer = '', string $region = ''): void
    {
        $this->gateways[$gwEui] = [
            'addr'       => $peer !== '' ? $peer : 'station://' . $gwEui,
            'proto'      => 'station',
            'dnSink'     => $dnSink,
            'region'     => $region !== '' ? $region : ELW_DEFAULT_REGION,
            'pending'    => [],
            'version'    => "\x01",
            'pull_token' => "\x00\x00",
            'last_xtime' => 0,
        ];
        $this->log("STATION gateway registered: $gwEui (region=" . ($region ?: ELW_DEFAULT_REGION) . ")");
    }

    /**
     * Basic Station 上行接入点：把站上报的 PHYPayload + upinfo 转成内部 rxpk 结构走标准上行链路。
     * upinfo 字段对齐 Basic Station：rssi/snr/freq(MHz)/dr(索引)/xtime(µs)/rctx。
     */
    public function ingestStationUp(string $phy, array $upinfo, string $gwEui, string $peer = ''): void
    {
        if ($phy === '') {
            return;
        }
        $regionName = $this->gateways[$gwEui]['region'] ?? ELW_DEFAULT_REGION;
        $region = Region::get($regionName);
        $datr = $region->drToDatr((int) ($upinfo['dr'] ?? 0));
        $rxpk = [
            'tmst' => (int) ($upinfo['xtime'] ?? 0),
            'freq' => (float) ($upinfo['freq'] ?? 0),
            'datr' => $datr,
            'codr' => '4/5',
            'rssi' => (int) ($upinfo['rssi'] ?? 0),
            'lsnr' => (float) ($upinfo['snr'] ?? 0),
            'data' => base64_encode($phy),
        ];
        if (isset($this->gateways[$gwEui])) {
            $this->gateways[$gwEui]['last_xtime'] = (int) ($upinfo['xtime'] ?? 0);
        }
        $this->log(sprintf("STATION RX gw=%s freq=%.3f datr=%s rssi=%d snr=%.1f (dr=%d, %dB phy)",
            $gwEui, $rxpk['freq'], $datr, $rxpk['rssi'], $rxpk['lsnr'], (int) ($upinfo['dr'] ?? 0), strlen($phy)));
        $this->processUplink($rxpk, $peer !== '' ? $peer : 'station://' . $gwEui, $gwEui);
    }

    /**
     * 周期调度 tick（Class B/C 下行、组播、FUOTA、未确认重传、Join 缓冲）。
     * LNS 进程（无 UDP 主循环）每 ~1s 调用一次；run() 主循环内部亦复用本方法。
     *
     * ★ Join-Accept 缓冲刷新必须最先执行且不可被其他调度模块的异常阻断：
     *   入网窗口（RX1/RX2，5/6s）是一次性的，任何延迟/中断都会直接导致设备 JOIN FAILED
     *   （设备 RX 窗口打开却等不到下行 → +EVT:JOIN FAILED）。
     *   各调度步骤独立 try/catch 隔离，避免新增模块（如信标调度）抛异常拖垮整条链路。
     */
    public function runScheduled(): void
    {
        if (time() - $this->lastDlCheck < 1) {
            return;
        }
        $this->lastDlCheck = time();
        $this->runSafe('join buffer', function (): void { $this->flushJoinBuffer(); });
        $this->runSafe('scheduled downlinks', function (): void { $this->processScheduledDownlinks(); });
        $this->runSafe('scheduled multicast', function (): void { $this->processScheduledMulticast(); });
        $this->runSafe('beacon scheduler', function (): void { $this->processBeaconScheduler(); });
        $this->runSafe('scheduled fuota', function (): void { $this->processScheduledFuota(); });
        $this->runSafe('unacked retx', function (): void { $this->rescheduleUnackedDownlinks(); });
    }

    /** 执行一个调度步骤并隔离其异常（任何一步失败不中断其余调度）。 */
    private function runSafe(string $name, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->log("SCHED WARN $name 异常（已隔离，不影响其他调度）: " . $e->getMessage());
        }
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
                $prevAddr = $this->gateways[$gwEui]['addr'] ?? '';
                $this->registerGateway($peer, $gwEui);
                // 诊断：PULL_DATA 源地址（下行通道）与 PUSH_DATA 源不同 → 下行必须发往这里
                if ($prevAddr !== $peer) {
                    $this->log("PULL_DATA gw=$gwEui peer=$peer（下行通道" . ($prevAddr === '' ? '首次登记' : '地址更新') . "）");
                }
                // 记下本次 PULL_DATA 的 token，作为 PULL_RESP 的关联 ID（网关只原样回显到 TX_ACK，并不据此校验）
                $this->gateways[$gwEui]['pull_token'] = $token;
                $this->flushDownlink($gwEui, $peer);
                break;
            case 0x05: // TX_ACK
                $ackJson = json_decode(substr($data, 12), true);
                $ackStatus = $ackJson['txpk_ack']['error'] ?? 'ok';
                $this->log("TX_ACK gw=$gwEui status=$ackStatus");
                // 下行发射结果分级（对齐 ChirpStack TxAckStatus 语义，见 chirpstack downlink/tx_ack.rs）：
                //  - Class A 下行 RX1+RX2 双窗口同时入队，后发窗口可能与先发窗口发射时间重叠
                //    （如 SF12 长 airtime），网关对重叠包回 COLLISION_PACKET / IGNORED——
                //    这是双窗口冗余的正常行为：任一窗口发射成功设备即可收到，
                //    ChirpStack 对 DownlinkTxAck.items 遍历取「任一 OK 即整体成功」，不记错误。
                //  - 仅真正的发射失败（太晚/太早/频率/功率/GPS 未锁定/队列满等）才记 txack 错误事件。
                $benign = ['', 'OK', 'NONE', 'IGNORED', 'COLLISION_PACKET', 'COLLISION_BEACON'];
                if (!in_array(strtoupper($ackStatus), $benign, true)) {
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

        // 记录网关 concentrator 时间参考（tmst + NS 主机时刻），供信标 tmst 推算对齐 GPS 时间。
        // ★ 注意：addr（下行回程地址）必须保留 PULL_DATA 源（registerGateway 登记）。
        //   这里仅做「尚无地址」时的 PUSH 源兜底，绝不覆盖已有地址——否则 PULL_RESP 会发到
        //   PUSH_DATA 源端口；若与 PULL_DATA 源端口不同，网关静默丢弃（无 TX_ACK → 设备 JOIN FAILED）。
        if ($tmst > 0 && $gwEui !== '') {
            $this->gateways[$gwEui] = $this->gateways[$gwEui] ?? [];
            if (!isset($this->gateways[$gwEui]['addr'])) {
                $this->gateways[$gwEui]['addr'] = $peer; // 兜底：尚未收到 PULL_DATA 时暂用 PUSH 源
            }
            $this->gateways[$gwEui]['c_ref'] = [
                'tmst'    => $tmst,
                'host_us' => (int) round(microtime(true) * 1000000),
                'host'    => time(),
            ];
        }

        if ($mtype === Frame::MTYPE_JOIN_REQUEST) {
            $this->log(sprintf("RX JoinRequest: gw=%s tmst=%d freq=%.3f datr=%s rssi=%d snr=%.1f (mtype=%d)", $gwEui, $tmst, $freq, $datr, $rssi, $lsnr, $mtype));
            $this->handleJoinRequest($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer, $rxTime);
        } elseif ($mtype === Frame::MTYPE_UNCONFIRMED_UP || $mtype === Frame::MTYPE_CONFIRMED_UP) {
            $this->handleDataUp($phy, $mtype, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer, $rxTime);
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
            // 漫游：非本网设备 Join，作为服务 NS 转发给 Home NS（Passive Roaming，按 JoinEUI 路由）
            if (Roaming::isEnabled() && $this->tryRoamingJoin($phy, $jr, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer)) {
                return; // 已成功转发，等待 Home NS 经 bin/roaming-inbound.php 回送 JoinAns 下行
            }
            $this->log("JOIN: unknown device devEUI=$devEui appEUI=$appEUI");
            $this->logEvent('join', 'error', "Join Request：未知设备 devEUI=$devEui appEUI=$appEui", $gwEui, 0, 0, $this->buildJoinRequestLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
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
            $this->logEvent('join', 'error', "Join Request：MIC 校验失败 devEUI=$devEui (mac_version=$macVersion)", $gwEui, $device['id'], $device['app_id'], $this->buildJoinRequestLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
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
            $this->logEvent('join', 'info', "Join Request → Join Accept 已生成 devEUI=$devEui -> devAddr=" . bin2hex($devAddr) . " (mac_version=$macVersion)", $gwEui, $device['id'], $device['app_id'], $this->buildJoinRequestLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
        } else {
            // 重复副本（如 SX130x 镜像频率）：沿用首份会话，仅更新“最强信号”副本用于下行频点选择
            $region = $this->joinBuf[$micKey]['region'];
            $joinAccept = $this->joinBuf[$micKey]['joinAccept'];
            $t6 = $t5;
        }

        // 缓冲下行：按 MIC 去重，约 80ms 后统一下发（取 RSSI 最强副本的频点/时隙），
        // 避免同一物理包被网关重复上送（真实信号 + 镜像频率）导致 NS 调度两次下行 → 网关 COLLISION_PACKET。
        $this->bufferJoinDownlink($micKey, $region, $joinAccept, $tmst, $freq, $datr, $rssi, $gwEui, $peer, $device['id'] ?? 0, $device['app_id'] ?? 0);
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

    private function handleDataUp(string $phy, int $mtype, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, string $peer, float $rxTime = 0): void
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
                unset($this->uplinkRxSets[$k]);
            }
        }
        $dupKey = $devAddrHex . ':' . (int) ($p['fcnt_lo'] ?? 0);
        // 多网关接收质量聚合（对齐 ChirpStack UplinkFrameSet.rx_info_set）：
        // 同一上行可能被多个网关重复上送，即使后续判定为重复帧也要记录，
        // 供首个副本应答 LinkCheckAns 时取「所有网关最大 SNR」与「去重网关数」。
        $this->uplinkRxSets[$dupKey][] = ['snr' => $lsnr, 'gw' => $gwEui, 't' => time()];
        if (count($this->uplinkRxSets[$dupKey]) > 32) {
            $this->uplinkRxSets[$dupKey] = array_slice($this->uplinkRxSets[$dupKey], -32);
        }
        if (isset($this->uplinkBuf[$dupKey])) {
            $this->log("DATA UP DEDUP skip devAddr=$devAddrHex (多网关重复上送)");
            return;
        }
        $this->uplinkBuf[$dupKey] = $nowU;

        $device = Database::fetch("SELECT * FROM devices WHERE dev_addr=? AND status='active'", [$devAddrHex]);
        if (!$device) {
            // 漫游：非本网 DevAddr 上行，作为服务 NS 转发给 Home NS（Passive Roaming，按 DevAddr 路由）
            if ($this->tryRoamingDataUp($phy, $p, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer)) {
                return; // 已发送 XmitDataReq，等待 Home NS 经 bin/roaming-inbound.php 回送下行
            }
            $this->log("DATA UP: unknown devAddr=$devAddrHex");
            $this->logEvent('uplink', 'error', "上行：未知设备 devAddr=$devAddrHex", $gwEui, 0, 0, $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
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
            $this->logEvent('uplink', 'warn', "上行：重复/过期帧 devAddr=$devAddrHex fcnt=$fcnt", $gwEui, $device['id'], $device['app_id'], $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
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

        // FUOTA 应用层载荷（FPort 200 FuotaSetupAns / 201 FuotaStatusAns，AppSKey 加密）
        $this->handleFuotaAppPayload($device, $p['fport'] ?? null, $decrypted, $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));

        Database::execute(
            "UPDATE devices SET fcnt_up=?, last_seen=? WHERE id=?",
            [$fcnt, time(), $device['id']]
        );
        // 内存同步 last_seen：后续下行调度（Class C imme 时序保护）需要"本上行"的时间基准
        $device['last_seen'] = time();

        // 原始报文 + 网关元数据（用于前端“原始 JSON”查看与第三方对接）
        // 主结构 = 网关协议原文（phy_payload/tx_info/rx_info，对齐 ChirpStack 网关 JSON）；
        // 解密负载附加在 payload 内，不破坏原始报文结构。
        $rawJson = $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime);
        $rawLog = json_decode($rawJson, true);
        $rawLog['phy_payload']['payload']['decrypted_hex'] = bin2hex($decrypted);
        $rawJson = json_encode($rawLog, JSON_UNESCAPED_SLASHES);

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
            $this->logEvent('uplink', 'warn', "上行 MAC/遥测处理异常（已跳过，通知仍下发）devAddr=$devAddrHex: " . $e->getMessage(), $gwEui, $device['id'], $device['app_id'], $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
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
        // ★ 传闭包而非 [$this,'log']：私有方法数组在类外 is_callable=false，PHP 8 会 TypeError
        Integration::dispatch($device['app_id'], $device, $uplinkData, $telemetry, function (string $m): void {
            $this->log($m);
        });

        // 3) 设备回执（ACK 位）→ 确认型下行被设备确认，闭环下行队列（对齐 ChirpStack 下行 ACK 处理）
        if (!empty($p['ack'])) {
            $this->acknowledgeDownlinks($device, $gwEui, $uplinkData, $telemetry, $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
        }
        // 4) 设备状态事件（DevStatusAns → 电量/链路余量变化），对齐 ChirpStack status 事件
        if ($statusEvent) {
            Integration::dispatch($device['app_id'], $device, $uplinkData, $telemetry, function (string $m): void {
                $this->log($m);
            }, 'status');
            $this->logEvent('status', 'info',
                "设备状态更新 dev#{$device['id']} battery=" . var_export($telemetry['battery'] ?? null, true)
                . " margin=" . ($telemetry['margin'] ?? ''),
                $gwEui, $device['id'], $device['app_id'], $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
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

        // ADRACKReq 响应（对齐 ChirpStack：must_send = fctrl.adr_ack_req）
        // 设备被 ADR 压到低速率后，累计 FCnt 超过 ADR_ACK_LIMIT 会在 FCtrl 置 ADRACKReq，
        // 要求 NS 在 RX1/RX2 窗口回下行（LinkADRReq 或空 ACK 帧），否则设备认为下行丢失会持续降功率重试。
        // 若本次已发下行（confirmed ACK / 应用下行 / MAC-only），无需重复回。
        if (!empty($p['adr_ack_req']) && $mtype !== Frame::MTYPE_CONFIRMED_UP && $macConsumed === false) {
            $this->log("ADRACKReq: dev#{$device['id']} devAddr=$devAddrHex 回空 ACK 下行 (ADR ack)");
            // 空下行帧（FHDR 仅 ADR 位，无 FPort/负载）——设备据此认为下行链路仍通，停止降功率重试
            $downPhy = $this->buildDownFrame(
                $ks, $p['dev_addr'], (int) $device['fcnt_down'] + 1,
                false, false, null, '', (bool) $device['adr'], '', '', $fcnt
            );
            $this->bumpDownFCnt($device['id']);
            $rx1Tmst = $this->enqueueClassADownlink($gwEui, $peer, $downPhy, $tmst, $region, $freq, $datr);
            $this->logEvent('downlink', 'info', "ADRACKReq 应答：空 ACK 下行 dev#{$device['id']} (ADR ack, Class A RX1/RX2)", $gwEui, $device['id'], $device['app_id'], $this->buildDataDownLog($downPhy, $rx1Tmst, $freq, $datr, $gwEui));
        }
    }

    // ---------------- 漫游（Passive Roaming，服务 NS 出站转发） ----------------

    /**
     * 非本网设备 Join：作为服务 NS 把 JoinReq 转发给 Home NS（按 JoinEUI 路由）。
     * 成功转发返回 true（调用方不再走本地入网流程）；无匹配伙伴或转发失败返回 false。
     * 注意：转发为同步 HTTPS（curl），会短暂阻塞 UDP 接收循环；漫游伙伴低延迟时可接受。
     */
    private function tryRoamingJoin(string $phy, array $jr, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, string $peer): bool
    {
        $client = Roaming::clientForJoinEui($jr['app_eui']);
        if (!$client) {
            $this->log("ROAMING JOIN: no partner for appEUI=" . bin2hex($jr['app_eui']));
            return false;
        }
        $msg = Roaming::buildJoinReq($client, [
            'phy'        => base64_encode($phy),
            'mac_version'=> '1.0.3',
            'opt_neg'    => false,
            'dev_eui'    => bin2hex($jr['dev_eui']),
            'dev_addr'   => '',
            'dl_settings'=> '',
            'rx_delay'   => 0,
            'cf_list'    => '',
        ]);
        // Join-Accept 固定延迟 JOIN_ACCEPT_DELAY1 = 5s
        Roaming::rememberPending('join', bin2hex($jr['dev_eui']), '', $gwEui, $peer, $tmst, ELW_DEFAULT_REGION, $freq, $datr, 5000);
        $resp = Roaming::forward($client, $msg);
        if (isset($resp['error'])) {
            $this->log("ROAMING JOIN forward failed appEUI=" . bin2hex($jr['app_eui']) . ": " . $resp['error']);
            return false;
        }
        $this->log("ROAMING JoinReq devEUI=" . bin2hex($jr['dev_eui']) . " -> " . $client->receiverId . " queued (awaiting JoinAns)");
        return true;
    }

    /**
     * 非本网 DevAddr 上行：作为服务 NS 把 XmitDataReq 转发给 Home NS（按 DevAddr 路由）。
     * 成功转发返回 true；否则返回 false（调用方按未知设备处理）。
     */
    private function tryRoamingDataUp(string $phy, array $p, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, string $peer): bool
    {
        $devAddrBin = $p['dev_addr'];
        if (!Roaming::isRoamingDevAddr($devAddrBin)) {
            return false;
        }
        $netIds = Roaming::getNetIdsForDevAddr($devAddrBin);
        if (empty($netIds)) {
            $this->log("ROAMING DATA: no partner for devAddr=" . bin2hex($devAddrBin));
            return false;
        }
        $client = Roaming::getClient($netIds[0]);
        if (!$client) {
            return false;
        }
        $region = Region::get(ELW_DEFAULT_REGION);
        $dlDelay = (int) ($region->getReceiveDelay1() * 1000); // RX1 窗口延迟
        $msg = Roaming::buildXmitDataReq($client, [
            'phy'       => base64_encode($phy),
            'dev_eui'   => '',
            'dev_addr'  => bin2hex($devAddrBin),
            'freq'      => $freq,
            'dr'        => $datr,
            'recv_time' => time(),
            'gw_id'     => $gwEui,
            'rssi'      => $rssi,
            'snr'      => $lsnr,
            'region'    => ELW_DEFAULT_REGION,
        ]);
        Roaming::rememberPending('data', '', bin2hex($devAddrBin), $gwEui, $peer, $tmst, ELW_DEFAULT_REGION, $freq, $datr, $dlDelay);
        $resp = Roaming::forward($client, $msg);
        if (isset($resp['error'])) {
            $this->log("ROAMING XmitDataReq forward failed devAddr=" . bin2hex($devAddrBin) . ": " . $resp['error']);
            return false;
        }
        $this->log("ROAMING XmitDataReq devAddr=" . bin2hex($devAddrBin) . " -> " . $client->receiverId . " queued (awaiting PrUpdAns)");
        return true;
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
            // 下行原始报文（txpk 结构，写入 downlinks.raw_json 供前端"原始 JSON"查看）
            $dlRawJson = $this->buildDataDownLog($downPhy, $tmst, $freq, $datr, $gwEui);
            if ($classC) {
                // Class C：优先 RXC imme（RX2 频点/速率），但刚上行且空口会越过 RX1 起点时
                // 改走 Class A RX1/RX2 窗口（enqueueClassCDownlink 内做时序判断）。
                // 下行频点/速率必须用 RX2 参数，不能用上行频点——否则设备 RXC 收不到。
                // 对齐 ChirpStack set_tx_info_for_rx2：Class C 下行一律走 rx2_frequency / rx2_dr。
                [$dlFreq, $dlDatr, $mode] = $this->enqueueClassCDownlink($device, $region, $downPhy, $tmst, $freq, $datr, $gwEui, $peer);
                $dlRawJson = $this->buildDataDownLog($downPhy, $tmst, $dlFreq, $dlDatr, $gwEui);
                $modeDesc = ($mode === 'a-windows') ? '刚上行，改走 RX1/RX2 窗口' : 'RXC imme';
                $this->log("APP DOWNLINK -> dev_id={$device['id']} port={$dl['port']} (Class C $modeDesc)");
                $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} port={$dl['port']} (Class C $modeDesc)", $gwEui, $device['id'], $device['app_id'], $dlRawJson);
            } else {
                // Class A：RX1 + 条件 RX2 双窗口（长空口时跳过 RX2，避免重叠损坏 RX1 尾部）
                $rx1Tmst = $this->enqueueClassADownlink($gwEui, $peer, $downPhy, $tmst, $region, $freq, $datr);
                $this->log("APP DOWNLINK -> dev_id={$device['id']} port={$dl['port']}");
                $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} port={$dl['port']} (Class A RX1/RX2)", $gwEui, $device['id'], $device['app_id'], $dlRawJson);
            }
            Database::execute("UPDATE downlinks SET status='sent', fcnt=?, sent_at=?, raw_json=? WHERE id=?", [$fcntDown, time(), $dlRawJson, $dl['id']]);
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
        // FUOTA MAC 消歧：FUOTA 命令（0x08~0x0D）与标准 MAC 命令同号，仅当设备属于
        // 活跃 campaign 的组播组时按 FUOTA 语义剥离并处理（对齐 ChirpStack），剩余字节继续走标准解析。
        if ($macBytes !== '') {
            $macBytes = $this->handleFuotaMacUplink($device, $macBytes);
        }
        $fopts = '';
        if ($macBytes !== '') {
            $cmds = MacCommands::parse($macBytes);
            if (!empty($cmds)) {
                $uplink = [
                    'snr'    => $lsnr,
                    'dr'     => $region->datrToDr($datr) ?? (int) $device['dr'], // 上行实际 DR（对齐 ChirpStack 用 uplink dr）
                    'region' => $region,
                    'freq'   => $freq,
                    'rx_set' => $this->uplinkRxSets[bin2hex($p['dev_addr']) . ':' . (int) ($p['fcnt_lo'] ?? 0)] ?? null,
                ];
                $res = MacCommands::handleUplink($device, $region, $uplink, $cmds);
                $fopts = implode('', $res['responses']);
                // 可观测性：NS 已应答 DeviceTimeReq，设备内部 UTC/GPS 时间标记有效
                foreach ($res['responses'] as $rb) {
                    if (!empty($rb) && $rb[0] === chr(MacCommands::CID_DEVICE_TIME_ANS)) {
                        $this->log(sprintf(
                            "DEVTIME: dev#%d 应答 DeviceTimeReq（device_time_valid=1, GPS秒=%d）",
                            $device['id'], (int) ($device['device_time'] ?? 0)
                        ));
                    }
                    if (!empty($rb) && $rb[0] === chr(MacCommands::CID_LINK_CHECK_ANS)) {
                        // 对齐 ChirpStack link_check.rs：margin = 最强网关 SNR − required SNR；gw_cnt = 收到该上行的网关数
                        $this->log(sprintf(
                            "LINKCHECK: dev#%d 应答 LinkCheckAns margin=%d gw_cnt=%d (上行DR=%s)",
                            $device['id'], ord($rb[1] ?? "\x00"), ord($rb[2] ?? "\x00"), $datr
                        ));
                    }
                }
            }
        }

        // Class B 激活编排：设备经 DeviceModeInd(B) 且已取得有效网络时间（device_time_valid=1）后，
        // NS 主动下发 BeaconFreqReq(0x13) + PingSlotChannelReq(0x11) 把设备引导到信标网格上，
        // 随后设备发 BeaconTimingReq → NS 回 BeaconTimingAns（Phase B）完成信标锁定。
        // 仅当尚未下发过才排队（避免每次上行重复下发）。
        // ★ 独立隔离：信标编排依赖 Beacon 类/beacon_epoch 列，任何缺失都不应中断本上行的正常应答。
        if (($device['class'] ?? 'A') === 'B' && !empty($device['device_time_valid'])) {
            try {
                if (MacCommands::getPending($device, MacCommands::CID_BEACON_FREQ_REQ) === null) {
                    $bf = MacCommands::buildBeaconFreqReq($region->getBeaconFrequency());
                    MacCommands::setPending($device, MacCommands::CID_BEACON_FREQ_REQ, $bf);
                    $fopts .= $bf;
                }
                if (MacCommands::getPending($device, MacCommands::CID_PING_SLOT_CHANNEL_REQ) === null) {
                    $psc = MacCommands::buildPingSlotChannelReq($region->getBeaconFrequency(), $region->getBeaconDataRate());
                    MacCommands::setPending($device, MacCommands::CID_PING_SLOT_CHANNEL_REQ, $psc);
                    $fopts .= $psc;
                }
                // 记录信标锚点（128s 网格边界 GPS 秒），供 ping-slot / 信标下发对齐。
                // 锚点必须是 128 的倍数（任意多 128 值皆可），否则 BeaconTimingAns/nextPingSlot 会退化成 delay=0。
                if (empty($device['beacon_epoch'])) {
                    $g = MacCommands::gpsSecondsNow();
                    $device['beacon_epoch'] = intdiv($g, Beacon::BEACON_PERIOD) * Beacon::BEACON_PERIOD;
                }
                $this->log(sprintf(
                    "CLASSB: dev#%d 进入 Class B，下发 BeaconFreqReq/PingSlotChannelReq 引导信标锁定 (beacon_epoch=%d)",
                    $device['id'], (int) ($device['beacon_epoch'] ?? 0)
                ));
            } catch (\Throwable $e) {
                $this->log("CLASSB WARN dev#{$device['id']} 信标编排异常（已隔离，不影响本上行应答）: " . $e->getMessage());
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

        // Relay 配置调度（对齐 ChirpStack downlink/data.rs：仅当 device_profile 配置了
        // relay_params 时执行）。is_relay → RelayConfReq（6 字段比对），is_relay_ed → EndDeviceConfReq（6 字段比对）。
        // 设备应答全 ACK 后由 handleRelayConfAns 写入 relay_state，下次上行即不再排队。
        $dp = DeviceProfile::get((int) ($device['device_profile_id'] ?? 0));
        $relayParams = $dp ? DeviceProfile::relayParams($dp) : null;
        if ($relayParams !== null) {
            $relayMac = Relay::scheduleConf($device, $relayParams);
            if ($relayMac !== '') {
                $fopts .= $relayMac;
                $this->log(sprintf(
                    "RELAY: dev#%d schedule %s (%dB)",
                    $device['id'],
                    (ord($relayMac[0]) === Relay::CID_RELAY_CONF_REQ) ? 'RelayConfReq' : 'EndDeviceConfReq',
                    strlen($relayMac)
                ));
            }
        }

        // 遥测状态采集：周期性下发 DevStatusReq（对齐 ChirpStack dev_status_req_freq，默认 1 小时）。
        //   - 距上次请求超过间隔 → setPending 挂起（下次下行捎带）；
        //   - 设备上报 DevStatusAns 后由 onDevStatusAns 清 pending；
        //   - 从未采到过（battery/margin 还是默认值）→ 首次间隔缩短到 60s，尽快采一次；
        //   - 采到过后 → 间隔 3600s（1 小时一次，避免每帧都发 DevStatusReq 干扰设备）。
        // 注意：margin=0 是合法值（信号刚好在门限上），不能用来判断"未上报"；
        // 未上报判据用 battery==-1（DB 默认）且 margin 为 NULL/默认 0 的组合。
        $pendingStatus = MacCommands::getPending($device, MacCommands::CID_DEV_STATUS_REQ);
        $lastReqAt = (int) ($device['dev_status_req_at'] ?? 0);
        $batteryDefault = ($device['battery'] ?? -1) == -1;
        $marginDefault  = ($device['margin'] ?? null) === null || (string) ($device['margin'] ?? '0') === '0';
        $everAnswered = !($batteryDefault && $marginDefault);
        $interval = $everAnswered ? 3600 : 60;
        if ($pendingStatus === null && ($lastReqAt === 0 || (time() - $lastReqAt) >= $interval)) {
            MacCommands::setPending($device, MacCommands::CID_DEV_STATUS_REQ, MacCommands::buildDevStatusReq());
            $device['dev_status_req_at'] = time();
            $this->log("DEVSTATUS: dev#{$device['id']} schedule DevStatusReq (everAnswered=" . ($everAnswered ? 'yes' : 'no') . ")");
        }
        $pendingStatus = MacCommands::getPending($device, MacCommands::CID_DEV_STATUS_REQ);
        if ($pendingStatus !== null && strlen($pendingStatus) === 1 && ord($pendingStatus[0]) === MacCommands::CID_DEV_STATUS_REQ) {
            $fopts .= $pendingStatus;
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

    private function persistDeviceMacState(array $device): void
    {
        $cols = [
            'dr', 'tx_power_index', 'nb_trans', 'rx2_frequency', 'rx2_dr', 'rx1_dr_offset',
            'enabled_uplink_channel_indices', 'pending_mac', 'mac_command_error_count',
            'uplink_adr_history', 'class', 'battery', 'margin', 'relay_state', 'dev_status_req_at',
            'device_time_valid', 'device_time', 'beacon_epoch',
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

    private function buildDownFrame(array $ks, string $devAddrBin, int $fcnt, bool $confirmed, bool $ack, $fport, string $payload, bool $adr, string $macFopts, string $macPort0, int $confFCnt = 0): string
    {
        if ($macPort0 !== '') {
            // MAC 溢出：用 Port0 承载剩余 MAC 命令（1.1 用 NwkSEncKey 加密）
            return $this->buildDownPhy($ks, $devAddrBin, $fcnt, $confirmed, $ack, 0, $macPort0, $adr ? 1 : 0, $macFopts, $confFCnt);
        }
        return $this->buildDownPhy($ks, $devAddrBin, $fcnt, $confirmed, $ack, $fport, $payload, $adr ? 1 : 0, $macFopts, $confFCnt);
    }

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
            // Class C：优先 RXC imme（RX2 频点/速率），刚上行且空口会越过 RX1 起点时改走 Class A 窗口
            //（enqueueClassCDownlink 内做时序判断；对齐 ChirpStack set_tx_info_for_rx2）。
            [$dlFreq, $dlDatr, $mode] = $this->enqueueClassCDownlink($device, $region, $downPhy, $tmst, $freq, $datr, $gwEui, $peer);
            $modeDesc = ($mode === 'a-windows') ? '刚上行，改走 RX1/RX2 窗口' : 'RXC imme';
            $this->log("MAC DOWNLINK -> dev_id={$device['id']} (Class C $modeDesc)");
            $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} (MAC-only, Class C $modeDesc)", $gwEui, $device['id'], $device['app_id'], $this->buildDataDownLog($downPhy, $tmst, $dlFreq, $dlDatr, $gwEui));
        } else {
            $rx1Tmst = $this->enqueueClassADownlink($gwEui, $peer, $downPhy, $tmst, $region, $freq, $datr);
            $this->log("MAC DOWNLINK -> dev_id={$device['id']} (Class A RX1/RX2)");
            $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} (MAC-only, Class A RX1/RX2)", $gwEui, $device['id'], $device['app_id'], $this->buildDataDownLog($downPhy, $rx1Tmst, $freq, $datr, $gwEui));
        }
    }

    /**
     * Class C 下行入队（默认 RXC imme），带"刚上行"时序保护。
     *
     * 背景：设备每次上行后都会打开 Class A 的 RX1/RX2 窗口（期间 RX_C 挂起、射频切走）。
     * 若在此时 imme 下发，且下行空口（RX2 默认 DR0/SF12 ≈1.32s）会越过 RX1 窗口起点，
     * 设备会放弃接收中的 RX_C 包去听 RX1 → 包丢失；设备端表现为 RX1/RX2 全超时
     * （即便网关 TX_ACK=ok，如 2026-08-19 实测 fcnt=2 案例）。
     * 该场景改走 Class A RX1/RX2 窗口（设备正在听，可靠）。
     *
     * @return array [dlFreq, dlDatr, mode] mode: 'c-imme' | 'a-windows'
     */
    private function enqueueClassCDownlink(array $device, Region $region, string $downPhy, int $ulTmst, float $ulFreq, string $ulDatr, string $gwEui, string $peer): array
    {
        $dlFreq = (int) ($device['rx2_frequency'] ?? 0) > 0 ? (int) $device['rx2_frequency'] / 1e6 : $region->getRx2Frequency() / 1e6;
        $dlDatr = $region->drToDatr((int) ($device['rx2_dr'] ?? 0) > 0 ? (int) $device['rx2_dr'] : $region->getRx2DataRate());
        $sinceUp = time() - (int) ($device['last_seen'] ?? 0);
        $airtimeUs = $this->uplinkAirtimeUs($downPhy, $dlDatr, $region);
        $rx1DelayS = $region->getReceiveDelay1() / 1000.0;
        if ($sinceUp >= 0 && $sinceUp < 2.5 && ($sinceUp + $airtimeUs / 1e6) > $rx1DelayS + 0.05) {
            // 刚上行 + 空口会越过 RX1 起点 → 走 Class A RX1/RX2 双窗口（设备正在听）
            $this->enqueueClassADownlink($gwEui, $peer, $downPhy, $ulTmst, $region, $ulFreq, $ulDatr);
            return [$dlFreq, $dlDatr, 'a-windows'];
        }
        // 常态：RXC 立即下发（RX2 频点/速率）
        $this->enqueueDownlink($gwEui, $peer, $downPhy, 0, $dlFreq, $dlDatr, true);
        return [$dlFreq, $dlDatr, 'c-imme'];
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
    private function acknowledgeDownlinks(array $device, string $gwEui, array $uplinkData, array $telemetry, string $rawJson = ''): void
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
        $this->logEvent('ack', 'info', "下行被设备确认 dev#{$device['id']} downlink#{$dl['id']} fcnt={$dl['fcnt']}", $gwEui, $device['id'], $device['app_id'], $rawJson);
        // ack 集成事件（消费者可见是哪条下行被确认）
        $ackData = $uplinkData + ['downlink_id' => (int) $dl['id'], 'downlink_fcnt' => (int) $dl['fcnt']];
        Integration::dispatch($device['app_id'], $device, $ackData, $telemetry, function (string $m): void {
            $this->log($m);
        }, 'ack');
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
                    dev.last_seen,
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
                'last_seen'        => (int) $dl['last_seen'],
            ];
            $sendAt = ($device['class'] === 'C') ? $now : $this->nextPingSlot($device, $now);
            if ($now < $sendAt) {
                continue; // 尚未到 ping 时隙，下一轮再试
            }
            $this->sendDeviceDownlink($device, $dl, true);
        }
    }

    // ---------------- Class B 信标生成与调度 ----------------

    /**
     * Class B 信标调度器（每 128s 触发一次）。
     * 计算下一个信标 GPS 秒 → Beacon::buildFrame 构造 PHYS 帧 → 按各区域信标频点/DR，
     * 由网关 concentrator 时间参考推算 tmst，经 PULL_RESP 下发所有在线网关（提前 BEACON_SCHEDULE_LEAD 秒）。
     *
     * 关键前提：NS 主机时钟须与 GPS 对齐（NTP/UTC 误差 < 百 ms 级）。信标 tmst 由「网关最近一次
     * 上行 rxpk.tmst + (信标unix − 上行主机unix)」推算；若网关无 concentrator 时间参考，则 imme 下发并告警
     * （信标与 GPS 失准，设备无法锁定）。更精确的做法是网关 GPS 锁定时改用 txpk.tmms（GPS 毫秒），后续可扩展。
     */
    private function processBeaconScheduler(): void
    {
        // 仅当网络中存在 Class B 设备才发射信标，避免纯 A/C 网络占用信标频点
        $hasB = Database::fetch("SELECT 1 FROM devices WHERE class='B' LIMIT 1");
        if (!$hasB) {
            return;
        }
        $gpsNow = MacCommands::gpsSecondsNow();
        $nextBeaconGps = (int) ceil($gpsNow / Beacon::BEACON_PERIOD) * Beacon::BEACON_PERIOD;
        if ($nextBeaconGps <= $this->lastBeaconGps) {
            return; // 已调度过本个信标
        }
        $beaconUnix = $this->gpsToUnix($nextBeaconGps);
        $dt = $beaconUnix - time();
        if ($dt > self::BEACON_SCHEDULE_LEAD || $dt < -2) {
            return; // 未进入下发窗口（或已错过太久，等下一个）
        }
        $this->lastBeaconGps = $nextBeaconGps;

        $gwSpecific = str_repeat("\x00", 7); // InfoDesc=0（网关无 GPS），设备用默认下行→上行时延

        $gateways = $this->collectBeaconGateways(); // region => [['gw_id'=>...], ...]
        $total = 0;
        foreach ($gateways as $regionName => $gws) {
            $region = Region::get($regionName ?: ELW_DEFAULT_REGION);
            // 按区域构造信标帧：RFU 长度随区域不同（EU868=2/0，CN470=3/1，US915=5/3…），
            // 固件按 phyParam.BeaconFormat.BeaconSize 做 size 校验，错则整帧丢弃 → 必须按区域构建
            $beaconPhy = Beacon::buildFrame(
                $nextBeaconGps,
                $gwSpecific,
                $this->beaconMacVersion,
                $region->getBeaconRfu1(),
                $region->getBeaconRfu2()
            );
            // 信标频点含跳频（CN470/US915/AU915 在 8 个信道间跳频），其余区域固定频点
            $freq = $region->getBeaconChannelFrequency($nextBeaconGps) / 1e6;
            $datr = $region->drToDatr($region->getBeaconDataRate());
            foreach ($gws as $gw) {
                $gwEui = $gw['gw_id'];
                $peer = $this->gateways[$gwEui]['addr'] ?? '';
                if ($peer === '') {
                    continue; // 网关当前无下行回程（未发 PULL_DATA/上行），跳过
                }
                $ref = $this->gateways[$gwEui]['c_ref'] ?? null;
                if (is_array($ref) && (time() - (int) ($ref['host'] ?? 0)) <= self::BEACON_REF_MAX_AGE) {
                    // 由网关 concentrator 时间参考推算信标 tmst（对齐 GPS 秒）
                    $deltaUs = ($beaconUnix * 1000000) - (int) ($ref['host_us'] ?? 0);
                    $tmst = ((int) ($ref['tmst'] ?? 0) + $deltaUs) & 0xFFFFFFFF;
                    $imme = false;
                } else {
                    // 无 concentrator 时间参考 → 立即下发（信标可能与 GPS 失准，记告警）
                    $tmst = 0;
                    $imme = true;
                    $this->log("BEACON WARN gw=$gwEui 无 concentrator 时间参考，信标以 imme 下发（可能与 GPS 失准）");
                }
                $this->enqueueDownlink($gwEui, $peer, $beaconPhy, $tmst, $freq, $datr, $imme);
                $total++;
            }
        }
        $this->log(sprintf(
            "BEACON: 调度信标 GPS秒=%d unix=%d 区域数=%d 网频数=%d",
            $nextBeaconGps, $beaconUnix, count($gateways), $total
        ));
    }

    /** GPS 秒 → Unix 秒（与 MacCommands::gpsSecondsNow 互逆）。
     * gps = unix − 315964800 + 18 ⇒ unix = gps + 315964800 − 18。
     * 注意 GpsTime::fromGps 闰秒符号相反（bug），此处用正确公式。 */
    private function gpsToUnix(int $gpsSec): int
    {
        return (int) ($gpsSec + GpsTime::GPS_EPOCH_UNIX - GpsTime::LEAP_SECONDS);
    }

    /** 收集在线网关并按区域分组（信标频点/DR 随区域不同）。 */
    private function collectBeaconGateways(): array
    {
        $rows = Database::fetchAll(
            "SELECT gw_id, region FROM gateways WHERE last_seen >= ? ORDER BY gw_id",
            [time() - 600]
        );
        $out = [];
        foreach ($rows as $r) {
            $region = $r['region'] ?: ELW_DEFAULT_REGION;
            $out[$region][] = $r;
        }
        return $out;
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
                $this->logEvent('downlink', 'warn', "下行重传 dev#{$dl['dev_id']} downlink#{$dl['id']} (Class C RXC, tx=$tx/$nbTrans)", $dl['last_gw_id'] ?? '', $dl['dev_id'], $dl['app_id'], $dl['raw_json'] ?? '');
            } else {
                // Class A/B：退回 pending，等下一次上行由 dispatchPendingAppDownlinks 重传
                Database::execute("UPDATE downlinks SET status='pending' WHERE id=?", [$dl['id']]);
                $this->log("RETX downlink#{$dl['id']} dev#{$dl['dev_id']} -> pending (Class A/B, tx=$tx/$nbTrans)");
                $this->logEvent('downlink', 'warn', "下行重传排队 dev#{$dl['dev_id']} downlink#{$dl['id']} (Class A/B, tx=$tx/$nbTrans)", '', $dl['dev_id'], $dl['app_id'], $dl['raw_json'] ?? '');
            }
        }
    }
    /**
     * 计算下一個 ping-slot 的墙钟时刻（unix 秒），对齐固件 ComputePingOffset。
     * ping-slot 网格：nextBeacon + pingOffset*30ms + k*pingPeriod，其中
     *   - nextBeacon = 距设备当前 GPS 时间最近的 128s 信标边界；
     *   - pingOffset  = AES-128(零密钥, [gps(4)||devAddr(4)])[0..1] mod pingPeriod30；
     *   - pingPeriod  = 设备 ping_period（秒）。
     * 无有效 GPS 时间时回退到 NS 当前 GPS 秒（设备侧 device_time 优先）。
     */
    private function nextPingSlot(array $device, int $now): int
    {
        $gpsNow = (int) ($device['device_time'] ?? 0);
        if ($gpsNow <= 0) {
            $gpsNow = \holastack\Core\MacCommands::gpsSecondsNow();
        }
        $period = (int) ($device['ping_period'] > 0 ? $device['ping_period'] : ELW_PING_PERIOD);
        if ($period <= 0) {
            $period = ELW_PING_PERIOD;
        }
        $pingPeriod30 = (int) round($period * 1000 / 30);
        if ($pingPeriod30 <= 0) {
            $pingPeriod30 = 1;
        }
        $ref = (int) ($device['beacon_epoch'] ?? 0);
        if ($ref <= 0 || ($ref % Beacon::BEACON_PERIOD) !== 0) {
            $ref = intdiv($gpsNow, Beacon::BEACON_PERIOD) * Beacon::BEACON_PERIOD;
        }
        $nextBeacon = (int) ceil(($gpsNow - $ref) / Beacon::BEACON_PERIOD) * Beacon::BEACON_PERIOD + $ref;
        $devAddrHex = sprintf('%08s', $device['dev_addr'] ?? '00000000');
        $devAddr = unpack('N', hex2bin($devAddrHex))[1];
        $pingOffset30 = Beacon::computePingOffset($nextBeacon, $devAddr, $pingPeriod30);
        // GPS 秒 → 墙钟秒 映射（now 对应 gpsNow）
        $baseUnix = $now + ($nextBeacon - $gpsNow);
        $slotUnix = $baseUnix + ($pingOffset30 * 30) / 1000.0;
        while ($slotUnix < $now) {
            $slotUnix += $period;
        }
        return (int) ceil($slotUnix);
    }

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
        // Class C 时序保护：设备刚上行（RX1/RX2 窗口期，RX_C 挂起）时 imme 下发会丢包
        //（尤其 RX2 默认 SF12 长空口：包还在空中设备已切去听 RX1）。等设备回到 RXC 再发。
        if ($imme && ($device['class'] ?? '') === 'C') {
            $sinceUp = time() - (int) ($device['last_seen'] ?? 0);
            if ($sinceUp >= 0 && $sinceUp < 2.5) {
                $this->log("SCHED DOWNLINK SKIP -> dev_id={$device['id']} class=C port={$dl['port']} (刚上行 RX1/RX2 窗口期，等设备回到 RXC 再发, sinceUp={$sinceUp}s)");
                return; // 保持 pending，下轮 tick 重试
            }
        }
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
        $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} class={$device['class']} port={$dl['port']} gw=$gwEui", $gwEui, $device['id'], $device['app_id'], $this->buildDataDownLog($downPhy, 0, $region->getRx2Frequency() / 1e6, $region->drToDatr($region->getRx2DataRate()), $gwEui));
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
        $this->logEvent('downlink', 'info', "组播下行下发 group={$q['multicast_group_id']} port={$q['f_port']}", '', 0, 0, $this->buildDataDownLog($phy, $tmst, $freq, $datr, $gwEui ?? ''));
    }
}

// ---------------- FUOTA 调度（对齐 ChirpStack fuota 状态机） ----------------

/**
 * 周期调用：推进所有活跃 FUOTA campaign 的状态机。
 *   SETUP          → 单播 McGroupSetupReq（等设备 McGroupSetupAns）
 *   FRAGMENTATION  → 组播 FragSessionSetupReq + FragDataBlockReq（按 min/max_delay 节流）
 *   STATUS         → 组播 FragStatusReq + FPort201 FuotaStatusReq（收集设备上报）
 *   DONE | FAILED  → 由 finalizeCampaign 收尾
 * 超时兜底：campaign 启动超过 timeout 秒即收尾。
 */
private function processScheduledFuota(): void
{
    $now = time();
    $campaigns = Database::fetchAll(
        "SELECT * FROM fuota_campaigns WHERE state IN ('SETUP','FRAGMENTATION','STATUS') AND next_frame_at <= ?",
        [$now]
    );
    foreach ($campaigns as $camp) {
        if ((int) $camp['started_at'] > 0 && ($now - (int) $camp['started_at']) > (int) $camp['timeout']) {
            $res = Fuota::finalizeCampaign((int) $camp['id']);
            $this->log(sprintf("FUOTA: campaign#%d timeout -> %s (done=%d failed=%d)", $camp['id'], $res['state'], $res['done'], $res['failed']));
            $this->logEvent('fuota', 'warn', "FUOTA campaign#{$camp['id']} 超时收尾 state={$res['state']}", '', 0, (int) $camp['application_id']);
            continue;
        }
        switch ($camp['state']) {
            case Fuota::STATE_SETUP:
                $this->fuotaSetupPhase($camp, $now);
                break;
            case Fuota::STATE_FRAGMENTATION:
                $this->fuotaFragmentationPhase($camp, $now);
                break;
            case Fuota::STATE_STATUS:
                $this->fuotaStatusPhase($camp, $now);
                break;
        }
    }
}

/** SETUP：向未确认组播会话的部署设备单播 McGroupSetupReq；全部确认或超时后进入 FRAGMENTATION。 */
private function fuotaSetupPhase(array $camp, int $now): void
{
    $campId = (int) $camp['id'];
    $group = Database::fetch("SELECT * FROM multicast_groups WHERE id=?", [(int) $camp['multicast_group_id']]);
    if (!$group) {
        Fuota::finalizeCampaign($campId);
        return;
    }
    $macCmd = Fuota::buildGroupSetupForCampaign($camp, $group);
    $deps = Database::fetchAll(
        "SELECT d.*, dv.id AS dev_id, dv.dev_eui, dv.dev_addr, dv.class, dv.region, dv.last_gw_id,
                dv.fcnt_down, dv.adr, dv.app_id, dv.mac_version,
                dv.nwk_s_key, dv.app_s_key, dv.f_nwk_s_int_key, dv.s_nwk_s_int_key, dv.nwk_s_enc_key
         FROM fuota_deployments d JOIN devices dv ON dv.id = d.dev_id
         WHERE d.campaign_id=? AND d.mc_group_ans=0 AND d.state != 'FAILED'",
        [$campId]
    );
    foreach ($deps as $dep) {
        $dev = $dep;
        $gwEui = $this->resolveServingGateway($dev);
        if ($gwEui === '') {
            continue; // 无服务网关（设备未上行过/网关未保活），等下一轮重发
        }
        $region = Region::get($dev['region'] ?: ELW_DEFAULT_REGION);
        $ks = $this->deviceKeySet($dev);
        $fcntDown = (int) $dev['fcnt_down'] + 1;
        $downPhy = $this->buildDownPhy($ks, hex2bin($dev['dev_addr']), $fcntDown, false, false, 0, $macCmd, 0, '', 0);
        $this->bumpDownFCnt((int) $dev['dev_id']);
        $peer = $this->gateways[$gwEui]['addr'] ?? '';
        $this->enqueueDownlink($gwEui, $peer, $downPhy, 0, $region->getRx2Frequency() / 1e6, $region->drToDatr($region->getRx2DataRate()), true);
        $this->log("FUOTA: campaign#$campId McGroupSetupReq -> dev#{$dev['dev_id']} ({$dev['dev_eui']}) gw=$gwEui");
        $this->logEvent('fuota', 'info', "FUOTA 组播会话下发 dev_eui={$dev['dev_eui']}", $gwEui, (int) $dev['dev_id'], (int) $dev['app_id'], $this->buildDataDownLog($downPhy, 0, $region->getRx2Frequency() / 1e6, $region->drToDatr($region->getRx2DataRate()), $gwEui));
    }

    // 全部确认 → 立即进入分片；否则按重发间隔推进
    $remaining = Database::fetch(
        "SELECT COUNT(*) AS n FROM fuota_deployments WHERE campaign_id=? AND mc_group_ans=0 AND state != 'FAILED'",
        [$campId]
    );
    $waiting = Database::fetch(
        "SELECT COUNT(*) AS n FROM fuota_deployments WHERE campaign_id=? AND state != 'FAILED'",
        [$campId]
    );
    if ((int) $remaining['n'] === 0 && (int) $waiting['n'] > 0) {
        Database::execute(
            "UPDATE fuota_campaigns SET state='FRAGMENTATION', next_frame_at=?, updated_at=? WHERE id=?",
            [$now, $now, $campId]
        );
        $this->log("FUOTA: campaign#$campId all McGroupSetupAns received -> FRAGMENTATION");
        $this->logEvent('fuota', 'info', "FUOTA campaign#$campId 组播会话全部确认，开始分片", '', 0, (int) $camp['application_id']);
        return;
    }
    // 未收齐：SETUP 超过 SETUP_MAX_SECONDS 也放行（未应答设备部署在收尾时置 FAILED）
    if ((int) $camp['started_at'] > 0 && ($now - (int) $camp['started_at']) >= self::FUOTA_SETUP_MAX_SECONDS) {
        Database::execute(
            "UPDATE fuota_campaigns SET state='FRAGMENTATION', next_frame_at=?, updated_at=? WHERE id=?",
            [$now, $now, $campId]
        );
        $this->log("FUOTA: campaign#$campId setup deadline reached (unacked=" . (int) $remaining['n'] . ") -> FRAGMENTATION");
        return;
    }
    Database::execute(
        "UPDATE fuota_campaigns SET next_frame_at=? WHERE id=?",
        [$now + self::FUOTA_SETUP_RESEND_INTERVAL, $campId]
    );
}

/** FRAGMENTATION：组播下发下一批分片帧（含首帧的 FragSessionSetupReq），全部发完进入 STATUS。 */
private function fuotaFragmentationPhase(array $camp, int $now): void
{
    $campId = (int) $camp['id'];
    $group = Database::fetch("SELECT * FROM multicast_groups WHERE id=?", [(int) $camp['multicast_group_id']]);
    if (!$group) {
        Fuota::finalizeCampaign($campId);
        return;
    }
    $remaining = (int) $camp['total_frames'] - (int) $camp['frames_sent'];
    if ($remaining <= 0) {
        Database::execute(
            "UPDATE fuota_campaigns SET state='STATUS', next_frame_at=?, status_req_sent=0, updated_at=? WHERE id=?",
            [$now, $now, $campId]
        );
        $this->log("FUOTA: campaign#$campId all frames sent -> STATUS");
        return;
    }
    $batch = min(self::FUOTA_FRAMES_PER_TICK, $remaining);
    $frames = Fuota::nextFrames($campId, (int) $camp['frames_sent'], $batch);
    foreach ($frames as $f) {
        $phy = Fuota::buildMulticastDown($group, 0, '', hex2bin($f['fopts_hex']));
        $this->fuotaSendMulticast($group, $phy);
    }
    $sent = (int) $camp['frames_sent'] + count($frames);
    // 节流：下一批在 min/max_delay * 本批帧数 之后（测试可设 0/0 立即推进）
    $delay = (int) $camp['min_delay'] * count($frames);
    $delay = random_int($delay, max($delay, (int) $camp['max_delay'] * count($frames)));
    Database::execute(
        "UPDATE fuota_campaigns SET frames_sent=?, next_frame_at=?, updated_at=? WHERE id=?",
        [$sent, $now + $delay, $now, $campId]
    );
    $this->log("FUOTA: campaign#$campId sent " . count($frames) . " frame(s) (total $sent/{$camp['total_frames']})");
}

/** STATUS：组播 FragStatusReq + FPort201 FuotaStatusReq，等待设备上报后收尾。 */
private function fuotaStatusPhase(array $camp, int $now): void
{
    $campId = (int) $camp['id'];
    $group = Database::fetch("SELECT * FROM multicast_groups WHERE id=?", [(int) $camp['multicast_group_id']]);
    if (!$group) {
        Fuota::finalizeCampaign($campId);
        return;
    }
    if ((int) $camp['status_req_sent'] === 0) {
        // 组播 FragStatusReq（MAC 0x0A，走 FPort0 FRMPayload）+ FuotaStatusReq（FPort 201）
        $this->fuotaSendMulticast($group, Fuota::buildMulticastDown($group, 0, Fuota::buildFragStatusReq(), ''));
        $this->fuotaSendMulticast($group, Fuota::buildMulticastDown($group, Fuota::FPORT_STATUS, Fuota::buildFuotaStatusReq(0, 0), ''));
        Database::execute(
            "UPDATE fuota_campaigns SET status_req_sent=1, next_frame_at=?, updated_at=? WHERE id=?",
            [$now + 30, $now, $campId]
        );
        $this->log("FUOTA: campaign#$campId FragStatusReq + FuotaStatusReq sent -> waiting for device reports");
        return;
    }
    $pending = Database::fetch(
        "SELECT COUNT(*) AS n FROM fuota_deployments WHERE campaign_id=? AND state NOT IN ('DONE','FAILED')",
        [$campId]
    );
    if ((int) $pending['n'] === 0) {
        $res = Fuota::finalizeCampaign($campId);
        $this->log(sprintf("FUOTA: campaign#%d completed -> %s (done=%d failed=%d)", $campId, $res['state'], $res['done'], $res['failed']));
        $this->logEvent('fuota', 'info', "FUOTA campaign#$campId 完成 state={$res['state']}", '', 0, (int) $camp['application_id']);
    } else {
        Database::execute("UPDATE fuota_campaigns SET next_frame_at=? WHERE id=?", [$now + 30, $campId]);
    }
}

private function fuotaSendMulticast(array $group, string $phy): void
{
    $region = Region::get($group['region'] ?: ELW_DEFAULT_REGION);
    $freq = ((int) $group['frequency'] > 0) ? ((int) $group['frequency'] / 1e6) : ($region->getRx2Frequency() / 1e6);
    $datr = $region->drToDatr((int) $group['dr']);
    $imme = (strtoupper($group['group_type']) === 'C');
    $tmst = $imme ? 0 : (int) (microtime(true) * 1000) % 1000000000;

    $gws = Multicast::groupGateways((int) $group['id']);
    if (empty($gws)) {
        $gws = Database::fetchAll("SELECT gw_id FROM gateways");
    }
    foreach ($gws as $gw) {
        $gwEui = $gw['gw_id'];
        $peer = $this->gateways[$gwEui]['addr'] ?? '';
        if ($peer === '' && !isset($this->gateways[$gwEui])) {
            continue;
        }
        $this->enqueueDownlink($gwEui, $peer, $phy, $tmst, $freq, $datr, $imme);
    }
    Database::execute("UPDATE multicast_groups SET f_cnt = f_cnt + 1 WHERE id=?", [(int) $group['id']]);
    $this->log("FUOTA MULTICAST DOWNLINK -> group={$group['id']} gw=" . count($gws));
}

/**
 * FUOTA MAC 上行消歧：仅当设备属于活跃 campaign 的组播组时，从 MAC 字节流中
 * 剥离 FUOTA Ans（0x08~0x0D）并按 FUOTA 语义处理；剩余字节返回给标准 MAC 解析。
 * 无活跃 campaign 时原样返回（与标准命令同号，按标准语义处理）。
 */
private function handleFuotaMacUplink(array $device, string $macBytes): string
{
    $devEui = $device['dev_eui'] ?? '';
    if ($devEui === '') {
        return $macBytes;
    }
    $active = Fuota::activeCampaignForDevice($devEui);
    if (!$active) {
        return $macBytes;
    }
    $camp = $active['campaign'];
    $out = '';
    $i = 0;
    $n = strlen($macBytes);
    while ($i < $n) {
        $cid = ord($macBytes[$i]);
        if (Fuota::isFuotaCid($cid)) {
            $len = Fuota::fuotaCidPayloadLen($cid);
            if ($len < 0) {
                $out .= $macBytes[$i];
                $i++;
                continue;
            }
            $payload = substr($macBytes, $i + 1, $len);
            $res = Fuota::handleMacAnswer(['campaign' => $camp, 'cid' => $cid, 'payload' => $payload]);
            if ($res['log'] !== '') {
                $this->log("FUOTA: dev#{$device['id']} {$res['log']}");
                $this->logEvent('fuota', 'info', "FUOTA 上行应答 dev_eui=$devEui {$res['log']}", '', (int) $device['id'], (int) ($device['app_id'] ?? 0));
            }
            $i += 1 + $len;
        } else {
            $stdLen = MacCommands::cmdLen($cid);
            if ($stdLen < 0) {
                // 未知命令：停止消歧，剩余字节原样交给标准解析
                $out .= substr($macBytes, $i);
                break;
            }
            $out .= substr($macBytes, $i, 1 + $stdLen);
            $i += 1 + $stdLen;
        }
    }
    return $out;
}

private function handleFuotaAppPayload(array $device, ?int $fport, string $decrypted, string $rawJson = ''): void
{
    if ($fport !== Fuota::FPORT_SETUP && $fport !== Fuota::FPORT_STATUS) {
        return;
    }
    if ($decrypted === '') {
        return;
    }
    $devEui = $device['dev_eui'] ?? '';
    if ($devEui === '') {
        return;
    }
    $active = Fuota::activeCampaignForDevice($devEui);
    if (!$active) {
        return;
    }
    $res = Fuota::handleAppPayload([
        'campaign' => $active['campaign'],
        'fport'    => $fport,
        'payload'  => $decrypted,
    ]);
    if ($res['log'] !== '') {
        $this->log("FUOTA: dev#{$device['id']} {$res['log']}");
        $this->logEvent('fuota', 'info', "FUOTA 应用载荷 dev_eui=$devEui {$res['log']}", '', (int) $device['id'], (int) ($device['app_id'] ?? 0), $rawJson);
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
    private function bufferJoinDownlink(string $micKey, Region $region, string $joinAccept, int $tmst, float $freq, string $datr, int $rssi, string $gwEui, string $peer, int $devId = 0, int $appId = 0): void
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
                'flushedTmst' => 0, // 最近一次实际下发使用的 ul_tmst 锚点（用于晚副本重排）
                'devId'      => $devId,
                'appId'      => $appId,
            ];
        } else {
            $e = &$this->joinBuf[$micKey];
            // 副本选择：优先【最新 tmst】——设备可能 NbTrans>1 在多个信道重复发射（如 868.3/868.5），
            // RX1/RX2 窗口相对【最后一次发射】打开；若按 RSSI 选了先发的副本，下行会提前一个发射间隔，
            // 设备窗口还没打开（错过）。同 tmst（镜像频率副本）时取 RSSI 更强（真实信道）的。
            if ($tmst > $e['bestTmst'] || ($tmst === $e['bestTmst'] && $rssi > $e['bestRssi'])) {
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
                    continue;
                }
                // 已下发后，若同一 Join 又来了 tmst 更晚的副本（网关分批上报，实测可晚达 ~1s），
                // 且仍在 RX 窗口内（首见后 <4.5s）→ 按新锚点重新下发（原下行已发出，设备错过也无害）。
                // 设备 RX1/RX2 相对其【最后一次发射】打开，晚副本 tmst 更接近真实 txDone。
                if (($e['bestTmst'] - (int) ($e['flushedTmst'] ?? 0)) > 50000 && ($now - $e['firstSeen']) < 4.5) {
                    $this->log(sprintf(
                        "JOIN RESCHED: 收到更晚副本 gw=%s ul_tmst=%d（原锚点 %d，Δ=%dµs），按新锚点重新下发 RX1/RX2",
                        $e['gwEui'], $e['bestTmst'], (int) ($e['flushedTmst'] ?? 0), $e['bestTmst'] - (int) ($e['flushedTmst'] ?? 0)
                    ));
                    $this->scheduleJoinRx1Rx2($e, 'resched');
                    $this->joinBuf[$micKey]['flushedTmst'] = $e['bestTmst'];
                }
                continue;
            }
            if ($now - $e['firstSeen'] < 0.08) {
                continue; // 仍在收集重复副本（镜像频率等），稍后再下发
            }
            $this->scheduleJoinRx1Rx2($e, 'first');
            $this->joinBuf[$micKey]['scheduled'] = true;
            $this->joinBuf[$micKey]['flushedTmst'] = $e['bestTmst'];
        }
    }

    /**
     * 按当前最佳副本（最新 tmst）下发 Join-Accept 的 RX1 + 条件 RX2。
     * $reason: 'first'（首次下发，记事件）| 'resched'（晚副本重排，不重复记事件）。
     */
    private function scheduleJoinRx1Rx2(array $e, string $reason): void
    {
        $region = $e['region'];
        $joinAccept = $e['joinAccept'];
        $gwEui = $e['gwEui'];
        $peer = $e['peer'];
        $tmst = $e['bestTmst'];
        $freq = $e['bestFreq'];
        $datr = $e['bestDatr'];
        $tag = $reason === 'resched' ? ' [RESCHED]' : '';

        // RX1：设备在其上行频点监听（取最佳副本的频点）
        $dlTmstRx1 = $tmst + $region->getJoinAcceptDelay1() * 1000;
        $this->log(sprintf(
            "JOIN DOWNLINK RX1%s: gw=%s ul_tmst=%d delay=%dms dl_tmst_rx1=%d RX1freq=%.3f RX1datr=%s (dedup rssi=%d)",
            $tag, $gwEui, $tmst, $region->getJoinAcceptDelay1(), $dlTmstRx1, $freq, $datr, $e['bestRssi']
        ));
        if ($reason !== 'resched') {
            // 记录 Join Accept 下行协议原文（txpk 结构，events.raw_json）
            $this->logEvent('join', 'info', "Join Accept 下行下发 RX1 gw=$gwEui freq=" . sprintf('%.3f', $freq) . " datr=$datr", $gwEui, $e['devId'] ?? 0, $e['appId'] ?? 0, $this->buildJoinAcceptLog($joinAccept, $dlTmstRx1, $freq, $datr, $gwEui));
        }
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
                "JOIN DOWNLINK RX2%s: SKIPPED (airtime=%.0fus >= gap=%.0fus, 避免与 RX1 发射尾部冲突导致 MIC 损坏)",
                $tag, $jaAirtimeUs, $dlGapUs
            ));
        } else {
            $dlTmstRx2 = $tmst + $region->getJoinAcceptDelay2() * 1000;
            $rx2Freq = $region->getRx2Frequency() / 1e6;
            $rx2Datr = $region->drToDatr($region->getRx2DataRate());
            $this->log(sprintf(
                "JOIN DOWNLINK RX2%s: dl_tmst_rx2=%d RX2freq=%.3f RX2datr=%s",
                $tag, $dlTmstRx2, $rx2Freq, $rx2Datr
            ));
            $this->enqueueDownlink($gwEui, $peer, $joinAccept, $dlTmstRx2, $rx2Freq, $rx2Datr, false);
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

    /**
     * Class A 下行：RX1 + 条件 RX2 双窗口入队（与 flushJoinBuffer 的 join 逻辑一致）。
     * RX2 仅当 RX1 空口时长 < RX1→RX2 间隔（留 20ms 余量）时才调度：
     * 长空口（如 SF12 ≈1.32s > 1s 间隔）下，RX2 会与 RX1 发射尾部重叠——网关虽判 COLLISION_PACKET
     * 不发射，但重叠调度可能损坏 RX1 尾部（含 MIC 末 4 字节）→ 设备收 RX1 但 MIC 校验失败。
     *
     * @return int RX1 窗口 tmst（µs，供日志使用）
     */
    private function enqueueClassADownlink(string $gwEui, string $peer, string $phy, int $ulTmst, Region $region, float $rx1Freq, string $rx1Datr): int
    {
        $rx1Tmst = $ulTmst + $region->getReceiveDelay1() * 1000;
        $this->enqueueDownlink($gwEui, $peer, $phy, $rx1Tmst, $rx1Freq, $rx1Datr, false);
        $gapUs = ($region->getReceiveDelay2() - $region->getReceiveDelay1()) * 1000; // RX1→RX2 间隔（默认 1s）
        $airtimeUs = $this->uplinkAirtimeUs($phy, $rx1Datr, $region);
        if ($airtimeUs <= $gapUs - 20000) {
            $rx2Tmst = $ulTmst + $region->getReceiveDelay2() * 1000;
            $this->enqueueDownlink($gwEui, $peer, $phy, $rx2Tmst, $region->getRx2Frequency() / 1e6, $region->drToDatr($region->getRx2DataRate()), false);
        } else {
            $this->log(sprintf(
                "CLASS A DOWNLINK RX2 SKIPPED (airtime=%.0fus >= gap=%.0fus, 避免与 RX1 发射尾部冲突导致 MIC 损坏)",
                $airtimeUs, $gapUs
            ));
        }
        return $rx1Tmst;
    }

    private function flushDownlink(string $gwEui, string $peer): void
    {
        if (!isset($this->gateways[$gwEui]) || empty($this->gateways[$gwEui]['pending'])) {
            return;
        }
        // Basic Station 网关：不走 UDP PULL_RESP，转 dnmsg 经 LNS sink 回送
        if (($this->gateways[$gwEui]['proto'] ?? '') === 'station') {
            $this->flushStationDownlink($gwEui);
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
                // 注意：$item['freq'] 是 MHz（enqueueDownlink 传入 getRx2Frequency()/1e6），用 MHz 比较
                'powe' => ($item['freq'] >= 869.4 && $item['freq'] <= 869.65) ? 29 : 16,
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

    /**
     * 把 pending 下行转成 Basic Station dnmsg 经注册的 sink 回送（LNS WebSocket）。
     * xtime 语义：imme（Class C）= 0 立即发送；否则 = 上行 xtime + delay（µs）。
     * 因 enqueueDownlink 的 tmst 已 = 上行 xtime + delay，此处直接取 tmst 即可。
     */
    private function flushStationDownlink(string $gwEui): void
    {
        $sink = $this->gateways[$gwEui]['dnSink'] ?? null;
        $regionName = $this->gateways[$gwEui]['region'] ?? ELW_DEFAULT_REGION;
        $region = Region::get($regionName);
        foreach ($this->gateways[$gwEui]['pending'] as $item) {
            $dn = Station::buildDnMsg([
                'diid'     => 1,
                'pdu'      => base64_encode($item['phy']),
                'freq'     => $item['freq'],
                'datr'     => $item['datr'],
                'region'   => $regionName,
                'rx_delay' => $region->getReceiveDelay1(),
            ], $item['imme'] ? 0 : ($item['tmst'] & 0xFFFFFFFF), 0, $item['imme'] ? 'C' : 'A');
            if ($sink !== null) {
                $sink($dn);
            }
            $this->log(sprintf(
                "STATION DNMSG gw=%s xtime=%d freq=%.3f datr=%s dC=%s phy=%s dn=%s",
                $gwEui, $dn['xtime'], $item['freq'], $item['datr'], $dn['dC'], bin2hex($item['phy']), json_encode($dn)
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

    /**
     * 构造网关协议原文日志（Join Request 上行，rxpk 解析结构）。
     * 对齐 ChirpStack 网关 JSON 格式：phy_payload.mhdr.f_type / payload / mic + tx_info + rx_info。
     * 用于 events.raw_json，让前端"JSON"看到的是原始报文而非系统摘要。
     */
    private function buildJoinRequestLog(string $phy, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, float $rxTime = 0): string
    {
        $jr = Frame::parseJoinRequest($phy);
        $mic = substr($phy, 19, 4);
        // datr 形如 "SF12BW125" / "SF10BW500"，解析出 SF / BW
        $sf = 0; $bw = 125000;
        if (preg_match('/SF(\d+)BW(\d+)/i', $datr, $m)) {
            $sf = (int) $m[1];
            $bw = (int) $m[2] * 1000;
        }
        // rx_info：网关侧接收元数据（Basic Station 有 gwTime/uplinkId/context；UDP 模式部分缺省）
        $rxInfo = [
            'gatewayId' => $gwEui,
            'uplinkId'  => (int) ($tmst & 0xFFFF),
            'gwTime'    => gmdate('Y-m-d\TH:i:s.u\Z', $rxTime ?: time()),
            'nsTime'    => gmdate('Y-m-d\TH:i:s.u\Z', time()),
            'rssi'      => $rssi,
            'snr'       => $lsnr,
            'channel'   => 0,
            'rfChain'   => 1,
            'location'  => (object) [],
            'context'   => base64_encode(substr($phy, 0, 4)),
            'crcStatus' => 'CRC_OK',
        ];
        return json_encode([
            'phy_payload' => [
                'mhdr' => [
                    'f_type' => 'JoinRequest',
                    'major'  => 'LoRaWANR1',
                ],
                'payload' => [
                    'join_eui' => bin2hex($jr['app_eui']),
                    'dev_eui'  => bin2hex($jr['dev_eui']),
                    'dev_nonce'=> unpack('v', $jr['dev_nonce'])[1],
                ],
                'mic' => array_values(unpack('C4', $mic)),
            ],
            'tx_info' => [
                'frequency' => (int) ($freq * 1e6),
                'modulation' => [
                    'lora' => [
                        'bandwidth' => $bw,
                        'spreadingFactor' => $sf ?: 12,
                        'codeRate' => 'CR_4_5',
                    ],
                ],
            ],
            'rx_info' => [$rxInfo],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * 构造网关协议原文日志（Join Accept 下行，txpk 结构）。
     * phy_payload.mhdr.f_type=JoinAccept + payload(hex) + mic + tx_info（含下行功率/时序/反转 IQ）。
     */
    private function buildJoinAcceptLog(string $phy, int $tmst, float $freq, string $datr, string $gwEui, float $rxTime = 0): string
    {
        $sf = 0; $bw = 125000;
        if (preg_match('/SF(\d+)BW(\d+)/i', $datr, $m)) {
            $sf = (int) $m[1];
            $bw = (int) $m[2] * 1000;
        }
        $mic = substr($phy, -4);
        return json_encode([
            'phy_payload' => [
                'mhdr' => [
                    'f_type' => 'JoinAccept',
                    'major'  => 'LoRaWANR1',
                ],
                'payload' => bin2hex($phy),
                'mic' => array_values(unpack('C4', $mic)),
            ],
            'tx_info' => [
                'frequency' => (int) ($freq * 1e6),
                'power'     => ($freq >= 869.4 && $freq <= 869.65) ? 29 : 16, // freq 单位为 MHz
                'modulation' => [
                    'lora' => [
                        'bandwidth' => $bw,
                        'spreadingFactor' => $sf ?: 12,
                        'codeRate' => 'CR_4_5',
                        'polarizationInversion' => true,
                    ],
                ],
                'timing' => [
                    'delay' => ['delay' => '5s'],
                ],
                'context' => base64_encode(substr($phy, 0, 4)),
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * 构造上行数据帧（Unconfirmed/Confirmed Data Up）的网关协议原文日志。
     * 结构对齐 ChirpStack 网关 JSON：phy_payload.mhdr.f_type / payload / mic + tx_info + rx_info。
     * 用于 uplinks.raw_json 与 events.raw_json，让前端"JSON"看到原始报文。
     */
    private function buildDataUpLog(string $phy, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, float $rxTime = 0): string
    {
        $p = Frame::parseDataUp($phy);
        $mtype = Frame::mtype($phy);
        $fType = ($mtype === Frame::MTYPE_CONFIRMED_UP) ? 'ConfirmedDataUp' : 'UnconfirmedDataUp';
        $sf = 0; $bw = 125000;
        if (preg_match('/SF(\d+)BW(\d+)/i', $datr, $m)) {
            $sf = (int) $m[1];
            $bw = (int) $m[2] * 1000;
        }
        return json_encode([
            'phy_payload' => [
                'mhdr' => ['f_type' => $fType, 'major' => 'LoRaWANR1'],
                'payload' => [
                    'dev_addr' => bin2hex($p['dev_addr']),
                    'fcnt'      => $p['fcnt_lo'],
                    'f_port'    => $p['fport'],
                    'frm_payload' => bin2hex($p['frmpayload']),
                    'confirmed' => ($mtype === Frame::MTYPE_CONFIRMED_UP) ? 1 : 0,
                ],
                'mic' => array_values(unpack('C4', $p['mic'])),
            ],
            'tx_info' => [
                'frequency' => (int) ($freq * 1e6),
                'modulation' => [
                    'lora' => [
                        'bandwidth' => $bw,
                        'spreadingFactor' => $sf ?: 12,
                        'codeRate' => 'CR_4_5',
                    ],
                ],
            ],
            'rx_info' => [[
                'gatewayId' => $gwEui,
                'uplinkId'  => (int) ($tmst & 0xFFFF),
                'gwTime'    => gmdate('Y-m-d\TH:i:s.u\Z', $rxTime ?: time()),
                'nsTime'    => gmdate('Y-m-d\TH:i:s.u\Z', time()),
                'rssi'      => $rssi,
                'snr'       => $lsnr,
                'channel'   => 0,
                'rfChain'   => 1,
                'location'  => (object) [],
                'context'   => base64_encode(substr($phy, 0, 4)),
                'crcStatus' => 'CRC_OK',
            ]],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * 构造下行数据帧（Unconfirmed/Confirmed Data Down）的 txpk 协议原文日志。
     * 用于 downlinks.raw_json，让前端"JSON"看到原始下行报文。
     */
    private function buildDataDownLog(string $phy, int $tmst, float $freq, string $datr, string $gwEui): string
    {
        $p = Frame::parseDataUp($phy); // 下行帧结构同 FHDR 布局，可复用解析
        $mtype = Frame::mtype($phy);
        $fType = ($mtype === Frame::MTYPE_CONFIRMED_DOWN) ? 'ConfirmedDataDown' : 'UnconfirmedDataDown';
        $sf = 0; $bw = 125000;
        if (preg_match('/SF(\d+)BW(\d+)/i', $datr, $m)) {
            $sf = (int) $m[1];
            $bw = (int) $m[2] * 1000;
        }
        return json_encode([
            'phy_payload' => [
                'mhdr' => ['f_type' => $fType, 'major' => 'LoRaWANR1'],
                'payload' => [
                    'dev_addr' => bin2hex($p['dev_addr']),
                    'fcnt'      => $p['fcnt_lo'],
                    'f_port'    => $p['fport'],
                    'frm_payload' => bin2hex($p['frmpayload']),
                    'confirmed' => ($mtype === Frame::MTYPE_CONFIRMED_DOWN) ? 1 : 0,
                ],
                'mic' => array_values(unpack('C4', $p['mic'])),
            ],
            'tx_info' => [
                'frequency' => (int) ($freq * 1e6),
                'power'     => ($freq >= 869.4 && $freq <= 869.65) ? 29 : 16, // freq 单位为 MHz
                'modulation' => [
                    'lora' => [
                        'bandwidth' => $bw,
                        'spreadingFactor' => $sf ?: 12,
                        'codeRate' => 'CR_4_5',
                        'polarizationInversion' => true,
                    ],
                ],
                'timing' => [
                    'delay' => ['delay' => '5s'],
                ],
                'context' => base64_encode(substr($phy, 0, 4)),
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
}
