<?php
$lines = file('c:/xampp/htdocs/group31petron_system_official4/assets/css/style.css');
foreach ($lines as $i => $line) {
    if (strpos($line, 'profile-dropdown') !== false || strpos($line, 'notif-dropdown') !== false) {
        echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
    }
}
