<?php
$content = file_get_contents(__DIR__ . '/../public/manager_fuel_pump_master.php');
echo "Length: " . strlen($content) . "\n";
echo "Encoding: " . mb_detect_encoding($content) . "\n";
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'pump_master_fuel_types') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
