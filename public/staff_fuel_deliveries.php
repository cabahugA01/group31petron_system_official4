<?php
$page_id = 'staff_fuel_deliveries';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php'); exit;
}

$msg      = '';
$msg_type = 'success';
if (isset($_SESSION['success'])) { $msg = $_SESSION['success']; $msg_type = 'success'; unset($_SESSION['success']); }
if (isset($_SESSION['error']))   { $msg = $_SESSION['error'];   $msg_type = 'error';   unset($_SESSION['error']); }

// ── All 17 tank entries (matches image exactly) ────────
$TANK_CONFIG = [
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 1',     'tank'=>'Underground Tank #1'],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 2',     'tank'=>'Underground Tank #2'],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 3',     'tank'=>'Underground Tank #3'],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 1 - 4',     'tank'=>'Underground Tank #4'],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 5',     'tank'=>'Underground Tank #5'],
    ['fuel_type'=>'Diesel',       'label'=>'DIESEL 2 - 6',     'tank'=>'Underground Tank #6'],
    ['fuel_type'=>'Kerosene',     'label'=>'KEROSENE - 1',     'tank'=>'Underground Tank #7'],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8'],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 2', 'tank'=>'Underground Tank #9'],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 1',     'tank'=>'Underground Tank #10'],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 2',     'tank'=>'Underground Tank #11'],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 3',     'tank'=>'Underground Tank #12'],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 4',     'tank'=>'Underground Tank #13'],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 1',  'tank'=>'Underground Tank #14'],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 1 - 2',  'tank'=>'Underground Tank #15'],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 3',  'tank'=>'Underground Tank #16'],
    ['fuel_type'=>'XTRA UNL',     'label'=>'XTRA UNL 2 - 4',  'tank'=>'Underground Tank #17'],
];

$FT_STYLE = [
    'Diesel'       => ['color'=>'#003d7a', 'icon'=>'fas fa-gas-pump'],
    'Turbo Diesel' => ['color'=>'#7c3aed', 'icon'=>'fas fa-gas-pump'],
    'XCS Plus'     => ['color'=>'#0369a1', 'icon'=>'fas fa-gas-pump'],
    'XTRA UNL'     => ['color'=>'#15803d', 'icon'=>'fas fa-gas-pump'],
    'Kerosene'     => ['color'=>'#b45309', 'icon'=>'fas fa-fire'],
];

// ── Auto batch ID (Same date = Same batch for FUEL) ─────
// Prefix: FBATCH- to distinguish from merchandise (MBATCH-)
function genBatch(PDO $pdo, string $d, int $station_id): string {
    $pfx = 'FBATCH-'.date('Ymd', strtotime($d)).'-';
    
    // Check if a fuel batch already exists for this date at this station
    $s = $pdo->prepare("
        SELECT batch_id 
        FROM fuel_deliveries 
        WHERE batch_id LIKE ? 
          AND station_id = ? 
          AND DATE(delivery_date) = ? 
        LIMIT 1
    ");
    $s->execute([$pfx.'%', $station_id, $d]);
    $existing = $s->fetchColumn();
    
    if ($existing) {
        // Reuse existing fuel batch for this date
        return $existing;
    }
    
    // Create new fuel batch for this date
    $s = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(batch_id,'-',-1) AS UNSIGNED)) FROM fuel_deliveries WHERE batch_id LIKE ?");
    $s->execute([$pfx.'%']);
    return $pfx.str_pad((int)$s->fetchColumn()+1,3,'0',STR_PAD_LEFT);
}

// ── Fetch Selected PO ──────────────────────────────────
$selected_po = null;
$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : (isset($_POST['selected_po_id']) ? (int)$_POST['selected_po_id'] : 0);
if ($po_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ? AND status = 'Expected Delivery' AND delivery_type = 'fuel'");
        $stmt->execute([$po_id, $station_id]);
        $selected_po = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='record_fuel_delivery') {
    $delivery_date  = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $supplier       = trim($_POST['supplier']      ?? 'Petron Corporation');
    $invoice_no     = trim($_POST['invoice_no']    ?? '');
    $tanker_number  = trim($_POST['tanker_number'] ?? '');
    $remarks        = trim($_POST['remarks']       ?? '');
    $liters_arr     = $_POST['liters'] ?? [];
    $post_po_id     = (int)($_POST['selected_po_id'] ?? 0);

    if ($invoice_no === '')    { $_SESSION['error']='Invoice/DR Number is required.'; header('Location: staff_fuel_deliveries.php'); exit; }
    if ($tanker_number === '') { $_SESSION['error']='Tanker Number is required.';     header('Location: staff_fuel_deliveries.php'); exit; }

    $has_entry = false;
    foreach ($liters_arr as $v) { if ((float)$v > 0) { $has_entry = true; break; } }
    if (!$has_entry) { $_SESSION['error']='Enter liters for at least one tank.'; header('Location: staff_fuel_deliveries.php'); exit; }

    try {
        $batch_id = genBatch($pdo, $delivery_date, $station_id);
        $pdo->beginTransaction();
        $saved = 0;
        foreach ($TANK_CONFIG as $i => $tank) {
            $liters = (float)($liters_arr[$i] ?? 0);
            if ($liters <= 0) continue;
            $pdo->prepare("
                INSERT INTO fuel_deliveries
                    (batch_id,station_id,delivery_date,fuel_type,supplier,invoice_no,
                     delivery_liters,tank_assigned,tanker_number,received_by,notes,status,created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,'Pending Manager Validation',NOW())
            ")->execute([
                $batch_id,$station_id,$delivery_date,$tank['fuel_type'],
                $supplier,$invoice_no,$liters,$tank['tank'],$tanker_number,$me['id'],$remarks
            ]);
            $saved++;
        }

        // If PO was selected, update its status in deliveries_oversight so it's no longer expected
        if ($post_po_id > 0) {
            $pdo->prepare("UPDATE deliveries_oversight SET status = 'Received', dr_number = ?, batch_id = ?, updated_at = NOW() WHERE id = ? AND station_id = ?")
                ->execute([$invoice_no, $batch_id, $post_po_id, $station_id]);
        }

        $pdo->commit();
        try {
            $pdo->prepare("INSERT INTO audit_logs(user_id,log_type,action_type,action_details,entity_type,status,ip_address,created_at)VALUES(?,'transaction','Create',?,'fuel_deliveries','Success',?,NOW())")
                ->execute([$me['id'],"Fuel delivery | Batch:{$batch_id} | Tanks:{$saved} | Invoice:{$invoice_no} | Tanker:{$tanker_number}",$_SERVER['REMOTE_ADDR']??'']);
        } catch(Exception $e){}
        $_SESSION['success'] = "__DELIVERY_SAVED__|{$saved}|{$batch_id}";
    } catch(Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error: '.$e->getMessage();
    }
    header('Location: staff_fuel_deliveries.php'); exit;
}

// ── Fetch Expected Deliveries (from fuel_purchase_orders) ──────────────────
$expected_pos = [];
try {
    $stmt = $pdo->prepare("
        SELECT fpo.id, fpo.po_number, fpo.volume, fpo.expected_delivery_date, fpo.status,
               ft.name AS fuel_type_name,
               COALESCE(fs.name, 'Petron Corporation') AS supplier_name
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN fuel_suppliers fs ON fpo.supplier_id = fs.id
        WHERE fpo.station_id = ?
          AND fpo.status IN ('pending','Incoming','Expected Delivery')
        ORDER BY fpo.expected_delivery_date ASC
        LIMIT 30
    ");
    $stmt->execute([$station_id]);
    $expected_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// Also fetch from deliveries_oversight (legacy/admin-created POs)
$expected_deliveries = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM deliveries_oversight 
        WHERE station_id = ? AND status = 'Expected Delivery' AND delivery_type = 'fuel'
        ORDER BY created_at ASC
    ");
    $stmt->execute([$station_id]);
    $expected_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// ── Fetch Tank Levels (View Only) ─────────────────────────────
$tank_levels = [];
try {
    $stmt = $pdo->prepare("
        SELECT fi.id, fi.fuel_type,
               COALESCE(fi.current_stock, fi.current_level, 0) AS current_level,
               fi.capacity,
               fi.critical_level,
               fi.reorder_level,
               fi.status,
               fi.last_updated,
               ft.name AS fuel_type_name
        FROM fuel_inventory fi
        LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
        WHERE fi.station_id = ?
        ORDER BY ft.name ASC
    ");
    $stmt->execute([$station_id]);
    $tank_levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// Build a lookup map: fuel_type name => inventory row
$tank_inv_map = [];
foreach ($tank_levels as $tl) {
    $key = strtolower(trim($tl['fuel_type_name'] ?? $tl['fuel_type'] ?? ''));
    if (!isset($tank_inv_map[$key])) {
        $tank_inv_map[$key] = $tl;
    }
}

include __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-x:hidden;max-width:100vw}
/* ── Page Head ── */
.fde-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.fde-head h1{margin:0 0 5px;font-size:22px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:.5px}
.fde-head .sub{font-size:12px;color:#64748b;font-weight:500;text-transform:uppercase;letter-spacing:.3px}

/* ── Alert ── */
.alert-b{padding:12px 16px;border-radius:8px;margin-bottom:18px;display:flex;align-items:flex-start;gap:10px;font-size:14px;line-height:1.5}
.a-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.a-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}

/* ── Main layout ── */
.fde-wrap{display:grid;grid-template-columns:1fr 1.3fr;gap:20px;align-items:start;max-width:100%;overflow:hidden}
@media(max-width:1100px){.fde-wrap{grid-template-columns:1fr}}

/* ── Card shell ── */
.fde-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;max-width:100%}
.fde-card-hd{padding:13px 18px;background:#002F70;color:#fff !important;display:flex;align-items:center;justify-content:space-between}
.fde-card-hd h3{margin:0;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;display:flex;align-items:center;gap:8px;color:#fff !important}
.fde-card-hd span{color:#fff !important;opacity:1 !important}
.fde-card-hd i{color:#fff !important}

/* ── Header Fields ── */
.hdr-fields{padding:18px 18px 0;max-width:100%}
.hf-row{display:grid;gap:12px;margin-bottom:12px}
.hf-2{grid-template-columns:1fr 1fr}
.hf-3{grid-template-columns:1fr 1fr 1fr}
@media(max-width:700px){.hf-2,.hf-3{grid-template-columns:1fr}}
.fld{display:flex;flex-direction:column;gap:4px;min-width:0}
.fld-lbl{font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px}
.fld-lbl span{color:#dc2626}
.fld-inp,.fld-sel{width:100%;padding:8px 11px;border:1px solid #00264D;border-radius:7px;font-size:13px;color:#ffffff !important;background:#002F70 !important;font-family:inherit;transition:border-color .15s,box-shadow .15s}
.fld-inp:focus,.fld-sel:focus{border-color:#0056b3;outline:none;box-shadow:0 0 0 3px rgba(0,86,179,.25)}
.fld-inp[readonly]{background:#001a42 !important;cursor:default;color:#ffffff !important;font-weight:700;border-color:#001a42 !important}
.fld-inp::placeholder,.fld-txt::placeholder{color:rgba(255,255,255,0.6) !important}
.fld-sel option{background:#002F70;color:#ffffff}
.fld-txt{width:100%;padding:8px 11px;border:1px solid #00264D;border-radius:7px;font-size:13px;color:#ffffff !important;background:#002F70 !important;font-family:inherit;resize:vertical;min-height:64px;transition:border-color .15s}
.fld-txt:focus{border-color:#0056b3;outline:none;box-shadow:0 0 0 3px rgba(0,86,179,.25)}

/* Selected PO Info Banner */
.po-selected-banner{margin:12px 18px 0;background:#e0f2fe;border:1px solid #7dd3fc;border-radius:8px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.po-selected-info{font-size:13px;color:#0369a1;line-height:1.4}
.po-selected-info strong{color:#0c4a6e}
.btn-deselect-po{background:#bae6fd;color:#0369a1;border:none;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;transition:all .15s}
.btn-deselect-po:hover{background:#7dd3fc;color:#0c4a6e}

/* ── Status badge (REMOVED - Clean design) ── */
/* .status-badge{margin:12px 18px 0;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:9px 14px;display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#856404} */

/* ── Tank Table ── */
.tank-tbl-wrap{padding:14px 18px;max-width:100%}
.tank-tbl{width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed}
.tank-tbl thead th{background:#002F70;color:#fff;padding:8px 6px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
.tank-tbl thead th:nth-child(1){width:5%}
.tank-tbl thead th:nth-child(2){width:35%}
.tank-tbl thead th:nth-child(3){width:40%}
.tank-tbl thead th:nth-child(4){width:20%;text-align:right}
.tank-tbl thead th.r{text-align:right}
.tank-tbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .12s}
.tank-tbl tbody tr:last-child{border-bottom:none}
.tank-tbl tbody tr.highlight-fuel{background:none !important}
.tank-tbl tbody tr:hover{background:#f8fafc}
.tank-tbl td{padding:6px 6px;vertical-align:middle;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ── Fuel name pill (Clean transparent background as requested) ── */
.ft-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;background:transparent !important}

/* ── Liters input ── */
.liters-inp{width:100%;max-width:110px;padding:6px 8px;border:1.5px solid #00264D;border-radius:7px;font-size:12px;font-weight:700;text-align:right;color:#ffffff;background:#002F70;font-family:inherit;transition:border-color .15s,box-shadow .15s}
.liters-inp:focus{border-color:#0056b3;outline:none;box-shadow:0 0 0 3px rgba(0,86,179,.25)}
.liters-inp.has-value{border-color:#22c55e;background:#14532d;color:#ffffff}
.liters-inp::placeholder{color:rgba(255,255,255,0.6)}

/* ── Buttons ── */
.btn-row{display:flex;justify-content:flex-end;align-items:center;gap:12px;padding:14px 18px 18px}

/* Protect txn-btn from global header button overrides */
.txn-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    padding: 7px 14px !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    border: 1px solid transparent !important;
    transition: all .2s ease-in-out !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    box-sizing: border-box !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
}
.txn-btn.primary {
    color: #00264D !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #00264D !important;
}
.txn-btn.primary:hover {
    background-color: #00264D !important;
    background: #00264D !important;
    color: #ffffff !important;
}
.txn-btn.secondary {
    color: #475569 !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #475569 !important;
}
.txn-btn.secondary:hover {
    background-color: #475569 !important;
    background: #475569 !important;
    color: #ffffff !important;
}
.txn-btn.success {
    color: #16a34a !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #16a34a !important;
}
.txn-btn.success:hover {
    background-color: #16a34a !important;
    background: #16a34a !important;
    color: #ffffff !important;
}
.txn-btn.warning {
    color: #b45309 !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #b45309 !important;
}
.txn-btn.warning:hover {
    background-color: #b45309 !important;
    background: #b45309 !important;
    color: #ffffff !important;
}
.txn-btn.danger {
    color: #dc2626 !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #dc2626 !important;
}
.txn-btn.danger:hover {
    background-color: #dc2626 !important;
    background: #dc2626 !important;
    color: #ffffff !important;
}

/* ── Expected PO Card & List ── */
.rec-scroll{max-height:600px;overflow-y:auto;overflow-x:hidden;padding:18px}
.po-card-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:14px;transition:transform .15s,box-shadow .15s;position:relative;max-width:100%}
.po-card-item:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.05)}
.po-card-item.selected{border-color:#0ea5e9;background:#f0f9ff}
.po-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;gap:8px;flex-wrap:wrap}
.po-number{font-family:monospace;font-size:11px;font-weight:700;background:#e0f2fe;color:#0369a1;padding:3px 8px;border-radius:5px;border:1px solid #bae6fd}
.po-date{font-size:10px;color:#94a3b8}
.po-body{font-size:12px;color:#334155;margin-bottom:12px;line-height:1.4}
.po-body div{margin-bottom:4px}
.po-body div strong{color:#00264D}
.po-actions{display:flex;justify-content:flex-end}
.btn-select-po{background:#002F70;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:background .15s}
.btn-select-po:hover{background:#001a42}
.selected-tag{font-size:10px;font-weight:700;color:#0284c7;background:#e0f2fe;padding:4px 8px;border-radius:5px;border:1px solid #bae6fd;display:flex;align-items:center;gap:4px}
</style>

<div class="fde-head">
    <div>
        <h1><i class="fas fa-truck-loading"></i> Record Fuel Delivery</h1>
        <div class="sub">Staff Input Form — Encode actual fuel delivery per tank</div>
    </div>
    <a href="staff_fuel_deliveries_history.php" class="txn-btn secondary">
        <i class="fas fa-history"></i> Delivery Status & History
    </a>
</div>

<?php if ($msg): ?>
<?php
    // Enhanced save success message
    if ($msg_type === 'success' && strpos($msg, '__DELIVERY_SAVED__|') === 0) {
        $parts = explode('|', $msg);
        $saved_count = (int)($parts[1] ?? 0);
        $batch_str = htmlspecialchars($parts[2] ?? '');
        ?>
<div class="alert-b a-ok" id="deliverySavedAlert" style="flex-direction:column;gap:0;padding:0;overflow:hidden;border-radius:10px;">
    <div style="background:#155724;color:#fff;padding:12px 18px;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-check-circle" style="font-size:20px;"></i>
        <div>
            <div style="font-size:15px;font-weight:700;">Delivery Recorded Successfully</div>
            <div style="font-size:12px;opacity:.85;margin-top:2px;"><?= $saved_count ?> tank record(s) saved &mdash; Batch: <strong><?= $batch_str ?></strong></div>
        </div>
        <button onclick="document.getElementById('deliverySavedAlert').remove()" style="margin-left:auto;background:none;border:none;color:#fff;font-size:18px;cursor:pointer;opacity:.7;">&times;</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border-top:1px solid #c3e6cb;">
        <div style="padding:14px 18px;display:flex;align-items:center;gap:10px;border-right:1px solid #c3e6cb;">
            <i class="fas fa-clock" style="color:#856404;font-size:18px;"></i>
            <div>
                <div style="font-size:11px;font-weight:700;color:#856404;text-transform:uppercase;letter-spacing:.4px;">Status</div>
                <div style="font-size:13px;font-weight:700;color:#533f03;">Pending Verification</div>
            </div>
        </div>
        <div style="padding:14px 18px;display:flex;align-items:center;gap:10px;">
            <i class="fas fa-user-check" style="color:#0369a1;font-size:18px;"></i>
            <div>
                <div style="font-size:11px;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:.4px;">Next Step</div>
                <div style="font-size:13px;font-weight:700;color:#0c4a6e;">Waiting for Manager Approval</div>
            </div>
        </div>
    </div>
    <div style="background:#f0fff4;padding:10px 18px;border-top:1px solid #c3e6cb;font-size:12px;color:#155724;">
        <i class="fas fa-info-circle"></i>&nbsp; Inventory will only be updated after the Manager validates and approves this delivery.
    </div>
</div>
<?php } else { ?>
<div class="alert-b <?= $msg_type==='success'?'a-ok':'a-err' ?>">
    <i class="fas fa-<?= $msg_type==='success'?'check-circle':'exclamation-triangle' ?>"></i>
    <div><?= $msg ?></div>
</div>
<?php } ?>
<?php endif; ?>

<!-- ══ TANK MONITORING (VIEW ONLY) ══ -->
<div style="margin-bottom:24px;">

    <!-- Section Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
        <div>
            <h2 style="margin:0;font-size:16px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-database" style="color:#002F70;"></i> Tank Monitoring
            </h2>
            <div style="font-size:11px;color:#64748b;margin-top:3px;font-weight:500;text-transform:uppercase;letter-spacing:.3px;">
                <i class="fas fa-eye" style="font-size:10px;"></i> View Only &mdash; Real-time underground tank levels (<?= count($TANK_CONFIG) ?> tanks)
            </div>
        </div>
        <span style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;">
            <i class="fas fa-lock" style="font-size:10px;"></i> Staff Read Access
        </span>
    </div>

    <?php if (empty($TANK_CONFIG)): ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:40px;text-align:center;color:#94a3b8;">
        <i class="fas fa-database" style="font-size:36px;margin-bottom:12px;display:block;opacity:.3;"></i>
        <div style="font-size:14px;font-weight:600;">No tank configuration defined.</div>
        <div style="font-size:12px;margin-top:4px;">Tank configuration has not been set up for this station yet.</div>
    </div>
    <?php else: ?>

    <!-- Tank Cards Grid — all 17 tanks in order -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;">
    <?php foreach ($TANK_CONFIG as $tank_num => $tank_cfg):
        $ft_raw   = $tank_cfg['fuel_type'];
        $ft_key_l = strtolower(trim($ft_raw));
        $tk       = $tank_inv_map[$ft_key_l] ?? null;

        $tank_label  = $tank_cfg['label'];
        $tank_assign = $tank_cfg['tank'];
        $sty         = $FT_STYLE[$ft_raw] ?? ['color'=>'#334155','icon'=>'fas fa-gas-pump'];

        $level    = $tk ? (float)($tk['current_level'] ?? 0) : 0;
        $capacity = $tk ? (float)($tk['capacity']      ?? 0) : 0;
        $critical = $tk ? (float)($tk['critical_level']?? 0) : 0;
        $reorder  = $tk ? (float)($tk['reorder_level'] ?? 0) : 0;
        $last_upd = $tk ? ($tk['last_updated'] ?? null) : null;
        $pct      = $capacity > 0 ? min(100, round($level / $capacity * 100, 1)) : 0;

        if (!$tk) {
            $bar_color = '#94a3b8'; $status_label = 'No Data'; $status_color = '#64748b';
        } elseif ($level <= $critical) {
            $bar_color = '#dc2626'; $status_label = 'Critical'; $status_color = '#dc2626';
        } elseif ($level <= $reorder) {
            $bar_color = '#d97706'; $status_label = 'Low Stock'; $status_color = '#d97706';
        } else {
            $bar_color = '#16a34a'; $status_label = 'Available'; $status_color = '#16a34a';
        }
    ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:11px 13px;box-shadow:0 1px 3px rgba(0,0,0,.04);">

        <!-- Tank Label + Status -->
        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:3px;">
            <div style="font-size:13px;font-weight:800;color:#00264D;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= htmlspecialchars($tank_label) ?>
            </div>
            <span style="font-size:11px;font-weight:700;color:<?= $status_color ?>;white-space:nowrap;flex-shrink:0;">
                <?= $status_label ?>
            </span>
        </div>

        <!-- Sub-label -->
        <div style="font-size:11px;color:#94a3b8;margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?= htmlspecialchars($ft_raw) ?> &middot; <?= htmlspecialchars($tank_assign) ?>
        </div>

        <!-- Level number -->
        <div style="display:flex;align-items:baseline;gap:3px;margin-bottom:5px;">
            <span style="font-size:20px;font-weight:800;color:<?= $bar_color ?>;font-family:monospace;"><?= number_format($level, 0) ?></span>
            <span style="font-size:12px;color:#64748b;font-weight:600;">L</span>
            <span style="font-size:11px;color:#94a3b8;margin-left:3px;">/ <?= number_format($capacity, 0) ?> L</span>
        </div>

        <!-- Progress bar -->
        <div style="background:#f1f5f9;border-radius:4px;height:5px;overflow:hidden;">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $bar_color ?>;border-radius:4px;"></div>
        </div>

        <!-- Pct -->
        <div style="margin-top:4px;font-size:11px;color:<?= $bar_color ?>;font-weight:700;"><?= $pct ?>% full</div>

    </div>
    <?php endforeach; ?>
    </div>

    <!-- Summary Bar -->
    <?php
        $total_tanks  = count($TANK_CONFIG);
        $critical_cnt = 0; $low_cnt = 0; $ok_cnt = 0; $nodata_cnt = 0;
        foreach ($TANK_CONFIG as $tc) {
            $key = strtolower(trim($tc['fuel_type']));
            $inv = $tank_inv_map[$key] ?? null;
            if (!$inv) { $nodata_cnt++; continue; }
            $lv = (float)($inv['current_level'] ?? 0);
            $cr = (float)($inv['critical_level'] ?? 0);
            $re = (float)($inv['reorder_level'] ?? 0);
            if ($lv <= $cr) $critical_cnt++;
            elseif ($lv <= $re) $low_cnt++;
            else $ok_cnt++;
        }
    ?>
    <div style="margin-top:14px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 18px;display:flex;align-items:center;flex-wrap:wrap;gap:16px;">
        <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;"><i class="fas fa-chart-bar"></i> Summary:</span>
        <span style="display:flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#16a34a;">
            <span style="width:10px;height:10px;border-radius:50%;background:#16a34a;display:inline-block;"></span>
            Available: <?= $ok_cnt ?>
        </span>
        <span style="display:flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#d97706;">
            <span style="width:10px;height:10px;border-radius:50%;background:#d97706;display:inline-block;"></span>
            Low Stock: <?= $low_cnt ?>
        </span>
        <span style="display:flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#dc2626;">
            <span style="width:10px;height:10px;border-radius:50%;background:#dc2626;display:inline-block;"></span>
            Critical: <?= $critical_cnt ?>
        </span>
        <span style="margin-left:auto;font-size:11px;color:#94a3b8;"><i class="fas fa-info-circle"></i> Contact your Manager if any tank is at critical level.</span>
    </div>

    <?php endif; ?>
</div>

<div class="fde-wrap">

    <!-- ══ LEFT: FORM ══ -->
    <div class="fde-card">
        <div class="fde-card-hd">
            <h3><i class="fas fa-gas-pump"></i> Fuel Delivery Form</h3>
            <span style="font-size:11px">Fields marked <span style="color:#ffd54f">*</span> are required</span>
        </div>

        <form method="POST" id="delForm">
            <input type="hidden" name="action" value="record_fuel_delivery">
            <input type="hidden" name="selected_po_id" id="selectedPoId" value="<?= $selected_po ? (int)$selected_po['id'] : 0 ?>">

            <!-- Selected PO Information Banner -->
            <?php if ($selected_po): ?>
            <div class="po-selected-banner" id="poBanner">
                <div class="po-selected-info">
                    <i class="fas fa-file-invoice" style="margin-right:4px;"></i>
                    Using Purchase Order: <strong><?= htmlspecialchars($selected_po['source_ref']) ?></strong><br>
                    Expected: <strong><?= number_format($selected_po['quantity'], 2) ?> L</strong> of <strong><?= htmlspecialchars($selected_po['product']) ?></strong> fuel from <strong><?= htmlspecialchars($selected_po['supplier']) ?></strong>.
                </div>
                <button type="button" class="txn-btn secondary" onclick="deselectPO()">Deselect</button>
            </div>
            <?php endif; ?>

            <!-- Header Fields -->
            <div class="hdr-fields">
                <div class="hf-row hf-2">
                    <div class="fld">
                        <label class="fld-lbl">Delivery Date <span>*</span></label>
                        <input type="date" name="delivery_date" class="fld-inp" value="<?= date('Y-m-d') ?>" required id="delDate">
                    </div>
                    <div class="fld">
                        <label class="fld-lbl">Batch ID</label>
                        <input type="text" class="fld-inp" id="batchPrev" value="Auto-Generated" readonly>
                    </div>
                </div>

                <div class="hf-row">
                    <div class="fld">
                        <label class="fld-lbl">Supplier <span>*</span></label>
                        <input type="text" name="supplier" id="supplierInput" class="fld-inp" value="<?= $selected_po ? htmlspecialchars($selected_po['supplier']) : 'Petron Corporation' ?>" list="supList" required>
                        <datalist id="supList">
                            <option value="Petron Corporation">
                            <option value="Shell Philippines">
                            <option value="Caltex Philippines">
                        </datalist>
                    </div>
                </div>

                <div class="hf-row hf-2">
                    <div class="fld">
                        <label class="fld-lbl">Invoice / DR No. <span>*</span></label>
                        <input type="text" name="invoice_no" class="fld-inp" placeholder="e.g. DR-0610-001" required>
                    </div>
                    <div class="fld">
                        <label class="fld-lbl">Tanker No. <span>*</span></label>
                        <input type="text" name="tanker_number" class="fld-inp" placeholder="e.g. TNK-4521" required>
                    </div>
                </div>

                <div class="hf-row">
                    <div class="fld">
                        <label class="fld-lbl">Remarks</label>
                        <textarea name="remarks" id="remarksInput" class="fld-txt" placeholder="e.g. Shift 1 receiving."><?= $selected_po ? "PO Reference: " . htmlspecialchars($selected_po['source_ref']) . ". " : "" ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Tank Table ── -->
            <div class="tank-tbl-wrap">
                <table class="tank-tbl">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Tank Assigned</th>
                            <th class="r">Liters Delivered</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($TANK_CONFIG as $i => $tk):
                        $ft  = $tk['fuel_type'];
                        $sty = $FT_STYLE[$ft] ?? ['color'=>'#334155','icon'=>'fas fa-gas-pump'];
                        // Highlight style if this row matches the selected PO's fuel product
                        $highlight = ($selected_po && strtolower(trim($selected_po['product'])) === strtolower(trim($ft))) ? 'highlight-fuel' : '';
                    ?>
                    <tr class="<?= $highlight ?>" data-fuel="<?= htmlspecialchars(strtolower(trim($ft))) ?>">
                        <td style="color:#94a3b8;font-size:11px;font-weight:600"><?= $i+1 ?></td>
                        <td>
                            <div class="ft-pill">
                                <?= htmlspecialchars($tk['label']) ?>
                            </div>
                        </td>
                        <td style="font-size:11px;color:#64748b"><?= htmlspecialchars($tk['tank']) ?></td>
                        <td style="text-align:right">
                            <input type="number" step="0.01" min="0" name="liters[<?= $i ?>]"
                                   class="liters-inp"
                                   placeholder="0"
                                   oninput="onLiters(this)">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Buttons -->
            <div class="btn-row">
                <button type="button" class="txn-btn secondary" onclick="resetForm()">
                    <i class="fas fa-undo"></i> Reset
                </button>
                <button type="submit" class="txn-btn primary">
                    <i class="fas fa-save"></i> Save Fuel Delivery
                </button>
            </div>
        </form>
    </div><!-- /.fde-card LEFT -->

    <!-- ══ RIGHT: EXPECTED FUEL DELIVERIES (PO) ══ -->
    <div class="fde-card">
        <div class="fde-card-hd">
            <h3><i class="fas fa-file-invoice"></i> Expected Fuel Deliveries (POs)</h3>
            <span style="font-size:11px">From Admin / Manager Purchase Orders</span>
        </div>
        <div style="padding:14px 18px;">

        <?php
            // Merge both PO sources
            $all_pos_display = [];
            foreach ($expected_pos as $po) {
                $all_pos_display[] = [
                    'id'       => 'fpo_'.$po['id'],
                    'po_no'    => $po['po_number'],
                    'product'  => $po['fuel_type_name'] ?? '—',
                    'liters'   => (float)$po['volume'],
                    'supplier' => $po['supplier_name'],
                    'due_date' => $po['expected_delivery_date'],
                    'status'   => 'Incoming',
                    'source'   => 'fpo',
                    'raw_id'   => $po['id'],
                ];
            }
            foreach ($expected_deliveries as $ed) {
                $all_pos_display[] = [
                    'id'       => 'do_'.$ed['id'],
                    'po_no'    => $ed['source_ref'] ?? $ed['delivery_ref'],
                    'product'  => $ed['product'],
                    'liters'   => (float)$ed['quantity'],
                    'supplier' => $ed['supplier'],
                    'due_date' => $ed['delivery_date'] ?? null,
                    'status'   => 'Expected Delivery',
                    'source'   => 'do',
                    'raw_id'   => $ed['id'],
                ];
            }
        ?>

        <?php if (empty($all_pos_display)): ?>
            <div style="text-align:center;padding:48px 24px;color:#94a3b8">
                <i class="fas fa-clipboard-list" style="font-size:40px;margin-bottom:14px;display:block;opacity:.35;"></i>
                <div style="font-size:14px;font-weight:600;">No expected fuel deliveries at the moment.</div>
                <div style="font-size:12px;margin-top:6px;">Purchase orders created by Admin/Manager will appear here.</div>
            </div>
        <?php else: ?>

            <!-- Summary count -->
            <div style="font-size:12px;color:#64748b;margin-bottom:12px;">
                <i class="fas fa-list-ul"></i> <?= count($all_pos_display) ?> pending purchase order(s) for this station
            </div>

            <!-- PO Comparison Table -->
            <div style="overflow-x:auto;border-radius:8px;border:1px solid #e2e8f0;">
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead>
                    <tr style="background:#002F70;">
                        <th style="padding:9px 10px;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:left;width:22%;">PO No.</th>
                        <th style="padding:9px 10px;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:left;width:22%;">Product</th>
                        <th style="padding:9px 10px;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:right;width:22%;">Expected Liters</th>
                        <th style="padding:9px 10px;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;width:18%;">Status</th>
                        <th style="padding:9px 10px;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;width:16%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_pos_display as $idx => $po): ?>
                <?php
                    $ft_key = $po['product'];
                    $sty = $FT_STYLE[$ft_key] ?? ['color'=>'#334155','icon'=>'fas fa-gas-pump'];
                    $is_selected = ($selected_po && $po['source'] === 'do' && $selected_po['id'] == $po['raw_id']);
                    $row_bg = $idx % 2 === 0 ? '#ffffff' : '#f8fafc';
                    if ($is_selected) $row_bg = '#e0f9f0';
                ?>
                <tr style="background:<?= $row_bg ?>;border-bottom:1px solid #f1f5f9;transition:background .12s;" 
                    onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='<?= $row_bg ?>'">
                    <td style="padding:10px 10px;">
                        <span style="font-family:monospace;font-size:11px;font-weight:700;background:#e0f2fe;color:#0369a1;padding:3px 7px;border-radius:4px;border:1px solid #bae6fd;">
                            <?= htmlspecialchars($po['po_no']) ?>
                        </span>
                        <?php if (!empty($po['due_date'])): ?>
                        <div style="font-size:10px;color:#94a3b8;margin-top:3px;">
                            <i class="fas fa-calendar-alt" style="font-size:9px;"></i>
                            <?= date('M d, Y', strtotime($po['due_date'])) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 10px;">
                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:#1e293b;">
                            <?= htmlspecialchars($po['product']) ?>
                        </span>
                        <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= htmlspecialchars($po['supplier']) ?></div>
                    </td>
                    <td style="padding:10px 10px;text-align:right;">
                        <span style="font-size:14px;font-weight:800;color:#00264D;font-family:monospace;">
                            <?= number_format($po['liters'], 0) ?>
                        </span>
                        <span style="font-size:10px;color:#64748b;"> L</span>
                    </td>
                    <td style="padding:10px 10px;text-align:center;">
                        <?php if ($po['status'] === 'Incoming'): ?>
                        <span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;border:1px solid #86efac;white-space:nowrap;">
                            <i class="fas fa-truck"></i> Incoming
                        </span>
                        <?php else: ?>
                        <span style="background:#fef3c7;color:#d97706;padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;border:1px solid #fcd34d;white-space:nowrap;">
                            <i class="fas fa-hourglass-half"></i> Expected
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 8px;text-align:center;">
                        <?php if ($po['source'] === 'do'): ?>
                            <?php if ($is_selected): ?>
                                <span style="font-size:10px;font-weight:700;color:#059669;display:flex;align-items:center;gap:3px;justify-content:center;">
                                    <i class="fas fa-check-circle"></i> Selected
                                </span>
                            <?php else: ?>
                                <button type="button"
                                    onclick="selectPO(<?= $po['raw_id'] ?>, '<?= htmlspecialchars(addslashes($po['product'])) ?>', '<?= htmlspecialchars(addslashes($po['supplier'])) ?>', '<?= htmlspecialchars(addslashes($po['po_no'])) ?>', <?= $po['liters'] ?>)"
                                    style="background:#fff;color:#002F70;border:1px solid #002F70;padding:4px 10px;border-radius:5px;font-size:10px;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap;">
                                    <i class="fas fa-link"></i> Link
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-size:10px;color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- Legend / Guide -->
            <div style="margin-top:12px;padding:10px 14px;background:#f0f9ff;border-radius:8px;border:1px solid #bae6fd;font-size:11px;color:#0369a1;">
                <i class="fas fa-info-circle"></i>
                <strong>Compare Guide:</strong> Match your actual tanker delivery liters against the Expected Liters above.
                Click <strong>Link</strong> to attach a Purchase Order to your delivery form.
            </div>

        <?php endif; ?>
        </div>
    </div><!-- /.fde-card RIGHT -->

</div><!-- /.fde-wrap -->



<script>
function onLiters(inp) {
    const v = parseFloat(inp.value);
    inp.classList.toggle('has-value', v > 0);
}

function selectPO(id, product, supplier, sourceRef, quantity) {
    // Redirect with parameter to reload page state cleanly
    window.location.href = 'staff_fuel_deliveries.php?po_id=' + id;
}

function deselectPO() {
    window.location.href = 'staff_fuel_deliveries.php';
}

function resetForm() {
    if (!confirm('Reset all form fields?')) return;
    document.getElementById('delForm').reset();
    document.querySelectorAll('.liters-inp').forEach(el => el.classList.remove('has-value'));
    document.getElementById('batchPrev').value = 'Auto-Generated';
    deselectPO();
}

// Preview batch ID when date changes (Fuel prefix: FBATCH-)
document.getElementById('delDate').addEventListener('change', function() {
    const d = this.value.replace(/-/g,'');
    if (d) document.getElementById('batchPrev').value = 'FBATCH-' + d + '-***';
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
