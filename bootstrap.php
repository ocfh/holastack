<?php
/**
 * 引导文件：PSR-4 自动加载 + 载入配置。
 * 任何入口脚本（NS 服务 / Web 后端）第一行 require 本文件即可。
 */
namespace holastack;

// 自动加载 src/ 下的 holastack\* 类
spl_autoload_register(function ($class) {
    $prefix = 'holastack\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $rel = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/config/config.php';

if (!is_dir(ELW_LOG_DIR)) {
    @mkdir(ELW_LOG_DIR, 0777, true);
}

// 全局服务端翻译助手（elw_t 等），定义于独立的无命名空间文件。
require_once __DIR__ . '/src/langHelpers.php';
