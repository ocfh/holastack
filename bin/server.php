<?php






require __DIR__ . '/../bootstrap.php';

use holastack\Core\NetworkServer;

echo "========================================\n";
echo " HolaStack  (Network Server) \n";
echo " Region: " . ELW_DEFAULT_REGION . "   UDP port: " . ELW_GW_UDP_PORT . "\n";
echo " DB: " . ELW_DB_DSN . "\n";
echo "========================================\n";

$ns = new NetworkServer(ELW_GW_UDP_PORT);
$ns->run();
