<?php
// Delete persisted binary config values from DB so config.php values take precedence.

$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$keys = ['fping', 'fping6', 'snmpget', 'snmpwalk', 'snmpbulkwalk', 'snmpgetnext', 'snmptranslate', 'rrdtool', 'traceroute'];
$stmt = $pdo->prepare("DELETE FROM config WHERE config_name = ?");
$deleted = 0;
foreach ($keys as $k) {
    $stmt->execute([$k]);
    $deleted += $stmt->rowCount();
}
echo "Deleted $deleted config rows.\n";

// Show remaining config rows.
echo "=== Remaining config rows ===\n";
foreach ($pdo->query("SELECT config_name FROM config ORDER BY config_name") as $r) {
    echo "  " . $r['config_name'] . "\n";
}
