<?php
/**
 * Relay（TS011-1.0.0）忠实移植冒烟测试。
 * 编解码向量取自 ChirpStack lrwn/src/relay.rs + lrwn/src/maccommand.rs 的测试用例，
 * 与 chirpstack/src/maccommand/relay_conf.rs 的 handle 行为对齐。
 */
require __DIR__ . '/../bootstrap.php';

use holastack\Core\Relay;
use holastack\Core\MacCommands;

$ok = 0; $fail = 0;
function chk($cond, $name) { global $ok, $fail; echo ($cond ? "PASS " : "FAIL ") . $name . "\n"; $cond ? $ok++ : $fail++; }
function hexb($s) { return implode(',', array_map('ord', str_split($s))); }

// ================ 1) UplinkMetadata（lrwn relay.rs 测试向量） ================
// UplinkMetadata { dr: 5, snr: 9, rssi: -110, wor_channel: 1 } -> [213, 191, 1]
$b = Relay::uplinkMetadataToBytes(5, 9, -110, 1);
chk(hexb($b) === '213,191,1', "uplinkMetadataToBytes -> 213,191,1 (got " . hexb($b) . ")");

$m = Relay::uplinkMetadataFromBytes("\xD5\xBF\x01");
chk($m['dr'] === 5 && $m['snr'] === 9 && $m['rssi'] === -110 && $m['wor_channel'] === 1,
    "uplinkMetadataFromBytes round-trip dr=5 snr=9 rssi=-110 wor=1 (got " . json_encode($m) . ")");

// 钳位：snr 30 -> 11，rssi -150 -> -142
$b2 = Relay::uplinkMetadataToBytes(5, 30, -150, 1);
$m2 = Relay::uplinkMetadataFromBytes($b2);
chk($m2['snr'] === 11 && $m2['rssi'] === -142, "uplinkMetadata clamps snr->11 rssi->-142 (got snr={$m2['snr']} rssi={$m2['rssi']})");

// ================ 2) encode_freq / decode_freq（lrwn helpers.rs） ================
chk(Relay::decodeFreq(Relay::encodeFreq(868100000)) === 868100000, "encodeFreq/decodeFreq round-trip 868.1 MHz");
chk(hexb(Relay::encodeFreq(868100000)) === '40,118,132', "encodeFreq(868100000) -> 40,118,132 (got " . hexb(Relay::encodeFreq(868100000)) . ")");
// 2.4GHz：÷2 后 ×200
chk(Relay::decodeFreq(Relay::encodeFreq(2450000000)) === 2450000000, "encodeFreq/decodeFreq round-trip 2450 MHz");

// ================ 3) ForwardUplinkReq（lrwn relay.rs 测试向量） ================
// metadata + freq=868100000 + payload [0xE0, 0x01, 0x02, 0x03] -> [213,191,1,40,118,132,224,1,2,3]
$phy = "\xE0\x01\x02\x03";
$fu = Relay::forwardUplinkReqToBytes(5, 9, -110, 1, 868100000, $phy);
chk(hexb($fu) === '213,191,1,40,118,132,224,1,2,3',
    "forwardUplinkReqToBytes -> lrwn vector (got " . hexb($fu) . ")");

$fuDec = Relay::forwardUplinkReqFromBytes($fu);
chk($fuDec['metadata']['dr'] === 5 && $fuDec['frequency'] === 868100000 && $fuDec['payload'] === $phy,
    "forwardUplinkReqFromBytes round-trip (got " . json_encode($fuDec) . ")");

// ================ 4) ForwardDownlinkReq（lrwn relay.rs 测试向量） ================
chk(Relay::forwardDownlinkReqFromBytes(Relay::forwardDownlinkReqToBytes($phy)) === $phy, "ForwardDownlinkReq pass-through");

// ================ 5) ChannelSettingsRelay（lrwn maccommand.rs 测试向量） ================
// start_stop=1, cad_periodicity=3, default_ch_idx=0, second_ch_idx=1, second_ch_dr=4, second_ch_ack_offset=5 -> [165, 44]
$cs = Relay::channelSettingsToBytes(5, 4, 1, 0, 3, 1);
chk(hexb($cs) === '165,44', "channelSettingsToBytes -> 165,44 (got " . hexb($cs) . ")");
$csDec = Relay::channelSettingsFromBytes($cs);
chk($csDec['second_ch_ack_offset'] === 5 && $csDec['second_ch_dr'] === 4 && $csDec['second_ch_idx'] === 1
    && $csDec['default_ch_idx'] === 0 && $csDec['cad_periodicity'] === 3 && $csDec['start_stop'] === 1,
    "channelSettingsFromBytes round-trip (got " . json_encode($csDec) . ")");

// ================ 6) RelayConfReq / RelayConfAns（lrwn maccommand.rs 测试向量） ================
// bytes: vec![64, 165, 44, 40, 118, 132]（CID 0x40 = 64）
$req = Relay::buildRelayConfReq(5, 4, 1, 0, 3, 1, 868100000);
chk(hexb($req) === '64,165,44,40,118,132', "RelayConfReq -> lrwn vector 64,165,44,40,118,132 (got " . hexb($req) . ")");
$reqDec = Relay::decodeRelayConfReq(substr($req, 1));
chk($reqDec['second_ch_freq'] === 868100000 && $reqDec['channel_settings']['second_ch_dr'] === 4,
    "decodeRelayConfReq round-trip freq=868.1MHz dr=4");

// RelayConfAns 0x3F（63）全 ack；0x15（21）部分 ack（freq+dr+default）
$ans = Relay::decodeRelayConfAns("\x3F");
$allAck = $ans['second_ch_freq_ack'] && $ans['second_ch_ack_offset_ack'] && $ans['second_ch_dr_ack']
    && $ans['second_ch_idx_ack'] && $ans['default_ch_idx_ack'] && $ans['cad_periodicity_ack'];
chk($allAck, "decodeRelayConfAns 0x3F -> all 6 ack true");
$ans15 = Relay::decodeRelayConfAns("\x15");
chk($ans15['second_ch_freq_ack'] && !$ans15['second_ch_ack_offset_ack'] && $ans15['second_ch_dr_ack']
    && !$ans15['second_ch_idx_ack'] && $ans15['default_ch_idx_ack'] && !$ans15['cad_periodicity_ack'],
    "decodeRelayConfAns 0x15 -> freq+dr+default ack only (got " . json_encode($ans15) . ")");

// ================ 7) handleRelayConfAns：全 ACK → 写 relay_state（对齐 relay_conf.rs） ================
$device = ['pending_mac' => json_encode([MacCommands::CID_RELAY_CONF_REQ => base64_encode($req)])];
$r = Relay::handleRelayConfAns($device, "\x3F");
chk($r['acked'] === true && $r['bytes'] === null && $r['mustRespond'] === false, "handleRelayConfAns all-ack -> acked=true");
$rs = json_decode($device['relay_state'] ?? '', true);
chk(is_array($rs) && $rs['enabled'] === true && $rs['second_channel_freq'] === 868100000
    && $rs['second_channel_dr'] === 4 && $rs['cad_periodicity'] === 3 && $rs['second_channel_ack_offset'] === 5,
    "handleRelayConfAns writes relay_state JSON (got " . ($device['relay_state'] ?? 'null') . ")");
chk(MacCommands::getPending($device, MacCommands::CID_RELAY_CONF_REQ) === null, "handleRelayConfAns clears pending");

// ================ 8) handleRelayConfAns：nack → 不写 relay_state ================
$device2 = ['pending_mac' => json_encode([MacCommands::CID_RELAY_CONF_REQ => base64_encode($req)])];
$r2 = Relay::handleRelayConfAns($device2, "\x15");
chk($r2['acked'] === false && !isset($device2['relay_state']), "handleRelayConfAns partial-ack -> acked=false, no relay_state");

// ================ 9) handleRelayConfAns：无 pending → 忽略 ================
$device3 = ['pending_mac' => ''];
$r3 = Relay::handleRelayConfAns($device3, "\x3F");
chk($r3['acked'] === false && !isset($device3['relay_state']), "handleRelayConfAns no pending -> ignored");

// ================ 10) FilterListReq（lrwn maccommand.rs 测试向量） ================
// idx=3, action=Forward, eui=[1,2,3,4,5,6,7,8,8,7,6,5,4,3,2,2] -> [66,176,1,2,2,3,4,5,6,7,8,8,7,6,5,4,3,2,1]
$fl = Relay::buildFilterListReq(3, Relay::FL_ACTION_FORWARD, [1,2,3,4,5,6,7,8,8,7,6,5,4,3,2,2]);
chk(hexb($fl) === '66,176,1,2,2,3,4,5,6,7,8,8,7,6,5,4,3,2,1',
    "FilterListReq -> lrwn vector (got " . hexb($fl) . ")");
$flAns = Relay::decodeFilterListAns("\x02");
chk($flAns['filter_list_len_ack'] === true && $flAns['filter_list_action_ack'] === false,
    "decodeFilterListAns 0x02 -> len ack only");

// ================ 11) EndDeviceConfReq（lrwn maccommand.rs 测试向量） ================
// activation=Dynamic(2), smart=3, ChannelSettingsED{ack=5,dr=4,idx=1,backoff=63}, freq=868100000
// -> [65, 11, 165, 126, 40, 118, 132]
$edc = Relay::buildEndDeviceConfReq(Relay::RMA_DYNAMIC, 3, 5, 4, 1, 63, 868100000);
chk(hexb($edc) === '65,11,165,126,40,118,132',
    "EndDeviceConfReq -> lrwn vector (got " . hexb($edc) . ")");
$edAns = Relay::decodeEndDeviceConfAns("\x0D");
chk($edAns['second_ch_freq_ack'] && !$edAns['second_ch_dr_ack'] && $edAns['second_ch_idx_ack'] && $edAns['backoff_ack'],
    "decodeEndDeviceConfAns 0x0D -> freq+idx+backoff ack");

// ================ 12) scheduleConf：relay out of sync（对齐 downlink/data.rs test_update_relay_conf） ================
// relay_state 旧 freq=868300000，relay_params 期望 freq=868500000 -> 排队 RelayConfReq
// 期望字节 [64,154,36,200,133,132]（start_stop=1,cad=1,def=0,second_idx=1,dr=3,ack=2,freq=868500000）
$rp12 = [
    'is_relay' => true, 'relay_enabled' => true, 'relay_cad_periodicity' => 1,
    'default_channel_index' => 0, 'second_channel_freq' => 868500000,
    'second_channel_dr' => 3, 'second_channel_ack_offset' => 2,
];
$dev12 = [
    'pending_mac' => '',
    'relay_state' => json_encode([
        'enabled' => true, 'cad_periodicity' => 1, 'default_channel_index' => 0,
        'second_channel_freq' => 868300000, 'second_channel_dr' => 3, 'second_channel_ack_offset' => 2,
    ]),
];
$mac12 = Relay::scheduleConf($dev12, $rp12);
chk(hexb($mac12) === '64,154,36,200,133,132',
    "scheduleConf out-of-sync RelayConfReq -> 64,154,36,200,133,132 (got " . hexb($mac12) . ")");
chk(MacCommands::getPending($dev12, MacCommands::CID_RELAY_CONF_REQ) === $mac12,
    "scheduleConf sets pending CID 0x40");
chk($dev12['relay_state'] === json_encode([
        'enabled' => true, 'cad_periodicity' => 1, 'default_channel_index' => 0,
        'second_channel_freq' => 868300000, 'second_channel_dr' => 3, 'second_channel_ack_offset' => 2,
    ]),
    "scheduleConf keeps relay_state unchanged until ack (ChirpStack retry semantics)");

// ================ 13) scheduleConf：relay in sync -> 不排队 ================
$dev13 = [
    'pending_mac' => '',
    'relay_state' => json_encode([
        'enabled' => true, 'cad_periodicity' => 1, 'default_channel_index' => 0,
        'second_channel_freq' => 868500000, 'second_channel_dr' => 3, 'second_channel_ack_offset' => 2,
    ]),
];
chk(Relay::scheduleConf($dev13, $rp12) === '' && MacCommands::getPending($dev13, MacCommands::CID_RELAY_CONF_REQ) === null,
    "scheduleConf in-sync -> no RelayConfReq");

// ================ 14) scheduleConf：全默认 params + 无 relay_state -> 不排队（对齐 Relay::default() 语义） ================
$dev14 = ['pending_mac' => '', 'relay_state' => ''];
chk(Relay::scheduleConf($dev14, ['is_relay' => true]) === '',
    "scheduleConf default-vs-default -> no command");
chk(Relay::scheduleConf($dev14, []) === '', "scheduleConf no relay flags -> no command");

// ================ 15) scheduleConf：second_channel_freq=0 -> second_ch_idx=0 ================
$dev15 = ['pending_mac' => '', 'relay_state' => ''];
$mac15 = Relay::scheduleConf($dev15, [
    'is_relay' => true, 'relay_enabled' => true, 'relay_cad_periodicity' => 1,
    'default_channel_index' => 0, 'second_channel_freq' => 0,
    'second_channel_dr' => 3, 'second_channel_ack_offset' => 2,
]);
chk(hexb($mac15) === '64,26,36,0,0,0',
    "scheduleConf freq=0 -> second_ch_idx=0 (got " . hexb($mac15) . ")");

// ================ 16) scheduleConf：ed out of sync（对齐 downlink/data.rs test_update_end_device_conf） ================
// relay_state 旧 ed_activation_mode=0，relay_params 期望 EnableRelayMode(1) -> 排队 EndDeviceConfReq
// 期望字节 [65,5,156,32,40,118,132]（rma=1,smart=1,ack=4,dr=3,idx=1,backoff=16,freq=868100000）
$rp16 = [
    'is_relay_ed' => true, 'ed_activation_mode' => 1, 'ed_smart_enable_level' => 1,
    'ed_back_off' => 16, 'second_channel_freq' => 868100000,
    'second_channel_dr' => 3, 'second_channel_ack_offset' => 4,
];
$dev16 = [
    'pending_mac' => '',
    'relay_state' => json_encode([
        'ed_activation_mode' => 0, 'ed_smart_enable_level' => 1, 'ed_back_off' => 16,
        'second_channel_freq' => 868100000, 'second_channel_dr' => 3, 'second_channel_ack_offset' => 4,
    ]),
];
$mac16 = Relay::scheduleConf($dev16, $rp16);
chk(hexb($mac16) === '65,5,156,32,40,118,132',
    "scheduleConf ed out-of-sync EndDeviceConfReq -> 65,5,156,32,40,118,132 (got " . hexb($mac16) . ")");
chk(MacCommands::getPending($dev16, MacCommands::CID_END_DEVICE_CONF_REQ) === $mac16,
    "scheduleConf sets pending CID 0x41");

// ================ 17) scheduleConf：ed in sync -> 不排队；is_relay+is_relay_ed 可同时排队 ================
$dev17 = [
    'pending_mac' => '',
    'relay_state' => json_encode([
        'ed_activation_mode' => 1, 'ed_smart_enable_level' => 1, 'ed_back_off' => 16,
        'second_channel_freq' => 868100000, 'second_channel_dr' => 3, 'second_channel_ack_offset' => 4,
    ]),
];
chk(Relay::scheduleConf($dev17, $rp16) === '', "scheduleConf ed in-sync -> no EndDeviceConfReq");

$dev18 = ['pending_mac' => '', 'relay_state' => ''];
// is_relay + is_relay_ed 同时生效；second_channel_* 为共享字段（与 RelayParams 定义一致）
$rp18 = [
    'is_relay' => true, 'relay_enabled' => true, 'relay_cad_periodicity' => 1,
    'default_channel_index' => 0,
    'is_relay_ed' => true, 'ed_activation_mode' => 1, 'ed_smart_enable_level' => 1,
    'ed_back_off' => 16,
    'second_channel_freq' => 868100000, 'second_channel_dr' => 3, 'second_channel_ack_offset' => 4,
];
$mac18 = Relay::scheduleConf($dev18, $rp18);
chk(hexb(substr($mac18, 0, 6)) === '64,156,36,40,118,132' && hexb(substr($mac18, 6)) === '65,5,156,32,40,118,132',
    "scheduleConf relay+ed both out-of-sync -> RelayConfReq then EndDeviceConfReq (got " . hexb($mac18) . ")");

// ================ 18) handleEndDeviceConfAns（对齐 end_device_conf.rs 测试） ================
$edc18 = Relay::buildEndDeviceConfReq(Relay::RMA_ENABLE, 1, 4, 3, 1, 16, 868100000);
$dev19 = ['pending_mac' => json_encode([MacCommands::CID_END_DEVICE_CONF_REQ => base64_encode($edc18)])];
$r19 = Relay::handleEndDeviceConfAns($dev19, "\x0F"); // 全 4 ack
chk($r19['acked'] === true, "handleEndDeviceConfAns all-ack -> acked=true");
$rs19 = json_decode($dev19['relay_state'] ?? '', true);
chk(is_array($rs19) && $rs19['ed_activation_mode'] === 1 && $rs19['ed_smart_enable_level'] === 1
    && $rs19['second_channel_ack_offset'] === 4 && $rs19['second_channel_dr'] === 3
    && $rs19['ed_back_off'] === 16 && $rs19['second_channel_freq'] === 868100000,
    "handleEndDeviceConfAns writes ed_* relay_state (got " . ($dev19['relay_state'] ?? 'null') . ")");
chk(MacCommands::getPending($dev19, MacCommands::CID_END_DEVICE_CONF_REQ) === null,
    "handleEndDeviceConfAns clears pending");
$dev20 = ['pending_mac' => json_encode([MacCommands::CID_END_DEVICE_CONF_REQ => base64_encode($edc18)])];
$r20 = Relay::handleEndDeviceConfAns($dev20, "\x0D"); // dr nack
chk($r20['acked'] === false && !isset($dev20['relay_state']),
    "handleEndDeviceConfAns dr-nack -> acked=false, no relay_state");
$dev21 = ['pending_mac' => ''];
chk(Relay::handleEndDeviceConfAns($dev21, "\x0F")['acked'] === false,
    "handleEndDeviceConfAns no pending -> ignored");

// ================ 19) handleFilterListAns（对齐 filter_list.rs 测试） ================
$fl19 = Relay::buildFilterListReq(3, Relay::FL_ACTION_FORWARD, [1,2,3,4,5,6,7,8,8,7,6,5,4,3,2,2]);
$dev22 = [
    'pending_mac'  => json_encode([MacCommands::CID_FILTER_LIST_REQ => base64_encode($fl19)]),
    'relay_state'  => json_encode(['filters' => [
        ['index' => 3, 'action' => 1, 'join_eui' => '0102030405060708', 'dev_eui' => '0807060504030201', 'provisioned' => false],
    ]]),
];
$r22 = Relay::handleFilterListAns($dev22, "\x07"); // 全 3 ack
chk($r22['acked'] === true, "handleFilterListAns all-ack -> acked=true");
$rs22 = json_decode($dev22['relay_state'] ?? '', true);
chk(is_array($rs22) && isset($rs22['filters'][0]) && $rs22['filters'][0]['provisioned'] === true,
    "handleFilterListAns marks filter provisioned (got " . ($dev22['relay_state'] ?? 'null') . ")");
// NoRule + ack → 移除 filter
$dev23 = [
    'pending_mac'  => json_encode([MacCommands::CID_FILTER_LIST_REQ => base64_encode(
        Relay::buildFilterListReq(3, Relay::FL_ACTION_NO_RULE, [])
    )]),
    'relay_state'  => json_encode(['filters' => [
        ['index' => 3, 'action' => 1, 'provisioned' => true],
    ]]),
];
Relay::handleFilterListAns($dev23, "\x07");
$rs23 = json_decode($dev23['relay_state'] ?? '', true);
chk(is_array($rs23) && count($rs23['filters'] ?? []) === 0,
    "handleFilterListAns NoRule removes filter (got " . ($dev23['relay_state'] ?? 'null') . ")");

echo "\n$ok passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
