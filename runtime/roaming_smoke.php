<?php
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../src/Core/Roaming.php';
use holastack\Core\Roaming;
use holastack\Core\RoamingClient;
use holastack\Core\GpsTime;
use holastack\Crypto\AES;

$ok = 0; $fail = 0;
function chk($cond, $name) { global $ok, $fail; echo ($cond ? "PASS " : "FAIL ") . $name . "\n"; $cond ? $ok++ : $fail++; }

// 1) GPS 时间
$gps = GpsTime::toGps(time());
chk(is_int($gps) && $gps > 1000000000, "GpsTime::toGps returns plausible int ($gps)");
chk(GpsTime::fromGps($gps) >= time()-2 && GpsTime::fromGps($gps) <= time()+2, "GpsTime::fromGps round-trips");

// 2) DevAddr 路由
$client = new RoamingClient(['net_id' => '123456', 'server' => 'https://homenet.example/roaming', 'kek_label' => '']);
Roaming::setClient('123456', $client);
chk(Roaming::isEnabled(), "isEnabled true after setClient");

// DevAddr 属于伙伴 123456 前缀（空口大端：字节 12 34 56 00 -> NetID = 123456）
$devAddr = hex2bin('12345600');
chk(Roaming::netIdFromDevAddr($devAddr) === '123456', "netIdFromDevAddr -> 123456 (got " . Roaming::netIdFromDevAddr($devAddr) . ")");
chk(Roaming::isRoamingDevAddr($devAddr) === true, "isRoamingDevAddr true for foreign NetID 123456");
$got = Roaming::getNetIdsForDevAddr($devAddr);
chk($got == [123456], "getNetIdsForDevAddr matches partner 123456 (got " . json_encode($got) . ")");

// 本网 DevAddr（localNsId 默认 000000，所以 000000 前缀应被排除）
$localDev = hex2bin('00000000');
chk(Roaming::isRoamingDevAddr($localDev) === false, "isRoamingDevAddr false for local/test NetID 000000");

// 测试 NetID 000001 排除（DevAddr 前 3 字节 00 00 01 -> NetID=000001 -> hex '00000100'）
$testDev = hex2bin('00000100');
chk(Roaming::isRoamingDevAddr($testDev) === false, "isRoamingDevAddr false for test NetID 000001");

// 3) JoinEUI 路由
$appEui = hex2bin('5634120000000000'); // 高3字节 563412
chk(Roaming::clientForJoinEui($appEui) !== null, "clientForJoinEui finds partner by JoinEUI 563412");
$appEui2 = hex2bin('9999990000000000'); // 单伙伴兜底
chk(Roaming::clientForJoinEui($appEui2) !== null, "clientForJoinEui single-partner fallback works");

// 4) 报文构造 + 签名
$ul = [
    'phy' => base64_encode(hex2bin('40AABBCCDDEE00112233445566778899')),
    'dev_eui' => '',
    'dev_addr' => bin2hex($devAddr),
    'freq' => 868.1, 'dr' => 'SF7BW125',
    'recv_time' => time(), 'gw_id' => 'AABBCCDD', 'rssi' => -55, 'snr' => 7.5, 'region' => 'EU868',
];
$xmit = Roaming::buildXmitDataReq($client, $ul);
chk($xmit['MessageType'] === 'XmitDataReq', "buildXmitDataReq MessageType");
chk($xmit['SenderID'] === Roaming::localNsId(), "buildXmitDataReq SenderID = local");
chk($xmit['ReceiverID'] === '123456', "buildXmitDataReq ReceiverID = partner");
chk(isset($xmit['ULMetaData']['RecvTime']) && is_int($xmit['ULMetaData']['RecvTime']), "ULMetaData.RecvTime is GPS int");
chk($xmit['ULMetaData']['DevAddr'] === bin2hex($devAddr), "ULMetaData.DevAddr");

$join = Roaming::buildJoinReq($client, ['phy' => base64_encode(hex2bin('00AABBCCDDEEFF0011223344')), 'dev_eui' => 'AABBCCDDEEFF0011', 'mac_version' => '1.0.3']);
chk($join['MessageType'] === 'JoinReq', "buildJoinReq MessageType");
chk($join['DevEUI'] === 'AABBCCDDEEFF0011', "buildJoinReq DevEUI");

$sig = Roaming::sign($client, $xmit);
chk(preg_match('/^[0-9a-f]{32}$/', $sig) === 1, "sign returns 32-hex CMAC ($sig)");

// 5) 入站签名校验：模拟 Home NS 用「自身 SenderID=123456 / ReceiverID=本地」对入站报文签名，
//    服务 NS 用伙伴 KEK 重算 CMAC 比对（body = SenderID|ReceiverID|MessageType|PHYPayload）。
$inbound = $xmit;
$inbound['SenderID'] = '123456';
$inbound['ReceiverID'] = Roaming::localNsId();
$kek = str_repeat("\x00", 16);
$expectedInboundSig = bin2hex(AES::cmac($kek, '123456' . Roaming::localNsId() . $inbound['MessageType'] . $inbound['PHYPayload']));
chk(Roaming::verifyInboundSignature('123456', $inbound, $expectedInboundSig) === true, "verifyInboundSignature true for correct sig");
chk(Roaming::verifyInboundSignature('123456', $inbound, 'deadbeef') === false, "verifyInboundSignature false for wrong sig");

// 6) PULL_RESP 字节结构（复制入站端点逻辑做一致性校验）
$phy = hex2bin('40AABBCCDDEE00112233445566778899');
$txpk = ['imme'=>false,'tmst'=>123456,'freq'=>868.1,'rfch'=>0,'powe'=>16,'modu'=>'LORA','datr'=>'SF7BW125','codr'=>'4/5','ipol'=>true,'size'=>strlen($phy),'data'=>base64_encode($phy)];
$json = json_encode(['txpk'=>$txpk]);
$pkt = "\x02" . "\x00\x00" . "\x03" . $json;
chk($pkt[3] === "\x03", "PULL_RESP id byte = 0x03");
chk(strpos($json, '"ipol":true') !== false, "PULL_RESP json contains ipol:true");

echo "\n$ok passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
