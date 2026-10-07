<?php
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
echo "ports count: " . $pdo->query("SELECT COUNT(*) FROM ports")->fetchColumn() . "\n";
echo "devices:\n";
foreach ($pdo->query("SELECT device_id, hostname, sysName FROM devices") as $r) {
    echo "  {$r['device_id']} {$r['hostname']} sysName={$r['sysName']}\n";
}
echo "links count: " . $pdo->query("SELECT COUNT(*) FROM links")->fetchColumn() . "\n";
