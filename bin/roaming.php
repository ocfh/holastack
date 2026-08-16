<?php
/**
 * 漫游（Roaming, Backend Interface）管理 CLI。
 *
 *   php bin/roaming.php list-servers
 *   php bin/roaming.php add-server <name> <url> [kind] [protocol]
 *   php bin/roaming.php del-server <serverId>
 *   php bin/roaming.php forward-uplink <serverId> <devEui> <pduHex>
 */
require __DIR__ . '/../bootstrap.php';

use holastack\Core\Roaming;

function roaming_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/roaming.php list-servers\n";
    echo "  php bin/roaming.php add-server <name> <url> [kind] [protocol]\n";
    echo "  php bin/roaming.php del-server <serverId>\n";
    echo "  php bin/roaming.php forward-uplink <serverId> <devEui> <pduHex>\n";
}

$argv = array_slice($GLOBALS['argv'], 1);
if (empty($argv)) {
    roaming_usage();
    exit(1);
}

$cmd = $argv[0];
switch ($cmd) {
    case 'list-servers':
        $rows = Roaming::listServers();
        echo count($rows) . " roaming server(s):\n";
        foreach ($rows as $r) {
            echo "  [{$r['id']}] {$r['name']} {$r['kind']}/{$r['protocol']} -> {$r['server']} enabled={$r['enabled']}\n";
        }
        break;

    case 'add-server':
        if (count($argv) < 3) { roaming_usage(); exit(1); }
        $res = Roaming::addServer([
            'name'     => $argv[1],
            'server'   => $argv[2],
            'kind'     => $argv[3] ?? 'PASSIVE',
            'protocol' => $argv[4] ?? 'BI_1_0',
        ]);
        echo $res['error'] ?? ("added roaming server id={$res['id']}\n");
        break;

    case 'del-server':
        if (count($argv) < 2) { roaming_usage(); exit(1); }
        Roaming::deleteServer((int) $argv[1]);
        echo "deleted roaming server {$argv[1]}\n";
        break;

    case 'forward-uplink':
        if (count($argv) < 4) { roaming_usage(); exit(1); }
        $server = Roaming::getServer((int) $argv[1]);
        if (!$server) {
            echo "roaming server not found\n";
            exit(1);
        }
        $pdu = hex2bin($argv[3]);
        $msg = Roaming::buildXmitDataReq($server, [
            'phy'      => base64_encode($pdu),
            'dev_eui'  => $argv[2],
            'dev_addr' => '',
            'gw_id'    => '',
            'dr'       => '',
        ]);
        $resp = Roaming::forward($server, $msg);
        echo "response: " . json_encode($resp, JSON_UNESCAPED_SLASHES) . "\n";
        break;

    default:
        roaming_usage();
        exit(1);
}
