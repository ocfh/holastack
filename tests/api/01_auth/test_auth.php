<?php
/**
 * 鉴权测试：验证 /v1/ 各端点在 合法 / 缺失 / 无效 API Key 下的行为。
 *
 * 运行：php tests/api/01_auth/test_auth.php
 */
$config = require __DIR__ . '/../load_config.php';
require __DIR__ . '/../ApiTestClient.php';

$client = new ApiTestClient($config['base_url'], $config['api_key'] ?? '');

// 1. 服务发现（根路径）
[$code, $body] = $client->request('GET', '/v1/');
$client->check('GET /v1/ 返回 200', $code === 200);
$client->check('GET /v1/ 列出 endpoints', isset($body['endpoints']) && is_array($body['endpoints']) && count($body['endpoints']) > 0);

// 2. 合法 Key -> 200
[$code, $body] = $client->request('GET', '/v1/info');
$client->check('合法 Key: /v1/info 返回 200', $code === 200);
$client->check('合法 Key: 响应含 application.id', isset($body['application']['id']));
$client->check('合法 Key: 响应含 counts.devices', isset($body['counts']['devices']));

// 3. 缺失鉴权 -> 401
[$code, $body] = $client->requestNoAuth('GET', '/v1/info');
$client->check('缺失 Key: 返回 401', $code === 401);
$client->check('缺失 Key: error=invalid_api_key', ($body['error'] ?? '') === 'invalid_api_key');

// 4. 无效 Key -> 401
$bad = new ApiTestClient($config['base_url'], 'holask-deadbeefdeadbeefdeadbeefdeadbeefdeadbe');
[$code, $body] = $bad->request('GET', '/v1/info');
$client->check('无效 Key: 返回 401', $code === 401);
$client->check('无效 Key: error=invalid_api_key', ($body['error'] ?? '') === 'invalid_api_key');

// 5. 空 Bearer -> 401
$empty = new ApiTestClient($config['base_url'], '');
[$code] = $empty->request('GET', '/v1/info');
$client->check('空 Key: 返回 401', $code === 401);

exit($client->summary('auth'));
