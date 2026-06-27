<?php
$page_id = 'admin_purchase_orders';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// Fetch station details for delivery location auto-fill
$station_q = $pdo->prepare("SELECT * FROM stations WHERE id = ? LIMIT 1");
$station_q->execute([$station_id]);
$station_data = $station_q->fetch(PDO::FETCH_ASSOC);
$station_name    = $station_data['name'] ?? 'Petron Carmen';
$_raw_addr       = trim($station_data['address'] ?? '');
$_raw_loc        = trim($station_data['location'] ?? '');
if (empty($_raw_addr) && !empty($_raw_loc) && $_raw_loc !== 'CDO') {
    $_raw_addr = $_raw_loc;
} elseif (empty($_raw_addr)) {
    $_raw_addr = 'Vamenta Blvd., Carmen, City of Cagayan de Oro, Misamis Oriental';
}
$station_address = $_raw_addr;

if (!in_array($role, ['admin','superadmin'])) {
    header('Location: dashboard.php'); exit;
}

// Ensure admin finalization and batch columns exist
foreach ([
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_id INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS approved_by INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS admin_finalized_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_at DATETIME NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_by INT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS po_number VARCHAR(100) NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS approved_by INT NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS po_number VARCHAR(100) NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS notes TEXT NULL",
    "ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

// Ensure purchase_orders status ENUM contains all needed values
try {
    $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN status
        ENUM('Draft','Pending Approval','Approved','Rejected','Pending','Confirmed','Received',
             'Cancelled','Pending Admin Validation','Official','Approved PO',
             'Admin Finalized','Rejected by Admin')
        DEFAULT 'Pending Admin Validation'");
} catch (Exception $e) {}

// Ensure fuel_purchase_orders.status is a flexible VARCHAR (not an ENUM)
// so 'Pending Admin Validation' and other values are always accepted
try {
    $pdo->exec("ALTER TABLE fuel_purchase_orders MODIFY COLUMN status VARCHAR(100) NOT NULL DEFAULT 'Pending Admin Validation'");
} catch (Exception $e) {}

// Backfill purchase_orders empty-status rows
try {
    $pdo->exec("UPDATE purchase_orders SET status='Admin Finalized' WHERE admin_finalized=1 AND (status='' OR status IS NULL)");
    $pdo->exec("UPDATE purchase_orders SET status='Pending Admin Validation' WHERE admin_finalized=0 AND (status='' OR status IS NULL OR status='Pending Admin Approval')");
} catch (Exception $e) {}

// Backfill fuel_purchase_orders: fix old 'forwarded'/'pending' rows created by manager to
// show as Pending Admin Validation so admin can see and finalize them
try {
    $pdo->exec("UPDATE fuel_purchase_orders SET status='Pending Admin Validation'
        WHERE LOWER(TRIM(status)) IN ('forwarded','pending','pending admin approval','')
          AND (batch_id IS NULL OR batch_id = '')
          AND (approved_by IS NULL)");
} catch (Exception $e) {}

$flash_ok  = $_SESSION['ok']  ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $po_type = $_POST['po_type'] ?? '';
    $po_date = $_POST['po_date'] ?? '';

    if ($action === 'finalize_batch') {
        $batch_id              = trim($_POST['batch_id_override'] ?? '');
        $submit_action         = trim($_POST['submit_action'] ?? 'finalize_po');
        $exp_date              = trim($_POST['expected_delivery_date'] ?? '');
        $exp_time              = trim($_POST['expected_delivery_time'] ?? '');
        $receiving_personnel   = trim($_POST['receiving_personnel'] ?? 'Any Assigned Staff');
        $delivery_instructions = trim($_POST['delivery_instructions'] ?? '');
        $payment_terms         = trim($_POST['payment_terms'] ?? '30 Days');
        $remarks               = trim($_POST['remarks'] ?? '');
        $items_input           = $_POST['items'] ?? []; // Array of [id => [qty, price]]

        if (empty($batch_id)) {
            $_SESSION['err'] = 'Batch ID / PO Number is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }

        // Format expected delivery fields
        $db_exp_date = !empty($exp_date) ? $exp_date : date('Y-m-d', strtotime('+3 days'));
        $time_str    = !empty($exp_time) ? date("g:i A", strtotime($exp_time)) : '9:00 AM';

        // Build structured notes format
        $structured_notes = "Expected Time: " . $time_str . "\n"
                          . "Receiving Personnel: " . $receiving_personnel . "\n"
                          . "Payment Terms: " . $payment_terms . "\n"
                          . "Instructions: " . $delivery_instructions . "\n"
                          . "Remarks: " . $remarks;

        // Determine target status
        $is_draft = ($submit_action === 'save_draft');
        if ($is_draft) {
            $db_status       = 'Draft';
            $admin_finalized = 0;
        } else {
            $db_status       = ($po_type === 'fuel') ? 'Approved PO' : 'Admin Finalized';
            $admin_finalized = 1;
        }

        try {
            $pdo->beginTransaction();

            // Get or create Petron supplier ID
            $sup_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' LIMIT 1")->fetchColumn() ?: 0;
            if (!$sup_id) {
                $pdo->exec("INSERT INTO suppliers (name) VALUES ('Petron Corporation')");
                $sup_id = $pdo->lastInsertId();
            }

            // If finalizing, prevent duplicates in deliveries_oversight by removing any old drafts
            if (!$is_draft) {
                $pdo->prepare("DELETE FROM deliveries_oversight WHERE batch_id = ?")->execute([$batch_id]);
            }

            foreach ($items_input as $item_id => $data) {
                $qty = (float)($data['qty'] ?? 0);
                $price = (float)($data['price'] ?? 0);
                $total = round($qty * $price, 2);

                if ($qty <= 0) {
                    throw new Exception("Quantity must be greater than zero for all items.");
                }

                if ($po_type === 'merch') {
                    // Fetch PO record to check
                    $stmt_fetch = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=?");
                    $stmt_fetch->execute([$item_id, $station_id]);
                    $po_rec = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
                    if (!$po_rec) {
                        throw new Exception("Merchandise request item #{$item_id} not found.");
                    }

                    // Generate unique delivery_ref for deliveries_oversight
                    $delivery_ref_prefix = 'MDR-' . date('Ymd') . '-';
                    $stmt_max = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(delivery_ref, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE delivery_ref LIKE ?");
                    $stmt_max->execute([$delivery_ref_prefix . '%']);
                    $max_num = (int)$stmt_max->fetchColumn();
                    $delivery_ref = $delivery_ref_prefix . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);

                    // Update purchase_orders
                    $pdo->prepare("
                        UPDATE purchase_orders
                        SET quantity = ?,
                            unit_price = ?,
                            total_amount = ?,
                            admin_finalized = ?,
                            admin_id = ?,
                            approved_by = ?,
                            admin_notes = ?,
                            expected_delivery_date = ?,
                            admin_finalized_at = IF(? = 1, NOW(), NULL),
                            batch_id = ?,
                            po_number = ?,
                            supplier_id = ?,
                            supplier_name = 'Petron Corporation',
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$qty, $price, $total, $admin_finalized, $me['id'], $me['id'], $structured_notes, $db_exp_date, $admin_finalized, $batch_id, $batch_id, $sup_id, $db_status, $item_id]);

                    // Sync to deliveries_oversight ONLY if finalized
                    if (!$is_draft) {
                        $pdo->prepare("
                            INSERT INTO deliveries_oversight (
                                delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                                delivery_date, station_id, status, source_ref, remarks, unit_price, expected_quantity,
                                created_at, updated_at
                            ) VALUES (
                                'merchandise', ?, ?, 'Petron Corporation', ?, ?, 'pcs',
                                ?, ?, 'Expected Delivery', ?, ?, ?, ?, NOW(), NOW()
                            )
                        ")->execute([
                            $delivery_ref,
                            $batch_id,
                            $po_rec['product_name'],
                            $qty,
                            $db_exp_date,
                            $po_rec['station_id'],
                            $batch_id,
                            $structured_notes,
                            $price,
                            $qty
                        ]);
                    }
                } else if ($po_type === 'fuel') {
                    // Fetch fuel PO record
                    $stmt_fetch = $pdo->prepare("
                        SELECT fpo.*, ft.name AS fuel_name
                        FROM fuel_purchase_orders fpo
                        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                        WHERE fpo.id=? AND fpo.station_id=?
                    ");
                    $stmt_fetch->execute([$item_id, $station_id]);
                    $po_rec = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
                    if (!$po_rec) {
                        throw new Exception("Fuel request item #{$item_id} not found.");
                    }

                    // Generate unique delivery_ref for deliveries_oversight
                    $fuel_delivery_ref_prefix = 'FDR-' . date('Ymd') . '-';
                    $stmt_max_fuel = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(delivery_ref, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE delivery_ref LIKE ?");
                    $stmt_max_fuel->execute([$fuel_delivery_ref_prefix . '%']);
                    $max_num_fuel = (int)$stmt_max_fuel->fetchColumn();
                    $fuel_delivery_ref = $fuel_delivery_ref_prefix . str_pad($max_num_fuel + 1, 4, '0', STR_PAD_LEFT);

                    // Update fuel_purchase_orders
                    $pdo->prepare("
                        UPDATE fuel_purchase_orders
                        SET volume = ?,
                            unit_price = ?,
                            total_amount = ?,
                            status = ?,
                            approved_by = ?,
                            approved_at = IF(? = 1, NOW(), NULL),
                            batch_id = ?,
                            po_number = ?,
                            supplier_id = ?,
                            notes = ?,
                            expected_delivery_date = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$qty, $price, $total, $db_status, $me['id'], $admin_finalized, $batch_id, $batch_id, $sup_id, $structured_notes, $db_exp_date, $item_id]);

                    // Sync to deliveries_oversight ONLY if finalized
                    if (!$is_draft) {
                        $pdo->prepare("
                            INSERT INTO deliveries_oversight (
                                delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                                delivery_date, station_id, status, source_ref, remarks, unit_price, expected_quantity,
                                created_at, updated_at
                            ) VALUES (
                                'fuel', ?, ?, 'Petron Corporation', ?, ?, 'L',
                                ?, ?, 'Expected Delivery', ?, ?, ?, ?, NOW(), NOW()
                            )
                        ")->execute([
                            $fuel_delivery_ref,
                            $batch_id,
                            $po_rec['fuel_name'],
                            $qty,
                            $db_exp_date,
                            $po_rec['station_id'],
                            $batch_id,
                            $structured_notes,
                            $price,
                            $qty
                        ]);
                    }
                }
            }

            log_activity($pdo, $me['id'], 'Admin Finalize PO Batch', "Grouped PO for date {$po_date} processed as batch {$batch_id} (Status: {$db_status}).");
            $pdo->commit();

            if ($submit_action === 'print_po') {
                header("Location: print_po_new.php?batch_id=" . urlencode($batch_id) . "&type=" . urlencode($po_type) . "&print=1");
                exit;
            }

            $_SESSION['ok'] = $is_draft 
                ? "Draft PO {$batch_id} saved successfully." 
                : "Batch {$batch_id} finalized and forwarded to Expected Deliveries.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['err'] = $e->getMessage();
        }
        header('Location: admin_purchase_orders.php');
        exit;
    }

    if ($action === 'reject_batch') {
        $reason = trim($_POST['reject_reason'] ?? '');
        if (empty($reason)) {
            $_SESSION['err'] = 'Rejection reason is required.';
            header('Location: admin_purchase_orders.php');
            exit;
        }

        try {
            $pdo->beginTransaction();

            if ($po_type === 'merch') {
                // Fetch items for this date
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM purchase_orders
                    WHERE station_id = ? AND type = 'merch' AND DATE(created_at) = ? AND status = 'Pending Admin Validation' AND admin_finalized = 0
                ");
                $stmt->execute([$station_id, $po_date]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $pdo->prepare("
                        UPDATE purchase_orders
                        SET status = 'Rejected by Admin',
                            admin_notes = ?,
                            admin_id = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$reason, $me['id'], $item['id']]);
                }
            } else if ($po_type === 'fuel') {
                // Fetch items for this date
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM fuel_purchase_orders
                    WHERE station_id = ? AND DATE(created_at) = ? AND status IN ('Pending Admin Validation', 'Pending')
                ");
                $stmt->execute([$station_id, $po_date]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $pdo->prepare("
                        UPDATE fuel_purchase_orders
                        SET status = 'Rejected',
                            notes = ?,
                            approved_by = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$reason, $me['id'], $item['id']]);
                }
            }

            log_activity($pdo, $me['id'], 'Admin Reject PO Batch', "Grouped PO for date {$po_date} rejected. Reason: {$reason}");
            $pdo->commit();
            $_SESSION['ok'] = "Batch requests for date {$po_date} rejected.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['err'] = $e->getMessage();
        }
        header('Location: admin_purchase_orders.php');
        exit;
    }
}

// ── FILTERS ───────────────────────────────────────────────────────────────────
$filter_date   = trim($_GET['filter_date'] ?? '');
$filter_status = trim($_GET['filter_status'] ?? '');
$filter_search = strtolower(trim($_GET['search'] ?? ''));

// ── FETCH ALL POs (MERGED, GROUPED BY BATCH/DATE) ────────────────────────────
$all_pos = [];

// Merchandise POs — group by batch_id if finalized, else by date+status
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(po.batch_id,
                CONCAT('POM-', DATE_FORMAT(MIN(po.created_at),'%Y%m%d'), '-', LPAD(MIN(po.id),4,'0'))) AS po_no,
            po.batch_id,
            DATE(MIN(po.created_at)) AS group_date,
            'Petron Corporation'   AS supplier,
            'Merchandise'          AS category,
            MIN(po.created_at)     AS date_created,
            COUNT(*)               AS total_items,
            SUM(COALESCE(po.total_amount,0)) AS total_amount,
            CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS created_by,
            MAX(po.status)         AS status,
            MIN(po.id)             AS id,
            MAX(po.stock_in_done)  AS stock_in_done,
            'merch'                AS po_type,
            GROUP_CONCAT(po.product_name ORDER BY po.id SEPARATOR ', ') AS detail,
            MAX(po.admin_notes)    AS notes
        FROM purchase_orders po
        LEFT JOIN users u ON po.created_by = u.id
        WHERE po.station_id = ?
        GROUP BY
            COALESCE(po.batch_id, CONCAT(DATE(po.created_at), '-', po.status))
        ORDER BY MIN(po.created_at) DESC
    ");
    $stmt->execute([$station_id]);
    $all_pos = array_merge($all_pos, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

// Fuel POs — group by batch_id if finalized, else by date+status
try {
    $stmt2 = $pdo->prepare("
        SELECT
            COALESCE(fpo.batch_id,
                CONCAT('POF-', DATE_FORMAT(MIN(fpo.created_at),'%Y%m%d'), '-', LPAD(MIN(fpo.id),4,'0'))) AS po_no,
            fpo.batch_id,
            DATE(MIN(fpo.created_at)) AS group_date,
            'Petron Corporation'   AS supplier,
            'Fuel'                 AS category,
            MIN(fpo.created_at)    AS date_created,
            COUNT(*)               AS total_items,
            SUM(COALESCE(fpo.total_amount,0)) AS total_amount,
            COALESCE(MAX(u.name), CONCAT(COALESCE(MAX(u.first_name),''), ' ', COALESCE(MAX(u.last_name),''))) AS created_by,
            MAX(fpo.status)        AS status,
            MIN(fpo.id)            AS id,
            0                      AS stock_in_done,
            'fuel'                 AS po_type,
            GROUP_CONCAT(COALESCE(ft.name,'Fuel') ORDER BY fpo.id SEPARATOR ', ') AS detail,
            MAX(fpo.notes)         AS notes
        FROM fuel_purchase_orders fpo
        LEFT JOIN users u ON fpo.created_by = u.id
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        WHERE fpo.station_id = ?
        GROUP BY
            COALESCE(fpo.batch_id, CONCAT(DATE(fpo.created_at), '-', fpo.status))
        ORDER BY MIN(fpo.created_at) DESC
    ");
    $stmt2->execute([$station_id]);
    $all_pos = array_merge($all_pos, $stmt2->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

// Sort merged list by date desc
usort($all_pos, fn($a,$b) => strtotime($b['date_created'] ?? 0) - strtotime($a['date_created'] ?? 0));

// ── SUMMARY COUNTS ────────────────────────────────────────────────────────────
$PENDING_ST   = ['pending admin validation','pending','pending approval','draft','forwarded'];
$APPROVED_ST  = ['admin finalized','approved po','approved','official'];
$DELIVERED_ST = ['delivered','received','confirmed','stocked in'];
$CANCELLED_ST = ['rejected by admin','rejected','cancelled'];

$cnt_total     = count($all_pos);
$cnt_pending   = 0; $cnt_approved = 0; $cnt_delivered = 0; $cnt_cancelled = 0;
foreach ($all_pos as $po) {
    $st = strtolower(trim($po['status'] ?? ''));
    if (in_array($st, $PENDING_ST))                        $cnt_pending++;
    elseif ($po['stock_in_done'] || in_array($st,$DELIVERED_ST)) $cnt_delivered++;
    elseif (in_array($st, $CANCELLED_ST))                  $cnt_cancelled++;
    elseif (in_array($st, $APPROVED_ST))                   $cnt_approved++;
}

// ── APPLY FILTERS ─────────────────────────────────────────────────────────────
$display_pos = $all_pos;
if ($filter_date !== '') {
    $display_pos = array_filter($display_pos,
        fn($p) => date('Y-m-d', strtotime($p['date_created'])) === $filter_date);
}
if ($filter_status !== '') {
    $display_pos = array_filter($display_pos, function($p) use ($filter_status, $PENDING_ST, $APPROVED_ST, $DELIVERED_ST, $CANCELLED_ST) {
        $st = strtolower(trim($p['status'] ?? ''));
        if ($filter_status === 'pending')   return in_array($st, $PENDING_ST);
        if ($filter_status === 'approved')  return in_array($st, $APPROVED_ST);
        if ($filter_status === 'delivered') return $p['stock_in_done'] || in_array($st, $DELIVERED_ST);
        if ($filter_status === 'cancelled') return in_array($st, $CANCELLED_ST);
        return true;
    });
}
if ($filter_search !== '') {
    $display_pos = array_filter($display_pos,
        fn($p) => str_contains(strtolower($p['po_no']), $filter_search)
               || str_contains(strtolower($p['detail'] ?? ''), $filter_search));
}
$display_pos = array_values($display_pos);

// ── Legacy pending data for finalize modals ───────────────────────────────────
$pending_merch_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name,
               sr.item_sku, sr.item_category, sr.remarks AS sr_remarks, sr.current_stock
        FROM purchase_orders po
        LEFT JOIN users u_mgr ON po.created_by = u_mgr.id
        LEFT JOIN stock_requests sr ON po.request_id = sr.id
        WHERE po.station_id = ? AND po.type = 'merch'
          AND po.status = 'Pending Admin Validation' AND po.admin_finalized = 0
        ORDER BY po.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $pending_merch_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group in PHP
$grouped_pending_merch = [];
foreach ($pending_merch_items as $item) {
    $date = date('Y-m-d', strtotime($item['created_at']));
    $grouped_pending_merch[$date][] = $item;
}

$pending_fuel_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT fpo.*, ft.name AS fuel_name, CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN users u_mgr ON fpo.created_by = u_mgr.id
        WHERE fpo.station_id = ? AND fpo.status IN ('Pending Admin Validation', 'Pending')
        ORDER BY fpo.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $pending_fuel_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group in PHP
$grouped_pending_fuel = [];
foreach ($pending_fuel_items as $item) {
    $date = date('Y-m-d', strtotime($item['created_at']));
    $grouped_pending_fuel[$date][] = $item;
}

// Total pending batches count
$total_pending_count = count($grouped_pending_merch) + count($grouped_pending_fuel);

// ── FETCH HISTORY DATA ────────────────────────────────────────────────────────
$finalized_merch_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name, 
               CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name,
               sr.item_sku
        FROM purchase_orders po
        LEFT JOIN users u_mgr ON po.created_by = u_mgr.id
        LEFT JOIN users u_adm ON po.admin_id = u_adm.id
        LEFT JOIN stock_requests sr ON po.request_id = sr.id
        WHERE po.station_id = ? AND po.type = 'merch' AND po.admin_finalized = 1
        ORDER BY po.admin_finalized_at DESC
    ");
    $stmt->execute([$station_id]);
    $finalized_merch_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group by DATE(created_at) — one row per date, one Print PO button per date
$grouped_finalized_merch = [];
foreach ($finalized_merch_items as $item) {
    $date_key = date('Y-m-d', strtotime($item['created_at']));
    $grouped_finalized_merch[$date_key][] = $item;
}

$finalized_fuel_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT fpo.*, ft.name AS fuel_name, CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name, 
               CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN users u_mgr ON fpo.created_by = u_mgr.id
        LEFT JOIN users u_adm ON fpo.approved_by = u_adm.id
        WHERE fpo.station_id = ? AND fpo.status NOT IN ('Pending Admin Validation', 'Pending', 'Draft')
        ORDER BY fpo.approved_at DESC
    ");
    $stmt->execute([$station_id]);
    $finalized_fuel_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group by DATE(created_at) — one row per date, one Print PO button per date
$grouped_finalized_fuel = [];
foreach ($finalized_fuel_items as $item) {
    $date_key = date('Y-m-d', strtotime($item['created_at']));
    $grouped_finalized_fuel[$date_key][] = $item;
}

// Fetch deliveries_oversight status index for fuel complete check
$fuel_oversight_status = [];
try {
    $dos = $pdo->query("SELECT source_ref, status FROM deliveries_oversight WHERE delivery_type='fuel'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dos as $do) {
        $fuel_oversight_status[strtolower(trim($do['source_ref']))] = $do['status'];
    }
} catch(Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<?php include __DIR__ . '/admin_po_css.php'; ?>
<?php include __DIR__ . '/admin_po_body.php'; ?>
<?php include __DIR__ . '/admin_po_modals.php'; ?>
