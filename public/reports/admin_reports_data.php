<?php
/**
 * ADMIN REPORTS DATA FETCHING LAYER
 * Fetches accurate data for all 7 Admin Report Categories & Sub-Tabs
 */

if (!defined('PETRON_SYSTEM')) {
    define('PETRON_SYSTEM', true);
}

if (!function_exists('get_exact_ugt_no')) {
    function get_exact_ugt_no(string $rawFuelType): string {
        $s = strtoupper(trim($rawFuelType));
        
        // Explicit check for DIESEL 2 vs DIESEL 1
        if (strpos($s, 'DIESEL 2') !== false || strpos($s, 'DIESEL-2') !== false || strpos($s, 'UGT #2') !== false || strpos($s, 'UGT-02') !== false || strpos($s, 'UGT 2') !== false) return 'UGT #2';
        if (strpos($s, 'DIESEL 1') !== false || strpos($s, 'DIESEL-1') !== false || strpos($s, 'UGT #1') !== false || strpos($s, 'UGT-01') !== false || strpos($s, 'UGT 1') !== false) return 'UGT #1';
        
        // Explicit check for XTRA UNL 2 vs XTRA UNL 1
        if (strpos($s, 'XTRA UNL 2') !== false || strpos($s, 'XTRA 2') !== false || strpos($s, 'UNL 2') !== false || strpos($s, 'UGT #6') !== false || strpos($s, 'UGT-06') !== false || strpos($s, 'UGT 6') !== false) return 'UGT #6';
        if (strpos($s, 'XTRA UNL 1') !== false || strpos($s, 'XTRA 1') !== false || strpos($s, 'UNL 1') !== false || strpos($s, 'UGT #4') !== false || strpos($s, 'UGT-04') !== false || strpos($s, 'UGT 4') !== false) return 'UGT #4';
        
        // Check TURBO DIESEL
        if (strpos($s, 'TURBO') !== false || strpos($s, 'UGT #5') !== false || strpos($s, 'UGT-05') !== false || strpos($s, 'UGT 5') !== false) return 'UGT #5';
        
        // Check XCS PLUS
        if (strpos($s, 'XCS') !== false || strpos($s, 'UGT #3') !== false || strpos($s, 'UGT-03') !== false || strpos($s, 'UGT 3') !== false) return 'UGT #3';
        
        // Check KEROSENE
        if (strpos($s, 'KEROSENE') !== false || strpos($s, 'UGT #7') !== false || strpos($s, 'UGT-07') !== false || strpos($s, 'UGT 7') !== false) return 'UGT #7';
        
        // Fallback checks
        if (strpos($s, 'DIESEL') !== false) {
            if (strpos($s, '2') !== false) return 'UGT #2';
            return 'UGT #1';
        }
        if (strpos($s, 'XTRA') !== false || strpos($s, 'UNL') !== false) {
            if (strpos($s, '2') !== false) return 'UGT #6';
            return 'UGT #4';
        }
        return 'UGT #1';
    }
}

if (!function_exists('staff_report_fuel_display_name')) {
    function staff_report_fuel_display_name($fuel_type): string {
        $name = trim((string)$fuel_type);
        $name = preg_replace('/\s+\d+\s*-\s*\d+$/', '', $name);
        $name = preg_replace('/\s*-\s*\d+$/', '', $name);
        $name = trim($name);
        $normalized = strtoupper(preg_replace('/\s+/', ' ', $name));
        if (strpos($normalized, 'TURBO') !== false && strpos($normalized, 'DIESEL') !== false) return 'Turbo Diesel';
        if (strpos($normalized, 'KEROSENE') !== false) return 'Kerosene';
        if (strpos($normalized, 'XCS') !== false) return 'XCS Plus';
        if (strpos($normalized, 'XTRA') !== false && strpos($normalized, 'UNL') !== false) return 'Xtra UNL';
        if (strpos($normalized, 'DIESEL') !== false) return 'Diesel';
        return $name !== '' ? $name : 'Fuel';
    }
}

if (!function_exists('getAdminReportData')) {
    function getAdminReportData(PDO $pdo, int $station_id, string $date_from, string $date_to, string $cat, string $tab, array $filters = []): array {
    $data = [];

    // Helper for table-qualified station_id clause
    $st_params = ($station_id > 0) ? ['station_id' => $station_id] : [];
    $st_clause = function(string $alias) use ($station_id): string {
        if ($station_id <= 0) return "";
        return " AND {$alias}.station_id = :station_id ";
    };

    switch ($cat) {

        // =========================================================================
        // 1. SALES REPORTS
        // =========================================================================
        case 'sales':
            if ($tab === 'fuel_sales') {
                // ---------------------------------------------------------------
                // FUEL SALES REPORT - UGT Daily Reconciliation (Back Office)
                // Combines Shift 1 + Shift 2 into ONE daily report per pump/UGT
                // NO: customer, receipt, payment method, attendant, shift columns
                // 5 CORE FUEL TYPES: Diesel, Turbo Diesel, XCS Plus, XTR Advance, Kerosene
                // ---------------------------------------------------------------
                
                $sql_norm = "CASE 
                    WHEN UPPER(ft.fuel_type) LIKE '%TURBO%' THEN 'Turbo Diesel'
                    WHEN UPPER(ft.fuel_type) LIKE '%DIESEL%' THEN 'Diesel'
                    WHEN UPPER(ft.fuel_type) LIKE '%XCS%' THEN 'XCS Plus'
                    WHEN UPPER(ft.fuel_type) LIKE '%XTRA%' OR UPPER(ft.fuel_type) LIKE '%UNL%' THEN 'XTR Advance'
                    WHEN UPPER(ft.fuel_type) LIKE '%KEROSENE%' THEN 'Kerosene'
                    ELSE ft.fuel_type
                END";

                $extra_where  = "";
                $extra_params = [];
                if (!empty($filters['fuel_type'])) {
                    $extra_where .= " AND ({$sql_norm}) = :filter_fuel_type ";
                    $extra_params['filter_fuel_type'] = $filters['fuel_type'];
                }
                if (!empty($filters['pump_id'])) {
                    $extra_where .= " AND ft.pump_id = :filter_pump_id ";
                    $extra_params['filter_pump_id'] = (int)$filters['pump_id'];
                }
                $base_params = array_merge(['date_from' => $date_from, 'date_to' => $date_to], $st_params, $extra_params);

                // Map pump_ids to clean UGT-01, UGT-02, ... labels
                $all_pumps_stmt = $pdo->prepare(
                    "SELECT DISTINCT ft.pump_id
                     FROM fuel_transactions ft
                     WHERE 1=1 {$st_clause('ft')}
                     ORDER BY ft.pump_id ASC"
                );
                $all_pumps_stmt->execute($st_params);
                $all_pump_ids = $all_pumps_stmt->fetchAll(PDO::FETCH_COLUMN);

                $ugt_map = [];
                $pump_list = [];
                foreach ($all_pump_ids as $idx => $pid) {
                    $code = sprintf('UGT-%02d', $idx + 1);
                    $ugt_map[$pid] = $code;
                    $pump_list[] = ['pump_id' => $pid, 'label' => $code];
                }
                $data['pump_list'] = $pump_list;

                // 1. UGT DAILY SALES TABLE — one row per pump per day
                $stmt = $pdo->prepare(
                    "SELECT ft.pump_id,
                            ft.fuel_type as raw_fuel_type,
                            {$sql_norm} as clean_fuel_type,
                            DATE(ft.transaction_date) as report_date,
                            MIN(ft.previous_reading) as beginning_reading,
                            MAX(ft.present_reading)  as ending_reading,
                            SUM(COALESCE(ft.calibration, 0)) as total_calibration,
                            SUM(COALESCE(ft.liters_sold, 0)) as net_volume_sold,
                            MAX(ft.price_per_liter) as price_per_liter,
                            SUM(COALESCE(ft.total_amount, 0)) as total_fuel_sales,
                            COUNT(ft.id) as shift_count
                     FROM fuel_transactions ft
                     LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
                     WHERE DATE(ft.transaction_date) BETWEEN :date_from AND :date_to
                       {$st_clause('ft')} {$extra_where}
                     GROUP BY ft.pump_id, ft.fuel_type, DATE(ft.transaction_date)
                     ORDER BY ft.fuel_type ASC, ft.pump_id ASC"
                );
                $stmt->execute($base_params);
                $raw_ugt = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($raw_ugt as &$r) {
                    $rawFuel = $r['raw_fuel_type'] ?? '';
                    $r['ugt_no'] = get_exact_ugt_no($rawFuel);
                    $r['fuel_type'] = !empty($rawFuel) ? $rawFuel : ($r['clean_fuel_type'] ?? 'Fuel');
                }
                $data['ugt_rows'] = $raw_ugt;

                // 2. DAILY FUEL SALES SUMMARY — per normalized 5 fuel types
                $stmt2 = $pdo->prepare(
                    "SELECT 
                        cat.norm_fuel_type as fuel_type,
                        COUNT(DISTINCT cat.pump_id) as ugt_count,
                        SUM(COALESCE(cat.liters_sold, 0)) as total_volume,
                        MAX(cat.price_per_liter) as avg_price,
                        SUM(COALESCE(cat.total_amount, 0)) as total_sales
                    FROM (
                        SELECT ft.pump_id, ft.liters_sold, ft.price_per_liter, ft.total_amount,
                               {$sql_norm} as norm_fuel_type
                        FROM fuel_transactions ft
                        WHERE DATE(ft.transaction_date) BETWEEN :date_from AND :date_to
                          {$st_clause('ft')} {$extra_where}
                    ) cat
                    GROUP BY cat.norm_fuel_type
                    ORDER BY total_sales DESC"
                );
                $stmt2->execute($base_params);
                $data['fuel_summary'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                // 3. DAILY RECONCILIATION SUMMARY
                $stmt3 = $pdo->prepare(
                    "SELECT COUNT(DISTINCT ft.pump_id) as total_ugts,
                            MIN(ft.previous_reading) as total_beginning,
                            MAX(ft.present_reading)  as total_ending,
                            SUM(COALESCE(ft.calibration, 0)) as total_calibration,
                            SUM(COALESCE(ft.liters_sold, 0)) as total_volume_sold,
                            SUM(COALESCE(ft.total_amount, 0)) as total_fuel_sales
                     FROM fuel_transactions ft
                     WHERE DATE(ft.transaction_date) BETWEEN :date_from AND :date_to
                       {$st_clause('ft')} {$extra_where}"
                );
                $stmt3->execute($base_params);
                $data['reconciliation'] = $stmt3->fetch(PDO::FETCH_ASSOC);

                // 4. VARIANCE CHECK — per normalized 5 fuel types
                $stmt4 = $pdo->prepare(
                    "SELECT 
                        cat.norm_fuel_type as fuel_type,
                        SUM(COALESCE(cat.liters_sold,0) * COALESCE(cat.price_per_liter,0)) as expected_sales,
                        SUM(COALESCE(cat.total_amount,0)) as recorded_sales,
                        ROUND(SUM(COALESCE(cat.total_amount,0)) - SUM(COALESCE(cat.liters_sold,0) * COALESCE(cat.price_per_liter,0)), 2) as variance
                    FROM (
                        SELECT ft.liters_sold, ft.price_per_liter, ft.total_amount,
                               {$sql_norm} as norm_fuel_type
                        FROM fuel_transactions ft
                        WHERE DATE(ft.transaction_date) BETWEEN :date_from AND :date_to
                          {$st_clause('ft')} {$extra_where}
                    ) cat
                    GROUP BY cat.norm_fuel_type
                    ORDER BY cat.norm_fuel_type ASC"
                );
                $stmt4->execute($base_params);
                $data['variance'] = $stmt4->fetchAll(PDO::FETCH_ASSOC);

                // 5. Filter dropdowns
                try {
                    $stmt6 = $pdo->prepare(
                        "SELECT DISTINCT {$sql_norm} as fuel_type
                         FROM fuel_transactions ft WHERE 1=1 {$st_clause('ft')} ORDER BY fuel_type ASC"
                    );
                    $stmt6->execute($st_params);
                    $data['fuel_types'] = $stmt6->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {
                    $data['fuel_types'] = [];
                }

            } else { // daily_merch_service
                // ---------------------------------------------------------------
                // DAILY MERCHANDISE & SERVICE SALES REPORT (24-Hour Summary)
                // Real DB queries with full dynamic filters & 5 summary sections
                // ---------------------------------------------------------------

                $filter_pm     = $filters['payment_method'] ?? '';
                $filter_ttype  = $filters['transaction_type'] ?? ''; // 'merchandise', 'job_order', or '' (both)
                $filter_cust   = trim($filters['customer'] ?? '');
                $filter_mech   = trim($filters['mechanic'] ?? '');
                $filter_status = $filters['status'] ?? '';

                // 1. MERCHANDISE SALES TRANSACTIONS TABLE
                $data['merchandise'] = [];
                if ($filter_ttype === '' || $filter_ttype === 'merchandise') {
                    $m_where = " AND LOWER(COALESCE(mt.transaction_type,'')) NOT IN ('job_order','service') ";
                    $m_params = ['date_from' => $date_from, 'date_to' => $date_to];

                    if (!empty($filter_pm)) {
                        $m_where .= " AND LOWER(COALESCE(mt.payment_method,'')) = LOWER(:filter_pm) ";
                        $m_params['filter_pm'] = $filter_pm;
                    }
                    if (!empty($filter_cust)) {
                        $m_where .= " AND (LOWER(mt.customer_name) LIKE LOWER(:filter_cust) OR LOWER(mt.customer_first_name) LIKE LOWER(:filter_cust) OR LOWER(mt.customer_last_name) LIKE LOWER(:filter_cust)) ";
                        $m_params['filter_cust'] = '%' . $filter_cust . '%';
                    }
                    if (!empty($filter_status)) {
                        $m_where .= " AND LOWER(COALESCE(mt.workflow_status, mt.validation_status, '')) = LOWER(:filter_status) ";
                        $m_params['filter_status'] = $filter_status;
                    }

                    $sql_merch = "SELECT 
                                    mt.transaction_id as receipt_no,
                                    DATE(mt.transaction_date) as date,
                                    COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                                    COALESCE(mti.category, 'General') as category,
                                    COALESCE(mti.product_name, mt.item_sku, 'Merchandise Product') as product,
                                    COALESCE(mti.quantity, mt.quantity, 1) as quantity,
                                    COALESCE(mti.unit_price, mt.unit_price, 0) as unit_price,
                                    COALESCE(mti.subtotal, mt.total_amount, 0) as amount,
                                    COALESCE(mt.payment_method, 'Cash') as payment_method
                                  FROM merchandise_transactions mt
                                  LEFT JOIN merchandise_transaction_items mti ON mt.id = mti.transaction_id AND (mti.item_type IS NULL OR mti.item_type = 'merchandise')
                                  WHERE DATE(mt.transaction_date) BETWEEN :date_from AND :date_to
                                    {$st_clause('mt')}
                                    {$m_where}
                                  ORDER BY mt.transaction_date DESC";
                    $stmt_m = $pdo->prepare($sql_merch);
                    $stmt_m->execute(array_merge($m_params, $st_params));
                    $data['merchandise'] = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
                }

                // 2. JOB ORDER SERVICE TRANSACTIONS TABLE
                $data['job_orders'] = [];
                if ($filter_ttype === '' || $filter_ttype === 'job_order') {
                    $j_where = " AND LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service') ";
                    $j_params = ['date_from' => $date_from, 'date_to' => $date_to];

                    if (!empty($filter_pm)) {
                        $j_where .= " AND LOWER(COALESCE(mt.payment_method,'')) = LOWER(:j_filter_pm) ";
                        $j_params['j_filter_pm'] = $filter_pm;
                    }
                    if (!empty($filter_cust)) {
                        $j_where .= " AND (LOWER(mt.customer_name) LIKE LOWER(:j_filter_cust) OR LOWER(mt.customer_first_name) LIKE LOWER(:j_filter_cust) OR LOWER(mt.customer_last_name) LIKE LOWER(:j_filter_cust)) ";
                        $j_params['j_filter_cust'] = '%' . $filter_cust . '%';
                    }
                    if (!empty($filter_mech)) {
                        $j_where .= " AND LOWER(COALESCE(mt.job_order_mechanic_name,'')) LIKE LOWER(:j_filter_mech) ";
                        $j_params['j_filter_mech'] = '%' . $filter_mech . '%';
                    }
                    if (!empty($filter_status)) {
                        $j_where .= " AND LOWER(COALESCE(mt.workflow_status, mt.validation_status, '')) = LOWER(:j_filter_status) ";
                        $j_params['j_filter_status'] = $filter_status;
                    }

                    $sql_jo = "SELECT 
                                COALESCE(NULLIF(mt.job_order_id,''), mt.transaction_id) as jo_no,
                                DATE(mt.transaction_date) as date,
                                COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                                CONCAT(COALESCE(mt.job_order_vehicle_plate,'N/A'), ' - ', COALESCE(mt.job_order_vehicle_type,'Vehicle')) as vehicle,
                                COALESCE(NULLIF(mt.job_order_mechanic_name,''), 'Unassigned') as mechanic,
                                COALESCE(NULLIF(mt.job_order_service,''), 'Vehicle Service') as service,
                                COALESCE(mt.subtotal_amount, 0) as labor_fee,
                                COALESCE(mt.vat_amount, 0) as service_fee,
                                GREATEST(COALESCE(mt.total_amount,0) - COALESCE(mt.subtotal_amount,0) - COALESCE(mt.vat_amount,0), 0) as parts_cost,
                                COALESCE(mt.total_amount, 0) as total_amount,
                                COALESCE(mt.payment_method, 'Cash') as payment_method,
                                COALESCE(mt.workflow_status, mt.validation_status, 'Completed') as status
                               FROM merchandise_transactions mt
                               WHERE DATE(mt.transaction_date) BETWEEN :date_from AND :date_to
                                 {$st_clause('mt')}
                                 {$j_where}
                               ORDER BY mt.transaction_date DESC";
                    $stmt_j = $pdo->prepare($sql_jo);
                    $stmt_j->execute(array_merge($j_params, $st_params));
                    $data['job_orders'] = $stmt_j->fetchAll(PDO::FETCH_ASSOC);
                }

                // Subtotals
                $merch_count  = count($data['merchandise']);
                $merch_amount = array_sum(array_column($data['merchandise'], 'amount'));

                $jo_count  = count($data['job_orders']);
                $jo_amount = array_sum(array_column($data['job_orders'], 'total_amount'));

                $data['merchandise_subtotal'] = $merch_amount;
                $data['job_orders_subtotal']  = $jo_amount;

                // 3. DAILY SALES SUMMARY
                $data['daily_summary'] = [
                    'merchandise' => ['count' => $merch_count, 'amount' => $merch_amount],
                    'job_order'   => ['count' => $jo_count,    'amount' => $jo_amount],
                    'overall'     => ['count' => $merch_count + $jo_count, 'amount' => $merch_amount + $jo_amount]
                ];

                // 4. PAYMENT METHOD SUMMARY
                $pm_map = [];
                foreach ($data['merchandise'] as $m) {
                    $pm = $m['payment_method'] ?: 'Cash';
                    if (!isset($pm_map[$pm])) $pm_map[$pm] = ['count' => 0, 'amount' => 0];
                    $pm_map[$pm]['count']++;
                    $pm_map[$pm]['amount'] += (float)$m['amount'];
                }
                foreach ($data['job_orders'] as $j) {
                    $pm = $j['payment_method'] ?: 'Cash';
                    if (!isset($pm_map[$pm])) $pm_map[$pm] = ['count' => 0, 'amount' => 0];
                    $pm_map[$pm]['count']++;
                    $pm_map[$pm]['amount'] += (float)$j['total_amount'];
                }
                $data['payment_summary'] = $pm_map;

                // 5. SALES BY CATEGORY (Merchandise)
                $cat_map = [];
                foreach ($data['merchandise'] as $m) {
                    $c = $m['category'] ?: 'General';
                    if (!isset($cat_map[$c])) $cat_map[$c] = ['count' => 0, 'amount' => 0];
                    $cat_map[$c]['count']++;
                    $cat_map[$c]['amount'] += (float)$m['amount'];
                }
                $data['category_summary'] = $cat_map;

                // 6. SERVICE REVENUE BREAKDOWN
                $total_labor       = array_sum(array_column($data['job_orders'], 'labor_fee'));
                $total_service_fee = array_sum(array_column($data['job_orders'], 'service_fee'));
                $total_parts       = array_sum(array_column($data['job_orders'], 'parts_cost'));
                $data['service_revenue'] = [
                    'labor_fee'   => $total_labor,
                    'service_fee' => $total_service_fee,
                    'parts'       => $total_parts,
                    'overall'     => $jo_amount
                ];

                // 7. TRANSACTION STATUS SUMMARY
                $status_counts = [
                    'Completed Job Orders' => 0,
                    'Released Vehicles'    => 0,
                    'Pending Job Orders'   => 0,
                    'Cancelled Job Orders' => 0
                ];
                foreach ($data['job_orders'] as $j) {
                    $st = strtolower($j['status']);
                    if (str_contains($st, 'completed')) $status_counts['Completed Job Orders']++;
                    elseif (str_contains($st, 'release')) $status_counts['Released Vehicles']++;
                    elseif (str_contains($st, 'cancel') || str_contains($st, 'reject')) $status_counts['Cancelled Job Orders']++;
                    else $status_counts['Pending Job Orders']++;
                }
                $data['status_summary'] = $status_counts;
            }
            break;

        // =========================================================================
        // 2. INVENTORY REPORTS
        // =========================================================================
        case 'inventory':

            // ---- 1. MERCHANDISE INVENTORY REPORT ----
            if ($tab === 'merch_inventory') {
                $fi_cat    = trim($filters['category']    ?? '');
                $fi_brand  = trim($filters['brand']       ?? '');
                $fi_status = trim($filters['status']      ?? '');
                $fi_batch  = trim($filters['batch_id']    ?? '');
                $fi_prod   = trim($filters['product']     ?? '');

                $where  = " WHERE p.station_id = :station_id ";
                $params = ['station_id' => $station_id];

                if (!empty($fi_cat))   { $where .= " AND LOWER(COALESCE(pc.name,'')) LIKE LOWER(:fi_cat) ";   $params['fi_cat']   = "%$fi_cat%"; }
                if (!empty($fi_brand)) { $where .= " AND LOWER(COALESCE(p.brand,'')) LIKE LOWER(:fi_brand) "; $params['fi_brand'] = "%$fi_brand%"; }
                if (!empty($fi_prod))  { $where .= " AND LOWER(p.name) LIKE LOWER(:fi_prod) ";                $params['fi_prod']  = "%$fi_prod%"; }
                if (!empty($fi_batch)) { $where .= " AND LOWER(COALESCE(mb.batch_number,'')) LIKE LOWER(:fi_batch) "; $params['fi_batch'] = "%$fi_batch%"; }
                if (!empty($fi_status)) {
                    if ($fi_status === 'Out of Stock')    $where .= " AND p.current_stock <= 0 ";
                    elseif ($fi_status === 'Critical Stock') $where .= " AND p.current_stock > 0 AND p.current_stock <= p.min_stock_level / 2 ";
                    elseif ($fi_status === 'Low Stock')   $where .= " AND p.current_stock > 0 AND p.current_stock <= p.min_stock_level ";
                    elseif ($fi_status === 'Expired')     $where .= " AND do.expiry_date IS NOT NULL AND do.expiry_date < CURDATE() ";
                    elseif ($fi_status === 'Available')   $where .= " AND p.current_stock > p.min_stock_level ";
                }

                $sql = "SELECT p.sku,
                               COALESCE(
                                   mb.batch_number,
                                   (SELECT msi.batch_ref FROM merchandise_stock_in msi
                                    WHERE msi.product_id = p.id AND msi.station_id = p.station_id
                                    AND msi.batch_ref LIKE 'BT-%'
                                    ORDER BY msi.encoded_at DESC LIMIT 1),
                                   CONCAT('BT-', DATE_FORMAT(p.updated_at, '%Y%m%d'), '-', LPAD(p.id, 4, '0'))
                               ) as batch_id,
                               p.name as product,
                               COALESCE(pc.name, 'General') as category,
                               COALESCE(p.unit, 'pcs') as uom,
                               COALESCE(mb.quantity_received, p.current_stock) as initial_stock,
                               p.current_stock,
                               COALESCE(p.min_stock_level, 0) as reorder_level,
                               COALESCE(do.expiry_date, NULL) as expiration_date,
                               p.updated_at as last_updated,
                               CASE
                                 WHEN p.current_stock <= 0 THEN 'Out of Stock'
                                 WHEN do.expiry_date IS NOT NULL AND do.expiry_date < CURDATE() THEN 'Expired'
                                 WHEN p.current_stock <= (p.min_stock_level / 2) THEN 'Critical Stock'
                                 WHEN p.current_stock <= p.min_stock_level THEN 'Low Stock'
                                 ELSE 'Available'
                               END as status
                        FROM products p
                        LEFT JOIN product_categories pc ON p.category_id = pc.id
                        LEFT JOIN merchandise_batches mb ON p.id = mb.product_id AND mb.status = 'active'
                        LEFT JOIN deliveries_oversight do ON mb.delivery_id = do.id
                        {$where}
                        ORDER BY p.name ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Filter dropdown options
                $data['categories'] = $pdo->prepare("SELECT DISTINCT pc.name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE p.station_id = :sid AND pc.name IS NOT NULL ORDER BY pc.name ASC");
                $data['categories']->execute(['sid' => $station_id]);
                $data['categories'] = $data['categories']->fetchAll(PDO::FETCH_COLUMN);

                $data['brands'] = $pdo->prepare("SELECT DISTINCT brand FROM products WHERE station_id = :sid AND brand IS NOT NULL AND brand != '' ORDER BY brand ASC");
                $data['brands']->execute(['sid' => $station_id]);
                $data['brands'] = $data['brands']->fetchAll(PDO::FETCH_COLUMN);

            // ---- 2. FUEL INVENTORY REPORT ----
            } elseif ($tab === 'fuel_inventory') {
                $fi_fuel  = trim($filters['fuel_type'] ?? '');
                $fi_ugt   = trim($filters['ugt']       ?? '');

                $where  = " WHERE fi.station_id = :station_id AND fi.status = 'active'
                            AND fi.ugt_no IS NOT NULL AND TRIM(fi.ugt_no) != '' ";
                $params = ['station_id' => $station_id];

                if (!empty($fi_fuel)) { $where .= " AND LOWER(fi.fuel_type) LIKE LOWER(:fi_fuel) "; $params['fi_fuel'] = "%$fi_fuel%"; }
                if (!empty($fi_ugt))  { $where .= " AND LOWER(fi.ugt_no) LIKE LOWER(:fi_ugt) ";    $params['fi_ugt']  = "%$fi_ugt%"; }

                $sql = "SELECT fi.ugt_no as ugt,
                               fi.fuel_type,
                               COALESCE(fi.current_stock, fi.current_level, 0) as current_volume,
                               COALESCE(fi.capacity, 0) as tank_capacity,
                               COALESCE(fi.reorder_level, 0) as reorder_level,
                               COALESCE(fi.critical_level, 0) as critical_level,
                               CASE
                                 WHEN fi.capacity > 0
                                 THEN ROUND((COALESCE(fi.current_stock, fi.current_level, 0) / fi.capacity) * 100, 1)
                                 ELSE 0
                               END as available_pct,
                               fi.last_updated,
                               CASE
                                 WHEN COALESCE(fi.current_stock, fi.current_level, 0) <= fi.critical_level THEN 'Critical Fuel'
                                 WHEN COALESCE(fi.current_stock, fi.current_level, 0) <= fi.reorder_level  THEN 'Low Fuel'
                                 ELSE 'Available'
                               END as status
                        FROM fuel_inventory fi
                        {$where}
                        ORDER BY fi.ugt_no ASC, fi.fuel_type ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $data['fuel_types'] = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_inventory WHERE station_id = :sid AND status = 'active' ORDER BY fuel_type ASC");
                $data['fuel_types']->execute(['sid' => $station_id]);
                $data['fuel_types'] = $data['fuel_types']->fetchAll(PDO::FETCH_COLUMN);

                $data['ugts'] = $pdo->prepare("SELECT DISTINCT ugt_no FROM fuel_inventory WHERE station_id = :sid AND status = 'active' AND ugt_no IS NOT NULL AND ugt_no != '' ORDER BY ugt_no ASC");
                $data['ugts']->execute(['sid' => $station_id]);
                $data['ugts'] = $data['ugts']->fetchAll(PDO::FETCH_COLUMN);

            // ---- 3. INVENTORY MOVEMENT REPORT ----
            } elseif ($tab === 'inventory_movement') {
                $fi_ttype = trim($filters['transaction_type'] ?? '');
                $fi_prod  = trim($filters['product']          ?? '');
                $fi_batch = trim($filters['batch_id']         ?? '');
                $fi_user  = trim($filters['user']             ?? '');

                // Map friendly type → DB action
                $type_map = [
                    'Stock In'                => 'stock_in',
                    'Sales'                   => 'sale',
                    'Return'                  => 'return',
                    'Expired'                 => 'expired',
                    'Damaged'                 => 'damaged',
                    'Physical Count Adjustment' => 'physical_count',
                    'Manual Adjustment'       => 'adjustment',
                ];

                $where  = " WHERE DATE(il.created_at) BETWEEN :date_from AND :date_to AND il.station_id = :station_id ";
                $params = ['date_from' => $date_from, 'date_to' => $date_to, 'station_id' => $station_id];

                if (!empty($fi_ttype)) {
                    $db_action = $type_map[$fi_ttype] ?? $fi_ttype;
                    $where .= " AND LOWER(il.action) = LOWER(:fi_ttype) ";
                    $params['fi_ttype'] = $db_action;
                }
                if (!empty($fi_prod))  { $where .= " AND LOWER(COALESCE(p.name,'')) LIKE LOWER(:fi_prod) "; $params['fi_prod'] = "%$fi_prod%"; }
                if (!empty($fi_user))  { $where .= " AND (LOWER(COALESCE(u.name,'')) LIKE LOWER(:fi_user) OR LOWER(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) LIKE LOWER(:fi_user2)) "; $params['fi_user'] = "%$fi_user%"; $params['fi_user2'] = "%$fi_user%"; }

                $sql = "SELECT DATE(il.created_at) as date,
                               COALESCE(mb.batch_number, il.reference_type, 'N/A') as batch_id,
                               COALESCE(p.name, CONCAT('Product #', il.product_id)) as product,
                               CASE il.action
                                 WHEN 'stock_in'      THEN 'Stock In'
                                 WHEN 'sale'          THEN 'Sales'
                                 WHEN 'return'        THEN 'Return'
                                 WHEN 'expired'       THEN 'Expired'
                                 WHEN 'damaged'       THEN 'Damaged'
                                 WHEN 'physical_count' THEN 'Physical Count Adjustment'
                                 WHEN 'adjustment'    THEN 'Manual Adjustment'
                                 ELSE il.action
                               END as transaction_type,
                               il.quantity_change as qty,
                               il.quantity_after as balance_after,
                               COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),''), 'System') as performed_by
                        FROM inventory_logs il
                        LEFT JOIN products p ON il.product_id = p.id
                        LEFT JOIN merchandise_batches mb ON il.product_id = mb.product_id AND mb.station_id = il.station_id AND mb.status = 'active'
                        LEFT JOIN users u ON il.user_id = u.id
                        {$where}
                        ORDER BY il.created_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ---- 4. INVENTORY ADJUSTMENT REPORT ----
            } elseif ($tab === 'inventory_adjustment') {
                $fi_status = trim($filters['status']    ?? '');
                $fi_atype  = trim($filters['adj_type']  ?? '');
                $fi_batch  = trim($filters['batch_id']  ?? '');

                $where  = " WHERE DATE(ma.requested_at) BETWEEN :date_from AND :date_to AND ma.station_id = :station_id ";
                $params = ['date_from' => $date_from, 'date_to' => $date_to, 'station_id' => $station_id];

                if (!empty($fi_status)) { $where .= " AND LOWER(ma.status) = LOWER(:fi_status) ";           $params['fi_status'] = $fi_status; }
                if (!empty($fi_atype))  { $where .= " AND LOWER(ma.adjustment_type) LIKE LOWER(:fi_atype) "; $params['fi_atype']  = "%$fi_atype%"; }
                if (!empty($fi_batch))  { $where .= " AND LOWER(COALESCE(ma.sku,'')) LIKE LOWER(:fi_batch) "; $params['fi_batch'] = "%$fi_batch%"; }

                $sql = "SELECT CONCAT('ADJ-', LPAD(ma.id, 5, '0')) as request_no,
                               COALESCE(ma.sku, 'N/A') as batch_id,
                               ma.product_name as product,
                               ma.adjustment_type,
                               ma.quantity_change as qty,
                               COALESCE(NULLIF(u1.name,''), NULLIF(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,'')),''), 'Staff') as requested_by,
                               COALESCE(NULLIF(u2.name,''), NULLIF(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,'')),''), '-') as approved_by,
                               ma.status,
                               ma.approved_at as approval_date
                        FROM merchandise_adjustments ma
                        LEFT JOIN users u1 ON ma.requested_by = u1.id
                        LEFT JOIN users u2 ON ma.approved_by  = u2.id
                        {$where}
                        ORDER BY ma.requested_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ---- 5. EXPIRED & DAMAGED REPORT ----
            } else { // expired_damaged
                $fi_batch = trim($filters['batch_id']   ?? '');
                $fi_prod  = trim($filters['product']    ?? '');
                $fi_atype = trim($filters['adj_type']   ?? '');

                $where  = " WHERE DATE(ma.requested_at) BETWEEN :date_from AND :date_to AND ma.station_id = :station_id ";
                $params = ['date_from' => $date_from, 'date_to' => $date_to, 'station_id' => $station_id];

                // Expired & Damaged = adjustments of type Expired or Damaged
                $where .= " AND LOWER(ma.adjustment_type) IN ('expired product', 'damaged product', 'expired', 'damaged') ";

                if (!empty($fi_batch)) { $where .= " AND LOWER(COALESCE(ma.sku,'')) LIKE LOWER(:fi_batch) ";      $params['fi_batch'] = "%$fi_batch%"; }
                if (!empty($fi_prod))  { $where .= " AND LOWER(ma.product_name) LIKE LOWER(:fi_prod) ";           $params['fi_prod']  = "%$fi_prod%"; }
                if (!empty($fi_atype)) { $where .= " AND LOWER(ma.adjustment_type) LIKE LOWER(:fi_atype) ";       $params['fi_atype'] = "%$fi_atype%"; }

                $sql = "SELECT COALESCE(ma.sku, CONCAT('ADJ-', ma.id)) as batch_id,
                               ma.product_name as product,
                               (SELECT do.expiry_date FROM deliveries_oversight do WHERE do.product = ma.product_name AND do.station_id = ma.station_id AND do.expiry_date IS NOT NULL LIMIT 1) as expiration_date,
                               CASE
                                 WHEN LOWER(ma.adjustment_type) LIKE '%expire%' THEN ABS(ma.quantity_change)
                                 ELSE 0
                               END as expired_qty,
                               CASE
                                 WHEN LOWER(ma.adjustment_type) LIKE '%damage%' THEN ABS(ma.quantity_change)
                                 ELSE 0
                               END as damaged_qty,
                               ABS(ma.quantity_change) as total_deduction,
                               ma.approved_at as approval_date,
                               COALESCE(NULLIF(u2.name,''), NULLIF(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,'')),''), '-') as approved_by
                        FROM merchandise_adjustments ma
                        LEFT JOIN users u2 ON ma.approved_by = u2.id
                        {$where}
                        ORDER BY ma.requested_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Also include deliveries with damaged_quantity > 0 if they exist in date range
                $sql2 = "SELECT COALESCE(do.dr_number, do.batch_id, CONCAT('DMG-', do.id)) as batch_id,
                                do.product as product,
                                do.expiry_date as expiration_date,
                                0 as expired_qty,
                                COALESCE(do.damaged_quantity, 0) as damaged_qty,
                                COALESCE(do.damaged_quantity, 0) as total_deduction,
                                COALESCE(do.admin_action_at, do.manager_action_at) as approval_date,
                                COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),''), '-') as approved_by
                         FROM deliveries_oversight do
                         LEFT JOIN users u ON (do.admin_id = u.id OR do.manager_id = u.id)
                         WHERE do.station_id = :station_id2
                           AND do.damaged_quantity > 0
                           AND DATE(do.created_at) BETWEEN :date_from2 AND :date_to2
                         ORDER BY do.created_at DESC";
                $stmt2 = $pdo->prepare($sql2);
                $stmt2->execute(['station_id2' => $station_id, 'date_from2' => $date_from, 'date_to2' => $date_to]);
                $dmg_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $data['rows'] = array_merge($data['rows'], $dmg_rows);
            }
            break;

        // =========================================================================
        // 3. OPERATIONS REPORTS
        // =========================================================================
        case 'operations':
            $data['mechanics'] = $pdo->prepare("SELECT id, full_name FROM mechanics WHERE station_id = :sid AND (archived = 0 OR archived IS NULL) ORDER BY full_name ASC");
            $data['mechanics']->execute(['sid' => $station_id]);
            $data['mechanics'] = $data['mechanics']->fetchAll(PDO::FETCH_ASSOC);

            $data['service_categories'] = $pdo->query("SELECT id, name FROM service_categories WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

            // ---- 1. JOB ORDER REPORT ----
            if ($tab === 'job_order') {
                $fi_status = trim($filters['status']         ?? '');
                $fi_mech   = trim($filters['mechanic']       ?? '');
                $fi_scat   = trim($filters['service_cat']    ?? '');
                $fi_cust   = trim($filters['customer']       ?? '');
                $fi_plate  = trim($filters['plate']          ?? '');
                $fi_payst  = trim($filters['payment_status'] ?? '');
                $fi_search = trim($filters['search']         ?? '');

                $where  = " WHERE DATE(jo.created_at) BETWEEN :date_from AND :date_to AND jo.station_id = :station_id ";
                $params = ['date_from' => $date_from, 'date_to' => $date_to, 'station_id' => $station_id];

                if (!empty($fi_status)) { $where .= " AND LOWER(jo.status) = LOWER(:fi_status) ";               $params['fi_status'] = $fi_status; }
                if (!empty($fi_mech))   { $where .= " AND LOWER(COALESCE(m.full_name,'')) LIKE LOWER(:fi_mech) "; $params['fi_mech']   = "%$fi_mech%"; }
                if (!empty($fi_scat))   { $where .= " AND LOWER(COALESCE(sc.name,'')) LIKE LOWER(:fi_scat) ";     $params['fi_scat']   = "%$fi_scat%"; }
                if (!empty($fi_cust))   { $where .= " AND LOWER(COALESCE(jo.customer_name,'')) LIKE LOWER(:fi_cust) "; $params['fi_cust'] = "%$fi_cust%"; }
                if (!empty($fi_plate))  { $where .= " AND LOWER(COALESCE(jo.vehicle_plate,'')) LIKE LOWER(:fi_plate) "; $params['fi_plate'] = "%$fi_plate%"; }
                if (!empty($fi_payst))  { $where .= " AND (LOWER(COALESCE(jo.payment_status,'')) = LOWER(:fi_payst) OR LOWER(COALESCE(jo.payment_method,'')) = LOWER(:fi_payst2)) "; $params['fi_payst'] = $fi_payst; $params['fi_payst2'] = $fi_payst; }
                if (!empty($fi_search)) {
                    $where .= " AND (LOWER(jo.job_order_number) LIKE LOWER(:srch) OR LOWER(COALESCE(jo.customer_name,'')) LIKE LOWER(:srch) OR LOWER(COALESCE(jo.vehicle_plate,'')) LIKE LOWER(:srch) OR LOWER(COALESCE(m.full_name,'')) LIKE LOWER(:srch)) ";
                    $params['srch'] = "%$fi_search%";
                }

                $sql = "SELECT jo.job_order_number as jo_no,
                               jo.created_at as date,
                               COALESCE(NULLIF(jo.customer_name,''), 'Walk-in') as customer,
                               CONCAT(COALESCE(jo.vehicle_plate,'N/A'), ' - ', COALESCE(jo.vehicle_type,'Vehicle')) as vehicle,
                               COALESCE(m.full_name, 'Unassigned') as mechanic,
                               COALESCE(sc.name, jo.service_type, jo.service_description, 'General Service') as service_category,
                               COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) as labor_fee,
                               COALESCE(jo.estimated_labor_cost, 0) as service_fee,
                               COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) as parts_cost,
                               COALESCE(jo.total_cost, (COALESCE(jo.actual_labor_cost,0) + COALESCE(jo.actual_parts_cost,0)), 0) as total_amount,
                               COALESCE(NULLIF(jo.payment_method,''), NULLIF(jo.payment_status,''), 'Unpaid') as payment_method,
                               jo.status,
                               jo.completed_at as released_date
                        FROM job_orders jo
                        LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id
                        LEFT JOIN service_categories sc ON jo.service_category_id = sc.id
                        {$where}
                        ORDER BY jo.created_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $data['rows'] = $rows;

                // Footer Summary calculations
                $total_jos      = count($rows);
                $completed_jos  = 0;
                $pending_jos    = 0;
                $in_progress_jos = 0;
                $released_jos   = 0;
                $cancelled_jos  = 0;
                $total_labor    = 0;
                $total_sfee     = 0;
                $total_parts    = 0;
                $overall_rev    = 0;

                foreach ($rows as $r) {
                    $st = strtolower($r['status'] ?? '');
                    if (in_array($st, ['completed', 'verified', 'finalized'])) $completed_jos++;
                    elseif (in_array($st, ['pending', 'reviewed'])) $pending_jos++;
                    elseif (in_array($st, ['in progress', 'awaiting parts'])) $in_progress_jos++;
                    elseif ($st === 'released') $released_jos++;
                    elseif (in_array($st, ['cancelled', 'rejected'])) $cancelled_jos++;

                    $total_labor += (float)$r['labor_fee'];
                    $total_sfee  += (float)$r['service_fee'];
                    $total_parts += (float)$r['parts_cost'];
                    $overall_rev += (float)$r['total_amount'];
                }

                $data['summary'] = [
                    'total_jos'       => $total_jos,
                    'completed_jos'   => $completed_jos,
                    'pending_jos'     => $pending_jos,
                    'in_progress_jos' => $in_progress_jos,
                    'released_jos'    => $released_jos,
                    'cancelled_jos'   => $cancelled_jos,
                    'total_labor'     => $total_labor,
                    'total_service_fee' => $total_sfee,
                    'total_parts'     => $total_parts,
                    'overall_revenue' => $overall_rev,
                ];

            // ---- 2. MECHANIC PERFORMANCE REPORT ----
            } else {
                $fi_mech  = trim($filters['mechanic']    ?? '');
                $fi_scat  = trim($filters['service_cat'] ?? '');
                $fi_status = trim($filters['status']     ?? '');
                $fi_search = trim($filters['search']     ?? '');

                $where  = " WHERE m.station_id = :station_id AND (m.archived = 0 OR m.archived IS NULL) ";
                $params = ['station_id' => $station_id];

                if (!empty($fi_mech))   { $where .= " AND LOWER(m.full_name) LIKE LOWER(:fi_mech) "; $params['fi_mech'] = "%$fi_mech%"; }
                if (!empty($fi_search)) { $where .= " AND LOWER(m.full_name) LIKE LOWER(:srch) ";    $params['srch']    = "%$fi_search%"; }

                $jo_and = "";
                if (!empty($fi_scat))   { $jo_and .= " AND LOWER(COALESCE(sc.name,'')) LIKE LOWER(:fi_scat) "; $params['fi_scat'] = "%$fi_scat%"; }
                if (!empty($fi_status)) { $jo_and .= " AND LOWER(jo.status) = LOWER(:fi_status) ";             $params['fi_status'] = $fi_status; }

                $sql = "SELECT m.full_name as mechanic,
                               COUNT(jo.id) as assigned_jobs,
                               SUM(CASE WHEN LOWER(jo.status) IN ('completed','verified','finalized','released') THEN 1 ELSE 0 END) as completed_jobs,
                               SUM(CASE WHEN LOWER(jo.status) IN ('pending','reviewed','in progress','awaiting parts') THEN 1 ELSE 0 END) as pending_jobs,
                               SUM(CASE WHEN LOWER(jo.status) IN ('cancelled','rejected') THEN 1 ELSE 0 END) as cancelled_jobs,
                               ROUND(AVG(CASE WHEN jo.completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, jo.created_at, jo.completed_at) ELSE NULL END), 0) as avg_completion_mins,
                               SUM(CASE WHEN LOWER(jo.status) IN ('completed','verified','finalized','released') THEN COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) ELSE 0 END) as labor_revenue,
                               SUM(CASE WHEN LOWER(jo.status) IN ('completed','verified','finalized','released') THEN COALESCE(jo.estimated_labor_cost, 0) ELSE 0 END) as service_revenue,
                               SUM(CASE WHEN LOWER(jo.status) IN ('completed','verified','finalized','released') THEN COALESCE(jo.total_cost, 0) ELSE 0 END) as total_revenue
                        FROM mechanics m
                        LEFT JOIN job_orders jo ON m.id = jo.assigned_mechanic_id AND DATE(jo.created_at) BETWEEN :date_from AND :date_to {$jo_and}
                        LEFT JOIN service_categories sc ON jo.service_category_id = sc.id
                        {$where}
                        GROUP BY m.id, m.full_name
                        ORDER BY total_revenue DESC, m.full_name ASC";
                $params['date_from'] = $date_from;
                $params['date_to']   = $date_to;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $data['rows'] = $rows;

                // Footer summary calculations for mechanics
                $total_mechanics = count($rows);
                $total_assigned  = array_sum(array_column($rows, 'assigned_jobs'));
                $total_completed = array_sum(array_column($rows, 'completed_jobs'));
                $total_pending   = array_sum(array_column($rows, 'pending_jobs'));
                $total_revenue   = array_sum(array_column($rows, 'total_revenue'));

                $mins_arr = array_filter(array_column($rows, 'avg_completion_mins'), fn($v) => !is_null($v) && $v > 0);
                $overall_avg_mins = count($mins_arr) ? round(array_sum($mins_arr) / count($mins_arr)) : 0;

                $data['summary'] = [
                    'total_mechanics'     => $total_mechanics,
                    'total_assigned'      => $total_assigned,
                    'total_completed'     => $total_completed,
                    'total_pending'       => $total_pending,
                    'overall_avg_mins'    => $overall_avg_mins,
                    'overall_revenue'     => $total_revenue,
                ];
            }
            break;

        // =========================================================================
        // 4. PROCUREMENT REPORTS
        // =========================================================================
        case 'procurement':
            // Load categories for filter dropdown
            try {
                $stmt = $pdo->query("SELECT id, name FROM product_categories ORDER BY name");
                $data['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $data['categories'] = []; }

            if ($tab === 'purchase_order') {
                // Build dynamic WHERE
                $po_where  = "WHERE DATE(po.created_at) BETWEEN :date_from AND :date_to";
                $po_params = ['date_from' => $date_from, 'date_to' => $date_to];
                if ($station_id > 0) { $po_where .= " AND po.station_id = :station_id"; $po_params['station_id'] = $station_id; }
                if (!empty($filters['po_status'])) {
                    $po_where .= " AND po.status LIKE :po_status";
                    $po_params['po_status'] = '%' . $filters['po_status'] . '%';
                }
                if (!empty($filters['search'])) {
                    $po_where .= " AND po.po_number LIKE :search";
                    $po_params['search'] = '%' . $filters['search'] . '%';
                }
                // Note: category filter applied via items join
                $cat_join  = '';
                $cat_where = '';
                if (!empty($filters['category'])) {
                    $cat_join  = " LEFT JOIN purchase_order_items poi2 ON poi2.po_id = po.id LEFT JOIN products p2 ON p2.id = poi2.product_id LEFT JOIN product_categories pc2 ON pc2.id = p2.category_id ";
                    $cat_where = " AND pc2.name = :cat_filter";
                    $po_params['cat_filter'] = $filters['category'];
                }

                $sql = "SELECT po.po_number,
                               po.created_at as po_date,
                               COALESCE(u1.name, CONCAT(u1.first_name,' ',u1.last_name), 'N/A') as requested_by,
                               COALESCE(u2.name, CONCAT(u2.first_name,' ',u2.last_name), 'N/A') as approved_by,
                               COALESCE(s.name, fs.supplier_name, 'Petron Corporation') as supplier,
                               COUNT(DISTINCT poi.id) as item_count,
                               COALESCE(SUM(poi.quantity_ordered), 0) as total_qty,
                               COALESCE(SUM(poi.total_price), 0) as estimated_cost,
                               po.expected_delivery_date,
                               po.status
                        FROM purchase_orders po
                        LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
                        LEFT JOIN suppliers s ON po.supplier_id = s.id
                        LEFT JOIN fuel_suppliers fs ON po.supplier_id = fs.id
                        LEFT JOIN users u1 ON po.created_by = u1.id
                        LEFT JOIN users u2 ON po.approved_by = u2.id
                        {$cat_join}
                        {$po_where} {$cat_where}
                        GROUP BY po.id
                        ORDER BY po.created_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($po_params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } elseif ($tab === 'fuel_reconciliation' || $tab === 'delivery_validation') {
                $fr_where  = "WHERE DATE(ft.transaction_date) BETWEEN :date_from AND :date_to";
                $fr_params = ['date_from' => $date_from, 'date_to' => $date_to];
                if ($station_id > 0) { $fr_where .= " AND ft.station_id = :station_id"; $fr_params['station_id'] = $station_id; }
                if (!empty($filters['fuel_type'])) {
                    $fr_where .= " AND LOWER(ft.fuel_type) LIKE :fuel_type";
                    $fr_params['fuel_type'] = '%' . strtolower($filters['fuel_type']) . '%';
                }
                if (!empty($filters['ugt'])) {
                    $fr_where .= " AND LOWER(ft.fuel_type) LIKE :ugt";
                    $fr_params['ugt'] = '%' . strtolower($filters['ugt']) . '%';
                }
                if (!empty($filters['status'])) {
                    if (strtolower($filters['status']) === 'submitted') {
                        $fr_where .= " AND LOWER(COALESCE(ft.status, '')) IN ('verified','approved','completed','submitted')";
                    } elseif (strtolower($filters['status']) === 'pending') {
                        $fr_where .= " AND LOWER(COALESCE(ft.status, '')) NOT IN ('verified','approved','completed','submitted')";
                    }
                }

                $sql = "SELECT ft.id,
                               ft.fuel_type as raw_fuel_type,
                               ft.pump_id,
                               COALESCE(ft.previous_reading, 0) as beginning_reading,
                               COALESCE(ft.present_reading, 0) as ending_reading,
                               COALESCE(ft.calibration, 0) as calibration,
                               GREATEST(0, COALESCE(ft.present_reading, 0) - COALESCE(ft.previous_reading, 0) - COALESCE(ft.calibration, 0)) as net_volume,
                               COALESCE(ft.price_per_liter, 0) as selling_price,
                               COALESCE(ft.total_amount, 0) as fuel_sales,
                               CASE 
                                 WHEN LOWER(COALESCE(ft.status, '')) IN ('verified','approved','completed','submitted') THEN 'Submitted'
                                 ELSE 'Pending'
                               END as status,
                               ft.transaction_date
                        FROM fuel_transactions ft
                        {$fr_where}
                        ORDER BY ft.transaction_date DESC, ft.id DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($fr_params);
                $raw_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($raw_rows as &$r) {
                    $rawFuel = $r['raw_fuel_type'] ?? '';
                    $r['ugt_no']    = get_exact_ugt_no($rawFuel);
                    $r['clean_fuel_type'] = staff_report_fuel_display_name($rawFuel);
                    $r['fuel_type'] = !empty($rawFuel) ? $rawFuel : $r['clean_fuel_type'];
                    if ((float)$r['fuel_sales'] <= 0 && (float)$r['net_volume'] > 0 && (float)$r['selling_price'] > 0) {
                        $r['fuel_sales'] = round((float)$r['net_volume'] * (float)$r['selling_price'], 2);
                    }
                }
                $data['rows'] = $raw_rows;

            } elseif ($tab === 'po_vs_received') {
                $pvr_where  = "WHERE DATE(po.created_at) BETWEEN :date_from AND :date_to";
                $pvr_params = ['date_from' => $date_from, 'date_to' => $date_to];
                if ($station_id > 0) { $pvr_where .= " AND po.station_id = :station_id"; $pvr_params['station_id'] = $station_id; }
                if (!empty($filters['po_no'])) {
                    $pvr_where .= " AND po.po_number LIKE :po_no";
                    $pvr_params['po_no'] = '%' . $filters['po_no'] . '%';
                }
                if (!empty($filters['search'])) {
                    $pvr_where .= " AND (po.po_number LIKE :search OR poi.item_name LIKE :search2)";
                    $pvr_params['search']  = '%' . $filters['search'] . '%';
                    $pvr_params['search2'] = '%' . $filters['search'] . '%';
                }

                $sql = "SELECT po.po_number,
                               COALESCE(poi.item_name, po.product_name) as product,
                               COALESCE(poi.quantity_ordered, po.quantity, 0) as ordered_qty,
                               COALESCE(poi.quantity_received, poi.received_quantity, 0) as received_qty,
                               (COALESCE(poi.quantity_received, poi.received_quantity, 0) - COALESCE(poi.quantity_ordered, po.quantity, 0)) as variance,
                               po.expected_delivery_date,
                               COALESCE(poi.received_at, po.stock_in_at) as actual_delivery,
                               CASE
                                 WHEN poi.quantity_received IS NULL THEN 'Pending Delivery'
                                 WHEN poi.quantity_received >= poi.quantity_ordered THEN 'Complete'
                                 WHEN poi.quantity_received > poi.quantity_ordered THEN 'Over Delivered'
                                 WHEN poi.quantity_received < poi.quantity_ordered AND poi.quantity_received > 0 THEN 'Partial'
                                 ELSE 'Under Delivered'
                               END as status
                        FROM purchase_orders po
                        LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
                        {$pvr_where}
                        ORDER BY po.created_at DESC, poi.id ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($pvr_params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } else { // stock_in_approval
                $si_where  = "WHERE DATE(msi.encoded_at) BETWEEN :date_from AND :date_to";
                $si_params = ['date_from' => $date_from, 'date_to' => $date_to];
                if ($station_id > 0) { $si_where .= " AND msi.station_id = :station_id"; $si_params['station_id'] = $station_id; }
                if (!empty($filters['product'])) {
                    $si_where .= " AND msi.product_name LIKE :product";
                    $si_params['product'] = '%' . $filters['product'] . '%';
                }
                if (!empty($filters['search'])) {
                    $si_where .= " AND (msi.product_name LIKE :search OR msi.batch_ref LIKE :search2 OR msi.po_number LIKE :search3)";
                    $si_params['search']  = '%' . $filters['search'] . '%';
                    $si_params['search2'] = '%' . $filters['search'] . '%';
                    $si_params['search3'] = '%' . $filters['search'] . '%';
                }
                // Approval status — msi has no explicit status col; manager encoded = Approved
                // filter_appr_status maps to condition_flag or we use remarks keyword
                if (!empty($filters['appr_status'])) {
                    if ($filters['appr_status'] === 'Approved') {
                        $si_where .= " AND msi.encoded_by IS NOT NULL";
                    } elseif ($filters['appr_status'] === 'Rejected') {
                        $si_where .= " AND (msi.remarks LIKE '%reject%' OR msi.condition_flag IN ('Damaged','Short'))";
                    }
                }

                $sql = "SELECT COALESCE(msi.batch_ref, CONCAT('BT-', msi.id)) as batch_id,
                               msi.product_name as product,
                               msi.qty_received,
                               msi.unit_cost,
                               msi.selling_price,
                               COALESCE(u.name, CONCAT(u.first_name,' ',u.last_name), 'N/A') as approved_by,
                               msi.encoded_at as approval_date,
                               CASE
                                 WHEN msi.encoded_by IS NOT NULL THEN 'Approved'
                                 ELSE 'Pending Approval'
                               END as status
                        FROM merchandise_stock_in msi
                        LEFT JOIN users u ON msi.encoded_by = u.id
                        {$si_where}
                        ORDER BY msi.encoded_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($si_params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            break;

        // =========================================================================
        // 5. FINANCIAL REPORTS
        // =========================================================================
        case 'financial':
            if ($tab === 'revenue_summary') {
                $rev_search = trim($filters['search'] ?? '');
                $sql = "SELECT 
                            d.date,
                            COALESCE(f.fuel_rev, 0) as fuel_revenue,
                            COALESCE(m.merch_rev, 0) as merchandise_revenue,
                            COALESCE(s.serv_rev, 0) as service_revenue,
                            (COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) + COALESCE(s.serv_rev, 0)) as gross_revenue,
                            0.00 as total_discounts,
                            (COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) + COALESCE(s.serv_rev, 0)) as net_revenue
                        FROM (
                            SELECT DATE(transaction_date) as date FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df1 AND :dt1
                            UNION
                            SELECT DATE(transaction_date) as date FROM merchandise_transactions WHERE DATE(transaction_date) BETWEEN :df2 AND :dt2
                        ) d
                        LEFT JOIN (
                            SELECT DATE(transaction_date) as date, SUM(total_amount) as fuel_rev
                            FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df3 AND :dt3 GROUP BY DATE(transaction_date)
                        ) f ON d.date = f.date
                        LEFT JOIN (
                            SELECT DATE(transaction_date) as date, SUM(total_amount) as merch_rev
                            FROM merchandise_transactions WHERE LOWER(COALESCE(transaction_type,'')) NOT IN ('job_order','service')
                            AND DATE(transaction_date) BETWEEN :df4 AND :dt4 GROUP BY DATE(transaction_date)
                        ) m ON d.date = m.date
                        LEFT JOIN (
                            SELECT DATE(transaction_date) as date, SUM(total_amount) as serv_rev
                            FROM merchandise_transactions WHERE LOWER(COALESCE(transaction_type,'')) IN ('job_order','service')
                            AND DATE(transaction_date) BETWEEN :df5 AND :dt5 GROUP BY DATE(transaction_date)
                        ) s ON d.date = s.date
                        ORDER BY d.date DESC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'df1' => $date_from, 'dt1' => $date_to,
                    'df2' => $date_from, 'dt2' => $date_to,
                    'df3' => $date_from, 'dt3' => $date_to,
                    'df4' => $date_from, 'dt4' => $date_to,
                    'df5' => $date_from, 'dt5' => $date_to
                ]);
                $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($rev_search)) {
                    $data['rows'] = array_filter($all_rows, function($r) use ($rev_search) {
                        return str_contains(strtolower($r['date']), strtolower($rev_search));
                    });
                } else {
                    $data['rows'] = $all_rows;
                }

            } elseif ($tab === 'receivables') {
                $filter_cust   = trim($filters['customer'] ?? '');
                $filter_status = trim($filters['status'] ?? '');
                $filter_due    = trim($filters['due_date'] ?? '');

                // 1. Query credit transactions
                $ar_where = " WHERE (LOWER(COALESCE(mt.payment_method,'')) LIKE '%credit%' OR LOWER(COALESCE(mt.payment_method,'')) LIKE '%fleet%' OR mt.credit_customer_id IS NOT NULL) ";
                $ar_params = [];
                if (!empty($filter_cust)) {
                    $ar_where .= " AND (LOWER(mt.customer_name) LIKE LOWER(:filter_cust) OR LOWER(c.name) LIKE LOWER(:filter_cust2)) ";
                    $ar_params['filter_cust']  = '%' . $filter_cust . '%';
                    $ar_params['filter_cust2'] = '%' . $filter_cust . '%';
                }
                if (!empty($filter_due)) {
                    $ar_where .= " AND DATE(COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))) = :filter_due ";
                    $ar_params['filter_due'] = $filter_due;
                }

                $sql_ar = "SELECT 
                            COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), c.name, 'Credit Customer') as customer,
                            COALESCE(mt.payment_method, 'Credit Account (AR)') as account_type,
                            COALESCE(mt.transaction_id, CONCAT('INV-', mt.id)) as invoice_no,
                            DATE(mt.transaction_date) as transaction_date,
                            COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY)) as due_date,
                            COALESCE(mt.balance_due, mt.total_amount, 0) as outstanding_balance,
                            GREATEST(DATEDIFF(CURDATE(), COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))), 0) as days_overdue,
                            CASE
                              WHEN LOWER(COALESCE(mt.payment_status,'')) = 'paid' THEN 'Paid'
                              WHEN DATE(COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))) = CURDATE() THEN 'Due Today'
                              WHEN DATE(COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))) < CURDATE() THEN 'Overdue'
                              ELSE 'Current'
                            END as status
                           FROM merchandise_transactions mt
                           LEFT JOIN customers c ON mt.customer_id = c.id
                           {$ar_where}
                           ORDER BY mt.transaction_date DESC";
                $stmt = $pdo->prepare($sql_ar);
                $stmt->execute($ar_params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($rows)) {
                    // Fallback to credit customers
                    $sql_ar_cust = "SELECT 
                                        c.name as customer,
                                        'Credit Account (AR)' as account_type,
                                        CONCAT('INV-', LPAD(c.id, 5, '0')) as invoice_no,
                                        DATE(c.created_at) as transaction_date,
                                        DATE_ADD(DATE(c.created_at), INTERVAL 30 DAY) as due_date,
                                        COALESCE(NULLIF(c.current_balance, 0), NULLIF(c.outstanding_balance, 0), c.credit_limit, 0) as outstanding_balance,
                                        GREATEST(DATEDIFF(CURDATE(), DATE_ADD(DATE(c.created_at), INTERVAL 30 DAY)), 0) as days_overdue,
                                        CASE
                                          WHEN COALESCE(c.current_balance, 0) <= 0 AND COALESCE(c.outstanding_balance, 0) <= 0 THEN 'Paid'
                                          WHEN DATE_ADD(DATE(c.created_at), INTERVAL 30 DAY) = CURDATE() THEN 'Due Today'
                                          WHEN DATE_ADD(DATE(c.created_at), INTERVAL 30 DAY) < CURDATE() THEN 'Overdue'
                                          ELSE 'Current'
                                        END as status
                                    FROM customers c
                                    WHERE LOWER(c.type) = 'credit' OR c.current_balance > 0 OR c.outstanding_balance > 0";
                    $stmt2 = $pdo->query($sql_ar_cust);
                    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                }

                if (!empty($filter_status)) {
                    $rows = array_filter($rows, function($r) use ($filter_status) {
                        return strtolower($r['status']) === strtolower($filter_status);
                    });
                }

                $data['rows'] = array_values($rows);

            } elseif ($tab === 'payment_collections') {
                $filter_pm   = trim($filters['payment_method'] ?? '');
                $filter_cust = trim($filters['customer'] ?? '');

                $col_where = " WHERE LOWER(COALESCE(mt.payment_status,'Paid')) = 'paid' AND DATE(mt.transaction_date) BETWEEN :date_from AND :date_to ";
                $col_params = ['date_from' => $date_from, 'date_to' => $date_to];

                if (!empty($filter_pm)) {
                    $col_where .= " AND LOWER(COALESCE(mt.payment_method,'')) = LOWER(:filter_pm) ";
                    $col_params['filter_pm'] = $filter_pm;
                }
                if (!empty($filter_cust)) {
                    $col_where .= " AND (LOWER(mt.customer_name) LIKE LOWER(:filter_cust) OR LOWER(mt.customer_first_name) LIKE LOWER(:filter_cust) OR LOWER(mt.customer_last_name) LIKE LOWER(:filter_cust)) ";
                    $col_params['filter_cust'] = '%' . $filter_cust . '%';
                }

                $sql_col = "SELECT 
                                mt.transaction_id as or_no,
                                COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                                COALESCE(mt.credit_po_number, mt.transaction_id) as invoice_no,
                                COALESCE(mt.payment_method, 'Cash') as payment_method,
                                COALESCE(mt.amount_paid, mt.total_amount, 0) as amount_paid,
                                COALESCE(u.name, 'Cashier Staff') as collected_by,
                                mt.transaction_date as payment_date
                            FROM merchandise_transactions mt
                            LEFT JOIN users u ON mt.staff_id = u.id
                            {$col_where}
                            ORDER BY mt.transaction_date DESC";
                $stmt = $pdo->prepare($sql_col);
                $stmt->execute($col_params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } else { // sales_vs_collection
                $svc_search = trim($filters['search'] ?? '');
                $sql_svc = "SELECT 
                                d.date,
                                COALESCE(s.total_sales, 0) as total_sales,
                                COALESCE(cs.total_credit_sales, 0) as total_credit_sales,
                                COALESCE(col.total_collections, 0) as total_collections,
                                GREATEST(COALESCE(cs.total_credit_sales, 0) - COALESCE(col.total_collections, 0), 0) as outstanding_balance
                            FROM (
                                SELECT DATE(transaction_date) as date FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df1 AND :dt1
                                UNION
                                SELECT DATE(transaction_date) as date FROM merchandise_transactions WHERE DATE(transaction_date) BETWEEN :df2 AND :dt2
                            ) d
                            LEFT JOIN (
                                SELECT date, SUM(amount) as total_sales FROM (
                                    SELECT DATE(transaction_date) as date, total_amount as amount FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df3 AND :dt3
                                    UNION ALL
                                    SELECT DATE(transaction_date) as date, total_amount as amount FROM merchandise_transactions WHERE DATE(transaction_date) BETWEEN :df4 AND :dt4
                                ) t1 GROUP BY date
                            ) s ON d.date = s.date
                            LEFT JOIN (
                                SELECT DATE(transaction_date) as date, SUM(total_amount) as total_credit_sales
                                FROM merchandise_transactions
                                WHERE (LOWER(COALESCE(payment_method,'')) LIKE '%credit%' OR LOWER(COALESCE(payment_method,'')) LIKE '%fleet%')
                                  AND DATE(transaction_date) BETWEEN :df5 AND :dt5
                                GROUP BY DATE(transaction_date)
                            ) cs ON d.date = cs.date
                            LEFT JOIN (
                                SELECT DATE(transaction_date) as date, SUM(COALESCE(amount_paid, total_amount, 0)) as total_collections
                                FROM merchandise_transactions
                                WHERE LOWER(COALESCE(payment_status,'Paid')) = 'paid'
                                  AND DATE(transaction_date) BETWEEN :df6 AND :dt6
                                GROUP BY DATE(transaction_date)
                            ) col ON d.date = col.date
                            ORDER BY d.date DESC";
                $stmt = $pdo->prepare($sql_svc);
                $stmt->execute([
                    'df1' => $date_from, 'dt1' => $date_to,
                    'df2' => $date_from, 'dt2' => $date_to,
                    'df3' => $date_from, 'dt3' => $date_to,
                    'df4' => $date_from, 'dt4' => $date_to,
                    'df5' => $date_from, 'dt5' => $date_to,
                    'df6' => $date_from, 'dt6' => $date_to
                ]);
                $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($svc_search)) {
                    $data['rows'] = array_filter($all_rows, function($r) use ($svc_search) {
                        return str_contains(strtolower($r['date']), strtolower($svc_search));
                    });
                } else {
                    $data['rows'] = $all_rows;
                }
            }
            break;

        // =========================================================================
        // 6. CUSTOMER REPORTS
        // =========================================================================
        case 'customer':
            // FINAL CUSTOMER REPORT (Single Customer Report overview)
            $filter_cname   = trim($filters['cust_name'] ?? '');
            $filter_ctype   = trim($filters['cust_type'] ?? '');
            $filter_pstatus = trim($filters['payment_status'] ?? '');
            $filter_plate   = trim($filters['plate'] ?? '');
            $filter_search  = trim($filters['search'] ?? '');

            $c_where = " WHERE 1=1 ";
            $c_params = [];
            if ($station_id > 0) {
                $c_where .= " AND (c.station_id = :station_id OR c.station_id IS NULL) ";
                $c_params['station_id'] = $station_id;
            }

            if (!empty($filter_cname)) {
                $c_where .= " AND LOWER(c.name) LIKE LOWER(:filter_cname) ";
                $c_params['filter_cname'] = '%' . $filter_cname . '%';
            }
            if (!empty($filter_ctype)) {
                $c_where .= " AND (LOWER(COALESCE(c.customer_type, c.type, '')) LIKE LOWER(:filter_ctype)) ";
                $c_params['filter_ctype'] = '%' . $filter_ctype . '%';
            }
            if (!empty($filter_plate)) {
                $c_where .= " AND (LOWER(COALESCE(c.vehicle_plate,'')) LIKE LOWER(:filter_plate) OR EXISTS (SELECT 1 FROM customer_vehicles cv WHERE cv.customer_id = c.id AND LOWER(cv.plate_number) LIKE LOWER(:filter_plate2))) ";
                $c_params['filter_plate']  = '%' . $filter_plate . '%';
                $c_params['filter_plate2'] = '%' . $filter_plate . '%';
            }
            if (!empty($filter_search)) {
                $c_where .= " AND (LOWER(c.name) LIKE LOWER(:filter_search) OR LOWER(COALESCE(c.customer_id,'')) LIKE LOWER(:filter_search2) OR LOWER(COALESCE(c.contact_number, c.phone,'')) LIKE LOWER(:filter_search3)) ";
                $c_params['filter_search']  = '%' . $filter_search . '%';
                $c_params['filter_search2'] = '%' . $filter_search . '%';
                $c_params['filter_search3'] = '%' . $filter_search . '%';
            }

            $sql_table = "SELECT c.id,
                                 COALESCE(NULLIF(c.customer_id,''), CONCAT('CUST-', LPAD(c.id, 4, '0'))) as customer_id_code,
                                 c.name as customer_name,
                                 COALESCE(NULLIF(c.contact_number,''), NULLIF(c.phone,''), 'N/A') as contact_no,
                                 COALESCE(NULLIF(c.customer_type,''), NULLIF(c.type,''), 'Walk-in') as customer_type,
                                 (SELECT COUNT(DISTINCT DATE(transaction_date)) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as total_visits,
                                 (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as total_transactions,
                                 (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id AND LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service')) as total_job_orders,
                                 (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id AND LOWER(COALESCE(mt.transaction_type,'')) NOT IN ('job_order','service')) as total_merch_purchases,
                                 (SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as total_amount_spent,
                                 COALESCE(c.current_balance, c.outstanding_balance, 0) as outstanding_balance,
                                 (SELECT MAX(transaction_date) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as last_visit,
                                 COALESCE(NULLIF(c.status,''), 'Active') as status
                          FROM customers c
                          {$c_where}
                          ORDER BY total_amount_spent DESC";
            $stmt_tbl = $pdo->prepare($sql_table);
            $stmt_tbl->execute($c_params);
            $rows_cust = $stmt_tbl->fetchAll(PDO::FETCH_ASSOC);

            $details_map = [];
            foreach ($rows_cust as $r_cust) {
                $details_map[$r_cust['id']] = getAdminCustomerDetails($pdo, (int)$r_cust['id']);
            }

            $data['rows'] = $rows_cust;
            $data['customer_details'] = $details_map;
            break;

        // =========================================================================
        // 7. AUDIT REPORTS
        // =========================================================================
        case 'audit':
            // ── 1. USER ACTIVITY LOGS ──────────────────────────────────────
            if ($tab === 'user_activity_logs') {
                $sql = "SELECT al.created_at,
                               COALESCE(NULLIF(u.name,''), CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')), al.user_id, 'System') as user,
                               COALESCE(NULLIF(u.role,''), 'Staff') as role,
                               al.action,
                               COALESCE(al.details, 'N/A') as details,
                               COALESCE(al.ip_address, 'N/A') as ip_address
                        FROM activity_logs al
                        LEFT JOIN users u ON al.user_id = u.id
                        WHERE DATE(al.created_at) BETWEEN :date_from AND :date_to
                          AND (al.action NOT LIKE '%delete%' AND al.action NOT LIKE '%deleted%')
                          AND (u.role IS NULL OR LOWER(u.role) != 'superadmin')
                        ORDER BY al.created_at DESC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ── 2. LOGIN HISTORY ───────────────────────────────────────────
            } elseif ($tab === 'login_history') {
                $sql = "SELECT la.attempt_time as date,
                               COALESCE(NULLIF(u.name,''), CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')), la.username, 'Unknown') as user,
                               COALESCE(NULLIF(u.role,''), 'N/A') as role,
                               la.username,
                               la.attempt_time as login_time,
                               NULL as logout_time,
                               NULL as session_duration,
                               la.ip_address,
                               la.status,
                               COALESCE(la.failure_reason, '') as failure_reason
                        FROM login_attempts la
                        LEFT JOIN users u ON la.user_id = u.id
                        WHERE DATE(la.attempt_time) BETWEEN :date_from AND :date_to
                          AND (u.role IS NULL OR LOWER(u.role) != 'superadmin')
                        ORDER BY la.attempt_time DESC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ── 3. TRANSACTION LOGS ────────────────────────────────────────
            } elseif ($tab === 'transaction_logs') {
                $sql = "SELECT mt.transaction_id,
                               CASE
                                 WHEN LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service') THEN 'Job Order'
                                 WHEN LOWER(COALESCE(mt.transaction_type,'')) = 'return' THEN 'Return'
                                 WHEN LOWER(COALESCE(mt.transaction_type,'')) IN ('void','voided') THEN 'Void'
                                 WHEN LOWER(COALESCE(mt.transaction_type,'')) = 'refund' THEN 'Refund'
                                 ELSE 'Merchandise Sale'
                               END as module,
                               COALESCE(mt.customer_name,
                                 CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),
                                 'Walk-in') as customer,
                               COALESCE(NULLIF(u.name,''), CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')), 'Staff') as performed_by,
                               mt.total_amount,
                               COALESCE(mt.payment_method, 'N/A') as payment_method,
                               mt.transaction_date,
                               COALESCE(mt.payment_status, mt.validation_status, 'Completed') as status
                        FROM merchandise_transactions mt
                        LEFT JOIN users u ON mt.staff_id = u.id
                        WHERE DATE(mt.transaction_date) BETWEEN :date_from AND :date_to
                          AND (u.role IS NULL OR LOWER(u.role) != 'superadmin')
                        ORDER BY mt.transaction_date DESC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ── 4. INVENTORY LOGS ──────────────────────────────────────────
            } elseif ($tab === 'inventory_logs') {
                $sql = "SELECT il.created_at,
                               COALESCE(NULLIF(p.name,''), CONCAT('Product #', il.product_id), 'N/A') as product,
                               COALESCE(p.sku, 'N/A') as sku,
                               CASE
                                 WHEN LOWER(COALESCE(il.action,'')) LIKE '%stock in%'    OR LOWER(COALESCE(il.action,'')) LIKE '%stockin%'   THEN 'Stock In'
                                 WHEN LOWER(COALESCE(il.action,'')) LIKE '%stock out%'   OR LOWER(COALESCE(il.action,'')) LIKE '%stockout%'  THEN 'Stock Out'
                                 WHEN LOWER(COALESCE(il.action,'')) LIKE '%adjust%'                                                          THEN 'Inventory Adjustment'
                                 WHEN LOWER(COALESCE(il.action,'')) LIKE '%expir%'                                                           THEN 'Expired Adjustment'
                                 WHEN LOWER(COALESCE(il.action,'')) LIKE '%damage%'                                                          THEN 'Damaged Adjustment'
                                 WHEN LOWER(COALESCE(il.action,'')) LIKE '%count%' OR LOWER(COALESCE(il.action,'')) LIKE '%physical%'        THEN 'Physical Count'
                                 ELSE COALESCE(il.action, 'N/A')
                               END as action_type,
                               COALESCE(il.quantity_before, 0) as quantity_before,
                               COALESCE(il.quantity_after, 0) as quantity_after,
                               COALESCE(il.quantity_change, il.quantity_after - il.quantity_before, 0) as quantity_change,
                               COALESCE(NULLIF(u.name,''), CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')), 'System') as performed_by,
                               COALESCE(il.notes, 'N/A') as notes
                        FROM inventory_logs il
                        LEFT JOIN products p ON il.product_id = p.id
                        LEFT JOIN users u ON il.user_id = u.id
                        WHERE DATE(il.created_at) BETWEEN :date_from AND :date_to
                          AND (u.role IS NULL OR LOWER(u.role) != 'superadmin')
                        ORDER BY il.created_at DESC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ── 5. APPROVAL LOGS ───────────────────────────────────────────
            } elseif ($tab === 'approval_logs') {
                $sql = "SELECT ppa.created_at,
                               COALESCE(NULLIF(ppa.product_name,''), 'N/A') as reference,
                               CASE
                                 WHEN LOWER(COALESCE(ppa.product_type,'')) = 'fuel'    THEN 'Price Change (Fuel)'
                                 WHEN LOWER(COALESCE(ppa.product_type,'')) = 'merch'   THEN 'Price Change (Merchandise)'
                                 ELSE COALESCE(ppa.product_type,'Price Change')
                               END as category,
                               COALESCE(NULLIF(u1.name,''), CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,'')), 'Staff') as requested_by,
                               COALESCE(NULLIF(u2.name,''), CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,'')), 'Pending') as approved_by,
                               COALESCE(ppa.old_value,'N/A') as old_value,
                               COALESCE(ppa.new_value,'N/A') as new_value,
                               COALESCE(ppa.status,'Pending') as status,
                               ppa.reviewed_at
                        FROM pending_price_approvals ppa
                        LEFT JOIN users u1 ON ppa.requested_by = u1.id
                        LEFT JOIN users u2 ON ppa.reviewed_by = u2.id
                        WHERE DATE(ppa.created_at) BETWEEN :date_from AND :date_to
                          AND (u1.role IS NULL OR LOWER(u1.role) != 'superadmin')
                          AND (u2.role IS NULL OR LOWER(u2.role) != 'superadmin')
                        ORDER BY ppa.created_at DESC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } else { // archived_deactivated
                // ── 6. ARCHIVED & DEACTIVATED LOGS ─────────────────────────
                $sql = "SELECT al.created_at,
                               COALESCE(al.entity_type, al.log_type, 'Record') as module,
                               COALESCE(al.action_details, CONCAT(al.entity_type, ' #', al.entity_id), 'N/A') as record_name,
                               CASE
                                 WHEN LOWER(COALESCE(al.action_type, '')) LIKE '%archive%'      THEN 'Archived'
                                 WHEN LOWER(COALESCE(al.action_type, '')) LIKE '%deactivat%'    THEN 'Deactivated'
                                 WHEN LOWER(COALESCE(al.action_type, '')) LIKE '%reactivat%'    THEN 'Reactivated'
                                 ELSE COALESCE(al.action_type, 'N/A')
                               END as action_done,
                               COALESCE(al.action_details, 'N/A') as reason,
                               COALESCE(NULLIF(u.name,''), CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')), 'Admin') as performed_by
                        FROM audit_logs al
                        LEFT JOIN users u ON al.user_id = u.id
                        WHERE DATE(al.created_at) BETWEEN :date_from AND :date_to
                          AND (u.role IS NULL OR LOWER(u.role) != 'superadmin')
                          AND (
                            LOWER(COALESCE(al.action_type,'')) LIKE '%archive%'
                            OR LOWER(COALESCE(al.action_type,'')) LIKE '%deactivat%'
                            OR LOWER(COALESCE(al.action_type,'')) LIKE '%reactivat%'
                            OR LOWER(COALESCE(al.log_type,'')) LIKE '%archive%'
                            OR LOWER(COALESCE(al.log_type,'')) LIKE '%deactivat%'
                            OR LOWER(COALESCE(al.log_type,'')) LIKE '%reactivat%'
                          )
                        ORDER BY al.created_at DESC LIMIT 500";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
                $rows_arch = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Fallback: pull from users table if audit_logs has no archive events yet
                if (empty($rows_arch)) {
                    $sql2 = "SELECT u.updated_at as created_at,
                                    'User' as module,
                                    COALESCE(NULLIF(u.name,''), CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')), u.username) as record_name,
                                    CASE WHEN u.status = 'inactive' OR u.status = 'deactivated' THEN 'Deactivated' ELSE 'Reactivated' END as action_done,
                                    'Account status change' as reason,
                                    'Admin' as performed_by
                             FROM users u
                             WHERE (u.status = 'inactive' OR u.status = 'deactivated')
                               AND (u.role IS NULL OR LOWER(u.role) != 'superadmin')
                               AND DATE(u.updated_at) BETWEEN :date_from AND :date_to
                             ORDER BY u.updated_at DESC LIMIT 200";
                    $stmt2 = $pdo->prepare($sql2);
                    $stmt2->execute(['date_from' => $date_from, 'date_to' => $date_to]);
                    $rows_arch = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                }
                $data['rows'] = $rows_arch;
            }
            break;
    }
    return $data;
}
}

/**
 * Fetch complete details for a single customer for the View Customer modal
 */
function getAdminCustomerDetails(PDO $pdo, int $customer_id): array {
    $details = [];

    // 1. Customer Info
    $stmt_c = $pdo->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $stmt_c->execute([$customer_id]);
    $details['info'] = $stmt_c->fetch(PDO::FETCH_ASSOC) ?: [];

    if (empty($details['info'])) return $details;

    // 2. Vehicle History (with last service date)
    $stmt_v = $pdo->prepare(
        "SELECT cv.plate_number, cv.vehicle_type, cv.brand, cv.model, cv.year_model, cv.color,
                (SELECT MAX(jo.created_at) FROM job_orders jo WHERE jo.customer_id = ? AND jo.vehicle_plate = cv.plate_number) as last_service
         FROM customer_vehicles cv
         WHERE cv.customer_id = ?
         ORDER BY cv.id DESC"
    );
    $stmt_v->execute([$customer_id, $customer_id]);
    $details['vehicles'] = $stmt_v->fetchAll(PDO::FETCH_ASSOC);

    // 3. Job Order History
    $stmt_s = $pdo->prepare(
        "SELECT jo.job_order_number as jo_no,
                DATE(jo.created_at) as date,
                COALESCE(NULLIF(jo.service_description,''), jo.service_type, 'Service') as service,
                COALESCE(m.full_name, 'Unassigned') as mechanic,
                jo.status,
                COALESCE(jo.total_cost, jo.actual_labor_cost, 0) as amount
         FROM job_orders jo
         LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id
         WHERE jo.customer_id = ?
         ORDER BY jo.created_at DESC"
    );
    $stmt_s->execute([$customer_id]);
    $details['service_history'] = $stmt_s->fetchAll(PDO::FETCH_ASSOC);

    // 4. Merchandise Purchase History
    $stmt_m = $pdo->prepare(
        "SELECT mt.transaction_id as receipt_no,
                DATE(mt.transaction_date) as date,
                COALESCE(NULLIF(mt.item_sku,''), 'Merchandise Item') as product,
                mt.quantity,
                mt.total_amount as amount
         FROM merchandise_transactions mt
         WHERE mt.customer_id = ?
         ORDER BY mt.transaction_date DESC"
    );
    $stmt_m->execute([$customer_id]);
    $details['merch_history'] = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    // 5. Payment History (OR No. included)
    $stmt_p = $pdo->prepare(
        "SELECT DATE(transaction_date) as date,
                COALESCE(NULLIF(transaction_id,''), CONCAT('OR-', LPAD(id, 5, '0'))) as or_no,
                payment_method,
                total_amount as amount,
                COALESCE(NULLIF(payment_status,''), NULLIF(validation_status,''), 'Completed') as status
         FROM merchandise_transactions
         WHERE customer_id = ?
         UNION ALL
         SELECT DATE(created_at) as date,
                CONCAT('CR-', LPAD(id, 5, '0')) as or_no,
                'Credit Payment' as payment_method,
                amount,
                'Completed' as status
         FROM customer_credit_transactions
         WHERE customer_id = ?
         ORDER BY date DESC"
    );
    $stmt_p->execute([$customer_id, $customer_id]);
    $details['payment_history'] = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

    // 6. Accounts Receivable History (Credit/Fleet only)
    $ctype = strtolower(trim($details['info']['customer_type'] ?? $details['info']['type'] ?? ''));
    $ar_rows = [];
    if (str_contains($ctype, 'credit') || str_contains($ctype, 'fleet')) {
        $stmt_ar = $pdo->prepare(
            "SELECT COALESCE(NULLIF(transaction_id,''), CONCAT('INV-', LPAD(id, 5, '0'))) as invoice_no,
                    DATE(COALESCE(credit_due_date, due_date, DATE_ADD(DATE(transaction_date), INTERVAL 30 DAY))) as due_date,
                    COALESCE(balance_due, total_amount, 0) as balance,
                    CASE
                      WHEN LOWER(COALESCE(payment_status,'')) = 'paid' THEN 'Paid'
                      WHEN COALESCE(credit_due_date, due_date, DATE_ADD(DATE(transaction_date), INTERVAL 30 DAY)) < CURDATE() THEN 'Overdue'
                      WHEN COALESCE(credit_due_date, due_date, DATE_ADD(DATE(transaction_date), INTERVAL 30 DAY)) = CURDATE() THEN 'Due Today'
                      ELSE 'Current'
                    END as status
             FROM merchandise_transactions
             WHERE customer_id = ?
               AND (LOWER(COALESCE(payment_method,'')) LIKE '%credit%' OR LOWER(COALESCE(payment_method,'')) LIKE '%fleet%')
             ORDER BY transaction_date DESC"
        );
        $stmt_ar->execute([$customer_id]);
        $ar_rows = $stmt_ar->fetchAll(PDO::FETCH_ASSOC);
    }
    $details['ar_history'] = $ar_rows;

    // 7. Customer Statistics
    $all_dates   = array_unique(array_filter(array_column($details['merch_history'], 'date')));
    $total_visits = count($all_dates);
    $total_jos    = count($details['service_history']);
    $total_merch  = count($details['merch_history']);
    $total_spent  = array_sum(array_column($details['merch_history'], 'amount'))
                  + array_sum(array_column($details['service_history'], 'amount'));
    $avg_spent    = ($total_visits > 0) ? ($total_spent / $total_visits) : 0;
    $last_visit   = !empty($details['merch_history'])
                  ? $details['merch_history'][0]['date']
                  : (!empty($details['service_history']) ? $details['service_history'][0]['date'] : 'N/A');

    $details['stats'] = [
        'total_visits'          => $total_visits,
        'total_job_orders'      => $total_jos,
        'total_merch_purchases' => $total_merch,
        'total_amount_spent'    => $total_spent,
        'average_spending'      => $avg_spent,
        'last_visit'            => $last_visit,
    ];

    return $details;
}
