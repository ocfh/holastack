<?php










require __DIR__ . '/../bootstrap.php';

use holastack\Core\Relay;

function relay_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/relay.php list-gateways\n";
    echo "  php bin/relay.php add-gateway <name> <relayDevEui> [region]\n";
    echo "  php bin/relay.php del-gateway <gatewayId>\n";
    echo "  php bin/relay.php list-devices <gatewayId>\n";
    echo "  php bin/relay.php provision <gatewayId> <devEui> [devAddr]\n";
}

$argv = array_slice($GLOBALS['argv'], 1);
if (empty($argv)) {
    relay_usage();
    exit(1);
}

$cmd = $argv[0];
switch ($cmd) {
    case 'list-gateways':
        $rows = Relay::listGateways();
        echo count($rows) . " relay gateway(s):\n";
        foreach ($rows as $r) {
            echo "  [{$r['id']}] {$r['name']} devEui={$r['relay_dev_eui']} region={$r['region']}\n";
        }
        break;

    case 'add-gateway':
        if (count($argv) < 3) { relay_usage(); exit(1); }
        $res = Relay::addGateway([
            'name'         => $argv[1],
            'relay_dev_eui' => $argv[2],
            'region'       => $argv[3] ?? ELW_DEFAULT_REGION,
        ]);
        echo $res['error'] ?? ("added relay gateway id={$res['id']}\n");
        break;

    case 'del-gateway':
        if (count($argv) < 2) { relay_usage(); exit(1); }
        Relay::deleteGateway((int) $argv[1]);
        echo "deleted relay gateway {$argv[1]}\n";
        break;

    case 'list-devices':
        if (count($argv) < 2) { relay_usage(); exit(1); }
        $rows = Relay::listDevices((int) $argv[1]);
        echo count($rows) . " relayed device(s):\n";
        foreach ($rows as $r) {
            echo "  [{$r['id']}] devEui={$r['dev_eui']} devAddr={$r['dev_addr']} mac={$r['mac_version']}\n";
        }
        break;

    case 'provision':
        if (count($argv) < 3) { relay_usage(); exit(1); }
        $res = Relay::provisionEndDevice((int) $argv[1], $argv[2], ['dev_addr' => $argv[3] ?? '']);
        echo $res['error'] ?? ("provisioned device id={$res['id']}\n");
        break;

    default:
        relay_usage();
        exit(1);
}
