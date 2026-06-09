<?php
$content = file_get_contents('c:/xampp/htdocs/group31petron_system_official4/partials/header.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'collapsed') !== false) {
        if ($i < 1500) { // checking inline style block in head
            echo ($i + 1) . ": " . trim($line) . "\n";
        }
    }
}
