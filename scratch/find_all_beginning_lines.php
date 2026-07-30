<?php
$file = 'C:/xampp/htdocs/group31petron_system_official4/public/staff_transactions_hub.php';
$lines = file($file);
foreach ($lines as $i => $line) {
    if (strpos($line, 'beginning') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
?>
