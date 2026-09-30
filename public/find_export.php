<?php
$content = file_get_contents(__DIR__ . '/admin_set_prices.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $l) {
    if (strpos($l, 'exportPricing') !== false) {
        echo "Line " . ($i + 1) . ": $l\n";
    }
}
