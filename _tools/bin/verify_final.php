<?php
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
echo "ssh-lldp links: " . $pdo->query("SELECT COUNT(*) FROM links WHERE protocol='ssh-lldp'")->fetchColumn() . "\n";
echo "\nTop 5 links:\n";
foreach ($pdo->query("SELECT id, local_device_id, remote_hostname, remote_port, protocol FROM links WHERE protocol='ssh-lldp' LIMIT 5") as $r) {
    echo "  id={$r['id']} dev={$r['local_device_id']} -> {$r['remote_hostname']}:{$r['remote_port']} ({$r['protocol']})\n";
}
echo "\nDevices v3 status:\n";
foreach ($pdo->query("SELECT device_id, hostname, snmpver, authlevel FROM devices WHERE device_id>1") as $r) {
    echo "  {$r['hostname']} snmpver={$r['snmpver']} level={$r['authlevel']}\n";
}
