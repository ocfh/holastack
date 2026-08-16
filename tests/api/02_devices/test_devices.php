<?php
/**
 * 设备接口测试：列表、单设备详情、密钥剥离、越界(404)。
 *
 * 运行：php tests/api/02_devices/test_devices.php
 */
$config = require __DIR__ . '/../load_config.php';
require __DIR__ . '/../ApiTestClient.php';

$client = new ApiTestClient($config['base_url'], $config['api_key'] ?? '', $config['test_dev_eui'] ?? '');

// 1. 设备列表
[$code, $body] = $client->request('GET', '/v1/devices');
$client->check('GET /v1/devices 返回 200', $code === 200);
$client->check('GET /v1/devices 含 data 数组', isset($body['data']) && is_array($body['data']));

// 2. 响应已剥离根密钥
$secretKeys = ['app_key', 'nwk_s_key', 'app_s_key', 'appkey', 'nwkskey', 'appskey'];
if (!empty($body['data'])) {
    $d0 = $body['data'][0];
    $leaked = array_intersect(array_keys($d0), $secretKeys);
    $client->check('设备响应不泄露根密钥', count($leaked) === 0);
    $client->check('设备响应含 dev_eui', isset($d0['dev_eui']));
    $client->check('设备响应含 online 状态', isset($d0['online']));
} else {
    echo "  SKIP 密钥剥离用例（该应用下暂无设备）\n";
}

// 3. 单设备详情（用列表里第一个真实设备）
if (!empty($body['data'][0]['dev_eui'])) {
    $eui = $body['data'][0]['dev_eui'];
    [$c2, $b2] = $client->request('GET', '/v1/devices/' . $eui);
    $client->check('GET /v1/devices/{eui} 返回 200', $c2 === 200);
    $client->check('单设备响应含 device.dev_eui', ($b2['device']['dev_eui'] ?? '') === strtolower($eui));
    $client->check('单设备响应含 counts', isset($b2['counts']['uplinks']));
} else {
    echo "  SKIP 单设备详情用例（该应用下暂无设备）\n";
}

// 4. 越界设备 -> 404
[$c3, $b3] = $client->request('GET', '/v1/devices/0000000000000000');
$client->check('不存在的设备 返回 404', $c3 === 404);
$client->check('不存在的设备 error=device_not_found', ($b3['error'] ?? '') === 'device_not_found');

exit($client->summary('devices'));
