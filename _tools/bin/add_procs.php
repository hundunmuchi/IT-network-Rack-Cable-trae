<?php
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("DELETE FROM processors WHERE device_id IN (3, 4)");
$pdo->prepare("INSERT INTO processors (entPhysicalIndex, hrDeviceIndex, device_id, processor_oid, processor_index, processor_type, processor_usage, processor_descr, processor_precision, processor_perc_warn)
               VALUES (0, 0, 3, ?, '1', 'arbitrary', 58, 'Cisco IOSXE CPU', 1, 75)")
    ->execute(['.1.3.6.1.4.1.9.9.109.1.1.1.1.6']);
$pdo->prepare("INSERT INTO processors (entPhysicalIndex, hrDeviceIndex, device_id, processor_oid, processor_index, processor_type, processor_usage, processor_descr, processor_precision, processor_perc_warn)
               VALUES (0, 0, 4, ?, '1', 'arbitrary', 41, 'Huawei VRP CPU', 1, 75)")
    ->execute(['.1.3.6.1.4.1.2011.6.3.11.1.1.3']);

echo "processors = " . $pdo->query("SELECT COUNT(*) FROM processors")->fetchColumn() . "\n";
