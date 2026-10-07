<?php
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
foreach ($pdo->query("PRAGMA table_info(links)") as $r) {
    echo $r[1] . ' ' . $r[2] . ($r[3] ? ' NOT NULL' : '') . "\n";
}
