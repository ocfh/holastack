<?php
namespace holastack\Core;

use holastack\DB\Database;
use holastack\Crypto\AES;
use holastack\Crypto\LoRaWANCrypto;



















class Roaming
{
    

    public const MSG_JOIN_REQ    = 'JoinReq';
    public const MSG_XMIT_DATA   = 'XmitDataReq';
    public const MSG_JOIN_ANS    = 'JoinAns';
    public const MSG_PR_UPD_ANS  = 'PrUpdAns';

    

    private static $clients = [];

    


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
                continue; 

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

    

    public static function localNsId(): string
    {
        return strtoupper(str_pad((string) (defined('ELW_NET_ID') ? ELW_NET_ID : '000000'), 6, '0', STR_PAD_LEFT));
    }

    public static function isEnabled(): bool
    {
        return defined('ELW_ROAMING_ENABLED') && ELW_ROAMING_ENABLED
            || count(self::$clients) > 0;
    }

    


    




    public static function isRoamingDevAddr(string $devAddrBin): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        if (strlen($devAddrBin) !== 4) {
            return false;
        }
        $netId = self::netIdFromDevAddr($devAddrBin);
        

        if ($netId === self::localNsId()) {
            return false;
        }
        

        if ($netId === '000000' || $netId === '000001') {
            return false;
        }
        return true;
    }

    




    public static function netIdFromDevAddr(string $devAddrBin): string
    {
        $b = unpack('C4', $devAddrBin);
        return strtoupper(sprintf('%02X%02X%02X', $b[1], $b[2], $b[3]));
    }

    





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
            

            $out = array_keys(self::$clients);
        }
        return $out;
    }

    


    




    public static function rxInfoToGwInfo(string $rfRegion, array $rxInfos): array
    {
        $out = [];
        foreach ($rxInfos as $rx) {
            $gwId = (string) ($rx['gw_id'] ?? '');
            $out[] = [
                'ID'        => substr($gwId, 4, 4), 

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
            return $client->kekLabel === ''; 

        }
        return hash_equals($expected, strtolower($authHex));
    }

    


    




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
        

        if ($client->tlsCert !== '' && $client->tlsKey !== '') {
            curl_setopt($ch, CURLOPT_SSLCERT, $client->tlsCert);
            curl_setopt($ch, CURLOPT_SSLKEY, $client->tlsKey);
        }
        if ($client->caCert !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $client->caCert);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

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

    


    





    public static function handleInboundAns(array $resp): array
    {
        $type = $resp['MessageType'] ?? '';
        $phy = $resp['PHYPayload'] ?? '';
        if ($phy === '') {
            return ['ok' => false, 'error' => 'empty PHYPayload'];
        }
        

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
    public $asyncTimeout; 

    public $lifetime;     

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
