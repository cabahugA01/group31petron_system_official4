<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Fuel Oversight - Petron POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .superadmin-dashboard {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            border-radius: 15px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
        }
        .nationwide-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .nationwide-card:hover {
            transform: translateY(-5px);
        }
        .mega-number {
            font-size: 3rem;
            font-weight: bold;
            background: linear-gradient(45deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .station-ranking {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .ranking-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
        }
        .ranking-item:hover {
            background-color: #f8f9fa;
        }
        .anomaly-card {
            background: #fff5f5;
            border-left: 4px solid #e53e3e;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
        }
        .compliance-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .status-healthy { background: #d4edda; color: #155724; }
        .status-warning { background: #fff3cd; color: #856404; }
        .status-error { background: #f8d7da; color: #721c24; }
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .audit-log-item {
            border-left: 3px solid #007bff;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .export-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            padding: 1.5rem;
            color: white;
        }
    </style>
</head>
<body>
    <?php include '../partials/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Super Admin Dashboard Header -->
        <div class="superadmin-dashboard">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2><i class="fas fa-globe"></i> Super Admin Fuel Oversight</h2>
                    <p class="mb-0">Nationwide monitoring across all branches</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="form-group">
                        <label for="dateRange" class="form-label text-white">Date Range</label>
                        <select class="form-select" id="dateRange">
                            <option value="today">Today</option>
                            <option value="yesterday">Yesterday</option>
                            <option value="7days" selected>Last 7 Days</option>
                            <option value="30days">Last 30 Days</option>
                            <option value="90days">Last 90 Days</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Nationwide Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="nationwide-card text-center">
                    <i class="fas fa-building text-primary fa-2x mb-2"></i>
                    <div class="mega-number" id="totalStations">0</div>
                    <div class="text-muted">Total Stations</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="nationwide-card text-center">
                    <i class="fas fa-users text-info fa-2x mb-2"></i>
                    <div class="mega-number" id="totalUsers">0</div>
                    <div class="text-muted">Total Users</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="nationwide-card text-center">
                    <i class="fas fa-gas-pump text-success fa-2x mb-2"></i>
                    <div class="mega-number" id="totalReadings">0</div>
                    <div class="text-muted">Total Readings</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="nationwide-card text-center">
                    <i class="fas fa-tint text-warning fa-2x mb-2"></i>
                    <div class="mega-number" id="totalLiters">0</div>
                    <div class="text-muted">Liters Sold</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="nationwide-card text-center">
                    <i class="fas fa-clock text-danger fa-2x mb-2"></i>
                    <div class="mega-number" id="pendingVerifications">0</div>
                    <div class="text-muted">Pending</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="nationwide-card text-center">
                    <i class="fas fa-exclamation-triangle text-danger fa-2x mb-2"></i>
                    <div class="mega-number" id="activeAlerts">0</div>
                    <div class="text-muted">Active Alerts</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Top Performing Stations -->
            <div class="col-md-6">
                <div class="station-ranking">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-trophy"></i> Top Performing Stations
                        </h5>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        <div id="topStationsList">
                            <div class="text-center p-4">
                                <i class="fas fa-spinner fa-spin"></i> Loading station rankings...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Critical Anomalies -->
            <div class="col-md-6">
                <div class="station-ranking">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-triangle"></i> Critical Anomalies Nationwide
                        </h5>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        <div id="anomaliesList">
                            <div class="text-center p-4">
                                <i class="fas fa-spinner fa-spin"></i> Loading anomalies...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Detailed Analysis Section -->
        <div class="row mt-4">
            <div class="col-12">
                <ul class="nav nav-tabs" id="analysisTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="station-tab" data-bs-toggle="tab" data-bs-target="#station" type="button">
                            <i class="fas fa-building"></i> Station Details
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="audit-tab" data-bs-toggle="tab" data-bs-target="#audit" type="button">
                            <i class="fas fa-history"></i> Audit Trail
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="rbac-tab" data-bs-toggle="tab" data-bs-target="#rbac" type="button">
                            <i class="fas fa-shield-alt"></i> RBAC Compliance
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="health-tab" data-bs-toggle="tab" data-bs-target="#health" type="button">
                            <i class="fas fa-heartbeat"></i> System Health
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="export-tab" data-bs-toggle="tab" data-bs-target="#export" type="button">
                            <i class="fas fa-download"></i> Export Reports
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="analysisTabsContent">
                    <!-- Station Details Tab -->
                    <div class="tab-pane fade show active" id="station" role="tabpanel">
                        <div class="filter-section">
                            <div class="row">
                                <div class="col-md-4">
                                    <label for="stationSelect" class="form-label">Select Station</label>
                                    <select class="form-select" id="stationSelect">
                                        <option value="">Choose a station...</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="stationDateRange" class="form-label">Date Range</label>
                                    <select class="form-select" id="stationDateRange">
                                        <option value="today">Today</option>
                                        <option value="yesterday">Yesterday</option>
                                        <option value="7days" selected>Last 7 Days</option>
                                        <option value="30days">Last 30 Days</option>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button class="btn btn-primary" onclick="loadStationDetails()">
                                        <i class="fas fa-search"></i> Load Details
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="stationDetailsContent">
                            <div class="text-center p-4 text-muted">
                                <i class="fas fa-building"></i> Select a station to view detailed analysis
                            </div>
                        </div>
                    </div>
                    
                    <!-- Audit Trail Tab -->
                    <div class="tab-pane fade" id="audit" role="tabpanel">
                        <div class="filter-section">
                            <div class="row">
                                <div class="col-md-3">
                                    <label for="auditStation" class="form-label">Station</label>
                                    <select class="form-select" id="auditStation">
                                        <option value="all">All Stations</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="auditUser" class="form-label">User</label>
                                    <select class="form-select" id="auditUser">
                                        <option value="all">All Users</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="auditAction" class="form-label">Action Type</label>
                                    <select class="form-select" id="auditAction">
                                        <option value="all">All Actions</option>
                                        <option value="reading">Reading</option>
                                        <option value="verification">Verification</option>
                                        <option value="po">Purchase Order</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button class="btn btn-primary" onclick="loadAuditTrail()">
                                        <i class="fas fa-search"></i> Load Audit Trail
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="auditTrailContent">
                            <div class="text-center p-4 text-muted">
                                <i class="fas fa-history"></i> Load audit trail to view system activities
                            </div>
                        </div>
                    </div>
                    
                    <!-- RBAC Compliance Tab -->
                    <div class="tab-pane fade" id="rbac" role="tabpanel">
                        <div class="text-center">
                            <button class="btn btn-primary mb-3" onclick="loadRBACCompliance()">
                                <i class="fas fa-shield-alt"></i> Check RBAC Compliance
                            </button>
                        </div>
                        
                        <div id="rbacComplianceContent">
                            <div class="text-center p-4 text-muted">
                                <i class="fas fa-shield-alt"></i> Click to check RBAC policy compliance
                            </div>
                        </div>
                    </div>
                    
                    <!-- System Health Tab -->
                    <div class="tab-pane fade" id="health" role="tabpanel">
                        <div class="text-center">
                            <button class="btn btn-primary mb-3" onclick="checkSystemHealth()">
                                <i class="fas fa-heartbeat"></i> Check System Health
                            </button>
                        </div>
                        
                        <div id="systemHealthContent">
                            <div class="text-center p-4 text-muted">
                                <i class="fas fa-heartbeat"></i> Click to check system health and integrity
                            </div>
                        </div>
                    </div>
                    
                    <!-- Export Reports Tab -->
                    <div class="tab-pane fade" id="export" role="tabpanel">
                        <div class="export-section">
                            <h5><i class="fas fa-download"></i> Export Comprehensive Reports</h5>
                            <p>Generate and download detailed reports for analysis</p>
                            
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="exportType" class="form-label text-white">Report Type</label>
                                        <select class="form-select" id="exportType">
                                            <option value="summary">Station Summary</option>
                                            <option value="anomalies">Anomaly Report</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="exportFormat" class="form-label text-white">Format</label>
                                        <select class="form-select" id="exportFormat">
                                            <option value="json">JSON</option>
                                            <option value="csv">CSV</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button class="btn btn-light" onclick="exportReport()">
                                    <i class="fas fa-download"></i> Export Report
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../partials/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentUser = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            initializePage();
        });
        
        async function initializePage() {
            try {
                // Get current user
                const userResponse = await fetch('../backend/api/users.php?action=get_current_user');
                const userData = await userResponse.json();
                
                if (!userData.success) {
                    window.location.href = '../public/login.php';
                    return;
                }
                
                currentUser = userData.data;
                
                // Check if user is super admin
                if (currentUser.role.toLowerCase() !== 'superadmin') {
                    alert('Access denied. Super admin access required.');
                    window.location.href = '../public/dashboard.php';
                    return;
                }
                
                // Load dashboard data
                await loadDashboardData();
                
                // Setup event listeners
                document.getElementById('dateRange').addEventListener('change', loadDashboardData);
                
            } catch (error) {
                console.error('Initialization error:', error);
                alert('Failed to initialize page');
            }
        }
        
        async function loadDashboardData() {
            const dateRange = document.getElementById('dateRange').value;
            
            try {
                const response = await fetch(`../backend/api/fuel_super_admin.php?action=dashboard&date_range=${dateRange}`);
                const data = await response.json();
                
                if (data.success) {
                    updateNationwideSummary(data.data.nationwide_summary);
                    updateTopStations(data.data.top_stations);
                    updateAnomalies(data.data.critical_anomalies);
                }
            } catch (error) {
                console.error('Error loading dashboard data:', error);
            }
        }
        
        function updateNationwideSummary(summary) {
            document.getElementById('totalStations').textContent = summary.total_stations || 0;
            document.getElementById('totalUsers').textContent = summary.total_users || 0;
            document.getElementById('totalReadings').textContent = (summary.total_readings || 0).toLocaleString();
            document.getElementById('totalLiters').textContent = (summary.total_liters || 0).toLocaleString();
            document.getElementById('pendingVerifications').textContent = summary.pending_verifications || 0;
            document.getElementById('activeAlerts').textContent = summary.active_alerts || 0;
        }
        
        function updateTopStations(stations) {
            const stationsList = document.getElementById('topStationsList');
            
            if (stations.length > 0) {
                stationsList.innerHTML = stations.map((station, index) => `
                    <div class="ranking-item">
                        <div class="row align-items-center">
                            <div class="col-md-1">
                                <h4 class="text-primary">#${index + 1}</h4>
                            </div>
                            <div class="col-md-5">
                                <h6>${station.name}</h6>
                                <small class="text-muted">${station.location}</small>
                            </div>
                            <div class="col-md-2">
                                <strong>${station.total_liters.toFixed(0)}L</strong><br>
                                <small class="text-muted">Liters</small>
                            </div>
                            <div class="col-md-2">
                                <strong>₱${station.total_amount.toFixed(0)}</strong><br>
                                <small class="text-muted">Revenue</small>
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-warning">${station.pending_count || 0} pending</span>
                            </div>
                        </div>
                    </div>
                `).join('');
            } else {
                stationsList.innerHTML = `
                    <div class="text-center p-4 text-muted">
                        <i class="fas fa-building"></i> No station data available
                    </div>
                `;
            }
        }
        
        function updateAnomalies(anomalies) {
            const anomaliesList = document.getElementById('anomaliesList');
            
            if (anomalies.length > 0) {
                anomaliesList.innerHTML = anomalies.map(anomaly => `
                    <div class="anomaly-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong>${anomaly.station_name} - Pump ${anomaly.pump_number}</strong><br>
                                <small class="text-muted">${anomaly.fuel_type} • ${anomaly.encoded_by_name}</small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-danger">${anomaly.liters_sold.toFixed(1)}L</span>
                            </div>
                        </div>
                        <div class="mt-1">
                            <small>Present: ${anomaly.present_reading} | Previous: ${anomaly.previous_reading} | Calibration: ${anomaly.calibration}</small>
                        </div>
                    </div>
                `).join('');
            } else {
                anomaliesList.innerHTML = `
                    <div class="text-center p-4 text-muted">
                        <i class="fas fa-check-circle"></i> No critical anomalies detected
                    </div>
                `;
            }
        }
        
        async function loadStationDetails() {
            const stationId = document.getElementById('stationSelect').value;
            const dateRange = document.getElementById('stationDateRange').value;
            
            if (!stationId) {
                alert('Please select a station');
                return;
            }
            
            try {
                const response = await fetch(`../backend/api/fuel_super_admin.php?action=station_details&station_id=${stationId}&date_range=${dateRange}`);
                const data = await response.json();
                
                if (data.success) {
                    displayStationDetails(data.data);
                }
            } catch (error) {
                console.error('Error loading station details:', error);
            }
        }
        
        function displayStationDetails(details) {
            const content = document.getElementById('stationDetailsContent');
            
            content.innerHTML = `
                <div class="row">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6>Station Information</h6>
                                <p><strong>Name:</strong> ${details.station_info.name}</p>
                                <p><strong>Location:</strong> ${details.station_info.location}</p>
                                <p><strong>Users:</strong> ${details.station_info.user_count}</p>
                                <p><strong>Pumps:</strong> ${details.station_info.pump_count}</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6>Performance Metrics</h6>
                                <p><strong>Total Readings:</strong> ${details.performance.total_readings}</p>
                                <p><strong>Total Liters:</strong> ${details.performance.total_liters.toFixed(0)}L</p>
                                <p><strong>Total Amount:</strong> ₱${details.performance.total_amount.toFixed(2)}</p>
                                <p><strong>Avg Liters/Reading:</strong> ${details.performance.avg_liters?.toFixed(1) || 0}L</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6>Status Summary</h6>
                                <p><strong>Pending:</strong> <span class="badge bg-warning">${details.performance.pending_count}</span></p>
                                <p><strong>Rejected:</strong> <span class="badge bg-danger">${details.performance.rejected_count}</span></p>
                                <p><strong>Last Activity:</strong> ${details.performance.last_activity ? new Date(details.performance.last_activity).toLocaleDateString() : 'Never'}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>User Activity</h6>
                            </div>
                            <div class="card-body">
                                ${details.user_activity.map(user => `
                                    <div class="d-flex justify-content-between mb-2">
                                        <div>
                                            <strong>${user.name}</strong><br>
                                            <small class="text-muted">${user.role}</small>
                                        </div>
                                        <div class="text-end">
                                            <strong>${user.readings_count}</strong> readings<br>
                                            <small>${user.total_liters.toFixed(0)}L</small>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>Recent Anomalies</h6>
                            </div>
                            <div class="card-body">
                                ${details.anomalies.length > 0 ? 
                                    details.anomalies.map(anomaly => `
                                        <div class="anomaly-card mb-2">
                                            <small>
                                                Pump ${anomaly.pump_number} - ${anomaly.fuel_type}<br>
                                                ${anomaly.liters_sold.toFixed(1)}L
                                            </small>
                                        </div>
                                    `).join('') :
                                    '<p class="text-muted">No anomalies detected</p>'
                                }
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        async function loadAuditTrail() {
            const station = document.getElementById('auditStation').value;
            const user = document.getElementById('auditUser').value;
            const action = document.getElementById('auditAction').value;
            
            try {
                const response = await fetch(`../backend/api/fuel_super_admin.php?action=audit_trail&station_id=${station}&user_id=${user}&action_type=${action}&limit=50`);
                const data = await response.json();
                
                if (data.success) {
                    displayAuditTrail(data.data);
                }
            } catch (error) {
                console.error('Error loading audit trail:', error);
            }
        }
        
        function displayAuditTrail(auditLogs) {
            const content = document.getElementById('auditTrailContent');
            
            if (auditLogs.length > 0) {
                content.innerHTML = auditLogs.map(log => `
                    <div class="audit-log-item">
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong>${log.action}</strong><br>
                                <small class="text-muted">${log.user_name} (${log.user_role}) - ${log.station_name}</small>
                            </div>
                            <div class="text-end">
                                <small class="text-muted">${new Date(log.created_at).toLocaleString()}</small>
                            </div>
                        </div>
                        ${log.details ? `<p class="mb-0 mt-1"><small>${log.details}</small></p>` : ''}
                    </div>
                `).join('');
            } else {
                content.innerHTML = `
                    <div class="text-center p-4 text-muted">
                        <i class="fas fa-history"></i> No audit logs found
                    </div>
                `;
            }
        }
        
        async function loadRBACCompliance() {
            try {
                const response = await fetch('../backend/api/fuel_super_admin.php?action=rbac_compliance');
                const data = await response.json();
                
                if (data.success) {
                    displayRBACCompliance(data.data);
                }
            } catch (error) {
                console.error('Error loading RBAC compliance:', error);
            }
        }
        
        function displayRBACCompliance(compliance) {
            const content = document.getElementById('rbacComplianceContent');
            
            const violationsHtml = compliance.violations.map(violation => `
                <div class="alert alert-warning">
                    <h6>${violation.violation_type}</h6>
                    <p class="mb-0"><strong>Count:</strong> ${violation.count}</p>
                    ${violation.affected_users ? `<p class="mb-0"><small>Affected Users: ${violation.affected_users}</small></p>` : ''}
                </div>
            `).join('');
            
            const distributionHtml = compliance.role_distribution.map(role => `
                <div class="d-flex justify-content-between mb-2">
                    <span>${role.role}</span>
                    <span>
                        <strong>${role.count}</strong> total 
                        <span class="badge bg-success">${role.active_count} active</span>
                    </span>
                </div>
            `).join('');
            
            content.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>Compliance Violations</h6>
                            </div>
                            <div class="card-body">
                                ${violationsHtml || '<p class="text-success">No violations detected</p>'}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>Role Distribution</h6>
                            </div>
                            <div class="card-body">
                                ${distributionHtml}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        async function checkSystemHealth() {
            try {
                const response = await fetch('../backend/api/fuel_super_admin.php?action=system_health');
                const data = await response.json();
                
                if (data.success) {
                    displaySystemHealth(data.data);
                }
            } catch (error) {
                console.error('Error checking system health:', error);
            }
        }
        
        function displaySystemHealth(health) {
            const content = document.getElementById('systemHealthContent');
            
            const healthHtml = Object.entries(health).map(([component, status]) => `
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6>${component.replace('_', ' ').toUpperCase()}</h6>
                            <span class="compliance-status status-${status.status}">${status.status.toUpperCase()}</span>
                        </div>
                        ${status.message ? `<p class="mb-0">${status.message}</p>` : ''}
                        ${status.issues ? `
                            <div class="mt-2">
                                ${status.issues.map(issue => `<small class="text-warning">• ${issue}</small><br>`).join('')}
                            </div>
                        ` : ''}
                    </div>
                </div>
            `).join('');
            
            content.innerHTML = healthHtml;
        }
        
        async function exportReport() {
            const reportType = document.getElementById('exportType').value;
            const format = document.getElementById('exportFormat').value;
            const dateRange = document.getElementById('dateRange').value;
            
            try {
                const response = await fetch(`../backend/api/fuel_super_admin.php?action=export_report&report_type=${reportType}&format=${format}&date_range=${dateRange}`);
                
                if (format === 'csv') {
                    // File download will be handled by the server
                    return;
                }
                
                const data = await response.json();
                
                if (data.success) {
                    // Create downloadable JSON file
                    const blob = new Blob([JSON.stringify(data.data, null, 2)], { type: 'application/json' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `fuel_report_${reportType}_${dateRange}.json`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                }
            } catch (error) {
                console.error('Error exporting report:', error);
                alert('Failed to export report');
            }
        }
        
        // Load stations for dropdowns (would need to implement stations API endpoint)
        async function loadStations() {
            try {
                const response = await fetch('../backend/api/stations.php?action=list');
                const data = await response.json();
                
                if (data.success) {
                    const stationSelect = document.getElementById('stationSelect');
                    const auditStation = document.getElementById('auditStation');
                    
                    const options = '<option value="">Choose a station...</option>' +
                        data.data.map(station => `<option value="${station.id}">${station.name}</option>`).join('');
                    
                    stationSelect.innerHTML = options;
                    auditStation.innerHTML = '<option value="all">All Stations</option>' + options;
                }
            } catch (error) {
                console.error('Error loading stations:', error);
            }
        }
        
        // Initialize stations on page load
        loadStations();
    </script>
</body>
</html>
