<?php
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
foreach ($pdo->query("SELECT device_id, hostname, ip, sysName FROM devices") as $r) {
    echo "id={$r['device_id']} hostname={$r['hostname']} ip={$r['ip']} sysName={$r['sysName']}\n";
}
