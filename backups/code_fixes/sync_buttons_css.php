<?php
$files = [
    'public/manager_fuel_transactions.php',
    'public/manager_fuel_deliveries.php',
    'public/manager_fuel_adjustments.php',
    'public/manager_fuel_pump_master.php'
];

$cssPatch = <<<CSS
.jo-act-btn { padding:5px 10px !important; border-radius:4px !important; font-size:12px !important; font-weight:600 !important; border:none !important; cursor:pointer !important; display:inline-flex !important; align-items:center !important; gap:4px !important; color:#fff !important; width:100% !important; justify-content:center !important; }
.jo-act-btn:hover { opacity:.88 !important; transform:none !important; }
.approve-btn { background:#28a745 !important; color:#fff !important; }
.reject-btn { background:#dc3545 !important; color:#fff !important; }
.adjust-btn { background:#002F6C !important; color:#fff !important; }
.view-btn { background:#6c757d !important; color:#fff !important; }
CSS;

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    
    // Replace the old jo-act-btn styles with the new exact match
    $c = preg_replace('/\.jo-act-btn\s*\{.*?\}/is', '', $c);
    $c = preg_replace('/\.approve-btn\s*\{.*?\}/is', '', $c);
    $c = preg_replace('/\.approve-btn:hover\s*\{.*?\}/is', '', $c);
    $c = preg_replace('/\.reject-btn\s*\{.*?\}/is', '', $c);
    $c = preg_replace('/\.reject-btn:hover\s*\{.*?\}/is', '', $c);
    $c = preg_replace('/\.adjust-btn\s*\{.*?\}/is', '', $c);
    $c = preg_replace('/\.adjust-btn:hover\s*\{.*?\}/is', '', $c);
    $c = preg_replace('/\.view-btn\s*\{.*?\}/is', '', $c);
    $c = preg_replace('/\.view-btn:hover\s*\{.*?\}/is', '', $c);
    
    $c = str_replace('/* Job Order Style Action Buttons */', "/* Job Order Style Action Buttons */\n" . $cssPatch, $c);
    
    file_put_contents($f, $c);
}
echo "Buttons synced to transactions style!\n";
