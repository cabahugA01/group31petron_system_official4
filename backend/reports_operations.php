<?php
/**
 * Reports & Analytics Backend
 * Implements role-based report generation, verification, and finalization
 * 
 * FLOW:
 * 1. System auto-generates shift reports
 * 2. Manager verifies accuracy
 * 3. Admin finalizes (requires manager password)
 * 4. Reports become read-only
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

class ReportsOperations {
    
    private $pdo;
    private $station_id;
    private $user;
    
    public function __construct($pdo, $user, $station_id) {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->station_id = $station_id;
    }
    
    /**
     * Generate Shift Report (Auto-generated)
     * System calculates totals from transactions, job orders, and inventory
     */
    public function generateShiftReport($shift_date, $shift_type = 'full_day') {
        try {
            $this->pdo->beginTransaction();
            
            // Check if report already exists
            $stmt = $this->pdo->prepare("
                SELECT id FROM shift_reports 
                WHERE station_id = ? AND report_date = ? AND shift_type = ?
                LIMIT 1
            ");
            $stmt->execute([$this->station_id, $shift_date, $shift_type]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                return [
                    'success' => false,
                    'message' => 'Report already exists for this period',
                    'report_id' => $existing['id']
                ];
            }
            
            // CALCULATE: Sales total
            $stmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(amount), 0) as total_sales
                FROM sales
                WHERE station_id = ? AND DATE(created_at) = ?
            ");
            $stmt->execute([$this->station_id, $shift_date]);
            $sales = $stmt->fetch(PDO::FETCH_ASSOC)['total_sales'];
            
            // CALCULATE: Job orders completed
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as job_count, COALESCE(SUM(total_cost), 0) as job_revenue
                FROM job_orders
                WHERE station_id = ? AND DATE(completed_at) = ? AND status = 'Completed'
            ");
            $stmt->execute([$this->station_id, $shift_date]);
            $jobs = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // CALCULATE: Inventory transactions
            $stmt = $this->pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'addition' THEN quantity ELSE 0 END), 0) as received,
                    COALESCE(SUM(CASE WHEN transaction_type = 'deduction' THEN quantity ELSE 0 END), 0) as deducted
                FROM inventory_transactions
                WHERE station_id = ? AND DATE(created_at) = ?
            ");
            $stmt->execute([$this->station_id, $shift_date]);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // CALCULATE: Fuel reconciliation
            $stmt = $this->pdo->prepare("
                SELECT * FROM fuel_readings
                WHERE station_id = ?
                ORDER BY reading_time DESC
                LIMIT 2
            ");
            $stmt->execute([$this->station_id]);
            $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $fuel_variance = 0;
            if (count($readings) >= 2) {
                $current = (float)($readings[0]['reading_liters'] ?? 0);
                $previous = (float)($readings[1]['reading_liters'] ?? 0);
                $calibration = (float)($readings[0]['calibration_adjustment'] ?? 0);
                $price_per_liter = 50; // placeholder
                
                // Formula: (Present − Previous − Calibration) × Price/L
                $fuel_variance = ($current - $previous - $calibration) * $price_per_liter;
            }
            
            // INSERT: Shift report
            $stmt = $this->pdo->prepare("
                INSERT INTO shift_reports
                (station_id, report_date, shift_type, sales_total, job_orders_count, job_orders_revenue,
                 inventory_received, inventory_deducted, fuel_variance, status, generated_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Verification', ?, NOW())
            ");
            
            $stmt->execute([
                $this->station_id,
                $shift_date,
                $shift_type,
                $sales,
                $jobs['job_count'],
                $jobs['job_revenue'],
                $inventory['received'],
                $inventory['deducted'],
                $fuel_variance,
                $this->user['id']
            ]);
            
            $report_id = $this->pdo->lastInsertId();
            
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Shift Report Generated',
                sprintf('Report for %s generated. Sales: ₱%.2f, Jobs: %d', $shift_date, $sales, $jobs['job_count'])
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Shift report generated',
                'report_id' => $report_id
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Manager Verify Report
     * Manager reviews auto-generated report for accuracy
     */
    public function managerVerifyReport($report_id, $action, $remarks = null) {
        try {
            $this->pdo->beginTransaction();
            
            // RBAC: Manager only
            $role = role_key($this->user['role'] ?? '');
            if ($role !== 'manager') {
                throw new Exception('Manager privileges required to verify reports');
            }
            
            $stmt = $this->pdo->prepare("
                SELECT * FROM shift_reports WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$report_id, $this->station_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$report) {
                throw new Exception('Report not found');
            }
            
            if ($report['status'] !== 'Pending Verification') {
                throw new Exception('Report already processed');
            }
            
            if ($action === 'verify') {
                // VERIFY: Approve for admin finalization
                $stmt = $this->pdo->prepare("
                    UPDATE shift_reports
                    SET status = 'Verified',
                        verified_by = ?,
                        verified_at = NOW(),
                        manager_remarks = ?
                    WHERE id = ?
                ");
                $stmt->execute([$this->user['id'], $remarks, $report_id]);
                
                log_activity(
                    $this->pdo,
                    $this->user['id'],
                    'Report Verified',
                    sprintf('Report %d verified by manager', $report_id)
                );
                
                $message = 'Report verified. Ready for admin finalization.';
                
            } elseif ($action === 'reject') {
                // REJECT: Return for correction
                $stmt = $this->pdo->prepare("
                    UPDATE shift_reports
                    SET status = 'Rejected',
                        rejected_by = ?,
                        rejected_at = NOW(),
                        manager_remarks = ?
                    WHERE id = ?
                ");
                $stmt->execute([$this->user['id'], $remarks, $report_id]);
                
                log_activity(
                    $this->pdo,
                    $this->user['id'],
                    'Report Rejected',
                    'Report returned for correction: ' . ($remarks ?? 'Not specified')
                );
                
                $message = 'Report rejected and returned for correction.';
            }
            
            $this->pdo->commit();
            
            return ['success' => true, 'message' => $message];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Admin Finalize Report
     * Admin locks report after manager verification (requires manager password)
     * ENFORCES: Read-only after finalization
     */
    public function adminFinalizeReport($report_id, $manager_password) {
        try {
            $this->pdo->beginTransaction();
            
            // RBAC: Admin or Super Admin only
            $role = role_key($this->user['role'] ?? '');
            if (!in_array($role, ['admin', 'superadmin'])) {
                throw new Exception('Admin privileges required');
            }
            
            $stmt = $this->pdo->prepare("
                SELECT * FROM shift_reports WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$report_id, $this->station_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$report) {
                throw new Exception('Report not found');
            }
            
            if ($report['status'] !== 'Verified') {
                throw new Exception('Report must be manager-verified before finalization');
            }
            
            // SECURITY: Super Admin bypasses password check
            if ($role === 'superadmin') {
                // Bypass
            } else {
                // ADMIN: Verify manager password from same station
                $stmt = $this->pdo->prepare("
                    SELECT u.password FROM users u
                    WHERE u.station_id = ? AND u.role = 'manager'
                    LIMIT 1
                ");
                $stmt->execute([$this->station_id]);
                $manager = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$manager || !password_verify($manager_password, $manager['password'])) {
                    throw new Exception('Invalid manager password verification');
                }
            }
            
            // FINALIZE: Lock report and mark as read-only
            $stmt = $this->pdo->prepare("
                UPDATE shift_reports
                SET status = 'Finalized',
                    finalized_by = ?,
                    finalized_at = NOW(),
                    is_locked = 1
                WHERE id = ?
            ");
            $stmt->execute([$this->user['id'], $report_id]);
            
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Report Finalized',
                sprintf('Report %d finalized and locked by %s', $report_id, $role)
            );
            
            $this->pdo->commit();
            
            return ['success' => true, 'message' => 'Report finalized and locked. Now read-only.'];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get Reports (Role-filtered)
     */
    public function getReports($filters = []) {
        $role = role_key($this->user['role'] ?? 'staff');
        
        $sql = "SELECT * FROM shift_reports WHERE station_id = ?";
        $params = [$this->station_id];
        
        // Staff only sees their own reports
        if ($role === 'staff') {
            $sql .= " AND generated_by = ?";
            $params[] = $this->user['id'];
        }
        
        // Filter by date if provided
        if (!empty($filters['start_date'])) {
            $sql .= " AND report_date >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND report_date <= ?";
            $params[] = $filters['end_date'];
        }
        
        $sql .= " ORDER BY report_date DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// API Handler
if (basename($_SERVER['PHP_SELF']) === 'reports_operations.php') {
    require_login();
    
    $user = current_user();
    $station_id = user_station_id();
    $reportsOps = new ReportsOperations($pdo, $user, $station_id);
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'generate_shift_report':
                $result = $reportsOps->generateShiftReport(
                    $_POST['shift_date'],
                    $_POST['shift_type'] ?? 'full_day'
                );
                break;
                
            case 'manager_verify':
                $result = $reportsOps->managerVerifyReport(
                    $_POST['report_id'],
                    $_POST['verify_action'],
                    $_POST['remarks'] ?? null
                );
                break;
                
            case 'admin_finalize':
                $result = $reportsOps->adminFinalizeReport(
                    $_POST['report_id'],
                    $_POST['manager_password']
                );
                break;
                
            case 'get_reports':
                $result = [
                    'success' => true,
                    'data' => $reportsOps->getReports($_GET)
                ];
                break;
                
            default:
                $result = ['success' => false, 'message' => 'Invalid action'];
        }
        
        json_response($result);
        
    } catch (Exception $e) {
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}
