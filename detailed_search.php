<?php
function searchDir($dir) {
    $items = glob("$dir/*");
    foreach ($items as $item) {
        if (is_dir($item)) {
            searchDir($item);
        } else if (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
            $c = file_get_contents($item);
            if (stripos($c, 'stock_requests') !== false) {
                echo $item . "\n";
                // find line with INSERT or UPDATE or FORM
                $lines = explode("\n", $c);
                foreach ($lines as $i => $l) {
                    if (stripos($l, 'insert') !== false || stripos($l, 'stock_requests') !== false) {
                        if (stripos($l, 'insert') !== false || stripos($l, 'form') !== false || stripos($l, 'post') !== false || stripos($l, 'action') !== false) {
                            echo "   Line " . ($i+1) . ": " . trim($l) . "\n";
                        }
                    }
                }
            }
        }
    }
}
searchDir(__DIR__ . '/public');
searchDir(__DIR__ . '/backend');
