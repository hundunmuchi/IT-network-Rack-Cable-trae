<?php
// Quick test: connect to mock SSH and show raw output.
$host = '127.0.0.2'; $port = 2222; $user = 'admin'; $pass = 'admin';
$sock = fsockopen($host, $port, $errno, $errstr, 3);
if (!$sock) { echo "connect failed\n"; exit; }
stream_set_timeout($sock, 3);
echo "login prompt: " . rtrim(fgets($sock, 1024), "\r\n") . "\n";
fwrite($sock, $user . "\n");
echo "pass prompt: " . rtrim(fgets($sock, 1024), "\r\n") . "\n";
fwrite($sock, $pass . "\n");
$prompt = rtrim(fgets($sock, 1024), "\r\n");
echo "prompt: [$prompt]\n";

$cmd = 'show lldp info remote-device';
fwrite($sock, $cmd . "\n");
$output = '';
$end = time() + 3;
while (time() < $end) {
    $chunk = fread($sock, 4096);
    if ($chunk === false || $chunk === '') break;
    $output .= $chunk;
    if (strpos($output, $prompt) !== false) break;
}
fclose($sock);
echo "=== RAW OUTPUT ===\n";
echo $output;
echo "=== END ===\n";
