<?php
// ── Auth & role gate MUST run before any output ──────────────────────────────
$page_id = 'manager_staff_oversight';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$user       = current_user();
$role       = role_key($user['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

require_permission('manage_staff_oversight');

// Header included AFTER auth is confirmed
require_once __DIR__ . '/../partials/header.php';
?>

<style>
    .action-btn { font-size:12px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:all .15s; font-weight:600; }
    .action-btn:hover { filter:brightness(.9); transform:translateY(-1px); }
    .btn-view    { background:#28a745; color:#fff; }
    .btn-edit    { background:#002F70; color:#fff; }
    .btn-reset   { background:#ffc107; color:#333; }
    .btn-danger  { background:#dc3545; color:#fff; }
    .btn-success { background:#28a745; color:#fff; }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-eye me-2"></i>Staff Oversight</h2>
                <div>
                    <button class="btn btn-primary" onclick="loadLogs()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="oversightTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="logs-tab" data-bs-toggle="tab" href="#logs" role="tab">Recent Logs</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="shifts-tab" data-bs-toggle="tab" href="#shifts" role="tab">Shift Summaries</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="performance-tab" data-bs-toggle="tab" href="#performance" role="tab">Performance</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="flags-tab" data-bs-toggle="tab" href="#flags" role="tab">Flagged Items</a>
                </li>
            </ul>

            <div class="tab-content" id="oversightTabContent">
                <!-- Recent Logs -->
                <div class="tab-pane fade show active" id="logs" role="tabpanel">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between">
                            <h5>Staff Activity Logs</h5>
                            <div>
                                <input type="date" id="dateFrom" class="form-control d-inline w-auto me-2">
                                <input type="date" id="dateTo" class="form-control d-inline w-auto me-2">
                                <select id="staffFilter" class="form-select d-inline w-auto">
                                    <option value="">All Staff</option>
                                </select>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="logsTable" class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Staff</th>
                                        <th>Action</th>
                                        <th>Date/Time</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Shift Summaries -->
                <div class="tab-pane fade" id="shifts" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <h5>Shift Summaries</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <select id="shiftType" class="form-select">
                                        <option value="">All Shifts</option>
                                        <option value="first">First Shift (6:00 AM - 2:00 PM)</option>
                <option value="second">Second Shift (2:00 PM - 12:00 Midnight)</option>
                                    </select>
                                </div>
                            </div>
                            <table id="shiftsTable" class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Pump/Shift</th>
                                        <th>Fuel Type</th>
                                        <th>Sales (L)</th>
                                        <th>Stock Change</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Performance -->
                <div class="tab-pane fade" id="performance" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <h5>Team Performance</h5>
                            <select id="perfPeriod" class="form-select d-inline w-auto">
                                <option value="week">Last Week</option>
                                <option value="month">Last Month</option>
                            </select>
                        </div>
                        <div class="card-body">
                            <canvas id="perfChart" height="400"></canvas>
                            <table id="perfTable" class="table table-striped mt-3">
                                <thead>
                                    <tr>
                                        <th>Staff Name</th>
                                        <th>Completed Job Orders</th>
                                        <th>Avg JO Amount</th>
                                        <th>Fuel Readings</th>
                                        <th>Total Liters Sold</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Flagged Items -->
                <div class="tab-pane fade" id="flags" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <h5>Flagged/Suspicious Entries</h5>
                        </div>
                        <div class="card-body">
                            <table id="flagsTable" class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>ID</th>
                                        <th>Staff</th>
                                        <th>Date</th>
                                        <th>Note/Flag</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
let logsTable, shiftsTable, perfTable, flagsTable, perfChart;

$(document).ready(function() {
    initTables();
    loadLogs();
    loadShifts();
    loadPerformance();
    loadFlags();
    loadStaffFilter();
});

function initTables() {
    logsTable = $('#logsTable').DataTable({
        data: [],
        columns: [
            { data: 'name' },
            { data: 'action' },
            { data: 'created_at', render: (data) => new Date(data).toLocaleString() },
            { data: 'details' }
        ]
    });

    shiftsTable = $('#shiftsTable').DataTable({
        data: [],
        columns: [
            { data: 'pump_number' },
            { data: 'fuel_type' },
            { data: 'sales_liters' },
            { data: 'total_stock_change' },
            { data: 'status' },
            {
                data: null,
                render: data => `
                    <button class="action-btn btn-danger" onclick="flagEntry('fuel_daily_readings', ${data.id}, 'Suspicious Shift')">
                        <i class="fas fa-times"></i> Flag
                    </button>
                `
            }
        ]
    });

    perfTable = $('#perfTable').DataTable({
        data: [],
        columns: [
            { data: 'name' },
            { data: 'completed' },
            { data: 'avg_amount', render: data => '₱' + parseFloat(data || 0).toFixed(2) },
            { data: 'readings' },
            { data: 'total_liters', render: data => parseFloat(data || 0).toFixed(2) + ' L' }
        ]
    });

    flagsTable = $('#flagsTable').DataTable({
        data: [],
        columns: [
            { data: 'type' },
            { data: 'id' },
            { data: 'staff' },
            { data: 'date', render: data => new Date(data).toLocaleString() },
            { data: 'note' },
            { data: 'status' },
            {
                data: null,
                render: data => `
                    <button class="action-btn btn-success validate-btn" onclick="validateEntry('${data.table}', ${data.id})">
                        <i class="fas fa-check"></i> Validate
                    </button>
                `
            }
        ]
    });
}

function loadLogs() {
    const from = $('#dateFrom').val();
    const to = $('#dateTo').val();
    const staff = $('#staffFilter').val();
    
    $.post('backend/staff_oversight_ops.php', {
        action: 'get_logs',
        date_from: from,
        date_to: to,
        staff_id: staff
    }, function(data) {
        if (data.success) {
            logsTable.clear().rows.add(data.data).draw();
        }
    });
}

function loadShifts() {
    $.post('backend/staff_oversight_ops.php', {
        action: 'get_shift_summaries',
        shift: $('#shiftType').val()
    }, function(data) {
        if (data.success) {
            shiftsTable.clear().rows.add(data.data).draw();
        }
    });
}

function loadPerformance() {
    $.post('backend/staff_oversight_ops.php', {
        action: 'performance',
        period: $('#perfPeriod').val()
    }, function(data) {
        if (data.success) {
            if (perfChart) perfChart.destroy();
            const ctx = document.getElementById('perfChart').getContext('2d');
            perfChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.data.map(row => row.name),
                    datasets: [{
                        label: 'Total Completed Jobs',
                        data: data.data.map(row => row.completed || 0),
                        backgroundColor: '#3498db'
                    }]
                }
            });
            perfTable.clear().rows.add(data.data).draw();
        }
    });
}

function loadFlags() {
    $.post('backend/staff_oversight_ops.php', { action: 'get_flagged_items' }, function(data) {
        if (data.success) {
            flagsTable.clear().rows.add(data.data).draw();
        }
    });
}

function loadStaffFilter() {
    $.post('backend/staff_oversight_ops.php', { action: 'staff_list' }, function(data) {
        if (data.success) {
            let options = '<option value="">All Staff</option>';
            data.data.forEach(staff => {
                options += `<option value="${staff.id}">${staff.name}</option>`;
            });
            $('#staffFilter').html(options);
        }
    });
}

function flagEntry(table, id, note) {
    if (!confirm('Flag this entry?')) return;
    
    $.post('backend/staff_oversight_ops.php', {
        action: 'flag',
        table: table,
        id: id,
        note: note
    }, function(data) {
        if (data.success) {
            alert('Flagged!');
            loadLogs();
            loadFlags();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function validateEntry(table, id, note = '') {
    $.post('backend/staff_oversight_ops.php', {
        action: 'validate',
        table: table,
        id: id,
        note: note
    }, function(data) {
        if (data.success) {
            alert('Validated!');
            loadLogs();
            loadFlags();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

$('#dateFrom, #dateTo, #staffFilter').on('change', loadLogs);
$('#shiftType').on('change', loadShifts);
$('#perfPeriod').on('change', loadPerformance);
</script>

<?php require_once '../partials/footer.php'; ?>

