<?php
$file = __DIR__ . '/../public/admin_dashboard.php';
$content = file_get_contents($file);

// Fix: s1_end 14:00:00 -> 13:59:59 (Shift 1 ends at 2PM, so last second before is 13:59:59)
$content = str_replace(
    '$s1_end   = "$date 14:00:00";',
    '$s1_end   = "$date 13:59:59";',
    $content
);
// Also try without multiple spaces
$content = str_replace(
    '$s1_end = "$date 14:00:00";',
    '$s1_end   = "$date 13:59:59";',
    $content
);

// Fix: s2_end 22:00:00 -> 23:59:59 (Shift 2 ends at midnight)
$content = str_replace(
    '$s2_end   = "$date 22:00:00";',
    '$s2_end   = "$date 23:59:59";',
    $content
);
$content = str_replace(
    '$s2_end = "$date 22:00:00";',
    '$s2_end   = "$date 23:59:59";',
    $content
);

// Fix label comment
$content = str_replace(
    '// Shift 1 boundaries',
    '// Shift 1: 6:00 AM – 2:00 PM',
    $content
);
$content = str_replace(
    '// Shift 2 boundaries',
    '// Shift 2: 2:00 PM – 12:00 Midnight',
    $content
);

file_put_contents($file, $content);
echo "Done. Verifying...\n";

// Verify
if (strpos(file_get_contents($file), '23:59:59') !== false) {
    echo "SUCCESS: 23:59:59 found in admin_dashboard.php\n";
} else {
    echo "WARNING: 23:59:59 NOT found. Manual check needed.\n";
}
if (strpos(file_get_contents($file), '22:00:00') === false) {
    echo "SUCCESS: 22:00:00 removed.\n";
} else {
    echo "NOTE: 22:00:00 still present somewhere.\n";
}
