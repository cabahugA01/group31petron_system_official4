<?php
header('Content-Type: application/json');

$root = dirname(__DIR__);
$dirs = ['backend', 'public', 'partials'];

// List of tables that MUST be isolated by station_id
$isolated_tables = [
    'pending_price_approvals',
    'fuel_inventory',
    'fuel_pricing',
    'fuel_price_history',
    'fuel_adjustments',
    'fuel_transactions',
    'fuel_pumps',
    'nozzles',
    'station_inventory',
    'inventory',
    'merchandise_transactions',
    'deliveries_oversight',
    'purchase_orders',
    'job_orders',
    'customers',
    'variance_alerts',
    'fuel_variance_reports',
    'labor_sessions'
];

$findings = [];

foreach ($dirs as $d) {
    $dirPath = $root . DIRECTORY_SEPARATOR . $d;
    if (!is_dir($dirPath)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath));
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $path = $file->getPathname();
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($ext !== 'php') continue;

        $content = file_get_contents($path);
        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);

        // Check if file is superadmin-only (superadmin is allowed to view across stations)
        $is_superadmin_only = (strpos($rel, 'superadmin_') !== false || strpos($rel, 'developer_') !== false);

        foreach ($isolated_tables as $table) {
            // Match SQL queries on this table
            // Pattern like: SELECT ... FROM table ...
            $pattern = '/(?:SELECT|UPDATE|DELETE)\s+[^;]{1,300}\bFROM\s+`?' . $table . '`?\b(?:\s+AS\s+\w+)?([^;]{0,300})/i';
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $idx => $m) {
                    $matchedSql = $m[0];
                    $afterFrom = $matches[1][$idx][0];
                    
                    // Check if station_id is mentioned in the WHERE clause or anywhere in this query snippet
                    if (stripos($matchedSql, 'station_id') === false) {
                        // Exclude SHOW, CREATE, DROP, ALTER
                        if (stripos($matchedSql, 'CREATE TABLE') !== false || stripos($matchedSql, 'SHOW') !== false) continue;
                        // Exclude if file is superadmin only or developer only
                        $findings[] = [
                            'file' => $rel,
                            'table' => $table,
                            'sql_snippet' => trim(preg_replace('/\s+/', ' ', $matchedSql))
                        ];
                    }
                }
            }
        }
    }
}

echo json_encode([
    'total_findings' => count($findings),
    'findings' => array_slice($findings, 0, 50)
], JSON_PRETTY_PRINT);
