<?php
/**
 * FUEL STOCK CALCULATIONS
 * 
 * Provides stock calculation functions for:
 * 1. Daily beginning stock calculation
 * 2. Daily stock reconciliation (theoretical vs actual)
 * 3. Variance analysis
 * 4. Stock trend reporting
 */

/**
 * Calculate daily beginning stock for a fuel product
 * 
 * Beginning Stock = Previous Day Ending Stock + All Finalized Deliveries (if today)
 * OR = Previous Day Ending Stock (if historical date)
 * 
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @param int $product_id Fuel product ID
 * @param string $date YYYY-MM-DD
 * @return array [beginning_stock, previous_day_ending, deliveries_today]
 */
function calculate_daily_beginning_stock($pdo, $station_id, $product_id, $date) {
    try {
        // Get previous day's ending stock
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(daily_ending_stock, 0) as previous_ending
            FROM fuel_daily_stock_history
            WHERE station_id = ? 
            AND product_id = ?
            AND stock_date = DATE_SUB(?, INTERVAL 1 DAY)
            LIMIT 1
        ");
        
        $stmt->execute([$station_id, $product_id, $date]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC);
        $previous_ending = $previous['previous_ending'] ?? 0;
        
        // If no previous history, get current stock as baseline
        if ($previous_ending == 0) {
            $stmt = $pdo->prepare("
                SELECT stock_level FROM fuel_inventory
                WHERE station_id = ? AND product_id = ?
            ");
            $stmt->execute([$station_id, $product_id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            $previous_ending = $current['stock_level'] ?? 0;
        }
        
        // Get all finalized deliveries for this date
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(delivery_liters), 0) as total_deliveries
            FROM fuel_deliveries
            WHERE station_id = ? 
            AND product_id_ref = ?
            AND DATE(finalized_at) = ?
            AND status = 'Finalized'
        ");
        
        $stmt->execute([$station_id, $product_id, $date]);
        $deliveries = $stmt->fetch(PDO::FETCH_ASSOC);
        $deliveries_today = $deliveries['total_deliveries'] ?? 0;
        
        $beginning_stock = $previous_ending + $deliveries_today;
        
        return [
            'beginning_stock' => $beginning_stock,
            'previous_day_ending' => $previous_ending,
            'deliveries_today' => $deliveries_today,
            'calculation_date' => $date
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating daily beginning stock: " . $e->getMessage());
        return [
            'beginning_stock' => 0,
            'previous_day_ending' => 0,
            'deliveries_today' => 0,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Calculate daily reconciliation (theoretical vs actual stock)
 * 
 * Theoretical Stock = Beginning Stock + Deliveries - Sales (via pump readings)
 * Actual Stock = Current fuel_inventory stock level
 * Variance = Theoretical - Actual (can be positive or negative)
 * 
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @param int $product_id Fuel product ID
 * @param string $date YYYY-MM-DD
 * @return array Complete reconciliation data
 */
function calculate_daily_reconciliation($pdo, $station_id, $product_id, $date) {
    try {
        // Get beginning stock
        $beginning = calculate_daily_beginning_stock($pdo, $station_id, $product_id, $date);
        $beginning_stock = $beginning['beginning_stock'];
        
        // Get approved deliveries for today (in addition to finalized ones used in beginning calc)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(delivery_liters), 0) as total
            FROM fuel_deliveries
            WHERE station_id = ? 
            AND DATE(finalized_at) = ?
            AND status = 'Finalized'
        ");
        $stmt->execute([$station_id, $date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $deliveries_today = $result['total'] ?? 0;
        
        // Get approved pump readings (sales) for today
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(fdr.current_reading - fdr.previous_reading), 0) as total_sales_liters
            FROM fuel_daily_readings fdr
            JOIN fuel_pumps fp ON fdr.fuel_station_id = fp.id
            JOIN products p ON fp.fuel_type_id = p.id
            WHERE fdr.station_id = ? 
            AND p.id = ?
            AND DATE(fdr.reading_date) = ?
            AND fdr.status = 'Approved'
        ");
        $stmt->execute([$station_id, $product_id, $date]);
        $sales = $stmt->fetch(PDO::FETCH_ASSOC);
        $sales_liters = $sales['total_sales_liters'] ?? 0;
        
        // Get approved adjustments for today
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(liters), 0) as total_adjustment
            FROM fuel_adjustments
            WHERE station_id = ? 
            AND product_id = ?
            AND DATE(DATE(approved_at)) = ?
            AND status = 'Approved'
        ");
        $stmt->execute([$station_id, $product_id, $date]);
        $adjustments = $stmt->fetch(PDO::FETCH_ASSOC);
        $adjustments_liters = $adjustments['total_adjustment'] ?? 0;
        
        // Calculate theoretical stock
        $theoretical_stock = $beginning_stock + $deliveries_today - $sales_liters + $adjustments_liters;
        
        // Get actual current stock
        $stmt = $pdo->prepare("
            SELECT stock_level FROM fuel_inventory
            WHERE station_id = ? AND product_id = ?
        ");
        $stmt->execute([$station_id, $product_id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $actual_stock = $current['stock_level'] ?? 0;
        
        // Calculate variance
        $variance = $theoretical_stock - $actual_stock;
        $variance_percentage = $actual_stock != 0 ? (abs($variance) / $actual_stock) * 100 : 0;
        
        return [
            'date' => $date,
            'station_id' => $station_id,
            'product_id' => $product_id,
            'beginning_stock' => $beginning_stock,
            'deliveries_received' => $deliveries_today,
            'sales_liters' => $sales_liters,
            'adjustments_liters' => $adjustments_liters,
            'theoretical_stock' => $theoretical_stock,
            'actual_stock' => $actual_stock,
            'variance_liters' => $variance,
            'variance_percentage' => round($variance_percentage, 2),
            'variance_status' => abs($variance) < 1 ? 'OK' : (abs($variance) < 5 ? 'MINOR' : 'MAJOR'),
            'reconciliation_ok' => abs($variance) < 1
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating daily reconciliation: " . $e->getMessage());
        return [
            'error' => $e->getMessage(),
            'reconciliation_ok' => false
        ];
    }
}

/**
 * Get historical reconciliation data for a date range
 * 
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @param int $product_id Fuel product ID
 * @param string $start_date YYYY-MM-DD
 * @param string $end_date YYYY-MM-DD
 * @return array List of daily reconciliations
 */
function get_reconciliation_history($pdo, $station_id, $product_id, $start_date, $end_date) {
    try {
        $reconciliations = [];
        $current_date = strtotime($start_date);
        $end_timestamp = strtotime($end_date);
        
        while ($current_date <= $end_timestamp) {
            $date = date('Y-m-d', $current_date);
            $recon = calculate_daily_reconciliation($pdo, $station_id, $product_id, $date);
            
            if (!isset($recon['error'])) {
                $reconciliations[] = $recon;
            }
            
            $current_date = strtotime('+1 day', $current_date);
        }
        
        return $reconciliations;
    } catch (Exception $e) {
        error_log("Error getting reconciliation history: " . $e->getMessage());
        return [];
    }
}

/**
 * Get variance summary for analysis
 * Shows which days had variances and their magnitude
 * 
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @param int $product_id Fuel product ID (optional, null for all)
 * @param int $days_back How many days to analyze (default 30)
 * @return array Variance analysis summary
 */
function get_variance_summary($pdo, $station_id, $product_id = null, $days_back = 30) {
    try {
        $start_date = date('Y-m-d', strtotime("-{$days_back} days"));
        $end_date = date('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT 
                DATE(dsr.stock_date) as date,
                dsr.product_id,
                p.name as fuel_name,
                dsr.beginning_stock,
                dsr.sales_liters,
                dsr.deliveries_received,
                dsr.adjustments_liters,
                dsr.theoretical_stock,
                dsr.actual_stock,
                (dsr.theoretical_stock - dsr.actual_stock) as variance_liters,
                ROUND(ABS((dsr.theoretical_stock - dsr.actual_stock) / dsr.actual_stock * 100), 2) as variance_percentage
            FROM fuel_daily_stock_history dsr
            JOIN products p ON dsr.product_id = p.id
            WHERE dsr.station_id = ?
            AND DATE(dsr.stock_date) BETWEEN ? AND ?
        ");
        
        $params = [$station_id, $start_date, $end_date];
        
        if ($product_id) {
            $stmt = $pdo->prepare(str_replace('AND DATE(dsr.stock_date)', "AND dsr.product_id = ? AND DATE(dsr.stock_date)", $stmt->queryString));
            array_splice($params, 1, 0, [$product_id]);
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                DATE(stock_date) as date,
                product_id,
                p.name as fuel_name,
                beginning_stock,
                sales_liters,
                deliveries_received,
                adjustments_liters,
                theoretical_stock,
                actual_stock,
                (theoretical_stock - actual_stock) as variance_liters,
                ROUND(ABS((theoretical_stock - actual_stock) / actual_stock * 100), 2) as variance_percentage
            FROM fuel_daily_stock_history dsr
            JOIN products p ON dsr.product_id = p.id
            WHERE dsr.station_id = ? 
            AND DATE(dsr.stock_date) BETWEEN ? AND ?
        ");
        
        if ($product_id) {
            $stmt = $pdo->prepare("
                SELECT 
                    DATE(stock_date) as date,
                    product_id,
                    p.name as fuel_name,
                    beginning_stock,
                    sales_liters,
                    deliveries_received,
                    adjustments_liters,
                    theoretical_stock,
                    actual_stock,
                    (theoretical_stock - actual_stock) as variance_liters,
                    ROUND(ABS((theoretical_stock - actual_stock) / actual_stock * 100), 2) as variance_percentage
                FROM fuel_daily_stock_history dsr
                JOIN products p ON dsr.product_id = p.id
                WHERE dsr.station_id = ? 
                AND dsr.product_id = ?
                AND DATE(dsr.stock_date) BETWEEN ? AND ?
            ");
            $stmt->execute([$station_id, $product_id, $start_date, $end_date]);
        } else {
            $stmt->execute([$station_id, $start_date, $end_date]);
        }
        
        $variances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate summary statistics
        $total_records = count($variances);
        $ok_count = 0;
        $minor_variance_count = 0;
        $major_variance_count = 0;
        
        foreach ($variances as $record) {
            $abs_variance = abs($record['variance_liters']);
            if ($abs_variance < 1) {
                $ok_count++;
            } elseif ($abs_variance < 5) {
                $minor_variance_count++;
            } else {
                $major_variance_count++;
            }
        }
        
        return [
            'period' => "{$start_date} to {$end_date}",
            'station_id' => $station_id,
            'days_analyzed' => $days_back,
            'total_records' => $total_records,
            'ok_count' => $ok_count,
            'minor_variance_count' => $minor_variance_count,
            'major_variance_count' => $major_variance_count,
            'variance_percentage' => $total_records > 0 ? round((($minor_variance_count + $major_variance_count) / $total_records) * 100, 2) : 0,
            'details' => $variances
        ];
        
    } catch (Exception $e) {
        error_log("Error getting variance summary: " . $e->getMessage());
        return [
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Calculate stock trend over time
 * Shows whether inventory is increasing, stable, or decreasing
 * 
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @param int $product_id Fuel product ID
 * @param string $period 'week', 'month', 'quarter' (default: 'month')
 * @return array Trend data with average stock levels
 */
function calculate_stock_trend($pdo, $station_id, $product_id, $period = 'month') {
    try {
        $days = match($period) {
            'week' => 7,
            'month' => 30,
            'quarter' => 90,
            default => 30
        };
        
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(stock_date, '%Y-%m-%d') as date,
                AVG(actual_stock) as avg_stock,
                MAX(actual_stock) as max_stock,
                MIN(actual_stock) as min_stock,
                AVG(sales_liters) as avg_sales,
                SUM(deliveries_received) as total_deliveries
            FROM fuel_daily_stock_history
            WHERE station_id = ? 
            AND product_id = ?
            AND DATE(stock_date) >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(stock_date)
            ORDER BY stock_date ASC
        ");
        
        $stmt->execute([$station_id, $product_id, $days]);
        $trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($trend_data)) {
            return [
                'status' => 'insufficient_data',
                'message' => 'Not enough data to determine trend'
            ];
        }
        
        // Calculate trend
        $first_avg = (float)$trend_data[0]['avg_stock'];
        $last_avg = (float)$trend_data[count($trend_data) - 1]['avg_stock'];
        $trend = 'stable';
        
        if ($last_avg > $first_avg * 1.1) {
            $trend = 'increasing';
        } elseif ($last_avg < $first_avg * 0.9) {
            $trend = 'decreasing';
        }
        
        return [
            'station_id' => $station_id,
            'product_id' => $product_id,
            'period' => $period,
            'days_analyzed' => $days,
            'trend' => $trend,
            'data_points' => count($trend_data),
            'first_avg_stock' => round($first_avg, 2),
            'last_avg_stock' => round($last_avg, 2),
            'change_percentage' => round(((($last_avg - $first_avg) / $first_avg) * 100), 2),
            'trend_data' => $trend_data
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating stock trend: " . $e->getMessage());
        return [
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Archive daily reconciliation to fuel_daily_stock_history
 * Should be called once per day (typically at midnight or during shift-end)
 * 
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @param string $date YYYY-MM-DD (optional, defaults to yesterday)
 * @return array Archive result
 */
function archive_daily_reconciliation($pdo, $station_id, $date = null) {
    try {
        if (!$date) {
            $date = date('Y-m-d', strtotime('-1 day'));
        }
        
        // Get all fuel products for this station
        $stmt = $pdo->prepare("
            SELECT DISTINCT fi.product_id
            FROM fuel_inventory fi
            WHERE fi.station_id = ?
        ");
        $stmt->execute([$station_id]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $archived_count = 0;
        
        foreach ($products as $product) {
            $recon = calculate_daily_reconciliation($pdo, $station_id, $product['product_id'], $date);
            
            if (!isset($recon['error'])) {
                // Check if already archived
                $stmt = $pdo->prepare("
                    SELECT id FROM fuel_daily_stock_history
                    WHERE station_id = ? 
                    AND product_id = ?
                    AND DATE(stock_date) = ?
                ");
                $stmt->execute([$station_id, $product['product_id'], $date]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Update existing record
                    $stmt = $pdo->prepare("
                        UPDATE fuel_daily_stock_history
                        SET beginning_stock = ?,
                            deliveries_received = ?,
                            sales_liters = ?,
                            adjustments_liters = ?,
                            theoretical_stock = ?,
                            actual_stock = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        $recon['beginning_stock'],
                        $recon['deliveries_received'],
                        $recon['sales_liters'],
                        $recon['adjustments_liters'],
                        $recon['theoretical_stock'],
                        $recon['actual_stock'],
                        $existing['id']
                    ]);
                } else {
                    // Insert new record
                    $stmt = $pdo->prepare("
                        INSERT INTO fuel_daily_stock_history (
                            station_id, product_id, stock_date,
                            beginning_stock, deliveries_received, sales_liters,
                            adjustments_liters, theoretical_stock, actual_stock,
                            created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $station_id,
                        $product['product_id'],
                        $date,
                        $recon['beginning_stock'],
                        $recon['deliveries_received'],
                        $recon['sales_liters'],
                        $recon['adjustments_liters'],
                        $recon['theoretical_stock'],
                        $recon['actual_stock']
                    ]);
                }
                
                $archived_count++;
            }
        }
        
        return [
            'success' => true,
            'date_archived' => $date,
            'station_id' => $station_id,
            'products_archived' => $archived_count,
            'message' => "✓ Archived reconciliation for {$archived_count} fuel products"
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "✗ Error archiving reconciliation: " . $e->getMessage()
        ];
    }
}
?>
