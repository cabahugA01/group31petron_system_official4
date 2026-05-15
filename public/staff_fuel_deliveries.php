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
.page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
}
.delivery-form {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 24px;
}
.delivery-form h3 {
    margin: 0 0 20px 0;
    color: #003d82;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.05rem;
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.form-group label {
    font-weight: 600;
    color: #333;
    font-size: 0.88rem;
}
.form-group input,
.form-group select,
.form-group textarea {
    padding: 9px 11px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 0.93rem;
}
.form-group textarea { resize: vertical; min-height: 72px; }
.btn-record {
    background: #003d82;
    color: white;
    border: none;
    padding: 11px 24px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: .93rem;
    transition: background 0.2s;
}
.btn-record:hover { background: #002a5c; }
.deliveries-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 24px;
}
.deliveries-table h3 {
    margin: 0;
    padding: 16px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    color: #003d82;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.table-wrapper { overflow-x: auto; }
.deliveries-table table { width: 100%; border-collapse: collapse; }
.deliveries-table th,
.deliveries-table td { padding: 11px 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-size: .88rem; }
.deliveries-table th { background: #f8f9fa; font-weight: 600; color: #495057; font-size: .8rem; text-transform: uppercase; }
.status-badge { padding: 3px 10px; border-radius: 20px; font-size: .78rem; font-weight: 600; display: inline-block; }
.status-badge.pending    { background: #fff3cd; color: #856404; }
.status-badge.verified   { background: #d4edda; color: #155724; }
.status-badge.rejected   { background: #f8d7da; color: #721c24; }
.status-badge.pending_review { background: #fff3cd; color: #856404; }
.tank-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.tank-card {
    background: white;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    border-left: 4px solid #003d82;
}
.tank-card .tc-label { font-size: .72rem; color: #888; text-transform: uppercase; font-weight: 600; margin-bottom: 4px; }
.tank-card .tc-value { font-size: 1.2rem; font-weight: 700; color: #003d82; }
.tank-card .tc-cap   { font-size: .75rem; color: #aaa; margin-top: 2px; }
.info-banner {
    background: #e8f4ff;
    border-left: 4px solid #003d82;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: .85rem;
    color: #444;
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



<!-- Record New Delivery Form -->
<div class="delivery-form">
    <h3><i class="fas fa-plus-circle"></i> Record New Delivery</h3>
    <form method="POST" id="deliveryForm">
        <input type="hidden" name="action" value="record_delivery">
        <div class="form-grid">
            <div class="form-group">
                <label for="delivery_date">Delivery Date <span style="color:red">*</span></label>
                <input type="date" id="delivery_date" name="delivery_date"
                       value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label for="fuel_type">Fuel Type <span style="color:red">*</span></label>
                <select id="fuel_type" name="fuel_type" required>
                    <option value="">Select Fuel Type</option>
                    <?php foreach ($fuel_types as $fuel): ?>
                    <?php
                        $key   = strtolower(trim($fuel['name']));
                        $tl    = $tank_levels[$key] ?? null;
                        $cur   = $tl ? (float)$tl['current_stock'] : 0;
                        $cap   = $tl ? (float)$tl['capacity']      : 0;
                        $avail = ($cap > 0) ? max(0, $cap - $cur)  : null;
                    ?>
                    <option value="<?= htmlspecialchars($fuel['name']) ?>"
                        data-current="<?= $cur ?>"
                        data-capacity="<?= $cap ?>">
                        <?= htmlspecialchars($fuel['name']) ?>
                        <?php if ($tl): ?>
                         — <?= number_format($cur, 0) ?> L / <?= $cap > 0 ? number_format($cap, 0) . ' L cap' : 'no cap set' ?>
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="supplier">Supplier <span style="color:red">*</span></label>
                <input type="text" id="supplier" name="supplier"
                       value="Petron Corporation" required
                       placeholder="e.g., Petron Corporation">
            </div>
            <div class="form-group">
                <label for="invoice_no">Invoice Number <span style="color:red">*</span></label>
                <input type="text" id="invoice_no" name="invoice_no"
                       placeholder="e.g., INV-2024-001" required>
            </div>
            <div class="form-group">
                <label for="delivery_liters">Quantity Delivered (Liters) <span style="color:red">*</span></label>
                <input type="number" id="delivery_liters" name="delivery_liters"
                       step="0.01" min="0.01" placeholder="e.g., 10000.00" required
                       oninput="checkCapacity()">
                <div id="capacity_hint" style="margin-top:4px;font-size:.82rem;display:none;"></div>
            </div>
            <div class="form-group">
                <label for="tanker_number">Tanker Number</label>
                <input type="text" id="tanker_number" name="tanker_number"
                       placeholder="e.g., TK-001">
            </div>
        </div>
        <div class="form-group" style="margin-bottom:16px;">
            <label for="notes">Delivery Notes</label>
            <textarea id="notes" name="notes" placeholder="Optional: driver name, seal number, observations..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;">
            <button type="submit" class="btn-record">
                <i class="fas fa-paper-plane"></i> Submit for Manager Validation
            </button>
        </div>
    </form>
</div>

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
    <div class="table-wrapper">
        <table>
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
            <tbody>
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
                <?php if (empty($recent_deliveries)): ?>
                <tr>
                    <td colspan="10">
                        <div class="empty-state">
                            <i class="fas fa-truck"></i>
                            <p>No deliveries found for the selected filters.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div><!-- /deliveries-table -->

<script>
var tankData = <?php
    $js_tank = [];
    foreach ($tank_levels as $key => $tl) {
        $js_tank[strtolower(trim($tl['fuel_type']))] = [
            'current'  => (float)$tl['current_stock'],
            'capacity' => (float)$tl['capacity'],
        ];
    }
    echo json_encode($js_tank);
?>;

document.getElementById('invoice_no').addEventListener('input', function(e) {
    e.target.value = e.target.value.toUpperCase();
});
document.getElementById('tanker_number').addEventListener('input', function(e) {
    e.target.value = e.target.value.toUpperCase();
});

// ── Period preset toggle ──────────────────────────────────────
function setPeriod(key) {
    // Update hidden input
    document.getElementById('periodInput').value = key;

    // Update button styles
    ['ytd','monthly','weekly','custom'].forEach(function(k) {
        var btn = document.getElementById('periodBtn_' + k);
        if (!btn) return;
        if (k === key) {
            btn.style.background   = '#002f6c';
            btn.style.color        = '#fff';
            btn.style.borderColor  = '#002f6c';
        } else {
            btn.style.background   = '#fff';
            btn.style.color        = '#475569';
            btn.style.borderColor  = '#dee2e6';
        }
    });

    // Show/hide custom date row
    var customRow = document.getElementById('customDateRow');
    if (customRow) {
        customRow.style.display = (key === 'custom') ? 'flex' : 'none';
    }

    // Auto-submit for preset periods (not custom — user needs to pick dates first)
    if (key !== 'custom') {
        document.getElementById('filterForm').submit();
    }
}

// Update capacity hint when fuel type changes
document.getElementById('fuel_type').addEventListener('change', function() {
    checkCapacity();
});

function checkCapacity() {
    var fuelSel  = document.getElementById('fuel_type');
    var litersEl = document.getElementById('delivery_liters');
    var hint     = document.getElementById('capacity_hint');
    if (!fuelSel || !litersEl || !hint) return;

    var fuelKey  = (fuelSel.value || '').toLowerCase().trim();
    var liters   = parseFloat(litersEl.value) || 0;
    var tank     = tankData[fuelKey];

    if (!tank || tank.capacity <= 0) {
        hint.style.display = 'none';
        return;
    }

    var current  = tank.current;
    var capacity = tank.capacity;
    var avail    = Math.max(0, capacity - current);
    var after    = current + liters;

    hint.style.display = 'block';

    if (liters > 0 && after > capacity) {
        hint.style.color   = '#dc3545';
        hint.style.background = '#fff5f5';
        hint.style.border  = '1px solid #f5c6cb';
        hint.style.padding = '6px 10px';
        hint.style.borderRadius = '5px';
        hint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> '
            + '<strong>Exceeds capacity!</strong> '
            + 'Available space: <strong>' + Math.round(avail).toLocaleString() + ' L</strong> '
            + '(Current: ' + Math.round(current).toLocaleString() + ' L, '
            + 'Capacity: ' + Math.round(capacity).toLocaleString() + ' L)';
    } else if (liters > 0) {
        var remaining = avail - liters;
        hint.style.color   = '#198754';
        hint.style.background = '#f0fdf4';
        hint.style.border  = '1px solid #bbf7d0';
        hint.style.padding = '6px 10px';
        hint.style.borderRadius = '5px';
        hint.innerHTML = '<i class="fas fa-check-circle"></i> '
            + 'OK — After delivery: <strong>' + Math.round(after).toLocaleString() + ' L</strong> '
            + '/ ' + Math.round(capacity).toLocaleString() + ' L cap '
            + '(' + Math.round((after / capacity) * 100) + '% full, '
            + Math.round(remaining).toLocaleString() + ' L remaining space)';
    } else {
        hint.style.color   = '#667085';
        hint.style.background = 'transparent';
        hint.style.border  = 'none';
        hint.style.padding = '0';
        hint.innerHTML = 'Available space: <strong>' + Math.round(avail).toLocaleString() + ' L</strong> '
            + '(Current: ' + Math.round(current).toLocaleString() + ' L / '
            + Math.round(capacity).toLocaleString() + ' L)';
    }
}

document.getElementById('deliveryForm').addEventListener('submit', function(e) {
    var liters = parseFloat(document.getElementById('delivery_liters').value);
    if (liters <= 0 || isNaN(liters)) {
        e.preventDefault();
        alert('Delivery liters must be greater than 0');
        return false;
    }
    var invoiceNo = document.getElementById('invoice_no').value.trim();
    if (!invoiceNo) {
        e.preventDefault();
        alert('Invoice number is required');
        return false;
    }
    var fuelType = document.getElementById('fuel_type').value;
    if (!fuelType) {
        e.preventDefault();
        alert('Please select a fuel type');
        return false;
    }

    // Hard block if over capacity
    var fuelKey  = fuelType.toLowerCase().trim();
    var tank     = tankData[fuelKey];
    if (tank && tank.capacity > 0) {
        var avail = Math.max(0, tank.capacity - tank.current);
        if (liters > avail) {
            e.preventDefault();
            alert(
                'Cannot submit: delivery of ' + Math.round(liters).toLocaleString() + ' L exceeds available tank space.\n\n' +
                'Fuel type: ' + fuelType + '\n' +
                'Current level: ' + Math.round(tank.current).toLocaleString() + ' L\n' +
                'Tank capacity: ' + Math.round(tank.capacity).toLocaleString() + ' L\n' +
                'Available space: ' + Math.round(avail).toLocaleString() + ' L'
            );
            return false;
        }
    }
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
