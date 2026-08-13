<?php
/**
 * AES-128 基础运算：ECB 块加解密、CMAC、CTR 流加解密。
 * 依赖 PHP openssl 扩展提供 AES-128-ECB。
 * 所有输入/输出均为原始二进制串（非 base64 / hex）。
 */
namespace holastack\Crypto;

class AES
{
    /** AES-128 ECB 加密（无填充），输入必须是 16 字节整数倍。 */
    public static function ecbEncrypt(string $key, string $data): string
    {
        return openssl_encrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    /** AES-128 ECB 解密（无填充）。 */
    public static function ecbDecrypt(string $key, string $data): string
    {
        return openssl_decrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    /** 两个等长二进制串逐字节异或。 */
    public static function xorBytes(string $a, string $b): string
    {
        $len = strlen($a);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($a[$i]) ^ ord($b[$i]));
        }
        return $out;
    }

    /** 128 位整数左移 1 位（用于 CMAC 子密钥生成）。 */
    private static function leftShift128(string $block): string
    {
        $carry = 0;
        $out = '';
        for ($i = 15; $i >= 0; $i--) {
            $b = ord($block[$i]);
            $nb = ($b << 1) | $carry;
            $out = chr($nb & 0xFF) . $out;
            $carry = ($b & 0x80) ? 1 : 0;
        }
        return $out;
    }

    /**
     * AES-128 CMAC，返回 16 字节完整 MAC。
     * 参考 NIST SP 800-38B。
     */
    public static function cmac(string $key, string $msg): string
    {
        $bs = 16;
        $zero = str_repeat("\x00", $bs);
        $Rb = str_repeat("\x00", 15) . "\x87";

        $L = self::ecbEncrypt($key, $zero);
        $K1 = self::leftShift128($L);
        if ((ord($L[0]) & 0x80) !== 0) {
            $K1 = self::xorBytes($K1, $Rb);
        }
        $K2 = self::leftShift128($K1);
        if ((ord($K1[0]) & 0x80) !== 0) {
            $K2 = self::xorBytes($K2, $Rb);
        }

        $msgLen = strlen($msg);
        $n = ($msgLen === 0) ? 1 : intval(ceil($msgLen / $bs));
        $lastBlock = substr($msg, ($n - 1) * $bs);
        if (strlen($lastBlock) === $bs) {
            $m = self::xorBytes($lastBlock, $K1);
        } else {
            // 填充 0x80 后补 0
            $padded = $lastBlock . "\x80" . str_repeat("\x00", $bs - strlen($lastBlock) - 1);
            $m = self::xorBytes($padded, $K2);
        }

        $x = $zero;
        for ($i = 0; $i < $n - 1; $i++) {
            $chunk = substr($msg, $i * $bs, $bs);
            $x = self::ecbEncrypt($key, self::xorBytes($x, $chunk));
        }
        $x = self::ecbEncrypt($key, self::xorBytes($x, $m));
        return $x;
    }

    /**
     * AES-128 CTR 模式加解密（对称）。
     * @param string $aBlock 16 字节基础计数器块，其最后一字节为块计数器（从 1 递增）。
     * @param string $payload 明文或密文
     */
    public static function ctrXcrypt(string $key, string $aBlock, string $payload): string
    {
        $len = strlen($payload);
        if ($len === 0) {
            return '';
        }
        $blocks = intval(ceil($len / 16));
        $out = '';
        for ($i = 0; $i < $blocks; $i++) {
            $a = $aBlock;
            $a[15] = chr($i + 1); // 块计数器 1..N（LoRaWAN payload 远小于 255*16）
            $ks = self::ecbEncrypt($key, $a);
            $chunk = substr($payload, $i * 16, 16);
            $out .= self::xorBytes($chunk, $ks);
        }
        return $out;
    }
}
