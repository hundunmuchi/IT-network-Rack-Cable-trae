<?php
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Ports per device ===\n";
foreach ($pdo->query("SELECT device_id, COUNT(*) AS c FROM ports GROUP BY device_id") as $r) {
    echo "device_id={$r['device_id']} ports={$r['c']}\n";
}

echo "\n=== First port per device ===\n";
foreach ($pdo->query("SELECT device_id, MIN(port_id) AS p, MAX(port_id) AS mp FROM ports GROUP BY device_id") as $r) {
    echo "device_id={$r['device_id']} min_port={$r['p']} max_port={$r['mp']}\n";
}

echo "\n=== ipv4_addresses ===\n";
foreach ($pdo->query("SELECT * FROM ipv4_addresses") as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== device_groups ===\n";
foreach ($pdo->query("SELECT id, name, `desc` FROM device_groups WHERE name LIKE 'Demo %'") as $r) {
    echo "id={$r['id']} name={$r['name']} desc={$r['desc']}\n";
}
