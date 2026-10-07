<?php
/**
 * netsnmp_wrap.php - Pure-PHP wrapper that mimics net-snmp CLI binaries
 * (snmpget, snmpwalk, snmpbulkwalk, snmpgetnext, snmptranslate).
 *
 * Uses PHP's snmp module (snmp2_get / snmp2_real_walk / snmp2_getnext)
 * to actually query the local SNMP simulator, then formats output
 * in the net-snmp CLI style that LibreNMS' SnmpResponse parser expects.
 *
 * The wrapper inspects $argv[0] (the script name) to determine which
 * net-snmp binary it should behave as.  When invoked from a .cmd
 * shim (e.g. snmpget.cmd -> "php netsnmp_wrap.php snmpget ..."), the
 * binary name is taken from the first positional argument instead.
 *
 * Output format follows net-snmp's -OQ flags (the LibreNMS default):
 *   OID = "string"      for octet-strings (value quoted)
 *   OID = 5             for integers
 *   OID = 12345         for timeticks (raw integer, no formatted time)
 *   OID = .1.2.3.4      for object identifiers
 *   OID = 1.2.3.4       for IP addresses
 *   OID = 41 42 43      for hex strings
 */

// Set MIBDIRS if missing so PHP's snmp module can resolve textual OIDs.
$defaultMibDir = 'e:\IT-network-Rack-Cable-trae\LibreNMS-offline\src\lnms\mibs';
if (!getenv('MIBDIRS')) {
    putenv('MIBDIRS=' . $defaultMibDir);
}

// Load BER encoder for raw SNMP getnext (PHP's snmp2_getnext doesn't return OID).
require_once 'e:\IT-network-Rack-Cable-trae\_tools\snmp_sim\Ber.php';

/**
 * Common OID map for textual <-> numeric translation.
 */
function getOidMap(): array {
    return [
        // SNMPv2-MIB
        'SNMPv2-MIB::sysDescr' => '1.3.6.1.2.1.1.1',
        'SNMPv2-MIB::sysObjectID' => '1.3.6.1.2.1.1.2',
        'SNMPv2-MIB::sysUpTime' => '1.3.6.1.2.1.1.3',
        'SNMPv2-MIB::sysContact' => '1.3.6.1.2.1.1.4',
        'SNMPv2-MIB::sysName' => '1.3.6.1.2.1.1.5',
        'SNMPv2-MIB::sysLocation' => '1.3.6.1.2.1.1.6',
        'SNMPv2-MIB::sysServices' => '1.3.6.1.2.1.1.7',
        'SNMPv2-MIB::sysORTable' => '1.3.6.1.2.1.1.9',
        'SNMPv2-MIB::sysORID' => '1.3.6.1.2.1.1.9.1.1',
        // IF-MIB
        'IF-MIB::ifTable' => '1.3.6.1.2.1.2.2',
        'IF-MIB::ifEntry' => '1.3.6.1.2.1.2.2.1',
        'IF-MIB::ifIndex' => '1.3.6.1.2.1.2.2.1.1',
        'IF-MIB::ifDescr' => '1.3.6.1.2.1.2.2.1.2',
        'IF-MIB::ifType' => '1.3.6.1.2.1.2.2.1.3',
        'IF-MIB::ifMtu' => '1.3.6.1.2.1.2.2.1.4',
        'IF-MIB::ifSpeed' => '1.3.6.1.2.1.2.2.1.5',
        'IF-MIB::ifPhysAddress' => '1.3.6.1.2.1.2.2.1.6',
        'IF-MIB::ifAdminStatus' => '1.3.6.1.2.1.2.2.1.7',
        'IF-MIB::ifOperStatus' => '1.3.6.1.2.1.2.2.1.8',
        'IF-MIB::ifInOctets' => '1.3.6.1.2.1.2.2.1.10',
        'IF-MIB::ifOutOctets' => '1.3.6.1.2.1.2.2.1.14',
        'IF-MIB::ifXTable' => '1.3.6.1.2.1.31.1.1',
        'IF-MIB::ifName' => '1.3.6.1.2.1.31.1.1.1.1',
        'IF-MIB::ifAlias' => '1.3.6.1.2.1.31.1.1.1.18',
        'IF-MIB::ifStackTable' => '1.3.6.1.2.1.31.1.2',
        'IF-MIB::ifHCInOctets' => '1.3.6.1.2.1.31.1.1.1.6',
        'IF-MIB::ifHCOutOctets' => '1.3.6.1.2.1.31.1.1.1.10',
        // IP-MIB
        'IP-MIB::ipAdEntAddr' => '1.3.6.1.2.1.4.20.1.1',
        'IP-MIB::ipAdEntIfIndex' => '1.3.6.1.2.1.4.20.1.2',
        'IP-MIB::ipAdEntNetMask' => '1.3.6.1.2.1.4.20.1.3',
        'IP-MIB::ipNetToMediaTable' => '1.3.6.1.2.1.4.22',
        // LLDP-MIB
        'LLDP-MIB::lldpRemTable' => '1.0.8802.1.1.2.1.4.1',
        'LLDP-MIB::lldpRemSysName' => '1.0.8802.1.1.2.1.4.1.1.9',
        'LLDP-MIB::lldpRemPortDesc' => '1.0.8802.1.1.2.1.4.1.1.8',
        'LLDP-MIB::lldpRemChassisId' => '1.0.8802.1.1.2.1.4.1.1.5',
        'LLDP-MIB::lldpRemPortId' => '1.0.8802.1.1.2.1.4.1.1.7',
        // ENTITY-MIB
        'ENTITY-MIB::entPhysicalTable' => '1.3.6.1.2.1.47.1.1.1',
        'ENTITY-MIB::entPhysicalEntry' => '1.3.6.1.2.1.47.1.1.1.1',
        'ENTITY-MIB::entPhysicalDescr' => '1.3.6.1.2.1.47.1.1.1.1.2',
        'ENTITY-MIB::entPhysicalName' => '1.3.6.1.2.1.47.1.1.1.1.7',
        'ENTITY-MIB::entPhysicalModelName' => '1.3.6.1.2.1.47.1.1.1.1.13',
        // HOST-RESOURCES-MIB
        'HOST-RESOURCES-MIB::hrStorageTable' => '1.3.6.1.2.1.25.2.3.1',
        'HOST-RESOURCES-MIB::hrStorageDescr' => '1.3.6.1.2.1.25.2.3.1.3',
        'HOST-RESOURCES-MIB::hrStorageUsed' => '1.3.6.1.2.1.25.2.3.1.5',
        // SNMP-FRAMEWORK-MIB
        'SNMP-FRAMEWORK-MIB::snmpEngineTime' => '1.3.6.1.6.3.10.2.1.3',
        'SNMP-FRAMEWORK-MIB::snmpEngineBoots' => '1.3.6.1.6.3.10.2.1.2',
    ];
}

/**
 * Convert textual OID (e.g. "SNMPv2-MIB::sysDescr.0") to numeric dotted form.
 * Returns the numeric OID without leading dot, or empty string if unknown.
 */
function textualToNumeric(string $oid): string {
    // Already numeric?
    if (preg_match('/^\.?\d+(\.\d+)+$/', ltrim($oid, '.'))) {
        return ltrim($oid, '.');
    }
    // Try direct map lookup.
    $map = getOidMap();
    if (isset($map[$oid])) {
        return $map[$oid];
    }
    // Try base + index (e.g. "SNMPv2-MIB::sysDescr.0" -> base "SNMPv2-MIB::sysDescr" + ".0")
    $baseOid = preg_replace('/\.\d+$/', '', $oid);
    if (isset($map[$baseOid])) {
        $idx = substr($oid, strlen($baseOid));
        return $map[$baseOid] . $idx;
    }
    // Fallback: ask PHP's snmp module via real_walk against simulator (best-effort).
    static $simTarget = null;
    if ($simTarget === null) {
        $simTarget = '127.0.0.2:11611';
    }
    $oldFormat = snmp_get_oid_output_format();
    snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
    $result = @snmp2_real_walk($simTarget, 'public', $oid, 1000000, 1);
    snmp_set_oid_output_format($oldFormat);
    if (is_array($result) && count($result) > 0) {
        $key = array_key_first($result);
        return ltrim($key, '.');
    }
    return '';
}

// PHP 7.4+ polyfill for array_key_first if missing.
if (!function_exists('array_key_first')) {
    function array_key_first(array $arr) {
        foreach ($arr as $key => $unused) return $key;
        return null;
    }
}

/**
 * Convert numeric OID (.1.3.6.1.2.1.1.1.0) to textual form (SNMPv2-MIB::sysDescr.0).
 * Falls back to numeric if unknown.
 */
function numericToTextual(string $numeric): string {
    $numeric = ltrim($numeric, '.');
    $map = getOidMap();
    $reverseMap = array_flip($map);
    // Try direct lookup with leading dot.
    $key = '.' . $numeric;
    if (isset($reverseMap[$key])) {
        return $reverseMap[$key];
    }
    // Try base + index. Find longest matching prefix.
    foreach ($reverseMap as $numOid => $textOid) {
        if (strpos($numeric, $numOid) === 0) {
            $idx = substr($numeric, strlen($numOid));
            return $textOid . $idx;
        }
    }
    return '.' . $numeric;
}

/**
 * Send a raw SNMP v2c GetNext request and return [oid, value] pair.
 * Returns null on failure.
 */
function rawSnmpGetnext(string $host, int $port, string $community, string $numericOid, int $timeoutUsec = 1000000, int $retries = 3): ?array {
    // Build varbind: SEQUENCE { OID, NULL }
    $oidEncoded = Ber::encodeOid($numericOid);
    $varbind = Ber::encodeSequence($oidEncoded . Ber::encodeNull());
    // varbind list: SEQUENCE { varbind }
    $varbindList = Ber::encodeSequence($varbind);
    // GetNextRequest PDU: tag 0xA1, content = reqid + error_status + error_index + varbind_list
    $reqId = mt_rand(1, 0x7FFFFFFF);
    $pduContent = Ber::encodeInteger($reqId) . Ber::encodeInteger(0) . Ber::encodeInteger(0) . $varbindList;
    $pdu = Ber::tlv(0xA1, $pduContent);
    // SNMP v2c message: SEQUENCE { version (1), community, pdu }
    $msg = Ber::encodeSequence(Ber::encodeInteger(1) . Ber::encodeOctetString($community) . $pdu);

    // Send via UDP, receive response.
    $errno = 0; $errstr = '';
    $sock = @fsockopen("udp://$host", $port, $errno, $errstr, 1);
    if (!$sock) return null;
    stream_set_timeout($sock, (int)($timeoutUsec / 1000000), ($timeoutUsec % 1000000) * 1000);
    fwrite($sock, $msg);
    $response = fread($sock, 65535);
    fclose($sock);
    if ($response === false || $response === '') return null;

    // Parse response: SEQUENCE { version, community, ResponsePDU }
    $pos = 0;
    $outerTlv = Ber::parseTlv($response, $pos);
    if ($outerTlv['tag'] !== 0x30) return null;
    $inner = $outerTlv['value'];
    $ipos = 0;
    $ver = Ber::parseTlv($inner, $ipos);
    $comm = Ber::parseTlv($inner, $ipos);
    $pduTlv = Ber::parseTlv($inner, $ipos);
    if ($pduTlv['tag'] !== 0xA2) return null; // Response-PDU

    $ppos = 0;
    $reqId = Ber::parseTlv($pduTlv['value'], $ppos);
    $errStat = Ber::parseTlv($pduTlv['value'], $ppos);
    $errIdx = Ber::parseTlv($pduTlv['value'], $ppos);
    $vblTlv = Ber::parseTlv($pduTlv['value'], $ppos);
    if ($vblTlv['tag'] !== 0x30) return null;

    // Parse varbind list - take first varbind only.
    $vbpos = 0;
    $vbTlv = Ber::parseTlv($vblTlv['value'], $vbpos);
    if ($vbTlv['tag'] !== 0x30) return null;
    $vpos = 0;
    $oidTlv = Ber::parseTlv($vbTlv['value'], $vpos);
    if ($oidTlv['tag'] !== 0x06) return null;
    $valTlv = Ber::parseTlv($vbTlv['value'], $vpos);

    $returnedOid = Ber::parseOid($oidTlv['value']);
    // Decode value based on tag.
    $val = decodeSnmpValue($valTlv['tag'], $valTlv['value']);

    return ['oid' => $returnedOid, 'value' => $val];
}

/**
 * Decode SNMP value from BER tag + content.
 */
function decodeSnmpValue(int $tag, string $content): string {
    switch ($tag) {
        case 0x02: // INTEGER
            return 'INTEGER: ' . Ber::parseInteger($content);
        case 0x04: // OCTET STRING
            $val = $content;
            // Try to display as readable string if printable.
            $printable = preg_match('/^[\x20-\x7E]+$/', $val);
            if ($printable) {
                return 'STRING: ' . $val;
            } else {
                $hex = implode(' ', str_split(bin2hex($val), 2));
                return 'Hex-STRING: ' . $hex;
            }
        case 0x05: // NULL
            return 'NULL';
        case 0x06: // OBJECT IDENTIFIER
            return 'OID: .' . Ber::parseOid($content);
        case 0x40: // IpAddress
            return 'IpAddress: ' . Ber::parseIpAddress($content);
        case 0x41: // Counter32
            return 'Counter32: ' . Ber::parseUnsigned($content);
        case 0x42: // Gauge32 / Unsigned32
            return 'Gauge32: ' . Ber::parseUnsigned($content);
        case 0x43: // TimeTicks
            return 'Timeticks: (' . Ber::parseUnsigned($content) . ')';
        case 0x44: // Opaque
            return 'Opaque: ' . bin2hex($content);
        case 0x46: // Counter64
            return 'Counter64: ' . Ber::parseUnsigned($content);
        case 0x47: // UInteger32
            return 'UInteger32: ' . Ber::parseUnsigned($content);
        default:
            return 'UNKNOWN(' . dechex($tag) . '): ' . bin2hex($content);
    }
}

/* ======================================================================
 * SNMPv3 client helpers (raw UDP, USM authPriv: SHA + AES-128)
 * ====================================================================== */

/**
 * RFC 3414 password-to-key localisation.
 */
function v3PasswordToKey(string $password, string $engineID, string $algo = 'SHA'): string {
    $hashFunc = (strtoupper($algo) === 'SHA256') ? 'sha256' : 'sha1';
    $buf = str_repeat($password, (int)ceil(1048576 / strlen($password)));
    $buf = substr($buf, 0, 1048576);
    $digest = hash($hashFunc, $buf, true);
    return hash_hmac($hashFunc, $digest . $engineID . $digest, $digest, true);
}

/**
 * Send an SNMPv3 discovery request (empty userName) and return the
 * engineID/boots/time reported by the agent.
 */
function v3Discovery(string $host, int $port): ?array {
    $msgID = mt_rand(1, 0x7FFFFFFF);
    $globalData = Ber::encodeSequence(
        Ber::encodeInteger($msgID) .
        Ber::encodeInteger(65507) .
        Ber::encodeOctetString("\x04") . // reportable
        Ber::encodeInteger(3)
    );
    $secParams = Ber::encodeSequence(
        Ber::encodeOctetString('') .   // engineID (empty for discovery)
        Ber::encodeInteger(0) .         // boots
        Ber::encodeInteger(0) .         // time
        Ber::encodeOctetString('') .    // userName
        Ber::encodeOctetString('') .    // authParams
        Ber::encodeOctetString('')      // privParams
    );
    // Empty GetRequest PDU to trigger a report.
    $pdu = Ber::tlv(0xA0,
        Ber::encodeInteger($msgID) .
        Ber::encodeInteger(0) .
        Ber::encodeInteger(0) .
        Ber::tlv(0x30, '')
    );
    $scopedPDU = Ber::encodeSequence(
        Ber::encodeOctetString('') .
        Ber::encodeOctetString('') .
        $pdu
    );
    $msg = Ber::encodeSequence(
        Ber::encodeInteger(3) .
        $globalData .
        Ber::encodeOctetString($secParams) .
        $scopedPDU
    );

    $sock = @fsockopen("udp://$host", $port, $errno, $errstr, 1);
    if (!$sock) return null;
    stream_set_timeout($sock, 1);
    fwrite($sock, $msg);
    $resp = fread($sock, 65535);
    fclose($sock);
    if ($resp === false || $resp === '') return null;

    $pos = 0;
    $outer = Ber::parseTlv($resp, $pos);
    if ($outer['tag'] !== 0x30) return null;
    $items = Ber::parseSequence($outer['value']);
    $secRaw = $items[2]['value'];
    $secItems = Ber::parseSequence($secRaw);
    $engineID = $secItems[0]['value'];
    $boots = Ber::parseInteger($secItems[1]['value']);
    $time = Ber::parseInteger($secItems[2]['value']);
    return ['engineID' => $engineID, 'boots' => $boots, 'time' => $time];
}

/**
 * Build and send an authenticated/encrypted SNMPv3 request and return
 * the parsed response varbinds as [ [oid, value_str], ... ].
 */
function v3SendRequest(string $host, int $port, array $disco, string $user,
                       string $authPass, string $privPass, string $authAlgo,
                       string $privAlgo, string $authLevel,
                       int $pduType, array $oidList, int $nonRep = 0, int $maxRep = 0): ?array {
    $engineID = $disco['engineID'];
    $boots = $disco['boots'];
    $time = $disco['time'];

    $authFlag = stripos($authLevel, 'auth') !== false;
    $privFlag = stripos($authLevel, 'priv') !== false;

    // Build varbinds.
    $vbBody = '';
    foreach ($oidList as $oid) {
        $vbBody .= Ber::tlv(0x30, Ber::encodeOid($oid) . Ber::encodeNull());
    }
    $varbindList = Ber::tlv(0x30, $vbBody);

    $reqId = mt_rand(1, 0x7FFFFFFF);
    if ($pduType === 0xA5) { // GetBulk
        $pduContent = Ber::encodeInteger($reqId) . Ber::encodeInteger($nonRep) . Ber::encodeInteger($maxRep) . $varbindList;
    } else {
        $pduContent = Ber::encodeInteger($reqId) . Ber::encodeInteger(0) . Ber::encodeInteger(0) . $varbindList;
    }
    $pdu = Ber::tlv($pduType, $pduContent);

    $scopedPDU = Ber::encodeSequence(
        Ber::encodeOctetString($engineID) .
        Ber::encodeOctetString('') .
        $pdu
    );

    // Encrypt if priv.
    $privParams = '';
    $msgDataTag = 0xA8; // default: PDU directly (no, actually for v3 msgData is the PDU tag)
    $msgData = $pdu;
    if ($privFlag) {
        $privKey = substr(v3PasswordToKey($privPass, $engineID, $authAlgo), 0, 16);
        $salt = openssl_random_pseudo_bytes(8);
        $iv = $salt . pack('N', $boots) . pack('N', $time);
        $encrypted = openssl_encrypt($scopedPDU, 'aes-128-cfb', $privKey, OPENSSL_RAW_DATA, $iv);
        $privParams = $salt;
        $msgDataTag = 0x04;
        $msgData = $encrypted;
    }

    $msgID = mt_rand(1, 0x7FFFFFFF);
    $flags = chr(0x01 | ($authFlag ? 0x04 : 0) | ($privFlag ? 0x02 : 0));
    $globalData = Ber::encodeSequence(
        Ber::encodeInteger($msgID) .
        Ber::encodeInteger(65507) .
        Ber::encodeOctetString($flags) .
        Ber::encodeInteger(3)
    );

    $authParams = $authFlag ? str_repeat("\x00", 12) : '';
    $secParams = Ber::encodeSequence(
        Ber::encodeOctetString($engineID) .
        Ber::encodeInteger($boots) .
        Ber::encodeInteger($time) .
        Ber::encodeOctetString($user) .
        Ber::encodeOctetString($authParams) .
        Ber::encodeOctetString($privParams)
    );

    $msgZeroAuth = Ber::encodeSequence(
        Ber::encodeInteger(3) .
        $globalData .
        Ber::encodeOctetString($secParams) .
        Ber::tlv($msgDataTag, $msgData)
    );

    if ($authFlag) {
        $authKey = v3PasswordToKey($authPass, $engineID, $authAlgo);
        $digest = substr(hash_hmac('sha1', $msgZeroAuth, $authKey, true), 0, 12);
        $secParams = Ber::encodeSequence(
            Ber::encodeOctetString($engineID) .
            Ber::encodeInteger($boots) .
            Ber::encodeInteger($time) .
            Ber::encodeOctetString($user) .
            Ber::encodeOctetString($digest) .
            Ber::encodeOctetString($privParams)
        );
    }

    $msg = Ber::encodeSequence(
        Ber::encodeInteger(3) .
        $globalData .
        Ber::encodeOctetString($secParams) .
        Ber::tlv($msgDataTag, $msgData)
    );

    $sock = @fsockopen("udp://$host", $port, $errno, $errstr, 1);
    if (!$sock) return null;
    stream_set_timeout($sock, 2);
    fwrite($sock, $msg);
    $resp = fread($sock, 65535);
    fclose($sock);
    if ($resp === false || $resp === '') return null;

    // Parse response.
    $pos = 0;
    $outer = Ber::parseTlv($resp, $pos);
    if ($outer['tag'] !== 0x30) return null;
    $items = Ber::parseSequence($outer['value']);
    $globalResp = Ber::parseSequence($items[1]['value']);
    $respFlags = ord($globalResp[2]['value'][0]);
    $respPriv = ($respFlags & 0x02) != 0;
    $respAuth = ($respFlags & 0x04) != 0;

    $secResp = Ber::parseSequence($items[2]['value']);
    $respEngineID = $secResp[0]['value'];
    $respBoots = Ber::parseInteger($secResp[1]['value']);
    $respTime = Ber::parseInteger($secResp[2]['value']);
    $respPrivParams = isset($secResp[5]) ? $secResp[5]['value'] : '';

    $msgDataResp = $items[3];
    $pduResp = $msgDataResp['value'];
    if ($respPriv) {
        $privKey = substr(v3PasswordToKey($privPass, $respEngineID, $authAlgo), 0, 16);
        $salt = $respPrivParams;
        if (strlen($salt) < 8) $salt = str_pad($salt, 8, "\x00", STR_PAD_LEFT);
        $iv = substr($salt, 0, 8) . pack('N', $respBoots) . pack('N', $respTime);
        $scopedPDU = openssl_decrypt($msgDataResp['value'], 'aes-128-cfb', $privKey, OPENSSL_RAW_DATA, $iv);
        if ($scopedPDU === false) return null;
        $spPos = 0;
        $spTlv = Ber::parseTlv($scopedPDU, $spPos);
        $spItems = Ber::parseSequence($spTlv['value']);
        $pduRespTlv = $spItems[2];
        $pduResp = $pduRespTlv['value'];
    }

    // Parse Response PDU (0xA2) or Report (0xA8).
    $pduItems = Ber::parseSequence($pduResp);
    if (count($pduItems) < 4) return null;
    $errStatus = Ber::parseInteger($pduItems[1]['value']);
    if ($errStatus !== 0) return null;

    $varbinds = Ber::parseSequence($pduItems[3]['value']);
    $result = [];
    foreach ($varbinds as $vb) {
        $vbItems = Ber::parseSequence($vb['value']);
        $oid = Ber::parseOid($vbItems[0]['value']);
        $val = decodeSnmpValue($vbItems[1]['tag'], $vbItems[1]['value']);
        $result[] = ['oid' => '.' . $oid, 'value' => $val];
    }
    return $result;
}

/**
 * High-level v3 helpers used by the switch below.
 */
function v3Get(array $parsed): array {
    $disco = v3Discovery($parsed['host'], $parsed['port']);
    if ($disco === null) return [null, 'Timeout: No Response'];
    $oids = [];
    foreach ($parsed['oids'] as $o) {
        $n = textualToNumeric($o);
        $oids[] = $n !== '' ? $n : ltrim($o, '.');
    }
    $result = v3SendRequest($parsed['host'], $parsed['port'], $disco,
        $parsed['v3_user'], $parsed['v3_authpass'], $parsed['v3_cryptopass'],
        $parsed['v3_authalgo'], $parsed['v3_cryptoalgo'], $parsed['v3_authlevel'],
        0xA0, $oids);
    if ($result === null) return [null, 'Timeout: No Response'];
    $out = '';
    foreach ($result as $i => $vb) {
        $outOid = $parsed['numeric'] ? $vb['oid'] : (numericToTextual(ltrim($vb['oid'], '.')));
        $out .= formatVarbind($outOid, $vb['value'], $parsed['numeric'], $parsed['hide_mib']) . "\n";
    }
    return [$out, ''];
}

function v3GetNext(array $parsed): array {
    $disco = v3Discovery($parsed['host'], $parsed['port']);
    if ($disco === null) return [null, 'Timeout: No Response'];
    $out = '';
    $err = '';
    foreach ($parsed['oids'] as $o) {
        $n = textualToNumeric($o);
        $oid = $n !== '' ? $n : ltrim($o, '.');
        $result = v3SendRequest($parsed['host'], $parsed['port'], $disco,
            $parsed['v3_user'], $parsed['v3_authpass'], $parsed['v3_cryptopass'],
            $parsed['v3_authalgo'], $parsed['v3_cryptoalgo'], $parsed['v3_authlevel'],
            0xA1, [$oid]);
        if ($result === null) { $err .= "Timeout: No Response\n"; continue; }
        foreach ($result as $vb) {
            $outOid = $parsed['numeric'] ? $vb['oid'] : numericToTextual(ltrim($vb['oid'], '.'));
            $out .= formatVarbind($outOid, $vb['value'], $parsed['numeric'], $parsed['hide_mib']) . "\n";
        }
    }
    return [$out, $err];
}

function v3Walk(array $parsed, bool $bulk): array {
    $disco = v3Discovery($parsed['host'], $parsed['port']);
    if ($disco === null) return [null, 'Timeout: No Response'];
    $out = '';
    $err = '';
    foreach ($parsed['oids'] as $o) {
        $n = textualToNumeric($o);
        $startOid = $n !== '' ? $n : ltrim($o, '.');
        if ($bulk) {
            $result = v3SendRequest($parsed['host'], $parsed['port'], $disco,
                $parsed['v3_user'], $parsed['v3_authpass'], $parsed['v3_cryptopass'],
                $parsed['v3_authalgo'], $parsed['v3_cryptoalgo'], $parsed['v3_authlevel'],
                0xA5, [$startOid], 0, $parsed['max_rep'] ?: 10);
        } else {
            // Sequential getnext walk.
            $result = [];
            $cur = $startOid;
            for ($k = 0; $k < 200; $k++) {
                $r = v3SendRequest($parsed['host'], $parsed['port'], $disco,
                    $parsed['v3_user'], $parsed['v3_authpass'], $parsed['v3_cryptopass'],
                    $parsed['v3_authalgo'], $parsed['v3_cryptoalgo'], $parsed['v3_authlevel'],
                    0xA1, [$cur]);
                if ($r === null || empty($r)) break;
                $next = ltrim($r[0]['oid'], '.');
                if ($next === '' || strpos($next, $startOid) !== 0 || $next === $cur) break;
                $result[] = $r[0];
                $cur = $next;
            }
        }
        if ($result === null) { $err .= "Timeout: No Response\n"; continue; }
        foreach ($result as $vb) {
            $outOid = $parsed['numeric'] ? $vb['oid'] : numericToTextual(ltrim($vb['oid'], '.'));
            $out .= formatVarbind($outOid, $vb['value'], $parsed['numeric'], $parsed['hide_mib']) . "\n";
        }
    }
    return [$out, $err];
}

// Determine which binary we are.
$argv0 = basename($argv[0]);
$map = [
    'snmpget'        => 'snmpget',
    'snmpwalk'       => 'snmpwalk',
    'snmpbulkwalk'   => 'snmpbulkwalk',
    'snmpgetnext'    => 'snmpgetnext',
    'snmptranslate'  => 'snmptranslate',
    // When invoking through .cmd shim + arg form: "php netsnmp_wrap.php snmpget ..."
    // the first positional arg is the binary name.
];

// Strip script name.
array_shift($argv);
if (!empty($argv) && isset($map[$argv[0]])) {
    $binary = array_shift($argv);
} else {
    // Try mapping by filename (snmpget.cmd -> snmpget).
    $base = strtolower(preg_replace('/\.(cmd|bat|exe)$/i', '', $argv0));
    $binary = $map[$base] ?? $base;
    if (!in_array($binary, ['snmpget', 'snmpwalk', 'snmpbulkwalk', 'snmpgetnext', 'snmptranslate'], true)) {
        fwrite(STDERR, "netsnmp_wrap: unknown binary mode '$binary'\n");
        exit(2);
    }
}

// Parse CLI args.
$parsed = [
    'version'    => '2c',
    'community'  => 'public',
    'host'       => null,
    'port'       => 161,
    'oids'       => [],
    'options'    => '-OQXUte',
    'mibs'       => null,
    'mibdirs'    => null,
    'timeout'    => null,
    'retries'    => null,
    'max_rep'    => 0,
    'context'    => null,
    'numeric'    => false,
    'hide_mib'   => false,
    'extra'      => [],
    // SNMPv3
    'v3_user'    => '',
    'v3_authlevel' => 'noAuthNoPriv',
    'v3_authalgo'  => 'SHA',
    'v3_authpass'  => '',
    'v3_cryptoalgo'=> 'AES',
    'v3_cryptopass'=> '',
];

$i = 0;
$argc = count($argv);
$nonOption = false; // after we see host:port, all remaining positional args are OIDs.
while ($i < $argc) {
    $a = $argv[$i];
    switch ($a) {
        case '-v':
            $parsed['version'] = $argv[++$i] ?? '2c';
            break;
        case '-v1':
        case '-v2c':
        case '-v3':
            $parsed['version'] = substr($a, 1);
            break;
        case '-c':
            $parsed['community'] = $argv[++$i] ?? 'public';
            break;
        case '-O':
            $parsed['options'] = $argv[++$i] ?? '';
            break;
        // -O<flags> combined form
        case (preg_match('/^-O(.+)$/', $a, $m) ? true : false):
            $parsed['options'] = $m[1];
            break;
        case '-m':
            $parsed['mibs'] = $argv[++$i] ?? '';
            break;
        case (preg_match('/^-m(.+)$/', $a, $m) ? true : false):
            $parsed['mibs'] = $m[1];
            break;
        case '-M':
            $parsed['mibdirs'] = $argv[++$i] ?? '';
            break;
        case (preg_match('/^-M(.+)$/', $a, $m) ? true : false):
            $parsed['mibdirs'] = $m[1];
            break;
        case '-t':
            $parsed['timeout'] = $argv[++$i] ?? null;
            break;
        case '-r':
            $parsed['retries'] = $argv[++$i] ?? null;
            break;
        case (preg_match('/^-Cr(\d+)$/', $a, $m) ? true : false):
            $parsed['max_rep'] = (int)$m[1];
            break;
        case '-Cc':
            // allow unordered; ignore, our walk handles naturally.
            break;
        case '-n':
            $parsed['context'] = $argv[++$i] ?? null;
            break;
        case '-u':
            $parsed['v3_user'] = $argv[++$i] ?? '';
            break;
        case '-a':
            $parsed['v3_authalgo'] = $argv[++$i] ?? 'SHA';
            break;
        case '-A':
            $parsed['v3_authpass'] = $argv[++$i] ?? '';
            break;
        case '-x':
            $parsed['v3_cryptoalgo'] = $argv[++$i] ?? 'AES';
            break;
        case '-X':
            $parsed['v3_cryptopass'] = $argv[++$i] ?? '';
            break;
        case '-l':
            $parsed['v3_authlevel'] = $argv[++$i] ?? 'noAuthNoPriv';
            break;
        case (preg_match('/^-(Cc|Cu|Cr)$/', $a) ? true : false):
        case (preg_match('/^-Cr\d+$/', $a) ? true : false):
            break;
        // -O<flags> may be glued like -OQn
        case (preg_match('/^-O([QXUtesbn]+)$/', $a, $m) ? true : false):
            $parsed['options'] = $m[1];
            break;
        default:
            // Host:port or OID positional.
            if ($parsed['host'] === null && (strpos($a, ':') !== false) && preg_match('#^(udp|tcp|udp6|tcp6)?:(.+):(\d+)$#', $a)) {
                // Format: udp:host:port
                if (preg_match('#^(udp|tcp|udp6|tcp6)?:(.+):(\d+)$#', $a, $m)) {
                    $parsed['host'] = $m[2];
                    $parsed['port'] = (int)$m[3];
                }
            } elseif ($parsed['host'] === null && preg_match('/^(\d+\.){3}\d+$/', $a) && $binary === 'snmptranslate') {
                // Bare IP-like OID for snmptranslate - treat as OID
                $parsed['oids'][] = $a;
            } elseif ($parsed['host'] === null && strpos($a, ':') !== false && preg_match('/^[a-zA-Z0-9.\-]+:\d+$/', $a) && !preg_match('/^[^:]+::[^:]+/', $a)) {
                // host:port form (no protocol prefix)
                $parts = explode(':', $a);
                $parsed['host'] = $parts[0];
                $parsed['port'] = (int)$parts[1];
            } else {
                // OID (textual or numeric). Could be SNMPv2-MIB::sysDescr.0, .1.3.6.1.2.1.1.1.0, etc.
                $parsed['oids'][] = $a;
            }
            break;
    }
    $i++;
}

// If -M given, set MIBDIRS so PHP snmp module finds MIB files.
if (!empty($parsed['mibdirs'])) {
    // net-snmp takes colon-separated dirs; PHP SNMP uses MIBDIRS env var.
    $dirs = str_replace(':', PATH_SEPARATOR, $parsed['mibdirs']);
    putenv('MIBDIRS=' . $dirs);
}

// Parse -O option flags - check for -On (numeric), -Os (hide mib), -Oq (quick), -OQ (extra quick).
$optFlags = $parsed['options'];
$parsed['numeric']  = (strpos($optFlags, 'n') !== false);
$parsed['hide_mib']= (strpos($optFlags, 's') !== false);
$quick             = (stripos($optFlags, 'q') !== false);
// -OQ means: do not print type prefix, just `OID = value`. We always strip type prefix for LibreNMS.

// SNMPv3 context isolation not implemented in simulator, ignore for now.
if (!empty($parsed['context'])) {
    $parsed['community'] = strtok($parsed['community'], '@');
}

/**
 * Format a single varbind to net-snmp -OQ style output line.
 *
 * PHP snmp module returns values like:
 *   "STRING: \"foo\"", "INTEGER: 5", "Timeticks: (123) 0:02:03.00",
 *   "OID: .1.2.3", "IpAddress: 1.2.3.4", "Hex-STRING: 41 42", "Gauge32: 5", "Counter32: 1000"
 *
 * We need to convert to -OQ form (no type prefix, value only).
 */
function formatVarbind(string $oid, string $rawValue, bool $numeric, bool $hideMib): string {
    // Strip type prefix.
    $value = $rawValue;
    if (preg_match('/^(STRING|INTEGER|INTEGER32|Counter32|Counter64|Gauge32|Gauge|Unsigned32|Timeticks|OID|IpAddress|Hex-STRING|BITS):\s*(.*)$/s', $rawValue, $m)) {
        $type = $m[1];
        $value = $m[2];
        switch ($type) {
            case 'STRING':
                // net-snmp -OQ outputs strings quoted: OID = "value"
                // Escape internal backslash and double-quote, then wrap in quotes.
                if ($value !== '' && $value[0] === '"' && substr($value, -1) === '"') {
                    // Already quoted (some PHP snmp builds).
                    $inner = substr($value, 1, -1);
                } else {
                    $inner = $value;
                }
                $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $inner);
                $value = '"' . $escaped . '"';
                break;
            case 'Hex-STRING':
                // Output as space-separated hex bytes - matches -Oe.
                break;
            case 'Timeticks':
                // PHP returns "Timeticks: (12345) 0:02:03.45"
                // -Ot flag -> raw integer. Extract the integer.
                if (preg_match('/\((\d+)\)/', $value, $tt)) {
                    $value = $tt[1];
                }
                break;
            case 'IpAddress':
                // Value is "1.2.3.4" - keep as-is.
                break;
            case 'OID':
                // Value is ".1.2.3.4" or textual form - keep as-is.
                break;
            case 'INTEGER':
            case 'INTEGER32':
            case 'Counter32':
            case 'Counter64':
            case 'Gauge32':
            case 'Gauge':
            case 'Unsigned32':
            case 'BITS':
            default:
                // Plain integer value.
                break;
        }
    }

    // Apply -On (numeric OID) and -Os (hide mib name) on the OID portion.
    $oidOut = $oid;
    if ($numeric) {
        // Convert textual OID to numeric form using our internal map.
        $numericOid = textualToNumeric($oid);
        if ($numericOid !== false && $numericOid !== '') {
            $oidOut = $numericOid;
            // textualToNumeric returns like 1.3.6.1.2.1.1.1.0 - prepend with . for consistency with net-snmp.
            if ($oidOut[0] !== '.') {
                $oidOut = '.' . $oidOut;
            }
        }
    } elseif ($hideMib) {
        // -Os: hide mib name -> just last component. e.g. "sysDescr.0" instead of "SNMPv2-MIB::sysDescr.0".
        if (strpos($oidOut, '::') !== false) {
            $oidOut = substr($oidOut, strpos($oidOut, '::') + 2);
        }
    }

    return $oidOut . ' = ' . trim($value);
}

/**
 * Build the SNMP target string for PHP's snmp module.
 */
function buildTarget(array $parsed): string {
    $ip = $parsed['host'];
    $port = $parsed['port'];
    if (strpos($ip, ':') !== false && strpos($ip, '[') === false) {
        $ip = '[' . $ip . ']'; // IPv6 literal.
    }
    return $ip . ':' . $port;
}

$exitCode = 0;
$output = '';
$stderr = '';

switch ($binary) {
    case 'snmptranslate':
        // Minimal snmptranslate: maintain static OID map for common MIBs.
        // For unknown OIDs, fall back to net-snmp-like behavior (echo numeric for textual input).
        $oidMap = [
            // SNMPv2-MIB
            'SNMPv2-MIB::sysDescr' => '1.3.6.1.2.1.1.1',
            'SNMPv2-MIB::sysObjectID' => '1.3.6.1.2.1.1.2',
            'SNMPv2-MIB::sysUpTime' => '1.3.6.1.2.1.1.3',
            'SNMPv2-MIB::sysContact' => '1.3.6.1.2.1.1.4',
            'SNMPv2-MIB::sysName' => '1.3.6.1.2.1.1.5',
            'SNMPv2-MIB::sysLocation' => '1.3.6.1.2.1.1.6',
            'SNMPv2-MIB::sysServices' => '1.3.6.1.2.1.1.7',
            'SNMPv2-MIB::sysORTable' => '1.3.6.1.2.1.1.9',
            'SNMPv2-MIB::sysORID' => '1.3.6.1.2.1.1.9.1.1',
            // IF-MIB
            'IF-MIB::ifTable' => '1.3.6.1.2.1.2.2',
            'IF-MIB::ifEntry' => '1.3.6.1.2.1.2.2.1',
            'IF-MIB::ifIndex' => '1.3.6.1.2.1.2.2.1.1',
            'IF-MIB::ifDescr' => '1.3.6.1.2.1.2.2.1.2',
            'IF-MIB::ifType' => '1.3.6.1.2.1.2.2.1.3',
            'IF-MIB::ifMtu' => '1.3.6.1.2.1.2.2.1.4',
            'IF-MIB::ifSpeed' => '1.3.6.1.2.1.2.2.1.5',
            'IF-MIB::ifPhysAddress' => '1.3.6.1.2.1.2.2.1.6',
            'IF-MIB::ifAdminStatus' => '1.3.6.1.2.1.2.2.1.7',
            'IF-MIB::ifOperStatus' => '1.3.6.1.2.1.2.2.1.8',
            'IF-MIB::ifInOctets' => '1.3.6.1.2.1.2.2.1.10',
            'IF-MIB::ifOutOctets' => '1.3.6.1.2.1.2.2.1.14',
            'IF-MIB::ifXTable' => '1.3.6.1.2.1.31.1.1',
            'IF-MIB::ifName' => '1.3.6.1.2.1.31.1.1.1.1',
            'IF-MIB::ifAlias' => '1.3.6.1.2.1.31.1.1.1.18',
            'IF-MIB::ifStackTable' => '1.3.6.1.2.1.31.1.2',
            'IF-MIB::ifHCInOctets' => '1.3.6.1.2.1.31.1.1.1.6',
            'IF-MIB::ifHCOutOctets' => '1.3.6.1.2.1.31.1.1.1.10',
            // IP-MIB
            'IP-MIB::ipAdEntAddr' => '1.3.6.1.2.1.4.20.1.1',
            'IP-MIB::ipAdEntIfIndex' => '1.3.6.1.2.1.4.20.1.2',
            'IP-MIB::ipAdEntNetMask' => '1.3.6.1.2.1.4.20.1.3',
            'IP-MIB::ipNetToMediaTable' => '1.3.6.1.2.1.4.22',
            // LLDP-MIB
            'LLDP-MIB::lldpRemTable' => '1.0.8802.1.1.2.1.4.1',
            'LLDP-MIB::lldpRemSysName' => '1.0.8802.1.1.2.1.4.1.1.9',
            'LLDP-MIB::lldpRemPortDesc' => '1.0.8802.1.1.2.1.4.1.1.8',
            'LLDP-MIB::lldpRemChassisId' => '1.0.8802.1.1.2.1.4.1.1.5',
            'LLDP-MIB::lldpRemPortId' => '1.0.8802.1.1.2.1.4.1.1.7',
            // ENTITY-MIB
            'ENTITY-MIB::entPhysicalTable' => '1.3.6.1.2.1.47.1.1.1',
            'ENTITY-MIB::entPhysicalEntry' => '1.3.6.1.2.1.47.1.1.1.1',
            'ENTITY-MIB::entPhysicalDescr' => '1.3.6.1.2.1.47.1.1.1.1.2',
            'ENTITY-MIB::entPhysicalName' => '1.3.6.1.2.1.47.1.1.1.1.7',
            'ENTITY-MIB::entPhysicalModelName' => '1.3.6.1.2.1.47.1.1.1.1.13',
            // HOST-RESOURCES-MIB
            'HOST-RESOURCES-MIB::hrStorageTable' => '1.3.6.1.2.1.25.2.3.1',
            'HOST-RESOURCES-MIB::hrStorageDescr' => '1.3.6.1.2.1.25.2.3.1.3',
            'HOST-RESOURCES-MIB::hrStorageUsed' => '1.3.6.1.2.1.25.2.3.1.5',
            // SNMP-FRAMEWORK-MIB
            'SNMP-FRAMEWORK-MIB::snmpEngineTime' => '1.3.6.1.6.3.10.2.1.3',
            'SNMP-FRAMEWORK-MIB::snmpEngineBoots' => '1.3.6.1.6.3.10.2.1.2',
            // Vendor-specific sysObjectIDs (for hardware detection)
            '.1.3.6.1.4.1.11.2.3.7.11.45' => 'hpSwitchProCurveJ8692A',
            '.1.3.6.1.4.1.9.1.1745' => 'catalyst385024',
            '.1.3.6.1.4.1.2011.2.23.1.2.27' => 's5700',
        ];
        $reverseMap = array_flip($oidMap);

        foreach ($parsed['oids'] as $oid) {
            $input = $oid;
            $result = $input;
            // Strip trailing .0 / .X for textual lookup
            $baseOid = preg_replace('/\.0$/', '', $input);
            if ($parsed['numeric']) {
                // -On: textual -> numeric
                if (isset($oidMap[$input])) {
                    $result = '.' . $oidMap[$input];
                } elseif (isset($oidMap[$baseOid])) {
                    // Input is like "SNMPv2-MIB::sysDescr.0", translate base + keep index
                    $idx = substr($input, strlen($baseOid));
                    $result = '.' . $oidMap[$baseOid] . $idx;
                } elseif (preg_match('/^\.?\d+(\.\d+)+$/', ltrim($input, '.'))) {
                    // Already numeric - echo as-is.
                    $result = (substr($input, 0, 1) === '.') ? $input : '.' . $input;
                } else {
                    $result = $input; // fallback
                }
            } elseif ($parsed['hide_mib']) {
                // -Os: hide mib name, return short form
                if (strpos($input, '::') !== false) {
                    $result = substr($input, strpos($input, '::') + 2);
                } elseif (isset($reverseMap[$input])) {
                    $mibName = $reverseMap[$input];
                    $result = (strpos($mibName, '::') !== false) ? substr($mibName, strpos($mibName, '::') + 2) : $mibName;
                } else {
                    $result = $input;
                }
            } else {
                // Default: try textual lookup from numeric
                if (isset($reverseMap[$input])) {
                    $result = $reverseMap[$input];
                } elseif (preg_match('/^\.?\d+(\.\d+)+$/', ltrim($input, '.'))) {
                    // Numeric input - check reverse map
                    $key = (substr($input, 0, 1) === '.') ? $input : '.' . $input;
                    $result = $reverseMap[$key] ?? $input;
                } else {
                    $result = $input;
                }
            }
            $output .= $result . "\n";
        }
        break;

    case 'snmpget':
        if ($parsed['host'] === null || empty($parsed['oids'])) {
            fwrite(STDERR, "snmpget: missing host or OID\n");
            exit(2);
        }
        if ($parsed['version'] === '3') {
            list($out, $err) = v3Get($parsed);
            if ($out !== null) $output .= $out;
            if ($err !== '') { $stderr .= $err; $exitCode = 2; }
            break;
        }
        $target = buildTarget($parsed);
        $timeout = $parsed['timeout'] !== null ? (int)($parsed['timeout'] * 1000000) : 1000000;
        $retries = $parsed['retries'] !== null ? (int)$parsed['retries'] : 3;
        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        foreach ($parsed['oids'] as $oid) {
            $result = @snmp2_get($target, $parsed['community'], $oid, $timeout, $retries);
            if ($result === false) {
                $output .= $oid . ' = No Such Instance currently exists at this OID.' . "\n";
                $exitCode = 0;
                continue;
            }
            $output .= formatVarbind($oid, (string)$result, $parsed['numeric'], $parsed['hide_mib']) . "\n";
        }
        break;

    case 'snmpgetnext':
        if ($parsed['host'] === null || empty($parsed['oids'])) {
            fwrite(STDERR, "snmpgetnext: missing host or OID\n");
            exit(2);
        }
        if ($parsed['version'] === '3') {
            list($out, $err) = v3GetNext($parsed);
            if ($out !== null) $output .= $out;
            if ($err !== '') { $stderr .= $err; $exitCode = 2; }
            break;
        }
        $timeout = $parsed['timeout'] !== null ? (int)($parsed['timeout'] * 1000000) : 1000000;
        $retries = $parsed['retries'] !== null ? (int)$parsed['retries'] : 3;
        foreach ($parsed['oids'] as $oid) {
            $numericOid = textualToNumeric($oid);
            if ($numericOid === '') {
                $stderr .= "Could not translate OID: $oid\n";
                $exitCode = 2;
                continue;
            }
            $result = rawSnmpGetnext($parsed['host'], $parsed['port'], $parsed['community'], $numericOid, $timeout, $retries);
            if ($result === null) {
                $stderr .= "Timeout: No Response from {$parsed['host']}:{$parsed['port']}\n";
                $exitCode = 2;
                continue;
            }
            $outputOid = $parsed['numeric'] ? '.' . $result['oid'] : numericToTextual($result['oid']);
            $output .= formatVarbind($outputOid, $result['value'], $parsed['numeric'], $parsed['hide_mib']) . "\n";
        }
        break;

    case 'snmpwalk':
    case 'snmpbulkwalk':
        if ($parsed['host'] === null || empty($parsed['oids'])) {
            fwrite(STDERR, "$binary: missing host or OID\n");
            exit(2);
        }
        if ($parsed['version'] === '3') {
            list($out, $err) = v3Walk($parsed, $binary === 'snmpbulkwalk');
            if ($out !== null) $output .= $out;
            if ($err !== '') { $stderr .= $err; $exitCode = 2; }
            break;
        }
        $target = buildTarget($parsed);
        $timeout = $parsed['timeout'] !== null ? (int)($parsed['timeout'] * 1000000) : 2000000;
        $retries = $parsed['retries'] !== null ? (int)$parsed['retries'] : 3;
        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        foreach ($parsed['oids'] as $oid) {
            $walk = @snmp2_real_walk($target, $parsed['community'], $oid, $timeout, $retries);
            if ($walk === false) {
                $stderr .= "Timeout: No Response from $target\n";
                $exitCode = 2;
                continue;
            }
            if (empty($walk)) {
                $exitCode = 0;
                continue;
            }
            foreach ($walk as $k => $v) {
                $output .= formatVarbind($k, (string)$v, $parsed['numeric'], $parsed['hide_mib']) . "\n";
            }
        }
        break;

    default:
        fwrite(STDERR, "Unknown binary: $binary\n");
        exit(2);
}

fwrite(STDOUT, $output);
if ($stderr !== '') {
    fwrite(STDERR, $stderr);
}
exit($exitCode);
