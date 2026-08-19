<?php
namespace holastack\Core;

use holastack\DB\Database;
use holastack\Crypto\AES;




























class Relay
{
    

    public const CID_RELAY_CONF_REQ            = 0x40; 

    public const CID_END_DEVICE_CONF_REQ       = 0x41; 

    public const CID_FILTER_LIST_REQ           = 0x42; 

    public const CID_UPDATE_UPLINK_LIST_REQ    = 0x43; 

    public const CID_CTRL_UPLINK_LIST_REQ      = 0x44; 

    public const CID_CONFIGURE_FWD_LIMIT_REQ   = 0x45; 

    public const CID_NOTIFY_NEW_END_DEVICE_REQ = 0x46; 


    


    public static function listGateways(): array
    {
        return Database::fetchAll("SELECT * FROM relay_gateways ORDER BY id DESC");
    }

    public static function getGateway(int $id): ?array
    {
        return Database::fetch("SELECT * FROM relay_gateways WHERE id=?", [$id]);
    }

    public static function addGateway(array $p): array
    {
        if (empty($p['name']) || empty($p['relay_dev_eui'])) {
            return ['error' => 'name and relay_dev_eui required'];
        }
        Database::execute(
            "INSERT INTO relay_gateways (tenant_id, name, relay_dev_eui, region, created_at)
             VALUES (?,?,?,?,?)",
            [
                (int) ($p['tenant_id'] ?? 0),
                $p['name'],
                strtolower(preg_replace('/[^0-9a-fA-F]/', '', $p['relay_dev_eui'])),
                $p['region'] ?? ELW_DEFAULT_REGION,
                time(),
            ]
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function deleteGateway(int $id): void
    {
        Database::execute("DELETE FROM relay_devices WHERE relay_gateway_id=?", [$id]);
        Database::execute("DELETE FROM relay_gateways WHERE id=?", [$id]);
    }

    


    public static function listDevices(int $gatewayId): array
    {
        return Database::fetchAll(
            "SELECT * FROM relay_devices WHERE relay_gateway_id=? ORDER BY id DESC",
            [$gatewayId]
        );
    }

    







    public static function provisionEndDevice(int $gatewayId, string $devEui, array $keys = []): array
    {
        $gw = self::getGateway($gatewayId);
        if (!$gw) {
            return ['error' => 'relay gateway not found'];
        }
        $devEui = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $devEui));
        if (strlen($devEui) !== 16) {
            return ['error' => 'invalid dev_eui'];
        }
        $is11 = !empty($keys['mac_version']) && LoRaWANVersion::is1_1($keys['mac_version']);
        $existing = Database::fetch(
            "SELECT id FROM relay_devices WHERE relay_gateway_id=? AND dev_eui=?",
            [$gatewayId, $devEui]
        );
        $session = [
            'slot_index'               => (int) ($keys['slot_index'] ?? 0),
            'join_eui'                 => strtolower(preg_replace('/[^0-9a-fA-F]/', '', $keys['join_eui'] ?? '')),
            'root_wor_s_key'           => strtolower(preg_replace('/[^0-9a-fA-F]/', '', $keys['root_wor_s_key'] ?? '')),
            'provisioned'              => !empty($keys['provisioned']) ? 1 : 0,
            'uplink_limit_bucket_size' => (int) ($keys['uplink_limit_bucket_size'] ?? 0),
            'uplink_limit_reload_rate' => (int) ($keys['uplink_limit_reload_rate'] ?? 0),
        ];
        $cols = "relay_gateway_id=?, dev_eui=?, slot_index=?, join_eui=?, dev_addr=?, root_wor_s_key=?,
                 provisioned=?, uplink_limit_bucket_size=?, uplink_limit_reload_rate=?,
                 nwk_s_key=?, app_s_key=?, f_nwk_s_int_key=?, s_nwk_s_int_key=?, nwk_s_enc_key=?,
                 mac_version=?, created_at=?";
        $params = [
            $gatewayId, $devEui,
            $session['slot_index'], $session['join_eui'],
            $keys['dev_addr'] ?? '',
            $session['root_wor_s_key'],
            $session['provisioned'],
            $session['uplink_limit_bucket_size'],
            $session['uplink_limit_reload_rate'],
            $keys['nwk_s_key'] ?? '',
            $keys['app_s_key'] ?? '',
            $keys['f_nwk_s_int_key'] ?? '',
            $keys['s_nwk_s_int_key'] ?? '',
            $keys['nwk_s_enc_key'] ?? '',
            $is11 ? '1.1' : '1.0.3',
            time(),
        ];
        if ($existing) {
            $params[] = $existing['id'];
            Database::execute("UPDATE relay_devices SET $cols WHERE id=?", $params);
            return ['id' => $existing['id']];
        }
        Database::execute(
            "INSERT INTO relay_devices
             (relay_gateway_id, dev_eui, slot_index, join_eui, dev_addr, root_wor_s_key,
              provisioned, uplink_limit_bucket_size, uplink_limit_reload_rate,
              nwk_s_key, app_s_key, f_nwk_s_int_key, s_nwk_s_int_key, nwk_s_enc_key,
              mac_version, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            array_slice($params, 0, 16)
        );
        return ['id' => Database::lastInsertId()];
    }

    public static function removeEndDevice(int $id): void
    {
        Database::execute("DELETE FROM relay_devices WHERE id=?", [$id]);
    }

    


    





    public static function encodeFreq(int $freqHz): string
    {
        if ($freqHz >= 2400000000) {
            $freqHz = intdiv($freqHz, 2);
        }
        $v = intdiv($freqHz, 100) & 0xFFFFFF;
        return chr($v & 0xFF) . chr(($v >> 8) & 0xFF) . chr(($v >> 16) & 0xFF);
    }

    

    public static function decodeFreq(string $b3): int
    {
        if (strlen($b3) < 3) {
            return 0;
        }
        $v = ord($b3[0]) | (ord($b3[1]) << 8) | (ord($b3[2]) << 16);
        return $v >= 12000000 ? $v * 200 : $v * 100;
    }

    


    



    public static function uplinkMetadataFromBytes(string $b): array
    {
        if (strlen($b) < 3) {
            return ['dr' => 0, 'snr' => -20, 'rssi' => -15, 'wor_channel' => 0];
        }
        $b0 = ord($b[0]); $b1 = ord($b[1]); $b2 = ord($b[2]);
        return [
            'dr'          => $b0 & 0x0F,
            'snr'         => (($b0 >> 4) | (($b1 & 0x01) << 4)) - 20,
            'rssi'        => -(($b1 >> 1)) - 15,
            'wor_channel' => $b2 & 0x03,
        ];
    }

    






    public static function uplinkMetadataToBytes(int $dr, int $snr, int $rssi, int $worChannel): string
    {
        $snr = max(-20, min(11, $snr));
        $rssi = max(-142, min(-15, $rssi));
        $snrE = ($snr + 20) & 0x1F;
        $rssiE = (-($rssi + 15)) & 0x7F;
        $b0 = ($dr & 0x0F) | (($snrE << 4) & 0xF0);
        $b1 = (($snrE >> 4) & 0x01) | (($rssiE << 1) & 0xFE);
        $b2 = $worChannel & 0x03;
        return chr($b0) . chr($b1) . chr($b2);
    }

    


    

    public static function forwardUplinkReqToBytes(int $dr, int $snr, int $rssi, int $worChannel, int $freqHz, string $phyPayload): string
    {
        return self::uplinkMetadataToBytes($dr, $snr, $rssi, $worChannel)
             . self::encodeFreq($freqHz)
             . $phyPayload;
    }

    

    public static function forwardUplinkReqFromBytes(string $b): array
    {
        if (strlen($b) < 6) {
            return ['metadata' => null, 'frequency' => 0, 'payload' => ''];
        }
        return [
            'metadata'  => self::uplinkMetadataFromBytes(substr($b, 0, 3)),
            'frequency' => self::decodeFreq(substr($b, 3, 3)),
            'payload'   => substr($b, 6),
        ];
    }

    

    public static function forwardDownlinkReqToBytes(string $phyPayload): string
    {
        return $phyPayload;
    }

    public static function forwardDownlinkReqFromBytes(string $b): string
    {
        return $b;
    }

    


    



    public static function channelSettingsFromBytes(string $b): array
    {
        if (strlen($b) < 2) {
            return ['start_stop' => 0, 'cad_periodicity' => 0, 'default_ch_idx' => 0, 'second_ch_idx' => 0, 'second_ch_dr' => 0, 'second_ch_ack_offset' => 0];
        }
        $b0 = ord($b[0]); $b1 = ord($b[1]);
        return [
            'second_ch_ack_offset' => $b0 & 0x07,
            'second_ch_dr'         => ($b0 & 0x78) >> 3,
            'second_ch_idx'        => (($b0 & 0x80) >> 7) | (($b1 & 0x01) << 1),
            'default_ch_idx'       => ($b1 & 0x02) >> 1,
            'cad_periodicity'      => ($b1 & 0x1C) >> 2,
            'start_stop'           => ($b1 & 0x20) >> 5,
        ];
    }

    public static function channelSettingsToBytes(int $ackOffset, int $dr, int $idx, int $defaultIdx, int $cadPeriodicity, int $startStop): string
    {
        $b0 = ($ackOffset & 0x07) | (($dr & 0x0F) << 3) | (($idx & 0x01) << 7);
        $b1 = (($idx >> 1) & 0x01) | (($defaultIdx & 0x01) << 1) | (($cadPeriodicity & 0x07) << 2) | (($startStop & 0x01) << 5);
        return chr($b0) . chr($b1);
    }

    


    




    public static function buildRelayConfReq(
        int $secondChAckOffset,
        int $secondChDr,
        int $secondChIdx,
        int $defaultChIdx,
        int $cadPeriodicity,
        int $startStop,
        int $secondChFreqHz
    ): string {
        return chr(self::CID_RELAY_CONF_REQ)
             . self::channelSettingsToBytes($secondChAckOffset, $secondChDr, $secondChIdx, $defaultChIdx, $cadPeriodicity, $startStop)
             . self::encodeFreq($secondChFreqHz);
    }

    




    public static function decodeRelayConfReq(string $payload): array
    {
        if (strlen($payload) < 5) {
            return ['channel_settings' => self::channelSettingsFromBytes(''), 'second_ch_freq' => 0];
        }
        return [
            'channel_settings' => self::channelSettingsFromBytes(substr($payload, 0, 2)),
            'second_ch_freq'   => self::decodeFreq(substr($payload, 2, 3)),
        ];
    }

    




    public static function decodeRelayConfAns(string $payload): array
    {
        $b = ord($payload[0] ?? "\x00");
        return [
            'second_ch_freq_ack'      => ($b & 0x01) !== 0,
            'second_ch_ack_offset_ack'=> ($b & 0x02) !== 0,
            'second_ch_dr_ack'        => ($b & 0x04) !== 0,
            'second_ch_idx_ack'       => ($b & 0x08) !== 0,
            'default_ch_idx_ack'      => ($b & 0x10) !== 0,
            'cad_periodicity_ack'     => ($b & 0x20) !== 0,
        ];
    }

    


    
















    public static function scheduleConf(array &$device, array $rp): string
    {
        $out = '';
        $rs = json_decode($device['relay_state'] ?? '', true);
        if (!is_array($rs)) {
            $rs = []; 

        }
        

        $cur = [
            'enabled'                   => (bool) ($rs['enabled'] ?? false),
            'cad_periodicity'           => (int) ($rs['cad_periodicity'] ?? 0),
            'default_channel_index'     => (int) ($rs['default_channel_index'] ?? 0),
            'second_channel_freq'       => (int) ($rs['second_channel_freq'] ?? 0),
            'second_channel_dr'         => (int) ($rs['second_channel_dr'] ?? 0),
            'second_channel_ack_offset' => (int) ($rs['second_channel_ack_offset'] ?? 0),
            'ed_activation_mode'        => (int) ($rs['ed_activation_mode'] ?? 0),
            'ed_smart_enable_level'     => (int) ($rs['ed_smart_enable_level'] ?? 0),
            'ed_back_off'               => (int) ($rs['ed_back_off'] ?? 0),
        ];

        if (!empty($rp['is_relay'])) {
            $want = [
                'enabled'                   => !empty($rp['relay_enabled']),
                'cad_periodicity'           => (int) ($rp['relay_cad_periodicity'] ?? 0),
                'default_channel_index'     => (int) ($rp['default_channel_index'] ?? 0),
                'second_channel_freq'       => (int) ($rp['second_channel_freq'] ?? 0),
                'second_channel_dr'         => (int) ($rp['second_channel_dr'] ?? 0),
                'second_channel_ack_offset' => (int) ($rp['second_channel_ack_offset'] ?? 0),
            ];
            if ($cur['enabled'] !== $want['enabled']
                || $cur['cad_periodicity'] !== $want['cad_periodicity']
                || $cur['default_channel_index'] !== $want['default_channel_index']
                || $cur['second_channel_freq'] !== $want['second_channel_freq']
                || $cur['second_channel_dr'] !== $want['second_channel_dr']
                || $cur['second_channel_ack_offset'] !== $want['second_channel_ack_offset']) {
                $req = self::buildRelayConfReq(
                    $want['second_channel_ack_offset'],
                    $want['second_channel_dr'],
                    $want['second_channel_freq'] > 0 ? 1 : 0, 

                    $want['default_channel_index'],
                    $want['cad_periodicity'],
                    $want['enabled'] ? 1 : 0,                 

                    $want['second_channel_freq']
                );
                $out .= $req;
                MacCommands::setPending($device, MacCommands::CID_RELAY_CONF_REQ, $req);
            }
        }

        if (!empty($rp['is_relay_ed'])) {
            $want = [
                'ed_activation_mode'        => (int) ($rp['ed_activation_mode'] ?? 0),
                'ed_smart_enable_level'     => (int) ($rp['ed_smart_enable_level'] ?? 0),
                'ed_back_off'               => (int) ($rp['ed_back_off'] ?? 0),
                'second_channel_freq'       => (int) ($rp['second_channel_freq'] ?? 0),
                'second_channel_dr'         => (int) ($rp['second_channel_dr'] ?? 0),
                'second_channel_ack_offset' => (int) ($rp['second_channel_ack_offset'] ?? 0),
            ];
            if ($cur['ed_activation_mode'] !== $want['ed_activation_mode']
                || $cur['ed_smart_enable_level'] !== $want['ed_smart_enable_level']
                || $cur['ed_back_off'] !== $want['ed_back_off']
                || $cur['second_channel_freq'] !== $want['second_channel_freq']
                || $cur['second_channel_dr'] !== $want['second_channel_dr']
                || $cur['second_channel_ack_offset'] !== $want['second_channel_ack_offset']) {
                $req = self::buildEndDeviceConfReq(
                    $want['ed_activation_mode'],
                    $want['ed_smart_enable_level'],
                    $want['second_channel_ack_offset'],
                    $want['second_channel_dr'],
                    $want['second_channel_freq'] > 0 ? 1 : 0, 

                    $want['ed_back_off'],
                    $want['second_channel_freq']
                );
                $out .= $req;
                MacCommands::setPending($device, MacCommands::CID_END_DEVICE_CONF_REQ, $req);
            }
        }
        return $out;
    }

    









    public static function handleRelayConfAns(array &$device, string $ansPayload): array
    {
        $ans = self::decodeRelayConfAns($ansPayload);
        $pending = MacCommands::getPending($device, self::CID_RELAY_CONF_REQ);
        if ($pending === null) {
            

            return ['bytes' => null, 'mustRespond' => false, 'acked' => false];
        }
        

        $reqPayload = substr($pending, 1);
        $req = self::decodeRelayConfReq($reqPayload);
        $cs = $req['channel_settings'];

        $allAck = $ans['second_ch_freq_ack'] && $ans['second_ch_ack_offset_ack'] && $ans['second_ch_dr_ack']
            && $ans['second_ch_idx_ack'] && $ans['default_ch_idx_ack'] && $ans['cad_periodicity_ack'];

        if ($allAck) {
            $device['relay_state'] = json_encode([
                'enabled'                => $cs['start_stop'] === 1,
                'cad_periodicity'        => $cs['cad_periodicity'],
                'default_channel_index'  => $cs['default_ch_idx'],
                'second_channel_freq'    => $req['second_ch_freq'],
                'second_channel_dr'      => $cs['second_ch_dr'],
                'second_channel_ack_offset' => $cs['second_ch_ack_offset'],
            ]);
        }
        MacCommands::clearPending($device, self::CID_RELAY_CONF_REQ);
        return ['bytes' => null, 'mustRespond' => false, 'acked' => $allAck];
    }

    


    

    public const FL_ACTION_NO_RULE = 0;
    public const FL_ACTION_FORWARD = 1;
    public const FL_ACTION_FILTER  = 2;

    





    public static function buildFilterListReq(int $filterListIdx, int $action, array $euiBytes): string
    {
        $len = count($euiBytes);
        $b0 = ($len & 0x17) | (($action & 0x03) << 5) | (($filterListIdx & 0x0F) << 7);
        $b1 = ($filterListIdx >> 1) & 0x07;
        $out = chr(self::CID_FILTER_LIST_REQ) . chr($b0) . chr($b1);
        

        foreach (array_reverse($euiBytes) as $b) {
            $out .= chr($b & 0xFF);
        }
        return $out;
    }

    

    public static function decodeFilterListAns(string $payload): array
    {
        $b = ord($payload[0] ?? "\x00");
        return [
            'filter_list_action_ack' => ($b & 0x01) !== 0,
            'filter_list_len_ack'    => ($b & 0x02) !== 0,
            'combined_rules_ack'     => ($b & 0x04) !== 0,
        ];
    }

    








    public static function handleFilterListAns(array &$device, string $ansPayload): array
    {
        $ans = self::decodeFilterListAns($ansPayload);
        $pending = MacCommands::getPending($device, self::CID_FILTER_LIST_REQ);
        if ($pending === null) {
            return ['bytes' => null, 'mustRespond' => false, 'acked' => false];
        }
        

        $reqPayload = substr($pending, 1);
        $b0 = ord($reqPayload[0] ?? "\x00");
        $idx = (($b0 & 0x80) >> 7) | ((ord($reqPayload[1] ?? "\x00") & 0x07) << 1);
        $action = ($b0 & 0x60) >> 5;

        $allAck = $ans['filter_list_action_ack'] && $ans['filter_list_len_ack'] && $ans['combined_rules_ack'];
        if ($allAck) {
            $rs = json_decode($device['relay_state'] ?? '', true);
            if (!is_array($rs)) {
                $rs = [];
            }
            $filters = $rs['filters'] ?? [];
            if (!is_array($filters)) {
                $filters = [];
            }
            foreach ($filters as &$f) {
                if ((int) ($f['index'] ?? -1) === $idx) {
                    $f['provisioned'] = true;
                }
            }
            unset($f);
            

            if ($action === self::FL_ACTION_NO_RULE) {
                $filters = array_values(array_filter($filters, fn ($f) => (int) ($f['index'] ?? -1) !== $idx));
            }
            $rs['filters'] = $filters;
            $device['relay_state'] = json_encode($rs);
        }
        MacCommands::clearPending($device, self::CID_FILTER_LIST_REQ);
        return ['bytes' => null, 'mustRespond' => false, 'acked' => $allAck];
    }

    


    

    public const RMA_DISABLE = 0;
    public const RMA_ENABLE  = 1;
    public const RMA_DYNAMIC = 2;
    public const RMA_ED_CTRL = 3;

    





    public static function buildEndDeviceConfReq(
        int $relayModeActivation,
        int $smartEnableLevel,
        int $secondChAckOffset,
        int $secondChDr,
        int $secondChIdx,
        int $backoff,
        int $secondChFreqHz
    ): string {
        $b0 = ($relayModeActivation & 0x03) << 2 | ($smartEnableLevel & 0x03);
        $cs0 = ($secondChAckOffset & 0x07) | (($secondChDr & 0x0F) << 3) | (($secondChIdx & 0x01) << 7);
        $cs1 = (($secondChIdx >> 1) & 0x01) | (($backoff & 0x3F) << 1);
        return chr(self::CID_END_DEVICE_CONF_REQ) . chr($b0) . chr($cs0) . chr($cs1) . self::encodeFreq($secondChFreqHz);
    }

    

    public static function decodeEndDeviceConfAns(string $payload): array
    {
        $b = ord($payload[0] ?? "\x00");
        return [
            'second_ch_freq_ack' => ($b & 0x01) !== 0,
            'second_ch_dr_ack'   => ($b & 0x02) !== 0,
            'second_ch_idx_ack'  => ($b & 0x04) !== 0,
            'backoff_ack'        => ($b & 0x08) !== 0,
        ];
    }

    










    public static function handleEndDeviceConfAns(array &$device, string $ansPayload): array
    {
        $ans = self::decodeEndDeviceConfAns($ansPayload);
        $pending = MacCommands::getPending($device, self::CID_END_DEVICE_CONF_REQ);
        if ($pending === null) {
            return ['bytes' => null, 'mustRespond' => false, 'acked' => false];
        }
        

        $reqPayload = substr($pending, 1);
        $actMode = ord($reqPayload[0] ?? "\x00");
        $cs0 = ord($reqPayload[1] ?? "\x00");
        $cs1 = ord($reqPayload[2] ?? "\x00");
        $freq = self::decodeFreq(substr($reqPayload, 3, 3));

        $allAck = $ans['second_ch_freq_ack'] && $ans['second_ch_dr_ack']
            && $ans['second_ch_idx_ack'] && $ans['backoff_ack'];

        if ($allAck) {
            $rs = json_decode($device['relay_state'] ?? '', true);
            if (!is_array($rs)) {
                $rs = [];
            }
            $rs['ed_activation_mode']        = ($actMode >> 2) & 0x03;
            $rs['ed_smart_enable_level']     = $actMode & 0x03;
            $rs['second_channel_ack_offset'] = $cs0 & 0x07;
            $rs['second_channel_dr']         = ($cs0 & 0x78) >> 3;
            $rs['ed_back_off']               = ($cs1 >> 1) & 0x3F;
            $rs['second_channel_freq']       = $freq;
            $device['relay_state'] = json_encode($rs);
        }
        MacCommands::clearPending($device, self::CID_END_DEVICE_CONF_REQ);
        return ['bytes' => null, 'mustRespond' => false, 'acked' => $allAck];
    }
}
