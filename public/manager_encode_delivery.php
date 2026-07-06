<?php
$page_id = 'manager_encode_delivery';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();  $me  = current_user();
$role  = role_key($me['role'] ?? '');
$station_id = user_station_id();  if (!in_array($role, ['manager', 'admin', 'superadmin'])) {  $_SESSION['error'] = 'Access denied. Manager access required.';  header('Location: dashboard.php');  exit;
}  // Bootstrap deliveries_oversight table
try {  $pdo->exec("CREATE TABLE IF NOT EXISTS deliveries_oversight (  id INT AUTO_INCREMENT PRIMARY KEY,  delivery_type ENUM('fuel','merchandise') NOT NULL DEFAULT 'fuel',  delivery_ref VARCHAR(100) NOT NULL DEFAULT '',  batch_id VARCHAR(100) DEFAULT NULL,  supplier VARCHAR(200) NOT NULL DEFAULT '',  product VARCHAR(200) NOT NULL DEFAULT '',  quantity DECIMAL(12,3) NOT NULL DEFAULT 0,  unit VARCHAR(30) NOT NULL DEFAULT 'L',  delivery_date DATE NOT NULL,  dr_number VARCHAR(100) DEFAULT NULL,  encoded_by INT DEFAULT NULL,  station_id INT NOT NULL,  status ENUM('Pending Validation','Validated','Flagged') NOT NULL DEFAULT 'Pending Validation',  admin_id INT DEFAULT NULL,  admin_action_at DATETIME DEFAULT NULL,  admin_notes TEXT DEFAULT NULL,  source_ref VARCHAR(100) DEFAULT NULL,  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,  INDEX idx_station (station_id), INDEX idx_status (status), INDEX idx_date (delivery_date)  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}
// Add batch_id column if missing (older installs)
try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN batch_id VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}  $msg  = '';
$msg_type = 'success';  // ── POST: Encode new delivery ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'encode_delivery') {  $delivery_type = in_array($_POST['delivery_type'] ?? '', ['fuel', 'merchandise'])  ? $_POST['delivery_type']  : 'fuel';  $supplier  = trim($_POST['supplier']  ?? '');  $product  = trim($_POST['product']  ?? '');  $quantity  = (float)($_POST['quantity']  ?? 0);  $unit  = trim($_POST['unit']  ?? 'L');  $delivery_date = trim($_POST['delivery_date'] ?? '');  $dr_number  = trim($_POST['dr_number']  ?? '');  $notes  = trim($_POST['notes']  ?? '');  // Validation  $errors = [];  if (!$supplier)  $errors[] = 'Supplier is required.';  if (!$product)  $errors[] = 'Product is required.';  if ($quantity <= 0)  $errors[] = 'Quantity must be greater than zero.';  if (!$delivery_date) $errors[] = 'Delivery date is required.';  if ($errors) {  $msg  = implode(' ', $errors);  $msg_type = 'error';  } else {  // Auto-generate delivery_ref: DR-YYYYMMDD-XXXXX (unique per record)  $date_part  = date('Ymd');  $rand_part  = strtoupper(substr(uniqid(), -5));  $delivery_ref = "DR-{$date_part}-{$rand_part}";  // ── Batch ID: same date + same type at same station → same batch ──────  // Fuel  → FBATCH-YYYYMMDD-XXX  // Merchandise → MBATCH-YYYYMMDD-XXX  $batch_prefix = ($delivery_type === 'fuel' ? 'FBATCH-' : 'MBATCH-') . date('Ymd', strtotime($delivery_date)) . '-';  $batch_table  = 'deliveries_oversight'; // manager encodes always go here  try {  $bs = $pdo->prepare("  SELECT batch_id  FROM deliveries_oversight  WHERE batch_id LIKE ?  AND station_id = ?  AND DATE(delivery_date) = ?  AND delivery_type = ?  LIMIT 1  ");  $bs->execute([$batch_prefix . '%', $station_id, $delivery_date, $delivery_type]);  $existing_batch = $bs->fetchColumn();  if ($existing_batch) {  $batch_id = $existing_batch;  } else {  $bn = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(batch_id,'-',-1) AS UNSIGNED)) FROM deliveries_oversight WHERE batch_id LIKE ?");  $bn->execute([$batch_prefix . '%']);  $batch_id = $batch_prefix . str_pad((int)$bn->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);  }  } catch (Exception $e) {  // Fallback: generate a unique batch ID  $batch_id = $batch_prefix . '001';  }  try {  $stmt = $pdo->prepare("  INSERT INTO deliveries_oversight  (delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,  delivery_date, dr_number, encoded_by, station_id, status,  admin_notes, created_at, updated_at)  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Validation', ?, NOW(), NOW())  ");  $stmt->execute([  $delivery_type, $delivery_ref, $batch_id, $supplier, $product,  $quantity, $unit, $delivery_date,  $dr_number ?: null,  $me['id'], $station_id,  $notes ?: null,  ]);  // Activity log (if function exists)  if (function_exists('log_activity')) {  log_activity($pdo, $me['id'], 'Encode Delivery',  "Ref: {$delivery_ref} | Batch: {$batch_id} | {$product} | {$quantity} {$unit} | Supplier: {$supplier} | By: {$me['name']}");  }  // ── Audit log ──  try {  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';  $detail = "Manager encoded delivery | Ref: {$delivery_ref} | Batch: {$batch_id} | Supplier: {$supplier} | Product: {$product} | Qty: {$quantity} {$unit} | Date: {$delivery_date}" . ($dr_number ? " | DR#: {$dr_number}" : '');  $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', 'Create', ?, 'deliveries', ?, 'Success', ?, ?, NOW())")  ->execute([$me['id'], $detail, null, $ip, $ua]);  } catch (Exception $e) {}  $typeLabel = $delivery_type === 'fuel' ? ' Fuel' : ' Merchandise';  $msg  = "New {$typeLabel} delivery encoded. Status: Pending Validation. Reference: <strong>" . htmlspecialchars($delivery_ref) . "</strong> | Batch: <strong>" . htmlspecialchars($batch_id) . "</strong>";  $msg_type = 'success';  } catch (Exception $e) {  $msg  = 'Database error: ' . $e->getMessage();  $msg_type = 'error';  }  }
}  // ── Fetch recent deliveries by this manager (last 30 days) ────────────────────
$recent_deliveries = [];
try {  $stmt = $pdo->prepare("  SELECT d.*, u.name AS encoded_by_name  FROM deliveries_oversight d  LEFT JOIN users u ON d.encoded_by = u.id  WHERE d.encoded_by = ? AND d.station_id = ?  AND d.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)  ORDER BY d.created_at DESC  LIMIT 50  ");  $stmt->execute([$me['id'], $station_id]);  $recent_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}  $station_name = 'Unknown Station';
try {  $s = $pdo->prepare('SELECT name FROM stations WHERE id = ? LIMIT 1');  $s->execute([$station_id]);  $station_name = $s->fetchColumn() ?: $station_name;
} catch (Exception $e) {}  include __DIR__ . '/../partials/header.php';
?>
<style>
:root {  --blue:  #002F6C;  --red:  #E30613;  --green:  #16a34a;  --orange: #d97706;  --gray:  #64748b;  --light:  #f8fafc;
}  /* ── Page Header ── */
.enc-page-head { margin-bottom: 22px; }
.enc-page-head h1 { font-size: 1.5rem; font-weight: 700; color: var(--blue); margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
.enc-page-head p  { font-size: 13px; color: var(--gray); margin: 0; }  /* ── Process Steps ── */
.process-steps { display: flex; align-items: center; gap: 0; margin-bottom: 24px; background: #fff; border-radius: 10px; padding: 16px 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); overflow:hidden; }
.step { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.step-num { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
.step.done  .step-num { background: #d1fae5; color: #065f46; }
.step.active .step-num { background: var(--blue); color: #fff; }
.step.pending .step-num { background: #f1f5f9; color: #94a3b8; }
.step-label { font-size: 12px; font-weight: 600; }
.step.done  .step-label { color: #065f46; }
.step.active .step-label { color: var(--blue); }
.step.pending .step-label { color: #94a3b8; }
.step-arrow { margin: 0 12px; color: #cbd5e1; font-size: 14px; flex-shrink: 0; }  /* ── Alert Banner ── */
.enc-alert { display: flex; align-items: flex-start; gap: 10px; padding: 13px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: 500; }
.enc-alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.enc-alert-error  { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.enc-alert i { margin-top: 1px; flex-shrink: 0; }  /* ── Card ── */
.enc-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.07); border: 1px solid #e9ecef; margin-bottom: 24px; }
.enc-card-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 22px; border-bottom: 1px solid #e9ecef; flex-wrap: wrap; gap: 8px; }
.enc-card-title { font-size: 1rem; font-weight: 700; color: var(--blue); display: flex; align-items: center; gap: 8px; margin: 0; }
.enc-card-body { padding: 22px; }  /* ── Form ── */
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group label { font-size: 12px; font-weight: 600; color: var(--gray); text-transform: uppercase; letter-spacing: .4px; }
.form-group label .req { color: var(--red); }
.form-group input,
.form-group select,
.form-group textarea {  padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 7px;  font-size: 13px; color: #333; background: #fff;  transition: border-color .2s, box-shadow .2s;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {  outline: none; border-color: var(--blue);  box-shadow: 0 0 0 3px rgba(0,47,108,.1);
}
.form-group textarea { resize: vertical; min-height: 80px; }
.form-section-title { font-size: 11px; font-weight: 700; color: var(--gray); text-transform: uppercase; letter-spacing: .6px; margin: 18px 0 10px; padding-bottom: 6px; border-bottom: 1px solid #f0f0f0; }  /* ── Buttons ── */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all .2s; text-decoration: none; }
.btn-primary { background: var(--blue); color: #fff; }
.btn-primary:hover { background: #003d8f; }
.btn-outline { background: #fff; color: var(--blue); border: 1px solid var(--blue); }
.btn-outline:hover { background: #e8f0fe; }
.btn-sm { padding: 5px 12px; font-size: 12px; }  /* ── Table ── */
.enc-table-wrap { overflow:hidden; }
table.enc-dt { width: 100%; border-collapse: collapse; }
table.enc-dt th { background: var(--light); padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 700; color: var(--gray); border-bottom: 2px solid #e5e7eb; white-space: nowrap; text-transform: uppercase; letter-spacing: .4px; }
table.enc-dt td { padding: 10px 14px; font-size: 13px; color: #333; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
table.enc-dt tr:hover td { background: #f8fafc; }  /* ── Badges ── */
.badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
.badge-pending  { background: #fef3c7; color: #92400e; }
.badge-validated { background: #d1fae5; color: #065f46; }
.badge-flagged  { background: #fee2e2; color: #991b1b; }
.badge-fuel  { background: #dbeafe; color: #1e40af; }
.badge-merch  { background: #ede9fe; color: #5b21b6; }  /* ── Empty state ── */
.empty-state { text-align: center; padding: 40px 20px; color: var(--gray); }
.empty-state i { font-size: 36px; margin-bottom: 10px; opacity: .4; display: block; }  /* ── Info box ── */
.info-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #1e40af; display: flex; align-items: flex-start; gap: 10px; margin-bottom: 20px; }
.info-box i { margin-top: 1px; flex-shrink: 0; }  @media (max-width: 768px) {  .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; }  .process-steps { flex-direction: column; align-items: flex-start; }  .step-arrow { transform: rotate(90deg); }
}
</style>  <!-- Page Header -->
<div class="enc-page-head">  <h1><i class="fas fa-truck-loading" style="color:var(--blue);"></i> Encode Delivery Record</h1>  <p>Submit a new delivery record for Admin validation. Station: <strong><?php echo htmlspecialchars($station_name); ?></strong></p>
</div>  <!-- Alert Banner -->
<?php if ($msg): ?>
<div class="enc-alert enc-alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>">  <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'times-circle'; ?>"></i>  <span><?php echo $msg; ?></span>
</div>
<?php endif; ?>  <!-- Info Box -->
<div class="info-box">  <i class="fas fa-info-circle"></i>  <span>Delivery records you encode here will appear in the <strong>Admin's Deliveries Oversight</strong> page with status <strong>Pending Validation</strong>. The Admin will review and validate or flag each entry.</span>
</div>  <!-- Encode Form Card -->
<div class="enc-card">  <div class="enc-card-head">  <h3 class="enc-card-title"><i class="fas fa-plus-circle"></i> New Delivery Record</h3>  <span style="font-size:12px;color:var(--gray);">Encoded by: <strong><?php echo htmlspecialchars($me['name'] ?? $me['username'] ?? 'Manager'); ?></strong></span>  </div>  <div class="enc-card-body">  <form method="POST" action="" id="encodeForm">  <input type="hidden" name="action" value="encode_delivery">  <div class="form-section-title"><i class="fas fa-tag"></i> Delivery Classification</div>  <div class="form-grid-2">  <div class="form-group">  <label>Delivery Type <span class="req">*</span></label>  <select name="delivery_type" id="deliveryType" onchange="updateUnitOptions()">  <option value="fuel"  <?php echo ($_POST['delivery_type'] ?? '') === 'fuel'  ? 'selected' : ''; ?>>Fuel</option>  <option value="merchandise"  <?php echo ($_POST['delivery_type'] ?? '') === 'merchandise'  ? 'selected' : ''; ?>>Merchandise</option>  </select>  </div>  <div class="form-group">  <label>DR Number <span style="color:var(--gray);font-weight:400;">(optional)</span></label>  <input type="text" name="dr_number" placeholder="e.g. DR-2026-00123"  value="<?php echo htmlspecialchars($_POST['dr_number'] ?? ''); ?>">  </div>  </div>  <div class="form-section-title"><i class="fas fa-box"></i> Product Details</div>  <div class="form-grid-2">  <div class="form-group">  <label>Supplier <span class="req">*</span></label>  <input type="text" name="supplier" placeholder="e.g. Petron Corporation"  value="<?php echo htmlspecialchars($_POST['supplier'] ?? ''); ?>" required>  </div>  <div class="form-group">  <label>Product <span class="req">*</span></label>  <input type="text" name="product" placeholder="e.g. Diesel, Unleaded 95, Motor Oil"  value="<?php echo htmlspecialchars($_POST['product'] ?? ''); ?>" required>  </div>  </div>  <div class="form-grid-3">  <div class="form-group">  <label>Quantity <span class="req">*</span></label>  <input type="number" name="quantity" placeholder="e.g. 5000" min="0.001" step="0.001"  value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>" required>  </div>  <div class="form-group">  <label>Unit <span class="req">*</span></label>  <select name="unit" id="unitSelect">  <option value="L"  <?php echo ($_POST['unit'] ?? 'L') === 'L'  ? 'selected' : ''; ?>>Liters (L)</option>  <option value="pcs"  <?php echo ($_POST['unit'] ?? '') === 'pcs'  ? 'selected' : ''; ?>>Pieces (pcs)</option>  <option value="kg"  <?php echo ($_POST['unit'] ?? '') === 'kg'  ? 'selected' : ''; ?>>Kilograms (kg)</option>  <option value="boxes" <?php echo ($_POST['unit'] ?? '') === 'boxes'  ? 'selected' : ''; ?>>Boxes</option>  <option value="drums" <?php echo ($_POST['unit'] ?? '') === 'drums'  ? 'selected' : ''; ?>>Drums</option>  <option value="cans"  <?php echo ($_POST['unit'] ?? '') === 'cans'  ? 'selected' : ''; ?>>Cans</option>  </select>  </div>  <div class="form-group">  <label>Delivery Date <span class="req">*</span></label>  <input type="date" name="delivery_date"  value="<?php echo htmlspecialchars($_POST['delivery_date'] ?? date('Y-m-d')); ?>" required>  </div>  </div>  <div class="form-section-title"><i class="fas fa-sticky-note"></i> Additional Notes</div>  <div class="form-group">  <label>Notes <span style="color:var(--gray);font-weight:400;">(optional)</span></label>  <textarea name="notes" placeholder="e.g. Delivery received in good condition. Verified against physical DR."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>  </div>  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f0;">  <a href="manager_dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>  <button type="submit" class="btn btn-primary" id="submitBtn">  <i class="fas fa-paper-plane"></i> Submit for Admin Validation  </button>  </div>  </form>  </div>
</div>  <!-- Recent Deliveries Table -->
<div class="enc-card">  <div class="enc-card-head">  <h3 class="enc-card-title"><i class="fas fa-history"></i> My Recent Deliveries <span style="font-size:12px;font-weight:400;color:var(--gray);">(Last 30 days)</span></h3>  <span style="font-size:12px;color:var(--gray);"><?php echo count($recent_deliveries); ?> record(s)</span>  </div>  <div class="enc-card-body" style="padding:0;">  <div class="enc-table-wrap">  <?php if (empty($recent_deliveries)): ?>  <div class="empty-state">  <i class="fas fa-truck"></i>  No delivery records found in the last 30 days.<br>  <small>Use the form above to encode your first delivery.</small>  </div>  <?php else: ?>  <table class="enc-dt">  <thead>  <tr>  <th>#</th>  <th>Reference</th>  <th>Type</th>  <th>DR Number</th>  <th>Supplier</th>  <th>Product</th>  <th>Quantity</th>  <th>Delivery Date</th>  <th>Status</th>  <th>Encoded At</th>  </tr>  </thead>  <tbody>  <?php foreach ($recent_deliveries as $d): ?>  <?php  $statusClass = [  'Pending Validation' => 'badge-pending',  'Validated'  => 'badge-validated',  'Flagged'  => 'badge-flagged',  ][$d['status']] ?? '';  $typeClass = $d['delivery_type'] === 'fuel' ? 'badge-fuel' : 'badge-merch';  $typeLabel = $d['delivery_type'] === 'fuel' ? 'Fuel' : 'Merchandise';  $qty = number_format((float)$d['quantity'], 2) . ' ' . htmlspecialchars($d['unit']);  ?>  <tr>  <td><?php echo (int)$d['id']; ?></td>  <td style="font-size:11px;color:var(--gray);font-family:monospace;"><?php echo htmlspecialchars($d['delivery_ref']); ?></td>  <td><span class="badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span></td>  <td><?php echo htmlspecialchars($d['dr_number'] ?: '—'); ?></td>  <td><?php echo htmlspecialchars($d['supplier']); ?></td>  <td><?php echo htmlspecialchars($d['product']); ?></td>  <td><?php echo $qty; ?></td>  <td><?php echo htmlspecialchars(date('M j, Y', strtotime($d['delivery_date']))); ?></td>  <td><span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($d['status']); ?></span></td>  <td style="font-size:11px;color:var(--gray);"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($d['created_at']))); ?></td>  </tr>  <?php endforeach; ?>  </tbody>  </table>  <?php endif; ?>  </div>  </div>
</div>  <script>
function updateUnitOptions() {  const type = document.getElementById('deliveryType').value;  const unitSelect = document.getElementById('unitSelect');  const fuelUnits = ['L', 'drums'];  const merchUnits = ['pcs', 'kg', 'boxes', 'cans', 'L'];  const units = type === 'fuel' ? fuelUnits : merchUnits;  const labels = {  'L': 'Liters (L)', 'drums': 'Drums', 'pcs': 'Pieces (pcs)',  'kg': 'Kilograms (kg)', 'boxes': 'Boxes', 'cans': 'Cans'  };  unitSelect.innerHTML = '';  units.forEach(u => {  const opt = document.createElement('option');  opt.value = u;  opt.textContent = labels[u] || u;  unitSelect.appendChild(opt);  });
}  // Prevent double-submit
document.getElementById('encodeForm').addEventListener('submit', function() {  const btn = document.getElementById('submitBtn');  btn.disabled = true;  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting&hellip;';
});  // Init unit options on load
updateUnitOptions();
<?php
// Restore selected unit after POST
$savedUnit = htmlspecialchars($_POST['unit'] ?? 'L');
?>
document.addEventListener('DOMContentLoaded', function() {  const savedUnit = '<?php echo $savedUnit; ?>';  const unitSelect = document.getElementById('unitSelect');  for (let i = 0; i < unitSelect.options.length; i++) {  if (unitSelect.options[i].value === savedUnit) {  unitSelect.selectedIndex = i;  break;  }  }
});
</script>  <?php include __DIR__ . '/../partials/footer.php'; ?>
