<?php
$lines = explode("\n", file_get_contents('public/manager_stock_request_review.php'));
foreach ($lines as $i => $l) {
    if (stripos($l, 'stock request') !== false) {
        echo "Line " . ($i+1) . ": " . trim($l) . "\n";
    }
}
