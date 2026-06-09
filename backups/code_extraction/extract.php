<?php
$source = file_get_contents('public/manager_fuel_management_complete.php');

function extract_section($source, $id) {
    $pattern = '/<div id="' . preg_quote($id, '/') . '".*?class="fuel-section".*?>.*?<\/div>\s*<\/div>/s';
    if (preg_match($pattern, $source, $matches)) {
        return $matches[0];
    }
    return '';
}

// Function to keep only specific sections and modals in the source
function build_page($source, $keep_ids) {
    $all_ids = ['fuel-transactions', 'daily-ops', 'fuel-deliveries', 'adjustments', 'reconciliation', 'variance-reports', 'shift-history', 'fuel-reports', 'pump-master'];
    
    $new_source = $source;
    foreach ($all_ids as $id) {
        if (!in_array($id, $keep_ids)) {
            // Find the section block
            $pattern = '/<!-- .*?-->\s*<div id="' . preg_quote($id, '/') . '".*?class="fuel-section".*?>.*?<\/div>\s*<\/div>/s';
            $new_source = preg_replace($pattern, '', $new_source);
            
            // Also try without comment if previous failed
            $pattern2 = '/<div id="' . preg_quote($id, '/') . '".*?class="fuel-section".*?>.*?<\/div>\s*<\/div>/s';
            $new_source = preg_replace($pattern2, '', $new_source);
        }
    }
    return $new_source;
}

$page1 = build_page($source, ['fuel-transactions', 'daily-ops', 'reconciliation', 'variance-reports', 'shift-history', 'fuel-reports']);
file_put_contents('public/manager_fuel_transactions.php', $page1);

$page2 = build_page($source, ['fuel-deliveries']);
file_put_contents('public/manager_fuel_deliveries.php', $page2);

$page3 = build_page($source, ['adjustments']);
file_put_contents('public/manager_fuel_adjustments.php', $page3);

$page4 = build_page($source, ['pump-master']);
file_put_contents('public/manager_fuel_pump_master.php', $page4);

echo "Extraction complete!\n";
