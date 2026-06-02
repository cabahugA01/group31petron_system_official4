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
#del-table{width:100%;border-collapse:collapse;font-size:0.875rem;}
#del-table th{background:#002F70 !important;color:#fff !important;padding:14px 16px !important;font-weight:600 !important;font-size:0.813rem !important;text-transform:uppercase !important;letter-spacing:.3px !important;border:none !important;white-space:nowrap;}
#del-table td{padding:12px 16px;border-bottom:1px solid #e9ecef;vertical-align:middle;color:#212529;}
#del-table tbody tr:hover td{background:#e3f2fd;}
#del-table tbody tr:last-child td{border-bottom:none;}

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

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-truck"></i> Merchandise Deliveries</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Merchandise deliveries &mdash; Approve, Flag Discrepancy, or Resolve</div>
    </div>
    <div class="header-actions">
        <button onclick="loadDeliveries()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<!-- Filters -->
<div class="filter-row" style="margin-bottom:16px;">
    <div class="fg">
        <label>Status</label>
        <select id="f-status" onchange="loadDeliveries()">
            <option value="">All Statuses</option>
            <option value="Pending">Pending Approval</option>
            <option value="Pending Resolution">Pending Resolution</option>
            <option value="Awaiting Replacement">Awaiting Replacement</option>
            <option value="Approved">Approved / Confirmed</option>
            <option value="Adjusted">Adjusted</option>
            <option value="Returned to Supplier">Returned to Supplier</option>
            <option value="Rejected">Rejected</option>
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

<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-boxes"></i> Merchandise Deliveries</div>
        <span id="rec-count" style="font-size:12px;color:#6c757d;"></span>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="table" id="del-table">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <th>Type</th>
                        <th>Supplier Name</th>
                        <th>Product / Fuel</th>
                        <th>Qty Delivered</th>
                        <th>Date</th>
                        <th>Encoded By</th>
                        <th>Status</th>
                        <th>Remarks / Notes</th>
                        <th>Actions</th>
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
        <div class="ibox"><i class="fas fa-info-circle"></i> Approving will <strong>automatically update inventory</strong> — delivered quantity added to stock.</div>
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
            <div class="modal-title"><i class="fas fa-exclamation-triangle" style="color:#fd7e14;"></i> Flag Discrepancy</div>
            <button class="modal-close" onclick="closeM('flagModal')">&times;</button>
        </div>
        <div class="obox"><i class="fas fa-info-circle"></i> Use this when delivery is <strong>incomplete or has damaged items</strong>. Inventory will NOT be updated until resolved.</div>
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
            <button type="button" onclick="doFlag()" class="btn" style="background:#fd7e14;color:#fff;font-weight:700;"><i class="fas fa-flag"></i> Flag as Discrepancy</button>
        </div>
    </div>
</div>

<!-- ══ REJECT MODAL (simple reject back to staff) ════════════════════════════ -->
<div class="modal-overlay" id="rejModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Delivery</div>
            <button class="modal-close" onclick="closeM('rejModal')">&times;</button>
        </div>
        <div class="wbox"><i class="fas fa-exclamation-triangle"></i> Delivery will be <strong>returned to Staff for correction</strong>. Use this for encoding errors, not physical discrepancies.</div>
        <div class="fg2">
            <div class="fld"><label>Delivery ID</label><input type="text" id="rej-ref" readonly></div>
            <div class="fld"><label>Qty Delivered</label><input type="text" id="rej-qty" readonly></div>
        </div>
        <div class="fld"><label>Product</label><input type="text" id="rej-prod" readonly></div>
        <div class="fld"><label>Rejection Reason <span style="color:#dc3545;">*</span></label><textarea id="rej-rsn" rows="3" placeholder="Explain why this delivery is being rejected (encoding error, wrong data, etc.)..."></textarea></div>
        <div class="modal-footer">
            <button type="button" onclick="closeM('rejModal')" class="btn ghost">Cancel</button>
            <button type="button" onclick="doReject()" class="btn" style="background:#dc3545;color:#fff;font-weight:700;"><i class="fas fa-times"></i> Reject &amp; Return to Staff</button>
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
        <div class="ibox"><i class="fas fa-info-circle"></i> Minor corrections only. <strong>Reason is mandatory</strong> for all adjustments.</div>
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
    ['aprModal','flagModal','rejModal','resolveModal','remarksModal','replRecvModal','adjModal','dtlModal'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    document.getElementById('f-supplier').addEventListener('input', function() {
        clearTimeout(_t); _t = setTimeout(loadDeliveries, 400);
    });
    loadDeliveries();
});

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openM(id)  { document.getElementById(id).classList.add('open'); }
function closeM(id) { document.getElementById(id).classList.remove('open'); }
document.addEventListener('click', function(e) {
    ['aprModal','flagModal','rejModal','resolveModal','remarksModal','replRecvModal','adjModal','dtlModal'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && e.target === el) closeM(id);
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') ['aprModal','flagModal','rejModal','resolveModal','remarksModal','replRecvModal','adjModal','dtlModal'].forEach(closeM);
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
    if (s === 'confirmed' || s === 'approved' || s === 'validated') return 'Approved';
    if (s === 'adjusted') return 'Adjusted';
    if (s === 'returned to supplier') return 'Returned to Supplier';
    if (s === 'discrepancy' || s === 'flagged' || s === 'rejected') return 'Rejected';
    if (s === 'closed') return 'Closed';
    return raw;
}

// ── Load deliveries ───────────────────────────────────────────────────────────
function loadDeliveries() {
    var status   = document.getElementById('f-status').value;
    var supplier = document.getElementById('f-supplier').value;
    var start    = document.getElementById('f-start').value;
    var end      = document.getElementById('f-end').value;

    var url = API + '?action=list&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
    if (status)   url += '&status='   + encodeURIComponent(status);
    if (supplier) url += '&supplier=' + encodeURIComponent(supplier);

    var tb = document.getElementById('del-tbody');
    tb.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;color:#6c757d;"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;display:block;margin-bottom:10px;"></i>Loading...</td></tr>';

    fetch(url).then(function(r){return r.json();}).then(function(res) {
        if (!res.success) {
            tb.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#dc3545;padding:32px;">' + h(res.message) + '</td></tr>';
            return;
        }

        var rows = res.data || [];
        document.getElementById('rec-count').textContent = rows.length + ' record(s)';

        // Remove summary count updates since cards are removed
        // var cPending = 0, cDisc = 0, cApproved = 0, cOther = 0;
        // rows.forEach(function(d) {
        //     var ds = getDisplayStatus(d.status);
        //     if (ds === 'Pending') cPending++;
        //     else if (ds === 'Pending Resolution' || ds === 'Awaiting Replacement') cDisc++;
        //     else if (ds === 'Approved' || ds === 'Adjusted') cApproved++;
        //     else cOther++;
        // });
        // document.getElementById('cnt-pending').textContent     = cPending;
        // document.getElementById('cnt-discrepancy').textContent = cDisc;
        // document.getElementById('cnt-approved').textContent    = cApproved;
        // document.getElementById('cnt-other').textContent       = cOther;

        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:48px;color:#6c757d;"><i class="fas fa-truck" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:.3;"></i><strong>No deliveries found</strong><br><span style="font-size:12px;">Try adjusting the filters above.</span></td></tr>';
            return;
        }

        var out = '';
        rows.forEach(function(d) {
            var ds  = getDisplayStatus(d.status);
            var qty = parseFloat(d.quantity_delivered||0).toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:2});
            var enc = d.encoded_by_name ? h(d.encoded_by_name) : '<span style="color:#adb5bd">—</span>';
            var rem = (d.remarks||d.manager_reason||'');
            var remDisplay = rem ? h(String(rem).substring(0,55)) + (rem.length > 55 ? '…' : '') : '<span style="color:#adb5bd">—</span>';

            // Type badge
            var isFuel = (d.delivery_type === 'fuel');
            var typeBadge = isFuel
                ? '<span style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;border-radius:12px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap;">⛽ Fuel</span>'
                : '<span style="background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:12px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap;">📦 Merch</span>';

            // Status badge
            var badgeCls = {
                'Pending':              'sbadge-pending',
                'Pending Resolution':   'sbadge-pending-resolution',
                'Awaiting Replacement': 'sbadge-awaiting-replacement',
                'Approved':             'sbadge-approved',
                'Adjusted':             'sbadge-adjusted',
                'Returned to Supplier': 'sbadge-returned-to-supplier',
                'Rejected':             'sbadge-rejected',
                'Closed':               'sbadge-closed',
            }[ds] || 'sbadge-pending';
            var badge = '<span class="sbadge ' + badgeCls + '">' + h(ds) + '</span>';

            // Action buttons
            var acts = '';
            if (ds === 'Pending') {
                acts = '<button class="btn-act btn-approve" onclick="openApr(' + d.id + ',\'' + j(d.delivery_ref) + '\',\'' + j(d.product_name) + '\',' + parseFloat(d.quantity_delivered) + ',\'' + j(d.supplier_name) + '\',\'' + j(d.delivery_type||'merchandise') + '\')"><i class="fas fa-check"></i> Approve</button>'
                     + '<button class="btn-act btn-flag"    onclick="openFlag(' + d.id + ',\'' + j(d.delivery_ref) + '\',\'' + j(d.product_name) + '\',' + parseFloat(d.quantity_delivered) + ')"><i class="fas fa-exclamation-triangle"></i> Flag</button>'
                     + '<button class="btn-act btn-reject"  onclick="openRej(' + d.id + ',\'' + j(d.delivery_ref) + '\',\'' + j(d.product_name) + '\',' + parseFloat(d.quantity_delivered) + ')"><i class="fas fa-times"></i> Reject</button>';
            } else if (ds === 'Pending Resolution') {
                acts = '<button class="btn-act btn-resolve" onclick="openResolve(' + d.id + ',\'' + j(d.delivery_ref) + '\',\'' + j(d.product_name) + '\',' + parseFloat(d.quantity_delivered) + ',\'' + j(d.remarks||'') + '\',\'' + j(d.manager_reason||d.admin_notes||'') + '\')"><i class="fas fa-tools"></i> Resolve</button>'
                     + '<button class="btn-act btn-view"    onclick="openRemarks(' + d.id + ',\'' + j(d.delivery_ref) + '\',\'' + j(d.manager_reason||d.admin_notes||'') + '\')"><i class="fas fa-comment-alt"></i> Add Remarks</button>';
            } else if (ds === 'Awaiting Replacement') {
                acts = '<button class="btn-act btn-replacement" onclick="openReplReceived(' + d.id + ',\'' + j(d.delivery_ref) + '\',\'' + j(d.product_name) + '\',' + parseFloat(d.quantity_delivered) + ')"><i class="fas fa-box-open"></i> Received</button>';
            }
            acts += '<button class="btn-act btn-view" onclick="openDtl(' + d.id + ')"><i class="fas fa-eye"></i> View</button>';

            out += '<tr>'
                + '<td><span class="del-ref">' + h(d.delivery_ref) + '</span></td>'
                + '<td>' + typeBadge + '</td>'
                + '<td><strong>' + h(d.supplier_name||'—') + '</strong></td>'
                + '<td><strong>' + h(d.product_name||'—') + '</strong></td>'
                + '<td style="font-weight:700;color:#155724;text-align:right;">' + qty + ' <span style="font-size:11px;color:#6c757d;">' + h(d.unit||'pcs') + '</span></td>'
                + '<td style="font-size:12px;color:#6c757d;">' + dt(d.delivery_date) + '</td>'
                + '<td style="font-size:12px;">' + enc + '</td>'
                + '<td>' + badge + '</td>'
                + '<td style="font-size:12px;color:#6c757d;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + h(rem) + '">' + remDisplay + '</td>'
                + '<td><div class="act-wrap">' + acts + '</div></td>'
                + '</tr>';
        });
        tb.innerHTML = out;
    }).catch(function(err) {
        tb.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#dc3545;padding:32px;"><i class="fas fa-wifi"></i> Network error — please refresh.</td></tr>';
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
            ibox.innerHTML = '<i class="fas fa-gas-pump"></i> Approving will <strong>update Fuel Inventory</strong> — delivered liters added to fuel stock.';
        } else {
            ibox.innerHTML = '<i class="fas fa-info-circle"></i> Approving will <strong>automatically update inventory</strong> — delivered quantity added to stock.';
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

// ── EXPORT ────────────────────────────────────────────────────────────────────
function exportExcel() {
    var s=document.getElementById('f-status').value, sup=document.getElementById('f-supplier').value;
    var st=document.getElementById('f-start').value, en=document.getElementById('f-end').value;
    window.open(API+'?action=export_excel&start='+encodeURIComponent(st)+'&end='+encodeURIComponent(en)+'&status='+encodeURIComponent(s)+'&supplier='+encodeURIComponent(sup),'_blank');
    toast('Exporting to Excel...','success');
}
function exportPDF() {
    var s=document.getElementById('f-status').value, sup=document.getElementById('f-supplier').value;
    var st=document.getElementById('f-start').value, en=document.getElementById('f-end').value;
    window.open(API+'?action=export_pdf&start='+encodeURIComponent(st)+'&end='+encodeURIComponent(en)+'&status='+encodeURIComponent(s)+'&supplier='+encodeURIComponent(sup),'_blank');
    toast('Exporting to PDF...','success');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
