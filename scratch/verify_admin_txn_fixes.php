<?php
// Quick functional test - simulates a logged-in admin session
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = ['user_id' => 1]; // pretend admin session

// Test 1: syntax check admin_validated_entries.php
$result1 = shell_exec('C:\xampp\php\php.exe -l c:\xampp\htdocs\group31petron_system_official4\public\admin_validated_entries.php 2>&1');
echo "admin_validated_entries.php: $result1\n";

// Test 2: syntax check admin_transactions_oversight.php
$result2 = shell_exec('C:\xampp\php\php.exe -l c:\xampp\htdocs\group31petron_system_official4\public\admin_transactions_oversight.php 2>&1');
echo "admin_transactions_oversight.php: $result2\n";

// Test 3: Verify the IIFE pattern is in the oversight file
$oversight = file_get_contents('c:\xampp\htdocs\group31petron_system_official4\public\admin_transactions_oversight.php');
$has_iife = strpos($oversight, '(function() {') !== false;
$has_origClose = strpos($oversight, 'var _origClose  = atoCloseModal;') !== false;
$has_broken = strpos($oversight, 'const originalAtoCloseModal = window.atoCloseModal;') !== false;
echo "IIFE pattern present: " . ($has_iife ? 'YES' : 'NO') . "\n";
echo "Correct _origClose ref: " . ($has_origClose ? 'YES' : 'NO') . "\n";
echo "Broken window.atoCloseModal override removed: " . ($has_broken ? 'STILL THERE (bad)' : 'GONE (good)') . "\n";

// Test 4: Verify admin_validated_entries.php uses correct schema
$validated = file_get_contents('c:\xampp\htdocs\group31petron_system_official4\public\admin_validated_entries.php');
$uses_mt = strpos($validated, 'merchandise_transactions') !== false;
$uses_jo = strpos($validated, 'job_orders') !== false;
$no_old_sales = strpos($validated, 'FROM sales') === false;
$no_old_fuel = strpos($validated, 'FROM fuel_transactions') === false;
echo "Uses merchandise_transactions: " . ($uses_mt ? 'YES' : 'NO') . "\n";
echo "Uses job_orders: " . ($uses_jo ? 'YES' : 'NO') . "\n";
echo "Old 'FROM sales' removed: " . ($no_old_sales ? 'YES (good)' : 'STILL THERE (bad)') . "\n";
echo "Old 'FROM fuel_transactions' removed: " . ($no_old_fuel ? 'YES (good)' : 'STILL THERE (bad)') . "\n";
