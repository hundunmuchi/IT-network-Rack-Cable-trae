<?php
// Verify both services are up.
sleep(2);
$web = @file_get_contents('http://127.0.0.1:5656/', false, stream_context_create(['http' => ['timeout' => 3]]));
echo 'web: ' . (is_string($web) ? strlen($web) . ' bytes' : 'FAILED') . PHP_EOL;

$snmp = @snmp2_get('127.0.0.2:11611', 'public', '1.3.6.1.2.1.1.1.0', 1000000);
echo 'snmp: ' . ($snmp !== false ? 'OK - ' . substr($snmp, 0, 40) : 'FAILED') . PHP_EOL;
