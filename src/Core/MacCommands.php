<?php
namespace holastack\Core;

use holastack\Region\Region;

/**
 * LoRaWAN MAC 命令引擎（对齐 ChirpStack maccommand/ 行为）。
 *
 * 负责：
 *  - 从上行帧的 FHDR.FOpts 与 FPort=0 负载中解析 MAC 命令；
 *  - 构造 NS→设备 的请求命令（LinkADRReq / RXParamSetupReq / DevStatusReq …）；
 *  - 处理设备上行中的 MAC 应答（Ans/Ind），更新设备会话状态（DR / TXPower / NbTrans / 信道），
 *    并向设备回送对应的应答（LinkCheckAns / DeviceTimeAns / ResetConf …）。
 *
 * 设备会话状态持久化在 devices 表：dr / tx_power_index / nb_trans / rx1_dr_offset / rx2_dr /
 * enabled_uplink_channel_indices(JSON) / mac_command_error_count(JSON) / uplink_adr_history(JSON) /
 * pending_mac(JSON: cid=>base64 请求字节)。
 */
class MacCommands
{
    // CID
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
    public const CID_RELAY_CONF_REQ         = 0x40; // RelayConfReq/Ans（TS011，对齐 lrwn maccommand.rs CID::to_u8）
    public const CID_END_DEVICE_CONF_REQ    = 0x41; // EndDeviceConfReq/Ans（TS011）
    public const CID_FILTER_LIST_REQ        = 0x42; // FilterListReq/Ans（TS011）
    public const CID_UPDATE_UPLINK_LIST_REQ = 0x43; // UpdateUplinkListReq/Ans（TS011）
    public const CID_CTRL_UPLINK_LIST_REQ   = 0x44; // CtrlUplinkListReq/Ans（TS011）
    public const CID_CONFIGURE_FWD_LIMIT_REQ= 0x45; // ConfigureFwdLimitReq/Ans（TS011）
    public const CID_NOTIFY_NEW_END_DEVICE_REQ = 0x46; // NotifyNewEndDeviceReq（TS011）

    // 应答 CID（设备→NS 的 Ans/Ind 与 NS→设备 的 Ans/Conf 复用同一 CID）
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

    // GPS 纪元（1980-01-06 00:00:00 UTC）相对 Unix 纪元（1970-01-01）的秒数偏移。
    private const GPS_EPOCH_OFFSET = 315964800;
    // 自 GPS 纪元以来插入 UTC 的闰秒数（GPS 时间 = UTC + 闰秒）。
    // 截至 2026 年最后一个闰秒为 2016-12-31，累计 18 秒，与 ChirpStack gpstime.rs 一致。
    private const GPS_LEAP_SECONDS = 18;

    public static function parse(string $bytes): array
    {
        $out = [];
        $i = 0;
        $n = strlen($bytes);
        while ($i < $n) {
            $cid = ord($bytes[$i]);
            $i++;
            // 各命令长度（不含 CID）；-1 表示变长/未知（按 0 处理）
            $len = self::cmdLen($cid);
            if ($len < 0) {
                // 未知命令：无法安全续解，停止（不破坏后续上行处理）
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
            case self::CID_RELAY_CONF_REQ:         return 1; // 上行方向为 RelayConfAns（1 字节）
            case self::CID_END_DEVICE_CONF_REQ:    return 1; // 上行方向为 EndDeviceConfAns（1 字节）
            case self::CID_FILTER_LIST_REQ:        return 1; // 上行方向为 FilterListAns（1 字节）
            case self::CID_UPDATE_UPLINK_LIST_REQ: return 1; // 上行方向为 UpdateUplinkListAns（1 字节）
            case self::CID_CTRL_UPLINK_LIST_REQ:   return 1; // 上行方向为 CtrlUplinkListAns（1 字节）
            case self::CID_CONFIGURE_FWD_LIMIT_REQ:return 1; // 上行方向为 ConfigureFwdLimitAns（1 字节）
            default:                               return -1;
        }
    }

    // ---------------- 请求构造（NS → 设备） ----------------

    public static function buildLinkADRReq(int $dr, int $txPower, int $chMask, int $chMaskCntl, int $nbRep): string
    {
        $drByte = ($dr & 0x0F);
        $txByte = ($txPower & 0x0F);
        $redundancy = (($chMaskCntl & 0x07) << 4) | ($nbRep & 0x0F);
        return chr(self::CID_LINK_ADR_REQ)
            . chr($drByte) . chr($txByte)
            . pack('v', $chMask & 0xFFFF)
            . chr($redundancy);
    }

    public static function buildRXParamSetupReq(Region $region, int $rx1DrOffset, int $rx2Dr): string
    {
        $dlSettings = (($rx1DrOffset & 0x07) << 4) | ($rx2Dr & 0x0F);
        $freq = (int) ($region->getRx2Frequency() / 100); // 100Hz 单位
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

    // ---------------- 上行处理 ----------------

    /**
     * 处理一条上行帧中的全部 MAC 命令。
     * @param array &$device devices 行（按引用修改并需调用方落库）
     * @param Region $region
     * @param array $uplink 上行元信息：['snr'=>float,'dr'=>int,'rx1_dr_offset'=>int,'freq'=>float]
     * @param array $commands parse() 的结果
     * @return array ['responses'=>string[](待下发 MAC 命令字节，含 CID), 'mustRespond'=>bool]
     */
    public static function handleUplink(array &$device, Region $region, array $uplink, array $commands): array
    {
        $responses = [];
        $mustRespond = false;
        // 按 CID 顺序处理（对齐 ChirpStack：RxTimingSetupAns/RxParamSetupAns 强制回包）
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

    // ---------------- 各命令处理 ----------------

    private static function onLinkCheckReq(array $uplink): array
    {
        // LinkCheckAns: margin(1) + gwCnt(1)。对齐 ChirpStack chirpstack/src/maccommand/link_check.rs：
        //   - margin = 所有收到该上行的网关中的最大 SNR − 该上行 DR 的解调门限（required SNR），
        //     小于 0 取 0；值域 clamp 到 [0,254]（ChirpStack 用 `as u8` 截断，此处按规范更稳）。
        //   - gw_cnt = 收到该上行的去重网关数（对齐 ufs.rx_info_set.len()），下限 1，上限 255。
        $dr = (int) ($uplink['dr'] ?? 0);
        $region = $uplink['region'] ?? null;
        $reqSnr = $region instanceof Region ? $region->requiredSnrForDr($dr) : 0.0;

        $rxSet = $uplink['rx_set'] ?? null;
        $maxSnr = (float) ($uplink['snr'] ?? 0); // 无 rx_set（单网关）时回退到本网关 SNR
        $gwCnt = 1;
        if (is_array($rxSet) && count($rxSet) > 0) {
            $maxSnr = max(array_map('floatval', array_column($rxSet, 'snr')));
            $gwCnt = count(array_unique(array_column($rxSet, 'gw')));
        }

        $margin = (int) floor($maxSnr - $reqSnr); // ChirpStack: max_snr - required_snr（截断）
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
        // DeviceTimeAns 携带「自 GPS 纪元（1980-01-06 00:00:00 UTC）以来的秒数」+ 1/256 秒分数，
        // 与 Unix 纪元（1970）不同，且 GPS 时间 = UTC + 闰秒（对齐 ChirpStack gpstime.rs to_gps_time）。
        $unix = time();
        $gpsSeconds = ($unix - self::GPS_EPOCH_OFFSET + self::GPS_LEAP_SECONDS) & 0xFFFFFFFF;
        $secs = pack('V', $gpsSeconds);
        $frac = chr((int) (fmod(microtime(true), 1.0) * 256) & 0xFF);
        // 标记设备内部 UTC/GPS 时间已有效：设备据此才允许切换到 Class-B。
        // 缺失 DeviceTimeAns → 永远禁止切换 Class-B（对齐 ChirpStack 行为）。
        $device['device_time_valid'] = 1;
        $device['device_time'] = $gpsSeconds;
        return ['bytes' => chr(self::CID_DEVICE_TIME_ANS) . $secs . $frac, 'mustRespond' => false];
    }

    /**
     * BeaconTimingAns（CID 0x12，设备→NS 发 BeaconTimingReq 后回应）。
     * 设备拿到 DeviceTimeAns 取得网络时间后发出 BeaconTimingReq，NS 据此告知
     * 「距下一个信标还有多久」以助设备对齐 128s 信标网格。
     * 字节布局（对齐 LoRaWAN 规范）：CID + TimingDelay(2B, 单位 30ms, 小端) + Channel(1B)。
     * TimingDelay = 下一个信标 GPS 秒 − 当前 GPS 秒，换算为 30ms 单位。
     */
    private static function onBeaconTimingReq(array &$device): array
    {
        $gpsNow = (int) ($device['device_time'] ?? 0);
        if ($gpsNow <= 0) {
            $gpsNow = self::gpsSecondsNow();
        }
        $period = 128; // 信标周期（秒）
        // 锚点必须是 128s 网格边界（多 128 的倍数皆可）；若未设置或不是 128 倍数，
        // 回退到当前 GPS 秒向下取整的网格边界，避免 nextBeacon 退化成当前秒（delay=0）。
        $ref = (int) ($device['beacon_epoch'] ?? 0);
        if ($ref <= 0 || ($ref % $period) !== 0) {
            $ref = intdiv($gpsNow, $period) * $period;
        }
        // 下一个信标边界（≥ 当前）
        $nextBeacon = (int) ceil(($gpsNow - $ref) / $period) * $period + $ref;
        $delaySec = $nextBeacon - $gpsNow;
        if ($delaySec < 0) {
            $delaySec = 0;
        }
        $timingDelay = (int) round($delaySec * 1000 / 30); // 30ms 单位
        if ($timingDelay > 0xFFFF) {
            $timingDelay = 0xFFFF;
        }
        // 记录锚点，供 ping-slot / 信标下发对齐；EU868 信标在固定信道 0
        $device['beacon_epoch'] = $ref;
        $channel = 0;
        $bytes = chr(self::CID_BEACON_TIMING_ANS)
            . chr($timingDelay & 0xFF) . chr(($timingDelay >> 8) & 0xFF)
            . chr($channel);
        return ['bytes' => $bytes, 'mustRespond' => false];
    }

    /** 当前 GPS 纪元秒（1980-01-06 + 闰秒），与 onDeviceTimeReq 同源。 */
    public static function gpsSecondsNow(): int
    {
        return (time() - self::GPS_EPOCH_OFFSET + self::GPS_LEAP_SECONDS) & 0xFFFFFFFF;
    }

    private static function onResetInd(array $blocks): array
    {
        $pl = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        $minor = ord($pl[0] ?? "\x00");
        // ResetConf: 回显 minor version（1.1 设备重置到该版本）
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
        // Class-B 切换必须以设备已取得有效网络时间（DeviceTimeAns）为前提：
        // 否则信标/ping-slot 时序无法对齐，禁止切换（回显当前 Class，由设备先发 DeviceTimeReq）。
        if ($newClass === 'B' && empty($device['device_time_valid'])) {
            return ['bytes' => chr(self::CID_DEVICE_MODE_CONF) . ($device['class'] ?? 'A'), 'mustRespond' => false];
        }
        $device['class'] = $newClass;
        // DeviceModeConf: 回显设备请求切换到的 Class
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
        // 设备已确认 RX1 Delay；NS 无需更新状态（值存于 region/DP），但必须回包
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
        // 解析请求中的 Rx2 freq / RX2DR / RX1DROffset
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
        // 电池：0=外电，1..254=百分比，255=不可用
        if ($battery === 0) {
            $device['battery'] = 0;       // 外电
        } elseif ($battery >= 1 && $battery <= 254) {
            $device['battery'] = $battery; // 百分比
        } else {
            $device['battery'] = -1;       // 不可用
        }
        // 设备已响应 DevStatusReq，清除挂起请求（避免每次上行都重发）
        self::clearPending($device, self::CID_DEV_STATUS_REQ);
        // 供上层 Webhook 上报：255(不可用) 映射为 null
        $device['mac_telemetry'] = [
            'battery' => ($battery === 255) ? null : $battery,
            'margin'  => $m,
        ];
        return ['bytes' => null, 'mustRespond' => false];
    }

    /**
     * RelayConfAns 处理：委托 Relay::handleRelayConfAns（对齐 maccommand/relay_conf.rs）。
     * 全 ACK → 写入 devices.relay_state；nack → 不写入。无待确认请求时忽略。
     */
    private static function onRelayConfAns(array &$device, array $blocks): array
    {
        $payload = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        return Relay::handleRelayConfAns($device, $payload);
    }

    /**
     * EndDeviceConfAns（CID 0x41）：全 ACK → 写 relay_state 的 ed_* 字段（对齐 end_device_conf.rs）。
     */
    private static function onEndDeviceConfAns(array &$device, array $blocks): array
    {
        $payload = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        return Relay::handleEndDeviceConfAns($device, $payload);
    }

    /**
     * FilterListAns（CID 0x42）：全 ACK → filters provisioned 标记 / NoRule 移除（对齐 filter_list.rs）。
     */
    private static function onFilterListAns(array &$device, array $blocks): array
    {
        $payload = $blocks[array_key_first($blocks)]['payload'] ?? "\x00";
        return Relay::handleFilterListAns($device, $payload);
    }

    /**
     * LinkADRAns 处理（对齐 ChirpStack link_adr.rs）：
     *  - 全 ACK（ch_mask+dr+power）→ 更新 dr/tx_power/nb_trans/信道，清空错误计数；
     *  - ADR 关闭仅 ch_mask ACK → 至少更新信道 + nb_trans，dr/power 仅在被 ACK 时更新；
     *  - 否则累积错误计数，并处理 TXPower nACK 的向下兼容（RN2483 等固件的 TXPower=0 误 nACK）。
     */
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
        // 解析请求：dr(1) txpower(1) chmask(2) redundancy(1)
        $reqDr = ord($pending[1]) & 0x0F;
        $reqTxPower = ord($pending[2]) & 0x0F;
        $chMask = unpack('v', substr($pending, 3, 2))[1];
        $chMaskCntl = (ord($pending[5]) >> 4) & 0x07;
        $nbRep = ord($pending[5]) & 0x0F;

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
            // RN2483 类固件对 TXPower=0 误 nACK：固件应按规范 ACK 并以最大功率运行
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
        // 其它 ChMaskCntl（如 US915 的 8 信道组）：在「当前信道 + 偏移组」上合并启用
        // 简化实现：保留已启用信道，不破坏现有集合（多信道组场景由上层多次 LinkADR 累加）
        return $cur;
    }

    // ---------------- pending / error 状态工具 ----------------

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
