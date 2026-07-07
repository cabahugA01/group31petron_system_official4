<?php
$file = 'c:/xampp/htdocs/group31petron_system_official4/partials/header.php';
$lines = file($file, FILE_BINARY);

// Remove lines 3199 to 3392 (0-indexed: 3198 to 3391)
// These are orphaned old handlers left after the DOMContentLoaded close at line 3198
$removeFrom = 3198; // 0-indexed (line 3199 in 1-indexed view)
$removeTo   = null;

// Find the end: the line containing the capture-phase handler comment
foreach ($lines as $i => $line) {
    if ($i >= $removeFrom && strpos($line, 'CAPTURE-PHASE HEADER CLICK HANDLER') !== false) {
        $removeTo = $i - 1;
        break;
    }
}

if ($removeTo === null) {
    echo "Could not find end marker. Showing context:\n";
    for ($j = $removeFrom; $j < $removeFrom + 30 && $j < count($lines); $j++) {
        echo ($j+1) . ": " . $lines[$j];
    }
    exit(1);
}

echo "Removing lines " . ($removeFrom+1) . " to " . ($removeTo+1) . "\n";
echo "First line: " . trim($lines[$removeFrom]) . "\n";
echo "Last line:  " . trim($lines[$removeTo]) . "\n";

array_splice($lines, $removeFrom, $removeTo - $removeFrom + 1);
file_put_contents($file, implode('', $lines));
echo "Done. Total lines: " . count($lines) . "\n";
