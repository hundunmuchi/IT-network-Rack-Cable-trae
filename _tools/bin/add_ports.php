<?php
/**
 * add_ports.php - Add sample ports to devices that don't have any.
 * Used after seed_sample_data.php when discovery only partially ran.
 */
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Adding sample ports to devices 3 and 4 ===\n";

// Device 3 (Cisco IOS-XE) - 28 ports
$ciscoPorts = [
    ['Gi0/0/1',  'GigabitEthernet0/0/1',  'uplink-to-core',           'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['Gi0/0/2',  'GigabitEthernet0/0/2',  'downlink-access-1',        'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['Gi0/0/3',  'GigabitEthernet0/0/3',  'downlink-access-2',        'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['Gi0/0/4',  'GigabitEthernet0/0/4',  'downlink-access-3',        'down','up',   1000000000, 'ethernetCsmacd'],
    ['Gi0/0/5',  'GigabitEthernet0/0/5',  'spare',                   'down','down', 1000000000, 'ethernetCsmacd'],
    ['Gi0/0/10', 'GigabitEthernet0/0/10', 'server-farm-1',           'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['Gi0/0/11', 'GigabitEthernet0/0/11', 'server-farm-2',           'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['Gi0/0/24', 'GigabitEthernet0/0/24', 'LLDP-to-Huawei-edge',     'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['Te0/1',    'TenGigabitEthernet0/1',  'uplink-10G',              'up', 'up',  10000000000, 'ethernetCsmacd'],
];

foreach ($ciscoPorts as $i => $p) {
    [$name, $descr, $alias, $oper, $admin, $speed, $type] = $p;
    $pdo->prepare("INSERT INTO ports (device_id, ifIndex, ifDescr, ifName, portName, ifAlias, ifOperStatus, ifOperStatus_prev, ifAdminStatus, ifAdminStatus_prev, ifSpeed, ifType, ifMtu, ifPhysAddress, ifHardType, ifVlan, ignore, disabled, detailed, deleted, ifConnectorPresent, ifDuplex, ifInOctets, ifOutOctets, ifInUcastPkts, ifOutUcastPkts, ifInErrors, ifOutErrors, poll_time, poll_prev, poll_period, ifSpeed_prev)
                   VALUES (3, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1500, '00:1B:54:AA:BB:C' || ?, 'ethernet', '1', 0, 0, 1, 0, 'true', 'fullDuplex', 6723812943, 5934281532, 12839471, 9842311, 12, 2, 0, 0, 0, ?)")
        ->execute([$i+1, $descr, $name, $name, $alias, $oper, $oper, $admin, $admin, $speed, $type, $i, $speed]);
}

// Device 4 (Huawei VRP) - 28 ports
$huaweiPorts = [
    ['GE0/0/1',  'GigabitEthernet0/0/1',  'uplink-to-cisco-core',     'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['GE0/0/2',  'GigabitEthernet0/0/2',  'access-port-1',            'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['GE0/0/3',  'GigabitEthernet0/0/3',  'access-port-2',            'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['GE0/0/4',  'GigabitEthernet0/0/4',  'access-port-3',           'down','up',   1000000000, 'ethernetCsmacd'],
    ['GE0/0/10', 'GigabitEthernet0/0/10', 'wifi-controller-1',       'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['GE0/0/11', 'GigabitEthernet0/0/11', 'wifi-controller-2',       'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['GE0/0/24', 'GigabitEthernet0/0/24', 'LLDP-to-HP-core',         'up', 'up',   1000000000, 'ethernetCsmacd'],
    ['XGE0/0/1', 'TenGigabitEthernet0/0/1','uplink-10G',             'up', 'up',  10000000000, 'ethernetCsmacd'],
];

foreach ($huaweiPorts as $i => $p) {
    [$name, $descr, $alias, $oper, $admin, $speed, $type] = $p;
    $pdo->prepare("INSERT INTO ports (device_id, ifIndex, ifDescr, ifName, portName, ifAlias, ifOperStatus, ifOperStatus_prev, ifAdminStatus, ifAdminStatus_prev, ifSpeed, ifType, ifMtu, ifPhysAddress, ifHardType, ifVlan, ignore, disabled, detailed, deleted, ifConnectorPresent, ifDuplex, ifInOctets, ifOutOctets, ifInUcastPkts, ifOutUcastPkts, ifInErrors, ifOutErrors, poll_time, poll_prev, poll_period, ifSpeed_prev)
                   VALUES (4, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1500, '00:1E:10:CC:DD:E' || ?, 'ethernet', '1', 0, 0, 1, 0, 'true', 'fullDuplex', 3492841937, 3194817234, 9823411, 8723491, 0, 0, 0, 0, 0, ?)")
        ->execute([$i+1, $descr, $name, $name, $alias, $oper, $oper, $admin, $admin, $speed, $type, $i, $speed]);
}

// Now re-add IPv4 addresses for devices 3 and 4
echo "Adding IPv4 addresses for devices 3 and 4...\n";
$p3 = $pdo->query("SELECT port_id FROM ports WHERE device_id=3 ORDER BY port_id LIMIT 1")->fetchColumn();
$p4 = $pdo->query("SELECT port_id FROM ports WHERE device_id=4 ORDER BY port_id LIMIT 1")->fetchColumn();

$netId = $pdo->query("SELECT ipv4_network_id FROM ipv4_networks WHERE ipv4_network='10.0.0.0/24' LIMIT 1")->fetchColumn();

if ($p3) {
    $pdo->prepare("INSERT INTO ipv4_addresses (ipv4_address, ipv4_prefixlen, ipv4_network_id, port_id, context_name) VALUES ('10.0.0.3', 24, ?, ?, 'default')")
        ->execute([$netId, $p3]);
}
if ($p4) {
    $pdo->prepare("INSERT INTO ipv4_addresses (ipv4_address, ipv4_prefixlen, ipv4_network_id, port_id, context_name) VALUES ('10.0.0.4', 24, ?, ?, 'default')")
        ->execute([$netId, $p4]);
}

// Now add LLDP links between devices
echo "Adding LLDP links...\n";
$pdo->exec("DELETE FROM links");
$p2 = $pdo->query("SELECT port_id FROM ports WHERE device_id=2 ORDER BY port_id LIMIT 1")->fetchColumn();

if ($p2 && $p3) {
    $pdo->prepare("INSERT INTO links (local_port_id, local_device_id, remote_port_id, remote_device_id, active, protocol, remote_hostname, remote_port, remote_platform, remote_version)
                   VALUES (?, 2, ?, 3, 1, 'lldp', 'cisco-sw-core-01', 'Gi0/0/1', 'Cisco IOS-XE', '17.6.1')")->execute([$p2, $p3]);
}
if ($p3 && $p4) {
    $pdo->prepare("INSERT INTO links (local_port_id, local_device_id, remote_port_id, remote_device_id, active, protocol, remote_hostname, remote_port, remote_platform, remote_version)
                   VALUES (?, 3, ?, 4, 1, 'lldp', 'huawei-sw-edge-01', 'GE0/0/24', 'Huawei S5700', 'VRP 5.170')")->execute([$p3, $p4]);
}
if ($p4 && $p2) {
    $pdo->prepare("INSERT INTO links (local_port_id, local_device_id, remote_port_id, remote_device_id, active, protocol, remote_hostname, remote_port, remote_platform, remote_version)
                   VALUES (?, 4, ?, 2, 1, 'lldp', 'hp-sw-core-01', 'Gi1/0/24', 'HP ProCurve 3500yl', 'K.15.07')")->execute([$p4, $p2]);
}

// Add FDB entries on new ports
$pdo->exec("DELETE FROM ports_fdb");
$fdb = [
    [$p2, '001122334455', 1, 2],
    [$p2, 'AABBCCDDEE01', 1, 2],
    [$p3, 'AABBCCDDEE02', 1, 3],
    [$p3, 'AABBCCDDEE03', 1, 3],
    [$p4, 'AABBCCDDEE04', 1, 4],
    [$p4, 'AABBCCDDEE05', 1, 4],
];
foreach ($fdb as $f) {
    $pdo->prepare("INSERT INTO ports_fdb (port_id, mac_address, vlan_id, device_id, created_at, updated_at) VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))")
        ->execute($f);
}

// Final summary
echo "\n=== Final summary ===\n";
foreach (['devices','ports','ipv4_addresses','locations','device_groups','device_group_device','alert_rules','sensors','mempools','storage','syslog','eventlog','links','ports_fdb','bills','processors'] as $t) {
    $c = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "    " . str_pad($t, 25) . " = $c\n";
}

echo "\n=== Ports per device ===\n";
foreach ($pdo->query("SELECT device_id, COUNT(*) AS c FROM ports GROUP BY device_id") as $r) {
    echo "    device_id={$r['device_id']} ports={$r['c']}\n";
}
