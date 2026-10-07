<?php
// Detailed DB config check with var_dump.

$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Persisted config values (raw, with var_dump) ===\n";
$stmt = $pdo->query("SELECT config_name, config_value FROM config WHERE config_name IN ('fping','fping6','snmpget','snmpwalk','snmpbulkwalk','snmpgetnext','snmptranslate','rrdtool','traceroute','icmp_check') ORDER BY config_name");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['config_name'] . " => ";
    var_dump($r['config_value']);
}
echo "Total rows: " . $stmt->rowCount() . "\n";
