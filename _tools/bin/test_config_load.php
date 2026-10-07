<?php
/**
 * Test that LibreNMS Config::get('fping') etc. return the config.php
 * paths after the processConfig() Windows patch.
 */

// Simulate being run from LibreNMS root.
chdir('e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms');

// Bootstrap minimal Laravel.
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$bins = ['fping', 'fping6', 'snmpget', 'snmpwalk', 'snmpbulkwalk', 'snmpgetnext', 'snmptranslate'];
echo "=== Config::get() for binaries ===\n";
foreach ($bins as $bin) {
    $val = \LibreNMS\Config::get($bin);
    echo "  $bin => " . var_export($val, true) . "\n";
}
