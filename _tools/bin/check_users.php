<?php
// Check users table to see if admin exists.

$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== users ===\n";
$rows = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "Total: " . count($rows) . " users\n";

echo "\n=== roles ===\n";
foreach ($pdo->query("SELECT role_id, role_name, role_desc FROM roles") as $r) {
    echo "id={$r['role_id']} name={$r['role_name']} desc={$r['role_desc']}\n";
}
