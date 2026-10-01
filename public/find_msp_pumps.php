<?php
$lines = file('manager_set_prices.php');
$matches = [];
foreach ($lines as $num => $line) {
    if (stripos($line, 'pump') !== false || stripos($line, 'get_fuel_details') !== false) {
        $matches[] = ($num + 1) . ": " . trim($line);
    }
}
echo "<pre>\n";
echo "Found " . count($matches) . " matches in manager_set_prices.php:\n";
echo htmlspecialchars(implode("\n", array_slice($matches, 0, 100)));
echo "\n</pre>";
