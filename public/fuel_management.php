<?php
$page_id = 'fuel_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/validate_foreign_keys_helper.php';
require_login();

$me = current_user();
$userRole = role_key($me['role'] ?? '');

// Redirect staff to new fuel management system
if ($userRole === 'staff') {
    header('Location: fuel_readings_encoding.php');
    exit;
}
$isAdmin   = in_array($userRole, ['admin', 'superadmin']);
$isManager = in_array($userRole, ['manager', 'admin', 'superadmin']);
$isStaff   = in_array($userRole, ['manager', 'admin', 'superadmin']);
$isSuper   = $userRole === 'superadmin';
$station_id = $isSuper ? ($_GET['station'] ?? '') : user_station_id();
$msg = '';

// Fetch stations for dropdown if superadmin
$stations = [];
if ($isSuper) {
    try {
        $stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {}
}

// AJAX: Get pump calibration value
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_pump_calibration') {
    header('Content-Type: application/json');
    
    $pump_id = $_GET['pump_id'] ?? 0;
    
    if (!$pump_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid pump ID']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT station_id, fuel_type_id, calibration_value FROM fuel_pumps WHERE id = ?");
        $stmt->execute([$pump_id]);
        $pump = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pump) {
            $calibration = $pump['calibration_value'] ?? 0;
          $price_per_liter = 0;

          if (!empty($pump['fuel_type_id'])) {
                $stmt = $pdo->prepare("SELECT price_per_liter FROM fuel_pricing WHERE station_id = ? AND fuel_type_id = ? AND is_active = 1 ORDER BY effective_date DESC, id DESC LIMIT 1");
            $stmt->execute([$pump['station_id'], $pump['fuel_type_id']]);
            $price_row = $stmt->fetch(PDO::FETCH_ASSOC);
            $price_per_liter = $price_row ? (float)$price_row['price_per_liter'] : 0;
          }

          echo json_encode(['success' => true, 'calibration' => $calibration, 'price_per_liter' => $price_per_liter]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Pump not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle Fuel Management Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // STAFF: Record Daily Pump Reading
    if ($action === 'record_pump_reading') {
        if (!$isStaff) {
            $msg = "❌ Error: Only authorized users can record pump readings.";
        } else {
            $fuel_station_id = $_POST['fuel_station_id'];
            $reading_date = $_POST['reading_date'];
            $shift = $_POST['shift'];
            $previous_reading = (float)($_POST['previous_reading'] ?? 0);
            $present_reading = (float)($_POST['present_reading'] ?? ($_POST['current_reading'] ?? 0));
            $price_per_liter = (float)($_POST['price_per_liter'] ?? 0);
            $notes = $_POST['notes'] ?? '';
            
            // Get pump calibration value
            $calibration = 0;
            if ($fuel_station_id) {
                $stmt = $pdo->prepare("SELECT calibration_value FROM fuel_pumps WHERE id = ?");
                $stmt->execute([$fuel_station_id]);
                $pump = $stmt->fetch(PDO::FETCH_ASSOC);
                $calibration = (float)($pump['calibration_value'] ?? 0);
            }
            
            // Calculate sales liters (excluding calibration)
            $difference = $present_reading - $previous_reading;
            $sales_liters = $difference - $calibration;
            $sales_amount = $sales_liters * $price_per_liter;

            // Validation & error prevention
            if ($present_reading < $previous_reading) {
              $msg = "❌ Error: Present reading must be greater than or equal to previous reading.";
            } elseif ($calibration > $difference) {
              $msg = "❌ Error: Calibration cannot exceed the difference between present and previous readings.";
            } elseif ($price_per_liter <= 0) {
              $msg = "❌ Error: Price per liter must be greater than zero.";
            } elseif ($sales_liters < 0) {
              $msg = "❌ Error: Negative liters computed. Please review present, previous, and calibration values.";
            }
            
            if (!$msg && $fuel_station_id && $reading_date && $shift) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO fuel_daily_readings (station_id, pump_id, fuel_station_id, reading_date, shift, previous_reading, current_reading, sales_liters, calibration, user_id, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$station_id, $fuel_station_id, $fuel_station_id, $reading_date, $shift, $previous_reading, $present_reading, $sales_liters, $calibration, $me['id'], $notes]);
                    
                    log_activity($pdo, $me['id'], 'Record Pump Reading', "Recorded reading for pump #$fuel_station_id ($shift shift). Sales: $sales_liters L (Calibration: $calibration L excluded)", 'fuel_management');
                  $msg = "✅ Pump reading recorded successfully. Net liters sold: " . number_format($sales_liters, 2) . " L | Peso sales: ₱" . number_format($sales_amount, 2) . " (Calibration: " . number_format($calibration, 2) . " L excluded)";
                } catch (PDOException $e) {
                    if ($e->errorInfo[1] == 1062) { // Duplicate entry
                        $msg = "❌ Error: Reading already recorded for this pump, date, and shift.";
                    } else {
                        $msg = "❌ Error: " . $e->getMessage();
                    }
                }
            } else {
                $msg = "❌ Error: Please fill all required fields.";
            }
        }
    
    // STAFF: Record Fuel Delivery
    } elseif ($action === 'record_delivery') {
        if (!$isStaff) {
            $msg = "❌ Error: Only authorized users can record deliveries.";
        } else {
            $delivery_date = $_POST['delivery_date'];
            $fuel_type = $_POST['fuel_type'];
            $supplier = 'Petron Corporation';
            $invoice_no = $_POST['invoice_no'] ?? '';
            $delivery_liters = (float)$_POST['delivery_liters'];
            $tanker_number = $_POST['tanker_number'] ?? '';
            $notes = $_POST['notes'] ?? '';
            
            if ($delivery_date && $fuel_type && $supplier && $delivery_liters > 0) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO fuel_deliveries (station_id, delivery_date, fuel_type, supplier, invoice_no, delivery_liters, tanker_number, received_by, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Review')");
                    $stmt->execute([$station_id, $delivery_date, $fuel_type, $supplier, $invoice_no, $delivery_liters, $tanker_number, $me['id'], $notes]);
                    
                    log_activity($pdo, $me['id'], 'Record Delivery', "Recorded delivery of " . number_format($delivery_liters, 2) . " liters of $fuel_type", 'fuel_management');
                    $msg = "✅ Fuel delivery recorded successfully.";
                } catch (PDOException $e) {
                    $msg = "❌ Error: " . $e->getMessage();
                }
            } else {
                $msg = "❌ Error: Please fill all required fields.";
            }
        }
    
    // STAFF: Record Adjustment
    } elseif ($action === 'record_adjustment') {
        if (!$isStaff) {
            $msg = "❌ Error: Only authorized users can record adjustments.";
        } else {
            $adjustment_date = $_POST['adjustment_date'];
            $fuel_type = $_POST['fuel_type'];
            $adjustment_type = $_POST['adjustment_type'];
            $liters = (float)$_POST['liters'];
            $reason = $_POST['reason'];
            $notes = $_POST['notes'] ?? '';
            
            if ($adjustment_date && $fuel_type && $adjustment_type && $liters != 0 && $reason) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO fuel_adjustments (station_id, adjustment_date, fuel_type, adjustment_type, liters, reason, user_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$station_id, $adjustment_date, $fuel_type, $adjustment_type, $liters, $reason, $me['id'], $notes]);
                    
                    $adj_type = ucfirst($adjustment_type);
                    log_activity($pdo, $me['id'], 'Record Adjustment', "$adj_type of " . number_format($liters, 2) . " liters ($fuel_type)", 'fuel_management');
                    $msg = "✅ Adjustment recorded successfully.";
                } catch (PDOException $e) {
                    $msg = "❌ Error: " . $e->getMessage();
                }
            } else {
                $msg = "❌ Error: Please fill all required fields.";
            }
        }
    
    // MANAGER: Verify Pump Reading
    } elseif ($action === 'verify_reading') {
        if (!$isManager) {
            $msg = "❌ Error: Only managers can verify readings.";
        } else {
            $id = $_POST['id'];
            $status = $_POST['status'];
            $notes = $_POST['notes'] ?? '';
            
            try {
                $stmt = $pdo->prepare("UPDATE fuel_daily_readings SET status = ?, notes = CONCAT(COALESCE(notes,''), '\n[Admin Review] ', ?) WHERE id = ?");
                $stmt->execute([$status, $notes, $id]);
                
                if ($stmt->rowCount() > 0) {
                    log_activity($pdo, $me['id'], 'Verify Reading', "Verified pump reading #$id as $status", 'fuel_management');
                    $msg = "✅ Pump reading #$id has been $status.";
                } else {
                    $msg = "❌ Error: Reading not found.";
                }
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    
    // MANAGER: Verify Delivery
    } elseif ($action === 'verify_delivery') {
        if (!$isManager) {
            $msg = "❌ Error: Only managers can verify deliveries.";
        } else {
            $id = $_POST['id'];
            $status = $_POST['status'];
            $notes = $_POST['notes'] ?? '';
            
            try {
                $stmt = $pdo->prepare("UPDATE fuel_deliveries SET status = ?, verified_by = ?, notes = CONCAT(COALESCE(notes,''), '\n[Admin Verification] ', ?) WHERE id = ?");
                $stmt->execute([$status, $me['id'], $notes, $id]);
                
                if ($stmt->rowCount() > 0) {
                    log_activity($pdo, $me['id'], 'Verify Delivery', "Verified delivery #$id as $status", 'fuel_management');
                    $msg = "✅ Delivery #$id has been $status.";
                } else {
                    $msg = "❌ Error: Delivery not found.";
                }
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    
    // MANAGER: Approve Adjustment
    } elseif ($action === 'approve_adjustment') {
        if (!$isManager) {
            $msg = "❌ Error: Only managers can approve adjustments.";
        } else {
            $id = $_POST['id'];
            $status = $_POST['status'];
            $notes = $_POST['notes'] ?? '';
            
            try {
                $stmt = $pdo->prepare("UPDATE fuel_adjustments SET status = ?, approved_by = ?, notes = CONCAT(COALESCE(notes,''), '\n[Admin Approval] ', ?) WHERE id = ?");
                $stmt->execute([$status, $me['id'], $notes, $id]);
                
                if ($stmt->rowCount() > 0) {
                    log_activity($pdo, $me['id'], 'Approve Adjustment', "Approved adjustment #$id as $status", 'fuel_management');
                    $msg = "✅ Adjustment #$id has been $status.";
                } else {
                    $msg = "❌ Error: Adjustment not found.";
                }
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    
    // MANAGER: Run Reconciliation
    } elseif ($action === 'run_reconciliation') {
        if (!$isManager) {
            $msg = "❌ Error: Only managers can run reconciliation.";
        } else {
            $reconciliation_date = $_POST['reconciliation_date'];
            $fuel_type = $_POST['fuel_type'];
            $physical_stock = (float)$_POST['physical_stock'];
            $notes = $_POST['notes'] ?? '';
            
            if ($reconciliation_date && $fuel_type && $physical_stock >= 0) {
                try {
                    // --- OPENING STOCK ---
                    // Try previous reconciliation first, then fall back to station_inventory
                    $prev_day = date('Y-m-d', strtotime($reconciliation_date . ' -1 day'));
                    $stmt = $pdo->prepare("SELECT closing_stock FROM fuel_reconciliation WHERE station_id = ? AND fuel_type = ? AND reconciliation_date = ? AND status IN ('Finalized','Approved','Verified') ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$station_id, $fuel_type, $prev_day]);
                    $prev_recon = $stmt->fetch();
                    
                    if ($prev_recon && $prev_recon['closing_stock'] !== null) {
                        $opening_stock = (float)$prev_recon['closing_stock'];
                    } else {
                        // Fall back to current station_inventory level for this fuel product
                        $stmt = $pdo->prepare("
                            SELECT si.stock_level 
                            FROM station_inventory si
                            JOIN products p ON p.id = si.product_id
                            WHERE si.station_id = ? AND p.name = ? 
                            AND p.type_id = (SELECT id FROM product_types WHERE name = 'fuel')
                            LIMIT 1
                        ");
                        $stmt->execute([$station_id, $fuel_type]);
                        $inv = $stmt->fetch();
                        $opening_stock = $inv ? (float)$inv['stock_level'] : 0;
                    }
                    
                    // --- DELIVERIES ---
                    // Match by fuel_type name (fuel_deliveries stores name as varchar)
                    $stmt = $pdo->prepare("SELECT SUM(delivery_liters) as total FROM fuel_deliveries WHERE station_id = ? AND fuel_type = ? AND delivery_date = ? AND status IN ('Verified','Finalized')");
                    $stmt->execute([$station_id, $fuel_type, $reconciliation_date]);
                    $deliveries_data = $stmt->fetch();
                    $deliveries = (float)($deliveries_data['total'] ?? 0);
                    
                    // --- SALES ---
                    // Readings may use pump_id (fuel_staff.php) or fuel_station_id (fuel_management.php)
                    // Join through fuel_pumps -> fuel_types to match by fuel type name
                    $stmt = $pdo->prepare("
                        SELECT SUM(dr.sales_liters) as total 
                        FROM fuel_daily_readings dr
                        LEFT JOIN fuel_pumps fp ON dr.pump_id = fp.id
                        LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
                        LEFT JOIN fuel_stations fs ON dr.fuel_station_id = fs.id
                        WHERE dr.station_id = ? 
                        AND (ft.name = ? OR fs.fuel_type = ?)
                        AND dr.reading_date = ? 
                        AND dr.status IN ('Verified','Finalized')
                    ");
                    $stmt->execute([$station_id, $fuel_type, $fuel_type, $reconciliation_date]);
                    $sales_data = $stmt->fetch();
                    $sales = (float)($sales_data['total'] ?? 0);
                    
                    // --- ADJUSTMENTS ---
                    $stmt = $pdo->prepare("SELECT SUM(CASE WHEN adjustment_type = 'Loss' THEN -liters ELSE liters END) as total FROM fuel_adjustments WHERE station_id = ? AND fuel_type = ? AND adjustment_date = ? AND status = 'Approved'");
                    $stmt->execute([$station_id, $fuel_type, $reconciliation_date]);
                    $adjustments_data = $stmt->fetch();
                    $adjustments = (float)($adjustments_data['total'] ?? 0);
                    
                    // --- CALCULATE ---
                    $closing_stock = $opening_stock + $deliveries - $sales + $adjustments;
                    $variance = $physical_stock - $closing_stock;
                    $variance_percent = $closing_stock > 0 ? ($variance / $closing_stock) * 100 : 0;
                    
                    // Get fuel_type_id from fuel_types table
                    $fuel_type_id = 0;
                    $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE name = ?");
                    $stmt->execute([$fuel_type]);
                    $fuel_type_data = $stmt->fetch();
                    if ($fuel_type_data) {
                        $fuel_type_id = (int)$fuel_type_data['id'];
                    }
                    
                    // Validate and get a valid pump_id for this station and fuel type
                    $pump_id = getValidPumpId($pdo, $station_id, $fuel_type_id);
                    if ($pump_id === null) {
                        $msg = "❌ Error: No fuel pumps configured for this station. Please add pumps first.";
                        throw new Exception("No fuel pumps configured for this station. Please add pumps first.");
                    }
                    
                    // Prepare reconciliation data for validation
                    $reconciliation_data = [
                        'station_id' => $station_id,
                        'pump_id' => $pump_id,
                        'fuel_type_id' => $fuel_type_id,
                        'verified_by' => $me['id'] ?? null
                    ];
                    
                    // Validate the data before insertion
                    $validation = validateFuelReconciliationData($pdo, $reconciliation_data);
                    if (!$validation['valid']) {
                        $msg = "❌ Validation Error: " . implode(', ', $validation['errors']);
                        throw new Exception("Validation Error: " . implode(', ', $validation['errors']));
                    }
                    
                    // Use cleaned data for insertion
                    $clean_data = $validation['cleaned_data'];
                    
                    // Insert reconciliation record
                    $stmt = $pdo->prepare("INSERT INTO fuel_reconciliation (station_id, reconciliation_date, fuel_type_id, fuel_type, pump_id, opening_stock, deliveries, sales, adjustments, closing_stock, physical_stock, variance, variance_percent, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$station_id, $reconciliation_date, $clean_data['fuel_type_id'], $fuel_type, $clean_data['pump_id'], $opening_stock, $deliveries, $sales, $adjustments, $closing_stock, $physical_stock, $variance, $variance_percent, $notes]);
                    
                    // If variance exceeds threshold, create variance report
                    $variance_threshold = 0.05; // 5%
                    if (abs($variance_percent) > $variance_threshold) {
                        $stmt = $pdo->prepare("INSERT INTO fuel_variance_reports (station_id, report_date, fuel_type, expected_stock, actual_stock, variance_liters, variance_percent, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$station_id, $reconciliation_date, $fuel_type, $closing_stock, $physical_stock, $variance, $variance_percent, "Auto-generated from reconciliation"]);
                    }
                    
                    log_activity($pdo, $me['id'], 'Run Reconciliation', "Reconciliation for $fuel_type on $reconciliation_date", 'fuel_management');
                    $msg = "✅ Reconciliation completed. Variance: " . number_format($variance, 2) . " liters (" . number_format($variance_percent, 2) . "%)";
                } catch (PDOException $e) {
                    if ($e->errorInfo[1] == 1062) { // Duplicate entry
                        $msg = "❌ Error: Reconciliation already done for this date and fuel type.";
                    } else {
                        $msg = "❌ Error: " . $e->getMessage();
                    }
                }
            } else {
                $msg = "❌ Error: Please fill all required fields.";
            }
        }
    
    // MANAGER: Approve Reconciliation (changes status from Pending to Approved)
    } elseif ($action === 'approve_reconciliation') {
        if (!$isManager) {
            $msg = "❌ Error: Only managers can approve reconciliations.";
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
                        $msg = "❌ Error: Reconciliation record not found.";
                    } elseif ($recon['status'] !== 'Pending') {
                        $msg = "❌ Error: Only Pending reconciliations can be approved.";
                    } else {
                        // Update status to Approved
                        $stmt = $pdo->prepare("UPDATE fuel_reconciliation SET status = 'approved', manager_notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                        $stmt->execute([$manager_notes, $me['id'], $recon_id]);
                        
                        log_activity($pdo, $me['id'], 'Approve Reconciliation', "Approved reconciliation #{$recon_id} for {$recon['fuel_type']} on {$recon['reconciliation_date']}", 'fuel_management');
                        $msg = "✅ Reconciliation approved! Admin can now finalize it.";
                    }
                } catch (Exception $e) {
                    $msg = "❌ Error: " . $e->getMessage();
                }
            } else {
                $msg = "❌ Error: Invalid reconciliation ID.";
            }
        }
    }
}

// Fetch data based on user role
$fuel_stations = [];
$daily_readings = [];
$deliveries = [];
$adjustments = [];
$reconciliations = [];
$variance_reports = [];

if ($station_id) {
    try {
        // Fetch fuel stations/pumps
        $stmt = $pdo->prepare("SELECT * FROM fuel_stations WHERE station_id = ? ORDER BY pump_number");
        $stmt->execute([$station_id]);
        $fuel_stations = $stmt->fetchAll();
        
        // Fetch daily readings with filters
        $filter_date = $_GET['date'] ?? '';
        $filter_shift = $_GET['shift'] ?? '';
        $filter_status = $_GET['status'] ?? '';
        
        $sql = "SELECT dr.*, fp.pump_number, ft.name as fuel_type_name, u.name as user_name 
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
        
        // Fetch reconciliations
        $stmt = $pdo->prepare("SELECT r.*, v.name as verifier_name 
                              FROM fuel_reconciliation r 
                              LEFT JOIN users v ON r.verified_by = v.id 
                              WHERE r.station_id = ? 
                              ORDER BY r.reconciliation_date DESC 
                              LIMIT 30");
        $stmt->execute([$station_id]);
        $reconciliations = $stmt->fetchAll();
        
        // Fetch variance reports
        $stmt = $pdo->prepare("SELECT vr.*, i.name as investigator_name 
                              FROM fuel_variance_reports vr 
                              LEFT JOIN users i ON vr.investigated_by = i.id 
                              WHERE vr.station_id = ? 
                              ORDER BY vr.report_date DESC 
                              LIMIT 20");
        $stmt->execute([$station_id]);
        $variance_reports = $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Fuel Management Error: " . $e->getMessage());
    }
}

// Fetch fuel types from database (not hardcoded)
$db_fuel_types = [];
try {
    // Try inventory_products first (category = Fuel)
    $stmt = $pdo->prepare("SELECT DISTINCT product_name AS name FROM inventory_products WHERE LOWER(category) = 'fuel' ORDER BY product_name");
    $stmt->execute();
    $db_fuel_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
if (empty($db_fuel_types)) {
    try {
        // Fallback to fuel_types table
        $stmt = $pdo->prepare("SELECT name FROM fuel_types ORDER BY name");
        $stmt->execute();
        $db_fuel_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}
// Helper to render fuel type options
function renderFuelTypeOptions(array $types, string $selected = ''): string {
    $html = '<option value="">-- Select --</option>';
    foreach ($types as $t) {
        $sel = ($t === $selected) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($t) . '"' . $sel . '>' . htmlspecialchars($t) . '</option>';
    }
    return $html;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* == PAGE HEADER - matches Transaction Module int-head standard == */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:0px !important; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }
.int-head .actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

/* ══════════════════════════════════════════════════
   FUEL MANAGEMENT – GLOBAL BUTTON OVERRIDE FIXES
   Forces plain-white look for all action buttons since
   partials/header.php applies !important dark-blue globally
   ══════════════════════════════════════════════════ */

/* 1. TAB PILL BUTTONS — transparent background, correct text color */
.tabs.pills .tab,
.tabs.pills button.tab {
    background-color: transparent !important;
    background: transparent !important;
    color: #475569 !important;
    border: none !important;
    box-shadow: none !important;
}
.tabs.pills .tab.active,
.tabs.pills button.tab.active {
    background-color: #002F70 !important;
    background: #002F70 !important;
    color: #ffffff !important;
    border: none !important;
}
.tabs.pills .tab:not(.active):hover,
.tabs.pills button.tab:not(.active):hover {
    background-color: rgba(0,47,112,0.08) !important;
    background: rgba(0,47,112,0.08) !important;
    color: #002F70 !important;
}

/* 2. ALL BTN INSIDE MODALS — plain white with dark-blue border */
.modal .btn,
.modal-body .btn,
.modal-footer .btn,
.show .modal-footer .btn {
    background-color: #ffffff !important;
    background: #ffffff !important;
    color: #002F70 !important;
    border: 1px solid #002F70 !important;
    font-weight: 600 !important;
}
.modal .btn:hover,
.modal-body .btn:hover,
.modal-footer .btn:hover {
    background-color: #002F70 !important;
    background: #002F70 !important;
    color: #ffffff !important;
}
/* Special: primary/danger variants inside modals keep their distinct look */
.modal .btn.primary {
    background-color: #002F70 !important;
    background: #002F70 !important;
    color: #ffffff !important;
    border: 1px solid #002F70 !important;
}
.modal .btn.danger {
    background-color: #dc3545 !important;
    background: #dc3545 !important;
    color: #ffffff !important;
    border: 1px solid #dc3545 !important;
}

/* 3. TABLE & CARD ACTION BUTTONS — plain white */
.table .btn, 
.table-wrap .btn,
.filter-bar .btn,
.card-head .btn,
.form-grid .btn {
    background-color: #ffffff !important;
    background: #ffffff !important;
    color: #002F70 !important;
    border: 1px solid #002F70 !important;
    font-weight: 600 !important;
    transition: all 0.15s ease-in-out !important;
}
.table .btn:hover, 
.table-wrap .btn:hover,
.filter-bar .btn:hover,
.card-head .btn:hover,
.form-grid .btn:hover {
    background-color: #002F70 !important;
    background: #002F70 !important;
    color: #ffffff !important;
    border-color: #002F70 !important;
}

/* 4. MODAL CLOSE / ICON BUTTONS — no background at all */
.icon-btn,
.modal-close,
.sr-modal-close,
.modal-header .icon-btn,
.modal-head .modal-close {
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    color: #64748b !important;
    box-shadow: none !important;
}
.icon-btn:hover,
.modal-close:hover,
.sr-modal-close:hover,
.modal-header .icon-btn:hover,
.modal-head .modal-close:hover {
    background: none !important;
    background-color: transparent !important;
    color: #1e293b !important;
}

.fuel-badge { display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:600; }
.fuel-badge.pending_review { background:#fff3cd;color:#856404; }
.fuel-badge.approved { background:rgba(40,167,69,.1);color:#28a745; }
.fuel-badge.rejected { background:rgba(220,53,69,.1);color:#dc3545; }
.fuel-badge.pending { background:#fff3cd;color:#856404; }
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
</style>

  <div class="int-head" data-rendering="php">
    <div>
      <h1><i class="fas fa-gas-pump"></i> Fuel Management</h1>
      <div class="sub">Centralized fuel inventory monitoring and reconciliation</div>
    </div>
    <?php if($isSuper): ?>
    <div class="actions">
        <form method="get" style="display:inline-flex; align-items:center; gap:10px;">
            <label for="station_filter" class="sub">Viewing Station:</label>
            <select name="station" id="station_filter" onchange="this.form.submit()" class="select" style="width:auto;">
                <option value="">-- Select a Station --</option>
                <?php foreach($stations as $id => $name): ?>
                    <option value="<?php echo $id; ?>" <?php echo $station_id == $id ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php endif; ?>
  </div>

  <!-- WORKFLOW NAVIGATION SECTION -->
  <section class="card" style="margin-top:18px">
    <div class="card-head">
      <div class="card-title">Manager Workflows</div>
    </div>
    <div class="workflow-grid">
      
      <!-- Manager: Verify Deliveries -->
      <?php if ($isManager): ?>
        <div class="workflow-link" style="cursor: default; pointer-events: none; opacity: 0.7;">
          <div class="wf-icon"><i class="fas fa-truck"></i></div>
          <strong>Verify Deliveries</strong>
          <small>Review and verify recorded fuel deliveries</small>
          <?php
            try {
              $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM fuel_deliveries WHERE station_id = ? AND status = 'Pending Review'");
              $stmt->execute([$station_id]);
              $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
              echo "<span class='wf-count'>" . intval($count) . " pending</span>";
            } catch (Exception $e) {}
          ?>
        </div>
      <?php endif; ?>
      
      <!-- Manager: Shift-End Processing -->
      <?php if ($isManager): ?>
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
      <?php endif; ?>
      
      <!-- Audit Trail -->
      <div class="workflow-link" style="cursor: default; pointer-events: none; opacity: 0.7; border-left-color:var(--muted);">
        <div class="wf-icon"><i class="fas fa-clipboard-list"></i></div>
        <strong>Audit Trail</strong>
        <small>View complete transaction history</small>
      </div>
    </div>
  </section>

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
              return $d['status'] == 'Pending Review';
          });
          echo count($pending_deliveries);
          ?>
        </div>
        <div class="metric-sub">Awaiting verification</div>
      </div>
    </div>
    <div class="card metric">
      <div class="metric-ico amber"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="metric-meta">
        <div class="metric-label">Open Variances</div>
        <div class="metric-value">
          <?php 
          $open_variances = array_filter($variance_reports, function($v) {
              return $v['status'] == 'Open' || $v['status'] == 'Under Investigation';
          });
          echo count($open_variances);
          ?>
        </div>
        <div class="metric-sub">Requires attention</div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="tabs pills">
    <button class="tab active" data-fueltab="operations"><i class="fas fa-gas-pump"></i> Daily Operations</button>
    <a href="staff_fuel_deliveries.php" class="tab" style="text-decoration: none; color: inherit; display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; transition: all 0.2s;"><i class="fas fa-truck"></i> Fuel Deliveries</a>
    <button class="tab" data-fueltab="adjustments"><i class="fas fa-exchange-alt"></i> Adjustments</button>
    <button class="tab" data-fueltab="reconciliation"><i class="fas fa-calculator"></i> Reconciliation</button>
    <button class="tab" data-fueltab="variances"><i class="fas fa-exclamation-triangle"></i> Variance Reports</button>
    <button class="tab" data-fueltab="history"><i class="fas fa-history"></i> Shift History</button>
  </div>

  <!-- TAB 1: DAILY OPERATIONS -->
  <section class="card" id="tab-operations">
    <div class="card-head">
      <div class="card-title">Daily Pump Readings</div>
      <button class="btn primary" onclick="document.getElementById('modalRecordReading').classList.add('show')">
        <i class="fas fa-plus"></i> New Reading
      </button>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Record daily pump meter readings per shift</div>
    
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
          <option value="Pending Review" <?php echo $filter_status == 'Pending Review' ? 'selected' : ''; ?>>Pending Review</option>
          <option value="Verified" <?php echo $filter_status == 'Verified' ? 'selected' : ''; ?>>Verified</option>
          <option value="Rejected" <?php echo $filter_status == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
        </select>
      </div>
      <div class="filter-group" style="justify-content:flex-end;">
        <label>&nbsp;</label>
        <button class="btn small" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
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
          <?php foreach($daily_readings as $reading): ?>
          <tr>
            <td><?php echo date('M d, Y', strtotime($reading['reading_date'])); ?></td>
            <td>
              <b><?php echo htmlspecialchars($reading['pump_number']); ?></b><br>
              <small style="color:var(--muted);"><?php echo htmlspecialchars($reading['fuel_type_name'] ?? $reading['fuel_type']); ?></small>
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
              <?php if($reading['status'] == 'Pending Review' && $isManager): ?>
                <button class="btn primary small" style="background:var(--blue);" onclick="openReviewReadingModal(<?php echo $reading['id']; ?>)">
                  <i class="fas fa-check-circle"></i> Verify
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
  </section>

  <!-- TAB 2: FUEL DELIVERIES -->
  <section class="card hidden" id="tab-deliveries">
    <div class="card-head">
      <div class="card-title">Fuel Deliveries</div>
      <button class="btn primary" onclick="document.getElementById('modalRecordDelivery').classList.add('show')">
        <i class="fas fa-truck"></i> New Delivery
      </button>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Record and verify fuel deliveries from suppliers</div>

    <div class="table-tools">
      <div class="searchbar small">
        <span class="ico"><i class="fas fa-search"></i></span>
        <input id="deliverySearch" placeholder="Search deliveries..." autocomplete="off" />
      </div>
    </div>

    <div class="table-wrap">
      <table class="table" id="deliveryTable">
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
              <?php if(in_array($delivery['status'], ['Pending Review', 'Pending', 'Encoded']) && $isManager): ?>
                <button class="btn primary small" style="background:var(--blue);" onclick="openVerifyDeliveryModal(<?php echo $delivery['id']; ?>)">
                  <i class="fas fa-check-circle"></i> Verify
                </button>
              <?php endif; ?>
              <button class="btn ghost small" onclick="viewDeliveryDetails(<?php echo $delivery['id']; ?>)">
                <i class="fas fa-eye"></i> View
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
  </section>

  <!-- TAB 3: ADJUSTMENTS -->
  <section class="card hidden" id="tab-adjustments">
    <div class="card-head">
      <div class="card-title">Fuel Adjustments</div>
      <button class="btn primary" onclick="document.getElementById('modalRecordAdjustment').classList.add('show')">
        <i class="fas fa-exchange-alt"></i> New Adjustment
      </button>
    </div>
    <div class="sub" style="padding:0 16px 4px;">Record losses, transfers, and other adjustments</div>

    <div class="table-tools">
      <div class="searchbar small">
        <span class="ico"><i class="fas fa-search"></i></span>
        <input id="adjustmentSearch" placeholder="Search adjustments..." autocomplete="off" />
      </div>
    </div>

    <div class="table-wrap">
      <table class="table" id="adjustmentTable">
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
              <?php if($adj['status'] == 'Pending' && $isManager): ?>
                <button class="btn ghost small" style="color:var(--blue);" onclick="openApproveAdjustmentModal(<?php echo $adj['id']; ?>)">
                  <i class="fas fa-check"></i> Approve
                </button>
              <?php endif; ?>
              <button class="btn ghost small" onclick="viewAdjustmentDetails(<?php echo $adj['id']; ?>)">
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

  <!-- TAB 4: RECONCILIATION -->
  <section class="card hidden" id="tab-reconciliation">
    <div class="card-head">
      <div class="card-title">Fuel Reconciliation</div>
      <?php if($isManager): ?>
      <button class="btn primary" onclick="document.getElementById('modalRunReconciliation').classList.add('show')">
        <i class="fas fa-calculator"></i> Run Reconciliation
      </button>
      <?php endif; ?>
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
                <span style="color:var(--muted);">0.00 L</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="fuel-badge <?php echo strtolower($recon['status']); ?>">
                <?php echo $recon['status']; ?>
              </span>
            </td>
            <td>
              <?php if($recon['status'] === 'Pending' && $isManager): ?>
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
          <tr><td colspan="11" style="text-align:center;">No reconciliation records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- TAB 5: VARIANCE REPORTS -->
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
            <td><?php echo $report['investigator_name'] ? htmlspecialchars($report['investigator_name']) : '<span style="color:var(--muted);">--</span>'; ?></td>
            <td class="right">
              <button class="btn ghost small" onclick="viewVarianceDetails(<?php echo $report['id']; ?>)">
                <i class="fas fa-eye"></i> View
              </button>
              <?php if(in_array($report['status'], ['Open', 'Under Investigation']) && $isManager): ?>
                <button class="btn ghost small" style="color:var(--blue);" onclick="openInvestigateVarianceModal(<?php echo $report['id']; ?>)">
                  <i class="fas fa-search"></i> Investigate
                </button>
              <?php endif; ?>
              <?php if($report['status'] === 'Under Investigation' && $isAdmin): ?>
                <button class="btn ghost small" style="color:var(--green);" onclick="approveVarianceReport(<?php echo $report['id']; ?>)">
                  <i class="fas fa-check"></i> Approve/Close
                </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($variance_reports)): ?>
          <tr><td colspan="8" style="text-align:center;">No variance reports found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- TAB 6: SHIFT HISTORY -->
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
          // Fetch activity logs for fuel management
          try {
            $sql = "SELECT al.*, u.name as user_name 
                    FROM activity_logs al 
                    LEFT JOIN users u ON al.user_id = u.id 
                    WHERE al.action LIKE '%fuel%' 
                    ORDER BY al.created_at DESC 
                    LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $activity_logs = $stmt->fetchAll();
            
            if (!empty($activity_logs)) {
              foreach($activity_logs as $log) {
          ?>
          <tr>
            <td>
              <?php echo date('M d, Y', strtotime($log['created_at'])); ?><br>
              <small style="color:var(--muted);"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></small>
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
              echo $shift;
              ?>
            </td>
            <td>
              <span class="fuel-badge verified">Logged</span>
            </td>
          </tr>
          <?php
              }
            } else {
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
            }
          } catch (Exception $e) {
          ?>
          <tr>
            <td colspan="6" style="text-align:center; padding:30px; color:var(--danger);">
              Error loading activity logs: <?php echo htmlspecialchars($e->getMessage()); ?>
            </td>
          </tr>
          <?php
          }
          ?>
        </tbody>
      </table>
    </div>
  </section>

<?php endif; ?>

<!-- MODALS -->
<!-- Modal: Record Pump Reading -->
<div class="modal" id="modalRecordReading">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Record Pump Reading</div>
      <button class="icon-btn" onclick="document.getElementById('modalRecordReading').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="record_pump_reading">
      <div class="form-grid">
        <div>
          <label class="pay-label">Pump / Station</label>
          <select class="select" name="fuel_station_id" required>
            <option value="">-- Select Pump --</option>
            <?php foreach($fuel_stations as $fs): ?>
              <option value="<?php echo $fs['id']; ?>">Pump #<?php echo htmlspecialchars($fs['pump_number']); ?> (<?php echo htmlspecialchars($fs['fuel_type']); ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="pay-label">Reading Date</label>
          <input class="input" type="date" name="reading_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
          <label class="pay-label">Shift</label>
          <select class="select" name="shift" id="modalReadingShift" required>
            <option value="">-- Select Shift --</option>
          </select>
        </div>
        <div>
          <label class="pay-label">Previous Cumulative Liters (L)</label>
          <input class="input" type="number" name="previous_reading" step="0.01" placeholder="0.00" required>
        </div>
        <div>
          <label class="pay-label">Present Cumulative Liters (L)</label>
          <input class="input" type="number" name="present_reading" step="0.01" placeholder="0.00" required>
        </div>
        <div>
          <label class="pay-label">Calibration</label>
          <input class="input" id="calibrationDisplay" readonly disabled placeholder="Auto-loaded from pump">
        </div>
        <div>
          <label class="pay-label">Price/Liter (₱)</label>
          <input class="input" type="number" name="price_per_liter" id="price_per_liter" step="0.01" min="0" placeholder="0.00" required readonly style="background-color:#f8f9fa;">
          <small style="color: #6c757d; font-size: 11px; margin-top: 2px; display: block;">
            Auto-loaded from active fuel pricing.
            <label style="margin-left:8px; cursor:pointer;">
              <input type="checkbox" id="override_price"> Override Price
            </label>
          </small>
        </div>

      </div>
      <div class="pay-section">
        <label class="pay-label">Calculated Net Liters (L)</label>
        <input class="input" id="salesLitersDisplay" readonly disabled placeholder="Auto-calculated">
      </div>
      <div class="pay-section">
        <label class="pay-label">Calculated Peso Sales (₱)</label>
        <input class="input" id="salesAmountDisplay" readonly disabled placeholder="Auto-calculated">
      </div>
      <div class="pay-section">
        <label class="pay-label">Notes (optional)</label>
        <input class="input" name="notes" placeholder="Any remarks...">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalRecordReading').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary">Save Reading</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Record Delivery -->
<div class="modal" id="modalRecordDelivery">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Record Fuel Delivery</div>
      <button class="icon-btn" onclick="document.getElementById('modalRecordDelivery').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="record_delivery">
      <div class="form-grid">
        <div>
          <label class="pay-label">Delivery Date</label>
          <input class="input" type="date" name="delivery_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
          <label class="pay-label">Fuel Type</label>
          <select class="select" name="fuel_type" required>
            <?php echo renderFuelTypeOptions($db_fuel_types); ?>
          </select>
        </div>
        <div>
          <label class="pay-label">Supplier</label>
          <input class="input" name="supplier" value="Petron Corporation" placeholder="Supplier name" required>
        </div>
        <div>
          <label class="pay-label">Invoice No.</label>
          <input class="input" name="invoice_no" placeholder="Invoice number">
        </div>
        <div>
          <label class="pay-label">Delivery Liters</label>
          <input class="input" type="number" name="delivery_liters" step="0.01" placeholder="0.00" required>
        </div>
        <div>
          <label class="pay-label">Tanker Number</label>
          <input class="input" name="tanker_number" placeholder="Tanker plate/ID">
        </div>
      </div>
      <div class="pay-section">
        <label class="pay-label">Notes (optional)</label>
        <input class="input" name="notes" placeholder="Any remarks...">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalRecordDelivery').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary">Save Delivery</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Record Adjustment -->
<div class="modal" id="modalRecordAdjustment">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Record Fuel Adjustment</div>
      <button class="icon-btn" onclick="document.getElementById('modalRecordAdjustment').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="record_adjustment">
      <div class="form-grid">
        <div>
          <label class="pay-label">Adjustment Date</label>
          <input class="input" type="date" name="adjustment_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
          <label class="pay-label">Fuel Type</label>
          <select class="select" name="fuel_type" required>
            <?php echo renderFuelTypeOptions($db_fuel_types); ?>
          </select>
        </div>
        <div>
          <label class="pay-label">Adjustment Type</label>
          <select class="select" name="adjustment_type" required>
            <option value="">-- Select --</option>
            <option value="Loss">Loss</option>
            <option value="Gain">Gain</option>
            <option value="Transfer">Transfer</option>
          </select>
        </div>
        <div>
          <label class="pay-label">Liters</label>
          <input class="input" type="number" name="liters" step="0.01" placeholder="0.00" required>
        </div>
      </div>
      <div class="pay-section">
        <label class="pay-label">Reason</label>
        <input class="input" name="reason" placeholder="Reason for adjustment" required>
      </div>
      <div class="pay-section">
        <label class="pay-label">Notes (optional)</label>
        <input class="input" name="notes" placeholder="Any remarks...">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalRecordAdjustment').classList.remove('show')">Cancel</button>
        <button type="submit" class="btn primary">Save Adjustment</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Run Reconciliation -->
<div class="modal" id="modalRunReconciliation">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Run Fuel Reconciliation</div>
      <button class="icon-btn" onclick="document.getElementById('modalRunReconciliation').classList.remove('show')">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="run_reconciliation">
      <div class="form-grid">
        <div>
          <label class="pay-label">Reconciliation Date</label>
          <input class="input" type="date" name="reconciliation_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
          <label class="pay-label">Fuel Type</label>
          <select class="select" name="fuel_type" required>
            <?php echo renderFuelTypeOptions($db_fuel_types); ?>
          </select>
        </div>
      </div>
      <div class="pay-section">
        <label class="pay-label">Physical Stock (Liters)</label>
        <input class="input" type="number" name="physical_stock" step="0.01" placeholder="Measured physical stock" required>
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

<!-- View/Review Modals (populated by JavaScript) -->
<div class="modal" id="modalViewReading"></div>
<div class="modal" id="modalReviewReading"></div>
<div class="modal" id="modalVerifyDelivery"></div>
<div class="modal" id="modalViewDelivery"></div>
<div class="modal" id="modalApproveAdjustment"></div>
<div class="modal" id="modalInvestigateVariance"></div>
<div class="modal" id="modalViewVariance"></div>

<script>
const currentStationId = <?php echo json_encode($station_id); ?>;
// Tab Switching (matching inventory.php pattern)
const fuelTabs = document.querySelectorAll('.tab[data-fueltab]');
function showFuelTab(key) {
    fuelTabs.forEach(b => b.classList.toggle('active', b.dataset.fueltab === key));
    document.getElementById('tab-operations')?.classList.toggle('hidden', key !== 'operations');
    document.getElementById('tab-deliveries')?.classList.toggle('hidden', key !== 'deliveries');
    document.getElementById('tab-adjustments')?.classList.toggle('hidden', key !== 'adjustments');
    document.getElementById('tab-reconciliation')?.classList.toggle('hidden', key !== 'reconciliation');
    document.getElementById('tab-variances')?.classList.toggle('hidden', key !== 'variances');
    document.getElementById('tab-history')?.classList.toggle('hidden', key !== 'history');
}
fuelTabs.forEach(btn => btn.addEventListener('click', () => showFuelTab(btn.dataset.fueltab)));
showFuelTab('operations');

// Close modals when clicking backdrop
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('show');
    });
});

// Auto-calculate sales liters and fetch calibration
document.querySelectorAll('input[name="previous_reading"], input[name="present_reading"], input[name="price_per_liter"]').forEach(input => {
    input.addEventListener('input', function() {
        calculateSales();
    });
});

function setPriceLockState() {
  const priceInput = document.getElementById('price_per_liter');
  const override = document.getElementById('override_price');
  if (!priceInput || !override) return;

  const locked = !override.checked;
  priceInput.readOnly = locked;
  priceInput.style.backgroundColor = locked ? '#f8f9fa' : '';
}

document.getElementById('override_price')?.addEventListener('change', setPriceLockState);
setPriceLockState();

// Fetch calibration when pump is selected
document.querySelector('select[name="fuel_station_id"]')?.addEventListener('change', function() {
    const pumpId = this.value;
    if (pumpId) {
        fetchCalibrationValue(pumpId);
    } else {
        document.getElementById('calibrationDisplay').value = '';
      const priceInput = document.getElementById('price_per_liter');
      if (priceInput) priceInput.value = '';
      const override = document.getElementById('override_price');
      if (override) override.checked = false;
      setPriceLockState();
        calculateSales();
    }
});

function fetchCalibrationValue(pumpId) {
    fetch(`fuel_management.php?action=get_pump_calibration&pump_id=${pumpId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('calibrationDisplay').value = data.calibration ? parseFloat(data.calibration).toFixed(2) + ' L' : '0.00 L';
              const priceInput = document.getElementById('price_per_liter');
              if (priceInput) {
                  const override = document.getElementById('override_price');
                  if (!override || !override.checked) {
                      priceInput.value = data.price_per_liter ? parseFloat(data.price_per_liter).toFixed(2) : '0.00';
                  }
              }
            } else {
                document.getElementById('calibrationDisplay').value = '0.00 L';
              const priceInput = document.getElementById('price_per_liter');
              if (priceInput) priceInput.value = '0.00';
            }
            calculateSales();
        })
        .catch(error => {
            console.error('Error fetching calibration:', error);
            document.getElementById('calibrationDisplay').value = '0.00 L';
            const priceInput = document.getElementById('price_per_liter');
            if (priceInput) priceInput.value = '0.00';
            calculateSales();
        });
}

function calculateSales() {
    const prev = parseFloat(document.querySelector('input[name="previous_reading"]').value) || 0;
  const present = parseFloat(document.querySelector('input[name="present_reading"]').value) || 0;
        const price = parseFloat(document.querySelector('input[name="price_per_liter"]').value) || 0;
    const calibrationText = document.getElementById('calibrationDisplay').value || '0.00 L';
    const calibration = parseFloat(calibrationText.replace(' L', '')) || 0;
  const sales = present - prev - calibration;
        const amount = sales * price;
  document.getElementById('salesLitersDisplay').value = sales.toFixed(2) + ' L';
        const amountEl = document.getElementById('salesAmountDisplay');
        if (amountEl) amountEl.value = '₱' + amount.toFixed(2);
}

// Filter Functions
function applyFilters() {
    const date = document.getElementById('filterDate').value;
    const shift = document.getElementById('filterShift').value;
    const status = document.getElementById('filterStatus').value;
    
    let url = 'fuel_management.php?';
    if (date) url += 'date=' + encodeURIComponent(date) + '&';
    if (shift) url += 'shift=' + encodeURIComponent(shift) + '&';
    if (status) url += 'status=' + encodeURIComponent(status) + '&';
    url += 'station=<?php echo $station_id; ?>';
    
    window.location.href = url;
}

function resetFilters() {
    window.location.href = 'fuel_management.php?station=<?php echo $station_id; ?>';
}

// Search functionality for tables
function setupTableSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });
}
setupTableSearch('deliverySearch', 'deliveryTable');
setupTableSearch('adjustmentSearch', 'adjustmentTable');

// Open View Modal (Staff - Read Only)
function openViewReadingModal(id) {
    fetch(`../backend/fuel_reading_view.php?id=${id}`)
        .then(response => response.text())
        .then(html => {
            const modal = document.getElementById('modalViewReading');
      modal.innerHTML = '<div class="modal-card modal-card-xl">' + html + '</div>';
            modal.classList.add('show');
        });
}

// Open Review Modal (Manager - Approve/Reject)
function openReviewReadingModal(id) {
    fetch(`../backend/fuel_reading_review.php?id=${id}`)
        .then(response => response.text())
        .then(html => {
            const modal = document.getElementById('modalReviewReading');
      modal.innerHTML = '<div class="modal-card modal-card-xl">' + html + '</div>';
            modal.classList.add('show');
        });
}

function openVerifyDeliveryModal(id) {
    fetch(`../backend/fuel_verify_delivery.php?id=${id}&station_id=${currentStationId}`)
        .then(response => response.text())
        .then(html => {
            const modal = document.getElementById('modalVerifyDelivery');
            modal.innerHTML = '<div class="modal-card">' + html + '</div>';
            modal.classList.add('show');
        });
}

function updateAdjustmentRow(adjustmentId, newStatus) {
    // Find the adjustment row in the table
    const table = document.querySelector('#adjustmentTable tbody');
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    for (let row of rows) {
        const approveBtn = row.querySelector(`button[onclick*="openApproveAdjustmentModal(${adjustmentId})"]`);
        if (approveBtn) {
            // Update status badge
            const statusCell = row.querySelector('td:nth-child(8)'); // Status column
            if (statusCell) {
                const badge = statusCell.querySelector('.fuel-badge');
                if (badge) {
                    badge.className = `fuel-badge ${newStatus.toLowerCase()}`;
                    badge.textContent = newStatus;
                }
            }
            
            // Update actions column - remove approve button
            const actionsCell = row.querySelector('td.right');
            if (actionsCell) {
                const approveBtn = actionsCell.querySelector('button[onclick*="openApproveAdjustmentModal"]');
                if (approveBtn) {
                    approveBtn.remove();
                }
            }
            
            // Add a visual indicator that it's been processed
            row.style.backgroundColor = newStatus === 'Approved' ? 'rgba(40, 167, 69, 0.05)' : 'rgba(220, 53, 69, 0.05)';
            row.style.transition = 'background-color 0.3s ease';
            
            // Remove highlight after 2 seconds
            setTimeout(() => {
                row.style.backgroundColor = '';
            }, 2000);
            
            break;
        }
    }
}

function openApproveAdjustmentModal(id) {
    fetch(`../backend/fuel_approve_adjustment.php?id=${id}`)
        .then(response => response.text())
        .then(html => {
            const modal = document.getElementById('modalApproveAdjustment');
            modal.innerHTML = '<div class="modal-card">' + html + '</div>';
            modal.classList.add('show');
            
            // Add a small delay to ensure DOM is fully loaded before binding events
            setTimeout(() => {
                bindApproveAdjustmentEvents();
            }, 100);
        });
}

// Function to bind event listeners for the approve adjustment modal
function bindApproveAdjustmentEvents() {
    // Remove existing listeners to avoid duplicates
    const statusRadios = document.querySelectorAll('input[name="status"]');
    statusRadios.forEach(radio => {
        radio.removeEventListener('change', handleStatusChange);
        radio.addEventListener('change', handleStatusChange);
    });
    
    // Bind cancel button by ID
    const cancelBtn = document.getElementById('cancelBtn');
    if (cancelBtn) {
        cancelBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('modalApproveAdjustment').classList.remove('show');
        };
        console.log('Cancel button bound successfully');
    } else {
        console.log('Cancel button not found');
    }
    
    // Bind close button in header (X button)
    const closeBtn = document.querySelector('#modalApproveAdjustment .btn-close');
    if (closeBtn) {
        closeBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('modalApproveAdjustment').classList.remove('show');
        };
        console.log('Close button bound successfully');
    }
    
    // Bind submit button
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            submitAdjustmentForm();
        };
        console.log('Submit button bound successfully');
    } else {
        console.log('Submit button not found');
    }
}

// Handle status change for approve/reject
function handleStatusChange() {
    const rejectionDiv = document.getElementById('rejectionReason');
    const rejectionSelect = document.getElementById('rejectionSelect');
    const submitBtn = document.getElementById('submitBtn');
    
    console.log('Status changed to:', this.value);
    
    if (this.value === 'Rejected') {
        rejectionDiv.style.display = 'block';
        rejectionSelect.required = true;
        submitBtn.innerHTML = '<i class="fas fa-times"></i> Reject Adjustment';
        submitBtn.className = 'btn btn-danger';
        console.log('Rejection fields shown');
    } else {
        rejectionDiv.style.display = 'none';
        rejectionSelect.required = false;
        rejectionSelect.value = '';
        submitBtn.innerHTML = '<i class="fas fa-check"></i> Approve Adjustment';
        submitBtn.className = 'btn btn-success';
        console.log('Rejection fields hidden');
    }
}

// Close modal function - make it globally accessible
window.closeModal = function() {
    const modal = document.getElementById('modalApproveAdjustment');
    if (modal) {
        modal.classList.remove('show');
    }
};

// Alternative close modal function
function closeModal() {
    const modal = document.getElementById('modalApproveAdjustment');
    if (modal) {
        modal.classList.remove('show');
    }
}

// Form submission function
function submitAdjustmentForm() {
    const status = document.querySelector('input[name="status"]:checked')?.value;
    const rejectionReason = document.getElementById('rejectionSelect')?.value || '';
    const adjustedAmount = document.getElementById('adjustedAmount')?.value || 0;
    
    console.log('Form submission:', { status, rejectionReason, adjustedAmount });
    
    if (!status) {
        alert('Please select an approval status (Approved or Rejected).');
        return;
    }
    
    if (status === 'Rejected' && !rejectionReason) {
        alert('Please select a reason for rejection.');
        return;
    }
    
    if (status === 'Approved' && (!adjustedAmount || adjustedAmount <= 0)) {
        alert('Please enter a valid amount for approved liters.');
        return;
    }
    
    // Confirm action
    const action = status === 'Approved' ? 'approve' : 'reject';
    const confirmMsg = `Are you sure you want to ${action} this fuel adjustment?`;
    
    if (confirm(confirmMsg)) {
        const formData = new FormData(document.getElementById('approveAdjustmentForm'));
        
        // Log form data for debugging
        console.log('Form data being sent:');
        for (let [key, value] of formData.entries()) {
            console.log(key + ': ' + value);
        }
        
        fetch('../backend/fuel_process_verification.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('Response from server:', data);
            if (data.success) {
                // Show success message
                const successMsg = document.createElement('div');
                successMsg.className = 'alert alert-success';
                successMsg.innerHTML = `<i class="fas fa-check-circle"></i> ${data.message}`;
                successMsg.style.position = 'fixed';
                successMsg.style.top = '20px';
                successMsg.style.right = '20px';
                successMsg.style.zIndex = '9999';
                successMsg.style.padding = '15px 20px';
                successMsg.style.borderRadius = '8px';
                successMsg.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                successMsg.style.backgroundColor = '#28a745';
                successMsg.style.color = 'white';
                successMsg.style.border = 'none';
                document.body.appendChild(successMsg);
                
                // Remove message after 3 seconds
                setTimeout(() => {
                    successMsg.remove();
                }, 3000);
                
                // Close modal
                const modal = document.getElementById('modalApproveAdjustment');
                modal.classList.remove('show');
                
                // Update the adjustment row in the table without page reload
                const adjustmentId = formData.get('id');
                updateAdjustmentRow(adjustmentId, status);
                
            } else {
                console.error('Server error:', data);
                alert('Error: ' + (data.message || 'Unknown error occurred'));
            }
        })
        .catch(error => {
            console.error('Network error:', error);
            alert('Network error occurred. Please try again.');
        });
    }
}

function openInvestigateVarianceModal(id) {
    fetch(`../backend/fuel_investigate_variance.php?id=${id}`)
        .then(response => response.text())
        .then(html => {
            const modal = document.getElementById('modalInvestigateVariance');
            modal.innerHTML = '<div class="modal-card">' + html + '</div>';
            modal.classList.add('show');
        });
}

// Close dynamic modals
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

// Close View modal and open Review modal
function closeViewModalAndReview(id) {
    document.getElementById('modalViewReading').classList.remove('show');
    setTimeout(() => {
        openReviewReadingModal(id);
    }, 150);
}

// Submit Review (for verify reading modal)
function submitReview(id, action) {
    const reason = document.getElementById('rejectionReason')?.value || '';
    const notes = document.getElementById('rejectionNotes')?.value || '';
    
    if (action === 'Rejected' && !reason) {
        alert('Please select a reason for rejection.');
        return;
    }
    
    const confirmMsg = action === 'Approved' 
        ? 'Are you sure you want to APPROVE this pump reading?' 
        : 'Are you sure you want to REJECT this pump reading? This action cannot be undone.';
    
    if (!confirm(confirmMsg)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'review_reading');
    formData.append('id', id);
    formData.append('status', action);
    formData.append('reason', reason);
    formData.append('notes', notes);
    
    fetch('../backend/fuel_process_review.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    });
}

function showRejectionFields() {
    document.getElementById('rejectionFields').classList.add('show');
}

// View Details Functions
function viewReadingDetails(id) {
    window.location.href = `fuel_reading_details.php?id=${id}`;
}

function viewDeliveryDetails(id) {
    fetch(`../backend/fuel_delivery_view.php?id=${id}`)
        .then(response => response.text())
        .then(html => {
            const modal = document.getElementById('modalViewDelivery');
            modal.innerHTML = '<div class="modal-card modal-card-wide">' + html + '</div>';
            modal.classList.add('show');
        });
}

function viewAdjustmentDetails(id) {
    window.open(`fuel_adjustment_details.php?id=${id}`, '_blank');
}

function viewVarianceDetails(id) {
    fetch(`fuel_variance_details.php?id=${id}`)
        .then(response => response.text())
        .then(html => {
            const modal = document.getElementById('modalViewVariance');
            modal.innerHTML = '<div class="modal-card">' + html + '</div>';
            modal.classList.add('show');
        });
}

function approveVarianceReport(id) {
    if (confirm('Are you sure you want to approve/close this variance report? This will mark it as resolved.')) {
        const formData = new FormData();
        formData.append('action', 'approve_variance');
        formData.append('id', id);
        formData.append('status', 'Resolved');
        formData.append('notes', 'Variance report approved and closed by ' + <?php echo json_encode($me['name']); ?>);
        
        fetch('../backend/fuel_process_verification.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Variance report approved and closed successfully!');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Unknown error occurred'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error occurred. Please try again.');
        });
    }
}

// ── Approve Reconciliation Modal ──
function showApproveModal(reconId, fuelType, reconDate) {
    document.getElementById('approveReconId').value = reconId;
    document.getElementById('approveFuelType').textContent = fuelType;
    document.getElementById('approveReconDate').textContent = new Date(reconDate + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    document.getElementById('modalApproveReconciliation').classList.add('show');
}
</script>

<script src="../assets/js/data_helper.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    DataHelper.populateShifts('filterShift', 'All Shifts')
        .then(() => console.log('Filter shifts loaded'))
        .catch(error => {
            console.error('Failed to load shifts:', error);
        });
    DataHelper.populateShifts('modalReadingShift')
        .then(() => console.log('Modal shifts loaded'))
        .catch(error => {
            console.error('Failed to load modal shifts:', error);
        });
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
