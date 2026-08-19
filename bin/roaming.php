<?php









require __DIR__ . '/../bootstrap.php';

use holastack\Core\Roaming;
use holastack\DB\Database;

function roam_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/roaming.php list-servers\n";
    echo "  php bin/roaming.php add-server <name> <url> [net_id] [kek_label]\n";
    echo "  php bin/roaming.php del-server <id>\n";
    echo "  php bin/roaming.php test-forward <net_id> <pduHex>\n";
}

$argv = array_slice($GLOBALS['argv'], 1);
if (empty($argv)) {
    roam_usage();
    exit(1);
}

$cmd = $argv[0];
switch ($cmd) {
    case 'list-servers':
        $rows = Database::fetchAll("SELECT id, name, net_id, kind, protocol, server, enabled, async_timeout FROM roaming_servers ORDER BY id");
        echo count($rows) . " roaming server(s):\n";
        foreach ($rows as $r) {
            echo "  [{$r['id']}] {$r['name']} net_id={$r['net_id']} {$r['kind']}/{$r['protocol']} -> {$r['server']} enabled={$r['enabled']} async_timeout={$r['async_timeout']}ms\n";
        }
        break;

    case 'add-server':
        if (count($argv) < 3) { roam_usage(); exit(1); }
        Database::execute(
            "INSERT INTO roaming_servers (name, server, net_id, kek_label, kind, protocol, async_timeout, enabled, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [
                $argv[1], $argv[2], strtoupper($argv[3] ?? ''), $argv[4] ?? '',
                'PASSIVE', 'BI_1_0', 250, 1, time(),
            ]
        );
        $id = Database::lastInsertId();
        echo "added roaming server id=$id (run Roaming::setup() / restart NS to activate)\n";
        break;

    case 'del-server':
        if (count($argv) < 2) { roam_usage(); exit(1); }
        Database::execute("DELETE FROM roaming_servers WHERE id=?", [(int) $argv[1]]);
        echo "deleted roaming server {$argv[1]}\n";
        break;

    case 'test-forward':
        if (count($argv) < 3) { roam_usage(); exit(1); }
        Roaming::setup();
        $netId = strtoupper($argv[1]);
        $client = Roaming::getClient($netId);
        if (!$client) {
            echo "roaming client not found for net_id=$netId (check roaming_servers + Roaming::setup)\n";
            exit(1);
        }
        $pdu = hex2bin($argv[2]);
        $msg = Roaming::buildXmitDataReq($client, [
            'phy'       => base64_encode($pdu),
            'dev_eui'   => '',
            'dev_addr'  => bin2hex(substr($pdu, 1, 4)),
            'freq'      => 868.1,
            'dr'        => 'SF7BW125',
            'recv_time' => time(),
            'gw_id'     => '00000000',
            'rssi'      => -60,
            'snr'       => 7.0,
            'region'    => ELW_DEFAULT_REGION,
        ]);
        $resp = Roaming::forward($client, $msg);
        echo "response: " . json_encode($resp, JSON_UNESCAPED_SLASHES) . "\n";
        break;

    default:
        roam_usage();
        exit(1);
}
