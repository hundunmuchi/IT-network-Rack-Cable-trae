<?php
// Quick test: query the simulator with PHP's snmp module to confirm it responds correctly.

$hosts = array(
    'HP'    => array('ip' => '127.0.0.2', 'port' => 11611),
    'Cisco' => array('ip' => '127.0.0.3', 'port' => 11611),
    'Huawei'=> array('ip' => '127.0.0.4', 'port' => 11611),
);

$queries = array(
    'sysDescr'    => 'SNMPv2-MIB::sysDescr.0',
    'sysObjectID' => 'SNMPv2-MIB::sysObjectID.0',
    'sysUpTime'   => 'SNMPv2-MIB::sysUpTime.0',
    'sysName'     => 'SNMPv2-MIB::sysName.0',
    'sysLocation' => 'SNMPv2-MIB::sysLocation.0',
);

foreach ($hosts as $name => $h) {
    echo "===== $name ({$h['ip']}:{$h['port']}) =====\n";
    foreach ($queries as $qname => $oid) {
        $r = @snmp2_get($h['ip'] . ':' . $h['port'], 'public', $oid, 2000000, 1);
        echo str_pad($qname, 14) . " : " . str_replace(array('STRING: ', 'OID: ', 'Timeticks: ', 'INTEGER: ', 'Hex-STRING: ', 'IpAddress: '), '', trim((string)$r)) . "\n";
    }
    // Walk ifTable
    echo "----- ifDescr walk -----\n";
    $walk = @snmp2_real_walk($h['ip'] . ':' . $h['port'], 'public', 'IF-MIB::ifDescr', 2000000, 1);
    if ($walk === false) {
        echo "  (walk failed)\n";
    } else {
        foreach (array_slice($walk, 0, 3, true) as $k => $v) {
            echo "  $k = $v\n";
        }
        $total = count($walk);
        echo "  ... total $total interfaces\n";
    }
    echo "\n";
}
