<?php
namespace holastack\Core;






















class Beacon
{
    public const BEACON_PERIOD = 128; 


    










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
            

            $crc1 = self::crc16($rfu1 . $time);
            $macPayload = $rfu1 . $time . pack('v', $crc1) . $gwSpecific . $rfu2 . pack('v', self::crc16($gwSpecific . $rfu2));
        }

        return $macPayload; 

    }

    



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
     * 计算 ping slot 偏移（LoRaWAN 规范 18.x）。
     * 加密密钥：1.0.x 用 NwkSKey；1.1 用 FNwkSIntKey。此前误用全零密钥，会导致
     * NS 算出的 ping slot 时间窗与设备端不一致，下行永远错过窗口。
     * @param string $key 16 字节原始密钥（hex2bin 后的二进制）
     */
    public static function computePingOffset(int $gpsSeconds, int $devAddr, int $pingPeriod30, string $key = ''): int
    {
        if ($pingPeriod30 <= 0) {
            $pingPeriod30 = 1;
        }
        $block = pack('V', $gpsSeconds & 0xFFFFFFFF) . pack('V', $devAddr & 0xFFFFFFFF) . str_repeat("\x00", 8);
        $k = (strlen($key) === 16) ? $key : str_repeat("\x00", 16);
        $cipher = \holastack\Crypto\AES::ecbEncrypt($k, $block);
        $result = (ord($cipher[0]) & 0xFF) + ((ord($cipher[1]) & 0xFF) << 8);
        return $result % $pingPeriod30;
    }
}
