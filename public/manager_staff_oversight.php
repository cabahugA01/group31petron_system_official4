<?php
// ── Auth & role gate MUST run before any output ──────────────────────────────
$page_id = 'manager_staff_oversight';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$user  = current_user();
$role  = role_key($user['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {  $_SESSION['error'] = 'Access denied. Manager privileges required.';  header('Location: dashboard.php');  exit;
}

require_permission('manage_staff_oversight');

// Header included AFTER auth is confirmed
require_once __DIR__ . '/../partials/header.php';
?>

<style>  /* ── Unified Outline Buttons ── */  .action-btn {  font-size: 13px;  padding: 6px 12px;  border-radius: 4px;  cursor: pointer;  display: inline-flex;  align-items: center;  gap: 5px;  transition: all .2s;  font-weight: 600;  text-decoration: none;  justify-content: center;  width: 110px;  background: white !important;  border: 1px solid transparent;  }  .action-btn:hover { filter: none; transform: none; }  .btn-view  { color: #16a34a !important; border-color: #16a34a !important; }  .btn-view:hover { background: #16a34a !important; color: #fff !important; }  .btn-edit  { color: #00264D !important; border-color: #00264D !important; }  .btn-edit:hover { background: #00264D !important; color: #fff !important; }  .btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }  .btn-reset:hover { background: #6b7280 !important; color: #fff !important; }  .btn-danger  { color: #dc2626 !important; border-color: #dc2626 !important; }  .btn-danger:hover { background: #dc2626 !important; color: #fff !important; }  .btn-success { color: #16a34a !important; border-color: #16a34a !important; }  .btn-success:hover { background: #16a34a !important; color: #fff !important; }  /* Custom Table header styling for DataTable consistency */  .table thead th {  font-size: 14px;  font-weight: 700;  letter-spacing: 0.3px;  }  .table tbody td {  font-size: 14px;  }  /* ── Tab Styling matching Action Outline Design ── */  .tabs.pills {  display: flex;  gap: 10px;  background: transparent !important;  border: none !important;  padding: 0 !important;  margin-bottom: 20px;  }  .tabs.pills .tab {  font-size: 13px;  padding: 8px 20px;  border-radius: 4px;  cursor: pointer;  display: inline-flex;  align-items: center;  gap: 8px;  transition: all .2s;  font-weight: 600;  text-decoration: none;  justify-content: center;  background: #ffffff !important;  color: #00264D !important;  border: 1px solid #00264D !important;  flex: none;  }  .tabs.pills .tab:hover {  background: #00264D !important;  color: #ffffff !important;  }  .tabs.pills .tab.active {  background: #00264D !important;  color: #ffffff !important;  border-color: #00264D !important;  }  /* ── Modal centering & scroll fix ── */  .modal { align-items: center !important; justify-content: center !important; overflow-y: auto !important; padding: 20px !important; z-index: 99999 !important; }  .modal-content { margin: auto !important; max-height: calc(100vh - 40px) !important; display: flex !important; flex-direction: column !important; overflow: hidden !important; }  .modal-body { overflow-y: auto !important; }
</style>

<div class="page-head">  <div>  <h1 class="h1">STAFF OVERSIGHT</h1>  <div class="sub">Track staff activities, shift summaries, and daily performance metrics.</div>  </div>  <div class="actions">  <button class="action-btn btn-edit" style="width: auto; padding: 8px 16px;" onclick="loadLogs(); loadShifts(); loadPerformance(); loadFlags();">  <i class="fas fa-sync-alt"></i> Refresh Data  </button>  </div>
</div>

<div class="card" style="padding: 20px;">  <!-- Tabs Interface -->  <div class="tabs pills" style="margin-bottom: 20px;">  <button class="tab active" id="tab-logs" onclick="switchTab('logs')">  <i class="fas fa-history"></i> Recent Logs  </button>  <button class="tab" id="tab-shifts" onclick="switchTab('shifts')">  <i class="fas fa-calendar-alt"></i> Shift Summaries  </button>  <button class="tab" id="tab-performance" onclick="switchTab('performance')">  <i class="fas fa-chart-line"></i> Performance  </button>  <button class="tab" id="tab-flags" onclick="switchTab('flags')">  <i class="fas fa-exclamation-triangle"></i> Flagged Items  </button>  </div>  <!-- Tab Contents -->  <div id="tabContent-logs" class="tab-content active">  <div class="d-flex justify-content-between align-items-center mb-3" style="flex-wrap: wrap; gap: 10px;">  <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Activity History</h3>  <div style="display: flex; gap: 8px; flex-wrap: wrap;">  <input type="date" id="dateFrom" class="inp" style="padding: 6px 12px; font-size: 13px;">  <input type="date" id="dateTo" class="inp" style="padding: 6px 12px; font-size: 13px;">  <select id="staffFilter" class="inp" style="padding: 6px 12px; font-size: 13px;">  <option value="">All Staff</option>  </select>  </div>  </div>  <div class="table-wrap">  <table id="logsTable" class="table table-hover align-middle">  <thead>  <tr>  <th>Staff Name</th>  <th>Action</th>  <th>Date/Time</th>  <th>Details</th>  </tr>  </thead>  </table>  </div>  </div>  <div id="tabContent-shifts" class="tab-content" style="display: none;">  <div class="d-flex justify-content-between align-items-center mb-3" style="flex-wrap: wrap; gap: 10px;">  <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Daily Shifts Summary</h3>  <select id="shiftType" class="inp" style="padding: 6px 12px; font-size: 13px;">  <option value="">All Shifts</option>  <option value="first">First Shift (6:00 AM - 2:00 PM)</option>  <option value="second">Second Shift (2:00 PM - 12:00 Midnight)</option>  </select>  </div>  <div class="table-wrap">  <table id="shiftsTable" class="table table-hover align-middle">  <thead>  <tr>  <th>Pump / Shift</th>  <th>Fuel Type</th>  <th>Sales (L)</th>  <th>Stock Change</th>  <th>Status</th>  <th style="text-align: right;">Actions</th>  </tr>  </thead>  </table>  </div>  </div>  <div id="tabContent-performance" class="tab-content" style="display: none;">  <div class="d-flex justify-content-between align-items-center mb-3" style="flex-wrap: wrap; gap: 10px;">  <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Team Performance Metrics</h3>  <select id="perfPeriod" class="inp" style="padding: 6px 12px; font-size: 13px;">  <option value="week">Last Week</option>  <option value="month">Last Month</option>  </select>  </div>  <div style="max-width: 100%; overflow: hidden; margin-bottom: 24px; background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">  <canvas id="perfChart" style="max-height: 320px; width: 100%;"></canvas>  </div>  <div class="table-wrap">  <table id="perfTable" class="table table-hover align-middle">  <thead>  <tr>  <th>Staff Name</th>  <th>Completed Job Orders</th>  <th>Avg JO Amount</th>  <th>Fuel Readings</th>  <th>Total Liters Sold</th>  </tr>  </thead>  </table>  </div>  </div>  <div id="tabContent-flags" class="tab-content" style="display: none;">  <div class="d-flex justify-content-between align-items-center mb-3">  <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Flagged Entries</h3>  </div>  <div class="table-wrap">  <table id="flagsTable" class="table table-hover align-middle">  <thead>  <tr>  <th>Type</th>  <th>ID</th>  <th>Staff</th>  <th>Date</th>  <th>Note / Flag</th>  <th>Status</th>  <th style="text-align: right;">Actions</th>  </tr>  </thead>  </table>  </div>  </div>
</div>

<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
let logsTable, shiftsTable, perfTable, flagsTable, perfChart;

$(document).ready(function() {  initTables();  loadLogs();  loadShifts();  loadPerformance();  loadFlags();  loadStaffFilter();
});

// Custom Tab Switcher
function switchTab(tabId) {  document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));  document.getElementById('tabContent-' + tabId).style.display = 'block';  document.getElementById('tab-' + tabId).classList.add('active');
}

function initTables() {  logsTable = $('#logsTable').DataTable({  data: [],  columns: [  { data: 'name' },  { data: 'action' },  { data: 'created_at', render: (data) => new Date(data).toLocaleString() },  { data: 'details' }  ]  });  shiftsTable = $('#shiftsTable').DataTable({  data: [],  columns: [  { data: 'pump_number' },  { data: 'fuel_type' },  { data: 'sales_liters' },  { data: 'total_stock_change' },  { data: 'status' },  {  data: null,  render: data => `  <div style="text-align:right;">  <button class="action-btn btn-danger" onclick="flagEntry('fuel_daily_readings', ${data.id}, 'Suspicious Shift')">  <i class="fas fa-flag"></i> Flag  </button>  </div>  `  }  ]  });  perfTable = $('#perfTable').DataTable({  data: [],  columns: [  { data: 'name' },  { data: 'completed' },  { data: 'avg_amount', render: data => '₱' + parseFloat(data || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) },  { data: 'readings' },  { data: 'total_liters', render: data => parseFloat(data || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L' }  ]  });  flagsTable = $('#flagsTable').DataTable({  data: [],  columns: [  { data: 'type' },  { data: 'id' },  { data: 'staff' },  { data: 'date', render: data => new Date(data).toLocaleString() },  { data: 'note' },  { data: 'status' },  {  data: null,  render: data => `  <div style="text-align:right;">  <button class="action-btn btn-success" onclick="validateEntry('${data.table}', ${data.id})">  <i class="fas fa-check"></i> Validate  </button>  </div>  `  }  ]  });
}

<<<<<<< HEAD
function loadLogs() {
    const from = $('#dateFrom').val();
    const to = $('#dateTo').val();
    const staff = $('#staffFilter').val();
    
    $.post('../backend/staff_oversight_ops.php', {
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
    $.post('../backend/staff_oversight_ops.php', {
        action: 'get_shift_summaries',
        shift: $('#shiftType').val()
    }, function(data) {
        if (data.success) {
            shiftsTable.clear().rows.add(data.data).draw();
        }
    });
}

function loadPerformance() {
    $.post('../backend/staff_oversight_ops.php', {
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
                        backgroundColor: '#002F70',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
            perfTable.clear().rows.add(data.data).draw();
        }
    });
}

function loadFlags() {
    $.post('../backend/staff_oversight_ops.php', { action: 'get_flagged_items' }, function(data) {
        if (data.success) {
            flagsTable.clear().rows.add(data.data).draw();
        }
    });
}

function loadStaffFilter() {
    $.post('../backend/staff_oversight_ops.php', { action: 'staff_list' }, function(data) {
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
    
    $.post('../backend/staff_oversight_ops.php', {
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
    $.post('../backend/staff_oversight_ops.php', {
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
=======
function loadLogs() {  const from = $('#dateFrom').val();  const to = $('#dateTo').val();  const staff = $('#staffFilter').val();  $.post('backend/staff_oversight_ops.php', {  action: 'get_logs',  date_from: from,  date_to: to,  staff_id: staff  }, function(data) {  if (data.success) {  logsTable.clear().rows.add(data.data).draw();  }  });
}

function loadShifts() {  $.post('backend/staff_oversight_ops.php', {  action: 'get_shift_summaries',  shift: $('#shiftType').val()  }, function(data) {  if (data.success) {  shiftsTable.clear().rows.add(data.data).draw();  }  });
}

function loadPerformance() {  $.post('backend/staff_oversight_ops.php', {  action: 'performance',  period: $('#perfPeriod').val()  }, function(data) {  if (data.success) {  if (perfChart) perfChart.destroy();  const ctx = document.getElementById('perfChart').getContext('2d');  perfChart = new Chart(ctx, {  type: 'bar',  data: {  labels: data.data.map(row => row.name),  datasets: [{  label: 'Total Completed Jobs',  data: data.data.map(row => row.completed || 0),  backgroundColor: '#002F70',  borderRadius: 6  }]  },  options: {  responsive: true,  maintainAspectRatio: false,  plugins: {  legend: { display: false }  }  }  });  perfTable.clear().rows.add(data.data).draw();  }  });
}

function loadFlags() {  $.post('backend/staff_oversight_ops.php', { action: 'get_flagged_items' }, function(data) {  if (data.success) {  flagsTable.clear().rows.add(data.data).draw();  }  });
}

function loadStaffFilter() {  $.post('backend/staff_oversight_ops.php', { action: 'staff_list' }, function(data) {  if (data.success) {  let options = '<option value="">All Staff</option>';  data.data.forEach(staff => {  options += `<option value="${staff.id}">${staff.name}</option>`;  });  $('#staffFilter').html(options);  }  });
}

function flagEntry(table, id, note) {  if (!confirm('Flag this entry?')) return;  $.post('backend/staff_oversight_ops.php', {  action: 'flag',  table: table,  id: id,  note: note  }, function(data) {  if (data.success) {  alert('Flagged!');  loadLogs();  loadFlags();  } else {  alert('Error: ' + data.error);  }  });
}

function validateEntry(table, id, note = '') {  $.post('backend/staff_oversight_ops.php', {  action: 'validate',  table: table,  id: id,  note: note  }, function(data) {  if (data.success) {  alert('Validated!');  loadLogs();  loadFlags();  } else {  alert('Error: ' + data.error);  }  });
>>>>>>> d6fef5c3338097d7c4b50431652c61431f9d9aa4
}

$('#dateFrom, #dateTo, #staffFilter').on('change', loadLogs);
$('#shiftType').on('change', loadShifts);
$('#perfPeriod').on('change', loadPerformance);
</script>

<?php require_once '../partials/footer.php'; ?>
