<?php
// Final end-to-end status check.

$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== FINAL DEVICE STATUS ===\n";
$rows = $pdo->query("SELECT device_id, hostname, os, status, sysName, hardware, uptime, last_polled, last_discovered FROM devices ORDER BY device_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "Device {$r['device_id']} ({$r['hostname']}): os={$r['os']} status={$r['status']} sysName={$r['sysName']} hardware={$r['hardware']}\n";
    echo "  uptime={$r['uptime']} last_polled={$r['last_polled']} last_discovered={$r['last_discovered']}\n";
}

echo "\n=== PORT COUNTS PER DEVICE ===\n";
foreach ($pdo->query("SELECT device_id, COUNT(*) AS port_count FROM ports GROUP BY device_id") as $r) {
    echo "Device {$r['device_id']}: {$r['port_count']} ports\n";
}

echo "\n=== PROCESSORS PER DEVICE ===\n";
foreach ($pdo->query("SELECT device_id, COUNT(*) AS proc_count FROM processors GROUP BY device_id") as $r) {
    echo "Device {$r['device_id']}: {$r['proc_count']} processors\n";
}
