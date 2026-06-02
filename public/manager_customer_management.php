<?php
/**
 * Manager Customer Management
 * Three-section module: Customer List, Customer Balances, Customer History
 * Station-scoped, manager-only access
 */

// Bootstrap
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

// Section routing
$valid_sections = ['records', 'balances', 'history'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid_sections)
    ? $_GET['section'] : 'records';

// Page ID for sidebar highlighting
$page_id = match($section) {
    'balances' => 'mgr_cust_balances',
    'history'  => 'mgr_cust_history',
    default    => 'mgr_cust_list',
};

// User and station context
$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Role gate: Manager, Admin, or SuperAdmin only
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

// No-station guard
if (!$station_id) {
    render_no_station_page('dashboard.php');
}

// Ensure required customer table columns exist
try {
    $cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    $required_columns = [
        'contact_number'   => "VARCHAR(50) NULL",
        'id_number'        => "VARCHAR(100) NULL",
        'credit_limit'     => "DECIMAL(12,2) DEFAULT 0.00",
        'current_balance'  => "DECIMAL(12,2) DEFAULT 0.00",
    ];
    foreach ($required_columns as $col => $def) {
        if (!in_array($col, $cols)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN $col $def");
        }
    }
} catch (Exception $e) {
    // Silently continue if ALTER fails
}

// POST handler for AJAX payment validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    $is_ajax = (strpos($content_type, 'application/json') !== false) || 
               (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    
    if ($is_ajax) {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        if ($action === 'validate_payment') {
            $customer_id = (int)($input['customer_id'] ?? 0);
            $amount = (float)($input['amount'] ?? 0);
            $reference = trim($input['reference'] ?? '');
            
            // Validate inputs
            if ($amount <= 0) {
                echo json_encode(['success' => false, 'error' => 'Payment amount must be greater than 0.']);
                exit;
            }
            
            if (strlen($reference) < 3) {
                echo json_encode(['success' => false, 'error' => 'Reference must be at least 3 characters.']);
                exit;
            }
            
            try {
                // Fetch customer
                $cust_stmt = $pdo->prepare("
                    SELECT id, name, COALESCE(credit_limit, 0) AS credit_limit, 
                           COALESCE(current_balance, balance, 0) AS outstanding 
                    FROM customers 
                    WHERE id = :id AND station_id = :station_id
                ");
                $cust_stmt->execute([':id' => $customer_id, ':station_id' => $station_id]);
                $customer = $cust_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$customer) {
                    echo json_encode(['success' => false, 'error' => 'Customer not found.']);
                    exit;
                }
                
                $outstanding = (float)$customer['outstanding'];
                $credit_limit = (float)$customer['credit_limit'];
                
                // Check for overpayment
                if ($amount > $outstanding) {
                    $excess = $amount - $outstanding;
                    echo json_encode([
                        'success' => false, 
                        'overpayment' => true, 
                        'excess' => $excess
                    ]);
                    exit;
                }
                
                // Begin transaction
                $pdo->beginTransaction();
                
                // Update balance
                $new_balance = $outstanding - $amount;
                $upd_stmt = $pdo->prepare("
                    UPDATE customers 
                    SET current_balance = :new_balance, balance = :new_balance 
                    WHERE id = :id AND station_id = :station_id
                ");
                $upd_stmt->execute([
                    ':new_balance' => $new_balance,
                    ':id' => $customer_id,
                    ':station_id' => $station_id
                ]);
                
                // Calculate new utilization
                $new_utilization = 0;
                if ($credit_limit > 0) {
                    $new_utilization = round(($new_balance / $credit_limit) * 100, 1);
                }
                
                // Write audit log
                write_audit_log(
                    $pdo,
                    'Payment Validated',
                    "Payment of ₱" . number_format($amount, 2) . " recorded for customer: {$customer['name']}. Reference: {$reference}. New balance: ₱" . number_format($new_balance, 2),
                    'customers',
                    $customer_id,
                    'transaction'
                );
                
                // Commit transaction
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'new_balance' => $new_balance,
                    'new_utilization' => $new_utilization
                ]);
                exit;
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo json_encode(['success' => false, 'error' => 'Database error. No changes were made.']);
                exit;
            }
        }
    }
}

// Flash messages
$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// Include header
include __DIR__ . '/../partials/header.php';
?>

<!-- Page-specific CSS -->
<link rel="stylesheet" href="../assets/css/manager_customer_management.css">

<style>
/* === CSS Variables === */
:root {
    --primary-color: #002F6C;
    --primary-hover: #00264D;
    --success-color: #28a745;
    --danger-color: #dc3545;
    --warning-color: #ffc107;
    --info-color: #17a2b8;
    --border-color: #e9ecef;
    --gray-100: #f8f9fa;
    --gray-600: #6c757d;
}

/* === Global Overflow Prevention === */
body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
}
.content-area, .main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
}

/* === Flash Messages === */
.flash-ok {
    background: #d1fae5;
    border: 1px solid #6ee7b7;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
    color: #065f46;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.flash-err {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
    color: #991b1b;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* === Page Header === */
.page-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}
.page-head h1 {
    font-size: 28px;
    font-weight: 700;
    color: var(--primary-color);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.page-head .sub {
    font-size: 14px;
    color: var(--gray-600);
    margin-top: 4px;
}
.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* === Tab Navigation === */
.tab-nav {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 2px solid var(--border-color);
    flex-wrap: wrap;
}
.tab-btn {
    padding: 12px 20px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: var(--gray-600);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: -2px;
}
.tab-btn:hover {
    color: var(--primary-color);
    background: rgba(0, 47, 108, 0.05);
}
.tab-btn.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
    background: rgba(0, 47, 108, 0.05);
}

/* === Cards === */
.dv-card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}
.dv-card-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.dv-card-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--primary-color);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.dv-card-body {
    padding: 20px;
}

/* === Tables === Clean Design with Blue Headers === */
body #customer-management-module .po-table-wrap,
#customer-management-module .po-table-wrap { 
    background: #fff !important; 
    border-radius: 8px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
    overflow: hidden !important;
    border: 1px solid #e9ecef !important;
}

body #customer-management-module .data-table,
#customer-management-module .data-table { 
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 0.875rem !important;
    margin: 0 !important;
}

body #customer-management-module .data-table th, 
body #customer-management-module .data-table td,
#customer-management-module .data-table th, 
#customer-management-module .data-table td { 
    padding: 12px 16px !important;
    text-align: left !important;
    border-bottom: 1px solid #e9ecef !important;
    vertical-align: middle !important;
}

body #customer-management-module .data-table thead th,
body #customer-management-module .data-table th,
#customer-management-module .data-table thead th,
#customer-management-module .data-table th { 
    background: #002F70 !important;
    color: #fff !important;
    font-weight: 600 !important;
    font-size: 0.813rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    padding: 14px 16px !important;
    white-space: nowrap !important;
    border: none !important;
}

body #customer-management-module .data-table tbody tr,
#customer-management-module .data-table tbody tr { 
    transition: all 0.2s ease !important;
    background: #fff !important;
    border-bottom: 1px solid #e9ecef !important;
}

body #customer-management-module .data-table tbody tr:hover,
#customer-management-module .data-table tbody tr:hover { 
    background: #e3f2fd !important;
}

body #customer-management-module .data-table tbody tr:last-child,
#customer-management-module .data-table tbody tr:last-child {
    border-bottom: none !important;
}

body #customer-management-module .data-table tbody td,
#customer-management-module .data-table tbody td { 
    color: #212529 !important;
    background: transparent !important;
    font-size: 0.875rem !important;
}

/* === Badges - Plain text, NO backgrounds === */
body #customer-management-module .badge,
#customer-management-module .badge {
    font-size: 0.813rem !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    display: inline !important;
    background: none !important;
    padding: 0 !important;
    border: none !important;
}
body #customer-management-module .badge-credit,
#customer-management-module .badge-credit {
    color: #002F70 !important;
}
body #customer-management-module .badge-walkin,
#customer-management-module .badge-walkin {
    color: #6c757d !important;
}
body #customer-management-module .badge-ok,
body #customer-management-module .badge-validated,
#customer-management-module .badge-ok,
#customer-management-module .badge-validated {
    color: #28a745 !important;
}
body #customer-management-module .badge-short,
#customer-management-module .badge-short {
    color: #dc3545 !important;
}
body #customer-management-module .badge-excess,
#customer-management-module .badge-excess {
    color: #fd7e14 !important;
}

/* === Row status - No special backgrounds === */
.row-over-limit,
.row-near-limit {
    /* No background colors - plain rows */
}

/* === Buttons === */
.btn, .act-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.813rem;
    font-weight: 600;
    transition: all .2s ease;
    color: #fff;
    text-decoration: none;
    white-space: nowrap;
}
.btn-validate, .act-btn.approve {
    background: #28a745;
}
.btn-validate:hover, .act-btn.approve:hover {
    background: #218838;
    box-shadow: 0 2px 4px rgba(40,167,69,0.3);
}
.act-btn.view {
    background: #6c757d;
}
.act-btn.view:hover {
    background: #5a6268;
    box-shadow: 0 2px 4px rgba(108,117,125,0.3);
}
.btn-sm {
    padding: 6px 12px;
    font-size: 0.75rem;
}
.btn-primary {
    background: var(--primary-color);
}
.btn-primary:hover {
    background: var(--primary-hover);
    box-shadow: 0 2px 4px rgba(0,47,108,0.3);
}

/* === Empty State === */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--gray-600);
}
.empty-state i {
    font-size: 48px;
    display: block;
    margin-bottom: 16px;
    opacity: 0.4;
}
.empty-state p {
    margin: 0;
    font-size: 14px;
}

/* === Modal === */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.modal-overlay.open {
    display: flex;
}
.modal-box {
    background: #fff;
    border-radius: 12px;
    padding: 28px;
    width: 100%;
    max-width: 500px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-height: 90vh;
    overflow-y: auto;
}
.modal-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--primary-color);
    margin-bottom: 20px;
}
.modal-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.modal-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
    margin-bottom: 16px;
}
.modal-input:focus {
    border-color: var(--primary-color);
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
}
.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}
</style>

<!-- Customer Management Module Wrapper -->
<div id="customer-management-module">

<div class="page-head">
    <div>
        <h1><i class="fas fa-users"></i> Customers</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> — Manager customer oversight</div>
    </div>
</div>

<?php if ($flash_ok): ?>
<div class="flash-ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($flash_ok); ?></div>
<?php endif; ?>

<?php if ($flash_err): ?>
<div class="flash-err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($flash_err); ?></div>
<?php endif; ?>

<!-- Tab Navigation -->
<div class="tab-nav">
    <a href="?section=records" class="tab-btn <?= $section === 'records' ? 'active' : '' ?>">
        <i class="fas fa-list"></i> Customer List
    </a>
    <a href="?section=balances" class="tab-btn <?= $section === 'balances' ? 'active' : '' ?>">
        <i class="fas fa-balance-scale"></i> Customer Balances
    </a>
    <a href="?section=history" class="tab-btn <?= $section === 'history' ? 'active' : '' ?>">
        <i class="fas fa-history"></i> Customer History
    </a>
</div>

<!-- Section Content -->
<?php if ($section === 'records'): ?>
    <!-- CUSTOMER LIST SECTION -->
    <?php
    // Get filter parameters
    $search = trim($_GET['search'] ?? '');
    $status_filter = $_GET['status'] ?? 'all';
    $type_filter = $_GET['type'] ?? 'all';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;

    // Build WHERE clause for search and filters
    $where_conditions = ["c.station_id = :station_id"];
    $bind_params = [':station_id' => $station_id];

    if (!empty($search)) {
        $where_conditions[] = "(c.name LIKE :search_like OR c.contact_number LIKE :search_like OR c.id_number LIKE :search_like)";
        $bind_params[':search_like'] = '%' . $search . '%';
    }

    if ($status_filter !== 'all') {
        $where_conditions[] = "COALESCE(c.status, 'active') = :status_filter";
        $bind_params[':status_filter'] = $status_filter;
    }

    if ($type_filter !== 'all') {
        $where_conditions[] = "c.type = :type_filter";
        $bind_params[':type_filter'] = $type_filter;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Get total count for pagination
    try {
        $count_sql = "SELECT COUNT(*) FROM customers c WHERE $where_clause";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($bind_params);
        $total_records = (int)$count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);
        $page = min($page, max(1, $total_pages)); // Clamp page to valid range
    } catch (Exception $e) {
        $total_records = 0;
        $total_pages = 0;
    }

    // Fetch customer records
    $customers = [];
    try {
        $sql = "
            SELECT 
                c.id,
                c.name,
                c.contact_number,
                c.type,
                COALESCE(c.credit_limit, 0) AS credit_limit,
                COALESCE(c.current_balance, c.balance, 0) AS outstanding_balance,
                COALESCE(c.status, 'active') AS status,
                COALESCE(c.created_at, c.registration_date) AS registration_date
            FROM customers c
            WHERE $where_clause
            ORDER BY c.name ASC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($bind_params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $flash_err = "Database error: " . $e->getMessage();
        $customers = [];
    }

    // Build current query string for pagination links
    $query_params = [];
    if (!empty($search)) $query_params['search'] = $search;
    if ($status_filter !== 'all') $query_params['status'] = $status_filter;
    if ($type_filter !== 'all') $query_params['type'] = $type_filter;
    $query_params['section'] = 'records';
    ?>

    <!-- Filter Bar -->
    <div class="dv-card">
        <div class="dv-card-head">
            <span class="dv-card-title"><i class="fas fa-filter"></i> Filters</span>
        </div>
        <div class="dv-card-body">
            <form method="GET" action="manager_customer_management.php" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                <input type="hidden" name="section" value="records">
                
                <div style="flex: 1; min-width: 200px;">
                    <label class="modal-label">Search</label>
                    <input type="text" name="search" class="modal-input" 
                           placeholder="Name, contact, or ID number" 
                           value="<?= htmlspecialchars($search) ?>"
                           data-debounce="300"
                           style="margin-bottom: 0;">
                </div>

                <div style="min-width: 150px;">
                    <label class="modal-label">Status</label>
                    <select name="status" class="modal-input" style="margin-bottom: 0;">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div style="min-width: 150px;">
                    <label class="modal-label">Type</label>
                    <select name="type" class="modal-input" style="margin-bottom: 0;">
                        <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="credit" <?= $type_filter === 'credit' ? 'selected' : '' ?>>Credit</option>
                        <option value="cash" <?= $type_filter === 'cash' ? 'selected' : '' ?>>Cash</option>
                    </select>
                </div>

                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn btn-validate">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="?section=records" class="btn" style="background: #6c757d; color: #fff;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Customer List Table -->
    <div class="dv-card">
        <div class="dv-card-head">
            <span class="dv-card-title"><i class="fas fa-list"></i> Customer List</span>
            <span style="font-size: 13px; color: #6c757d;">
                <?= number_format($total_records) ?> record<?= $total_records !== 1 ? 's' : '' ?> found
            </span>
        </div>
        <div class="dv-card-body">
            <?php if (count($customers) > 0): ?>
                <div class="po-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Contact</th>
                            <th>Type</th>
                            <th style="text-align: right;">Credit Limit</th>
                            <th style="text-align: right;">Outstanding Balance</th>
                            <th>Status</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $cust): ?>
                            <tr>
                                <td><?= htmlspecialchars($cust['name']) ?></td>
                                <td><?= htmlspecialchars($cust['contact_number'] ?? '—') ?></td>
                                <td>
                                    <?php
                                    $type_class = $cust['type'] === 'credit' ? 'badge-credit' : 'badge-walkin';
                                    $type_display = $cust['type'] === 'cash' ? 'Cash' : 'Credit';
                                    ?>
                                    <span class="badge <?= $type_class ?>">
                                        <?= htmlspecialchars($type_display) ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    ₱<?= number_format((float)$cust['credit_limit'], 2) ?>
                                </td>
                                <td style="text-align: right;">
                                    ₱<?= number_format((float)$cust['outstanding_balance'], 2) ?>
                                </td>
                                <td>
                                    <?php
                                    $status_class = $cust['status'] === 'active' ? 'badge-ok' : 'badge-short';
                                    ?>
                                    <span class="badge <?= $status_class ?>">
                                        <?= htmlspecialchars(ucfirst($cust['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if ($cust['registration_date']) {
                                        $date = new DateTime($cust['registration_date']);
                                        echo $date->format('M d, Y');
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- Pagination Controls -->
                <?php if ($total_pages > 1): ?>
                    <div style="margin-top: 20px; display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($query_params, ['page' => $page - 1])) ?>" 
                               class="btn btn-sm" style="background: #6c757d; color: #fff;">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>

                        <?php
                        // Show page numbers with ellipsis
                        $show_pages = [];
                        if ($total_pages <= 7) {
                            $show_pages = range(1, $total_pages);
                        } else {
                            $show_pages = [1];
                            if ($page > 3) $show_pages[] = '...';
                            for ($i = max(2, $page - 1); $i <= min($total_pages - 1, $page + 1); $i++) {
                                $show_pages[] = $i;
                            }
                            if ($page < $total_pages - 2) $show_pages[] = '...';
                            $show_pages[] = $total_pages;
                        }

                        foreach ($show_pages as $p):
                            if ($p === '...'): ?>
                                <span style="padding: 0 8px; color: #6c757d;">...</span>
                            <?php else: ?>
                                <a href="?<?= http_build_query(array_merge($query_params, ['page' => $p])) ?>" 
                                   class="btn btn-sm"
                                   style="<?= $p === $page ? 'background: var(--primary-color); color: #fff;' : 'background: #e9ecef; color: #495057;' ?>">
                                    <?= $p ?>
                                </a>
                            <?php endif;
                        endforeach; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?= http_build_query(array_merge($query_params, ['page' => $page + 1])) ?>" 
                               class="btn btn-sm" style="background: #6c757d; color: #fff;">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <p>
                        <?php if (!empty($search) || $status_filter !== 'all' || $type_filter !== 'all'): ?>
                            No customers match the current filter criteria.
                        <?php else: ?>
                            No customer records are available for this station.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($section === 'balances'): ?>
    <!-- CUSTOMER BALANCES SECTION -->
    <?php
    // Fetch customer balance data
    $balance_customers = [];
    try {
        $bal_sql = "
            SELECT 
                c.id,
                c.name,
                COALESCE(c.credit_limit, 0) AS credit_limit,
                COALESCE(c.current_balance, c.balance, 0) AS outstanding,
                COALESCE(c.credit_limit, 0) - COALESCE(c.current_balance, c.balance, 0) AS available_credit,
                CASE WHEN COALESCE(c.credit_limit, 0) > 0
                     THEN ROUND((COALESCE(c.current_balance, c.balance, 0) / c.credit_limit) * 100, 1)
                     ELSE 0 END AS utilization_pct,
                (SELECT MAX(COALESCE(mt.transaction_date, mt.created_at))
                 FROM merchandise_transactions mt
                 WHERE mt.customer_id = c.id) AS last_txn_date
            FROM customers c
            WHERE c.station_id = :station_id
              AND (COALESCE(c.credit_limit, 0) > 0 OR COALESCE(c.current_balance, c.balance, 0) > 0)
            ORDER BY outstanding DESC
        ";
        $bal_stmt = $pdo->prepare($bal_sql);
        $bal_stmt->execute([':station_id' => $station_id]);
        $balance_customers = $bal_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $flash_err = "Database error: " . $e->getMessage();
        $balance_customers = [];
    }
    ?>

    <div class="dv-card">
        <div class="dv-card-head">
            <span class="dv-card-title"><i class="fas fa-balance-scale"></i> Customer Balances</span>
            <span style="font-size: 13px; color: #6c757d;">
                <?= count($balance_customers) ?> customer<?= count($balance_customers) !== 1 ? 's' : '' ?>
            </span>
        </div>
        <div class="dv-card-body">
            <?php if (count($balance_customers) > 0): ?>
                <div class="po-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th style="text-align: right;">Credit Limit</th>
                            <th style="text-align: right;">Outstanding Balance</th>
                            <th style="text-align: right;">Available Credit</th>
                            <th style="text-align: center;">Utilization</th>
                            <th>Last Transaction</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($balance_customers as $bc): 
                            $credit_limit = (float)$bc['credit_limit'];
                            $outstanding = (float)$bc['outstanding'];
                            $available = (float)$bc['available_credit'];
                            $utilization = (float)$bc['utilization_pct'];
                            
                            // Determine row class
                            $row_class = '';
                            if ($credit_limit > 0) {
                                if ($outstanding >= $credit_limit) {
                                    $row_class = 'row-over-limit';
                                } elseif ($utilization >= 80) {
                                    $row_class = 'row-near-limit';
                                }
                            }
                            
                            // Utilization badge color
                            $util_badge = 'badge-ok';
                            if ($utilization >= 100) {
                                $util_badge = 'badge-short';
                            } elseif ($utilization >= 80) {
                                $util_badge = 'badge-walkin';
                            }
                        ?>
                            <tr class="<?= $row_class ?>" data-customer-id="<?= (int)$bc['id'] ?>" data-credit-limit="<?= $credit_limit ?>">
                                <td><strong><?= htmlspecialchars($bc['name']) ?></strong></td>
                                <td style="text-align: right;">₱<?= number_format($credit_limit, 2) ?></td>
                                <td style="text-align: right;" class="outstanding-balance">
                                    ₱<?= number_format($outstanding, 2) ?>
                                </td>
                                <td style="text-align: right;" class="available-credit">
                                    ₱<?= number_format(max(0, $available), 2) ?>
                                </td>
                                <td style="text-align: center;" class="utilization">
                                    <span class="badge <?= $util_badge ?>">
                                        <?= number_format($utilization, 1) ?>%
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if ($bc['last_txn_date']) {
                                        $date = new DateTime($bc['last_txn_date']);
                                        echo $date->format('M d, Y');
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" 
                                            class="btn btn-validate btn-sm"
                                            onclick="openPaymentModal(<?= (int)$bc['id'] ?>, '<?= htmlspecialchars($bc['name'], ENT_QUOTES) ?>', <?= $outstanding ?>)">
                                        <i class="fas fa-money-bill-wave"></i> Record Payment
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-wallet"></i>
                    <p>No customers with credit limits or outstanding balances.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Modal -->
    <div class="modal-overlay" id="paymentModal">
        <div class="modal-box">
            <div class="modal-title">Record Payment</div>
            <form id="paymentForm" onsubmit="return false;">
                <div style="margin-bottom: 16px;">
                    <strong>Customer:</strong> <span id="paymentCustomerName"></span>
                </div>
                
                <input type="hidden" id="paymentCustomerId" value="">
                
                <label class="modal-label">Payment Amount (₱) <span style="color: #dc3545;">*</span></label>
                <input type="number" 
                       id="paymentAmount" 
                       class="modal-input" 
                       placeholder="0.00" 
                       step="0.01" 
                       min="0.01" 
                       required>
                
                <label class="modal-label">Reference / Notes <span style="color: #dc3545;">*</span></label>
                <textarea id="paymentReference" 
                          class="modal-input" 
                          rows="3" 
                          placeholder="e.g., Check #12345, Bank transfer confirmation, etc."
                          required></textarea>
                
                <div class="modal-actions">
                    <button type="button" class="btn" style="background: #6c757d; color: #fff;" onclick="closePaymentModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-validate" onclick="submitPayment()">
                        <i class="fas fa-check"></i> Submit Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($section === 'history'): ?>
    <!-- CUSTOMER HISTORY SECTION -->
    <div class="dv-card">
        <div class="dv-card-head">
            <span class="dv-card-title"><i class="fas fa-history"></i> Customer History</span>
        </div>
        <div class="dv-card-body">
            <div class="empty-state">
                <i class="fas fa-file-alt"></i>
                <p>Customer history implementation coming soon.</p>
            </div>
        </div>
    </div>

<?php endif; ?>

<!-- Page-specific JavaScript -->
<script src="../assets/js/manager_customer_management.js"></script>

<script>
// Force styles to apply - debug script
document.addEventListener('DOMContentLoaded', function() {
    console.log('Customer Management Module - Applying forced styles');
    
    // Force table header styles
    const tableHeaders = document.querySelectorAll('#customer-management-module .data-table thead th');
    tableHeaders.forEach(th => {
        th.style.setProperty('background', '#003471', 'important');
        th.style.setProperty('color', '#fff', 'important');
        th.style.setProperty('padding', '14px 16px', 'important');
        th.style.setProperty('text-transform', 'uppercase', 'important');
        th.style.setProperty('font-weight', '600', 'important');
        th.style.setProperty('border', 'none', 'important');
    });
    
    // Force badge styles
    const badges = document.querySelectorAll('#customer-management-module .badge');
    badges.forEach(badge => {
        badge.style.setProperty('display', 'inline-flex', 'important');
        badge.style.setProperty('padding', '4px 10px', 'important');
        badge.style.setProperty('border-radius', '12px', 'important');
        badge.style.setProperty('border', 'none', 'important');
        
        if (badge.classList.contains('badge-credit')) {
            badge.style.setProperty('background', '#e3f2fd', 'important');
            badge.style.setProperty('color', '#1976d2', 'important');
        } else if (badge.classList.contains('badge-walkin')) {
            badge.style.setProperty('background', '#f5f5f5', 'important');
            badge.style.setProperty('color', '#616161', 'important');
        } else if (badge.classList.contains('badge-ok') || badge.classList.contains('badge-validated')) {
            badge.style.setProperty('background', '#d1f4e0', 'important');
            badge.style.setProperty('color', '#0d7d3e', 'important');
        } else if (badge.classList.contains('badge-short')) {
            badge.style.setProperty('background', '#ffebee', 'important');
            badge.style.setProperty('color', '#c62828', 'important');
        }
    });
    
    console.log('Styles applied:', {
        headers: tableHeaders.length,
        badges: badges.length
    });
});
</script>

</div><!-- End #customer-management-module -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
