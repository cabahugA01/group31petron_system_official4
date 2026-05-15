<?php
$page_id = 'fuel_price_change_log';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only managers and above can access fuel price change log
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

$msg = '';
if (isset($_SESSION['success'])) { 
    $msg = $_SESSION['success']; 
    unset($_SESSION['success']); 
}
if (isset($_SESSION['error'])) { 
    $msg = $_SESSION['error']; 
    unset($_SESSION['error']); 
}

// Handle form submission for new price change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add_price_change':
            $fuel_type = $_POST['fuel_type'] ?? '';
            $old_price = floatval($_POST['old_price'] ?? 0);
            $new_price = floatval($_POST['new_price'] ?? 0);
            $reason = $_POST['reason'] ?? '';
            
            try {
                // Validate inputs
                if (empty($fuel_type)) {
                    throw new Exception('Fuel type is required');
                }
                if ($old_price <= 0) {
                    throw new Exception('Old price must be greater than 0');
                }
                if ($new_price <= 0) {
                    throw new Exception('New price must be greater than 0');
                }
                if (empty($reason)) {
                    throw new Exception('Reason for change is required');
                }
                
                // Get current fuel inventory price
                $stmt = $pdo->prepare("
                    SELECT price_per_liter 
                    FROM fuel_inventory 
                    WHERE station_id = ? AND fuel_type = ?
                ");
                $stmt->execute([$station_id, $fuel_type]);
                $current_price = $stmt->fetchColumn();
                
                if ($current_price === false) {
                    throw new Exception('Fuel type not found in inventory');
                }
                
                // Verify old price matches current price
                if (abs($current_price - $old_price) > 0.01) {
                    throw new Exception('Old price does not match current inventory price');
                }
                
                // Log the price change
                $stmt = $pdo->prepare("
                    INSERT INTO fuel_price_log (
                        station_id, fuel_type, old_price, new_price,
                        changed_by, changed_by_name, reason_for_change,
                        ip_address, user_agent
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $station_id,
                    $fuel_type,
                    $old_price,
                    $new_price,
                    $me['id'],
                    $me['name'],
                    $reason,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                // Update fuel inventory price
                $stmt = $pdo->prepare("
                    UPDATE fuel_inventory 
                    SET price_per_liter = ?, updated_at = NOW()
                    WHERE station_id = ? AND fuel_type = ?
                ");
                $stmt->execute([$new_price, $station_id, $fuel_type]);
                
                // Log to audit trail
                $stmt = $pdo->prepare("
                    INSERT INTO audit_log (action, details, user_id, station_id, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $audit_details = "Manager {$me['name']} (ID: {$me['id']}) changed {$fuel_type} price from ₱{$old_price} to ₱{$new_price}. Reason: {$reason}";
                $stmt->execute(['FUEL_PRICE_CHANGE', $audit_details, $me['id'], $station_id]);
                
                $_SESSION['success'] = "Fuel price change logged successfully. {$fuel_type} price updated from ₱{$old_price} to ₱{$new_price}.";
                header('Location: fuel_price_change_log.php');
                exit;
                
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error adding price change: ' . $e->getMessage();
                header('Location: fuel_price_change_log.php');
                exit;
            }
            break;
    }
}

// Get fuel types from inventory
$fuel_types = [];
try {
    $stmt = $pdo->prepare("
        SELECT fuel_type, price_per_liter 
        FROM fuel_inventory 
        WHERE station_id = ? 
        ORDER BY fuel_type
    ");
    $stmt->execute([$station_id]);
    $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fuel_types = [];
}

// Get price change history with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$price_changes = [];
$total_changes = 0;

try {
    // Get total count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM fuel_price_log 
        WHERE station_id = ?
    ");
    $stmt->execute([$station_id]);
    $total_changes = $stmt->fetchColumn();
    
    // Get paginated results
    $stmt = $pdo->prepare("
        SELECT 
            id, change_timestamp, fuel_type, old_price, new_price,
            price_difference, changed_by_name, reason_for_change
        FROM fuel_price_log 
        WHERE station_id = ?
        ORDER BY change_timestamp DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$station_id, $per_page, $offset]);
    $price_changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $price_changes = [];
    $total_changes = 0;
}

$total_pages = ceil($total_changes / $per_page);

// Get summary statistics
$summary_stats = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            fuel_type,
            COUNT(*) as change_count,
            MIN(old_price) as lowest_price,
            MAX(new_price) as highest_price,
            AVG(price_difference) as avg_change,
            MAX(change_timestamp) as last_change
        FROM fuel_price_log 
        WHERE station_id = ?
        GROUP BY fuel_type
        ORDER BY change_count DESC
    ");
    $stmt->execute([$station_id]);
    $summary_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $summary_stats = [];
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Fuel Price Change Log</h1>
        <div class="sub">Track and manage fuel price changes with complete audit trail</div>
    </div>
    <div class="header-actions">
        <span class="badge status-active" style="margin-right: 15px;"><i class="fas fa-clock"></i> <?php echo date('g:i A'); ?></span>
        <button class="btn btn-outline" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
        <button class="btn btn-primary" onclick="openAddPriceModal()"><i class="fas fa-plus"></i> Add Price Change</button>
    </div>
</div>

<?php if($msg): ?>
<div class="alert <?php echo strpos($msg, 'Error') !== false ? 'alert-error' : 'alert-success'; ?>">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Summary Statistics -->
<div class="dashboard-card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-chart-line text-info"></i> Price Change Summary</h3>
    </div>
    <div class="card-body">
        <div class="stats-grid">
            <?php foreach ($summary_stats as $stat): ?>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stat['change_count']; ?></div>
                    <div class="stat-label"><?php echo htmlspecialchars($stat['fuel_type']); ?> Changes</div>
                    <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                        Range: ₱<?php echo number_format($stat['lowest_price'], 2); ?> - ₱<?php echo number_format($stat['highest_price'], 2); ?>
                    </div>
                    <div style="font-size: 0.8rem; color: #666;">
                        Avg Change: ₱<?php echo number_format($stat['avg_change'], 2); ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($summary_stats)): ?>
                <div class="stat-card">
                    <div class="stat-value">0</div>
                    <div class="stat-label">Total Changes</div>
                    <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">No price changes recorded yet</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Price Change History -->
<div class="dashboard-card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-history text-warning"></i> Price Change History</h3>
        <div class="filter-controls" style="display: flex; gap: 10px; align-items: center;">
            <select id="fuelTypeFilter" onchange="filterChanges()" style="padding: 5px; border-radius: 4px; border: 1px solid #ddd;">
                <option value="">All Fuel Types</option>
                <?php foreach ($fuel_types as $fuel): ?>
                    <option value="<?php echo htmlspecialchars($fuel['fuel_type']); ?>">
                        <?php echo htmlspecialchars($fuel['fuel_type']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <input type="date" id="dateFilter" onchange="filterChanges()" style="padding: 5px; border-radius: 4px; border: 1px solid #ddd;">
            
            <button onclick="resetFilters()" class="btn btn-sm btn-outline" style="padding: 5px 10px;">Reset</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table" id="priceChangeTable">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Fuel Type</th>
                        <th>Old Price</th>
                        <th>New Price</th>
                        <th>Change</th>
                        <th>Changed By</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($price_changes as $change): ?>
                        <tr data-fuel-type="<?php echo htmlspecialchars($change['fuel_type']); ?>" 
                            data-date="<?php echo date('Y-m-d', strtotime($change['change_timestamp'])); ?>">
                            <td><?php echo date('M j, Y H:i', strtotime($change['change_timestamp'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($change['fuel_type']); ?></strong></td>
                            <td>₱<?php echo number_format($change['old_price'], 2); ?></td>
                            <td>₱<?php echo number_format($change['new_price'], 2); ?></td>
                            <td>
                                <span class="badge <?php echo $change['price_difference'] >= 0 ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $change['price_difference'] >= 0 ? '+' : ''; ?>
                                    ₱<?php echo number_format($change['price_difference'], 2); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($change['changed_by_name']); ?></td>
                            <td><?php echo htmlspecialchars(substr($change['reason_for_change'], 0, 50)); ?><?php echo strlen($change['reason_for_change']) > 50 ? '...' : ''; ?></td>
                            <td>
                                <button class="btn btn-sm" onclick="viewDetails(<?php echo $change['id']; ?>)" 
                                        style="background: #007bff; color: white; padding: 4px 8px; border-radius: 3px;">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($price_changes)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                <i class="fas fa-history" style="font-size: 3rem; color: #ccc; margin-bottom: 10px; display: block;"></i>
                                <p style="color: #666;">No price changes recorded yet.</p>
                                <button class="btn btn-primary" onclick="openAddPriceModal()">Add First Price Change</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination" style="margin-top: 20px; text-align: center;">
                <?php
                $prev_page = $page - 1;
                $next_page = $page + 1;
                ?>
                
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $prev_page; ?>" class="btn btn-outline">&laquo; Previous</a>
                <?php endif; ?>
                
                <span style="margin: 0 15px;">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                    (<?php echo $total_changes; ?> total changes)
                </span>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $next_page; ?>" class="btn btn-outline">Next &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Price Change Modal -->
<div id="addPriceModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <span class="close" onclick="closeModal('addPriceModal')">&times;</span>
        <h2>Add Fuel Price Change</h2>
        <form method="post" action="fuel_price_change_log.php">
            <input type="hidden" name="action" value="add_price_change">
            
            <div class="form-group">
                <label class="form-label">Fuel Type</label>
                <select name="fuel_type" class="form-select" required onchange="updateCurrentPrice()">
                    <option value="">Select Fuel Type</option>
                    <?php foreach ($fuel_types as $fuel): ?>
                        <option value="<?php echo htmlspecialchars($fuel['fuel_type']); ?>" 
                                data-price="<?php echo $fuel['price_per_liter']; ?>">
                            <?php echo htmlspecialchars($fuel['fuel_type']); ?> (Current: ₱<?php echo number_format($fuel['price_per_liter'], 2); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Old Price</label>
                <input type="number" name="old_price" class="form-input" step="0.01" min="0" required readonly>
                <small style="color: #666;">Current price will be auto-filled</small>
            </div>
            
            <div class="form-group">
                <label class="form-label">New Price</label>
                <input type="number" name="new_price" class="form-input" step="0.01" min="0" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Reason for Change</label>
                <textarea name="reason" class="form-textarea" rows="3" required 
                          placeholder="Enter reason for price adjustment..."></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn btn-success" style="padding: 10px 20px;">
                    <i class="fas fa-save"></i> Save Price Change
                </button>
                <button type="button" class="btn btn-secondary" style="padding: 10px 20px;" onclick="closeModal('addPriceModal')">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Details Modal -->
<div id="viewDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <span class="close" onclick="closeModal('viewDetailsModal')">&times;</span>
        <h2>Price Change Details</h2>
        <div id="priceChangeDetails">
            <!-- Details will be loaded here -->
        </div>
        <div style="margin-top: 20px; text-align: right;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('viewDetailsModal')">
                Close
            </button>
        </div>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.stat-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    padding: 20px;
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #003d7a;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 0.9rem;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
}

.badge-success {
    background: #d4edda;
    color: #155724;
}

.badge-danger {
    background: #f8d7da;
    color: #721c24;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 20px;
    border-radius: 8px;
    position: relative;
    max-height: 80vh;
    overflow-y: auto;
}

.close {
    position: absolute;
    right: 20px;
    top: 15px;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.form-group {
    margin-bottom: 15px;
}

.form-label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
}

.alert {
    padding: 12px 16px;
    margin-bottom: 20px;
    border-radius: 6px;
    font-weight: 500;
}

.alert-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
</style>

<script>
function openAddPriceModal() {
    document.getElementById('addPriceModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function updateCurrentPrice() {
    const select = document.querySelector('select[name="fuel_type"]');
    const oldPriceInput = document.querySelector('input[name="old_price"]');
    
    if (select.value && select.options[select.selectedIndex]) {
        const selectedOption = select.options[select.selectedIndex];
        const currentPrice = selectedOption.getAttribute('data-price');
        oldPriceInput.value = currentPrice;
    } else {
        oldPriceInput.value = '';
    }
}

function viewDetails(changeId) {
    // This would typically fetch details via AJAX
    // For now, show a placeholder
    document.getElementById('priceChangeDetails').innerHTML = `
        <p><strong>Loading details for change ID: ${changeId}...</strong></p>
        <p>This would show complete details including IP address, user agent, and full reason.</p>
    `;
    document.getElementById('viewDetailsModal').style.display = 'block';
}

function filterChanges() {
    const fuelType = document.getElementById('fuelTypeFilter').value;
    const date = document.getElementById('dateFilter').value;
    const rows = document.querySelectorAll('#priceChangeTable tbody tr');
    
    rows.forEach(row => {
        let show = true;
        
        if (fuelType && row.getAttribute('data-fuel-type') !== fuelType) {
            show = false;
        }
        
        if (date && row.getAttribute('data-date') !== date) {
            show = false;
        }
        
        row.style.display = show ? '' : 'none';
    });
}

function resetFilters() {
    document.getElementById('fuelTypeFilter').value = '';
    document.getElementById('dateFilter').value = '';
    filterChanges();
}

// Close modals when clicking outside
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
