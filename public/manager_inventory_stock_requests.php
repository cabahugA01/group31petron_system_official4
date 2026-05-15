<?php
$page_id = "mgr_inv_requests";
require_once __DIR__ . "/../backend/lib.php";
require_once __DIR__ . "/db_connect.php";
require_login();

$me         = current_user();
$role       = role_key($me["role"] ?? "");
$station_id = user_station_id();

if (!in_array($role, ["manager", "admin", "superadmin"])) {
    header("Location: dashboard.php");
    exit;
}

$msg      = "";
$msg_type = "success";
$active_tab = $_GET["tab"] ?? "pending";

include __DIR__ . "/../partials/header.php";
?>
<style>
.inv-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:24px; }
.inv-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.inv-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.inv-card-body { padding:20px; }
.sbadge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.sbadge-pending  { background:#fff3cd; color:#856404; }
.sbadge-approved { background:#d4edda; color:#155724; }
.sbadge-rejected { background:#f8d7da; color:#721c24; }
.inv-alert { display:flex; align-items:center; gap:10px; padding:13px 16px; border-radius:8px; margin-bottom:18px; font-size:14px; }
.inv-alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.inv-alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.btn-action { border:none; padding:6px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:5px; transition:background .15s; }
.btn-approve { background:#28a745; color:#fff; }
.btn-approve:hover { background:#1e7e34; }
.btn-reject  { background:#dc3545; color:#fff; }
.btn-reject:hover  { background:#b02a37; }
.btn-edit    { background:#002F70; color:#fff; }
.btn-edit:hover    { background:#001F4F; }
.modal-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; width:100vw; height:100vh; background:rgba(0,0,0,.6); z-index:9999; align-items:center; justify-content:center; margin:0; padding:0; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:14px; padding:28px; width:600px; max-width:calc(100vw - 32px); max-height:calc(100vh - 40px); overflow-y:auto; box-shadow:0 24px 80px rgba(0,0,0,.3); animation:modalIn .2s ease; position:relative; z-index:10000; }
@keyframes modalIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:2px solid #e9ecef; }
.modal-title { font-size:1.05rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#adb5bd; line-height:1; }
.modal-close:hover { color:#333; }
.field-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
.field-group label { display:block; margin-bottom:5px; font-weight:700; font-size:12px; color:#495057; text-transform:uppercase; letter-spacing:.4px; }
.field-group input[type=text], .field-group input[type=number], .field-group textarea { width:100%; padding:9px 11px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; box-sizing:border-box; }
.field-group input[readonly] { background:#f8f9fa; color:#6c757d; }
.field-group input[type=number]:focus, .field-group textarea:focus { border-color:#002F70; outline:none; box-shadow:0 0 0 3px rgba(0,47,112,.12); }
.field-group textarea { resize:vertical; }
.qty-preview { display:flex; align-items:center; gap:10px; background:#f0f4ff; border:1px solid #c5d3f0; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:13px; }
.qty-preview .arrow { color:#002F70; font-size:16px; font-weight:700; }
.qty-old { color:#6c757d; text-decoration:line-through; }
.qty-new { color:#002F70; font-weight:700; font-size:15px; }
.info-box { background:#e8f4fd; border-left:4px solid #002F70; border-radius:6px; padding:10px 14px; margin-bottom:16px; font-size:12px; color:#002F70; line-height:1.6; }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; padding-top:14px; border-top:1px solid #e9ecef; }
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e9ecef; margin-bottom:20px; }
.tab-btn { padding:10px 22px; background:none; border:none; border-bottom:3px solid transparent; font-size:14px; font-weight:600; color:#6c757d; cursor:pointer; margin-bottom:-2px; transition:all .15s; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-inbox"></i> Staff Stock Requests</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Review, approve or reject staff stock requests</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<div id="flashMsg" style="display:none;" class="inv-alert"></div>

<!-- Tab nav -->
<div class="tab-nav">
    <button class="tab-btn <?php echo $active_tab === "pending" ? "active" : ""; ?>" onclick="switchTab("pending")">
        <i class="fas fa-clock"></i> Pending <span id="pendingBadge" style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;"></span>
    </button>
    <button class="tab-btn <?php echo $active_tab === "history" ? "active" : ""; ?>" onclick="switchTab("history")">
        <i class="fas fa-history"></i> History
    </button>
</div>

<!-- Pending tab -->
<div id="tab-pending" style="display:<?php echo $active_tab === "pending" ? "block" : "none"; ?>;">
    <div class="inv-card">
        <div class="inv-card-head">
            <div class="inv-card-title"><i class="fas fa-clock"></i> Pending Requests</div>
            <span style="font-size:12px;color:#6c757d;">Click <strong>Approve</strong> or <strong>Reject</strong> to process each request.</span>
        </div>
        <div class="inv-card-body">
            <div class="table-wrap">
                <table class="table" id="pendingTable">
                    <thead>
                        <tr>
                            <th>#</th><th>Date</th><th>Staff</th><th>SKU</th><th>Product</th>
                            <th>Category</th><th>Current Stock</th><th>Qty Requested</th>
                            <th>Remarks</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="pendingBody">
                        <tr><td colspan="10" style="text-align:center;padding:30px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- History tab -->
<div id="tab-history" style="display:<?php echo $active_tab === "history" ? "block" : "none"; ?>;">
    <div class="inv-card">
        <div class="inv-card-head">
            <div class="inv-card-title"><i class="fas fa-history"></i> Processed Requests</div>
        </div>
        <div class="inv-card-body">
            <div class="table-wrap">
                <table class="table" id="historyTable">
                    <thead>
                        <tr>
                            <th>#</th><th>Date</th><th>Staff</th><th>Product</th>
                            <th>Qty Requested</th><th>Qty Approved</th><th>Status</th>
                            <th>PO Generated</th><th>Manager Notes</th><th>Processed On</th>
                        </tr>
                    </thead>
                    <tbody id="historyBody">
                        <tr><td colspan="10" style="text-align:center;padding:30px;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- APPROVE MODAL -->
<div class="modal-overlay" id="approveModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-check-circle" style="color:#28a745;"></i> Approve Stock Request</div>
            <button class="modal-close" onclick="closeApprove()">&times;</button>
        </div>
        <div class="field-grid">
            <div class="field-group"><label>SKU</label><input type="text" id="appSku" readonly></div>
            <div class="field-group"><label>Product</label><input type="text" id="appName" readonly></div>
            <div class="field-group"><label>Category</label><input type="text" id="appCategory" readonly></div>
            <div class="field-group"><label>Requested By</label><input type="text" id="appStaff" readonly></div>
            <div class="field-group"><label>Current Stock</label><input type="text" id="appCurStock" readonly></div>
            <div class="field-group"><label>Qty Requested by Staff</label><input type="text" id="appReqQty" readonly></div>
        </div>
        <div class="qty-preview">
            <i class="fas fa-boxes" style="color:#002F70;"></i>
            <span>Staff requested:</span><span class="qty-old" id="appPreviewOld">—</span>
            <span class="arrow">→</span>
            <span>Manager approves:</span><span class="qty-new" id="appPreviewNew">—</span>
            <span style="color:#6c757d;font-size:11px;">units</span>
        </div>
        <div class="field-grid">
            <div class="field-group" style="grid-column:1/-1;">
                <label>Approved Quantity <span style="color:#dc3545;">*</span></label>
                <input type="number" id="appQty" min="1" required placeholder="Enter approved quantity..."
                       style="font-size:16px;font-weight:700;color:#002F70;">
            </div>
        </div>
        <div class="field-group" style="margin-bottom:16px;">
            <label>Manager Notes</label>
            <textarea id="appNotes" rows="3" placeholder="Optional: reason for adjustment, notes for staff..."></textarea>
        </div>
        <div class="info-box">
            <i class="fas fa-info-circle"></i> <strong>On Approve:</strong><br>
            &bull; Request status → <strong>Approved</strong><br>
            &bull; Purchase Order auto-generated → Pending Admin Validation<br>
            &bull; Audit trail logged: Manager ID, qty, timestamp
        </div>
        <div id="appError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px;border-radius:6px;margin-bottom:12px;font-size:13px;"></div>
        <div class="modal-footer">
            <button type="button" onclick="closeApprove()" style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">Cancel</button>
            <button type="button" id="appSubmitBtn" onclick="submitApprove()" style="padding:9px 22px;background:#28a745;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                <i class="fas fa-check"></i> Approve
            </button>
        </div>
    </div>
</div>

<!-- REJECT MODAL -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Stock Request</div>
            <button class="modal-close" onclick="closeReject()">&times;</button>
        </div>
        <div class="field-group" style="margin-bottom:8px;">
            <label>Product</label>
            <input type="text" id="rejName" readonly style="width:100%;padding:9px;border:1px solid #dee2e6;border-radius:6px;background:#f8f9fa;color:#6c757d;">
        </div>
        <div class="field-group" style="margin-bottom:16px;">
            <label>Rejection Reason <span style="color:#dc3545;">*</span></label>
            <textarea id="rejNotes" rows="4" placeholder="Required: explain why this request is being rejected..."
                      style="width:100%;padding:9px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;resize:vertical;"></textarea>
        </div>
        <div id="rejError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px;border-radius:6px;margin-bottom:12px;font-size:13px;"></div>
        <div class="modal-footer">
            <button type="button" onclick="closeReject()" style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">Cancel</button>
            <button type="button" id="rejSubmitBtn" onclick="submitReject()" style="padding:9px 22px;background:#dc3545;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                <i class="fas fa-times"></i> Reject
            </button>
        </div>
    </div>
</div>

<script>
var currentRequestId = null;

// Move modals to body
document.addEventListener("DOMContentLoaded", function() {
    ["approveModal","rejectModal"].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    loadRequests();
});

function switchTab(tab) {
    document.getElementById("tab-pending").style.display = tab === "pending" ? "block" : "none";
    document.getElementById("tab-history").style.display = tab === "history" ? "block" : "none";
    document.querySelectorAll(".tab-btn").forEach(function(b) { b.classList.remove("active"); });
    event.target.classList.add("active");
}

function loadRequests() {
    fetch("../backend/api/stock_request.php?action=get_requests")
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var requests = data.requests || [];
        var pending  = requests.filter(function(r) { return r.status === "Pending"; });
        var history  = requests.filter(function(r) { return r.status !== "Pending"; });

        var badge = document.getElementById("pendingBadge");
        badge.textContent = pending.length > 0 ? pending.length : "";
        badge.style.display = pending.length > 0 ? "inline" : "none";

        renderPending(pending);
        renderHistory(history);
    })
    .catch(function() {
        document.getElementById("pendingBody").innerHTML = "<tr><td colspan=\"10\" style=\"text-align:center;padding:30px;color:#dc3545;\">Error loading requests.</td></tr>";
    });
}

function renderPending(rows) {
    var tbody = document.getElementById("pendingBody");
    if (rows.length === 0) {
        tbody.innerHTML = "<tr><td colspan=\"10\" style=\"text-align:center;padding:40px;color:#6c757d;\"><i class=\"fas fa-check-circle\" style=\"font-size:2em;display:block;margin-bottom:10px;color:#28a745;opacity:.5;\"></i><strong>All caught up!</strong><br>No pending stock requests.</td></tr>";
        return;
    }
    tbody.innerHTML = rows.map(function(r) {
        return "<tr>" +
            "<td style=\"color:#6c757d;font-size:12px;\">#" + r.id + "</td>" +
            "<td style=\"font-size:12px;\">" + fmtDate(r.created_at) + "</td>" +
            "<td>" + esc(r.staff_name || "") + "</td>" +
            "<td><code style=\"font-size:11px;\">" + esc(r.item_sku || "") + "</code></td>" +
            "<td><strong>" + esc(r.item_name) + "</strong></td>" +
            "<td style=\"font-size:12px;\">" + esc(r.item_category || "") + "</td>" +
            "<td style=\"text-align:center;\">" + r.current_stock + "</td>" +
            "<td style=\"text-align:center;font-weight:700;color:#002F70;font-size:15px;\">" + r.requested_quantity + "</td>" +
            "<td style=\"font-size:12px;color:#6c757d;max-width:150px;\">" + (r.remarks ? esc(r.remarks) : "<span style=\"color:#adb5bd;\">—</span>") + "</td>" +
            "<td style=\"white-space:nowrap;\">" +
                "<button class=\"btn-action btn-approve\" onclick=\"openApprove(" + r.id + ",\'" + esc(r.item_name) + "\',\'" + esc(r.item_sku||"") + "\',\'" + esc(r.item_category||"") + "\'," + r.current_stock + "," + r.requested_quantity + ",\'" + esc(r.staff_name||"") + "\')\"><i class=\"fas fa-check\"></i> Approve</button> " +
                "<button class=\"btn-action btn-reject\" onclick=\"openReject(" + r.id + ",\'" + esc(r.item_name) + "\')\"><i class=\"fas fa-times\"></i> Reject</button>" +
            "</td>" +
        "</tr>";
    }).join("");
}

function renderHistory(rows) {
    var tbody = document.getElementById("historyBody");
    if (rows.length === 0) {
        tbody.innerHTML = "<tr><td colspan=\"10\" style=\"text-align:center;padding:28px;color:#6c757d;\">No processed requests yet.</td></tr>";
        return;
    }
    tbody.innerHTML = rows.map(function(r) {
        var st  = r.status || "Unknown";
        var cls = "sbadge sbadge-" + st.toLowerCase();
        var qtyApproved = r.approved_quantity !== null && r.approved_quantity !== undefined
            ? "<strong style=\"color:#28a745;font-size:14px;\">" + r.approved_quantity + "</strong>" +
              (parseInt(r.approved_quantity) !== parseInt(r.requested_quantity) ? " <span style=\"font-size:10px;color:#fd7e14;\">adjusted</span>" : "")
            : "<span style=\"color:#adb5bd;\">—</span>";
        return "<tr>" +
            "<td style=\"color:#6c757d;font-size:12px;\">#" + r.id + "</td>" +
            "<td style=\"font-size:12px;\">" + fmtDate(r.created_at) + "</td>" +
            "<td>" + esc(r.staff_name || "") + "</td>" +
            "<td><strong>" + esc(r.item_name) + "</strong></td>" +
            "<td style=\"text-align:center;color:#6c757d;\">" + r.requested_quantity + "</td>" +
            "<td style=\"text-align:center;\">" + qtyApproved + "</td>" +
            "<td><span class=\"" + cls + "\">" + esc(st) + "</span></td>" +
            "<td style=\"font-size:11px;\">—</td>" +
            "<td style=\"font-size:12px;color:#495057;max-width:180px;\">" + (r.manager_notes ? esc(r.manager_notes) : "<span style=\"color:#adb5bd;\">—</span>") + "</td>" +
            "<td style=\"font-size:12px;color:#6c757d;\">" + (r.processed_at ? fmtDate(r.processed_at) : fmtDate(r.updated_at || r.created_at)) + "</td>" +
        "</tr>";
    }).join("");
}

// ── Approve modal ─────────────────────────────────────────────────────────────
function openApprove(id, name, sku, category, curStock, reqQty, staffName) {
    currentRequestId = id;
    document.getElementById("appSku").value      = sku;
    document.getElementById("appName").value     = name;
    document.getElementById("appCategory").value = category;
    document.getElementById("appStaff").value    = staffName;
    document.getElementById("appCurStock").value = curStock + " units";
    document.getElementById("appReqQty").value   = reqQty + " units";
    document.getElementById("appQty").value      = reqQty;
    document.getElementById("appPreviewOld").textContent = reqQty;
    document.getElementById("appPreviewNew").textContent = reqQty;
    document.getElementById("appNotes").value    = "";
    document.getElementById("appError").style.display = "none";
    document.getElementById("appSubmitBtn").disabled = false;
    document.getElementById("appSubmitBtn").innerHTML = "<i class=\"fas fa-check\"></i> Approve";
    document.getElementById("approveModal").classList.add("open");
    setTimeout(function() { document.getElementById("appQty").focus(); }, 100);
}
function closeApprove() {
    document.getElementById("approveModal").classList.remove("open");
}
document.getElementById("appQty").addEventListener("input", function() {
    var v = parseInt(this.value) || 0;
    document.getElementById("appPreviewNew").textContent = v > 0 ? v : "—";
});
document.getElementById("approveModal").addEventListener("click", function(e) { if (e.target === this) closeApprove(); });

function submitApprove() {
    var qty   = parseInt(document.getElementById("appQty").value) || 0;
    var notes = document.getElementById("appNotes").value.trim();
    if (qty <= 0) { showErr("appError", "Please enter a valid approved quantity."); return; }

    var btn = document.getElementById("appSubmitBtn");
    btn.disabled = true;
    btn.innerHTML = "<i class=\"fas fa-spinner fa-spin\"></i> Processing...";
    document.getElementById("appError").style.display = "none";

    fetch("../backend/api/stock_request.php?action=approve", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ request_id: currentRequestId, approved_quantity: qty, manager_notes: notes })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            closeApprove();
            showFlash("success", "&#10003; Request #" + currentRequestId + " approved. " + (res.po_number ? "PO <strong>" + res.po_number + "</strong> generated." : ""));
            loadRequests();
        } else {
            showErr("appError", res.message || "Failed to approve.");
            btn.disabled = false;
            btn.innerHTML = "<i class=\"fas fa-check\"></i> Approve";
        }
    })
    .catch(function() {
        showErr("appError", "Network error. Please try again.");
        btn.disabled = false;
        btn.innerHTML = "<i class=\"fas fa-check\"></i> Approve";
    });
}

// ── Reject modal ──────────────────────────────────────────────────────────────
function openReject(id, name) {
    currentRequestId = id;
    document.getElementById("rejName").value  = name;
    document.getElementById("rejNotes").value = "";
    document.getElementById("rejError").style.display = "none";
    document.getElementById("rejSubmitBtn").disabled = false;
    document.getElementById("rejSubmitBtn").innerHTML = "<i class=\"fas fa-times\"></i> Reject";
    document.getElementById("rejectModal").classList.add("open");
    setTimeout(function() { document.getElementById("rejNotes").focus(); }, 100);
}
function closeReject() {
    document.getElementById("rejectModal").classList.remove("open");
}
document.getElementById("rejectModal").addEventListener("click", function(e) { if (e.target === this) closeReject(); });

function submitReject() {
    var notes = document.getElementById("rejNotes").value.trim();
    if (!notes) { showErr("rejError", "Rejection reason is required."); return; }

    var btn = document.getElementById("rejSubmitBtn");
    btn.disabled = true;
    btn.innerHTML = "<i class=\"fas fa-spinner fa-spin\"></i> Processing...";
    document.getElementById("rejError").style.display = "none";

    fetch("../backend/api/stock_request.php?action=reject", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ request_id: currentRequestId, manager_notes: notes })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            closeReject();
            showFlash("success", "&#10003; Request #" + currentRequestId + " rejected.");
            loadRequests();
        } else {
            showErr("rejError", res.message || "Failed to reject.");
            btn.disabled = false;
            btn.innerHTML = "<i class=\"fas fa-times\"></i> Reject";
        }
    })
    .catch(function() {
        showErr("rejError", "Network error. Please try again.");
        btn.disabled = false;
        btn.innerHTML = "<i class=\"fas fa-times\"></i> Reject";
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function showFlash(type, msg) {
    var el = document.getElementById("flashMsg");
    el.className = "inv-alert inv-alert-" + type;
    el.innerHTML = "<i class=\"fas fa-" + (type === "success" ? "check-circle" : "times-circle") + "\"></i><span>" + msg + "</span>";
    el.style.display = "flex";
    setTimeout(function() { el.style.display = "none"; }, 6000);
}
function showErr(id, msg) {
    var el = document.getElementById(id);
    el.textContent = msg;
    el.style.display = "block";
}
function esc(str) {
    return String(str).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;");
}
function fmtDate(ds) {
    if (!ds) return "—";
    var d = new Date(ds);
    return d.toLocaleDateString("en-PH", {month:"short",day:"numeric",year:"numeric"}) + " " +
           d.toLocaleTimeString("en-PH", {hour:"2-digit",minute:"2-digit"});
}
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") { closeApprove(); closeReject(); }
});
</script>

<?php include __DIR__ . "/../partials/footer.php"; ?>
