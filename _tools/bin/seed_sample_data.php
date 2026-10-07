<?php
/**
 * seed_sample_data.php - Populate LibreNMS SQLite with rich demo data
 * showcasing: locations, IPv4, device groups, alert rules, sensors,
 * mempools, storage, syslog, eventlog, FDB, links, bills.
 *
 * Idempotent: cleans up previous demo rows first.
 */
$pdo = new PDO('sqlite:e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = OFF');

echo "=== Seeding LibreNMS sample data ===\n\n";

// 1. Locations: fix raw SNMP prefix, add GPS coords
echo "[1] Fixing locations...\n";
$locUpdates = [
    [1, 'Beijing HQ - Building A - Rack 1 - U1',   39.9042, 116.4074],
    [2, 'Beijing HQ - Building C - Rack 5 - U20',  39.9087, 116.3975],
    [3, 'Shanghai DC - Building B - Rack 3 - U10', 31.2304, 121.4737],
];
foreach ($locUpdates as [$id, $name, $lat, $lng]) {
    $pdo->prepare("UPDATE locations SET location=?, lat=?, lng=?, fixed_coordinates=1 WHERE id=?")
        ->execute([$name, $lat, $lng, $id]);
}
$pdo->exec("UPDATE devices SET location_id=1 WHERE device_id=2");
$pdo->exec("UPDATE devices SET location_id=3 WHERE device_id=3");
$pdo->exec("UPDATE devices SET location_id=2 WHERE device_id=4");

// 2. Device IPs (display column)
echo "[2] Setting device IPs...\n";
$pdo->exec("UPDATE devices SET ip='10.0.0.2' WHERE device_id=2");
$pdo->exec("UPDATE devices SET ip='10.0.0.3' WHERE device_id=3");
$pdo->exec("UPDATE devices SET ip='10.0.0.4' WHERE device_id=4");

// 3. IPv4 addresses on first port of each device
echo "[3] Adding IPv4 addresses...\n";
$pdo->exec("DELETE FROM ipv4_addresses WHERE ipv4_address LIKE '10.0.0.%'");
$pdo->exec("DELETE FROM ipv4_networks WHERE ipv4_network='10.0.0.0/24'");
$pdo->prepare("INSERT INTO ipv4_networks (ipv4_network, context_name) VALUES (?, 'default')")
    ->execute(['10.0.0.0/24']);
$netId = $pdo->lastInsertId();

$ipv4Map = [
    2 => '10.0.0.2',
    3 => '10.0.0.3',
    4 => '10.0.0.4',
];
foreach ($ipv4Map as $devId => $ip) {
    $row = $pdo->query("SELECT port_id FROM ports WHERE device_id=$devId ORDER BY port_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $pdo->prepare("INSERT INTO ipv4_addresses (ipv4_address, ipv4_prefixlen, ipv4_network_id, port_id, context_name) VALUES (?, 24, ?, ?, 'default')")
            ->execute([$ip, $netId, $row['port_id']]);
    }
}

// 4. Device groups (correct columns: id, name, desc, pattern)
echo "[4] Creating device groups...\n";
$pdo->exec("DELETE FROM device_group_device WHERE device_group_id IN (SELECT id FROM device_groups WHERE name LIKE 'Demo %')");
$pdo->exec("DELETE FROM device_groups WHERE name LIKE 'Demo %'");

$groups = [
    ['Demo - Core Switches',   'All core switches',           '{"rule":"devices.type = \"network\""}'],
    ['Demo - Vendor: HP',      'HP ProCurve devices',          '{"rule":"devices.os = \"procurve\""}'],
    ['Demo - Vendor: Cisco',   'Cisco IOS-XE devices',         '{"rule":"devices.os = \"iosxe\""}'],
    ['Demo - Vendor: Huawei',  'Huawei VRP devices',           '{"rule":"devices.os = \"vrp\""}'],
    ['Demo - Beijing Sites',   'All devices in Beijing',       '{"rule":"devices.location_id IN (1,2)"}'],
    ['Demo - Shanghai Sites',  'All devices in Shanghai DC',   '{"rule":"devices.location_id = 3"}'],
];
$groupIds = [];
foreach ($groups as $g) {
    $pdo->prepare("INSERT INTO device_groups (name, desc, pattern) VALUES (?, ?, ?)")->execute([$g[0], $g[1], $g[2]]);
    $groupIds[$g[0]] = $pdo->lastInsertId();
}
$links = [
    [$groupIds['Demo - Core Switches'],  2],
    [$groupIds['Demo - Core Switches'],  3],
    [$groupIds['Demo - Core Switches'],  4],
    [$groupIds['Demo - Vendor: HP'],     2],
    [$groupIds['Demo - Vendor: Cisco'],  3],
    [$groupIds['Demo - Vendor: Huawei'], 4],
    [$groupIds['Demo - Beijing Sites'],  2],
    [$groupIds['Demo - Beijing Sites'],  4],
    [$groupIds['Demo - Shanghai Sites'], 3],
];
$stmt = $pdo->prepare("INSERT INTO device_group_device (device_group_id, device_id) VALUES (?, ?)");
foreach ($links as $l) { $stmt->execute($l); }

// 5. Alert rules (correct columns: id, rule, severity, extra, disabled, name, query, builder, proc, invert_map)
echo "[5] Creating alert rules...\n";
$pdo->exec("DELETE FROM alert_rules WHERE name LIKE 'Demo %'");

$rules = [
    ['Demo - Device Down',       'devices.status != 1 && devices.status_reason = "icmp-ping-down"', 'critical'],
    ['Demo - Port Down',         'ports.ifOperStatus = "down" && ports.ifAdminStatus = "up"',         'critical'],
    ['Demo - High CPU',          'processors.processor_usage > 80',                                  'warning'],
    ['Demo - High Memory',       'mempools.mempool_perc > 90',                                       'warning'],
    ['Demo - Disk Near Full',   'storage.storage_perc > 85',                                        'warning'],
    ['Demo - Interface Errors', 'ports.ifInErrors_delta > 0 || ports.ifOutErrors_delta > 0',         'warning'],
];
foreach ($rules as $r) {
    $pdo->prepare("INSERT INTO alert_rules (rule, severity, extra, disabled, name, query, builder, proc, invert_map) VALUES (?, ?, '{}', 0, ?, '', 'sql', '', 0)")
        ->execute([$r[1], $r[2], $r[0]]);
}

// 6. Sensors (correct columns: sensor_class, sensor_type, sensor_descr, sensor_current, sensor_prev, sensor_limit, sensor_limit_low, sensor_alert, sensor_custom, device_id, poller_type, sensor_oid, sensor_index, lastupdate)
echo "[6] Adding sensors...\n";
$pdo->exec("DELETE FROM sensors WHERE sensor_descr LIKE 'Demo %'");

$sensors = [
    // device_id, class, type, descr, value, unit
    [2, 'temperature', 'hp-temp-cpu',     'Demo - HP CPU Temp',     42,   '°C'],
    [2, 'temperature', 'hp-temp-inlet',   'Demo - HP Inlet Temp',   25,   '°C'],
    [2, 'humidity',    'hp-humidity',      'Demo - HP Humidity',     55,   '%'],
    [2, 'fanspeed',    'hp-fan-1',        'Demo - HP Fan 1 RPM',    8500, 'RPM'],
    [2, 'voltage',     'hp-voltage-3.3',  'Demo - HP 3.3V Rail',    3.31, 'V'],
    [3, 'temperature', 'cisco-temp-cpu',  'Demo - Cisco CPU Temp',  48,   '°C'],
    [3, 'temperature', 'cisco-temp-inlet','Demo - Cisco Inlet Temp',27,   '°C'],
    [3, 'fanspeed',    'cisco-fan-1',     'Demo - Cisco Fan 1',     9200, 'RPM'],
    [3, 'voltage',     'cisco-voltage-12', 'Demo - Cisco 12V Rail', 12.05, 'V'],
    [4, 'temperature', 'huawei-temp-cpu', 'Demo - Huawei CPU Temp', 50,   '°C'],
    [4, 'humidity',    'huawei-humidity',  'Demo - Huawei Humidity', 48,   '%'],
    [4, 'fanspeed',    'huawei-fan-1',    'Demo - Huawei Fan 1',    7800, 'RPM'],
];
foreach ($sensors as $s) {
    $pdo->prepare("INSERT INTO sensors (sensor_class, sensor_type, sensor_descr, sensor_current, sensor_prev, sensor_limit, sensor_limit_low, sensor_alert, sensor_custom, device_id, poller_type, sensor_oid, sensor_index, lastupdate)
                   VALUES (?, ?, ?, ?, 0, NULL, NULL, 0, 'No', ?, 'snmp', ?, '1', datetime('now'))")
        ->execute([$s[1], $s[2], $s[3], $s[4], $s[0], '.1.3.6.1.4.1.' . $s[0] . '.1.0']);
}

// 7. Mempools (correct columns: mempool_index, entPhysicalIndex, mempool_type, mempool_precision, mempool_descr, device_id, mempool_perc, mempool_used, mempool_free, mempool_total, mempool_largestfree, mempool_lowestfree, mempool_deleted, mempool_perc_warn)
echo "[7] Adding mempools...\n";
$pdo->exec("DELETE FROM mempools WHERE mempool_descr LIKE 'Demo %'");

$mempools = [
    [2, 'Demo - HP RAM',      512, 256],
    [3, 'Demo - Cisco RAM',  1024, 620],
    [4, 'Demo - Huawei RAM',  512, 312],
];
foreach ($mempools as $m) {
    [$devId, $descr, $total, $used] = $m;
    $free = $total - $used;
    $perc = intval($used / $total * 100);
    $pdo->prepare("INSERT INTO mempools (mempool_index, entPhysicalIndex, mempool_type, mempool_precision, mempool_descr, device_id, mempool_perc, mempool_used, mempool_free, mempool_total, mempool_largestfree, mempool_lowestfree, mempool_deleted, mempool_perc_warn)
                   VALUES ('1', 0, 'physicalMemory', 1, ?, ?, ?, ?, ?, ?, ?, ?, 0, 75)")
        ->execute([$descr, $devId, $perc, $used, $free, $total, $free, $free]);
}

// 8. Storage (correct columns: storage_mib, storage_index, storage_type, storage_descr, storage_size, storage_units, storage_used, storage_free, storage_perc, storage_perc_warn, storage_deleted, device_id)
echo "[8] Adding storage...\n";
$pdo->exec("DELETE FROM storage");

$storage = [
    [2, 'flash',     1024, 256],
    [3, 'bootflash', 2048, 820],
    [4, 'flash',      512, 192],
];
foreach ($storage as $s) {
    [$devId, $descr, $size, $used] = $s;
    $free = $size - $used;
    $perc = intval($used / $size * 100);
    $pdo->prepare("INSERT INTO storage (device_id, storage_mib, storage_index, storage_type, storage_descr, storage_size, storage_units, storage_used, storage_free, storage_perc, storage_perc_warn, storage_deleted)
                   VALUES (?, 'hostResourcesStorage', '1', 'hrStorageOther', ?, ?, 1, ?, ?, ?, 80, 0)")
        ->execute([$devId, $descr, $size, $used, $free, $perc]);
}

// 9. Syslog (correct columns: device_id, facility, priority, level, tag, timestamp, program, msg, seq)
echo "[9] Adding syslog entries...\n";
$pdo->exec("DELETE FROM syslog WHERE device_id IN (2,3,4)");

$syslogEntries = [
    [2, '2026-10-06 09:15:23', 'I', 'I', 'LINK',  'Interface Gi1/0/1 link up at 1Gbps'],
    [2, '2026-10-06 09:15:24', 'I', 'I', 'STP',   'STP: Port Gi1/0/1 role set to Root'],
    [2, '2026-10-06 10:02:11', 'W', 'W', 'ENV',   'Fan 1 speed below threshold (8500 RPM)'],
    [2, '2026-10-06 10:32:01', 'I', 'I', 'SNMP',  'SNMP community public request from 10.0.0.1'],
    [2, '2026-10-06 11:45:00', 'I', 'I', 'SYS',   'Configuration saved to flash'],
    [3, '2026-10-06 09:20:11', 'I', 'I', 'LINK',  'GigabitEthernet0/1 link up at 1Gbps'],
    [3, '2026-10-06 09:30:45', 'W', 'W', 'ENV',   'Temperature sensor 1 reading 48C above normal'],
    [3, '2026-10-06 10:15:00', 'I', 'I', 'OSPF',  'OSPF adjacency with 10.0.0.2 established'],
    [3, '2026-10-06 11:00:00', 'I', 'I', 'SYS',   'Configuration committed'],
    [3, '2026-10-06 11:30:00', 'E', 'E', 'LINK',  'Interface Gi0/2 link down - check cable'],
    [4, '2026-10-06 09:25:00', 'I', 'I', 'LINK',  'GigabitEthernet0/0/1 link up at 1Gbps'],
    [4, '2026-10-06 09:55:30', 'W', 'W', 'ENV',   'CPU temperature 50C - within tolerance'],
    [4, '2026-10-06 10:45:00', 'I', 'I', 'LLDP',  'LLDP neighbor discovered on port Gi0/0/24'],
    [4, '2026-10-06 11:15:00', 'I', 'I', 'SYS',   'VRP version 5.170 running'],
    [4, '2026-10-06 11:45:00', 'E', 'E', 'OSPF',  'OSPF adjacency lost with 10.0.0.3'],
];
$seq = 1;
foreach ($syslogEntries as $e) {
    $pdo->prepare("INSERT INTO syslog (device_id, timestamp, facility, priority, level, tag, program, msg, seq) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$e[0], $e[1], $e[2], $e[2], $e[2], $e[3], $e[3], $e[4], $seq++]);
}

// 10. Eventlog (correct columns: event_id, device_id, datetime, message, type, reference, username, severity)
echo "[10] Adding eventlog entries...\n";
$pdo->exec("DELETE FROM eventlog WHERE message LIKE 'Demo %'");

$events = [
    [2, '2026-10-06 09:15:23', 'Demo - Device discovered',           'system',  4],
    [2, '2026-10-06 10:02:11', 'Demo - Sensor alert: Fan 1 RPM low', 'sensor',  3],
    [3, '2026-10-06 09:20:11', 'Demo - Device discovered',           'system',  4],
    [3, '2026-10-06 09:30:45', 'Demo - Sensor alert: CPU Temp high',  'sensor',  3],
    [3, '2026-10-06 11:30:00', 'Demo - Port Gi0/2 link down',         'interface', 2],
    [4, '2026-10-06 09:25:00', 'Demo - Device discovered',           'system',  4],
    [4, '2026-10-06 11:45:00', 'Demo - OSPF adjacency lost',          'routing', 2],
];
foreach ($events as $e) {
    $pdo->prepare("INSERT INTO eventlog (device_id, datetime, message, type, reference, username, severity) VALUES (?, ?, ?, ?, '', 'librenms', ?)")
        ->execute([$e[0], $e[1], $e[2], $e[3], $e[4]]);
}

// 11. Links (LLDP neighbor topology)
echo "[11] Adding LLDP links (topology)...\n";
$pdo->exec("DELETE FROM links");

// Get a port from each device
$p2 = $pdo->query("SELECT port_id FROM ports WHERE device_id=2 ORDER BY port_id LIMIT 1")->fetchColumn();
$p3 = $pdo->query("SELECT port_id FROM ports WHERE device_id=3 ORDER BY port_id LIMIT 1")->fetchColumn();
$p4 = $pdo->query("SELECT port_id FROM ports WHERE device_id=4 ORDER BY port_id LIMIT 1")->fetchColumn();

if ($p2 && $p3) {
    $pdo->prepare("INSERT INTO links (local_port_id, local_device_id, remote_port_id, remote_device_id, active, protocol, remote_hostname, remote_port, remote_platform, remote_version)
                   VALUES (?, 2, ?, 3, 1, 'lldp', 'cisco-sw-core-01', 'Gi0/1', 'Cisco IOS-XE', '17.6.1')")->execute([$p2, $p3]);
}
if ($p3 && $p4) {
    $pdo->prepare("INSERT INTO links (local_port_id, local_device_id, remote_port_id, remote_device_id, active, protocol, remote_hostname, remote_port, remote_platform, remote_version)
                   VALUES (?, 3, ?, 4, 1, 'lldp', 'huawei-sw-edge-01', 'GE0/0/24', 'Huawei S5700', 'VRP 5.170')")->execute([$p3, $p4]);
}
if ($p4 && $p2) {
    $pdo->prepare("INSERT INTO links (local_port_id, local_device_id, remote_port_id, remote_device_id, active, protocol, remote_hostname, remote_port, remote_platform, remote_version)
                   VALUES (?, 4, ?, 2, 1, 'lldp', 'hp-sw-core-01', 'Gi1/0/24', 'HP ProCurve 3500yl', 'K.15.07')")->execute([$p4, $p2]);
}

// 12. FDB (MAC address table)
echo "[12] Adding FDB entries...\n";
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

// 13. Bills (correct columns: bill_name, bill_type, bill_cdr, bill_day, bill_quota, rate_95th_in/out/95th, dir_95th, bill_custid, bill_ref, bill_notes, bill_autoadded, bill_last_calc)
echo "[13] Adding billing entry...\n";
$pdo->exec("DELETE FROM bills WHERE bill_name LIKE 'Demo %'");
$pdo->exec("DELETE FROM bill_ports WHERE bill_id IN (SELECT bill_id FROM bills WHERE bill_name LIKE 'Demo %')");

$pdo->prepare("INSERT INTO bills (bill_name, bill_type, bill_cdr, bill_day, bill_quota, rate_95th_in, rate_95th_out, rate_95th, dir_95th, total_data, total_data_in, total_data_out, rate_average_in, rate_average_out, rate_average, bill_last_calc, bill_custid, bill_ref, bill_notes, bill_autoadded)
               VALUES (?, 'cdr', 1000000000, 1, 0, 250000000, 180000000, 250000000, 'in', 0, 0, 0, 0, 0, 0, datetime('now'), 'CUST-001', 'PO-2026-001', 'Demo 95th percentile bandwidth bill', 0)")
    ->execute(['Demo - 95th Percentile (HP-Cisco Uplink)']);
$billId = $pdo->lastInsertId();
if ($p2) {
    $pdo->prepare("INSERT INTO bill_ports (bill_id, port_id, bill_port_autoadded) VALUES (?, ?, 0)")->execute([$billId, $p2]);
}

// 14. Add some traffic counters to ports (for graph display)
echo "[14] Setting port traffic counters...\n";
$pdo->exec("UPDATE ports SET ifInOctets=8329102384, ifOutOctets=4192340981, ifInUcastPkts=12839471, ifOutUcastPkts=9842311, ifInErrors=3, ifOutErrors=1, ifSpeed=1000000000 WHERE device_id=2 AND port_id IN (SELECT port_id FROM ports WHERE device_id=2 LIMIT 1)");
$pdo->exec("UPDATE ports SET ifInOctets=6723812943, ifOutOctets=5934281532, ifInErrors=12, ifOutErrors=2, ifSpeed=1000000000 WHERE device_id=3 AND port_id IN (SELECT port_id FROM ports WHERE device_id=3 LIMIT 1)");
$pdo->exec("UPDATE ports SET ifInOctets=3492841937, ifOutOctets=3194817234, ifInErrors=0, ifOutErrors=0, ifSpeed=1000000000 WHERE device_id=4 AND port_id IN (SELECT port_id FROM ports WHERE device_id=4 LIMIT 1)");

// 15. Update processor usage (already exists, just set demo values)
echo "[15] Setting processor usage...\n";
$pdo->exec("UPDATE processors SET processor_usage=23 WHERE device_id=2");
$pdo->exec("UPDATE processors SET processor_usage=58 WHERE device_id=3");
$pdo->exec("UPDATE processors SET processor_usage=41 WHERE device_id=4");

// 16. Summary
echo "\n[16] Summary after seeding:\n";
foreach (['devices','ports','ipv4_addresses','ipv4_networks','locations','device_groups','device_group_device','alert_rules','sensors','mempools','storage','syslog','eventlog','links','ports_fdb','bills','bill_ports','processors'] as $t) {
    $c = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "    " . str_pad($t, 25) . " = $c\n";
}

echo "\n=== Done. Refresh LibreNMS Web UI to see the demo data. ===\n";
