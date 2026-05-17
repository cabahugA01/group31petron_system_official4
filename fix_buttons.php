<?php
$files = [
    'public/manager_fuel_transactions.php',
    'public/manager_fuel_deliveries.php',
    'public/manager_fuel_adjustments.php',
    'public/manager_fuel_pump_master.php'
];

$css = <<<CSS
/* Job Order Style Action Buttons */
.jo-act-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; font-size:.78rem; font-weight:600; border-radius:6px; cursor:pointer; transition:all 0.2s ease; border:1px solid transparent; text-decoration:none; white-space:nowrap; }
.approve-btn { background:#e6f4ea; color:#1e8e3e; border-color:#ceead6; }
.approve-btn:hover { background:#ceead6; transform:translateY(-1px); }
.reject-btn { background:#fce8e6; color:#d93025; border-color:#fad2cf; }
.reject-btn:hover { background:#fad2cf; transform:translateY(-1px); }
.adjust-btn { background:#e8f0fe; color:#1a73e8; border-color:#d2e3fc; }
.adjust-btn:hover { background:#d2e3fc; transform:translateY(-1px); }
.view-btn { background:#f8f9fa; color:#5f6368; border-color:#dadce0; }
.view-btn:hover { background:#f1f3f4; transform:translateY(-1px); }

CSS;

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    
    // Inject CSS
    if (strpos($c, '.jo-act-btn') === false) {
        $c = str_replace('<style>', "<style>\n" . $css, $c);
    }
    
    // Replace table action buttons (which typically have inline styles like style="font-size:.75rem;padding:4px 10px;" etc)
    // Approve
    $c = preg_replace('/class="btn btn-success"[^>]*onclick="([^"]+)"/is', 'class="jo-act-btn approve-btn" onclick="$1"', $c);
    $c = preg_replace('/class="btn btn-success"[^>]*onclick=\'([^\']+)\'/is', 'class="jo-act-btn approve-btn" onclick=\'$1\'', $c);
    
    // Reject
    $c = preg_replace('/class="btn btn-danger"[^>]*onclick="([^"]+)"/is', 'class="jo-act-btn reject-btn" onclick="$1"', $c);
    $c = preg_replace('/class="btn btn-danger"[^>]*onclick=\'([^\']+)\'/is', 'class="jo-act-btn reject-btn" onclick=\'$1\'', $c);
    
    // Adjust / Primary (Edit)
    $c = preg_replace('/class="btn btn-primary"[^>]*onclick="([^"]+)"/is', 'class="jo-act-btn adjust-btn" onclick="$1"', $c);
    $c = preg_replace('/class="btn btn-primary"[^>]*onclick=\'([^\']+)\'/is', 'class="jo-act-btn adjust-btn" onclick=\'$1\'', $c);
    
    // View / Secondary
    $c = preg_replace('/class="btn btn-secondary"[^>]*onclick="([^"]+)"/is', 'class="jo-act-btn view-btn" onclick="$1"', $c);
    $c = preg_replace('/class="btn btn-secondary"[^>]*onclick=\'([^\']+)\'/is', 'class="jo-act-btn view-btn" onclick=\'$1\'', $c);
    
    // For buttons that don't have onclick but are part of forms (modals)
    // We can also just blanket replace the classes if they appear in modal footers or action columns
    $c = preg_replace('/class="btn btn-success([^"]*)"/is', 'class="jo-act-btn approve-btn"', $c);
    $c = preg_replace('/class="btn btn-danger([^"]*)"/is', 'class="jo-act-btn reject-btn"', $c);
    
    // Specific custom inline buttons (like the Return button in deliveries)
    $c = preg_replace('/<button type="button" class="btn" style="[^"]*background:#dc3545[^"]*"[^>]*onclick="([^"]+)"/is', '<button type="button" class="jo-act-btn reject-btn" onclick="$1"', $c);
    
    // Investigate / Resolve buttons (custom colored)
    $c = preg_replace('/<button class="btn"[^>]*background:#003d82[^>]*onclick="([^"]+)"/is', '<button class="jo-act-btn adjust-btn" onclick="$1"', $c);

    file_put_contents($f, $c);
}
echo "Buttons updated!\n";
