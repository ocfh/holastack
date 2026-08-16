<?php
/**
 * 测试配置示例。复制为 config.php 后修改（config.php 不入库）。
 *
 * 1) 启动 holastack 站点，记下可访问的 base_url；
 * 2) 在后台「应用 → API Key」页生成一条 Key，填入 api_key；
 * 3) 可选：填入一个真实存在的设备 EUI 以启用下行 / 设备级用例。
 */
return [
    'base_url'     => 'http://127.0.0.1:8080',
    'api_key'      => 'holask-在这里填入你的应用API Key',
    'test_dev_eui' => '',   // 例如 '1122334455667788'（16 hex，可选）
];
