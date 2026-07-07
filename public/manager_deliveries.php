<?php
$page_id = 'manager_deliveries';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['manager','admin','superadmin'])) {
    header('Location: dashboard.php'); exit;
}

$valid_sections = ['manage','history'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'manage';

$flash_ok = $flash_err = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'review_delivery') {
        $did     = (int)($_POST['delivery_id'] ?? 0);
        $st      = $_POST['status'] ?? ''; // 'Approved' or 'Discrepancy'
        $notes   = trim($_POST['notes'] ?? '');
        $adj_qty = (float)($_POST['adjusted_qty'] ?? 0);

        if ($did && in_array($st, ['Approved','Discrepancy'])) {
            try {
                $pdo->beginTransaction();

                // Ensure manager_id column exists (separate from admin_id which is for Admin oversight)
                try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS manager_id INT DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS manager_action_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS manager_notes TEXT DEFAULT NULL"); } catch (Exception $e) {}

                // Fetch the delivery record — only act on records pending Manager approval
                $s = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id=? AND station_id=? AND status='Pending Manager Approval'");
                $s->execute([$did, $station_id]);
                $del = $s->fetch(PDO::FETCH_ASSOC);

                if (!$del) {
                    throw new Exception("Delivery record not found or already processed.");
                }

                $final_qty = $del['quantity'];
                
                if ($st === 'Approved') {
                    if ($adj_qty > 0 && $adj_qty != $del['quantity']) {
                        $final_qty = $adj_qty;
                        $notes = "Adjusted qty from {$del['quantity']} to {$adj_qty}. " . $notes;
                    }
                    
                    // Update inventory
                    $upd_inv = $pdo->prepare("UPDATE inventory_products SET stock_quantity = stock_quantity + ?, updated_at = NOW() WHERE product_name = ? AND station_id = ?");
                    $upd_inv->execute([$final_qty, $del['product'], $station_id]);
                    
                    if ($upd_inv->rowCount() === 0) {
                        throw new Exception("Product '{$del['product']}' not found in inventory. Cannot approve.");
                    }
                }

                // Update delivery oversight — use manager_id (not admin_id) for Manager actions.
                // admin_id is reserved for Admin-level oversight actions.
                // After Manager approval, status moves to 'Pending Admin Oversight' so Admin can review.
                $next_status = ($st === 'Approved') ? 'Pending Admin Oversight' : 'Discrepancy';
                $pdo->prepare("
                    UPDATE deliveries_oversight 
                    SET status=?, quantity=?, manager_id=?, manager_action_at=NOW(), manager_notes=? 
                    WHERE id=?
                ")->execute([$next_status, $final_qty, $me['id'], $notes, $did]);

                $pdo->commit();

                $flash_ok = "Delivery #{$did} marked as <strong>{$st}</strong> and forwarded to Admin oversight.";

                // Audit Log
                write_audit_log($pdo, $st === 'Approved' ? 'Approve' : 'Reject',
                    "Manager {$st}: Batch {$del['batch_id']} | Product: {$del['product']} | Qty: {$final_qty}",
                    'deliveries_oversight', $did, 'transaction');

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash_err = "Error processing delivery: " . $e->getMessage();
            }
        }
    }
}

// ── Section data ──────────────────────────────────────────────────────────────
$records = [];
if ($section === 'manage') {
    try {
        $stmt = $pdo->prepare("
            SELECT d.*, u.name as staff_name 
            FROM deliveries_oversight d
            LEFT JOIN users u ON d.encoded_by = u.id
            WHERE d.station_id=? AND d.status='Pending Manager Approval'
            ORDER BY d.created_at ASC
        ");
        $stmt->execute([$station_id]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
} else if ($section === 'history') {
    try {
        $stmt = $pdo->prepare("
            SELECT d.*, u.name as staff_name, a.name as manager_name
            FROM deliveries_oversight d
            LEFT JOIN users u ON d.encoded_by  = u.id
            LEFT JOIN users a ON d.manager_id  = a.id
            WHERE d.station_id=? AND d.status != 'Pending Manager Approval'
            ORDER BY d.manager_action_at DESC
        ");
        $stmt->execute([$station_id]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$section_meta = [
    'manage'  => ['fas fa-clipboard-check', 'Manage Deliveries'],
    'history' => ['fas fa-history',         'Delivery History'],
];
[$sec_ico, $sec_title] = $section_meta[$section];

include __DIR__ . '/../partials/header.php';
?>
<style>
.mgrc-card{background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e9ecef;margin-bottom:20px;}
.mgrc-head{padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.mgrc-title{font-size:16px;font-weight:700;color:#002F70;margin:0;display:flex;align-items:center;gap:8px;}
.mgrc-body{padding:20px;}
.mgrc-table{width:100%;border-collapse:collapse;font-size:13px;}
.mgrc-table th{background:#f8f9fa;padding:10px 12px;text-align:left;font-weight:700;color:#495057;border-bottom:2px solid #dee2e6;white-space:nowrap;}
.mgrc-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.mgrc-table tr:hover td{background:#f8f9fa;}
.badge-pending{background:#fef3c7;color:#92400e;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.badge-approved{background:#d1fae5;color:#065f46;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.badge-rejected{background:#fee2e2;color:#991b1b;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.mgrc-btn{padding:6px 14px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:5px;}
.mgrc-btn-approve{background:#28a745;color:#fff;} .mgrc-btn-approve:hover{background:#218838;}
.mgrc-btn-reject{background:#dc3545;color:#fff;} .mgrc-btn-reject:hover{background:#c82333;}
.mgrc-empty{text-align:center;padding:40px;color:#9ca3af;}
.mgrc-empty i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.4;}
.mgrc-search{width:100%;padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;margin-bottom:14px;box-sizing:border-box;}
.mgrc-search:focus{border-color:#002F70;outline:none;}
.flash-ok{background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#065f46;font-weight:600;}
.flash-err{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#991b1b;font-weight:600;}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:28px;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.modal-title{font-size:17px;font-weight:700;color:#002F70;margin-bottom:16px;}
.modal-label{display:block;font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;margin-bottom:5px;}
.modal-input{width:100%;padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;box-sizing:border-box;margin-bottom:14px;}
.modal-input:focus{border-color:#002F70;outline:none;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:6px;}
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="<?php echo $sec_ico; ?>"></i> <?php echo $sec_title; ?></h1>
    <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; Deliveries Management Oversight</div>
  </div>
</div>

<?php if ($flash_ok): ?><div class="flash-ok"><i class="fas fa-check-circle"></i> <?php echo $flash_ok; ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($flash_err); ?></div><?php endif; ?>

<!-- ===== SECTION: MANAGE DELIVERIES ===== -->
<?php if ($section === 'manage'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-clipboard-check"></i> Pending Deliveries</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($records); ?> pending</span>
  </div>
  <div class="mgrc-body">
    <input class="mgrc-search" id="recordSearch" placeholder="&#128269; Search deliveries..." oninput="filterRows('recordSearch','recordTable')">
    <div style="overflow:hidden;">
      <table class="mgrc-table" id="recordTable">
        <thead><tr>
          <th>Batch ID</th>
          <th>Supplier & DR</th>
          <th>Date</th>
          <th>Product Name</th>
          <th>Qty Encoded</th>
          <th>Staff</th>
          <th>Action</th>
        </tr></thead>
        <tbody>
        <?php if (empty($records)): ?>
          <tr><td colspan="7" class="mgrc-empty"><i class="fas fa-check-circle"></i>No pending deliveries.</td></tr>
        <?php else: foreach ($records as $r): ?>
          <tr data-search="<?php echo strtolower(htmlspecialchars($r['batch_id'] . ' ' . $r['supplier'] . ' ' . $r['product'])); ?>">
            <td style="color:#002F70;font-weight:700;font-size:12px;"><?php echo htmlspecialchars($r['batch_id']); ?></td>
            <td>
                <strong><?php echo htmlspecialchars($r['supplier']); ?></strong><br>
                <span style="font-size:11px;color:#6c757d;">DR: <?php echo htmlspecialchars($r['dr_number'] ?: 'N/A'); ?></span>
            </td>
            <td><?php echo date('M d, Y', strtotime($r['delivery_date'])); ?></td>
            <td style="font-weight:700;"><?php echo htmlspecialchars($r['product']); ?></td>
            <td><span style="font-size:14px;font-weight:700;color:#e67e22;"><?php echo number_format((float)$r['quantity'], 2) . ' ' . htmlspecialchars($r['unit']); ?></span></td>
            <td><?php echo htmlspecialchars($r['staff_name'] ?? '—'); ?></td>
            <td>
              <div style="display:flex;flex-direction:column;gap:6px;">
                <button class="mgrc-btn mgrc-btn-approve" style="justify-content:center;width:90px;" onclick="openModal('Approved', <?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['product'])); ?>', <?php echo $r['quantity']; ?>)">
                  <i class="fas fa-check"></i> Approve
                </button>
                <button class="mgrc-btn mgrc-btn-reject" style="justify-content:center;width:90px;" onclick="openModal('Discrepancy', <?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['product'])); ?>', <?php echo $r['quantity']; ?>)">
                  <i class="fas fa-times"></i> Reject
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===== SECTION: DELIVERY HISTORY ===== -->
<?php if ($section === 'history'): ?>
<div class="mgrc-card">
  <div class="mgrc-head">
    <h2 class="mgrc-title"><i class="fas fa-history"></i> Processed Deliveries Log</h2>
    <span style="font-size:13px;color:#6c757d;"><?php echo count($records); ?> records</span>
  </div>
  <div class="mgrc-body">
    <input class="mgrc-search" id="historySearch" placeholder="&#128269; Search history..." oninput="filterRows('historySearch','historyTable')">
    <div style="overflow:hidden;">
      <table class="mgrc-table" id="historyTable">
        <thead><tr>
          <th>Batch ID</th>
          <th>Product & Supplier</th>
          <th>Final Qty</th>
          <th>Processed By</th>
          <th>Status</th>
          <th>Details</th>
        </tr></thead>
        <tbody>
        <?php if (empty($records)): ?>
          <tr><td colspan="6" class="mgrc-empty"><i class="fas fa-folder-open"></i>No delivery history available.</td></tr>
        <?php else: foreach ($records as $r): 
            $is_approved = strtolower($r['status']) === 'approved';
        ?>
          <tr data-search="<?php echo strtolower(htmlspecialchars($r['batch_id'] . ' ' . $r['supplier'] . ' ' . $r['product'])); ?>">
            <td style="color:#6c757d;font-size:12px;"><?php echo htmlspecialchars($r['batch_id']); ?></td>
            <td>
                <strong><?php echo htmlspecialchars($r['product']); ?></strong><br>
                <span style="font-size:11px;color:#6c757d;"><?php echo htmlspecialchars($r['supplier']); ?></span>
            </td>
            <td><span style="font-weight:700;color:<?php echo $is_approved?'#28a745':'#dc3545'; ?>;"><?php echo number_format((float)$r['quantity'], 2) . ' ' . htmlspecialchars($r['unit']); ?></span></td>
            <td>
                <?php echo htmlspecialchars($r['admin_name'] ?? '—'); ?><br>
                <span style="font-size:11px;color:#6c757d;"><?php echo $r['admin_action_at'] ? date('M d, Y h:i A', strtotime($r['admin_action_at'])) : ''; ?></span>
            </td>
            <td><span class="badge-<?php echo $is_approved?'approved':'rejected'; ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
            <td style="max-width:200px;font-size:11px;color:#6c757d;">
                <?php if ($r['admin_notes']): ?>
                    <strong>Note:</strong> <?php echo htmlspecialchars($r['admin_notes']); ?>
                <?php else: ?>
                    <em>No notes</em>
                <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ===== ACTION MODAL ===== -->
<div class="modal-overlay" id="actionModal">
  <div class="modal-box">
    <h3 class="modal-title" id="modalTitle">Review Delivery</h3>
    <p style="font-size:13px;color:#495057;margin-bottom:18px;line-height:1.5;" id="modalDesc"></p>
    <form method="POST" action="manager_deliveries.php?section=manage">
      <input type="hidden" name="action" value="review_delivery">
      <input type="hidden" name="delivery_id" id="modalDeliveryId">
      <input type="hidden" name="status" id="modalStatus">

      <div id="adjustQtyWrap" style="display:none;">
        <label class="modal-label">Final Quantity Received (Adjust if needed)</label>
        <input type="number" step="0.001" name="adjusted_qty" id="modalAdjustedQty" class="modal-input" placeholder="0.000">
        <p style="font-size:11px;color:#6c757d;margin-top:-10px;margin-bottom:14px;">Leave as is if the quantity perfectly matches the DR.</p>
      </div>

      <label class="modal-label">Manager Notes (Required for Rejection/Adjustment)</label>
      <textarea name="notes" id="modalNotes" class="modal-input" rows="3" placeholder="Explain your decision..."></textarea>
      
      <div class="modal-actions">
        <button type="button" class="mgrc-btn" style="background:#e9ecef;color:#495057;" onclick="closeModal()">Cancel</button>
        <button type="submit" class="mgrc-btn" id="modalSubmitBtn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<script>
function filterRows(inputId, tableId) {
    const q = document.getElementById(inputId).value.toLowerCase();
    document.querySelectorAll('#' + tableId + ' tbody tr[data-search]').forEach(function(row) {
        row.style.display = row.getAttribute('data-search').includes(q) ? '' : 'none';
    });
}

function openModal(actionType, id, product, originalQty) {
    const modal = document.getElementById('actionModal');
    const isApprove = actionType === 'Approved';
    
    document.getElementById('modalTitle').textContent = (isApprove ? 'Approve' : 'Reject') + ' Delivery: ' + product;
    
    document.getElementById('modalDesc').textContent = isApprove
        ? 'Approving this delivery will securely add the quantity to the live inventory.'
        : 'Rejecting this delivery will send it back to the staff for correction due to a discrepancy.';
        
    document.getElementById('modalStatus').value = actionType;
    document.getElementById('modalDeliveryId').value = id;
    
    const adjustWrap = document.getElementById('adjustQtyWrap');
    const adjustInput = document.getElementById('modalAdjustedQty');
    const notesInput = document.getElementById('modalNotes');
    
    if (isApprove) {
        adjustWrap.style.display = 'block';
        adjustInput.value = originalQty;
        notesInput.required = false;
    } else {
        adjustWrap.style.display = 'none';
        adjustInput.value = '';
        notesInput.required = true;
    }
    
    notesInput.value = '';
    
    const btn = document.getElementById('modalSubmitBtn');
    btn.textContent = isApprove ? 'Approve & Update Inventory' : 'Reject Delivery';
    btn.className = 'mgrc-btn ' + (isApprove ? 'mgrc-btn-approve' : 'mgrc-btn-reject');
    
    modal.classList.add('open');
}

function closeModal() {
    document.getElementById('actionModal').classList.remove('open');
}

document.getElementById('actionModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<div style="height: 80px;"></div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
