<?php
// Fix character encoding in staff_inventory_fuel.php
$file = 'public/staff_inventory_fuel.php';
$content = file_get_contents($file);

// Replace malformed UTF-8 em-dash (—) with simple dash
$content = str_replace("'—'", "'-'", $content);

// Also fix other common malformed characters
$content = str_replace('—', '—', $content);  // em-dash
$content = str_replace('—', '-', $content);  // fallback to simple dash
$content = str_replace('—', '"', $content);   // left double quote
$content = str_replace('—�', '"', $content);   // right double quote

// Write back
file_put_contents($file, $content);

echo "Fixed character encoding in $file\n";
echo "Malformed UTF-8 characters have been replaced.\n";
?>
