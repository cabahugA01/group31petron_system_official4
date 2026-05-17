<?php
/**
 * STAFF FUEL DELIVERIES INTERFACE
 * 
 * Fuel delivery recording for staff with:
 * - Simple delivery recording form
 * - Pending delivery status tracking
 * - Delivery history view
 * - Manager approval workflow
 */

$page_id = 'staff_fuel_deliveries';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only staff and above can access fuel deliveries
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Staff privileges required.';
    header('Location: dashboard.php');
    exit;
}

$msg = '';
$msg_type = '';
if (isset($_SESSION['success'])) { 
    $msg = $_SESSION['success']; 
    $msg_type = 'success';
    unset($_SESSION['success']); 
}
if (isset($_SESSION['error'])) { 
    $msg = $_SESSION['error']; 
    $msg_type = 'error';
    unset($_SESSION['error']); 
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // STAFF: Record Fuel Delivery
    if ($action === 'record_delivery') {
        $delivery_date   = $_POST['delivery_date'] ?? '';
        $fuel_type       = trim($_POST['fuel_type'] ?? '');
        $supplier        = trim($_POST['supplier'] ?? 'Petron Corporation');
        $invoice_no      = trim($_POST['invoice_no'] ?? '');
        $delivery_liters = (float)($_POST['delivery_liters'] ?? 0);
        $tanker_number   = trim($_POST['tanker_number'] ?? '');
        $notes           = trim($_POST['notes'] ?? '');

        if ($delivery_date && $fuel_type && $supplier && $delivery_liters > 0 && $invoice_no) {
            try {
                // ── Capacity check before inserting ──────────────────────────
                $capStmt = $pdo->prepare("
                    SELECT COALESCE(current_level, current_stock, 0) AS current_level,
                           COALESCE(capacity, 0) AS capacity
                    FROM fuel_inventory
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    LIMIT 1
                ");
                $capStmt->execute([$station_id, $fuel_type]);
                $capRow = $capStmt->fetch(PDO::FETCH_ASSOC);

                if ($capRow && $capRow['capacity'] > 0) {
                    $current  = (float)$capRow['current_level'];
                    $capacity = (float)$capRow['capacity'];
                    $after    = $current + $delivery_liters;
                    if ($after > $capacity) {
                        $available = max(0, $capacity - $current);
                        throw new Exception(
                            "Delivery exceeds tank capacity for {$fuel_type}. " .
                            "Capacity: " . number_format($capacity, 0) . " L, " .
                            "Current level: " . number_format($current, 0) . " L, " .
                            "Available space: " . number_format($available, 0) . " L, " .
                            "You entered: " . number_format($delivery_liters, 0) . " L."
                        );
                    }
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO fuel_deliveries (
                        station_id, delivery_date, fuel_type, supplier,
                        invoice_no, delivery_liters, tanker_number,
                        received_by, notes, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
                ");
                $stmt->execute([
                    $station_id, $delivery_date, $fuel_type, $supplier,
                    $invoice_no, $delivery_liters, $tanker_number,
                    $me['id'], $notes
                ]);

                $delivery_id = $pdo->lastInsertId();

                log_activity($pdo, $me['id'], 'Record Fuel Delivery',
                    "Recorded delivery: {$delivery_liters}L of {$fuel_type} (Invoice: {$invoice_no})",
                    'fuel_management'
                );

                $pdo->commit();

                $_SESSION['success'] = "Fuel delivery recorded successfully! Delivery ID: {$delivery_id}. Awaiting manager verification.";
                header('Location: staff_fuel_deliveries.php');
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg      = "" . $e->getMessage();
                $msg_type = 'error';
            }
        } else {
            $msg      = "Please fill all required fields.";
            $msg_type = 'error';
        }
    }
}

// Fetch fuel types — try inventory_products first, fall back to fuel_types table
$fuel_types = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT product_name AS name
        FROM inventory_products
        WHERE LOWER(category) = 'fuel'
        ORDER BY product_name
    ");
    $stmt->execute();
    $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching fuel types from inventory_products: " . $e->getMessage());
}

// Fallback: try fuel_types table if inventory_products returned nothing
if (empty($fuel_types)) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM fuel_types ORDER BY name");
        $stmt->execute();
        $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching fuel types from fuel_types: " . $e->getMessage());
    }
}

// Fetch current tank levels (with capacity for available-space display)
$tank_levels = [];
try {
    $stmt = $pdo->prepare("
        SELECT fi.fuel_type,
               COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
               COALESCE(fi.capacity, 0)                        AS capacity
        FROM fuel_inventory fi
        WHERE fi.station_id = ?
        ORDER BY fi.fuel_type
    ");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tank_levels[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {
    error_log("Error fetching tank levels: " . $e->getMessage());
}

// ── Filter state ─────────────────────────────────────────────
// Period presets: ytd | monthly | weekly | custom (default: monthly)
$filter_period    = $_GET['period']     ?? 'monthly';
$filter_date_from = $_GET['date_from']  ?? '';
$filter_date_to   = $_GET['date_to']    ?? '';
$filter_fuel_type = trim($_GET['fuel_type_filter'] ?? '');
$filter_supplier  = trim($_GET['supplier_filter']  ?? '');
$filter_keyword   = trim($_GET['keyword']           ?? '');

// Resolve date range from period preset
$today      = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end   = date('Y-m-d', strtotime('sunday this week'));

switch ($filter_period) {
    case 'ytd':
        $resolved_from = date('Y-01-01');
        $resolved_to   = $today;
        break;
    case 'weekly':
        $resolved_from = $week_start;
        $resolved_to   = $week_end;
        break;
    case 'custom':
        $resolved_from = $filter_date_from ?: date('Y-m-01');
        $resolved_to   = $filter_date_to   ?: $today;
        break;
    case 'monthly':
    default:
        $filter_period = 'monthly';
        $resolved_from = date('Y-m-01');
        $resolved_to   = $today;
        break;
}

// Sanitize
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolved_from)) $resolved_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolved_to))   $resolved_to   = $today;

// ── Distinct suppliers for filter dropdown ────────────────────
$supplier_list = [];
try {
    $sup = $pdo->prepare("
        SELECT DISTINCT supplier
        FROM fuel_deliveries
        WHERE station_id = ?
          AND supplier IS NOT NULL AND supplier <> ''
        ORDER BY supplier ASC
    ");
    $sup->execute([$station_id]);
    $supplier_list = array_column($sup->fetchAll(PDO::FETCH_ASSOC), 'supplier');
} catch (Exception $e) { $supplier_list = []; }

// Fetch deliveries — filtered
$recent_deliveries = [];
try {
    $sql = "
        SELECT
            fd.*,
            u.name AS recorded_by_name,
            v.name AS verified_by_name
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        LEFT JOIN users v ON fd.verified_by = v.id
        WHERE fd.station_id = ?
          AND DATE(fd.delivery_date) BETWEEN ? AND ?
    ";
    $params = [$station_id, $resolved_from, $resolved_to];

    if ($filter_fuel_type !== '') {
        $sql    .= " AND LOWER(TRIM(fd.fuel_type)) = LOWER(TRIM(?))";
        $params[] = $filter_fuel_type;
    }
    if ($filter_supplier !== '') {
        $sql    .= " AND LOWER(TRIM(fd.supplier)) = LOWER(TRIM(?))";
        $params[] = $filter_supplier;
    }
    if ($filter_keyword !== '') {
        $sql    .= " AND (fd.invoice_no LIKE ? OR fd.tanker_number LIKE ? OR fd.notes LIKE ?)";
        $kw      = '%' . $filter_keyword . '%';
        $params  = array_merge($params, [$kw, $kw, $kw]);
    }

    $sql .= " ORDER BY fd.delivery_date DESC, fd.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recent_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching deliveries: " . $e->getMessage());
}

// ── Summary totals for the active filter period ───────────────
$summary_liters  = array_sum(array_column($recent_deliveries, 'delivery_liters'));
$summary_count   = count($recent_deliveries);
$pending_count   = count(array_filter($recent_deliveries, fn($d) => in_array(strtolower($d['status'] ?? ''), ['pending','pending review'])));
$verified_count  = count(array_filter($recent_deliveries, fn($d) => strtolower($d['status'] ?? '') === 'verified'));

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Page layout ─────────────────────────────────────────────── */
.page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
}
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #002F6C;
    color: #fff;
    text-decoration: none;
    padding: 9px 18px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    transition: background .18s;
    white-space: nowrap;
}
.back-link:hover { background: #001f4d; color: #fff; text-decoration: none; }

/* ── Delivery encoding table (matches fuel transaction table style) ── */
.del-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    overflow: hidden;
    margin-bottom: 20px;
}
.del-card-header {
    padding: 14px 20px;
    background: #f0f4ff;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.del-card-header h3 {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #003d82 !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
}

/* ── Shared fields row (date, supplier, invoice, tanker) ── */
.del-shared-row {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    padding: 16px 20px 0;
    align-items: flex-end;
}
.del-shared-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 160px;
    flex: 1;
}
.del-shared-field label {
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.del-shared-field input {
    padding: 8px 11px;
    border: 1.5px solid #e2e8f0;
    border-radius: 7px;
    font-size: 13px;
    color: #1e293b;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
}
.del-shared-field input:focus {
    outline: none;
    border-color: #003d82;
    box-shadow: 0 0 0 3px rgba(0,61,130,.1);
}

/* ── Delivery encoding table ─────────────────────────────────── */
.det-wrap { overflow-x: auto; padding: 14px 0 0; }
.det {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 820px;
}
.det thead tr { background: #f1f5f9; }
.det th {
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .45px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}
.det th.num { text-align: right; }
.det tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .12s; }
.det tbody tr:last-child { border-bottom: none; }
.det tbody tr:hover { background: #f8fafc; }
.det td { padding: 10px 14px; vertical-align: middle; }
.det td.num { text-align: right; font-variant-numeric: tabular-nums; }

/* Fuel type identity cell */
.det-fuel-cell {
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
}
.det-fuel-name { font-weight: 700; font-size: 13px; }
.det-auto { color: #334155; font-weight: 600; font-variant-numeric: tabular-nums; }
.det-auto.dim { color: #94a3b8; font-weight: 400; font-style: italic; }

/* Input cells */
.det-input {
    padding: 8px 10px;
    border: 1.5px solid #e2e8f0;
    border-radius: 7px;
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    background: #fff;
    width: 100%;
    min-width: 120px;
    transition: border-color .15s, box-shadow .15s;
}
.det-input:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,61,130,.1);
}
.det-input.notes-input { min-width: 140px; font-weight: 400; font-size: 12px; }

/* Submit button per row */
.det-submit-btn {
    padding: 8px 16px;
    background: #002f6c;
    color: #fff;
    border: none;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background .15s;
}
.det-submit-btn:hover   { background: #001f4d; }
.det-submit-btn:disabled { opacity: .5; cursor: not-allowed; }
.det-reset-btn {
    padding: 8px 12px;
    background: #f1f5f9;
    color: #475569;
    border: 1.5px solid #e2e8f0;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
}
.det-reset-btn:hover { background: #e2e8f0; }
.det-row-msg { font-size: 11px; display: none; white-space: nowrap; }

/* ── Delivery records table ──────────────────────────────────── */
.deliveries-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 24px;
}
.table-wrapper { overflow-x: auto; }
.deliveries-table table { width: 100%; border-collapse: collapse; }
.deliveries-table th,
.deliveries-table td { padding: 10px 13px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
.deliveries-table th {
    background: #f8fafc;
    font-weight: 700;
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}
.deliveries-table tbody tr:hover td { background: #f8fafc; }

/* ── Status badges ───────────────────────────────────────────── */
.status-badge { padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; display: inline-block; white-space: nowrap; }
.status-badge.pending         { background: #fef9c3; color: #854d0e; }
.status-badge.verified        { background: #dcfce7; color: #166534; }
.status-badge.rejected        { background: #fee2e2; color: #991b1b; }
.status-badge.pending_review  { background: #fef9c3; color: #854d0e; }

.empty-state { text-align: center; padding: 36px; color: #6c757d; }
.empty-state i { font-size: 2.5rem; margin-bottom: 12px; opacity: .45; display: block; }
</style>

<div class="page-head" data-rendering="php">
    <div>
        <h1 class="h1"><i class="fas fa-truck" style="color:#003d82;margin-right:8px;"></i>Fuel Deliveries</h1>
        <div class="sub">Record fuel deliveries received from suppliers — pending manager validation</div>
    </div>
    <a href="staff_transactions_hub.php?section=fuel" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Fuel Transactions
    </a>
</div>

<!-- Message Alert -->
<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" style="margin-bottom:20px;">
    <?= $msg ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>



<!-- ═══════════════════════════════════════════════════════════
     RECORD NEW DELIVERY — Table format (matches fuel transaction style)
═══════════════════════════════════════════════════════════ -->

<?php if (empty($fuel_types)): ?>
<div style="background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:16px 20px;margin-bottom:20px;color:#7c5c00;font-size:13px;">
    <i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>
    No fuel types configured for this station. Contact your manager.
</div>
<?php else: ?>

<!-- Hidden forms for each fuel row (placed outside table — <form> inside <tr> is invalid HTML) -->
<?php foreach ($fuel_types as $idx => $fuel):
    $ft_key  = 'del_' . preg_replace('/[^a-z0-9]/', '_', strtolower($fuel['name'])) . '_' . $idx;
    $ft_name = htmlspecialchars($fuel['name']);
    $tl_key  = strtolower(trim($fuel['name']));
    $tl      = $tank_levels[$tl_key] ?? null;
    $cur     = $tl ? (float)$tl['current_stock'] : 0;
    $cap     = $tl ? (float)$tl['capacity']      : 0;
?>
<form id="delForm_<?= $ft_key ?>"
      method="POST"
      action="staff_fuel_deliveries.php"
      style="display:none;">
    <input type="hidden" name="action"            value="record_delivery">
    <input type="hidden" name="fuel_type"         value="<?= $ft_name ?>">
    <input type="hidden" name="delivery_date"     id="delDate_<?= $ft_key ?>">
    <input type="hidden" name="supplier"          id="delSupplier_<?= $ft_key ?>">
    <input type="hidden" name="invoice_no"        id="delInvoice_<?= $ft_key ?>">
    <input type="hidden" name="delivery_liters"   id="delLiters_<?= $ft_key ?>">
    <input type="hidden" name="tanker_number"     id="delTanker_<?= $ft_key ?>">
    <input type="hidden" name="notes"             id="delNotes_<?= $ft_key ?>">
</form>
<?php endforeach; ?>

<div class="del-card">
    <div class="del-card-header">
        <i class="fas fa-truck" style="color:#003d82;"></i>
        <h3>Record New Delivery</h3>
        <span style="margin-left:auto;font-size:11px;color:#64748b;font-weight:500;">
            <?= date('F j, Y') ?> &nbsp;|&nbsp; Pending Manager Validation
        </span>
    </div>

    <!-- Shared fields: Date & Supplier (apply to all rows) -->
    <div class="del-shared-row">
        <div class="del-shared-field">
            <label><i class="fas fa-calendar-day" style="margin-right:4px;"></i>Delivery Date <span style="color:#dc2626;">*</span></label>
            <input type="date" id="sharedDate" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="del-shared-field" style="max-width:280px;flex:0 1 280px;">
            <label><i class="fas fa-building" style="margin-right:4px;"></i>Supplier <span style="color:#dc2626;">*</span></label>
            <input type="text" id="sharedSupplier" value="Petron Corporation" placeholder="e.g., Petron Corporation">
        </div>
        <div style="display:flex;align-items:flex-end;padding-bottom:1px;">
            <div style="font-size:11px;color:#64748b;background:#f1f5f9;border-radius:7px;padding:8px 12px;line-height:1.5;">
                <i class="fas fa-info-circle" style="margin-right:4px;color:#003d82;"></i>
                Fill in <strong>Invoice</strong> &amp; <strong>Liters</strong> per fuel type below, then click <strong>Submit</strong>.
            </div>
        </div>
    </div>

    <!-- Per-fuel-type table -->
    <div class="det-wrap">
        <table class="det">
            <thead>
                <tr>
                    <th>Fuel Type</th>
                    <th class="num">Current Level</th>
                    <th class="num">Tank Capacity</th>
                    <th style="min-width:150px;">Invoice No. <span style="color:#dc2626;font-weight:800;">*</span></th>
                    <th style="min-width:140px;">Liters Delivered <span style="color:#dc2626;font-weight:800;">*</span></th>
                    <th style="min-width:120px;">Tanker No.</th>
                    <th style="min-width:160px;">Notes</th>
                    <th style="min-width:160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fuel_types as $idx => $fuel):
                $ft_key  = 'del_' . preg_replace('/[^a-z0-9]/', '_', strtolower($fuel['name'])) . '_' . $idx;
                $ft_name = htmlspecialchars($fuel['name']);
                $ft_lower = strtolower($fuel['name']);
                $tl_key  = strtolower(trim($fuel['name']));
                $tl      = $tank_levels[$tl_key] ?? null;
                $cur     = $tl ? (float)$tl['current_stock'] : 0;
                $cap     = $tl ? (float)$tl['capacity']      : 0;
                $avail   = ($cap > 0) ? max(0, $cap - $cur) : null;

                // Brand-accurate colors (same as fuel transaction table)
                if      (str_contains($ft_lower, 'diesel'))   { $ft_color = '#003d7a'; $ft_icon = 'fa-gas-pump';  }
                elseif  (str_contains($ft_lower, 'kerosene')) { $ft_color = '#b45309'; $ft_icon = 'fa-fire';      }
                elseif  (str_contains($ft_lower, 'xcs'))      { $ft_color = '#0369a1'; $ft_icon = 'fa-gas-pump';  }
                elseif  (str_contains($ft_lower, 'xtra'))     { $ft_color = '#15803d'; $ft_icon = 'fa-gas-pump';  }
                elseif  (str_contains($ft_lower, 'blaze'))    { $ft_color = '#b91c1c'; $ft_icon = 'fa-fire-alt';  }
                elseif  (str_contains($ft_lower, 'e10'))      { $ft_color = '#065f46'; $ft_icon = 'fa-leaf';      }
                else                                           { $ft_color = '#334155'; $ft_icon = 'fa-gas-pump';  }

                // Capacity fill percentage
                $fill_pct = ($cap > 0) ? min(100, round(($cur / $cap) * 100)) : 0;
                $fill_color = $fill_pct >= 80 ? '#15803d' : ($fill_pct >= 40 ? '#b45309' : '#dc2626');
            ?>
            <tr id="delRow_<?= $ft_key ?>">
                <!-- Fuel type identity -->
                <td>
                    <div class="det-fuel-cell">
                        <div style="width:32px;height:32px;border-radius:50%;background:<?= $ft_color ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas <?= $ft_icon ?>" style="color:#fff;font-size:13px;"></i>
                        </div>
                        <div class="det-fuel-name" style="color:<?= $ft_color ?>;"><?= $ft_name ?></div>
                    </div>
                </td>

                <!-- Current level -->
                <td class="num">
                    <span class="det-auto <?= $cur > 0 ? '' : 'dim' ?>">
                        <?= $cur > 0 ? number_format($cur, 0) . ' L' : '—' ?>
                    </span>
                    <?php if ($cap > 0): ?>
                    <div style="margin-top:4px;height:4px;background:#e2e8f0;border-radius:4px;min-width:60px;">
                        <div style="height:4px;background:<?= $fill_color ?>;border-radius:4px;width:<?= $fill_pct ?>%;"></div>
                    </div>
                    <div style="font-size:10px;color:<?= $fill_color ?>;margin-top:2px;font-weight:600;"><?= $fill_pct ?>%</div>
                    <?php endif; ?>
                </td>

                <!-- Tank capacity -->
                <td class="num">
                    <span class="det-auto <?= $cap > 0 ? '' : 'dim' ?>">
                        <?= $cap > 0 ? number_format($cap, 0) . ' L' : '—' ?>
                    </span>
                    <?php if ($avail !== null): ?>
                    <div style="font-size:10px;color:#64748b;margin-top:2px;">
                        <?= number_format($avail, 0) ?> L avail.
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Invoice No. -->
                <td>
                    <input type="text"
                           id="invoice_<?= $ft_key ?>"
                           class="det-input"
                           placeholder="INV-2024-001"
                           maxlength="100"
                           autocomplete="off"
                           style="border-color:<?= $ft_color ?>;"
                           oninput="this.value=this.value.toUpperCase()">
                </td>

                <!-- Liters Delivered -->
                <td>
                    <input type="number"
                           id="liters_<?= $ft_key ?>"
                           class="det-input"
                           step="0.01" min="0.01"
                           placeholder="e.g. 10000"
                           autocomplete="off"
                           style="border-color:<?= $ft_color ?>;"
                           oninput="checkDelCapacity('<?= $ft_key ?>', <?= $cur ?>, <?= $cap ?>)">
                    <div id="capHint_<?= $ft_key ?>" style="font-size:10px;margin-top:3px;display:none;"></div>
                </td>

                <!-- Tanker No. -->
                <td>
                    <input type="text"
                           id="tanker_<?= $ft_key ?>"
                           class="det-input"
                           placeholder="TK-001"
                           maxlength="50"
                           autocomplete="off"
                           oninput="this.value=this.value.toUpperCase()">
                </td>

                <!-- Notes -->
                <td>
                    <input type="text"
                           id="notes_<?= $ft_key ?>"
                           class="det-input notes-input"
                           placeholder="Driver, seal no., remarks…"
                           maxlength="255"
                           autocomplete="off">
                </td>

                <!-- Actions -->
                <td>
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                        <button type="button"
                                class="det-submit-btn"
                                id="delSubmitBtn_<?= $ft_key ?>"
                                onclick="triggerDelSubmit('<?= $ft_key ?>', <?= $cur ?>, <?= $cap ?>)">
                            <i class="fas fa-paper-plane"></i> Submit
                        </button>
                        <button type="button"
                                class="det-reset-btn"
                                onclick="resetDelRow('<?= $ft_key ?>')"
                                title="Clear this row">
                            <i class="fas fa-undo"></i>
                        </button>
                    </div>
                    <div id="delRowMsg_<?= $ft_key ?>" class="det-row-msg" style="margin-top:5px;"></div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div><!-- /det-wrap -->
</div><!-- /del-card -->

<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     DELIVERY RECORDS — Filter Bar + Summary + Table
═══════════════════════════════════════════════════════════ -->
<div class="deliveries-table">

    <!-- ── Filter Header ──────────────────────────────────────── -->
    <div style="padding:18px 20px;background:#f8f9fa;border-bottom:1px solid #dee2e6;">

        <!-- Title row -->
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <h3 style="margin:0;padding:0;background:none;border:none;font-size:1rem;color:#003d82;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-history"></i> My Delivery Records
                <?php if ($pending_count > 0): ?>
                <span class="status-badge pending"><?= $pending_count ?> Pending</span>
                <?php endif; ?>
            </h3>
        </div>

        <!-- Filter form -->
        <form method="GET" action="staff_fuel_deliveries.php" id="filterForm">

            <!-- Row 1: Period preset buttons -->
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;align-items:center;">
                <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-right:4px;">Period:</span>
                <?php
                $periods = [
                    'ytd'     => ['label' => 'Year-to-Date', 'icon' => 'fa-calendar'],
                    'monthly' => ['label' => 'This Month',   'icon' => 'fa-calendar-alt'],
                    'weekly'  => ['label' => 'This Week',    'icon' => 'fa-calendar-week'],
                    'custom'  => ['label' => 'Custom Range', 'icon' => 'fa-sliders-h'],
                ];
                foreach ($periods as $key => $p):
                    $active = ($filter_period === $key);
                ?>
                <button type="button"
                        onclick="setPeriod('<?= $key ?>')"
                        style="padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;border:2px solid <?= $active ? '#002f6c' : '#dee2e6' ?>;background:<?= $active ? '#002f6c' : '#fff' ?>;color:<?= $active ? '#fff' : '#475569' ?>;transition:all .15s;"
                        id="periodBtn_<?= $key ?>">
                    <i class="fas <?= $p['icon'] ?>"></i> <?= $p['label'] ?>
                </button>
                <?php endforeach; ?>
                <input type="hidden" name="period" id="periodInput" value="<?= htmlspecialchars($filter_period) ?>">
            </div>

            <!-- Row 2: Custom date range (shown only when custom is active) -->
            <div id="customDateRow" style="display:<?= $filter_period === 'custom' ? 'flex' : 'none' ?>;gap:10px;flex-wrap:wrap;margin-bottom:12px;align-items:flex-end;">
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">From</label>
                    <input type="date" name="date_from" id="dateFrom"
                           value="<?= htmlspecialchars($filter_date_from ?: $resolved_from) ?>"
                           style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;">
                </div>
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">To</label>
                    <input type="date" name="date_to" id="dateTo"
                           value="<?= htmlspecialchars($filter_date_to ?: $resolved_to) ?>"
                           style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;">
                </div>
            </div>

            <!-- Row 3: Search dropdowns + keyword + action buttons -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">

                <!-- Fuel Type filter -->
                <div style="display:flex;flex-direction:column;gap:4px;min-width:160px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">Fuel Type</label>
                    <select name="fuel_type_filter"
                            style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;">
                        <option value="">All Types</option>
                        <?php foreach ($fuel_types as $ft): ?>
                        <option value="<?= htmlspecialchars($ft['name']) ?>"
                            <?= $filter_fuel_type === $ft['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ft['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Supplier filter -->
                <div style="display:flex;flex-direction:column;gap:4px;min-width:180px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">Supplier</label>
                    <select name="supplier_filter"
                            style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;">
                        <option value="">All Suppliers</option>
                        <?php foreach ($supplier_list as $sup): ?>
                        <option value="<?= htmlspecialchars($sup) ?>"
                            <?= $filter_supplier === $sup ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sup) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Keyword search -->
                <div style="display:flex;flex-direction:column;gap:4px;flex:1;min-width:180px;">
                    <label style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;">Keyword</label>
                    <input type="text" name="keyword"
                           value="<?= htmlspecialchars($filter_keyword) ?>"
                           placeholder="Invoice no., tanker, notes…"
                           style="padding:7px 11px;border:1.5px solid #dee2e6;border-radius:7px;font-size:13px;color:#1e293b;">
                </div>

                <!-- Action buttons -->
                <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:1px;">
                    <button type="submit"
                            style="padding:8px 18px;background:#002f6c;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;transition:background .15s;"
                            onmouseover="this.style.background='#001f4d'" onmouseout="this.style.background='#002f6c'">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="staff_fuel_deliveries.php"
                       style="padding:8px 16px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:6px;transition:background .15s;"
                       onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>

            </div><!-- /row 3 -->

            <!-- Active filter summary label -->
            <?php
            $period_labels = ['ytd'=>'Year-to-Date','monthly'=>'This Month','weekly'=>'This Week','custom'=>'Custom Range'];
            $period_label  = $period_labels[$filter_period] ?? 'This Month';
            ?>
            <div style="margin-top:10px;font-size:11px;color:#64748b;">
                <i class="fas fa-filter" style="margin-right:4px;"></i>
                Showing: <strong><?= $period_label ?></strong>
                (<?= date('M j, Y', strtotime($resolved_from)) ?> – <?= date('M j, Y', strtotime($resolved_to)) ?>)
                <?php if ($filter_fuel_type): ?> &bull; Fuel: <strong><?= htmlspecialchars($filter_fuel_type) ?></strong><?php endif; ?>
                <?php if ($filter_supplier):  ?> &bull; Supplier: <strong><?= htmlspecialchars($filter_supplier) ?></strong><?php endif; ?>
                <?php if ($filter_keyword):   ?> &bull; Keyword: <strong>"<?= htmlspecialchars($filter_keyword) ?>"</strong><?php endif; ?>
            </div>

        </form>
    </div><!-- /filter header -->

    <!-- ── Table ──────────────────────────────────────────────── -->
    <div class="table-wrapper" style="max-height: 450px; overflow-y: auto;">
        <table id="deliveriesTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Fuel Type</th>
                    <th>Liters</th>
                    <th>Supplier</th>
                    <th>Invoice No.</th>
                    <th>Tanker</th>
                    <th>Status</th>
                    <th>Validated By</th>
                    <th>Encoded</th>
                </tr>
            </thead>
            <tbody id="deliveriesTableBody">
                <?php foreach ($recent_deliveries as $delivery):
                    $st_raw    = $delivery['status'] ?? 'Pending';
                    $st_key    = strtolower(str_replace(' ', '_', $st_raw));
                    $is_pending = in_array(strtolower($st_raw), ['pending', 'pending review']);
                ?>
                <tr style="<?= $is_pending ? 'background:#fffbea;' : '' ?>">
                    <td><strong>#<?= $delivery['id'] ?></strong></td>
                    <td><?= date('M d, Y', strtotime($delivery['delivery_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($delivery['fuel_type']) ?></strong></td>
                    <td><strong><?= number_format($delivery['delivery_liters'], 0) ?> L</strong></td>
                    <td><?= htmlspecialchars($delivery['supplier']) ?></td>
                    <td><?= htmlspecialchars($delivery['invoice_no']) ?></td>
                    <td><?= htmlspecialchars($delivery['tanker_number'] ?? '—') ?></td>
                    <td>
                        <span class="status-badge <?= $st_key ?>">
                            <?php if ($is_pending): ?>
                                <i class="fas fa-clock"></i>
                            <?php elseif (strtolower($st_raw) === 'verified'): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <i class="fas fa-undo"></i>
                            <?php endif; ?>
                            <?= htmlspecialchars($st_raw) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($delivery['verified_by_name']): ?>
                            <span style="font-size:.82rem;color:#155724;font-weight:600;">
                                <i class="fas fa-user-tie"></i> <?= htmlspecialchars($delivery['verified_by_name']) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#bbb;font-size:.8rem;">Awaiting manager</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;color:#888;"><?= date('M j, g:i A', strtotime($delivery['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <?php if (!empty($recent_deliveries)): ?>
    <div id="deliveriesPagination" style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-top:1px solid #e2e8f0; background:#fff; font-size:13px; color:#475569;">
        <div style="display:flex; align-items:center; gap:8px;">
            <label style="margin:0; font-weight:400; color:#475569;">Rows per page:</label>
            <select id="delRowsPerPage" onchange="changeDelPageSize()" style="padding:4px 24px 4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; background:#fff; color:#1e293b; outline:none; cursor:pointer;">
                <option value="10" selected>10</option>
                <option value="20">20</option>
                <option value="30">30</option>
                <option value="40">40</option>
                <option value="50">50</option>
            </select>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <button onclick="prevDelPage()" id="delPrevBtn" style="width:28px; height:28px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer; font-size:12px; color:#475569; display:flex; align-items:center; justify-content:center;" disabled>
                <i class="fas fa-chevron-left"></i>
            </button>
            <span id="delPageInfo" style="color:#475569; font-size:13px; padding:0 4px;">Page 1 of 1</span>
            <button onclick="nextDelPage()" id="delNextBtn" style="width:28px; height:28px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer; font-size:12px; color:#475569; display:flex; align-items:center; justify-content:center;">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($recent_deliveries)): ?>
    <div class="empty-state">
        <i class="fas fa-truck"></i>
        <p>No deliveries found for the selected filters.</p>
    </div>
    <?php endif; ?>

</div><!-- /deliveries-table -->

<div style="height: 80px;"></div> <!-- Spacer to prevent overlap with fixed footer -->

<script>
// ── Pagination Logic ─────────────────────────────────────────
var currentDelPage = 1;
var rowsPerDelPage = 10;
var totalDelRows = 0;
var allDelRows = [];

document.addEventListener('DOMContentLoaded', function() {
    var tbody = document.getElementById('deliveriesTableBody');
    if (tbody) {
        allDelRows = Array.from(tbody.querySelectorAll('tr'));
        totalDelRows = allDelRows.length;
        if (totalDelRows > 0) {
            renderDelPage();
        }
    }
});

function changeDelPageSize() {
    var select = document.getElementById('delRowsPerPage');
    rowsPerDelPage = parseInt(select.value, 10);
    currentDelPage = 1;
    renderDelPage();
}

function prevDelPage() {
    if (currentDelPage > 1) {
        currentDelPage--;
        renderDelPage();
    }
}

function nextDelPage() {
    var totalPages = Math.ceil(totalDelRows / rowsPerDelPage);
    if (currentDelPage < totalPages) {
        currentDelPage++;
        renderDelPage();
    }
}

function renderDelPage() {
    if (totalDelRows === 0) return;
    
    var totalPages = Math.ceil(totalDelRows / rowsPerDelPage) || 1;
    var startIndex = (currentDelPage - 1) * rowsPerDelPage;
    var endIndex = Math.min(startIndex + rowsPerDelPage, totalDelRows);
    
    allDelRows.forEach(function(row, index) {
        if (index >= startIndex && index < endIndex) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    var info = document.getElementById('delPageInfo');
    if (info) {
        info.textContent = 'Page ' + currentDelPage + ' of ' + totalPages;
    }
    
    var prevBtn = document.getElementById('delPrevBtn');
    if (prevBtn) {
        prevBtn.disabled = (currentDelPage === 1);
        prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
        prevBtn.style.cursor = prevBtn.disabled ? 'not-allowed' : 'pointer';
    }
    
    var nextBtn = document.getElementById('delNextBtn');
    if (nextBtn) {
        nextBtn.disabled = (currentDelPage >= totalPages) || (totalDelRows === 0);
        nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
        nextBtn.style.cursor = nextBtn.disabled ? 'not-allowed' : 'pointer';
    }
}

// ── Period preset toggle ──────────────────────────────────────
function setPeriod(key) {
    document.getElementById('periodInput').value = key;
    ['ytd','monthly','weekly','custom'].forEach(function(k) {
        var btn = document.getElementById('periodBtn_' + k);
        if (!btn) return;
        if (k === key) {
            btn.style.background  = '#002f6c';
            btn.style.color       = '#fff';
            btn.style.borderColor = '#002f6c';
        } else {
            btn.style.background  = '#fff';
            btn.style.color       = '#475569';
            btn.style.borderColor = '#dee2e6';
        }
    });
    var customRow = document.getElementById('customDateRow');
    if (customRow) customRow.style.display = (key === 'custom') ? 'flex' : 'none';
    if (key !== 'custom') document.getElementById('filterForm').submit();
}

// ── Per-row capacity hint ─────────────────────────────────────
function checkDelCapacity(ftKey, current, capacity) {
    var litersEl = document.getElementById('liters_' + ftKey);
    var hint     = document.getElementById('capHint_' + ftKey);
    if (!litersEl || !hint) return;

    var liters = parseFloat(litersEl.value) || 0;

    // Clear any previous row error message when user is typing
    var msgEl = document.getElementById('delRowMsg_' + ftKey);
    if (msgEl && liters > 0) { msgEl.style.display = 'none'; }

    if (capacity <= 0) { hint.style.display = 'none'; return; }

    var avail = Math.max(0, capacity - current);
    var after = current + liters;
    hint.style.display = 'block';

    if (liters > 0 && after > capacity) {
        hint.style.color      = '#dc2626';
        hint.style.fontWeight = '700';
        hint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Exceeds capacity! Avail: '
            + Math.round(avail).toLocaleString() + ' L';
    } else if (liters > 0) {
        hint.style.color      = '#15803d';
        hint.style.fontWeight = '600';
        hint.innerHTML = '<i class="fas fa-check-circle"></i> After delivery: '
            + Math.round(after).toLocaleString() + ' L / '
            + Math.round(capacity).toLocaleString() + ' L ('
            + Math.round((after / capacity) * 100) + '%)';
    } else {
        hint.style.color      = '#64748b';
        hint.style.fontWeight = '400';
        hint.innerHTML = 'Available space: ' + Math.round(avail).toLocaleString() + ' L';
    }
}

// ── Reset a delivery row ──────────────────────────────────────
function resetDelRow(ftKey) {
    ['invoice_', 'liters_', 'tanker_', 'notes_'].forEach(function(prefix) {
        var el = document.getElementById(prefix + ftKey);
        if (el) el.value = '';
    });
    var hint = document.getElementById('capHint_' + ftKey);
    if (hint) { hint.style.display = 'none'; hint.innerHTML = ''; }
    var msg = document.getElementById('delRowMsg_' + ftKey);
    if (msg) { msg.style.display = 'none'; msg.innerHTML = ''; }
    var btn = document.getElementById('delSubmitBtn_' + ftKey);
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit'; }
}

// ── Show inline row message ───────────────────────────────────
function showDelMsg(ftKey, text, color) {
    var msgEl = document.getElementById('delRowMsg_' + ftKey);
    if (!msgEl) return;
    msgEl.style.display  = 'block';
    msgEl.style.color    = color || '#dc2626';
    msgEl.innerHTML      = text;
}

// ── Trigger submit for a row ──────────────────────────────────
function triggerDelSubmit(ftKey, current, capacity) {
    var dateEl     = document.getElementById('sharedDate');
    var supplierEl = document.getElementById('sharedSupplier');
    var invoiceEl  = document.getElementById('invoice_' + ftKey);
    var litersEl   = document.getElementById('liters_'  + ftKey);
    var tankerEl   = document.getElementById('tanker_'  + ftKey);
    var notesEl    = document.getElementById('notes_'   + ftKey);
    var btn        = document.getElementById('delSubmitBtn_' + ftKey);

    // Clear previous message
    var msgEl = document.getElementById('delRowMsg_' + ftKey);
    if (msgEl) { msgEl.style.display = 'none'; msgEl.innerHTML = ''; }

    // Read values
    var delivDate = dateEl     ? dateEl.value.trim()     : '';
    var supplier  = supplierEl ? supplierEl.value.trim() : '';
    var invoice   = invoiceEl  ? invoiceEl.value.trim()  : '';
    var liters    = litersEl   ? parseFloat(litersEl.value) : NaN;

    // ── Validation ────────────────────────────────────────────
    if (!delivDate) {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Delivery date is required.');
        dateEl && dateEl.focus();
        return;
    }
    if (!supplier) {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Supplier is required.');
        supplierEl && supplierEl.focus();
        return;
    }
    if (!invoice) {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Invoice number is required.');
        invoiceEl && invoiceEl.focus();
        return;
    }
    if (isNaN(liters) || liters <= 0) {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Enter a valid quantity (liters > 0).');
        litersEl && litersEl.focus();
        return;
    }

    // ── Capacity guard ────────────────────────────────────────
    if (capacity > 0) {
        var avail = Math.max(0, capacity - current);
        if (liters > avail) {
            showDelMsg(ftKey,
                '<i class="fas fa-exclamation-triangle"></i> Exceeds available tank space! Max: '
                + Math.round(avail).toLocaleString() + ' L'
            );
            litersEl && litersEl.focus();
            return;
        }
    }

    // ── Populate hidden form fields ───────────────────────────
    document.getElementById('delDate_'     + ftKey).value = delivDate;
    document.getElementById('delSupplier_' + ftKey).value = supplier;
    document.getElementById('delInvoice_'  + ftKey).value = invoice;
    document.getElementById('delLiters_'   + ftKey).value = liters;
    document.getElementById('delTanker_'   + ftKey).value = tankerEl ? tankerEl.value.trim() : '';
    document.getElementById('delNotes_'    + ftKey).value = notesEl  ? notesEl.value.trim()  : '';

    // ── Disable button to prevent double-submit ───────────────
    if (btn) {
        btn.disabled    = true;
        btn.innerHTML   = '<i class="fas fa-spinner fa-spin"></i> Submitting\u2026';
    }

    // ── Submit the hidden form ────────────────────────────────
    var form = document.getElementById('delForm_' + ftKey);
    if (form) {
        form.submit();
    } else {
        showDelMsg(ftKey, '<i class="fas fa-exclamation-circle"></i> Form not found. Please refresh the page.');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit'; }
    }
}

// ── Prevent Enter key from accidentally submitting shared fields ──
document.addEventListener('DOMContentLoaded', function() {
    ['sharedDate', 'sharedSupplier'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault();
            });
        }
    });
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
