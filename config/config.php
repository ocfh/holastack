<?php




















$local = [];
$localFile = __DIR__ . '/local.php';
if (file_exists($localFile)) {
    $loaded = require $localFile;
    if (is_array($loaded)) {
        $local = $loaded;
    }
}



$dsn = $local['db_dsn'] ?? (getenv('ELW_DB_DSN') ?: ('sqlite:' . __DIR__ . '/../runtime/server.db'));

define('ELW_DB_DSN', $dsn);



if (strpos(ELW_DB_DSN, 'sqlite:') === 0) {
    $dbDir = dirname(substr(ELW_DB_DSN, 7));
    if ($dbDir !== '' && $dbDir !== '.' && !is_dir($dbDir)) {
        @mkdir($dbDir, 0777, true);
    }
}

define('ELW_DB_USER', $local['db_user'] ?? (getenv('ELW_DB_USER') ?: ''));
define('ELW_DB_PASS', $local['db_pass'] ?? (getenv('ELW_DB_PASS') ?: ''));



define('ELW_INSTALLED', !empty($local['installed']) || (getenv('ELW_INSTALLED') ?: false) === '1');



define('ELW_GW_UDP_PORT', (int)($local['gw_udp_port'] ?? (getenv('ELW_GW_UDP_PORT') ?: 1700)));



define('ELW_WEB_PORT', (int)(getenv('ELW_WEB_PORT') ?: 8080));



define('ELW_DEFAULT_REGION', $local['region'] ?? (getenv('ELW_REGION') ?: 'EU868'));





define('ELW_BEACON_EPOCH', (int)($local['beacon_epoch'] ?? (getenv('ELW_BEACON_EPOCH') ?: 0)));



define('ELW_PING_PERIOD', (int)($local['ping_period'] ?? (getenv('ELW_PING_PERIOD') ?: 2)));



define('ELW_ROOT', __DIR__ . '/..');



define('ELW_LOG_DIR', ELW_ROOT . '/runtime/logs');







define('ELW_NET_ID', $local['net_id'] ?? (getenv('ELW_NET_ID') ?: '000000'));


define('ELW_ROAMING_ENABLED', (bool)($local['roaming_enabled'] ?? (getenv('ELW_ROAMING_ENABLED') ?: false)));
