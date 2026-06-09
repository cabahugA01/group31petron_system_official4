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
    
    // Explicitly replace any remaining replacement characters
    $c = str_replace("\xEF\xBF\xBD", "-", $c);
    
    // Replace standalone literal question marks used as fallbacks in HTML
    $c = preg_replace('/>\?<\/td>/is', '>-</td>', $c);
    $c = preg_replace('/>\?<\/span>/is', '>-</span>', $c);
    $c = preg_replace('/>\?<\/div>/is', '>-</div>', $c);
    $c = preg_replace('/\'\?\'/is', "'-'", $c); // PHP ternary fallbacks like ?? '?'
    $c = str_replace("Pump #-", "Pump #", $c); // Clean up if it was Pump #?
    $c = str_replace("Pump #?", "Pump #", $c);
    
    // Check if it's the specific image 1 "VALIDATED BY / AT" column
    // The previous script had replaced:
    // <td><?php echo !empty($h['validated_by_name']) ? ... : '-'; ? ></td>
    
    // Check for weird php echoes that output a question mark
    $c = str_replace("echo '?';", "echo '-';", $c);
    
    // Also, make absolutely sure that for Deliveries, it's not hardcoded.
    // The user mentioned "dapat dili jud hardcoded ang fuel transaction ni manager"
    // Wait, what does the user mean by hardcoded?
    // In `manager_fuel_transactions.php`, are the tables fetching from the DB properly?
    // Yes, they do `$stmt->execute([$station_id])`.
    // Wait, are there any hardcoded rows in the HTML for Fuel Transactions?
    
    file_put_contents($f, $c);
}
echo "Cleaned up all ? marks!\n";
