<?php
/**
 * ssh_mock.php - 模拟网络设备的 SSH 命令行端点（单连接顺序处理）。
 *
 * 监听 127.0.0.2 / 127.0.0.3 / 127.0.0.4 的 2222 端口。
 * 认证: admin / admin
 *
 * 支持的 LLDP 命令:
 *   Cisco:  show lldp neighbors detail
 *   Huawei: display lldp neighbor
 *   HP:     show lldp info remote-device
 */

$devices = require __DIR__ . '/../snmp_sim/devices.php';

$ipToVendor = [];
foreach ($devices as $key => $def) {
    $ipToVendor[$def['ip']] = $def['vendor'];
}

function buildLldpOutput($vendor, $deviceDef) {
    $lines = [];
    switch ($vendor) {
        case 'cisco':
            $lines[] = 'Capability codes: (R) Router, (B) Bridge, (T) Telephone';
            $lines[] = '';
            $lines[] = 'Local Intf    Chassis id      Port id          Port Description   System Name';
            foreach ($deviceDef['lldpRem'] as $n) {
                $lines[] = sprintf('%-14s%-16s%-17s%-21s%s',
                    'Gi1/0/' . $n['port'], $n['chassisId'], $n['portId'], $n['portDesc'], $n['sysName']);
            }
            break;
        case 'huawei':
            foreach ($deviceDef['lldpRem'] as $i => $n) {
                $lines[] = 'Neighbor index: ' . ($i + 1);
                $lines[] = '  Chassis ID      : ' . $n['chassisId'];
                $lines[] = '  Port ID         : ' . $n['portId'];
                $lines[] = '  Port description: ' . $n['portDesc'];
                $lines[] = '  System name     : ' . $n['sysName'];
            }
            break;
        case 'hp':
        default:
            $lines[] = '  LocalPort | ChassisId        | PortId        | PortDescription      | SysName';
            $lines[] = '  ----------+------------------+---------------+----------------------+----------';
            foreach ($deviceDef['lldpRem'] as $n) {
                $lines[] = sprintf('  %-10s| %-16s | %-13s | %-20s | %s',
                    $n['port'], $n['chassisId'], $n['portId'], $n['portDesc'], $n['sysName']);
            }
            break;
    }
    return implode("\n", $lines) . "\n";
}

$lldpOutputs = [];
foreach ($devices as $key => $def) {
    $lldpOutputs[$def['ip']] = buildLldpOutput($def['vendor'], $def);
}

// Create one listening socket bound to 0.0.0.0:2222 (all IPs).
$main = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($main, SOL_SOCKET, SO_REUSEADDR, 1);
if (!socket_bind($main, '0.0.0.0', 2222)) {
    fwrite(STDERR, "bind 0.0.0.0:2222 failed\n");
    exit(1);
}
socket_listen($main);
echo "SSH mock listening on 0.0.0.0:2222\n";
echo "SSH mock started. Press Ctrl-C to stop.\n";

while (true) {
    $client = socket_accept($main);
    if (!$client) continue;
    socket_getsockname($client, $localIp);
    $vendor = $ipToVendor[$localIp] ?? 'cisco';
    $prompt = $vendor === 'huawei' ? '<' . str_replace(['.', ':'], '-', $localIp) . '>' : str_replace(['.', ':'], '-', $localIp) . '#';

    // login
    socket_write($client, "login as:\n");
    $user = rtrim(socket_read($client, 1024, PHP_NORMAL_READ), "\r\n");
    if ($user !== 'admin') {
        socket_write($client, "Access denied\n");
        socket_close($client);
        continue;
    }
    // password
    socket_write($client, "admin@{$localIp}'s password:\n");
    $pass = rtrim(socket_read($client, 1024, PHP_NORMAL_READ), "\r\n");
    if ($pass !== 'admin') {
        socket_write($client, "\nAccess denied\n");
        socket_close($client);
        continue;
    }
    // prompt
    socket_write($client, $prompt . "\n");

    // command loop
    while (true) {
        $cmd = rtrim(socket_read($client, 1024, PHP_NORMAL_READ), "\r\n");
        if ($cmd === false || $cmd === '') break;
        $cmdLower = strtolower($cmd);
        if ($cmd === 'exit' || $cmd === 'quit') break;
        $out = '';
        if (strpos($cmdLower, 'lldp') !== false && (strpos($cmdLower, 'neighbor') !== false || strpos($cmdLower, 'remote') !== false)) {
            $out = $lldpOutputs[$localIp] ?? "No LLDP neighbors\n";
        } elseif (strpos($cmdLower, 'show version') !== false || strpos($cmdLower, 'display version') !== false) {
            $out = ($vendor === 'cisco' ? "Cisco IOS Software\n" : ($vendor === 'huawei' ? "Huawei VRP\n" : "HP ProCurve Switch\n"));
        } elseif ($cmd === '') {
            // empty
        } else {
            $out = "% Invalid input\n";
        }
        socket_write($client, $out . $prompt . "\n");
    }
    socket_close($client);
}
