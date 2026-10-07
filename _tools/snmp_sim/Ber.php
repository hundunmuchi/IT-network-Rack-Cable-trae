<?php
/**
 * BER (Basic Encoding Rules) for ASN.1 / SNMP.
 * Minimal pure-PHP encode/decode covering the small subset that SNMP v1/v2c
 * messages require:
 *   INTEGER, OCTET STRING, NULL, OBJECT IDENTIFIER, SEQUENCE,
 *   IpAddress, Counter32, Gauge32, TimeTicks, Opaque, Counter64,
 *   and SNMP-specific GetRequest-PDU / GetNextRequest-PDU / GetBulkRequest-PDU
 *   / Response-PDU / SetRequest-PDU tags.
 *
 * Tags (universal context):
 *   INTEGER         0x02
 *   OCTET STRING    0x04
 *   NULL            0x05
 *   OBJECT IDENT    0x06
 *   SEQUENCE        0x30
 *   SET             0x31
 *
 * SNMP application tags (after the application class bit 0x40):
 *   IpAddress       0x40
 *   Counter32       0x41
 *   Gauge32         0x42
 *   TimeTicks       0x43
 *   Opaque          0x44
 *   NsapAddress     0x45
 *   Counter64       0x46
 *   UInteger32      0x47
 *
 * SNMP context tags (PDU types) - note: low tag numbers in the context class (0xA0+):
 *   GetRequestPDU     0xA0
 *   GetNextRequestPDU 0xA1
 *   ResponsePDU       0xA2
 *   SetRequestPDU     0xA3
 *   GetBulkRequestPDU 0xA5
 *
 * Note: for SNMP v1/v2c the entire message is:
 *   SEQUENCE { version (INTEGER), community (OCTET STRING), data (PDU) }
 */
class Ber
{
    /* ---- low-level length encode/decode ---- */

    public static function encodeLength($len)
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xff) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function decodeLength($data, &$pos)
    {
        $b = ord($data[$pos++]);
        if ($b < 0x80) {
            return $b;
        }
        $n = $b & 0x7f;
        $len = 0;
        for ($i = 0; $i < $n; $i++) {
            $len = ($len << 8) | ord($data[$pos++]);
        }
        return $len;
    }

    /* ---- primitive TLV builder ---- */

    public static function tlv($tag, $value)
    {
        return chr($tag) . self::encodeLength(strlen($value)) . $value;
    }

    /* ---- type-specific encoders ---- */

    public static function encodeInteger($v)
    {
        if ($v < 0) {
            // two's complement negative
            $bytes = '';
            $v = $v & 0xffffffff; // limit to 32-bit
            for ($i = 0; $i < 4; $i++) {
                $bytes = chr($v & 0xff) . $bytes;
                $v >>= 8;
            }
            // trim leading 0xff bytes if value is small enough
            while (strlen($bytes) > 1 && $bytes[0] === "\xff" && (ord($bytes[1]) & 0x80)) {
                $bytes = substr($bytes, 1);
            }
            return self::tlv(0x02, $bytes);
        }
        if ($v === 0) {
            return self::tlv(0x02, "\x00");
        }
        $bytes = '';
        while ($v > 0) {
            $bytes = chr($v & 0xff) . $bytes;
            $v >>= 8;
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes; // leading zero to keep positive
        }
        return self::tlv(0x02, $bytes);
    }

    public static function encodeOctetString($s)
    {
        return self::tlv(0x04, $s);
    }

    public static function encodeNull()
    {
        return self::tlv(0x05, '');
    }

    public static function encodeOid($oid)
    {
        $parts = explode('.', ltrim($oid, '.'));
        if (count($parts) < 2) {
            $parts = array(0, 0);
        }
        $first = (intval($parts[0]) * 40) + intval($parts[1]);
        $body = self::encodeBase128($first);
        for ($i = 2; $i < count($parts); $i++) {
            $body .= self::encodeBase128(intval($parts[$i]));
        }
        return self::tlv(0x06, $body);
    }

    private static function encodeBase128($v)
    {
        if ($v === 0) {
            return "\x00";
        }
        $bytes = '';
        while ($v > 0) {
            $bytes = chr($v & 0x7f) . $bytes;
            $v >>= 7;
        }
        $out = '';
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $b = ord($bytes[$i]) | ($i < $n - 1 ? 0x80 : 0);
            $out .= chr($b);
        }
        return $out;
    }

    public static function encodeSequence($data)
    {
        return self::tlv(0x30, $data);
    }

    public static function encodeIpAddress($ip)
    {
        if (is_string($ip)) {
            $parts = explode('.', $ip);
            $bytes = '';
            foreach ($parts as $p) {
                $bytes .= chr(intval($p) & 0xff);
            }
        } else {
            $bytes = $ip;
        }
        return self::tlv(0x40, $bytes);
    }

    public static function encodeCounter32($v)
    {
        $v = $v & 0xffffffff;
        $bytes = '';
        if ($v === 0) {
            $bytes = "\x00";
        } else {
            while ($v > 0) {
                $bytes = chr($v & 0xff) . $bytes;
                $v >>= 8;
            }
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return self::tlv(0x41, $bytes);
    }

    public static function encodeGauge32($v)
    {
        $v = $v & 0xffffffff;
        $bytes = '';
        if ($v === 0) {
            $bytes = "\x00";
        } else {
            while ($v > 0) {
                $bytes = chr($v & 0xff) . $bytes;
                $v >>= 8;
            }
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return self::tlv(0x42, $bytes);
    }

    public static function encodeTimeTicks($v)
    {
        $v = $v & 0xffffffff;
        $bytes = '';
        if ($v === 0) {
            $bytes = "\x00";
        } else {
            while ($v > 0) {
                $bytes = chr($v & 0xff) . $bytes;
                $v >>= 8;
            }
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return self::tlv(0x43, $bytes);
    }

    public static function encodeCounter64($v)
    {
        // $v can be a string (decimal) or int
        if (is_string($v) && function_exists('gmp_init')) {
            $n = gmp_init($v, 10);
            $v = gmp_strval($n, 16);
            $bytes = hex2bin(str_pad($v, (strlen($v) + 1) & ~1, '0', STR_PAD_LEFT));
        } else {
            $orig = $v;
            $v = (int)$v;
            $bytes = '';
            while ($v > 0) {
                $bytes = chr($v & 0xff) . $bytes;
                $v >>= 8;
            }
        }
        if (strlen($bytes) === 0) {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return self::tlv(0x46, $bytes);
    }

    /* ---- value-variant encoder ---- */

    public static function encodeValue($type, $value)
    {
        switch ($type) {
            case 'INTEGER':
                return self::encodeInteger((int)$value);
            case 'OCTET STRING':
            case 'STRING':
                return self::encodeOctetString((string)$value);
            case 'NULL':
                return self::encodeNull();
            case 'OBJECT IDENTIFIER':
            case 'OID':
                return self::encodeOid($value);
            case 'IpAddress':
                return self::encodeIpAddress($value);
            case 'Counter32':
                return self::encodeCounter32((int)$value);
            case 'Gauge32':
            case 'Unsigned32':
                return self::encodeGauge32((int)$value);
            case 'TimeTicks':
                return self::encodeTimeTicks((int)$value);
            case 'Counter64':
                return self::encodeCounter64($value);
            case 'Hex-STRING':
                if (is_string($value) && preg_match('/^[0-9a-fA-F]+$/', $value) && strlen($value) % 2 === 0) {
                    return self::encodeOctetString(hex2bin($value));
                }
                return self::encodeOctetString((string)$value);
            case 'OPAQUE':
                return self::tlv(0x44, (string)$value);
            default:
                return self::encodeOctetString((string)$value);
        }
    }

    /* ---- generic TLV parser ---- */

    public static function parseTlv($data, &$pos)
    {
        if ($pos >= strlen($data)) {
            return null;
        }
        $tag = ord($data[$pos++]);
        $len = self::decodeLength($data, $pos);
        $value = substr($data, $pos, $len);
        $pos += $len;
        return array('tag' => $tag, 'value' => $value);
    }

    public static function parseInteger($data)
    {
        $v = 0;
        $n = strlen($data);
        // signed two's complement
        for ($i = 0; $i < $n; $i++) {
            $v = ($v << 8) | ord($data[$i]);
        }
        // sign extension if top bit set and length < sizeof(intmax)
        if ($n > 0 && (ord($data[0]) & 0x80)) {
            // negative
            $bits = $n * 8;
            $v = $v - (1 << $bits);
        }
        return (int)$v;
    }

    public static function parseUnsigned($data)
    {
        $v = 0;
        $n = strlen($data);
        for ($i = 0; $i < $n; $i++) {
            $v = ($v << 8) | ord($data[$i]);
        }
        // For 64-bit counters on 32-bit PHP this loses precision; try BCmath
        if ($n > 4 && function_exists('gmp_init')) {
            $g = gmp_init(0);
            for ($i = 0; $i < $n; $i++) {
                $g = gmp_mul($g, 256);
                $g = gmp_add($g, ord($data[$i]));
            }
            return gmp_strval($g);
        }
        return $v;
    }

    public static function parseOid($data)
    {
        if (strlen($data) === 0) {
            return '';
        }
        $first = ord($data[0]);
        $a = intdiv($first, 40);
        $b = $first % 40;
        if ($a > 2) {
            // unusual: when first byte encodes a value > 80 (i.e. 2.40+n), the spec uses 2.<n>
            $a = 2;
            $b = $first - 80;
        }
        $oid = "$a.$b";
        $v = 0;
        $n = strlen($data);
        for ($i = 1; $i < $n; $i++) {
            $byte = ord($data[$i]);
            $v = ($v << 7) | ($byte & 0x7f);
            if (!($byte & 0x80)) {
                $oid .= ".$v";
                $v = 0;
            }
        }
        return $oid;
    }

    public static function parseSequence($data)
    {
        $items = array();
        $pos = 0;
        while ($pos < strlen($data)) {
            $item = self::parseTlv($data, $pos);
            if ($item === null) {
                break;
            }
            $items[] = $item;
        }
        return $items;
    }

    public static function parseIpAddress($data)
    {
        if (strlen($data) !== 4) {
            return '';
        }
        return ord($data[0]) . '.' . ord($data[1]) . '.' . ord($data[2]) . '.' . ord($data[3]);
    }
}
