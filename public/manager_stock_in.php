<?php
// Manager Stock-In
// Approves pending staff-recorded deliveries and updates inventory.
$page_id = 'mgr_stock_in';
$page_title = 'Stock-In';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

$active_type = $_GET['type'] ?? 'merch';
if (!in_array($active_type, ['merch', 'fuel'], true)) {
    $active_type = 'merch';
}

try {
    $pdo->exec("ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL DEFAULT NULL");
} catch (Exception $ignored) {}

$session_success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);
if (!empty($_GET['success_msg'])) {
    $session_success = trim($_GET['success_msg']);
}

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'supplier' => trim($_GET['supplier'] ?? ''),
    'delivery_date' => trim($_GET['delivery_date'] ?? ''),
    'status' => trim($_GET['status'] ?? ''),
];

$pending_statuses = ['Pending Stock-In', 'Ready for Stock-In', 'Validated', 'Verified', 'Partial Delivery', 'Damaged Items', 'Adjusted'];

function si_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function si_money($value): string
{
    return '&#8369;' . number_format((float)$value, 2);
}

function si_qty($value, int $decimals = 0): string
{
    return number_format((float)$value, $decimals);
}

function si_product_id($id): string
{
    $id = (int)$id;
    return $id > 0 ? 'P' . str_pad((string)$id, 4, '0', STR_PAD_LEFT) : '-';
}

function si_extract_invoice(?string $remarks): string
{
    $remarks = (string)$remarks;
    if (preg_match('/Invoice:\s*([^|]+)/i', $remarks, $m)) {
        return trim($m[1]);
    }
    return '';
}

function si_status_sql(array $statuses): string
{
    return implode(',', array_fill(0, count($statuses), '?'));
}

function si_fetch_pending_rows(PDO $pdo, int $station_id, string $type, array $filters, array $statuses): array
{
    $delivery_type = $type === 'fuel' ? 'fuel' : 'merchandise';
    $params = array_merge([$station_id, $delivery_type], $statuses);
    $where = "do2.station_id = ? AND do2.delivery_type = ? AND do2.status IN (" . si_status_sql($statuses) . ")";

    if ($filters['search'] !== '') {
        $where .= " AND (do2.source_ref LIKE ? OR do2.delivery_ref LIKE ? OR do2.dr_number LIKE ? OR do2.product LIKE ? OR do2.supplier LIKE ?)";
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($filters['supplier'] !== '' && strcasecmp($filters['supplier'], 'Petron Corporation') !== 0) {
        $where .= " AND do2.supplier LIKE ?";
        $params[] = '%' . $filters['supplier'] . '%';
    }
    if ($filters['delivery_date'] !== '') {
        $where .= " AND do2.delivery_date = ?";
        $params[] = $filters['delivery_date'];
    }
    if ($filters['status'] !== '' && in_array($filters['status'], $statuses, true)) {
        $where .= " AND do2.status = ?";
        $params[] = $filters['status'];
    }

    if ($type === 'fuel') {
        $sql = "
            SELECT
                do2.id AS delivery_id,
                do2.delivery_ref,
                COALESCE(NULLIF(do2.source_ref, ''), do2.delivery_ref) AS po_key,
                do2.source_ref,
                do2.supplier,
                do2.product AS fuel_type,
                do2.quantity,
                do2.expected_quantity,
                do2.actual_quantity,
                do2.damaged_quantity,
                do2.unit,
                do2.delivery_date,
                do2.dr_number,
                do2.remarks,
                do2.status,
                do2.batch_id,
                do2.encoded_by,
                COALESCE(NULLIF(do2.received_by_name, ''), u.name, 'Unknown') AS received_by,
                COALESCE(do2.unit_cost, do2.unit_price,
                    (SELECT fpo.unit_price FROM fuel_purchase_orders fpo
                     LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
                     WHERE fpo.station_id = do2.station_id
                       AND (fpo.po_number = do2.source_ref OR fpo.batch_id = do2.source_ref)
                       AND LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product))
                     ORDER BY fpo.id DESC LIMIT 1),
                    0
                ) AS cost_price,
                COALESCE(
                    (SELECT fi.price_per_liter FROM fuel_inventory fi
                     LEFT JOIN fuel_types ft ON ft.id = fi.fuel_type_id
                     WHERE fi.station_id = do2.station_id
                       AND (
                         LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(do2.product))
                         OR LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product))
                         OR LOWER(TRIM(fi.fuel_type)) LIKE LOWER(CONCAT(TRIM(do2.product), ' (%'))
                         OR LOWER(TRIM(fi.fuel_type)) LIKE LOWER(CONCAT(TRIM(do2.product), ' %'))
                         OR fi.fuel_type_id = (
                            SELECT fpo2.fuel_type_id FROM fuel_purchase_orders fpo2 
                            WHERE fpo2.station_id = do2.station_id 
                              AND (fpo2.po_number = do2.source_ref OR fpo2.batch_id = do2.source_ref) 
                            LIMIT 1
                         )
                       )
                     ORDER BY
                       CASE
                         WHEN LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(do2.product)) THEN 1
                         WHEN LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product)) THEN 2
                         ELSE 3
                       END
                     LIMIT 1),
                    0
                ) AS current_selling_price,
                COALESCE(
                    (SELECT fi.ugt_no FROM fuel_inventory fi
                     LEFT JOIN fuel_types ft ON ft.id = fi.fuel_type_id
                     WHERE fi.station_id = do2.station_id
                       AND (
                         LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(do2.product))
                         OR LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product))
                         OR LOWER(TRIM(fi.fuel_type)) LIKE LOWER(CONCAT(TRIM(do2.product), ' (%'))
                         OR LOWER(TRIM(fi.fuel_type)) LIKE LOWER(CONCAT(TRIM(do2.product), ' %'))
                         OR fi.fuel_type_id = (
                            SELECT fpo2.fuel_type_id FROM fuel_purchase_orders fpo2 
                            WHERE fpo2.station_id = do2.station_id 
                              AND (fpo2.po_number = do2.source_ref OR fpo2.batch_id = do2.source_ref) 
                            LIMIT 1
                         )
                       )
                     ORDER BY
                       CASE
                         WHEN LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(do2.product)) THEN 1
                         WHEN LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product)) THEN 2
                         ELSE 3
                       END
                     LIMIT 1),
                    ''
                ) AS ugt_no
            FROM deliveries_oversight do2
            LEFT JOIN users u ON u.id = do2.encoded_by
            WHERE {$where}
            ORDER BY do2.delivery_date ASC, do2.created_at ASC, do2.id ASC
        ";
    } else {
        // Check if inventory_products is accessible (InnoDB engine file may be missing)
        $inv_accessible = false;
        try { $pdo->query("SELECT 1 FROM inventory_products LIMIT 1"); $inv_accessible = true; } catch (Throwable $e) {}

        if ($inv_accessible) {
            $sql = "
            SELECT
                do2.id AS delivery_id,
                do2.delivery_ref,
                COALESCE(NULLIF(do2.source_ref, ''), do2.delivery_ref) AS po_key,
                do2.source_ref,
                do2.supplier,
                do2.product,
                do2.quantity,
                do2.expected_quantity,
                do2.actual_quantity,
                do2.damaged_quantity,
                do2.unit,
                do2.delivery_date,
                do2.delivery_time,
                do2.dr_number,
                do2.remarks,
                do2.status,
                do2.batch_id,
                do2.encoded_by,
                COALESCE(NULLIF(do2.received_by_name, ''), u.name, 'Unknown') AS received_by,
                ip.id AS product_id,
                ip.sku,
                ip.category,
                COALESCE(si.unit, ip.size, do2.unit, 'Piece') AS unit_display,
                COALESCE(do2.unit_cost, do2.unit_price,
                    (SELECT po.unit_price FROM purchase_orders po
                     WHERE po.station_id = do2.station_id
                       AND (po.po_number = do2.source_ref OR po.batch_id = do2.source_ref)
                       AND LOWER(TRIM(po.product_name)) = LOWER(TRIM(do2.product))
                     ORDER BY po.id DESC LIMIT 1),
                    ip.unit_cost, 0
                ) AS cost_price,
                COALESCE(si.price, ip.unit_price, 0) AS current_selling_price,
                (SELECT po.id FROM purchase_orders po
                 WHERE po.station_id = do2.station_id
                   AND (po.po_number = do2.source_ref OR po.batch_id = do2.source_ref)
                   AND LOWER(TRIM(po.product_name)) = LOWER(TRIM(do2.product))
                 ORDER BY po.id DESC LIMIT 1) AS po_id,
                COALESCE(
                    (SELECT sr.request_no FROM stock_requests sr
                     JOIN purchase_orders po ON po.request_id = sr.id
                     WHERE po.station_id = do2.station_id
                       AND (po.po_number = do2.source_ref OR po.batch_id = do2.source_ref)
                       AND LOWER(TRIM(po.product_name)) = LOWER(TRIM(do2.product))
                     ORDER BY sr.id DESC LIMIT 1),
                    do2.source_ref
                ) AS purchase_request_no
            FROM deliveries_oversight do2
            LEFT JOIN users u ON u.id = do2.encoded_by
            LEFT JOIN inventory_products ip
                   ON ip.station_id = do2.station_id
                  AND LOWER(TRIM(ip.product_name)) = LOWER(TRIM(do2.product))
                  AND LOWER(COALESCE(ip.category, '')) NOT IN ('fuel','fuels')
            LEFT JOIN station_inventory si
                   ON si.product_id = ip.id AND si.station_id = do2.station_id
            WHERE {$where}
            ORDER BY do2.delivery_date ASC, do2.created_at ASC, do2.id ASC
        ";
        } else {
            // Fallback: no inventory_products JOIN (engine file missing/corrupt)
            $sql = "
            SELECT
                do2.id AS delivery_id,
                do2.delivery_ref,
                COALESCE(NULLIF(do2.source_ref, ''), do2.delivery_ref) AS po_key,
                do2.source_ref,
                do2.supplier,
                do2.product,
                do2.quantity,
                do2.expected_quantity,
                do2.actual_quantity,
                do2.damaged_quantity,
                do2.unit,
                do2.delivery_date,
                do2.delivery_time,
                do2.dr_number,
                do2.remarks,
                do2.status,
                do2.batch_id,
                do2.encoded_by,
                COALESCE(NULLIF(do2.received_by_name, ''), u.name, 'Unknown') AS received_by,
                NULL AS product_id,
                NULL AS sku,
                NULL AS category,
                COALESCE(do2.unit, 'Piece') AS unit_display,
                COALESCE(do2.unit_cost, do2.unit_price, 0) AS cost_price,
                0 AS current_selling_price,
                NULL AS po_id,
                do2.source_ref AS purchase_request_no
            FROM deliveries_oversight do2
            LEFT JOIN users u ON u.id = do2.encoded_by
            WHERE {$where}
            ORDER BY do2.delivery_date ASC, do2.created_at ASC, do2.id ASC
        ";
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function si_group_rows(array $rows, string $type): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = $row['po_key'] ?: $row['delivery_ref'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'id' => substr(md5($type . '-' . $key), 0, 12),
                'po_no' => $key,
                'purchase_request_no' => $row['purchase_request_no'] ?? ($row['source_ref'] ?? ''),
                'supplier' => 'Petron Corporation',
                'delivery_ref' => $row['delivery_ref'] ?? '',
                'delivery_date' => $row['delivery_date'] ?? '',
                'delivery_time' => $row['delivery_time'] ?? '',
                'received_by' => $row['received_by'] ?? 'Unknown',
                'dr_number' => $row['dr_number'] ?? '',
                'sales_invoice' => si_extract_invoice($row['remarks'] ?? ''),
                'status' => $row['status'] ?? 'Pending Stock-In',
                'batch_id' => $row['batch_id'] ?? '',
                'rows' => [],
            ];
        }
        $groups[$key]['rows'][] = $row;
    }
    return $groups;
}

$merch_rows = si_fetch_pending_rows($pdo, $station_id, 'merch', $filters, $pending_statuses);
$fuel_rows = si_fetch_pending_rows($pdo, $station_id, 'fuel', $filters, $pending_statuses);
$merch_groups = si_group_rows($merch_rows, 'merch');
$fuel_groups = si_group_rows($fuel_rows, 'fuel');
$active_groups = $active_type === 'fuel' ? $fuel_groups : $merch_groups;

$today = date('Y-m-d');
$today_deliveries = 0;
foreach (array_merge($merch_groups, $fuel_groups) as $group) {
    if (($group['delivery_date'] ?? '') === $today) {
        $today_deliveries++;
    }
}

$supplier_options = ['Petron Corporation'];

function si_tab_url(string $type, array $filters): string
{
    $params = array_filter(array_merge(['type' => $type], $filters), static fn($value) => $value !== '');
    return 'manager_stock_in.php?' . http_build_query($params);
}

// Compute absolute base URL to avoid Edge trailing-slash relative path issues
$_si_base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
if ($_si_base === '' || $_si_base === '.') $_si_base = '';

include __DIR__ . '/../partials/header.php';
?>

<style>
body .main,
.main,
.main-content {
    padding: 0 !important;
}

.stock-page {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    padding: 0 !important;
    margin: 0 !important;
    color: #1e293b;
    background: transparent;
}
.stock-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:15px!important;margin-top:0!important;margin-bottom:25px!important;}
.stock-title{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif!important;font-size:24px!important;font-weight:700!important;color:#002f70!important;margin:0!important;line-height:1.2!important;display:flex!important;align-items:center!important;gap:10px!important;text-transform:uppercase!important;letter-spacing:0.5px!important;}
.stock-sub{font-size:13px;color:#64748b;margin-top:4px;font-weight:600;text-transform:none;letter-spacing:.3px;}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:16px;width:100%;box-sizing:border-box;}
.summary-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(15,23,42,.04);}
.summary-label{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:800;}
.summary-value{font-size:28px;color:#002F70;font-weight:850;margin-top:4px;}
.summary-icon{width:38px;height:38px;border-radius:8px;background:#eff6ff;color:#002F70;display:flex;align-items:center;justify-content:center;font-size:17px;}
.stock-tabs { display: flex !important; flex-wrap: wrap !important; margin: 8px 0 20px !important; border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; border-bottom: 3px solid #00264D !important; gap: 0 !important; }
.stock-tab { flex: 1 !important; min-width: 120px !important; padding: 11px 16px !important; font-size: 11.5px !important; font-weight: 700 !important; color: #334155 !important; background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; border-bottom: none !important; text-decoration: none !important; transition: all 0.15s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 7px !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; cursor: pointer !important; }
.stock-tab:last-child { border-right: none !important; }
.stock-tab:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.stock-tab.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; }
.filter-panel{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:16px;box-shadow:0 2px 8px rgba(15,23,42,.04);width:100%;box-sizing:border-box;}
.filter-grid{display:grid;grid-template-columns:2fr 1.4fr 1fr 1fr auto;gap:10px;align-items:end;}
.field label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.45px;font-weight:800;color:#64748b;margin-bottom:5px;}
.field input,.field select{width:100%;height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;font-size:12px;color:#1e293b;background:#fff;box-sizing:border-box;}
.field input:focus,.field select:focus{outline:none;border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.12);}
.filter-actions{display:flex;gap:8px;}
.si-btn{height:38px;border-radius:6px;border:1px solid transparent;padding:0 13px;font-size:12px;font-weight:800;display:inline-flex;align-items:center;gap:7px;cursor:pointer;text-decoration:none;white-space:nowrap;}
.si-btn.primary{background:#002F70!important;color:#fff!important;border-color:#002F70!important;}
.si-btn.outline{background:#fff!important;color:#475569!important;border-color:#cbd5e1!important;}
.si-btn.success{background:#16a34a!important;color:#fff!important;border-color:#16a34a!important;}
.si-btn:disabled{opacity:.55;cursor:not-allowed;}
.table-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;overflow-y:visible;box-shadow:0 2px 8px rgba(15,23,42,.04);width:100%;box-sizing:border-box;}
.table-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid #e2e8f0;}
.table-title{font-size:15px;font-weight:850;color:#002F70;display:flex;align-items:center;gap:8px;}
.stock-table{width:100%;border-collapse:collapse;font-size:12px;}
.stock-table th{background:#002F70;color:#fff;text-align:left;padding:11px 12px;text-transform:uppercase;letter-spacing:.45px;font-size:10px;}
.stock-table td{padding:11px 12px;border-bottom:1px solid #eef2f7;vertical-align:middle;overflow-wrap:anywhere;}
.click-row{cursor:pointer;}
.click-row:hover td{background:#eff6ff;}
.po-link{font-family:Consolas,monospace;font-weight:850;color:#002F70;}
.status-pill{display:inline-flex;align-items:center;gap:5px;color:#b45309;font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.35px;}
.detail-row{display:none;background:#f8fafc;}
.detail-row.open{display:table-row;}
.detail-cell{padding:0!important;width:100%;}
.detail-panel{padding:18px;padding-bottom:100px;border-top:1px solid #dbeafe;box-sizing:border-box;max-width:100%;overflow:visible;}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-bottom:16px;}
.info-item{background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;}
.info-item label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.45px;font-weight:850;color:#64748b;margin-bottom:3px;}
.info-item span{font-size:13px;font-weight:750;color:#0f172a;}
.verify-wrap{max-width:100%;min-width:0;overflow-x:auto;border:1px solid #dbeafe;border-radius:8px;background:#fff;}
.verify-table{width:100%;border-collapse:collapse;font-size:12px;min-width:920px;}
.verify-table th{background:#eaf2ff;color:#002F70;text-align:left;padding:10px;font-size:10px;text-transform:uppercase;letter-spacing:.4px;}
.verify-table td{padding:9px 10px;border-top:1px solid #eef2f7;}
.verify-table input{height:34px;border:1px solid #cbd5e1;border-radius:6px;padding:0 8px;font-size:12px;box-sizing:border-box;}
.qty-input{width:96px;text-align:right;}
.price-input{width:120px;text-align:right;}
.readonly-money{font-weight:800;color:#475569;}
.detail-summary{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-top:14px;padding:14px 0 16px;border-top:2px solid #dbeafe;background:#f8fafc;z-index:5;}
.summary-inline{display:flex;gap:18px;flex-wrap:wrap;font-size:12px;color:#475569;}
.summary-inline strong{color:#002F70;font-size:14px;}
.approve-btn{flex:0 0 auto;justify-content:center;}
.empty-state{text-align:center;padding:70px 20px;color:#64748b;}
.empty-state i{font-size:42px;color:#16a34a;display:block;margin-bottom:12px;}
#stockToast.stock-toast{display:none;position:fixed!important;top:82px!important;left:50%!important;right:auto!important;bottom:auto!important;transform:translateX(-50%) translateY(-8px)!important;z-index:2147483000!important;box-sizing:border-box!important;width:fit-content!important;min-width:260px!important;max-width:min(480px,calc(100vw - 32px))!important;height:auto!important;min-height:0!important;max-height:140px!important;overflow:auto!important;border-radius:8px!important;padding:12px 16px!important;color:#fff!important;font-size:13px!important;line-height:1.35!important;font-weight:800!important;text-align:left!important;white-space:normal!important;overflow-wrap:break-word!important;box-shadow:0 10px 24px rgba(15,23,42,.28)!important;opacity:0;pointer-events:none;transition:opacity .22s ease,transform .22s ease;}
#stockToast.stock-toast.is-visible{display:block!important;opacity:1;transform:translateX(-50%) translateY(0)!important;}
#stockToast.stock-toast.toast-ok{background:#16a34a!important;}
#stockToast.stock-toast.toast-err{background:#dc2626!important;}
/* Confirm Modal Overlay and Buttons (Override global button styling) */
#siConfirmOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65) !important;
    z-index: 99999 !important;
    align-items: center;
    justify-content: center;
}
#siConfirmOverlay .confirm-card {
    background: #ffffff !important;
    border-radius: 12px !important;
    padding: 26px 30px !important;
    max-width: 440px !important;
    width: 90% !important;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35) !important;
    position: relative !important;
}
#siConfirmOverlay #siConfirmCancelBtn {
    padding: 10px 22px !important;
    border: 1.5px solid #dc2626 !important;
    border-radius: 7px !important;
    background: #dc2626 !important;
    background-color: #dc2626 !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    font-weight: 700 !important;
    font-size: 13.5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.25) !important;
    transition: all 0.15s ease !important;
}
#siConfirmOverlay #siConfirmCancelBtn:hover {
    background: #b91c1c !important;
    background-color: #b91c1c !important;
    border-color: #b91c1c !important;
}
#siConfirmOverlay #siConfirmOkBtn {
    padding: 10px 22px !important;
    border: 1.5px solid #16a34a !important;
    border-radius: 7px !important;
    background: #16a34a !important;
    background-color: #16a34a !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    font-weight: 800 !important;
    font-size: 13.5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    box-shadow: 0 2px 6px rgba(22, 163, 74, 0.25) !important;
    transition: all 0.15s ease !important;
}
/* Stock-In Success Banner */
.stock-banner {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 22px;
    position: relative;
    box-shadow: 0 4px 14px rgba(22, 163, 74, 0.14);
    animation: stockBannerSlideDown 0.3s ease-out;
}
@keyframes stockBannerSlideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}
.stock-banner-success {
    background: #f0fdf4 !important;
    border: 1.5px solid #86efac !important;
    color: #166534 !important;
}
.stock-banner-icon {
    font-size: 28px;
    color: #16a34a;
    flex-shrink: 0;
}
.stock-banner-content {
    flex: 1;
    min-width: 0;
}
.stock-banner-title {
    font-size: 15.5px;
    font-weight: 800;
    color: #14532d;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.stock-banner-desc {
    font-size: 13.5px;
    color: #166534;
    line-height: 1.45;
    font-weight: 600;
}
.stock-banner-meta {
    margin-top: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.stock-banner-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #dcfce7;
    border: 1px solid #bbf7d0;
    color: #15803d;
    padding: 3px 9px;
    border-radius: 5px;
    font-size: 12px;
    font-weight: 700;
    font-family: Consolas, monospace;
}
.stock-banner-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.stock-banner-btn {
    padding: 7px 15px;
    background: #16a34a !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border-radius: 6px;
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s ease;
    border: 1px solid #15803d;
}
.stock-banner-btn:hover {
    background: #15803d !important;
    text-decoration: none;
}
.stock-banner-dismiss-btn {
    background: transparent;
    border: 1.5px solid #86efac;
    color: #15803d;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    padding: 6px 14px;
    border-radius: 6px;
    transition: all 0.15s ease;
}
.stock-banner-dismiss-btn:hover {
    background: #dcfce7;
    color: #14532d;
}
@media print{#stockToast.stock-toast,#siConfirmOverlay,#stockSuccessBanner{display:none!important;}}
@media(max-width:900px){
    .stock-page{padding:16px 12px 48px;}
    .filter-grid{grid-template-columns:1fr;}
    .filter-actions{justify-content:flex-start;}
    .detail-summary{align-items:stretch;justify-content:flex-start;}
    .approve-btn{width:100%;}
    .stock-banner{flex-direction:column;align-items:flex-start;gap:12px;}
    .stock-banner-actions{width:100%;justify-content:flex-end;}
}
</style>

<div class="stock-page">
    <div class="stock-head">
        <div>
            <h1 class="stock-title"><i class="fas fa-dolly"></i> Stock-In</h1>
        </div>
    </div>

    <!-- Stock-In Success Banner -->
    <div class="stock-banner stock-banner-success" id="stockSuccessBanner" style="<?= !empty($session_success) ? 'display:flex;' : 'display:none;' ?>">
        <div class="stock-banner-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stock-banner-content">
            <div class="stock-banner-title" id="stockBannerTitle">Stock-In Approved Successfully!</div>
            <div class="stock-banner-desc" id="stockBannerDesc">
                <?= !empty($session_success) ? htmlspecialchars($session_success) : 'The delivery stock-in has been approved. Official station inventory and prices have been updated.' ?>
            </div>
            <div class="stock-banner-meta" id="stockBannerMeta" style="display:none;"></div>
        </div>
        <div class="stock-banner-actions">
            <a id="stockBannerPrintBtn" href="#" target="_blank" class="stock-banner-btn" style="display:none;">
                <i class="fas fa-print"></i> Print Invoice
            </a>
            <button type="button" class="stock-banner-dismiss-btn" onclick="dismissStockBanner()">Dismiss</button>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div>
                <div class="summary-label">Pending Merchandise Deliveries</div>
                <div class="summary-value"><?= count($merch_groups) ?></div>
            </div>
            <div class="summary-icon"><i class="fas fa-boxes"></i></div>
        </div>
        <div class="summary-card">
            <div>
                <div class="summary-label">Pending Fuel Deliveries</div>
                <div class="summary-value"><?= count($fuel_groups) ?></div>
            </div>
            <div class="summary-icon"><i class="fas fa-gas-pump"></i></div>
        </div>
        <div class="summary-card">
            <div>
                <div class="summary-label">Total Pending Stock-In</div>
                <div class="summary-value"><?= count($merch_groups) + count($fuel_groups) ?></div>
            </div>
            <div class="summary-icon"><i class="fas fa-inbox"></i></div>
        </div>
        <div class="summary-card">
            <div>
                <div class="summary-label">Today's Deliveries</div>
                <div class="summary-value"><?= $today_deliveries ?></div>
            </div>
            <div class="summary-icon"><i class="fas fa-calendar-day"></i></div>
        </div>
    </div>

    <div class="stock-tabs">
        <a class="stock-tab <?= $active_type === 'merch' ? 'active' : '' ?>" href="<?= si_h(si_tab_url('merch', $filters)) ?>">
            <i class="fas fa-boxes"></i> Merchandise
        </a>
        <a class="stock-tab <?= $active_type === 'fuel' ? 'active' : '' ?>" href="<?= si_h(si_tab_url('fuel', $filters)) ?>">
            <i class="fas fa-gas-pump"></i> Fuel
        </a>
    </div>

    <form class="filter-panel" method="get" action="<?= htmlspecialchars($_si_base . '/public/manager_stock_in.php') ?>">
        <input type="hidden" name="type" value="<?= si_h($active_type) ?>">
        <div class="filter-grid">
            <div class="field">
                <label>Search PO Number</label>
                <input type="text" name="search" value="<?= si_h($filters['search']) ?>" placeholder="PO number, DR number, product">
            </div>
            <div class="field">
                <label>Supplier</label>
                <select name="supplier">
                    <option value="">All Suppliers</option>
                    <?php foreach ($supplier_options as $supplier): ?>
                        <option value="<?= si_h($supplier) ?>" <?= $filters['supplier'] === $supplier ? 'selected' : '' ?>><?= si_h($supplier) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Delivery Date</label>
                <input type="date" name="delivery_date" value="<?= si_h($filters['delivery_date']) ?>">
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($pending_statuses as $status): ?>
                        <option value="<?= si_h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= si_h($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button class="si-btn primary" type="submit"><i class="fas fa-filter"></i> Filter</button>
                <a class="si-btn outline" href="<?= htmlspecialchars($_si_base . '/public/manager_stock_in.php') ?>?type=<?= si_h($active_type) ?>"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </div>
    </form>

    <div class="table-card">
        <div class="table-head">
            <div class="table-title">
                <i class="<?= $active_type === 'fuel' ? 'fas fa-gas-pump' : 'fas fa-boxes' ?>"></i>
                Pending Stock-In Table
            </div>
        </div>

        <?php if (empty($active_groups)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <strong>No pending deliveries for stock-in.</strong><br>
                <span style="font-size:13px;">Staff-recorded deliveries with Pending Stock-In status will appear here.</span>
            </div>
        <?php else: ?>
            <table class="stock-table">
                <thead>
                <tr>
                    <th>PO No.</th>
                    <th>Supplier</th>
                    <th>Delivery Date</th>
                    <th>Received By</th>
                    <th><?= $active_type === 'fuel' ? 'Fuel Types' : 'Products' ?></th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($active_groups as $group): ?>
                    <?php
                    $gid = $group['id'];
                    $row_count = count($group['rows']);
                    $total_received_default = 0;
                    foreach ($group['rows'] as $r) {
                        $total_received_default += (float)($r['actual_quantity'] !== null ? $r['actual_quantity'] : $r['quantity']);
                    }
                    ?>
                    <tr class="click-row" onclick="toggleStockDetail('<?= si_h($gid) ?>')">
                        <td><span class="po-link"><?= si_h($group['po_no']) ?></span></td>
                        <td><?= si_h($group['supplier']) ?></td>
                        <td><?= $group['delivery_date'] ? date('M d, Y', strtotime($group['delivery_date'])) : '-' ?></td>
                        <td><?= si_h($group['received_by']) ?></td>
                        <td><?= $row_count ?> <?= $active_type === 'fuel' ? 'Fuel Type' . ($row_count === 1 ? '' : 's') : 'Product' . ($row_count === 1 ? '' : 's') ?></td>
                        <td><span class="status-pill"><i class="fas fa-hourglass-half"></i> <?= si_h($group['status']) ?></span></td>
                    </tr>
                    <tr class="detail-row" id="detail-<?= si_h($gid) ?>">
                        <td colspan="6" class="detail-cell">
                            <div class="detail-panel">
                                <div class="info-grid">
                                    <div class="info-item"><label>Purchase Order No.</label><span><?= si_h($group['po_no']) ?></span></div>
                                    <div class="info-item"><label>Purchase Request No.</label><span><?= si_h($group['purchase_request_no'] ?: '-') ?></span></div>
                                    <div class="info-item"><label>Supplier</label><span><?= si_h($group['supplier']) ?></span></div>
                                    <div class="info-item"><label>Delivery Receipt No.</label><span><?= si_h($group['dr_number'] ?: '-') ?></span></div>
                                    <div class="info-item"><label>Sales Invoice No.</label><span><?= si_h($group['sales_invoice'] ?: '-') ?></span></div>
                                    <div class="info-item"><label>Delivery Date &amp; Time</label><span><?= $group['delivery_date'] ? date('M d, Y', strtotime($group['delivery_date'])) : '-' ?><?= !empty($group['delivery_time']) ? ' at ' . date('g:i A', strtotime($group['delivery_time'])) : '' ?></span></div>
                                    <div class="info-item"><label>Received By</label><span><?= si_h($group['received_by']) ?></span></div>
                                </div>

                                <div class="verify-wrap">
                                    <?php if ($active_type === 'fuel'): ?>
                                        <table class="verify-table">
                                            <thead>
                                            <tr>
                                                <th>Fuel Type</th>
                                                <th>UGT No.</th>
                                                <th>Liters Ordered</th>
                                                <th>Liters Received</th>
                                                <th>Cost per Liter</th>
                                                <th>Selling Price/Liter</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($group['rows'] as $item): ?>
                                                <?php
                                                $ordered = (float)($item['expected_quantity'] ?: $item['quantity']);
                                                $received = (float)($item['actual_quantity'] !== null ? $item['actual_quantity'] : $item['quantity']);
                                                ?>
                                                <tr data-stock-row="<?= si_h($gid) ?>"
                                                        data-delivery-id="<?= (int)$item['delivery_id'] ?>">
                                                    <td><strong><?= si_h($item['fuel_type']) ?></strong></td>
                                                    <td>
                                                        <?php if (!empty($item['ugt_no'])): ?>
                                                            <span style="display:inline-block;padding:3px 9px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;border-radius:6px;font-weight:800;font-size:12px;font-family:Consolas,monospace;letter-spacing:0.5px;"><?= si_h($item['ugt_no']) ?></span>
                                                        <?php else: ?>
                                                            <span style="color:#94a3b8;">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= si_qty($ordered, 2) ?> L</td>
                                                    <td>
                                                        <input class="qty-input qty-field" type="number" step="0.01" min="0"
                                                               value="<?= si_h($received) ?>" oninput="recalcStockGroup('<?= si_h($gid) ?>', true)">
                                                    </td>
                                                    <td><span class="readonly-money"><?= si_money($item['cost_price']) ?></span></td>
                                                    <td>
                                                         <input class="price-input price-field" type="number" step="0.01" min="0.01" required
                                                                value=""
                                                                placeholder="<?= (float)$item['current_selling_price'] > 0 ? 'Current ₱' . number_format($item['current_selling_price'], 2) . '/L' : 'Enter selling price/L (required)' ?>"
                                                                style="border-color: #f59e0b;">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <table class="verify-table">
                                            <thead>
                                            <tr>
                                                <th>Product ID</th>
                                                <th>Product Code</th>
                                                <th>Product Name</th>
                                                <th>Qty Ordered</th>
                                                <th>Qty Received</th>
                                                <th>Unit</th>
                                                <th>Unit Cost</th>
                                                <th>Selling Price</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($group['rows'] as $item): ?>
                                                <?php
                                                $ordered = (int)round((float)($item['expected_quantity'] ?: $item['quantity']));
                                                $received = (int)round((float)($item['actual_quantity'] !== null ? $item['actual_quantity'] : $item['quantity']));
                                                ?>
                                                <tr data-stock-row="<?= si_h($gid) ?>"
                                                    data-delivery-id="<?= (int)$item['delivery_id'] ?>">
                                                    <td><?= si_h(si_product_id($item['product_id'])) ?></td>
                                                    <td><code><?= si_h($item['sku'] ?: '-') ?></code></td>
                                                    <td><strong><?= si_h($item['product']) ?></strong></td>
                                                    <td><?= si_qty($ordered) ?></td>
                                                    <td>
                                                        <input class="qty-input qty-field" type="number" min="0" step="1"
                                                               value="<?= si_h($received) ?>" oninput="recalcStockGroup('<?= si_h($gid) ?>', false)">
                                                    </td>
                                                    <td><?= si_h($item['unit_display'] ?: $item['unit'] ?: 'Piece') ?></td>
                                                    <td><span class="readonly-money"><?= si_money($item['cost_price']) ?></span></td>
                                                    <td>
                                                         <input class="price-input price-field" type="number" min="0.01" step="0.01" required
                                                                value=""
                                                                placeholder="<?= (float)$item['current_selling_price'] > 0 ? 'Current ₱' . number_format($item['current_selling_price'], 2) : 'Enter selling price (required)' ?>"
                                                                style="border-color: #f59e0b;">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>

                                <div class="detail-summary" style="display:flex; justify-content:flex-end;">
                                    <button type="button" class="si-btn success approve-btn"
                                            onclick="approveStockIn('<?= si_h($active_type) ?>','<?= si_h($gid) ?>','<?= si_h($group['po_no']) ?>')">
                                        <i class="fas fa-check-circle"></i> Approve Stock-In
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <!-- Stock-In Pagination Footer -->
        <div id="siPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="siShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing <?= empty($active_groups) ? '0' : '1–'.min(10, count($active_groups)) ?> of <?= count($active_groups) ?> entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="siPerPage" onchange="siChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="siPrevBtn" onclick="siGoPage(siState.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="siPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of <?= max(1, ceil(count($active_groups) / 10)) ?></span>
                    <button id="siNextBtn" onclick="siGoPage(siState.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($active_groups) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($active_groups) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="stock-toast" id="stockToast" role="status" aria-live="polite"></div>

<!-- Custom Confirm Modal (replaces window.confirm which Edge may block) -->
<div id="siConfirmOverlay" onclick="if(event.target===this) siConfirmCancel();">
    <div class="confirm-card">
        <div style="font-size:16px;font-weight:800;color:#002F70;margin-bottom:10px;"><i class="fas fa-check-circle" style="color:#16a34a;margin-right:8px;"></i>Confirm Stock-In Approval</div>
        <div id="siConfirmMsg" style="font-size:13.5px;color:#374151;line-height:1.6;margin-bottom:22px;"></div>
        <div style="display:flex;justify-content:flex-end;gap:12px;">
            <button type="button" id="siConfirmCancelBtn" onclick="siConfirmCancel()" style="padding:10px 22px!important;border:1.5px solid #dc2626!important;border-radius:7px!important;background:#dc2626!important;background-color:#dc2626!important;color:#ffffff!important;-webkit-text-fill-color:#ffffff!important;font-weight:700!important;font-size:13.5px!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;gap:7px!important;">Cancel</button>
            <button type="button" id="siConfirmOkBtn" style="padding:10px 22px!important;border:1.5px solid #16a34a!important;border-radius:7px!important;background:#16a34a!important;background-color:#16a34a!important;color:#ffffff!important;-webkit-text-fill-color:#ffffff!important;font-weight:800!important;font-size:13.5px!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;gap:7px!important;"><i class="fas fa-check"></i> Yes, Approve</button>
        </div>
    </div>
</div>

<script>
const stockEndpoint = '<?= htmlspecialchars($_si_base) ?>/backend/api/manager_stock_in.php';

function toggleStockDetail(groupId) {
    const row = document.getElementById('detail-' + groupId);
    if (!row) return;
    row.classList.toggle('open');
}

function stockRows(groupId) {
    return Array.from(document.querySelectorAll('[data-stock-row="' + groupId + '"]'));
}

function recalcStockGroup(groupId, isFuel) {
    let total = 0;
    stockRows(groupId).forEach(function(row) {
        const qty = parseFloat(row.querySelector('.qty-field').value) || 0;
        total += qty;
    });
    const out = document.getElementById('sum-' + groupId);
    if (out) {
        out.textContent = isFuel ? total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L' : Math.round(total).toLocaleString();
    }
}

var _siConfirmCallback = null;

function siConfirm(message, onYes) {
    document.getElementById('siConfirmMsg').textContent = message;
    var overlay = document.getElementById('siConfirmOverlay');
    overlay.style.display = 'flex';
    document.getElementById('siConfirmOkBtn').onclick = function() {
        overlay.style.display = 'none';
        onYes();
    };
}

function siConfirmCancel() {
    var overlay = document.getElementById('siConfirmOverlay');
    if (overlay) overlay.style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        siConfirmCancel();
    }
});

function approveStockIn(type, groupId, poKey) {
    var rows = stockRows(groupId);
    if (!rows.length) {
        showStockToast('No items found in this group. Please refresh the page.', 'err');
        return;
    }

    var items = [];
    var hasError = false;
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var qtyInput = row.querySelector('.qty-field');
        var priceInput = row.querySelector('.price-field');
        
        if (!qtyInput || !priceInput) {
            showStockToast('Invalid row structure. Please refresh the page.', 'err');
            hasError = true;
            break;
        }
        
        var qty = parseFloat(qtyInput.value);
        var price = parseFloat(priceInput.value);

        if (!Number.isFinite(qty) || qty < 0) {
            showStockToast('Received quantity cannot be negative.', 'err');
            qtyInput.style.borderColor = '#dc2626';
            qtyInput.focus();
            hasError = true;
            break;
        }
        if (!Number.isFinite(price) || price <= 0) {
            showStockToast('Enter the selling price for all items before approving.', 'err');
            priceInput.style.borderColor = '#dc2626';
            priceInput.focus();
            hasError = true;
            break;
        }
        qtyInput.style.borderColor = '';
        priceInput.style.borderColor = '';

        var deliveryId = parseInt(row.getAttribute('data-delivery-id'), 10);
        
        items.push({
            delivery_id: deliveryId,
            qty_received: qty,
            selling_price: price
        });
    }
    
    if (hasError) return;

    var label = type === 'fuel' ? 'fuel' : 'merchandise';
    siConfirm(
        'Approve stock-in for ' + poKey + '? This will update inventory, prices, history, and PO status.',
        function() {
            var button = document.querySelector('#detail-' + groupId + ' .approve-btn');
            
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...';
            }

            var action = type === 'fuel' ? 'approve_fuel_stock_in' : 'approve_merchandise_stock_in';
            var url = stockEndpoint + '?action=' + action;
            var payload = {po_key: poKey, items: items};
            
            fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            })
            .then(function(response) {
                return response.text().then(function(text) {
                    var data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (e) {
                        data = {};
                    }
                    if (!response.ok) {
                        throw new Error(data.message || ('Server error ' + response.status));
                    }
                    return data;
                });
            })
            .then(function(data) {
                if (data.success) {
                    var invoiceUrl = 'print_supplier_invoice.php?batch_id=' + encodeURIComponent(data.batch_id || poKey) + '&type=' + encodeURIComponent(type);
                    var successObj = {
                        title: 'Stock-In Approved Successfully!',
                        message: data.message || ('Successfully approved ' + label + ' stock-in for ' + poKey + '. Official station inventory and prices have been updated.'),
                        batch_id: data.batch_id || '',
                        po_key: poKey,
                        invoice_url: invoiceUrl,
                        time: Date.now()
                    };
                    try {
                        sessionStorage.setItem('petron_stock_in_success', JSON.stringify(successObj));
                    } catch(e) {}

                    showStockBanner(successObj);
                    showStockToast(data.message || ('Approved ' + label + ' stock-in! Opening printable invoice...'), 'ok');
                    window.open(invoiceUrl, '_blank');
                    setTimeout(function() { window.location.reload(); }, 1400);
                } else {
                    showStockToast(data.message || 'Unable to approve stock-in.', 'err');
                    if (button) {
                        button.disabled = false;
                        button.innerHTML = '<i class="fas fa-check-circle"></i> Approve Stock-In';
                    }
                }
            })
            .catch(function(err) {
                showStockToast('Error: ' + (err.message || 'Connection error. Check server logs.'), 'err');
                if (button) {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-check-circle"></i> Approve Stock-In';
                }
            });
        }
    );
}

function showStockToast(message, type) {
    if (window.showPetronFlash) {
        window.showPetronFlash(message, type === 'ok' ? 'success' : 'error');
        return;
    }
    const toast = document.getElementById('stockToast');
    if (!toast) return;
    toast.textContent = message;
    toast.className = 'stock-toast ' + (type === 'ok' ? 'toast-ok' : 'toast-err') + ' is-visible';
    clearTimeout(window.stockToastTimer);
    clearTimeout(window.stockToastHideTimer);
    window.stockToastTimer = setTimeout(function() {
        toast.classList.remove('is-visible');
        window.stockToastHideTimer = setTimeout(function() {
            toast.className = 'stock-toast';
        }, 240);
    }, type === 'ok' ? 3000 : 4500);
}

// ── Stock-In Pagination Engine ──
var siState = { page: 1, per_page: 10 };

function siRender() {
    const tableBody = document.querySelector('.stock-table tbody');
    if (!tableBody) return;

    const clickRows = Array.from(tableBody.querySelectorAll('tr.click-row'));
    const tot = clickRows.length;
    const pp = siState.per_page || 10;
    const tp = Math.max(1, Math.ceil(tot / pp));

    if (siState.page > tp) siState.page = tp;
    if (siState.page < 1) siState.page = 1;
    const p = siState.page;

    const start = (p - 1) * pp;
    const end   = p * pp;

    clickRows.forEach(function(r, i) {
        const isVis = (i >= start && i < end);
        r.style.display = isVis ? '' : 'none';
        const nextRow = r.nextElementSibling;
        if (nextRow && nextRow.classList.contains('detail-row') && !isVis) {
            nextRow.style.display = 'none';
            r.classList.remove('is-active');
        }
    });

    // Update counter
    const showingStart = tot === 0 ? 0 : start + 1;
    const showingEnd   = Math.min(end, tot);
    const entriesLbl   = document.getElementById('siShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
    }

    const lbl = document.getElementById('siPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

    const prev = document.getElementById('siPrevBtn');
    const next = document.getElementById('siNextBtn');
    if (prev) {
        prev.disabled = (p <= 1);
        prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
        prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
    }
    if (next) {
        next.disabled = (p >= tp);
        next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
        next.style.color = next.disabled ? '#cbd5e1' : '#475569';
    }
}

window.siState = siState;
window.siGoPage = function(p) {
    const tableBody = document.querySelector('.stock-table tbody');
    if (!tableBody) return;
    const clickRows = Array.from(tableBody.querySelectorAll('tr.click-row'));
    const tp = Math.max(1, Math.ceil(clickRows.length / (siState.per_page || 10)));
    if (p < 1 || p > tp) return;
    siState.page = p;
    siRender();
};

window.siChangePerPage = function() {
    const s = document.getElementById('siPerPage');
    if (s && siState) {
        siState.per_page = parseInt(s.value, 10);
        siState.page = 1;
        siRender();
    }
};

function showStockBanner(data) {
    var banner = document.getElementById('stockSuccessBanner');
    if (!banner) return;
    
    var titleEl = document.getElementById('stockBannerTitle');
    var descEl = document.getElementById('stockBannerDesc');
    var metaEl = document.getElementById('stockBannerMeta');
    var printBtn = document.getElementById('stockBannerPrintBtn');
    
    if (titleEl && data.title) titleEl.textContent = data.title;
    if (descEl && data.message) descEl.textContent = data.message;
    
    if (metaEl) {
        metaEl.innerHTML = '';
        if (data.po_key) {
            metaEl.innerHTML += '<span class="stock-banner-tag"><i class="fas fa-file-invoice"></i> PO: ' + siEscapeHtml(data.po_key) + '</span>';
        }
        if (data.batch_id) {
            metaEl.innerHTML += '<span class="stock-banner-tag"><i class="fas fa-layer-group"></i> Batch: ' + siEscapeHtml(data.batch_id) + '</span>';
        }
        metaEl.style.display = metaEl.innerHTML ? 'flex' : 'none';
    }
    
    if (printBtn) {
        if (data.invoice_url) {
            printBtn.href = data.invoice_url;
            printBtn.style.display = 'inline-flex';
        } else {
            printBtn.style.display = 'none';
        }
    }
    
    banner.style.display = 'flex';
    try {
        banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch(e) {}
}

function dismissStockBanner() {
    var banner = document.getElementById('stockSuccessBanner');
    if (banner) {
        banner.style.display = 'none';
    }
    try {
        sessionStorage.removeItem('petron_stock_in_success');
    } catch(e) {}
}

function siEscapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function() {
    siRender();

    // Check for stored success banner from previous stock-in
    try {
        var stored = sessionStorage.getItem('petron_stock_in_success');
        if (stored) {
            var data = JSON.parse(stored);
            if (data && (Date.now() - (data.time || 0) < 600000)) {
                showStockBanner(data);
            }
            sessionStorage.removeItem('petron_stock_in_success');
        }
    } catch (e) {}
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
