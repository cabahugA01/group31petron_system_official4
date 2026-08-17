<?php
$files = ['partials/sidebar.php', 'partials/header.php', 'public/manager_dashboard.php'];
foreach ($files as $f) {
    if (!file_exists($f)) { echo $f . ": NOT FOUND\n"; continue; }
    $c = file_get_contents($f);
    $found = strpos($c, 'manager_audit_trail') !== false;
    echo $f . ': ' . ($found ? 'LINKED' : 'NOT LINKED') . "\n";
    if ($found) {
        preg_match_all('/.{0,60}manager_audit_trail.{0,60}/', $c, $m);
        foreach ($m[0] as $line) echo '  -> ' . trim($line) . "\n";
    }
}
