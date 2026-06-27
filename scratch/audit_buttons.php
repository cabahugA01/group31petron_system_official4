<?php
/**
 * Action Button Audit Script
 * Checks all onclick functions, modals, links, and forms across transaction oversight files.
 */

$files = [
    'public/admin_transaction_adjustments.php',
    'public/manager_transaction_monitoring.php',
    'public/voided_transactions.php',
    'public/manager_voided_transactions.php',
    'public/admin_voided_transactions.php'
];

echo "=== AUDITING ACTION BUTTONS AND MODAL HANDLERS ===\n\n";

foreach ($files as $f) {
    $path = __DIR__ . '/../' . $f;
    if (!file_exists($path)) continue;
    $content = file_get_contents($path);
    
    echo "--- FILE: $f ---\n";
    
    // Check for buttons / links with onclick
    preg_match_all('/<(button|a)[^>]*onclick=["\']([^"\']+)["\'][^>]*>(.*?)<\/\1>/is', $content, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $tag = $m[1];
        $click = $m[2];
        $text = strip_tags($m[3]);
        echo "  [Button/Link] Text: '" . trim($text) . "' | OnClick: " . substr($click, 0, 80) . "\n";
    }
    
    // Check for JavaScript functions defined
    preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\(/i', $content, $fn_matches);
    if (!empty($fn_matches[1])) {
        echo "  [JS Functions defined]: " . implode(', ', array_unique($fn_matches[1])) . "\n";
    }
    
    // Check for export links
    preg_match_all('/href=["\']([^"\']*export[^"\']*)["\']/i', $content, $exp_matches);
    if (!empty($exp_matches[1])) {
        echo "  [Export Links]: " . implode(', ', array_unique($exp_matches[1])) . "\n";
    }
    
    echo "\n";
}
