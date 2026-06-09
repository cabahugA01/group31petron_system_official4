<?php
$files = [
    'public/manager_fuel_transactions.php',
    'public/manager_fuel_deliveries.php',
    'public/manager_fuel_adjustments.php',
    'public/manager_fuel_pump_master.php'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        $content = file_get_contents($f);
        
        // Let's just remove the "Purpose:" banners.
        $content = preg_replace('/<div style="padding:12px 16px;background:#f0f4ff;border-radius:8px;border-left:4px solid <\?php echo \$colors\[\'primary\'\]; \?>;margin-bottom:18px;font-size:\.85rem;color:#444;">.*?<\/div>/s', '', $content);
        
        // Remove Export buttons (which match action="export_variance" and the generic export form)
        $content = preg_replace('/<form method="post" action="manager_fuel_management_complete\.php" style="display:flex;gap:8px;align-items:center;margin:0;">.*?<input type="hidden" name="action" value="export_variance">.*?<\/form>/is', '', $content);
        
        $content = preg_replace('/<button class="btn btn-info" id="rptExportBtn".*?<\/button>/is', '', $content);
        
        // Also remove summary cards from variance reports
        $content = preg_replace('/<div style="display:grid;grid-template-columns:repeat\(auto-fit,minmax\(150px,1fr\)\);gap:12px;margin-bottom:20px;">.*?<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/is', '', $content);

        file_put_contents($f, $content);
    }
}
echo "Cleanup complete without destroying forms!\n";
