<?php
/**
 * 消息接口测试：上行 / 下行列表、limit 与 dev_eui 筛选。
 *
 * 运行：php tests/api/03_messages/test_messages.php
 */
$config = require __DIR__ . '/../load_config.php';
require __DIR__ . '/../ApiTestClient.php';

$client = new ApiTestClient($config['base_url'], $config['api_key'] ?? '', $config['test_dev_eui'] ?? '');

// 1. 上行列表
[$code, $body] = $client->request('GET', '/v1/uplinks');
$client->check('GET /v1/uplinks 返回 200', $code === 200);
$client->check('GET /v1/uplinks 含 data', isset($body['data']));

// 2. limit 生效
[$c2, $b2] = $client->request('GET', '/v1/uplinks', ['limit' => 3]);
$client->check('GET /v1/uplinks?limit=3 返回 200', $c2 === 200);
$client->check('limit=3 数据条数 <= 3', count($b2['data'] ?? []) <= 3);

// 3. 下行列表
[$c3, $b3] = $client->request('GET', '/v1/downlinks');
$client->check('GET /v1/downlinks 返回 200', $c3 === 200);
$client->check('GET /v1/downlinks 含 data', isset($b3['data']));

// 4. 按设备筛选（用配置的测试设备）
$eui = $client->testDevEui();
if ($eui !== '') {
    [$c4, $b4] = $client->request('GET', '/v1/uplinks', ['dev_eui' => $eui]);
    $client->check('按 dev_eui 筛选上行 返回 200', $c4 === 200);
    $ok = true;
    foreach ($b4['data'] ?? [] as $u) {
        if (strtolower($u['dev_eui'] ?? '') !== $eui) {
            $ok = false;
            break;
        }
    }
    $client->check('筛选结果均属该设备', $ok);

    [$c5, $b5] = $client->request('GET', '/v1/devices/' . $eui . '/uplinks');
    $client->check('GET /v1/devices/{eui}/uplinks 返回 200', $c5 === 200);
    $client->check('设备级上行响应含 data', isset($b5['data']));
} else {
    echo "  SKIP 设备级筛选用例（未配置 test_dev_eui）\n";
}

exit($client->summary('messages'));
