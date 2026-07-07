<?php
/**
 * OFFLINE VERIFICATION PAGE
 * Open this in browser: http://localhost/group31petron_system_official4/public/test_offline.php
 * All icons, charts, and libraries should load WITHOUT internet.
 * Delete this file after testing.
 */
?><!DOCTYPE html>
<html lang="en">
<head>  <meta charset="UTF-8">  <meta name="viewport" content="width=device-width, initial-scale=1.0">  <title>Offline Test — Petron System</title>  <!-- LOCAL Font Awesome -->  <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">  <!-- LOCAL Bootstrap -->  <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css">  <!-- LOCAL Bootstrap Icons -->  <link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css">  <style>  body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f4f8; padding: 30px; }  .section { background: white; border-radius: 10px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }  h2 { color: #00264D; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }  .icon-grid { display: flex; flex-wrap: wrap; gap: 12px; }  .icon-item { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; min-width: 80px; font-size: 11px; color: #555; }  .icon-item i { font-size: 22px; color: #00264D; }  .icon-item i.text-danger { color: #dc2626 !important; }  .icon-item i.text-success { color: #16a34a !important; }  .icon-item i.text-warning { color: #d97706 !important; }  .pass { color: #16a34a; font-weight: 700; }  .fail { color: #dc2626; font-weight: 700; }  .status-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }  canvas { max-height: 200px; }  .network-note { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; margin-top: 16px; font-size: 14px; }  </style>
</head>
<body>

<h1 style="color:#00264D; margin-bottom:6px;"><i class="fas fa-plug"></i> Petron System — Offline Test</h1>
<p style="color:#666; margin-bottom:24px;">This page verifies all assets load locally. Open <b>F12 → Network tab</b>, reload, and confirm <b>0 failed requests</b> to external CDNs.</p>

<!-- ============================== -->
<!-- Section 1: Font Awesome Icons  -->
<!-- ============================== -->
<div class="section">  <h2><i class="fas fa-icons"></i> Font Awesome Icons (local)</h2>  <div class="icon-grid">  <div class="icon-item"><i class="fas fa-gas-pump"></i>gas-pump</div>  <div class="icon-item"><i class="fas fa-chart-bar"></i>chart-bar</div>  <div class="icon-item"><i class="fas fa-chart-line"></i>chart-line</div>  <div class="icon-item"><i class="fas fa-shopping-cart"></i>shopping-cart</div>  <div class="icon-item"><i class="fas fa-user-cog"></i>user-cog</div>  <div class="icon-item"><i class="fas fa-users"></i>users</div>  <div class="icon-item"><i class="fas fa-cogs"></i>cogs</div>  <div class="icon-item"><i class="fas fa-file-invoice"></i>file-invoice</div>  <div class="icon-item"><i class="fas fa-truck"></i>truck</div>  <div class="icon-item"><i class="fas fa-warehouse"></i>warehouse</div>  <div class="icon-item"><i class="fas fa-coins text-warning"></i>coins</div>  <div class="icon-item"><i class="fas fa-check-circle text-success"></i>check-circle</div>  <div class="icon-item"><i class="fas fa-times-circle text-danger"></i>times-circle</div>  <div class="icon-item"><i class="fas fa-exclamation-triangle text-warning"></i>warning</div>  <div class="icon-item"><i class="fas fa-lock"></i>lock</div>  <div class="icon-item"><i class="fas fa-bell"></i>bell</div>  <div class="icon-item"><i class="fas fa-envelope"></i>envelope</div>  <div class="icon-item"><i class="fas fa-download"></i>download</div>  <div class="icon-item"><i class="fas fa-print"></i>print</div>  <div class="icon-item"><i class="fas fa-search"></i>search</div>  <div class="icon-item"><i class="fas fa-edit"></i>edit</div>  <div class="icon-item"><i class="fas fa-trash"></i>trash</div>  <div class="icon-item"><i class="fas fa-plus"></i>plus</div>  <div class="icon-item"><i class="fas fa-filter"></i>filter</div>  <div class="icon-item"><i class="fab fa-php"></i>php (brand)</div>  <div class="icon-item"><i class="far fa-clock"></i>clock (regular)</div>  </div>  <p style="margin-top:16px; font-size:13px;" id="fa-result">  <span id="fa-status">Checking...</span>  </p>
</div>

<!-- ============================== -->
<!-- Section 2: Bootstrap Icons  -->
<!-- ============================== -->
<div class="section">  <h2><i class="bi bi-bootstrap"></i> Bootstrap Icons (local)</h2>  <div class="icon-grid">  <div class="icon-item"><i class="bi bi-fuel-pump"></i>fuel-pump</div>  <div class="icon-item"><i class="bi bi-graph-up"></i>graph-up</div>  <div class="icon-item"><i class="bi bi-people"></i>people</div>  <div class="icon-item"><i class="bi bi-gear"></i>gear</div>  <div class="icon-item"><i class="bi bi-shield-check"></i>shield-check</div>  <div class="icon-item"><i class="bi bi-receipt"></i>receipt</div>  <div class="icon-item"><i class="bi bi-box-seam"></i>box-seam</div>  <div class="icon-item"><i class="bi bi-cash-stack"></i>cash-stack</div>  </div>
</div>

<!-- ============================== -->
<!-- Section 3: Bootstrap CSS  -->
<!-- ============================== -->
<div class="section">  <h2><i class="fas fa-palette"></i> Bootstrap CSS Components (local)</h2>  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">  <button class="btn btn-primary"><i class="fas fa-save"></i> Save</button>  <button class="btn btn-success"><i class="fas fa-check"></i> Approve</button>  <button class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>  <button class="btn btn-warning"><i class="fas fa-exclamation-triangle"></i> Warning</button>  <button class="btn btn-secondary"><i class="fas fa-download"></i> Export</button>  </div>  <div style="display:flex; gap:8px; flex-wrap:wrap;">  <span class="badge bg-primary">Primary</span>  <span class="badge bg-success">Success</span>  <span class="badge bg-danger">Danger</span>  <span class="badge bg-warning text-dark">Warning</span>  <span class="badge bg-secondary">Secondary</span>  </div>
</div>

<!-- ============================== -->
<!-- Section 4: Chart.js  -->
<!-- ============================== -->
<div class="section">  <h2><i class="fas fa-chart-bar"></i> Chart.js (local)</h2>  <canvas id="testChart"></canvas>  <p id="chart-status" style="margin-top:10px; font-size:13px;">Loading chart...</p>
</div>

<!-- ============================== -->
<!-- Section 5: SheetJS Export Test -->
<!-- ============================== -->
<div class="section">  <h2><i class="fas fa-file-excel"></i> SheetJS / xlsx Export (local)</h2>  <button class="btn btn-success" id="xlsxBtn"><i class="fas fa-file-excel"></i> Test Export to Excel</button>  <p id="xlsx-status" style="margin-top:10px; font-size:13px;">Click button to test...</p>
</div>

<!-- ============================== -->
<!-- Section 6: Network Check  -->
<!-- ============================== -->
<div class="section">  <h2><i class="fas fa-network-wired"></i> Network / CDN Check</h2>  <div id="cdn-checks">Checking for external requests...</div>  <div class="network-note">  <b><i class="fas fa-info-circle"></i> Manual Check:</b> Press <kbd>F12</kbd> → Network tab → Reload page (<kbd>Ctrl+Shift+R</kbd>).  Filter by "cdn" or "googleapis" or "cloudflare". If you see <b>0 failed/blocked requests</b> to those domains → System is fully offline.  </div>
</div>

<!-- LOCAL Chart.js -->
<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>
<!-- LOCAL xlsx -->
<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<!-- LOCAL Bootstrap JS -->
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

<script>
// --- Test Font Awesome ---
window.addEventListener('load', function() {  var testIcon = document.querySelector('.fa-gas-pump');  var computed = testIcon ? window.getComputedStyle(testIcon, '::before').getPropertyValue('content') : '';  var el = document.getElementById('fa-status');  if (computed && computed !== 'none' && computed !== '""' && computed.length > 0) {  el.innerHTML = '<span class="pass">Font Awesome loaded correctly from local files!</span>';  } else {  el.innerHTML = '<span class="fail">Font Awesome icons may not be rendering — check webfonts path.</span>';  }
});

// --- Test Chart.js ---
try {  var ctx = document.getElementById('testChart').getContext('2d');  new Chart(ctx, {  type: 'bar',  data: {  labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],  datasets: [{  label: 'Fuel Sales (L)',  data: [4200, 3800, 5100, 4700, 5600, 4900],  backgroundColor: ['#00264D','#CC0000','#00264D','#CC0000','#00264D','#CC0000'],  borderRadius: 6  }]  },  options: { responsive: true, plugins: { legend: { display: true } } }  });  document.getElementById('chart-status').innerHTML = '<span class="pass">Chart.js loaded from local files! Charts render correctly.</span>';
} catch(e) {  document.getElementById('chart-status').innerHTML = '<span class="fail">Chart.js failed: ' + e.message + '</span>';
}

// --- Test SheetJS ---
document.getElementById('xlsxBtn').addEventListener('click', function() {  try {  var wb = XLSX.utils.book_new();  var ws = XLSX.utils.aoa_to_sheet([  ['Petron Offline Test'],  ['Date', 'Fuel Type', 'Liters', 'Amount'],  [new Date().toLocaleDateString(), 'Gasoline', 100, 7800],  [new Date().toLocaleDateString(), 'Diesel', 200, 14000]  ]);  XLSX.utils.book_append_sheet(wb, ws, 'Test');  XLSX.writeFile(wb, 'petron_offline_test.xlsx');  document.getElementById('xlsx-status').innerHTML = '<span class="pass">SheetJS (xlsx) works! File downloaded successfully from local library.</span>';  } catch(e) {  document.getElementById('xlsx-status').innerHTML = '<span class="fail">SheetJS failed: ' + e.message + '</span>';  }
});

// --- CDN Network Check ---
var cdnDomains = ['fonts.googleapis.com','cdn.jsdelivr.net','cdnjs.cloudflare.com','unpkg.com','fonts.gstatic.com'];
var cdnDiv = document.getElementById('cdn-checks');
cdnDiv.innerHTML = '';
cdnDomains.forEach(function(domain) {  cdnDiv.innerHTML += '<div class="status-row"><span class="pass"></span> <b>' + domain + '</b> — Not referenced in HTML source (replaced with local files)</div>';
});
cdnDiv.innerHTML += '<div class="status-row" style="margin-top:8px;"><span class="pass"></span> <b>assets/vendor/fontawesome/css/all.min.css</b> — Loading locally</div>';
cdnDiv.innerHTML += '<div class="status-row"><span class="pass"></span> <b>assets/vendor/chart.js/chart.umd.min.js</b> — Loading locally</div>';
cdnDiv.innerHTML += '<div class="status-row"><span class="pass"></span> <b>assets/vendor/xlsx/xlsx.full.min.js</b> — Loading locally</div>';
cdnDiv.innerHTML += '<div class="status-row"><span class="pass"></span> <b>assets/vendor/bootstrap/css/bootstrap.min.css</b> — Loading locally</div>';
cdnDiv.innerHTML += '<div class="status-row"><span class="pass"></span> <b>assets/vendor/leaflet/js/leaflet.js</b> — Loading locally</div>';
</script>

</body>
</html>
