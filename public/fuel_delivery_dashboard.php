<?php
/**
 * FUEL DELIVERY DASHBOARD
 * 
 * Comprehensive tracking and management interface for:
 * - All delivery methods (3-step workflow vs. manager direct)
 * - Delivery status tracking
 * - Audit trail viewing
 * - Stock impact analysis
 * - Performance metrics
 */

session_start();
require_once 'db_connect.php';
require_once '../backend/lib.php';
require_once '../backend/fuel_audit_logging.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Get user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Check if user has appropriate permissions
$hasPermission = in_array($me['role'], ['manager', 'superadmin', 'staff']);
if (!$hasPermission) {
    header("Location: dashboard.php");
    exit;
}

// Get user's station (for non-superadmins)
$station_id = user_station_id($pdo, $me['id']);
$isSuperadmin = ($me['role'] === 'superadmin');

// Handle filtering
$status_filter = $_GET['status'] ?? 'all';
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$fuel_type_filter = $_GET['fuel_type'] ?? '';

// Build query conditions
$where_conditions = ["1=1"];
$params = [];

if (!$isSuperadmin && $station_id) {
    $where_conditions[] = "fd.station_id = ?";
    $params[] = $station_id;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "fd.status = ?";
    $params[] = $status_filter;
}

if ($date_from) {
    $where_conditions[] = "fd.delivery_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "fd.delivery_date <= ?";
    $params[] = $date_to;
}

if ($fuel_type_filter) {
    $where_conditions[] = "fd.fuel_type LIKE ?";
    $params[] = "%$fuel_type_filter%";
}

$where_clause = implode(" AND ", $where_conditions);

// Get deliveries with detailed information
$stmt = $pdo->prepare("
    SELECT 
        fd.*,
        s.name as station_name,
        u_received.name as received_by_name,
        u_verified.name as verified_by_name,
        u_finalized.name as finalized_by_name,
        p.name as product_name,
        si.stock_level as current_stock,
        DATEDIFF(NOW(), fd.delivery_date) as days_since_delivery
    FROM fuel_deliveries fd
    LEFT JOIN stations s ON fd.station_id = s.id
    LEFT JOIN users u_received ON fd.received_by = u_received.id
    LEFT JOIN users u_verified ON fd.verified_by = u_verified.id
    LEFT JOIN users u_finalized ON fd.finalized_by = u_finalized.id
    LEFT JOIN products p ON (p.name = fd.fuel_type OR p.sku = fd.fuel_type) AND p.type_id = 1
    LEFT JOIN station_inventory si ON si.station_id = fd.station_id AND si.product_id = p.id
    WHERE {$where_clause}
    ORDER BY fd.created_at DESC
");

$stmt->execute($params);
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$stats = [
    'total_deliveries' => 0,
    'pending_approvals' => 0,
    'completed_deliveries' => 0,
    'total_liters' => 0,
    'avg_processing_time' => 0
];

$status_counts = [
    'Encoded' => 0,
    'Verified' => 0,
    'Finalized' => 0,
    'Manager_Direct' => 0,
    'Rejected' => 0
];

foreach ($deliveries as $delivery) {
    $stats['total_deliveries']++;
    $stats['total_liters'] += $delivery['delivery_liters'];
    
    if (isset($status_counts[$delivery['status']])) {
        $status_counts[$delivery['status']]++;
    }
    
    if (in_array($delivery['status'], ['Encoded', 'Verified'])) {
        $stats['pending_approvals']++;
    } elseif (in_array($delivery['status'], ['Finalized', 'Manager_Direct'])) {
        $stats['completed_deliveries']++;
    }
}

// Get available fuel types
$stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_deliveries ORDER BY fuel_type");
$stmt->execute();
$fuel_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get recent audit trail entries
$recent_audit = [];
if (!empty($deliveries)) {
    $delivery_ids = array_column($deliveries, 'id');
    $placeholders = str_repeat('?,', count($delivery_ids) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT 
            al.*,
            u.name as user_name,
            JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.fuel_type')) as fuel_type,
            JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.reference_id')) as delivery_id
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.log_type = 'inventory' 
        AND al.entity_type = 'fuel_inventory'
        AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.reference_type')) = 'fuel_delivery'
        AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.reference_id')) IN ($placeholders)
        ORDER BY al.created_at DESC
        LIMIT 20
    ");
    
    $stmt->execute($delivery_ids);
    $recent_audit = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Delivery Dashboard - Petron POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .status-encoded { background: #6c757d; }
        .status-verified { background: #0dcaf0; }
        .status-finalized { background: #198754; }
        .status-manager_direct { background: #0d6efd; }
        .status-rejected { background: #dc3545; }
        .metric-card {
            transition: transform 0.2s;
            border-left: 4px solid #0d6efd;
        }
        .metric-card:hover {
            transform: translateY(-2px);
        }
        .delivery-row:hover {
            background-color: rgba(0,123,255,0.1);
        }
        .audit-entry {
            border-left: 3px solid #dee2e6;
            transition: border-color 0.2s;
        }
        .audit-entry:hover {
            border-left-color: #0d6efd;
        }
        .filter-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="bi bi-graph-up-arrow"></i> Fuel Delivery Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="manager_fuel_delivery.php">
                    <i class="bi bi-plus-circle"></i> Record Delivery
                </a>
                <a class="nav-link" href="inventory.php">
                    <i class="bi bi-box-seam"></i> Inventory
                </a>
                <a class="nav-link" href="dashboard.php">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Summary Metrics -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-truck display-4 text-primary mb-2"></i>
                        <h3 class="card-title"><?= $stats['total_deliveries'] ?></h3>
                        <p class="card-text text-muted">Total Deliveries</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-clock-history display-4 text-warning mb-2"></i>
                        <h3 class="card-title"><?= $stats['pending_approvals'] ?></h3>
                        <p class="card-text text-muted">Pending</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle display-4 text-success mb-2"></i>
                        <h3 class="card-title"><?= $stats['completed_deliveries'] ?></h3>
                        <p class="card-text text-muted">Completed</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-droplet-fill display-4 text-info mb-2"></i>
                        <h3 class="card-title"><?= number_format($stats['total_liters'], 0) ?>L</h3>
                        <p class="card-text text-muted">Total Liters</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="card-title">Status Breakdown</h6>
                        <?php foreach ($status_counts as $status => $count): ?>
                            <?php if ($count > 0): ?>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge status-<?= strtolower($status) ?> text-white"><?= $status ?></span>
                                <span class="fw-bold"><?= $count ?></span>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card filter-section text-white shadow-lg mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="Encoded" <?= $status_filter === 'Encoded' ? 'selected' : '' ?>>Encoded</option>
                            <option value="Verified" <?= $status_filter === 'Verified' ? 'selected' : '' ?>>Verified</option>
                            <option value="Finalized" <?= $status_filter === 'Finalized' ? 'selected' : '' ?>>Finalized</option>
                            <option value="Manager_Direct" <?= $status_filter === 'Manager_Direct' ? 'selected' : '' ?>>Manager Direct</option>
                            <option value="Rejected" <?= $status_filter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Fuel Type</label>
                        <select name="fuel_type" class="form-select">
                            <option value="">All Types</option>
                            <?php foreach ($fuel_types as $fuel_type): ?>
                            <option value="<?= htmlspecialchars($fuel_type) ?>" 
                                    <?= $fuel_type_filter === $fuel_type ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fuel_type) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-light d-block w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <a href="fuel_delivery_dashboard.php" class="btn btn-outline-light d-block w-100">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            <!-- Delivery List -->
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-list-task"></i> Fuel Deliveries
                            <span class="badge bg-primary ms-2"><?= count($deliveries) ?> records</span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($deliveries)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-4 text-muted"></i>
                            <h5 class="text-muted mt-3">No deliveries found</h5>
                            <p class="text-muted">Adjust your filters or 
                                <a href="manager_fuel_delivery.php">record a new delivery</a>
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Date</th>
                                        <th>Fuel Type</th>
                                        <th>Liters</th>
                                        <th>Supplier</th>
                                        <th>Status</th>
                                        <th>Current Stock</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deliveries as $delivery): ?>
                                    <tr class="delivery-row">
                                        <td>
                                            <strong>#<?= $delivery['id'] ?></strong>
                                            <br><small class="text-muted"><?= $delivery['invoice_no'] ?></small>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($delivery['delivery_date'])) ?>
                                            <br><small class="text-muted"><?= $delivery['days_since_delivery'] ?> days ago</small>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($delivery['fuel_type']) ?></strong>
                                            <?php if ($delivery['product_name']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($delivery['product_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-primary">
                                                <?= number_format($delivery['delivery_liters'], 0) ?>L
                                            </span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($delivery['supplier']) ?>
                                            <?php if ($delivery['tanker_number']): ?>
                                                <br><small class="text-muted">Tanker: <?= htmlspecialchars($delivery['tanker_number']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge status-<?= strtolower($delivery['status']) ?> text-white">
                                                <?= $delivery['status'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($delivery['current_stock'] !== null): ?>
                                                <strong><?= number_format($delivery['current_stock'], 0) ?>L</strong>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-info" 
                                                        data-bs-toggle="modal" data-bs-target="#deliveryModal<?= $delivery['id'] ?>">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary"
                                                        onclick="showAuditTrail(<?= $delivery['id'] ?>)">
                                                    <i class="bi bi-clock-history"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Audit Trail -->
            <div class="col-lg-4">
                <div class="card shadow">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-shield-check"></i> Recent Audit Trail
                        </h5>
                        <small>Latest fuel delivery activities</small>
                    </div>
                    <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                        <?php if (empty($recent_audit)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-clipboard-data display-4 text-muted"></i>
                            <p class="text-muted mt-2">No audit trail found</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($recent_audit as $audit): ?>
                        <div class="audit-entry p-3 mb-2 bg-light rounded">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1"><?= htmlspecialchars($audit['action_type']) ?></h6>
                                    <p class="mb-1 small"><?= htmlspecialchars($audit['action_details']) ?></p>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-secondary"><?= htmlspecialchars($audit['fuel_type']) ?></span>
                                        <small class="text-muted">Delivery #<?= $audit['delivery_id'] ?></small>
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <?= date('M j, g:i A', strtotime($audit['created_at'])) ?>
                                </small>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-person"></i> <?= htmlspecialchars($audit['user_name']) ?>
                                </small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delivery Detail Modals -->
    <?php foreach ($deliveries as $delivery): ?>
    <div class="modal fade" id="deliveryModal<?= $delivery['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-truck"></i> Delivery #<?= $delivery['id'] ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Delivery Information</h6>
                            <table class="table table-sm">
                                <tr><td><strong>Date:</strong></td><td><?= date('F j, Y', strtotime($delivery['delivery_date'])) ?></td></tr>
                                <tr><td><strong>Fuel Type:</strong></td><td><?= htmlspecialchars($delivery['fuel_type']) ?></td></tr>
                                <tr><td><strong>Quantity:</strong></td><td><?= number_format($delivery['delivery_liters'], 2) ?> liters</td></tr>
                                <tr><td><strong>Supplier:</strong></td><td><?= htmlspecialchars($delivery['supplier']) ?></td></tr>
                                <tr><td><strong>Invoice:</strong></td><td><?= htmlspecialchars($delivery['invoice_no']) ?></td></tr>
                                <tr><td><strong>Tanker:</strong></td><td><?= htmlspecialchars($delivery['tanker_number']) ?: 'N/A' ?></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>Status & Processing</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Status:</strong></td>
                                    <td><span class="badge status-<?= strtolower($delivery['status']) ?> text-white"><?= $delivery['status'] ?></span></td>
                                </tr>
                                <tr><td><strong>Station:</strong></td><td><?= htmlspecialchars($delivery['station_name']) ?></td></tr>
                                <tr><td><strong>Received By:</strong></td><td><?= htmlspecialchars($delivery['received_by_name']) ?></td></tr>
                                <?php if ($delivery['verified_by_name']): ?>
                                <tr><td><strong>Verified By:</strong></td><td><?= htmlspecialchars($delivery['verified_by_name']) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($delivery['finalized_by_name']): ?>
                                <tr><td><strong>Finalized By:</strong></td><td><?= htmlspecialchars($delivery['finalized_by_name']) ?></td></tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                    <?php if ($delivery['notes']): ?>
                    <div class="mt-3">
                        <h6>Notes</h6>
                        <div class="border p-2 rounded bg-light">
                            <?= nl2br(htmlspecialchars($delivery['notes'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function showAuditTrail(deliveryId) {
            // This could be enhanced to show a detailed audit trail modal
            alert('Audit trail for Delivery #' + deliveryId + ' - Feature can be expanded to show detailed audit history');
        }
    </script>
</body>
</html>