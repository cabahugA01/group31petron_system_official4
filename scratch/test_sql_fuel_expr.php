<?php
require_once __DIR__ . '/../public/db_connect.php';

$sql_expr = "CASE 
    WHEN UPPER(ft.fuel_type) LIKE '%TURBO%' THEN 'Turbo Diesel'
    WHEN UPPER(ft.fuel_type) LIKE '%DIESEL%' THEN 'Diesel'
    WHEN UPPER(ft.fuel_type) LIKE '%XCS%' THEN 'XCS Plus'
    WHEN UPPER(ft.fuel_type) LIKE '%XTRA%' OR UPPER(ft.fuel_type) LIKE '%UNL%' THEN 'XTR Advance'
    WHEN UPPER(ft.fuel_type) LIKE '%KEROSENE%' THEN 'Kerosene'
    ELSE ft.fuel_type
END";

echo "=== TEST 1: FUEL SUMMARY (5 CATEGORIES) ===\n";
$stmt = $pdo->prepare("SELECT 
        {$sql_expr} as fuel_type,
        COUNT(DISTINCT ft.pump_id) as ugt_count,
        SUM(COALESCE(ft.liters_sold, 0)) as total_volume,
        MAX(ft.price_per_liter) as avg_price,
        SUM(COALESCE(ft.total_amount, 0)) as total_sales
    FROM fuel_transactions ft
    WHERE station_id = 1253
    GROUP BY fuel_type
    ORDER BY total_sales DESC");
$stmt->execute();
$summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($summary);

echo "=== TEST 2: VARIANCE CHECK (5 CATEGORIES) ===\n";
$stmt2 = $pdo->prepare("SELECT 
        {$sql_expr} as fuel_type,
        SUM(COALESCE(ft.liters_sold,0) * COALESCE(ft.price_per_liter,0)) as expected_sales,
        SUM(COALESCE(ft.total_amount,0)) as recorded_sales,
        ROUND(SUM(COALESCE(ft.total_amount,0)) - SUM(COALESCE(ft.liters_sold,0) * COALESCE(ft.price_per_liter,0)), 2) as variance
    FROM fuel_transactions ft
    WHERE station_id = 1253
    GROUP BY fuel_type
    ORDER BY fuel_type ASC");
$stmt2->execute();
$variance = $stmt2->fetchAll(PDO::FETCH_ASSOC);
print_r($variance);

echo "=== TEST 3: DISTINCT FUEL TYPES FOR FILTER DROPDOWN ===\n";
$stmt3 = $pdo->prepare("SELECT DISTINCT {$sql_expr} as fuel_type FROM fuel_transactions ft WHERE station_id = 1253 ORDER BY fuel_type ASC");
$stmt3->execute();
$ft_dropdown = $stmt3->fetchAll(PDO::FETCH_COLUMN);
print_r($ft_dropdown);
