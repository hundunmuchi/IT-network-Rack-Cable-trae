<?php
// Check device state in the SQLite database

require __DIR__ . '/../../LibreNMS-offline/src/lnms/vendor/autoload.php';
$app = require_once __DIR__ . '/../../LibreNMS-offline/src/lnms/bootstrap/app.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../LibreNMS-offline/src/lnms');
$dotenv->safeLoad();

$config = require __DIR__ . '/../../LibreNMS-offline/src/lnms/config/database.php';
$db = $config['connections']['sqlite'];
$pdo = new PDO('sqlite:' . __DIR__ . '/../../LibreNMS-offline/src/lnms/' . $db['database']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Devices ===\n";
foreach ($pdo->query("SELECT device_id, hostname, sysName, sysDescr, hardware, os, status, ip, port, snmpver FROM devices ORDER BY device_id") as $r) {
    echo "device_id={$r['device_id']} hostname={$r['hostname']} sysName={$r['sysName']} os={$r['os']} hardware={$r['hardware']} status={$r['status']} ip={$r['ip']} port={$r['port']}\n";
    echo "  sysDescr: " . substr($r['sysDescr'], 0, 100) . "\n";
}

echo "\n=== Ports ===\n";
foreach ($pdo->query("SELECT device_id, COUNT(*) as port_count FROM ports GROUP BY device_id ORDER BY device_id") as $r) {
    echo "device_id={$r['device_id']} ports={$r['port_count']}\n";
}

echo "\n=== IPv4 addresses ===\n";
foreach ($pdo->query("SELECT device_id, COUNT(*) as cnt FROM ipv4_addresses GROUP BY device_id") as $r) {
    echo "device_id={$r['device_id']} ipv4_addrs={$r['cnt']}\n";
}

echo "\n=== OS definitions cache ===\n";
foreach ($pdo->query("SELECT * FROM os_cache LIMIT 10") as $r) {
    print_r($r);
}
