<?php
$content = file_get_contents('c:/xampp/htdocs/group31petron_system_official4/public/admin_reports.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'h1') !== false || strpos($line, 'h2') !== false || strpos($line, 'page-title') !== false || strpos($line, 'page-header') !== false || strpos($line, 'sub') !== false) {
        if ($i > 100 && $i < 650) {
            echo ($i + 1) . ": " . trim($line) . "\n";
        }
    }
}
