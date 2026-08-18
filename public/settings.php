<?php
$page_id = 'settings';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = $me['role'] ?? 'staff';

// Only allow superadmin
if ($role !== 'superadmin') {
    header('Location: dashboard.php');
    exit;
}

// Get current section
$section = $_GET['section'] ?? 'service_rates';

// Handle form submissions
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_service') {
        $service_id = $_POST['service_id'] ?? '';
        $service_name = $_POST['service_name'] ?? '';
        $category = $_POST['category'] ?? '';
        $rate = (float)($_POST['rate'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        $is_active = $status === 'active' ? 1 : 0;

        // Resolve or create service_category_id from category name
        $stmt = $pdo->prepare("SELECT id FROM service_categories WHERE name = ? LIMIT 1");
        $stmt->execute([$category]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cat) {
            $service_category_id = $cat['id'];
        } else {
            $pdo->prepare("INSERT INTO service_categories (name, description) VALUES (?, ?)")
                ->execute([$category, 'Imported category']);
            $service_category_id = $pdo->lastInsertId();
        }
        
        if ($service_id) {
            // Update existing service (map to current schema)
            $stmt = $pdo->prepare("UPDATE service_rates SET service_category_id = ?, rate_name = ?, flat_rate = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$service_category_id, $service_name, $rate, $is_active, $service_id]);
            $notice = '✅ Service updated successfully';
        } else {
            // Add new service
            $stmt = $pdo->prepare("INSERT INTO service_rates (service_category_id, rate_name, flat_rate, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$service_category_id, $service_name, $rate, $is_active]);
            $notice = '✅ Service added successfully';
        }
        
        // Log activity
        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)")
                ->execute([$me['id'], 'Service Rate Update', "$service_name - $category", $_SERVER['REMOTE_ADDR']]);
        } catch(Exception $e) {}
    }
    
    elseif ($action === 'delete_service') {
        $service_id = $_POST['service_id'] ?? '';
        $stmt = $pdo->prepare("DELETE FROM service_rates WHERE id = ?");
        $stmt->execute([$service_id]);
        $notice = '✅ Service deleted successfully';
        
        // Log activity
        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)")
                ->execute([$me['id'], 'Service Rate Delete', "Service ID: $service_id", $_SERVER['REMOTE_ADDR']]);
        } catch(Exception $e) {}
    }
    
    elseif ($action === 'save_calibration') {
        $calibration_id = $_POST['calibration_id'] ?? '';
        $fuel_type = $_POST['fuel_type'] ?? '';
        $calibration_constant = (float)($_POST['calibration_constant'] ?? 0);
        $effective_date = $_POST['effective_date'] ?? date('Y-m-d');
        $status = $_POST['status'] ?? 'active';
        
        if ($calibration_id) {
            // Update existing calibration
            $stmt = $pdo->prepare("UPDATE fuel_calibration SET fuel_type = ?, calibration_constant = ?, effective_date = ?, status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$fuel_type, $calibration_constant, $effective_date, $status, $calibration_id]);
            $notice = '✅ Calibration value updated successfully';
        } else {
            // Add new calibration
            $stmt = $pdo->prepare("INSERT INTO fuel_calibration (fuel_type, calibration_constant, effective_date, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$fuel_type, $calibration_constant, $effective_date, $status]);
            $notice = '✅ Calibration value added successfully';
        }
        
        // Log activity
        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)")
                ->execute([$me['id'], 'Fuel Calibration Update', "$fuel_type - $calibration_constant", $_SERVER['REMOTE_ADDR']]);
        } catch(Exception $e) {}
    }
    
    elseif ($action === 'save_supplier') {
        $notice = '❌ Supplier modification is disabled. Petron Corporation is the sole supplier for the system.';
    }
    
    elseif ($action === 'delete_supplier') {
        $notice = '❌ Supplier deletion is disabled. Petron Corporation must remain as the sole supplier.';
    }
    
    elseif ($action === 'set_default_supplier') {
        $supplier_id = $_POST['supplier_id'] ?? 0;
        $pdo->prepare("UPDATE system_settings SET setting_value=?, updated_at=NOW(), updated_by=? WHERE setting_key='default_supplier_id'")->execute([$supplier_id, $me['id']]);
        $notice = '✅ Default supplier updated successfully';
        log_activity($pdo, $me['id'], 'Supplier Management', "Set default supplier ID: $supplier_id", $_SERVER['REMOTE_ADDR']);
    }
}

// Fetch data
$services = [];
$calibrations = [];
$suppliers_list = [];
$default_supplier_id = null;
$stations_list = [];

try {
    $stmt = $pdo->query("SELECT sr.*, sc.name AS category FROM service_rates sr LEFT JOIN service_categories sc ON sr.service_category_id = sc.id ORDER BY sc.name, sr.rate_name");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    // Create table if not exists (schema matching expected structure)
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_category_id INT DEFAULT NULL,
        station_id INT DEFAULT NULL,
        rate_name VARCHAR(100) NOT NULL,
        flat_rate DECIMAL(10,2) NOT NULL,
        estimated_duration INT DEFAULT 60,
        is_active TINYINT(1) DEFAULT 1,
        effective_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

try {
    $stmt = $pdo->query("SELECT * FROM fuel_calibration ORDER BY fuel_type, effective_date DESC");
    $calibrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    // Create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_calibration (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fuel_type ENUM('Gasoline', 'Diesel', 'LPG') NOT NULL,
        calibration_constant DECIMAL(10,6) NOT NULL,
        effective_date DATE NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

try {
    // Fetch all suppliers
    $stmt = $pdo->query("SELECT * FROM suppliers ORDER BY name");
    $suppliers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    $suppliers_list = [];
}

try {
    $stmt = $pdo->query("SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key='default_supplier_id'");
    $default_supplier_id = $stmt->fetchColumn();
} catch(Exception $e) {
    $default_supplier_id = null;
}

try {
    $stmt = $pdo->query("SELECT id, name, location FROM stations WHERE status = 'Active' ORDER BY name");
    $stations_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    $stations_list = [];
}

include __DIR__ . '/../partials/header.php';
?>


<style>
    /* System Settings Styles */
    
    /* Global button fixes */
    * {
        box-sizing: border-box;
    }
    
    body {
        pointer-events: auto;
        position: relative;
    }
    
    /* Ensure all interactive elements are clickable */
    button, .btn, .btn-icon, input[type="button"], input[type="submit"], a {
        pointer-events: auto;
        z-index: auto;
        position: relative;
        cursor: pointer;
        user-select: none;
    }
    
    /* Specific fixes for action buttons */
    .action-buttons,
    .action-buttons * {
        pointer-events: auto !important;
        z-index: auto !important;
        position: relative !important;
    }
    
    /* Enhanced button styling for better clickability */
    .btn-icon {
        pointer-events: auto !important;
        z-index: 100 !important;
        position: relative !important;
        cursor: pointer !important;
        user-select: none !important;
        -webkit-user-select: none !important;
        -moz-user-select: none !important;
        -ms-user-select: none !important;
    }
    
    .btn-icon:hover {
        background: #f8fafc !important;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .btn-icon:active {
        transform: translateY(0);
    }
    
    /* Fix table container overflow issues */
    .table-container {
        position: relative;
        overflow: visible;
    }
    
    /* Ensure modal overlays don't interfere */
    .modal {
        pointer-events: none;
    }
    
    .modal.show {
        pointer-events: auto;
    }
    
    .modal-content {
        pointer-events: auto;
    }
    
    .system-settings-container {
        padding: 24px;
        max-width: 1400px;
        margin: 0 auto;
        pointer-events: auto;
    }
    
    .section-header {
        margin-bottom: 32px;
    }
    
    .section-title {
        font-size: 28px;
        font-weight: 800;
        color: var(--petron-blue);
        margin-bottom: 8px;
    }
    
    .section-subtitle {
        color: var(--muted);
        font-size: 14px;
    }
    
    .filter-card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: var(--shadow);
    }
    
    .filter-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 16px;
    }
    
    .filter-actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }
    
    .table-card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 16px;
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    
    .table-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--line);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .table-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--text);
    }
    
    .table-container {
        max-height: 500px;
        overflow-y: auto;
    }
    
    .settings-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    
    .settings-table thead th {
        background: #f8fafc;
        padding: 12px 16px;
        text-align: left;
        font-weight: 600;
        color: var(--muted);
        font-size: 12px;
        border-bottom: 1px solid var(--line);
        position: sticky;
        top: 0;
        z-index: 10;
    }
    
    .settings-table tbody td {
        padding: 16px;
        border-bottom: 1px solid var(--line);
        font-size: 14px;
    }
    
    .settings-table tbody tr:hover {
        background: #f8fafc;
    }
    
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        border: 1px solid;
    }
    
    .badge-repair {
        background: rgba(59, 130, 246, 0.1);
        color: #2563eb;
        border-color: rgba(59, 130, 246, 0.2);
    }
    
    .badge-installation {
        background: rgba(16, 185, 129, 0.1);
        color: #059669;
        border-color: rgba(16, 185, 129, 0.2);
    }
    
    .badge-diagnostics {
        background: rgba(245, 158, 11, 0.1);
        color: #d97706;
        border-color: rgba(245, 158, 11, 0.2);
    }
    
    .badge-gasoline {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
        border-color: rgba(239, 68, 68, 0.2);
    }
    
    .badge-diesel {
        background: rgba(107, 114, 128, 0.1);
        color: #374151;
        border-color: rgba(107, 114, 128, 0.2);
    }
    
    .badge-lpg {
        background: rgba(168, 85, 247, 0.1);
        color: #9333ea;
        border-color: rgba(168, 85, 247, 0.2);
    }
    
    .badge-active {
        background: rgba(34, 197, 94, 0.1);
        color: #16a34a;
        border-color: rgba(34, 197, 94, 0.2);
    }
    
    .badge-inactive {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
        border-color: rgba(239, 68, 68, 0.2);
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .btn-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        pointer-events: auto;
        position: relative;
        user-select: none;
    }
    
    .btn-icon:hover {
        background: #f8fafc;
    }
    
    .btn-icon.edit {
        color: var(--petron-blue);
        border-color: rgba(0, 47, 108, 0.2);
    }
    
    .btn-icon.delete {
        color: #dc2626;
        border-color: rgba(220, 38, 38, 0.2);
    }
    
    .modal {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 20px;
    }
    
    .modal.show {
        display: flex !important;
    }
    
    .modal-content {
        background: var(--card);
        border-radius: 16px;
        width: 100%;
        max-width: 600px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
    
    .modal-header {
        padding: 24px 24px 16px;
        border-bottom: 1px solid var(--line);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--text);
    }
    
    .modal-close {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        background: #f8fafc;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--muted);
    }
    
    .modal-body {
        padding: 24px;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .form-label {
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
    }
    
    .form-input, .form-select {
        padding: 10px 14px;
        border: 1px solid var(--line);
        border-radius: 8px;
        font-size: 14px;
        background: #fff;
        outline: none;
        transition: border-color 0.2s;
    }
    
    .form-input:focus, .form-select:focus {
        border-color: var(--petron-blue);
        box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
    }
    
    .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--line);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }
    
    .btn {
        padding: 10px 16px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        pointer-events: auto;
        position: relative;
        user-select: none;
    }
    
    .btn-primary {
        background: var(--petron-blue);
        color: white;
        pointer-events: auto;
        position: relative;
        cursor: pointer;
    }
    
    .btn-primary:hover {
        background: #002455;
    }
    
    .btn-secondary {
        background: #f8fafc;
        color: var(--muted);
        border: 1px solid var(--line);
        pointer-events: auto;
        position: relative;
        cursor: pointer;
    }
    
    .btn-secondary:hover {
        background: #e2e8f0;
    }
    
    .btn-green {
        background: #16a34a;
        color: white;
        pointer-events: auto;
        position: relative;
        cursor: pointer;
    }
    
    .btn-green:hover {
        background: #15803d;
    }
    
    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        background: white;
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 16px;
        box-shadow: var(--shadow);
        display: none;
        align-items: center;
        gap: 12px;
        z-index: 2000;
        }
    
    .toast.show {
        display: flex;
    }
    
    .toast.success {
        border-left: 4px solid #16a34a;
    }
    
    .toast.error {
        border-left: 4px solid #dc2626;
    }
    
    .toast-icon {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        color: white;
        flex-shrink: 0;
    }
    
    .toast.success .toast-icon {
        background: #16a34a;
    }
    
    .toast.error .toast-icon {
        background: #dc2626;
    }
    
    .toast-message {
        flex: 1;
        font-size: 14px;
        color: var(--text);
    }
    
    .text-right {
        text-align: right;
    }
    
    .empty-state {
        padding: 60px 20px;
        text-align: center;
        color: var(--muted);
    }
    
    .empty-icon {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.3;
    }
    
    .empty-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 8px;
    }
    
    .empty-icon {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.3;
        color: var(--muted);
    }
    
    .empty-icon i {
        font-size: 48px;
        margin: 0;
        padding: 0;
    }
    
    .section-title {
        font-size: 28px;
        font-weight: 800;
        color: var(--petron-blue);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .section-title i {
        font-size: 24px;
        margin: 0;
        padding: 0;
    }
    
    .settings-nav {
        background: white;
        border-radius: 12px;
        padding: 8px;
        margin-bottom: 32px;
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        box-shadow: var(--shadow);
    }
    
    .settings-nav-item {
        padding: 12px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        color: var(--muted);
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        border: 1px solid transparent;
    }
    
    .settings-nav-item:hover {
        background: #f8fafc;
        color: var(--text);
    }
    
    .settings-nav-item.active {
        background: var(--petron-blue);
        color: white;
        border-color: var(--petron-blue);
    }
    
    .settings-nav-item i {
        font-size: 16px;
    }
</style>

<div class="system-settings-container">
    <!-- Settings Navigation -->
    <nav class="settings-nav">
        <a href="?section=service_rates" class="settings-nav-item <?php echo ($section === 'service_rates' || empty($_GET['section'])) ? 'active' : ''; ?>">
            <i class="fas fa-tools"></i> Service Rates
        </a>
        <a href="?section=calibration" class="settings-nav-item <?php echo $section === 'calibration' ? 'active' : ''; ?>">
            <i class="fas fa-gas-pump"></i> Fuel Calibration
        </a>
        <a href="?section=suppliers" class="settings-nav-item <?php echo $section === 'suppliers' ? 'active' : ''; ?>">
            <i class="fas fa-truck"></i> Suppliers
        </a>
    </nav>
    <?php if ($notice): ?>
        <div class="toast success show" id="noticeToast">
            <div class="toast-icon"><i class="fas fa-check"></i></div>
            <div class="toast-message"><?php echo htmlspecialchars($notice); ?></div>
        </div>
    <?php endif; ?>
    
    <?php if ($section === 'service_rates'): ?>
        <!-- Service Rate Masterlist Section -->
        <div class="section-header">
            <h1 class="section-title"><i class="fas fa-tools"></i> Service Rate Masterlist</h1>
            <p class="section-subtitle">Manage pricing for services (repairs, installations, diagnostics)</p>
        </div>
        
        <!-- Filter Card -->
        <div class="filter-card">
            <form method="get">
                <input type="hidden" name="section" value="service_rates">
                <div class="filter-grid">
                    <div class="form-group">
                        <label class="form-label">Service Type</label>
                        <select name="service_type" class="form-select">
                            <option value="">All Categories</option>
                            <option value="Repair" <?php echo ($_GET['service_type'] ?? '') === 'Repair' ? 'selected' : ''; ?>>Repair</option>
                            <option value="Installation" <?php echo ($_GET['service_type'] ?? '') === 'Installation' ? 'selected' : ''; ?>>Installation</option>
                            <option value="Diagnostics" <?php echo ($_GET['service_type'] ?? '') === 'Diagnostics' ? 'selected' : ''; ?>>Diagnostics</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="active" <?php echo ($_GET['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($_GET['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">Reset</button>
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                </div>
            </form>
        </div>
        
        <!-- Table Card -->
        <div class="table-card">
            <div class="table-header">
                <h2 class="table-title">Service Rates</h2>
                <button class="btn btn-primary" id="addServiceBtn" onclick="openServiceModal()">
                    <i class="fas fa-plus"></i> Add New Service
                </button>
            </div>
            <div class="table-container">
                <?php if (empty($services)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-tools"></i></div>
                        <div class="empty-title">No services found</div>
                        <div class="empty-description">Start by adding your first service rate</div>
                        <button class="btn btn-primary" id="addServiceBtn2" onclick="openServiceModal()">Add Service</button>
                    </div>
                <?php else: ?>
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>Service Name</th>
                                <th>Category</th>
                                <th>Rate (PHP)</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $service): ?>
                                <?php
                                // Apply filters
                                $service_type_filter = $_GET['service_type'] ?? '';
                                $status_filter = $_GET['status'] ?? '';
                                
                                $service_name_display = $service['rate_name'] ?? $service['service_name'] ?? '';
                                $service_category_display = $service['category'] ?? $service['category_name'] ?? '';
                                $service_rate_value = isset($service['flat_rate']) ? $service['flat_rate'] : ($service['rate'] ?? 0);
                                $service_status_display = (isset($service['is_active']) ? ($service['is_active'] ? 'active' : 'inactive') : ($service['status'] ?? 'inactive'));

                                if ($service_type_filter && $service_category_display !== $service_type_filter) continue;
                                if ($status_filter && $service_status_display !== $status_filter) continue;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($service_name_display); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($service['category']); ?>">
                                            <?php echo htmlspecialchars($service_category_display); ?>
                                        </span>
                                    </td>
                                    <td class="text-right"><?php echo number_format($service_rate_value, 2); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $service_status_display; ?>">
                                            <?php echo ucfirst($service_status_display); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y H:i', strtotime($service['updated_at'] ?? ($service['updated_at'] ?? null))); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon edit" onclick="editService(<?php echo $service['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon delete" onclick="deleteService(<?php echo $service['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
    <?php elseif ($section === 'calibration'): ?>
        <!-- Fuel Calibration Values Section -->
        <div class="section-header">
            <h1 class="section-title"><i class="fas fa-gas-pump"></i> Fuel Calibration Values</h1>
            <p class="section-subtitle">Set and adjust calibration constants for fuel dispensing and reconciliation</p>
        </div>
        
        <!-- Filter Card -->
        <div class="filter-card">
            <form method="get">
                <input type="hidden" name="section" value="calibration">
                <div class="filter-grid">
                    <div class="form-group">
                        <label class="form-label">Fuel Type</label>
                        <select name="fuel_type" id="fuel_type_settings_1" class="form-select">
                            <option value="">All Fuel Types</option>
                            <?php
                            $fuel_types_to_show = [];
                            
                            // First try to get fuel types from database
                            try {
                                $stmt = $pdo->query("SELECT DISTINCT fuel_type FROM fuel_calibration ORDER BY fuel_type");
                                $db_fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($db_fuel_types as $fuel_type) {
                                    $fuel_types_to_show[] = $fuel_type['fuel_type'];
                                }
                            } catch(Exception $e) {
                                // Ignore database errors
                            }
                            
                            // Always include standard fuel types
                            $standard_fuel_types = ['Gasoline', 'Diesel', 'LPG'];
                            foreach ($standard_fuel_types as $fuel_type) {
                                if (!in_array($fuel_type, $fuel_types_to_show)) {
                                    $fuel_types_to_show[] = $fuel_type;
                                }
                            }
                            
                            // Sort and display all fuel types
                            sort($fuel_types_to_show);
                            foreach ($fuel_types_to_show as $fuel_type):
                            ?>
                                <option value="<?php echo htmlspecialchars($fuel_type); ?>" <?php echo (($_GET['fuel_type'] ?? '') === $fuel_type) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($fuel_type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Station / Branch</label>
                        <select name="station" class="form-select">
                            <option value="">All Stations</option>
                            <?php foreach ($stations_list as $station): ?>
                                <option value="<?php echo $station['id']; ?>" <?php echo (($_GET['station'] ?? '') == $station['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($station['name'] . ($station['location'] ? ' - ' . $station['location'] : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">Reset</button>
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                </div>
            </form>
        </div>
        
        <!-- Table Card -->
        <div class="table-card">
            <div class="table-header">
                <h2 class="table-title">Fuel Calibration Values</h2>
                <button class="btn btn-primary" onclick="openCalibrationModal()">
                    <i class="fas fa-plus"></i> Add Calibration
                </button>
            </div>
            <div class="table-container">
                <?php if (empty($calibrations)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-gas-pump"></i></div>
                        <div class="empty-title">No calibration values found</div>
                        <div class="empty-description">Add your first fuel calibration value</div>
                        <button class="btn btn-primary" onclick="openCalibrationModal()">Add Calibration</button>
                    </div>
                <?php else: ?>
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>Fuel Type</th>
                                <th>Calibration Constant</th>
                                <th>Effective Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calibrations as $calibration): ?>
                                <?php
                                // Apply filters
                                $fuel_type_filter = $_GET['fuel_type'] ?? '';
                                
                                if ($fuel_type_filter && $calibration['fuel_type'] !== $fuel_type_filter) continue;
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($calibration['fuel_type']); ?>">
                                            <?php echo htmlspecialchars($calibration['fuel_type']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right"><?php echo number_format($calibration['calibration_constant'], 6); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($calibration['effective_date'])); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $calibration['status']; ?>">
                                            <?php echo ucfirst($calibration['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon edit" onclick="editCalibration(<?php echo $calibration['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($section === 'suppliers'): ?>
        <!-- Supplier Management Section -->
        <div class="section-header">
            <h1 class="section-title"><i class="fas fa-truck"></i> Supplier Management</h1>
            <p class="section-subtitle">View merchandise suppliers and configure default supplier</p>
        </div>
        
        <div class="table-card">
            <div class="table-header">
                <h2 class="table-title">Suppliers</h2>
                <!-- Add Supplier is disabled as Petron Corporation is the sole supplier -->
            </div>
            <div class="table-container">
                <?php if (empty($suppliers_list)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-truck"></i></div>
                        <div class="empty-title">No suppliers found</div>
                        <div class="empty-description">Petron Corporation is the sole supplier of the system.</div>
                    </div>
                <?php else: ?>
                    <table class="settings-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contact Person</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Default</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suppliers_list as $supplier): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                        <?php if ($supplier['id'] == $default_supplier_id): ?>
                                            <span class="badge badge-active">Default</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($supplier['contact_person'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['phone'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['email'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($supplier['id'] == $default_supplier_id): ?>
                                            <i class="fas fa-star" style="color: #f59e0b;"></i>
                                        <?php else: ?>
                                            <button class="btn-icon" id="setDefault_<?php echo $supplier['id']; ?>" onclick="setDefaultSupplier(<?php echo $supplier['id']; ?>)" title="Set as Default" type="button">
                                                <i class="far fa-star"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Service Modal -->
<div class="modal" id="serviceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="serviceModalTitle">Add Service</h3>
            <button class="modal-close" onclick="closeServiceModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="serviceForm">
            <input type="hidden" name="action" value="save_service">
            <input type="hidden" name="service_id" id="service_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Service Name</label>
                        <input type="text" name="service_name" id="service_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" id="service_category" class="form-select" required>
                            <option value="">Select Category</option>
                            <option value="Repair">Repair</option>
                            <option value="Installation">Installation</option>
                            <option value="Diagnostics">Diagnostics</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rate (PHP)</label>
                        <input type="number" name="rate" id="service_rate" class="form-input" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="service_status" class="form-select" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeServiceModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Calibration Modal -->
<div class="modal" id="calibrationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="calibrationModalTitle">Add Calibration Value</h3>
            <button class="modal-close" onclick="closeCalibrationModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="calibrationForm">
            <input type="hidden" name="action" value="save_calibration">
            <input type="hidden" name="calibration_id" id="calibration_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Fuel Type</label>
                        <select name="fuel_type" id="fuel_type_settings_2" class="form-select" required>
                            <option value="">Select Fuel Type</option>
                            <?php
                            $modal_fuel_types_to_show = [];
                            
                            // First try to get fuel types from database
                            try {
                                $stmt = $pdo->query("SELECT DISTINCT fuel_type FROM fuel_calibration ORDER BY fuel_type");
                                $db_fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($db_fuel_types as $fuel_type) {
                                    $modal_fuel_types_to_show[] = $fuel_type['fuel_type'];
                                }
                            } catch(Exception $e) {
                                // Ignore database errors
                            }
                            
                            // Always include standard fuel types
                            $standard_fuel_types = ['Gasoline', 'Diesel', 'LPG'];
                            foreach ($standard_fuel_types as $fuel_type) {
                                if (!in_array($fuel_type, $modal_fuel_types_to_show)) {
                                    $modal_fuel_types_to_show[] = $fuel_type;
                                }
                            }
                            
                            // Sort and display all fuel types
                            sort($modal_fuel_types_to_show);
                            foreach ($modal_fuel_types_to_show as $fuel_type):
                            ?>
                                <option value="<?php echo htmlspecialchars($fuel_type); ?>">
                                    <?php echo htmlspecialchars($fuel_type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Station / Branch</label>
                        <select name="station_id" id="calibration_station" class="form-select">
                            <option value="">All Stations</option>
                            <?php foreach ($stations_list as $station): ?>
                                <option value="<?php echo $station['id']; ?>">
                                    <?php echo htmlspecialchars($station['name'] . ($station['location'] ? ' - ' . $station['location'] : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Effective Date</label>
                        <input type="date" name="effective_date" id="effective_date" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Calibration Constant</label>
                        <input type="number" name="calibration_constant" id="calibration_constant" class="form-input" step="0.000001" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="calibration_status" class="form-select" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCalibrationModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Confirm Delete</h3>
            <button class="modal-close" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this service? This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <form method="post" id="deleteForm" style="display: inline;">
                <input type="hidden" name="action" value="delete_service">
                <input type="hidden" name="service_id" id="delete_service_id">
                <button type="submit" class="btn btn-primary" style="background: #dc2626;">Delete</button>
            </form>
        </div>
    </div>
</div>

<!-- Supplier Modal -->
<div class="modal" id="supplierModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="supplierModalTitle">Add Supplier</h3>
            <button class="modal-close" onclick="closeSupplierModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="supplierForm">
            <input type="hidden" name="action" value="save_supplier">
            <input type="hidden" name="supplier_id" id="supplier_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Supplier Name *</label>
                        <input type="text" name="name" id="supplier_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" id="supplier_contact" class="form-input">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="supplier_phone" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="supplier_email" class="form-input">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" id="supplier_address" class="form-input" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="is_default" id="supplier_default" value="1">
                        Set as Default Supplier
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeSupplierModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Supplier</button>
            </div>
        </form>
    </div>
</div>

<script>
console.log('=== SUPPLIER FUNCTIONS LOADING ===');

// IMMEDIATE SUPPLIER FUNCTIONS - Load right after modal HTML
window.openSupplierModal = function() {
    console.log('openSupplierModal called');
    try {
        const modal = document.getElementById('supplierModal');
        if (modal) {
            modal.style.display = 'flex';
            modal.classList.add('show');
            document.getElementById('supplierModalTitle').textContent = 'Add Supplier';
            const form = document.getElementById('supplierForm');
            if (form) form.reset();
            document.getElementById('supplier_id').value = '';
            console.log('Supplier modal opened successfully');
        } else {
            console.error('Supplier modal not found');
            alert('Error: Supplier modal not found');
        }
    } catch (error) {
        console.error('Error opening supplier modal:', error);
        alert('Error opening supplier modal: ' + error.message);
    }
};

window.closeSupplierModal = function() {
    console.log('closeSupplierModal called');
    try {
        const modal = document.getElementById('supplierModal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
            console.log('Supplier modal closed successfully');
        }
    } catch (error) {
        console.error('Error closing supplier modal:', error);
    }
};

window.editSupplier = function(id) {
    console.log('editSupplier called with id:', id);
    try {
        // First open the modal
        const modal = document.getElementById('supplierModal');
        if (modal) {
            modal.style.display = 'flex';
            modal.classList.add('show');
            document.getElementById('supplierModalTitle').textContent = 'Edit Supplier';
            document.getElementById('supplier_id').value = id;
            
            // Load supplier data for editing
            <?php foreach ($suppliers_list as $supplier): ?>
            if (id == <?php echo $supplier['id']; ?>) {
                document.getElementById('supplier_name').value = '<?php echo htmlspecialchars($supplier['name']); ?>';
                document.getElementById('supplier_contact').value = '<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>';
                document.getElementById('supplier_phone').value = '<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>';
                document.getElementById('supplier_email').value = '<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>';
                document.getElementById('supplier_address').value = '<?php echo htmlspecialchars($supplier['address'] ?? ''); ?>';
                console.log('Loaded data for supplier: <?php echo htmlspecialchars($supplier['name']); ?>');
            }
            <?php endforeach; ?>
            
            console.log('Supplier modal opened for editing');
        } else {
            console.error('Supplier modal not found');
            alert('Error: Supplier modal not found');
        }
    } catch (error) {
        console.error('Error in editSupplier:', error);
        alert('Error editing supplier: ' + error.message);
    }
};

window.deleteSupplier = function(id) {
    console.log('deleteSupplier called with id:', id);
    if (confirm('Are you sure you want to delete this supplier? This action cannot be undone.')) {
        try {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'settings.php?section=suppliers';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_supplier';
            
            const supplierIdInput = document.createElement('input');
            supplierIdInput.type = 'hidden';
            supplierIdInput.name = 'supplier_id';
            supplierIdInput.value = id;
            
            form.appendChild(actionInput);
            form.appendChild(supplierIdInput);
            document.body.appendChild(form);
            form.submit();
        } catch (error) {
            console.error('Error deleting supplier:', error);
            alert('Error deleting supplier: ' + error.message);
        }
    }
};

window.setDefaultSupplier = function(id) {
    console.log('setDefaultSupplier called with id:', id);
    if (confirm('Set this supplier as the default for receiving?')) {
        try {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'settings.php?section=suppliers';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'set_default_supplier';
            
            const supplierIdInput = document.createElement('input');
            supplierIdInput.type = 'hidden';
            supplierIdInput.name = 'supplier_id';
            supplierIdInput.value = id;
            
            form.appendChild(actionInput);
            form.appendChild(supplierIdInput);
            document.body.appendChild(form);
            form.submit();
        } catch (error) {
            console.error('Error setting default supplier:', error);
            alert('Error setting default supplier: ' + error.message);
        }
    }
};

console.log('=== SUPPLIER FUNCTIONS LOADED ===');
console.log('editSupplier function:', typeof window.editSupplier);

console.log('=== JAVASCRIPT STARTING ===');

// Simple Direct Button Functions
console.log('Loading simple button functions...');

// Direct function assignments
window.openServiceModal = function() {
    console.log('openServiceModal called');
    try {
        const modal = document.getElementById('serviceModal');
        if (modal) {
            modal.style.display = 'flex';
            modal.classList.add('show');
            document.getElementById('serviceModalTitle').textContent = 'Add Service';
            const form = document.getElementById('serviceForm');
            if (form) form.reset();
            document.getElementById('service_id').value = '';
            console.log('Modal opened successfully');
        } else {
            console.error('Modal not found');
        }
    } catch (error) {
        console.error('Error opening modal:', error);
    }
};

window.closeServiceModal = function() {
    console.log('closeServiceModal called');
    try {
        const modal = document.getElementById('serviceModal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
            console.log('Modal closed successfully');
        }
    } catch (error) {
        console.error('Error closing modal:', error);
    }
};

window.editService = function(id) {
    console.log('editService called with id:', id);
    try {
        // Simple approach - just open modal for editing
        openServiceModal();
        document.getElementById('serviceModalTitle').textContent = 'Edit Service';
        document.getElementById('service_id').value = id;
        console.log('Service modal opened for editing');
    } catch (error) {
        console.error('Error in editService:', error);
    }
};

window.deleteService = function(id) {
    console.log('deleteService called with id:', id);
    if (confirm('Are you sure you want to delete this service?')) {
        try {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'settings.php';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_service';
            
            const serviceIdInput = document.createElement('input');
            serviceIdInput.type = 'hidden';
            serviceIdInput.name = 'service_id';
            serviceIdInput.value = id;
            
            form.appendChild(actionInput);
            form.appendChild(serviceIdInput);
            document.body.appendChild(form);
            form.submit();
        } catch (error) {
            console.error('Error deleting service:', error);
        }
    }
};

window.resetFilters = function() {
    console.log('resetFilters called');
    const currentSection = '<?php echo $section; ?>';
    window.location.href = window.location.pathname + '?section=' + currentSection;
};

// Test functions on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded - testing button functions...');
    
    // Test if functions exist
    console.log('openServiceModal exists:', typeof window.openServiceModal);
    console.log('editService exists:', typeof window.editService);
    console.log('deleteService exists:', typeof window.deleteService);
    console.log('openSupplierModal exists:', typeof window.openSupplierModal);
    console.log('editSupplier exists:', typeof window.editSupplier);
    console.log('deleteSupplier exists:', typeof window.deleteSupplier);
    console.log('setDefaultSupplier exists:', typeof window.setDefaultSupplier);
    
    // Force modals to be hidden initially
    const serviceModal = document.getElementById('serviceModal');
    const supplierModal = document.getElementById('supplierModal');
    if (serviceModal) {
        serviceModal.style.display = 'none';
        console.log('Service modal hidden initially');
    }
    if (supplierModal) {
        supplierModal.style.display = 'none';
        console.log('Supplier modal hidden initially');
    }
    
    // Test supplier buttons specifically
    const supplierButtons = document.querySelectorAll('button[onclick*="Supplier"]');
    console.log('Found supplier buttons:', supplierButtons.length);
    supplierButtons.forEach((btn, index) => {
        console.log(`Supplier Button ${index}:`, btn.textContent.trim(), btn.getAttribute('onclick'));
    });
    
    // Add click event listeners as backup - Enhanced for supplier section
    document.querySelectorAll('button[onclick*="openSupplierModal"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Backup click handler for Add Supplier button');
            if (typeof openSupplierModal === 'function') {
                openSupplierModal();
            } else {
                console.error('openSupplierModal function not found');
                alert('Error: Add Supplier function not available');
            }
        });
    });
    
    document.querySelectorAll('button[onclick*="editSupplier"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const id = btn.getAttribute('onclick').match(/editSupplier\((\d+)\)/)?.[1];
            if (id && typeof editSupplier === 'function') {
                console.log('Backup click handler for edit supplier button, ID:', id);
                editSupplier(parseInt(id));
            } else {
                console.error('editSupplier function not found or invalid ID');
                alert('Error: Edit function not available');
            }
        });
    });
    
    document.querySelectorAll('button[onclick*="deleteSupplier"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const id = btn.getAttribute('onclick').match(/deleteSupplier\((\d+)\)/)?.[1];
            if (id && typeof deleteSupplier === 'function') {
                console.log('Backup click handler for delete supplier button, ID:', id);
                deleteSupplier(parseInt(id));
            } else {
                console.error('deleteSupplier function not found or invalid ID');
                alert('Error: Delete function not available');
            }
        });
    });
    
    document.querySelectorAll('button[onclick*="setDefaultSupplier"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const id = btn.getAttribute('onclick').match(/setDefaultSupplier\((\d+)\)/)?.[1];
            if (id && typeof setDefaultSupplier === 'function') {
                console.log('Backup click handler for set default supplier button, ID:', id);
                setDefaultSupplier(parseInt(id));
            } else {
                console.error('setDefaultSupplier function not found or invalid ID');
                alert('Error: Set Default function not available');
            }
        });
    });
    
    // Specific supplier section initialization
    if (window.location.search.includes('section=suppliers')) {
        console.log('Supplier section detected - initializing buttons...');
        
        // Force add supplier button to work
        const addBtn = document.getElementById('addSupplierBtn');
        if (addBtn) {
            addBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Add Supplier button clicked directly');
                openSupplierModal();
            });
        }
        
        // Force individual supplier buttons to work
        <?php foreach ($suppliers_list as $supplier): ?>
        const editBtn<?php echo $supplier['id']; ?> = document.getElementById('edit_<?php echo $supplier['id']; ?>');
        if (editBtn<?php echo $supplier['id']; ?>) {
            editBtn<?php echo $supplier['id']; ?>.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Edit button clicked for supplier <?php echo $supplier['id']; ?>');
                editSupplier(<?php echo $supplier['id']; ?>);
            });
        }
        
        const deleteBtn<?php echo $supplier['id']; ?> = document.getElementById('delete_<?php echo $supplier['id']; ?>');
        if (deleteBtn<?php echo $supplier['id']; ?>) {
            deleteBtn<?php echo $supplier['id']; ?>.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Delete button clicked for supplier <?php echo $supplier['id']; ?>');
                deleteSupplier(<?php echo $supplier['id']; ?>);
            });
        }
        
        const setDefaultBtn<?php echo $supplier['id']; ?> = document.getElementById('setDefault_<?php echo $supplier['id']; ?>');
        if (setDefaultBtn<?php echo $supplier['id']; ?>) {
            setDefaultBtn<?php echo $supplier['id']; ?>.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Set Default button clicked for supplier <?php echo $supplier['id']; ?>');
                setDefaultSupplier(<?php echo $supplier['id']; ?>);
            });
        }
        <?php endforeach; ?>
    }
});

// Also try immediately without waiting for DOM
console.log('Immediate function test - openServiceModal:', typeof window.openServiceModal);
</script>

<script>
// Calibration Modal Functions
window.openCalibrationModal = function() {
    console.log('openCalibrationModal called');
    try {
        const modal = document.getElementById('calibrationModal');
        if (modal) {
            modal.style.display = 'flex';
            modal.classList.add('show');
            document.getElementById('calibrationModalTitle').textContent = 'Add Calibration Value';
            const form = document.getElementById('calibrationForm');
            if (form) form.reset();
            document.getElementById('calibration_id').value = '';
            document.getElementById('effective_date').value = new Date().toISOString().split('T')[0];
            console.log('Calibration modal opened successfully');
        } else {
            console.error('Calibration modal not found');
        }
    } catch (error) {
        console.error('Error opening calibration modal:', error);
    }
};

window.closeCalibrationModal = function() {
    try {
        const modal = document.getElementById('calibrationModal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
        }
    } catch (error) {
        console.error('Error closing calibration modal:', error);
    }
};

window.editCalibration = function(id) {
    console.log('editCalibration called with id:', id);
    try {
        openCalibrationModal();
        document.getElementById('calibrationModalTitle').textContent = 'Edit Calibration Value';
        document.getElementById('calibration_id').value = id;
        console.log('Calibration modal opened for editing');
    } catch (error) {
        console.error('Error in editCalibration:', error);
    }
};

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
});
</script>

<script>
// Supplier Modal Functions - Enhanced with better error handling
console.log('Loading supplier functions...');

window.openSupplierModal = function() {
    console.log('openSupplierModal called');
    try {
        const modal = document.getElementById('supplierModal');
        if (modal) {
            modal.style.display = 'flex';
            modal.classList.add('show');
            document.getElementById('supplierModalTitle').textContent = 'Add Supplier';
            const form = document.getElementById('supplierForm');
            if (form) form.reset();
            document.getElementById('supplier_id').value = '';
            console.log('Supplier modal opened successfully');
        } else {
            console.error('Supplier modal not found');
            alert('Error: Supplier modal not found');
        }
    } catch (error) {
        console.error('Error opening supplier modal:', error);
        alert('Error opening supplier modal: ' + error.message);
    }
};

window.closeSupplierModal = function() {
    console.log('closeSupplierModal called');
    try {
        const modal = document.getElementById('supplierModal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
            console.log('Supplier modal closed successfully');
        }
    } catch (error) {
        console.error('Error closing supplier modal:', error);
    }
};

window.editSupplier = function(id) {
    console.log('editSupplier called with id:', id);
    try {
        openSupplierModal();
        document.getElementById('supplierModalTitle').textContent = 'Edit Supplier';
        document.getElementById('supplier_id').value = id;
        
        // Load supplier data for editing
        <?php foreach ($suppliers_list as $supplier): ?>
        if (id == <?php echo $supplier['id']; ?>) {
            document.getElementById('supplier_name').value = '<?php echo htmlspecialchars($supplier['name']); ?>';
            document.getElementById('supplier_contact').value = '<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>';
            document.getElementById('supplier_phone').value = '<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>';
            document.getElementById('supplier_email').value = '<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>';
            document.getElementById('supplier_address').value = '<?php echo htmlspecialchars($supplier['address'] ?? ''); ?>';
        }
        <?php endforeach; ?>
        
        console.log('Supplier modal opened for editing');
    } catch (error) {
        console.error('Error in editSupplier:', error);
        alert('Error editing supplier: ' + error.message);
    }
};

window.deleteSupplier = function(id) {
    console.log('deleteSupplier called with id:', id);
    if (confirm('Are you sure you want to delete this supplier? This action cannot be undone.')) {
        try {
            // Create form submission for better reliability
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'settings.php?section=suppliers';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_supplier';
            
            const supplierIdInput = document.createElement('input');
            supplierIdInput.type = 'hidden';
            supplierIdInput.name = 'supplier_id';
            supplierIdInput.value = id;
            
            form.appendChild(actionInput);
            form.appendChild(supplierIdInput);
            document.body.appendChild(form);
            form.submit();
        } catch (error) {
            console.error('Error deleting supplier:', error);
            alert('Error deleting supplier: ' + error.message);
        }
    }
};

window.setDefaultSupplier = function(id) {
    console.log('setDefaultSupplier called with id:', id);
    if (confirm('Set this supplier as the default for receiving?')) {
        try {
            // Create form submission for better reliability
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'settings.php?section=suppliers';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'set_default_supplier';
            
            const supplierIdInput = document.createElement('input');
            supplierIdInput.type = 'hidden';
            supplierIdInput.name = 'supplier_id';
            supplierIdInput.value = id;
            
            form.appendChild(actionInput);
            form.appendChild(supplierIdInput);
            document.body.appendChild(form);
            form.submit();
        } catch (error) {
            console.error('Error setting default supplier:', error);
            alert('Error setting default supplier: ' + error.message);
        }
    }
};

// Utility Functions
window.showToast = function(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast ${type} show`;
    toast.innerHTML = `
        <div class="toast-icon">${type === 'success' ? '✓' : '⚠'}</div>
        <div class="toast-message">${message}</div>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
};

// Auto-hide notice toast and redirect for professional experience
<?php if ($notice): ?>
setTimeout(function() {
    const noticeToast = document.getElementById('noticeToast');
    if (noticeToast) {
        noticeToast.remove();
    }
    
    // Redirect to suppliers section after successful operation for professional UX
    <?php if (strpos($notice, '✅') !== false): ?>
    // Only redirect on success messages (checkmark emoji)
    setTimeout(function() {
        window.location.href = 'settings.php?section=suppliers';
    }, 500);
    <?php endif; ?>
}, 2500);
<?php endif; ?>
</script>

<script src="../assets/js/data_helper.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    DataHelper.populateFuelTypes('fuel_type_settings_1', 'All Fuel Types');
    DataHelper.populateFuelTypes('fuel_type_settings_2', 'Select Fuel Type');
    
    // Final test - ensure supplier functions are available
    console.log('=== FINAL SUPPLIER FUNCTION TEST ===');
    console.log('openSupplierModal:', typeof window.openSupplierModal);
    console.log('editSupplier:', typeof window.editSupplier);
    console.log('deleteSupplier:', typeof window.deleteSupplier);
    console.log('setDefaultSupplier:', typeof window.setDefaultSupplier);
    
    // Test supplier modal existence
    const supplierModal = document.getElementById('supplierModal');
    console.log('Supplier modal exists:', !!supplierModal);
    if (supplierModal) {
        console.log('Supplier modal display:', supplierModal.style.display);
        console.log('Supplier modal classes:', supplierModal.className);
    }
    
    // Add a simple test function that can be called from console
    window.testSupplierFunctions = function() {
        console.log('Testing supplier functions...');
        try {
            console.log('openSupplierModal function:', window.openSupplierModal);
            console.log('Add Supplier button:', document.querySelector('button[onclick*="openSupplierModal"]'));
            console.log('Edit buttons:', document.querySelectorAll('button[onclick*="editSupplier"]').length);
            console.log('Delete buttons:', document.querySelectorAll('button[onclick*="deleteSupplier"]').length);
            return 'Test completed - check console for details';
        } catch (error) {
            return 'Error: ' + error.message;
        }
    };
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
