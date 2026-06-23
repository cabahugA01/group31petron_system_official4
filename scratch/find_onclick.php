<?php
$content = file_get_contents('c:\xampp\htdocs\group31petron_system_official4\public\admin_transactions_oversight.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (stripos($line, 'atoOpen') !== false || stripos($line, 'onclick') !== false) {
        echo "Line " . ($i+1) . ": " . trim($line) . "\n";
    }
}
