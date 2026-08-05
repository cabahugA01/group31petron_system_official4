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

echo "=== TEST WITH SUBQUERY FOR FUEL SUMMARY ===\n";
$stmt = $pdo->prepare("SELECT 
        cat.norm_fuel_type as fuel_type,
        COUNT(DISTINCT cat.pump_id) as ugt_count,
        SUM(COALESCE(cat.liters_sold, 0)) as total_volume,
        MAX(cat.price_per_liter) as avg_price,
        SUM(COALESCE(cat.total_amount, 0)) as total_sales
    FROM (
        SELECT ft.pump_id, ft.liters_sold, ft.price_per_liter, ft.total_amount,
               {$sql_expr} as norm_fuel_type
        FROM fuel_transactions ft
        WHERE station_id = 1253
    ) cat
    GROUP BY cat.norm_fuel_type
    ORDER BY total_sales DESC");
$stmt->execute();
$summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($summary);

echo "=== TEST WITH SUBQUERY FOR VARIANCE CHECK ===\n";
$stmt2 = $pdo->prepare("SELECT 
        cat.norm_fuel_type as fuel_type,
        SUM(COALESCE(cat.liters_sold,0) * COALESCE(cat.price_per_liter,0)) as expected_sales,
        SUM(COALESCE(cat.total_amount,0)) as recorded_sales,
        ROUND(SUM(COALESCE(cat.total_amount,0)) - SUM(COALESCE(cat.liters_sold,0) * COALESCE(cat.price_per_liter,0)), 2) as variance
    FROM (
        SELECT ft.liters_sold, ft.price_per_liter, ft.total_amount,
               {$sql_expr} as norm_fuel_type
        FROM fuel_transactions ft
        WHERE station_id = 1253
    ) cat
    GROUP BY cat.norm_fuel_type
    ORDER BY cat.norm_fuel_type ASC");
$stmt2->execute();
$variance = $stmt2->fetchAll(PDO::FETCH_ASSOC);
print_r($variance);
