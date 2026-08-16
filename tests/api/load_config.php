<?php
/**
 * 测试配置加载器。
 * 优先读取同目录 config.php（不入库），否则从环境变量读取：
 *   HOLASTACK_BASE_URL      站点地址，如 http://127.0.0.1:8080
 *   HOLASTACK_API_KEY       应用 API Key（holask-...）
 *   HOLASTACK_TEST_DEV_EUI  可选：用于下行/设备级用例的真实设备 EUI
 *
 * 返回：['base_url'=>string, 'api_key'=>string, 'test_dev_eui'=>string]
 */
if (file_exists(__DIR__ . '/config.php')) {
    $cfg = require __DIR__ . '/config.php';
    return [
        'base_url'     => $cfg['base_url'] ?? 'http://127.0.0.1:8080',
        'api_key'      => $cfg['api_key']  ?? '',
        'test_dev_eui' => $cfg['test_dev_eui'] ?? '',
    ];
}

return [
    'base_url'     => getenv('HOLASTACK_BASE_URL') ?: 'http://127.0.0.1:8080',
    'api_key'      => getenv('HOLASTACK_API_KEY') ?: '',
    'test_dev_eui' => getenv('HOLASTACK_TEST_DEV_EUI') ?: '',
];
