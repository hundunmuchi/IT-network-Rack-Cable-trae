<?php
// Reset admin password to a known value.
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$newPassword = 'admin';
$hash = password_hash($newPassword, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
$stmt->execute([$hash]);
echo "Rows updated: " . $stmt->rowCount() . "\n";

// Verify.
$r = $pdo->query("SELECT username, password FROM users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
echo "Verify: username={$r['username']} hash={$r['password']}\n";
echo "password_verify('admin', hash) = " . (password_verify('admin', $r['password']) ? 'true' : 'false') . "\n";
