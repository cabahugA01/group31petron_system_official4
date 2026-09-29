<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

// Check for any Cagayan De Oro parsed into Region II or Cagayan
$cdo_check = $pdo->query("
    SELECT id, name, address, barangay, city, province, region 
    FROM stations 
    WHERE name LIKE '%Cagayan De Oro%' OR address LIKE '%Cagayan De Oro%'
")->fetchAll(PDO::FETCH_ASSOC);

// Check if any station in Region II has 'Oro'
$oro_check = $pdo->query("
    SELECT id, name, address, barangay, city, province, region 
    FROM stations 
    WHERE region = 'Region II' AND (name LIKE '%Oro%' OR address LIKE '%Oro%')
")->fetchAll(PDO::FETCH_ASSOC);

// Check any station whose region doesn't match standard region distribution
$region_summary = $pdo->query("SELECT region, COUNT(*) as count FROM stations GROUP BY region ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'cdo_stations' => $cdo_check,
    'oro_in_region_2' => $oro_check,
    'region_summary' => $region_summary
], JSON_PRETTY_PRINT);
