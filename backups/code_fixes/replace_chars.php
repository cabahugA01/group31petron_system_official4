<?php
$files = [
    'public/manager_fuel_transactions.php',
    'public/manager_fuel_deliveries.php',
    'public/manager_fuel_adjustments.php',
    'public/manager_fuel_pump_master.php'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    
    // Replace the unicode replacement character with a hyphen
    $c = str_replace("\xEF\xBF\xBD", "-", $c);
    
    // Also replace literal question marks that might have been converted differently in CP1252
    // Wait, let's only replace the literal replacement character.
    // Sometimes it's literally saved as a question mark `?` if the encoding was downgraded to ASCII.
    // Let's check if the file contains `Pump #?` or `Pump #-`.
    // We will do that with a safe str_replace.
    $c = str_replace('Pump #?', 'Pump #', $c);
    $c = str_replace('<td style="color:#bbb;font-size:.78rem;">?</td>', '<td style="color:#bbb;font-size:.78rem;">-</td>', $c);
    $c = str_replace('? NEEDS UPDATE', 'NEEDS UPDATE', $c);
    $c = str_replace('? ', '- ', $c);
    // Be very careful not to replace PHP `<?php` or `?>` or ternary operators `? :`!
    
    file_put_contents($f, $c);
}
echo "Replaced characters!\n";
