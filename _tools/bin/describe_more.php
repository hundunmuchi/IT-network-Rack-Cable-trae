<?php
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach (['device_groups','device_group_device','syslog','eventlog','ipv4_addresses','ipv4_networks','processors','bill_ports','ports_fdb','links','locations'] as $t) {
    echo "=== $t ===\n";
    $cols = $pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['name']}  ({$c['type']})\n";
    }
}
