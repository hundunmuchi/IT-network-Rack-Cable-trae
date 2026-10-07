<?php
// Test if config.php is loaded correctly with LibreNMS Config class.

require_once 'e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\vendor\autoload.php';
$app = require_once 'e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\bootstrap\app.php';
$dotenv = Dotenv\Dotenv::createImmutable('e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms');
$dotenv->safeLoad();

use LibreNMS\Config;

// Force load
Config::load();

echo "fping: " . Config::get('fping') . "\n";
echo "snmp.get: " . Config::get('snmp.get', 'DEFAULT') . "\n";
echo "snmpget: " . Config::get('snmpget') . "\n";
echo "snmpwalk: " . Config::get('snmpwalk') . "\n";
echo "snmpbulkwalk: " . Config::get('snmpbulkwalk') . "\n";
echo "snmpgetnext: " . Config::get('snmpgetnext') . "\n";
echo "snmptranslate: " . Config::get('snmptranslate') . "\n";
echo "icmp_check: " . var_export(Config::get('icmp_check'), true) . "\n";
echo "_config_php_loaded: " . var_export(Config::get('_config_php_loaded'), true) . "\n";

// Check if config.php was loaded
echo "\n--- Direct check ---\n";
$config_path = 'e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\config.php';
echo "config.php exists: " . (is_file($config_path) ? 'YES' : 'NO') . "\n";
echo "config.php readable: " . (is_readable($config_path) ? 'YES' : 'NO') . "\n";
