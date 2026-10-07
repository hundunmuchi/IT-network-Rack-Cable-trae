<?php
// Debug script - test raw SNMP GETNEXT against the local simulator.

require_once 'e:\IT-network-Rack-Cable-trae\_tools\snmp_sim\Ber.php';

$host = '127.0.0.2';
$port = 11611;
$community = 'public';
$oid = '1.3.6.1.2.1.1.1.0';  // sysDescr.0

// Build GETNEXT message.
$oidEncoded = Ber::encodeOid($oid);
echo "Encoded OID: " . bin2hex($oidEncoded) . "\n";

$varbind = Ber::encodeSequence($oidEncoded . Ber::encodeNull());
echo "Varbind: " . bin2hex($varbind) . "\n";

$varbindList = Ber::encodeSequence($varbind);
echo "VarbindList: " . bin2hex($varbindList) . "\n";

$reqId = 12345;
$pduContent = Ber::encodeInteger($reqId) . Ber::encodeInteger(0) . Ber::encodeInteger(0) . $varbindList;
$pdu = Ber::tlv(0xA1, $pduContent);
echo "PDU: " . bin2hex($pdu) . "\n";

$msg = Ber::encodeSequence(Ber::encodeInteger(1) . Ber::encodeOctetString($community) . $pdu);
echo "Message: " . bin2hex($msg) . "\n";

// Send via UDP.
$errno = 0; $errstr = '';
$sock = @fsockopen("udp://$host", $port, $errno, $errstr, 1);
if (!$sock) {
    echo "fsockopen failed: $errstr ($errno)\n";
    exit(1);
}
stream_set_timeout($sock, 2, 0);
echo "Socket opened.\n";
$written = fwrite($sock, $msg);
echo "Wrote $written bytes.\n";
$response = fread($sock, 65535);
$info = stream_get_meta_data($sock);
echo "Response bytes: " . strlen($response) . "\n";
echo "Stream info: " . json_encode($info) . "\n";
echo "Response hex: " . bin2hex($response) . "\n";
fclose($sock);

// Parse response.
$pos = 0;
$outerTlv = Ber::parseTlv($response, $pos);
echo "Outer TLV tag: " . dechex($outerTlv['tag']) . "\n";
$inner = $outerTlv['value'];
$ipos = 0;
$ver = Ber::parseTlv($inner, $ipos);
echo "Version: " . Ber::parseInteger($ver['value']) . "\n";
$comm = Ber::parseTlv($inner, $ipos);
echo "Community: " . $comm['value'] . "\n";
$pduTlv = Ber::parseTlv($inner, $ipos);
echo "PDU tag: " . dechex($pduTlv['tag']) . "\n";
$ppos = 0;
$reqIdTlv = Ber::parseTlv($pduTlv['value'], $ppos);
echo "ReqID: " . Ber::parseInteger($reqIdTlv['value']) . "\n";
$errStatTlv = Ber::parseTlv($pduTlv['value'], $ppos);
echo "ErrStat: " . Ber::parseInteger($errStatTlv['value']) . "\n";
$errIdxTlv = Ber::parseTlv($pduTlv['value'], $ppos);
echo "ErrIdx: " . Ber::parseInteger($errIdxTlv['value']) . "\n";
$vblTlv = Ber::parseTlv($pduTlv['value'], $ppos);
echo "VBL tag: " . dechex($vblTlv['tag']) . ", len=" . strlen($vblTlv['value']) . "\n";
$vbpos = 0;
$vbTlv = Ber::parseTlv($vblTlv['value'], $vbpos);
echo "VB tag: " . dechex($vbTlv['tag']) . ", len=" . strlen($vbTlv['value']) . "\n";
$vpos = 0;
$oidTlv = Ber::parseTlv($vbTlv['value'], $vpos);
echo "Returned OID tag: " . dechex($oidTlv['tag']) . "\n";
echo "Returned OID: ." . Ber::parseOid($oidTlv['value']) . "\n";
$valTlv = Ber::parseTlv($vbTlv['value'], $vpos);
echo "Value tag: " . dechex($valTlv['tag']) . "\n";
echo "Value hex: " . bin2hex($valTlv['value']) . "\n";
