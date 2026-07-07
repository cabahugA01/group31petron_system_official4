<?php
$lines = file('c:/xampp/htdocs/group31petron_system_official4/partials/header.php');
$checks = [
    'petronToggleSidebar',
    'petronToggleNotif',
    'petronToggleProfile',
    'petronToggleTheme',
    'notifBell.addEventListener',      // should NOT exist
    'themeToggle.addEventListener',    // should NOT exist
    'profileAccess.addEventListener',  // should NOT exist
    'petronDiagClickTracker',          // should NOT exist
    'onclick="petronToggleSidebar',
    'onclick="petronToggleNotif',
    'onclick="petronToggleTheme',
    'onclick="petronToggleProfile',
];
foreach ($checks as $needle) {
    $found = array_filter($lines, fn($l) => strpos($l, $needle) !== false);
    $count = count($found);
    echo ($count > 0 ? "FOUND($count)" : "NOT FOUND") . " : $needle\n";
    if ($count > 0) {
        foreach (array_keys($found) as $lineNum) {
            echo "  Line " . ($lineNum+1) . ": " . trim($lines[$lineNum]) . "\n";
        }
    }
}
