<?php
namespace holastack\Core;

use holastack\Crypto\LoRaWANCrypto;







class Frame
{
    

    public const MTYPE_JOIN_REQUEST       = 0x00;
    public const MTYPE_JOIN_ACCEPT        = 0x01;
    public const MTYPE_UNCONFIRMED_UP      = 0x02;
    public const MTYPE_UNCONFIRMED_DOWN    = 0x03;
    public const MTYPE_CONFIRMED_UP        = 0x04;
    public const MTYPE_CONFIRMED_DOWN      = 0x05;

    public static function mtype(string $phy): int
    {
        return (ord($phy[0]) >> 5) & 0x07;
    }

    


    public static function parseJoinRequest(string $phy): array
    {
        

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
        return substr($phy, 0, 19); 

    }

    


    




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

        

        $encryptedBody = LoRaWANCrypto::encryptJoinAccept($appKey, substr($plaintext, 1));
        return "\x20" . $encryptedBody;
    }

    


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
            'adr_ack_req' => ($fctrl & 0x40) ? 1 : 0,
            'ack'       => ($fctrl & 0x20) ? 1 : 0,
            'fopts'     => $fopts,
            'fcnt_lo'   => $fcntLo,
            'fport'     => $fport,
            'frmpayload'=> $frmpayload,
            'mic'       => $mic,
            'data_without_mic' => $dataWithoutMic,
        ];
    }

    public static function fullFCnt(int $fcntLo, int $lastFCnt): int
    {
        $lastHi = ($lastFCnt >> 16) & 0xFFFF;
        $lastLo = $lastFCnt & 0xFFFF;
        if ($fcntLo >= $lastLo) {
            return ($lastHi << 16) | $fcntLo;
        }
        

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

    public static function verifyDataMIC1_1(string $fNwkSIntKey, string $sNwkSIntKey, string $devAddr, int $fcnt, string $dataWithoutMic, string $mic, int $txDr = 0, int $txCh = 0): bool
    {
        return LoRaWANCrypto::dataMICUp1_1($fNwkSIntKey, $sNwkSIntKey, $devAddr, $fcnt, $dataWithoutMic, $txDr, $txCh) === $mic;
    }

    

    public static function decryptFRMPayload1_1(string $nwkSEncKey, string $appSKey, int $dir, string $devAddr, int $fcnt, ?int $fport, string $payload): string
    {
        if ($payload === '' || $fport === null) {
            return '';
        }
        $key = ($fport === 0) ? $nwkSEncKey : $appSKey;
        return LoRaWANCrypto::frmPayloadCrypt($key, $dir, $devAddr, $fcnt, $payload);
    }

    





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
