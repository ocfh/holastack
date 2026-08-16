<?php
/**
 * LoRaWAN 1.0.x / 1.1 加解密与完整性封装。
 * 所有密钥/负载均为原始二进制串。
 *
 * 版本差异（对齐 ChirpStack lrwn / LoRaWAN 规范）：
 *  - 1.0.x：单 NwkSKey（= AppKey 派生），上行 MIC 用 B0 单密钥；Join 用 AppKey。
 *  - 1.1  ：FNwkSIntKey / SNwkSIntKey / NwkSEncKey / AppSKey 四把独立密钥
 *           （NwkKey 派生三把网络密钥、AppKey 派生 AppSKey），上行 MIC 为
 *           B0( FNwkSIntKey ) 与 B1( SNwkSIntKey ) 的 2+2 字节拼接；下行 MIC 用 SNwkSIntKey
 *           且 B0 含 ConfFCnt；Join 用 NwkKey（MIC 与加密）。
 * 既有 1.0.x 方法保持不变，1.1 以独立方法提供（方法名带 1_1 后缀），避免存量设备回退。
 */
namespace holastack\Crypto;

class LoRaWANCrypto
{
    /** Join Request MIC = cmac(AppKey, MHDR|AppEUI|DevEUI|DevNonce)[0:4] */
    public static function joinRequestMIC(string $appKey, string $dataWithoutMic): string
    {
        return substr(AES::cmac($appKey, $dataWithoutMic), 0, 4);
    }

    public static function verifyJoinRequestMIC(string $appKey, string $frame): bool
    {
        if (strlen($frame) < 12) {
            return false;
        }
        $data = substr($frame, 0, -4);
        $mic = substr($frame, -4);
        return self::joinRequestMIC($appKey, $data) === $mic;
    }

    /** Join Accept MIC（针对解密后的明文） = cmac(AppKey, MHDR|AppNonce|NetID|DevAddr|RFU|RxDelay|CFList)[0:4] */
    public static function joinAcceptMIC(string $appKey, string $plaintextWithoutMic): string
    {
        return substr(AES::cmac($appKey, $plaintextWithoutMic), 0, 4);
    }

    /**
     * 解密 Join Accept 负载（设备端收到后解密，或离线验证）。
     * ★ 与 encryptJoinAccept 互逆：NS 用 ecbDecrypt 加密，设备用 ecbEncrypt 解密。
     *   固件 soft-se.c:SecureElementProcessJoinAccept 调 SecureElementAesEncrypt (= lorawan_aes_encrypt = AES encrypt)。
     */
    public static function decryptJoinAccept(string $appKey, string $encrypted): string
    {
        $out = '';
        for ($i = 0; $i < strlen($encrypted); $i += 16) {
            $out .= AES::ecbEncrypt($appKey, substr($encrypted, $i, 16));
        }
        return $out;
    }

    /**
     * 加密 Join Accept 负载（服务器下发前）。
     * ★ LoRaWAN 规范规定：NS 用 AES decrypt 加密，设备用 AES encrypt 解密（互逆）。
     *   固件 soft-se.c:SecureElementProcessJoinAccept 调 SecureElementAesEncrypt -> lorawan_aes_encrypt。
     *   ChirpStack phy_payload.rs:encrypt_join_accept_payload 用 cipher.decrypt_block。
     *   holastack 此前用 ecbEncrypt（与设备同方向），导致设备 encrypt(encrypt(plaintext)) ≠ plaintext，MIC 必败。
     *   修正：改用 ecbDecrypt（AES decrypt）加密，与 ChirpStack / 固件一致。
     */
    public static function encryptJoinAccept(string $appKey, string $plaintext): string
    {
        $out = '';
        for ($i = 0; $i < strlen($plaintext); $i += 16) {
            $out .= AES::ecbDecrypt($appKey, substr($plaintext, $i, 16));
        }
        return $out;
    }

    /**
     * 派生 NwkSKey / AppSKey。
     * @return array [nwkSKey, appSKey] 各 16 字节
     */
    public static function computeSessionKeys(string $appKey, string $appNonce, string $netId, string $devNonce): array
    {
        $padLen = 16 - (1 + 3 + 3 + 2);
        $nwkInput = "\x01" . $appNonce . $netId . $devNonce . str_repeat("\x00", $padLen);
        $appInput = "\x02" . $appNonce . $netId . $devNonce . str_repeat("\x00", $padLen);
        return [
            AES::ecbEncrypt($appKey, $nwkInput),
            AES::ecbEncrypt($appKey, $appInput),
        ];
    }

    /**
     * 数据帧 MIC（上下行通用）。
     * @param int $dir 0=上行 1=下行
     * @param string $devAddr 4 字节（传输顺序，大端）
     * @param int $fcnt 32 位帧计数
     * @param string $dataWithoutMic MHDR|FHDR|FPort|FRMPayload
     */
    public static function dataMIC(string $key, int $dir, string $devAddr, int $fcnt, string $dataWithoutMic): string
    {
        $b0 = "\x49" . "\x00\x00\x00\x00" . chr($dir & 0xFF)
            . $devAddr . pack('V', $fcnt & 0xFFFFFFFF) . "\x00"
            . chr(strlen($dataWithoutMic) & 0xFF);
        return substr(AES::cmac($key, $b0 . $dataWithoutMic), 0, 4);
    }

    /** FRMPayload 加解密（对称）。FPort=0 用 NwkSKey，否则 AppSKey。 */
    public static function frmPayloadCrypt(string $key, int $dir, string $devAddr, int $fcnt, string $payload): string
    {
        $a = "\x01" . "\x00\x00\x00\x00" . chr($dir & 0xFF)
            . $devAddr . pack('V', $fcnt & 0xFFFFFFFF) . "\x00" . "\x00";
        return AES::ctrXcrypt($key, $a, $payload);
    }

    // ============ LoRaWAN 1.1 ============

    /**
     * 1.1 会话密钥派生（get_s_key）。
     * b[0]=typ; b[1..4]=joinNonce(3 字节, 传输顺序); b[4..12]=joinEui(OTAA) 或 netId(ABP/opt_neg=false);
     * b[12..14]=devNonce(2 字节); b[14..16]=0x00 0x00。返回 AES-128-ECB(key, b)。
     *
     * @param int    $typ        0x01=FNwkSIntKey, 0x02=AppSKey, 0x03=SNwkSIntKey, 0x04=NwkSEncKey
     * @param string $key        派生密钥所依据的root key（网络密钥用 NwkKey，AppSKey 用 AppKey）
     * @param string $joinNonce  3 字节
     * @param string $joinEui    8 字节（OTAA 的 JoinEUI）
     * @param string $devNonce   2 字节
     */
    public static function getSKey1_1(int $typ, string $key, string $joinNonce, string $joinEui, string $devNonce): string
    {
        $b = chr($typ & 0xFF) . $joinNonce . $joinEui . $devNonce . "\x00\x00";
        return AES::ecbEncrypt($key, $b);
    }

    /**
     * 1.1 OTAA 会话密钥派生。
     * @return array [fNwkSIntKey, sNwkSIntKey, nwkSEncKey, appSKey] 各 16 字节
     */
    public static function computeSessionKeys1_1(string $nwkKey, string $appKey, string $joinNonce, string $joinEui, string $devNonce): array
    {
        return [
            self::getSKey1_1(0x01, $nwkKey, $joinNonce, $joinEui, $devNonce), // FNwkSIntKey
            self::getSKey1_1(0x03, $nwkKey, $joinNonce, $joinEui, $devNonce), // SNwkSIntKey
            self::getSKey1_1(0x04, $nwkKey, $joinNonce, $joinEui, $devNonce), // NwkSEncKey
            self::getSKey1_1(0x02, $appKey,  $joinNonce, $joinEui, $devNonce), // AppSKey
        ];
    }

    /**
     * 1.1 上行数据 MIC。
     * MIC = cmacF[0:2] || cmacS[0:2]
     *  B0 = 0x49 | ConfFCnt(3, 上行=0) | dir(0) | DevAddr(4) | FCnt(4 LE) | 0x00 | len(1)  —— 用 FNwkSIntKey
     *  B1 = 0x49 | ConfFCnt(3) | TxDr | TxCh | dir(0) | DevAddr(4) | FCnt(4 LE) | 0x00 | len(1) —— 用 SNwkSIntKey
     *
     * @param int $txDr 上行数据速率索引（LoRaWAN 1.1 规范要求，需与设备发射时一致；通常由网关 rx_info 提供）
     * @param int $txCh 上行信道索引（同 txDr 要求）
     */
    public static function dataMICUp1_1(string $fNwkSIntKey, string $sNwkSIntKey, string $devAddr, int $fcnt, string $dataWithoutMic, int $txDr = 0, int $txCh = 0): string
    {
        $len = strlen($dataWithoutMic) & 0xFF;
        $b0 = "\x49" . "\x00\x00\x00" . "\x00" . $devAddr . pack('V', $fcnt & 0xFFFFFFFF) . "\x00" . chr($len);
        $b1 = "\x49" . "\x00\x00\x00" . chr($txDr & 0xFF) . chr($txCh & 0xFF) . "\x00"
            . $devAddr . pack('V', $fcnt & 0xFFFFFFFF) . "\x00" . chr($len);
        $cmacF = AES::cmac($fNwkSIntKey, $b0 . $dataWithoutMic);
        $cmacS = AES::cmac($sNwkSIntKey, $b1 . $dataWithoutMic);
        return substr($cmacF, 0, 2) . substr($cmacS, 0, 2);
    }

    /**
     * 1.1 下行数据 MIC。
     * B0 = 0x49 | ConfFCnt(3) | dir(1) | DevAddr(4) | FCnt(4 LE) | 0x00 | len(1) —— 用 SNwkSIntKey
     * ConfFCnt：确认帧下发时填被确认的那个上行 FCnt（24 位），非确认帧填 0。
     */
    public static function dataMICDown1_1(string $sNwkSIntKey, string $devAddr, int $fcnt, int $confFCnt, string $dataWithoutMic): string
    {
        $len = strlen($dataWithoutMic) & 0xFF;
        $cf = pack('V', $confFCnt & 0xFFFFFFFF); // 取低 24 位由下式拼接
        $b0 = "\x49" . $cf[0] . $cf[1] . $cf[2] . "\x01"
            . $devAddr . pack('V', $fcnt & 0xFFFFFFFF) . "\x00" . chr($len);
        return substr(AES::cmac($sNwkSIntKey, $b0 . $dataWithoutMic), 0, 4);
    }

    // ---- 1.1 Join：与 1.0 流程相同，但密钥为 NwkKey（且派生用 JoinEUI） ----

    /** 1.1 Join-Request MIC = cmac(NwkKey, MHDR|JoinEUI|DevEUI|DevNonce)[0:4] */
    public static function joinRequestMIC1_1(string $nwkKey, string $dataWithoutMic): string
    {
        return substr(AES::cmac($nwkKey, $dataWithoutMic), 0, 4);
    }

    public static function verifyJoinRequestMIC1_1(string $nwkKey, string $frame): bool
    {
        if (strlen($frame) < 12) {
            return false;
        }
        $data = substr($frame, 0, -4);
        $mic = substr($frame, -4);
        return self::joinRequestMIC1_1($nwkKey, $data) === $mic;
    }

    /** 1.1 Join-Accept MIC（明文，密钥 NwkKey） */
    public static function joinAcceptMIC1_1(string $nwkKey, string $plaintextWithoutMic): string
    {
        return substr(AES::cmac($nwkKey, $plaintextWithoutMic), 0, 4);
    }

    /**
     * 1.1 加密 Join Accept 负载（NS 下发前）。规范同样规定 NS 用 AES decrypt 加密（与 1.0 同方向）。
     * 密钥为 NwkKey。
     */
    public static function encryptJoinAccept1_1(string $nwkKey, string $plaintext): string
    {
        $out = '';
        for ($i = 0; $i < strlen($plaintext); $i += 16) {
            $out .= AES::ecbDecrypt($nwkKey, substr($plaintext, $i, 16));
        }
        return $out;
    }
}
