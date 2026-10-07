<?php
$host = '127.0.0.2'; $port = 2222; $user = 'admin'; $pass = 'admin';
$sock = fsockopen($host, $port, $errno, $errstr, 3);
stream_set_timeout($sock, 2);
fgets($sock, 1024); fwrite($sock, "admin\n");
fgets($sock, 1024); fwrite($sock, "admin\n");
$prompt = rtrim(fgets($sock, 1024), "\r\n");
echo "prompt=[$prompt]\n";
$cmd = "show lldp info remote-device";
fwrite($sock, $cmd . "\n");
$output = '';
$end = time() + 5;
while (time() < $end) {
    $chunk = fread($sock, 4096);
    if ($chunk === false || $chunk === '') break;
    $output .= $chunk;
    if (strpos($output, $prompt . "\n") !== false) break;
}
fclose($sock);
echo "=== RAW OUTPUT ===\n";
echo $output;
echo "=== END ===\n";

// Now test parsing
$lines = explode("\n", $output);
$result = [];
$started = false;
foreach ($lines as $line) {
    $t = rtrim($line, "\r");
    if (!$started) {
        if (trim($t) === trim($cmd)) { $started = true; }
        continue;
    }
    if ($t === $prompt) break;
    $result[] = $t;
}
$parsed = implode("\n", $result);
echo "=== PARSED ===\n$parsed\n=== END PARSED ===\n";

// Try HP regex
$neighbors = [];
foreach (explode("\n", $parsed) as $line) {
    if (preg_match('/^\s*(\d+)\s*\|\s*(\S+)\s*\|\s*(\S+)\s*\|\s*(.+?)\s*\|\s*(\S+)\s*$/', $line, $m)) {
        $neighbors[] = ['chassisId' => $m[2], 'portId' => $m[3], 'portDesc' => trim($m[4]), 'sysName' => trim($m[5])];
    }
}
echo "=== NEIGHBORS: " . count($neighbors) . " ===\n";
print_r($neighbors);
