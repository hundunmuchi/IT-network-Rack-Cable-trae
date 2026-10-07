<?php
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("UPDATE devices SET snmpver='v3', authlevel='authPriv', authname='librenms', authpass='AuthPass123', authalgo='SHA', cryptoalgo='AES', cryptopass='PrivPass456' WHERE device_id IN (2,3,4)");
echo "Updated devices 2,3,4 to SNMPv3 authPriv\n";
foreach ($pdo->query("SELECT device_id, hostname, snmpver, authlevel, authname FROM devices WHERE device_id IN (2,3,4)") as $r) {
    echo "device_id={$r['device_id']} host={$r['hostname']} snmpver={$r['snmpver']} level={$r['authlevel']} user={$r['authname']}\n";
}
