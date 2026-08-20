<?php
namespace holastack\Core;

use holastack\Region\Region;















class MacCommands
{
    

    public const CID_LINK_CHECK_REQ        = 0x02;
    public const CID_LINK_ADR_REQ          = 0x03;
    public const CID_DUTY_CYCLE_REQ        = 0x04;
    public const CID_RX_PARAM_SETUP_REQ    = 0x05;
    public const CID_DEV_STATUS_REQ         = 0x06;
    public const CID_NEW_CHANNEL_REQ        = 0x07;
    public const CID_RX_TIMING_SETUP_REQ    = 0x08;
    public const CID_TX_PARAM_SETUP_REQ     = 0x09;
    public const CID_DL_CHANNEL_REQ         = 0x0A;
    public const CID_REKEY_IND              = 0x0B;
    public const CID_ADR_PARAM_SETUP_REQ    = 0x0C;
    public const CID_DEVICE_TIME_REQ        = 0x0D;
    public const CID_FORCE_REJOIN_REQ       = 0x0E;
    public const CID_REJOIN_PARAM_SETUP_REQ = 0x0F;
    public const CID_PING_SLOT_INFO_REQ     = 0x10;
    public const CID_PING_SLOT_CHANNEL_REQ  = 0x11;
    public const CID_BEACON_TIMING_REQ      = 0x12;
    public const CID_BEACON_FREQ_REQ        = 0x13;
    public const CID_DEVICE_MODE_IND        = 0x20;
    public const CID_RESET_IND              = 0x21;
    public const CID_RELAY_CONF_REQ         = 0x40; 

    public const CID_END_DEVICE_CONF_REQ    = 0x41; 

    public const CID_FILTER_LIST_REQ        = 0x42; 

    public const CID_UPDATE_UPLINK_LIST_REQ = 0x43; 

    public const CID_CTRL_UPLINK_LIST_REQ   = 0x44; 

    public const CID_CONFIGURE_FWD_LIMIT_REQ= 0x45; 

    public const CID_NOTIFY_NEW_END_DEVICE_REQ = 0x46; 


    

    public const CID_LINK_CHECK_ANS        = 0x02;
    public const CID_LINK_ADR_ANS          = 0x03;
    public const CID_DUTY_CYCLE_ANS        = 0x04;
    public const CID_RX_PARAM_SETUP_ANS    = 0x05;
    public const CID_DEV_STATUS_ANS        = 0x06;
    public const CID_NEW_CHANNEL_ANS       = 0x07;
    public const CID_RX_TIMING_SETUP_ANS   = 0x08;
    public const CID_TX_PARAM_SETUP_ANS    = 0x09;
    public const CID_DL_CHANNEL_ANS        = 0x0A;
    public const CID_REKEY_CONF            = 0x0B;
    public const CID_ADR_PARAM_SETUP_ANS   = 0x0C;
    public const CID_DEVICE_TIME_ANS       = 0x0D;
    public const CID_REJOIN_PARAM_SETUP_ANS= 0x0F;
    public const CID_PING_SLOT_INFO_ANS    = 0x10;
    public const CID_PING_SLOT_CHANNEL_ANS = 0x11;
    public const CID_BEACON_TIMING_ANS     = 0x12;
    public const CID_BEACON_FREQ_ANS       = 0x13;
    public const CID_DEVICE_MODE_CONF      = 0x20;
    public const CID_RESET_CONF            = 0x21;

    

    private const GPS_EPOCH_OFFSET = 315964800;
    

    

    private const GPS_LEAP_SECONDS = 18;

    public static function parse(string $bytes): array
    {
        $out = [];
        $i = 0;
        $n = strlen($bytes);
        while ($i < $n) {
            $cid = ord($bytes[$i]);
            $i++;
            

            $len = self::cmdLen($cid);
            if ($len < 0) {
                

                break;
            }
            $payload = substr($bytes, $i, $len);
            $i += $len;
            $out[] = ['cid' => $cid, 'payload' => $payload];
        }
        return $out;
    }

    public static function cmdLen(int $cid): int
    {
        switch ($cid) {
            case self::CID_LINK_CHECK_REQ:        return 0;
            case self::CID_LINK_ADR_REQ:          return 4;
            case self::CID_LINK_ADR_ANS:          return 1;
            case self::CID_DUTY_CYCLE_REQ:        return 1;
            case self::CID_DUTY_CYCLE_ANS:        return 0;
            case self::CID_RX_PARAM_SETUP_REQ:    return 4;
            case self::CID_RX_PARAM_SETUP_ANS:    return 1;
            case self::CID_DEV_STATUS_REQ:         return 0;
            case self::CID_DEV_STATUS_ANS:         return 2;
            case self::CID_NEW_CHANNEL_REQ:        return 5;
            case self::CID_NEW_CHANNEL_ANS:        return 1;
            case self::CID_RX_TIMING_SETUP_REQ:    return 1;
            case self::CID_RX_TIMING_SETUP_ANS:    return 0;
            case self::CID_TX_PARAM_SETUP_REQ:     return 1;
            case self::CID_TX_PARAM_SETUP_ANS:     return 0;
            case self::CID_DL_CHANNEL_REQ:         return 4;
            case self::CID_DL_CHANNEL_ANS:         return 1;
            case self::CID_REKEY_IND:              return 1;
            case self::CID_REKEY_CONF:             return 1;
            case self::CID_ADR_PARAM_SETUP_REQ:    return 1;
            case self::CID_ADR_PARAM_SETUP_ANS:    return 0;
            case self::CID_DEVICE_TIME_REQ:        return 0;
            case self::CID_DEVICE_TIME_ANS:        return 5;
            case self::CID_FORCE_REJOIN_REQ:       return 2;
            case self::CID_REJOIN_PARAM_SETUP_REQ: return 2;
            case self::CID_REJOIN_PARAM_SETUP_ANS: return 1;
            case self::CID_PING_SLOT_INFO_REQ:     return 1;
            case self::CID_PING_SLOT_INFO_ANS:     return 0;
            case self::CID_PING_SLOT_CHANNEL_REQ:  return 4;
            case self::CID_PING_SLOT_CHANNEL_ANS:  return 1;
            case self::CID_BEACON_TIMING_REQ:      return 0;
            case self::CID_BEACON_TIMING_ANS:      return 3;
            case self::CID_BEACON_FREQ_REQ:        return 3;
            case self::CID_BEACON_FREQ_ANS:        return 1;
            case self::CID_DEVICE_MODE_IND:        return 1;
            case self::CID_DEVICE_MODE_CONF:       return 1;
            case self::CID_RESET_IND:              return 1;
            case self::CID_RESET_CONF:             return 1;
            case self::CID_RELAY_CONF_REQ:         return 1; 

            case self::CID_END_DEVICE_CONF_REQ:    return 1; 

            case self::CID_FILTER_LIST_REQ:        return 1; 

            case self::CID_UPDATE_UPLINK_LIST_REQ: return 1; 

            case self::CID_CTRL_UPLINK_LIST_REQ:   return 1; 

            case self::CID_CONFIGURE_FWD_LIMIT_REQ:return 1; 

            default:                               return -1;
        }
    }

    


    public static function buildLinkADRReq(int $dr, int $txPower, int $chMask, int $chMaskCntl, int $nbRep): string
    {
        $drTx = (($dr & 0x0F) << 4) | ($txPower & 0x0F);
        $redundancy = (($chMaskCntl & 0x07) << 4) | ($nbRep & 0x0F);
        return chr(self::CID_LINK_ADR_REQ)
            . chr($drTx)
            . pack('v', $chMask & 0xFFFF)
            . chr($redundancy);
    }

    public static function buildRXParamSetupReq(Region $region, int $rx1DrOffset, int $rx2Dr): string
    {
        $dlSettings = (($rx1DrOffset & 0x07) << 4) | ($rx2Dr & 0x0F);
        $freq = (int) ($region->getRx2Frequency() / 100); 

        return chr(self::CID_RX_PARAM_SETUP_REQ)
            . chr($dlSettings)
            . self::packFreq($freq);
    }

    public static function buildRXTimingSetupReq(int $delay): string
    {
        return chr(self::CID_RX_TIMING_SETUP_REQ) . chr($delay & 0x0F);
    }

    public static function buildDutyCycleReq(int $maxDCycle): string
    {
        return chr(self::CID_DUTY_CYCLE_REQ) . chr($maxDCycle & 0x0F);
    }

    public static function buildNewChannelReq(int $chIndex, int $freqHz, int $minDr, int $maxDr): string
    {
        return chr(self::CID_NEW_CHANNEL_REQ)
            . chr($chIndex & 0xFF)
            . self::packFreq((int) ($freqHz / 100))
            . chr((($maxDr & 0x0F) << 4) | ($minDr & 0x0F));
    }

    public static function buildDevStatusReq(): string
    {
        return chr(self::CID_DEV_STATUS_REQ);
    }

    public static function buildPingSlotChannelReq(int $freqHz, int $dr): string
    {
        return chr(self::CID_PING_SLOT_CHANNEL_REQ)
            . self::packFreq((int) ($freqHz / 100))
            . chr($dr & 0x0F);
    }

    public static function buildBeaconFreqReq(int $freqHz): string
    {
        return chr(self::CID_BEACON_FREQ_REQ) . self::packFreq((int) ($freqHz / 100));
    }

    private static function packFreq(int $freqIn100hz): string
    {
        $freqIn100hz &= 0xFFFFFF;
        return chr($freqIn100hz & 0xFF) . chr(($freqIn100hz >> 8) & 0xFF) . chr(($freqIn100hz >> 16) & 0xFF);
    }

    


    








    public static function handleUplink(array &$device, Region $region, array $uplink, array $commands): array
    {
        $responses = [];
        $mustRespond = false;
        

        $order = array_unique(array_column($commands, 'cid'));
        foreach ($order as $cid) {
            $blocks = array_filter($commands, fn($c) => $c['cid'] === $cid);
            $res = self::dispatch($device, $region, $uplink, $cid, $blocks);
            if ($res['bytes'] !== null) {
                $responses[] = $res['bytes'];
            }
            if ($res['mustRespond']) {
                $mustRespond = true;
            }
        }
        return ['responses' => $responses, 'mustRespond' => $mustRespond];
    }

    private static function dispatch(array &$device, Region $region, array $uplink, int $cid, array $blocks): array
    {
        switch ($cid) {
            case self::CID_LINK_CHECK_REQ:    return self::onLinkCheckReq($uplink);
            case self::CID_DEVICE_TIME_REQ:   return self::onDeviceTimeReq($device);
            case self::CID_RESET_IND:         return self::onResetInd($blocks);
            case self::CID_REKEY_IND:         return self::onRekeyInd($blocks);
            case self::CID_DEVICE_MODE_IND:   return self::onDeviceModeInd($device, $blocks);
            case self::CID_PING_SLOT_INFO_REQ:return self::onPingSlotInfoReq($device, $blocks);
            case self::CID_BEACON_TIMING_REQ: return self::onBeaconTimingReq($device);
            case self::CID_LINK_ADR_ANS:      return self::onLinkADRAns($device, $region, $blocks);
            case self::CID_RX_PARAM_SETUP_ANS:return self::onRxParamSetupAns($device, $blocks);
            case self::CID_RX_TIMING_SETUP_ANS: return self::onRxTimingSetupAns();
            case self::CID_NEW_CHANNEL_ANS:   return self::onNewChannelAns($device, $region, $blocks);
            case self::CID_DUTY_CYCLE_ANS:    return ['bytes' => null, 'mustRespond' => false];
            case self::CID_TX_PARAM_SETUP_ANS:return ['bytes' => null, 'mustRespond' => false];
            case self::CID_DL_CHANNEL_ANS:    return ['bytes' => null, 'mustRespond' => false];
            case self::CID_ADR_PARAM_SETUP_ANS:return ['bytes' => null, 'mustRespond' => false];
            case self::CID_REJOIN_PARAM_SETUP_ANS: return self::onRejoinParamSetupAns($device, $blocks);
            case self::CID_PING_SLOT_CHANNEL_ANS: return self::onPingSlotChannelAns($device, $blocks);
            case self::CID_BEACON_FREQ_ANS:   return self::onBeaconFreqAns($device, $blocks);
            case self::CID_DEV_STATUS_ANS:    return self::onDevStatusAns($device, $blocks);
            case self::CID_RELAY_CONF_REQ:    return self::onRelayConfAns($device, $blocks);
            case self::CID_END_DEVICE_CONF_REQ: return self::onEndDeviceConfAns($device, $blocks);
            case self::CID_FILTER_LIST_REQ:   return self::onFilterListAns($device, $blocks);
            default:
                return ['bytes' => null, 'mustRespond' => false];
        }
    }

    


    private static function onLinkCheckReq(array $uplink): array
    {
        

        

        

        

        $dr = (int) ($uplink['dr'] ?? 0);
        $region = $uplink['region'] ?? null;
        $reqSnr = $region instanceof Region ? $region->requiredSnrForDr($dr) : 0.0;

        $rxSet = $uplink['rx_set'] ?? null;
        $maxSnr = (float) ($uplink['snr'] ?? 0); 

        $gwCnt = 1;
        if (is_array($rxSet) && count($rxSet) > 0) {
            $maxSnr = max(array_map('floatval', array_column($rxSet, 'snr')));
            $gwCnt = count(array_unique(array_column($rxSet, 'gw')));
        }

        $margin = (int) floor($maxSnr - $reqSnr); 

        if ($margin < 0) {
            $margin = 0;
        }
        if ($margin > 254) {
            $margin = 254;
        }
        if ($gwCnt < 1) {
            $gwCnt = 1;
        }
        if ($gwCnt > 255) {
            $gwCnt = 255;
        }
        return ['bytes' => chr(self::CID_LINK_CHECK_ANS) . chr($margin) . chr($gwCnt), 'mustRespond' => false];
    }

    private static function onDeviceTimeReq(array &$device): array
    {
        

        

        $unix = time();
        $gpsSeconds = ($unix - self::GPS_EPOCH_OFFSET + self::GPS_LEAP_SECONDS) & 0xFFFFFFFF;
        $secs = pack('V', $gpsSeconds);
        $frac = chr((int) (fmod(microtime(true), 1.0) * 256) & 0xFF);
        

        

        $device['device_time_valid'] = 1;
        $device['device_time'] = $gpsSeconds;
        return ['bytes' => chr(self::CID_DEVICE_TIME_ANS) . $secs . $frac, 'mustRespond' => false];
    }

    







    private static function onBeaconTimingReq(array &$device): array
    {
        $gpsNow = (int) ($device['device_time'] ?? 0);
        if ($gpsNow <= 0) {
            $gpsNow = self::gpsSecondsNow();
        }
        $period = 128; 

        

        

        $ref = (int) ($device['beacon_epoch'] ?? 0);
        if ($ref <= 0 || ($ref % $period) !== 0) {
            $ref = intdiv($gpsNow, $period) * $period;
        }
        

        $nextBeacon = (int) ceil(($gpsNow - $ref) / $period) * $period + $ref;
        $delaySec = $nextBeacon - $gpsNow;
        if ($delaySec < 0) {
            $delaySec = 0;
        }
        $timingDelay = (int) round($delaySec * 1000 / 30); 

        if ($timingDelay > 0xFFFF) {
            $timingDelay = 0xFFFF;
        }
        

        $device['beacon_epoch'] = $ref;
        $channel = 0;
        $bytes = chr(self::CID_BEACON_TIMING_ANS)
            . chr($timingDelay & 0xFF) . chr(($timingDelay >> 8) & 0xFF)
            . chr($channel);
        return ['bytes' => $bytes, 'mustRespond' => false];
    }

    

    public static function gpsSecondsNow(): int
    {
        return (time() - self::GPS_EPOCH_OFFSET + self::GPS_LEAP_SECONDS) & 0xFFFFFFFF;
    }

    private static function onResetInd(array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        $minor = ord($pl[0] ?? "\x00");
        

        return ['bytes' => chr(self::CID_RESET_CONF) . chr($minor), 'mustRespond' => false];
    }

    private static function onRekeyInd(array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x01";
        $minor = ord($pl[0] ?? "\x01");
        return ['bytes' => chr(self::CID_REKEY_CONF) . chr($minor), 'mustRespond' => false];
    }

    private static function onDeviceModeInd(array &$device, array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? 'A';
        $class = $pl[0] ?? 'A';
        $map = ['A' => 'A', 'B' => 'B', 'C' => 'C'];
        $newClass = $map[$class] ?? 'A';
        

        

        if ($newClass === 'B' && empty($device['device_time_valid'])) {
            return ['bytes' => chr(self::CID_DEVICE_MODE_CONF) . ($device['class'] ?? 'A'), 'mustRespond' => false];
        }
        $device['class'] = $newClass;
        

        return ['bytes' => chr(self::CID_DEVICE_MODE_CONF) . $newClass, 'mustRespond' => false];
    }

    private static function onPingSlotInfoReq(array &$device, array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        $device['class_b_ping_slot_periodicity'] = ord($pl[0] ?? "\x00") & 0x0F;
        return ['bytes' => null, 'mustRespond' => false];
    }

    private static function onRxTimingSetupAns(): array
    {
        

        return ['bytes' => null, 'mustRespond' => true];
    }

    private static function onRxParamSetupAns(array &$device, array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        $status = ord($pl[0] ?? "\x00");
        $pending = self::getPending($device, self::CID_RX_PARAM_SETUP_REQ);
        if ($pending === null) {
            return ['bytes' => null, 'mustRespond' => true];
        }
        

        $dlSettings = ord($pending[1]);
        $rx2Dr = $dlSettings & 0x0F;
        $rx1DrOffset = ($dlSettings >> 4) & 0x07;
        $freq = self::unpackFreq(substr($pending, 2, 3));
        if (($status & 0x07) === 0x07) {
            $device['rx2_frequency'] = $freq * 100;
            $device['rx2_dr'] = $rx2Dr;
            $device['rx1_dr_offset'] = $rx1DrOffset;
            self::clearError($device, self::CID_RX_PARAM_SETUP_REQ);
            self::clearPending($device, self::CID_RX_PARAM_SETUP_REQ);
        } else {
            self::bumpError($device, self::CID_RX_PARAM_SETUP_REQ);
        }
        return ['bytes' => null, 'mustRespond' => true];
    }

    private static function onNewChannelAns(array &$device, Region $region, array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        $status = ord($pl[0] ?? "\x00");
        if (($status & 0x03) === 0x03) {
            self::clearError($device, self::CID_NEW_CHANNEL_REQ);
        } else {
            self::bumpError($device, self::CID_NEW_CHANNEL_REQ);
        }
        return ['bytes' => null, 'mustRespond' => false];
    }

    private static function onRejoinParamSetupAns(array &$device, array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        $status = ord($pl[0] ?? "\x00");
        if (($status & 0x01) === 0x01) {
            self::clearError($device, self::CID_REJOIN_PARAM_SETUP_REQ);
        } else {
            self::bumpError($device, self::CID_REJOIN_PARAM_SETUP_REQ);
        }
        return ['bytes' => null, 'mustRespond' => false];
    }

    private static function onPingSlotChannelAns(array &$device, array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        $status = ord($pl[0] ?? "\x00");
        if (($status & 0x03) === 0x03) {
            self::clearError($device, self::CID_PING_SLOT_CHANNEL_REQ);
        } else {
            self::bumpError($device, self::CID_PING_SLOT_CHANNEL_REQ);
        }
        return ['bytes' => null, 'mustRespond' => false];
    }

    private static function onBeaconFreqAns(array &$device, array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        $status = ord($pl[0] ?? "\x00");
        if (($status & 0x01) === 0x01) {
            self::clearError($device, self::CID_BEACON_FREQ_REQ);
        } else {
            self::bumpError($device, self::CID_BEACON_FREQ_REQ);
        }
        return ['bytes' => null, 'mustRespond' => false];
    }

    private static function onDevStatusAns(array &$device, array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00\x00";
        $battery = ord($pl[0] ?? "\x00");
        $margin = ord($pl[1] ?? "\x00");
        $m = $margin & 0x3F;
        if ($m > 31) {
            $m -= 64;
        }
        $device['margin'] = $m;
        

        if ($battery === 0) {
            $device['battery'] = 0;       

        } elseif ($battery >= 1 && $battery <= 254) {
            $device['battery'] = $battery; 

        } else {
            $device['battery'] = -1;       

        }
        

        self::clearPending($device, self::CID_DEV_STATUS_REQ);
        

        $device['mac_telemetry'] = [
            'battery' => ($battery === 255) ? null : $battery,
            'margin'  => $m,
        ];
        return ['bytes' => null, 'mustRespond' => false];
    }

    




    private static function onRelayConfAns(array &$device, array $blocks): array
    {
        $payload = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        return Relay::handleRelayConfAns($device, $payload);
    }

    



    private static function onEndDeviceConfAns(array &$device, array $blocks): array
    {
        $payload = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        return Relay::handleEndDeviceConfAns($device, $payload);
    }

    



    private static function onFilterListAns(array &$device, array $blocks): array
    {
        $payload = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        return Relay::handleFilterListAns($device, $payload);
    }

    






    private static function onLinkADRAns(array &$device, Region $region, array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        $status = ord($pl[0] ?? "\x00");
        $chMaskAck = ($status & 0x01) === 0x01;
        $drAck = ($status & 0x02) === 0x02;
        $txPowerAck = ($status & 0x04) === 0x04;

        $pending = self::getPending($device, self::CID_LINK_ADR_REQ);
        if ($pending === null) {
            return ['bytes' => null, 'mustRespond' => false];
        }
        

        $reqDr = (ord($pending[1]) >> 4) & 0x0F;
        $reqTxPower = ord($pending[1]) & 0x0F;
        $chMask = unpack('v', substr($pending, 2, 2))[1];
        $chMaskCntl = (ord($pending[4]) >> 4) & 0x07;
        $nbRep = ord($pending[4]) & 0x0F;

        if ($chMaskAck && $drAck && $txPowerAck) {
            self::clearError($device, self::CID_LINK_ADR_REQ);
            $device['tx_power_index'] = $reqTxPower;
            $device['dr'] = $reqDr;
            $device['nb_trans'] = $nbRep;
            $device['enabled_uplink_channel_indices'] = json_encode(
                self::channelsFromMask($region, $chMask, $chMaskCntl, self::enabledChannels($device))
            );
        } elseif (!$device['adr'] && $chMaskAck) {
            self::clearError($device, self::CID_LINK_ADR_REQ);
            $device['enabled_uplink_channel_indices'] = json_encode(
                self::channelsFromMask($region, $chMask, $chMaskCntl, self::enabledChannels($device))
            );
            $device['nb_trans'] = $nbRep;
            if ($drAck) {
                $device['dr'] = $reqDr;
            }
            if ($txPowerAck) {
                $device['tx_power_index'] = $reqTxPower;
            }
        } else {
            self::bumpError($device, self::CID_LINK_ADR_REQ);
            

            if (!$txPowerAck && $reqTxPower == 0) {
                $device['tx_power_index'] = 1;
                $device['min_supported_tx_power_index'] = 1;
            }
            if (!$txPowerAck && $reqTxPower > 0) {
                $device['max_supported_tx_power_index'] = $reqTxPower - 1;
            }
        }
        self::clearPending($device, self::CID_LINK_ADR_REQ);
        return ['bytes' => null, 'mustRespond' => false];
    }

    private static function channelsFromMask(Region $region, int $chMask, int $chMaskCntl, array $cur): array
    {
        if ($chMaskCntl === 0) {
            $out = [];
            for ($i = 0; $i < 16; $i++) {
                if (($chMask >> $i) & 0x01) {
                    $out[] = $i;
                }
            }
            return $out;
        }
        

        

        return $cur;
    }

    


    public static function getPending(array &$device, int $cid): ?string
    {
        $pm = self::jsonDecode($device['pending_mac'] ?? '');
        $k = (string) $cid;
        return isset($pm[$k]) ? base64_decode($pm[$k]) : null;
    }

    public static function setPending(array &$device, int $cid, string $bytes): void
    {
        $pm = self::jsonDecode($device['pending_mac'] ?? '');
        $pm[(string) $cid] = base64_encode($bytes);
        $device['pending_mac'] = json_encode($pm);
    }

    public static function clearPending(array &$device, int $cid): void
    {
        $pm = self::jsonDecode($device['pending_mac'] ?? '');
        unset($pm[(string) $cid]);
        $device['pending_mac'] = json_encode($pm);
    }

    private static function bumpError(array &$device, int $cid): void
    {
        $ec = self::jsonDecode($device['mac_command_error_count'] ?? '');
        $k = (string) $cid;
        $ec[$k] = ($ec[$k] ?? 0) + 1;
        $device['mac_command_error_count'] = json_encode($ec);
    }

    private static function clearError(array &$device, int $cid): void
    {
        $ec = self::jsonDecode($device['mac_command_error_count'] ?? '');
        unset($ec[(string) $cid]);
        $device['mac_command_error_count'] = json_encode($ec);
    }

    private static function enabledChannels(array $device): array
    {
        $v = self::jsonDecode($device['enabled_uplink_channel_indices'] ?? '');
        return is_array($v) ? $v : [];
    }

    private static function jsonDecode(string $s): array
    {
        if ($s === '' || $s === null) {
            return [];
        }
        $v = json_decode($s, true);
        return is_array($v) ? $v : [];
    }

    private static function unpackFreq(string $b3): int
    {
        if (strlen($b3) < 3) {
            return 0;
        }
        return ord($b3[0]) | (ord($b3[1]) << 8) | (ord($b3[2]) << 16);
    }
}
