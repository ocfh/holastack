<?php
namespace holastack\Core;

use holastack\Crypto\LoRaWANCrypto;

/**
 * LoRaWAN 1.0.x / 1.1 物理层帧解析与构造。
 * 所有方法参数为原始二进制串；帧中多字节整数字段按 LoRaWAN 规范字节序处理。
 * 1.0.x 方法保持不变；1.1 变体以 1_1 后缀命名（密钥选择/双密钥 MIC 不同）。
 */
class Frame
{
    /** MHDR mtype 取值 */
    public const MTYPE_JOIN_REQUEST       = 0x00;
    public const MTYPE_JOIN_ACCEPT        = 0x01;
    public const MTYPE_UNCONFIRMED_UP      = 0x02;
    public const MTYPE_CONFIRMED_UP        = 0x03;
    public const MTYPE_UNCONFIRMED_DOWN    = 0x04;
    public const MTYPE_CONFIRMED_DOWN      = 0x05;

    public static function mtype(string $phy): int
    {
        return (ord($phy[0]) >> 5) & 0x07;
    }

    // ---------------- Join Request ----------------

    public static function parseJoinRequest(string $phy): array
    {
        // MHDR(1) | AppEUI(8) | DevEUI(8) | DevNonce(2) | MIC(4)
        return [
            'mhdr'     => $phy[0],
            'app_eui'  => substr($phy, 1, 8),
            'dev_eui'  => substr($phy, 9, 8),
            'dev_nonce'=> substr($phy, 17, 2),
            'mic'      => substr($phy, 19, 4),
        ];
    }

    public static function joinRequestDataForMic(string $phy): string
    {
        return substr($phy, 0, 19); // MHDR|AppEUI|DevEUI|DevNonce
    }

    // ---------------- Join Accept ----------------

    /**
     * 构造加密后的 Join Accept 物理层负载。
     * @return string 0x20 + AES-ECB(明文含 MIC)
     */
    public static function buildJoinAccept(
        string $appKey, string $appNonce, string $netId, string $devAddr,
        int $dlSettings, int $rxDelay, ?string $cfList = null
    ): string {
        $dlSettingsByte = chr($dlSettings & 0xFF);
        $micData = "\x20" . $appNonce . $netId . $devAddr . $dlSettingsByte . chr($rxDelay & 0xFF);
        if ($cfList !== null) {
            $micData .= $cfList;
        }
        $mic = LoRaWANCrypto::joinAcceptMIC($appKey, $micData);

        $plaintext = "\x20" . $appNonce . $netId . $devAddr
            . $dlSettingsByte . chr($rxDelay & 0xFF);
        if ($cfList !== null) {
            $plaintext .= $cfList;
        }
        $plaintext .= $mic;

        // 仅加密 MHDR 之后的部分（按 16 字节块）
        $encryptedBody = LoRaWANCrypto::encryptJoinAccept($appKey, substr($plaintext, 1));
        return "\x20" . $encryptedBody;
    }

    // ---------------- Data Up ----------------

    public static function parseDataUp(string $phy): array
    {
        $mhdr = $phy[0];
        $devAddr = substr($phy, 1, 4);
        $fctrl = ord($phy[5]);
        $foptsLen = $fctrl & 0x0F;
        $fcntLo = unpack('v', substr($phy, 6, 2))[1];
        $pos = 8;
        $fopts = $foptsLen > 0 ? substr($phy, $pos, $foptsLen) : '';
        $pos += $foptsLen;
        $fport = null;
        $frmpayload = '';
        if (strlen($phy) > $pos + 4) {
            // 至少还有 FPort + MIC（4 字节）
            $fport = ord($phy[$pos]);
            $pos++;
            $frmpayload = substr($phy, $pos, -4);
        }
        $mic = substr($phy, -4);
        $dataWithoutMic = substr($phy, 0, -4);
        return [
            'mhdr'      => $mhdr,
            'dev_addr'  => $devAddr,
            'fctrl'     => $fctrl,
            'adr'       => ($fctrl & 0x80) ? 1 : 0,
            'ack'       => ($fctrl & 0x20) ? 1 : 0,
            'fopts'     => $fopts,
            'fcnt_lo'   => $fcntLo,
            'fport'     => $fport,
            'frmpayload'=> $frmpayload,
            'mic'       => $mic,
            'data_without_mic' => $dataWithoutMic,
        ];
    }

    /** 根据已保存的 32 位上行计数还原完整 32 位帧计数。 */
    public static function fullFCnt(int $fcntLo, int $lastFCnt): int
    {
        $lastHi = ($lastFCnt >> 16) & 0xFFFF;
        $lastLo = $lastFCnt & 0xFFFF;
        if ($fcntLo >= $lastLo) {
            return ($lastHi << 16) | $fcntLo;
        }
        // 回绕
        return (($lastHi + 1) << 16) | $fcntLo;
    }

    public static function verifyDataMIC(string $nwkSKey, int $dir, string $devAddr, int $fcnt, string $dataWithoutMic, string $mic): bool
    {
        return LoRaWANCrypto::dataMIC($nwkSKey, $dir, $devAddr, $fcnt, $dataWithoutMic) === $mic;
    }

    public static function decryptFRMPayload(string $key, int $dir, string $devAddr, int $fcnt, string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        return LoRaWANCrypto::frmPayloadCrypt($key, $dir, $devAddr, $fcnt, $payload);
    }

    // ---------------- Data Down ----------------

    /**
     * 构造数据下行物理层负载（已加密 + MIC）。
     * @param int $dir 1
     * @param bool $confirmed 是否 Confirmed Data Down
     * @param bool $ack 是否置 ACK 位
     * @param int $fport 端口（0..223），null 表示无 FPort（纯 MAC 命令在 FHDR）
     * @param string $payload 明文应用负载（会被加密）
     * @param int $adr 是否置 ADR 位
     * @param string $fopts FHDR 中的 MAC 命令字节（≤15 字节）
     */
    public static function buildDataDown(
        string $nwkSKey, string $appSKey, int $dir, string $devAddr, int $fcnt,
        bool $confirmed, bool $ack, ?int $fport, string $payload, int $adr = 0, string $fopts = ''
    ): string {
        $mtype = $confirmed ? self::MTYPE_CONFIRMED_DOWN : self::MTYPE_UNCONFIRMED_DOWN;
        $mhdr = chr(($mtype << 5) & 0xE0);
        $foptsLen = strlen($fopts) & 0x0F;
        $fctrl = ($adr ? 0x80 : 0x00) | ($ack ? 0x20 : 0x00) | $foptsLen;
        $fhdr = $devAddr . chr($fctrl) . pack('v', $fcnt & 0xFFFF) . $fopts;

        $plainFrm = '';
        if ($fport !== null) {
            $encKey = ($fport === 0) ? $nwkSKey : $appSKey;
            $enc = LoRaWANCrypto::frmPayloadCrypt($encKey, $dir, $devAddr, $fcnt, $payload);
            $plainFrm = chr($fport) . $enc;
        }
        $dataWithoutMic = $mhdr . $fhdr . $plainFrm;
        $mic = LoRaWANCrypto::dataMIC($nwkSKey, $dir, $devAddr, $fcnt, $dataWithoutMic);
        return $dataWithoutMic . $mic;
    }

    // ============ LoRaWAN 1.1 变体 ============

    /**
     * 1.1 构造加密后的 Join Accept（密钥 NwkKey，MIC/加密方向同 1.0）。
     */
    public static function buildJoinAccept1_1(
        string $nwkKey, string $appNonce, string $netId, string $devAddr,
        int $dlSettings, int $rxDelay, ?string $cfList = null
    ): string {
        $dlSettingsByte = chr($dlSettings & 0xFF);
        $micData = "\x20" . $appNonce . $netId . $devAddr . $dlSettingsByte . chr($rxDelay & 0xFF);
        if ($cfList !== null) {
            $micData .= $cfList;
        }
        $mic = LoRaWANCrypto::joinAcceptMIC1_1($nwkKey, $micData);

        $plaintext = "\x20" . $appNonce . $netId . $devAddr . $dlSettingsByte . chr($rxDelay & 0xFF);
        if ($cfList !== null) {
            $plaintext .= $cfList;
        }
        $plaintext .= $mic;

        $encryptedBody = LoRaWANCrypto::encryptJoinAccept1_1($nwkKey, substr($plaintext, 1));
        return "\x20" . $encryptedBody;
    }

    /** 1.1 上行数据 MIC 校验（双密钥 B0+B1）。 */
    public static function verifyDataMIC1_1(string $fNwkSIntKey, string $sNwkSIntKey, string $devAddr, int $fcnt, string $dataWithoutMic, string $mic, int $txDr = 0, int $txCh = 0): bool
    {
        return LoRaWANCrypto::dataMICUp1_1($fNwkSIntKey, $sNwkSIntKey, $devAddr, $fcnt, $dataWithoutMic, $txDr, $txCh) === $mic;
    }

    /** 1.1 FRMPayload 解密：FPort=0 用 NwkSEncKey，否则 AppSKey。 */
    public static function decryptFRMPayload1_1(string $nwkSEncKey, string $appSKey, int $dir, string $devAddr, int $fcnt, ?int $fport, string $payload): string
    {
        if ($payload === '' || $fport === null) {
            return '';
        }
        $key = ($fport === 0) ? $nwkSEncKey : $appSKey;
        return LoRaWANCrypto::frmPayloadCrypt($key, $dir, $devAddr, $fcnt, $payload);
    }

    /**
     * 1.1 构造数据下行物理层负载（已加密 + MIC）。
     * MIC 用 SNwkSIntKey（B0 含 ConfFCnt）；FRMPayload FPort=0 用 NwkSEncKey，否则 AppSKey。
     * @param int $confFCnt 确认帧下发时填被确认上行的 FCnt（24 位），非确认帧填 0
     */
    public static function buildDataDown1_1(
        string $sNwkSIntKey, string $nwkSEncKey, string $appSKey, string $devAddr, int $fcnt,
        bool $confirmed, bool $ack, ?int $fport, string $payload, int $adr = 0, string $fopts = '', int $confFCnt = 0
    ): string {
        $dir = 1;
        $mtype = $confirmed ? self::MTYPE_CONFIRMED_DOWN : self::MTYPE_UNCONFIRMED_DOWN;
        $mhdr = chr(($mtype << 5) & 0xE0);
        $foptsLen = strlen($fopts) & 0x0F;
        $fctrl = ($adr ? 0x80 : 0x00) | ($ack ? 0x20 : 0x00) | $foptsLen;
        $fhdr = $devAddr . chr($fctrl) . pack('v', $fcnt & 0xFFFF) . $fopts;

        $plainFrm = '';
        if ($fport !== null) {
            $encKey = ($fport === 0) ? $nwkSEncKey : $appSKey;
            $enc = LoRaWANCrypto::frmPayloadCrypt($encKey, $dir, $devAddr, $fcnt, $payload);
            $plainFrm = chr($fport) . $enc;
        }
        $dataWithoutMic = $mhdr . $fhdr . $plainFrm;
        $mic = LoRaWANCrypto::dataMICDown1_1($sNwkSIntKey, $devAddr, $fcnt, $confFCnt, $dataWithoutMic);
        return $dataWithoutMic . $mic;
    }
}
