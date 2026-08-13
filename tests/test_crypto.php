<?php
/**
 * 密码学自测：使用权威测试向量验证 AES-128 ECB / CMAC / LoRaWAN MIC / 加解密。
 * 运行：php tests/test_crypto.php
 */
require __DIR__ . '/../bootstrap.php';
use holastack\Crypto\AES;
use holastack\Crypto\LoRaWANCrypto;
use holastack\Core\Frame;

$pass = 0; $fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $name\n"; }
    else { $fail++; echo "FAIL  $name\n"; }
}

// ---- AES-128 ECB (FIPS 197 标准向量) ----
$key = hex2bin('000102030405060708090a0b0c0d0e0f');
$pt  = hex2bin('00112233445566778899aabbccddeeff');
$ct  = AES::ecbEncrypt($key, $pt);
check('AES-128 ECB FIPS vector', bin2hex($ct) === '69c4e0d86a7b0430d8cdb78070b4c55a');
check('AES-128 ECB decrypt roundtrip', AES::ecbDecrypt($key, $ct) === $pt);

// ---- AES-128 CMAC (NIST SP 800-38B 标准向量) ----
$k = hex2bin('2b7e151628aed2a6abf7158809cf4f3c');
check('CMAC empty', bin2hex(AES::cmac($k, '')) === 'bb1d6929e95937287fa37d129b756746');
$m1 = hex2bin('6bc1bee22e409f96e93d7e117393172a');
check('CMAC 1-block', bin2hex(AES::cmac($k, $m1)) === '070a16b46b4d4144f79bdd9dd04a287c');

// ---- Join Request MIC ----
$appKey  = $k;
$appEUI  = hex2bin('0102030405060708');
$devEUI  = hex2bin('0807060504030201');
$devNonce = "\x00\x01";
$joined  = "\x00" . $appEUI . $devEUI . $devNonce;
$mic     = LoRaWANCrypto::joinRequestMIC($appKey, $joined);
check('JoinRequest MIC verify', LoRaWANCrypto::verifyJoinRequestMIC($appKey, $joined . $mic));

// ---- Join Accept 加解密往返 ----
$appNonce = hex2bin('010203');
$netId    = hex2bin('010203');
$devAddr  = hex2bin('0a0b0c0d');
$dlSettings = 0x12; // RX1DRoffset=1, RX2DR=2 —— 用非零值验证 DLSettings 字节位置
$rxDelay    = 0x05;
$ja       = Frame::buildJoinAccept($appKey, $appNonce, $netId, $devAddr, $dlSettings, $rxDelay, null);
check('JoinAccept mtype=0x20', ord($ja[0]) === 0x20);
$decBody = LoRaWANCrypto::decryptJoinAccept($appKey, substr($ja, 1));
$expMic  = LoRaWANCrypto::joinAcceptMIC($appKey, "\x20" . $appNonce . $netId . $devAddr . chr($dlSettings) . chr($rxDelay));
$expPlainBody = $appNonce . $netId . $devAddr . chr($dlSettings) . chr($rxDelay) . $expMic;
check('JoinAccept decrypt roundtrip', $decBody === $expPlainBody);
check('JoinAccept DLSettings byte', ord($decBody[10]) === $dlSettings);
check('JoinAccept RxDelay byte', ord($decBody[11]) === $rxDelay);
check('JoinAccept MIC correct', substr($decBody, -4) === $expMic);

// ---- 会话密钥派生 ----
[$nwk, $app] = LoRaWANCrypto::computeSessionKeys($appKey, $appNonce, $netId, $devNonce);
check('NwkSKey length=16', strlen($nwk) === 16);
check('AppSKey length=16', strlen($app) === 16);

// ---- 数据帧 FRMPayload 加解密往返 ----
$payload = 'Hello';
$enc = LoRaWANCrypto::frmPayloadCrypt($app, 0, $devAddr, 1, $payload);
$dec = LoRaWANCrypto::frmPayloadCrypt($app, 0, $devAddr, 1, $enc);
check('FRMPayload crypt roundtrip', $dec === $payload);

// ---- 数据帧 MIC ----
$mhdr = "\x40";
$fhdr = $devAddr . "\x00" . pack('v', 1);
$fport = "\x0a";
$without = $mhdr . $fhdr . $fport . $enc;
$micD = LoRaWANCrypto::dataMIC($nwk, 0, $devAddr, 1, $without);
check('Data MIC verify', LoRaWANCrypto::dataMIC($nwk, 0, $devAddr, 1, $without) === $micD);
$phyD = $without . $micD;
$p = Frame::parseDataUp($phyD);
check('ParseDataUp fcnt_lo', $p['fcnt_lo'] === 1);
check('ParseDataUp fport', $p['fport'] === 10);

echo "\n==== $pass passed, $fail failed ====\n";
exit($fail > 0 ? 1 : 0);
