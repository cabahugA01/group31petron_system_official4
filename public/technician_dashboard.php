<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$u = current_user();
$role = role_key($u['role'] ?? 'staff');
$station_id = user_station_id();

// Only technicians can access this page
if ($role !== 'technician') {
    header('Location: dashboard.php');
    exit;
}

// Get technician info
$technician = null;
try {
    $stmt = $pdo->prepare("
        SELECT t.*, u.name as user_name, u.username
        FROM technicians t
        LEFT JOIN users u ON u.user_id = t.user_id
        WHERE t.station_id = ? AND (t.user_id = ? OR u.username = ?)
        LIMIT 1
    ");
    $stmt->execute([$station_id, $u['id'], $u['username']]);
    $technician = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback if technicians table doesn't exist yet
    $technician = [
        'id' => $u['id'],
        'full_name' => $u['name'],
        'specialization' => 'General Technician',
        'status' => 'active'
    ];
}

// Get assigned jobs
$assigned_jobs = [];
try {
    $stmt = $pdo->prepare("
        SELECT jo.*, 
               c.name as customer_name,
               c.phone as customer_phone,
               sc.name as service_name,
               sc.fixed_labor_rate,
               sc.estimated_time_minutes,
               u.name as assigned_by_name,
               TIMESTAMPDIFF(MINUTE, jo.started_at, NOW()) as elapsed_minutes
        FROM job_orders jo
        LEFT JOIN customers c ON c.id = jo.customer_id
        LEFT JOIN service_categories sc ON sc.id = jo.service_category_id
        LEFT JOIN users u ON u.user_id = jo.assigned_by
        WHERE jo.station_id = ? 
          AND (jo.assigned_technician_id = ? OR jo.assigned_mechanic_id = ?)
          AND jo.status IN ('In Progress', 'Approved')
        ORDER BY jo.created_at DESC
    ");
    $stmt->execute([$station_id, $technician['id'], $technician['id']]);
    $assigned_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $assigned_jobs = [];
}

// Get completed jobs today
$completed_today = [];
try {
    $stmt = $pdo->prepare("
        SELECT jo.*, 
               c.name as customer_name,
               sc.name as service_name,
               sc.fixed_labor_rate
        FROM job_orders jo
        LEFT JOIN customers c ON c.id = jo.customer_id
        LEFT JOIN service_categories sc ON sc.id = jo.service_category_id
        WHERE jo.station_id = ? 
          AND (jo.assigned_technician_id = ? OR jo.assigned_mechanic_id = ?)
          AND jo.status = 'Completed'
          AND DATE(jo.completed_at) = CURDATE()
        ORDER BY jo.completed_at DESC
    ");
    $stmt->execute([$station_id, $technician['id'], $technician['id']]);
    $completed_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $completed_today = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.technician-dashboard {
    padding: 20px;
    background: var(--bg);
    min-height: calc(100vh - 110px);
}

.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px;
    background: var(--card);
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.technician-info h2 {
    margin: 0;
    color: var(--text);
    font-size: 24px;
}

.technician-info .specialization {
    color: var(--muted);
    font-size: 14px;
    margin-top: 5px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--card);
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    text-align: center;
}

.stat-number {
    font-size: 36px;
    font-weight: 700;
    color: var(--blue);
    margin-bottom: 8px;
}

.stat-label {
    color: var(--muted);
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.jobs-section {
    background: var(--card);
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.section-title {
    font-size: 20px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.job-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    transition: all 0.3s ease;
}

.job-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.job-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.job-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 5px;
}

.job-meta {
    font-size: 12px;
    color: var(--muted);
}

.job-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

.job-detail {
    font-size: 14px;
}

.job-detail-label {
    font-weight: 600;
    color: var(--muted);
    display: block;
    margin-bottom: 3px;
}

.job-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.btn-primary {
    background: var(--blue);
    color: white;
}

.btn-primary:hover {
    background: #003366;
}

.btn-success {
    background: #28A745;
    color: white;
}

.btn-success:hover {
    background: #218838;
}

.btn-warning {
    background: #FFC107;
    color: #212529;
}

.btn-warning:hover {
    background: #E0A800;
}

.btn-secondary {
    background: var(--muted);
    color: var(--text);
}

.btn-secondary:hover {
    background: #6c757d;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-in-progress {
    background: #D1ECF1;
    color: #0C5460;
}

.status-approved {
    background: #D4EDDA;
    color: #155724;
}

.status-completed {
    background: #D1ECF1;
    color: #0C5460;
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: var(--muted);
}

.empty-state-icon {
    font-size: 48px;
    margin-bottom: 15px;
    opacity: 0.5;
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
    background-color: var(--card);
    margin: 5% auto;
    padding: 0;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--muted);
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 20px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 8px;
}

.form-input, .form-textarea, .form-select {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 14px;
    background: var(--bg);
    color: var(--text);
}

.form-textarea {
    resize: vertical;
    min-height: 80px;
}

.parts-list {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 15px;
}

.part-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px;
    border-bottom: 1px solid var(--line);
}

.part-item:last-child {
    border-bottom: none;
}

.part-name {
    font-weight: 500;
}

.part-cost {
    color: var(--blue);
    font-weight: 600;
}
</style>

<div class="technician-dashboard">
    <div class="dashboard-header">
        <div class="technician-info">
            <h2>🔧 Technician Dashboard</h2>
            <div class="specialization">
                <?php echo htmlspecialchars($technician['specialization'] ?? 'General Technician'); ?>
            </div>
        </div>
        <div>
            <span class="status-badge status-<?php echo $technician['status'] ?? 'active'; ?>">
                <?php echo ucfirst($technician['status'] ?? 'active'); ?>
            </span>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo count($assigned_jobs); ?></div>
            <div class="stat-label">Active Jobs</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo count($completed_today); ?></div>
            <div class="stat-label">Completed Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">
                <?php 
                $total_earnings = array_sum(array_column($completed_today, 'fixed_labor_rate'));
                echo '₱' . number_format($total_earnings, 2);
                ?>
            </div>
            <div class="stat-label">Today's Earnings</div>
        </div>
    </div>

    <div class="jobs-section">
        <h3 class="section-title">
            🚗 Assigned Jobs
        </h3>
        
        <?php if (empty($assigned_jobs)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <div class="empty-state-title">No assigned jobs</div>
                <div class="empty-state-text">You don't have any active jobs assigned to you.</div>
            </div>
        <?php else: ?>
            <?php foreach ($assigned_jobs as $job): ?>
                <div class="job-card">
                    <div class="job-header">
                        <div>
                            <div class="job-title"><?php echo htmlspecialchars($job['job_order_number']); ?></div>
                            <div class="job-meta">
                                Customer: <?php echo htmlspecialchars($job['customer_name'] ?? 'Walk-in'); ?>
                                • 
                                <?php echo htmlspecialchars($job['service_name']); ?>
                            </div>
                        </div>
                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $job['status'])); ?>">
                            <?php echo htmlspecialchars($job['status']); ?>
                        </span>
                    </div>
                    
                    <div class="job-details">
                        <div class="job-detail">
                            <span class="job-detail-label">Vehicle:</span>
                            <?php echo htmlspecialchars($job['vehicle_plate'] ?? 'N/A'); ?>
                        </div>
                        <div class="job-detail">
                            <span class="job-detail-label">Fixed Rate:</span>
                            ₱<?php echo number_format($job['fixed_labor_rate'] ?? 0, 2); ?>
                        </div>
                        <div class="job-detail">
                            <span class="job-detail-label">Est. Time:</span>
                            <?php echo $job['estimated_time_minutes'] ?? 60; ?> mins
                        </div>
                        <?php if ($job['elapsed_minutes']): ?>
                        <div class="job-detail">
                            <span class="job-detail-label">Elapsed:</span>
                            <?php echo $job['elapsed_minutes']; ?> mins
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="job-actions">
                        <?php if ($job['status'] === 'Approved'): ?>
                            <button class="btn btn-primary" onclick="startJob(<?php echo $job['id']; ?>)">
                                ▶️ Start Job
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($job['status'] === 'In Progress'): ?>
                            <button class="btn btn-warning" onclick="logParts(<?php echo $job['id']; ?>)">
                                📦 Log Parts
                            </button>
                            <button class="btn btn-secondary" onclick="addWorkNote(<?php echo $job['id']; ?>)">
                                📝 Add Note
                            </button>
                            <button class="btn btn-success" onclick="completeJob(<?php echo $job['id']; ?>)">
                                ✅ Complete Job
                            </button>
                        <?php endif; ?>
                        
                        <button class="btn btn-secondary" onclick="viewJobDetails(<?php echo $job['id']; ?>)">
                            👁️ View Details
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="jobs-section" style="margin-top: 30px;">
        <h3 class="section-title">
            ✅ Completed Today
        </h3>
        
        <?php if (empty($completed_today)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🎯</div>
                <div class="empty-state-title">No completed jobs today</div>
                <div class="empty-state-text">Complete your first job for today!</div>
            </div>
        <?php else: ?>
            <?php foreach ($completed_today as $job): ?>
                <div class="job-card">
                    <div class="job-header">
                        <div>
                            <div class="job-title"><?php echo htmlspecialchars($job['job_order_number']); ?></div>
                            <div class="job-meta">
                                Customer: <?php echo htmlspecialchars($job['customer_name'] ?? 'Walk-in'); ?>
                            </div>
                        </div>
                        <span class="status-badge status-completed">Completed</span>
                    </div>
                    
                    <div class="job-details">
                        <div class="job-detail">
                            <span class="job-detail-label">Service:</span>
                            <?php echo htmlspecialchars($job['service_name']); ?>
                        </div>
                        <div class="job-detail">
                            <span class="job-detail-label">Fixed Rate:</span>
                            ₱<?php echo number_format($job['fixed_labor_rate'] ?? 0, 2); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Log Parts Modal -->
<div id="logPartsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">📦 Log Parts Used</h3>
            <button class="modal-close" onclick="closeModal('logPartsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="logPartsForm">
                <input type="hidden" id="logPartsJobId" name="job_id">
                
                <div class="form-group">
                    <label class="form-label">Search Parts from Inventory</label>
                    <input type="text" class="form-input" id="partsSearch" placeholder="Type part name to search..." oninput="searchParts()">
                    <div id="partsSuggestions" class="parts-list" style="display: none;"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Selected Parts</label>
                    <div id="selectedParts" class="parts-list">
                        <div class="empty-state">No parts selected yet</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Work Notes</label>
                    <textarea class="form-textarea" name="work_notes" placeholder="Describe work done and any issues found..."></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('logPartsModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="savePartsUsed()">Save Parts Used</button>
        </div>
    </div>
</div>

<!-- Work Note Modal -->
<div id="workNoteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">📝 Add Work Note</h3>
            <button class="modal-close" onclick="closeModal('workNoteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="workNoteForm">
                <input type="hidden" id="workNoteJobId" name="job_id">
                
                <div class="form-group">
                    <label class="form-label">Work Note</label>
                    <textarea class="form-textarea" name="work_note" placeholder="Describe progress, issues, or customer communications..." required></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('workNoteModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveWorkNote()">Save Note</button>
        </div>
    </div>
</div>

<script>
let selectedParts = [];
let currentJobId = null;

function startJob(jobId) {
    if (confirm('Start working on this job?')) {
        fetch('job_order_operations.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=start_job_order&job_id=${jobId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Job started successfully!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}

function logParts(jobId) {
    currentJobId = jobId;
    document.getElementById('logPartsJobId').value = jobId;
    selectedParts = [];
    updateSelectedParts();
    document.getElementById('logPartsModal').style.display = 'block';
}

function addWorkNote(jobId) {
    currentJobId = jobId;
    document.getElementById('workNoteJobId').value = jobId;
    document.getElementById('workNoteModal').style.display = 'block';
}

function completeJob(jobId) {
    if (confirm('Complete this job? Make sure all parts used have been logged.')) {
        fetch('job_order_operations.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=complete_job_order&job_id=${jobId}&parts_used=${JSON.stringify(selectedParts)}&actual_labor_hours=0`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Job completed successfully!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}

function searchParts() {
    const query = document.getElementById('partsSearch').value;
    if (query.length < 2) {
        document.getElementById('partsSuggestions').style.display = 'none';
        return;
    }
    
    fetch(`../backend/get_inventory_for_service.php?search=${encodeURIComponent(query)}`)
    .then(response => response.json())
    .then(data => {
        const suggestions = document.getElementById('partsSuggestions');
        if (data.success && data.data.length > 0) {
            suggestions.innerHTML = data.data.map(part => `
                <div class="part-item" onclick="selectPart(${part.id}, '${part.name}', ${part.price}, ${part.stock_level})">
                    <div>
                        <div class="part-name">${part.name}</div>
                        <small>Stock: ${part.stock_level}</small>
                    </div>
                    <div class="part-cost">₱${part.price}</div>
                </div>
            `).join('');
            suggestions.style.display = 'block';
        } else {
            suggestions.innerHTML = '<div class="empty-state">No parts found</div>';
            suggestions.style.display = 'block';
        }
    });
}

function selectPart(id, name, price, stock) {
    const existingPart = selectedParts.find(p => p.product_id === id);
    if (existingPart) {
        existingPart.quantity++;
    } else {
        selectedParts.push({
            product_id: id,
            part_name: name,
            unit_cost: price,
            quantity: 1
        });
    }
    updateSelectedParts();
    document.getElementById('partsSearch').value = '';
    document.getElementById('partsSuggestions').style.display = 'none';
}

function updateSelectedParts() {
    const container = document.getElementById('selectedParts');
    if (selectedParts.length === 0) {
        container.innerHTML = '<div class="empty-state">No parts selected yet</div>';
    } else {
        container.innerHTML = selectedParts.map((part, index) => `
            <div class="part-item">
                <div>
                    <div class="part-name">${part.part_name}</div>
                    <small>₱${part.unit_cost} x ${part.quantity}</small>
                </div>
                <div>
                    <button type="button" class="btn btn-secondary" onclick="removePart(${index})" style="padding: 4px 8px; font-size: 12px;">Remove</button>
                </div>
            </div>
        `).join('');
    }
}

function removePart(index) {
    selectedParts.splice(index, 1);
    updateSelectedParts();
}

function savePartsUsed() {
    const workNotes = document.querySelector('#logPartsForm textarea[name="work_notes"]').value;
    
    fetch('job_order_operations.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=confirm_parts_used&job_id=${currentJobId}&parts_used=${JSON.stringify(selectedParts)}&notes=${encodeURIComponent(workNotes)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Parts logged successfully!');
            closeModal('logPartsModal');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function saveWorkNote() {
    const workNote = document.querySelector('#workNoteForm textarea[name="work_note"]').value;
    
    fetch('job_order_operations.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update_job_status&job_id=${currentJobId}&new_status=In Progress&notes=${encodeURIComponent(workNote)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Work note added successfully!');
            closeModal('workNoteModal');
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function viewJobDetails(jobId) {
    window.open(`job_order_detail.php?id=${jobId}`, '_blank');
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
