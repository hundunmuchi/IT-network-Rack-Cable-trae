<?php
/**
 * fping.php - Stub fping replacement that always reports success.
 *
 * Real fping is not available on Windows. LibreNMS uses fping to do
 * ICMP ping checks against devices. Since our simulated devices are
 * on loopback (127.0.0.0/8), we just return a successful ping
 * response for any host.
 *
 * Output format (matches net-snmp fping -e -q -c <count> ...):
 *   <host> : xmt/rcv/%loss = <count>/<count>/0%, min/avg/max = 0.05/0.05/0.05
 *
 * The exit code is 0 (success).
 */

// Find the host argument (last positional arg).
$host = null;
$count = 3;
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '-c' && isset($argv[$i + 1])) {
        $count = (int)$argv[++$i];
    } elseif ($a === '-t' && isset($argv[$i + 1])) {
        ++$i;
    } elseif ($a === '-p' && isset($argv[$i + 1])) {
        ++$i;
    } elseif ($a === '-O' && isset($argv[$i + 1])) {
        ++$i;
    } elseif ($a === '-e' || $a === '-q' || $a === '-6' || $a === '-4') {
        continue;
    } elseif (substr($a, 0, 1) === '-') {
        // Unknown flag - skip its value if it expects one.
        continue;
    } else {
        $host = $a;
    }
}

if ($host === null) {
    fwrite(STDERR, "fping: no host specified\n");
    exit(1);
}

// Always succeed - simulate fast ping response.
// Note: fping writes results to STDERR (not STDOUT). LibreNMS reads from getErrorOutput().
$latency = 0.05;
fwrite(STDERR, sprintf("%s : xmt/rcv/%%loss = %d/%d/0%%, min/avg/max = %.2f/%.2f/%.2f\n",
    $host, $count, $count, $latency, $latency, $latency));
exit(0);
