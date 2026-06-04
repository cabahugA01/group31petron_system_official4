<?php
$content = file_get_contents('c:/xampp/htdocs/group31petron_system_official4/public/staff_transactions_hub.php');

// Let's find occurrences of $_GET['section'] or section switches
preg_match_all('/section\s*===\s*[\'"]([^\'"]+)[\'"]/', $content, $matches1);
preg_match_all('/section\s*==\s*[\'"]([^\'"]+)[\'"]/', $content, $matches2);
preg_match_all('/section[\'"]\s*\]\s*===\s*[\'"]([^\'"]+)[\'"]/', $content, $matches3);
preg_match_all('/section[\'"]\s*\]\s*==\s*[\'"]([^\'"]+)[\'"]/', $content, $matches4);
preg_match_all('/[\'"]section[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $content, $matches5);

echo "Matches 1:\n";
print_r(array_unique($matches1[1] ?? []));

echo "Matches 2:\n";
print_r(array_unique($matches2[1] ?? []));

echo "Matches 3:\n";
print_r(array_unique($matches3[1] ?? []));

echo "Matches 4:\n";
print_r(array_unique($matches4[1] ?? []));

echo "Matches 5:\n";
print_r(array_unique($matches5[1] ?? []));

// Find any tab variables
preg_match_all('/active_tab\s*===\s*[\'"]([^\'"]+)[\'"]/', $content, $matchesTab);
echo "Active tabs:\n";
print_r(array_unique($matchesTab[1] ?? []));

// Also let's print first 150 lines of the file to see the PHP logic at the top
$lines = explode("\n", $content);
echo "\nFirst 150 lines:\n";
for ($i = 0; $i < min(150, count($lines)); $i++) {
    echo ($i + 1) . ": " . $lines[$i] . "\n";
}
