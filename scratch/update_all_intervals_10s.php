<?php
$files = glob("C:/xampp/htdocs/group31petron_system_official4/public/*.php");
$updated = [];

foreach ($files as $f) {
    $content = file_get_contents($f);
    $orig = $content;

    // Replace 15000ms / 15s auto-refresh intervals with 10000ms (10 seconds)
    // Matches: setInterval(autoRefresh..., 15000) or setInterval(refresh..., 15000)
    $content = preg_replace('/(setInterval\s*\(\s*(?:autoRefresh[A-Za-z0-9_]*|refresh[A-Za-z0-9_]*|check[A-Za-z0-9_]*)\s*,\s*)15000(\s*\))/i', '${1}10000${2}', $content);

    if ($content !== $orig) {
        file_put_contents($f, $content);
        $updated[] = basename($f);
    }
}

echo "Updated auto-refresh to 10 seconds (10000ms) in " . count($updated) . " files:\n";
foreach ($updated as $u) {
    echo " - $u\n";
}
