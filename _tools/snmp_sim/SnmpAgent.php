<?php
/**
 * Minimal pure-PHP SNMP agent (v1 / v2c / v3).
 *
 * v1/v2c: community-based, existing behaviour.
 * v3:    User-based Security Model (USM) supporting authPriv with
 *        HMAC-SHA-96 authentication and AES-128-CFB privacy.
 *        Engine ID, boots and time are returned in discovery responses.
 *
 * PDU types handled:
 *   - GetRequest-PDU    (0xA0)
 *   - GetNextRequest-PDU(0xA1)
 *   - GetBulkRequest-PDU(0xA5)   (v2c/v3)
 *   - SetRequest-PDU    (0xA3)
 *   - Report-PDU        (0xA8)   (v3 discovery / error)
 */
require_once __DIR__ . '/Ber.php';
require_once __DIR__ . '/MibBuilder.php';
require_once __DIR__ . '/DeviceMib.php';

class SnmpAgent
{
    const VERSION_1  = 0;
    const VERSION_2C = 1;
    const VERSION_3  = 3;

    /** @var array device_key => binding */
    private $bindings = array();
    private $community = 'public';
    private $quiet = false;

    /** @var array $v3Users user => [authPass, privPass, authAlgo, privAlgo] */
    private $v3Users = array();

    public function setCommunity($c) { $this->community = $c; }
    public function setQuiet($q) { $this->quiet = (bool)$q; }

    /**
     * Configure SNMPv3 users.  authAlgo currently only 'SHA',
     * privAlgo currently only 'AES' (AES-128-CFB).
     */
    public function setV3Users(array $users)
    {
        $this->v3Users = $users;
    }

    /**
     * Derive the localised authentication key for a user + engineID.
     * Implements the password-to-key algorithm from RFC 3414 Appendix A.2.
     */
    public static function passwordToKey($password, $engineID, $algo = 'SHA')
    {
        $algo = strtoupper($algo);
        $hashFunc = ($algo === 'SHA256') ? 'sha256' : 'sha1';
        $hashLen  = ($algo === 'SHA256') ? 32 : 20;

        // Step 1: fill 1 MB buffer with repeating password, hash it.
        $buf = str_repeat($password, (int)ceil(1048576 / strlen($password)));
        $buf = substr($buf, 0, 1048576);
        $digest = hash($hashFunc, $buf, true);

        // Step 2: localise with engineID.
        $localised = hash_hmac($hashFunc, $digest . $engineID . $digest, $digest, true);

        return $localised;
    }

    /**
     * Compute HMAC-SHA-96 auth digest over a message where the
     * msgAuthenticationParameters field has been replaced by zeros.
     */
    public static function computeAuthDigest($wholeMsg, $authKey)
    {
        $mac = hash_hmac('sha1', $wholeMsg, $authKey, true);
        return substr($mac, 0, 12);
    }

    public function addBinding($key, $deviceDef)
    {
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            throw new \RuntimeException("socket_create failed: " . socket_strerror(socket_last_error()));
        }
        $bindIp = isset($deviceDef['ip']) ? $deviceDef['ip'] : '127.0.0.1';
        if (!socket_bind($sock, $bindIp, $deviceDef['port'])) {
            throw new \RuntimeException("socket_bind $bindIp:{$deviceDef['port']} failed: " . socket_strerror(socket_last_error($sock)));
        }
        socket_set_nonblock($sock);
        $mib = DeviceMib::build($deviceDef);

        // Build a per-device engineID: 0x80 0x00 0x1f 0x88 (enterprise) + 0x04 (string) + device ip bytes
        $engineID = "\x80\x00\x1f\x88\x04" . implode('', array_map('chr', array_map('intval', explode('.', $bindIp))));

        $this->bindings[$key] = array(
            'socket'   => $sock,
            'mib'      => $mib,
            'device'   => $deviceDef,
            'port'     => $deviceDef['port'],
            'ip'       => $bindIp,
            'engineID' => $engineID,
            'boots'    => 1,
        );
        $this->log("Listening on $bindIp:{$deviceDef['port']} ({$deviceDef['vendor']} - {$deviceDef['sysName']})");
    }

    public function run()
    {
        $this->log("SNMP simulator started (v1/v2c/v3). Press Ctrl-C to stop.");
        $lastReport = time();
        while (true) {
            $read = array(); $keys = array();
            foreach ($this->bindings as $key => $b) {
                $read[] = $b['socket'];
                $keys[] = $key;
            }
            $write = null; $except = null;
            $n = @socket_select($read, $write, $except, 1);
            if ($n === false) {
                if (socket_last_error() === SOCKET_EINTR) continue;
                usleep(10000);
                continue;
            }
            foreach ($read as $sock) {
                $key = null;
                foreach ($this->bindings as $k => $b) {
                    if ($b['socket'] === $sock) { $key = $k; break; }
                }
                if ($key === null) continue;
                $fromIp = ''; $fromPort = 0;
                $buf = @socket_recvfrom($sock, $data, 65535, 0, $fromIp, $fromPort);
                if ($buf === false || $buf === 0) continue;
                try {
                    $response = $this->handleRequest($data, $this->bindings[$key]);
                    if ($response !== null) {
                        socket_sendto($sock, $response, strlen($response), 0, $fromIp, $fromPort);
                    }
                } catch (\Throwable $e) {
                    $this->log("Error handling request from $fromIp:$fromPort: " . $e->getMessage());
                }
            }
            if (time() - $lastReport >= 60) {
                $lastReport = time();
                $this->log("still alive at " . date('c'));
            }
        }
    }

    /**
     * Decode the incoming SNMP request and produce a response.
     */
    public function handleRequest($data, $binding)
    {
        $pos = 0;
        $outer = Ber::parseTlv($data, $pos);
        if ($outer === null || $outer['tag'] !== 0x30) return null;
        $items = Ber::parseSequence($outer['value']);
        if (count($items) < 3) return null;

        $version = Ber::parseInteger($items[0]['value']);

        if ($version === self::VERSION_1 || $version === self::VERSION_2C) {
            return $this->handleV1V2c($items, $version, $binding['mib']);
        }
        if ($version === self::VERSION_3) {
            return $this->handleV3($items, $data, $binding);
        }
        return null;
    }

    private function handleV1V2c($items, $version, $mib)
    {
        $community = $items[1]['value'];
        if ($community !== $this->community) return null;

        $pdu = $items[2];
        $pduType = $pdu['tag'];
        $pduItems = Ber::parseSequence($pdu['value']);
        if (count($pduItems) < 4) return null;
        $reqId = Ber::parseInteger($pduItems[0]['value']);

        $responseVarbinds = $this->processPdu($pduType, $pduItems, $mib, $version);
        list($errStatus, $errIdx) = $this->extractError($pduItems);

        $responsePdu = Ber::tlv(0xA2,
            Ber::encodeInteger($reqId) .
            Ber::encodeInteger($errStatus) .
            Ber::encodeInteger($errIdx) .
            self::encodeVarbinds($responseVarbinds)
        );
        return Ber::encodeSequence(
            Ber::encodeInteger($version) .
            Ber::encodeOctetString($community) .
            $responsePdu
        );
    }

    private function handleV3($items, $wholeMsg, $binding)
    {
        $mib = $binding['mib'];
        // msgGlobalData
        $global = Ber::parseSequence($items[1]['value']);
        if (count($global) < 4) return null;
        $msgID = Ber::parseInteger($global[0]['value']);
        $msgMaxSize = Ber::parseInteger($global[1]['value']);
        $msgFlags = $global[2]['value'];
        $msgSecurityModel = Ber::parseInteger($global[3]['value']);

        $flags = ord($msgFlags[0]);
        $reportable = ($flags & 0x01) != 0;
        $privFlag   = ($flags & 0x02) != 0;
        $authFlag   = ($flags & 0x04) != 0;

        // msgSecurityParameters (OCTET STRING wrapping a SEQUENCE)
        $secParamsRaw = $items[2]['value'];
        $secItems = Ber::parseSequence($secParamsRaw);
        // Expected: engineID, engineBoots, engineTime, userName, authParams, privParams
        $engineID     = $secItems[0]['value'];
        $engineBoots  = Ber::parseInteger($secItems[1]['value']);
        $engineTime   = Ber::parseInteger($secItems[2]['value']);
        $userName     = $secItems[3]['value'];
        $authParams   = isset($secItems[4]) ? $secItems[4]['value'] : '';
        $privParams   = isset($secItems[5]) ? $secItems[5]['value'] : '';

        // msgData: either plaintext PDU or encrypted ScopedPDU (OCTET STRING)
        $msgData = $items[3];

        // ---- Discovery: empty userName -> return Report PDU with engine info ----
        if ($userName === '') {
            return $this->buildV3DiscoveryReport($msgID, $binding);
        }

        // Look up user
        if (!isset($this->v3Users[$userName])) {
            return $this->buildV3Report($msgID, $binding, 1.3 . '.6.1.6.3.15.1.1.3.0', 0, $userName); // usmStatsUnknownUserNames
        }
        $user = $this->v3Users[$userName];
        $authPass = $user['authPass'];
        $privPass = $user['privPass'];
        $authAlgo = isset($user['authAlgo']) ? $user['authAlgo'] : 'SHA';
        $privAlgo = isset($user['privAlgo']) ? $user['privAlgo'] : 'AES';

        $engineIDLocal = $binding['engineID'];

        // ---- Authenticate (if requested) ----
        if ($authFlag) {
            $authKey = self::passwordToKey($authPass, $engineIDLocal, $authAlgo);
            // Reconstruct wholeMsg with zeroed authParams for verification.
            $zeroedSec = $this->encodeSecurityParameters($engineIDLocal, $engineBoots, $engineTime, $userName, str_repeat("\x00", 12), $privParams);
            $globalBytes = Ber::encodeInteger($msgID) .
                           Ber::encodeInteger($msgMaxSize) .
                           Ber::encodeOctetString($msgFlags) .
                           Ber::encodeInteger($msgSecurityModel);
            $wholeMsgZeroed = Ber::encodeSequence(
                Ber::encodeInteger(3) .
                Ber::encodeSequence($globalBytes) .
                Ber::encodeOctetString($zeroedSec) .
                Ber::tlv($msgData['tag'], $msgData['value'])
            );
            $expectedDigest = self::computeAuthDigest($wholeMsgZeroed, $authKey);
            if ($authParams !== $expectedDigest) {
                return $this->buildV3Report($msgID, $binding, '1.3.6.1.6.3.15.1.1.5.0', 0, $userName); // usmStatsWrongDigests
            }
        }

        // ---- Decrypt ScopedPDU (if requested) ----
        $pduTag = $msgData['tag'];
        $pduValue = $msgData['value'];
        if ($privFlag) {
            // msgData is OCTET STRING (0x04) containing encrypted ScopedPDU
            $encrypted = $msgData['value'];
            $privKey = substr(self::passwordToKey($privPass, $engineIDLocal, $authAlgo), 0, 16); // AES-128 needs 16 bytes
            // IV = salt (8 bytes privParams) + engineBoots (4) + engineTime (4)
            $salt = $privParams;
            if (strlen($salt) < 8) $salt = str_pad($salt, 8, "\x00", STR_PAD_LEFT);
            $iv = substr($salt, 0, 8) .
                  pack('N', $engineBoots) .
                  pack('N', $engineTime);
            $scopedPDU = openssl_decrypt($encrypted, 'aes-128-cfb', $privKey, OPENSSL_RAW_DATA, $iv);
            if ($scopedPDU === false) {
                return $this->buildV3Report($msgID, $binding, '1.3.6.1.6.3.15.1.1.6.0', 0, $userName); // usmStatsDecryptionErrors
            }
            // Parse ScopedPDU: SEQUENCE { contextEngineID, contextName, data }
            $spPos = 0;
            $spTlv = Ber::parseTlv($scopedPDU, $spPos);
            if ($spTlv['tag'] !== 0x30) return null;
            $spItems = Ber::parseSequence($spTlv['value']);
            $contextEngineID = $spItems[0]['value'];
            $contextName = $spItems[1]['value'];
            $pduTlv = $spItems[2];
            $pduTag = $pduTlv['tag'];
            $pduValue = $pduTlv['value'];
        }

        // ---- Process PDU ----
        $pduItems = Ber::parseSequence($pduValue);
        if (count($pduItems) < 4) return null;
        $reqId = Ber::parseInteger($pduItems[0]['value']);
        $responseVarbinds = $this->processPdu($pduTag, $pduItems, $mib, self::VERSION_2C);
        list($errStatus, $errIdx) = $this->extractError($pduItems);

        $responsePdu = Ber::tlv(0xA2,
            Ber::encodeInteger($reqId) .
            Ber::encodeInteger($errStatus) .
            Ber::encodeInteger($errIdx) .
            self::encodeVarbinds($responseVarbinds)
        );

        // ---- Build ScopedPDU and encrypt ----
        $respEngineBoots = $binding['boots'];
        $respEngineTime  = time();
        $scopedPDU = Ber::encodeSequence(
            Ber::encodeOctetString($engineIDLocal) .
            Ber::encodeOctetString('') . // contextName
            $responsePdu
        );

        $outPrivParams = '';
        $outMsgDataTag = 0xA2;
        $outMsgData = $responsePdu;

        if ($privFlag) {
            $privKey = substr(self::passwordToKey($privPass, $engineIDLocal, $authAlgo), 0, 16);
            $salt = openssl_random_pseudo_bytes(8);
            $iv = $salt . pack('N', $respEngineBoots) . pack('N', $respEngineTime);
            $encrypted = openssl_encrypt($scopedPDU, 'aes-128-cfb', $privKey, OPENSSL_RAW_DATA, $iv);
            $outPrivParams = $salt;
            $outMsgDataTag = 0x04; // OCTET STRING wraps encrypted ScopedPDU
            $outMsgData = $encrypted;
        }

        // ---- Build msgSecurityParameters ----
        $authKey = $authFlag ? self::passwordToKey($authPass, $engineIDLocal, $authAlgo) : null;
        $zeroAuth = str_repeat("\x00", 12);
        $secParams = $this->encodeSecurityParameters(
            $engineIDLocal, $respEngineBoots, $respEngineTime,
            $userName, $zeroAuth, $outPrivParams
        );

        // ---- Assemble message with zero auth then compute digest ----
        $respFlags = chr(($authFlag ? 0x04 : 0) | ($privFlag ? 0x02 : 0));
        $globalData = Ber::encodeSequence(
            Ber::encodeInteger($msgID) .
            Ber::encodeInteger($msgMaxSize) .
            Ber::encodeOctetString($respFlags) .
            Ber::encodeInteger(3) // USM
        );

        $messageZeroAuth = Ber::encodeSequence(
            Ber::encodeInteger(3) .
            $globalData .
            Ber::encodeOctetString($secParams) .
            Ber::tlv($outMsgDataTag, $outMsgData)
        );

        $finalAuthParams = $authFlag ? self::computeAuthDigest($messageZeroAuth, $authKey) : '';
        $secParamsFinal = $this->encodeSecurityParameters(
            $engineIDLocal, $respEngineBoots, $respEngineTime,
            $userName, $finalAuthParams, $outPrivParams
        );

        return Ber::encodeSequence(
            Ber::encodeInteger(3) .
            $globalData .
            Ber::encodeOctetString($secParamsFinal) .
            Ber::tlv($outMsgDataTag, $outMsgData)
        );
    }

    /**
     * Build a v3 discovery Report PDU (empty userName -> return engine info).
     */
    private function buildV3DiscoveryReport($msgID, $binding)
    {
        $engineID = $binding['engineID'];
        $boots = $binding['boots'];
        $time = time();
        // Report PDU: 0xA8 { reqId, 0, 0, varbinds{ usmStatsEngineID/boots/time } }
        $varbinds = self::encodeVarbinds(array(
            array('oid' => '.1.3.6.1.6.3.15.1.1.1.0', 'type' => 'OCTET STRING', 'value' => $engineID),
            array('oid' => '.1.3.6.1.6.3.15.1.1.2.0', 'type' => 'INTEGER', 'value' => $boots),
            array('oid' => '.1.3.6.1.6.3.15.1.1.3.0', 'type' => 'INTEGER', 'value' => $time),
        ));
        $reportPdu = Ber::tlv(0xA8,
            Ber::encodeInteger($msgID) .
            Ber::encodeInteger(0) .
            Ber::encodeInteger(0) .
            $varbinds
        );
        // ScopedPDU
        $scopedPDU = Ber::encodeSequence(
            Ber::encodeOctetString($engineID) .
            Ber::encodeOctetString('') .
            $reportPdu
        );
        $globalData = Ber::encodeSequence(
            Ber::encodeInteger($msgID) .
            Ber::encodeInteger(65507) .
            Ber::encodeOctetString("\x00") . // no auth, no priv
            Ber::encodeInteger(3)
        );
        $secParams = $this->encodeSecurityParameters($engineID, $boots, $time, '', '', '');
        return Ber::encodeSequence(
            Ber::encodeInteger(3) .
            $globalData .
            Ber::encodeOctetString($secParams) .
            $scopedPDU
        );
    }

    /**
     * Build a v3 Report PDU for USM errors.
     */
    private function buildV3Report($msgID, $binding, $oid, $value, $userName)
    {
        $engineID = $binding['engineID'];
        $boots = $binding['boots'];
        $time = time();
        $varbinds = self::encodeVarbinds(array(
            array('oid' => $oid, 'type' => 'Counter32', 'value' => $value),
        ));
        $reportPdu = Ber::tlv(0xA8,
            Ber::encodeInteger($msgID) .
            Ber::encodeInteger(0) .
            Ber::encodeInteger(0) .
            $varbinds
        );
        $scopedPDU = Ber::encodeSequence(
            Ber::encodeOctetString($engineID) .
            Ber::encodeOctetString('') .
            $reportPdu
        );
        $globalData = Ber::encodeSequence(
            Ber::encodeInteger($msgID) .
            Ber::encodeInteger(65507) .
            Ber::encodeOctetString("\x04") . // reportable + auth
            Ber::encodeInteger(3)
        );
        // Use zero auth for error reports
        $secParams = $this->encodeSecurityParameters($engineID, $boots, $time, $userName, str_repeat("\x00", 12), '');
        return Ber::encodeSequence(
            Ber::encodeInteger(3) .
            $globalData .
            Ber::encodeOctetString($secParams) .
            $scopedPDU
        );
    }

    private function encodeSecurityParameters($engineID, $boots, $time, $userName, $authParams, $privParams)
    {
        return Ber::encodeSequence(
            Ber::encodeOctetString($engineID) .
            Ber::encodeInteger($boots) .
            Ber::encodeInteger($time) .
            Ber::encodeOctetString($userName) .
            Ber::encodeOctetString($authParams) .
            Ber::encodeOctetString($privParams)
        );
    }

    private function extractError($pduItems)
    {
        $errStatus = 0;
        $errIdx = 0;
        // error-status and error-index are items 1 and 2 for Get/GetNext/Set
        // For GetBulk they are non-repeaters and max-repetitions
        return array($errStatus, $errIdx);
    }

    private function processPdu($pduType, $pduItems, $mib, $version)
    {
        $responseVarbinds = array();
        switch ($pduType) {
            case 0xA0: // GetRequest
                foreach (Ber::parseSequence($pduItems[3]['value']) as $vb) {
                    $vbItems = Ber::parseSequence($vb['value']);
                    $oid = Ber::parseOid($vbItems[0]['value']);
                    $leaf = $mib->get($oid);
                    if ($leaf === null) {
                        if ($version === self::VERSION_2C) {
                            $responseVarbinds[] = array('oid' => $oid, 'type' => 'OBJECT IDENTIFIER', 'value' => '0.0');
                        } else {
                            $responseVarbinds[] = array('oid' => $oid, 'type' => 'NULL', 'value' => '');
                        }
                    } else {
                        $responseVarbinds[] = $mib->resolve($leaf);
                    }
                }
                break;
            case 0xA1: // GetNextRequest
                foreach (Ber::parseSequence($pduItems[3]['value']) as $vb) {
                    $vbItems = Ber::parseSequence($vb['value']);
                    $oid = Ber::parseOid($vbItems[0]['value']);
                    $leaf = $mib->getNext($oid);
                    if ($leaf === null) {
                        $responseVarbinds[] = array('oid' => '.0.0', 'type' => 'OBJECT IDENTIFIER', 'value' => '0.0');
                    } else {
                        $responseVarbinds[] = $mib->resolve($leaf);
                    }
                }
                break;
            case 0xA5: // GetBulkRequest
                $nonRep = Ber::parseInteger($pduItems[1]['value']);
                $maxRep = Ber::parseInteger($pduItems[2]['value']);
                $varbinds = Ber::parseSequence($pduItems[3]['value']);
                $startOid = Ber::parseOid(Ber::parseSequence($varbinds[0]['value'])[0]['value']);
                $leaves = $mib->getBulk($startOid, $maxRep, $nonRep);
                foreach ($leaves as $leaf) {
                    $responseVarbinds[] = $mib->resolve($leaf);
                }
                break;
            case 0xA3: // SetRequest
                foreach (Ber::parseSequence($pduItems[3]['value']) as $vb) {
                    $vbItems = Ber::parseSequence($vb['value']);
                    $oid = Ber::parseOid($vbItems[0]['value']);
                    $leaf = $mib->get($oid);
                    if ($leaf === null) {
                        $responseVarbinds[] = array('oid' => $oid, 'type' => 'NULL', 'value' => '');
                    } else {
                        $responseVarbinds[] = $mib->resolve($leaf);
                    }
                }
                break;
            default:
                return array();
        }
        return $responseVarbinds;
    }

    private static function encodeVarbinds($varbinds)
    {
        $body = '';
        foreach ($varbinds as $vb) {
            $oid = isset($vb['oid']) ? $vb['oid'] : '.0.0';
            $type = isset($vb['type']) ? $vb['type'] : 'NULL';
            $value = isset($vb['value']) ? $vb['value'] : '';
            $vbBody = Ber::encodeOid($oid) . Ber::encodeValue($type, $value);
            $body .= Ber::tlv(0x30, $vbBody);
        }
        return Ber::tlv(0x30, $body);
    }

    private function log($msg)
    {
        if ($this->quiet) return;
        $ts = date('Y-m-d H:i:s');
        echo "[$ts] $msg\n";
    }
}

if (!defined('SOCKET_EINTR')) {
    define('SOCKET_EINTR', 4);
}
