<?php
require_once '../includes/header.php';
require_login();
require_permission('manage_staff_oversight');
$station_id = user_station_id();
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="fas fa-users-cog me-2"></i>Manager Oversight Dashboard</h1>
                <div>
                    <a href="manager_staff_oversight.php" class="btn btn-primary">
                        <i class="fas fa-list"></i> View Details
                    </a>
                </div>
            </div>

            <div class="row g-4 mb-5">
                <!-- Pending Validations -->
                <div class="col-xl-3 col-md-6">
                    <div class="card border-start-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Pending Validations
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800" id="pendingValidations">0</div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Flagged Entries -->
                <div class="col-xl-3 col-md-6">
                    <div class="card border-start-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                        Flagged Entries
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800" id="flaggedCount">0</div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-flag fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shift Coverage -->
                <div class="col-xl-3 col-md-6">
                    <div class="card border-start-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Shift Coverage Today
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800" id="shiftCoverage">0%</div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-calendar-day fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Team Performance -->
                <div class="col-xl-3 col-md-6">
                    <div class="card border-start-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                        Top Performer (Sales)
                                    </div>
                                    <div class="h6 mb-0 font-weight-bold text-gray-800" id="topPerformer">N/A</div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-trophy fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row">
                <div class="col-lg-3 col-md-6 mb-3">
                    <a href="manager_staff_oversight.php" class="card h-100 text-decoration-none">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col mr-2">
                                    <h5>Staff Logs & Validation</h5>
                                    <p class="mb-0 text-muted">View recent activity, validate encoding, flag issues</p>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-arrow-right fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <a href="manager_calendar.php" class="card h-100 text-decoration-none">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col mr-2">
                                    <h5>Shift Calendar</h5>
                                    <p class="mb-0 text-muted">Assign shifts, view schedule, sync with logs</p>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-arrow-right fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card h-100 text-decoration-none">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col mr-2">
                                    <h5>Quick Audit Trail</h5>
                                    <p class="mb-0 text-muted">Full activity log with filters</p>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-clipboard fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Load dashboard metrics
$(document).ready(function() {
    loadDashboardMetrics();
    setInterval(loadDashboardMetrics, 300000); // 5min refresh
});

function loadDashboardMetrics() {
    $.post('backend/staff_oversight_ops.php', { action: 'get_logs', limit: 50 }, function(logs) {
        // Calculate pending, flagged, etc from recent logs
        // Update cards
    });
    
    $.post('backend/manager_calendar_ops.php', { action: 'get_data', start: new Date().toISOString().split('T')[0], end: new Date(Date.now() + 86400000).toISOString().split('T')[0] }, function(shifts) {
        // Calculate coverage %
        document.getElementById('shiftCoverage').textContent = '85%'; // Sample
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>

