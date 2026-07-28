<?php
$page_id = 'admin_supplier_billing';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['admin', 'superadmin'], true)) {
    header('Location: dashboard.php');
    exit;
}
if ($station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}
if (!in_array($role, ['superadmin', 'developer'], true) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

$active_tab = $_GET['tab'] ?? 'merchandise';
if (!in_array($active_tab, ['merchandise', 'fuel'], true)) {
    $active_tab = 'merchandise';
}

$flash_msg = $_SESSION['supplier_billing_msg'] ?? '';
$flash_type = $_SESSION['supplier_billing_type'] ?? 'success';
unset($_SESSION['supplier_billing_msg'], $_SESSION['supplier_billing_type']);

sb_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_supplier_payment') {
    $invoice_type = $_POST['invoice_type'] ?? '';
    $invoice_no = trim($_POST['invoice_no'] ?? '');
    $po_number = trim($_POST['po_number'] ?? '');

    try {
        if (!in_array($invoice_type, ['merchandise', 'fuel'], true) || $invoice_no === '' || $po_number === '') {
            throw new Exception('Invalid supplier invoice selected.');
        }

        $all_invoices = $invoice_type === 'fuel'
            ? sb_fetch_fuel_invoices($pdo, $station_id)
            : sb_fetch_merchandise_invoices($pdo, $station_id);
        sb_apply_payment_status($pdo, $station_id, $all_invoices);

        $key = sb_invoice_key($invoice_type, $invoice_no, $po_number);
        if (empty($all_invoices[$key])) {
            throw new Exception('Supplier invoice was not found or is not ready for billing.');
        }

        $invoice = $all_invoices[$key];
        if (($invoice['payment_status'] ?? 'Pending') === 'Paid') {
            throw new Exception('This supplier invoice is already marked as paid.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO supplier_invoice_payments
                (station_id, invoice_type, invoice_no, po_number, supplier, invoice_date, due_date,
                 total_amount, payment_status, approved_by, approved_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                supplier = VALUES(supplier),
                invoice_date = VALUES(invoice_date),
                due_date = VALUES(due_date),
                total_amount = VALUES(total_amount),
                payment_status = IF(payment_status = 'Paid', payment_status, 'Approved'),
                approved_by = IF(payment_status = 'Paid', approved_by, VALUES(approved_by)),
                approved_at = IF(payment_status = 'Paid', approved_at, NOW()),
                updated_at = NOW()
        ");
        $stmt->execute([
            $station_id,
            $invoice_type,
            $invoice['invoice_no'],
            $invoice['po_number'],
            $invoice['supplier'],
            $invoice['invoice_date'],
            $invoice['due_date'],
            $invoice['total_amount'],
            (int)$me['id'],
        ]);

        if (function_exists('log_activity')) {
            log_activity($pdo, (int)$me['id'], 'Approve Supplier Payment', strtoupper($invoice_type) . " invoice {$invoice_no} | PO {$po_number} | Amount " . number_format((float)$invoice['total_amount'], 2));
        }

        $_SESSION['supplier_billing_msg'] = "Supplier payment approved for invoice {$invoice_no}.";
        $_SESSION['supplier_billing_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['supplier_billing_msg'] = $e->getMessage();
        $_SESSION['supplier_billing_type'] = 'error';
    }

    header('Location: admin_supplier_billing.php?tab=' . urlencode($invoice_type ?: $active_tab));
    exit;
}

$merch_invoices = sb_fetch_merchandise_invoices($pdo, $station_id);
$fuel_invoices = sb_fetch_fuel_invoices($pdo, $station_id);
sb_apply_payment_status($pdo, $station_id, $merch_invoices);
sb_apply_payment_status($pdo, $station_id, $fuel_invoices);

$current_summary_invoices = $active_tab === 'fuel' ? $fuel_invoices : $merch_invoices;
$summary = sb_summary($current_summary_invoices);

include __DIR__ . '/../partials/header.php';

function sb_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_invoice_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL,
        invoice_type ENUM('merchandise','fuel') NOT NULL,
        invoice_no VARCHAR(100) NOT NULL,
        po_number VARCHAR(100) NOT NULL,
        supplier VARCHAR(200) NOT NULL,
        invoice_date DATE NULL,
        due_date DATE NULL,
        total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        payment_status ENUM('Pending','Approved','Paid') NOT NULL DEFAULT 'Pending',
        approved_by INT NULL,
        approved_at DATETIME NULL,
        paid_at DATETIME NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_supplier_invoice (station_id, invoice_type, invoice_no, po_number),
        INDEX idx_station_status (station_id, payment_status),
        INDEX idx_invoice_type (invoice_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        "ALTER TABLE merchandise_stock_in ADD COLUMN IF NOT EXISTS delivery_id INT NULL",
        "ALTER TABLE merchandise_stock_in ADD COLUMN IF NOT EXISTS selling_price DECIMAL(12,2) NOT NULL DEFAULT 0",
        "ALTER TABLE fuel_stock_in ADD COLUMN IF NOT EXISTS selling_price_per_liter DECIMAL(12,2) NOT NULL DEFAULT 0",
        "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS sales_invoice_no VARCHAR(100) NULL",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $ignored) {
        }
    }
}

function sb_invoice_key(string $type, string $invoice_no, string $po_number): string
{
    return strtolower($type) . '|' . strtolower(trim($invoice_no)) . '|' . strtolower(trim($po_number));
}

function sb_generated_invoice_no(string $prefix, $date, int $id): string
{
    $ts = strtotime((string)$date);
    $year = $ts ? date('Y', $ts) : date('Y');
    return $prefix . '-' . $year . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function sb_due_date($invoice_date): string
{
    $ts = strtotime((string)$invoice_date);
    if (!$ts) {
        $ts = time();
    }
    return date('Y-m-d', strtotime('+30 days', $ts));
}

function sb_invoice_from_remarks(?string $remarks): string
{
    $remarks = trim((string)$remarks);
    if ($remarks === '') {
        return '';
    }

    if (preg_match('/(?:sales\s*invoice|invoice|si)\s*(?:no\.?|number|#|:|-)?\s*([A-Z0-9][A-Z0-9._-]*)/i', $remarks, $match)) {
        return trim($match[1]);
    }

    return '';
}

function sb_supplier_invoice_candidate($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(?:DR|MDR|FDR)[-_ ]/i', $value)) {
        return '';
    }

    return $value;
}

function sb_resolve_supplier_invoice_no(array $candidates, string $prefix, $date, int $id): string
{
    foreach ($candidates as $candidate) {
        $invoice_no = sb_supplier_invoice_candidate($candidate);
        if ($invoice_no !== '') {
            return $invoice_no;
        }
    }

    return sb_generated_invoice_no($prefix, $date, $id);
}

function sb_add_invoice_item(array &$invoices, array $invoice, array $item): void
{
    $key = sb_invoice_key($invoice['type'], $invoice['invoice_no'], $invoice['po_number']);
    if (!isset($invoices[$key])) {
        $invoice['key'] = $key;
        $invoice['items'] = [];
        $invoice['total_amount'] = 0.0;
        $invoice['payment_status'] = 'Pending';
        $invoices[$key] = $invoice;
    }

    $invoices[$key]['items'][] = $item;
    $invoices[$key]['total_amount'] += (float)$item['total'];

    if (strtotime((string)$item['delivery_date']) && strtotime((string)$item['delivery_date']) < strtotime((string)$invoices[$key]['delivery_date'])) {
        $invoices[$key]['delivery_date'] = $item['delivery_date'];
        $invoices[$key]['invoice_date'] = $item['delivery_date'];
        $invoices[$key]['due_date'] = sb_due_date($item['delivery_date']);
    }
}

function sb_fetch_merchandise_invoices(PDO $pdo, int $station_id): array
{
    $invoices = [];
    try {
        $stmt = $pdo->prepare("
            SELECT
                msi.id,
                msi.po_id,
                msi.po_number,
                msi.product_id,
                msi.product_name,
                msi.sku,
                msi.qty_received,
                COALESCE(NULLIF(msi.unit_cost, 0),
                    (SELECT poi.unit_price
                     FROM purchase_order_items poi
                     WHERE poi.po_id = msi.po_id
                       AND (poi.product_id = msi.product_id OR LOWER(TRIM(poi.item_name)) = LOWER(TRIM(msi.product_name)))
                     ORDER BY poi.id DESC LIMIT 1),
                    (SELECT po.unit_price
                     FROM purchase_orders po
                     WHERE po.station_id = msi.station_id
                       AND (po.id = msi.po_id OR po.po_number = msi.po_number OR po.batch_id = msi.po_number)
                       AND (po.product_name IS NULL OR LOWER(TRIM(po.product_name)) = LOWER(TRIM(msi.product_name)))
                     ORDER BY po.id DESC LIMIT 1),
                    0
                ) AS unit_cost_calc,
                COALESCE(msi.total_cost, 0) AS total_cost,
                msi.encoded_at,
                MIN(d.id) AS delivery_id,
                MAX(NULLIF(d.sales_invoice_no, '')) AS invoice_no_raw,
                GROUP_CONCAT(DISTINCT d.remarks SEPARATOR ' | ') AS delivery_remarks,
                COALESCE(MAX(NULLIF(d.source_ref, '')), msi.po_number) AS po_no_raw,
                COALESCE(MAX(NULLIF(d.supplier, '')), 'Petron Corporation') AS supplier_name,
                MIN(COALESCE(d.delivery_date, DATE(msi.encoded_at))) AS delivery_date,
                COALESCE(MAX(NULLIF(ip.sku, '')), msi.sku, '') AS product_code
            FROM merchandise_stock_in msi
            LEFT JOIN deliveries_oversight d
                   ON d.station_id = msi.station_id
                  AND d.delivery_type = 'merchandise'
                  AND d.status IN ('Stock-In Complete', 'Confirmed', 'Closed')
                  AND (
                        d.id = msi.delivery_id
                        OR (
                            COALESCE(NULLIF(d.source_ref, ''), d.delivery_ref) = msi.po_number
                            AND LOWER(TRIM(d.product)) = LOWER(TRIM(msi.product_name))
                        )
                  )
            LEFT JOIN inventory_products ip ON ip.id = msi.product_id
            WHERE msi.station_id = ?
              AND COALESCE(msi.qty_received, 0) > 0
            GROUP BY msi.id, msi.po_id, msi.po_number, msi.product_id, msi.product_name,
                     msi.sku, msi.qty_received, msi.unit_cost, msi.total_cost, msi.encoded_at
            ORDER BY delivery_date DESC, msi.id DESC
        ");
        $stmt->execute([$station_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }

    foreach ($rows as $row) {
        $delivery_date = $row['delivery_date'] ?: date('Y-m-d', strtotime((string)$row['encoded_at']));
        $po_number = trim((string)($row['po_no_raw'] ?: $row['po_number'] ?: 'PO-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT)));
        $invoice_no = sb_resolve_supplier_invoice_no([
            $row['invoice_no_raw'] ?? '',
            sb_invoice_from_remarks($row['delivery_remarks'] ?? ''),
        ], 'INV', $delivery_date, (int)$row['id']);

        $qty = (float)$row['qty_received'];
        $unit_cost = (float)$row['unit_cost_calc'];
        $total = (float)$row['total_cost'];
        if ($total <= 0 && $qty > 0) {
            $total = $qty * $unit_cost;
        }

        sb_add_invoice_item($invoices, [
            'type' => 'merchandise',
            'invoice_no' => $invoice_no,
            'po_number' => $po_number,
            'supplier' => $row['supplier_name'] ?: 'Petron Corporation',
            'delivery_date' => $delivery_date,
            'invoice_date' => $delivery_date,
            'due_date' => sb_due_date($delivery_date),
        ], [
            'product_code' => $row['product_code'] ?: $row['sku'] ?: '-',
            'product_name' => $row['product_name'],
            'qty_received' => $qty,
            'unit_cost' => $unit_cost,
            'total' => $total,
            'delivery_date' => $delivery_date,
        ]);
    }

    return $invoices;
}

function sb_add_fuel_invoice_row(array &$invoices, array $row): void
{
    $delivery_date = $row['delivery_date'] ?: date('Y-m-d', strtotime((string)$row['encoded_at']));
    $po_number = trim((string)($row['po_number'] ?: 'FPO-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT)));
    $invoice_no = sb_resolve_supplier_invoice_no([
        $row['sales_invoice_no'] ?? '',
        sb_invoice_from_remarks($row['delivery_remarks'] ?? ''),
        $row['stock_invoice_no'] ?? '',
    ], 'INV', $delivery_date, (int)$row['id']);

    $liters = (float)$row['qty_received'];
    $cost = (float)$row['cost_per_liter'];
    $total = $liters * $cost;

    sb_add_invoice_item($invoices, [
        'type' => 'fuel',
        'invoice_no' => $invoice_no,
        'po_number' => $po_number,
        'supplier' => $row['supplier_name'] ?: 'Petron Corporation',
        'delivery_date' => $delivery_date,
        'invoice_date' => $delivery_date,
        'due_date' => sb_due_date($delivery_date),
    ], [
        'fuel_type' => $row['fuel_type'],
        'liters_received' => $liters,
        'cost_per_liter' => $cost,
        'total' => $total,
        'delivery_date' => $delivery_date,
    ]);
}

function sb_fetch_fuel_invoices(PDO $pdo, int $station_id): array
{
    $invoices = [];
    try {
        $stmt = $pdo->prepare("
            SELECT
                fsi.id,
                fsi.delivery_id,
                fsi.fuel_type,
                fsi.qty_received,
                fsi.encoded_at,
                fsi.invoice_no AS stock_invoice_no,
                d.sales_invoice_no,
                d.dr_number,
                d.remarks AS delivery_remarks,
                d.delivery_date,
                COALESCE(NULLIF(d.source_ref, ''), fsi.delivery_ref, CONCAT('FPO-', fsi.delivery_id)) AS po_number,
                COALESCE(NULLIF(d.supplier, ''), 'Petron Corporation') AS supplier_name,
                COALESCE(NULLIF(d.unit_cost, 0), NULLIF(d.unit_price, 0),
                    (SELECT NULLIF(fpo.unit_price, 0)
                     FROM fuel_purchase_orders fpo
                     LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
                     WHERE fpo.station_id = fsi.station_id
                       AND fpo.po_number = d.source_ref
                       AND LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(fsi.fuel_type))
                     ORDER BY fpo.id DESC LIMIT 1),
                    (SELECT NULLIF(fpo.unit_price, 0)
                     FROM fuel_purchase_orders fpo
                     WHERE fpo.station_id = fsi.station_id
                       AND fpo.po_number = COALESCE(NULLIF(d.source_ref, ''), fsi.delivery_ref)
                     ORDER BY ABS(COALESCE(fpo.volume, 0) - COALESCE(fsi.qty_received, 0)), fpo.id DESC LIMIT 1),
                    CASE
                        WHEN COALESCE(d.payable_amount, 0) > 0
                         AND COALESCE(NULLIF(d.actual_quantity, 0), NULLIF(d.quantity, 0), NULLIF(fsi.qty_received, 0)) IS NOT NULL
                        THEN d.payable_amount / COALESCE(NULLIF(d.actual_quantity, 0), NULLIF(d.quantity, 0), NULLIF(fsi.qty_received, 0))
                        ELSE NULL
                    END,
                    0
                ) AS cost_per_liter
            FROM fuel_stock_in fsi
            LEFT JOIN deliveries_oversight d
                   ON d.id = fsi.delivery_id
                  AND d.station_id = fsi.station_id
                  AND d.delivery_type = 'fuel'
            WHERE fsi.station_id = ?
              AND COALESCE(fsi.qty_received, 0) > 0
            ORDER BY COALESCE(d.delivery_date, DATE(fsi.encoded_at)) DESC, fsi.id DESC
        ");
        $stmt->execute([$station_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }

    foreach ($rows as $row) {
        sb_add_fuel_invoice_row($invoices, $row);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                d.id,
                d.id AS delivery_id,
                COALESCE(NULLIF(d.product, ''), 'Fuel') AS fuel_type,
                COALESCE(NULLIF(d.actual_quantity, 0), NULLIF(d.quantity, 0), 0) AS qty_received,
                COALESCE(d.finalized_at, d.updated_at, d.created_at) AS encoded_at,
                NULL AS stock_invoice_no,
                d.sales_invoice_no,
                d.dr_number,
                d.remarks AS delivery_remarks,
                d.delivery_date,
                COALESCE(NULLIF(d.source_ref, ''), NULLIF(d.delivery_ref, ''), CONCAT('FPO-', d.id)) AS po_number,
                COALESCE(NULLIF(d.supplier, ''), 'Petron Corporation') AS supplier_name,
                COALESCE(NULLIF(d.unit_cost, 0), NULLIF(d.unit_price, 0),
                    (SELECT NULLIF(fpo.unit_price, 0)
                     FROM fuel_purchase_orders fpo
                     LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
                     WHERE fpo.station_id = d.station_id
                       AND fpo.po_number = d.source_ref
                       AND LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(d.product))
                     ORDER BY fpo.id DESC LIMIT 1),
                    (SELECT NULLIF(fpo.unit_price, 0)
                     FROM fuel_purchase_orders fpo
                     WHERE fpo.station_id = d.station_id
                       AND fpo.po_number = COALESCE(NULLIF(d.source_ref, ''), NULLIF(d.delivery_ref, ''))
                     ORDER BY ABS(COALESCE(fpo.volume, 0) - COALESCE(NULLIF(d.actual_quantity, 0), NULLIF(d.quantity, 0), 0)), fpo.id DESC LIMIT 1),
                    CASE
                        WHEN COALESCE(d.payable_amount, 0) > 0
                         AND COALESCE(NULLIF(d.actual_quantity, 0), NULLIF(d.quantity, 0)) IS NOT NULL
                        THEN d.payable_amount / COALESCE(NULLIF(d.actual_quantity, 0), NULLIF(d.quantity, 0))
                        ELSE NULL
                    END,
                    0
                ) AS cost_per_liter
            FROM deliveries_oversight d
            LEFT JOIN fuel_stock_in fsi
                   ON fsi.delivery_id = d.id
                  AND fsi.station_id = d.station_id
            WHERE d.station_id = ?
              AND d.delivery_type = 'fuel'
              AND d.status IN ('Stock-In Complete', 'Confirmed', 'Closed', 'Completed')
              AND COALESCE(NULLIF(d.actual_quantity, 0), NULLIF(d.quantity, 0), 0) > 0
              AND fsi.id IS NULL
            ORDER BY COALESCE(d.delivery_date, DATE(d.updated_at), DATE(d.created_at)) DESC, d.id DESC
        ");
        $stmt->execute([$station_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            sb_add_fuel_invoice_row($invoices, $row);
        }
    } catch (Exception $e) {
        error_log('Supplier billing fuel fallback failed: ' . $e->getMessage());
    }

    return $invoices;
}

function sb_apply_payment_status(PDO $pdo, int $station_id, array &$invoices): void
{
    if (empty($invoices)) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT invoice_type, invoice_no, po_number, payment_status, approved_at, paid_at
        FROM supplier_invoice_payments
        WHERE station_id = ?
    ");
    $stmt->execute([$station_id]);
    $payments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payments[sb_invoice_key($row['invoice_type'], $row['invoice_no'], $row['po_number'])] = $row;
    }

    foreach ($invoices as $key => &$invoice) {
        if (isset($payments[$key])) {
            $invoice['payment_status'] = $payments[$key]['payment_status'] ?: 'Pending';
            $invoice['approved_at'] = $payments[$key]['approved_at'] ?? null;
            $invoice['paid_at'] = $payments[$key]['paid_at'] ?? null;
        }
    }
    unset($invoice);
}

function sb_summary(array $invoices): array
{
    $out = [
        'pending_count' => 0,
        'approved_count' => 0,
        'paid_count' => 0,
        'payable_total' => 0.0,
    ];

    foreach ($invoices as $invoice) {
        $status = $invoice['payment_status'] ?? 'Pending';
        if ($status === 'Pending') {
            $out['pending_count']++;
            $out['payable_total'] += (float)$invoice['total_amount'];
        } elseif ($status === 'Approved') {
            $out['approved_count']++;
            $out['payable_total'] += (float)$invoice['total_amount'];
        } elseif ($status === 'Paid') {
            $out['paid_count']++;
        }
    }

    return $out;
}

function sb_money($amount): string
{
    return '&#8369;' . number_format((float)$amount, 2);
}

function sb_date($date): string
{
    $ts = strtotime((string)$date);
    return $ts ? date('M d, Y', $ts) : '-';
}

function sb_badge_class(string $status): string
{
    if ($status === 'Paid') return 'paid';
    if ($status === 'Approved') return 'approved';
    return 'pending';
}
?>

<style>
:root { --blue:#002F70; --red:#dc3545; --green:#16a34a; --amber:#d97706; --muted:#64748b; --line:#e2e8f0; --soft:#f8fafc; }
.sb-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.sb-head h1{margin:0;color:var(--blue);font-size:26px;font-weight:800;display:flex;align-items:center;gap:10px}
.sb-sub{color:var(--muted);font-size:13px;margin-top:5px}
.sb-alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:700;font-size:13px}
.sb-alert.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.sb-alert.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.sb-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:22px}
.sb-card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px;display:flex;align-items:center;gap:14px;box-shadow:0 2px 4px rgba(0,0,0,.03)}
.sb-card .icon{width:46px;height:46px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:19px}
.sb-card .label{font-size:11px;font-weight:800;text-transform:uppercase;color:var(--muted);letter-spacing:.3px}
.sb-card .value{font-size:23px;font-weight:900;color:#0f172a;margin-top:2px}
.sb-tabs{display:flex;gap:10px;border-bottom:2px solid var(--line);padding-bottom:8px;margin-bottom:18px}
.sb-tab{border:1px solid #cbd5e1;background:#fff;color:#334155;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:800;font-size:13px;display:inline-flex;align-items:center;gap:8px}
.sb-tab.active{background:var(--blue);border-color:var(--blue);color:#fff}
.sb-panel{background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden;box-shadow:0 3px 8px rgba(0,0,0,.04)}
.sb-panel-title{background:var(--blue);color:#fff;padding:14px 18px;font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px}
.sb-table-wrap{overflow-x:auto}
.sb-table{width:100%;border-collapse:collapse;font-size:13px}
.sb-table th{background:#f8fafc;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.35px;padding:12px;text-align:left;border-bottom:2px solid var(--line)}
.sb-table td{padding:12px;border-bottom:1px solid #eef2f7;vertical-align:middle}
.sb-table tbody tr.summary-row:hover td{background:#f8fafc}
.sb-link{appearance:none!important;-webkit-appearance:none!important;background:transparent!important;border:0!important;color:#002F70!important;font-weight:900;font-family:monospace;text-decoration:none;cursor:pointer;padding:0!important;font-size:13px;line-height:1.3;display:inline-flex;align-items:center;gap:6px;box-shadow:none!important;min-width:0!important;outline:none!important;text-align:left!important}
.sb-link:hover{text-decoration:underline;background:transparent!important}
.sb-status{display:inline-flex;align-items:center;gap:6px;border-radius:20px;padding:4px 10px;font-size:11px;font-weight:900;border:1px solid transparent}
.sb-status.pending{background:#fffbeb;color:#b45309;border-color:#fde68a}
.sb-status.approved{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.sb-status.paid{background:#dcfce7;color:#166534;border-color:#bbf7d0}
.sb-detail-cell{background:#f8fafc!important;padding:0!important;border-bottom:2px solid var(--blue)!important}
.sb-detail{padding:0}
.sb-detail-head{background:var(--blue);color:#fff;padding:13px 20px;font-weight:900;display:flex;gap:8px;align-items:center}
.sb-detail-body{padding:18px 20px}
.sb-section-title{font-size:11px;font-weight:900;color:var(--blue);text-transform:uppercase;letter-spacing:.45px;margin:0 0 8px;display:flex;align-items:center;gap:6px}
.sb-info{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px;margin-bottom:16px}
.sb-info .k{font-size:10px;color:var(--muted);font-weight:900;text-transform:uppercase;margin-bottom:3px}
.sb-info .v{font-size:13px;color:#0f172a;font-weight:800}
.sb-mini{border:1px solid var(--line);border-radius:8px;overflow:hidden;background:#fff;margin-bottom:14px}
.sb-mini table{width:100%;border-collapse:collapse;font-size:13px}
.sb-mini th{background:var(--blue);color:#fff;padding:10px 12px;font-size:10.5px;text-transform:uppercase;text-align:left}
.sb-mini td{padding:10px 12px;border-bottom:1px solid #eef2f7}
.sb-summary{display:flex;justify-content:flex-end;gap:18px;flex-wrap:wrap;background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px 14px;margin-bottom:14px}
.sb-summary div{font-size:12px;color:#475569;font-weight:800}
.sb-summary strong{color:#0f172a;font-size:14px}
.sb-actions{display:flex;justify-content:flex-end;gap:10px}
.sb-btn{border:0;border-radius:6px;padding:9px 16px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none}
.sb-btn.primary{background:var(--blue);color:#fff}
.sb-actions .sb-btn.secondary{background:#fff!important;border:1px solid #94a3b8!important;color:#002F70!important;box-shadow:none!important}
.sb-actions .sb-btn.secondary:hover{background:#f8fafc!important;border-color:#002F70!important;color:#002F70!important}
.sb-btn:disabled{opacity:.55;cursor:not-allowed}
.sb-empty{text-align:center;color:var(--muted);padding:54px 18px}
.sb-empty i{font-size:42px;color:#cbd5e1;margin-bottom:12px;display:block}
@media(max-width:760px){.sb-tabs{flex-wrap:wrap}.sb-actions{justify-content:stretch}.sb-btn{justify-content:center;width:100%}}
</style>

<div class="sb-head">
    <div>
        <h1><i class="fas fa-file-invoice"></i> Supplier Billing</h1>
        <div class="sb-sub">Review supplier invoices from completed stock-in records and approve payments.</div>
    </div>
</div>

<!-- Official Station Supplier Details Card -->
<div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:20px;margin-bottom:22px;box-shadow:0 2px 6px rgba(0,0,0,0.04);">
    <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #002F70;padding-bottom:10px;margin-bottom:16px;">
        <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:10px;">
            <i class="fas fa-building" style="color:#002F70;font-size:18px;"></i>
            Official Station Supplier Profile
        </div>
        <span style="background:#002F70;color:#fff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;">Exclusive Supplier</span>
    </div>
    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;">
        <!-- Supplier Information -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
            <div style="font-size:12px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-id-card"></i> Supplier Information
            </div>
            <div style="font-size:13px;color:#1e293b;line-height:1.6;">
                <div style="margin-bottom:6px;"><strong>Official Name:</strong> Petron Corporation</div>
                <div style="margin-bottom:6px;"><strong>Business Address:</strong> Petron Regional Depot &amp; Sales Office, Zone 4, Carmen, Cagayan de Oro City, Misamis Oriental, 9000 Philippines</div>
                <div><strong>Registration Details:</strong> SEC Reg. No. 31171 | TIN: 000-168-801-000 | CDO Regional Branch</div>
            </div>
        </div>
        
        <!-- Contact Details -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
            <div style="font-size:12px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-address-book"></i> Contact Details &amp; Terms
            </div>
            <div style="font-size:13px;color:#1e293b;line-height:1.6;">
                <div style="margin-bottom:6px;"><strong>Contact Person:</strong> Petron CDO Sales &amp; Supply Manager</div>
                <div style="margin-bottom:6px;"><strong>Phone Number:</strong> (088) 856-4321 / +63 917 800 7387</div>
                <div style="margin-bottom:6px;"><strong>Email Address:</strong> cdo.orders@petron.com / contactus@petron.com</div>
                <div><strong>Delivery Terms:</strong> FOB Destination / Net 30 Days / CDO Local Tanker Lorry &amp; Container Delivery</div>
            </div>
        </div>
    </div>
</div>

<div class="sb-cards">
    <div class="sb-card">
        <div class="icon" style="background:#fffbeb;color:#d97706"><i class="fas fa-hourglass-half"></i></div>
        <div><div class="label">Pending Supplier Invoices</div><div class="value"><?= (int)$summary['pending_count'] ?></div></div>
    </div>
    <div class="sb-card">
        <div class="icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-check-double"></i></div>
        <div><div class="label">Approved Payments</div><div class="value"><?= (int)$summary['approved_count'] ?></div></div>
    </div>
    <div class="sb-card">
        <div class="icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-wallet"></i></div>
        <div><div class="label">Total Amount Payable</div><div class="value" style="font-size:20px"><?= sb_money($summary['payable_total']) ?></div></div>
    </div>
    <div class="sb-card">
        <div class="icon" style="background:#f5f3ff;color:#7c3aed"><i class="fas fa-receipt"></i></div>
        <div><div class="label">Paid Invoices</div><div class="value"><?= (int)$summary['paid_count'] ?></div></div>
    </div>
</div>

<div class="sb-tabs">
    <a class="sb-tab <?= $active_tab === 'merchandise' ? 'active' : '' ?>" href="admin_supplier_billing.php?tab=merchandise"><i class="fas fa-boxes"></i> Merchandise</a>
    <a class="sb-tab <?= $active_tab === 'fuel' ? 'active' : '' ?>" href="admin_supplier_billing.php?tab=fuel"><i class="fas fa-gas-pump"></i> Fuel</a>
</div>

<?php
$current_invoices = $active_tab === 'fuel' ? $fuel_invoices : $merch_invoices;
$is_fuel = $active_tab === 'fuel';
?>

<div class="sb-panel">
    <div class="sb-panel-title">
        <i class="fas <?= $is_fuel ? 'fa-gas-pump' : 'fa-boxes' ?>"></i>
        Supplier Invoice Table
    </div>
    <?php if (empty($current_invoices)): ?>
        <div class="sb-empty">
            <i class="fas fa-inbox"></i>
            <strong>No supplier invoices ready for billing.</strong>
            <div style="margin-top:6px">Invoices appear here after manager stock-in is completed.</div>
        </div>
    <?php else: ?>
        <div class="sb-table-wrap">
            <table class="sb-table">
                <thead>
                    <tr>
                        <th>Invoice No.</th>
                        <th>PO No.</th>
                        <th>Supplier</th>
                        <th>Delivery Date</th>
                        <th style="text-align:right">Total Amount</th>
                        <th style="text-align:center">Payment Status</th>
                        <th style="text-align:center;width:56px">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($current_invoices as $invoice):
                    $row_id = preg_replace('/[^a-zA-Z0-9_]/', '_', $invoice['key']);
                    $status = $invoice['payment_status'] ?? 'Pending';
                ?>
                    <tr class="summary-row">
                        <td><button type="button" class="sb-link" onclick="toggleSupplierInvoice('<?= htmlspecialchars($row_id) ?>')"><?= htmlspecialchars($invoice['invoice_no']) ?></button></td>
                        <td style="font-family:monospace;font-weight:800"><?= htmlspecialchars($invoice['po_number']) ?></td>
                        <td><?= htmlspecialchars($invoice['supplier']) ?></td>
                        <td><?= sb_date($invoice['delivery_date']) ?></td>
                        <td style="text-align:right;font-weight:900"><?= sb_money($invoice['total_amount']) ?></td>
                        <td style="text-align:center"><span class="sb-status <?= sb_badge_class($status) ?>"><i class="fas fa-circle" style="font-size:6px"></i><?= htmlspecialchars($status) ?></span></td>
                        <td style="text-align:center"><a href="print_supplier_receipt.php?invoice_no=<?= urlencode($invoice['invoice_no']) ?>&po_number=<?= urlencode($invoice['po_number']) ?>&type=<?= urlencode($invoice['type']) ?>" target="_blank" title="Print Receipt" style="color:#002F70;font-size:15px"><i class="fas fa-print"></i></a></td>
                    </tr>
                    <tr id="detail_<?= htmlspecialchars($row_id) ?>" style="display:none">
                        <td colspan="7" class="sb-detail-cell">
                            <div class="sb-detail">
                                <div class="sb-detail-head"><i class="fas fa-file-invoice-dollar"></i><?= htmlspecialchars($invoice['invoice_no']) ?></div>
                                <div class="sb-detail-body">
                                    <div class="sb-section-title"><i class="fas fa-building"></i> Supplier Information</div>
                                    <div class="sb-info">
                                        <div><div class="k">Invoice No.</div><div class="v"><?= htmlspecialchars($invoice['invoice_no']) ?></div></div>
                                        <div><div class="k">Purchase Order No.</div><div class="v"><?= htmlspecialchars($invoice['po_number']) ?></div></div>
                                        <div><div class="k">Supplier</div><div class="v"><?= htmlspecialchars($invoice['supplier']) ?></div></div>
                                        <div><div class="k">Invoice Date</div><div class="v"><?= sb_date($invoice['invoice_date']) ?></div></div>
                                        <div><div class="k">Due Date</div><div class="v"><?= sb_date($invoice['due_date']) ?></div></div>
                                    </div>

                                    <div class="sb-section-title"><i class="fas <?= $is_fuel ? 'fa-gas-pump' : 'fa-boxes' ?>"></i> <?= $is_fuel ? 'Delivered Fuel' : 'Delivered Products' ?></div>
                                    <div class="sb-mini">
                                        <table>
                                            <thead>
                                                <?php if ($is_fuel): ?>
                                                    <tr><th>Fuel Type</th><th style="text-align:right">Liters Received</th><th style="text-align:right">Cost per Liter</th><th style="text-align:right">Total</th></tr>
                                                <?php else: ?>
                                                    <tr><th>Product Code</th><th>Product Name</th><th style="text-align:right">Qty Received</th><th style="text-align:right">Unit Cost</th><th style="text-align:right">Total</th></tr>
                                                <?php endif; ?>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($invoice['items'] as $item): ?>
                                                    <?php if ($is_fuel): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($item['fuel_type']) ?></td>
                                                            <td style="text-align:right;font-weight:800"><?= number_format((float)$item['liters_received']) ?> L</td>
                                                            <td style="text-align:right"><?= sb_money($item['cost_per_liter']) ?></td>
                                                            <td style="text-align:right;font-weight:900"><?= sb_money($item['total']) ?></td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <tr>
                                                            <td style="font-family:monospace"><?= htmlspecialchars($item['product_code']) ?></td>
                                                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                            <td style="text-align:right;font-weight:800"><?= number_format((float)$item['qty_received']) ?></td>
                                                            <td style="text-align:right"><?= sb_money($item['unit_cost']) ?></td>
                                                            <td style="text-align:right;font-weight:900"><?= sb_money($item['total']) ?></td>
                                                        </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php
                                        $total_qty = 0.0;
                                        foreach ($invoice['items'] as $item) {
                                            $total_qty += $is_fuel ? (float)$item['liters_received'] : (float)$item['qty_received'];
                                        }
                                    ?>
                                    <div class="sb-summary">
                                        <?php if ($is_fuel): ?>
                                            <div>Total Fuel Types: <strong><?= count($invoice['items']) ?></strong></div>
                                            <div>Total Liters Received: <strong><?= number_format($total_qty) ?> L</strong></div>
                                        <?php else: ?>
                                            <div>Total Products: <strong><?= count($invoice['items']) ?></strong></div>
                                        <?php endif; ?>
                                        <div>Grand Total Amount: <strong><?= sb_money($invoice['total_amount']) ?></strong></div>
                                    </div>

                                    <div class="sb-actions">
                                        <button type="button" class="sb-btn secondary" onclick="toggleSupplierInvoice('<?= htmlspecialchars($row_id) ?>')">Close</button>
                                        <a href="print_supplier_receipt.php?invoice_no=<?= urlencode($invoice['invoice_no']) ?>&po_number=<?= urlencode($invoice['po_number']) ?>&type=<?= urlencode($invoice['type']) ?>" target="_blank" class="sb-btn secondary" style="border-color:#002F70!important;color:#002F70!important">
                                            <i class="fas fa-print"></i> Print Receipt
                                        </a>
                                        <form method="POST" style="margin:0">
                                            <input type="hidden" name="action" value="approve_supplier_payment">
                                            <input type="hidden" name="invoice_type" value="<?= htmlspecialchars($invoice['type']) ?>">
                                            <input type="hidden" name="invoice_no" value="<?= htmlspecialchars($invoice['invoice_no']) ?>">
                                            <input type="hidden" name="po_number" value="<?= htmlspecialchars($invoice['po_number']) ?>">
                                            <button type="submit" class="sb-btn primary" <?= $status !== 'Pending' ? 'disabled' : '' ?>>
                                                <i class="fas fa-credit-card"></i>
                                                <?= $status === 'Pending' ? 'Approve Supplier Payment' : 'Payment ' . htmlspecialchars($status) ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleSupplierInvoice(key) {
    var row = document.getElementById('detail_' + key);
    if (!row) return;
    var isOpen = row.style.display !== 'none';
    document.querySelectorAll('tr[id^="detail_"]').forEach(function(item) {
        item.style.display = 'none';
    });
    if (!isOpen) {
        row.style.display = 'table-row';
        setTimeout(function() { row.scrollIntoView({behavior: 'smooth', block: 'nearest'}); }, 40);
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
