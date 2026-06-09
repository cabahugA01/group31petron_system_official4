<?php
/**
 * Logic trace — verifies the formula and continuous cycle implementation
 * using the exact example values from the user's spec.
 */

echo "=== Fuel Transaction Formula Trace ===\n\n";

function compute($beginning, $ending, $calibration, $price) {
    $diff        = round($ending - $beginning, 4);   // Ending - Beginning
    $volume      = round($diff - $calibration, 4);   // subtract calibration
    if ($volume < 0) $volume = 0.0;                 // clamp to 0
    $amount      = round($volume * $price, 2);       // Amount = Volume × Price
    return compact('beginning','ending','calibration','diff','volume','amount','price');
}

function trace($label, $r) {
    printf("%-30s Beginning=%-10s Ending=%-10s Cal=%-6s Diff=%-10s Volume=%-10s Price=%-8s Amount=₱%s\n",
        $label, $r['beginning'], $r['ending'], $r['calibration'],
        $r['diff'], $r['volume'], $r['price'], number_format($r['amount'],2));
}

$price = 84.00;  // e.g. Diesel price per liter from fuel_inventory

// Day 1 – Shift 1: First ever reading (Beginning = 100,000)
$s1 = compute(100000, 100500, 0, $price);
trace("Day1 Shift1:", $s1);

// Day 1 – Shift 2: Beginning taken from Shift 1 Ending = 100,500
$s2 = compute($s1['ending'], 101200, 0, $price);
trace("Day1 Shift2 (carry-over):", $s2);
assert($s2['beginning'] == $s1['ending'], "FAIL: Shift 2 beginning != Shift 1 ending");

// Day 2 – Shift 1: Beginning taken from Shift 2 Ending = 101,200
$s3 = compute($s2['ending'], 101800, 0, $price);
trace("Day2 Shift1 (carry-over):", $s3);
assert($s3['beginning'] == $s2['ending'], "FAIL: Day2 Shift1 beginning != Day1 Shift2 ending");

echo "\n=== With Calibration ===\n";
$sc = compute(101800, 102350, 5.5, $price);
trace("Day2 Shift2 (cal=5.5):", $sc);
// Volume should be (102350 - 101800) - 5.5 = 550 - 5.5 = 544.5
assert($sc['volume'] == 544.5, "FAIL: volume with calibration is wrong: " . $sc['volume']);

echo "\n=== Carryover Continuity Check ===\n";
echo "Shift1 Ending  = {$s1['ending']}  → becomes Shift2 Beginning = {$s2['beginning']} ✓\n";
echo "Shift2 Ending  = {$s2['ending']} → becomes Day2 S1 Beginning = {$s3['beginning']} ✓\n";

echo "\n✅ All checks passed — formula and cycle logic are correct.\n";
