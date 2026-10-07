<?php
// Check current DB state.
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = ['devices', 'ports', 'ipv4_addresses', 'ipv6_addresses', 'locations', 'device_groups', 'device_group_device', 'alert_rules', 'sensors', 'syslog', 'eventlog', 'users', 'bill_ports', 'ports_fdb', 'links', 'storage', 'processors', 'mempools', 'devices_perf'];

foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo str_pad($t, 25) . " = $c\n";
    } catch (Throwable $e) {
        echo str_pad($t, 25) . " (table not found)\n";
    }
}

echo "\n--- devices ---\n";
foreach ($pdo->query("SELECT device_id, hostname, ip, sysName, hardware, os, status, type FROM devices") as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n--- locations ---\n";
foreach ($pdo->query("SELECT * FROM locations") as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
