<?php
$content = file_get_contents('public/staff_fuel_deliveries.php');
echo "Length: " . strlen($content) . PHP_EOL;
echo "Count of 'direction': " . substr_count(strtolower($content), 'direction') . PHP_EOL;
echo "Count of 'directions': " . substr_count(strtolower($content), 'directions') . PHP_EOL;
echo "Count of 'banner': " . substr_count(strtolower($content), 'banner') . PHP_EOL;
echo "Count of 'guideline': " . substr_count(strtolower($content), 'guideline') . PHP_EOL;
echo "Count of 'guide': " . substr_count(strtolower($content), 'guide') . PHP_EOL;
