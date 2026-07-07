<?php
$page_id = 'fuel_reconciliation_manager';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/validate_foreign_keys_helper.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Manager/Admin can validate
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// Auto-add status column if it doesn't exist
try {
    $pdo->exec("ALTER TABLE fuel_daily_readings ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'pending'");
} catch (Exception $e) {
    // Column might already exist, ignore
}

// Handle reconciliation validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'validate_reconciliation') {
        $reading_id = (int)($_POST['recon_id'] ?? 0);
        $calibration_adjustment = (float)($_POST['calibration_adjustment'] ?? 0);
        $manager_notes = trim($_POST['manager_notes'] ?? '');
        $approved = isset($_POST['approve']) ? 1 : 0;
        
        try {
            // Get the STAFF reading from fuel_daily_readings
            $stmt = $pdo->prepare("SELECT fdr.*, fp.fuel_type_id FROM fuel_daily_readings fdr LEFT JOIN fuel_pumps fp ON fdr.pump_id = fp.id WHERE fdr.id=?");
            $stmt->execute([$reading_id]);
            $reading = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reading) {
                $msg = "❌ Reading record not found.";
            } else if ($approved) {
                // MANAGER APPROVED: Create reconciliation record with status='Verified' for Admin
                $present_reading = $reading['current_reading'] ?? 0;
                $previous_reading = $reading['previous_reading'] ?? 0;
                $calibration = $reading['calibration'] ?? 0;
                $sales_liters = $reading['sales_liters'] ?? 0;
                
                // Prepare reconciliation data for validation
                $reconciliation_data = [
                    'station_id' => $reading['station_id'],
                    'pump_id' => $reading['pump_id'],
                    'fuel_type_id' => $reading['fuel_type_id'],
                    'verified_by' => $me['id'] ?? null
                ];
                
                // Validate the data before insertion
                $validation = validateFuelReconciliationData($pdo, $reconciliation_data);
                if (!$validation['valid']) {
                    $msg = "❌ Validation Error: " . implode(', ', $validation['errors']);
                } else {
                    // Use cleaned data for insertion
                    $clean_data = $validation['cleaned_data'];
                    
                    // Get price
                    $price_per_liter = 65.50; // Default price
                    
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO fuel_reconciliation 
                        (station_id, fuel_type_id, pump_id, reconciliation_date, 
                         previous_reading, present_reading, calibration, sales_liters, 
                         price_per_liter, status, notes, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Verified', ?, NOW())
                    ");
                    $insert_stmt->execute([
                        $clean_data['station_id'],
                        $clean_data['fuel_type_id'],
                        $clean_data['pump_id'],
                        $reading['reading_date'],
                        $previous_reading,
                        $present_reading,
                        $calibration + $calibration_adjustment,
                        $sales_liters,
                        $price_per_liter,
                        $manager_notes
                    ]);
                    
                    $msg = "✅ Reading approved and reconciliation record created successfully.";
                    
                    // Mark the staff reading as 'approved' so it disappears from pending list
                    $pdo->prepare("UPDATE fuel_daily_readings SET status='approved' WHERE id=?")->execute([$reading_id]);
                    
                    log_activity($pdo, $me['id'], 'Manager Approved Reading', 
                        "Reading #{$reading_id} approved and moved to reconciliation | Ready for Admin finalization");
                    
                    $msg = "✅ Reading APPROVED and ready for Admin finalization!";
                }
            } else {
                // Rejected - mark as rejected and remove from pending list
                $pdo->prepare("UPDATE fuel_daily_readings SET status='rejected' WHERE id=?")->execute([$reading_id]);
                
                log_activity($pdo, $me['id'], 'Manager Rejected Reading', 
                    "Reading #{$reading_id} rejected | Notes: $manager_notes");
                
                $msg = "❌ Reading REJECTED. Marked in logs.";
            }
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
}

// Fetch STAFF SUBMISSIONS from fuel_daily_readings (not fuel_reconciliation!)
$reconciliations = [];
try {
    if ($role === 'superadmin') {
        $stmt = $pdo->query("
            SELECT fdr.*, 
                   s.name as station_name, 
                   ft.name as product_name,
                   fp.pump_number,
                   u.username as created_by_name
            FROM fuel_daily_readings fdr
            LEFT JOIN stations s ON fdr.station_id = s.id
            LEFT JOIN fuel_pumps fp ON fdr.pump_id = fp.id
            LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
            LEFT JOIN users u ON fdr.user_id = u.id
            WHERE (fdr.status IS NULL OR fdr.status = 'pending')
            ORDER BY fdr.reading_date DESC, fdr.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT fdr.*, 
                   s.name as station_name, 
                   ft.name as product_name,
                   fp.pump_number,
                   u.username as created_by_name
            FROM fuel_daily_readings fdr
            LEFT JOIN stations s ON fdr.station_id = s.id
            LEFT JOIN fuel_pumps fp ON fdr.pump_id = fp.id
            LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
            LEFT JOIN users u ON fdr.user_id = u.id
            WHERE fdr.station_id = ? AND (fdr.status IS NULL OR fdr.status = 'pending')
            ORDER BY fdr.reading_date DESC, fdr.created_at DESC
        ");
        $stmt->execute([$station_id]);
    }
    $reconciliations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reconciliations = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f8fafc; }
  
  .fr-wrapper { max-width: 1200px; margin: 0 auto; padding: 24px; }
  
  .fr-header { 
    background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%); 
    color: white; padding: 40px 32px; border-radius: 16px; margin-bottom: 32px; 
    box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3); 
  }
  .fr-header-content { display: flex; align-items: center; gap: 16px; }
  .fr-header-icon { font-size: 42px; }
  .fr-header h1 { font-size: 32px; font-weight: 700; margin-bottom: 6px; color: white !important; }
  .fr-header p { font-size: 14px; opacity: 0.85; color: white !important; }
  
  .fr-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
  .fr-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
  .fr-alert-error { background: #fee2e2; color: #7f1d1d; border: 1px solid #fecaca; }
  
  .fr-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px; }
  
  .fr-card { 
    background: white; border-radius: 12px; padding: 24px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-top: 4px solid #06b6d4; 
  }
  .fr-card-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px; }
  .fr-card-title { font-size: 18px; font-weight: 700; color: #0f172a; }
  .fr-card-badge { 
    background: #cffafe; color: #164e63; padding: 4px 12px; 
    border-radius: 20px; font-size: 12px; font-weight: 600; 
  }
  
  .fr-card-body { display: grid; gap: 12px; }
  .fr-info { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
  .fr-info-label { color: #64748b; font-size: 13px; font-weight: 600; }
  .fr-info-value { color: #0f172a; font-weight: 500; font-family: 'Courier New', monospace; }
  
  .fr-calc-box { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 12px; margin: 12px 0; font-size: 12px; }
  .fr-calc-label { color: #0369a1; font-weight: 600; margin-bottom: 6px; }
  .fr-calc-line { color: #0369a1; margin: 4px 0; font-family: 'Courier New', monospace; }
  .fr-calc-result { color: #0c4a6e; font-weight: 700; margin-top: 6px; border-top: 1px solid #bae6fd; padding-top: 6px; }
  
  .fr-form { display: grid; gap: 16px; margin-top: 16px; padding-top: 16px; border-top: 2px solid #f1f5f9; }
  .fr-form label { font-size: 13px; font-weight: 600; color: #334155; display: block; margin-bottom: 6px; }
  .fr-form input, .fr-form textarea { 
    width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; 
    border-radius: 8px; font-size: 13px; font-family: inherit;
  }
  .fr-form textarea { resize: none; height: 70px; }
  .fr-form input:focus, .fr-form textarea:focus { outline: none; border-color: #06b6d4; box-shadow: 0 0 0 3px rgba(6,182,212,0.1); }
  
  .fr-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 16px; }
  .fr-btn { 
    padding: 10px 16px; border: 0; border-radius: 8px; cursor: pointer; 
    font-weight: 600; font-size: 13px;
  }
  .fr-btn-approve { background: #003d7a; color: white; }
  .fr-btn-approve:hover { background: #002d5c; }
  .fr-btn-reject { background: #f3f4f6; color: #374151; }
  .fr-btn-reject:hover { background: #e5e7eb; }
  
  .fr-empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
</style>

<div class="fr-wrapper">
  <div class="fr-header">
    <div class="fr-header-content">
      <div class="fr-header-icon">⛽</div>
      <div>
        <h1>Fuel Reconciliation - Manager Validation</h1>
        <p>Manager - Validate fuel readings and calibration adjustments</p>
      </div>
    </div>
  </div>
  
  <?php if($msg): ?>
    <div class="fr-alert <?php echo strpos($msg, '✅') !== false ? 'fr-alert-success' : 'fr-alert-error'; ?>">
      <i class="fas <?php echo strpos($msg, '✅') !== false ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
      <?php echo htmlspecialchars($msg); ?>
    </div>
  <?php endif; ?>
  
  <?php if(empty($reconciliations)): ?>
    <div class="fr-empty">
      <div style="font-size: 48px; margin-bottom: 12px;">✓</div>
      <div style="font-size: 16px; font-weight: 500;">No pending reconciliations</div>
      <div style="font-size: 13px; margin-top: 6px; opacity: 0.7;">All fuel reconciliations have been validated.</div>
    </div>
  <?php else: ?>
    <div class="fr-cards">
      <?php foreach($reconciliations as $rec): ?>
        <div class="fr-card">
          <div class="fr-card-header">
            <div>
              <div class="fr-card-title"><?php echo htmlspecialchars($rec['product_name'] ?? 'Fuel'); ?></div>
              <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;"><?php echo htmlspecialchars($rec['station_name']); ?></div>
            </div>
            <div class="fr-card-badge">PENDING</div>
          </div>
          
          <div class="fr-card-body">
            <div class="fr-info">
              <div class="fr-info-label">Date</div>
              <div class="fr-info-value"><?php echo date('M d, Y', strtotime($rec['reading_date'])); ?></div>
            </div>
            
            <!-- Reconciliation Formula Display -->
            <div class="fr-calc-box">
              <div class="fr-calc-label">📊 Reconciliation Formula:</div>
              <div class="fr-calc-line">Present Reading: <?php echo number_format($rec['current_reading'], 2); ?> L</div>
              <div class="fr-calc-line">− Previous Reading: <?php echo number_format($rec['previous_reading'], 2); ?> L</div>
              <div class="fr-calc-line">= Raw Difference: <?php echo number_format($rec['current_reading'] - $rec['previous_reading'], 2); ?> L</div>
              <div class="fr-calc-line">− Calibration: <?php echo number_format($rec['calibration'] ?? 0, 2); ?> L</div>
              <div class="fr-calc-result">= Adjusted Diff: <?php echo number_format(($rec['current_reading'] - $rec['previous_reading']) - ($rec['calibration'] ?? 0), 2); ?> L</div>
              <div class="fr-calc-line" style="margin-top: 8px;">× Unit Price: ₱<?php echo number_format(65.50, 2); ?>/L</div>
              <div class="fr-calc-result" style="font-size: 14px;">= Calculated Sales Amount: ₱<?php echo number_format((($rec['current_reading'] - $rec['previous_reading']) - ($rec['calibration'] ?? 0)) * 65.50, 2); ?></div>
            </div>
            
            <div class="fr-info">
              <div class="fr-info-label">Shift</div>
              <div class="fr-info-value"><?php echo htmlspecialchars($rec['shift'] ?? 'N/A'); ?></div>
            </div>
            <div class="fr-info">
              <div class="fr-info-label">Pump</div>
              <div class="fr-info-value"><?php echo htmlspecialchars($rec['pump_number'] ?? 'N/A'); ?></div>
            </div>
            <div class="fr-info">
              <div class="fr-info-label">Recorded By</div>
              <div class="fr-info-value"><?php echo htmlspecialchars($rec['created_by_name'] ?? 'Unknown'); ?></div>
            </div>
            
            <form method="post" class="fr-form">
              <input type="hidden" name="action" value="validate_reconciliation">
              <input type="hidden" name="recon_id" value="<?php echo $rec['id']; ?>">
              
              <div>
                <label>Calibration Adjustment (liters) *</label>
                <input type="number" name="calibration_adjustment" placeholder="Enter adjustment in liters" step="0.01" value="<?php echo $rec['calibration_adjustment'] ?? 0; ?>" required>
                <small style="color: #94a3b8; margin-top: 4px; display: block;">Amount to subtract due to gauge/tank calibration errors</small>
              </div>
              
              <div>
                <label>Manager Validation Notes</label>
                <textarea name="manager_notes" placeholder="e.g., Calibration verified, gauge within tolerance"></textarea>
              </div>
              
              <div class="fr-actions">
                <button type="submit" name="reject" value="reject" class="fr-btn fr-btn-reject">
                  <i class="fas fa-times"></i> Reject
                </button>
                <button type="submit" name="approve" value="approve" class="fr-btn fr-btn-approve">
                  <i class="fas fa-check"></i> Approve
                </button>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  
  <div style="margin-top: 40px; padding: 20px; background: #cffafe; border-left: 4px solid #06b6d4; border-radius: 8px;">
    <strong style="color: #164e63;">📊 Fuel Reconciliation Formula:</strong>
    <div style="margin-top: 12px; color: #164e63; font-size: 13px; line-height: 2;">
      <code style="background: white; padding: 12px; border-radius: 6px; display: block;">
        Adjusted Variance (L) = (Present Reading - Previous Reading - Calibration) × Unit Price/L
      </code>
    </div>
    <ul style="margin-top: 12px; margin-left: 20px; color: #164e63; font-size: 13px; line-height: 1.8;">
      <li>Staff enters present and previous readings</li>
      <li>Manager validates and sets calibration adjustment</li>
      <li>System calculates adjusted variance</li>
      <li>Converts liters to monetary value</li>
      <li>Admin finalizes reconciliation report</li>
    </ul>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
