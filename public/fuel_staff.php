<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_staff';

// AJAX: Get previous reading for a pump (moved before require_login)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_previous_reading') {
    require_once __DIR__ . '/../public/db_connect.php';
    
    $pump_id = $_GET['pump_id'] ?? 0;
    
    // Simple session check
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    if ($pump_id) {
        try {
            // Get user's station_id
            $stmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            $station_id = $user['station_id'];
            
            // Verify pump belongs to user's station
            $stmt = $pdo->prepare("SELECT station_id FROM fuel_pumps WHERE id = ?");
            $stmt->execute([$pump_id]);
            $pump = $stmt->fetch();
            
            if (!$pump || $pump['station_id'] != $station_id) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Pump not found or access denied']);
                exit;
            }
            
            // Get last reading
            $stmt = $pdo->prepare("SELECT current_reading FROM fuel_daily_readings WHERE pump_id = ? ORDER BY reading_date DESC, shift DESC LIMIT 1");
            $stmt->execute([$pump_id]);
            $last_reading = $stmt->fetch();
            
            $previous_reading = $last_reading ? $last_reading['current_reading'] : 0;
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'previous_reading' => $previous_reading]);
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid pump ID']);
    }
    exit;
}

// AJAX: Get pump calibration value
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_pump_calibration') {
    require_once __DIR__ . '/../public/db_connect.php';
    
    $pump_id = $_GET['pump_id'] ?? 0;
    
    // Simple session check
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    if ($pump_id) {
        try {
            // Get user's station_id
            $stmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            $station_id = $user['station_id'];
            
            // Verify pump belongs to user's station
            $stmt = $pdo->prepare("SELECT station_id, calibration_value, fuel_type_id FROM fuel_pumps WHERE id = ?");
            $stmt->execute([$pump_id]);
            $pump = $stmt->fetch();
            
            if (!$pump || $pump['station_id'] != $station_id) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Pump not found or access denied']);
                exit;
            }
            
            $calibration = $pump['calibration_value'] ?? 0;
            $price_per_liter = 0;

            if (!empty($pump['fuel_type_id'])) {
                $stmt = $pdo->prepare("SELECT price_per_liter FROM fuel_pricing WHERE station_id = ? AND fuel_type_id = ? AND is_active = 1 ORDER BY effective_date DESC, id DESC LIMIT 1");
              $stmt->execute([$station_id, $pump['fuel_type_id']]);
              $price_row = $stmt->fetch(PDO::FETCH_ASSOC);
              $price_per_liter = $price_row ? (float)$price_row['price_per_liter'] : 0;
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'calibration' => $calibration, 'price_per_liter' => $price_per_liter]);
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid pump ID']);
    }
    exit;
}

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/fuel_pos_sync.php';
require_once __DIR__ . '/../backend/inventory_automation.php';

require_login();

$me = current_user();
$station_id = user_station_id();
$msg = '';

// Determine role and access level
// Staff can record, Manager can verify/approve, Admin/Superadmin can finalize and override
$userRole  = role_key($me['role'] ?? '');
$isAdmin   = in_array($userRole, ['admin', 'superadmin']);
$isManager = in_array($userRole, ['manager', 'admin', 'superadmin']);
$isStaff   = in_array($userRole, ['staff', 'manager', 'admin', 'superadmin']);
$isSuper   = $userRole === 'superadmin';

// Superadmin can view any station
if ($isSuper && !$station_id) {
    $stations = [];
    try {
        $stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {}
    $station_id = $_GET['station'] ?? '';
}

// Get current tab
$active_tab = $_GET['tab'] ?? 'pump';

// Ensure tables exist (Auto-fix for missing tables)
try {
    // Create fuel_pumps table
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_pumps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL,
        pump_number VARCHAR(50) NOT NULL,
        fuel_type_id INT,
        status VARCHAR(20) DEFAULT 'active'
    )");
    
    // Create fuel_daily_readings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_daily_readings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT,
        pump_id INT,
        reading_date DATE,
        shift VARCHAR(50),
        previous_reading DECIMAL(10,2),
        current_reading DECIMAL(10,2),
        sales_liters DECIMAL(10,2),
        user_id INT,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_reading (station_id, pump_id, reading_date, shift)
    )");
    
    // Create fuel_deliveries table
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT,
        delivery_date DATE,
        fuel_type VARCHAR(50),
        supplier VARCHAR(100),
        invoice_no VARCHAR(50),
        delivery_liters DECIMAL(10,2),
        tanker_number VARCHAR(50),
        received_by INT,
        notes TEXT,
        status VARCHAR(20) DEFAULT 'Pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create fuel_adjustments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_adjustments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT,
        adjustment_date DATE,
        fuel_type VARCHAR(50),
        adjustment_type VARCHAR(50),
        liters DECIMAL(10,2),
        reason VARCHAR(255),
        user_id INT,
        notes TEXT,
        status VARCHAR(20) DEFAULT 'Pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Auto-populate pumps if none exist for this station
    $pumpCount = $pdo->query("SELECT COUNT(*) FROM fuel_pumps WHERE station_id = $station_id")->fetchColumn();
    if ($pumpCount == 0) {
        // Get existing fuel types
        $fuelTypes = $pdo->query("SELECT id FROM fuel_types LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($fuelTypes)) {
            foreach (range(1, 3) as $i) {
                $fuel_id = $fuelTypes[$i-1] ?? $fuelTypes[0];
                $pdo->prepare("INSERT IGNORE INTO fuel_pumps (station_id, pump_number, fuel_type_id, status) VALUES (?, ?, ?, 'active')")
                    ->execute([$station_id, "Pump $i", $fuel_id]);
            }
        }
    }
} catch (PDOException $e) {}

// Handle Fuel Management Actions (All Roles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // ===== STAFF LEVEL OPERATIONS =====
    
    // STAFF: Record Daily Pump Reading
    if ($action === 'record_pump_reading') {
        if (!$isStaff) {
            $msg = "? Error: Only authorized users can record pump readings.";
        } else {
            $pump_id = $_POST['pump_id'] ?? '';
            $reading_date = $_POST['reading_date'];
            $shift = $_POST['shift'];
            $previous_reading = (float)($_POST['previous_reading'] ?? 0);
            $present_reading = (float)($_POST['present_reading'] ?? ($_POST['current_reading'] ?? 0));
            $calibration = (float)($_POST['calibration'] ?? 0); // Get from form input
            $price_per_liter = (float)($_POST['price_per_liter'] ?? 0);
            $notes = $_POST['notes'] ?? '';
            
            // Calculate sales liters (excluding calibration)
            $difference = $present_reading - $previous_reading;
            $sales_liters = $difference - $calibration;
            $sales_amount = $sales_liters * $price_per_liter;

            // Validation & error prevention
            if ($present_reading < $previous_reading) {
              $msg = "? Error: Present reading must be greater than or equal to previous reading.";
            } elseif ($calibration > $difference) {
              $msg = "? Error: Calibration cannot exceed the difference between present and previous readings.";
            } elseif ($price_per_liter <= 0) {
              $msg = "? Error: Price per liter must be greater than zero.";
            } elseif ($sales_liters < 0) {
              $msg = "? Error: Negative liters computed. Please review present, previous, and calibration values.";
            }
            
            if (!$msg && $pump_id && $reading_date && $shift) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO fuel_daily_readings (station_id, pump_id, reading_date, shift, previous_reading, current_reading, sales_liters, calibration, user_id, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$station_id, $pump_id, $reading_date, $shift, $previous_reading, $present_reading, $sales_liters, $calibration, $me['id'], $notes]);
                    
                    // NOTE: Stock deduction is NOT done here. It happens at verification
                    // time (verify_reading action) so rejected readings don't affect inventory.
                    
                    log_activity($pdo, $me['id'], 'Record Pump Reading', "Recorded reading for pump #$pump_id ($shift shift). Sales: $sales_liters L (Calibration: $calibration L excluded)", 'fuel_management');
                    $msg = "Pump reading recorded successfully. Net liters sold: " . number_format($sales_liters, 2) . " L | Peso sales: ?" . number_format($sales_amount, 2) . " (Calibration: " . number_format($calibration, 2) . " L excluded). Awaiting manager review.";
                } catch (PDOException $e) {
                    if ($e->errorInfo[1] == 1062) { // Duplicate entry
                        $msg = "? Error: Reading already recorded for this pump, date, and shift.";
                    } else {
                        $msg = "? Error: " . $e->getMessage();
                    }
                }
            } else {
                $msg = "? Error: Please fill all required fields.";
            }
        }
    
    // STAFF: Record Fuel Delivery
    } elseif ($action === 'record_delivery') {
        if (!$isStaff) {
            $msg = "? Error: Only authorized users can record deliveries.";
        } else {
            $delivery_date = $_POST['delivery_date'];
            $fuel_type = $_POST['fuel_type'];
            $supplier = $_POST['supplier'];
            $invoice_no = $_POST['invoice_no'] ?? '';
            $delivery_liters = (float)$_POST['delivery_liters'];
            $tanker_number = $_POST['tanker_number'] ?? '';
            $notes = $_POST['notes'] ?? '';
            
            if ($delivery_date && $fuel_type && $supplier && $delivery_liters > 0) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO fuel_deliveries (station_id, delivery_date, fuel_type, supplier, invoice_no, delivery_liters, tanker_number, received_by, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Encoded')");
                    $stmt->execute([$station_id, $delivery_date, $fuel_type, $supplier, $invoice_no, $delivery_liters, $tanker_number, $me['id'], $notes]);
                    
                    log_activity($pdo, $me['id'], 'Record Delivery', "Recorded delivery of " . number_format($delivery_liters, 2) . " liters of $fuel_type", 'fuel_management');
                    $msg = "? Fuel delivery recorded successfully.";
                } catch (PDOException $e) {
                    $msg = "? Error: " . $e->getMessage();
                }
            } else {
                $msg = "? Error: Please fill all required fields.";
            }
        }
    
    // STAFF: Record Adjustment
    } elseif ($action === 'record_adjustment') {
        if (!$isStaff) {
            $msg = "? Error: Only authorized users can record adjustments.";
        } else {
            $adjustment_date = $_POST['adjustment_date'];
            $fuel_type = $_POST['fuel_type'];
            $adjustment_type = $_POST['adjustment_type'];
            $liters = (float)$_POST['liters'];
            $reason = $_POST['reason'];
            $notes = $_POST['notes'] ?? '';
            
            if ($adjustment_date && $fuel_type && $adjustment_type && $liters != 0 && $reason) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO fuel_adjustments (station_id, adjustment_date, fuel_type, adjustment_type, liters, reason, user_id, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                    $stmt->execute([$station_id, $adjustment_date, $fuel_type, $adjustment_type, $liters, $reason, $me['id'], $notes]);
                    
                    $adj_type = ucfirst($adjustment_type);
                    log_activity($pdo, $me['id'], 'Record Adjustment', "$adj_type of " . number_format($liters, 2) . " liters ($fuel_type)", 'fuel_management');
                    $msg = "? Adjustment recorded successfully.";
                } catch (PDOException $e) {
                    $msg = "? Error: " . $e->getMessage();
                }
            } else {
                $msg = "? Error: Please fill all required fields.";
            }
        }
    
    // ===== MANAGER LEVEL OPERATIONS =====
    
    // MANAGER: Verify Pump Reading
    } elseif ($action === 'verify_reading') {
        if (!$isManager) {
            $msg = "? Error: Only managers can verify readings.";
        } else {
            $id = $_POST['id'];
            $status = $_POST['status'];
            $notes = $_POST['notes'] ?? '';
            
            try {
                $stmt = $pdo->prepare("UPDATE fuel_daily_readings SET status = ?, notes = CONCAT(COALESCE(notes,''), '\n[Manager Review] ', ?) WHERE id = ?");
                $stmt->execute([$status, $notes, $id]);
                
                if ($stmt->rowCount() > 0) {
                    // Deduct stock from inventory when reading is verified
                    if ($status === 'Verified') {
                        $stmtReading = $pdo->prepare("SELECT pump_id, sales_liters, shift FROM fuel_daily_readings WHERE id = ?");
                        $stmtReading->execute([$id]);
                        $reading = $stmtReading->fetch(PDO::FETCH_ASSOC);
                        
                        if ($reading) {
                            $stmtPump = $pdo->prepare("SELECT fuel_type_id FROM fuel_pumps WHERE id = ?");
                            $stmtPump->execute([$reading['pump_id']]);
                            $pump = $stmtPump->fetch(PDO::FETCH_ASSOC);
                            
                            if ($pump && $pump['fuel_type_id']) {
                                $stock_result = recordStockMovement(
                                    $pdo,
                                    $station_id,
                                    $pump['fuel_type_id'],
                                    -$reading['sales_liters'],
                                    'pump_reading',
                                    'fuel_daily_readings',
                                    $id,
                                    $me['id'],
                                    "Verified reading #{$id}, Shift: {$reading['shift']}, Pump: {$reading['pump_id']}"
                                );
                                
                                if (!$stock_result['success']) {
                                    $msg .= " Warning: " . $stock_result['message'];
                                }
                            }
                        }
                    }
                    // Rejected readings simply don't deduct stock (no reversal needed
                    // since stock is only deducted upon verification)
                    
                    log_activity($pdo, $me['id'], 'Verify Reading', "Verified pump reading #$id as $status", 'fuel_management');
                    $msg = "Pump reading #$id has been $status.";
                } else {
                    $msg = "Error: Reading not found.";
                }
            } catch (PDOException $e) {
                $msg = "? Error: " . $e->getMessage();
            }
        }
    
    // MANAGER: Verify Delivery
    } elseif ($action === 'verify_delivery') {
        if (!$isManager) {
            $msg = "? Error: Only managers can verify deliveries.";
        } else {
            $id = $_POST['id'];
            $status = $_POST['status'];
            $notes = $_POST['notes'] ?? '';
            
            try {
                $stmt = $pdo->prepare("UPDATE fuel_deliveries SET status = ?, verified_by = ?, notes = CONCAT(COALESCE(notes,''), '\n[Manager Verification] ', ?) WHERE id = ? AND status IN ('Pending', 'Pending Review', 'Encoded')");
                $stmt->execute([$status, $me['id'], $notes, $id]);
                
                if ($stmt->rowCount() > 0) {
                    // If delivery is finalized, update inventory in real-time
                    if ($status === 'Finalized' || $status === 'finalized') {
                        // Get fuel type ID
                        $stmtFuel = $pdo->prepare("SELECT id FROM fuel_types WHERE name = ?");
                        $stmtFuel->execute([$_POST['fuel_type']]);
                        $fuel_type_id = $stmtFuel->fetchColumn();
                        
                        if ($fuel_type_id) {
                            // Get delivery details
                            $stmtDel = $pdo->prepare("SELECT delivery_liters FROM fuel_deliveries WHERE id = ?");
                            $stmtDel->execute([$id]);
                            $delivery = $stmtDel->fetch(PDO::FETCH_ASSOC);
                            
                            if ($delivery) {
                                // Update inventory in real-time
                                $stock_result = recordStockMovement(
                                    $pdo,
                                    $station_id,
                                    $fuel_type_id,
                                    $delivery['delivery_liters'],  // Add stock
                                    'delivery_finalized',
                                    'fuel_deliveries',
                                    $id,
                                    $me['id'],
                                    "Delivery #$id finalized"
                                );
                                
                                if (!$stock_result['success']) {
                                    $msg .= " ?? Warning: " . $stock_result['message'];
                                }
                            }
                        }
                    }
                    
                    log_activity($pdo, $me['id'], 'Verify Delivery', "Verified delivery #$id as $status", 'fuel_management');
                    $msg = "? Delivery #$id has been $status.";
                } else {
                    $msg = "? Error: Delivery not found.";
                }
            } catch (PDOException $e) {
                $msg = "? Error: " . $e->getMessage();
            }
        }
    
    // MANAGER: Approve Adjustment
    } elseif ($action === 'approve_adjustment') {
        if (!$isManager) {
            $msg = "? Error: Only managers can approve adjustments.";
        } else {
            $id = $_POST['id'];
            $status = $_POST['status'];
            $notes = $_POST['notes'] ?? '';
            
            try {
                $stmt = $pdo->prepare("UPDATE fuel_adjustments SET status = ?, approved_by = ?, notes = CONCAT(COALESCE(notes,''), '\n[Manager Approval] ', ?) WHERE id = ?");
                $stmt->execute([$status, $me['id'], $notes, $id]);
                
                if ($stmt->rowCount() > 0) {
                    // If adjustment is approved, update inventory in real-time
                    if ($status === 'Approved' || $status === 'approved') {
                        // Get adjustment details — fuel_type (string) and liters
                        $stmtAdj = $pdo->prepare("SELECT fuel_type, fuel_type_id, adjustment_type, liters FROM fuel_adjustments WHERE id = ?");
                        $stmtAdj->execute([$id]);
                        $adjustment = $stmtAdj->fetch(PDO::FETCH_ASSOC);
                        
                        if ($adjustment) {
                            // Resolve fuel_type_id: use column if set, otherwise look up from fuel_type name
                            $adj_fuel_type_id = $adjustment['fuel_type_id'];
                            if (!$adj_fuel_type_id && $adjustment['fuel_type']) {
                                $stmtFt = $pdo->prepare("SELECT id FROM fuel_types WHERE name = ?");
                                $stmtFt->execute([$adjustment['fuel_type']]);
                                $adj_fuel_type_id = $stmtFt->fetchColumn();
                            }
                            
                            if ($adj_fuel_type_id) {
                                $adjustment_type = $adjustment['adjustment_type'] ?? '';
                                
                                // Determine transaction type (addition or deduction)
                                $is_deduction = in_array(strtolower($adjustment_type), ['loss', 'consumption', 'theft']);
                                $quantity = $is_deduction ? -$adjustment['liters'] : $adjustment['liters'];
                                
                                // Update inventory in real-time
                                $stock_result = recordStockMovement(
                                    $pdo,
                                    $station_id,
                                    $adj_fuel_type_id,
                                    $quantity,
                                    'adjustment_approved',
                                    'fuel_adjustments',
                                    $id,
                                    $me['id'],
                                    "Adjustment #$id approved: $adjustment_type"
                                );
                                
                                if (!$stock_result['success']) {
                                    $msg .= " Warning: " . $stock_result['message'];
                                }
                            }
                        }
                    }
                    
                    log_activity($pdo, $me['id'], 'Approve Adjustment', "Approved adjustment #$id as $status", 'fuel_management');
                    $msg = "Adjustment #$id has been $status.";
                } else {
                    $msg = "Error: Adjustment not found.";
                }
            } catch (PDOException $e) {
                $msg = "? Error: " . $e->getMessage();
            }
        }
    
    // MANAGER: Run Reconciliation
    } elseif ($action === 'investigate_variance') {
        if (!$isManager) {
            $msg = "? Error: Only managers can investigate variances.";
        } else {
            $id = $_POST['id'];
            $status = $_POST['status'] ?? '';
            $notes = $_POST['notes'] ?? '';
            $root_cause = $_POST['root_cause'] ?? '';
            $corrective_actions = $_POST['corrective_actions'] ?? '';
            
            if (!$id || !in_array($status, ['Under Investigation', 'Resolved'])) {
                $msg = "? Error: Invalid parameters provided.";
            } elseif (!$notes) {
                $msg = "? Error: Investigation notes are required.";
            } else {
                try {
                    // Build investigation notes
                    $investigation_notes = "[Investigation by " . $me['name'] . "]\n";
                    $investigation_notes .= "Status: $status\n";
                    if ($root_cause) {
                        $investigation_notes .= "Root Cause: $root_cause\n";
                    }
                    $investigation_notes .= "Notes: $notes\n";
                    if ($corrective_actions) {
                        $investigation_notes .= "Corrective Actions: $corrective_actions\n";
                    }
                    $investigation_notes .= "Date: " . date('Y-m-d H:i:s') . "\n";
                    
                    $stmt = $pdo->prepare("UPDATE fuel_variance_reports SET status = ?, investigated_by = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$status, $me['id'], $investigation_notes, $id]);
                    
                    if ($stmt->rowCount() > 0) {
                        log_activity($pdo, $me['id'], 'Variance Investigation', "Updated variance #$id to $status" . ($root_cause ? " (Root Cause: $root_cause)" : ''), 'fuel_management');
                        $msg = "? Variance report #$id has been updated to $status.";
                    } else {
                        $msg = "? Error: Variance report not found.";
                    }
                } catch (PDOException $e) {
                    $msg = "? Error: " . $e->getMessage();
                }
            }
        }
    
    // MANAGER: Run Reconciliation
    } elseif ($action === 'run_reconciliation') {
        if (!$isManager) {
            $msg = "? Error: Only managers can run reconciliation.";
        } else {
            $reconciliation_date = $_POST['reconciliation_date'];
            $fuel_type = $_POST['fuel_type'];
            $physical_stock = (float)$_POST['physical_stock'];
            $notes = $_POST['notes'] ?? '';
            
            if ($reconciliation_date && $fuel_type && $physical_stock >= 0) {
                try {
                    // Get opening stock (previous day's closing stock)
                    $prev_day = date('Y-m-d', strtotime($reconciliation_date . ' -1 day'));
                    $stmt = $pdo->prepare("SELECT closing_stock FROM fuel_reconciliation WHERE station_id = ? AND fuel_type = ? AND reconciliation_date = ?");
                    $stmt->execute([$station_id, $fuel_type, $prev_day]);
                    $prev_recon = $stmt->fetch();
                    $opening_stock = $prev_recon['closing_stock'] ?? 0;
                    
                    // Get total deliveries for the day
                    $stmt = $pdo->prepare("SELECT SUM(delivery_liters) as total FROM fuel_deliveries WHERE station_id = ? AND fuel_type = ? AND delivery_date = ? AND status IN ('Verified', 'Finalized')");
                    $stmt->execute([$station_id, $fuel_type, $reconciliation_date]);
                    $deliveries_data = $stmt->fetch();
                    $deliveries = $deliveries_data['total'] ?? 0;
                    
                    // Get total sales for the day
                    $stmt = $pdo->prepare("SELECT SUM(dr.sales_liters) as total FROM fuel_daily_readings dr LEFT JOIN fuel_pumps fp ON dr.pump_id = fp.id LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id WHERE dr.station_id = ? AND ft.name = ? AND dr.reading_date = ? AND dr.status IN ('Verified', 'Finalized')");
                    $stmt->execute([$station_id, $fuel_type, $reconciliation_date]);
                    $sales_data = $stmt->fetch();
                    $sales = $sales_data['total'] ?? 0;
                    
                    // Get total adjustments for the day
                    $stmt = $pdo->prepare("SELECT SUM(CASE WHEN adjustment_type = 'Loss' THEN -liters ELSE liters END) as total FROM fuel_adjustments WHERE station_id = ? AND fuel_type = ? AND adjustment_date = ? AND status = 'Approved'");
                    $stmt->execute([$station_id, $fuel_type, $reconciliation_date]);
                    $adjustments_data = $stmt->fetch();
                    $adjustments = $adjustments_data['total'] ?? 0;
                    
                    // Calculate expected closing stock
                    $closing_stock = $opening_stock + $deliveries - $sales + $adjustments;
                    $variance = $physical_stock - $closing_stock;
                    $variance_percent = $closing_stock > 0 ? ($variance / $closing_stock) * 100 : 0;
                    
                    // Insert reconciliation record
                    $stmt = $pdo->prepare("INSERT INTO fuel_reconciliation (station_id, reconciliation_date, fuel_type, opening_stock, deliveries, sales, adjustments, closing_stock, physical_stock, variance, variance_percent, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                    $stmt->execute([$station_id, $reconciliation_date, $fuel_type, $opening_stock, $deliveries, $sales, $adjustments, $closing_stock, $physical_stock, $variance, $variance_percent, $notes]);
                    
                    // If variance exceeds threshold, create variance report
                    $variance_threshold = 0.05; // 5%
                    if (abs($variance_percent) > $variance_threshold) {
                        $stmt = $pdo->prepare("INSERT INTO fuel_variance_reports (station_id, report_date, fuel_type, expected_stock, actual_stock, variance_liters, variance_percent, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Open')");
                        $stmt->execute([$station_id, $reconciliation_date, $fuel_type, $closing_stock, $physical_stock, $variance, $variance_percent, "Auto-generated from reconciliation"]);
                    }
                    
                    log_activity($pdo, $me['id'], 'Run Reconciliation', "Reconciliation for $fuel_type on $reconciliation_date", 'fuel_management');
                    $msg = "? Reconciliation completed. Variance: " . number_format($variance, 2) . " liters (" . number_format($variance_percent, 2) . "%)";
                } catch (PDOException $e) {
                    if ($e->errorInfo[1] == 1062) { // Duplicate entry
                        $msg = "? Error: Reconciliation already done for this date and fuel type.";
                    } else {
                        $msg = "? Error: " . $e->getMessage();
                    }
                }
            } else {
                $msg = "? Error: Please fill all required fields.";
            }
        }
    
    // MANAGER: Approve Reconciliation (changes status from Pending to Approved)
    } elseif ($action === 'approve_reconciliation') {
        if (!$isManager) {
            $msg = "? Error: Only managers can approve reconciliations.";
        } else {
            $recon_id = (int)($_POST['recon_id'] ?? 0);
            $manager_notes = trim($_POST['manager_notes'] ?? '');
            
            if ($recon_id) {
                try {
                    // Check if reconciliation exists and is in Pending status
                    $stmt = $pdo->prepare("SELECT * FROM fuel_reconciliation WHERE id = ? AND station_id = ?");
                    $stmt->execute([$recon_id, $station_id]);
                    $recon = $stmt->fetch();
                    
                    if (!$recon) {
                        $msg = "? Error: Reconciliation record not found.";
                    } elseif ($recon['status'] !== 'Pending') {
                        $msg = "? Error: Only Pending reconciliations can be approved.";
                    } else {
                        // Update status to Approved
                        $stmt = $pdo->prepare("UPDATE fuel_reconciliation SET status = 'approved', manager_notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                        $stmt->execute([$manager_notes, $me['id'], $recon_id]);
                        
                        log_activity($pdo, $me['id'], 'Approve Reconciliation', "Approved reconciliation #{$recon_id} for {$recon['fuel_type']} on {$recon['reconciliation_date']}", 'fuel_management');
                        $msg = "? Reconciliation approved! Admin can now finalize it.";
                    }
                } catch (Exception $e) {
                    $msg = "? Error: " . $e->getMessage();
                }
            } else {
                $msg = "? Error: Invalid reconciliation ID.";
            }
        }
    
    // ===== PUMP MANAGEMENT (Admin/Superadmin only) =====
    
    // ADD PUMP
     } elseif ($action === 'add_pump') {
         if (!$isAdmin) {
             $msg = "? Error: Only admins can manage pumps.";
         } else {
             $pump_number = trim($_POST['pump_number'] ?? '');
             $status = $_POST['status'] ?? 'active';
             
             if ($pump_number) {
                 try {
                     // Check if pump number already exists for this station
                     $stmt = $pdo->prepare("SELECT id FROM fuel_pumps WHERE station_id = ? AND pump_number = ?");
                     $stmt->execute([$station_id, $pump_number]);
                     if ($stmt->rowCount() > 0) {
                         $msg = "? Error: Pump number already exists for this station.";
                     } else {
                         // Insert new pump with fuel_type_id = 1 as placeholder
                         $stmt = $pdo->prepare("INSERT INTO fuel_pumps (station_id, pump_number, fuel_type_id, status) VALUES (?, ?, ?, ?)");
                         $stmt->execute([$station_id, $pump_number, 1, $status]);
                         
                         log_activity($pdo, $me['id'], 'Add Pump', "Created Pump $pump_number at station $station_id", 'fuel_management');
                         $msg = "? Pump $pump_number created successfully. Now add nozzles to this pump.";
                     }
                 } catch (PDOException $e) {
                     $msg = "? Error: " . $e->getMessage();
                 }
             } else {
                 $msg = "? Error: Please fill all required fields.";
             }
         }
    
     // EDIT PUMP
     } elseif ($action === 'edit_pump') {
         if (!$isAdmin) {
             $msg = "? Error: Only admins can manage pumps.";
         } else {
              $pump_id = $_POST['pump_id'] ?? '';
              $status = $_POST['status'] ?? 'active';
              
              if ($pump_id) {
                  try {
                      // Check if pump exists
                      $stmt = $pdo->prepare("SELECT pump_number FROM fuel_pumps WHERE id = ? AND station_id = ?");
                      $stmt->execute([$pump_id, $station_id]);
                      $pump = $stmt->fetch();
                      
                      if (!$pump) {
                          $msg = "? Error: Pump not found.";
                      } else {
                              // Update pump status
                              $stmt = $pdo->prepare("UPDATE fuel_pumps SET status = ? WHERE id = ?");
                              $stmt->execute([$status, $pump_id]);
                              
                              $log_msg = "Updated Pump " . $pump['pump_number'] . " - Status: $status";
                              log_activity($pdo, $me['id'], 'Edit Pump', $log_msg, 'fuel_management');
                              $msg = "? Pump " . $pump['pump_number'] . " updated successfully.";
                     }
                 } catch (PDOException $e) {
                     $msg = "? Error: " . $e->getMessage();
                 }
             } else {
                 $msg = "? Error: Please fill all required fields.";
             }
         }
    
    // DELETE PUMP (Superadmin only)
    } elseif ($action === 'delete_pump') {
        if (!$isSuper) {
            $msg = "? Error: Only superadmin can delete pumps.";
        } else {
            $pump_id = $_POST['pump_id'] ?? '';
            
            if ($pump_id) {
                try {
                    // Get pump details
                    $stmt = $pdo->prepare("SELECT pump_number FROM fuel_pumps WHERE id = ?");
                    $stmt->execute([$pump_id]);
                    $pump = $stmt->fetch();
                    
                    if (!$pump) {
                        $msg = "? Error: Pump not found.";
                    } else {
                        // Delete pump
                        $stmt = $pdo->prepare("DELETE FROM fuel_pumps WHERE id = ?");
                        $stmt->execute([$pump_id]);
                        
                        log_activity($pdo, $me['id'], 'Delete Pump', "Deleted Pump " . $pump['pump_number'], 'fuel_management');
                        $msg = "? Pump " . $pump['pump_number'] . " deleted successfully.";
                    }
                } catch (PDOException $e) {
                    $msg = "? Error: " . $e->getMessage();
                }
            } else {
                  $msg = "? Error: Pump ID is required.";
              }
          }
      }
     
     // ADD NOZZLE
     elseif ($action === 'add_nozzle') {
        $pump_id = $_POST['pump_id'] ?? '';
        $nozzle_number = trim($_POST['nozzle_number'] ?? '');
        $fuel_type_id = $_POST['fuel_type_id'] ?? '';
        $status = $_POST['status'] ?? 'active';
        
        if (!$pump_id || !$nozzle_number || !$fuel_type_id) {
            $msg = "? Error: Pump, nozzle number, and fuel type are required.";
        } else {
            try {
                // Validate pump exists and belongs to this station
                $stmt = $pdo->prepare("SELECT pump_number FROM fuel_pumps WHERE id = ? AND station_id = ?");
                $stmt->execute([$pump_id, $station_id]);
                $pump = $stmt->fetch();
                
                if (!$pump) {
                    $msg = "? Error: Pump not found.";
                } else {
                    // Check max 6 nozzles per pump
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM nozzles WHERE pump_id = ?");
                    $stmt->execute([$pump_id]);
                    $result = $stmt->fetch();
                    
                    if ($result['count'] >= 6) {
                        $msg = "? Error: Maximum 6 nozzles per pump. Cannot add more.";
                    } else {
                        // Check for duplicate nozzle number in this pump
                        $stmt = $pdo->prepare("SELECT id FROM nozzles WHERE pump_id = ? AND nozzle_number = ?");
                        $stmt->execute([$pump_id, $nozzle_number]);
                        
                        if ($stmt->rowCount() > 0) {
                            $msg = "? Error: Nozzle number already exists for this pump.";
                        } else {
                            // Validate fuel type exists
                            $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE id = ?");
                            $stmt->execute([$fuel_type_id]);
                            
                            if ($stmt->rowCount() === 0) {
                                $msg = "? Error: Invalid fuel type selected.";
                            } else {
                                // Insert nozzle
                                $stmt = $pdo->prepare("INSERT INTO nozzles (pump_id, nozzle_number, fuel_type_id, status) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$pump_id, $nozzle_number, $fuel_type_id, $status]);
                                
                                // Get fuel type name for logging
                                $stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
                                $stmt->execute([$fuel_type_id]);
                                $fuelType = $stmt->fetch();
                                
                                log_activity($pdo, $me['id'], 'Add Nozzle', "Added nozzle $nozzle_number to pump " . $pump['pump_number'] . " - Fuel Type: " . $fuelType['name'], 'fuel_management');
                                $msg = "? Nozzle $nozzle_number added successfully.";
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                $msg = "? Error: " . $e->getMessage();
            }
        }
    
    // EDIT NOZZLE
    } elseif ($action === 'edit_nozzle') {
        $nozzle_id = $_POST['nozzle_id'] ?? '';
        $nozzle_number = trim($_POST['nozzle_number'] ?? '');
        $fuel_type_id = $_POST['fuel_type_id'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$nozzle_id || !$nozzle_number || !$fuel_type_id) {
            $msg = "? Error: Nozzle ID, number, and fuel type are required.";
        } else {
            try {
                // Get nozzle details
                $stmt = $pdo->prepare("SELECT pump_id FROM nozzles WHERE id = ?");
                $stmt->execute([$nozzle_id]);
                $nozzle = $stmt->fetch();
                
                if (!$nozzle) {
                    $msg = "? Error: Nozzle not found.";
                } else {
                    // Check for duplicate nozzle number in same pump (excluding current nozzle)
                    $stmt = $pdo->prepare("SELECT id FROM nozzles WHERE pump_id = ? AND nozzle_number = ? AND id != ?");
                    $stmt->execute([$nozzle['pump_id'], $nozzle_number, $nozzle_id]);
                    
                    if ($stmt->rowCount() > 0) {
                        $msg = "? Error: Another nozzle with this number already exists for this pump.";
                    } else {
                        // Validate fuel type exists
                        $stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
                        $stmt->execute([$fuel_type_id]);
                        $fuelType = $stmt->fetch();
                        
                        if (!$fuelType) {
                            $msg = "? Error: Invalid fuel type selected.";
                        } else {
                            // Update nozzle
                            $stmt = $pdo->prepare("UPDATE nozzles SET nozzle_number = ?, fuel_type_id = ?, status = ?, notes = ? WHERE id = ?");
                            $stmt->execute([$nozzle_number, $fuel_type_id, $status, $notes, $nozzle_id]);
                            
                            log_activity($pdo, $me['id'], 'Edit Nozzle', "Updated nozzle $nozzle_number - Fuel Type: " . $fuelType['name'] . ", Status: $status", 'fuel_management');
                            $msg = "? Nozzle $nozzle_number updated successfully.";
                        }
                    }
                }
            } catch (PDOException $e) {
                $msg = "? Error: " . $e->getMessage();
            }
        }
    }
}

// Helper function to get nozzles for a pump
function getNozzlesForPump($pdo, $pump_id) {
    try {
        $stmt = $pdo->prepare("SELECT n.id, n.pump_id, n.nozzle_number, n.fuel_type_id, n.status, n.notes, ft.name as fuel_type_name FROM nozzles n LEFT JOIN fuel_types ft ON n.fuel_type_id = ft.id WHERE n.pump_id = ? ORDER BY n.nozzle_number");
        $stmt->execute([$pump_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// Fetch data based on user role
$fuel_stations = [];
$daily_readings = [];
$my_readings = [];
$deliveries = [];
$my_deliveries = [];
$adjustments = [];
$my_adjustments = [];
$reconciliations = [];
$variance_reports = [];
$fuel_pumps = [];

if ($station_id) {
    try {
        // Fetch fuel stations/pumps
        $stmt = $pdo->prepare("SELECT fp.id, fp.station_id, fp.pump_number, fp.fuel_type_id, fp.status, ft.name as fuel_type FROM fuel_pumps fp LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id WHERE fp.station_id = ? ORDER BY fp.pump_number");
        $stmt->execute([$station_id]);
        $fuel_stations = $stmt->fetchAll();
        
         // Fetch fuel pumps with fuel type info (for Manage Pumps tab)
         $stmt = $pdo->prepare("SELECT fp.id, fp.station_id, fp.pump_number, fp.fuel_type_id, fp.status, ft.name as fuel_type_name FROM fuel_pumps fp LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id WHERE fp.station_id = ? ORDER BY fp.pump_number");
         $stmt->execute([$station_id]);
         $fuel_pumps = $stmt->fetchAll();
        
        // Fetch daily readings with filters
        $filter_date = $_GET['date'] ?? date('Y-m-d');
        $filter_shift = $_GET['shift'] ?? '';
        $filter_status = $_GET['status'] ?? '';
        
        $sql = "SELECT dr.*, fp.pump_number, ft.name as fuel_type, u.name as user_name 
                FROM fuel_daily_readings dr 
                LEFT JOIN fuel_pumps fp ON dr.pump_id = fp.id 
                LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id 
                LEFT JOIN users u ON dr.user_id = u.id 
                WHERE dr.station_id = ?";
        $params = [$station_id];
        
        if ($filter_date) {
            $sql .= " AND dr.reading_date = ?";
            $params[] = $filter_date;
        }
        if ($filter_shift) {
            $sql .= " AND dr.shift = ?";
            $params[] = $filter_shift;
        }
        if ($filter_status) {
            $sql .= " AND dr.status = ?";
            $params[] = $filter_status;
        }
        $sql .= " ORDER BY dr.reading_date DESC, dr.shift, fp.pump_number";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $daily_readings = $stmt->fetchAll();
        
        // Get my recent readings for staff
        $stmt = $pdo->prepare("SELECT dr.*, fp.pump_number, ft.name as fuel_type, u.name as user_name FROM fuel_daily_readings dr LEFT JOIN fuel_pumps fp ON dr.pump_id = fp.id LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id LEFT JOIN users u ON dr.user_id = u.id WHERE dr.station_id = ? AND dr.user_id = ? ORDER BY dr.reading_date DESC LIMIT 20");
        $stmt->execute([$station_id, $me['id']]);
        $my_readings = $stmt->fetchAll();
        
        // Fetch deliveries
        $stmt = $pdo->prepare("SELECT d.*, u.name as receiver_name, v.name as verifier_name, ft.name as fuel_type_name 
                              FROM fuel_deliveries d 
                              LEFT JOIN users u ON d.received_by = u.id 
                              LEFT JOIN users v ON d.verified_by = v.id 
                              LEFT JOIN fuel_types ft ON d.fuel_type = ft.id
                              WHERE d.station_id = ? 
                              ORDER BY d.delivery_date DESC 
                              LIMIT 50");
        $stmt->execute([$station_id]);
        $deliveries = $stmt->fetchAll();
        
        // Get my recent deliveries for staff
        $stmt = $pdo->prepare("SELECT d.*, u.name as receiver_name, v.name as verifier_name, ft.name as fuel_type_name FROM fuel_deliveries d LEFT JOIN users u ON d.received_by = u.id LEFT JOIN users v ON d.verified_by = v.id LEFT JOIN fuel_types ft ON d.fuel_type = ft.id WHERE d.station_id = ? AND d.received_by = ? ORDER BY d.delivery_date DESC LIMIT 20");
        $stmt->execute([$station_id, $me['id']]);
        $my_deliveries = $stmt->fetchAll();
        
        // Fetch adjustments
        $stmt = $pdo->prepare("SELECT a.*, u.name as user_name, ap.name as approver_name 
                              FROM fuel_adjustments a 
                              LEFT JOIN users u ON a.user_id = u.id 
                              LEFT JOIN users ap ON a.approved_by = ap.id 
                              WHERE a.station_id = ? 
                              ORDER BY a.adjustment_date DESC 
                              LIMIT 50");
        $stmt->execute([$station_id]);
        $adjustments = $stmt->fetchAll();
        
        // Get my recent adjustments for staff
        $stmt = $pdo->prepare("SELECT a.*, u.name as user_name, ap.name as approver_name FROM fuel_adjustments a LEFT JOIN users u ON a.user_id = u.id LEFT JOIN users ap ON a.approved_by = ap.id WHERE a.station_id = ? AND a.user_id = ? ORDER BY a.adjustment_date DESC LIMIT 20");
        $stmt->execute([$station_id, $me['id']]);
        $my_adjustments = $stmt->fetchAll();
        
        // Fetch reconciliations (manager/admin view)
        if ($isManager) {
            $stmt = $pdo->prepare("SELECT r.*, v.name as verifier_name 
                                  FROM fuel_reconciliation r 
                                  LEFT JOIN users v ON r.verified_by = v.id 
                                  WHERE r.station_id = ? 
                                  ORDER BY r.reconciliation_date DESC 
                                  LIMIT 30");
            $stmt->execute([$station_id]);
            $reconciliations = $stmt->fetchAll();
        }
        
        // Fetch variance reports (manager/admin view)
        if ($isManager) {
            $stmt = $pdo->prepare("SELECT vr.*, i.name as investigator_name 
                                  FROM fuel_variance_reports vr 
                                  LEFT JOIN users i ON vr.investigated_by = i.id 
                                  WHERE vr.station_id = ? 
                                  ORDER BY vr.report_date DESC 
                                  LIMIT 20");
            $stmt->execute([$station_id]);
            $variance_reports = $stmt->fetchAll();
        }
        
    } catch (Exception $e) {
        error_log("Fuel Management Error: " . $e->getMessage());
    }
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.fuel-badge { display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:600; }
.fuel-badge.pending, .fuel-badge.pending_review { background:#fff3cd;color:#856404; }
.fuel-badge.verified, .fuel-badge.approved { background:rgba(0,51,102,.08);color:var(--blue); }
.fuel-badge.encoded { background:#e3f2fd;color:#0d47a1; }
.fuel-badge.finalized { background:var(--blue);color:#fff; }
.fuel-badge.rejected { background:rgba(227,0,31,.08);color:var(--danger); }
.fuel-badge.open { background:rgba(227,0,31,.08);color:var(--danger); }
.fuel-badge.investigating { background:#fff3cd;color:#856404; }
.fuel-badge.resolved { background:rgba(0,51,102,.08);color:var(--blue); }
.fuel-badge.loss { background:rgba(227,0,31,.08);color:var(--danger); }
.fuel-badge.gain { background:rgba(0,51,102,.08);color:var(--blue); }
.fuel-badge.morning { background:#e3f2fd;color:#0d47a1; }
.fuel-badge.afternoon { background:#fff3e0;color:#e65100; }
.fuel-badge.evening { background:#f3e5f5;color:#4a148c; }
.fuel-badge.first { background:#e3f2fd;color:#0d47a1; }
.fuel-badge.second { background:#fff3e0;color:#e65100; }
.variance-positive { color:var(--blue);font-weight:700; }
.variance-negative { color:var(--danger);font-weight:700; }
.workflow-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;padding:16px; }
.workflow-link { display:block;padding:16px;background:#fff;border:1px solid var(--line);border-radius:14px;text-decoration:none;color:inherit;border-left:4px solid var(--blue);transition:all .2s; }
.workflow-link:hover { box-shadow:var(--shadow);transform:translateY(-1px); }
.workflow-link .wf-icon { font-size:18px;color:var(--blue);margin-bottom:6px; }
.workflow-link strong { display:block;font-size:14px;margin-bottom:4px; }
.workflow-link small { color:var(--muted);display:block;margin-bottom:8px;font-size:12px; }
.workflow-link .wf-count { background:#fff3cd;color:#856404;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600; }
.filter-bar { display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:0 16px 12px; }
.filter-bar .filter-group { display:flex;flex-direction:column;gap:4px; }
.filter-bar label { font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em; }
.form-grid { display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:0 16px 14px; }
@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
.fa-spin { animation:spin 1s linear infinite; }
.nozzle-item { background:#fff;padding:12px;border-radius:6px;border-left:3px solid var(--blue);display:flex;justify-content:space-between;align-items:center; }
.pump-card { border:1px solid var(--line);border-radius:8px;padding:16px;margin-bottom:14px; }
.pump-header { display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px; }
.nozzle-wrap { background:rgba(0,0,0,.02);padding:14px;border-radius:6px; }
/* Calibration field styling */
input[name="calibration"] {
    background-color: #f8f9fa !important;
    border: 2px solid rgba(40, 167, 69, 0.3) !important;
    transition: all 0.3s ease !important;
}
input[name="calibration"]:focus {
    border-color: #28a745 !important;
    background-color: white !important;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.25) !important;
}
input[name="calibration"]:hover {
    background-color: rgba(255, 255, 255, 0.95) !important;
}
</style>

  <div class="page-head" data-rendering="php">
    <div>
      <h1 class="h1">Fuel Management</h1>
      <div class="sub">
        <?php 
        if ($isStaff && !$isManager) {
            echo "Encode daily readings, deliveries, and adjustments";
        } elseif ($isManager && !$isAdmin) {
            echo "Manage fuel operations: Verify, approve, and reconcile";
        } else {
            echo "Complete fuel inventory management system";
        }
        ?>
      </div>
    </div>
    <div class="actions">
      <?php if($isSuper): ?>
        <form method="get" style="display:inline-flex; align-items:center; gap:10px;">
            <label for="station_filter" class="sub">Viewing Station:</label>
            <select name="station" id="station_filter" onchange="this.form.submit()" class="select" style="width:auto;min-width:200px;">
                <option value="">-- Select a Station --</option>
                <?php foreach($stations as $id => $name): ?>
                    <option value="<?php echo $id; ?>" <?php echo $station_id == $id ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- WORKFLOW NAVIGATION SECTION (Manager/Admin only) -->
  <?php if($isManager): ?>
  <section class="card" style="margin-top:18px">
    <div class="card-head">
      <div class="card-title">Manager Workflows</div>
    </div>
    <div class="workflow-grid">
      
      <!-- Manager: Verify Deliveries -->
      <div class="workflow-link" style="cursor: default; pointer-events: none; opacity: 0.7;">
        <div class="wf-icon"><i class="fas fa-truck"></i></div>
        <strong>Verify Deliveries</strong>
        <small>Review and verify recorded fuel deliveries</small>
        <?php
          try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM fuel_deliveries WHERE station_id = ? AND status = 'Encoded'");
            $stmt->execute([$station_id]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            echo "<span class='wf-count'>" . intval($count) . " pending</span>";
          } catch (Exception $e) {}
        ?>
      </div>
      
      <!-- Admin: Finalize Deliveries -->
      <?php if($isAdmin): ?>
      <div class="workflow-link" style="cursor: default; pointer-events: none; opacity: 0.7; border-left-color:#0d47a1;">
        <div class="wf-icon"><i class="fas fa-lock"></i></div>
        <strong>Finalize Deliveries</strong>
        <small>Complete verified deliveries & update stock</small>
        <?php
          try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM fuel_deliveries WHERE station_id = ? AND status = 'Verified'");
            $stmt->execute([$station_id]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            echo "<span class='wf-count'>" . intval($count) . " awaiting</span>";
          } catch (Exception $e) {}
        ?>
      </div>
      <?php endif; ?>
      
      <!-- Manager: Shift-End Processing -->
      <div class="workflow-link" style="cursor: default; pointer-events: none; opacity: 0.7; border-left-color:#e6a817;">
        <div class="wf-icon"><i class="fas fa-clock"></i></div>
        <strong>Shift-End Processing</strong>
        <small>Approve pump readings & deduct sales</small>
        <?php
          try {
              $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM fuel_daily_readings WHERE station_id = ? AND DATE(reading_date) = CURDATE() AND status = 'Pending Review'");
            $stmt->execute([$station_id]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            echo "<span class='wf-count'>" . intval($count) . " readings</span>";
          } catch (Exception $e) {}
        ?>
      </div>
      
      <!-- Audit Trail -->
      <div class="workflow-link" style="cursor: default; pointer-events: none; opacity: 0.7; border-left-color:var(--muted);">
        <div class="wf-icon"><i class="fas fa-clipboard-list"></i></div>
        <strong>Audit Trail</strong>
        <small>View complete transaction history</small>
      </div>
      
    </div>
  </section>
  <?php endif; ?>

  <?php if($msg): ?><div class="card" style="padding:10px; margin-top:10px; background:#e6f4ea; color:green;"><?php echo $msg; ?></div><?php endif; ?>

  <?php if($isSuper && !$station_id): ?>
    <div class="card" style="padding:40px; text-align:center; margin-top:20px;">
        <div class="empty">
          <div class="empty-ico"><i class="fas fa-gas-pump"></i></div>
          <div class="muted">Select a station from the dropdown above to manage its fuel operations.</div>
        </div>
    </div>
  
  <?php else: ?>
  
  <!-- Quick Stats -->
  <div class="cards four" style="margin-top:18px">
    <div class="card metric">
      <div class="metric-ico blue"><i class="fas fa-gas-pump"></i></div>
      <div class="metric-meta">
        <div class="metric-label">Active Pumps</div>
        <div class="metric-value"><?php echo count($fuel_stations); ?></div>
        <div class="metric-sub">Total fuel stations</div>
      </div>
    </div>
    <div class="card metric">
      <div class="metric-ico green"><i class="fas fa-check-circle"></i></div>
      <div class="metric-meta">
        <div class="metric-label">Today's Readings</div>
        <div class="metric-value">
          <?php 
          $today_readings = array_filter($daily_readings, function($r) {
              return $r['reading_date'] == date('Y-m-d');
          });
          echo count($today_readings);
          ?>
        </div>
        <div class="metric-sub">For <?php echo date('M d, Y'); ?></div>
      </div>
    </div>
    <div class="card metric">
      <div class="metric-ico purple"><i class="fas fa-truck"></i></div>
      <div class="metric-meta">
        <div class="metric-label">Pending Delivery</div>
        <div class="metric-value">
          <?php 
          $pending_deliveries = array_filter($deliveries, function($d) {
              return in_array($d['status'], ['Encoded', 'Pending']);
          });
          echo count($pending_deliveries);
          ?>
        </div>
        <div class="metric-sub">Awaiting action</div>
      </div>
    </div>
    <div class="card metric">
      <div class="metric-ico amber"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="metric-meta">
        <div class="metric-label">Open Variances</div>
        <div class="metric-value">
          <?php 
          $open_variances = array_filter($variance_reports, function($v) {
              return in_array($v['status'], ['Open', 'Under Investigation']);
          });
          echo count($open_variances);
          ?>
        </div>
        <div class="metric-sub">Requires attention</div>
      </div>
    </div>
  </div>

  <!-- Tabs (role-based visibility) -->
  <div class="tabs pills">
    <!-- Staff Tabs -->
    <button class="tab active" data-fueltab="pump"><i class="fas fa-gas-pump"></i> Pump Readings</button>
    <button class="tab" data-fueltab="delivery"><i class="fas fa-truck"></i> Deliveries</button>
    <button class="tab" data-fueltab="adjustment"><i class="fas fa-exchange-alt"></i> Adjustments</button>
    <button class="tab" data-fueltab="myentries"><i class="fas fa-clipboard-list"></i> My Entries</button>
    
    <!-- Manager/Admin Tabs -->
    <?php if($isManager): ?>
    <button class="tab" data-fueltab="operations"><i class="fas fa-cogs"></i> Operations</button>
    <button class="tab" data-fueltab="reconciliation"><i class="fas fa-calculator"></i> Reconciliation</button>
    <button class="tab" data-fueltab="variances"><i class="fas fa-exclamation-triangle"></i> Variances</button>
    <button class="tab" data-fueltab="history"><i class="fas fa-history"></i> History</button>
    <?php if($isAdmin): ?>
    <button class="tab" data-fueltab="manage_pumps"><i class="fas fa-sliders-h"></i> Manage Pumps</button>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- TAB 1: PUMP READINGS -->
  <section class="card" id="tab-pump">
    <div class="card-head">
      <div class="card-title">Record Daily Pump Reading</div>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Encode previous and present cumulative liters per pump</div>
    
    <form method="post">
      <input type="hidden" name="action" value="record_pump_reading">
      <div class="form-grid">
        <div>
          <label class="pay-label">Select Pump *</label>
          <select name="pump_id" id="pump_id" class="select" required>
            <option value="">-- Choose Pump --</option>
            <?php foreach($fuel_stations as $pump): ?>
              <option value="<?php echo $pump['id']; ?>">
                Pump <?php echo htmlspecialchars($pump['pump_number']); ?> - <?php echo htmlspecialchars($pump['fuel_type']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="pay-label">Reading Date *</label>
          <input type="date" name="reading_date" class="input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
          <label class="pay-label">Shift *</label>
          <select name="shift" id="shift_delivery" class="select" required>
            <option value="">-- Select Shift --</option>
          </select>
        </div>
        <div>
          <label class="pay-label">Previous Cumulative Liters (L) *</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="number" step="0.01" name="previous_reading" id="previous_reading" class="input" placeholder="0.00" required style="background-color:#f8f9fa;flex:1;">
            <button type="button" onclick="fetchPreviousReading()" class="btn secondary" style="padding:8px 12px;font-size:12px;">
              <i class="fas fa-sync-alt"></i> Fetch
            </button>
          </div>
          <span id="syncIcon" style="display:none;"><i class="fas fa-sync-alt fa-spin"></i></span>
        </div>
        <div>
          <label class="pay-label">Present Cumulative Liters (L) *</label>
          <input type="number" step="0.01" name="present_reading" class="input" placeholder="0.00" required>
        </div>
        <div>
          <label class="pay-label">Calibration (L) *</label>
          <input type="number" step="0.01" min="0" max="50" name="calibration" id="calibrationDisplay" class="input" placeholder="0.00" required>
          <small style="color: #6c757d; font-size: 11px; margin-top: 2px; display: block;">Enter calibration amount (auto-loaded from pump, editable)</small>
        </div>
        <div>
          <label class="pay-label">Price/Liter (?) *</label>
          <input type="number" step="0.01" min="0" name="price_per_liter" id="price_per_liter" class="input" placeholder="0.00" required readonly style="background-color:#f8f9fa;">
          <small style="color: #6c757d; font-size: 11px; margin-top: 2px; display: block;">
            Auto-loaded from active fuel pricing.
            <label style="margin-left:8px; cursor:pointer;">
              <input type="checkbox" id="override_price"> Override Price
            </label>
          </small>
        </div>
      </div>
      <div class="pay-section" style="padding:0 16px 10px;">
        <label class="pay-label">Calculated Net Liters (L)</label>
        <input class="input" id="salesCalc" readonly disabled placeholder="Auto-calculated">
      </div>
      <div class="pay-section" style="padding:0 16px 10px;">
        <label class="pay-label">Calculated Peso Sales (?)</label>
        <input class="input" id="amountCalc" readonly disabled placeholder="Auto-calculated">
      </div>
      <div class="pay-section" style="padding:0 16px 10px;">
        <label class="pay-label">Notes (optional)</label>
        <input class="input" name="notes" placeholder="Any observations...">
      </div>
      <div class="modal-actions" style="padding:12px 16px;">
        <div></div>
        <button type="submit" class="btn primary"><i class="fas fa-check-circle"></i> Submit Pump Reading</button>
      </div>
    </form>
  </section>

  <!-- Daily Pump Readings -->
  <section class="card" id="tab-pump-readings" style="margin-top:14px">
    <div class="card-head">
      <div class="card-title">Daily Pump Readings</div>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Record daily pump meter readings per shift</div>

    <!-- Filters -->
    <div class="filter-bar">
      <div class="filter-group">
        <label>Date</label>
        <input type="date" id="pumpFilterDate" class="input" style="width:160px;" value="<?php echo htmlspecialchars($filter_date); ?>" onchange="applyPumpFilters()">
      </div>
      <div class="filter-group">
        <label>Shift</label>
        <select id="pumpFilterShift" class="select" style="width:140px;" onchange="applyPumpFilters()">
          <option value="">All Shifts</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Status</label>
        <select id="pumpFilterStatus" class="select" style="width:140px;" onchange="applyPumpFilters()">
          <option value="">All Status</option>
          <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
          <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>Approved</option>
          <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
        </select>
      </div>
      <div class="filter-group" style="justify-content:flex-end;">
        <label>&nbsp;</label>
        <button class="btn ghost small" onclick="resetPumpFilters()"><i class="fas fa-undo"></i> Reset</button>
      </div>
    </div>

    <!-- Readings Table -->
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Pump</th>
            <th>Shift</th>
            <th>Previous</th>
            <th>Present</th>
            <th>Net Liters Sold (L)</th>
            <th>Staff</th>
            <th>Status</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($daily_readings)): ?>
          <tr>
            <td colspan="9" style="text-align:center; padding:30px;">
              <div class="empty small">
                <div class="empty-ico"><i class="fas fa-gas-pump"></i></div>
                <div class="muted">No pump readings found</div>
              </div>
            </td>
          </tr>
          <?php else: ?>
            <?php foreach($daily_readings as $reading): ?>
            <tr>
              <td><?php echo date('M d, Y', strtotime($reading['reading_date'])); ?></td>
              <td>
                <b><?php echo htmlspecialchars($reading['pump_number'] ?? 'N/A'); ?></b><br>
                <small class="muted"><?php echo htmlspecialchars($reading['fuel_type'] ?? ''); ?></small>
              </td>
              <td>
                <span class="fuel-badge <?php echo strtolower($reading['shift']); ?>">
                  <?php echo $reading['shift']; ?>
                </span>
              </td>
              <td><?php echo number_format($reading['previous_reading'] ?? 0, 2); ?></td>
              <td><?php echo number_format($reading['current_reading'] ?? 0, 2); ?></td>
              <td><b><?php echo number_format($reading['sales_liters'] ?? 0, 2); ?> L</b></td>
              <td><?php echo htmlspecialchars($reading['user_name'] ?? 'Unknown'); ?></td>
              <td>
                <span class="fuel-badge <?php echo strtolower($reading['status']); ?>">
                  <?php echo $reading['status']; ?>
                </span>
              </td>
              <td class="right">
                <?php if($reading['status'] == 'pending' && $isManager): ?>
                  <button class="btn primary small" style="background:var(--blue);" onclick="openReviewReadingModal(<?php echo $reading['id']; ?>)">
                    <i class="fas fa-clipboard-check"></i> Review
                  </button>
                <?php endif; ?>
                <button class="btn ghost small" onclick="openViewReadingModal(<?php echo $reading['id']; ?>)">
                  <i class="fas fa-eye"></i> View
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- TAB 2: FUEL DELIVERIES -->
  <section class="card hidden" id="tab-delivery">
    <div class="card-head">
      <div class="card-title">Log Fuel Delivery</div>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Record tanker deliveries from suppliers</div>
    
    <form method="post">
      <input type="hidden" name="action" value="record_delivery">
      <div class="form-grid">
        <div>
          <label class="pay-label">Delivery Date *</label>
          <input type="date" name="delivery_date" class="input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
          <label class="pay-label">Fuel Type *</label>
          <select name="fuel_type" id="fuel_type_delivery" class="select" required>
            <option value="">-- Select Fuel Type --</option>
          </select>
        </div>
        <div>
          <label class="pay-label">Supplier *</label>
          <input type="text" name="supplier" class="input" placeholder="e.g., Petron, Shell, Caltex" required>
        </div>
        <div>
          <label class="pay-label">Delivery Liters *</label>
          <input type="number" step="0.01" name="delivery_liters" class="input" placeholder="0.00" required>
        </div>
        <div>
          <label class="pay-label">Invoice Number</label>
          <input type="text" name="invoice_no" class="input" placeholder="Optional">
        </div>
        <div>
          <label class="pay-label">Tanker Number</label>
          <input type="text" name="tanker_number" class="input" placeholder="e.g., ABC-123">
        </div>
      </div>
      <div class="pay-section" style="padding:0 16px 10px;">
        <label class="pay-label">Notes (optional)</label>
        <input class="input" name="notes" placeholder="Delivery conditions, quality notes...">
      </div>
      <div class="modal-actions" style="padding:12px 16px;">
        <div></div>
        <button type="submit" class="btn primary"><i class="fas fa-truck-loading"></i> Log Delivery</button>
      </div>
    </form>
  </section>

  <!-- Recent Deliveries -->
  <section class="card hidden" id="tab-delivery-recent" style="margin-top:14px">
    <div class="card-head">
      <div class="card-title">Recent Deliveries</div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Fuel</th>
            <th>Liters</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($my_deliveries)): ?>
          <tr>
            <td colspan="4" style="text-align:center; padding:30px;">
              <div class="empty small">
                <div class="empty-ico"><i class="fas fa-truck"></i></div>
                <div class="muted">No deliveries logged yet</div>
              </div>
            </td>
          </tr>
          <?php else: ?>
            <?php foreach($my_deliveries as $delivery): ?>
            <tr>
              <td><?php echo date('m/d', strtotime($delivery['delivery_date'])); ?></td>
              <td><?php echo htmlspecialchars($delivery['fuel_type_name'] ?? $delivery['fuel_type']); ?></td>
              <td><b><?php echo number_format($delivery['delivery_liters'], 0); ?> L</b></td>
              <td>
                <span class="fuel-badge <?php echo strtolower($delivery['status']); ?>">
                  <?php echo $delivery['status']; ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- TAB 3: ADJUSTMENTS -->
  <section class="card hidden" id="tab-adjustment">
    <div class="card-head">
      <div class="card-title">Record Adjustment</div>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Log losses, transfers, consumption, or other adjustments</div>
    
    <form method="post">
      <input type="hidden" name="action" value="record_adjustment">
      <div class="form-grid">
        <div>
          <label class="pay-label">Adjustment Date *</label>
          <input type="date" name="adjustment_date" class="input" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
          <label class="pay-label">Fuel Type *</label>
          <select name="fuel_type" id="fuel_type_adjustment" class="select" required>
            <option value="">-- Select Fuel --</option>
          </select>
        </div>
        <div>
          <label class="pay-label">Adjustment Type *</label>
          <select name="adjustment_type" id="adjustment_type_fuel" class="select" required>
            <option value="">-- Select Type --</option>
          </select>
        </div>
        <div>
          <label class="pay-label">Liters *</label>
          <input type="number" step="0.01" name="liters" class="input" placeholder="0.00" required>
        </div>
        <div>
          <label class="pay-label">Reason *</label>
          <input type="text" name="reason" class="input" placeholder="e.g., Spillage, Equipment test" required>
        </div>
        <div>
          <label class="pay-label">Notes (optional)</label>
          <input name="notes" class="input" placeholder="Additional information...">
        </div>
      </div>
      <div class="modal-actions" style="padding:12px 16px;">
        <div></div>
        <button type="submit" class="btn primary"><i class="fas fa-edit"></i> Submit Adjustment</button>
      </div>
    </form>
  </section>

  <!-- Recent Adjustments -->
  <section class="card hidden" id="tab-adjustment-recent" style="margin-top:14px">
    <div class="card-head">
      <div class="card-title">Recent Adjustments</div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Liters</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($my_adjustments)): ?>
          <tr>
            <td colspan="4" style="text-align:center; padding:30px;">
              <div class="empty small">
                <div class="empty-ico"><i class="fas fa-exchange-alt"></i></div>
                <div class="muted">No adjustments recorded yet</div>
              </div>
            </td>
          </tr>
          <?php else: ?>
            <?php foreach($my_adjustments as $adj): ?>
            <tr>
              <td><?php echo date('m/d', strtotime($adj['adjustment_date'])); ?></td>
              <td><?php echo htmlspecialchars($adj['adjustment_type']); ?></td>
              <td class="<?php echo $adj['liters'] < 0 ? 'variance-negative' : 'variance-positive'; ?>">
                <b><?php echo ($adj['liters'] > 0 ? '+' : '') . number_format($adj['liters'], 1); ?> L</b>
              </td>
              <td>
                <span class="fuel-badge <?php echo strtolower($adj['status']); ?>">
                  <?php echo $adj['status']; ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

   <!-- TAB 4: ALL MY ENTRIES -->
    <section class="card hidden" id="tab-myentries">
      <div class="card-head">
        <div class="card-title">All My Entries</div>
      </div>
      <div class="sub" style="padding:0 16px 4px;">Complete history of all your fuel entries</div>

      <!-- Summary Metrics -->
      <div class="workflow-grid" style="padding:12px 16px;">
        <?php
          $pending_readings = array_filter($my_readings, function($r) { return $r['status'] == 'Pending Review'; });
          $pending_del = array_filter($my_deliveries, function($d) { return $d['status'] == 'Encoded' || $d['status'] == 'Pending'; });
          $pending_adj = array_filter($my_adjustments, function($a) { return $a['status'] == 'Pending'; });
        ?>
        <div class="workflow-link">
          <div class="wf-icon"><i class="fas fa-gas-pump"></i></div>
          <div class="wf-count"><?php echo count($my_readings); ?></div>
          <div class="muted">Pump Readings</div>
          <div class="muted" style="font-size:11px;"><?php echo count($pending_readings); ?> Pending Review</div>
        </div>
        <div class="workflow-link">
          <div class="wf-icon"><i class="fas fa-truck"></i></div>
          <div class="wf-count"><?php echo count($my_deliveries); ?></div>
          <div class="muted">Deliveries</div>
          <div class="muted" style="font-size:11px;"><?php echo count($pending_del); ?> Pending</div>
        </div>
        <div class="workflow-link">
          <div class="wf-icon"><i class="fas fa-exchange-alt"></i></div>
          <div class="wf-count"><?php echo count($my_adjustments); ?></div>
          <div class="muted">Adjustments</div>
          <div class="muted" style="font-size:11px;"><?php echo count($pending_adj); ?> Pending</div>
        </div>
      </div>

      <!-- Activity Table -->
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th>Details</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php
            try {
              $sql = "SELECT 'Reading' as type, reading_date as date, CONCAT('Pump Reading: ', sales_liters, 'L') as details, status FROM fuel_daily_readings WHERE user_id = ? AND station_id = ?
                      UNION ALL
                      SELECT 'Delivery' as type, delivery_date as date, CONCAT('Delivery: ', delivery_liters, 'L ', fuel_type) as details, status FROM fuel_deliveries WHERE received_by = ? AND station_id = ?
                      UNION ALL
                      SELECT 'Adjustment' as type, adjustment_date as date, CONCAT(adjustment_type, ': ', liters, 'L ', fuel_type) as details, status FROM fuel_adjustments WHERE user_id = ? AND station_id = ?
                      ORDER BY date DESC LIMIT 30";
              $stmt = $pdo->prepare($sql);
              $stmt->execute([$me['id'], $station_id, $me['id'], $station_id, $me['id'], $station_id]);
              $all_entries = $stmt->fetchAll();

              if (empty($all_entries)):
            ?>
            <tr>
              <td colspan="4" style="text-align:center; padding:30px;">
                <div class="empty small">
                  <div class="empty-ico"><i class="fas fa-inbox"></i></div>
                  <div class="muted">No entries found. Start recording pump readings, deliveries, and adjustments.</div>
                </div>
              </td>
            </tr>
            <?php
              else:
                foreach($all_entries as $entry):
                  $type_class = strtolower($entry['type']);
            ?>
            <tr>
              <td><?php echo date('M d, Y', strtotime($entry['date'])); ?></td>
              <td>
                <i class="fas <?php echo $type_class == 'reading' ? 'fa-gas-pump' : ($type_class == 'delivery' ? 'fa-truck' : 'fa-exchange-alt'); ?>"></i>
                <?php echo $entry['type']; ?>
              </td>
              <td><?php echo htmlspecialchars($entry['details']); ?></td>
              <td>
                <span class="fuel-badge <?php echo strtolower($entry['status']); ?>">
                  <?php echo $entry['status']; ?>
                </span>
              </td>
            </tr>
            <?php
                endforeach;
              endif;
            } catch (Exception $e) {
            ?>
            <tr><td colspan="4" style="text-align:center; color:var(--danger);">Error loading entries: <?php echo htmlspecialchars($e->getMessage()); ?></td></tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </section>

   <!-- TAB 5: OPERATIONS (Manager/Admin only) -->
   <?php if($isManager): ?>
   <section class="card hidden" id="tab-operations">
    <div class="card-head">
      <div class="card-title">Daily Operations</div>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Verify and approve daily fuel readings, deliveries, and adjustments</div>

    <!-- Filters -->
    <div class="filter-bar">
      <div class="filter-group">
        <label>Date</label>
        <input type="date" id="filterDate" class="input" style="width:160px;" value="<?php echo htmlspecialchars($filter_date); ?>" onchange="applyFilters()">
      </div>
      <div class="filter-group">
        <label>Shift</label>
        <select id="filterShift" class="select" style="width:140px;" onchange="applyFilters()">
          <option value="">All Shifts</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Status</label>
        <select id="filterStatus" class="select" style="width:140px;" onchange="applyFilters()">
          <option value="">All Status</option>
          <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
          <option value="Verified" <?php echo $filter_status == 'Verified' ? 'selected' : ''; ?>>Verified</option>
          <option value="Finalized" <?php echo $filter_status == 'Finalized' ? 'selected' : ''; ?>>Finalized</option>
        </select>
      </div>
      <div class="filter-group" style="justify-content:flex-end;">
        <label>&nbsp;</label>
        <button class="btn ghost small" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
      </div>
    </div>

    <!-- Readings Table -->
    <div class="card-head" style="border:0;padding-top:8px;">
      <div class="card-title" style="font-size:14px;">Pump Readings</div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Pump</th>
            <th>Shift</th>
            <th>Previous</th>
            <th>Present</th>
            <th>Net Liters Sold (L)</th>
            <th>Staff</th>
            <th>Status</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($daily_readings as $reading): ?>
          <tr>
            <td><?php echo date('M d, Y', strtotime($reading['reading_date'])); ?></td>
            <td>
              <b><?php echo htmlspecialchars($reading['pump_number']); ?></b><br>
              <small class="muted"><?php echo htmlspecialchars($reading['fuel_type']); ?></small>
            </td>
            <td>
              <span class="fuel-badge <?php echo strtolower($reading['shift']); ?>">
                <?php echo $reading['shift']; ?>
              </span>
            </td>
            <td><?php echo number_format($reading['previous_reading'], 2); ?></td>
            <td><?php echo number_format($reading['current_reading'], 2); ?></td>
            <td><b><?php echo number_format($reading['sales_liters'], 2); ?> L</b></td>
            <td><?php echo htmlspecialchars($reading['user_name']); ?></td>
            <td>
              <span class="fuel-badge <?php echo strtolower($reading['status']); ?>">
                <?php echo $reading['status']; ?>
              </span>
            </td>
            <td class="right">
              <?php if($reading['status'] == 'pending' && $isManager): ?>
                <button class="btn primary small" style="background:var(--blue);" onclick="openReviewReadingModal(<?php echo $reading['id']; ?>)">
                  <i class="fas fa-clipboard-check"></i> Review
                </button>
              <?php endif; ?>
              <button class="btn ghost small" onclick="openViewReadingModal(<?php echo $reading['id']; ?>)">
                <i class="fas fa-eye"></i> View
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($daily_readings)): ?>
          <tr>
            <td colspan="9" style="text-align:center; padding:30px;">
              <div class="empty small">
                <div class="empty-ico"><i class="fas fa-gas-pump"></i></div>
                <div class="muted">No pump readings found</div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Deliveries Table -->
    <div class="card-head" style="border:0;padding-top:8px;">
      <div class="card-title" style="font-size:14px;">Fuel Deliveries</div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Fuel Type</th>
            <th>Supplier</th>
            <th>Liters</th>
            <th>Tanker</th>
            <th>Received By</th>
            <th>Status</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($deliveries as $delivery): ?>
          <tr>
            <td><?php echo date('M d, Y', strtotime($delivery['delivery_date'])); ?></td>
            <td><b><?php echo htmlspecialchars($delivery['fuel_type_name'] ?? $delivery['fuel_type']); ?></b></td>
            <td><?php echo htmlspecialchars($delivery['supplier']); ?></td>
            <td><b><?php echo number_format($delivery['delivery_liters'], 2); ?> L</b></td>
            <td><?php echo htmlspecialchars($delivery['tanker_number']); ?></td>
            <td><?php echo htmlspecialchars($delivery['receiver_name']); ?></td>
            <td>
              <span class="fuel-badge <?php echo strtolower($delivery['status']); ?>">
                <?php echo $delivery['status']; ?>
              </span>
            </td>
            <td class="right">
              <?php if(in_array($delivery['status'], ['Pending Review', 'Pending', 'Encoded'])): ?>
                <button class="btn ghost small" onclick="openVerifyDeliveryModal(<?php echo $delivery['id']; ?>, '<?php echo htmlspecialchars($delivery['delivery_date']); ?>', '<?php echo htmlspecialchars(addslashes($delivery['fuel_type_name'] ?? $delivery['fuel_type'])); ?>', '<?php echo htmlspecialchars(addslashes($delivery['supplier'])); ?>', '<?php echo $delivery['delivery_liters']; ?>', '<?php echo htmlspecialchars(addslashes($delivery['tanker_number'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($delivery['receiver_name'] ?? '')); ?>')">
                  <i class="fas fa-check"></i> Verify
                </button>
              <?php endif; ?>
              <button class="btn ghost small" onclick="viewDeliveryDetails(<?php echo $delivery['id']; ?>, '<?php echo htmlspecialchars($delivery['delivery_date']); ?>', '<?php echo htmlspecialchars(addslashes($delivery['fuel_type_name'] ?? $delivery['fuel_type'])); ?>', '<?php echo htmlspecialchars(addslashes($delivery['supplier'])); ?>', '<?php echo $delivery['delivery_liters']; ?>', '<?php echo htmlspecialchars(addslashes($delivery['tanker_number'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($delivery['receiver_name'] ?? '')); ?>', '<?php echo htmlspecialchars($delivery['status']); ?>')">
                <i class="fas fa-eye"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($deliveries)): ?>
          <tr><td colspan="8" style="text-align:center;">No delivery records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Adjustments Table -->
    <div class="card-head" style="border:0;padding-top:8px;">
      <div class="card-title" style="font-size:14px;">Fuel Adjustments</div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Fuel Type</th>
            <th>Type</th>
            <th>Liters</th>
            <th>Reason</th>
            <th>Staff</th>
            <th>Status</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($adjustments as $adj): ?>
          <tr>
            <td><?php echo date('M d, Y', strtotime($adj['adjustment_date'])); ?></td>
            <td><?php echo htmlspecialchars($adj['fuel_type']); ?></td>
            <td>
              <span class="fuel-badge <?php echo strtolower($adj['adjustment_type']); ?>">
                <?php echo $adj['adjustment_type']; ?>
              </span>
            </td>
            <td class="<?php echo $adj['adjustment_type'] == 'Loss' ? 'variance-negative' : 'variance-positive'; ?>">
              <?php echo ($adj['adjustment_type'] == 'Loss' ? '-' : '+') . number_format($adj['liters'], 2); ?> L
            </td>
            <td><?php echo htmlspecialchars($adj['reason']); ?></td>
            <td><?php echo htmlspecialchars($adj['user_name']); ?></td>
            <td>
              <span class="fuel-badge <?php echo strtolower($adj['status']); ?>">
                <?php echo $adj['status']; ?>
              </span>
            </td>
            <td class="right">
              <?php if($adj['status'] == 'Pending'): ?>
                <button class="btn ghost small" onclick="openApproveAdjustmentModal(<?php echo $adj['id']; ?>, '<?php echo htmlspecialchars($adj['adjustment_date']); ?>', '<?php echo htmlspecialchars(addslashes($adj['fuel_type'])); ?>', '<?php echo htmlspecialchars(addslashes($adj['adjustment_type'])); ?>', '<?php echo $adj['liters']; ?>', '<?php echo htmlspecialchars(addslashes($adj['reason'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($adj['user_name'] ?? '')); ?>')">
                  <i class="fas fa-check"></i> Approve
                </button>
              <?php endif; ?>
              <button class="btn ghost small" onclick="viewAdjustmentDetails(<?php echo $adj['id']; ?>, '<?php echo htmlspecialchars($adj['adjustment_date']); ?>', '<?php echo htmlspecialchars(addslashes($adj['fuel_type'])); ?>', '<?php echo htmlspecialchars(addslashes($adj['adjustment_type'])); ?>', '<?php echo $adj['liters']; ?>', '<?php echo htmlspecialchars(addslashes($adj['reason'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($adj['user_name'] ?? '')); ?>', '<?php echo htmlspecialchars($adj['status']); ?>')">
                <i class="fas fa-eye"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($adjustments)): ?>
          <tr><td colspan="8" style="text-align:center;">No adjustment records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
   </section>

   <!-- TAB 6: RECONCILIATION (Manager/Admin only) -->
   <section class="card hidden" id="tab-reconciliation">
    <div class="card-head">
      <div class="card-title">Fuel Reconciliation</div>
      <button class="btn primary" onclick="document.getElementById('modalRunReconciliation').classList.add('show')">
        <i class="fas fa-calculator"></i> Run Reconciliation
      </button>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Daily reconciliation of fuel stock vs sales</div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Fuel Type</th>
            <th>Opening</th>
            <th>Deliveries</th>
            <th>Sales</th>
            <th>Adjustments</th>
            <th>Expected</th>
            <th>Physical</th>
            <th>Variance</th>
            <th>Status</th>
            <th>Sync</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($reconciliations as $recon): ?>
          <tr>
            <td><?php echo date('M d, Y', strtotime($recon['reconciliation_date'])); ?></td>
            <td><b><?php echo htmlspecialchars($recon['fuel_type']); ?></b></td>
            <td><?php echo number_format($recon['opening_stock'], 2); ?> L</td>
            <td><?php echo number_format($recon['deliveries'], 2); ?> L</td>
            <td><?php echo number_format($recon['sales'], 2); ?> L</td>
            <td><?php echo number_format($recon['adjustments'], 2); ?> L</td>
            <td><b><?php echo number_format($recon['closing_stock'], 2); ?> L</b></td>
            <td><?php echo number_format($recon['physical_stock'], 2); ?> L</td>
            <td>
              <?php if($recon['variance'] != 0): ?>
                <span class="<?php echo $recon['variance'] > 0 ? 'variance-positive' : 'variance-negative'; ?>">
                  <?php echo ($recon['variance'] > 0 ? '+' : '') . number_format($recon['variance'], 2); ?> L
                  <br>
                  <small>(<?php echo ($recon['variance_percent'] > 0 ? '+' : '') . number_format($recon['variance_percent'], 2); ?>%)</small>
                </span>
              <?php else: ?>
                <span class="muted">0.00 L</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="fuel-badge <?php echo strtolower($recon['status']); ?>">
                <?php echo $recon['status']; ?>
              </span>
            </td>
            <td>
              <?php if($recon['status'] === 'Finalized' && !$recon['synced_to_pos']): ?>
                <a href="pos_fuel_sync.php" class="btn ghost small">
                  <i class="fas fa-sync"></i> Sync
                </a>
              <?php elseif($recon['synced_to_pos']): ?>
                <span class="fuel-badge finalized" title="<?php echo htmlspecialchars($recon['synced_at']); ?>">
                  <i class="fas fa-check"></i> Synced
                </span>
              <?php else: ?>
                <span class="muted">&mdash;</span>
              <?php endif; ?>
             </td>
             <td>
               <?php if($recon['status'] === 'Pending'): ?>
                 <button class="btn primary small" onclick="showApproveModal(<?php echo $recon['id']; ?>, '<?php echo htmlspecialchars($recon['fuel_type']); ?>', '<?php echo $recon['reconciliation_date']; ?>')">
                   <i class="fas fa-check"></i> Approve
                 </button>
               <?php elseif($recon['status'] === 'approved'): ?>
                 <span class="fuel-badge approved" title="Waiting for Admin finalization">
                   <i class="fas fa-clock"></i> Pending Admin
                 </span>
               <?php elseif($recon['status'] === 'finalized'): ?>
                 <span class="fuel-badge finalized" title="Finalized and locked">
                   <i class="fas fa-lock"></i> Locked
                 </span>
               <?php endif; ?>
             </td>
           </tr>
           <?php endforeach; ?>
           <?php if(empty($reconciliations)): ?>
           <tr>
             <td colspan="12" style="text-align:center; padding:30px;">
              <div class="empty small">
                <div class="empty-ico"><i class="fas fa-calculator"></i></div>
                <div class="muted">No reconciliation records found</div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
   </section>

   <!-- TAB 7: VARIANCE REPORTS (Manager/Admin only) -->
   <section class="card hidden" id="tab-variances">
    <div class="card-head">
      <div class="card-title">Variance Reports</div>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Fuel stock discrepancies requiring investigation</div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Report Date</th>
            <th>Fuel Type</th>
            <th>Expected</th>
            <th>Actual</th>
            <th>Variance</th>
            <th>Status</th>
            <th>Investigated By</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($variance_reports as $report): ?>
          <tr>
            <td><?php echo date('M d, Y', strtotime($report['report_date'])); ?></td>
            <td><b><?php echo htmlspecialchars($report['fuel_type']); ?></b></td>
            <td><?php echo number_format($report['expected_stock'], 2); ?> L</td>
            <td><?php echo number_format($report['actual_stock'], 2); ?> L</td>
            <td>
              <span class="<?php echo $report['variance_liters'] > 0 ? 'variance-positive' : 'variance-negative'; ?>">
                <?php echo ($report['variance_liters'] > 0 ? '+' : '') . number_format($report['variance_liters'], 2); ?> L
                <br>
                <small>(<?php echo ($report['variance_percent'] > 0 ? '+' : '') . number_format($report['variance_percent'], 2); ?>%)</small>
              </span>
            </td>
            <td>
              <span class="fuel-badge <?php 
                echo $report['status'] == 'Open' ? 'open' : 
                       ($report['status'] == 'Under Investigation' ? 'investigating' : 
                       ($report['status'] == 'Resolved' ? 'resolved' : 'pending')); ?>">
                <?php echo $report['status']; ?>
              </span>
            </td>
            <td><?php echo $report['investigator_name'] ? htmlspecialchars($report['investigator_name']) : '&mdash;'; ?></td>
            <td class="right">
              <button class="btn ghost small" onclick="viewVarianceDetails(<?php echo $report['id']; ?>, '<?php echo htmlspecialchars($report['report_date']); ?>', '<?php echo htmlspecialchars(addslashes($report['fuel_type'])); ?>', '<?php echo $report['expected_stock']; ?>', '<?php echo $report['actual_stock']; ?>', '<?php echo $report['variance_liters']; ?>', '<?php echo $report['variance_percent']; ?>', '<?php echo htmlspecialchars($report['status']); ?>', '<?php echo htmlspecialchars(addslashes($report['investigator_name'] ?? '')); ?>')">
                <i class="fas fa-eye"></i> View
              </button>
              <?php if(in_array($report['status'], ['Open', 'Under Investigation']) && $isManager): ?>
                <button class="btn ghost small" style="color:var(--blue);" onclick="openInvestigateVarianceModal(<?php echo $report['id']; ?>, '<?php echo htmlspecialchars($report['report_date']); ?>', '<?php echo htmlspecialchars(addslashes($report['fuel_type'])); ?>', '<?php echo $report['expected_stock']; ?>', '<?php echo $report['actual_stock']; ?>', '<?php echo $report['variance_liters']; ?>', '<?php echo $report['variance_percent']; ?>', '<?php echo htmlspecialchars($report['status']); ?>')">
                  <i class="fas fa-search"></i> Investigate
                </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($variance_reports)): ?>
          <tr>
            <td colspan="8" style="text-align:center; padding:30px;">
              <div class="empty small">
                <div class="empty-ico"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="muted">No variance reports found</div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
   </section>

   <!-- TAB 8: SHIFT HISTORY (Manager/Admin only) -->
   <section class="card hidden" id="tab-history">
    <div class="card-head">
      <div class="card-title">Shift History</div>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Complete audit trail of all shift entries</div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Date & Time</th>
            <th>Staff</th>
            <th>Action</th>
            <th>Details</th>
            <th>Shift</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php
          try {
            $sql = "SELECT al.*, u.name as user_name 
                    FROM activity_logs al 
                    LEFT JOIN users u ON al.user_id = u.id 
                    WHERE al.action LIKE '%fuel%' AND al.module = 'fuel_management'
                    ORDER BY al.created_at DESC 
                    LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $activity_logs = $stmt->fetchAll();
            
            if (!empty($activity_logs)):
              foreach($activity_logs as $log):
          ?>
          <tr>
            <td>
              <?php echo date('M d, Y', strtotime($log['created_at'])); ?><br>
              <small class="muted"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></small>
            </td>
            <td><?php echo htmlspecialchars($log['user_name']); ?></td>
            <td>
              <span class="fuel-badge verified"><?php echo htmlspecialchars($log['action']); ?></span>
            </td>
            <td><?php echo htmlspecialchars($log['details']); ?></td>
            <td>
              <?php
              $shift = 'N/A';
              if (preg_match('/\((First Shift|Second Shift|Morning|Afternoon|Evening) shift\)/i', $log['details'], $matches)) {
                  $shift = $matches[1];
              }
              if ($shift !== 'N/A'): ?>
                <span class="fuel-badge <?php echo strtolower($shift); ?>"><?php echo $shift; ?></span>
              <?php else: ?>
                <span class="muted">N/A</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="fuel-badge verified">Logged</span>
            </td>
          </tr>
          <?php
              endforeach;
            else:
          ?>
          <tr>
            <td colspan="6" style="text-align:center; padding:30px;">
              <div class="empty small">
                <div class="empty-ico"><i class="fas fa-history"></i></div>
                <div class="muted">No activity logs found</div>
              </div>
            </td>
          </tr>
          <?php
            endif;
          } catch (Exception $e) {
          ?>
          <tr>
            <td colspan="6" style="text-align:center; padding:30px; color:var(--danger);">
              Error loading activity logs: <?php echo htmlspecialchars($e->getMessage()); ?>
            </td>
          </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
   </section>

   <!-- TAB 9: MANAGE PUMPS (Admin/Superadmin only) -->
   <section class="card hidden" id="tab-manage_pumps">
    <div class="card-head">
      <div class="card-title">Manage Fuel Pumps</div>
      <button class="btn primary" onclick="document.getElementById('modalAddPump').classList.add('show')">
        <i class="fas fa-plus"></i> Add New Pump
      </button>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Create, edit, or remove fuel pumps for this station</div>

    <div style="padding:16px;">
      <?php if(!empty($fuel_pumps)): ?>
        <?php foreach($fuel_pumps as $pump): ?>
        <div class="pump-card">
          <div class="pump-head">
            <div>
              <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Pump Number</div>
              <div style="font-size:18px;font-weight:700;"><?php echo htmlspecialchars($pump['pump_number']); ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="fuel-badge <?php echo strtolower($pump['status']); ?>"><?php echo ucfirst($pump['status']); ?></span>
              <button class="btn ghost small" onclick="openEditPumpModal(<?php echo $pump['id']; ?>, '<?php echo htmlspecialchars($pump['pump_number']); ?>', '<?php echo $pump['status']; ?>')">
                <i class="fas fa-edit"></i> Edit
              </button>
              <?php if($isSuper): ?>
              <button class="btn ghost small" style="color:var(--danger);" onclick="openDeletePumpModal(<?php echo $pump['id']; ?>, '<?php echo htmlspecialchars($pump['pump_number']); ?>')">
                <i class="fas fa-trash"></i>
              </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Nozzles -->
          <div class="nozzle-wrap">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
              <div style="font-weight:600;font-size:13px;"><i class="fas fa-wind"></i> Nozzles</div>
              <button class="btn ghost small" onclick="openAddNozzleModal(<?php echo $pump['id']; ?>, '<?php echo htmlspecialchars($pump['pump_number']); ?>')">
                <i class="fas fa-plus"></i> Add
              </button>
            </div>

            <?php 
            $nozzles = getNozzlesForPump($pdo, $pump['id']);
            if (!empty($nozzles)): 
            ?>
              <?php foreach ($nozzles as $nozzle): ?>
              <div class="nozzle-item">
                <div>
                  <div style="font-weight:600;">Nozzle <?php echo htmlspecialchars($nozzle['nozzle_number']); ?></div>
                  <div class="muted" style="font-size:12px;">
                    <?php echo htmlspecialchars($nozzle['fuel_type_name'] ?? 'Unknown'); ?> &bull;
                    <span class="fuel-badge <?php echo strtolower($nozzle['status']); ?>" style="font-size:11px;"><?php echo ucfirst($nozzle['status']); ?></span>
                  </div>
                </div>
                <button class="btn ghost small" onclick="openEditNozzleModal(<?php echo $nozzle['id']; ?>, <?php echo $pump['id']; ?>, '<?php echo htmlspecialchars($nozzle['nozzle_number']); ?>', <?php echo $nozzle['fuel_type_id']; ?>, '<?php echo $nozzle['status']; ?>', '<?php echo htmlspecialchars($nozzle['notes'] ?? ''); ?>')">
                  <i class="fas fa-edit"></i> Edit
                </button>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty small" style="padding:16px 0;">
                <div class="empty-ico"><i class="fas fa-inbox"></i></div>
                <div class="muted">No nozzles added yet</div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty" style="padding:40px 0;">
          <div class="empty-ico"><i class="fas fa-gas-pump"></i></div>
          <div class="muted">No pumps configured for this station</div>
        </div>
      <?php endif; ?>
    </div>
   </section>
   <?php endif; ?>

<?php endif; ?>

<!-- MODALS -->
<!-- Placeholder modals for dynamic content -->
<div class="modal" id="modalRecordReading"></div>
<div class="modal" id="modalRecordDelivery"></div>
<div class="modal" id="modalRecordAdjustment"></div>
<div class="modal" id="modalViewReading"></div>


<!-- Modal: Approve Adjustment -->
<div class="modal" id="modalApproveAdjustment">
  <div class="modal-card">
    <div class="modal-head">
      <div class="card-title">Approve Adjustment</div>
      <button type="button" class="close" onclick="this.closest('.modal').classList.remove('show')">&times;</button>
    </div>

    <div id="approveAdjustmentDetails" style="padding:16px;"></div>

    <form method="post" id="approveAdjustmentForm">
      <input type="hidden" name="action" value="approve_adjustment">
      <input type="hidden" name="id" id="approveAdjustmentId">

      <div style="padding:0 16px;">
        <label class="pay-label">Approval Decision *</label>
        <div style="display:flex;gap:20px;margin:8px 0 16px;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="radio" name="status" value="Approved" checked onchange="toggleAdjustmentBtn()">
            <span class="fuel-badge verified">Approved</span>
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="radio" name="status" value="Rejected" onchange="toggleAdjustmentBtn()">
            <span class="fuel-badge rejected">Rejected</span>
          </label>
        </div>

        <div id="adjustmentRejectReasonWrap" style="display:none;margin-bottom:12px;">
          <label class="pay-label">Reason for Rejection *</label>
          <select class="select" name="rejection_reason" id="adjustmentRejectReasonSelect" style="width:100%;">
            <option value="">Select reason...</option>
            <option value="Unsupported Reason">Unsupported adjustment reason</option>
            <option value="Incorrect Volume">Incorrect volume amount</option>
            <option value="Duplicate Entry">Duplicate adjustment entry</option>
            <option value="Insufficient Evidence">Insufficient supporting evidence</option>
            <option value="Other">Other (specify in notes)</option>
          </select>
        </div>

        <div style="margin-bottom:16px;">
          <label class="pay-label">Manager Notes</label>
          <textarea class="input" name="notes" id="approveAdjustmentNotes" rows="3" style="width:100%;resize:vertical;" placeholder="Enter any comments..."></textarea>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn ghost" onclick="document.getElementById('modalApproveAdjustment').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary" id="approveAdjustmentSubmitBtn"><i class="fas fa-check"></i> Approve Adjustment</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Investigate Variance -->
<div class="modal" id="modalInvestigateVariance">
  <div class="modal-card">
    <div class="modal-head">
      <div class="card-title">Investigate Variance</div>
      <button type="button" class="close" onclick="this.closest('.modal').classList.remove('show')">&times;</button>
    </div>

    <div id="investigateVarianceDetails" style="padding:16px;"></div>

    <form method="post" id="investigateVarianceForm">
      <input type="hidden" name="action" value="investigate_variance">
      <input type="hidden" name="id" id="investigateVarianceId">

      <div style="padding:0 16px;">
        <label class="pay-label">Investigation Status *</label>
        <div style="display:flex;gap:20px;margin:8px 0 16px;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="radio" name="status" value="Under Investigation" checked onchange="toggleVarianceBtn()">
            <span class="fuel-badge investigating">Under Investigation</span>
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="radio" name="status" value="Resolved" onchange="toggleVarianceBtn()">
            <span class="fuel-badge resolved">Resolved</span>
          </label>
        </div>

        <div style="margin-bottom:12px;">
          <label class="pay-label">Root Cause</label>
          <select class="select" name="root_cause" id="varianceRootCause" style="width:100%;">
            <option value="">Select root cause...</option>
            <option value="Measurement Error">Measurement Error</option>
            <option value="Recording Error">Recording Error</option>
            <option value="Equipment Malfunction">Equipment Malfunction</option>
            <option value="Fuel Loss">Fuel Loss / Leakage</option>
            <option value="Process Issue">Process Issue</option>
            <option value="Environmental">Environmental Factor</option>
            <option value="Other">Other (specify in notes)</option>
          </select>
        </div>

        <div style="margin-bottom:16px;">
          <label class="pay-label">Investigation Notes *</label>
          <textarea class="input" name="notes" id="investigateVarianceNotes" rows="3" style="width:100%;resize:vertical;" placeholder="Describe findings from the investigation..." required></textarea>
        </div>

        <div style="margin-bottom:16px;">
          <label class="pay-label">Corrective Actions</label>
          <textarea class="input" name="corrective_actions" id="varianceCorrectiveActions" rows="2" style="width:100%;resize:vertical;" placeholder="What steps will be taken to prevent recurrence..."></textarea>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn ghost" onclick="document.getElementById('modalInvestigateVariance').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary" id="investigateVarianceSubmitBtn"><i class="fas fa-search"></i> Save Investigation</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Run Reconciliation -->
<div class="modal" id="modalRunReconciliation">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Run Reconciliation</div>
      <button class="icon-btn" onclick="document.getElementById('modalRunReconciliation').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="run_reconciliation">
      <div class="form-grid">
        <div>
          <label class="pay-label">Reconciliation Date *</label>
          <input class="input" type="date" name="reconciliation_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
          <label class="pay-label">Fuel Type *</label>
          <select class="select" name="fuel_type" required>
            <option value="">-- Select Fuel --</option>
            <option value="Diesel">Diesel</option>
            <option value="Gasoline">Gasoline</option>
            <option value="Premium">Premium</option>
          </select>
        </div>
        <div>
          <label class="pay-label">Physical Stock (L) *</label>
          <input class="input" type="number" step="0.01" name="physical_stock" placeholder="0.00" required>
        </div>
      </div>
      <div class="pay-section">
        <label class="pay-label">Notes (optional)</label>
        <input class="input" name="notes" placeholder="Any remarks...">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalRunReconciliation').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary">Run Reconciliation</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Approve Reconciliation -->
<div class="modal" id="modalApproveReconciliation">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Approve Reconciliation</div>
      <button class="icon-btn" onclick="document.getElementById('modalApproveReconciliation').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="approve_reconciliation">
      <input type="hidden" name="recon_id" id="approveReconId">
      <div style="padding:16px;">
        <div style="margin-bottom:16px;">
          <strong>Fuel Type:</strong> <span id="approveFuelType"></span><br>
          <strong>Date:</strong> <span id="approveReconDate"></span>
        </div>
        <div style="margin-bottom:16px;">
          <label class="pay-label">Manager Notes (Optional)</label>
          <textarea class="input" name="manager_notes" rows="3" style="width:100%;resize:vertical;" placeholder="Add any notes about this reconciliation..."></textarea>
        </div>
        <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:12px;margin-bottom:16px;">
          <strong style="color:#0369a1;"><i class="fas fa-info-circle"></i> Next Step:</strong>
          <p style="color:#0369a1;font-size:13px;margin:4px 0 0 0;">After approval, an Admin will review and finalize this reconciliation with a password lock.</p>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn ghost" onclick="document.getElementById('modalApproveReconciliation').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary"><i class="fas fa-check"></i> Approve Reconciliation</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Add Pump -->
<div class="modal" id="modalAddPump">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Add New Pump</div>
      <button class="icon-btn" onclick="document.getElementById('modalAddPump').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="add_pump">
      <div class="form-grid">
        <div>
          <label class="pay-label">Pump Number *</label>
          <input class="input" type="text" name="pump_number" placeholder="e.g., 1, 2, 3" required>
        </div>
        <div>
          <label class="pay-label">Status *</label>
          <div style="display:flex;gap:16px;padding-top:6px;">
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
              <input type="radio" name="status" value="active" checked> Active
            </label>
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
              <input type="radio" name="status" value="inactive"> Inactive
            </label>
          </div>
        </div>
      </div>
      <div class="pay-section">
        <span class="muted" style="font-size:12px;"><i class="fas fa-info-circle"></i> After creating the pump, you can add nozzles to it.</span>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalAddPump').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary">Create Pump</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit Pump -->
<div class="modal" id="modalEditPump">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Edit Pump</div>
      <button class="icon-btn" onclick="document.getElementById('modalEditPump').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="edit_pump">
      <input type="hidden" name="pump_id" id="editPumpId">
      <div class="form-grid">
        <div>
          <label class="pay-label">Pump Number</label>
          <input class="input" type="text" id="editPumpNumber" disabled>
        </div>
        <div>
          <label class="pay-label">Status *</label>
          <div style="display:flex;gap:16px;padding-top:6px;">
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
              <input type="radio" name="status" value="active"> Active
            </label>
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
              <input type="radio" name="status" value="inactive"> Inactive
            </label>
          </div>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalEditPump').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary">Update Pump</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Delete Pump -->
<div class="modal" id="modalDeletePump">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Delete Pump</div>
      <button class="icon-btn" onclick="document.getElementById('modalDeletePump').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="delete_pump">
      <input type="hidden" name="pump_id" id="deletePumpId">
      <div class="pay-section">
        <p><i class="fas fa-exclamation-triangle" style="color:var(--danger);"></i> Are you sure you want to delete <strong id="deletePumpName"></strong>?</p>
        <p class="muted">This action cannot be undone.</p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalDeletePump').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary" style="background:var(--danger);">Delete Pump</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Add Nozzle -->
<div class="modal" id="modalAddNozzle">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Add Nozzle to <span id="addNozzlePumpNumber"></span></div>
      <button class="icon-btn" onclick="document.getElementById('modalAddNozzle').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="add_nozzle">
      <input type="hidden" name="pump_id" id="addNozzlePumpId">
      <div class="form-grid">
        <div>
          <label class="pay-label">Nozzle Number *</label>
          <input class="input" type="text" name="nozzle_number" placeholder="e.g., 1, 2, A, B" required>
        </div>
        <div>
          <label class="pay-label">Fuel Type *</label>
          <select class="select" name="fuel_type_id" id="addNozzleFuelTypeId" required>
            <option value="">-- Select Fuel Type --</option>
          </select>
        </div>
        <div>
          <label class="pay-label">Status *</label>
          <div style="display:flex;gap:16px;padding-top:6px;">
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
              <input type="radio" name="status" value="active" checked> Active
            </label>
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
              <input type="radio" name="status" value="inactive"> Inactive
            </label>
          </div>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalAddNozzle').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary">Add Nozzle</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit Nozzle -->
<div class="modal" id="modalEditNozzle">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Edit Nozzle</div>
      <button class="icon-btn" onclick="document.getElementById('modalEditNozzle').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="edit_nozzle">
      <input type="hidden" name="nozzle_id" id="editNozzleId">
      <div class="form-grid">
        <div>
          <label class="pay-label">Nozzle Number *</label>
          <input class="input" type="text" name="nozzle_number" id="editNozzleNumber" required>
        </div>
        <div>
          <label class="pay-label">Fuel Type *</label>
          <select class="select" name="fuel_type_id" id="editNozzleFuelTypeId" required>
            <option value="">-- Select Fuel Type --</option>
          </select>
        </div>
        <div>
          <label class="pay-label">Status *</label>
          <div style="display:flex;gap:16px;padding-top:6px;">
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
              <input type="radio" name="status" value="active"> Active
            </label>
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
              <input type="radio" name="status" value="inactive"> Inactive
            </label>
          </div>
        </div>
      </div>
      <div class="pay-section">
        <label class="pay-label">Notes (optional)</label>
        <input class="input" name="notes" id="editNozzleNotes" placeholder="Optional notes about this nozzle">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalEditNozzle').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary">Update Nozzle</button>
      </div>
    </form>
  </div>
</div>

<script src="../assets/js/data_helper.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // Load fuel types dynamically from backend
    DataHelper.populateFuelTypes('fuel_type_delivery', '-- Select Fuel Type --')
        .then(() => DataHelper.populateFuelTypes('fuel_type_adjustment', '-- Select Fuel --'))
        .then(() => DataHelper.populateFuelTypes('addNozzleFuelTypeId', '-- Select Fuel Type --'))
        .then(() => DataHelper.populateFuelTypes('editNozzleFuelTypeId', '-- Select Fuel Type --'))
        .then(() => DataHelper.populateShifts('shift_delivery', '-- Select Shift --'))
        .then(() => DataHelper.populateShifts('pumpFilterShift', 'All Shifts'))
        .then(() => {
            // Only populate filterShift if element exists (operations tab for managers)
            const filterShiftElement = document.getElementById('filterShift');
            if (filterShiftElement) {
                return DataHelper.populateShifts('filterShift', 'All Shifts');
            }
            return Promise.resolve();
        })
        .then(() => {
            // Set selected value from URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const shiftParam = urlParams.get('shift');
            if (shiftParam) {
                const pumpFilterShift = document.getElementById('pumpFilterShift');
                if (pumpFilterShift) pumpFilterShift.value = shiftParam;
                
                const filterShift = document.getElementById('filterShift');
                if (filterShift) filterShift.value = shiftParam;
            }
        })
        .then(() => DataHelper.populateAdjustmentTypes('adjustment_type_fuel', '-- Select Type --'))
        .catch(error => console.error('Failed to load fuel types/shifts/adjustment types:', error));

    // -- Tab switching (data-fueltab) --
    const fuelTabs = document.querySelectorAll('.tab[data-fueltab]');
    const tabKeys = ['pump','delivery','adjustment','myentries','operations','reconciliation','variances','history','manage_pumps'];

    // Companion sections that show/hide with their parent tab
    const tabCompanions = {
        'pump': ['tab-pump-readings'],
        'delivery': ['tab-delivery-recent'],
        'adjustment': ['tab-adjustment-recent']
    };

    function showFuelTab(key) {
        fuelTabs.forEach(b => b.classList.toggle('active', b.dataset.fueltab === key));
        tabKeys.forEach(k => {
            const panel = document.getElementById('tab-' + k);
            if (panel) panel.classList.toggle('hidden', k !== key);
            // Toggle companion sections
            const companions = tabCompanions[k] || [];
            companions.forEach(cid => {
                const el = document.getElementById(cid);
                if (el) el.classList.toggle('hidden', k !== key);
            });
        });
    }

    fuelTabs.forEach(btn => btn.addEventListener('click', () => showFuelTab(btn.dataset.fueltab)));

    // Show correct tab based on URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'pump';
    showFuelTab(activeTab);

    // -- Auto-calculate net liters sold --
    function calculateSales() {
        const prev = parseFloat(document.querySelector('input[name="previous_reading"]')?.value) || 0;
      const present = parseFloat(document.querySelector('input[name="present_reading"]')?.value) || 0;
        const calibration = parseFloat(document.querySelector('input[name="calibration"]')?.value) || 0;
      const sales = present - prev - calibration;
      const price = parseFloat(document.querySelector('input[name="price_per_liter"]')?.value) || 0;
      const amount = sales * price;
        const el = document.getElementById('salesCalc');
        const amountEl = document.getElementById('amountCalc');
      if (el) el.value = sales.toFixed(2) + ' L';
      if (amountEl) amountEl.value = '?' + amount.toFixed(2);
    }

    ['previous_reading', 'present_reading', 'calibration', 'price_per_liter'].forEach(name => {
        const input = document.querySelector('input[name="' + name + '"]');
        if (input) input.addEventListener('input', calculateSales);
    });

    function setPriceLockState() {
      const priceInput = document.getElementById('price_per_liter');
      const override = document.getElementById('override_price');
      if (!priceInput || !override) return;

      const locked = !override.checked;
      priceInput.readOnly = locked;
      priceInput.style.backgroundColor = locked ? '#f8f9fa' : '';
    }

    const overridePriceCheckbox = document.getElementById('override_price');
    if (overridePriceCheckbox) {
      overridePriceCheckbox.addEventListener('change', setPriceLockState);
      setPriceLockState();
    }

    // -- Fetch previous reading and calibration when pump is selected --
    const pumpSelect = document.getElementById('pump_id');
    if (pumpSelect) {
        pumpSelect.addEventListener('change', function() {
            if (this.value) {
                fetchPreviousReading();
                fetchCalibrationValue();
            } else {
                const prevInput = document.getElementById('previous_reading');
                if (prevInput) prevInput.value = '0.00';
                document.getElementById('calibrationDisplay').value = '0.00';
              const priceInput = document.getElementById('price_per_liter');
              if (priceInput) priceInput.value = '0.00';
              const override = document.getElementById('override_price');
              if (override) override.checked = false;
              setPriceLockState();
                calculateSales();
            }
        });
    }

    // -- Fetch calibration value function --
    function fetchCalibrationValue() {
        const pumpSelect = document.getElementById('pump_id');
        const pumpId = pumpSelect ? pumpSelect.value : null;
        const calInput = document.getElementById('calibrationDisplay');
      const priceInput = document.getElementById('price_per_liter');
        
      if (!pumpId || !calInput) return;
        
        // Show loading indicator
        calInput.value = '0.00'; // Use valid number for number input
        calInput.placeholder = 'Loading...';
      if (priceInput) {
        priceInput.value = '0.00';
        priceInput.placeholder = 'Loading...';
      }
        
        fetch(`fuel_staff.php?action=get_pump_calibration&pump_id=${pumpId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.calibration) {
                    calInput.value = parseFloat(data.calibration).toFixed(2);
                } else {
                    calInput.value = '0.00';
                }

          if (priceInput) {
              const override = document.getElementById('override_price');
              if (!override || !override.checked) {
                priceInput.value = data.success && data.price_per_liter ? parseFloat(data.price_per_liter).toFixed(2) : '0.00';
              }
            priceInput.placeholder = '0.00';
          }

                calInput.placeholder = '0.00';
                calculateSales();
            })
            .catch(error => {
                console.error('Error fetching calibration:', error);
                calInput.value = '0.00';
                calInput.placeholder = '0.00';
          if (priceInput) {
            priceInput.value = '0.00';
            priceInput.placeholder = '0.00';
          }
                calculateSales();
            });
    }

    // -- Manual fetch previous reading function --
    function fetchPreviousReading() {
        const pumpSelect = document.getElementById('pump_id');
        const pumpId = pumpSelect ? pumpSelect.value : null;
        const prevInput = document.getElementById('previous_reading');
        const syncIcon = document.getElementById('syncIcon');

        if (pumpId && prevInput) {
            prevInput.value = '0.00'; // Use valid number instead of "Loading..."
            prevInput.placeholder = 'Loading...'; // Show loading text in placeholder
            if (syncIcon) { syncIcon.style.display = 'inline'; syncIcon.classList.add('fa-spin'); }

            fetch('fuel_staff.php?action=get_previous_reading&pump_id=' + pumpId)
                .then(r => r.json())
                .then(data => {
                    prevInput.value = data.success ? parseFloat(data.previous_reading).toFixed(2) : '0.00';
                    prevInput.placeholder = '0.00'; // Reset placeholder
                    calculateSales();
                    if (syncIcon) { syncIcon.style.display = 'none'; syncIcon.classList.remove('fa-spin'); }
                })
                .catch(error => {
                    console.error('Error fetching previous reading:', error);
                    prevInput.value = '0.00';
                    prevInput.placeholder = '0.00'; // Reset placeholder
                    if (syncIcon) { syncIcon.style.display = 'none'; syncIcon.classList.remove('fa-spin'); }
                });
        } else if (prevInput) {
            prevInput.value = '0.00';
        }
    }

    calculateSales();

    // -- Modal backdrop click to close --
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });

    // -- Escape key closes all modals --
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(m => m.classList.remove('show'));
        }
    });
});

// -- Filter Functions --
function applyFilters() {
    const date = document.getElementById('filterDate')?.value;
    const shift = document.getElementById('filterShift')?.value;
    const status = document.getElementById('filterStatus')?.value;

    let url = 'fuel_staff.php?tab=operations';
    if (date) url += '&date=' + encodeURIComponent(date);
    if (shift) url += '&shift=' + encodeURIComponent(shift);
    if (status) url += '&status=' + encodeURIComponent(status);

    window.location.href = url;
}

function resetFilters() {
    window.location.href = 'fuel_staff.php?tab=operations';
}

// -- Helper: close modal --
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

// -- View Reading Modal (Staff - Read Only) --
function openViewReadingModal(id) {
    fetch(`../backend/fuel_reading_view.php?id=${id}`)
        .then(response => response.text())
        .then(html => {
            const modal = document.getElementById('modalViewReading');
      modal.innerHTML = '<div class="modal-card modal-card-xl">' + html + '</div>';
            modal.classList.add('show');
        });
}

// -- Review Reading Modal (Manager - Approve/Reject) --
function openReviewReadingModal(id) {
    fetch(`../backend/fuel_reading_review.php?id=${id}`)
        .then(response => response.text())
        .then(html => {
            const modal = document.getElementById('modalReviewReading');
      modal.innerHTML = '<div class="modal-card modal-card-xl">' + html + '</div>';
            modal.classList.add('show');
        });
}

function toggleVerifyBtn() {
    const status = document.querySelector('#verifyReadingForm input[name="status"]:checked').value;
    const rejectWrap = document.getElementById('rejectReasonWrap');
    const btn = document.getElementById('verifySubmitBtn');

    if (status === 'Rejected') {
        rejectWrap.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times"></i> Reject Reading';
        btn.style.background = 'var(--red,#c62828)';
    } else {
        rejectWrap.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-check"></i> Verify Reading';
        btn.style.background = '';
    }
}

// Form submit handler for verify reading
document.getElementById('verifyReadingForm')?.addEventListener('submit', function(e) {
    const status = document.querySelector('#verifyReadingForm input[name="status"]:checked')?.value;
    const reason = document.getElementById('rejectReasonSelect').value;

    if (status === 'Rejected' && !reason) {
        e.preventDefault();
        alert('Please select a reason for rejection.');
        return;
    }

    // Append rejection reason to notes
    if (status === 'Rejected' && reason) {
        const notes = document.getElementById('verifyNotes');
        notes.value = (notes.value ? notes.value + ' ' : '') + '[Reason: ' + reason + ']';
    }
});

// -- Verify Delivery Modal --
function openVerifyDeliveryModal(id, date, fuelType, supplier, liters, tanker, receiver) {
    document.getElementById('verifyDeliveryId').value = id;
    document.getElementById('verifyDeliveryFuelType').value = fuelType;

    const details = document.getElementById('verifyDeliveryDetails');
    const fmtDate = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    details.innerHTML =
        '<div class="form-grid">' +
            '<div><label class="pay-label">Date</label><div class="input" style="background:var(--bg);">' + fmtDate + '</div></div>' +
            '<div><label class="pay-label">Fuel Type</label><div class="input" style="background:var(--bg);">' + fuelType + '</div></div>' +
            '<div><label class="pay-label">Supplier</label><div class="input" style="background:var(--bg);">' + supplier + '</div></div>' +
            '<div><label class="pay-label">Volume</label><div class="input" style="background:var(--bg);font-weight:600;">' + parseFloat(liters).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L</div></div>' +
            '<div><label class="pay-label">Tanker #</label><div class="input" style="background:var(--bg);">' + (tanker || '&mdash;') + '</div></div>' +
            '<div><label class="pay-label">Received By</label><div class="input" style="background:var(--bg);">' + (receiver || '&mdash;') + '</div></div>' +
        '</div>';

    // Reset form state
    document.querySelector('#verifyDeliveryForm input[name="status"][value="Finalized"]').checked = true;
    document.getElementById('deliveryRejectReasonWrap').style.display = 'none';
    document.getElementById('deliveryRejectReasonSelect').value = '';
    document.getElementById('verifyDeliveryNotes').value = '';
    const btn = document.getElementById('verifyDeliverySubmitBtn');
    btn.innerHTML = '<i class="fas fa-check"></i> Finalize Delivery';
    btn.style.background = '';

    document.getElementById('modalVerifyDelivery').classList.add('show');
}

function toggleDeliveryBtn() {
    const status = document.querySelector('#verifyDeliveryForm input[name="status"]:checked').value;
    const rejectWrap = document.getElementById('deliveryRejectReasonWrap');
    const btn = document.getElementById('verifyDeliverySubmitBtn');

    if (status === 'Rejected') {
        rejectWrap.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times"></i> Reject Delivery';
        btn.style.background = 'var(--red,#c62828)';
    } else {
        rejectWrap.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-check"></i> Finalize Delivery';
        btn.style.background = '';
    }
}

// Form submit handler for verify delivery
document.getElementById('verifyDeliveryForm')?.addEventListener('submit', function(e) {
    const status = document.querySelector('#verifyDeliveryForm input[name="status"]:checked')?.value;
    const reason = document.getElementById('deliveryRejectReasonSelect').value;

    if (status === 'Rejected' && !reason) {
        e.preventDefault();
        alert('Please select a reason for rejection.');
        return;
    }

    if (status === 'Rejected' && reason) {
        const notes = document.getElementById('verifyDeliveryNotes');
        notes.value = (notes.value ? notes.value + ' ' : '') + '[Reason: ' + reason + ']';
    }
});

// -- Approve Adjustment Modal --
function openApproveAdjustmentModal(id, date, fuelType, adjType, liters, reason, staff) {
    document.getElementById('approveAdjustmentId').value = id;

    const details = document.getElementById('approveAdjustmentDetails');
    const fmtDate = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    const isLoss = adjType.toLowerCase() === 'loss';
    details.innerHTML =
        '<div class="form-grid">' +
            '<div><label class="pay-label">Date</label><div class="input" style="background:var(--bg);">' + fmtDate + '</div></div>' +
            '<div><label class="pay-label">Fuel Type</label><div class="input" style="background:var(--bg);">' + fuelType + '</div></div>' +
            '<div><label class="pay-label">Type</label><div class="input" style="background:var(--bg);"><span class="fuel-badge ' + adjType.toLowerCase() + '">' + adjType + '</span></div></div>' +
            '<div><label class="pay-label">Volume</label><div class="input ' + (isLoss ? 'variance-negative' : 'variance-positive') + '" style="background:var(--bg);font-weight:600;">' + (isLoss ? '-' : '+') + parseFloat(liters).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L</div></div>' +
            '<div class="pay-section"><label class="pay-label">Reason</label><div class="input" style="background:var(--bg);">' + (reason || '&mdash;') + '</div></div>' +
            '<div><label class="pay-label">Submitted By</label><div class="input" style="background:var(--bg);">' + (staff || '&mdash;') + '</div></div>' +
        '</div>';

    // Reset form state
    document.querySelector('#approveAdjustmentForm input[name="status"][value="Approved"]').checked = true;
    document.getElementById('adjustmentRejectReasonWrap').style.display = 'none';
    document.getElementById('adjustmentRejectReasonSelect').value = '';
    document.getElementById('approveAdjustmentNotes').value = '';
    const btn = document.getElementById('approveAdjustmentSubmitBtn');
    btn.innerHTML = '<i class="fas fa-check"></i> Approve Adjustment';
    btn.style.background = '';

    document.getElementById('modalApproveAdjustment').classList.add('show');
}

function toggleAdjustmentBtn() {
    const status = document.querySelector('#approveAdjustmentForm input[name="status"]:checked').value;
    const rejectWrap = document.getElementById('adjustmentRejectReasonWrap');
    const btn = document.getElementById('approveAdjustmentSubmitBtn');

    if (status === 'Rejected') {
        rejectWrap.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times"></i> Reject Adjustment';
        btn.style.background = 'var(--red,#c62828)';
    } else {
        rejectWrap.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-check"></i> Approve Adjustment';
        btn.style.background = '';
    }
}

// Form submit handler for approve adjustment
document.getElementById('approveAdjustmentForm')?.addEventListener('submit', function(e) {
    const status = document.querySelector('#approveAdjustmentForm input[name="status"]:checked')?.value;
    const reason = document.getElementById('adjustmentRejectReasonSelect').value;

    if (status === 'Rejected' && !reason) {
        e.preventDefault();
        alert('Please select a reason for rejection.');
        return;
    }

    if (status === 'Rejected' && reason) {
        const notes = document.getElementById('approveAdjustmentNotes');
        notes.value = (notes.value ? notes.value + ' ' : '') + '[Reason: ' + reason + ']';
    }
});

// -- Investigate Variance Modal --
function openInvestigateVarianceModal(id, date, fuelType, expected, actual, variance, variancePct, currentStatus) {
    document.getElementById('investigateVarianceId').value = id;

    const details = document.getElementById('investigateVarianceDetails');
    const fmtDate = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    const vLiters = parseFloat(variance);
    const vPct = parseFloat(variancePct);
    const varClass = vLiters > 0 ? 'variance-positive' : 'variance-negative';
    const varSign = vLiters > 0 ? '+' : '';
    details.innerHTML =
        '<div class="form-grid">' +
            '<div><label class="pay-label">Report Date</label><div class="input" style="background:var(--bg);">' + fmtDate + '</div></div>' +
            '<div><label class="pay-label">Fuel Type</label><div class="input" style="background:var(--bg);">' + fuelType + '</div></div>' +
            '<div><label class="pay-label">Expected Stock</label><div class="input" style="background:var(--bg);">' + parseFloat(expected).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L</div></div>' +
            '<div><label class="pay-label">Actual Stock</label><div class="input" style="background:var(--bg);">' + parseFloat(actual).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L</div></div>' +
            '<div><label class="pay-label">Variance</label><div class="input ' + varClass + '" style="background:var(--bg);font-weight:600;">' + varSign + vLiters.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L (' + varSign + vPct.toFixed(2) + '%)</div></div>' +
            '<div><label class="pay-label">Current Status</label><div class="input" style="background:var(--bg);"><span class="fuel-badge ' + (currentStatus === 'Open' ? 'open' : 'investigating') + '">' + currentStatus + '</span></div></div>' +
        '</div>';

    // Set default status based on current
    if (currentStatus === 'Open') {
        document.querySelector('#investigateVarianceForm input[name="status"][value="Under Investigation"]').checked = true;
    } else {
        document.querySelector('#investigateVarianceForm input[name="status"][value="Resolved"]').checked = true;
    }
    toggleVarianceBtn();

    document.getElementById('varianceRootCause').value = '';
    document.getElementById('investigateVarianceNotes').value = '';
    document.getElementById('varianceCorrectiveActions').value = '';

    document.getElementById('modalInvestigateVariance').classList.add('show');
}

function toggleVarianceBtn() {
    const status = document.querySelector('#investigateVarianceForm input[name="status"]:checked').value;
    const btn = document.getElementById('investigateVarianceSubmitBtn');

    if (status === 'Resolved') {
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Resolve Variance';
        btn.style.background = 'var(--green,#2e7d32)';
    } else {
        btn.innerHTML = '<i class="fas fa-search"></i> Save Investigation';
        btn.style.background = '';
    }
}

// Form submit handler for investigate variance
document.getElementById('investigateVarianceForm')?.addEventListener('submit', function(e) {
    const notes = document.getElementById('investigateVarianceNotes').value.trim();
    if (!notes) {
        e.preventDefault();
        alert('Investigation notes are required.');
        return;
    }
});

// -- View Detail Modals (read-only) --
function viewReadingDetails(id, date, pump, shift, prev, curr, sales, staff, status) {
    const details = document.getElementById('verifyReadingDetails');
    const fmtDate = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    details.innerHTML =
        '<div class="form-grid">' +
            '<div><label class="pay-label">Date</label><div class="input" style="background:var(--bg);">' + fmtDate + '</div></div>' +
            '<div><label class="pay-label">Pump</label><div class="input" style="background:var(--bg);">' + pump + '</div></div>' +
            '<div><label class="pay-label">Shift</label><div class="input" style="background:var(--bg);">' + shift + '</div></div>' +
            '<div><label class="pay-label">Staff</label><div class="input" style="background:var(--bg);">' + staff + '</div></div>' +
            '<div><label class="pay-label">Previous</label><div class="input" style="background:var(--bg);">' + parseFloat(prev).toFixed(2) + '</div></div>' +
            '<div><label class="pay-label">Present</label><div class="input" style="background:var(--bg);">' + parseFloat(curr).toFixed(2) + '</div></div>' +
            '<div><label class="pay-label">Net Liters Sold (L)</label><div class="input" style="background:var(--bg);font-weight:600;">' + parseFloat(sales).toFixed(2) + ' L</div></div>' +
            '<div><label class="pay-label">Status</label><div class="input" style="background:var(--bg);"><span class="fuel-badge ' + status.toLowerCase() + '">' + status + '</span></div></div>' +
        '</div>';

    // Hide form, show details only
    document.getElementById('verifyReadingForm').style.display = 'none';
    document.querySelector('#modalVerifyReading .modal-head .card-title').textContent = 'Reading Details';
    document.getElementById('modalVerifyReading').classList.add('show');

    // Restore form visibility on close
    const modal = document.getElementById('modalVerifyReading');
    const restoreForm = function() {
        document.getElementById('verifyReadingForm').style.display = '';
        document.querySelector('#modalVerifyReading .modal-head .card-title').textContent = 'Verify Pump Reading';
        modal.removeEventListener('click', handleBackdropClick);
    };
    const handleBackdropClick = function(e) {
        if (e.target === modal) { modal.classList.remove('show'); restoreForm(); }
    };
    modal.addEventListener('click', handleBackdropClick);
    // Also override the close button temporarily
    const closeBtn = modal.querySelector('.modal-head .close');
    const origOnclick = closeBtn.onclick;
    closeBtn.onclick = function() { modal.classList.remove('show'); restoreForm(); closeBtn.onclick = origOnclick; };
}

function viewDeliveryDetails(id, date, fuelType, supplier, liters, tanker, receiver, status) {
    const details = document.getElementById('verifyDeliveryDetails');
    const fmtDate = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    details.innerHTML =
        '<div class="form-grid">' +
            '<div><label class="pay-label">Date</label><div class="input" style="background:var(--bg);">' + fmtDate + '</div></div>' +
            '<div><label class="pay-label">Fuel Type</label><div class="input" style="background:var(--bg);">' + fuelType + '</div></div>' +
            '<div><label class="pay-label">Supplier</label><div class="input" style="background:var(--bg);">' + supplier + '</div></div>' +
            '<div><label class="pay-label">Volume</label><div class="input" style="background:var(--bg);font-weight:600;">' + parseFloat(liters).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L</div></div>' +
            '<div><label class="pay-label">Tanker #</label><div class="input" style="background:var(--bg);">' + (tanker || '&mdash;') + '</div></div>' +
            '<div><label class="pay-label">Received By</label><div class="input" style="background:var(--bg);">' + (receiver || '&mdash;') + '</div></div>' +
            '<div><label class="pay-label">Status</label><div class="input" style="background:var(--bg);"><span class="fuel-badge ' + status.toLowerCase() + '">' + status + '</span></div></div>' +
        '</div>';

    document.getElementById('verifyDeliveryForm').style.display = 'none';
    document.querySelector('#modalVerifyDelivery .modal-head .card-title').textContent = 'Delivery Details';
    document.getElementById('modalVerifyDelivery').classList.add('show');

    const modal = document.getElementById('modalVerifyDelivery');
    const restoreForm = function() {
        document.getElementById('verifyDeliveryForm').style.display = '';
        document.querySelector('#modalVerifyDelivery .modal-head .card-title').textContent = 'Verify Delivery';
        modal.removeEventListener('click', handleBackdropClick);
    };
    const handleBackdropClick = function(e) {
        if (e.target === modal) { modal.classList.remove('show'); restoreForm(); }
    };
    modal.addEventListener('click', handleBackdropClick);
    const closeBtn = modal.querySelector('.modal-head .close');
    const origOnclick = closeBtn.onclick;
    closeBtn.onclick = function() { modal.classList.remove('show'); restoreForm(); closeBtn.onclick = origOnclick; };
}

function viewAdjustmentDetails(id, date, fuelType, adjType, liters, reason, staff, status) {
    const details = document.getElementById('approveAdjustmentDetails');
    const fmtDate = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    const isLoss = adjType.toLowerCase() === 'loss';
    details.innerHTML =
        '<div class="form-grid">' +
            '<div><label class="pay-label">Date</label><div class="input" style="background:var(--bg);">' + fmtDate + '</div></div>' +
            '<div><label class="pay-label">Fuel Type</label><div class="input" style="background:var(--bg);">' + fuelType + '</div></div>' +
            '<div><label class="pay-label">Type</label><div class="input" style="background:var(--bg);"><span class="fuel-badge ' + adjType.toLowerCase() + '">' + adjType + '</span></div></div>' +
            '<div><label class="pay-label">Volume</label><div class="input ' + (isLoss ? 'variance-negative' : 'variance-positive') + '" style="background:var(--bg);font-weight:600;">' + (isLoss ? '-' : '+') + parseFloat(liters).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L</div></div>' +
            '<div class="pay-section"><label class="pay-label">Reason</label><div class="input" style="background:var(--bg);">' + (reason || '&mdash;') + '</div></div>' +
            '<div><label class="pay-label">Submitted By</label><div class="input" style="background:var(--bg);">' + (staff || '&mdash;') + '</div></div>' +
            '<div><label class="pay-label">Status</label><div class="input" style="background:var(--bg);"><span class="fuel-badge ' + status.toLowerCase() + '">' + status + '</span></div></div>' +
        '</div>';

    document.getElementById('approveAdjustmentForm').style.display = 'none';
    document.querySelector('#modalApproveAdjustment .modal-head .card-title').textContent = 'Adjustment Details';
    document.getElementById('modalApproveAdjustment').classList.add('show');

    const modal = document.getElementById('modalApproveAdjustment');
    const restoreForm = function() {
        document.getElementById('approveAdjustmentForm').style.display = '';
        document.querySelector('#modalApproveAdjustment .modal-head .card-title').textContent = 'Approve Adjustment';
        modal.removeEventListener('click', handleBackdropClick);
    };
    const handleBackdropClick = function(e) {
        if (e.target === modal) { modal.classList.remove('show'); restoreForm(); }
    };
    modal.addEventListener('click', handleBackdropClick);
    const closeBtn = modal.querySelector('.modal-head .close');
    const origOnclick = closeBtn.onclick;
    closeBtn.onclick = function() { modal.classList.remove('show'); restoreForm(); closeBtn.onclick = origOnclick; };
}

function viewVarianceDetails(id, date, fuelType, expected, actual, variance, variancePct, status, investigator) {
    const details = document.getElementById('investigateVarianceDetails');
    const fmtDate = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    const vLiters = parseFloat(variance);
    const vPct = parseFloat(variancePct);
    const varClass = vLiters > 0 ? 'variance-positive' : 'variance-negative';
    const varSign = vLiters > 0 ? '+' : '';
    details.innerHTML =
        '<div class="form-grid">' +
            '<div><label class="pay-label">Report Date</label><div class="input" style="background:var(--bg);">' + fmtDate + '</div></div>' +
            '<div><label class="pay-label">Fuel Type</label><div class="input" style="background:var(--bg);">' + fuelType + '</div></div>' +
            '<div><label class="pay-label">Expected Stock</label><div class="input" style="background:var(--bg);">' + parseFloat(expected).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L</div></div>' +
            '<div><label class="pay-label">Actual Stock</label><div class="input" style="background:var(--bg);">' + parseFloat(actual).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L</div></div>' +
            '<div><label class="pay-label">Variance</label><div class="input ' + varClass + '" style="background:var(--bg);font-weight:600;">' + varSign + vLiters.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L (' + varSign + vPct.toFixed(2) + '%)</div></div>' +
            '<div><label class="pay-label">Status</label><div class="input" style="background:var(--bg);"><span class="fuel-badge ' + (status === 'Open' ? 'open' : (status === 'Under Investigation' ? 'investigating' : (status === 'Resolved' ? 'resolved' : 'pending'))) + '">' + status + '</span></div></div>' +
            '<div><label class="pay-label">Investigated By</label><div class="input" style="background:var(--bg);">' + (investigator || '&mdash;') + '</div></div>' +
        '</div>';

    document.getElementById('investigateVarianceForm').style.display = 'none';
    document.querySelector('#modalInvestigateVariance .modal-head .card-title').textContent = 'Variance Details';
    document.getElementById('modalInvestigateVariance').classList.add('show');

    const modal = document.getElementById('modalInvestigateVariance');
    const restoreForm = function() {
        document.getElementById('investigateVarianceForm').style.display = '';
        document.querySelector('#modalInvestigateVariance .modal-head .card-title').textContent = 'Investigate Variance';
        modal.removeEventListener('click', handleBackdropClick);
    };
    const handleBackdropClick = function(e) {
        if (e.target === modal) { modal.classList.remove('show'); restoreForm(); }
    };
    modal.addEventListener('click', handleBackdropClick);
    const closeBtn = modal.querySelector('.modal-head .close');
    const origOnclick = closeBtn.onclick;
    closeBtn.onclick = function() { modal.classList.remove('show'); restoreForm(); closeBtn.onclick = origOnclick; };
}

// -- Pump Reading Filters --
function applyPumpFilters() {
    const date = document.getElementById('pumpFilterDate')?.value;
    const shift = document.getElementById('pumpFilterShift')?.value;
    const status = document.getElementById('pumpFilterStatus')?.value;

    let url = 'fuel_staff.php?tab=pump';
    if (date) url += '&date=' + encodeURIComponent(date);
    if (shift) url += '&shift=' + encodeURIComponent(shift);
    if (status) url += '&status=' + encodeURIComponent(status);
    window.location.href = url;
}

function resetPumpFilters() {
    window.location.href = 'fuel_staff.php?tab=pump';
}

// -- Pump Management --
function openEditPumpModal(pumpId, pumpNumber, status) {
    document.getElementById('editPumpId').value = pumpId;
    document.getElementById('editPumpNumber').value = pumpNumber;
    const radio = document.querySelector('#modalEditPump input[name="status"][value="' + status + '"]');
    if (radio) radio.checked = true;
    document.getElementById('modalEditPump').classList.add('show');
}

function openDeletePumpModal(pumpId, pumpNumber) {
    document.getElementById('deletePumpId').value = pumpId;
    document.getElementById('deletePumpName').textContent = 'Pump ' + pumpNumber;
    document.getElementById('modalDeletePump').classList.add('show');
}

// -- Nozzle Management --
function openAddNozzleModal(pumpId, pumpNumber) {
    document.querySelector('#modalAddNozzle form').reset();
    document.getElementById('addNozzlePumpId').value = pumpId;
    document.getElementById('addNozzlePumpNumber').textContent = 'Pump ' + pumpNumber;
    loadFuelTypesForNozzle();
    document.getElementById('modalAddNozzle').classList.add('show');
}

function openEditNozzleModal(nozzleId, pumpId, nozzleNumber, fuelTypeId, status, notes) {
    document.querySelector('#modalEditNozzle form').reset();
    document.getElementById('editNozzleId').value = nozzleId;
    document.getElementById('editNozzleNumber').value = nozzleNumber;
    const radio = document.querySelector('#modalEditNozzle input[name="status"][value="' + status + '"]');
    if (radio) radio.checked = true;
    if (document.getElementById('editNozzleNotes')) {
        document.getElementById('editNozzleNotes').value = notes || '';
    }
    loadFuelTypesForNozzle('editNozzleFuelTypeId', fuelTypeId);
    document.getElementById('modalEditNozzle').classList.add('show');
}

function loadFuelTypesForNozzle(selectId, selectedId) {
    const targetSelect = selectId ? document.getElementById(selectId) : document.querySelector('#modalAddNozzle select[name="fuel_type_id"]');
    if (!targetSelect) return;

    if (targetSelect.children.length > 1) {
        if (selectedId) targetSelect.value = selectedId;
        return;
    }

    if (typeof DataHelper !== 'undefined' && DataHelper.populateFuelTypes) {
        DataHelper.populateFuelTypes(targetSelect.id || targetSelect.name, '-- Select Fuel Type --').then(() => {
            if (selectedId) targetSelect.value = selectedId;
        });
    }
}

// -- Approve Reconciliation Modal --
function showApproveModal(reconId, fuelType, reconDate) {
    document.getElementById('approveReconId').value = reconId;
    document.getElementById('approveFuelType').textContent = fuelType;
    document.getElementById('approveReconDate').textContent = new Date(reconDate + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    document.getElementById('modalApproveReconciliation').classList.add('show');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
