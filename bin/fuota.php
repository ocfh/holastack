<?php
/**
 * FUOTA（固件无线升级）管理 CLI。
 *
 *   php bin/fuota.php list-campaigns
 *   php bin/fuota.php create-campaign <name> <appId> <multicastGroupId> [fragSize]
 *   php bin/fuota.php fragment <campaignId> <firmwareFile.bin>
 */
require __DIR__ . '/../bootstrap.php';

use holastack\Core\Fuota;

function fuota_usage(): void
{
    echo "Usage:\n";
    echo "  php bin/fuota.php list-campaigns\n";
    echo "  php bin/fuota.php create-campaign <name> <appId> <multicastGroupId> [fragSize]\n";
    echo "  php bin/fuota.php fragment <campaignId> <firmwareFile.bin>\n";
}

$argv = array_slice($GLOBALS['argv'], 1);
if (empty($argv)) {
    fuota_usage();
    exit(1);
}

$cmd = $argv[0];
switch ($cmd) {
    case 'list-campaigns':
        $rows = Fuota::listCampaigns();
        echo count($rows) . " campaign(s):\n";
        foreach ($rows as $r) {
            echo "  [{$r['id']}] {$r['name']} app={$r['application_id']} mg={$r['multicast_group_id']} fragSize={$r['fragment_size']}\n";
        }
        break;

    case 'create-campaign':
        if (count($argv) < 4) { fuota_usage(); exit(1); }
        $res = Fuota::createCampaign([
            'name'              => $argv[1],
            'application_id'    => (int) $argv[2],
            'multicast_group_id' => (int) $argv[3],
            'fragment_size'     => (int) ($argv[4] ?? 200),
        ]);
        echo $res['error'] ?? ("created campaign id={$res['id']}\n");
        break;

    case 'fragment':
        if (count($argv) < 3) { fuota_usage(); exit(1); }
        $fw = @file_get_contents($argv[2]);
        if ($fw === false) {
            echo "cannot read firmware file: {$argv[2]}\n";
            exit(1);
        }
        $res = Fuota::enqueueCampaign((int) $argv[1], $fw);
        if (isset($res['error'])) {
            echo "error: {$res['error']}\n";
            exit(1);
        }
        echo "campaign {$argv[1]}: frag_n={$res['meta']['frag_n']} frag_size={$res['meta']['frag_size']} digest={$res['meta']['digest']}\n";
        echo "generated " . count($res['downlinks']) . " multicast downlink frame(s)\n";
        break;

    default:
        fuota_usage();
        exit(1);
}
