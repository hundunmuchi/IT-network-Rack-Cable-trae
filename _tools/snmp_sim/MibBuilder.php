<?php
/**
 * Build the in-memory OID tree for a simulated device.
 *
 * Each leaf is an array:
 *   array('type' => 'OCTET STRING', 'value' => '...', 'oid' => '.1.3.6...')
 *
 * For table-like data we use indexed OIDs under the table entry OID
 * (so getnext / getbulk can iterate them).
 *
 * Counters (octets, uptime, etc.) are computed at lookup time based on the
 * current time and a deterministic base so values increase across polls.
 */
require_once __DIR__ . '/Ber.php';

class MibBuilder
{
    private $oids = array();      // oid => leaf array
    private $orderedKeys = array(); // sorted list of OIDs for next/bulk walk
    private $bootTime;

    public function __construct()
    {
        $this->bootTime = time();
    }

    public function add($oid, $type, $value)
    {
        // Normalize: ensure leading dot for matching
        $oid = '.' . ltrim($oid, '.');
        $this->oids[$oid] = array('type' => $type, 'value' => $value, 'oid' => $oid);
    }

    /**
     * Add a leaf whose value is computed at lookup time (for time-varying counters).
     * $callback receives the OID string and must return a scalar value.
     */
    public function addCallback($oid, $type, callable $callback)
    {
        $oid = '.' . ltrim($oid, '.');
        $this->oids[$oid] = array('type' => $type, 'value' => '', 'oid' => $oid, 'callback' => $callback);
    }

    public function build()
    {
        $keys = array_keys($this->oids);
        // Build a comparator for proper SNMP OID ordering: numeric per-arc
        usort($keys, function ($a, $b) {
            $ta = explode('.', ltrim($a, '.'));
            $tb = explode('.', ltrim($b, '.'));
            $n = min(count($ta), count($tb));
            for ($i = 0; $i < $n; $i++) {
                $ia = (int)$ta[$i];
                $ib = (int)$tb[$i];
                if ($ia !== $ib) {
                    return $ia < $ib ? -1 : 1;
                }
            }
            return count($ta) < count($tb) ? -1 : (count($ta) > count($tb) ? 1 : 0);
        });
        $this->orderedKeys = $keys;
    }

    public function get($oid)
    {
        $oid = '.' . ltrim($oid, '.');
        if (isset($this->oids[$oid])) {
            $leaf = $this->oids[$oid];
            // Allow callback value generators
            if (isset($leaf['callback'])) {
                $leaf['value'] = call_user_func($leaf['callback'], $oid);
            }
            return $leaf;
        }
        return null;
    }

    public function getNext($oid)
    {
        $oid = '.' . ltrim($oid, '.');
        foreach ($this->orderedKeys as $k) {
            if ($this->oidGreater($k, $oid)) {
                $leaf = $this->oids[$k];
                if (isset($leaf['callback'])) {
                    $leaf['value'] = call_user_func($leaf['callback'], $k);
                }
                return $leaf;
            }
        }
        return null; // end of MIB
    }

    public function getBulk($oid, $maxRepetitions, $nonRepeaters = 0)
    {
        // Returns array of leaves: first $nonRepeaters are 1-step next,
        // then up to $maxRepetitions iterations of next on the rest.
        $result = array();
        $cur = $oid;
        for ($i = 0; $i < $nonRepeaters; $i++) {
            $leaf = $this->getNext($cur);
            if ($leaf === null) {
                // end-of-mib marker
                $result[] = array('type' => 'NULL', 'value' => '', 'oid' => '.0.0', 'eom' => true);
                $cur = '.0.0';
                continue;
            }
            $result[] = $leaf;
            $cur = $leaf['oid'];
        }
        $count = 0;
        $max = max(1, $maxRepetitions);
        // SNMPv2c getbulk returns at least N + max*R varbinds; if last row
        // reaches end-of-mib, response is truncated with endOfMibVbs.
        $endReached = false;
        while ($count < $max && !$endReached) {
            // Walk one column per repetition
            $leaf = $this->getNext($cur);
            if ($leaf === null) {
                $result[] = array('type' => 'NULL', 'value' => '', 'oid' => '.0.0', 'eom' => true);
                $endReached = true;
                break;
            }
            $result[] = $leaf;
            $cur = $leaf['oid'];
            $count++;
        }
        return $result;
    }

    /**
     * Resolve any callback in a leaf to produce the final value.
     */
    public function resolve($leaf)
    {
        if (isset($leaf['callback'])) {
            $leaf['value'] = call_user_func($leaf['callback'], $leaf['oid']);
        }
        return $leaf;
    }

    private function oidGreater($a, $b)
    {
        $ta = explode('.', ltrim($a, '.'));
        $tb = explode('.', ltrim($b, '.'));
        $n = min(count($ta), count($tb));
        for ($i = 0; $i < $n; $i++) {
            $ia = (int)$ta[$i];
            $ib = (int)$tb[$i];
            if ($ia !== $ib) {
                return $ia > $ib;
            }
        }
        return count($ta) > count($tb);
    }

    public function uptimeCentiseconds()
    {
        return (time() - $this->bootTime) * 100;
    }

    public function bootTime()
    {
        return $this->bootTime;
    }
}
