<?php
/**
 * DELETE FUEL MANAGEMENT HISTORICAL DATA
 * 
 * This script deletes all historical records from fuel management:
 * - Fuel daily readings (pump readings)
 * - Fuel deliveries
 * - Fuel transactions
 * - Fuel adjustments
 * - Related audit trails
 * 
 * USE WITH CAUTION: This is PERMANENT and cannot be undone!
 */

session_start();
require_once __DIR__ . '/backend/lib.php';
require_once __DIR__ . '/public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// Only allow Manager, Admin, or Superadmin
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    die('Access Denied. Only Managers, Admins, or Superadmins can delete fuel history.');
}

// Handle POST request to delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $confirm_text = trim($_POST['confirm_text'] ?? '');
    
    if ($confirm_text !== 'DELETE FUEL HISTORY') {
        $_SESSION['error'] = 'Confirmation text incorrect. No data was deleted.';
        header('Location: delete_fuel_history.php');
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get counts before deletion for reporting
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_daily_readings WHERE station_id = ?");
        $stmt->execute([$station_id]);
        $readings_count = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries WHERE station_id = ?");
        $stmt->execute([$station_id]);
        $deliveries_count = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ?");
        $stmt->execute([$station_id]);
        $transactions_count = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ?");
        $stmt->execute([$station_id]);
        $adjustments_count = $stmt->fetchColumn();
        
        // DELETE HISTORICAL DATA (keep current inventory levels)
        
        // 1. Delete fuel daily readings
        $pdo->prepare("DELETE FROM fuel_daily_readings WHERE station_id = ?")->execute([$station_id]);
        
        // 2. Delete fuel deliveries
        $pdo->prepare("DELETE FROM fuel_deliveries WHERE station_id = ?")->execute([$station_id]);
        
        // 3. Delete fuel transactions
        $pdo->prepare("DELETE FROM fuel_transactions WHERE station_id = ?")->execute([$station_id]);
        
        // 4. Delete fuel adjustments
        $pdo->prepare("DELETE FROM fuel_adjustments WHERE station_id = ?")->execute([$station_id]);
        
        // 5. RESET PUMP READINGS - Clear all beginning/ending readings to 0.00
        $pdo->prepare("
            UPDATE fuel_pumps 
            SET last_reading = 0.00,
                beginning_reading = 0.00,
                ending_reading = 0.00,
                last_reading_date = NULL
            WHERE station_id = ?
        ")->execute([$station_id]);
        
        // 6. Delete fuel audit trail (if exists)
        try {
            $pdo->prepare("DELETE FROM fuel_audit_trail WHERE station_id = ?")->execute([$station_id]);
        } catch (Exception $e) {
            // Table may not exist, continue
        }
        
        // 7. Delete fuel transaction audit (if exists)
        try {
            $pdo->prepare("DELETE FROM fuel_transaction_audit WHERE station_id = ?")->execute([$station_id]);
        } catch (Exception $e) {
            // Table may not exist, continue
        }
        
        // 8. Delete fuel variance reports (if exists)
        try {
            $pdo->prepare("DELETE FROM fuel_variance_reports WHERE station_id = ?")->execute([$station_id]);
        } catch (Exception $e) {
            // Table may not exist, continue
        }
        
        // 9. Delete fuel readings (from encoding module if exists)
        try {
            $pdo->prepare("DELETE FROM fuel_readings WHERE station_id = ?")->execute([$station_id]);
        } catch (Exception $e) {
            // Table may not exist, continue
        }
        
        // 10. Delete fuel stock in records (if exists)
        try {
            $pdo->prepare("DELETE FROM fuel_stock_in WHERE station_id = ?")->execute([$station_id]);
        } catch (Exception $e) {
            // Table may not exist, continue
        }
        
        // 11. Delete fuel batches (if exists)
        try {
            $pdo->prepare("DELETE FROM fuel_batches WHERE station_id = ?")->execute([$station_id]);
        } catch (Exception $e) {
            // Table may not exist, continue
        }
        
        // 12. Delete fuel price log history (if exists)
        try {
            $pdo->prepare("DELETE FROM fuel_price_log WHERE station_id = ?")->execute([$station_id]);
        } catch (Exception $e) {
            // Table may not exist, continue
        }
        
        // Log activity
        log_activity(
            $pdo, 
            $me['id'], 
            'Delete Fuel History', 
            "Deleted all fuel historical data for station {$station_id}: {$readings_count} readings, {$deliveries_count} deliveries, {$transactions_count} transactions, {$adjustments_count} adjustments"
        );
        
        $pdo->commit();
        
        $_SESSION['success'] = "Successfully deleted fuel history AND reset all pump readings to 0.00: {$readings_count} pump readings, {$deliveries_count} deliveries, {$transactions_count} transactions, {$adjustments_count} adjustments.";
        header('Location: manager_fuel_management_complete.php');
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Error deleting fuel history: ' . $e->getMessage();
        header('Location: delete_fuel_history.php');
        exit;
    }
}

require_once __DIR__ . '/partials/header.php';
?>

<style>
.delete-container {
    max-width: 700px;
    margin: 40px auto;
    padding: 0;
}
.delete-card {
    background: #fff;
    border: 2px solid #dc2626;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
}
.delete-header {
    background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
    padding: 24px 28px;
    color: #fff;
}
.delete-header h1 {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
}
.delete-header .subtitle {
    margin-top: 8px;
    font-size: 14px;
    color: #fecaca;
}
.delete-body {
    padding: 28px;
}
.warning-box {
    background: #fef2f2;
    border: 2px solid #fecaca;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 24px;
}
.warning-box h2 {
    color: #991b1b;
    font-size: 16px;
    font-weight: 700;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.warning-box ul {
    margin: 0;
    padding-left: 20px;
    color: #7f1d1d;
}
.warning-box li {
    margin-bottom: 6px;
    font-size: 14px;
}
.info-box {
    background: #eff6ff;
    border: 2px solid #bfdbfe;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 24px;
}
.info-box h2 {
    color: #1e40af;
    font-size: 16px;
    font-weight: 700;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.info-box ul {
    margin: 0;
    padding-left: 20px;
    color: #1e3a8a;
}
.info-box li {
    margin-bottom: 6px;
    font-size: 14px;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #374151;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.form-group input[type="text"] {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Courier New', monospace;
    font-weight: 700;
    box-sizing: border-box;
}
.form-group input[type="text"]:focus {
    outline: none;
    border-color: #dc2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
}
.form-group .hint {
    margin-top: 8px;
    font-size: 12px;
    color: #6b7280;
}
.btn-group {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}
.btn {
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    border: none;
}
.btn-cancel {
    background: #f1f5f9;
    color: #475569;
    border: 2px solid #cbd5e1;
}
.btn-cancel:hover {
    background: #e2e8f0;
}
.btn-delete {
    background: #dc2626;
    color: #fff;
    border: 2px solid #dc2626;
}
.btn-delete:hover {
    background: #991b1b;
    border-color: #991b1b;
}
.btn-delete:disabled {
    background: #cbd5e1;
    border-color: #cbd5e1;
    color: #94a3b8;
    cursor: not-allowed;
}
</style>

<div class="delete-container">
    <div class="delete-card">
        <div class="delete-header">
            <h1><i class="fas fa-trash-alt"></i> Delete Fuel Management History</h1>
            <div class="subtitle">Permanently remove all historical fuel data records</div>
        </div>
        
        <div class="delete-body">
            <?php if (isset($_SESSION['error'])): ?>
            <div style="background:#fef2f2;color:#991b1b;padding:14px 18px;border-radius:8px;margin-bottom:20px;border:2px solid #fecaca;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
            <?php endif; ?>
            
            <div class="warning-box">
                <h2><i class="fas fa-exclamation-triangle"></i> WARNING: This Action is PERMANENT</h2>
                <ul>
                    <li><strong>Fuel Pump Readings</strong> - All daily pump reading history + RESET all pumps to 0.00</li>
                    <li><strong>Fuel Deliveries</strong> - All fuel delivery records</li>
                    <li><strong>Fuel Transactions</strong> - All fuel sale transaction history</li>
                    <li><strong>Fuel Adjustments</strong> - All adjustment records</li>
                    <li><strong>Audit Trails</strong> - All related audit logs</li>
                    <li><strong>Price History</strong> - All fuel price change logs</li>
                    <li><strong>Stock Records</strong> - All fuel stock-in and batch records</li>
                </ul>
                <p style="margin: 16px 0 0 0; color: #7f1d1d; font-weight: 600;">
                    ⚠️ Once deleted, this data CANNOT be recovered. All pump readings will be reset to 0.00. Make sure you have a backup if needed.
                </p>
            </div>
            
            <div class="info-box">
                <h2><i class="fas fa-info-circle"></i> What Will NOT Be Deleted</h2>
                <ul>
                    <li><strong>Current Fuel Inventory Levels</strong> - Current stock quantities will be preserved</li>
                    <li><strong>Fuel Pump Configuration</strong> - Pump setup and calibration settings</li>
                    <li><strong>Fuel Types</strong> - Fuel type definitions and pricing</li>
                    <li><strong>Supplier Information</strong> - Supplier master data</li>
                </ul>
            </div>
            
            <form method="POST" onsubmit="return confirmDelete()">
                <input type="hidden" name="confirm_delete" value="1">
                
                <div class="form-group">
                    <label>Type "DELETE FUEL HISTORY" to confirm:</label>
                    <input type="text" 
                           name="confirm_text" 
                           id="confirmText" 
                           placeholder="DELETE FUEL HISTORY"
                           autocomplete="off"
                           required>
                    <div class="hint">Type exactly as shown (case sensitive)</div>
                </div>
                
                <div class="btn-group">
                    <a href="manager_fuel_management_complete.php" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-delete" id="deleteBtn" disabled>
                        <i class="fas fa-trash-alt"></i> Delete All Fuel History
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Enable delete button only when correct text is entered
document.getElementById('confirmText').addEventListener('input', function() {
    const deleteBtn = document.getElementById('deleteBtn');
    if (this.value === 'DELETE FUEL HISTORY') {
        deleteBtn.disabled = false;
    } else {
        deleteBtn.disabled = true;
    }
});

function confirmDelete() {
    return confirm('ARE YOU ABSOLUTELY SURE?\n\nThis will permanently delete ALL fuel management historical data.\n\nClick OK to proceed or Cancel to abort.');
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
