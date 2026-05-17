<?php
$files = [
    'public/manager_fuel_transactions.php',
    'public/manager_fuel_deliveries.php',
    'public/manager_fuel_adjustments.php',
    'public/manager_fuel_pump_master.php'
];

$cssPatch = <<<CSS

/* Anti-scroll / Compress Tables */
.data-table {
    width: 100% !important;
    table-layout: auto !important;
}
.data-table th, .data-table td {
    white-space: normal !important;
    word-break: break-word !important;
    padding: 8px 6px !important;
    font-size: .82rem !important;
}
.jo-act-btn {
    white-space: normal !important;
    padding: 4px 8px !important;
    font-size: .75rem !important;
    text-align: center;
    justify-content: center;
}
.audit-badge, .tag-open, .tag-investigate, .tag-resolved {
    white-space: normal !important;
    text-align: center;
}
CSS;

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    
    if (strpos($c, 'Anti-scroll') === false) {
        $c = str_replace('</style>', $cssPatch . "\n</style>", $c);
        file_put_contents($f, $c);
    }
}
echo "Table compression applied!\n";
