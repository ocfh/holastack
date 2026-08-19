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









class NetworkServer
{
    private $sock;
    private $port;
    private $gateways = [];   

    private $running = true;
    private $lastDlCheck = 0; 

    private $joinBuf = [];    

    private $uplinkBuf = [];   

    private $uplinkRxSets = []; 

                               

                               

    private $lastBeaconGps = 0; 

    private $beaconMacVersion = '1.0.3'; 


    

    private const FUOTA_FRAMES_PER_TICK = 2;
    

    private const FUOTA_SETUP_RESEND_INTERVAL = 30;
    

    private const FUOTA_SETUP_MAX_SECONDS = 120;

    

    

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
            

            $read = [$this->sock];
            

            

            

            

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

    

    private function runSafe(string $name, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->log("SCHED WARN $name 异常（已隔离，不影响其他调度）: " . $e->getMessage());
        }
    }

    


    private function handlePacket(string $data, string $peer, float $rxTime = 0): void
    {
        if (strlen($data) < 4) {
            return;
        }
        $version = $data[0]; 

        $token = substr($data, 1, 2);
        $id = ord($data[3]);
        $gwEui = (strlen($data) >= 12) ? bin2hex(substr($data, 4, 8)) : '';
        

        if ($gwEui !== '') {
            $this->gateways[$gwEui] = $this->gateways[$gwEui] ?? [];
            $this->gateways[$gwEui]['version'] = $version;
        }

        switch ($id) {
            case 0x00: 

                $this->sendAck(0x01, $token, $peer, $gwEui);
                $json = json_decode(substr($data, 12), true);
                if (is_array($json)) {
                    $this->handlePush($json, $peer, $gwEui, $rxTime);
                }
                break;
            case 0x02: 

                $this->sendAck(0x04, $token, $peer, $gwEui);
                $prevAddr = $this->gateways[$gwEui]['addr'] ?? '';
                $this->registerGateway($peer, $gwEui);
                

                if ($prevAddr !== $peer) {
                    $this->log("PULL_DATA gw=$gwEui peer=$peer（下行通道" . ($prevAddr === '' ? '首次登记' : '地址更新') . "）");
                }
                

                $this->gateways[$gwEui]['pull_token'] = $token;
                $this->flushDownlink($gwEui, $peer);
                break;
            case 0x05: 

                $ackJson = json_decode(substr($data, 12), true);
                $ackStatus = $ackJson['txpk_ack']['error'] ?? 'ok';
                $this->log("TX_ACK gw=$gwEui status=$ackStatus");
                

                

                

                

                

                

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

    


    private function handlePush(array $json, string $peer, string $gwEui, float $rxTime = 0): void
    {
        

        

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

        

        

        

        

        if ($tmst > 0 && $gwEui !== '') {
            $this->gateways[$gwEui] = $this->gateways[$gwEui] ?? [];
            if (!isset($this->gateways[$gwEui]['addr'])) {
                $this->gateways[$gwEui]['addr'] = $peer; 

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
            

            $revDevEui = bin2hex(strrev($jr['dev_eui']));
            $device = Database::fetch(
                "SELECT * FROM devices WHERE dev_eui=? AND activation='OTAA'",
                [$revDevEui]
            );
        }
        $t3 = microtime(true);
        if (!$device) {
            

            if (Roaming::isEnabled() && $this->tryRoamingJoin($phy, $jr, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer)) {
                return; 

            }
            $this->log("JOIN: unknown device devEUI=$devEui appEUI=$appEUI");
            $this->logEvent('join', 'error', "Join Request：未知设备 devEUI=$devEui appEUI=$appEui", $gwEui, 0, 0, $this->buildJoinRequestLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
            return;
        }
        

        $dp = DeviceProfile::getOrDefault((int) $device['device_profile_id']);
        $macVersion = LoRaWANVersion::value($dp['mac_version'] ?? '1.0.3');
        $is11 = LoRaWANVersion::is1_1($macVersion);
        $appKey = hex2bin($device['app_key']);
        

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

        

        $micKey = bin2hex($is11
            ? LoRaWANCrypto::joinRequestMIC1_1($nwkKey, substr($phy, 0, -4))
            : LoRaWANCrypto::joinRequestMIC($appKey, substr($phy, 0, -4)));

        if (!isset($this->joinBuf[$micKey])) {
            

            $appNonce = random_bytes(3);
            $netId = random_bytes(3);
            $devAddr = $this->generateDevAddr();
            

            

            $rx1DrOffset = (int) ($device['rx1_dr_offset'] ?? 0) & 0x07;
            $rx2Dr = (int) ($device['rx2_dr'] ?? 0) & 0x0F;
            $dlSettings = ($rx1DrOffset << 4) | $rx2Dr;
            $rxDelay = (int) ($device['rx_delay'] ?? 1) & 0x0F;
            $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
            $cfList = $region->getCfList(); 

            $t6 = microtime(true);
            if ($is11) {
                

                

                [$fNwkSIntKey, $sNwkSIntKey, $nwkSEncKey, $appSKey] = LoRaWANCrypto::computeSessionKeys1_1(
                    $nwkKey, $appKey, $appNonce, $jr['app_eui'], $jr['dev_nonce']
                );
                $joinAccept = Frame::buildJoinAccept1_1($nwkKey, $appNonce, $netId, $devAddr, $dlSettings, $rxDelay, $cfList);
            } else {
                [$nwkSKey, $appSKey] = LoRaWANCrypto::computeSessionKeys($appKey, $appNonce, $netId, $jr['dev_nonce']);
                $joinAccept = Frame::buildJoinAccept($appKey, $appNonce, $netId, $devAddr, $dlSettings, $rxDelay, $cfList);
            }

            

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
            

            $region = $this->joinBuf[$micKey]['region'];
            $joinAccept = $this->joinBuf[$micKey]['joinAccept'];
            $t6 = $t5;
        }

        

        

        $this->bufferJoinDownlink($micKey, $region, $joinAccept, $tmst, $freq, $datr, $rssi, $gwEui, $peer, $device['id'] ?? 0, $device['app_id'] ?? 0);
    }

    private function generateDevAddr(): string
    {
        do {
            $addr = random_bytes(4);
        } while (($addr[0] & "\xFE") === "\x00" || ($addr[0] & "\xFE") === "\xFE");
        return $addr;
    }

    






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

    


    private function handleDataUp(string $phy, int $mtype, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, string $peer, float $rxTime = 0): void
    {
        $p = Frame::parseDataUp($phy);
        $devAddrHex = bin2hex($p['dev_addr']);

        

        

        

        $nowU = microtime(true);
        foreach ($this->uplinkBuf as $k => $t) {
            if ($nowU - $t > 1.0) {
                unset($this->uplinkBuf[$k]);
                unset($this->uplinkRxSets[$k]);
            }
        }
        $dupKey = $devAddrHex . ':' . (int) ($p['fcnt_lo'] ?? 0);
        

        

        

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
            

            if ($this->tryRoamingDataUp($phy, $p, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $peer)) {
                return; 

            }
            $this->log("DATA UP: unknown devAddr=$devAddrHex");
            $this->logEvent('uplink', 'error', "上行：未知设备 devAddr=$devAddrHex", $gwEui, 0, 0, $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
            return;
        }
        Database::execute("UPDATE devices SET last_gw_id=? WHERE id=?", [$gwEui, $device['id']]);
        $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
        $ks = $this->deviceKeySet($device);

        $fcnt = Frame::fullFCnt($p['fcnt_lo'], (int) $device['fcnt_up']);
        

        $micOk = ($ks['family'] === '1.1')
            ? Frame::verifyDataMIC1_1($ks['fNwkSIntKey'], $ks['sNwkSIntKey'], $p['dev_addr'], $fcnt, $p['data_without_mic'], $p['mic'],
                (int) ($region->datrToDr($datr) ?? 0), 0)
            : Frame::verifyDataMIC($ks['nwkSKey'], 0, $p['dev_addr'], $fcnt, $p['data_without_mic'], $p['mic']);
        if (!$micOk) {
            $this->log("DATA UP: MIC failed devAddr=$devAddrHex fcnt=$fcnt (mac_version={$ks['mac_version']})");
            return;
        }

        

        if ($fcnt <= (int) $device['fcnt_up']) {
            $this->log("DATA UP: old/duplicate fcnt=$fcnt (last=" . $device['fcnt_up'] . ")");
            $this->logEvent('uplink', 'warn', "上行：重复/过期帧 devAddr=$devAddrHex fcnt=$fcnt", $gwEui, $device['id'], $device['app_id'], $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
            return;
        }

        

        $decrypted = '';
        if ($p['fport'] !== null && $p['frmpayload'] !== '') {
            if ($ks['family'] === '1.1') {
                $decrypted = Frame::decryptFRMPayload1_1($ks['nwkSEncKey'], $ks['appSKey'], 0, $p['dev_addr'], $fcnt, $p['fport'], $p['frmpayload']);
            } else {
                $key = ($p['fport'] === 0) ? $ks['nwkSKey'] : $ks['appSKey'];
                $decrypted = Frame::decryptFRMPayload($key, 0, $p['dev_addr'], $fcnt, $p['frmpayload']);
            }
        }

        

        $this->handleFuotaAppPayload($device, $p['fport'] ?? null, $decrypted, $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));

        Database::execute(
            "UPDATE devices SET fcnt_up=?, last_seen=? WHERE id=?",
            [$fcnt, time(), $device['id']]
        );
        

        $device['last_seen'] = time();

        

        

        

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

        

        

        

        try {
            $telemetry = $this->captureTelemetry($device, $p, $decrypted); 

            $mac = $this->processMacAndAdr($device, $region, $tmst, $freq, $datr, $lsnr, $fcnt, $p, $decrypted);
            $this->persistDeviceMacState($device);
        } catch (\Throwable $e) {
            $this->log("WARN uplink MAC/telemetry error devAddr=$devAddrHex class={$device['class']} mac_version={$ks['mac_version']}: " . $e->getMessage());
            $this->logEvent('uplink', 'warn', "上行 MAC/遥测处理异常（已跳过，通知仍下发）devAddr=$devAddrHex: " . $e->getMessage(), $gwEui, $device['id'], $device['app_id'], $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
            $telemetry = [];
            $mac = ['fopts' => '', 'port0' => ''];
        }

        

        $statusEvent = false;
        if (isset($device['mac_telemetry']) && is_array($device['mac_telemetry'])) {
            foreach (['battery', 'margin'] as $k) {
                if (array_key_exists($k, $device['mac_telemetry'])) {
                    $telemetry[$k] = $device['mac_telemetry'][$k];
                }
            }
            $statusEvent = true; 

        }

        

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
        

        $this->fireCallback($device['app_id'], $uplinkData + $telemetry);
        

        

        Integration::dispatch($device['app_id'], $device, $uplinkData, $telemetry, function (string $m): void {
            $this->log($m);
        });

        

        if (!empty($p['ack'])) {
            $this->acknowledgeDownlinks($device, $gwEui, $uplinkData, $telemetry, $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
        }
        

        if ($statusEvent) {
            Integration::dispatch($device['app_id'], $device, $uplinkData, $telemetry, function (string $m): void {
                $this->log($m);
            }, 'status');
            $this->logEvent('status', 'info',
                "设备状态更新 dev#{$device['id']} battery=" . var_export($telemetry['battery'] ?? null, true)
                . " margin=" . ($telemetry['margin'] ?? ''),
                $gwEui, $device['id'], $device['app_id'], $this->buildDataUpLog($phy, $tmst, $freq, $datr, $rssi, $lsnr, $gwEui, $rxTime));
        }

        

        $macConsumed = false;
        if ($mtype === Frame::MTYPE_CONFIRMED_UP) {
            $downPhy = $this->buildDownFrame(
                $ks, $p['dev_addr'], (int) $device['fcnt_down'] + 1,
                false, true, null, '', (bool) $device['adr'], $mac['fopts'], $mac['port0'], $fcnt
            );
            $this->bumpDownFCnt($device['id']);
            

            $this->enqueueDownlink($gwEui, $peer, $downPhy, $tmst + $region->getReceiveDelay1() * 1000, $freq, $datr, false);
            $macConsumed = true;
        }

        

        $this->dispatchPendingAppDownlinks($device, $region, $tmst, $freq, $datr, $gwEui, $peer, $p['dev_addr'], $ks, $mac, $macConsumed);

        

        

        

        

        if (!empty($p['adr_ack_req']) && $mtype !== Frame::MTYPE_CONFIRMED_UP && $macConsumed === false) {
            $this->log("ADRACKReq: dev#{$device['id']} devAddr=$devAddrHex 回空 ACK 下行 (ADR ack)");
            

            $downPhy = $this->buildDownFrame(
                $ks, $p['dev_addr'], (int) $device['fcnt_down'] + 1,
                false, false, null, '', (bool) $device['adr'], '', '', $fcnt
            );
            $this->bumpDownFCnt($device['id']);
            $rx1Tmst = $this->enqueueClassADownlink($gwEui, $peer, $downPhy, $tmst, $region, $freq, $datr);
            $this->logEvent('downlink', 'info', "ADRACKReq 应答：空 ACK 下行 dev#{$device['id']} (ADR ack, Class A RX1/RX2)", $gwEui, $device['id'], $device['app_id'], $this->buildDataDownLog($downPhy, $rx1Tmst, $freq, $datr, $gwEui));
        }
    }

    


    





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
        

        Roaming::rememberPending('join', bin2hex($jr['dev_eui']), '', $gwEui, $peer, $tmst, ELW_DEFAULT_REGION, $freq, $datr, 5000);
        $resp = Roaming::forward($client, $msg);
        if (isset($resp['error'])) {
            $this->log("ROAMING JOIN forward failed appEUI=" . bin2hex($jr['app_eui']) . ": " . $resp['error']);
            return false;
        }
        $this->log("ROAMING JoinReq devEUI=" . bin2hex($jr['dev_eui']) . " -> " . $client->receiverId . " queued (awaiting JoinAns)");
        return true;
    }

    




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
        $dlDelay = (int) ($region->getReceiveDelay1() * 1000); 

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
            

            

            $macCarried = (!$macConsumed) && (($mac['fopts'] ?? '') !== '');
            $fopts = $macCarried ? $mac['fopts'] : '';
            $downPhy = $this->buildDownFrame($ks, $devAddrBin, $fcntDown, $confirmed, false, (int) $dl['port'], $payload, (bool) $device['adr'], $fopts, '');
            $this->bumpDownFCnt($device['id']);
            

            $dlRawJson = $this->buildDataDownLog($downPhy, $tmst, $freq, $datr, $gwEui);
            if ($classC) {
                

                

                

                

                [$dlFreq, $dlDatr, $mode] = $this->enqueueClassCDownlink($device, $region, $downPhy, $tmst, $freq, $datr, $gwEui, $peer);
                $dlRawJson = $this->buildDataDownLog($downPhy, $tmst, $dlFreq, $dlDatr, $gwEui);
                $modeDesc = ($mode === 'a-windows') ? 'RX1/2' : 'imme';
                $this->log("APP DOWNLINK -> dev_id={$device['id']} port={$dl['port']} (Class C $modeDesc)");
                $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} port={$dl['port']} (Class C $modeDesc)", $gwEui, $device['id'], $device['app_id'], $dlRawJson);
            } else {
                

                $rx1Tmst = $this->enqueueClassADownlink($gwEui, $peer, $downPhy, $tmst, $region, $freq, $datr);
                $this->log("APP DOWNLINK -> dev_id={$device['id']} port={$dl['port']}");
                $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} port={$dl['port']} (Class A RX1/RX2)", $gwEui, $device['id'], $device['app_id'], $dlRawJson);
            }
            Database::execute("UPDATE downlinks SET status='sent', fcnt=?, sent_at=?, raw_json=? WHERE id=?", [$fcntDown, time(), $dlRawJson, $dl['id']]);
            $macConsumed = $macConsumed || $macCarried;
        }

        

        if (!$macConsumed) {
            $this->sendMacOnlyDownlink($device, $region, $tmst, $freq, $datr, $gwEui, $peer, $devAddrBin, $ks, $mac);
        }
    }

    


    







    private function processMacAndAdr(array &$device, Region $region, int $tmst, float $freq, string $datr, float $lsnr, int $fcnt, array $p, string $decrypted): array
    {
        

        $macBytes = $p['fopts'] ?? '';
        if (($p['fport'] ?? null) === 0 && $decrypted !== '') {
            $macBytes .= $decrypted;
        }
        

        

        if ($macBytes !== '') {
            $macBytes = $this->handleFuotaMacUplink($device, $macBytes);
        }
        $fopts = '';
        if ($macBytes !== '') {
            $cmds = MacCommands::parse($macBytes);
            if (!empty($cmds)) {
                $uplink = [
                    'snr'    => $lsnr,
                    'dr'     => $region->datrToDr($datr) ?? (int) $device['dr'], 

                    'region' => $region,
                    'freq'   => $freq,
                    'rx_set' => $this->uplinkRxSets[bin2hex($p['dev_addr']) . ':' . (int) ($p['fcnt_lo'] ?? 0)] ?? null,
                ];
                $res = MacCommands::handleUplink($device, $region, $uplink, $cmds);
                $fopts = implode('', $res['responses']);
                

                foreach ($res['responses'] as $rb) {
                    if (!empty($rb) && $rb[0] === chr(MacCommands::CID_DEVICE_TIME_ANS)) {
                        $this->log(sprintf(
                            "DEVTIME: dev#%d 应答 DeviceTimeReq（device_time_valid=1, GPS秒=%d）",
                            $device['id'], (int) ($device['device_time'] ?? 0)
                        ));
                    }
                    if (!empty($rb) && $rb[0] === chr(MacCommands::CID_LINK_CHECK_ANS)) {
                        

                        $this->log(sprintf(
                            "LINKCHECK: dev#%d 应答 LinkCheckAns margin=%d gw_cnt=%d (上行DR=%s)",
                            $device['id'], ord($rb[1] ?? "\x00"), ord($rb[2] ?? "\x00"), $datr
                        ));
                    }
                }
            }
        }

        

        

        

        

        

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

        

        $history = json_decode($device['uplink_adr_history'] ?? '[]', true) ?: [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = ['f_cnt' => $fcnt, 'max_snr' => $lsnr, 'tx_power_index' => (int) $device['tx_power_index']];
        if (count($history) > 32) {
            $history = array_slice($history, -32);
        }
        $device['uplink_adr_history'] = json_encode($history);

        

        if (!empty($device['adr'])) {
            $maxTx = (int) ($device['max_supported_tx_power_index'] ?? 0);
            if ($maxTx <= 0) {
                $maxTx = 7; 

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
            $ch = range(0, 15); 

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
            

            

            [$dlFreq, $dlDatr, $mode] = $this->enqueueClassCDownlink($device, $region, $downPhy, $tmst, $freq, $datr, $gwEui, $peer);
            $modeDesc = ($mode === 'a-windows') ? '刚上行，改走 RX1/RX2 窗口' : 'RXC imme';
            $this->log("MAC DOWNLINK -> dev_id={$device['id']} (Class C $modeDesc)");
            $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} (Class C $modeDesc)", $gwEui, $device['id'], $device['app_id'], $this->buildDataDownLog($downPhy, $tmst, $dlFreq, $dlDatr, $gwEui));
        } else {
            $rx1Tmst = $this->enqueueClassADownlink($gwEui, $peer, $downPhy, $tmst, $region, $freq, $datr);
            $this->log("MAC DOWNLINK -> dev_id={$device['id']} (Class A RX1/RX2)");
            $this->logEvent('downlink', 'info', "下行下发 dev_id={$device['id']} (MAC-only, Class A RX1/RX2)", $gwEui, $device['id'], $device['app_id'], $this->buildDataDownLog($downPhy, $rx1Tmst, $freq, $datr, $gwEui));
        }
    }

    











    private function enqueueClassCDownlink(array $device, Region $region, string $downPhy, int $ulTmst, float $ulFreq, string $ulDatr, string $gwEui, string $peer): array
    {
        $dlFreq = (int) ($device['rx2_frequency'] ?? 0) > 0 ? (int) $device['rx2_frequency'] / 1e6 : $region->getRx2Frequency() / 1e6;
        $dlDatr = $region->drToDatr((int) ($device['rx2_dr'] ?? 0) > 0 ? (int) $device['rx2_dr'] : $region->getRx2DataRate());
        $sinceUp = time() - (int) ($device['last_seen'] ?? 0);
        $airtimeUs = $this->uplinkAirtimeUs($downPhy, $dlDatr, $region);
        $rx1DelayS = $region->getReceiveDelay1() / 1000.0;
        if ($sinceUp >= 0 && $sinceUp < 2.5 && ($sinceUp + $airtimeUs / 1e6) > $rx1DelayS + 0.05) {
            

            $this->enqueueClassADownlink($gwEui, $peer, $downPhy, $ulTmst, $region, $ulFreq, $ulDatr);
            return [$dlFreq, $dlDatr, 'a-windows'];
        }
        

        $this->enqueueDownlink($gwEui, $peer, $downPhy, 0, $dlFreq, $dlDatr, true);
        return [$dlFreq, $dlDatr, 'c-imme'];
    }

    private function bumpDownFCnt(int $devId): void
    {
        Database::execute("UPDATE devices SET fcnt_down = fcnt_down + 1 WHERE id=?", [$devId]);
    }

    






    private function acknowledgeDownlinks(array $device, string $gwEui, array $uplinkData, array $telemetry, string $rawJson = ''): void
    {
        $dl = Database::fetch(
            "SELECT * FROM downlinks WHERE dev_id=? AND confirmed=1 AND status='sent' AND acknowledged_at=0 ORDER BY id DESC LIMIT 1",
            [$device['id']]
        );
        if (!$dl) {
            

            $this->log("DATA UP ACK bit set dev#{$device['id']} (无待确认下行)");
            return;
        }
        Database::execute(
            "UPDATE downlinks SET status='acknowledged', acknowledged_at=? WHERE id=?",
            [time(), $dl['id']]
        );
        $this->log("DOWNLINK ACKED dev#{$device['id']} downlink#{$dl['id']} fcnt={$dl['fcnt']}");
        $this->logEvent('ack', 'info', "下行被设备确认 dev#{$device['id']} downlink#{$dl['id']} fcnt={$dl['fcnt']}", $gwEui, $device['id'], $device['app_id'], $rawJson);
        

        $ackData = $uplinkData + ['downlink_id' => (int) $dl['id'], 'downlink_fcnt' => (int) $dl['fcnt']];
        Integration::dispatch($device['app_id'], $device, $ackData, $telemetry, function (string $m): void {
            $this->log($m);
        }, 'ack');
    }

    







    private function captureTelemetry(array $device, array $p, string $decryptedBin): array
    {
        $upd = [];
        $params = [];
        $telemetry = ['battery' => null, 'margin' => null, 'latitude' => null, 'longitude' => null, 'altitude' => null];
        $fport = $p['fport'] ?? null;

        

        


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

        

        $bandwidth = 0;
        $sf = 0;
        if (preg_match('/SF(\d+)\s*BW\s*(\d+)/i', $data['datr'] ?? '', $m)) {
            $sf = (int) $m[1];
            $bandwidth = (int) $m[2] * 1000;
        }
        $ts = (int) ($data['received_at'] ?? time());
        

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
            

            stream_set_timeout($fp, 2);
            $resp = @fread($fp, 512);
            $status = $resp ? trim(strtok($resp, "\r\n")) : 'no-response';
            $this->log("CALLBACK: POST app#$appId -> $status url=$url");
        }
        @fclose($fp);
    }

    


    




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
                continue; 

            }
            $this->sendDeviceDownlink($device, $dl, true);
        }
    }

    


    









    private function processBeaconScheduler(): void
    {
        

        $hasB = Database::fetch("SELECT 1 FROM devices WHERE class='B' LIMIT 1");
        if (!$hasB) {
            return;
        }
        $gpsNow = MacCommands::gpsSecondsNow();
        $nextBeaconGps = (int) ceil($gpsNow / Beacon::BEACON_PERIOD) * Beacon::BEACON_PERIOD;
        if ($nextBeaconGps <= $this->lastBeaconGps) {
            return; 

        }
        $beaconUnix = $this->gpsToUnix($nextBeaconGps);
        $dt = $beaconUnix - time();
        if ($dt > self::BEACON_SCHEDULE_LEAD || $dt < -2) {
            return; 

        }
        $this->lastBeaconGps = $nextBeaconGps;

        $gwSpecific = str_repeat("\x00", 7); 


        $gateways = $this->collectBeaconGateways(); 

        $total = 0;
        foreach ($gateways as $regionName => $gws) {
            $region = Region::get($regionName ?: ELW_DEFAULT_REGION);
            

            

            $beaconPhy = Beacon::buildFrame(
                $nextBeaconGps,
                $gwSpecific,
                $this->beaconMacVersion,
                $region->getBeaconRfu1(),
                $region->getBeaconRfu2()
            );
            

            $freq = $region->getBeaconChannelFrequency($nextBeaconGps) / 1e6;
            $datr = $region->drToDatr($region->getBeaconDataRate());
            foreach ($gws as $gw) {
                $gwEui = $gw['gw_id'];
                $peer = $this->gateways[$gwEui]['addr'] ?? '';
                if ($peer === '') {
                    continue; 

                }
                $ref = $this->gateways[$gwEui]['c_ref'] ?? null;
                if (is_array($ref) && (time() - (int) ($ref['host'] ?? 0)) <= self::BEACON_REF_MAX_AGE) {
                    

                    $deltaUs = ($beaconUnix * 1000000) - (int) ($ref['host_us'] ?? 0);
                    $tmst = ((int) ($ref['tmst'] ?? 0) + $deltaUs) & 0xFFFFFFFF;
                    $imme = false;
                } else {
                    

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

    



    private function gpsToUnix(int $gpsSec): int
    {
        return (int) ($gpsSec + GpsTime::GPS_EPOCH_UNIX - GpsTime::LEAP_SECONDS);
    }

    

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
                

                Database::execute("UPDATE downlinks SET status='pending' WHERE id=?", [$dl['id']]);
                $this->log("RETX downlink#{$dl['id']} dev#{$dl['dev_id']} -> pending (Class A/B, tx=$tx/$nbTrans)");
                $this->logEvent('downlink', 'warn', "下行重传排队 dev#{$dl['dev_id']} downlink#{$dl['id']} (Class A/B, tx=$tx/$nbTrans)", '', $dl['dev_id'], $dl['app_id'], $dl['raw_json'] ?? '');
            }
        }
    }
    








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
        

        $baseUnix = $now + ($nextBeacon - $gpsNow);
        $slotUnix = $baseUnix + ($pingOffset30 * 30) / 1000.0;
        while ($slotUnix < $now) {
            $slotUnix += $period;
        }
        return (int) ceil($slotUnix);
    }

    private function sendDeviceDownlink(array $device, array $dl, bool $imme): void
    {
        

        

        $gwEui = $this->resolveServingGateway($device);
        if ($gwEui === '') {
            $this->log("SCHED DOWNLINK SKIP -> dev_id={$device['id']} class={$device['class']} port={$dl['port']} (无可用服务网关，等待网关保活)");
            return;
        }
        $region = Region::get($device['region'] ?: ELW_DEFAULT_REGION);
        

        

        if ($imme && ($device['class'] ?? '') === 'C') {
            $sinceUp = time() - (int) ($device['last_seen'] ?? 0);
            if ($sinceUp >= 0 && $sinceUp < 2.5) {
                $this->log("SCHED DOWNLINK SKIP -> dev_id={$device['id']} class=C port={$dl['port']} (等RXC, sinceUp={$sinceUp}s)");
                return; 

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

    




    private function resolveServingGateway(array $device): string
    {
        $last = $device['last_gw_id'] ?? '';
        if ($last !== '' && isset($this->gateways[$last])) {
            return $last;
        }
        $appId = (int) ($device['app_id'] ?? 0);
        $region = $device['region'] ?? '';
        

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
        

        $any = Database::fetch("SELECT gw_id FROM gateways WHERE last_seen >= ? ORDER BY last_seen DESC LIMIT 1", [time() - 600]);
        return $any ? $any['gw_id'] : '';
    }

    


    





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
            $group = $q; 

            $phy = Multicast::buildDownlink($group, (int) $q['f_port'], $q['payload_hex']);
            $region = Region::get($group['region'] ?: ELW_DEFAULT_REGION);
            $freq = ((int) $group['frequency'] > 0) ? ((int) $group['frequency'] / 1e6) : ($region->getRx2Frequency() / 1e6);
            $datr = $region->drToDatr((int) $group['dr']);
            $imme = (strtoupper($group['group_type']) === 'C');
            $tmst = $imme ? 0 : (int) (microtime(true) * 1000) % 1000000000;

            $gws = Multicast::groupGateways((int) $q['multicast_group_id']);
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
            

            Database::execute("UPDATE multicast_groups SET f_cnt = f_cnt + 1 WHERE id=?", [$q['multicast_group_id']]);
            Database::execute("DELETE FROM multicast_queue WHERE id=?", [$q['id']]);
        $this->log("MULTICAST DOWNLINK -> group={$q['multicast_group_id']} port={$q['f_port']} gw=" . count($gws));
        $this->logEvent('downlink', 'info', "组播下行下发 group={$q['multicast_group_id']} port={$q['f_port']}", '', 0, 0, $this->buildDataDownLog($phy, $tmst, $freq, $datr, $gwEui ?? ''));
    }
}













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
            continue; 

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
    

    $delay = (int) $camp['min_delay'] * count($frames);
    $delay = random_int($delay, max($delay, (int) $camp['max_delay'] * count($frames)));
    Database::execute(
        "UPDATE fuota_campaigns SET frames_sent=?, next_frame_at=?, updated_at=? WHERE id=?",
        [$sent, $now + $delay, $now, $campId]
    );
    $this->log("FUOTA: campaign#$campId sent " . count($frames) . " frame(s) (total $sent/{$camp['total_frames']})");
}



private function fuotaStatusPhase(array $camp, int $now): void
{
    $campId = (int) $camp['id'];
    $group = Database::fetch("SELECT * FROM multicast_groups WHERE id=?", [(int) $camp['multicast_group_id']]);
    if (!$group) {
        Fuota::finalizeCampaign($campId);
        return;
    }
    if ((int) $camp['status_req_sent'] === 0) {
        

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




    




















    private function uplinkAirtimeUs(string $phy, $datr, Region $region): int
    {
        $sf = 0;
        $bw = 0;
        if (preg_match('/SF(\d+)BW(\d+)/i', (string) $datr, $m)) {
            $sf = (int) $m[1];
            $bw = (int) $m[2] * 1000; 

        } elseif (is_numeric($datr)) {
            $d = $region->getDataRate((int) $datr);
            $sf = $d['sf'];
            $bw = $d['bw'] * 1000;
        }
        if ($sf <= 0 || $bw <= 0) {
            

            $this->log("WARN uplinkAirtimeUs: 无法解析 datr=" . var_export($datr, true) . "，回退 SF12BW125（下行时序可能为最保守估计）");
            $sf = 12;
            $bw = 125000;
        }
        $pl = strlen($phy);                 

        

        $coderate = 1;                      

        $preambleLen = 8;                   

        $fixLen = false;                    

        $crcOn = true;                      

        $crDenom = $coderate + 4;          

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
        

        $numerator = (4 * $intermediate + 1) * (1 << ($sf - 2));
        

        $toaMs = (int) ceil((1000 * $numerator) / $bw);
        return $toaMs * 1000;
    }

    






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
                'flushedTmst' => 0, 

                'devId'      => $devId,
                'appId'      => $appId,
            ];
        } else {
            $e = &$this->joinBuf[$micKey];
            

            

            

            if ($tmst > $e['bestTmst'] || ($tmst === $e['bestTmst'] && $rssi > $e['bestRssi'])) {
                $e['bestRssi'] = $rssi;
                $e['bestTmst'] = $tmst;
                $e['bestFreq'] = $freq;
                $e['bestDatr'] = $datr;
            }
        }
    }

    




    private function flushJoinBuffer(): void
    {
        $now = microtime(true);
        foreach ($this->joinBuf as $micKey => $e) {
            if ($e['scheduled']) {
                if ($now - $e['firstSeen'] > 10) {
                    unset($this->joinBuf[$micKey]); 

                    continue;
                }
                

                

                

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
                continue; 

            }
            $this->scheduleJoinRx1Rx2($e, 'first');
            $this->joinBuf[$micKey]['scheduled'] = true;
            $this->joinBuf[$micKey]['flushedTmst'] = $e['bestTmst'];
        }
    }

    




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

        

        $dlTmstRx1 = $tmst + $region->getJoinAcceptDelay1() * 1000;
        $this->log(sprintf(
            "JOIN DOWNLINK RX1%s: gw=%s ul_tmst=%d delay=%dms dl_tmst_rx1=%d RX1freq=%.3f RX1datr=%s (dedup rssi=%d)",
            $tag, $gwEui, $tmst, $region->getJoinAcceptDelay1(), $dlTmstRx1, $freq, $datr, $e['bestRssi']
        ));
        if ($reason !== 'resched') {
            

            $this->logEvent('join', 'info', "Join Accept 下行下发 RX1 gw=$gwEui freq=" . sprintf('%.3f', $freq) . " datr=$datr", $gwEui, $e['devId'] ?? 0, $e['appId'] ?? 0, $this->buildJoinAcceptLog($joinAccept, $dlTmstRx1, $freq, $datr, $gwEui));
        }
        $this->enqueueDownlink($gwEui, $peer, $joinAccept, $dlTmstRx1, $freq, $datr, false);

        

        

        

        

        

        $dlGapUs = ($region->getJoinAcceptDelay2() - $region->getJoinAcceptDelay1()) * 1000; 

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
        

        if (isset($this->gateways[$gwEui]['addr'])) {
            $this->flushDownlink($gwEui, $this->gateways[$gwEui]['addr']);
        }
    }

    








    private function enqueueClassADownlink(string $gwEui, string $peer, string $phy, int $ulTmst, Region $region, float $rx1Freq, string $rx1Datr): int
    {
        $rx1Tmst = $ulTmst + $region->getReceiveDelay1() * 1000;
        $this->enqueueDownlink($gwEui, $peer, $phy, $rx1Tmst, $rx1Freq, $rx1Datr, false);
        $gapUs = ($region->getReceiveDelay2() - $region->getReceiveDelay1()) * 1000; 

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
                

                

                

                'powe' => ($item['freq'] >= 869.4 && $item['freq'] <= 869.65) ? 29 : 16,
                'modu' => 'LORA',
                'datr' => $item['datr'],
                'codr' => '4/5',
                'ipol' => true,   

                'size' => strlen($item['phy']),
                'data' => base64_encode($item['phy']),
            ];
            $json = json_encode(['txpk' => $txpk]);
            

            

            

            

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
            

        }
        $this->log("[$type/$level] $message");
    }

    





    private function buildJoinRequestLog(string $phy, int $tmst, float $freq, string $datr, int $rssi, float $lsnr, string $gwEui, float $rxTime = 0): string
    {
        $jr = Frame::parseJoinRequest($phy);
        $mic = substr($phy, 19, 4);
        

        $sf = 0; $bw = 125000;
        if (preg_match('/SF(\d+)BW(\d+)/i', $datr, $m)) {
            $sf = (int) $m[1];
            $bw = (int) $m[2] * 1000;
        }
        

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
                'power'     => ($freq >= 869.4 && $freq <= 869.65) ? 29 : 16, 

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

    




    private function buildDataDownLog(string $phy, int $tmst, float $freq, string $datr, string $gwEui): string
    {
        $p = Frame::parseDataUp($phy); 

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
                'power'     => ($freq >= 869.4 && $freq <= 869.65) ? 29 : 16, 

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
