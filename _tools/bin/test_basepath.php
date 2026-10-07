<?php
require_once 'e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\vendor\autoload.php';
$app = require_once 'e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\bootstrap\app.php';
$dotenv = Dotenv\Dotenv::createImmutable('e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms');
$dotenv->safeLoad();

echo "base_path(): " . base_path() . "\n";
echo "base_path('config.php'): " . base_path('config.php') . "\n";
echo "is_file: " . (is_file(base_path('config.php')) ? 'YES' : 'NO') . "\n";
echo "realpath: " . realpath(base_path('config.php')) . "\n";
echo "CWD: " . getcwd() . "\n";

// Try include directly.
$test = ['orig' => 1];
@include base_path('config.php');
echo "After include, has 'fping': " . (isset($test['fping']) ? $test['fping'] : 'NOT SET') . "\n";
echo "After include, has '_config_php_loaded': " . (isset($test['_config_php_loaded']) ? 'YES' : 'NO') . "\n";
