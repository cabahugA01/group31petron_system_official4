<?php
require_once __DIR__ . '/../backend/lib.php';
require_login();

$id   = $_GET['id']   ?? '';
$type = $_GET['type'] ?? 'sale';

$sale = null;

if ($type === 'job_order') {
    require_once __DIR__ . '/db_connect.php';
    try {
        // Fetch by job_order_id string (JO-xxx) OR numeric db id
        $stmt = $pdo->prepare("
            SELECT jo.*,
                   u.name  AS mechanic_name,
                   cb.name AS staff_name,
                   s.name  AS station_name,
                   s.location AS station_location,
                   s.address  AS station_address,
                   s.vat_tin  AS station_vat_tin
            FROM job_orders jo
            LEFT JOIN users    u  ON u.user_id  = jo.assigned_mechanic_id
            LEFT JOIN users    cb ON cb.user_id = jo.created_by
            LEFT JOIN stations s  ON s.id  = jo.station_id
            WHERE jo.job_order_id = ? OR jo.job_order_number = ? OR jo.id = ?
            LIMIT 1
        ");
        $numeric_id = is_numeric($id) ? (int)$id : 0;
        $stmt->execute([$id, $id, $numeric_id]);
        $jo = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── FALLBACK: Try merchandise_transactions table for JO/combined types ──
        if (!$jo && $numeric_id > 0) {
            try {
                $stmt_mt = $pdo->prepare("
                    SELECT mt.*,
                           u.name AS staff_name,
                           s.name AS station_name,
                           s.location AS station_location,
                           s.address AS station_address,
                           s.vat_tin AS station_vat_tin
                    FROM merchandise_transactions mt
                    LEFT JOIN users u ON u.user_id = mt.staff_id
                    LEFT JOIN stations s ON s.id = mt.station_id
                    WHERE mt.id = ?
                      AND mt.transaction_type IN ('job_order', 'combined')
                    LIMIT 1
                ");
                $stmt_mt->execute([$numeric_id]);
                $jo_mt = $stmt_mt->fetch(PDO::FETCH_ASSOC);
                
                if ($jo_mt) {
                    // Map merchandise_transaction fields to job_order structure
                    $jo_total = (float)($jo_mt['total_amount'] ?? 0);
                    $jo_paid = (float)($jo_mt['amount_paid'] ?? 0);
                    $jo_balance = (float)($jo_mt['balance_due'] ?? max(0, $jo_total - $jo_paid));
                    
                    $jo = [
                        'id' => $jo_mt['id'],
                        'job_order_id' => $jo_mt['job_order_id'] ?? null,
                        'job_order_number' => null,
                        'service_type' => $jo_mt['job_order_service'] ?? 'Service',
                        'service_description' => $jo_mt['job_order_description'] ?? '',
                        'customer_name' => $jo_mt['customer_name'] ?? 'Walk-in Customer',
                        'vehicle_plate' => $jo_mt['job_order_vehicle_plate'] ?? '',
                        'vehicle_type' => $jo_mt['job_order_vehicle_type'] ?? '',
                        'contact_number' => $jo_mt['job_order_contact'] ?? '',
                        'assigned_mechanic' => $jo_mt['job_order_mechanic_name'] ?? null,
                        'mechanic_name' => $jo_mt['job_order_mechanic_name'] ?? null,
                        'total_cost' => $jo_total,
                        'labor_cost' => 0,
                        'service_cost' => $jo_total,
                        'total_amount' => $jo_total,
                        'amount_paid' => $jo_paid,
                        'balance_due' => $jo_balance,
                        'payment_method' => $jo_mt['payment_method'] ?? 'Cash',
                        'payment_status' => $jo_mt['payment_status'] ?? 'Pending Payment',
                        'status' => $jo_mt['validation_status'] ?? 'Pending',
                        'notes' => $jo_mt['remarks'] ?? '',
                        'remarks' => $jo_mt['remarks'] ?? '',
                        'created_at' => $jo_mt['created_at'] ?? date('Y-m-d H:i:s'),
                        'order_date' => $jo_mt['transaction_date'] ?? $jo_mt['created_at'],
                        'staff_name' => $jo_mt['staff_name'] ?? 'N/A',
                        'shift_name' => $jo_mt['shift_name'] ?? '',
                        'shift_period' => $jo_mt['shift_period'] ?? '',
                        'station_name' => $jo_mt['station_name'] ?? 'Petron Station',
                        'station_location' => $jo_mt['station_location'] ?? '',
                        'station_address' => $jo_mt['station_address'] ?? '',
                        'station_vat_tin' => $jo_mt['station_vat_tin'] ?? '',
                        'card_reference' => $jo_mt['card_reference'] ?? '',
                        'parts_used' => null,
                        '_source' => 'merchandise_transactions'
                    ];
                }
            } catch (Exception $e) {
                error_log("Receipt MT fallback error: " . $e->getMessage());
            }
        }

        if ($jo) {
            // Ensure stations columns exist
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('address', $cols))
                    $pdo->exec("ALTER TABLE stations ADD COLUMN address VARCHAR(500) NULL AFTER location");
                if (!in_array('vat_tin', $cols))
                    $pdo->exec("ALTER TABLE stations ADD COLUMN vat_tin VARCHAR(50) NULL AFTER address");
            } catch (Exception $e) {}

            // Map job_order fields into the $sale shape used by the receipt template
            $jo_total   = (float)($jo['total_amount'] ?? $jo['service_cost'] ?? $jo['labor_cost'] ?? 0);
            $jo_paid    = (float)($jo['amount_paid'] ?? 0);
            $jo_balance = (float)($jo['balance_due'] ?? max(0, $jo_total - $jo_paid));

            // Build a synthetic items array from service info
            $jo_items = [];
            if (!empty($jo['service_type']) || !empty($jo['service_description'])) {
                $jo_items[] = [
                    'product_name' => $jo['service_type'] ?? 'Service',
                    'category'     => 'Job Order Service',
                    'size_variant' => '',
                    'quantity'     => 1,
                    'unit_price'   => $jo_total,
                    'subtotal'     => $jo_total,
                    'item_type'    => 'service',
                ];
            }

            // Parts used
            if (!empty($jo['parts_used'])) {
                $parts = is_array($jo['parts_used'])
                    ? $jo['parts_used']
                    : json_decode($jo['parts_used'], true);
                if (is_array($parts)) {
                    foreach ($parts as $p) {
                        $jo_items[] = [
                            'product_name' => $p['name'] ?? $p['part_name'] ?? 'Part',
                            'category'     => 'Parts',
                            'size_variant' => '',
                            'quantity'     => (float)($p['quantity'] ?? $p['qty'] ?? 1),
                            'unit_price'   => (float)($p['unit_price'] ?? $p['price'] ?? 0),
                            'subtotal'     => (float)($p['subtotal'] ?? $p['amount'] ?? 0),
                            'item_type'    => 'merchandise',
                        ];
                    }
                }
            }

            // Payment status
            $raw_pay_status = strtolower(trim($jo['payment_status'] ?? ''));
            if (!$raw_pay_status || $raw_pay_status === 'pending payment') $raw_pay_status = 'pending';

            $sale = [
                'transaction_id'      => $jo['job_order_id'] ?? $jo['job_order_number'] ?? ('#'.$jo['id']),
                'id'                  => $jo['job_order_id'] ?? $jo['job_order_number'] ?? ('#'.$jo['id']),
                'created_at'          => $jo['created_at'] ?? $jo['order_date'] ?? date('Y-m-d H:i:s'),
                'staff_name'          => $jo['staff_name'] ?? 'N/A',
                'shift_name'          => $jo['shift_name'] ?? $jo['shift_period'] ?? '',
                'customer_name'       => $jo['customer_name'] ?? 'Walk-in Customer',
                'customer_first_name' => '',
                'customer_last_name'  => '',
                'payment_method'      => $jo['payment_method'] ?? 'Cash',
                'payment_status'      => $raw_pay_status,
                'amount_paid'         => $jo_paid,
                'balance_due'         => $jo_balance,
                'total_amount'        => $jo_total,
                'subtotal_amount'     => 0,
                'vat_amount'          => 0,
                'amount_tendered'     => $jo_paid,
                'change_amount'       => 0,
                'card_reference'      => $jo['card_reference'] ?? '',
                'card_type'           => '',
                'ewallet_reference'   => '',
                'ewallet_provider'    => '',
                'efuel_card_number'   => '',
                'remarks'             => $jo['notes'] ?? $jo['remarks'] ?? '',
                'validation_status'   => $jo['status'] ?? 'Pending',
                'station_name'        => $jo['station_name']    ?? 'Petron Station',
                'station_address'     => $jo['station_address'] ?? '',
                'station_location'    => $jo['station_location'] ?? '',
                'station_vat_tin'     => $jo['station_vat_tin'] ?? '',
                'items'               => $jo_items,
                'transaction_type'    => 'job_order',
                'job_order'           => [
                    'job_order_id'        => $jo['job_order_id'] ?? $jo['job_order_number'] ?? null,
                    'service_type'        => $jo['service_type'] ?? $jo['job_type'] ?? '',
                    'service_description' => $jo['service_description'] ?? $jo['notes'] ?? '',
                    'mechanic_name'       => $jo['mechanic_name'] ?? $jo['assigned_mechanic'] ?? null,
                    'vehicle_plate'       => $jo['vehicle_plate'] ?? $jo['plate_number'] ?? null,
                    'vehicle_type'        => $jo['vehicle_type'] ?? null,
                    'contact_number'      => $jo['contact_number'] ?? $jo['customer_contact'] ?? null,
                ],
            ];
        }
    } catch (Exception $e) {
        error_log("Receipt JO fetch error: " . $e->getMessage());
    }

} elseif ($type === 'merchandise') {
    require_once __DIR__ . '/db_connect.php';

    // ── Ensure stations table has address & vat_tin columns ──────────────────
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('address', $cols)) {
            $pdo->exec("ALTER TABLE stations ADD COLUMN address VARCHAR(500) NULL AFTER location");
        }
        if (!in_array('vat_tin', $cols)) {
            $pdo->exec("ALTER TABLE stations ADD COLUMN vat_tin VARCHAR(50) NULL AFTER address");
        }
    } catch (Exception $e) { /* ignore */ }

    try {
        $stmt = $pdo->prepare("
            SELECT mt.*,
                   u.name AS staff_name,
                   s.name AS station_name,
                   s.location AS station_location,
                   s.address AS station_address,
                   s.vat_tin AS station_vat_tin,
                   COALESCE(
                       NULLIF(TRIM(mt.customer_name), ''),
                       NULLIF(TRIM(mt.customer_name), 'Walk-in Customer'),
                       c.name,
                       'Walk-in Customer'
                   ) AS resolved_customer_name,
                   COALESCE(NULLIF(TRIM(mt.customer_first_name), ''), '') AS resolved_first_name,
                   COALESCE(NULLIF(TRIM(mt.customer_last_name),  ''), '') AS resolved_last_name
            FROM   merchandise_transactions mt
            LEFT JOIN users    u ON mt.staff_id   = u.id
            LEFT JOIN stations s ON mt.station_id = s.id
            LEFT JOIN customers c ON mt.credit_customer_id = c.id
            WHERE  mt.transaction_id = ?
            LIMIT  1
        ");
        $stmt->execute([$id]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($txn) {
            $stmt2 = $pdo->prepare("
                SELECT product_name, category, size_variant, quantity, unit_price, subtotal,
                       COALESCE(item_type, 'merchandise') AS item_type
                FROM   merchandise_transaction_items
                WHERE  transaction_id = ?
                ORDER  BY id ASC
            ");
            $stmt2->execute([$txn['id']]);
            $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // ── Build Job Order data from inline transaction fields ────────
            $job_order_data = null;
            $jo_id_ref = $txn['job_order_id'] ?? null;
            $jo_db_id  = $txn['job_order_db_id'] ?? null;

            // Try to load from job_orders table first
            if ($jo_id_ref || $jo_db_id) {
                try {
                    if ($jo_db_id) {
                        $joStmt = $pdo->prepare("
                            SELECT jo.*,
                                   u.name AS mechanic_name,
                                   cb.name AS created_by_name
                            FROM job_orders jo
                            LEFT JOIN users u  ON u.user_id  = jo.assigned_mechanic_id
                            LEFT JOIN users cb ON cb.user_id = jo.created_by
                            WHERE jo.id = ?
                            LIMIT 1
                        ");
                        $joStmt->execute([$jo_db_id]);
                    } else {
                        $joStmt = $pdo->prepare("
                            SELECT jo.*,
                                   u.name AS mechanic_name,
                                   cb.name AS created_by_name
                            FROM job_orders jo
                            LEFT JOIN users u  ON u.user_id  = jo.assigned_mechanic_id
                            LEFT JOIN users cb ON cb.user_id = jo.created_by
                            WHERE (jo.job_order_id = ? OR jo.job_order_number = ?)
                            LIMIT 1
                        ");
                        $joStmt->execute([$jo_id_ref, $jo_id_ref]);
                    }
                    $job_order_data = $joStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Exception $e) {
                    error_log("Receipt JO lookup error: " . $e->getMessage());
                }
            }

            // Fall back to inline fields stored on the transaction itself
            if (!$job_order_data && !empty($txn['job_order_service'])) {
                $job_order_data = [
                    'job_order_id'        => $txn['job_order_id'] ?? null,
                    'service_type'        => $txn['job_order_service'] ?? '',
                    'service_description' => $txn['job_order_description'] ?? '',
                    'mechanic_name'       => $txn['job_order_mechanic_name'] ?? null,
                    'vehicle_plate'       => $txn['job_order_vehicle_plate'] ?? null,
                    'vehicle_type'        => $txn['job_order_vehicle_type'] ?? null,
                    'contact_number'      => $txn['job_order_contact'] ?? null,
                ];
            }

            // Also detect service items in the cart as a JO indicator
            if (!$job_order_data) {
                foreach ($items as $it) {
                    if (($it['item_type'] ?? '') === 'service') {
                        $job_order_data = [
                            'job_order_id'        => null,
                            'service_type'        => $it['product_name'] ?? 'Service',
                            'service_description' => $txn['job_order_description'] ?? '',
                            'mechanic_name'       => $txn['job_order_mechanic_name'] ?? null,
                            'vehicle_plate'       => $txn['job_order_vehicle_plate'] ?? null,
                            'vehicle_type'        => $txn['job_order_vehicle_type'] ?? null,
                            'contact_number'      => $txn['job_order_contact'] ?? null,
                        ];
                        break;
                    }
                }
            }

            $sale = [
                'transaction_id'        => $txn['transaction_id'],
                'id'                    => $txn['transaction_id'],
                'created_at'            => $txn['created_at'] ?? $txn['transaction_date'] ?? date('Y-m-d H:i:s'),
                'staff_id'              => $txn['staff_id'],
                'staff_name'            => $txn['staff_name'] ?? 'N/A',
                'shift_name'            => $txn['shift_name'] ?? '',
                'shift_period'          => $txn['shift_period'] ?? '',
                'customer_name'         => $txn['resolved_customer_name'] ?? $txn['customer_name'] ?? 'Walk-in Customer',
                'customer_first_name'   => $txn['resolved_first_name'] ?? $txn['customer_first_name'] ?? '',
                'customer_last_name'    => $txn['resolved_last_name']  ?? $txn['customer_last_name']  ?? '',
                'payment_method'        => $txn['payment_method'] ?? 'Cash',
                'payment_status'        => $txn['payment_status'] ?? null,
                'amount_paid'           => $txn['amount_paid']    ?? $txn['amount_tendered'] ?? 0,
                'balance_due'           => $txn['balance_due']    ?? null,
                'credit_customer_id'    => $txn['credit_customer_id'] ?? null,
                'total_amount'          => $txn['total_amount'] ?? 0,
                'subtotal_amount'       => $txn['subtotal_amount'] ?? null,
                'vat_amount'            => $txn['vat_amount'] ?? null,
                'amount_tendered'       => $txn['amount_tendered'] ?? 0,
                'change_amount'         => $txn['change_amount'] ?? 0,
                'card_reference'        => $txn['card_reference'] ?? '',
                'card_type'             => $txn['card_type'] ?? '',
                'ewallet_reference'     => $txn['ewallet_reference'] ?? '',
                'ewallet_provider'      => $txn['ewallet_provider'] ?? '',
                'efuel_card_number'     => $txn['efuel_card_number'] ?? '',
                'remarks'               => $txn['remarks'] ?? '',
                'validation_status'     => $txn['validation_status'] ?? 'Pending',
                'station_name'          => $txn['station_name'] ?? '',
                'station_address'       => $txn['station_address'] ?? '',
                'station_location'      => $txn['station_location'] ?? '',
                'station_vat_tin'       => $txn['station_vat_tin'] ?? '',
                'items'                 => $items,
                'job_order'             => $job_order_data,
                // ── Transaction type for receipt title ────────────────────
                // Priority: stored column → JO inline fields → items item_type
                'transaction_type'      => (function() use ($txn, $items, $job_order_data) {
                    // 1. Use stored column if available and valid
                    $stored = $txn['transaction_type'] ?? '';
                    if (in_array($stored, ['job_order', 'merchandise', 'combined'])) return $stored;

                    // 2. Detect JO presence from inline transaction fields
                    $has_jo_fields = (
                        !empty($txn['job_order_service'])   ||
                        !empty($txn['job_order_id'])        ||
                        !empty($txn['job_order_db_id'])     ||
                        !empty($txn['job_order_vehicle_plate'])
                    );

                    // 3. Detect from items
                    $has_service_item = false;
                    $has_merch_item   = false;
                    foreach ($items as $it) {
                        $itype = strtolower(trim($it['item_type'] ?? 'merchandise'));
                        if ($itype === 'service') $has_service_item = true;
                        else                      $has_merch_item   = true;
                    }

                    // 4. job_order_data object also signals JO
                    $has_jo = $has_jo_fields || $has_service_item || !empty($job_order_data);

                    if ($has_jo && $has_merch_item) return 'combined';
                    if ($has_jo)                    return 'job_order';
                    return 'merchandise';
                })(),
            ];
        }
    } catch (Exception $e) {
        error_log("Receipt DB error: " . $e->getMessage());
    }
} else {
    $sales = read_json('sales.json', []);
    foreach ($sales as $s) {
        if (($s['id'] ?? '') === $id) { $sale = $s; break; }
    }
}

if (!$sale) {
    http_response_code(404);
    
    // Debug info (only show in development - remove in production)
    $debug_info = '';
    if (isset($pdo)) {
        try {
            // Check if record exists in job_orders
            $debug_stmt = $pdo->prepare("SELECT id, job_order_id, job_order_number, customer_name, status FROM job_orders WHERE id = ? OR job_order_id = ? OR job_order_number = ? LIMIT 1");
            $debug_stmt->execute([$id, $id, $id]);
            $debug_jo = $debug_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if record exists in merchandise_transactions
            $debug_stmt2 = $pdo->prepare("SELECT id, transaction_id, customer_name, transaction_type, validation_status FROM merchandise_transactions WHERE id = ? OR transaction_id = ? LIMIT 1");
            $debug_stmt2->execute([$id, $id]);
            $debug_mt = $debug_stmt2->fetch(PDO::FETCH_ASSOC);
            
            if ($debug_jo) {
                $debug_info = '<p style="font-size:12px;color:#666;margin-top:10px;">Debug: Found in job_orders table (ID: '.$debug_jo['id'].', Status: '.$debug_jo['status'].')</p>';
            } elseif ($debug_mt) {
                $debug_info = '<p style="font-size:12px;color:#666;margin-top:10px;">Debug: Found in merchandise_transactions table (ID: '.$debug_mt['id'].', Type: '.$debug_mt['transaction_type'].', Status: '.$debug_mt['validation_status'].')</p>';
            } else {
                $debug_info = '<p style="font-size:12px;color:#666;margin-top:10px;">Debug: Transaction not found in database (searched ID: '.htmlspecialchars($id).', Type: '.htmlspecialchars($type).')</p>';
            }
        } catch (Exception $e) {
            $debug_info = '<p style="font-size:12px;color:#999;margin-top:10px;">Debug error: '.$e->getMessage().'</p>';
        }
    }
    
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Receipt Not Found</title>
<style>body{font-family:Arial,sans-serif;text-align:center;padding:60px;color:#555}
h2{color:#c0392b}a{color:#002F6C}.debug{font-size:11px;color:#999;margin-top:15px;padding:10px;background:#f5f5f5;border-radius:5px}</style></head><body>
<h2>Receipt Not Found</h2>
<p>Transaction <strong><?php echo htmlspecialchars($id); ?></strong> could not be located.</p>
<p><a href="javascript:window.close()">Close this window</a></p>
<?php echo $debug_info; ?>
</body></html><?php
    exit;
}

// ── Derived display values ────────────────────────────────────────────────────
$ts           = $sale['created_at'] ?? $sale['transaction_timestamp'] ?? date('Y-m-d H:i:s');
$disp_date    = date('F j, Y',  strtotime($ts));
$disp_time    = date('h:i A',   strtotime($ts));
$txn_id       = $sale['transaction_id'] ?? $sale['id'] ?? 'N/A';
$customer     = trim(($sale['customer_first_name'] ?? '') . ' ' . ($sale['customer_last_name'] ?? ''))
                ?: ($sale['customer_name'] ?? $sale['customer'] ?? 'Walk-in Customer');
$staff_name   = $sale['staff_name']     ?? 'N/A';
$shift_name   = $sale['shift_name']     ?? $sale['shift_period'] ?? '';
$pay_method   = $sale['payment_method'] ?? 'Cash';
$total        = (float)($sale['total_amount'] ?? $sale['total'] ?? 0);
$tendered     = (float)($sale['amount_tendered'] ?? $sale['amount_received'] ?? 0);
$change       = (float)($sale['change_amount']   ?? $sale['change'] ?? 0);

// ── Payment status derivation ────────────────────────────────────────────────
// Use stored payment_status if present, otherwise derive from amount_paid vs total
$stored_pay_status = strtolower(trim($sale['payment_status'] ?? ''));
$amount_paid_db    = (float)($sale['amount_paid'] ?? $tendered ?? 0);
$balance_due_db    = (float)($sale['balance_due'] ?? 0);

if ($stored_pay_status === 'partial payment' || $stored_pay_status === 'partial') {
    $pay_status_norm = 'partial';
} elseif ($stored_pay_status === 'pending payment' || ($stored_pay_status === '' && $amount_paid_db <= 0)) {
    $pay_status_norm = 'pending';
} elseif (in_array($stored_pay_status, ['credit transaction', 'credit'])) {
    $pay_status_norm = 'credit';
} else {
    $pay_status_norm = 'paid'; // Paid or fully settled
}

// For display: if balance_due not stored, compute it
if ($balance_due_db <= 0 && $pay_status_norm === 'partial') {
    $balance_due_db = max(0, $total - $amount_paid_db);
}

// ── Transaction type label — always fixed ─────────────────────────────────────
$txn_type_label    = 'MERCHANDISE/SERVICE TRANSACTION';
$txn_type_sublabel = 'Official Merchandise & Service Invoice';

// ── Compute subtotal and VAT correctly ────────────────────────────────────
// If stored subtotal_amount exists, use it. Otherwise derive from items sum.
$items_sum = 0;
foreach (($sale['items'] ?? []) as $it) {
    $items_sum += (float)($it['subtotal'] ?? ((float)($it['unit_price'] ?? 0) * (float)($it['quantity'] ?? 1)));
}

if (!empty($sale['subtotal_amount']) && (float)$sale['subtotal_amount'] > 0) {
    // Stored values — trust them
    $subtotal_display = (float)$sale['subtotal_amount'];
    $vat_display      = !empty($sale['vat_amount']) ? (float)$sale['vat_amount'] : round($subtotal_display * 0.12, 2);
    // Recompute grand total from stored subtotal + vat for accuracy
    $total = round($subtotal_display + $vat_display, 2);
} else {
    // Legacy: total_amount was stored without VAT — treat items_sum as subtotal
    $subtotal_display = $items_sum > 0 ? $items_sum : ($total > 0 ? round($total / 1.12, 2) : 0);
    $vat_display      = round($subtotal_display * 0.12, 2);
    $total            = round($subtotal_display + $vat_display, 2);
}
$vatable = $subtotal_display;
$vat_amt = $vat_display;
$station_name = $sale['station_name']   ?? 'Petron Station';
// Always guarantee header values — use DB if set, otherwise use the known correct defaults
$vat_tin      = (!empty($sale['station_vat_tin']))  ? $sale['station_vat_tin']  : '236-002-207-0000';
$station_addr = (!empty($sale['station_address']))  ? $sale['station_address']
              : ((!empty($sale['station_location'])) ? $sale['station_location']
              : 'Vamenta Blvd., Carmen, Cagayan de Oro City, Misamis Oriental');
$items        = $sale['items'] ?? [];
$pm_lc        = strtolower($pay_method);
$job_order    = $sale['job_order'] ?? null;
$has_jo       = !empty($job_order);

// Logo path - use absolute URL from web root
$logo = '/group31petron_system_official4/assets/img/Petron Logo.png';

// ── QR Code: encode verify URL ───────────────────────────────────────────────
$qr_customer_disp = $customer !== 'Walk-in Customer' ? $customer : 'Walk-in';

$http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';

// Check if we are accessed via localhost
$is_localhost = in_array(strtolower(explode(':', $http_host)[0]), ['localhost', '127.0.0.1', '::1']);

$qr_host = $http_host;

// If accessed via localhost, try to replace with actual LAN IP so phones can scan it
if ($is_localhost) {
    $lan_ip = '';
    
    // First attempt: reliable socket approach (finds the IP used to reach the internet)
    try {
        $sock = @fsockopen('8.8.8.8', 53, $en, $es, 0.5);
        if ($sock) {
            $lan_ip = explode(':', stream_socket_get_name($sock, false))[0];
            fclose($sock);
        }
    } catch (Exception $e) {}

    // Second attempt: DNS lookup
    if (empty($lan_ip) || str_starts_with($lan_ip, '127.') || str_starts_with($lan_ip, '169.254.')) {
        try {
            $host_ips = gethostbynamel(gethostname());
            if (is_array($host_ips)) {
                foreach ($host_ips as $ip) {
                    if (!str_starts_with($ip, '127.') && !str_starts_with($ip, '169.254.') && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $lan_ip = $ip;
                        break;
                    }
                }
            }
        } catch (Exception $e) {}
    }

    // Final fallback
    if (!empty($lan_ip) && !str_starts_with($lan_ip, '169.254.')) {
        $port_suffix = str_contains($http_host, ':') ? (':' . explode(':', $http_host)[1]) : '';
        $qr_host = $lan_ip . $port_suffix;
    }
}

// Build the verify URL (this will be an online URL if accessed via ngrok/public domain)
$verify_url = $scheme . '://' . $qr_host . '/group31petron_system_official4/public/verify.php'
            . '?id=' . urlencode($txn_id) . '&type=merchandise';

// Generate human-readable text for fallback (in case image fails to load)
$qr_data = "TXN: {$txn_id}\nURL: {$verify_url}";

// QR image — encodes the verify URL with real LAN IP (scannable from phone)
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&ecc=M&data=' . urlencode($verify_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($txn_type_label); ?> — <?php echo htmlspecialchars($txn_id); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── Reset ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

/* ── Screen ── */
@media screen{
  body{background:#d1d5db;font-family:'Courier New',Courier,monospace;padding:20px 12px 60px}
  .jo-page{max-width:320px;margin:0 auto;background:#fff;border-radius:8px;
           box-shadow:0 4px 24px rgba(0,0,0,.22);padding:16px 14px 16px}
  .jo-toolbar{max-width:320px;margin:0 auto 14px;display:flex;gap:8px;justify-content:flex-end}
  .jo-toolbar button{padding:9px 18px;border:none;border-radius:5px;font-size:13px;
                     font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px}
  .btn-print{background:#003d7a;color:#fff}
  .btn-print:hover{background:#002a56}
  .btn-close{background:#6c757d;color:#fff}
  .btn-close:hover{background:#545b62}
}

/* ── Print ── */
@page {
  size: 80mm auto;          /* thermal receipt width, height auto-fits content */
  margin: 4mm 3mm;          /* small margins — real receipt printers use ~3–4mm */
}
@media print{
  body{margin:0;padding:0;background:#fff}
  .jo-page{box-shadow:none;border-radius:0;padding:0;max-width:80mm;width:80mm}
  .jo-toolbar{display:none!important}
  .no-print{display:none!important}
}

/* ── Receipt body ── */
.jo-receipt{font-family:'Courier New',Courier,monospace;font-size:11.5px;color:#111;line-height:1.5}

/* Header */
.jo-r-head{text-align:center;margin-bottom:8px}
.jo-r-logo-img{width:90px;height:auto;display:block;margin:0 auto 6px}
.jo-r-brand{font-size:13px;font-weight:700;color:#003d7a;margin-top:6px;letter-spacing:.5px;text-transform:uppercase}
.jo-r-branch{font-size:11px;font-weight:600;color:#222;margin-top:3px}
.jo-r-address{font-size:10px;color:#555;margin-top:2px}
.jo-r-tin{font-size:10px;color:#555;margin-top:2px}

/* Dividers */
.jo-r-div{border-top:1px dashed #888;margin:7px 0}
.jo-r-div2{border-top:3px double #111;margin:7px 0}

/* Title */
.jo-r-title{text-align:center;font-size:14px;font-weight:900;letter-spacing:1px;margin:5px 0 2px}
.jo-r-sub{text-align:center;font-size:9.5px;color:#555;margin-bottom:3px}

/* Section label */
.jo-r-lbl{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;
          color:#003d7a;margin:5px 0 3px}

/* Key-value rows */
.jo-r-row{display:flex;justify-content:space-between;align-items:baseline;
          margin-bottom:2px;gap:6px;font-size:11px}
.jo-r-key{color:#555;flex-shrink:0;min-width:88px}
.jo-r-val{text-align:right;word-break:break-word}
.jo-r-bold{font-weight:700}
.jo-r-note{font-size:9.5px;color:#666;font-style:italic;margin:2px 0}

/* Grand total */
.jo-r-grand{font-size:14px;font-weight:900;padding:3px 0}

/* Items table */
.jo-r-th{display:flex;font-size:9px;font-weight:700;letter-spacing:.5px;
         text-transform:uppercase;color:#555;border-bottom:1px solid #ccc;
         padding-bottom:2px;margin-bottom:2px}
.jo-r-tr{display:flex;font-size:10.5px;margin-bottom:3px;align-items:flex-start;
         border-bottom:1px dotted #ddd;padding-bottom:2px}
.jo-r-td-name{flex:1}
.jo-r-td-qty{width:26px;text-align:center;flex-shrink:0}
.jo-r-td-price{width:60px;text-align:right;flex-shrink:0}
.jo-r-td-sub{width:66px;text-align:right;flex-shrink:0;font-weight:600}
.jo-r-remarks{display:block;font-size:9px;color:#888;font-style:italic}

/* Status badge */
.jo-r-status{display:inline-block;font-size:9px;font-weight:700;padding:2px 7px;
             border-radius:10px;color:#fff}
.s-pending{background:#d97706}.s-approved{background:#16a34a}
.s-rejected{background:#dc2626}.s-adjusted{background:#7c3aed}

/* QR */
.jo-r-qr{text-align:center;margin:7px 0}
.jo-r-qr-lbl{font-size:9px;color:#888;margin-bottom:4px}
.jo-r-qr img{width:88px;height:88px}
.jo-r-qr-txt{font-size:8px;color:#aaa;word-break:break-all}

/* Footer */
.jo-r-foot{text-align:center;margin-top:7px}
.jo-r-foot-title{font-size:11px;font-weight:700;margin-bottom:3px}
.jo-r-foot-line{font-size:9.5px;color:#555;margin-bottom:2px}
.jo-r-foot-meta{font-size:8.5px;color:#aaa;margin-top:5px}
</style>
</head>
<body>

<!-- Toolbar (hidden on print) -->
<div class="jo-toolbar">
  <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  <button class="btn-close" onclick="window.close()"><i class="fas fa-times"></i> Close</button>
</div>

<div class="jo-page">
<div class="jo-receipt">

  <!-- ══ HEADER ══════════════════════════════════════════════════════════════ -->
  <div class="jo-r-head">
    <img src="<?php echo htmlspecialchars($logo); ?>"
         alt="Petron"
         class="jo-r-logo-img"
         onerror="this.style.display='none'">
    <div class="jo-r-brand">PETRON STATION MANAGEMENT SYSTEM</div>
    <div class="jo-r-branch"><?php echo htmlspecialchars($station_addr); ?></div>
    <div class="jo-r-tin">VAT REG TIN: <?php echo htmlspecialchars($vat_tin); ?></div>
  </div>

  <div class="jo-r-div2"></div>
  <div class="jo-r-title"><?php echo htmlspecialchars($txn_type_label); ?></div>
  <div class="jo-r-sub"><?php echo htmlspecialchars($txn_type_sublabel); ?></div>
  <div class="jo-r-div"></div>

  <!-- ══ TRANSACTION DETAILS ═════════════════════════════════════════════════ -->
  <div class="jo-r-lbl">Transaction Details</div>

  <div class="jo-r-row"><span class="jo-r-key">Transaction ID</span><span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($txn_id); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Date</span><span class="jo-r-val"><?php echo $disp_date; ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Time</span><span class="jo-r-val"><?php echo $disp_time; ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Customer</span><span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($customer); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Staff</span><span class="jo-r-val"><?php echo htmlspecialchars($staff_name); ?></span></div>
  <?php if ($shift_name): ?>
  <div class="jo-r-row"><span class="jo-r-key">Shift</span><span class="jo-r-val"><?php echo htmlspecialchars($shift_name); ?></span></div>
  <?php endif; ?>

  <div class="jo-r-div"></div>

  <!-- ══ ITEMS ════════════════════════════════════════════════════════════════ -->
  <div class="jo-r-lbl">Items Purchased</div>

  <div class="jo-r-th">
    <span class="jo-r-td-name">Item</span>
    <span class="jo-r-td-qty">Qty</span>
    <span class="jo-r-td-price">Unit</span>
    <span class="jo-r-td-sub">Subtotal</span>
  </div>

  <?php if (!empty($items)): ?>
    <?php foreach ($items as $it):
      $iname  = $it['product_name'] ?? $it['name'] ?? 'Item';
      $icat   = $it['category']     ?? '';
      $isize  = $it['size_variant'] ?? $it['size'] ?? '';
      $iqty   = (float)($it['quantity'] ?? $it['qty'] ?? 1);
      $iprice = (float)($it['unit_price'] ?? $it['price'] ?? 0);
      $isub   = (float)($it['subtotal']   ?? $it['amount'] ?? ($iprice * $iqty));
    ?>
    <div class="jo-r-tr">
      <span class="jo-r-td-name">
        <?php echo htmlspecialchars($iname); ?>
        <?php if ($icat || $isize): ?>
          <span class="jo-r-remarks"><?php echo htmlspecialchars(implode(' · ', array_filter([$icat, $isize]))); ?></span>
        <?php endif; ?>
      </span>
      <span class="jo-r-td-qty"><?php echo number_format($iqty, 0); ?></span>
      <span class="jo-r-td-price">&#8369;<?php echo number_format($iprice, 2); ?></span>
      <span class="jo-r-td-sub">&#8369;<?php echo number_format($isub, 2); ?></span>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="jo-r-row"><span style="color:#888;font-style:italic">No item details available.</span></div>
  <?php endif; ?>

  <div class="jo-r-div"></div>

  <!-- ══ JOB ORDER DETAILS (shown only when a Job Order is linked) ══════════ -->
  <?php if ($has_jo): ?>
  <div class="jo-r-lbl" style="color:#b45309;">Job Order Details</div>

  <?php if (!empty($job_order['job_order_id']) || !empty($job_order['job_order_number'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Job Order ID</span>
    <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($job_order['job_order_id'] ?? $job_order['job_order_number'] ?? '—'); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['service_type'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Service Type</span>
    <span class="jo-r-val"><?php echo htmlspecialchars($job_order['service_type']); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['service_description'])): ?>
  <div class="jo-r-row" style="align-items:flex-start;">
    <span class="jo-r-key" style="padding-top:1px;">Description</span>
    <span class="jo-r-val" style="font-size:10px;color:#555;"><?php echo nl2br(htmlspecialchars($job_order['service_description'])); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['vehicle_plate'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Vehicle Plate</span>
    <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($job_order['vehicle_plate']); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['vehicle_type'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Vehicle Type</span>
    <span class="jo-r-val"><?php echo htmlspecialchars($job_order['vehicle_type']); ?></span>
  </div>
  <?php endif; ?>

  <?php if (!empty($job_order['mechanic_name'])): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">Mechanic</span>
    <span class="jo-r-val"><?php echo htmlspecialchars($job_order['mechanic_name']); ?></span>
  </div>
  <?php endif; ?>

  <div class="jo-r-div"></div>
  <?php endif; ?>

  <!-- ══ TOTALS ════════════════════════════════════════════════════════════════ -->
  <div class="jo-r-row"><span class="jo-r-key">Vatable Sales</span><span class="jo-r-val">&#8369;<?php echo number_format($vatable, 2); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">VAT (12%)</span><span class="jo-r-val">&#8369;<?php echo number_format($vat_amt, 2); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Zero-Rated</span><span class="jo-r-val">&#8369;0.00</span></div>
  <div class="jo-r-row"><span class="jo-r-key">VAT-Exempt</span><span class="jo-r-val">&#8369;0.00</span></div>

  <div class="jo-r-div2"></div>
  <div class="jo-r-row jo-r-grand">
    <span>GRAND TOTAL</span>
    <span>&#8369;<?php echo number_format($total, 2); ?></span>
  </div>
  <div class="jo-r-div"></div>

  <!-- ══ PAYMENT ═══════════════════════════════════════════════════════════════ -->
  <div class="jo-r-lbl">Payment</div>

  <div class="jo-r-row">
    <span class="jo-r-key">Method</span>
    <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars(strtoupper($pay_method)); ?></span>
  </div>

  <?php if ($pay_status_norm === 'partial'): ?>
    <!-- ── PARTIAL PAYMENT ── -->
    <div class="jo-r-row">
      <span class="jo-r-key">Amount Paid</span>
      <span class="jo-r-val jo-r-bold">&#8369;<?php echo number_format($amount_paid_db, 2); ?></span>
    </div>
    <div class="jo-r-row" style="color:#9a3412;font-weight:700;">
      <span class="jo-r-key">Balance Due</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($balance_due_db, 2); ?></span>
    </div>
    <?php if ($pm_lc === 'cash' && $tendered > 0): ?>
    <div class="jo-r-row"><span class="jo-r-key">Change</span><span class="jo-r-val">&#8369;<?php echo number_format($change, 2); ?></span></div>
    <?php endif; ?>
    <?php if (!empty($sale['card_reference'])): ?>  <div class="jo-r-row"><span class="jo-r-key">Ref No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['card_reference'] ?: $sale['ewallet_reference'] ?: $sale['efuel_card_number']); ?></span></div><?php endif; ?>

  <?php elseif ($pay_status_norm === 'pending'): ?>
    <!-- ── PENDING PAYMENT (no amount collected yet) ── -->
    <div class="jo-r-row">
      <span class="jo-r-key">Amount Paid</span>
      <span class="jo-r-val">&#8369;0.00</span>
    </div>
    <div class="jo-r-row" style="color:#9a3412;font-weight:700;">
      <span class="jo-r-key">Balance Due</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span>
    </div>

  <?php elseif ($pay_status_norm === 'credit'): ?>
    <!-- ── CREDIT (UTANG) ── -->
    <div class="jo-r-row"><span class="jo-r-key">Amount Paid</span><span class="jo-r-val">&#8369;0.00</span></div>
    <div class="jo-r-row" style="color:#6b21a8;font-weight:700;"><span class="jo-r-key">Credit Amount</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
    <div class="jo-r-row" style="font-size:9.5px;color:#856404"><span>Transaction forwarded to Receivables module.</span></div>

  <?php else: ?>
    <!-- ── PAID (full payment) ── -->
    <?php if ($pm_lc === 'cash'): ?>
      <div class="jo-r-row">
        <span class="jo-r-key">Amount Tendered</span>
        <span class="jo-r-val">&#8369;<?php echo number_format($tendered > 0 ? $tendered : $total, 2); ?></span>
      </div>
      <div class="jo-r-row">
        <span class="jo-r-key">Change</span>
        <span class="jo-r-val jo-r-bold">&#8369;<?php echo number_format($change, 2); ?></span>
      </div>

    <?php elseif ($pm_lc === 'card'): ?>
      <div class="jo-r-row"><span class="jo-r-key">Amount Charged</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php if (!empty($sale['card_reference'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Card Ref No.</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['card_reference']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['card_type'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Card Type</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['card_type']); ?></span></div>
      <?php endif; ?>

    <?php elseif ($pm_lc === 'e-wallet'): ?>
      <div class="jo-r-row"><span class="jo-r-key">Amount Transferred</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php if (!empty($sale['ewallet_reference'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">E-Wallet Ref</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['ewallet_reference']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($sale['ewallet_provider'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">Provider</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['ewallet_provider']); ?></span></div>
      <?php endif; ?>

    <?php elseif ($pm_lc === 'e-fuel card'): ?>
      <div class="jo-r-row"><span class="jo-r-key">Amount Deducted</span><span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php if (!empty($sale['efuel_card_number'])): ?>
      <div class="jo-r-row"><span class="jo-r-key">E-Fuel Card</span><span class="jo-r-val"><?php echo htmlspecialchars($sale['efuel_card_number']); ?></span></div>
      <?php endif; ?>
    <?php endif; ?>

  <?php endif; ?>

  <!-- Payment Status Badge removed for clean layout -->
  
  <?php if (!empty($sale['remarks'])): ?>
  <div class="jo-r-div"></div>
  <div class="jo-r-note">Remarks: <?php echo htmlspecialchars($sale['remarks']); ?></div>
  <?php endif; ?>

  <div class="jo-r-div"></div>

  <!-- ══ QR CODE ═══════════════════════════════════════════════════════════════ -->
  <div class="jo-r-qr">
    <div class="jo-r-qr-lbl">Scan QR to verify this transaction</div>
    <img src="<?php echo htmlspecialchars($qr_url); ?>"
         alt="QR Code"
         width="120" height="120"
         onerror="this.style.display='none';document.getElementById('qr_fallback').style.display='block'">
    <div id="qr_fallback" style="display:none;font-size:7.5px;color:#555;word-break:break-all;
         background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:6px;
         text-align:left;margin-top:4px;white-space:pre-wrap;font-family:monospace;"><?php echo htmlspecialchars($qr_data); ?></div>
    <div class="jo-r-qr-lbl" style="margin-top:4px;font-size:8px;color:#94a3b8;">
      <?php echo htmlspecialchars($txn_id); ?> &nbsp;·&nbsp; <?php echo strtoupper($pay_status_norm ?? 'paid'); ?>
    </div>
  </div>

  <div class="jo-r-div"></div>

  <!-- ══ FOOTER ════════════════════════════════════════════════════════════════ -->
  <div class="jo-r-foot">
    <div class="jo-r-foot-title">Official Merchandise/Service Transaction Receipt</div>
    <?php if ($pay_status_norm === 'partial'): ?>
    <div class="jo-r-foot-line" style="color:#92400e;font-weight:700;border:1px solid #fde68a;background:#fef9c3;padding:4px 6px;border-radius:4px;margin:4px 0;">
      &#9888; This receipt reflects a partial payment.<br>
      Balance due of &#8369;<?php echo number_format($balance_due_db, 2); ?> must be settled upon completion of service.
    </div>
    <?php elseif ($pay_status_norm === 'pending'): ?>
    <div class="jo-r-foot-line" style="color:#9a3412;font-weight:700;border:1px solid #fed7aa;background:#ffedd5;padding:4px 6px;border-radius:4px;margin:4px 0;">
      &#9888; No payment collected yet. Full balance of &#8369;<?php echo number_format($total, 2); ?> remains outstanding.
    </div>
    <?php elseif ($pay_status_norm === 'credit'): ?>
    <div class="jo-r-foot-line" style="color:#6b21a8;font-weight:700;border:1px solid #d8b4fe;background:#f3e8ff;padding:4px 6px;border-radius:4px;margin:4px 0;">
      &#9888; Credit transaction (Utang). Amount forwarded to the Receivables module.
    </div>
    <?php else: ?>
    <div class="jo-r-foot-line">This document is valid as an official service record.</div>
    <?php endif; ?>
    <div class="jo-r-foot-line">VAT-Registered &nbsp;|&nbsp; TIN: <?php echo $vat_tin; ?></div>
    <div class="jo-r-foot-line">Thank you for choosing Petron!</div>
    <div class="jo-r-foot-meta">
      Printed: <?php echo date('M j, Y h:i A'); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($txn_id); ?>
    </div>
  </div>

</div><!-- /.jo-receipt -->
</div><!-- /.jo-page -->

</body>
</html>
