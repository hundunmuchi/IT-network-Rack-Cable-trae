<?php
/**
 * 模拟网络设备定义：惠普 (HP/ProCurve)、思科 (Cisco)、华为 (Huawei)。
 *
 * 每台设备都返回：sysDescr / sysObjectID / sysUpTime / sysName / sysContact / sysLocation、
 * 接口表（ifIndex, ifDescr, ifType, ifSpeed, ifAdminStatus, ifOperStatus, ifHCInOctets,
 * ifHCOutOctets, ifInOctets, ifOutOctets, ifInErrors, ifOutErrors, ifInDiscards, ifOutDiscards）、
 * IP 地址表（ipAdEntAddr / ipAdEntIfIndex / ipAdEntNetMask）、
 * LLDP 邻居（lldpRemTable，对应链路发现）。
 *
 * 设备基线数据由这些函数返回；运行时的 Counter 类型（如 octets/uptime）会按时间动态增长，
 * 让 LibreNMS 把它们当成实时设备采集并画出流量图。
 *
 * 端口分配：每台设备独占一个 UDP 端口（127.0.0.1:11611/11612/11613），
 * 避免 Windows 上无法绑定多个 UDP 161。
 */

// SNMPv3 credentials shared by all simulated devices.
// authPriv with HMAC-SHA-96 authentication and AES-128-CFB privacy.
$v3Creds = array(
    'user'      => 'librenms',
    'authPass'  => 'AuthPass123',
    'privPass'  => 'PrivPass456',
    'authAlgo'  => 'SHA',
    'privAlgo'  => 'AES',
);

return array(
    // ============== 惠普 ProCurve 交换机 ==============
    'hp' => array(
        'ip'   => '127.0.0.2',
        'port' => 11611,
        'v3'   => $v3Creds,
        'ssh'  => array('port' => 2222, 'user' => 'admin', 'pass' => 'admin'),
        'sysObjectID' => '.1.3.6.1.4.1.11.2.3.7.11.45',     // hp.switch.proCurve
        'sysDescr' => "HP J8692A ProCurve Switch 3500yl-24G-PWR, revision K.13.25, ROM K.12.02 (P)R, (R)", 
        'sysName' => 'hp-sw-core-01',
        'sysContact' => 'noc@example.com',
        'sysLocation' => '机房 A - 机柜 1 - U1',
        'vendor' => 'hp',
        'hardware' => 'J8692A ProCurve Switch 3500yl-24G-PWR',
        'os' => 'ProCurve K.13.25',
        'serial' => 'CZ12345678',
        'interfaces' => array(
            array('desc' => '1', 'type' => 6,   'speed' => 1000000000, 'mac' => '001234-abcd-01'), // 1Gbps copper
            array('desc' => '2', 'type' => 6,   'speed' => 1000000000, 'mac' => '001234-abcd-02'),
            array('desc' => '3', 'type' => 6,   'speed' => 1000000000, 'mac' => '001234-abcd-03'),
            array('desc' => '4', 'type' => 6,   'speed' => 1000000000, 'mac' => '001234-abcd-04'),
            array('desc' => '5', 'type' => 6,   'speed' => 1000000000, 'mac' => '001234-abcd-05'),
            array('desc' => '6', 'type' => 6,   'speed' => 1000000000, 'mac' => '001234-abcd-06'),
            array('desc' => '7', 'type' => 6,   'speed' => 1000000000, 'mac' => '001234-abcd-07'),
            array('desc' => '8', 'type' => 6,   'speed' => 1000000000, 'mac' => '001234-abcd-08'),
            array('desc' => '9', 'type' => 6,   'speed' => 1000000000, 'mac' => '001234-abcd-09'),
            array('desc' => '10', 'type' => 6,  'speed' => 1000000000, 'mac' => '001234-abcd-0a'),
            array('desc' => '11', 'type' => 6,  'speed' => 1000000000, 'mac' => '001234-abcd-0b'),
            array('desc' => '12', 'type' => 6,  'speed' => 1000000000, 'mac' => '001234-abcd-0c'),
            array('desc' => 'Trk1', 'type' => 56, 'speed' => 2000000000, 'mac' => '001234-abcd-0d'), // Trunk
            array('desc' => 'Trk2', 'type' => 56, 'speed' => 2000000000, 'mac' => '001234-abcd-0e'),
            array('desc' => 'VLAN1', 'type' => 53, 'speed' => 1000000000, 'mac' => '001234-abcd-0f'), // vlan
        ),
        'ipAddr' => array(
            array('addr' => '10.10.1.2', 'mask' => '255.255.255.0', 'ifIndex' => 15), // VLAN1
        ),
        'lldpRem' => array(
            // lldpRemTable: lldpRemTimeMark.lldpRemLocalPortNum.lldpRemIndex
            // LLDP-MIB uses lldpRemPortSubtype / lldpRemPortId / lldpRemChassisId etc.
            array('port' => 1, 'chassisId' => 'c84c-cat1', 'portId' => 'Gi1/0/1', 'sysName' => 'cisco-sw-edge-01', 'portDesc' => 'to-cisco-edge-01'),
            array('port' => 2, 'chassisId' => 'h3c-5700', 'portId' => 'GE0/0/1',  'sysName' => 'huawei-sw-edge-01', 'portDesc' => 'to-huawei-edge-01'),
            array('port' => 13, 'chassisId' => 'c84c-cat2', 'portId' => 'Gi1/0/24','sysName' => 'cisco-sw-core-02', 'portDesc' => 'to-cisco-core-02'),
        ),
    ),

    // ============== 思科 Catalyst 3850 交换机 ==============
    'cisco' => array(
        'ip'   => '127.0.0.3',
        'port' => 11611,
        'v3'   => $v3Creds,
        'ssh'  => array('port' => 2222, 'user' => 'admin', 'pass' => 'admin'),
        'sysObjectID' => '.1.3.6.1.4.1.9.1.1745',          // cisco.catalyst3850
        'sysDescr' => "Cisco IOS Software, Catalyst 3850 Software (CAT3K_CAA-UNIVERSALK9-M), Version 16.12.10p RELEASE SOFTWARE (fc2)\nTechnical Support: http://www.cisco.com/techsupport\nCopyright (c) 1986-2023 by Cisco Systems, Inc.\nCompiled Wed 12-Apr-23 13:00 by mcpre",
        'sysName' => 'cisco-sw-core-01',
        'sysContact' => 'noc@example.com',
        'sysLocation' => '机房 B - 机柜 3 - U10',
        'vendor' => 'cisco',
        'hardware' => 'Catalyst 3850-48T-E',
        'os' => 'IOS-XE 16.12.10p',
        'serial' => 'FOC1234A5Z6',
        'interfaces' => array(
            array('desc' => 'Gi1/0/1',  'type' => 6, 'speed' => 1000000000, 'mac' => '00abcd-000001'),
            array('desc' => 'Gi1/0/2',  'type' => 6, 'speed' => 1000000000, 'mac' => '00abcd-000002'),
            array('desc' => 'Gi1/0/3',  'type' => 6, 'speed' => 1000000000, 'mac' => '00abcd-000003'),
            array('desc' => 'Gi1/0/4',  'type' => 6, 'speed' => 1000000000, 'mac' => '00abcd-000004'),
            array('desc' => 'Gi1/0/5',  'type' => 6, 'speed' => 1000000000, 'mac' => '00abcd-000005'),
            array('desc' => 'Gi1/0/6',  'type' => 6, 'speed' => 1000000000, 'mac' => '00abcd-000006'),
            array('desc' => 'Gi1/0/7',  'type' => 6, 'speed' => 1000000000, 'mac' => '00abcd-000007'),
            array('desc' => 'Gi1/0/8',  'type' => 6, 'speed' => 1000000000, 'mac' => '00abcd-000008'),
            array('desc' => 'Te1/0/1',  'type' => 6, 'speed' => 10000000000, 'mac' => '00abcd-000021'), // 10G
            array('desc' => 'Te1/0/2',  'type' => 6, 'speed' => 10000000000, 'mac' => '00abcd-000022'), // 10G
            array('desc' => 'Vlan1',    'type' => 53, 'speed' => 1000000000, 'mac' => '00abcd-000031'), // SVI
            array('desc' => 'Vlan100',  'type' => 53, 'speed' => 1000000000, 'mac' => '00abcd-000032'),
        ),
        'ipAddr' => array(
            array('addr' => '10.10.2.2', 'mask' => '255.255.255.0', 'ifIndex' => 11), // Vlan1
            array('addr' => '10.10.20.2', 'mask' => '255.255.255.0', 'ifIndex' => 12), // Vlan100
        ),
        'lldpRem' => array(
            array('port' => 1, 'chassisId' => 'hp-sw01',  'portId' => '1',  'sysName' => 'hp-sw-core-01',  'portDesc' => 'to-hp-core-01'),
            array('port' => 9, 'chassisId' => 'h3c-5700', 'portId' => 'GE0/0/24','sysName' => 'huawei-sw-edge-01','portDesc' => 'to-huawei-edge-01'),
            array('port' => 10, 'chassisId' => 'c84c-cat2','portId' => 'Te1/0/2', 'sysName' => 'cisco-sw-core-02','portDesc' => 'to-cisco-core-02'),
        ),
    ),

    // ============== 华为 S5700-28C-HI 交换机 ==============
    'huawei' => array(
        'ip'   => '127.0.0.4',
        'port' => 11611,
        'v3'   => $v3Creds,
        'ssh'  => array('port' => 2222, 'user' => 'admin', 'pass' => 'admin'),
        'sysObjectID' => '.1.3.6.1.4.1.2011.2.23.1.2.27',  // huawei.s5700-28c-hi
        'sysDescr' => "Huawei Versatile Routing Platform Software\nVRP (R) Software, Version 8.180 (S5700 V200R019C10)\nCopyright (C) 2007-2023 Huawei Technologies Co., Ltd.\nHUAWEI S5700-28C-HI-24S\nuptime is 143 days, 8 hours, 14 minutes",
        'sysName' => 'huawei-sw-edge-01',
        'sysContact' => 'noc@example.com',
        'sysLocation' => '机房 C - 机柜 5 - U20',
        'vendor' => 'huawei',
        'hardware' => 'S5700-28C-HI-24S',
        'os' => 'VRP V200R019C10',
        'serial' => 'CNBE12345678',
        'interfaces' => array(
            array('desc' => 'GigabitEthernet0/0/1',  'type' => 6, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-01'),
            array('desc' => 'GigabitEthernet0/0/2',  'type' => 6, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-02'),
            array('desc' => 'GigabitEthernet0/0/3',  'type' => 6, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-03'),
            array('desc' => 'GigabitEthernet0/0/4',  'type' => 6, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-04'),
            array('desc' => 'GigabitEthernet0/0/5',  'type' => 6, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-05'),
            array('desc' => 'GigabitEthernet0/0/6',  'type' => 6, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-06'),
            array('desc' => 'GigabitEthernet0/0/7',  'type' => 6, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-07'),
            array('desc' => 'GigabitEthernet0/0/8',  'type' => 6, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-08'),
            array('desc' => 'XGigabitEthernet0/0/1', 'type' => 6, 'speed' => 10000000000, 'mac' => '4c1f-cc-00-21'), // 10G
            array('desc' => 'XGigabitEthernet0/0/2', 'type' => 6, 'speed' => 10000000000, 'mac' => '4c1f-cc-00-22'),
            array('desc' => 'Vlanif1',              'type' => 53, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-31'),
            array('desc' => 'Vlanif100',            'type' => 53, 'speed' => 1000000000, 'mac' => '4c1f-cc-00-32'),
        ),
        'ipAddr' => array(
            array('addr' => '10.10.3.2', 'mask' => '255.255.255.0', 'ifIndex' => 11), // Vlanif1
            array('addr' => '10.10.30.2', 'mask' => '255.255.255.0', 'ifIndex' => 12), // Vlanif100
        ),
        'lldpRem' => array(
            array('port' => 1, 'chassisId' => 'hp-sw01',  'portId' => '2',  'sysName' => 'hp-sw-core-01',  'portDesc' => 'to-hp-core-01'),
            array('port' => 9, 'chassisId' => 'c84c-cat2', 'portId' => 'Te1/0/1','sysName' => 'cisco-sw-core-01','portDesc' => 'to-cisco-core-01'),
        ),
    ),
);
