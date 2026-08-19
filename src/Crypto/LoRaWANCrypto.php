<?php













namespace holastack\Crypto;

class LoRaWANCrypto
{
    

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

    

    public static function joinAcceptMIC(string $appKey, string $plaintextWithoutMic): string
    {
        return substr(AES::cmac($appKey, $plaintextWithoutMic), 0, 4);
    }

    





    public static function decryptJoinAccept(string $appKey, string $encrypted): string
    {
        $out = '';
        for ($i = 0; $i < strlen($encrypted); $i += 16) {
            $out .= AES::ecbEncrypt($appKey, substr($encrypted, $i, 16));
        }
        return $out;
    }

    








    public static function encryptJoinAccept(string $appKey, string $plaintext): string
    {
        $out = '';
        for ($i = 0; $i < strlen($plaintext); $i += 16) {
            $out .= AES::ecbDecrypt($appKey, substr($plaintext, $i, 16));
        }
        return $out;
    }

    




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

    







    public static function dataMIC(string $key, int $dir, string $devAddr, int $fcnt, string $dataWithoutMic): string
    {
        $b0 = "\x49" . "\x00\x00\x00\x00" . chr($dir & 0xFF)
            . $devAddr . pack('V', $fcnt & 0xFFFFFFFF) . "\x00"
            . chr(strlen($dataWithoutMic) & 0xFF);
        return substr(AES::cmac($key, $b0 . $dataWithoutMic), 0, 4);
    }

    

    public static function frmPayloadCrypt(string $key, int $dir, string $devAddr, int $fcnt, string $payload): string
    {
        $a = "\x01" . "\x00\x00\x00\x00" . chr($dir & 0xFF)
            . $devAddr . pack('V', $fcnt & 0xFFFFFFFF) . "\x00" . "\x00";
        return AES::ctrXcrypt($key, $a, $payload);
    }

    


    











    public static function getSKey1_1(int $typ, string $key, string $joinNonce, string $joinEui, string $devNonce): string
    {
        $b = chr($typ & 0xFF) . $joinNonce . $joinEui . $devNonce . "\x00\x00";
        return AES::ecbEncrypt($key, $b);
    }

    




    public static function computeSessionKeys1_1(string $nwkKey, string $appKey, string $joinNonce, string $joinEui, string $devNonce): array
    {
        return [
            self::getSKey1_1(0x01, $nwkKey, $joinNonce, $joinEui, $devNonce), 

            self::getSKey1_1(0x03, $nwkKey, $joinNonce, $joinEui, $devNonce), 

            self::getSKey1_1(0x04, $nwkKey, $joinNonce, $joinEui, $devNonce), 

            self::getSKey1_1(0x02, $appKey,  $joinNonce, $joinEui, $devNonce), 

        ];
    }

    









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

    





    public static function dataMICDown1_1(string $sNwkSIntKey, string $devAddr, int $fcnt, int $confFCnt, string $dataWithoutMic): string
    {
        $len = strlen($dataWithoutMic) & 0xFF;
        $cf = pack('V', $confFCnt & 0xFFFFFFFF); 

        $b0 = "\x49" . $cf[0] . $cf[1] . $cf[2] . "\x01"
            . $devAddr . pack('V', $fcnt & 0xFFFFFFFF) . "\x00" . chr($len);
        return substr(AES::cmac($sNwkSIntKey, $b0 . $dataWithoutMic), 0, 4);
    }

    


    

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

    

    public static function joinAcceptMIC1_1(string $nwkKey, string $plaintextWithoutMic): string
    {
        return substr(AES::cmac($nwkKey, $plaintextWithoutMic), 0, 4);
    }

    




    public static function encryptJoinAccept1_1(string $nwkKey, string $plaintext): string
    {
        $out = '';
        for ($i = 0; $i < strlen($plaintext); $i += 16) {
            $out .= AES::ecbDecrypt($nwkKey, substr($plaintext, $i, 16));
        }
        return $out;
    }
}
