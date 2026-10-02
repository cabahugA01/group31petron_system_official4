<?php
$lines = file('fuel_management.php');
$matches = [];
foreach ($lines as $num => $line) {
    if (stripos($line, 'fuel_pumps') !== false || stripos($line, 'pumps') !== false) {
        $matches[] = ($num + 1) . ": " . trim($line);
    }
}
echo "<pre>\n";
echo "Found " . count($matches) . " lines in fuel_management.php:\n";
echo htmlspecialchars(implode("\n", $matches));
echo "\n</pre>";
