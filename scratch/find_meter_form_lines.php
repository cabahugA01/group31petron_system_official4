<?php
$file = 'C:/xampp/htdocs/group31petron_system_official4/public/staff_transactions_hub.php';
$lines = file($file);
foreach ($lines as $i => $line) {
    if (stripos($line, 'METER READING') !== false || stripos($line, 'Encode Meter Readings') !== false || stripos($line, 'previous_reading') !== false || stripos($line, 'BEGINNING') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
?>
