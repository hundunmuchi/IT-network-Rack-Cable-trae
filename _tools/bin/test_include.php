<?php
// Test if config.php can be included properly with $config variable.

function base_path($suffix = '') {
    return 'e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms' . ($suffix ? '/' . $suffix : '');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$config = ['orig' => 1];
echo "Before include, config keys: " . json_encode(array_keys($config)) . "\n";
$cfg_path = base_path('config.php');
echo "Path: $cfg_path\n";
echo "is_file: " . (is_file($cfg_path) ? 'YES' : 'NO') . "\n";
$result = include $cfg_path;
echo "Include result: " . var_export($result, true) . "\n";
echo "After include, config keys: " . json_encode(array_keys($config)) . "\n";
if (isset($config['fping'])) {
    echo "fping set to: " . $config['fping'] . "\n";
}
if (isset($config['_config_php_loaded'])) {
    echo "_config_php_loaded: YES\n";
}
