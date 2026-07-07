<?php
$page_id = 'admin_anomaly_monitoring';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');

// Restrict access to Admin/Owner roles only
if (!in_array($role, ['admin', 'owner', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin/Owner access required for Anomaly Monitoring.';
    header('Location: dashboard.php');
    exit;
}

$station_id = user_station_id();

// Handle anomaly investigation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['anomaly_id'])) {
        $anomaly_id = $_POST['anomaly_id'];
        $action = $_POST['action'];
        $notes = $_POST['notes'] ?? '';
        
        try {
            if ($action === 'investigate') {
                $stmt = $pdo->prepare("
                    UPDATE fuel_variance_reports 
                    SET status = 'investigating', 
                        investigation_notes = CONCAT(IFNULL(investigation_notes, ''), '\n[', NOW(), '] Investigation started by {$me['name']}: ', ?),
                        updated_by = ?
                    WHERE id = ? AND station_id = ?
                ");
                $stmt->execute([$notes, $me['id'], $anomaly_id, $station_id]);
                
                $_SESSION['success'] = 'Anomaly investigation started successfully.';
            } elseif ($action === 'resolve') {
                $stmt = $pdo->prepare("
                    UPDATE fuel_variance_reports 
                    SET status = 'resolved', 
                        investigation_notes = CONCAT(IFNULL(investigation_notes, ''), '\n[', NOW(), '] Resolved by {$me['name']}: ', ?),
                        updated_by = ?
                    WHERE id = ? AND station_id = ?
                ");
                $stmt->execute([$notes, $me['id'], $anomaly_id, $station_id]);
                
                $_SESSION['success'] = 'Anomaly marked as resolved.';
            } elseif ($action === 'add_note') {
                $stmt = $pdo->prepare("
                    UPDATE fuel_variance_reports 
                    SET investigation_notes = CONCAT(IFNULL(investigation_notes, ''), '\n[', NOW(), '] Note by {$me['name']}: ', ?),
                        updated_by = ?
                    WHERE id = ? AND station_id = ?
                ");
                $stmt->execute([$notes, $me['id'], $anomaly_id, $station_id]);
                
                $_SESSION['success'] = 'Investigation note added successfully.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Action failed: ' . $e->getMessage();
        }
        
        header('Location: admin_anomaly_monitoring.php');
        exit;
    }
}

// Get anomaly data
try {
    // Anomaly statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_anomalies,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_anomalies,
            SUM(CASE WHEN status = 'investigating' THEN 1 ELSE 0 END) as investigating_anomalies,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_anomalies,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_anomalies,
            SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_anomalies,
            SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium_anomalies,
            SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low_anomalies,
            AVG(ABS(variance_liters)) as avg_variance,
            MAX(ABS(variance_liters)) as max_variance
        FROM fuel_variance_reports 
        WHERE station_id = ? AND DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([$station_id]);
    $anomaly_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Recent anomalies with pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare("
        SELECT 
            fvr.*,
            u.name as created_by_name,
            u.role as created_by_role,
            updater.name as updated_by_name,
            updater.role as updated_by_role
        FROM fuel_variance_reports fvr
        LEFT JOIN users u ON fvr.created_by = u.id
        LEFT JOIN users updater ON fvr.updated_by = updater.id
        WHERE fvr.station_id = ? AND DATE(fvr.created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY fvr.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$station_id, $limit, $offset]);
    $anomalies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM fuel_variance_reports 
        WHERE station_id = ? AND DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([$station_id]);
    $total_anomalies = $stmt->fetch()['total'];
    $total_pages = ceil($total_anomalies / $limit);

    // Anomaly trends (last 7 days)
    $stmt = $pdo->prepare("
        SELECT 
            DATE(created_at) as anomaly_date,
            COUNT(*) as anomaly_count,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_count,
            SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_count,
            AVG(ABS(variance_liters)) as avg_variance
        FROM fuel_variance_reports 
        WHERE station_id = ? AND DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY anomaly_date DESC
    ");
    $stmt->execute([$station_id]);
    $trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Admin anomaly monitoring error: " . $e->getMessage());
    $anomaly_stats = [
        'total_anomalies' => 0, 'open_anomalies' => 0, 'investigating_anomalies' => 0, 
        'resolved_anomalies' => 0, 'critical_anomalies' => 0, 'high_anomalies' => 0,
        'medium_anomalies' => 0, 'low_anomalies' => 0, 'avg_variance' => 0, 'max_variance' => 0
    ];
    $anomalies = [];
    $total_anomalies = 0;
    $total_pages = 0;
    $trend_data = [];
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Anomaly Monitoring</h1>
        <div class="sub">Monitor and investigate fuel variance anomalies for compliance</div>
    </div>
    <div class="actions">
        <a href="admin_export_center.php" class="btn ghost"><i class="fas fa-download"></i> Export Report</a>
        <a href="dashboard.php" class="btn primary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</div>

<!-- Anomaly Statistics -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
    <div class="card" style="text-align: center; padding: 15px; border-left: 4px solid #dc3545;">
        <div style="font-size: 2rem; font-weight: bold; color: #dc3545;"><?php echo number_format($anomaly_stats['critical_anomalies']); ?></div>
        <div style="color: #666;">Critical Anomalies</div>
        <div style="font-size: 0.8rem; color: #999; margin-top: 5px;">Require immediate attention</div>
    </div>
    
    <div class="card" style="text-align: center; padding: 15px; border-left: 4px solid #ffc107;">
        <div style="font-size: 2rem; font-weight: bold; color: #ffc107;"><?php echo number_format($anomaly_stats['open_anomalies']); ?></div>
        <div style="color: #666;">Open Anomalies</div>
        <div style="font-size: 0.8rem; color: #999; margin-top: 5px;">Awaiting investigation</div>
    </div>
    
    <div class="card" style="text-align: center; padding: 15px; border-left: 4px solid #17a2b8;">
        <div style="font-size: 2rem; font-weight: bold; color: #17a2b8;"><?php echo number_format($anomaly_stats['investigating_anomalies']); ?></div>
        <div style="color: #666;">Investigating</div>
        <div style="font-size: 0.8rem; color: #999; margin-top: 5px;">Under review</div>
    </div>
    
    <div class="card" style="text-align: center; padding: 15px; border-left: 4px solid #28a745;">
        <div style="font-size: 2rem; font-weight: bold; color: #28a745;"><?php echo number_format($anomaly_stats['resolved_anomalies']); ?></div>
        <div style="color: #666;">Resolved</div>
        <div style="font-size: 0.8rem; color: #999; margin-top: 5px;">Investigation complete</div>
    </div>
</div>

<!-- Anomaly Trends -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-chart-line"></i> 7-Day Anomaly Trends</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px;">
            <?php foreach (array_reverse($trend_data) as $trend): ?>
                <div style="text-align: center; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="font-size: 0.8rem; color: #666;"><?php echo date('M d', strtotime($trend['anomaly_date'])); ?></div>
                    <div style="font-size: 1.2rem; font-weight: bold; color: #dc3545;"><?php echo $trend['anomaly_count']; ?></div>
                    <div style="font-size: 0.7rem; color: #999;">avg: <?php echo number_format($trend['avg_variance'], 1); ?>L</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Anomaly List -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-exclamation-triangle"></i> Recent Anomalies (Last 30 Days)</h3>
        <div style="font-size: 0.9rem; color: #666;">
            Showing <?php echo count($anomalies); ?> of <?php echo $total_anomalies; ?> anomalies
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Fuel Type</th>
                        <th>Variance</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($anomalies)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px; color: #666;">
                                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 10px; display: block; color: #28a745;"></i>
                                No anomalies detected in the last 30 days
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($anomalies as $anomaly): ?>
                            <tr>
                                <td>#<?php echo $anomaly['id']; ?></td>
                                <td><?php echo htmlspecialchars($anomaly['fuel_type']); ?></td>
                                <td>
                                    <div style="font-weight: bold; color: <?php echo abs($anomaly['variance_liters']) > 50 ? '#dc3545' : '#ffc107'; ?>;">
                                        <?php echo number_format($anomaly['variance_liters'], 2); ?> L
                                    </div>
                                    <div style="font-size: 0.8rem; color: #666;">
                                        <?php echo number_format($anomaly['variance_percentage'], 1); ?>%
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?php 
                                        echo match($anomaly['severity']) {
                                            'critical' => '#dc3545',
                                            'high' => '#ffc107',
                                            'medium' => '#17a2b8',
                                            'low' => '#6c757d',
                                            default => '#6c757d'
                                        }; ?>; color: white;">
                                        <?php echo ucfirst($anomaly['severity']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?php 
                                        echo match($anomaly['status']) {
                                            'open' => '#dc3545',
                                            'investigating' => '#ffc107',
                                            'resolved' => '#28a745',
                                            default => '#6c757d'
                                        }; ?>; color: white;">
                                        <?php echo ucfirst($anomaly['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, H:i', strtotime($anomaly['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($anomaly['created_by_name'] ?? 'System'); ?></td>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <?php if ($anomaly['status'] === 'open'): ?>
                                            <button onclick="startInvestigation(<?php echo $anomaly['id']; ?>)" class="btn btn-sm btn-primary">
                                                <i class="fas fa-search"></i> Investigate
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($anomaly['status'] === 'investigating'): ?>
                                            <button onclick="addNote(<?php echo $anomaly['id']; ?>)" class="btn btn-sm btn-info">
                                                <i class="fas fa-plus"></i> Add Note
                                            </button>
                                            <button onclick="resolveAnomaly(<?php echo $anomaly['id']; ?>)" class="btn btn-sm btn-success">
                                                <i class="fas fa-check"></i> Resolve
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button onclick="viewDetails(<?php echo $anomaly['id']; ?>)" class="btn btn-sm btn-ghost">
                                            <i class="fas fa-eye"></i> Details
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>" class="btn ghost">Previous</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <a href="?page=<?php echo $i; ?>" class="btn <?php echo $i === $page ? 'primary' : 'ghost'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>" class="btn ghost">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Action Modals -->
<div id="investigateModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Start Investigation</h3>
            <span class="close" onclick="closeModal('investigateModal')">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="investigate">
            <input type="hidden" name="anomaly_id" id="investigateAnomalyId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="lbl">Investigation Notes</label>
                    <textarea name="notes" class="inp" rows="4" placeholder="Describe initial investigation findings..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('investigateModal')" class="btn ghost">Cancel</button>
                <button type="submit" class="btn primary">Start Investigation</button>
            </div>
        </form>
    </div>
</div>

<div id="noteModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Investigation Note</h3>
            <span class="close" onclick="closeModal('noteModal')">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_note">
            <input type="hidden" name="anomaly_id" id="noteAnomalyId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="lbl">Note</label>
                    <textarea name="notes" class="inp" rows="4" placeholder="Add investigation note..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('noteModal')" class="btn ghost">Cancel</button>
                <button type="submit" class="btn primary">Add Note</button>
            </div>
        </form>
    </div>
</div>

<div id="resolveModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Resolve Anomaly</h3>
            <span class="close" onclick="closeModal('resolveModal')">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="anomaly_id" id="resolveAnomalyId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="lbl">Resolution Notes</label>
                    <textarea name="notes" class="inp" rows="4" placeholder="Describe resolution details..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('resolveModal')" class="btn ghost">Cancel</button>
                <button type="submit" class="btn success">Resolve Anomaly</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background-color: white;
    padding: 0;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    color: #333;
}

.close {
    font-size: 28px;
    font-weight: bold;
    color: #aaa;
    cursor: pointer;
}

.close:hover {
    color: #000;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 20px;
    border-top: 1px solid #ddd;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.form-group {
    margin-bottom: 15px;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 0.8rem;
}
</style>

<script>
function startInvestigation(anomalyId) {
    document.getElementById('investigateAnomalyId').value = anomalyId;
    document.getElementById('investigateModal').style.display = 'flex';
}

function addNote(anomalyId) {
    document.getElementById('noteAnomalyId').value = anomalyId;
    document.getElementById('noteModal').style.display = 'flex';
}

function resolveAnomaly(anomalyId) {
    document.getElementById('resolveAnomalyId').value = anomalyId;
    document.getElementById('resolveModal').style.display = 'flex';
}

function viewDetails(anomalyId) {
    // This would open a detailed view - for now, just show an alert
    alert('Detailed view would show full anomaly information, investigation history, and related audit logs.');
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
