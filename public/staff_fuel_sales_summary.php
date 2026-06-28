<?php
/**
 * STAFF FUEL SALES SUMMARY REPORT
 * Complete fetch process with all summaries
 * PDF-optimized printing (no content cutoff)
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$user_id = (int)($me['id'] ?? 0);
$station_id = user_station_id();

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); exit;
}

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) die('Error: You are not assigned to a station.');

// Get Station Info
$station_name = 'Station';
$station_location = '';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $station_name = $st['name'];
        $station_location = $st['location'] ?? '';
    }
} catch (Exception $e) {}

// Date handling
$today = date('Y-m-d');
// Default to most recent date with either fuel or merchandise data for this station (fallback to today)
$default_date = $today;
try {
    $dr = $pdo->prepare("
        SELECT MAX(d) AS d FROM (
            SELECT DATE(transaction_date) AS d FROM fuel_transactions WHERE station_id = ?
            UNION
            SELECT DATE(transaction_date) AS d FROM merchandise_transactions WHERE station_id = ?
        ) AS combined_dates
    ");
    $dr->execute([$station_id, $station_id]);
    $dr_row = $dr->fetch(PDO::FETCH_ASSOC);
    if ($dr_row && $dr_row['d']) $default_date = $dr_row['d'];
} catch (Exception $e) {}
$report_date = trim($_GET['report_date'] ?? $default_date);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) $report_date = $default_date;

// Helper: Check table existence
function table_exists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function column_exists($pdo, $table, $column) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Normalize various shift_period values to Shift 1 (true) or Shift 2 (false).
 * Handles: 'Shift 1','Shift1','First Shift','1st','Morning','General','Day' → shift1
 *          'Shift 2','Shift2','Second Shift','2nd','Evening','Afternoon','Night' → shift2
 */
function is_shift1(string $shift): bool {
    $s = strtolower(trim($shift));
    $shift1_keywords = ['shift 1','shift1','first','1st','morning','day','general','am'];
    $shift2_keywords = ['shift 2','shift2','second','2nd','evening','afternoon','night','pm'];
    foreach ($shift2_keywords as $kw) {
        if (strpos($s, $kw) !== false) return false;
    }
    foreach ($shift1_keywords as $kw) {
        if (strpos($s, $kw) !== false) return true;
    }
    // Fallback: if it contains digit '2' treat as shift2, else shift1
    return strpos($s, '2') === false;
}

// Check available tables
$has_fuel_readings = table_exists($pdo, 'fuel_readings');
$has_fuel_transactions = table_exists($pdo, 'fuel_transactions');
$has_payments = table_exists($pdo, 'payments');
$has_customers = table_exists($pdo, 'customers');
$has_fuel_types = table_exists($pdo, 'fuel_types');
$has_fuel_pumps = table_exists($pdo, 'fuel_pumps');
$has_merchandise_transactions = table_exists($pdo, 'merchandise_transactions');

// Initialize data structures
$meter_readings = [];
$fuel_transactions = [];
$volume_sales = [];
$tank_sales = [];
$shift1_summary = [
    'fuel_sales' => 0,
    'merchandise_sales' => 0,
    'service_income' => 0,
    'total_sales' => 0,
    'cash' => 0,
    'card' => 0,
    'ewallet' => 0,
    'credit' => 0,
];
$shift2_summary = [
    'fuel_sales' => 0,
    'merchandise_sales' => 0,
    'service_income' => 0,
    'total_sales' => 0,
    'cash' => 0,
    'card' => 0,
    'ewallet' => 0,
    'credit' => 0,
];
$ar_summary = [];
$overall_summary = [
    'total_fuel_sales' => 0,
    'total_merchandise_sales' => 0,
    'total_liters' => 0,
    'total_cash' => 0,
    'total_deposits' => 0,
];

$error_message = null;

// ============================================================
// DATA SOURCE 1: METER READINGS TABLE
// ============================================================
if ($has_fuel_readings) {
    try {
        $sql = "SELECT 
                    fr.id,
                    fr.pump_number,
                    ";
        
        if ($has_fuel_pumps) {
            $sql .= "COALESCE(fp.pump_number, CONCAT('Pump ', fr.pump_number)) AS pump_name, ";
        } else {
            $sql .= "CONCAT('Pump ', fr.pump_number) AS pump_name, ";
        }
        
        if ($has_fuel_types) {
            $sql .= "COALESCE(ft.name, fr.fuel_type) AS fuel_type, ";
        } else {
            $sql .= "fr.fuel_type, ";
        }
        
        $sql .= "fr.previous_reading AS beginning_reading,
                 fr.present_reading AS ending_reading,
                 fr.difference AS liters_sold,
                 0.00 AS calibration,
                 fr.shift_period,
                 fr.status,
                 fr.encoded_at
            FROM fuel_readings fr ";
        
        if ($has_fuel_pumps) {
            $sql .= "LEFT JOIN fuel_pumps fp ON fr.pump_number = fp.pump_number AND fp.station_id = fr.station_id ";
        }
        
        if ($has_fuel_types) {
            $sql .= "LEFT JOIN fuel_types ft ON fr.fuel_type = ft.id ";
        }
        
        $sql .= "WHERE fr.station_id = ? AND DATE(fr.encoded_at) = ?
                 ORDER BY fr.pump_number, fr.encoded_at";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $report_date]);
        $meter_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error_message = "Error fetching meter readings: " . $e->getMessage();
    }
}

// If no meter readings but we have fuel_transactions, generate meter readings from transactions
if (count($meter_readings) == 0 && $has_fuel_transactions) {
    try {
        $sql = "SELECT 
                    ft.id,
                    CONCAT('Pump ', COALESCE(ft.pump_id, '—')) AS pump_name,
                    ft.fuel_type,
                    MIN(COALESCE(ft.previous_reading, 0)) AS beginning_reading,
                    MAX(COALESCE(ft.present_reading, 0)) AS ending_reading,
                    SUM(ft.liters_sold) AS liters_sold,
                    0 AS calibration,
                    COALESCE(ft.shift_period, 'Shift 1') AS shift_period,
                    'Completed' AS status,
                    ft.transaction_date AS encoded_at
            FROM fuel_transactions ft
            WHERE ft.station_id = ? AND DATE(ft.transaction_date) = ?
                 GROUP BY ft.fuel_type, ft.pump_id, ft.shift_period
                 ORDER BY ft.fuel_type, ft.pump_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $report_date]);
        $meter_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {}
}

// ============================================================
// DATA SOURCE 2: FUEL TRANSACTIONS TABLE
// ============================================================
if ($has_fuel_transactions) {
    try {
        $sql = "SELECT 
                    ft.id,
                    ft.fuel_type,
                    ft.liters_sold,
                    ft.price_per_liter AS unit_price,
                    ft.total_amount,
                    ft.payment_method,
                    ft.shift_period AS shift,
                    ft.transaction_date AS created_at
            FROM fuel_transactions ft
            WHERE ft.station_id = ? AND DATE(ft.transaction_date) = ?
                 ORDER BY ft.transaction_date";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $report_date]);
        $fuel_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by fuel type for volume sales summary
        $volume_sales_temp = [];
        foreach ($fuel_transactions as $trans) {
            $fuel = $trans['fuel_type'];
            if (!isset($volume_sales_temp[$fuel])) {
                $volume_sales_temp[$fuel] = [
                    'fuel_type' => $fuel,
                    'total_liters' => 0,
                    'avg_price' => 0,
                    'total_amount' => 0,
                    'count' => 0,
                ];
            }
            $volume_sales_temp[$fuel]['total_liters'] += (float)$trans['liters_sold'];
            $volume_sales_temp[$fuel]['total_amount'] += (float)$trans['total_amount'];
            $volume_sales_temp[$fuel]['count']++;
        }
        
        // Calculate averages
        foreach ($volume_sales_temp as $fuel => $data) {
            $volume_sales_temp[$fuel]['avg_price'] = $data['total_amount'] / $data['total_liters'];
        }
        
        $volume_sales = array_values($volume_sales_temp);
        
    } catch (Exception $e) {
        $error_message = "Error fetching fuel transactions: " . $e->getMessage();
    }
}

// ============================================================
// DATA SOURCE 3: PAYMENTS TABLE & SHIFT SUMMARIES
// ============================================================
try {
    // Get fuel sales by shift
    if ($has_fuel_transactions) {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(shift_period, 'Shift 1') AS shift_period,
                SUM(total_amount) AS total_amount,
                SUM(liters_sold) AS total_liters,
                payment_method,
                COUNT(*) AS transaction_count
            FROM fuel_transactions
            WHERE station_id = ? AND DATE(transaction_date) = ?
            GROUP BY shift_period, payment_method
        ");
        $stmt->execute([$station_id, $report_date]);
        $fuel_by_shift = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($fuel_by_shift as $row) {
            $amount   = (float)$row['total_amount'];
            $payment  = strtolower($row['payment_method'] ?? 'cash');
            $is_s1    = is_shift1($row['shift_period'] ?? '');
            $target   = $is_s1 ? 'shift1' : 'shift2';

            if ($is_s1) {
                $shift1_summary['fuel_sales'] += $amount;
                if (in_array($payment, ['cash','cash payment'])) $shift1_summary['cash'] += $amount;
                elseif (in_array($payment, ['card','credit card','debit card'])) $shift1_summary['card'] += $amount;
                elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift1_summary['ewallet'] += $amount;
                else $shift1_summary['credit'] += $amount;
            } else {
                $shift2_summary['fuel_sales'] += $amount;
                if (in_array($payment, ['cash','cash payment'])) $shift2_summary['cash'] += $amount;
                elseif (in_array($payment, ['card','credit card','debit card'])) $shift2_summary['card'] += $amount;
                elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift2_summary['ewallet'] += $amount;
                else $shift2_summary['credit'] += $amount;
            }
        }
    }
    
    // Get merchandise and service sales by shift and process transaction items
    $processed_merch = [];
    $processed_services = [];
    $total_merch_amount = 0;
    $total_service_amount = 0;
    
    // Add service_income key to shift summaries
    $shift1_summary['service_income'] = 0;
    $shift2_summary['service_income'] = 0;

    if ($has_merchandise_transactions) {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    mt.id AS transaction_db_id,
                    mt.transaction_id,
                    mt.payment_method,
                    mt.shift_period,
                    mt.total_amount AS tx_total_amount,
                    mti.id AS item_db_id,
                    COALESCE(mti.category, 'General') AS category,
                    COALESCE(mti.product_name, mt.item_sku, 'Item') AS product_name,
                    COALESCE(mti.quantity, mt.quantity) AS stock_out,
                    COALESCE(mti.unit_price, mt.unit_price) AS unit_price,
                    COALESCE(mti.subtotal, mt.total_amount) AS item_subtotal,
                    COALESCE(mti.item_type, CASE WHEN COALESCE(mti.category, '') = 'Service Fee' THEN 'service' ELSE 'merchandise' END) AS item_type,
                    u.username AS encoder,
                    mt.transaction_date,
                    mt.created_at
                FROM merchandise_transactions mt
                LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
                LEFT JOIN users u ON mt.staff_id = u.id
                WHERE mt.station_id = ? AND DATE(mt.transaction_date) = ?
                ORDER BY mti.category, mt.transaction_date
            ");
            $stmt->execute([$station_id, $report_date]);
            $raw_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group items by transaction to compute scaling factor (for VAT inclusion)
            $tx_subtotals = [];
            foreach ($raw_items as $item) {
                $tx_id = $item['transaction_db_id'];
                if (!isset($tx_subtotals[$tx_id])) {
                    $tx_subtotals[$tx_id] = 0;
                }
                $tx_subtotals[$tx_id] += (float)$item['item_subtotal'];
            }

            foreach ($raw_items as $item) {
                $tx_id = $item['transaction_db_id'];
                $subtotal_sum = $tx_subtotals[$tx_id];
                $tx_total = (float)$item['tx_total_amount'];
                
                $factor = 1.0;
                if ($subtotal_sum > 0) {
                    $factor = $tx_total / $subtotal_sum;
                }

                // Scale price and amount to be VAT-inclusive
                $scaled_unit_price = (float)$item['unit_price'] * $factor;
                $scaled_amount = (float)$item['item_subtotal'] * $factor;

                $processed_item = [
                    'id' => $item['item_db_id'] ?? $item['transaction_db_id'],
                    'transaction_id' => $item['transaction_id'],
                    'category' => $item['category'],
                    'product_name' => $item['product_name'],
                    'service_type' => $item['product_name'],
                    'stock_out' => (float)$item['stock_out'],
                    'unit_price' => $scaled_unit_price,
                    'labor_fee' => $scaled_unit_price,
                    'total_amount' => $scaled_amount,
                    'shift' => $item['shift_period'] ?? 'Shift 1',
                    'encoder' => $item['encoder'] ?? 'N/A',
                    'payment_method' => $item['payment_method'],
                    'created_at' => $item['created_at']
                ];

                if ($item['item_type'] === 'service') {
                    // Find parts used (any merchandise items in the same transaction)
                    $parts = [];
                    foreach ($raw_items as $other) {
                        if ($other['transaction_db_id'] === $tx_id && $other['item_type'] === 'merchandise') {
                            $parts[] = $other['product_name'] . ' (x' . number_format((float)$other['stock_out'], 0) . ')';
                        }
                    }
                    $processed_item['parts_used'] = count($parts) > 0 ? implode(', ', $parts) : '—';
                    $processed_services[] = $processed_item;
                    $total_service_amount += $scaled_amount;
                    
                    // Add to shift summaries
                    $payment = strtolower($item['payment_method'] ?? 'cash');
                    $is_s1 = is_shift1($item['shift_period'] ?? '');
                    if ($is_s1) {
                        $shift1_summary['service_income'] += $scaled_amount;
                        if (in_array($payment, ['cash','cash payment'])) $shift1_summary['cash'] += $scaled_amount;
                        elseif (in_array($payment, ['card','credit card','debit card'])) $shift1_summary['card'] += $scaled_amount;
                        elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift1_summary['ewallet'] += $scaled_amount;
                        else $shift1_summary['credit'] += $scaled_amount;
                    } else {
                        $shift2_summary['service_income'] += $scaled_amount;
                        if (in_array($payment, ['cash','cash payment'])) $shift2_summary['cash'] += $scaled_amount;
                        elseif (in_array($payment, ['card','credit card','debit card'])) $shift2_summary['card'] += $scaled_amount;
                        elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift2_summary['ewallet'] += $scaled_amount;
                        else $shift2_summary['credit'] += $scaled_amount;
                    }
                } else {
                    $processed_merch[] = $processed_item;
                    $total_merch_amount += $scaled_amount;
                    
                    // Add to shift summaries
                    $payment = strtolower($item['payment_method'] ?? 'cash');
                    $is_s1 = is_shift1($item['shift_period'] ?? '');
                    if ($is_s1) {
                        $shift1_summary['merchandise_sales'] += $scaled_amount;
                        if (in_array($payment, ['cash','cash payment'])) $shift1_summary['cash'] += $scaled_amount;
                        elseif (in_array($payment, ['card','credit card','debit card'])) $shift1_summary['card'] += $scaled_amount;
                        elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift1_summary['ewallet'] += $scaled_amount;
                        else $shift1_summary['credit'] += $scaled_amount;
                    } else {
                        $shift2_summary['merchandise_sales'] += $scaled_amount;
                        if (in_array($payment, ['cash','cash payment'])) $shift2_summary['cash'] += $scaled_amount;
                        elseif (in_array($payment, ['card','credit card','debit card'])) $shift2_summary['card'] += $scaled_amount;
                        elseif (in_array($payment, ['gcash','maya','e-wallet','ewallet'])) $shift2_summary['ewallet'] += $scaled_amount;
                        else $shift2_summary['credit'] += $scaled_amount;
                    }
                }
            }
        } catch (Exception $e) {
            $error_message = "Error fetching merchandise transactions: " . $e->getMessage();
        }
    }
    
    // Calculate totals
    $shift1_summary['total_sales'] = $shift1_summary['fuel_sales'] + $shift1_summary['merchandise_sales'] + $shift1_summary['service_income'];
    $shift2_summary['total_sales'] = $shift2_summary['fuel_sales'] + $shift2_summary['merchandise_sales'] + $shift2_summary['service_income'];
    
} catch (Exception $e) {
    $error_message = "Error calculating shift summaries: " . $e->getMessage();
}

// ============================================================
// DATA SOURCE 4: CUSTOMER ACCOUNTS - A/R SUMMARY
// ============================================================
if ($has_customers) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.name,
                c.contact_number,
                COALESCE(c.account_balance, 0) AS balance,
                c.credit_limit,
                c.type
            FROM customers c
            WHERE c.station_id = ? 
              AND c.type IN ('credit', 'suki')
              AND COALESCE(c.account_balance, 0) > 0
            ORDER BY c.account_balance DESC
        ");
        $stmt->execute([$station_id]);
        $ar_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error_message = "Error fetching A/R summary: " . $e->getMessage();
    }
}

// ============================================================
// TANK SALES SUMMARY
// ============================================================
try {
    // Group meter readings by fuel type for tank summary
    $tank_summary_temp = [];
    foreach ($meter_readings as $reading) {
        $fuel = $reading['fuel_type'];
        if (!isset($tank_summary_temp[$fuel])) {
            $tank_summary_temp[$fuel] = [
                'fuel_type' => $fuel,
                'total_dispensed' => 0,
                'tank_capacity' => 0,
                'utilization' => 0,
            ];
        }
        $tank_summary_temp[$fuel]['total_dispensed'] += (float)$reading['liters_sold'];
    }
    
    $tank_sales = array_values($tank_summary_temp);
    
} catch (Exception $e) {}

// ============================================================
// OVERALL DAILY SUMMARY
// ============================================================
$overall_summary['total_fuel_sales'] = $shift1_summary['fuel_sales'] + $shift2_summary['fuel_sales'];
$overall_summary['total_merchandise_sales'] = $shift1_summary['merchandise_sales'] + $shift2_summary['merchandise_sales'];
$overall_summary['total_service_income'] = $shift1_summary['service_income'] + $shift2_summary['service_income'];
$overall_summary['total_liters'] = array_sum(array_column($volume_sales, 'total_liters'));
$overall_summary['total_cash'] = $shift1_summary['cash'] + $shift2_summary['cash'];
$overall_summary['total_deposits'] = 0; // Would come from deposits table if available
$total_service_amount = $overall_summary['total_service_income'];

// Ensure fuel transactions total matches
if (isset($fuel_transactions) && is_array($fuel_transactions) && count($fuel_transactions) > 0) {
    $direct_fuel_total = array_sum(array_column($fuel_transactions, 'total_amount'));
    // Use the direct total if shift summary is zero but we have transactions
    if ($overall_summary['total_fuel_sales'] == 0 && $direct_fuel_total > 0) {
        $overall_summary['total_fuel_sales'] = $direct_fuel_total;
    }
}

$page_title = "Fuel Sales Summary Report";

// ============================================================
// EXCEL EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $export_type = $_GET['type'] ?? 'fuel'; // fuel or merchandise
    
    header('Content-Type: application/vnd.ms-excel');
    if ($export_type === 'merchandise') {
        header('Content-Disposition: attachment;filename="Merchandise_Sales_Report_' . $report_date . '.xls"');
    } else {
        header('Content-Disposition: attachment;filename="Fuel_Sales_Report_' . $report_date . '.xls"');
    }
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    if ($export_type === 'merchandise') {
        echo '<x:Name>Merchandise Sales Report</x:Name>';
    } else {
        echo '<x:Name>Fuel Sales Report</x:Name>';
    }
    echo '<x:WorksheetOptions>';
    echo '<x:Print>';
    echo '<x:ValidPrinterInfo/>';
    echo '</x:Print>';
    echo '</x:WorksheetOptions>';
    echo '</x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets>';
    echo '</x:ExcelWorkbook>';
    echo '</xml>';
    echo '<![endif]-->';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }';
    echo 'th, td { border: 1px solid #000000; padding: 8px; text-align: left; }';
    echo 'th { background-color: #E0E0E0; font-weight: bold; text-align: center; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '.font-bold { font-weight: bold; }';
    echo 'h1 { font-size: 18px; font-weight: bold; margin: 10px 0; }';
    echo 'h2 { font-size: 14px; font-weight: bold; margin: 15px 0 10px 0; background-color: #F0F0F0; padding: 5px; border: 1px solid #000; }';
    echo 'h3 { font-size: 12px; font-weight: bold; margin: 10px 0 5px 0; }';
    echo 'p { margin: 5px 0; }';
    echo '.summary-table { background-color: #F9F9F9; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    if ($export_type === 'merchandise') {
        // MERCHANDISE & SERVICE SALES REPORT
        echo '<h1>DAILY MERCHANDISE & SERVICE SALES REPORT</h1>';
        echo '<h1 style="font-size: 16px;">24-HOUR SUMMARY</h1>';
        echo '<p>' . htmlspecialchars($station_name) . '</p>';
        echo '<p><strong>Date:</strong> ' . date('F d, Y', strtotime($report_date)) . '</p>';
        echo '<br/>';
        
        // Use pre-processed merchandise transactions
        $merch_transactions = $processed_merch;
        
        // MERCHANDISE SALES TABLE
        echo '<h2>MERCHANDISE SALES TABLE</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Category</th>';
        echo '<th>Product Name</th>';
        echo '<th>Beginning Stock</th>';
        echo '<th>Stock-In</th>';
        echo '<th>Stock-Out</th>';
        echo '<th>Ending Stock</th>';
        echo '<th>Unit Price</th>';
        echo '<th>Amount</th>';
        echo '<th>Encoder</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $total_merch_amount = 0;
        if (count($merch_transactions) > 0) {
            foreach ($merch_transactions as $trans) {
                $total_merch_amount += $trans['total_amount'];
                echo '<tr>';
                echo '<td>' . htmlspecialchars($trans['category']) . '</td>';
                echo '<td class="font-bold">' . htmlspecialchars($trans['product_name']) . '</td>';
                echo '<td class="text-right">—</td>';
                echo '<td class="text-right">—</td>';
                echo '<td class="text-right">' . number_format($trans['stock_out'], 2) . '</td>';
                echo '<td class="text-right">—</td>';
                echo '<td class="text-right">₱' . number_format($trans['unit_price'], 2) . '</td>';
                echo '<td class="text-right font-bold">₱' . number_format($trans['total_amount'], 2) . '</td>';
                echo '<td>' . htmlspecialchars($trans['encoder'] ?? 'N/A') . '</td>';
                echo '</tr>';
            }
            echo '<tr class="font-bold">';
            echo '<td colspan="7" class="text-right">TOTAL</td>';
            echo '<td class="text-right">₱' . number_format($total_merch_amount, 2) . '</td>';
            echo '<td></td>';
            echo '</tr>';
        } else {
            echo '<tr><td colspan="9" style="text-align: center; padding: 20px;">No merchandise sales found for this date.</td></tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // Use pre-processed service transactions
        $service_transactions = $processed_services;
        
        echo '<h2>SERVICE INCOME TABLE</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Service Type</th>';
        echo '<th>Labor Fee</th>';
        echo '<th>Parts Used</th>';
        echo '<th>Total Service Amount</th>';
        echo '<th>Encoder</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $total_service_amount = 0;
        if (count($service_transactions) > 0) {
            foreach ($service_transactions as $trans) {
                $total_service_amount += $trans['total_amount'];
                echo '<tr>';
                echo '<td class="font-bold">' . htmlspecialchars($trans['service_type']) . '</td>';
                echo '<td class="text-right">₱' . number_format($trans['labor_fee'], 2) . '</td>';
                echo '<td>' . htmlspecialchars($trans['parts_used'] ?? '—') . '</td>';
                echo '<td class="text-right font-bold">₱' . number_format($trans['total_amount'], 2) . '</td>';
                echo '<td>' . htmlspecialchars($trans['encoder'] ?? 'N/A') . '</td>';
                echo '</tr>';
            }
            echo '<tr class="font-bold">';
            echo '<td colspan="3" class="text-right">TOTAL</td>';
            echo '<td class="text-right">₱' . number_format($total_service_amount, 2) . '</td>';
            echo '<td></td>';
            echo '</tr>';
        } else {
            echo '<tr><td colspan="5" style="text-align: center; padding: 20px;">No service income found for this date.</td></tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // SHIFT SUMMARIES
        echo '<h2>SHIFT 1 SALES SUMMARY (6AM-2PM)</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td class="font-bold">Merchandise Sales:</td><td class="text-right font-bold">₱' . number_format($shift1_summary['merchandise_sales'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold">Service Income:</td><td class="text-right font-bold">₱' . number_format($shift1_summary['service_income'], 2) . '</td></tr>';
        echo '<tr><td colspan="2">&nbsp;</td></tr>';
        echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
        echo '<tr><td>Cash:</td><td class="text-right">₱' . number_format($shift1_summary['cash'], 2) . '</td></tr>';
        echo '<tr><td>Card:</td><td class="text-right">₱' . number_format($shift1_summary['card'], 2) . '</td></tr>';
        echo '<tr><td>E-Wallet:</td><td class="text-right">₱' . number_format($shift1_summary['ewallet'], 2) . '</td></tr>';
        echo '<tr><td>Credit/Suki:</td><td class="text-right">₱' . number_format($shift1_summary['credit'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        echo '<h2>SHIFT 2 SALES SUMMARY (2PM-12AM)</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td class="font-bold">Merchandise Sales:</td><td class="text-right font-bold">₱' . number_format($shift2_summary['merchandise_sales'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold">Service Income:</td><td class="text-right font-bold">₱' . number_format($shift2_summary['service_income'], 2) . '</td></tr>';
        echo '<tr><td colspan="2">&nbsp;</td></tr>';
        echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
        echo '<tr><td>Cash:</td><td class="text-right">₱' . number_format($shift2_summary['cash'], 2) . '</td></tr>';
        echo '<tr><td>Card:</td><td class="text-right">₱' . number_format($shift2_summary['card'], 2) . '</td></tr>';
        echo '<tr><td>E-Wallet:</td><td class="text-right">₱' . number_format($shift2_summary['ewallet'], 2) . '</td></tr>';
        echo '<tr><td>Credit/Suki:</td><td class="text-right">₱' . number_format($shift2_summary['credit'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // A/R SUMMARY
        if (count($ar_summary) > 0) {
            echo '<h2>A/R SUMMARY (Suki/Credit Customers)</h2>';
            echo '<table border="1" cellpadding="5" cellspacing="0">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Customer Name</th>';
            echo '<th>Contact Number</th>';
            echo '<th>Type</th>';
            echo '<th>Outstanding Balance</th>';
            echo '<th>Credit Limit</th>';
            echo '<th>Available Credit</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            $total_ar = 0;
            foreach ($ar_summary as $ar) {
                $total_ar += $ar['balance'];
                $available = $ar['credit_limit'] - $ar['balance'];
                echo '<tr>';
                echo '<td class="font-bold">' . htmlspecialchars($ar['name']) . '</td>';
                echo '<td>' . htmlspecialchars($ar['contact_number'] ?? '-') . '</td>';
                echo '<td>' . strtoupper($ar['type']) . '</td>';
                echo '<td class="text-right font-bold">₱' . number_format($ar['balance'], 2) . '</td>';
                echo '<td class="text-right">₱' . number_format($ar['credit_limit'], 2) . '</td>';
                echo '<td class="text-right">₱' . number_format($available, 2) . '</td>';
                echo '</tr>';
            }
            
            echo '<tr class="font-bold">';
            echo '<td colspan="3" class="text-right">TOTAL ACCOUNTS RECEIVABLE</td>';
            echo '<td class="text-right">₱' . number_format($total_ar, 2) . '</td>';
            echo '<td colspan="2"></td>';
            echo '</tr>';
            echo '</tbody>';
            echo '</table>';
            echo '<br/>';
        }
        
        // OVERALL SUMMARY
        echo '<h2>OVERALL DAILY MERCHANDISE SUMMARY</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td><strong>Total Merchandise Sales:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_merchandise_sales'], 2) . '</td></tr>';
        echo '<tr><td><strong>Total Service Income:</strong></td><td class="text-right font-bold">₱' . number_format($total_service_amount, 2) . '</td></tr>';
        echo '<tr><td><strong>Grand Total Sales:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_merchandise_sales'] + $total_service_amount, 2) . '</td></tr>';
        echo '<tr><td><strong>Total Cash Collection:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
        
        // TOTAL CASH IN BANK
        echo '<h2>TOTAL CASH IN BANK</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<tbody>';
        echo '<tr><td class="font-bold">Cash on Hand:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold">Deposits Made Today:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_deposits'], 2) . '</td></tr>';
        echo '<tr><td class="font-bold" style="font-size: 14px;">TOTAL CASH IN BANK:</td><td class="text-right font-bold" style="font-size: 14px;">₱' . number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        
    } else {
        // FUEL SALES REPORT (existing code)
        // Header
        echo '<h1>DAILY FUEL SALES REPORT</h1>';
        echo '<h1 style="font-size: 16px;">24-HOUR SUMMARY</h1>';
        echo '<p>' . htmlspecialchars($station_name) . '</p>';
        echo '<p><strong>Date:</strong> ' . date('F d, Y', strtotime($report_date)) . '</p>';
        echo '<br/>';
    
    // METER READINGS TABLE
    echo '<h2>METER READINGS</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Pump Name</th>';
    echo '<th>Fuel Type</th>';
    echo '<th>Beginning</th>';
    echo '<th>Ending</th>';
    echo '<th>Calibration</th>';
    echo '<th>Volume (Liters)</th>';
    echo '<th>Price</th>';
    echo '<th>Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($meter_readings) > 0) {
        $total_liters_meter = 0;
        $total_amount_meter = 0;
        foreach ($meter_readings as $reading) {
            $total_liters_meter += $reading['liters_sold'];
            // Get price from volume_sales
            $price = 0;
            $amount = 0;
            foreach ($volume_sales as $vol) {
                if (strpos($reading['fuel_type'], $vol['fuel_type']) !== false || strpos($vol['fuel_type'], $reading['fuel_type']) !== false) {
                    $price = $vol['avg_price'];
                    $amount = $reading['liters_sold'] * $price;
                    break;
                }
            }
            $total_amount_meter += $amount;
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($reading['pump_name']) . '</td>';
            echo '<td>' . htmlspecialchars($reading['fuel_type']) . '</td>';
            echo '<td class="text-right">' . number_format($reading['beginning_reading'], 2) . '</td>';
            echo '<td class="text-right">' . number_format($reading['ending_reading'], 2) . '</td>';
            echo '<td class="text-right">' . number_format($reading['calibration'], 2) . '</td>';
            echo '<td class="text-right font-bold">' . number_format($reading['liters_sold'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($price, 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($amount, 2) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="font-bold">';
        echo '<td colspan="5" class="text-right">TOTAL</td>';
        echo '<td class="text-right">' . number_format($total_liters_meter, 2) . '</td>';
        echo '<td></td>';
        echo '<td class="text-right">₱' . number_format($total_amount_meter, 2) . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="8" style="text-align: center; padding: 20px;">No meter readings found for this date.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // VOLUME SALES SUMMARY
    echo '<h2>VOLUME SALES SUMMARY</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Fuel Type</th>';
    echo '<th>Total Liters</th>';
    echo '<th>Avg Price/L</th>';
    echo '<th>Total Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($volume_sales) > 0) {
        $total_amount_vol = 0;
        foreach ($volume_sales as $vol) {
            $total_amount_vol += $vol['total_amount'];
            echo '<tr>';
            echo '<td class="font-bold">' . htmlspecialchars($vol['fuel_type']) . '</td>';
            echo '<td class="text-right">' . number_format($vol['total_liters'], 2) . ' L</td>';
            echo '<td class="text-right">₱' . number_format($vol['avg_price'], 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($vol['total_amount'], 2) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="font-bold">';
        echo '<td>TOTAL</td>';
        echo '<td class="text-right">' . number_format(array_sum(array_column($volume_sales, 'total_liters')), 2) . ' L</td>';
        echo '<td class="text-right">-</td>';
        echo '<td class="text-right">₱' . number_format($total_amount_vol, 2) . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="4" style="text-align: center; padding: 20px;">No volume sales data available.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // SHIFT SUMMARIES
    echo '<h2>SHIFT 1 FUEL SALES & CASH SUMMARY (6AM-2PM)</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td class="font-bold">Total Fuel Sales:</td><td class="text-right font-bold">₱' . number_format($shift1_summary['fuel_sales'], 2) . '</td></tr>';
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
    echo '<tr><td>Cash:</td><td class="text-right">₱' . number_format($shift1_summary['cash'], 2) . '</td></tr>';
    echo '<tr><td>Card:</td><td class="text-right">₱' . number_format($shift1_summary['card'], 2) . '</td></tr>';
    echo '<tr><td>E-Wallet:</td><td class="text-right">₱' . number_format($shift1_summary['ewallet'], 2) . '</td></tr>';
    echo '<tr><td>Credit/Suki:</td><td class="text-right">₱' . number_format($shift1_summary['credit'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    echo '<h2>SHIFT 2 FUEL SALES & CASH SUMMARY (2PM-12AM)</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td class="font-bold">Total Fuel Sales:</td><td class="text-right font-bold">₱' . number_format($shift2_summary['fuel_sales'], 2) . '</td></tr>';
    echo '<tr><td colspan="2">&nbsp;</td></tr>';
    echo '<tr><td colspan="2" class="font-bold">Payment Breakdown</td></tr>';
    echo '<tr><td>Cash:</td><td class="text-right">₱' . number_format($shift2_summary['cash'], 2) . '</td></tr>';
    echo '<tr><td>Card:</td><td class="text-right">₱' . number_format($shift2_summary['card'], 2) . '</td></tr>';
    echo '<tr><td>E-Wallet:</td><td class="text-right">₱' . number_format($shift2_summary['ewallet'], 2) . '</td></tr>';
    echo '<tr><td>Credit/Suki:</td><td class="text-right">₱' . number_format($shift2_summary['credit'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // A/R SUMMARY
    if (count($ar_summary) > 0) {
        echo '<h2>A/R SUMMARY (Suki/Credit Customers)</h2>';
        echo '<table border="1" cellpadding="5" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Customer Name</th>';
        echo '<th>Contact Number</th>';
        echo '<th>Type</th>';
        echo '<th>Outstanding Balance</th>';
        echo '<th>Credit Limit</th>';
        echo '<th>Available Credit</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        $total_ar = 0;
        foreach ($ar_summary as $ar) {
            $total_ar += $ar['balance'];
            $available = $ar['credit_limit'] - $ar['balance'];
            echo '<tr>';
            echo '<td class="font-bold">' . htmlspecialchars($ar['name']) . '</td>';
            echo '<td>' . htmlspecialchars($ar['contact_number'] ?? '-') . '</td>';
            echo '<td>' . strtoupper($ar['type']) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($ar['balance'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($ar['credit_limit'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($available, 2) . '</td>';
            echo '</tr>';
        }
        
        echo '<tr class="font-bold">';
        echo '<td colspan="3" class="text-right">TOTAL ACCOUNTS RECEIVABLE</td>';
        echo '<td class="text-right">₱' . number_format($total_ar, 2) . '</td>';
        echo '<td colspan="2"></td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
    }
    
    // OVERALL DAILY SUMMARY
    echo '<h2>OVERALL DAILY FUEL SUMMARY</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td><strong>Total Fuel Sales:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_fuel_sales'], 2) . '</td></tr>';
    echo '<tr><td><strong>Total Liters Sold:</strong></td><td class="text-right font-bold">' . number_format($overall_summary['total_liters'], 2) . ' L</td></tr>';
    echo '<tr><td><strong>Total Cash Collection:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
    echo '<tr><td><strong>Total Deposits:</strong></td><td class="text-right font-bold">₱' . number_format($overall_summary['total_deposits'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // TOTAL CASH IN BANK
    echo '<h2>TOTAL CASH IN BANK</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<tbody>';
    echo '<tr><td class="font-bold">Cash on Hand:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_cash'], 2) . '</td></tr>';
    echo '<tr><td class="font-bold">Deposits Made Today:</td><td class="text-right font-bold">₱' . number_format($overall_summary['total_deposits'], 2) . '</td></tr>';
    echo '<tr><td class="font-bold" style="font-size: 14px;">TOTAL CASH IN BANK:</td><td class="text-right font-bold" style="font-size: 14px;">₱' . number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    
    } // End if merchandise/fuel export type
    
    echo '</body>';
    echo '</html>';
    exit;
}

// ============================================================
// EXPORT HANDLING - FORMATTED LIKE ACTUAL DAILY FUEL REPORT
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Daily Fuel Sales Report - <?= htmlspecialchars($report_date) ?></title>
        <style>
            @page {
                size: A4 portrait;
                margin: 15mm 10mm;
            }
            @media print {
                body { margin: 0; padding: 0; }
                .no-print { display: none !important; }
                table { page-break-inside: avoid; }
                .page-break { page-break-after: always; }
            }
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: 'Courier New', monospace; 
                font-size: 10pt;
                line-height: 1.2;
            }
            
            /* Header Section */
            .report-header {
                border: 2px solid #000;
                padding: 8px;
                margin-bottom: 10px;
            }
            .report-header table {
                width: 100%;
                border: none;
            }
            .report-header td {
                border: none;
                padding: 2px 5px;
                font-size: 9pt;
            }
            .report-title {
                text-align: center;
                font-size: 14pt;
                font-weight: bold;
                margin-bottom: 8px;
                text-decoration: underline;
            }
            
            /* Main Content Layout */
            .content-wrapper {
                display: grid;
                grid-template-columns: 30% 70%;
                gap: 10px;
            }
            
            /* Left Column - Calibration */
            .left-column {
                border: 1px solid #000;
                padding: 5px;
            }
            .calibration-box {
                border: 1px solid #000;
                padding: 5px;
                margin-bottom: 8px;
            }
            .calibration-box h3 {
                font-size: 9pt;
                text-align: center;
                border-bottom: 1px solid #000;
                padding-bottom: 3px;
                margin-bottom: 5px;
            }
            
            /* Right Column - Main Table */
            .right-column {
                border: 1px solid #000;
                padding: 5px;
            }
            
            /* Meter Reading Table */
            .meter-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8pt;
                margin-bottom: 8px;
            }
            .meter-table th {
                border: 1px solid #000;
                background: #fff;
                padding: 4px 2px;
                text-align: center;
                font-weight: bold;
            }
            .meter-table td {
                border: 1px solid #000;
                padding: 3px 2px;
                text-align: center;
            }
            .meter-table td.text-right {
                text-align: right;
                padding-right: 5px;
            }
            
            /* Summary Section */
            .summary-section {
                border: 1px solid #000;
                padding: 5px;
                margin-top: 8px;
            }
            .summary-section h3 {
                font-size: 9pt;
                text-align: center;
                border-bottom: 1px solid #000;
                padding-bottom: 3px;
                margin-bottom: 5px;
            }
            .summary-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8pt;
            }
            .summary-table td {
                border: 1px solid #000;
                padding: 3px 5px;
            }
            .summary-table .label {
                background: #fff;
                font-weight: bold;
                width: 40%;
            }
            .summary-table .value {
                text-align: right;
            }
            
            /* Footer */
            .report-footer {
                margin-top: 10px;
                border-top: 1px solid #000;
                padding-top: 5px;
                font-size: 8pt;
                text-align: center;
            }
            
            .signature-section {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-top: 20px;
            }
            .signature-box {
                text-align: center;
                padding: 10px;
            }
            .signature-line {
                border-top: 1px solid #000;
                margin: 30px 20px 5px 20px;
            }
        </style>
    </head>
    <body>
        <!-- Report Header -->
        <div class="report-header">
            <div class="report-title">DAILY FUEL SALES REPORT</div>
            <div class="report-title" style="font-size: 11pt; margin-top: 5px;">24-HOUR SUMMARY</div>
            <table>
                <tr>
                    <td style="width: 50%;"><?= htmlspecialchars($station_name) ?></td>
                    <td><strong>Date:</strong> <?= date('F d, Y', strtotime($report_date)) ?></td>
                </tr>
                <tr>
                    <td><strong>Location:</strong> <?= htmlspecialchars($station_location) ?></td>
                    <td><strong>Period:</strong> 24 Hours (00:00 - 23:59)</td>
                </tr>
                <tr>
                    <td colspan="2"><strong>Generated:</strong> <?= date('F d, Y h:i A') ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Main Content -->
        <div class="content-wrapper">
            <!-- Left Column: Calibration & Summary -->
            <div class="left-column">
                <!-- Shift 1 Calibration -->
                <div class="calibration-box">
                    <h3>SHIFT 1 - BEGINNING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>A/R Report #:</td><td style="text-align: right;">___________</td></tr>
                        <tr><td>Amount:</td><td style="text-align: right;">₱ <?= number_format($shift1_summary['total_sales'], 2) ?></td></tr>
                        <tr><td>Cash Deposit:</td><td style="text-align: right;">₱ <?= number_format($shift1_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <div class="calibration-box">
                    <h3>SHIFT 1 - ENDING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>Cash/Bank:</td><td style="text-align: right;">₱ <?= number_format($shift1_summary['cash'], 2) ?></td></tr>
                        <tr><td>Less Deposit:</td><td style="text-align: right;">₱ 0.00</td></tr>
                        <tr><td style="font-weight: bold;">Cash on Hand:</td><td style="text-align: right; font-weight: bold;">₱ <?= number_format($shift1_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <!-- Shift 2 Calibration -->
                <div class="calibration-box">
                    <h3>SHIFT 2 - BEGINNING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>A/R Report #:</td><td style="text-align: right;">___________</td></tr>
                        <tr><td>Amount:</td><td style="text-align: right;">₱ <?= number_format($shift2_summary['total_sales'], 2) ?></td></tr>
                        <tr><td>Cash Deposit:</td><td style="text-align: right;">₱ <?= number_format($shift2_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <div class="calibration-box">
                    <h3>SHIFT 2 - ENDING</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr><td>Cash/Bank:</td><td style="text-align: right;">₱ <?= number_format($shift2_summary['cash'], 2) ?></td></tr>
                        <tr><td>Less Deposit:</td><td style="text-align: right;">₱ 0.00</td></tr>
                        <tr><td style="font-weight: bold;">Cash on Hand:</td><td style="text-align: right; font-weight: bold;">₱ <?= number_format($shift2_summary['cash'], 2) ?></td></tr>
                    </table>
                </div>
                
                <!-- Overall Summary Box -->
                <div class="calibration-box">
                    <h3>OVERALL SUMMARY</h3>
                    <table style="width: 100%; font-size: 8pt;">
                        <tr>
                            <td style="font-weight: bold;">Total Cash in Bank:</td>
                            <td style="text-align: right; font-weight: bold;">₱ <?= number_format($overall_summary['total_cash'], 2) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Right Column: Meter Reading Table -->
            <div class="right-column">
                <h3 style="text-align: center; font-size: 10pt; margin-bottom: 8px; text-decoration: underline;">METER READING TABLE</h3>
                
                <table class="meter-table">
                    <thead>
                        <tr>
                            <th rowspan="2">PUMP</th>
                            <th rowspan="2">FUEL<br>TYPE</th>
                            <th colspan="2">SHIFT 1</th>
                            <th colspan="2">SHIFT 2</th>
                            <th rowspan="2">TOTAL<br>LITERS</th>
                            <th rowspan="2">AMOUNT</th>
                        </tr>
                        <tr>
                            <th>BEGIN</th>
                            <th>END</th>
                            <th>BEGIN</th>
                            <th>END</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Group readings by pump and fuel type
                        $pump_groups = [];
                        foreach ($meter_readings as $reading) {
                            $key = $reading['pump_name'] . '|' . $reading['fuel_type'];
                            if (!isset($pump_groups[$key])) {
                                $pump_groups[$key] = [
                                    'pump' => $reading['pump_name'],
                                    'fuel' => $reading['fuel_type'],
                                    'shift1_begin' => 0,
                                    'shift1_end' => 0,
                                    'shift2_begin' => 0,
                                    'shift2_end' => 0,
                                    'total_liters' => 0,
                                    'amount' => 0,
                                ];
                            }
                            
                            $shift = $reading['shift_period'] ?? '';
                            if (is_shift1($shift)) {
                                $pump_groups[$key]['shift1_begin'] = $reading['beginning_reading'];
                                $pump_groups[$key]['shift1_end'] = $reading['ending_reading'];
                            } else {
                                $pump_groups[$key]['shift2_begin'] = $reading['beginning_reading'];
                                $pump_groups[$key]['shift2_end'] = $reading['ending_reading'];
                            }
                            $pump_groups[$key]['total_liters'] += $reading['liters_sold'];
                        }
                        
                        // Calculate amounts from volume sales
                        foreach ($pump_groups as $key => &$group) {
                            foreach ($volume_sales as $vol) {
                                if (strpos($group['fuel'], $vol['fuel_type']) !== false) {
                                    $group['amount'] = $group['total_liters'] * $vol['avg_price'];
                                    break;
                                }
                            }
                        }
                        unset($group);
                        
                        $grand_total_liters = 0;
                        $grand_total_amount = 0;
                        
                        foreach ($pump_groups as $group): 
                            $grand_total_liters += $group['total_liters'];
                            $grand_total_amount += $group['amount'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($group['pump']) ?></td>
                            <td><?= htmlspecialchars($group['fuel']) ?></td>
                            <td class="text-right"><?= number_format($group['shift1_begin'], 2) ?></td>
                            <td class="text-right"><?= number_format($group['shift1_end'], 2) ?></td>
                            <td class="text-right"><?= number_format($group['shift2_begin'], 2) ?></td>
                            <td class="text-right"><?= number_format($group['shift2_end'], 2) ?></td>
                            <td class="text-right" style="font-weight: bold;"><?= number_format($group['total_liters'], 2) ?></td>
                            <td class="text-right" style="font-weight: bold;">₱<?= number_format($group['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr style="font-weight: bold;">
                            <td colspan="6" style="text-align: right;">TOTAL</td>
                            <td class="text-right"><?= number_format($grand_total_liters, 2) ?></td>
                            <td class="text-right">₱<?= number_format($grand_total_amount, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Volume Sales Summary -->
                <div class="summary-section">
                    <h3>VOLUME & AMOUNT SUMMARY</h3>
                    <table class="summary-table">
                        <?php foreach ($volume_sales as $vol): ?>
                        <tr>
                            <td class="label"><?= htmlspecialchars($vol['fuel_type']) ?></td>
                            <td><?= number_format($vol['total_liters'], 2) ?> L</td>
                            <td class="value">₱<?= number_format($vol['total_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold;">
                            <td class="label">TOTAL FUEL SALES</td>
                            <td><?= number_format(array_sum(array_column($volume_sales, 'total_liters')), 2) ?> L</td>
                            <td class="value">₱<?= number_format($overall_summary['total_fuel_sales'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="label">MERCHANDISE</td>
                            <td>—</td>
                            <td class="value">₱<?= number_format($overall_summary['total_merchandise_sales'], 2) ?></td>
                        </tr>
                        <tr style="font-weight: bold;">
                            <td class="label">TOTAL SALES</td>
                            <td colspan="2" class="value">₱<?= number_format($overall_summary['total_fuel_sales'] + $overall_summary['total_merchandise_sales'], 2) ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Cash Breakdown -->
                <div class="summary-section">
                    <h3>CASH/BANK IN SUMMARY</h3>
                    <table class="summary-table">
                        <tr>
                            <td class="label">TOTAL CASH IN BANK:</td>
                            <td class="value" style="font-weight: bold;">₱<?= number_format($overall_summary['total_cash'], 2) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- A/R Summary (if exists) -->
        <?php if (count($ar_summary) > 0): ?>
        <div class="summary-section" style="margin-top: 10px;">
            <h3>ACCOUNTS RECEIVABLE (A/R) SUMMARY</h3>
            <table class="meter-table">
                <thead>
                    <tr>
                        <th>Customer Name</th>
                        <th>Type</th>
                        <th>Balance</th>
                        <th>Credit Limit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_ar = 0;
                    foreach ($ar_summary as $ar): 
                        $total_ar += $ar['balance'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($ar['name']) ?></td>
                        <td style="text-align: center;"><?= strtoupper($ar['type']) ?></td>
                        <td class="text-right" style="font-weight: bold;">₱<?= number_format($ar['balance'], 2) ?></td>
                        <td class="text-right">₱<?= number_format($ar['credit_limit'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: bold;">
                        <td colspan="2" style="text-align: right;">TOTAL A/R</td>
                        <td class="text-right">₱<?= number_format($total_ar, 2) ?></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Cashier / Staff</strong><br>
                <small>Printed Name & Signature</small>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <strong>Manager / Supervisor</strong><br>
                <small>Printed Name & Signature</small>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="report-footer">
            <p><strong>Generated on:</strong> <?= date('F d, Y h:i:s A') ?></p>
            <p>Petron Station Management System © 2026</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$page_title = "Fuel Sales Summary Report";

// Include system header
require_once __DIR__ . '/../partials/header.php';
?>

<style>
    body {
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .main-content {
        width: 100%;
        max-width: 100%;
        padding: 0;
        margin: 0;
    }
    
    .container {
        max-width: 100%;
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .header {
        background: #fff;
        color: #000;
        padding: 15px 20px;
        text-align: center;
        border-bottom: 2px solid #000;
        margin-bottom: 0;
    }
    
    .header h1 {
        font-size: 22px;
        margin: 0 0 8px 0;
        font-weight: 700;
        color: #000;
    }
    
    .header p {
        font-size: 12px;
        color: #000;
        margin: 3px 0;
    }
    
    .controls {
        padding: 12px 20px;
        background: #fff;
        border-bottom: 1px solid #000;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .date-controls {
        display: flex;
        gap: 8px;
        align-items: center;
        font-size: 12px;
    }
    
    .date-controls label {
        font-weight: 700;
        color: #000;
    }
    
    .date-controls input[type="date"] {
        padding: 6px 10px;
        border: 1px solid #000;
        font-size: 12px;
    }
    
    .btn {
        padding: 7px 14px;
        border-radius: 4px;
        background: #ffffff;
        border: 1px solid #00264D;
        color: #00264D;
        cursor: pointer;
        font-size: 11px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all .2s;
        white-space: nowrap;
    }
    
    .btn:hover {
        background: #00264D;
        color: #ffffff;
    }
    
    .btn-primary {
        border-color: #00264D;
        color: #00264D;
    }
    
    .btn-primary:hover {
        background: #00264D;
        color: #ffffff;
    }
    
    .tab-navigation {
        display: flex;
        gap: 0;
        border-bottom: 2px solid #000;
        background: #fff;
    }
    
    .tab-btn {
        padding: 12px 24px;
        border: 1px solid #000;
        border-bottom: none;
        background: #fff;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        color: #000;
        transition: all 0.3s ease;
    }
    
    .tab-btn:hover {
        background: #f5f5f5;
    }
    
    .tab-btn.active {
        background: #000;
        color: #fff;
        border-bottom: 2px solid #000;
        margin-bottom: -2px;
    }
    
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .print-area {
        background: #fff;
    }
    
    .content {
        padding: 15px 20px;
    }
    
    .section-title {
        font-size: 16px;
        font-weight: 700;
        margin: 20px 0 10px 0;
        color: #000;
        padding-bottom: 8px;
        border-bottom: 2px solid #000;
        text-transform: uppercase;
    }
    
    .table-container {
        overflow-x: visible;
        margin-bottom: 20px;
        width: 100%;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border: 1px solid #000;
        font-size: 11px;
    }
    
    thead {
        background: #fff;
        color: #000;
    }
    
    th {
        padding: 8px 6px;
        text-align: left;
        font-weight: 700;
        font-size: 10px;
        text-transform: uppercase;
        border: 1px solid #000;
    }
    
    td {
        padding: 6px 6px;
        border: 1px solid #000;
        font-size: 11px;
    }
    
    tbody tr {
        background: #fff;
    }
    
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .font-bold { font-weight: 700; }
    
    .shift-boxes {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin: 20px 0;
    }
    
    .shift-box {
        background: #fff;
        padding: 15px;
        border: 1px solid #000;
    }
    
    .shift-box h3 {
        font-size: 14px;
        color: #000;
        margin: 0 0 10px 0;
        font-weight: 700;
        border-bottom: 1px solid #000;
        padding-bottom: 8px;
        text-transform: uppercase;
    }
    
    .shift-box table {
        font-size: 11px;
    }
    
    .shift-box td {
        padding: 6px 4px;
        border: none;
        border-bottom: 1px solid #ddd;
    }
    
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
        margin: 20px 0;
    }
    
    .summary-card {
        background: #fff;
        padding: 15px;
        border: 1px solid #000;
        text-align: center;
    }
    
    .summary-card .label {
        font-size: 11px;
        color: #000;
        margin-bottom: 8px;
        font-weight: 700;
    }
    
    .summary-card .value {
        font-size: 20px;
        color: #000;
        font-weight: 700;
    }
    
    @media print {
        @page {
            size: legal portrait;
            margin: 0.5in 0.4in;
        }

        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area {
            position: fixed !important; top: 0 !important; left: 0 !important;
            width: 100% !important; margin: 0 !important; padding: 0 !important;
            background: white !important;
        }
        html, body { margin: 0 !important; padding: 0 !important; background: white !important; overflow: visible !important; }
        .container, .content { margin: 0 !important; padding: 0 !important; }

        /* ── Kill ALL icons ── */
        i, svg, .fas, .far, .fab, .fa, [class*="fa-"] {
            display: none !important;
            width: 0 !important; height: 0 !important;
            font-size: 0 !important; line-height: 0 !important;
            margin: 0 !important; padding: 0 !important;
        }

        .header { text-align: center !important; border-bottom: 2px solid #000 !important; padding: 6px 0 !important; margin: 0 0 8px 0 !important; }
        .header h1 { font-size: 16px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 3px 0 !important; }
        .header p { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }
        .section-title { font-size: 12px !important; font-weight: 700 !important; margin: 8px 0 4px 0 !important; padding-bottom: 3px !important; border-bottom: 2px solid #000 !important; page-break-after: avoid !important; }
        .table-container { overflow: visible !important; width: 100% !important; text-align: center !important; }
        table { width: 95% !important; max-width: 100% !important; border-collapse: collapse !important; font-size: 10px !important; table-layout: auto !important; margin: 0 auto 8px auto !important; }
        thead { display: table-header-group !important; }
        tbody { display: table-row-group !important; }
        tr { page-break-inside: avoid !important; }
        th { font-size: 10px !important; padding: 6px 8px !important; border: 1px solid #000 !important; background: #fff !important; color: #000 !important; font-weight: 700 !important; text-align: center !important; white-space: nowrap !important; }
        td { font-size: 9px !important; padding: 5px 8px !important; border: 1px solid #000 !important; white-space: nowrap !important; vertical-align: top !important; }
        .shift-boxes, .shift-summary { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 6px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .shift-box { border: 1px solid #000 !important; padding: 5px !important; font-size: 9px !important; }
        .shift-box h3 { font-size: 10px !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px 0 !important; }
        .shift-box table { width: auto !important; margin: 0 !important; }
        .shift-box td { border: none !important; border-bottom: 1px solid #ddd !important; font-size: 9px !important; }
        .summary-grid { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 5px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .summary-card { border: 1px solid #000 !important; padding: 5px !important; }
        .summary-card .label { font-size: 7px !important; }
        .summary-card .value { font-size: 10px !important; font-weight: 700 !important; }
        .tab-navigation, .tab-btn, .controls { display: none !important; }
        .tab-content { display: block !important; }
        .tab-pane { display: block !important; }
    }
</style>

<!-- CONTROLS - OUTSIDE PRINTABLE AREA -->
<div class="controls">
    <div class="date-controls">
        <label><strong>Report Date:</strong></label>
        <input type="date" id="report_date" value="<?= htmlspecialchars($report_date) ?>" max="<?= $today ?>">
        <button class="btn btn-primary" onclick="applyFilters()">
            <i class="fa-solid fa-filter"></i> Apply
        </button>
    </div>
    
    <div>
        <a href="?export=excel&type=fuel&report_date=<?= urlencode($report_date) ?>" class="btn" id="export-excel-btn" style="border-color:#16a34a;color:#16a34a;">
            <i class="fa-solid fa-file-excel"></i> Export Excel
        </a>
        <button class="btn" onclick="window.print()" style="border-color:#475569;color:#475569;">
            <i class="fa-solid fa-print"></i> Print Report
        </button>
    </div>
</div>

<!-- TAB NAVIGATION -->
<div class="tab-navigation">
    <button class="tab-btn active" onclick="switchTab('fuel')">DAILY FUEL SALES REPORT</button>
    <button class="tab-btn" onclick="switchTab('merchandise')">DAILY MERCHANDISE SALES REPORT</button>
</div>

<!-- PRINTABLE DOCUMENT AREA -->
<div class="print-area">
    <!-- FUEL TAB CONTENT -->
    <div id="fuel-tab" class="tab-content active">
        <div class="container">
            <div class="header">
                <h1>DAILY FUEL SALES REPORT</h1>
                <h1 style="font-size: 18px; margin-top: 5px;">24-HOUR SUMMARY</h1>
                <p><?= htmlspecialchars($station_name) ?> <?= $station_location ? '- ' . htmlspecialchars($station_location) : '' ?></p>
                <p><strong>Date:</strong> <?= date('F d, Y', strtotime($report_date)) ?></p>
            </div>
        
        <div class="content">
            <!-- Meter Readings Table -->
            <div class="section-title">
                METER READING TABLE
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Pump Name</th>
                            <th>Fuel Type</th>
                            <th class="text-right">Beginning</th>
                            <th class="text-right">Ending</th>
                            <th class="text-right">Calibration</th>
                            <th class="text-right">Volume (Liters)</th>
                            <th class="text-right">Price</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_liters_meter = 0;
                        $total_amount_meter = 0;
                        if (count($meter_readings) > 0):
                            foreach ($meter_readings as $reading): 
                                $total_liters_meter += $reading['liters_sold'];
                                // Get price from volume_sales
                                $price = 0;
                                $amount = 0;
                                foreach ($volume_sales as $vol) {
                                    if (strpos($reading['fuel_type'], $vol['fuel_type']) !== false || strpos($vol['fuel_type'], $reading['fuel_type']) !== false) {
                                        $price = $vol['avg_price'];
                                        $amount = $reading['liters_sold'] * $price;
                                        break;
                                    }
                                }
                                $total_amount_meter += $amount;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($reading['pump_name']) ?></strong></td>
                            <td><?= htmlspecialchars($reading['fuel_type']) ?></td>
                            <td class="text-right"><?= number_format($reading['beginning_reading'], 2) ?></td>
                            <td class="text-right"><?= number_format($reading['ending_reading'], 2) ?></td>
                            <td class="text-right"><?= number_format($reading['calibration'], 2) ?></td>
                            <td class="text-right font-bold"><?= number_format($reading['liters_sold'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($price, 2) ?></td>
                            <td class="text-right font-bold">₱<?= number_format($amount, 2) ?></td>
                        </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                No meter readings found for this date.
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (count($meter_readings) > 0): ?>
                        <tr style="font-weight: 700;">
                            <td colspan="5" class="text-right">TOTAL</td>
                            <td class="text-right"><?= number_format($total_liters_meter, 2) ?></td>
                            <td></td>
                            <td class="text-right">₱<?= number_format($total_amount_meter, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Volume Sales Summary -->
            <div class="section-title">
                VOLUME SALES SUMMARY
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Fuel Type</th>
                            <th class="text-right">Total Liters</th>
                            <th class="text-right">Avg Price/L</th>
                            <th class="text-right">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_amount_vol = 0;
                        if (count($volume_sales) > 0):
                            foreach ($volume_sales as $vol): 
                                $total_amount_vol += $vol['total_amount'];
                        ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($vol['fuel_type']) ?></td>
                            <td class="text-right"><?= number_format($vol['total_liters'], 2) ?> L</td>
                            <td class="text-right">₱<?= number_format($vol['avg_price'], 2) ?></td>
                            <td class="text-right font-bold">₱<?= number_format($vol['total_amount'], 2) ?></td>
                        </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px;">
                                No volume sales data available.
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (count($volume_sales) > 0): ?>
                        <tr style="font-weight: 700;">
                            <td>TOTAL</td>
                            <td class="text-right"><?= number_format(array_sum(array_column($volume_sales, 'total_liters')), 2) ?> L</td>
                            <td class="text-right">-</td>
                            <td class="text-right">₱<?= number_format($total_amount_vol, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Tank Sales Summary -->
            <div class="section-title">
                TANK SALES SUMMARY
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Tank / Fuel Type</th>
                            <th class="text-right">Tank Capacity</th>
                            <th class="text-right">Dispensed Liters</th>
                            <th class="text-right">Utilization %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (count($tank_sales) > 0):
                            foreach ($tank_sales as $tank): 
                        ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($tank['fuel_type']) ?></td>
                            <td class="text-right">-</td>
                            <td class="text-right"><?= number_format($tank['total_dispensed'], 2) ?> L</td>
                            <td class="text-right">-</td>
                        </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px;">
                                No tank sales data available.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Shift Summaries -->
            <div class="section-title">
                SHIFT FUEL SALES & CASH SUMMARIES
            </div>
            <div class="shift-boxes">
                <!-- Shift 1 -->
                <div class="shift-box">
                    <h3>SHIFT 1 (6AM-2PM)</h3>
                    <table>
                        <tbody>
                            <tr>
                                <td class="font-bold">Total Fuel Sales:</td>
                                <td class="text-right font-bold">₱<?= number_format($shift1_summary['fuel_sales'], 2) ?></td>
                            </tr>
                            <tr><td colspan="2" style="height: 10px;"></td></tr>
                            <tr>
                                <td colspan="2" class="font-bold">Payment Breakdown</td>
                            </tr>
                            <tr>
                                <td>Cash:</td>
                                <td class="text-right">₱<?= number_format($shift1_summary['cash'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>Card:</td>
                                <td class="text-right">₱<?= number_format($shift1_summary['card'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>E-Wallet:</td>
                                <td class="text-right">₱<?= number_format($shift1_summary['ewallet'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>Credit/Suki:</td>
                                <td class="text-right">₱<?= number_format($shift1_summary['credit'], 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Shift 2 -->
                <div class="shift-box">
                    <h3>SHIFT 2 (2PM-12AM)</h3>
                    <table>
                        <tbody>
                            <tr>
                                <td class="font-bold">Total Fuel Sales:</td>
                                <td class="text-right font-bold">₱<?= number_format($shift2_summary['fuel_sales'], 2) ?></td>
                            </tr>
                            <tr><td colspan="2" style="height: 10px;"></td></tr>
                            <tr>
                                <td colspan="2" class="font-bold">Payment Breakdown</td>
                            </tr>
                            <tr>
                                <td>Cash:</td>
                                <td class="text-right">₱<?= number_format($shift2_summary['cash'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>Card:</td>
                                <td class="text-right">₱<?= number_format($shift2_summary['card'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>E-Wallet:</td>
                                <td class="text-right">₱<?= number_format($shift2_summary['ewallet'], 2) ?></td>
                            </tr>
                            <tr>
                                <td>Credit/Suki:</td>
                                <td class="text-right">₱<?= number_format($shift2_summary['credit'], 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- A/R Summary -->
            <?php if (count($ar_summary) > 0): ?>
            <div class="section-title">
                A/R SUMMARY (Suki/Credit Customers)
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Customer Name</th>
                            <th>Contact Number</th>
                            <th>Type</th>
                            <th class="text-right">Outstanding Balance</th>
                            <th class="text-right">Credit Limit</th>
                            <th class="text-right">Available Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_ar = 0;
                        foreach ($ar_summary as $ar): 
                            $total_ar += $ar['balance'];
                            $available = $ar['credit_limit'] - $ar['balance'];
                        ?>
                        <tr>
                            <td class="font-bold"><?= htmlspecialchars($ar['name']) ?></td>
                            <td><?= htmlspecialchars($ar['contact_number'] ?? '-') ?></td>
                            <td><?= strtoupper($ar['type']) ?></td>
                            <td class="text-right font-bold">₱<?= number_format($ar['balance'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($ar['credit_limit'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($available, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: 700;">
                            <td colspan="3" class="text-right">TOTAL ACCOUNTS RECEIVABLE</td>
                            <td class="text-right">₱<?= number_format($total_ar, 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Overall Daily Summary -->
            <div class="section-title">
                OVERALL DAILY FUEL SUMMARY
            </div>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="label">Total Fuel Sales</div>
                    <div class="value">₱<?= number_format($overall_summary['total_fuel_sales'], 2) ?></div>
                </div>
                
                <div class="summary-card">
                    <div class="label">Total Liters Sold</div>
                    <div class="value"><?= number_format($overall_summary['total_liters'], 2) ?> L</div>
                </div>
                
                <div class="summary-card">
                    <div class="label">Total Cash Collection</div>
                    <div class="value">₱<?= number_format($overall_summary['total_cash'], 2) ?></div>
                </div>
                
                <div class="summary-card">
                    <div class="label">Total Deposits</div>
                    <div class="value">₱<?= number_format($overall_summary['total_deposits'], 2) ?></div>
                </div>
            </div>
            
            <!-- Total Cash in Bank -->
            <div class="section-title">
                TOTAL CASH IN BANK
            </div>
            <div class="table-container">
                <table>
                    <tbody>
                        <tr>
                            <td class="font-bold" style="width: 70%;">Cash on Hand:</td>
                            <td class="text-right font-bold" style="font-size: 18px;">₱<?= number_format($overall_summary['total_cash'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="font-bold">Deposits Made Today:</td>
                            <td class="text-right font-bold" style="font-size: 18px;">₱<?= number_format($overall_summary['total_deposits'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="font-bold" style="font-size: 18px;">TOTAL CASH IN BANK:</td>
                            <td class="text-right font-bold" style="font-size: 24px;">₱<?= number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>
    </div>
    <!-- END FUEL TAB -->
    
    <!-- MERCHANDISE TAB CONTENT -->
    <div id="merchandise-tab" class="tab-content">
        <div class="container">
            <div class="header">
                <h1>DAILY MERCHANDISE & SERVICE SALES REPORT</h1>
                <h1 style="font-size: 18px; margin-top: 5px;">24-HOUR SUMMARY</h1>
                <p><?= htmlspecialchars($station_name) ?> <?= $station_location ? '- ' . htmlspecialchars($station_location) : '' ?></p>
                <p><strong>Date:</strong> <?= date('F d, Y', strtotime($report_date)) ?></p>
            </div>
            
            <div class="content">
                <!-- Merchandise Sales Table -->
                <div class="section-title">
                    MERCHANDISE SALES TABLE
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Product Name</th>
                                <th class="text-right">Beginning Stock</th>
                                <th class="text-right">Stock-In</th>
                                <th class="text-right">Stock-Out</th>
                                <th class="text-right">Ending Stock</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Amount</th>
                                <th>Encoder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Fetch merchandise transactions for the day
                            $merch_transactions = $processed_merch;
                            $total_merch_amount = 0;
                            
                            if (count($merch_transactions) > 0):
                                foreach ($merch_transactions as $trans): 
                                    $total_merch_amount += $trans['total_amount'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($trans['category']) ?></td>
                                <td class="font-bold"><?= htmlspecialchars($trans['product_name']) ?></td>
                                <td class="text-right">—</td>
                                <td class="text-right">—</td>
                                <td class="text-right"><?= number_format($trans['stock_out'], 2) ?></td>
                                <td class="text-right">—</td>
                                <td class="text-right">₱<?= number_format($trans['unit_price'], 2) ?></td>
                                <td class="text-right font-bold">₱<?= number_format($trans['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars($trans['encoder'] ?? 'N/A') ?></td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px;">
                                    No merchandise sales found for this date.
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (count($merch_transactions) > 0): ?>
                            <tr style="font-weight: 700;">
                                <td colspan="7" class="text-right">TOTAL</td>
                                <td class="text-right">₱<?= number_format($total_merch_amount, 2) ?></td>
                                <td></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Service Income Table -->
                <div class="section-title">
                    SERVICE INCOME TABLE
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Service Type</th>
                                <th class="text-right">Labor Fee</th>
                                <th>Parts Used</th>
                                <th class="text-right">Total Service Amount</th>
                                <th>Encoder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Fetch service transactions for the day
                            $service_transactions = $processed_services;
                            $total_service_amount = 0;
                            
                            if (count($service_transactions) > 0):
                                foreach ($service_transactions as $trans): 
                                    $total_service_amount += $trans['total_amount'];
                            ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($trans['service_type']) ?></td>
                                <td class="text-right">₱<?= number_format($trans['labor_fee'], 2) ?></td>
                                <td><?= htmlspecialchars($trans['parts_used'] ?? '—') ?></td>
                                <td class="text-right font-bold">₱<?= number_format($trans['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars($trans['encoder'] ?? 'N/A') ?></td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px;">
                                    No service income found for this date.
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if (count($service_transactions) > 0): ?>
                            <tr style="font-weight: 700;">
                                <td colspan="3" class="text-right">TOTAL</td>
                                <td class="text-right">₱<?= number_format($total_service_amount, 2) ?></td>
                                <td></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Shift Summaries -->
                <div class="section-title">
                    SHIFT SALES SUMMARIES
                </div>
                <div class="shift-boxes">
                    <!-- Shift 1 -->
                    <div class="shift-box">
                        <h3>SHIFT 1 (6AM-2PM)</h3>
                        <table>
                            <tbody>
                                <tr>
                                    <td class="font-bold">Merchandise Sales:</td>
                                    <td class="text-right font-bold">₱<?= number_format($shift1_summary['merchandise_sales'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td class="font-bold">Service Income:</td>
                                    <td class="text-right font-bold">₱<?= number_format($shift1_summary['service_income'], 2) ?></td>
                                </tr>
                                <tr><td colspan="2" style="height: 10px;"></td></tr>
                                <tr>
                                    <td colspan="2" class="font-bold">Payment Breakdown</td>
                                </tr>
                                <tr>
                                    <td>Cash:</td>
                                    <td class="text-right">₱<?= number_format($shift1_summary['cash'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Card:</td>
                                    <td class="text-right">₱<?= number_format($shift1_summary['card'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>E-Wallet:</td>
                                    <td class="text-right">₱<?= number_format($shift1_summary['ewallet'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Credit/Suki:</td>
                                    <td class="text-right">₱<?= number_format($shift1_summary['credit'], 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Shift 2 -->
                    <div class="shift-box">
                        <h3>SHIFT 2 (2PM-12AM)</h3>
                        <table>
                            <tbody>
                                <tr>
                                    <td class="font-bold">Merchandise Sales:</td>
                                    <td class="text-right font-bold">₱<?= number_format($shift2_summary['merchandise_sales'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td class="font-bold">Service Income:</td>
                                    <td class="text-right font-bold">₱<?= number_format($shift2_summary['service_income'], 2) ?></td>
                                </tr>
                                <tr><td colspan="2" style="height: 10px;"></td></tr>
                                <tr>
                                    <td colspan="2" class="font-bold">Payment Breakdown</td>
                                </tr>
                                <tr>
                                    <td>Cash:</td>
                                    <td class="text-right">₱<?= number_format($shift2_summary['cash'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Card:</td>
                                    <td class="text-right">₱<?= number_format($shift2_summary['card'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>E-Wallet:</td>
                                    <td class="text-right">₱<?= number_format($shift2_summary['ewallet'], 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Credit/Suki:</td>
                                    <td class="text-right">₱<?= number_format($shift2_summary['credit'], 2) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- A/R Summary -->
                <?php if (count($ar_summary) > 0): ?>
                <div class="section-title">
                    A/R SUMMARY (Suki/Credit Customers)
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Customer Name</th>
                                <th>Contact Number</th>
                                <th>Type</th>
                                <th class="text-right">Outstanding Balance</th>
                                <th class="text-right">Credit Limit</th>
                                <th class="text-right">Available Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_ar = 0;
                            foreach ($ar_summary as $ar): 
                                $total_ar += $ar['balance'];
                                $available = $ar['credit_limit'] - $ar['balance'];
                            ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($ar['name']) ?></td>
                                <td><?= htmlspecialchars($ar['contact_number'] ?? '-') ?></td>
                                <td><?= strtoupper($ar['type']) ?></td>
                                <td class="text-right font-bold">₱<?= number_format($ar['balance'], 2) ?></td>
                                <td class="text-right">₱<?= number_format($ar['credit_limit'], 2) ?></td>
                                <td class="text-right">₱<?= number_format($available, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight: 700;">
                                <td colspan="3" class="text-right">TOTAL ACCOUNTS RECEIVABLE</td>
                                <td class="text-right">₱<?= number_format($total_ar, 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Overall Summary -->
                <div class="section-title">
                    OVERALL DAILY MERCHANDISE SUMMARY
                </div>
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="label">Total Merchandise Sales</div>
                        <div class="value">₱<?= number_format($overall_summary['total_merchandise_sales'], 2) ?></div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="label">Total Service Income</div>
                        <div class="value">₱<?= number_format($total_service_amount, 2) ?></div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="label">Grand Total Sales</div>
                        <div class="value">₱<?= number_format($overall_summary['total_merchandise_sales'] + $total_service_amount, 2) ?></div>
                    </div>
                    
                    <div class="summary-card">
                        <div class="label">Total Cash Collection</div>
                        <div class="value">₱<?= number_format($overall_summary['total_cash'], 2) ?></div>
                    </div>
                </div>
                
                <!-- Total Cash in Bank -->
                <div class="section-title">
                    TOTAL CASH IN BANK
                </div>
                <div class="table-container">
                    <table>
                        <tbody>
                            <tr>
                                <td class="font-bold" style="width: 70%;">Cash on Hand:</td>
                                <td class="text-right font-bold" style="font-size: 18px;">₱<?= number_format($overall_summary['total_cash'], 2) ?></td>
                            </tr>
                            <tr>
                                <td class="font-bold">Deposits Made Today:</td>
                                <td class="text-right font-bold" style="font-size: 18px;">₱<?= number_format($overall_summary['total_deposits'], 2) ?></td>
                            </tr>
                            <tr>
                                <td class="font-bold" style="font-size: 18px;">TOTAL CASH IN BANK:</td>
                                <td class="text-right font-bold" style="font-size: 24px;">₱<?= number_format($overall_summary['total_cash'] + $overall_summary['total_deposits'], 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <!-- END MERCHANDISE TAB -->
</div>

<script>
    function switchTab(tabName) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Remove active from all buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById(tabName + '-tab').classList.add('active');
        
        // Highlight selected button
        event.target.classList.add('active');
        
        // Update export button
        const exportBtn = document.getElementById('export-excel-btn');
        const reportDate = document.getElementById('report_date').value;
        if (tabName === 'merchandise') {
            exportBtn.href = `?export=excel&type=merchandise&report_date=${reportDate}`;
        } else {
            exportBtn.href = `?export=excel&type=fuel&report_date=${reportDate}`;
        }
    }
    
    function applyFilters() {
        const reportDate = document.getElementById('report_date').value;
        window.location.href = `?report_date=${reportDate}`;
    }
    
    // Allow Enter key to apply filters
    document.getElementById('report_date').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
</script>

<?php
// Include system footer
require_once __DIR__ . '/../partials/footer.php';
?>
