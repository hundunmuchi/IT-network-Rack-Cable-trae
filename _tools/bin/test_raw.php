<?php
require_once 'e:\IT-network-Rack-Cable-trae\_tools\bin\ssh_topology.php';
// Can't require because it runs. Let's just inline the function logic.
$host = '127.0.0.2'; $port = 2222; $user = 'admin'; $pass = 'admin';
$sock = fsockopen($host, $port, $errno, $errstr, 3);
stream_set_timeout($sock, 2);
fgets($sock, 1024); fwrite($sock, "admin\n");
fgets($sock, 1024); fwrite($sock, "admin\n");
$prompt = rtrim(fgets($sock, 1024), "\r\n");
echo "prompt=[$prompt]\n";
fwrite($sock, "show lldp info remote-device\n");
$output = '';
$end = time() + 5;
while (time() < $end) {
    $chunk = fread($sock, 4096);
    if ($chunk === false || $chunk === '') break;
    $output .= $chunk;
    if (strpos($output, $prompt . "\n") !== false) break;
}
fclose($sock);
echo "=== RAW ===\n";
echo $output;
echo "=== END ===\n";
