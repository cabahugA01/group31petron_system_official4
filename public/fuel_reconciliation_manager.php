<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Reconciliation Manager - Petron POS</title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        .manager-dashboard {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border-radius: 15px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2a5298;
        }
        .verification-table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .btn-verify {
            background: #28a745;
            border: none;
            border-radius: 5px;
            padding: 5px 15px;
        }
        .btn-reject {
            background: #dc3545;
            border: none;
            border-radius: 5px;
            padding: 5px 15px;
        }
        .exception-item {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
        }
        .password-modal .modal-content {
            border-radius: 15px;
        }
        .reading-row {
            transition: background-color 0.2s;
        }
        .reading-row:hover {
            background-color: #f8f9fa;
        }
        .status-badge {
            font-size: 0.8rem;
        }
        .responsibility-item {
            text-align: center;
            padding: 1rem;
            border-radius: 10px;
            background: #f8f9fa;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .responsibility-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .resp-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .responsibility-item h6 {
            color: #2a5298;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .review-checklist {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }
        .review-checklist h6 {
            color: #007bff;
            margin-bottom: 1rem;
        }
        .form-check {
            margin-bottom: 1rem;
        }
        .form-check-input:checked {
            background-color: #28a745;
            border-color: #28a745;
        }
    </style>
</head>
<body>
    <?php include '../partials/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Manager Dashboard Header -->
        <div class="manager-dashboard">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2><i class="fas fa-clipboard-check"></i> Fuel Reconciliation Manager</h2>
                    <p class="mb-0">Review and verify fuel readings encoded by staff</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="form-group">
                        <label for="reportDate" class="form-label">Report Date</label>
                        <input type="date" class="form-control" id="reportDate">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <i class="fas fa-clock text-warning fa-2x mb-2"></i>
                    <div class="stat-number" id="pendingCount">0</div>
                    <div class="text-muted">Pending Verification</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                    <div class="stat-number" id="verifiedCount">0</div>
                    <div class="text-muted">Verified Readings</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <i class="fas fa-times-circle text-danger fa-2x mb-2"></i>
                    <div class="stat-number" id="rejectedCount">0</div>
                    <div class="text-muted">Rejected Readings</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <i class="fas fa-gas-pump text-primary fa-2x mb-2"></i>
                    <div class="stat-number" id="totalLiters">0</div>
                    <div class="text-muted">Total Liters Sold</div>
                </div>
            </div>
        </div>
        
        <!-- Manager's Responsibilities Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-user-tie"></i> Manager's Responsibilities at This Stage
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="responsibility-item">
                                    <div class="resp-icon">
                                        <i class="fas fa-eye text-primary"></i>
                                    </div>
                                    <h6>Review Encoded Data</h6>
                                    <p class="small text-muted">Examine present, previous, and calibration values encoded by staff</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="responsibility-item">
                                    <div class="resp-icon">
                                        <i class="fas fa-calculator text-success"></i>
                                    </div>
                                    <h6>Check System Computation</h6>
                                    <p class="small text-muted">Verify computed sales are accurate and logical</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="responsibility-item">
                                    <div class="resp-icon">
                                        <i class="fas fa-exclamation-triangle text-warning"></i>
                                    </div>
                                    <h6>Handle Exceptions</h6>
                                    <p class="small text-muted">Address flagged anomalies with approval or rejection</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="responsibility-item">
                                    <div class="resp-icon">
                                        <i class="fas fa-lock text-danger"></i>
                                    </div>
                                    <h6>Finalize Report</h6>
                                    <p class="small text-muted">Password-protected finalization with audit trail</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content Area -->
        <div class="row">
            <!-- Pending Readings -->
            <div class="col-md-8">
                <div class="verification-table">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-list"></i> Pending Readings for Verification
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th><input type="checkbox" id="selectAll"></th>
                                        <th>Pump</th>
                                        <th>Shift</th>
                                        <th>Fuel Type</th>
                                        <th>Present</th>
                                        <th>Previous</th>
                                        <th>Calibration</th>
                                        <th>Liters</th>
                                        <th>Amount</th>
                                        <th>Staff</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="pendingReadingsBody">
                                    <tr>
                                        <td colspan="11" class="text-center p-4">
                                            <i class="fas fa-spinner fa-spin"></i> Loading pending readings...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="row">
                            <div class="col-md-6">
                                <button class="btn btn-success" id="bulkApproveBtn" disabled>
                                    <i class="fas fa-check"></i> Approve Selected
                                </button>
                                <button class="btn btn-danger ms-2" id="bulkRejectBtn" disabled>
                                    <i class="fas fa-times"></i> Reject Selected
                                </button>
                            </div>
                            <div class="col-md-6 text-end">
                                <button class="btn btn-primary" id="refreshBtn">
                                    <i class="fas fa-sync"></i> Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Exceptions and Alerts -->
            <div class="col-md-4">
                <!-- Exception Reports -->
                <div class="card mb-3">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="mb-0">
                            <i class="fas fa-exclamation-triangle"></i> Exception Reports
                        </h6>
                    </div>
                    <div class="card-body">
                        <div id="exceptionList">
                            <div class="text-center text-muted">
                                <i class="fas fa-check-circle"></i> No exceptions detected
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">
                            <i class="fas fa-chart-bar"></i> Quick Stats
                        </h6>
                    </div>
                    <div class="card-body">
                        <div id="quickStats">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Average Liters/Reading:</span>
                                <strong id="avgLiters">0</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Revenue:</span>
                                <strong id="totalRevenue">₱0</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Verification Rate:</span>
                                <strong id="verificationRate">0%</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Verification Modal -->
    <div class="modal fade password-modal" id="verificationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Manager Verification - Review & Decision</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Manager's Review Checklist -->
                    <div class="review-checklist mb-4">
                        <h6><i class="fas fa-clipboard-check text-primary"></i> Manager's Review Checklist</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="reviewData">
                                    <label class="form-check-label" for="reviewData">
                                        <strong>Review Encoded Data</strong><br>
                                        <small class="text-muted">Present, Previous, Calibration values examined</small>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="reviewComputation">
                                    <label class="form-check-label" for="reviewComputation">
                                        <strong>Check System Computation</strong><br>
                                        <small class="text-muted">Sales calculation verified as accurate</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="reviewExceptions">
                                    <label class="form-check-label" for="reviewExceptions">
                                        <strong>Handle Exceptions</strong><br>
                                        <small class="text-muted">Anomalies evaluated & decided</small>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="reviewAudit">
                                    <label class="form-check-label" for="reviewAudit">
                                        <strong>Audit Trail Ready</strong><br>
                                        <small class="text-muted">Accountability documented</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Reading Details -->
                    <div id="verificationDetails" class="mb-4"></div>
                    
                    <!-- Exception Handling Section -->
                    <div id="exceptionSection" class="mb-4" style="display: none;">
                        <div class="alert alert-warning">
                            <h6><i class="fas fa-exclamation-triangle"></i> Exception Handling Required</h6>
                            <div id="exceptionDetails"></div>
                            <div class="mt-3">
                                <label class="form-label"><strong>Manager's Decision:</strong></label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="exceptionDecision" id="approveWithNotes" value="approve">
                                    <label class="btn btn-outline-success" for="approveWithNotes">
                                        <i class="fas fa-check"></i> Approve with Justification
                                    </label>
                                    <input type="radio" class="btn-check" name="exceptionDecision" id="rejectForCorrection" value="reject">
                                    <label class="btn btn-outline-danger" for="rejectForCorrection">
                                        <i class="fas fa-times"></i> Reject for Correction
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <form id="verificationForm">
                        <div class="mb-3">
                            <label for="managerPassword" class="form-label">Manager Password *</label>
                            <input type="password" class="form-control" id="managerPassword" required>
                            <div class="form-text">Password required for report finalization and audit trail</div>
                        </div>
                        <div class="mb-3">
                            <label for="managerNotes" class="form-label">Manager Notes *</label>
                            <textarea class="form-control" id="managerNotes" rows="3" placeholder="Document your review findings and justification..." required></textarea>
                            <div class="form-text">Notes will be logged in audit trail for accountability</div>
                        </div>
                        <input type="hidden" id="verificationReadingId">
                        <input type="hidden" id="verificationAction">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmVerification">Finalize Verification</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Adjustment Modal -->
    <div class="modal fade" id="adjustmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Fuel Reading - Manager Correction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Current Reading Details -->
                    <div id="adjustmentDetails" class="mb-4"></div>
                    
                    <!-- Adjustment Form -->
                    <form id="adjustmentForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustPresentReading" class="form-label">Present Reading (L)</label>
                                    <input type="number" step="0.01" class="form-control" id="adjustPresentReading" required>
                                    <div class="form-text">Current meter reading</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustCalibration" class="form-label">Calibration (L)</label>
                                    <input type="number" step="0.01" class="form-control" id="adjustCalibration" required>
                                    <div class="form-text">Calibration adjustment amount</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustPricePerLiter" class="form-label">Price per Liter (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="adjustPricePerLiter" required>
                                    <div class="form-text">Fuel price per liter</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Computed Values</label>
                                    <div class="p-2 bg-light rounded">
                                        <div><strong>Liters Sold:</strong> <span id="computedLiters">0.00</span> L</div>
                                        <div><strong>Total Amount:</strong> ₱<span id="computedAmount">0.00</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="adjustReason" class="form-label">Adjustment Reason *</label>
                            <textarea class="form-control" id="adjustReason" rows="3" placeholder="Explain why this adjustment is necessary..." required></textarea>
                            <div class="form-text">This will be logged in the audit trail</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="adjustManagerPassword" class="form-label">Manager Password *</label>
                            <input type="password" class="form-control" id="adjustManagerPassword" required>
                            <div class="form-text">Password required to authorize adjustment</div>
                        </div>
                        
                        <input type="hidden" id="adjustReadingId">
                        <input type="hidden" id="adjustPreviousReading">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="confirmAdjustment">Apply Adjustment</button>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../partials/footer.php'; ?>
    
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentUser = null;
        let selectedReadings = new Set();
        let verificationModal = null;
        let adjustmentModal = null;
        let pendingReadingsData = [];
        
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
                
                // Check if user has managerial role
                if (!['manager', 'admin', 'superadmin'].includes(currentUser.role.toLowerCase())) {
                    alert('Access denied. Managerial access required.');
                    window.location.href = '../public/dashboard.php';
                    return;
                }
                
                // Initialize modals
                verificationModal = new bootstrap.Modal(document.getElementById('verificationModal'));
                adjustmentModal = new bootstrap.Modal(document.getElementById('adjustmentModal'));
                
                // Set today's date
                document.getElementById('reportDate').value = new Date().toISOString().split('T')[0];
                
                // Load dashboard data
                await loadDashboardData();
                
                // Setup event listeners
                setupEventListeners();
                
            } catch (error) {
                console.error('Initialization error:', error);
                alert('Failed to initialize page');
            }
        }
        
        function setupEventListeners() {
            // Date change
            document.getElementById('reportDate').addEventListener('change', loadDashboardData);
            
            // Refresh button
            document.getElementById('refreshBtn').addEventListener('click', loadDashboardData);
            
            // Select all checkbox
            document.getElementById('selectAll').addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.reading-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = this.checked;
                    const readingId = parseInt(cb.value);
                    if (this.checked) {
                        selectedReadings.add(readingId);
                    } else {
                        selectedReadings.delete(readingId);
                    }
                });
                updateBulkButtons();
            });
            
            // Bulk actions
            document.getElementById('bulkApproveBtn').addEventListener('click', () => bulkVerification('approve'));
            document.getElementById('bulkRejectBtn').addEventListener('click', () => bulkVerification('reject'));
            
            // Verification form
            document.getElementById('confirmVerification').addEventListener('click', confirmVerification);
            
            // Adjustment form
            document.getElementById('confirmAdjustment').addEventListener('click', confirmAdjustment);
            
            // Real-time calculation for adjustment
            document.getElementById('adjustPresentReading').addEventListener('input', computeAdjustmentValues);
            document.getElementById('adjustCalibration').addEventListener('input', computeAdjustmentValues);
            document.getElementById('adjustPricePerLiter').addEventListener('input', computeAdjustmentValues);
        }
        
        async function loadDashboardData() {
            const date = document.getElementById('reportDate').value;
            
            try {
                // Load dashboard stats
                const dashboardResponse = await fetch(`../backend/api/fuel_reconciliation_manager.php?action=dashboard&date=${date}`);
                const dashboardData = await dashboardResponse.json();
                
                if (dashboardData.success) {
                    updateDashboardStats(dashboardData.data);
                    updateExceptions(dashboardData.data.exceptions);
                }
                
                // Load pending readings
                await loadPendingReadings();
                
            } catch (error) {
                console.error('Error loading dashboard data:', error);
            }
        }
        
        function updateDashboardStats(data) {
            const summary = data.summary;
            
            document.getElementById('pendingCount').textContent = data.pending_count || 0;
            document.getElementById('verifiedCount').textContent = summary.verified_count || 0;
            document.getElementById('rejectedCount').textContent = summary.rejected_count || 0;
            document.getElementById('totalLiters').textContent = (summary.total_liters || 0).toFixed(0);
            
            // Update quick stats
            const avgLiters = summary.total_readings > 0 ? (summary.total_liters / summary.total_readings).toFixed(1) : 0;
            document.getElementById('avgLiters').textContent = avgLiters;
            document.getElementById('totalRevenue').textContent = `₱${(summary.total_amount || 0).toFixed(2)}`;
            
            const verificationRate = summary.total_readings > 0 
                ? ((summary.verified_count / summary.total_readings) * 100).toFixed(1) 
                : 0;
            document.getElementById('verificationRate').textContent = `${verificationRate}%`;
        }
        
        function updateExceptions(exceptions) {
            const exceptionList = document.getElementById('exceptionList');
            
            if (exceptions.length > 0) {
                exceptionList.innerHTML = exceptions.map(exception => `
                    <div class="exception-item">
                        <div class="d-flex justify-content-between">
                            <strong>Pump ${exception.pump_number}</strong>
                            <span class="badge bg-warning">${exception.liters_sold.toFixed(1)}L</span>
                        </div>
                        <small class="text-muted">
                            ${exception.fuel_type} • ${exception.encoded_by_name}
                        </small>
                    </div>
                `).join('');
            } else {
                exceptionList.innerHTML = `
                    <div class="text-center text-muted">
                        <i class="fas fa-check-circle"></i> No exceptions detected
                    </div>
                `;
            }
        }
        
        async function loadPendingReadings() {
            const date = document.getElementById('reportDate').value;
            const tbody = document.getElementById('pendingReadingsBody');
            
            try {
                const response = await fetch(`../backend/api/fuel_reconciliation_manager.php?action=get_pending_readings&date=${date}`);
                const data = await response.json();
                
                if (data.success) {
                    pendingReadingsData = Array.isArray(data.data) ? data.data : [];
                    if (data.data.length > 0) {
                        tbody.innerHTML = data.data.map(reading => createReadingRow(reading)).join('');
                        
                        // Add event listeners to checkboxes
                        document.querySelectorAll('.reading-checkbox').forEach(checkbox => {
                            checkbox.addEventListener('change', function() {
                                const readingId = parseInt(this.value);
                                if (this.checked) {
                                    selectedReadings.add(readingId);
                                } else {
                                    selectedReadings.delete(readingId);
                                }
                                updateBulkButtons();
                            });
                        });
                    } else {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="11" class="text-center p-4 text-muted">
                                    <i class="fas fa-check-circle"></i> No pending readings for verification
                                </td>
                            </tr>
                        `;
                    }
                }
                else {
                    pendingReadingsData = [];
                }
            } catch (error) {
                console.error('Error loading pending readings:', error);
                pendingReadingsData = [];
                tbody.innerHTML = `
                    <tr>
                        <td colspan="11" class="text-center p-4 text-danger">
                            <i class="fas fa-exclamation-triangle"></i> Error loading readings
                        </td>
                    </tr>
                `;
            }
        }
        
        function createReadingRow(reading) {
            const litersClass = reading.liters_sold < 0 ? 'text-danger' : 
                              reading.liters_sold > 1000 ? 'text-warning' : '';
            
            return `
                <tr class="reading-row">
                    <td><input type="checkbox" class="reading-checkbox" value="${reading.id}"></td>
                    <td><strong>${reading.pump_number}</strong></td>
                    <td>${reading.shift || reading.shift_period || '-'}</td>
                    <td>${reading.fuel_type}</td>
                    <td>${reading.present_reading.toFixed(2)}</td>
                    <td>${reading.previous_reading.toFixed(2)}</td>
                    <td>${reading.calibration.toFixed(2)}</td>
                    <td class="${litersClass}">${reading.liters_sold.toFixed(2)}</td>
                    <td>₱${reading.amount.toFixed(2)}</td>
                    <td>${reading.encoded_by_name}</td>
                    <td>
                        <button class="btn btn-sm btn-verify" onclick="verifyReading(${reading.id}, 'approve')">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn btn-sm btn-reject" onclick="verifyReading(${reading.id}, 'reject')">
                            <i class="fas fa-times"></i>
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="adjustReading(${reading.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                    </td>
                </tr>
            `;
        }
        
        function verifyReading(readingId, action) {
            // Reset form and checklist
            document.getElementById('verificationReadingId').value = readingId;
            document.getElementById('verificationAction').value = action;
            document.getElementById('managerPassword').value = '';
            document.getElementById('managerNotes').value = '';
            
            // Reset checklist
            document.querySelectorAll('.form-check-input').forEach(cb => cb.checked = false);
            document.getElementById('exceptionSection').style.display = 'none';
            
            // Find the reading data
            const reading = pendingReadingsData.find(r => r.id === readingId);
            if (!reading) return;
            
            // Detect exceptions
            const exceptions = detectExceptions(reading);
            
            // Update modal content based on action
            const modalTitle = document.querySelector('#verificationModal .modal-title');
            const confirmBtn = document.getElementById('confirmVerification');
            
            if (action === 'approve') {
                modalTitle.textContent = 'Approve Reading - Manager Review Required';
                confirmBtn.className = 'btn btn-success';
                confirmBtn.textContent = 'Finalize Approval';
            } else {
                modalTitle.textContent = 'Reject Reading - Manager Review Required';
                confirmBtn.className = 'btn btn-danger';
                confirmBtn.textContent = 'Finalize Rejection';
            }
            
            // Display reading details with review guidance
            displayReadingDetails(reading, exceptions);
            
            // Show exception section if needed
            if (exceptions.length > 0) {
                document.getElementById('exceptionSection').style.display = 'block';
                displayExceptionDetails(exceptions);
            }
            
            verificationModal.show();
        }
        
        function adjustReading(readingId) {
            // Find the reading data
            const reading = pendingReadingsData.find(r => r.id === readingId);
            if (!reading) return;
            
            // Reset form
            document.getElementById('adjustReadingId').value = readingId;
            document.getElementById('adjustPreviousReading').value = reading.previous_reading;
            document.getElementById('adjustPresentReading').value = reading.present_reading;
            document.getElementById('adjustCalibration').value = reading.calibration;
            document.getElementById('adjustPricePerLiter').value = reading.price_per_liter;
            document.getElementById('adjustReason').value = '';
            document.getElementById('adjustManagerPassword').value = '';
            
            // Display current reading details
            displayAdjustmentDetails(reading);
            
            // Compute initial values
            computeAdjustmentValues();
            
            adjustmentModal.show();
        }
        
        function displayAdjustmentDetails(reading) {
            const detailsHtml = `
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="mb-0"><i class="fas fa-edit"></i> Current Reading Details - Pump #${reading.pump_number}</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Present Reading:</strong></td>
                                        <td>${reading.present_reading} L</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Previous Reading:</strong></td>
                                        <td>${reading.previous_reading} L</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Calibration:</strong></td>
                                        <td>${reading.calibration} L</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Current Liters Sold:</strong></td>
                                        <td class="${reading.liters_sold <= 0 ? 'text-danger fw-bold' : ''}">${reading.liters_sold} L</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Current Amount:</strong></td>
                                        <td>₱${reading.amount.toFixed(2)}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Staff:</strong></td>
                                        <td>${reading.encoded_by_name}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="alert alert-warning">
                                <strong>Current Formula:</strong> (${reading.present_reading} - ${reading.previous_reading} - ${reading.calibration}) × ₱${reading.price_per_liter}/L = ${reading.liters_sold} L = ₱${reading.amount.toFixed(2)}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('adjustmentDetails').innerHTML = detailsHtml;
        }
        
        function computeAdjustmentValues() {
            const presentReading = parseFloat(document.getElementById('adjustPresentReading').value) || 0;
            const previousReading = parseFloat(document.getElementById('adjustPreviousReading').value) || 0;
            const calibration = parseFloat(document.getElementById('adjustCalibration').value) || 0;
            const pricePerLiter = parseFloat(document.getElementById('adjustPricePerLiter').value) || 0;
            
            const litersSold = presentReading - previousReading - calibration;
            const totalAmount = litersSold * pricePerLiter;
            
            document.getElementById('computedLiters').textContent = litersSold.toFixed(2);
            document.getElementById('computedAmount').textContent = totalAmount.toFixed(2);
            
            // Add validation warnings
            const litersElement = document.getElementById('computedLiters');
            if (litersSold < 0) {
                litersElement.className = 'text-danger fw-bold';
            } else if (litersSold > 1000) {
                litersElement.className = 'text-warning fw-bold';
            } else {
                litersElement.className = '';
            }
        }
        
        async function confirmAdjustment() {
            const readingId = document.getElementById('adjustReadingId').value;
            const presentReading = document.getElementById('adjustPresentReading').value;
            const calibration = document.getElementById('adjustCalibration').value;
            const pricePerLiter = document.getElementById('adjustPricePerLiter').value;
            const reason = document.getElementById('adjustReason').value;
            const password = document.getElementById('adjustManagerPassword').value;
            
            if (!presentReading || !calibration || !pricePerLiter) {
                alert('Please fill in all adjustment fields');
                return;
            }
            
            if (!reason.trim()) {
                alert('Adjustment reason is required for audit trail');
                return;
            }
            
            if (!password) {
                alert('Manager password is required');
                return;
            }
            
            const litersSold = parseFloat(presentReading) - parseFloat(document.getElementById('adjustPreviousReading').value) - parseFloat(calibration);
            
            if (litersSold < 0) {
                if (!confirm('Warning: Computed liters sold is negative. This indicates an error. Continue anyway?')) {
                    return;
                }
            }
            
            try {
                const response = await fetch('../backend/api/fuel_reconciliation_manager.php?action=adjust_reading', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `reading_id=${readingId}&present_reading=${presentReading}&calibration=${calibration}&price_per_liter=${pricePerLiter}&liters_sold=${litersSold}&adjustment_reason=${encodeURIComponent(reason)}&manager_password=${encodeURIComponent(password)}`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(`Adjustment completed successfully!\n\n` +
                          `✅ Reading adjusted with new values\n` +
                          `✅ Audit trail logged with adjustment reason\n` +
                          `✅ Transaction updated and locked\n` +
                          `✅ Accountability documented`);
                    
                    adjustmentModal.hide();
                    await loadDashboardData();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Adjustment error:', error);
                alert('Failed to process adjustment');
            }
        }
        
        function detectExceptions(reading) {
            const exceptions = [];
            
            // Check for calibration reasonableness (2.50L example)
            if (reading.calibration > 10) {
                exceptions.push({
                    type: 'high_calibration',
                    message: `Calibration (${reading.calibration}L) seems unusually high`,
                    suggestion: 'Verify if this calibration amount is reasonable for actual pump operations'
                });
            }
            
            // Check for negative or zero sales
            if (reading.liters_sold <= 0) {
                exceptions.push({
                    type: 'negative_sales',
                    message: `Net liters sold (${reading.liters_sold}L) is negative or zero`,
                    suggestion: 'Check if present reading should be higher than previous + calibration'
                });
            }
            
            // Check for unusually high sales
            if (reading.liters_sold > 1000) {
                exceptions.push({
                    type: 'high_sales',
                    message: `Net liters sold (${reading.liters_sold}L) seems unusually high`,
                    suggestion: 'Compare with expected fuel sales trend or delivery records'
                });
            }
            
            // Check calibration vs difference
            const difference = reading.present_reading - reading.previous_reading;
            if (reading.calibration > difference) {
                exceptions.push({
                    type: 'calibration_exceeds_difference',
                    message: `Calibration (${reading.calibration}L) exceeds reading difference (${difference}L)`,
                    suggestion: 'Calibration should be less than or equal to the difference between readings'
                });
            }
            
            return exceptions;
        }
        
        function displayReadingDetails(reading, exceptions) {
            const detailsHtml = `
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-gas-pump"></i> Reading Details - Pump #${reading.pump_number} (${reading.shift} shift)</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Present Reading:</strong></td>
                                        <td>${reading.present_reading} L</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Previous Reading:</strong></td>
                                        <td>${reading.previous_reading} L</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Calibration:</strong></td>
                                        <td class="${reading.calibration > 10 ? 'text-warning fw-bold' : ''}">${reading.calibration} L</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>Computed Sales:</strong></td>
                                        <td class="${reading.liters_sold <= 0 ? 'text-danger fw-bold' : reading.liters_sold > 1000 ? 'text-warning fw-bold' : 'text-success fw-bold'}">${reading.liters_sold} L</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Total Amount:</strong></td>
                                        <td>₱${reading.amount.toFixed(2)}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Staff:</strong></td>
                                        <td>${reading.encoded_by_name}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="alert alert-info">
                                <strong>Formula Applied:</strong> (${reading.present_reading} - ${reading.previous_reading} - ${reading.calibration}) × ₱${reading.price_per_liter}/L = ${reading.liters_sold} L = ₱${reading.amount.toFixed(2)}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('verificationDetails').innerHTML = detailsHtml;
        }
        
        function displayExceptionDetails(exceptions) {
            const exceptionsHtml = exceptions.map(exc => `
                <div class="alert alert-warning mb-2">
                    <strong><i class="fas fa-exclamation-triangle"></i> ${exc.type.replace(/_/g, ' ').toUpperCase()}:</strong><br>
                    ${exc.message}<br>
                    <small><strong>Suggestion:</strong> ${exc.suggestion}</small>
                </div>
            `).join('');
            
            document.getElementById('exceptionDetails').innerHTML = exceptionsHtml;
        }
        
        async function confirmVerification() {
            const readingId = document.getElementById('verificationReadingId').value;
            const action = document.getElementById('verificationAction').value;
            const password = document.getElementById('managerPassword').value;
            const notes = document.getElementById('managerNotes').value;
            
            // Validate checklist completion
            const reviewData = document.getElementById('reviewData').checked;
            const reviewComputation = document.getElementById('reviewComputation').checked;
            const reviewExceptions = document.getElementById('reviewExceptions').checked;
            const reviewAudit = document.getElementById('reviewAudit').checked;
            
            if (!reviewData || !reviewComputation || !reviewExceptions || !reviewAudit) {
                alert('Please complete all checklist items before finalizing verification');
                return;
            }
            
            if (!password) {
                alert('Please enter your manager password');
                return;
            }
            
            if (!notes.trim()) {
                alert('Manager notes are required for audit trail documentation');
                return;
            }
            
            // Check exception decision if exceptions exist
            const exceptionSection = document.getElementById('exceptionSection');
            if (exceptionSection.style.display !== 'none') {
                const exceptionDecision = document.querySelector('input[name="exceptionDecision"]:checked');
                if (!exceptionDecision) {
                    alert('Please select an exception handling decision');
                    return;
                }
            }
            
            try {
                const response = await fetch('../backend/api/fuel_reconciliation_manager.php?action=verify_reading', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `reading_id=${readingId}&manager_password=${password}&verification_action=${action}&notes=${encodeURIComponent(notes)}`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    const actionText = action === 'approve' ? 'approved' : 'rejected';
                    
                    // Show comprehensive success message
                    alert(`Reading ${actionText} successfully!\n\n` +
                          `✅ Manager review completed\n` +
                          `✅ Audit trail logged with user ID + timestamp\n` +
                          `✅ Report locked and no longer editable by staff\n` +
                          `✅ Accountability documented for operational and academic defense`);
                    
                    verificationModal.hide();
                    await loadDashboardData();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Verification error:', error);
                alert('Failed to process verification');
            }
        }
        
        function updateBulkButtons() {
            const hasSelection = selectedReadings.size > 0;
            document.getElementById('bulkApproveBtn').disabled = !hasSelection;
            document.getElementById('bulkRejectBtn').disabled = !hasSelection;
        }
        
        async function bulkVerification(action) {
            if (selectedReadings.size === 0) {
                alert('No readings selected');
                return;
            }
            
            const password = prompt(`Enter your manager password to ${action} selected readings:`);
            
            if (!password) {
                return;
            }
            
            try {
                const response = await fetch('../backend/api/fuel_reconciliation_manager.php?action=bulk_verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `reading_ids=${Array.from(selectedReadings).join(',')}&manager_password=${encodeURIComponent(password)}&verification_action=${action}`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(`Bulk ${action} completed! Processed ${data.processed} readings.`);
                    selectedReadings.clear();
                    document.getElementById('selectAll').checked = false;
                    updateBulkButtons();
                    await loadDashboardData();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Bulk verification error:', error);
                alert('Failed to process bulk verification');
            }
        }
    </script>
</body>
</html>
