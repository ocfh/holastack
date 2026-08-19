<?php
namespace holastack\Core;

/**
 * LoRaWAN Class B 信标帧构造（对齐 RA-09-WAN 固件 LoRaMacClassB.c 的 beacon 解析）。
 *
 * 信标帧（LoRaWAN 1.0.x）无 MIC、无加密，仅靠两段 CRC16 提供完整性；
 * 设备仅在「信标网格时刻 + 信标频点」开窗接收，并以 CRC 校验。
 *
 *  ⚠ 信标帧【无 MHDR】（隐式模式发送，BCNPayload 直接从 RFU1 开始），对齐固件
 *  LoRaMacClassB.c:1470（LORAMAC_VERSION==0x01000300）：
 *
 *  1.0.3 布局：RFU1(x) | Time(4) | CRC1(2) | GwSpecific(7) | RFU2(y) | CRC2(2)
 *  1.0.4 布局：RFU1(x) | Param(1) | Time(4) | CRC1(2) | GwSpecific(7) | RFU2(y) | CRC2(2)
 *
 *  - Time = 自 GPS 纪元（1980-01-06）起的秒数，小端；
 *  - GwSpecific = InfoDesc(1) + Info(6)；
 *  - RFU1/RFU2 由区域决定（EU868=2/0，CN470=3/1，US915=5/3，AU915=3/1…），
 *    固件按 phyParam.BeaconFormat.{Rfu1Size,Rfu2Size,BeaconSize} 做 size 校验，
 *    长度不符直接丢弃 → NS 必须按区域填对 RFU 长度。
 *
 * CRC16 多项式 0x1021、初值 0x0000，与固件 BeaconCrc() 逐位一致（已核对）。
 */
class Beacon
{
    public const BEACON_PERIOD = 128; // 秒

    /**
     * 构造信标 PHYPayload（含 MHDR）。
     * @param int    $gpsSeconds   信标 GPS 秒（BeaconTime）
     * @param string $gwSpecific   7 字节 GwSpecific（InfoDesc + Info）
     * @param string $macVersion   '1.0.3' | '1.0.4'
     * @param int    $rfu1Size     区域相关 RFU1 长度（EU868 = 0）
     * @param int    $rfu2Size     区域相关 RFU2 长度（EU868 = 0）
     * @param int    $param        1.0.4 的 Param 字节（时间精度索引，默认 0）
     * @return string 完整 PHYPayload
     */
    public static function buildFrame(
        int $gpsSeconds,
        string $gwSpecific,
        string $macVersion = '1.0.3',
        int $rfu1Size = 0,
        int $rfu2Size = 0,
        int $param = 0
    ): string {
        $gpsSeconds &= 0xFFFFFFFF;
        $time = pack('V', $gpsSeconds);
        $gwSpecific = substr($gwSpecific, 0, 7);
        while (strlen($gwSpecific) < 7) {
            $gwSpecific .= "\x00";
        }

        $rfu1 = str_repeat("\x00", $rfu1Size);
        $rfu2 = str_repeat("\x00", $rfu2Size);

        if ($macVersion === '1.0.4') {
            $paramByte = chr($param & 0xFF);
            $crc1Input = $rfu1 . $paramByte . $time;
            $crc1 = self::crc16($crc1Input);
            $macPayload = $rfu1 . $paramByte . $time . pack('v', $crc1) . $gwSpecific . $rfu2 . pack('v', self::crc16($gwSpecific . $rfu2));
        } else {
            // 1.0.3
            $crc1 = self::crc16($rfu1 . $time);
            $macPayload = $rfu1 . $time . pack('v', $crc1) . $gwSpecific . $rfu2 . pack('v', self::crc16($gwSpecific . $rfu2));
        }

        return $macPayload; // 信标无 MHDR：BCNPayload 从 RFU1 开始（隐式模式发送）
    }

    /**
     * 信标 CRC16（与固件 BeaconCrc 一致）：poly 0x1021，init 0x0000，无反射。
     */
    public static function crc16(string $data): int
    {
        $crc = 0x0000;
        $n = strlen($data);
        for ($i = 0; $i < $n; $i++) {
            $crc ^= (ord($data[$i]) << 8) & 0xFFFF;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000)
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }
        return $crc & 0xFFFF;
    }

    /**
     * 由 devAddr + 信标 GPS 秒派生 ping-slot 偏移（30ms 单位），对齐固件 ComputePingOffset。
     * pingOffset = (AES-128(零密钥, [gps(4) || devAddr(4) || 0])[0..1]) mod pingPeriod30
     * @param int $gpsSeconds      信标 GPS 秒
     * @param int $devAddr         设备地址（uint32）
     * @param int $pingPeriod30    ping 周期（30ms 单位）
     * @return int pingOffset（30ms 单位）
     */
    public static function computePingOffset(int $gpsSeconds, int $devAddr, int $pingPeriod30): int
    {
        if ($pingPeriod30 <= 0) {
            $pingPeriod30 = 1;
        }
        $block = pack('V', $gpsSeconds & 0xFFFFFFFF) . pack('V', $devAddr & 0xFFFFFFFF) . str_repeat("\x00", 8);
        $cipher = \holastack\Crypto\AES::ecbEncrypt(str_repeat("\x00", 16), $block);
        $result = (ord($cipher[0]) & 0xFF) + ((ord($cipher[1]) & 0xFF) << 8);
        return $result % $pingPeriod30;
    }
}
