<?php
// Remove orphaned lines 3131-3183 (0-indexed: 3130-3182)
$file = 'C:/xampp/htdocs/group31petron_system_official4/public/staff_fuel_sales_summary.php';
$lines = file($file);
$total = count($lines);

$keep = array_merge(
    array_slice($lines, 0, 3130),     // lines 1-3130 (keep)
    array_slice($lines, 3183)          // lines 3184+ (keep)
);

file_put_contents($file, implode('', $keep));
echo "Done. Was: $total lines. Now: " . count($keep) . " lines. Removed: " . ($total - count($keep)) . "\n";
