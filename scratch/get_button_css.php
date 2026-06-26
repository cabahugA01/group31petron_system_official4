<?php
$content = file_get_contents(__DIR__ . '/../public/staff_transactions_hub.php');
preg_match_all('/<style>(.*?)<\/style>/is', $content, $matches);
foreach ($matches[1] as $idx => $styleBlock) {
    // find all rules that have the word button
    preg_match_all('/([^{}]+)\{[^{}]*\bbutton\b[^{}]*\}/is', $styleBlock, $rules);
    if (!empty($rules[1])) {
        echo "=== Style Block " . ($idx + 1) . " ===\n";
        foreach ($rules[1] as $rIdx => $selector) {
            echo "Selector: " . trim($selector) . "\n";
        }
    }
    // search for any selector styling button
    preg_match_all('/([^{}]+button[^{}]+)\{[^{}]*\}/is', $styleBlock, $rules2);
    if (!empty($rules2[1])) {
        echo "=== Style Block " . ($idx + 1) . " (selectors containing button) ===\n";
        foreach ($rules2[1] as $rIdx => $selector) {
            echo "Selector: " . trim($selector) . "\n";
        }
    }
}
