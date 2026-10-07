<?php
// Test SnmpQuery as LibreNMS would use it.

chdir('e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms');
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use LibreNMS\Data\Source\NetSnmpQuery as SnmpQuery;
use App\Models\Device;

// Pick device 2 (HP)
$device = Device::find(2);
echo "device 2: hostname={$device->hostname} os={$device->os}\n";

// Try a basic snmpget
echo "\n--- Test 1: SnmpQuery::make()->device(\$device)->numeric()->get([...]) ---\n";
$resp = SnmpQuery::make()->device($device)->numeric()->get(['SNMPv2-MIB::sysDescr.0', 'SNMPv2-MIB::sysObjectID.0', 'SNMPv2-MIB::sysName.0']);
echo "isValid=" . ($resp->isValid() ? 'true' : 'false') . "\n";
$vals = $resp->values();
echo "values: " . json_encode($vals) . "\n";
echo "raw output:\n" . $resp->raw() . "\n";
