<?php
/**
 * 下行下发测试：合法入队、参数校验、越界设备。
 * 需要配置 test_dev_eui（应用下真实存在的设备）。
 *
 * 运行：php tests/api/04_downlink/test_downlink.php
 */
$config = require __DIR__ . '/../load_config.php';
require __DIR__ . '/../ApiTestClient.php';

$client = new ApiTestClient($config['base_url'], $config['api_key'] ?? '', $config['test_dev_eui'] ?? '');

$eui = $client->testDevEui();
if ($eui === '') {
    echo "  SKIP 未配置 test_dev_eui，跳过下行用例（在 config.php 设置后重跑）\n";
    exit($client->summary('downlink'));
}

// 1. 合法入队
[$code, $body] = $client->request(
    'POST',
    '/v1/devices/' . $eui . '/downlink',
    [],
    ['port' => 1, 'payload' => '01020304', 'confirmed' => false]
);
$client->check('合法下行入队 返回 201', $code === 201);
$client->check('入队返回 id>0', isset($body['id']) && $body['id'] > 0);
$client->check('入队返回 status=pending', ($body['status'] ?? '') === 'pending');

// 2. payload 非 hex -> 400
[$c2, $b2] = $client->request(
    'POST',
    '/v1/devices/' . $eui . '/downlink',
    [],
    ['port' => 1, 'payload' => 'zzzz', 'confirmed' => false]
);
$client->check('非法 payload(非hex) 返回 400', $c2 === 400);
$client->check('非法 payload error=payload must be hex', ($b2['error'] ?? '') === 'payload must be hex');

// 3. port 越界 -> 400
[$c3, $b3] = $client->request(
    'POST',
    '/v1/devices/' . $eui . '/downlink',
    [],
    ['port' => 300, 'payload' => '0102', 'confirmed' => false]
);
$client->check('port=300 返回 400', $c3 === 400);
$client->check('port 越界 error=port must be 1..223', ($b3['error'] ?? '') === 'port must be 1..223');

// 4. 不存在设备 -> 404
[$c4, $b4] = $client->request(
    'POST',
    '/v1/devices/0000000000000000/downlink',
    [],
    ['port' => 1, 'payload' => '0102', 'confirmed' => false]
);
$client->check('越界设备下行 返回 404', $c4 === 404);
$client->check('越界设备 error=device_not_found', ($b4['error'] ?? '') === 'device_not_found');

exit($client->summary('downlink'));
