<?php






namespace holastack\Crypto;

class AES
{
    

    public static function ecbEncrypt(string $key, string $data): string
    {
        return openssl_encrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    public static function ecbDecrypt(string $key, string $data): string
    {
        return openssl_decrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    public static function xorBytes(string $a, string $b): string
    {
        $len = strlen($a);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($a[$i]) ^ ord($b[$i]));
        }
        return $out;
    }

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
            $a[15] = chr($i + 1); 

            $ks = self::ecbEncrypt($key, $a);
            $chunk = substr($payload, $i * 16, 16);
            $out .= self::xorBytes($chunk, $ks);
        }
        return $out;
    }
}
