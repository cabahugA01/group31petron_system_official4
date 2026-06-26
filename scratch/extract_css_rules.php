<?php
$content = file_get_contents(__DIR__ . '/../public/staff_transactions_hub.php');
preg_match_all('/<style>(.*?)<\/style>/is', $content, $matches);
foreach ($matches[1] as $idx => $styleBlock) {
    echo "=== Style Block " . ($idx + 1) . " ===\n";
    $lines = explode("\n", $styleBlock);
    foreach ($lines as $lineNum => $line) {
        if (preg_match('/\bbutton\b/i', $line) || preg_match('/\bcart\b/i', $line)) {
            echo "Line " . ($lineNum + 1) . ": " . trim($line) . "\n";
        }
    }
}
