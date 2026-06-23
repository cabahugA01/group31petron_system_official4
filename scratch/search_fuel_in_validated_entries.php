<?php
$content = file_get_contents('c:\xampp\htdocs\group31petron_system_official4\public\admin_validated_entries.php');
$lines = explode("\n", $content);
foreach ($lines as $idx => $line) {
    if (stripos($line, 'fuel') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
