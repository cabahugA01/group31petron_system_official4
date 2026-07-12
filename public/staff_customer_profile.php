<?php
/**
 * Staff Customer Profile
 * View detailed customer information
 */

$page_id = 'customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

customer_ensure_optional_columns($pdo);

// Staff only
if (!in_array($role, ['staff', 'superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

$customer_id = (int)($_GET['id'] ?? 0);
if (!$customer_id) {
    header('Location: staff_customer_list.php');
    exit;
}

// Fetch customer details
try {
    $customerIdExpr = customer_id_expr($pdo, 'c');
    $displayNameExpr = customer_display_name_expr($pdo, 'c');
    $firstNameExpr = customer_first_name_expr($pdo, 'c');
    $middleNameExpr = customer_middle_name_expr($pdo, 'c');
    $lastNameExpr = customer_last_name_expr($pdo, 'c');
    $contactExpr = customer_contact_expr($pdo, 'c');
    $typeExpr = customer_type_expr($pdo, 'c');
    $statusExpr = customer_status_expr($pdo, 'c');
    $registeredExpr = customer_registered_at_expr($pdo, 'c');
    $govIdTypeExpr = customer_gov_id_type_expr($pdo, 'c');
    $updatedAtExpr = customer_has_column($pdo, 'updated_at') ? 'c.updated_at' : 'NULL';

    $where = ['c.id = ?'];
    $params = [$customer_id];
    customer_apply_station_scope($where, $params, 'c', $role, $station_id);

    $stmt = $pdo->prepare("
        SELECT c.*,
               $customerIdExpr AS customer_id,
               $displayNameExpr AS display_name,
               $firstNameExpr AS first_name,
               $middleNameExpr AS middle_name,
               $lastNameExpr AS last_name,
               $contactExpr AS contact_number,
               $typeExpr AS customer_type,
               $statusExpr AS status,
               $registeredExpr AS registered_at,
               $govIdTypeExpr AS gov_id_type,
               $updatedAtExpr AS updated_at,
               " . customer_user_name_expr('u') . " AS registered_by_name
        FROM customers c
        LEFT JOIN users u ON c.registered_by = u.id
        WHERE " . implode(' AND ', $where) . "
    ");
    $stmt->execute($params);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $_SESSION['error'] = 'Customer not found';
        header('Location: staff_customer_list.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['error'] = 'Error loading customer: ' . $e->getMessage();
    header('Location: staff_customer_list.php');
    exit;
}

$fullName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['middle_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));

$page_title = "Customer Profile - $fullName";
include __DIR__ . '/../partials/header.php';
?>

<style>
.profile-container{max-width:1000px;margin:0 auto;}
.profile-header{background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:24px;margin-bottom:20px;display:flex;align-items:center;gap:20px;}
.profile-avatar{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#002F70,#0056b3);color:#fff;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;}
.profile-info h2{margin:0 0 8px;font-size:24px;color:#002F70;}
.profile-badges{display:flex;gap:8px;flex-wrap:wrap;}
.badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;}
.badge-walkin{background:#e0e7ff;color:#3730a3;}
.badge-regular{background:#fef3c7;color:#92400e;}
.badge-fleet{background:#dbeafe;color:#1e40af;}
.badge-active{background:#d1fae5;color:#065f46;}
.badge-inactive{background:#fee2e2;color:#991b1b;}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:20px;}
.info-card{background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:20px;}
.info-card h3{margin:0 0 16px;font-size:16px;font-weight:700;color:#002F70;border-bottom:2px solid #e5e7eb;padding-bottom:10px;}
.info-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f3f4f6;}
.info-row:last-child{border-bottom:none;}
.info-label{font-size:13px;font-weight:600;color:#6b7280;}
.info-value{font-size:14px;color:#1f2937;font-weight:500;}
.action-buttons{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-user"></i> Customer Profile</h1>
        <div class="sub">View customer details and transaction history</div>
    </div>
    <div>
        <button class="btn btn-secondary" onclick="history.back()"><i class="fas fa-arrow-left"></i> Back</button>
    </div>
</div>

<div class="profile-container">
    <!-- Profile Header -->
    <div class="profile-header">
        <div class="profile-avatar">
            <?= strtoupper(substr($customer['first_name'] ?? '', 0, 1) . substr($customer['last_name'] ?? '', 0, 1)) ?>
        </div>
        <div class="profile-info" style="flex:1;">
            <h2><?= htmlspecialchars($fullName) ?></h2>
            <p style="margin:4px 0;color:#6b7280;font-size:14px;">
                <i class="fas fa-id-card"></i> <?= htmlspecialchars($customer['customer_id'] ?? 'N/A') ?>
            </p>
            <div class="profile-badges">
                <span class="badge badge-regular">
                    Registered
                </span>
                <span class="badge badge-<?= $customer['status'] === 'active' ? 'active' : 'inactive' ?>">
                    <?= ucfirst($customer['status'] ?? 'N/A') ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Info Cards -->
    <div class="info-grid">
        <!-- Contact Information -->
        <div class="info-card">
            <h3><i class="fas fa-address-card"></i> Contact Information</h3>
            <div class="info-row">
                <span class="info-label">Contact Number</span>
                <span class="info-value"><?= htmlspecialchars($customer['contact_number'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Address</span>
                <span class="info-value"><?= htmlspecialchars($customer['address'] ?? 'N/A') ?></span>
            </div>
            <?php if ($customer['gov_id_type']): ?>
            <div class="info-row">
                <span class="info-label">ID Type</span>
                <span class="info-value"><?= htmlspecialchars($customer['gov_id_type']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Registration Details -->
        <div class="info-card">
            <h3><i class="fas fa-info-circle"></i> Registration Details</h3>
            <div class="info-row">
                <span class="info-label">Registered On</span>
                <span class="info-value">
                    <?= $customer['registered_at'] ? date('M d, Y h:i A', strtotime($customer['registered_at'])) : 'N/A' ?>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Registered By</span>
                <span class="info-value"><?= htmlspecialchars($customer['registered_by_name'] ?? 'System') ?></span>
            </div>
            <?php if ($customer['updated_at']): ?>
            <div class="info-row">
                <span class="info-label">Last Updated</span>
                <span class="info-value"><?= date('M d, Y h:i A', strtotime($customer['updated_at'])) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="info-card">
        <div class="action-buttons">
            <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Profile</button>
            <button class="btn btn-warning" onclick="editCustomer()"><i class="fas fa-edit"></i> Edit Customer</button>
            <button class="btn btn-secondary" onclick="history.back()"><i class="fas fa-arrow-left"></i> Back to List</button>
        </div>
    </div>
</div>

<script>
function editCustomer() {
    window.location.href = 'staff_customer_list.php?edit=<?= (int)$customer_id ?>';
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
