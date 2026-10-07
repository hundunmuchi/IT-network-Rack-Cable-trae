<?php
foreach (get_defined_functions()['internal'] as $f) {
    if (strpos($f, 'snmp') !== false) echo $f, "\n";
}
