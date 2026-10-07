<?php
/**
 * ssh_topology.php - 通过 SSH（或 mock）对设备做 LLDP 递归发现，绘制拓扑。
 *
 * 用法:
 *   php ssh_topology.php [--start=10.0.0.2] [--user=admin] [--pass=admin] [--port=2222]
 *
 * 流程:
 *   1. 从 --start 指定的设备开始 SSH 连接
 *   2. 执行厂商对应的 LLDP 命令
 *   3. 解析输出，提取邻居 chassisId/portId/sysName
 *   4. 将可管理的邻居入队继续递归（BFS）
 *   5. 把所有链路写入 LibreNMS 的 links 表，protocol='ssh-lldp'
 *
 * 对真实设备，把 connectSsh() 中的 fsockopen 替换为 phpseclib Net_SSH2 即可。
 */

$devices = require __DIR__ . '/../snmp_sim/devices.php';

$opts = getopt('', ['start::', 'user::', 'pass::', 'port::']);
$startIp = $opts['start'] ?? '127.0.0.2';
$user = $opts['user'] ?? 'admin';
$pass = $opts['pass'] ?? 'admin';
$port = (int)($opts['port'] ?? 2222);

$dbPath = 'e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\storage\librenms.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Map sysName / ip / hostname -> device_id from existing devices.
$nameToDevice = [];
$devIdToHostname = [];
foreach ($pdo->query("SELECT device_id, sysName, ip, hostname FROM devices") as $r) {
    $nameToDevice[$r['sysName']] = $r['device_id'];
    $nameToDevice[$r['ip']] = $r['device_id'];
    $nameToDevice[$r['hostname']] = $r['device_id'];
    $devIdToHostname[$r['device_id']] = $r['hostname'];
}

/**
 * Connect to a mock SSH endpoint, authenticate, run a command, return output.
 * For real devices, replace this with phpseclib\Net\SSH2:
 *   $ssh = new \phpseclib\Net\SSH2($host, $port);
 *   $ssh->login($user, $pass);
 *   return $ssh->exec($cmd);
 */
function sshExec(string $host, int $port, string $user, string $pass, string $cmd): ?string {
    $sock = @fsockopen($host, $port, $errno, $errstr, 3);
    if (!$sock) return null;
    stream_set_timeout($sock, 2);
    // Read "login as:\n"
    fgets($sock, 1024);
    fwrite($sock, $user . "\n");
    // Read password prompt line
    fgets($sock, 1024);
    fwrite($sock, $pass . "\n");
    // Read device prompt line (e.g. "127-0-0-2#" or "<127-0-0-4>")
    $prompt = rtrim(fgets($sock, 1024), "\r\n");
    if ($prompt === '' || strpos($prompt, 'denied') !== false) { fclose($sock); return null; }
    // Send command
    fwrite($sock, $cmd . "\n");
    // Read until prompt returns
    $output = '';
    $endTime = time() + 5;
    while (time() < $endTime) {
        $chunk = fread($sock, 4096);
        if ($chunk === false || $chunk === '') break;
        $output .= $chunk;
        if (strpos($output, $prompt . "\n") !== false) break;
    }
    fclose($sock);
    // Strip trailing prompt line. The mock does not echo the command.
    $lines = explode("\n", $output);
    $result = [];
    foreach ($lines as $line) {
        $t = rtrim($line, "\r");
        if ($t === $prompt) break;
        $result[] = $t;
    }
    return implode("\n", $result);
}

/**
 * Choose LLDP command based on device vendor (guessed from first connection's
 * prompt or a predefined map). For the mock we detect by IP.
 */
function lldpCommand(string $ip): string {
    $last = (int)substr($ip, strrpos($ip, '.') + 1);
    // 2=HP, 3=Cisco, 4=Huawei in our mock.
    if ($last === 2) return 'show lldp info remote-device';
    if ($last === 3) return 'show lldp neighbors detail';
    return 'display lldp neighbor';
}

/**
 * Parse LLDP output into neighbors: [ ['chassisId','portId','portDesc','sysName'], ... ]
 */
function parseLldp(string $output, string $vendor): array {
    $neighbors = [];
    $lines = explode("\n", $output);
    if ($vendor === 'cisco') {
        // Cisco format: Local Intf | Chassis id | Port id | Port Description | System Name
        // Neighbor lines start with an interface name (contains a digit).
        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match('/^([A-Za-z]+\d[\S]*)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.+)$/', $t, $m)) {
                $neighbors[] = ['chassisId' => $m[2], 'portId' => $m[3], 'portDesc' => $m[4], 'sysName' => trim($m[5])];
            }
        }
    } elseif ($vendor === 'huawei') {
        $cur = [];
        foreach ($lines as $line) {
            if (preg_match('/Chassis ID\s*:\s*(.+)/', $line, $m)) $cur['chassisId'] = trim($m[1]);
            elseif (preg_match('/Port ID\s*:\s*(.+)/', $line, $m)) $cur['portId'] = trim($m[1]);
            elseif (preg_match('/Port description\s*:\s*(.+)/', $line, $m)) $cur['portDesc'] = trim($m[1]);
            elseif (preg_match('/System name\s*:\s*(.+)/', $line, $m)) {
                $cur['sysName'] = trim($m[1]);
                $neighbors[] = $cur;
                $cur = [];
            }
        }
    } else { // HP / generic table
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s*\|\s*(\S+)\s*\|\s*(\S+)\s*\|\s*(.+?)\s*\|\s*(\S+)\s*$/', $line, $m)) {
                $neighbors[] = ['chassisId' => $m[2], 'portId' => $m[3], 'portDesc' => trim($m[4]), 'sysName' => trim($m[5])];
            }
        }
    }
    return $neighbors;
}

function vendorOf(string $ip): string {
    $last = (int)substr($ip, strrpos($ip, '.') + 1);
    if ($last === 2) return 'hp';
    if ($last === 3) return 'cisco';
    return 'huawei';
}

echo "=== SSH LLDP Topology Discovery ===\n";
echo "Start: $startIp (user=$user, port=$port)\n\n";

// Clear previous ssh-lldp links.
$pdo->exec("DELETE FROM links WHERE protocol='ssh-lldp'");

$visited = [];
$queue = [$startIp];
$edges = []; // [localIp, localPort, remoteSysName, remotePort]

while (!empty($queue)) {
    $ip = array_shift($queue);
    if (isset($visited[$ip])) continue;
    $visited[$ip] = true;

    $vendor = vendorOf($ip);
    $cmd = lldpCommand($ip);
    echo "[SSH] $ip ($vendor) -> $cmd\n";
    $output = sshExec($ip, $port, $user, $pass, $cmd);
    if ($output === null) {
        echo "  FAILED to connect\n";
        continue;
    }
    $neighbors = parseLldp($output, $vendor);
    echo "  found " . count($neighbors) . " neighbor(s)\n";

    foreach ($neighbors as $n) {
        $edges[] = [
            'localIp'     => $ip,
            'localPort'   => $n['portId'],
            'remoteName'  => $n['sysName'],
            'remotePort'  => $n['portId'],
            'chassisId'   => $n['chassisId'],
            'vendor'      => $vendor,
        ];
        // If neighbor sysName maps to a known device, enqueue its hostname.
        if (isset($nameToDevice[$n['sysName']])) {
            $devId = $nameToDevice[$n['sysName']];
            $neighborHost = $devIdToHostname[$devId] ?? null;
            if ($neighborHost && !isset($visited[$neighborHost])) {
                $queue[] = $neighborHost;
            }
        }
    }
}

echo "\n=== Writing " . count($edges) . " links to DB ===\n";
$insert = $pdo->prepare("INSERT INTO links (local_port_id, local_device_id, remote_port_id, remote_device_id, active, protocol, remote_hostname, remote_port, remote_platform, remote_version) VALUES (?, ?, ?, ?, 1, 'ssh-lldp', ?, ?, ?, ?)");
foreach ($edges as $e) {
    $localDevId = $nameToDevice[$e['localIp']] ?? null;
    $remoteDevId = $nameToDevice[$e['remoteName']] ?? 0;
    if ($localDevId === null) {
        echo "  SKIP unknown local device: {$e['localIp']}\n";
        continue;
    }
    $localPort = $pdo->query("SELECT port_id FROM ports WHERE device_id=$localDevId ORDER BY port_id LIMIT 1")->fetchColumn();
    $remotePort = $remoteDevId ? $pdo->query("SELECT port_id FROM ports WHERE device_id=$remoteDevId ORDER BY port_id LIMIT 1")->fetchColumn() : 0;
    $platform = $e['vendor'] === 'cisco' ? 'Cisco IOS' : ($e['vendor'] === 'huawei' ? 'Huawei VRP' : 'HP ProCurve');
    $version = $e['vendor'] === 'cisco' ? '16.12' : ($e['vendor'] === 'huawei' ? 'V200R019' : 'K.13.25');
    $insert->execute([$localPort, $localDevId, (int)$remotePort, (int)$remoteDevId, $e['remoteName'], $e['remotePort'], $platform, $version]);
    echo "  LINK: {$e['localIp']}:{$e['localPort']} <-> {$e['remoteName']}:{$e['remotePort']}\n";
}

echo "\n=== Done. Total links in DB (ssh-lldp): " . $pdo->query("SELECT COUNT(*) FROM links WHERE protocol='ssh-lldp'")->fetchColumn() . " ===\n";
echo "View topology at: http://127.0.0.1:5656/map/dependency\n";
