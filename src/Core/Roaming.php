<?php
namespace holastack\Core;

use holastack\DB\Database;
use holastack\Crypto\AES;
use holastack\Crypto\LoRaWANCrypto;

/**
 * 漫游（Roaming, Backend Interface TS002）实现。
 *
 * 本模块承担「服务 NS（Serving NS）」角色：当本 NS 收到非本网 DevAddr 的上行 / 非本网设备
 * 的 Join 时，按 NetID 把报文转发给伙伴 Home NS（Passive Roaming），由 Home NS 决策。
 *
 * 实现结构：
 *  - CLIENTS：按伙伴 NetID 注册的 RoamingClient（含 TLS/KEK/签名/超时）；
 *  - isRoamingDevAddr / getNetIdsForDevAddr：基于本 NS NetID 前缀的 DevAddr 路由判定；
 *  - rxInfoToGwInfo / ulMetaDataToRxInfo / ulMetaDataToTxInfo / dlMetaDataToUplinkRxInfo：
 *    BI ULMetaData ↔ 网关 rx_info 双向转换（含 RecvTime → GPS 时间映射）；
 *  - buildJoinReq / buildXmitDataReq：构造 BI 1.0 报文并 AES-CMAC 签名；
 *  - forward：经 HTTPS POST（curl，支持 mTLS）转发，签名头 X-Downlink-Auth / X-Request-Id。
 *
 * 注意：本实现为「服务 NS 出站」为主，入站（JoinAns/PrUpdAns）由 bin/roaming-inbound.php
 * 接收后调用 Roaming::handleInboundAns() 注入本地下行管道（见 NetworkServer::scheduleRoamingDownlink）。
 */
class Roaming
{
    // BI 消息类型
    public const MSG_JOIN_REQ    = 'JoinReq';
    public const MSG_XMIT_DATA   = 'XmitDataReq';
    public const MSG_JOIN_ANS    = 'JoinAns';
    public const MSG_PR_UPD_ANS  = 'PrUpdAns';

    /** @var array<string, RoamingClient> 伙伴 NetID => Client（运行期注册表） */
    private static $clients = [];

    // ---------------- Client 注册（对齐 backend/roaming.rs::setup / set / get） ----------------

    public static function setup(): int
    {
        self::$clients = [];
        $rows = Database::fetchAll(
            "SELECT * FROM roaming_servers WHERE enabled=1"
        );
        $n = 0;
        foreach ($rows as $r) {
            $netId = strtoupper((string) ($r['net_id'] ?? ''));
            if ($netId === '' || $netId === '000000') {
                continue; // 本网 NetID 不应作为伙伴
            }
            self::$clients[$netId] = new RoamingClient([
                'net_id'        => $netId,
                'name'          => $r['name'],
                'server'        => $r['server'],
                'sender_id'     => self::localNsId(),
                'receiver_id'   => $netId,
                'kek_label'     => $r['kek_label'] ?? '',
                'ca_cert'       => $r['ca_cert'] ?? '',
                'tls_cert'      => $r['tls_cert'] ?? '',
                'tls_key'       => $r['tls_key'] ?? '',
                'authorization' => $r['authorization'] ?? '',
                'async_timeout' => (int) ($r['async_timeout'] ?? 250),
                'lifetime'      => (int) ($r['passive_roaming_lifetime'] ?? 0),
                'validate_mic'  => (int) ($r['validate_mic'] ?? 1) === 1,
            ]);
            $n++;
        }
        return $n;
    }

    public static function setClient(string $netId, RoamingClient $c): void
    {
        self::$clients[strtoupper($netId)] = $c;
    }

    public static function getClient(string $netId): ?RoamingClient
    {
        return self::$clients[strtoupper($netId)] ?? null;
    }

    public static function debugClients(): array
    {
        return array_keys(self::$clients);
    }

    /** 本 NS 标识（NetID，6 hex）。生产应在 config.php 定义 ELW_NET_ID。 */
    public static function localNsId(): string
    {
        return strtoupper(str_pad((string) (defined('ELW_NET_ID') ? ELW_NET_ID : '000000'), 6, '0', STR_PAD_LEFT));
    }

    public static function isEnabled(): bool
    {
        return defined('ELW_ROAMING_ENABLED') && ELW_ROAMING_ENABLED
            || count(self::$clients) > 0;
    }

    // ---------------- DevAddr 路由（对齐 backend/roaming.rs::is_roaming_dev_addr / get_net_ids_for_dev_addr） ----------------

    /**
     * 该 DevAddr 是否属于漫游（不属于本网 NetID 前缀，且非测试 NetID）。
     * @param string $devAddrBin 4 字节二进制 DevAddr
     */
    public static function isRoamingDevAddr(string $devAddrBin): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        if (strlen($devAddrBin) !== 4) {
            return false;
        }
        $netId = self::netIdFromDevAddr($devAddrBin);
        // 排除本网 NetID
        if ($netId === self::localNsId()) {
            return false;
        }
        // 排除测试 NetID（0x000000 / 0x000001），允许从测试 NetID 平滑切换到正式 NetID
        if ($netId === '000000' || $netId === '000001') {
            return false;
        }
        return true;
    }

    /**
     * 由 DevAddr 推导其归属 NetID（取最高 24 位 NwkID，即前 3 字节）。
     * DevAddr 在空口以大端（MSB 先）传输 4 字节；NwkID（24 位）占据最高 24 位 = 前 3 字节。
     */
    public static function netIdFromDevAddr(string $devAddrBin): string
    {
        $b = unpack('C4', $devAddrBin);
        return strtoupper(sprintf('%02X%02X%02X', $b[1], $b[2], $b[3]));
    }

    /**
     * 由 JoinEUI（AppEUI，8 字节）推导 Home NS 的伙伴 Client（被动漫游 Join 路由）。
     * LoRaWAN 1.1 的 JoinEUI 高位嵌有 NetID 路由信息；取最高 3 字节近似 NetID 查注册表。
     * 仅一个伙伴配置时兜底转发（便于单伙伴漫游联调）。无匹配返回 null。
     */
    public static function clientForJoinEui(string $appEuiBin): ?RoamingClient
    {
        if (!self::isEnabled()) {
            return null;
        }
        if (strlen($appEuiBin) !== 8) {
            return null;
        }
        $b = unpack('C8', $appEuiBin);
        $netId = strtoupper(sprintf('%02X%02X%02X', $b[8], $b[7], $b[6]));
        if (isset(self::$clients[$netId])) {
            return self::$clients[$netId];
        }
        if (count(self::$clients) === 1) {
            return array_values(self::$clients)[0];
        }
        return null;
    }

    /**
     * 返回该 DevAddr 匹配的伙伴 NetID 列表（基于注册表 + DevAddr 前缀）。
     * 若 DevAddr 直接命中某伙伴 NetID 前缀则返回该 NetID；否则返回全部已注册伙伴（兜底）。
     */
    public static function getNetIdsForDevAddr(string $devAddrBin): array
    {
        $out = [];
        $devNet = self::netIdFromDevAddr($devAddrBin);
        foreach (array_keys(self::$clients) as $netId) {
            if ($netId === $devNet) {
                $out[] = $netId;
            }
        }
        if (empty($out) && count(self::$clients) === 1) {
            // 仅一个伙伴时兜底转发
            $out = array_keys(self::$clients);
        }
        return $out;
    }

    // ---------------- 网关元数据 ↔ BI ULMetaData 转换（对齐 backend/roaming.rs） ----------------

    /**
     * 由网关上行 rx_info 构造 BI GWInfoElement 列表（ULMetaData.GWInfo）。
     * @param array $rxInfos 每条：['gw_id'=>hex,'rssi'=>int,'snr'=>float,'lat'=>?,'lon'=>?,'ul_token'=>?,'fine_recv_time'=>?]
     */
    public static function rxInfoToGwInfo(string $rfRegion, array $rxInfos): array
    {
        $out = [];
        foreach ($rxInfos as $rx) {
            $gwId = (string) ($rx['gw_id'] ?? '');
            $out[] = [
                'ID'        => substr($gwId, 4, 4), // 取后 4 hex（GWInfo.ID 为 DevAddr 风格 4 字节）
                'RSSI'      => (int) ($rx['rssi'] ?? 0),
                'SNR'       => (float) ($rx['snr'] ?? 0),
                'Lat'       => ($rx['lat'] ?? null),
                'Lon'       => ($rx['lon'] ?? null),
                'ULToken'   => $rx['ul_token'] ?? '',
                'RFRegion'  => $rfRegion,
                'DLAllowed' => true,
            ];
        }
        return $out;
    }

    /**
     * 由 BI ULMetaData.GWInfo 还原网关 rx_info（用于入站 JoinAns/PrUpdAns 下行定位）。
     * 仅返回首个网关（简化；多网关选 RSSI 最强）。
     */
    public static function ulMetaDataToRxInfo(array $gwInfos): ?array
    {
        if (empty($gwInfos)) {
            return null;
        }
        $best = null;
        foreach ($gwInfos as $g) {
            if ($best === null || (int) ($g['RSSI'] ?? -999) > (int) ($best['RSSI'] ?? -999)) {
                $best = $g;
            }
        }
        return $best;
    }

    // ---------------- BI 报文构造（BI 1.0，对齐 Roaming.php 旧骨架 + 规范字段） ----------------

    /**
     * 构造 JoinReq（BI 1.0）。
     * @param RoamingClient $client 目标伙伴
     * @param array $join ['phy'=>base64,'mac_version'=>str,'opt_neg'=>bool,'dev_eui'=>str,'dev_addr'=>str,'dl_settings'=>str,'rx_delay'=>int,'cf_list'=>str]
     */
    public static function buildJoinReq(RoamingClient $client, array $join): array
    {
        return [
            'SenderID'   => self::localNsId(),
            'ReceiverID' => $client->receiverId,
            'MessageType'=> self::MSG_JOIN_REQ,
            'PHYPayload' => $join['phy'] ?? '',
            'MACVersion' => $join['mac_version'] ?? '1.0.3',
            'OptNeg'     => (bool) ($join['opt_neg'] ?? false),
            'DevEUI'     => $join['dev_eui'] ?? '',
            'DevAddr'    => $join['dev_addr'] ?? '',
            'DLSettings' => $join['dl_settings'] ?? '',
            'RxDelay'    => (int) ($join['rx_delay'] ?? 1),
            'CFList'     => $join['cf_list'] ?? '',
        ];
    }

    /**
     * 构造 XmitDataReq（BI 1.0）。
     * @param RoamingClient $client 目标伙伴
     * @param array $ul ['phy'=>base64,'dev_eui'=>str,'dev_addr'=>str,'freq'=>float,'dr'=>str,
     *                   'recv_time'=>int(unix),'gw_id'=>str,'rssi'=>int,'snr'=>float,'ul_token'=>?,'fine_recv_time'=>?]
     */
    public static function buildXmitDataReq(RoamingClient $client, array $ul): array
    {
        $gwInfo = [[
            'ID'        => substr((string) ($ul['gw_id'] ?? ''), 4, 4),
            'RSSI'      => (int) ($ul['rssi'] ?? 0),
            'SNR'       => (float) ($ul['snr'] ?? 0),
            'Lat'       => ($ul['lat'] ?? null),
            'Lon'       => ($ul['lon'] ?? null),
            'ULToken'   => $ul['ul_token'] ?? '',
            'RFRegion'  => $ul['region'] ?? ELW_DEFAULT_REGION,
            'DLAllowed' => true,
        ]];
        return [
            'SenderID'   => self::localNsId(),
            'ReceiverID' => $client->receiverId,
            'MessageType'=> self::MSG_XMIT_DATA,
            'PHYPayload' => $ul['phy'] ?? '',
            'ULMetaData' => [
                'DevEUI'   => $ul['dev_eui'] ?? '',
                'DevAddr'  => $ul['dev_addr'] ?? '',
                'ULFreq'   => (float) ($ul['freq'] ?? 0),
                'DataRate' => $ul['dr'] ?? '',
                'RecvTime' => GpsTime::toGps((int) ($ul['recv_time'] ?? time())),
                'GWCnt'    => 1,
                'GWInfo'   => $gwInfo,
            ],
            'NumberOfTransmissions' => 1,
        ];
    }

    // ---------------- 签名（对齐 ChirpStack X-Downlink-Auth：AES-CMAC over 请求体） ----------------

    /**
     * 计算 BI 请求签名（AES-CMAC，16 字节；用于 X-Downlink-Auth 头）。
     * ChirpStack 以「共享 NS 根密钥」对 (SenderID|ReceiverID|MessageType|PHYPayload) 做 CMAC。
     * 此处签名密钥 = KEK（按 kek_label 查 roaming_keks），缺省为全零（演示）。
     * @return string 32 hex
     */
    public static function sign(RoamingClient $client, array $message): string
    {
        $kek = self::kekForLabel($client->kekLabel);
        $body = $client->senderId . $client->receiverId . $message['MessageType']
            . ($message['PHYPayload'] ?? '');
        return bin2hex(AES::cmac($kek, $body));
    }

    private static function kekForLabel(string $label): string
    {
        if ($label === '') {
            return str_repeat("\x00", 16);
        }
        $row = Database::fetch("SELECT kek FROM roaming_keks WHERE label=?", [$label]);
        if ($row && $row['kek'] !== '') {
            $b = hex2bin($row['kek']);
            if (strlen($b) === 16) {
                return $b;
            }
        }
        return str_repeat("\x00", 16);
    }

    /**
     * 校验伙伴 Home NS 入站报文的 AES-CMAC 签名（X-Downlink-Auth 头）。
     * body = SenderID(伙伴 NetID) | ReceiverID(本网 NetID) | MessageType | PHYPayload，密钥为伙伴共享 KEK。
     * 未知伙伴 → 返回 false（拒绝）；伙伴未配置 KEK（演示态）且未带签名 → 放行（仅联调用，生产必须配 KEK）。
     */
    public static function verifyInboundSignature(string $senderNetId, array $resp, string $authHex): bool
    {
        $client = self::getClient($senderNetId);
        if (!$client) {
            return false;
        }
        $kek = self::kekForLabel($client->kekLabel);
        $body = $senderNetId . ($resp['ReceiverID'] ?? '') . ($resp['MessageType'] ?? '') . ($resp['PHYPayload'] ?? '');
        $expected = bin2hex(AES::cmac($kek, $body));
        if ($authHex === '') {
            return $client->kekLabel === ''; // 演示态：无 KEK 且无签名 → 放行
        }
        return hash_equals($expected, strtolower($authHex));
    }

    // ---------------- 出站转发（对齐 backend/roaming.rs::Client + forward） ----------------

    /**
     * 把一条 BI 报文经 HTTPS POST 转发给伙伴 NS（支持 mTLS / authorization / 签名头）。
     * 返回伙伴 NS 的 JSON 应答（解码后数组），失败返回 ['error'=>...]。
     */
    public static function forward(RoamingClient $client, array $message): array
    {
        if (empty($client->server)) {
            return ['error' => 'server url empty'];
        }
        $body = json_encode($message);
        if ($body === false) {
            return ['error' => 'json encode failed'];
        }
        $signature = self::sign($client, $message);
        $requestId = bin2hex(random_bytes(8));

        $ch = curl_init($client->server);
        if ($ch === false) {
            return ['error' => 'curl init failed'];
        }
        $headers = [
            'Content-Type: application/json',
            'X-Downlink-Auth: ' . $signature,
            'X-Request-Id: ' . $requestId,
        ];
        if ($client->authorization !== '') {
            $headers[] = 'Authorization: ' . $client->authorization;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(2, (int) ceil($client->asyncTimeout / 1000) + 5),
            CURLOPT_CONNECTTIMEOUT=> 5,
        ]);
        // mTLS
        if ($client->tlsCert !== '' && $client->tlsKey !== '') {
            curl_setopt($ch, CURLOPT_SSLCERT, $client->tlsCert);
            curl_setopt($ch, CURLOPT_SSLKEY, $client->tlsKey);
        }
        if ($client->caCert !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $client->caCert);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 演示默认不校验（生产应配置 ca_cert）
        }
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['error' => 'http post failed: ' . $err];
        }
        $dec = json_decode($resp, true);
        return is_array($dec) ? $dec : ['raw' => $resp, 'http_code' => $httpCode];
    }

    // ---------------- 入站应答处理（JoinAns / PrUpdAns） ----------------

    /**
     * 处理伙伴 NS 返回的 JoinAns / PrUpdAns（由 bin/roaming-inbound.php 调用）。
     * 提取下行 PHYPayload，并依据相关表（roaming_pending）把下行调度回原服务网关。
     * @return array ['ok'=>bool,'phy'=>base64|'','gw_id'=>str,'ul_tmst'=>int,'region'=>str] 供 NetworkServer 下发
     */
    public static function handleInboundAns(array $resp): array
    {
        $type = $resp['MessageType'] ?? '';
        $phy = $resp['PHYPayload'] ?? '';
        if ($phy === '') {
            return ['ok' => false, 'error' => 'empty PHYPayload'];
        }
        // 用 DevEUI 或 DevAddr 关联 roaming_pending
        $devEui = strtolower($resp['DevEUI'] ?? '');
        $devAddr = strtolower($resp['DevAddr'] ?? '');
        $pending = null;
        if ($devEui !== '') {
            $pending = Database::fetch("SELECT * FROM roaming_pending WHERE dev_eui=? ORDER BY id DESC LIMIT 1", [$devEui]);
        }
        if (!$pending && $devAddr !== '') {
            $pending = Database::fetch("SELECT * FROM roaming_pending WHERE dev_addr=? ORDER BY id DESC LIMIT 1", [$devAddr]);
        }
        if (!$pending) {
            return ['ok' => false, 'error' => 'no pending correlation', 'phy' => $phy];
        }
        // 清理已消费的关联
        Database::execute("DELETE FROM roaming_pending WHERE id=?", [$pending['id']]);
        return [
            'ok'       => true,
            'type'     => $type,
            'phy'      => $phy,
            'gw_id'    => $pending['gw_id'],
            'peer'     => $pending['peer'],
            'ul_tmst'  => (int) $pending['ul_tmst'],
            'dl_delay' => (int) ($pending['dl_delay'] ?? 0),
            'region'   => $pending['region'],
            'freq'     => (float) $pending['freq'],
            'datr'     => $pending['datr'],
        ];
    }

    /** 记录一条待关联（用于入站下行回送）。$dlDelayMs 为服务 NS 侧应延迟下发的时间（Join=5000, 数据=RX1 delay*1000）。 */
    public static function rememberPending(string $kind, string $devEui, string $devAddr, string $gwId, string $peer, int $ulTmst, string $region, float $freq, string $datr, int $dlDelayMs): void
    {
        Database::execute(
            "INSERT INTO roaming_pending (kind, dev_eui, dev_addr, gw_id, peer, ul_tmst, region, freq, datr, dl_delay, created_at, expires_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $kind, strtolower($devEui), strtolower($devAddr), $gwId, $peer, $ulTmst, $region, $freq, $datr, $dlDelayMs,
                time(), time() + 60,
            ]
        );
    }
}

/**
 * 漫游伙伴客户端（对齐 ChirpStack backend::Client）。
 * 一个伙伴 NetID 对应一个 Client，携带传输与安全参数。
 */
class RoamingClient
{
    public $netId;
    public $name;
    public $server;
    public $senderId;
    public $receiverId;
    public $kekLabel;
    public $caCert;
    public $tlsCert;
    public $tlsKey;
    public $authorization;
    public $asyncTimeout; // ms
    public $lifetime;     // s（Passive Roaming lifetime）
    public $validateMic;

    public function __construct(array $cfg)
    {
        $this->netId        = $cfg['net_id'] ?? '000000';
        $this->name         = $cfg['name'] ?? '';
        $this->server       = $cfg['server'] ?? '';
        $this->senderId     = $cfg['sender_id'] ?? '000000';
        $this->receiverId   = $cfg['receiver_id'] ?? $this->netId;
        $this->kekLabel     = $cfg['kek_label'] ?? '';
        $this->caCert       = $cfg['ca_cert'] ?? '';
        $this->tlsCert      = $cfg['tls_cert'] ?? '';
        $this->tlsKey       = $cfg['tls_key'] ?? '';
        $this->authorization = $cfg['authorization'] ?? '';
        $this->asyncTimeout = (int) ($cfg['async_timeout'] ?? 250);
        $this->lifetime     = (int) ($cfg['lifetime'] ?? 0);
        $this->validateMic  = (bool) ($cfg['validate_mic'] ?? true);
    }
}
