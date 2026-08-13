<?php
/**
 * LoRaWAN 1.0.x 加解密与完整性封装。
 * 所有密钥/负载均为原始二进制串。
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

    /** 解密 Join Accept 负载（AES-128 ECB，逐 16 字节块）。 */
    public static function decryptJoinAccept(string $appKey, string $encrypted): string
    {
        $out = '';
        for ($i = 0; $i < strlen($encrypted); $i += 16) {
            $out .= AES::ecbDecrypt($appKey, substr($encrypted, $i, 16));
        }
        return $out;
    }

    /** 加密 Join Accept 负载（服务器下发前）。 */
    public static function encryptJoinAccept(string $appKey, string $plaintext): string
    {
        $out = '';
        for ($i = 0; $i < strlen($plaintext); $i += 16) {
            $out .= AES::ecbEncrypt($appKey, substr($plaintext, $i, 16));
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
}
