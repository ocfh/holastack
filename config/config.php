<?php
/**
 * 全局配置。
 *
 * 读取优先级（后者覆盖前者）：
 *   1. 环境变量（ELW_DB_DSN / ELW_DB_USER / ELW_DB_PASS ...）
 *   2. 安装器生成的 config/local.php（生产部署由 Web 安装向导写入）
 *   3. 内置默认（SQLite，零安装、可直接运行验证）
 *
 * config/local.php 结构（安装器写入，已纳入 .gitignore，不要提交）：
 *   <?php return [
 *     'db_dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=lorawan;charset=utf8mb4',
 *     'db_user' => 'lorawan',
 *     'db_pass' => 'secret',
 *     'installed' => true,
 *   ];
 */

// ---- 本地安装配置（若存在则由安装向导生成）----
$local = [];
$localFile = __DIR__ . '/local.php';
if (file_exists($localFile)) {
    $loaded = require $localFile;
    if (is_array($loaded)) {
        $local = $loaded;
    }
}

// 数据库 DSN：默认 SQLite（存放于 runtime/，已纳入 .gitignore）
$dsn = $local['db_dsn'] ?? (getenv('ELW_DB_DSN') ?: ('sqlite:' . __DIR__ . '/../runtime/server.db'));

define('ELW_DB_DSN', $dsn);
define('ELW_DB_USER', $local['db_user'] ?? (getenv('ELW_DB_USER') ?: ''));
define('ELW_DB_PASS', $local['db_pass'] ?? (getenv('ELW_DB_PASS') ?: ''));

// 是否已通过安装向导完成初始化（用于 Web 端跳转/鉴权判断）
define('ELW_INSTALLED', !empty($local['installed']) || (getenv('ELW_INSTALLED') ?: false) === '1');

// 网关 UDP 监听端口（Semtech Packet Forwarder 默认 1700）
define('ELW_GW_UDP_PORT', (int)($local['gw_udp_port'] ?? (getenv('ELW_GW_UDP_PORT') ?: 1700)));

// Web 管理后台端口
define('ELW_WEB_PORT', (int)(getenv('ELW_WEB_PORT') ?: 8080));

// 默认区域（EU868 / CN470 / US915 ...）
define('ELW_DEFAULT_REGION', $local['region'] ?? (getenv('ELW_REGION') ?: 'EU868'));

// Class B 信标参考时刻（Unix 秒）。真实部署应由网关 GPS 时间同步；
// 此处提供可配置基准，ping 时隙 = beacon_epoch + k * ping_period。
define('ELW_BEACON_EPOCH', (int)($local['beacon_epoch'] ?? (getenv('ELW_BEACON_EPOCH') ?: 0)));

// Class B ping 时隙周期（秒），默认 2s。设备级可在 devices.ping_period 覆盖。
define('ELW_PING_PERIOD', (int)($local['ping_period'] ?? (getenv('ELW_PING_PERIOD') ?: 2)));

// 项目根目录
define('ELW_ROOT', __DIR__ . '/..');

// 运行日志目录
define('ELW_LOG_DIR', ELW_ROOT . '/runtime/logs');

// ---- 漫游（Roaming / Backend Interface TS002）----
// 本 NS 的 NetID（6 hex 字符，如 '000000'）。用于判定 DevAddr 是否属于本网：
// 不属于本网 NetID 前缀的 DevAddr 视为漫游，转发给伙伴 NS。
define('ELW_NET_ID', $local['net_id'] ?? (getenv('ELW_NET_ID') ?: '000000'));
// 漫游是否启用（影响 Join/上行未命中本地设备时是否触发转发）。
define('ELW_ROAMING_ENABLED', (bool)($local['roaming_enabled'] ?? (getenv('ELW_ROAMING_ENABLED') ?: false)));
