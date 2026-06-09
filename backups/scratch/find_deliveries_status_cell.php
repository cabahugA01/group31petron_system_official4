<?php
$file = __DIR__ . '/public/manager_fuel_management_complete.php';
$lines = file($file);
for ($i = 1470; $i <= 1550; $i++) {
    if (isset($lines[$i])) {
        if (stripos($lines[$i], 'status') !== false || stripos($lines[$i], 'badge') !== false || stripos($lines[$i], 'tag-') !== false) {
            echo ($i + 1) . ": " . trim($lines[$i]) . "\n";
        }
    }
}
