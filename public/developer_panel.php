<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header("Location: super_admin_dashboard.php");
exit;

$page_id = 'developer_panel';
require_once __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Developer Panel</h1>
        <div class="sub">System Administration & Development Tools</div>
    </div>
</div>

<style>
.developer-panel {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 24px;
    margin-bottom: 30px;
}

.dev-card {
    background: var(--card);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border: 1px solid var(--border);
    transition: all 0.3s ease;
}

.dev-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.dev-card h3 {
    color: var(--petron-blue);
    margin-bottom: 16px;
    font-size: 18px;
    font-weight: 600;
}

.dev-card p {
    color: var(--muted);
    margin-bottom: 20px;
    line-height: 1.5;
}

.dev-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.btn-danger {
    background: var(--petron-red);
    color: white;
}

.btn-danger:hover {
    background: #c41e3a;
}

.btn-warning {
    background: #f59e0b;
    color: #000;
}

.btn-warning:hover {
    background: #d97706;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover {
    background: #059669;
}

.log-viewer {
    background: #1e1e1e;
    color: #fff;
    padding: 20px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    max-height: 400px;
    overflow-y: auto;
    margin-top: 16px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
}

.data-table th,
.data-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
}

.data-table th {
    background: var(--petron-blue);
    color: white;
    font-weight: 600;
}

.data-table tr:hover {
    background: rgba(0, 47, 108, 0.05);
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-content {
    background: var(--card);
    border-radius: 12px;
    padding: 32px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.modal-title {
    font-size: 20px;
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
    margin-bottom: 24px;
}

.modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text);
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--petron-blue);
    box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
}

.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: #10b981;
    color: white;
}

.alert-danger {
    background: var(--petron-red);
    color: white;
}

.alert-warning {
    background: #f59e0b;
    color: #000;
}
</style>

<div class="developer-panel">
    <!-- System Logs -->
    <div class="dev-card">
        <h3><i class="fas fa-file-alt"></i> System Logs</h3>
        <p>View and manage system logs, error reports, and audit trails.</p>
        <div class="dev-actions">
            <button class="btn" onclick="viewSystemLogs()"><i class="fas fa-eye"></i> View Logs</button>
            <button class="btn btn-danger" onclick="clearSystemLogs()"><i class="fas fa-trash"></i> Clear Logs</button>
            <button class="btn btn-success" onclick="downloadLogs()"><i class="fas fa-download"></i> Download</button>
        </div>
    </div>

    <!-- Database Management -->
    <div class="dev-card">
        <h3><i class="fas fa-database"></i> Database Management</h3>
        <p>Manage database operations, backup data, and perform maintenance tasks.</p>
        <div class="dev-actions">
            <button class="btn" onclick="viewDatabaseStatus()"><i class="fas fa-info-circle"></i> Status</button>
            <button class="btn btn-warning" onclick="backupDatabase()"><i class="fas fa-save"></i> Backup</button>
            <button class="btn btn-danger" onclick="resetDatabase()"><i class="fas fa-exclamation-triangle"></i> Reset Data</button>
        </div>
    </div>

    <!-- User Administration -->
    <div class="dev-card">
        <h3><i class="fas fa-users-cog"></i> User Administration</h3>
        <p>Manage users, roles, permissions, and access control across all stations.</p>
        <div class="dev-actions">
            <button class="btn" onclick="viewAllUsers()"><i class="fas fa-users"></i> All Users</button>
            <button class="btn btn-success" onclick="showAddUserModal()"><i class="fas fa-plus"></i> Add User</button>
            <button class="btn btn-warning" onclick="managePermissions()"><i class="fas fa-key"></i> Permissions</button>
        </div>
    </div>

    <!-- Station Management -->
    <div class="dev-card">
        <h3><i class="fas fa-gas-pump"></i> Station Management</h3>
        <p>Manage station settings, configurations, and operational parameters.</p>
        <div class="dev-actions">
            <button class="btn" onclick="viewAllStations()"><i class="fas fa-store"></i> All Stations</button>
            <button class="btn btn-success" onclick="showAddStationModal()"><i class="fas fa-plus"></i> Add Station</button>
            <button class="btn btn-warning" onclick="resetStationData()"><i class="fas fa-sync"></i> Reset Station</button>
        </div>
    </div>

    <!-- Data Operations -->
    <div class="dev-card">
        <h3><i class="fas fa-tools"></i> Data Operations</h3>
        <p>Perform advanced data operations, cleanup, and system maintenance.</p>
        <div class="dev-actions">
            <button class="btn" onclick="viewDataStats()"><i class="fas fa-chart-bar"></i> Statistics</button>
            <button class="btn btn-warning" onclick="cleanupData()"><i class="fas fa-broom"></i> Cleanup</button>
            <button class="btn btn-danger" onclick="deleteAllData()"><i class="fas fa-trash-alt"></i> Delete All Data</button>
        </div>
    </div>

    <!-- System Configuration -->
    <div class="dev-card">
        <h3><i class="fas fa-cog"></i> System Configuration</h3>
        <p>Configure system settings, environment variables, and operational parameters.</p>
        <div class="dev-actions">
            <button class="btn" onclick="viewSystemConfig()"><i class="fas fa-cogs"></i> Configuration</button>
            <button class="btn btn-warning" onclick="resetConfig()"><i class="fas fa-undo"></i> Reset Config</button>
            <button class="btn btn-success" onclick="exportConfig()"><i class="fas fa-file-export"></i> Export Config</button>
        </div>
    </div>
</div>

<!-- System Logs Modal -->
<div id="logsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">System Logs</h3>
            <button class="modal-close" onclick="closeModal('logsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="log-viewer" id="logContent">
                <!-- Logs will be loaded here -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('logsModal')">Close</button>
            <button class="btn btn-danger" onclick="clearSystemLogs()">Clear All Logs</button>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Add New User</h3>
            <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addUserForm">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="dev_role_add" required>
                        <option value="">Select role</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Station</label>
                    <select name="station_id" id="dev_station_select" required>
                        <option value="">Select Station</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password_hash" required>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
            <button type="submit" class="btn btn-success" onclick="addUser()">Add User</button>
        </div>
    </div>
</div>

<!-- Add Station Modal -->
<div id="addStationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Add New Station</h3>
            <button class="modal-close" onclick="closeModal('addStationModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addStationForm">
                <div class="form-group">
                    <label>Station Name</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" required>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <textarea name="address" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="tel" name="contact_number">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('addStationModal')">Cancel</button>
            <button type="submit" class="btn btn-success" onclick="addStation()">Add Station</button>
        </div>
    </div>
</div>

<script>
// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// System Logs Functions
function viewSystemLogs() {
    openModal('logsModal');
    loadSystemLogs();
}

function loadSystemLogs() {
    const logContent = document.getElementById('logContent');
    logContent.innerHTML = 'Loading system logs...';
    
    fetch('../backend/api/developer_operations.php?action=get_system_logs&limit=200')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            if (result.data && result.data.length > 0) {
                const logs = result.data.map(log => {
                    const time = log.created_at || 'N/A';
                    const type = log.action_type || 'INFO';
                    const user = log.user_name || 'System';
                    const desc = log.description || '';
                    return `[${time}] [${type}] [${user}] ${desc}`;
                });
                logContent.innerHTML = logs.join('\n');
            } else {
                logContent.innerHTML = 'No logs found. ' + (result.message || '');
            }
        } else {
            logContent.innerHTML = 'Error loading logs: ' + (result.error || 'Unknown error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        logContent.innerHTML = 'Error loading logs: ' + error.message;
    });
}

function clearSystemLogs() {
    const days = prompt('Clear logs older than how many days? (Enter 0 to clear all)', '30');
    if (days === null) return; // User cancelled
    
    if (confirm(`Are you sure you want to clear logs older than ${days} days? This action cannot be undone.`)) {
        showToast('Clearing system logs...', 'info');
        
        const formData = new FormData();
        formData.append('action', 'clear_system_logs');
        formData.append('older_than_days', days);
        
        fetch('../backend/api/developer_operations.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showToast(result.message || 'System logs cleared', 'success');
                loadSystemLogs(); // Reload logs
            } else {
                showToast(result.error || 'Failed to clear logs', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error clearing logs: ' + error.message, 'error');
        });
    }
}

function downloadLogs() {
    showToast('Preparing logs for download...', 'info');
    
    fetch('../backend/api/developer_operations.php?action=get_system_logs&limit=10000')
    .then(response => response.json())
    .then(result => {
        if (result.success && result.data) {
            // Convert logs to CSV format
            let csv = 'Timestamp,Type,User,Description,IP Address\n';
            result.data.forEach(log => {
                const time = log.created_at || '';
                const type = log.action_type || '';
                const user = log.user_name || '';
                const desc = (log.description || '').replace(/"/g, '""');
                const ip = log.ip_address || '';
                csv += `"${time}","${type}","${user}","${desc}","${ip}"\n`;
            });
            
            // Create download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'system_logs_' + new Date().toISOString().slice(0,10) + '.csv';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            a.remove();
            
            showToast('Logs downloaded successfully', 'success');
        } else {
            showToast(result.error || 'Failed to download logs', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error downloading logs: ' + error.message, 'error');
    });
}

// Database Management Functions
function viewDatabaseStatus() {
    showToast('Retrieving database status...', 'info');
    
    fetch('../backend/api/developer_operations.php?action=get_database_status')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            const data = result.data;
            let statusHtml = '<div class="alert alert-success" style="text-align: left;">';
            statusHtml += `<strong>Database:</strong> ${data.database}<br>`;
            statusHtml += `<strong>MySQL Version:</strong> ${data.mysql_version}<br>`;
            statusHtml += `<strong>Database Size:</strong> ${data.size_mb} MB<br>`;
            statusHtml += `<strong>Last Backup:</strong> ${data.last_backup}<br>`;
            statusHtml += `<strong>Backup Count:</strong> ${data.backup_count}<br><br>`;
            statusHtml += '<strong>Table Records:</strong><br>';
            statusHtml += '<ul style="margin: 5px 0; padding-left: 20px;">';
            for (const [table, info] of Object.entries(data.tables)) {
                const status = info.status === 'ok' ? '✅' : '⚠️';
                statusHtml += `<li>${table}: ${info.count} records ${status}</li>`;
            }
            statusHtml += '</ul></div>';
            
            // Create a temporary modal to show status
            const tempModal = document.createElement('div');
            tempModal.className = 'modal';
            tempModal.style.display = 'flex';
            tempModal.innerHTML = `
                <div class="modal-content" style="max-width: 600px;">
                    <div class="modal-header">
                        <h3 class="modal-title">Database Status</h3>
                        <button class="modal-close" onclick="this.parentElement.parentElement.parentElement.remove()">&times;</button>
                    </div>
                    <div class="modal-body">
                        ${statusHtml}
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" onclick="this.parentElement.parentElement.parentElement.remove()">Close</button>
                    </div>
                </div>
            `;
            document.body.appendChild(tempModal);
            showToast('Database status retrieved', 'success');
        } else {
            showToast(result.error || 'Failed to get database status', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error getting database status: ' + error.message, 'error');
    });
}

function backupDatabase() {
    if (confirm('Are you sure you want to backup the database? This may take a few moments.')) {
        showToast('Starting database backup...', 'info');
        
        const formData = new FormData();
        formData.append('action', 'backup_database');
        
        fetch('../backend/api/developer_operations.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showToast(result.message + ' (' + result.size_kb + ' KB)', 'success');
            } else {
                showToast(result.error || 'Backup failed', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error during backup: ' + error.message, 'error');
        });
    }
}

function resetDatabase() {
    showToast('Database reset is disabled for safety. Use individual reset functions instead.', 'warning');
}

// User Administration Functions
function viewAllUsers() {
    window.location.href = 'view_all_users.php';
}

function showAddUserModal() {
    openModal('addUserModal');
    loadStationsForUser();
}

function loadStationsForUser() {
    DataHelper.populateStations('dev_station_select', 'Select Station')
        .then(() => console.log('Stations loaded'))
        .catch(error => {
            console.error('Failed to load stations:', error);
            showToast('Failed to load stations. Please refresh.', 'error');
        });
}

function addUser() {
    const form = document.getElementById('addUserForm');
    const formData = new FormData(form);
    formData.append('action', 'create_user');
    
    showToast('Adding user...', 'info');
    
    fetch('../backend/api/developer_operations.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast(result.message || 'User added successfully', 'success');
            closeModal('addUserModal');
            form.reset();
        } else {
            showToast(result.error || 'Failed to add user', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error adding user: ' + error.message, 'error');
    });
}

function managePermissions() {
    window.location.href = 'rbac.php';
}

// Station Management Functions
function viewAllStations() {
    window.location.href = 'view_stations.php';
}

function showAddStationModal() {
    openModal('addStationModal');
}

function addStation() {
    const form = document.getElementById('addStationForm');
    const formData = new FormData(form);
    formData.append('action', 'add');
    
    showToast('Adding station...', 'info');
    
    fetch('../backend/api/stations.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast(result.message || 'Station added successfully', 'success');
            closeModal('addStationModal');
            form.reset();
        } else {
            showToast(result.error || 'Failed to add station', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error adding station: ' + error.message, 'error');
    });
}

function resetStationData() {
    const stationId = prompt('Enter Station ID to reset:');
    if (stationId) {
        if (confirm(`Reset all data for station ${stationId}? This action cannot be undone.`)) {
            showToast('Resetting station data...', 'warning');
            
            const formData = new FormData();
            formData.append('action', 'reset_station_data');
            formData.append('station_id', stationId);
            
            fetch('../backend/api/developer_operations.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Station data reset successfully', 'success');
                } else {
                    showToast(result.error || 'Failed to reset station data', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error resetting station: ' + error.message, 'error');
            });
        }
    }
}

// Data Operations Functions
function viewDataStats() {
    showToast('Retrieving data statistics...', 'info');
    
    fetch('../backend/api/developer_operations.php?action=get_data_stats')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            const stats = result.data;
            let statsHtml = '<div class="alert alert-success" style="text-align: left;">';
            
            statsHtml += '<strong>Users:</strong><br>';
            statsHtml += `&nbsp;&nbsp;Total: ${stats.users.total}<br>`;
            statsHtml += `&nbsp;&nbsp;Active: ${stats.users.active}<br>`;
            if (Object.keys(stats.users.by_role).length > 0) {
                statsHtml += '&nbsp;&nbsp;By Role: ';
                statsHtml += Object.entries(stats.users.by_role).map(([role, count]) => `${role}: ${count}`).join(', ');
                statsHtml += '<br>';
            }
            
            statsHtml += '<strong>Stations:</strong><br>';
            statsHtml += `&nbsp;&nbsp;Total: ${stats.stations.total}, Active: ${stats.stations.active}<br>`;
            
            statsHtml += '<strong>Products:</strong><br>';
            statsHtml += `&nbsp;&nbsp;Total: ${stats.products.total}<br>`;
            
            statsHtml += '<strong>Inventory:</strong><br>';
            statsHtml += `&nbsp;&nbsp;Total Items: ${stats.inventory.total}, Low Stock: ${stats.inventory.low_stock}<br>`;
            
            statsHtml += '<strong>Sales:</strong><br>';
            statsHtml += `&nbsp;&nbsp;Total: ${stats.sales.total}, Today: ${stats.sales.today}, This Month: ${stats.sales.this_month}<br>`;
            
            statsHtml += '<strong>Job Orders:</strong><br>';
            statsHtml += `&nbsp;&nbsp;Total: ${stats.job_orders.total}, Pending: ${stats.job_orders.pending}, Completed: ${stats.job_orders.completed}<br>`;
            
            statsHtml += '<strong>Fuel:</strong><br>';
            statsHtml += `&nbsp;&nbsp;Types: ${stats.fuel.types}, Pumps: ${stats.fuel.pumps}<br>`;
            
            statsHtml += '<strong>Activity Logs:</strong><br>';
            statsHtml += `&nbsp;&nbsp;Total: ${stats.activity_logs.total}<br>`;
            
            statsHtml += '</div>';
            
            // Create a temporary modal to show stats
            const tempModal = document.createElement('div');
            tempModal.className = 'modal';
            tempModal.style.display = 'flex';
            tempModal.innerHTML = `
                <div class="modal-content" style="max-width: 600px;">
                    <div class="modal-header">
                        <h3 class="modal-title">Data Statistics</h3>
                        <button class="modal-close" onclick="this.parentElement.parentElement.parentElement.remove()">&times;</button>
                    </div>
                    <div class="modal-body">
                        ${statsHtml}
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" onclick="this.parentElement.parentElement.parentElement.remove()">Close</button>
                    </div>
                </div>
            `;
            document.body.appendChild(tempModal);
            showToast('Data statistics retrieved', 'success');
        } else {
            showToast(result.error || 'Failed to get statistics', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error getting statistics: ' + error.message, 'error');
    });
}

function cleanupData() {
    const days = prompt('Remove data older than how many days?', '90');
    if (days === null) return; // User cancelled
    
    const dryRun = confirm('Run in DRY RUN mode first? (Recommended - will show what would be deleted without actually deleting)');
    
    if (confirm(`Are you sure you want to cleanup data older than ${days} days? ${dryRun ? '(DRY RUN - no data will be deleted)' : 'This action cannot be undone.'}`)) {
        showToast('Starting data cleanup...', 'info');
        
        const formData = new FormData();
        formData.append('action', 'cleanup_old_data');
        formData.append('days', days);
        formData.append('dry_run', dryRun ? 'true' : 'false');
        
        fetch('../backend/api/developer_operations.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                const data = result.data;
                let message = dryRun ? 'Dry run complete. ' : 'Cleanup complete. ';
                
                if (Object.keys(data.tables_cleaned).length > 0) {
                    for (const [table, info] of Object.entries(data.tables_cleaned)) {
                        if (info.error) {
                            message += `${table}: Error - ${info.error}. `;
                        } else if (dryRun) {
                            message += `${table}: ${info.records_found} records would be deleted. `;
                        } else {
                            message += `${table}: ${info.records_deleted} records deleted. `;
                        }
                    }
                } else {
                    message += 'No records found to clean.';
                }
                
                showToast(message, dryRun ? 'info' : 'success');
            } else {
                showToast(result.error || 'Cleanup failed', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error during cleanup: ' + error.message, 'error');
        });
    }
}

function deleteAllData() {
    showToast('Delete All Data is disabled for safety. Use individual cleanup functions or database reset.', 'warning');
}

// System Configuration Functions
function viewSystemConfig() {
    showToast('Retrieving system configuration...', 'info');
    
    fetch('../backend/api/developer_operations.php?action=get_system_config')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            const config = result.data;
            let configHtml = '<div class="alert alert-success" style="text-align: left;">';
            
            configHtml += '<strong>System Information:</strong><br>';
            configHtml += `&nbsp;&nbsp;System Version: ${config.system_version}<br>`;
            configHtml += `&nbsp;&nbsp;Environment: ${config.environment}<br>`;
            configHtml += `&nbsp;&nbsp;Timezone: ${config.timezone}<br>`;
            configHtml += `&nbsp;&nbsp;Current Time: ${config.current_time}<br><br>`;
            
            configHtml += '<strong>Software Versions:</strong><br>';
            configHtml += `&nbsp;&nbsp;PHP Version: ${config.php_version}<br>`;
            configHtml += `&nbsp;&nbsp;MySQL Version: ${config.mysql_version}<br>`;
            configHtml += `&nbsp;&nbsp;Server Software: ${config.server_software}<br><br>`;
            
            configHtml += '<strong>PHP Settings:</strong><br>';
            configHtml += `&nbsp;&nbsp;Max Upload Size: ${config.max_upload_size}<br>`;
            configHtml += `&nbsp;&nbsp;Post Max Size: ${config.post_max_size}<br>`;
            configHtml += `&nbsp;&nbsp;Memory Limit: ${config.memory_limit}<br>`;
            configHtml += `&nbsp;&nbsp;Max Execution Time: ${config.max_execution_time}s<br>`;
            configHtml += `&nbsp;&nbsp;Session Timeout: ${config.session_timeout}<br><br>`;
            
            configHtml += '<strong>Database:</strong><br>';
            configHtml += `&nbsp;&nbsp;Name: ${config.database.name}<br>`;
            configHtml += `&nbsp;&nbsp;Host: ${config.database.host}<br>`;
            
            configHtml += '</div>';
            
            // Create a temporary modal to show config
            const tempModal = document.createElement('div');
            tempModal.className = 'modal';
            tempModal.style.display = 'flex';
            tempModal.innerHTML = `
                <div class="modal-content" style="max-width: 600px;">
                    <div class="modal-header">
                        <h3 class="modal-title">System Configuration</h3>
                        <button class="modal-close" onclick="this.parentElement.parentElement.parentElement.remove()">&times;</button>
                    </div>
                    <div class="modal-body">
                        ${configHtml}
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" onclick="this.parentElement.parentElement.parentElement.remove()">Close</button>
                    </div>
                </div>
            `;
            document.body.appendChild(tempModal);
            showToast('System configuration retrieved', 'success');
        } else {
            showToast(result.error || 'Failed to get configuration', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error getting configuration: ' + error.message, 'error');
    });
}

function resetConfig() {
    showToast('Reset configuration is disabled for safety. Manual configuration reset required.', 'warning');
}

function exportConfig() {
    showToast('Exporting configuration...', 'info');
    
    fetch('../backend/api/developer_operations.php?action=export_config')
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            // Download the config file
            const link = document.createElement('a');
            link.href = result.download_url;
            link.download = result.file;
            document.body.appendChild(link);
            link.click();
            link.remove();
            
            showToast(result.message || 'Configuration exported successfully', 'success');
        } else {
            showToast(result.error || 'Failed to export configuration', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error exporting configuration: ' + error.message, 'error');
    });
}

// Toast notification function
function showToast(message, type = 'info') {
    if (window.showPetronFlash) {
        window.showPetronFlash(message, type);
        return;
    }
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    
    if (type === 'success') {
        toast.style.background = '#10b981';
    } else if (type === 'error' || type === 'danger') {
        toast.style.background = 'var(--petron-red)';
    } else if (type === 'warning') {
        toast.style.background = '#f59e0b';
        toast.style.color = '#000';
    } else {
        toast.style.background = '#007bff';
    }
    
    toast.style.color = type === 'warning' ? '#000' : 'white';
    toast.style.padding = '12px 20px';
    toast.style.borderRadius = '8px';
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '10000';
    toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.remove();
    }, 3000);
}
</script>

<script src="../assets/js/data_helper.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    DataHelper.populateRoles('dev_role_add', 'Select role');
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
