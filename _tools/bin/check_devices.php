<?php
// Check device + ports + IPs discovery results.

$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== devices ===\n";
$rows = $pdo->query("SELECT device_id, hostname, ip, os, status, status_reason, sysName, sysDescr, hardware, last_discovered FROM devices ORDER BY device_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "id={$r['device_id']} host={$r['hostname']} os={$r['os']} status={$r['status']} sysName={$r['sysName']} hardware={$r['hardware']}\n";
    echo "  sysDescr: " . substr($r['sysDescr'], 0, 80) . "\n";
    echo "  last_discovered: {$r['last_discovered']}\n";
}

echo "\n=== ports (device_id=2) ===\n";
$ports = $pdo->query("SELECT port_id, ifIndex, ifName, ifType, ifOperStatus, ifAdminStatus FROM ports WHERE device_id=2 ORDER BY ifIndex")->fetchAll(PDO::FETCH_ASSOC);
foreach ($ports as $p) {
    echo "  ifIndex={$p['ifIndex']} ifName={$p['ifName']} type={$p['ifType']} oper={$p['ifOperStatus']} admin={$p['ifAdminStatus']}\n";
}
echo "Total: " . count($ports) . " ports\n";

echo "\n=== ipv4 addresses (device_id=2) ===\n";
$ips = $pdo->query("SELECT * FROM ipv4_addresses WHERE device_id=2")->fetchAll(PDO::FETCH_ASSOC);
foreach ($ips as $i) {
    echo "  " . json_encode($i) . "\n";
}
echo "Total: " . count($ips) . " IPs\n";

echo "\n=== processors (device_id=2) ===\n";
$procs = $pdo->query("SELECT * FROM processors WHERE device_id=2")->fetchAll(PDO::FETCH_ASSOC);
foreach ($procs as $p) {
    echo "  " . json_encode($p) . "\n";
}
