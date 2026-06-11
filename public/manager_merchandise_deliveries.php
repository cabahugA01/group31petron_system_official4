<?php
$page_id = 'manager_deliveries';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// Ensure extra columns exist for discrepancy flow
foreach ([
    "ALTER TABLE deliveries_oversight ADD COLUMN discrepancy_type VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN resolution_action VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN resolved_at DATETIME DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN resolved_by INT DEFAULT NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

include __DIR__ . '/../partials/header.php';
?>
<style>
/* === Clean Merchandise Deliveries Design === */
/* ── Workflow Steps Guide ── */
.workflow-container {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 20px;
}
.workflow-steps {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.workflow-title {
    font-size: 11px;
    font-weight: 700;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.step {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #888;
}
.step-num {
    background: #dee2e6;
    color: #6c757d;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 700;
}
.step-arrow {
    color: #ccc;
    font-size: 10px;
}
.step.active {
    color: #002F70;
}
.step.active .step-num {
    background: #002F70;
    color: #fff;
}
.step.done {
    color: #28a745;
}
.step.done .step-num {
    background: #28a745;
    color: #fff;
}

/* ── Modern Tabs ── */
.tab-container {
    display: flex;
    gap: 4px;
    margin-bottom: 16px;
    border-bottom: 2px solid #e9ecef;
}
.tab-btn {
    background: none;
    border: none;
    padding: 10px 20px;
    font-weight: 700;
    color: #6c757d;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.tab-btn:hover {
    color: #002F70;
    border-bottom-color: #dee2e6;
}
.tab-btn.active {
    color: #002F70;
    border-bottom-color: #002F70;
}
.tab-btn .badge {
    background: #fd7e14;
    color: #fff;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: 700;
}

/* ── Plain text badges - NO backgrounds ── */
.sbadge{color:#6c757d !important;font-weight:700 !important;font-size:0.813rem !important;background:none !important;padding:0 !important;border:none !important;text-transform:uppercase;}
.sbadge-pending{color:#fd7e14 !important;}
.sbadge-approved,.sbadge-confirmed,.sbadge-validated{color:#28a745 !important;}
.sbadge-rejected,.sbadge-discrepancy{color:#dc3545 !important;}
.sbadge-pending-resolution{color:#fd7e14 !important;}
.sbadge-awaiting-replacement{color:#17a2b8 !important;}
.sbadge-returned-to-supplier{color:#6c757d !important;}
.sbadge-adjusted{color:#17a2b8 !important;}
.sbadge-closed{color:#6c757d !important;}

/* ── Delivery ref chip ── */
.del-ref{font-family:monospace;font-size:11px;background:#e8f4fd;color:#002F70;padding:3px 7px;border-radius:5px;font-weight:700;display:inline-block;border:1px solid #b8d4f1;}
.cat-tag{font-size:10px;color:#6c757d;display:block;margin-top:2px;}

/* ── Action buttons ── */
.act-wrap{display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex-direction:column;}
.btn-act{padding:6px 12px;border:none;border-radius:6px;cursor:pointer;font-size:0.75rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;transition:all .2s;white-space:nowrap;width:90px;justify-content:center;}
.btn-act:hover{opacity:0.85;transform:translateY(-1px);}
.btn-approve{background:#28a745;color:#fff;} .btn-approve:hover{background:#218838;}
.btn-flag{background:#002F70;color:#fff;} .btn-flag:hover{background:#001a4d;}
.btn-reject{background:#dc3545;color:#fff;} .btn-reject:hover{background:#c82333;}
.btn-adjust{background:#002F70;color:#fff;} .btn-adjust:hover{background:#001a4d;}
.btn-resolve{background:#002F70;color:#fff;} .btn-resolve:hover{background:#001a4d;}
.btn-replacement{background:#002F70;color:#fff;} .btn-replacement:hover{background:#001a4d;}
.btn-view{background:#f0f4ff;color:#002F70;border:1px solid #c5d3f0;} .btn-view:hover{background:#e0e8ff;}

/* ── Table ── */
.table-wrap{overflow-x:auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #e9ecef;overflow:hidden;}
body #del-table{width:100%;border-collapse:collapse;font-size:0.875rem;}
body #del-table thead th{background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;}
body #del-table tbody td{padding:12px 16px;border-bottom:1px solid #e9ecef;vertical-align:middle;color:#212529;}
body #del-table tbody tr:hover td{background:#e3f2fd;}
body #del-table tbody tr:last-child td{border-bottom:none;}

/* ── Filter bar ── */
.filter-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;}
.filter-row .fg{display:flex;flex-direction:column;gap:3px;}
.filter-row .fg label{font-size:10px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;}
.filter-row select,.filter-row input{padding:10px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;min-width:130px;}
.filter-row select:focus,.filter-row input:focus{border-color:#002F70;outline:none;box-shadow:0 0 0 3px rgba(0,47,112,.1);}

/* ── Summary cards ── */
.sum-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:20px;}
.sum-card{background:#fff;border-radius:10px;padding:14px 16px;box-shadow:0 2px 6px rgba(0,0,0,.06);border:1px solid #e9ecef;display:flex;flex-direction:column;gap:3px;}
.sum-card .sc-num{font-size:1.8rem;font-weight:700;line-height:1;}
.sum-card .sc-lbl{font-size:11px;color:#6c757d;font-weight:500;}
.sc-pending .sc-num{color:#002F70;}
.sc-discrepancy .sc-num{color:#fd7e14;}
.sc-approved .sc-num{color:#155724;}
.sc-closed .sc-num{color:#383d41;}

/* ── Modals ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:26px;width:560px;max-width:calc(100vw - 24px);max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);animation:mIn .18s ease;}
.modal-box.wide{width:640px;}
@keyframes mIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:2px solid #e9ecef;}
.modal-title{font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:#adb5bd;line-height:1;}
.modal-close:hover{color:#333;}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;padding-top:12px;border-top:1px solid #e9ecef;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.fld{margin-bottom:12px;}
.fld label{display:block;margin-bottom:4px;font-weight:700;font-size:11px;color:#495057;text-transform:uppercase;letter-spacing:.4px;}
.fld input,.fld textarea,.fld select{width:100%;padding:8px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;font-family:inherit;}
.fld input[readonly]{background:#f8f9fa;color:#6c757d;}
.fld input:focus,.fld textarea:focus,.fld select:focus{border-color:#002F70;outline:none;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
.fld textarea{resize:vertical;}
.ibox{background:#e8f4fd;border-left:4px solid #002F70;border-radius:6px;padding:9px 13px;margin-bottom:12px;font-size:12px;color:#002F70;line-height:1.6;}
.wbox{background:#fff3cd;border-left:4px solid #856404;border-radius:6px;padding:9px 13px;margin-bottom:12px;font-size:12px;color:#856404;line-height:1.6;}
.obox{background:#fff3e0;border-left:4px solid #fd7e14;border-radius:6px;padding:9px 13px;margin-bottom:12px;font-size:12px;color:#7d4e00;line-height:1.6;}
.drow{display:flex;gap:8px;padding:7px 0;border-bottom:1px solid #f0f0f0;font-size:13px;}
.drow:last-child{border-bottom:none;}
.dlbl{font-weight:600;color:#6c757d;min-width:140px;font-size:11px;text-transform:uppercase;letter-spacing:.3px;}
.dval{color:#212529;flex:1;}

/* ── Resolution option cards ── */
.res-options{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;}
.res-opt{border:2px solid #dee2e6;border-radius:8px;padding:12px;cursor:pointer;transition:all .15s;text-align:center;}
.res-opt:hover{border-color:#002F70;background:#f0f4ff;}
.res-opt.selected{border-color:#002F70;background:#e8f4fd;}
.res-opt .ro-icon{font-size:1.4rem;margin-bottom:6px;display:block;}
.res-opt .ro-title{font-size:12px;font-weight:700;color:#002F70;}
.res-opt .ro-desc{font-size:11px;color:#6c757d;margin-top:3px;}

/* ── Toast ── */
.toast{position:fixed;bottom:24px;right:24px;padding:12px 18px;border-radius:8px;color:#fff;font-weight:600;font-size:13px;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.2);display:none;animation:tUp .22s ease;max-width:340px;}
.toast.show{display:block;}
.toast-success{background:#28a745;}
.toast-error{background:#dc3545;}
@keyframes tUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

/* ── Discrepancy banner in detail ── */
.disc-banner{background:#fff3e0;border:1px solid #fd7e14;border-radius:8px;padding:11px 14px;margin-bottom:14px;font-size:13px;color:#7d4e00;display:flex;align-items:flex-start;gap:10px;}

/* Prevent horizontal scroll */
body{overflow-x:hidden !important;max-width:100vw !important;}
</style>

<?php
// Summary counts for cards
$cnt_pending = 0; $cnt_validated = 0; $cnt_rejected = 0;
try {
    $sc = $pdo->prepare("SELECT status FROM deliveries_oversight WHERE station_id=? AND delivery_type='merchandise'");
    $sc->execute([$station_id]);
    foreach ($sc->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $sl = strtolower($s);
        if (in_array($sl, ['pending manager approval','pending manager confirmation','pending validation','pending resolution','awaiting replacement'])) $cnt_pending++;
        elseif (in_array($sl, ['confirmed','approved','validated','ready for stock-in','adjusted'])) $cnt_validated++;
        elseif (in_array($sl, ['discrepancy','rejected','flagged','returned','returned to supplier','closed'])) $cnt_rejected++;
    }
} catch (Exception $e) {}


?>

<div class="page-head">
    <div>
        <a href="manager_dashboard.php" class="btn ghost" style="margin-bottom:6px;display:inline-flex;align-items:center;gap:5px;font-size:12px;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h1 class="h1"><i class="fas fa-truck"></i> Merchandise Deliveries</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Validate staff-encoded deliveries &mdash; Approve, Reject, Return, or Adjust</div>
    </div>
    <div class="header-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button onclick="exportCSV()" class="btn ghost" style="font-size:12px;"><i class="fas fa-file-csv"></i> Export CSV</button>
        <button onclick="exportPDF()" class="btn ghost" style="font-size:12px;"><i class="fas fa-file-pdf"></i> Export PDF</button>
        <button onclick="loadDeliveries()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<!-- Summary Cards -->
<div class="sum-grid" style="margin-bottom:18px;">
    <div class="sum-card sc-pending">
        <div class="sc-num" id="card-pending"><?php echo $cnt_pending; ?></div>
        <div class="sc-lbl"><i class="fas fa-hourglass-half"></i> Pending Deliveries</div>
    </div>
    <div class="sum-card sc-approved">
        <div class="sc-num" id="card-validated"><?php echo $cnt_validated; ?></div>
        <div class="sc-lbl"><i class="fas fa-check-circle"></i> Validated Deliveries</div>
    </div>
    <div class="sum-card sc-discrepancy">
        <div class="sc-num" id="card-rejected"><?php echo $cnt_rejected; ?></div>
        <div class="sc-lbl"><i class="fas fa-exclamation-triangle"></i> Rejected / Returned</div>
    </div>
</div>

<!-- Filters -->
<div class="filter-row" style="margin-bottom:16px;">
    <div class="fg">
        <label>Status</label>
        <select id="f-status" onchange="loadDeliveries()">
            <option value="active">All Active</option>
            <option value="Pending">Pending Verification</option>
            <option value="Pending Resolution">Pending Resolution</option>
            <option value="Awaiting Replacement">Awaiting Replacement</option>
        </select>
    </div>
    <div class="fg">
        <label>Supplier</label>
        <input type="text" id="f-supplier" placeholder="Search supplier...">
    </div>
    <div class="fg">
        <label>From</label>
        <input type="date" id="f-start" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" onchange="loadDeliveries()">
    </div>
    <div class="fg">
        <label>To</label>
        <input type="date" id="f-end" value="<?php echo date('Y-m-d'); ?>" onchange="loadDeliveries()">
    </div>
</div>

<!-- Tabs for Manage vs History vs PO -->
<div class="tab-container">
    <button class="tab-btn active" id="tab-manage" onclick="switchTab('manage')">
        <i class="fas fa-clipboard-check"></i> Manage Deliveries <span class="badge" id="badge-pending">0</span>
    </button>
    <button class="tab-btn" id="tab-history" onclick="switchTab('history')">
        <i class="fas fa-history"></i> Delivery History
    </button>

</div>

<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-boxes"></i> Merchandise Deliveries</div>
        <span id="rec-count" style="font-size:12px;color:#6c757d;"></span>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table id="del-table">
                <thead style="background:#002F70 !important;">
                    <tr style="background:#002F70 !important;">
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Delivery ID</th>
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Type</th>
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Supplier Name</th>
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Product / Fuel</th>
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Qty Delivered</th>
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Date</th>
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Encoded By</th>
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Status</th>
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Remarks / Notes</th>
                        <th style="background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;">Actions</th>
                    </tr>
                </thead>
                <tbody id="del-tbody">
                    <tr><td colspan="10" style="text-align:center;padding:40px;color:#6c757d;"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;display:block;margin-bottom:10px;"></i>Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>



<!-- ══ APPROVE MODAL ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="aprModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-check-circle" style="color:#28a745;"></i> Approve Delivery</div>
            <button class="modal-close" onclick="closeM('aprModal')">&times;</button>
        </div>
        <div class="ibox"><i class="fas fa-info-circle"></i> Approving marks this delivery as <strong>Ready for Stock-In</strong>. Inventory will be updated by Staff during the stock-in step.</div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="apr-ref" readonly></div>
            <div class="fld"><label>Supplier</label><input type="text" id="apr-sup" readonly></div>
        </div>
        <div class="fg2">
            <div class="fld"><label>Product</label><input type="text" id="apr-prod" readonly></div>
            <div class="fld"><label>Qty Delivered</label><input type="text" id="apr-qty" readonly></div>
        </div>
        <div class="fld"><label>Optional Remarks</label><textarea id="apr-rmk" rows="2" placeholder="Optional notes..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('aprModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doApprove()" class="btn" style="background:#28a745;color:#fff;font-weight:700;"><i class="fas fa-check"></i> Approve &amp; Update Inventory</button>
        </div>
    </div>
</div>

<!-- ══ FLAG DISCREPANCY MODAL ════════════════════════════════════════════════ -->
<div class="modal-overlay" id="flagModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Delivery</div>
            <button class="modal-close" onclick="closeM('flagModal')">&times;</button>
        </div>
        <div class="obox"><i class="fas fa-info-circle"></i> Use this when delivery is <strong>invalid, has wrong Batch ID, or cannot be accepted</strong>. Delivery will be flagged and inventory will NOT be updated.</div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="flag-ref" readonly></div>
            <div class="fld"><label>Product</label><input type="text" id="flag-prod" readonly></div>
        </div>
        <div class="fg2">
            <div class="fld"><label>Encoded Qty</label><input type="text" id="flag-qty" readonly></div>
            <div class="fld">
                <label>Discrepancy Type <span style="color:#dc3545;">*</span></label>
                <select id="flag-type">
                    <option value="shortage">Shortage (kulang)</option>
                    <option value="damaged">Damaged items (guba)</option>
                    <option value="both">Both shortage &amp; damaged</option>
                    <option value="wrong_item">Wrong item delivered</option>
                </select>
            </div>
        </div>
        <div class="fld"><label>Discrepancy Details <span style="color:#dc3545;">*</span></label><textarea id="flag-reason" rows="3" placeholder="e.g. Encoded 50 pcs but actual delivery is 45 pcs — 5 pcs kulang. OR 2 pcs guba/damaged."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('flagModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doFlag()" class="btn" style="background:#dc3545;color:#fff;font-weight:700;"><i class="fas fa-times-circle"></i> Reject Delivery</button>
        </div>
    </div>
</div>

<!-- ══ REJECT MODAL (simple reject back to staff) ════════════════════════════ -->
<div class="modal-overlay" id="rejModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-undo" style="color:#856404;"></i> Return to Staff</div>
            <button class="modal-close" onclick="closeM('rejModal')">&times;</button>
        </div>
        <div class="wbox"><i class="fas fa-exclamation-triangle"></i> Delivery will be <strong>returned to Staff for correction</strong>. Use this for encoding errors (wrong DR number, incorrect data). Staff will re-encode and resubmit.</div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="rej-ref" readonly></div>
            <div class="fld"><label>Qty Delivered</label><input type="text" id="rej-qty" readonly></div>
        </div>
        <div class="fld"><label>Product</label><input type="text" id="rej-prod" readonly></div>
        <div class="fld"><label>Return Reason <span style="color:#dc3545;">*</span></label><textarea id="rej-rsn" rows="3" placeholder="Explain why this delivery is being returned (e.g. wrong DR number, incorrect quantity entered, missing Batch ID)..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('rejModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doReject()" class="btn" style="background:#856404;color:#fff;font-weight:700;"><i class="fas fa-undo"></i> Return to Staff</button>
        </div>
    </div>
</div>

<!-- ══ RESOLVE DISCREPANCY MODAL ════════════════════════════════════════════ -->
<div class="modal-overlay" id="resolveModal">
    <div class="modal-box wide">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-tools" style="color:#17a2b8;"></i> Resolve Discrepancy</div>
            <button class="modal-close" onclick="closeM('resolveModal')">&times;</button>
        </div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="res-ref" readonly></div>
            <div class="fld"><label>Product</label><input type="text" id="res-prod" readonly></div>
        </div>
        <div class="fg2">
            <div class="fld"><label>Encoded Qty</label><input type="text" id="res-qty" readonly></div>
            <div class="fld"><label>Staff Remarks</label><input type="text" id="res-remarks" readonly></div>
        </div>
        <div class="fld"><label>Manager Notes</label><input type="text" id="res-notes" readonly></div>

        <div class="fld" style="margin-top:4px;">
            <label>Choose Resolution Action <span style="color:#dc3545;">*</span></label>
            <div class="res-options" id="resOptions">
                <div class="res-opt" onclick="selectRes('return_supplier')" id="ro-return_supplier">
                    <span class="ro-icon">🔄</span>
                    <div class="ro-title">Return to Supplier</div>
                    <div class="ro-desc">Ibalik ang kulang/guba nga items. No inventory update.</div>
                </div>
                <div class="res-opt" onclick="selectRes('replacement')" id="ro-replacement">
                    <span class="ro-icon">📦</span>
                    <div class="ro-title">Request Replacement</div>
                    <div class="ro-desc">Supplier mo-deliver ug kapuli. Awaiting replacement.</div>
                </div>
                <div class="res-opt" onclick="selectRes('adjustment')" id="ro-adjustment">
                    <span class="ro-icon">✏️</span>
                    <div class="ro-title">Adjust Quantity</div>
                    <div class="ro-desc">I-update ang qty sa actual received. Inventory updated.</div>
                </div>
                <div class="res-opt" onclick="selectRes('approve_as_is')" id="ro-approve_as_is">
                    <span class="ro-icon">✅</span>
                    <div class="ro-title">Approve As-Is</div>
                    <div class="ro-desc">Accept original qty. Inventory updated with encoded qty.</div>
                </div>
            </div>
        </div>

        <div class="fld" id="adj-qty-wrap" style="display:none;">
            <label>Actual Received Quantity <span style="color:#dc3545;">*</span></label>
            <input type="number" id="res-adj-qty" min="0.01" step="0.01" placeholder="Enter actual quantity received...">
        </div>
        <div class="fld">
            <label>Resolution Notes <span style="color:#dc3545;">*</span></label>
            <textarea id="res-note" rows="3" placeholder="Explain the resolution decision..."></textarea>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('resolveModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doResolve()" class="btn" style="background:#17a2b8;color:#fff;font-weight:700;" id="res-submit-btn"><i class="fas fa-check"></i> Apply Resolution</button>
        </div>
    </div>
</div>

<!-- ══ STAFF REMARKS MODAL (for Pending Resolution entries) ═════════════════ -->
<div class="modal-overlay" id="remarksModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-comment-alt" style="color:#6c757d;"></i> Add Staff Remarks</div>
            <button class="modal-close" onclick="closeM('remarksModal')">&times;</button>
        </div>
        <div class="obox"><i class="fas fa-info-circle"></i> Add remarks about the discrepancy (e.g. "5 pcs kulang", "2 pcs guba"). Manager will use this to decide resolution.</div>
        <div class="fld"><label>Delivery ID</label><input type="text" id="rmk-ref" readonly></div>
        <div class="fld"><label>Discrepancy Note from Manager</label><input type="text" id="rmk-mgrnote" readonly></div>
        <div class="fld"><label>Your Remarks <span style="color:#dc3545;">*</span></label><textarea id="rmk-text" rows="4" placeholder="e.g. 5 pcs kulang — supplier confirmed shortage. OR 2 pcs guba upon inspection."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('remarksModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doAddRemarks()" class="btn" style="background:#6c757d;color:#fff;font-weight:700;"><i class="fas fa-save"></i> Save Remarks</button>
        </div>
    </div>
</div>

<!-- ══ BATCH APPROVE MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="aprBatchModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-check-circle" style="color:#28a745;"></i> Approve Batch</div>
            <button class="modal-close" onclick="closeM('aprBatchModal')">&times;</button>
        </div>
        <div class="ibox"><i class="fas fa-info-circle"></i> Approving this batch will mark all pending items in it as <strong>Ready for Stock-In</strong>. Inventory will be updated by Staff during stock-in.</div>
        <div class="fld"><label>Batch ID</label><input type="text" id="apr-batch-id" readonly></div>
        <div class="fld"><label>Optional Remarks</label><textarea id="apr-batch-rmk" rows="2" placeholder="Optional notes..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('aprBatchModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doApproveBatch()" class="btn" style="background:#28a745;color:#fff;font-weight:700;"><i class="fas fa-check"></i> Approve Batch</button>
        </div>
    </div>
</div>

<!-- ══ BATCH REJECT MODAL ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="flagBatchModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Batch</div>
            <button class="modal-close" onclick="closeM('flagBatchModal')">&times;</button>
        </div>
        <div class="obox"><i class="fas fa-info-circle"></i> Flag a discrepancy for all pending items in this batch. Status will change to Pending Resolution.</div>
        <div class="fg2">
            <div class="fld"><label>Batch ID</label><input type="text" id="flag-batch-id" readonly></div>
            <div class="fld">
                <label>Discrepancy Type <span style="color:#dc3545;">*</span></label>
                <select id="flag-batch-type">
                    <option value="shortage">Shortage (kulang)</option>
                    <option value="damaged">Damaged items (guba)</option>
                    <option value="both">Both shortage &amp; damaged</option>
                    <option value="wrong_item">Wrong item delivered</option>
                </select>
            </div>
        </div>
        <div class="fld"><label>Discrepancy Details <span style="color:#dc3545;">*</span></label><textarea id="flag-batch-reason" rows="3" placeholder="Explain the reason for rejecting this batch..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('flagBatchModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doFlagBatch()" class="btn" style="background:#dc3545;color:#fff;font-weight:700;"><i class="fas fa-times-circle"></i> Reject Batch</button>
        </div>
    </div>
</div>

<!-- ══ BATCH RETURN TO STAFF MODAL ═════════════════════════════════════════════ -->
<div class="modal-overlay" id="rejBatchModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-undo" style="color:#856404;"></i> Return Batch to Staff</div>
            <button class="modal-close" onclick="closeM('rejBatchModal')">&times;</button>
        </div>
        <div class="wbox"><i class="fas fa-exclamation-triangle"></i> Return this entire batch to the encoding staff for correction. Staff will re-encode and resubmit.</div>
        <div class="fld"><label>Batch ID</label><input type="text" id="rej-batch-id" readonly></div>
        <div class="fld"><label>Reason for Return <span style="color:#dc3545;">*</span></label><textarea id="rej-batch-rsn" rows="3" placeholder="Mandatory: explain why you are returning this batch..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('rejBatchModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doRejectBatch()" class="btn" style="background:#856404;color:#fff;font-weight:700;"><i class="fas fa-undo"></i> Return Batch</button>
        </div>
    </div>
</div>

<!-- ══ BATCH ADJUST MODAL ═════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="adjBatchModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-sliders-h" style="color:#002F70;"></i> Adjust Batch Deliveries</div>
            <button class="modal-close" onclick="closeM('adjBatchModal')">&times;</button>
        </div>
        <div class="ibox"><i class="fas fa-info-circle"></i> Enter the corrected quantities for each delivery in this batch. Status will be updated to Adjusted (Ready for Stock-In).</div>
        <input type="hidden" id="adj-batch-id">
        <div id="adj-batch-items-container" style="max-height: 250px; overflow-y: auto; margin-bottom: 12px; padding: 4px; border: 1px solid #e9ecef; border-radius: 6px;">
            <!-- items rendered dynamically -->
        </div>
        <div class="fld"><label>Adjustment Reason <span style="color:#dc3545;">*</span></label><textarea id="adj-batch-rsn" rows="3" placeholder="Mandatory: explain the reason for this adjustment..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('adjBatchModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doAdjustBatch()" class="btn" style="background:#002F70;color:#fff;font-weight:700;"><i class="fas fa-save"></i> Save Batch Adjustments</button>
        </div>
    </div>
</div>

<!-- ══ REPLACEMENT RECEIVED MODAL ═══════════════════════════════════════════ -->
<div class="modal-overlay" id="replRecvModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-box-open" style="color:#6f42c1;"></i> Confirm Replacement Received</div>
            <button class="modal-close" onclick="closeM('replRecvModal')">&times;</button>
        </div>
        <div class="ibox"><i class="fas fa-info-circle"></i> Confirming that the replacement items have been received from the supplier. <strong>Inventory will be updated</strong> with the full original quantity.</div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="rr-ref" readonly></div>
            <div class="fld"><label>Product</label><input type="text" id="rr-prod" readonly></div>
        </div>
        <div class="fld"><label>Qty to Add to Inventory</label><input type="text" id="rr-qty" readonly></div>
        <div class="fld"><label>Notes (optional)</label><textarea id="rr-note" rows="2" placeholder="e.g. Replacement received from Petron Corp on May 20..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('replRecvModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doReplReceived()" class="btn" style="background:#6f42c1;color:#fff;font-weight:700;"><i class="fas fa-check"></i> Confirm &amp; Update Inventory</button>
        </div>
    </div>
</div>

<!-- ══ ADJUST MODAL ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="adjModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-sliders-h" style="color:#002F70;"></i> Adjust Delivery</div>
            <button class="modal-close" onclick="closeM('adjModal')">&times;</button>
        </div>
        <div class="ibox"><i class="fas fa-info-circle"></i> Minor corrections only. After saving, this delivery will be marked <strong>Adjusted — Ready for Stock-In</strong>. Inventory will be updated by Staff during stock-in. <strong>Reason is mandatory.</strong></div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="adj-ref" readonly></div>
            <div class="fld"><label>Product</label><input type="text" id="adj-prod" readonly></div>
        </div>
        <div class="fg2">
            <div class="fld"><label>Qty Delivered <span style="color:#dc3545;">*</span></label><input type="number" id="adj-qty" min="0.01" step="0.01"></div>
            <div class="fld"><label>Supplier</label><input type="text" id="adj-sup"></div>
        </div>
        <div class="fld"><label>Remarks</label><input type="text" id="adj-rmk" placeholder="Optional remarks..."></div>
        <div class="fld"><label>Adjustment Reason <span style="color:#dc3545;">*</span></label><textarea id="adj-rsn" rows="3" placeholder="Mandatory: explain the reason for this adjustment..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('adjModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doAdjust()" class="btn" style="background:#002F70;color:#fff;font-weight:700;"><i class="fas fa-save"></i> Save Adjustment</button>
        </div>
    </div>
</div>

<!-- ══ DETAIL MODAL ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="dtlModal">
    <div class="modal-box wide">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-file-alt" style="color:#17a2b8;"></i> Delivery Details</div>
            <button class="modal-close" onclick="closeM('dtlModal')">&times;</button>
        </div>
        <div id="dtl-body"><div style="text-align:center;padding:32px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('dtlModal')" class="btn ghost">Close</button>
        </div>
    </div>
</div>



<div class="toast" id="toast"></div>

<script>
var API = '../backend/api/manager_merchandise_deliveries_api.php';
var CID = null, _t = null, _selRes = null;

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    console.log('Manager Merchandise Deliveries: Initializing...');
    try {
        ['aprModal','flagModal','rejModal','resolveModal','remarksModal','replRecvModal','adjModal','dtlModal','aprBatchModal','flagBatchModal','rejBatchModal','adjBatchModal'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el && el.parentNode !== document.body) document.body.appendChild(el);
        });
        document.getElementById('f-supplier').addEventListener('input', function() {
            clearTimeout(_t); _t = setTimeout(loadDeliveries, 400);
        });
        console.log('Manager Merchandise Deliveries: Loading deliveries...');
        loadDeliveries();
    } catch (error) {
        console.error('Initialization error:', error);
        var tb = document.getElementById('del-tbody');
        if (tb) {
            tb.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#dc3545;padding:32px;"><i class="fas fa-exclamation-triangle"></i> Initialization Error: ' + error.message + '</td></tr>';
        }
    }
});

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openM(id)  { document.getElementById(id).classList.add('open'); }
function closeM(id) { document.getElementById(id).classList.remove('open'); }
document.addEventListener('click', function(e) {
    ['aprModal','flagModal','rejModal','resolveModal','remarksModal','replRecvModal','adjModal','dtlModal','poModal','poDtlModal','aprBatchModal','flagBatchModal','rejBatchModal','adjBatchModal'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && e.target === el) closeM(id);
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') ['aprModal','flagModal','rejModal','resolveModal','remarksModal','replRecvModal','adjModal','dtlModal','poModal','poDtlModal','aprBatchModal','flagBatchModal','rejBatchModal','adjBatchModal'].forEach(closeM);
});

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast toast-' + (type || 'success') + ' show';
    setTimeout(function() { t.classList.remove('show'); }, 4000);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function h(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function j(s) { return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"'); }
function dt(s) { return s ? String(s).replace('T',' ').substring(0,16) : '<span style="color:#adb5bd">—</span>'; }
function dr(l,v){ return '<div class="drow"><span class="dlbl">'+l+'</span><span class="dval">'+v+'</span></div>'; }

function badgeHtml(status) {
    var cls = 'sbadge sbadge-' + status.toLowerCase().replace(/ /g,'\\ ');
    return '<span class="' + cls + '">' + h(status) + '</span>';
}

// ── Status bucket mapping ─────────────────────────────────────────────────────
function getDisplayStatus(raw) {
    var s = (raw || '').toLowerCase();
    if (s.includes('pending manager') || s === 'pending validation' || s === 'pending') return 'Pending';
    if (s === 'pending resolution') return 'Pending Resolution';
    if (s === 'awaiting replacement') return 'Awaiting Replacement';
    if (s === 'ready for stock-in') return 'Ready for Stock-In';
    if (s === 'confirmed' || s === 'approved' || s === 'validated') return 'Ready for Stock-In';
    if (s === 'adjusted') return 'Adjusted';
    if (s === 'returned') return 'Returned to Staff';
    if (s === 'returned to supplier') return 'Returned to Supplier';
    if (s === 'discrepancy' || s === 'flagged' || s === 'rejected') return 'Rejected';
    if (s === 'closed') return 'Closed';
    return raw;
}

// ── Load deliveries ───────────────────────────────────────────────────────────
var currentTab = 'manage';

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.tab-btn').forEach(function(el) {
        el.classList.remove('active');
    });
    var activeBtn = document.getElementById('tab-' + tab);
    if (activeBtn) activeBtn.classList.add('active');
    
    // Show/hide filter row and delivery table based on tab
    var filterRow = document.querySelector('.filter-row');
    var delPanel  = document.querySelector('.inv-card');
    if (filterRow) filterRow.style.display = '';
    if (delPanel)  delPanel.style.display  = '';

    // Update status filter dropdown options
    var sf = document.getElementById('f-status');
    if (tab === 'manage') {
        sf.innerHTML = '<option value="active">All Active</option>'
                     + '<option value="Pending">Pending Verification</option>'
                     + '<option value="Pending Resolution">Pending Resolution</option>'
                     + '<option value="Awaiting Replacement">Awaiting Replacement</option>';
    } else {
        sf.innerHTML = '<option value="history">All Processed</option>'
                     + '<option value="Ready for Stock-In">Approved (Ready for Stock-In)</option>'
                     + '<option value="Adjusted">Adjusted (Ready for Stock-In)</option>'
                     + '<option value="Returned to Staff">Returned to Staff</option>'
                     + '<option value="Returned to Supplier">Returned to Supplier</option>'
                     + '<option value="Rejected">Rejected</option>';
    }
    loadDeliveries();
}

function loadDeliveries() {
    console.log('loadDeliveries() called, currentTab:', currentTab);
    var status   = document.getElementById('f-status').value;
    var supplier = document.getElementById('f-supplier').value;
    var start    = document.getElementById('f-start').value;
    var end      = document.getElementById('f-end').value;

    var url = API + '?action=list&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
    if (supplier) url += '&supplier=' + encodeURIComponent(supplier);

    console.log('Fetching from:', url);

    var tb = document.getElementById('del-tbody');
    tb.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;color:#6c757d;"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;display:block;margin-bottom:10px;"></i>Loading...</td></tr>';

    fetch(url).then(function(r){
        if (!r.ok) {
            console.error('API response not OK:', r.status, r.statusText);
            throw new Error('HTTP ' + r.status + ': ' + r.statusText);
        }
        return r.json();
    }).then(function(res) {
        console.log('API Response:', res);
        if (!res.success) {
            console.error('API returned error:', res.message);
            tb.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#dc3545;padding:32px;">' + h(res.message) + '</td></tr>';
            return;
        }

        var allRows = res.data || [];
        
        // Count active pending items for tab badge
        var pendingCount = 0;
        allRows.forEach(function(d) {
            var ds = getDisplayStatus(d.status);
            if (ds === 'Pending' || ds === 'Pending Resolution' || ds === 'Awaiting Replacement') {
                pendingCount++;
            }
        });
        document.getElementById('badge-pending').textContent = pendingCount;

        // Filter rows based on active tab and selected status dropdown value
        var rows = allRows.filter(function(d) {
            var ds = getDisplayStatus(d.status);
            
            if (currentTab === 'manage') {
                if (ds !== 'Pending' && ds !== 'Pending Resolution' && ds !== 'Awaiting Replacement') {
                    return false;
                }
                if (status === 'active') return true;
                return ds === status;
            } else {
                var historyBuckets = ['Ready for Stock-In','Adjusted','Returned to Staff','Returned to Supplier','Rejected','Closed'];
                if (historyBuckets.indexOf(ds) === -1) return false;
                if (status === 'history') return true;
                return ds === status;
            }
        });

        document.getElementById('rec-count').textContent = rows.length + ' record(s)';

        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:48px;color:#6c757d;"><i class="fas fa-truck" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:.3;"></i><strong>No deliveries found</strong><br><span style="font-size:12px;">Try adjusting the filters above.</span></td></tr>';
            return;
        }

        // Group rows by batch_id
        var batches = {};
        var batchOrder = [];
        rows.forEach(function(d) {
            var bId = d.batch_id || ('NOBATCH-' + d.id);
            if (!batches[bId]) {
                batches[bId] = [];
                batchOrder.push(bId);
            }
            batches[bId].push(d);
        });
        window.currentBatches = batches;

        var out = '';
        batchOrder.forEach(function(bId) {
            var items = batches[bId];
            var firstItem = items[0];
            var ds = getDisplayStatus(firstItem.status);

            // Compute total batch quantity and group suppliers/encoders
            var suppliers = [];
            var encoders = [];
            items.forEach(function(item) {
                if (item.supplier_name && !suppliers.includes(item.supplier_name)) {
                    suppliers.push(item.supplier_name);
                }
                if (item.encoded_by_name && !encoders.includes(item.encoded_by_name)) {
                    encoders.push(item.encoded_by_name);
                }
            });

            var supplierDisplay = suppliers.join(', ');
            if (supplierDisplay.length > 30) {
                supplierDisplay = supplierDisplay.substring(0, 28) + '...';
            }
            var encoderDisplay = encoders.join(', ');

            // Type badge
            var isFuel = (firstItem.delivery_type === 'fuel');
            var typeBadge = isFuel
                ? '<span style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;border-radius:12px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap;">⛽ Fuel</span>'
                : '<span style="background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:12px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap;">📦 Merch</span>';

            // Status badge
            var badgeCls = {
                'Pending':              'sbadge-pending',
                'Pending Resolution':   'sbadge-pending-resolution',
                'Awaiting Replacement': 'sbadge-awaiting-replacement',
                'Ready for Stock-In':   'sbadge-approved',
                'Adjusted':             'sbadge-adjusted',
                'Returned to Staff':    'sbadge-pending-resolution',
                'Returned to Supplier': 'sbadge-returned-to-supplier',
                'Rejected':             'sbadge-rejected',
                'Closed':               'sbadge-closed',
            }[ds] || 'sbadge-pending';
            var dsLabel = ds === 'Ready for Stock-In' ? '✅ Ready for Stock-In' : ds;
            var badge = '<span class="sbadge ' + badgeCls + '">' + h(dsLabel) + '</span>';

            // Products and Quantities line-by-line list
            var productsHtml = items.map(function(item) {
                return '<div style="padding:4px 0; border-bottom:1px solid #f1f5f9; min-height:36px; display:flex; flex-direction:column; justify-content:center;">'
                     + '<strong style="color:#334155;">' + h(item.product_name || '—') + '</strong>'
                     + '<span style="font-size:10px;color:#94a3b8;font-family:monospace;">' + h(item.delivery_ref) + '</span>'
                     + '</div>';
            }).join('');

            var qtyHtml = items.map(function(item) {
                return '<div style="padding:4px 0; border-bottom:1px solid #f1f5f9; min-height:36px; display:flex; align-items:center; justify-content:flex-end; font-weight:700; color:#1e293b;">'
                     + parseFloat(item.quantity_delivered||0).toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:2})
                     + '&nbsp;<span style="font-size:11px;color:#64748b;">' + h(item.unit||'pcs') + '</span>'
                     + '</div>';
            }).join('');

            // Batch Action Buttons (only for Pending batch)
            var batchActs = '';
            var isVirtualBatch = bId.startsWith('NOBATCH-');
            var batchIdLabel = isVirtualBatch ? firstItem.delivery_ref : bId;

            if (ds === 'Pending') {
                batchActs = '<button class="btn-act btn-approve" onclick="openAprBatch(\'' + j(batchIdLabel) + '\')"><i class="fas fa-check"></i> Approve</button>'
                          + '<button class="btn-act btn-reject"  onclick="openFlagBatch(\'' + j(batchIdLabel) + '\')"><i class="fas fa-times-circle"></i> Reject</button>'
                          + '<button class="btn-act btn-adjust"  onclick="openAdjBatch(\'' + j(batchIdLabel) + '\')"><i class="fas fa-sliders-h"></i> Adjust</button>';
            }
            batchActs += '<button class="btn-act btn-view" onclick="openBatchDtl(\'' + j(batchIdLabel) + '\')"><i class="fas fa-eye"></i> View</button>';

            // Render Batch Row
            out += '<tr style="background:#ffffff;">'
                + '<td><strong style="color: #0f172a;"><i class="fas fa-folder-open" style="color: #fd7e14; margin-right: 4px;"></i> ' + h(batchIdLabel) + '</strong></td>'
                + '<td>' + typeBadge + '</td>'
                + '<td><strong style="color: #334155;">' + h(supplierDisplay || '—') + '</strong></td>'
                + '<td style="padding:0 16px;">' + productsHtml + '</td>'
                + '<td style="padding:0 16px;">' + qtyHtml + '</td>'
                + '<td style="font-size:12px;color:#64748b;">' + dt(firstItem.delivery_date) + '</td>'
                + '<td style="font-size:12px;color:#475569;">' + h(encoderDisplay || '—') + '</td>'
                + '<td>' + badge + '</td>'
                + '<td style="font-size:12px;color:#64748b;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + h(firstItem.remarks || '') + '</td>'
                + '<td><div class="act-wrap" style="flex-direction:row;gap:4px;">' + batchActs + '</div></td>'
                + '</tr>';
        });
        tb.innerHTML = out;
    }).catch(function(err) {
        console.error('Fetch error:', err);
        tb.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#dc3545;padding:32px;"><i class="fas fa-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:12px;"></i><strong>Error loading deliveries</strong><br><span style="font-size:12px;color:#6c757d;">' + h(err.message) + '</span><br><button onclick="loadDeliveries()" class="btn" style="margin-top:12px;"><i class="fas fa-sync-alt"></i> Retry</button></td></tr>';
    });
}

// ── BATCH OPERATION HANDLERS ───────────────────────────────────────────────────
function openAprBatch(batchId) {
    document.getElementById('apr-batch-id').value = batchId;
    document.getElementById('apr-batch-rmk').value = '';
    openM('aprBatchModal');
}
function doApproveBatch() {
    var batchId = document.getElementById('apr-batch-id').value;
    var reason = document.getElementById('apr-batch-rmk').value;
    fetch(API + '?action=approve_batch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ batch_id: batchId, reason: reason })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        closeM('aprBatchModal');
        toast(res.message, res.success ? 'success' : 'error');
        if (res.success) loadDeliveries();
    });
}

function openFlagBatch(batchId) {
    document.getElementById('flag-batch-id').value = batchId;
    document.getElementById('flag-batch-reason').value = '';
    document.getElementById('flag-batch-type').value = 'shortage';
    openM('flagBatchModal');
}
function doFlagBatch() {
    var batchId = document.getElementById('flag-batch-id').value;
    var reason = document.getElementById('flag-batch-reason').value.trim();
    var dtype = document.getElementById('flag-batch-type').value;
    if (!reason) { toast('Discrepancy details are required.', 'error'); return; }
    fetch(API + '?action=flag_batch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ batch_id: batchId, reason: reason, discrepancy_type: dtype })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        closeM('flagBatchModal');
        toast(res.message, res.success ? 'success' : 'error');
        if (res.success) loadDeliveries();
    });
}

function openRejBatch(batchId) {
    document.getElementById('rej-batch-id').value = batchId;
    document.getElementById('rej-batch-rsn').value = '';
    openM('rejBatchModal');
}
function doRejectBatch() {
    var batchId = document.getElementById('rej-batch-id').value;
    var reason = document.getElementById('rej-batch-rsn').value.trim();
    if (!reason) { toast('Reason for return is required.', 'error'); return; }
    fetch(API + '?action=reject_batch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ batch_id: batchId, reason: reason })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        closeM('rejBatchModal');
        toast(res.message, res.success ? 'success' : 'error');
        if (res.success) loadDeliveries();
    });
}

function openAdjBatch(batchId) {
    var items = window.currentBatches[batchId] || [];
    document.getElementById('adj-batch-id').value = batchId;
    document.getElementById('adj-batch-rsn').value = '';
    var container = document.getElementById('adj-batch-items-container');
    container.innerHTML = '';
    items.forEach(function(item) {
        var div = document.createElement('div');
        div.className = 'fg2';
        div.style.marginBottom = '8px';
        div.innerHTML = '<div class="fld" style="margin-bottom:0;"><label>' + h(item.product_name) + ' (' + h(item.delivery_ref) + ')</label><input type="text" value="' + h(item.supplier_name) + '" readonly style="font-size:11px;"></div>'
                      + '<div class="fld" style="margin-bottom:0;"><label>Qty Delivered (' + h(item.unit || 'pcs') + ')</label><input type="number" class="adj-batch-item-qty" data-id="' + item.id + '" value="' + parseFloat(item.quantity_delivered) + '" min="0.01" step="0.01"></div>';
        container.appendChild(div);
    });
    openM('adjBatchModal');
}
function doAdjustBatch() {
    var batchId = document.getElementById('adj-batch-id').value;
    var reason = document.getElementById('adj-batch-rsn').value.trim();
    if (!reason) { toast('Adjustment reason is mandatory.', 'error'); return; }

    var adjustments = [];
    var inputs = document.querySelectorAll('.adj-batch-item-qty');
    for (var i = 0; i < inputs.length; i++) {
        var id = inputs[i].getAttribute('data-id');
        var qty = parseFloat(inputs[i].value);
        if (isNaN(qty) || qty <= 0) {
            toast('All quantities must be greater than 0.', 'error');
            return;
        }
        adjustments.push({ id: id, quantity: qty });
    }

    fetch(API + '?action=adjust_batch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ batch_id: batchId, adjustments: adjustments, reason: reason })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        closeM('adjBatchModal');
        toast(res.message, res.success ? 'success' : 'error');
        if (res.success) loadDeliveries();
    });
}

// ── APPROVE ───────────────────────────────────────────────────────────────────
function openApr(id, ref, prod, qty, sup, dtype) {
    CID = id;
    document.getElementById('apr-ref').value  = ref;
    document.getElementById('apr-prod').value = prod;
    document.getElementById('apr-qty').value  = qty;
    document.getElementById('apr-sup').value  = sup;
    document.getElementById('apr-rmk').value  = '';
    // Update info box based on type
    var ibox = document.querySelector('#aprModal .ibox');
    if (ibox) {
        if (dtype === 'fuel') {
            ibox.innerHTML = '<i class="fas fa-gas-pump"></i> Approving marks this fuel delivery as <strong>Ready for Stock-In</strong>. Inventory will be updated by Staff during the fuel stock-in step.';
        } else {
            ibox.innerHTML = '<i class="fas fa-info-circle"></i> Approving marks this delivery as <strong>Ready for Stock-In</strong>. Inventory will be updated by Staff during the stock-in step.';
        }
    }
    openM('aprModal');
}
function doApprove() {
    fetch(API + '?action=approve', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CID,reason:document.getElementById('apr-rmk').value})})
    .then(function(r){return r.json();}).then(function(res){closeM('aprModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

// ── FLAG DISCREPANCY ──────────────────────────────────────────────────────────
function openFlag(id, ref, prod, qty) {
    CID = id;
    document.getElementById('flag-ref').value    = ref;
    document.getElementById('flag-prod').value   = prod;
    document.getElementById('flag-qty').value    = qty;
    document.getElementById('flag-reason').value = '';
    document.getElementById('flag-type').value   = 'shortage';
    openM('flagModal');
}
function doFlag() {
    var reason = document.getElementById('flag-reason').value.trim();
    var dtype  = document.getElementById('flag-type').value;
    if (!reason) { toast('Discrepancy details are required.','error'); return; }
    fetch(API + '?action=flag_discrepancy', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CID,reason:reason,discrepancy_type:dtype})})
    .then(function(r){return r.json();}).then(function(res){closeM('flagModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

// ── REJECT ────────────────────────────────────────────────────────────────────
function openRej(id, ref, prod, qty) {
    CID = id;
    document.getElementById('rej-ref').value  = ref;
    document.getElementById('rej-prod').value = prod;
    document.getElementById('rej-qty').value  = qty;
    document.getElementById('rej-rsn').value  = '';
    openM('rejModal');
}
function doReject() {
    var rsn = document.getElementById('rej-rsn').value.trim();
    if (!rsn) { toast('Rejection reason is required.','error'); return; }
    fetch(API + '?action=reject', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CID,reason:rsn})})
    .then(function(r){return r.json();}).then(function(res){closeM('rejModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

// ── RESOLVE DISCREPANCY ───────────────────────────────────────────────────────
function selectRes(val) {
    _selRes = val;
    document.querySelectorAll('.res-opt').forEach(function(el) { el.classList.remove('selected'); });
    var el = document.getElementById('ro-' + val);
    if (el) el.classList.add('selected');
    document.getElementById('adj-qty-wrap').style.display = (val === 'adjustment') ? 'block' : 'none';
}
function openResolve(id, ref, prod, qty, remarks, mgrNote) {
    CID = id; _selRes = null;
    document.getElementById('res-ref').value     = ref;
    document.getElementById('res-prod').value    = prod;
    document.getElementById('res-qty').value     = qty;
    document.getElementById('res-remarks').value = remarks || '—';
    document.getElementById('res-notes').value   = mgrNote || '—';
    document.getElementById('res-note').value    = '';
    document.getElementById('res-adj-qty').value = '';
    document.getElementById('adj-qty-wrap').style.display = 'none';
    document.querySelectorAll('.res-opt').forEach(function(el) { el.classList.remove('selected'); });
    openM('resolveModal');
}
function doResolve() {
    if (!_selRes) { toast('Please select a resolution action.','error'); return; }
    var note = document.getElementById('res-note').value.trim();
    if (!note) { toast('Resolution notes are required.','error'); return; }
    var payload = {id:CID, resolution:_selRes, resolution_note:note};
    if (_selRes === 'adjustment') {
        var aq = parseFloat(document.getElementById('res-adj-qty').value);
        if (!(aq > 0)) { toast('Adjusted quantity must be greater than 0.','error'); return; }
        payload.adjusted_qty = aq;
    }
    fetch(API + '?action=resolve_discrepancy', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(function(r){return r.json();}).then(function(res){closeM('resolveModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

// ── ADD STAFF REMARKS ─────────────────────────────────────────────────────────
function openRemarks(id, ref, mgrNote) {
    CID = id;
    document.getElementById('rmk-ref').value     = ref;
    document.getElementById('rmk-mgrnote').value = mgrNote || '—';
    document.getElementById('rmk-text').value    = '';
    openM('remarksModal');
}
function doAddRemarks() {
    var txt = document.getElementById('rmk-text').value.trim();
    if (!txt) { toast('Remarks are required.','error'); return; }
    fetch(API + '?action=add_staff_remarks', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CID,remarks:txt})})
    .then(function(r){return r.json();}).then(function(res){closeM('remarksModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

// ── REPLACEMENT RECEIVED ──────────────────────────────────────────────────────
function openReplReceived(id, ref, prod, qty) {
    CID = id;
    document.getElementById('rr-ref').value  = ref;
    document.getElementById('rr-prod').value = prod;
    document.getElementById('rr-qty').value  = qty;
    document.getElementById('rr-note').value = '';
    openM('replRecvModal');
}
function doReplReceived() {
    fetch(API + '?action=replacement_received', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CID,note:document.getElementById('rr-note').value})})
    .then(function(r){return r.json();}).then(function(res){closeM('replRecvModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

// ── ADJUST ────────────────────────────────────────────────────────────────────
function openAdj(id, ref, prod, qty, sup, rmk) {
    CID = id;
    document.getElementById('adj-ref').value  = ref;
    document.getElementById('adj-prod').value = prod;
    document.getElementById('adj-qty').value  = qty;
    document.getElementById('adj-sup').value  = sup;
    document.getElementById('adj-rmk').value  = rmk;
    document.getElementById('adj-rsn').value  = '';
    openM('adjModal');
}
function doAdjust() {
    var rsn = document.getElementById('adj-rsn').value.trim();
    var qty = parseFloat(document.getElementById('adj-qty').value);
    if (!rsn)    { toast('Adjustment reason is mandatory.','error'); return; }
    if (!(qty>0)){ toast('Quantity must be greater than 0.','error'); return; }
    fetch(API + '?action=adjust', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CID,quantity_delivered:qty,supplier_name:document.getElementById('adj-sup').value,remarks:document.getElementById('adj-rmk').value,reason:rsn})})
    .then(function(r){return r.json();}).then(function(res){closeM('adjModal');toast(res.message,res.success?'success':'error');if(res.success)loadDeliveries();});
}

// ── DETAIL VIEW ───────────────────────────────────────────────────────────────
function openDtl(id) {
    document.getElementById('dtl-body').innerHTML = '<div style="text-align:center;padding:32px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    openM('dtlModal');
    fetch(API + '?action=get_detail&id=' + id).then(function(r){return r.json();}).then(function(res){
        if (!res.success) { document.getElementById('dtl-body').innerHTML = '<p style="color:#dc3545;padding:16px;">' + h(res.message) + '</p>'; return; }
        var d = res.data;
        var ds = getDisplayStatus(d.status);
        var html = '';
        if (ds === 'Pending Resolution' || ds === 'Awaiting Replacement') {
            html += '<div class="disc-banner"><i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0;font-size:16px;"></i><div><strong>Discrepancy Flagged</strong><br>' + h(d.manager_reason||d.admin_notes||'') + '</div></div>';
        }
        var isFuel = (d.delivery_type === 'fuel');
        var typeBadge = isFuel
            ? '<span style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;border-radius:12px;padding:2px 8px;font-size:11px;font-weight:700;">⛽ Fuel</span>'
            : '<span style="background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:12px;padding:2px 8px;font-size:11px;font-weight:700;">📦 Merchandise</span>';
        html += dr('Delivery ID','<span class="del-ref">'+h(d.delivery_ref)+'</span>')
            + dr('Type', typeBadge)
            + dr('Supplier','<strong>'+h(d.supplier_name||'—')+'</strong>')
            + dr('Product / Fuel',h(d.product_name||'—'))
            + dr('Qty Delivered','<strong style="color:#155724;">'+parseFloat(d.quantity_delivered||0).toLocaleString()+'</strong> '+h(d.unit||''))
            + dr('Date',dt(d.delivery_date))
            + dr('DR Number',h(d.dr_number||'—'))
            + dr('Encoded By',h(d.encoded_by_name||'—'))
            + dr('Status','<span class="sbadge sbadge-'+ds.toLowerCase().replace(/ /g,'-')+'">'+h(ds)+'</span>')
            + dr('Staff Remarks',h(d.remarks||'—'))
            + dr('Manager Notes',h(d.manager_reason||d.admin_notes||'—'))
            + dr('Manager',h(d.manager_name||'—'))
            + dr('Action At',dt(d.manager_action_at));
        if (d.resolution_action) html += dr('Resolution',h(d.resolution_action));
        document.getElementById('dtl-body').innerHTML = html;
    });
}

function openBatchDtl(batchId) {
    var items = window.currentBatches[batchId] || [];
    var html = '<div style="margin-bottom:12px;"><h4 style="color:#002F70;margin-bottom:8px;">Batch: ' + h(batchId) + '</h4></div>';
    html += '<table style="width:100%;border-collapse:collapse;font-size:12px;text-align:left;">'
          + '<thead style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">'
          + '<tr>'
          + '<th style="padding:8px;">Ref</th>'
          + '<th style="padding:8px;">Product</th>'
          + '<th style="padding:8px;text-align:right;">Qty</th>'
          + '<th style="padding:8px;">Status</th>'
          + '<th style="padding:8px;">Remarks</th>'
          + '</tr>'
          + '</thead>'
          + '<tbody>';
    items.forEach(function(item) {
        var itemDs = getDisplayStatus(item.status);
        html += '<tr style="border-bottom:1px solid #f1f5f9;">'
              + '<td style="padding:8px;font-family:monospace;font-weight:700;">' + h(item.delivery_ref) + '</td>'
              + '<td style="padding:8px;font-weight:600;">' + h(item.product_name) + '</td>'
              + '<td style="padding:8px;text-align:right;font-weight:700;">' + parseFloat(item.quantity_delivered).toLocaleString() + ' ' + h(item.unit || 'pcs') + '</td>'
              + '<td style="padding:8px;"><span class="sbadge sbadge-' + itemDs.toLowerCase().replace(/ /g,'-') + '">' + h(itemDs) + '</span></td>'
              + '<td style="padding:8px;color:#64748b;">' + h(item.remarks || '—') + '</td>'
              + '</tr>';
    });
    html += '</tbody></table>';
    document.getElementById('dtl-body').innerHTML = html;
    openM('dtlModal');
}

// ── EXPORT ────────────────────────────────────────────────────────────────────
function exportCSV() {
    var s=document.getElementById('f-status').value, sup=document.getElementById('f-supplier').value;
    var st=document.getElementById('f-start').value, en=document.getElementById('f-end').value;
    window.open(API+'?action=export_excel&start='+encodeURIComponent(st)+'&end='+encodeURIComponent(en)+'&status='+encodeURIComponent(s)+'&supplier='+encodeURIComponent(sup),'_blank');
    toast('Exporting to CSV...','success');
}
function exportPDF() {
    var s=document.getElementById('f-status').value, sup=document.getElementById('f-supplier').value;
    var st=document.getElementById('f-start').value, en=document.getElementById('f-end').value;
    window.open(API+'?action=export_pdf&start='+encodeURIComponent(st)+'&end='+encodeURIComponent(en)+'&status='+encodeURIComponent(s)+'&supplier='+encodeURIComponent(sup),'_blank');
    toast('Exporting to PDF...','success');
}


</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
