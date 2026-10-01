<?php
$lines = file('admin_set_prices.php');
$matches = [];
foreach ($lines as $num => $line) {
    if (stripos($line, 'pump') !== false) {
        $matches[] = ($num + 1) . ": " . trim($line);
    }
}
echo "<pre>\n";
echo "Found " . count($matches) . " lines with 'pump':\n";
echo htmlspecialchars(implode("\n", array_slice($matches, 0, 100)));
echo "\n</pre>";
