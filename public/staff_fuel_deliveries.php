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
        $_SESSION['success'] = "✅ Saved {$saved} tank delivery record(s). Batch ID: <strong>{$batch_id}</strong>";
    } catch(Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Error: '.$e->getMessage();
    }
    header('Location: staff_fuel_deliveries.php'); exit;
}

// ── Fetch Expected Deliveries (from Admin Finalized POs) ──────────────────
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

include __DIR__ . '/../partials/header.php';
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
<div class="alert-b <?= $msg_type==='success'?'a-ok':'a-err' ?>">
    <i class="fas fa-<?= $msg_type==='success'?'check-circle':'exclamation-triangle' ?>"></i>
    <div><?= $msg ?></div>
</div>
<?php endif; ?>

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
                            <div class="ft-pill" style="color:<?= $sty['color'] ?>;">
                                <i class="<?= $sty['icon'] ?>" style="color:<?= $sty['color'] ?>;font-size:13px;margin-right:4px;"></i>
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
            <h3><i class="fas fa-file-contract"></i> Expected Fuel Deliveries (POs)</h3>
            <span style="font-size:11px">From Admin Purchase Orders</span>
        </div>
        <div class="rec-scroll">
        <?php if (empty($expected_deliveries)): ?>
            <div style="text-align:center;padding:48px;color:#94a3b8">
                <i class="fas fa-clipboard-list" style="font-size:40px;margin-bottom:12px;display:block;opacity:.4"></i>
                <div style="font-size:14px">No expected fuel deliveries at the moment.</div>
                <div style="font-size:12px;margin-top:6px;color:#64748b">Tanan purchase orders gikan ni admin nadawat na o wala pay na-create.</div>
            </div>
        <?php else: ?>
            <?php foreach ($expected_deliveries as $ed):
                $is_cur_selected = ($selected_po && $selected_po['id'] == $ed['id']);
                $ft_key = $ed['product'];
                $sty = $FT_STYLE[$ft_key] ?? ['color' => '#64748b', 'icon' => 'fas fa-gas-pump'];
            ?>
            <div class="po-card-item <?= $is_cur_selected ? 'selected' : '' ?>">
                <div class="po-header">
                    <span class="po-number">PO: <?= htmlspecialchars($ed['source_ref']) ?></span>
                    <span class="po-date"><?= date('M d, Y', strtotime($ed['created_at'])) ?></span>
                </div>
                <div class="po-body">
                    <div>Supplier: <strong><?= htmlspecialchars($ed['supplier']) ?></strong></div>
                    <div>Fuel Type: 
                        <span style="font-size:11px;font-weight:700;padding:4px 8px;border-radius:6px;border:1px solid <?= $sty['color'] ?>;color:<?= $sty['color'] ?>;margin-left:4px;background:transparent;display:inline-flex;align-items:center;gap:5px;">
                            <i class="<?= $sty['icon'] ?>" style="color:<?= $sty['color'] ?>;"></i>
                            <?= htmlspecialchars($ed['product']) ?>
                        </span>
                    </div>
                    <div>Expected liters: <strong><?= number_format($ed['quantity'], 2) ?> L</strong></div>
                    <?php if(!empty($ed['remarks'])): ?>
                        <div style="font-size:11px;color:#64748b;margin-top:6px;background:#f1f5f9;padding:6px;border-radius:4px;">
                            Note: <?= htmlspecialchars($ed['remarks']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="po-actions">
                    <?php if ($is_cur_selected): ?>
                        <span class="selected-tag"><i class="fas fa-check-circle"></i> Currently Selected</span>
                    <?php else: ?>
                        <button type="button" class="txn-btn primary" onclick="selectPO(<?= $ed['id'] ?>, '<?= htmlspecialchars(addslashes($ed['product'])) ?>', '<?= htmlspecialchars(addslashes($ed['supplier'])) ?>', '<?= htmlspecialchars(addslashes($ed['source_ref'])) ?>', <?= (float)$ed['quantity'] ?>)">
                            <i class="fas fa-check"></i> Select Purchase Order
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
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
