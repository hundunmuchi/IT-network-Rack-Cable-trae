<?php
// Inspect persisted config values in SQLite DB.

$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Persisted config values ===\n";
foreach ($pdo->query("SELECT config_name, config_value FROM config WHERE config_name IN ('fping','fping6','snmpget','snmpwalk','snmpbulkwalk','snmpgetnext','snmptranslate','rrdtool','traceroute','icmp_check')") as $r) {
    echo $r['config_name'] . ' = ' . $r['config_value'] . "\n";
}
