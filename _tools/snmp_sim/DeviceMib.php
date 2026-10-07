<?php
/**
 * Build the MIB tree for a given device definition.
 *
 * Returns a MibBuilder populated with:
 *   - system group (sysDescr, sysObjectID, sysUpTime, sysContact, sysName, sysLocation, sysServices, sysORLastChange)
 *   - SNMPv2-MIB::sysORTable (a small table, to satisfy any nextwalk on .1.3.6.1.2.1.1.9)
 *   - ifTable (ifIndex, ifDescr, ifType, ifMtu, ifSpeed, ifPhysAddress, ifAdminStatus, ifOperStatus,
 *              ifLastChange, ifInOctets, ifOutOctets, ifInErrors, ifOutErrors, ifInDiscards,
 *              ifOutDiscards, ifInUnknownProtos, ifInNUcastPkts, ifOutNUcastPkts)
 *   - ifXTable (ifName, ifInMulticastPkts, ifOutMulticastPkts, ifInBroadcastPkts, ifOutBroadcastPkts,
 *               ifHCInOctets, ifHCOutOctets, ifHCInUcastPkts, ifHCOutUcastPkts, ifConnectorPresent,
 *               ifAlias, ifCounterDiscontinuityTime)
 *   - ipAdEntAddr / ipAdEntIfIndex / ipAdEntNetMask (ipAddrTable)
 *   - lldpRemTable (lldpRemChassisId, lldpRemChassisIdSubtype, lldpRemPortId, lldpRemPortIdSubtype,
 *                   lldpRemPortDesc, lldpRemSysName, lldpRemManAddrAddr, lldpRemManAddrLen)
 *   - lldpLocPortTable (lldpLocPortDesc, lldpLocPortId, lldpLocPortIdSubtype)
 *
 * Counter values use a deterministic time-based function so values increase
 * across polls and look like real traffic.
 */
require_once __DIR__ . '/Ber.php';
require_once __DIR__ . '/MibBuilder.php';

class DeviceMib
{
    public static function build($deviceDef)
    {
        $mib = new MibBuilder();
        $bootTime = $mib->bootTime();

        /* ---- system group ---- */
        $mib->add('.1.3.6.1.2.1.1.1.0',         'OCTET STRING', $deviceDef['sysDescr']);
        $mib->add('.1.3.6.1.2.1.1.2.0',         'OBJECT IDENTIFIER', $deviceDef['sysObjectID']);
        $mib->addCallback('.1.3.6.1.2.1.1.3.0', 'TimeTicks', function () use ($mib) { return $mib->uptimeCentiseconds(); });
        $mib->add('.1.3.6.1.2.1.1.4.0',         'OCTET STRING', $deviceDef['sysContact']);
        $mib->add('.1.3.6.1.2.1.1.5.0',         'OCTET STRING', $deviceDef['sysName']);
        $mib->add('.1.3.6.1.2.1.1.6.0',         'OCTET STRING', $deviceDef['sysLocation']);
        $mib->add('.1.3.6.1.2.1.1.7.0',         'INTEGER', 78);  // services: Internet + End-to-End + Application + layers
        $mib->add('.1.3.6.1.2.1.1.8.0',         'TimeTicks', 0); // sysORLastChange
        $mib->add('.1.3.6.1.2.1.1.9.1.2.1',     'OBJECT IDENTIFIER', '.1.3.6.1.4.1.9'); // sysOREntry (one row)
        $mib->add('.1.3.6.1.2.1.1.9.1.3.1',     'OCTET STRING', 'Cisco Systems Inc. (managed by librenms simulator)');
        $mib->add('.1.3.6.1.2.1.1.9.1.4.1',     'TimeTicks', 0);
        $mib->add('.1.3.6.1.2.1.1.9.1.5.1',     'TimeTicks', 0);

        /* ---- SNMP-FRAMEWORK-MIB::snmpEngineTime / snmpEngineBoots (used by some discovery) ---- */
        $mib->add('.1.3.6.1.6.3.10.2.1.1.0',    'INTEGER', 0); // snmpEngineBoots
        $mib->add('.1.3.6.1.6.3.10.2.1.2.0',    'INTEGER', (int)((time() - $bootTime) / 100)); // snmpEngineTime
        $mib->add('.1.3.6.1.6.3.10.2.1.3.0',    'OCTET STRING', 'LibreNMS SNMP simulator');

        /* ---- vendor-specific sysDescr-like (HP, Cisco, Huawei) used by LibreNMS OS detection ---- */
        // For Cisco, expose ciscoImageString / ciscoMemPool / ciscoSerial - simplified versions
        if ($deviceDef['vendor'] === 'cisco') {
            // .1.3.6.1.2.1.47.1.1.1.1.10.1 = entPhysicalSerialNum (for serial)
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.10.1',     'OCTET STRING', $deviceDef['serial']);
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.11.1',     'OCTET STRING', 'Cisco Systems');
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.12.1',     'OCTET STRING', $deviceDef['hardware']);
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.7.1',      'OCTET STRING', $deviceDef['hardware']);
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.13.1',     'OCTET STRING', 'Cisco IOS-XE');
            // cpmCPU table - load average
            $mib->add('.1.3.6.1.4.1.9.9.109.1.1.1.1.3.1', 'Gauge32', 5);   // cpmCPUTotal5sec
            $mib->add('.1.3.6.1.4.1.9.9.109.1.1.1.1.6.1', 'Gauge32', 7);   // cpmCPUTotal1min
            $mib->add('.1.3.6.1.4.1.9.9.109.1.1.1.1.7.1', 'INTEGER', 25);  // cpmCPUMemoryUsed
            $mib->add('.1.3.6.1.4.1.9.9.109.1.1.1.1.8.1', 'INTEGER', 512); // cpmCPUMemoryFree
            $mib->add('.1.3.6.1.4.1.9.9.109.1.1.1.1.12.7', 'Gauge32', 8);   // cpmCPUThreshold (alt)
        } elseif ($deviceDef['vendor'] === 'huawei') {
            // ENTITY-MIB
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.10.1',     'OCTET STRING', $deviceDef['serial']);
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.12.1',     'OCTET STRING', $deviceDef['hardware']);
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.7.1',      'OCTET STRING', $deviceDef['hardware']);
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.13.1',     'OCTET STRING', 'Huawei Versatile Routing Platform Software');
            // hwEntityStatus - simplified
            $mib->add('.1.3.6.1.4.1.2011.10.1.1.2.1.1.0', 'OCTET STRING', $deviceDef['serial']);
            $mib->add('.1.3.6.1.4.1.2011.5.25.31.1.1.1.0','INTEGER', 1);
            // CPU usage (HW)
            $mib->add('.1.3.6.1.4.1.2011.6.3.4.1.0',       'Gauge32', 8);   // hwEntityCpuUsage
            $mib->add('.1.3.6.1.4.1.2011.6.3.4.2.0',       'Gauge32', 12); // hwEntityMemUsage
        } else { // hp
            // ENTITY-MIB
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.10.1',     'OCTET STRING', $deviceDef['serial']);
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.12.1',     'OCTET STRING', $deviceDef['hardware']);
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.7.1',      'OCTET STRING', $deviceDef['hardware']);
            $mib->add('.1.3.6.1.2.1.47.1.1.1.1.13.1',     'OCTET STRING', 'HP ProCurve');
            // hpCpu / hpMem (ProCurve)
            $mib->add('.1.3.6.1.4.1.11.2.14.11.5.1.9.1.1.1.0', 'Gauge32', 15); // hpCpuUsage (approximation)
            $mib->add('.1.3.6.1.4.1.11.2.14.11.5.1.9.2.1.1.0', 'Gauge32', 30); // hpMemUsage
        }

        /* ---- ifTable + ifXTable ---- */
        $i = 1;
        foreach ($deviceDef['interfaces'] as $iface) {
            $idx = $i;
            // 1 = up, 2 = down, 3 = testing - we make most "up", a couple "down" for realistic data
            $oper = ($idx == 2 || $idx == 5) ? 2 : 1; // 2 ports down
            $admin = 1; // always admin up

            // ifTable (.1.3.6.1.2.1.2.2.1.x.<idx>)
            $mib->add(".1.3.6.1.2.1.2.2.1.1.$idx",     'INTEGER', $idx);     // ifIndex
            $mib->add(".1.3.6.1.2.1.2.2.1.2.$idx",     'OCTET STRING', $iface['desc']); // ifDescr
            $mib->add(".1.3.6.1.2.1.2.2.1.3.$idx",     'INTEGER', $iface['type']);     // ifType
            $mib->add(".1.3.6.1.2.1.2.2.1.4.$idx",     'INTEGER', 1500);              // ifMtu
            $mib->add(".1.3.6.1.2.1.2.2.1.5.$idx",     'Gauge32', $iface['speed']);    // ifSpeed
            $macHex = self::macToHex($iface['mac']);
            $mib->add(".1.3.6.1.2.1.2.2.1.6.$idx",     'Hex-STRING', $macHex); // ifPhysAddress
            $mib->add(".1.3.6.1.2.1.2.2.1.7.$idx",     'INTEGER', $admin);              // ifAdminStatus
            $mib->add(".1.3.6.1.2.1.2.2.1.8.$idx",     'INTEGER', $oper);               // ifOperStatus
            $mib->add(".1.3.6.1.2.1.2.2.1.9.$idx",     'TimeTicks', 0);                 // ifLastChange
            // Counters are computed at query time so traffic grows over time
            $port = $deviceDef['port'];
            $mib->addCallback(".1.3.6.1.2.1.2.2.1.10.$idx", 'Counter32', function () use ($idx, $port) { return self::counter($idx, 'in',  $port); });   // ifInOctets
            $mib->addCallback(".1.3.6.1.2.1.2.2.1.11.$idx", 'Counter32', function () use ($idx, $port) { return self::counter($idx, 'out', $port); });   // ifOutOctets
            $mib->addCallback(".1.3.6.1.2.1.2.2.1.12.$idx", 'Counter32', function () use ($idx, $port) { return self::counter($idx, 'inerrors', $port); });
            $mib->addCallback(".1.3.6.1.2.1.2.2.1.13.$idx", 'Counter32', function () use ($idx, $port) { return self::counter($idx, 'outerrors', $port); });
            $mib->add(".1.3.6.1.2.1.2.2.1.14.$idx",    'Counter32', 0);  // ifInUnknownProtos
            $mib->addCallback(".1.3.6.1.2.1.2.2.1.15.$idx", 'Counter32', function () use ($idx, $port) { return self::counter($idx, 'inucast', $port); });
            $mib->addCallback(".1.3.6.1.2.1.2.2.1.16.$idx", 'Counter32', function () use ($idx, $port) { return self::counter($idx, 'outucast', $port); });
            $mib->add(".1.3.6.1.2.1.2.2.1.17.$idx",    'Counter32', 0);  // ifInNUcastPkts
            $mib->add(".1.3.6.1.2.1.2.2.1.18.$idx",    'Counter32', 0);  // ifOutNUcastPkts
            $mib->add(".1.3.6.1.2.1.2.2.1.19.$idx",    'Gauge32', $oper === 1 ? 1 : 0);  // ifOutQLen

            // ifXTable (.1.3.6.1.2.1.31.1.1.1.x.<idx>)
            $mib->add(".1.3.6.1.2.1.31.1.1.1.1.$idx",   'OCTET STRING', $iface['desc']);  // ifName (use same desc)
            $mib->add(".1.3.6.1.2.1.31.1.1.1.2.$idx",   'Counter32', 0);   // ifInMulticastPkts
            $mib->add(".1.3.6.1.2.1.31.1.1.1.3.$idx",   'Counter32', 0);   // ifOutMulticastPkts
            $mib->add(".1.3.6.1.2.1.31.1.1.1.4.$idx",   'Counter32', 0);   // ifInBroadcastPkts
            $mib->add(".1.3.6.1.2.1.31.1.1.1.5.$idx",   'Counter32', 0);   // ifOutBroadcastPkts
            $mib->addCallback(".1.3.6.1.2.1.31.1.1.1.6.$idx",  'Counter64', function () use ($idx, $port) { return self::counter64($idx, 'in', $port); });  // ifHCInOctets
            $mib->addCallback(".1.3.6.1.2.1.31.1.1.1.7.$idx",  'Counter64', function () use ($idx, $port) { return self::counter64($idx, 'out', $port); }); // ifHCOutOctets
            $mib->add(".1.3.6.1.2.1.31.1.1.1.8.$idx",   'Counter64', 0);   // ifHCInUcastPkts
            $mib->add(".1.3.6.1.2.1.31.1.1.1.9.$idx",   'Counter64', 0);   // ifHCOutUcastPkts
            $mib->add(".1.3.6.1.2.1.31.1.1.1.10.$idx",  'INTEGER', 1);     // ifConnectorPresent (yes)
            $mib->add(".1.3.6.1.2.1.31.1.1.1.11.$idx",  'OCTET STRING', 'desc_' . $iface['desc']); // ifAlias
            $mib->add(".1.3.6.1.2.1.31.1.1.1.12.$idx",  'TimeTicks', 0);   // ifCounterDiscontinuityTime
            $mib->add(".1.3.6.1.2.1.31.1.1.1.13.$idx",  'OCTET STRING', 'up'); // ifCompliance
            $mib->add(".1.3.6.1.2.1.31.1.1.1.14.$idx",  'OCTET STRING', 'ethernetCsmacd'); // ifStackStatus
            $mib->add(".1.3.6.1.2.1.31.1.1.1.15.$idx",  'OCTET STRING', '');
            $mib->add(".1.3.6.1.2.1.31.1.1.1.16.$idx",  'OCTET STRING', '');
            $mib->add(".1.3.6.1.2.1.31.1.1.1.17.$idx",  'OCTET STRING', '');
            $mib->add(".1.3.6.1.2.1.31.1.1.1.18.$idx",  'INTEGER', 1);  // ifCounterDiscontinuityTime
            $i++;
        }

        /* ---- ipAddrTable (.1.3.6.1.2.1.4.20.1.x.<idx>) ---- */
        foreach ($deviceDef['ipAddr'] as $entry) {
            $idx = $entry['addr']; // OID index is the IP encoded
            $mib->add(".1.3.6.1.2.1.4.20.1.1.$idx", 'IpAddress', $entry['addr']);           // ipAdEntAddr
            $mib->add(".1.3.6.1.2.1.4.20.1.2.$idx", 'INTEGER', $entry['ifIndex']);          // ipAdEntIfIndex
            $mib->add(".1.3.6.1.2.1.4.20.1.3.$idx", 'IpAddress', $entry['mask']);          // ipAdEntNetMask
            $mib->add(".1.3.6.1.2.1.4.20.1.4.$idx", 'INTEGER', 144000);                    // ipAdEntBcastAddr
            $mib->add(".1.3.6.1.2.1.4.20.1.5.$idx", 'INTEGER', 0);                          // ipAdEntReasmMaxSize
        }

        /* ---- LLDP-MIB tables ---- */
        // .1.0.8802.1.1.2.1.4.1 = lldpRemTable (per lldpRemTimeMark.lldpRemLocalPortNum.lldpRemIndex)
        // lldpRemChassisIdSubtype / lldpRemChassisId / lldpRemPortIdSubtype / lldpRemPortId / lldpRemPortDesc / lldpRemSysName / lldpRemManAddr
        foreach ($deviceDef['lldpRem'] as $i => $nbr) {
            $timeMark = 100;
            $port = $nbr['port'];
            $idx = $i + 1;
            $key = "$timeMark.$port.$idx"; // lldpRemTimeMark.lldpRemLocalPortNum.lldpRemIndex
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.3.$key", 'INTEGER', 4);   // lldpRemChassisIdSubtype (4 = mac address)
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.4.$key", 'OCTET STRING', $nbr['chassisId']);
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.5.$key", 'OCTET STRING', '');
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.6.$key", 'INTEGER', 5);   // lldpRemPortIdSubtype (5 = interface name)
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.7.$key", 'OCTET STRING', $nbr['portId']);
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.8.$key", 'OCTET STRING', $nbr['portDesc']);
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.9.$key", 'OCTET STRING', $nbr['sysName']);
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.10.$key", 'OCTET STRING', '');
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.11.$key", 'OCTET STRING', '');
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.12.$key", 'TimeTicks', 100 * $idx);
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.13.$key", 'INTEGER', 14);  // lldpRemManAddrSubtype (14 = ipv4)
            $mib->add(".1.0.8802.1.1.2.1.4.1.1.14.$key", 'IpAddress', '0.0.0.0');
        }
        // lldpLocPortTable (.1.0.8802.1.1.2.1.3.7.1.x.<port>)
        foreach ($deviceDef['lldpRem'] as $i => $nbr) {
            $port = $nbr['port'];
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.3.$port", 'OCTET STRING', 'Port ' . $port);
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.4.$port", 'OCTET STRING', $nbr['portId']);
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.5.$port", 'INTEGER', 5);  // lldpLocPortIdSubtype
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.6.$port", 'OCTET STRING', '');
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.7.$port", 'TimeTicks', 0);
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.8.$port", 'INTEGER', 0);
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.9.$port", 'INTEGER', 0);
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.10.$port", 'INTEGER', 1);
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.11.$port", 'INTEGER', 1);
            $mib->add(".1.0.8802.1.1.2.1.3.7.1.12.$port", 'INTEGER', 1);
        }
        // lldpLocalSystemData - chassis
        $mib->add('.1.0.8802.1.1.2.1.3.1',  'INTEGER', 4);   // lldpMessageTxInterval
        $mib->add('.1.0.8802.1.1.2.1.3.2',  'INTEGER', 120); // lldpMessageTxHoldMultiplier
        $mib->add('.1.0.8802.1.1.2.1.3.3',  'INTEGER', 4);   // lldpNotificationInterval
        $mib->add('.1.0.8802.1.1.2.1.3.6.0', 'INTEGER', 4);  // lldpLocChassisIdSubtype
        $mib->add('.1.0.8802.1.1.2.1.3.7.0', 'OCTET STRING', $deviceDef['sysName']);

        /* ---- host resources (sensors) ---- */
        // hrStorage (.1.3.6.1.2.1.25.2.3.1.x.<idx>)
        $mib->add('.1.3.6.1.2.1.25.2.3.1.1.1', 'INTEGER', 1);
        $mib->add('.1.3.6.1.2.1.25.2.3.1.2.1', 'OCTET STRING', 'Memory');
        $mib->add('.1.3.6.1.2.1.25.2.3.1.3.1', 'OBJECT IDENTIFIER', '.1.3.6.1.2.1.25.2.1.2'); // hrStorageRam
        $mib->add('.1.3.6.1.2.1.25.2.3.1.4.1', 'INTEGER', 4096);
        $mib->add('.1.3.6.1.2.1.25.2.3.1.5.1', 'INTEGER', 524288);
        $mib->add('.1.3.6.1.2.1.25.2.3.1.6.1', 'INTEGER', 100000);
        $mib->add('.1.3.6.1.2.1.25.2.3.1.7.1', 'INTEGER', 424288);

        // hrDevice (.1.3.6.1.2.1.25.3.1) - simplified
        $mib->add('.1.3.6.1.2.1.25.3.1.1.0', 'OCTET STRING', 'Device ' . $deviceDef['sysName']);

        /* ---- entity sensors ---- */
        // ENTITY-SENSOR-MIB::entPhySensorScale etc - return some env sensors (Temp / Fan)
        // Simplified to 1 row entry
        $mib->add('.1.3.6.1.2.1.99.1.1.1.1.1', 'INTEGER', 8);    // entPhySensorType = unknown
        $mib->add('.1.3.6.1.2.1.99.1.1.1.2.1', 'INTEGER', 0);    // scale
        $mib->add('.1.3.6.1.2.1.99.1.1.1.3.1', 'INTEGER', 350);  // value (35.0 degrees)
        $mib->add('.1.3.6.1.2.1.99.1.1.1.4.1', 'INTEGER', 1);    // status = ok
        $mib->add('.1.3.6.1.2.1.99.1.1.1.5.1', 'TimeTicks', 0);

        /* ---- IF-MIB::ifStackTable (stacked relationships) ---- */
        // We'll add a stub entry per ifIndex (lower=physical, upper=vlan)
        foreach ($deviceDef['interfaces'] as $i => $iface) {
            $idx = $i + 1;
            // ifStackStatus.<upper>.<lower>
            $mib->add(".1.3.6.1.2.1.31.1.2.1.1.$idx.0", 'INTEGER', 1);
        }

        /* ---- ipNetToMediaTable (ARP) ---- */
        foreach ($deviceDef['lldpRem'] as $i => $nbr) {
            $rowKey = '1.' . dechex(ip2long('10.10.' . ($i + 1) . '.1'));
            $mib->add(".1.3.6.1.2.1.4.22.1.1.$rowKey", 'INTEGER', $nbr['port']);
            $mib->add(".1.3.6.1.2.1.4.22.1.2.$rowKey", 'Hex-STRING', '000c' . dechex(0xab + $i) . '0' . $i);
            $mib->add(".1.3.6.1.2.1.4.22.1.3.$rowKey", 'IpAddress', '10.10.' . ($i + 1) . '.1');
            $mib->add(".1.3.6.1.2.1.4.22.1.4.$rowKey", 'INTEGER', 3); // dynamic
        }

        $mib->build();
        return $mib;
    }

    /**
     * Generate a deterministic, time-increasing Counter32 value for an interface.
     * $kind in: in / out / inerrors / outerrors / inucast / outucast
     */
    public static function counter($ifIndex, $kind, $port)
    {
        $now = time();
        $bootTime = $port * 1000; // device-specific boot offset
        $uptime = $now - $bootTime / 100; // ~bootTime shifted seconds
        if ($uptime < 0) $uptime = 0;
        // base rate per kind (octets/sec)
        $rate = ($kind === 'in' || $kind === 'out') ? 1000000 + ($ifIndex * 250000) : 0; // ~1MB/s + per-if
        if ($kind === 'inerrors' || $kind === 'outerrors') $rate = 1 + $ifIndex;
        if ($kind === 'inucast' || $kind === 'outucast') $rate = 1000 + $ifIndex * 100;
        // Sin modulation so it looks like real traffic
        $modulation = 0.5 + 0.5 * sin($now / 60 + $ifIndex);
        $value = $uptime * $rate * $modulation;
        // Cap to 32-bit Counter32 with wrap
        $value = abs((int)$value) & 0xffffffff;
        return $value;
    }

    public static function counter64($ifIndex, $kind, $port)
    {
        // Counter64 wraps less; let it grow naturally but stay as string for safety
        $now = time();
        $bootTime = $port * 1000;
        $uptime = $now - $bootTime / 100;
        if ($uptime < 0) $uptime = 0;
        $rate = 1000000 + ($ifIndex * 250000);
        $modulation = 0.5 + 0.5 * sin($now / 60 + $ifIndex);
        $modStr = number_format(max(0, $modulation), 6, '.', '');
        $value = bcmul((string)(int)$uptime, (string)$rate, 0);
        $value = bcmul($value, $modStr, 0);
        // Limit to 64-bit range
        if (bccomp($value, '9223372036854775807') > 0) {
            $value = bcmod($value, '18446744073709551616');
        }
        return $value;
    }

    public static function macToHex($mac)
    {
        // Accept formats like "4c1f-cc-00-01" or "00:0c:29:aa:bb:cc"
        $cleaned = str_replace(array('-', ':', '.'), '', $mac);
        if (preg_match('/^[0-9a-fA-F]{12}$/', $cleaned)) {
            return $cleaned;
        }
        // Fall back: hash the string into a 6-byte MAC
        $hash = md5($mac);
        return substr($hash, 0, 12);
    }
}
