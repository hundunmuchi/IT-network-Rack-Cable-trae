<?php
/**
 * 入口：启动本地 SNMP 模拟器，同时模拟 HP / Cisco / Huawei 三台交换机。
 *
 * 用法（在 PowerShell 里）:
 *   & "e:\IT-network-Rack-Cable-trae\_tools\php74\php.exe" `
 *       "e:\IT-network-Rack-Cable-trae\_tools\snmp_sim\run.php"
 *
 * 每台设备独占一个 UDP 端口（默认 11611 / 11612 / 11613），
 * LibreNMS 添加设备时通过 --port=11611 等参数指向它。
 *
 * 选项:
 *   --community=public   设置 SNMP 团体字（默认 public）
 *   --quiet              只输出错误
 *   --port-base=11610    端口基址（实际端口 = 基址 + 1/2/3）
 */
require_once __DIR__ . '/SnmpAgent.php';

$opts = getopt('', array('community::', 'quiet'));
$community = isset($opts['community']) ? $opts['community'] : 'public';
$quiet = isset($opts['quiet']);

$devices = require __DIR__ . '/devices.php';

$agent = new SnmpAgent();
$agent->setCommunity($community);
$agent->setQuiet($quiet);

// Collect SNMPv3 users from all devices (they share the same creds).
$v3Users = array();
foreach ($devices as $def) {
    if (isset($def['v3']['user'])) {
        $v3Users[$def['v3']['user']] = array(
            'authPass' => $def['v3']['authPass'],
            'privPass' => $def['v3']['privPass'],
            'authAlgo' => $def['v3']['authAlgo'],
            'privAlgo' => $def['v3']['privAlgo'],
        );
    }
}
if (!empty($v3Users)) {
    $agent->setV3Users($v3Users);
    echo "SNMPv3 users configured: " . implode(', ', array_keys($v3Users)) . "\n";
}

foreach ($devices as $key => $def) {
    try {
        $agent->addBinding($key, $def);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Failed to bind device $key: " . $e->getMessage() . "\n");
        exit(1);
    }
}

$agent->run();
