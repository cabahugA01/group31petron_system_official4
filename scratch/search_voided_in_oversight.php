<?php
$content = file_get_contents('c:\xampp\htdocs\group31petron_system_official4\public\admin_transactions_oversight.php');
$lines = explode("\n", $content);
foreach ($lines as $idx => $line) {
    if (stripos($line, 'void') !== false || stripos($line, 'cancel') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
