<?php
// admin_purchase_orders_view.php
// Modernized Admin Purchase Order Management Page
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/admin_po_css.php';
?>
<style>
:root {
  --petron-blue: #002F6C;
  --petron-red: #E31837;
  --petron-yellow: #FFC72C;
  --bg-light: #F8FAFC;
  --border-color: #E2E8F0;
  --text-dark: #1E293B;
  --text-muted: #64748B;
  --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
  --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

/* Premium Layout & Typography */
body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  color: var(--text-dark);
  background-color: #F1F5F9;
}

.po-container {
  width: 100%;
  padding: 0 24px 24px 24px;
  box-sizing: border-box;
}


/* Header styling */
.po-header-section {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 28px;
  margin-top: 0;
  padding-top: 0;
}

.po-title-group h1 {
  font-size: 26px;
  font-weight: 800;
  color: var(--petron-blue);
  margin: 0;
  letter-spacing: -0.5px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.po-title-group p {
  color: var(--text-muted);
  margin: 4px 0 0 0;
  font-size: 14px;
}

/* Sub Tabs Navigation Group */
.sub-tab-nav {
  display: flex;
  gap: 8px;
  margin-bottom: 24px;
}

.sub-tab-btn {
  padding: 9px 20px !important;
  font-size: 13px !important;
  font-weight: 600 !important;
  color: #64748b !important;
  border: 1.5px solid #e2e8f0 !important;
  background: #fff !important;
  cursor: pointer;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  border-radius: 8px;
  outline: none;
}

.sub-tab-btn:hover {
  color: #002F6C !important;
  border-color: #002F6C !important;
  background: #f8fafc !important;
}

.sub-tab-btn.active {
  color: #002F6C !important;
  border-color: #002F6C !important;
  background: #eff6ff !important;
}

/* Summary Cards Grid */
.po-cards-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 20px;
  margin-bottom: 28px;
}

.po-card {
  background: #FFF;
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: var(--shadow-sm);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  cursor: pointer;
  position: relative;
  overflow: hidden;
}

.po-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 4px;
  height: 100%;
  background: transparent;
  transition: background-color 0.2s ease;
}

.po-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

.po-card.active::before {
  background: var(--petron-blue);
}

.po-card.active {
  border-color: #CBD5E1;
  background: #F8FAFC;
}

.po-card-info {
  display: flex;
  flex-direction: column;
}

.po-card-label {
  font-size: 12px;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.po-card-val {
  font-size: 28px;
  font-weight: 800;
  color: var(--text-dark);
  margin-top: 6px;
  line-height: 1;
}

.po-card-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  transition: all 0.2s ease;
}

/* Color schemes for cards */
.card-pending .po-card-icon { background: #FFF7ED; color: #EA580C; }
.card-approved .po-card-icon { background: #F0FDF4; color: #16A34A; }
.card-printed .po-card-icon { background: #F0F9FF; color: #0284C7; }
.card-total .po-card-icon { background: #EEF2F6; color: #475569; }

/* Filter Section */
.po-filters-box {
  background: #FFF;
  border: 1px solid var(--border-color);
  border-radius: 12px;
  padding: 18px 24px;
  margin-bottom: 24px;
  box-shadow: var(--shadow-sm);
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 16px;
  align-items: end;
}

.filter-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.filter-group label {
  font-size: 11px;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

.filter-input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #CBD5E1;
  border-radius: 8px;
  font-size: 13px;
  color: var(--text-dark);
  background: #FFF;
  outline: none;
  transition: border-color 0.2s ease;
}

.filter-input:focus {
  border-color: var(--petron-blue);
}

.filter-btn-clear {
  border: 1.5px solid #CBD5E1 !important;
  background: #FFF !important;
  color: #475569 !important;
  font-weight: 600;
  font-size: 13px;
  padding: 10px 16px;
  border-radius: 8px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 40px;
  transition: all 0.2s ease;
  outline: none;
}

.filter-btn-clear:hover {
  background: #F1F5F9 !important;
  color: #1E293B !important;
  border-color: #94A3B8 !important;
}

/* Data Table Styling */
.po-table-container {
  background: #FFF;
  border: 1px solid var(--border-color);
  border-radius: 12px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  margin-bottom: 30px;
}

.po-modern-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 13px;
}

.po-modern-table thead {
  background: #F8FAFC;
  border-bottom: 1px solid var(--border-color);
}

.po-modern-table th {
  padding: 14px 20px;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
  font-size: 11px;
  letter-spacing: 0.5px;
}

.po-modern-table tbody tr.main-row {
  border-bottom: 1px solid #F1F5F9;
  cursor: pointer;
  transition: background-color 0.2s ease;
}

.po-modern-table tbody tr.main-row:hover {
  background-color: #F8FAFC;
}

.po-modern-table tbody tr.main-row.active {
  background-color: #F0F4F8;
}

.po-modern-table td {
  padding: 16px 20px;
  color: var(--text-dark);
  vertical-align: middle;
}

.po-code {
  font-family: 'Courier New', Courier, monospace;
  font-weight: 700;
  color: var(--petron-blue);
  background: #F0F4F8;
  padding: 3px 8px;
  border-radius: 5px;
  font-size: 12px;
}

/* Badges styling */
.badge-status {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 30px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}

.badge-pending { background: #FFF7ED; color: #C2410C; border: 1px solid #FFEDD5; }
.badge-approved { background: #F0FDF4; color: #15803D; border: 1px solid #DCFCE7; }
.badge-printed { background: #F0F9FF; color: #0369A1; border: 1px solid #E0F2FE; }
.badge-waiting { background: #FEF3C7; color: #B45309; border: 1px solid #FEF3C7; }
.badge-completed { background: #F0FDF4; color: #16A34A; border: 1px solid #DCFCE7; }

/* Accordion Details Row styling */
.details-row {
  background-color: #FCFDFE;
  border-bottom: 1px solid var(--border-color);
}

.details-wrapper {
  padding: 24px 30px;
  animation: slideDown 0.25s ease-out;
}

@keyframes slideDown {
  from { opacity: 0; transform: translateY(-5px); }
  to { opacity: 1; transform: translateY(0); }
}

.details-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 32px;
}

.details-title {
  font-size: 14px;
  font-weight: 800;
  color: var(--petron-blue);
  text-transform: uppercase;
  border-bottom: 2px solid var(--border-color);
  padding-bottom: 8px;
  margin-top: 0;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.info-list {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.info-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.info-item.full-width {
  grid-column: span 2;
}

.info-item label {
  font-size: 11px;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
}

.info-item span, .info-item textarea, .info-item input, .info-item select {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-dark);
}

.form-field-input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #CBD5E1;
  border-radius: 6px;
  outline: none;
  background: #FFF;
  box-sizing: border-box;
}

.form-field-input[readonly] {
  background: #F1F5F9;
  color: #475569;
}

/* Ordered Products table inside accordion */
.details-products-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  margin-bottom: 16px;
}

.details-products-table th {
  background: #F1F5F9;
  padding: 8px 12px;
  color: var(--text-muted);
  font-weight: 700;
  text-transform: uppercase;
  font-size: 10px;
}

.details-products-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #E2E8F0;
  vertical-align: middle;
}

.details-cost-input {
  width: 100px;
  padding: 6px 8px;
  border: 1px solid #CBD5E1;
  border-radius: 5px;
  text-align: right;
  outline: none;
}

/* Details summary block */
.details-summary-card {
  background: #F8FAFC;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 16px;
  margin-top: 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.details-summary-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.details-summary-item label {
  font-size: 10px;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
}

.details-summary-item span {
  font-size: 18px;
  font-weight: 850;
  color: var(--text-dark);
}

.details-summary-item.grand-total span {
  color: #16A34A;
}

/* Action Buttons */
.po-action-bar {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 20px;
}

/* Flat Premium Outline Button style */
.btn-po-action {
  border: 1.5px solid #CBD5E1 !important;
  background: #FFF !important;
  color: #475569 !important;
  font-weight: 700;
  font-size: 13px;
  padding: 10px 20px;
  border-radius: 8px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all 0.2s ease;
  height: 38px;
  outline: none;
}

.btn-po-action:hover {
  background: #F1F5F9 !important;
  color: #1E293B !important;
  border-color: #94A3B8 !important;
}

.btn-po-action.btn-approve {
  border-color: #002F6C !important;
  color: #002F6C !important;
}

.btn-po-action.btn-approve:hover {
  background: #eff6ff !important;
  color: #002F6C !important;
  border-color: #002F6C !important;
}

.btn-po-action.btn-reject {
  border-color: #E31837 !important;
  color: #E31837 !important;
}

.btn-po-action.btn-reject:hover {
  background: #fee2e2 !important;
  color: #E31837 !important;
  border-color: #E31837 !important;
}

.btn-po-action.btn-print {
  border-color: #0284C7 !important;
  color: #0284C7 !important;
}

.btn-po-action.btn-print:hover {
  background: #f0f9ff !important;
  color: #0284C7 !important;
  border-color: #0284C7 !important;
}

/* Form overlay modal for rejection */
.reject-modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.5);
  z-index: 1000000;
  align-items: center;
  justify-content: center;
}

.reject-modal-overlay.open {
  display: flex;
}

.reject-modal-box {
  background: #FFF;
  border-radius: 12px;
  width: 450px;
  box-shadow: var(--shadow-md);
  overflow: hidden;
  border: 1px solid var(--border-color);
}

.reject-modal-header {
  background: var(--petron-blue);
  padding: 16px 20px;
  color: #FFF;
  font-weight: 700;
  font-size: 15px;
  text-transform: uppercase;
}

.reject-modal-body {
  padding: 20px;
}

.reject-modal-footer {
  padding: 12px 20px;
  background: #F8FAFC;
  border-top: 1px solid var(--border-color);
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
/* PO Detail Modal */
.po-modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:999999; align-items:center; justify-content:center; }
.po-modal-overlay.open { display:flex; }
.po-modal-box { background:#fff; border-radius:14px; width:94%; max-width:780px; max-height:92vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.po-modal-header { background:linear-gradient(135deg,#002F6C 0%,#1e4d9a 100%); padding:16px 24px; color:#fff; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
.po-modal-header h2 { margin:0; font-size:15px; font-weight:800; display:flex; align-items:center; gap:10px; text-transform:uppercase; }
.po-modal-close { background:rgba(255,255,255,.15); border:none; color:#fff; width:32px; height:32px; border-radius:50%; cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center; transition:.2s; }
.po-modal-close:hover { background:rgba(255,255,255,.3); }
.po-modal-body { overflow-y:auto; padding:20px 24px; flex:1; }
.po-modal-footer { padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:12px; flex-shrink:0; }
/* Manager-style detail rows inside the modal */
.po-detail-section-title { font-size:11px; font-weight:800; color:#002F6C; text-transform:uppercase; letter-spacing:.6px; padding:12px 0 8px; border-bottom:2px solid #e2e8f0; margin:0 0 4px; display:flex; align-items:center; gap:6px; }
.po-detail-row { display:flex; gap:12px; padding:8px 0; border-bottom:1px solid #f0f4f8; font-size:13px; }
.po-detail-row:last-child { border-bottom:none; }
.po-detail-label { font-weight:700; color:#475569; flex:0 0 170px; }
.po-detail-value { color:#1e293b; flex:1; }
/* Summary bar */
.po-summary-bar { background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; margin:16px 0 4px; }
.po-summary-item { display:flex; flex-direction:column; gap:2px; }
.po-summary-item label { font-size:10px; font-weight:700; color:#0369a1; text-transform:uppercase; }
.po-summary-item span { font-size:18px; font-weight:800; color:#1e293b; }
.po-summary-item.grand span { color:#16a34a; }
/* Products table */
.po-products-wrap { border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-top:12px; }
.po-products-table { width:100%; border-collapse:collapse; font-size:12px; }
.po-products-table thead { background:#002F6C; }
.po-products-table thead th { padding:9px 12px; color:#fff; font-weight:700; text-align:left; }
.po-products-table thead th.right { text-align:right; }
.po-products-table tbody tr:nth-child(even) { background:#f8fafc; }
.po-products-table tbody tr:hover { background:#eff6ff; }
.po-products-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.po-products-table td.right { text-align:right; }
</style>

<div class="po-container">
  <?php if (!empty($flash_ok)): ?>
    <div class="alert alert-success" style="background: #ECFDF5; border: 1px solid #A7F3D0; color: #047857; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
      <i class="fas fa-check-circle"></i>
      <span><?= $flash_ok ?></span>
    </div>
  <?php endif; ?>
  <?php if (!empty($flash_err)): ?>
    <div class="alert alert-danger" style="background: #FEF2F2; border: 1px solid #FCA5A5; color: #B91C1C; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
      <i class="fas fa-exclamation-circle"></i>
      <span><?= $flash_err ?></span>
    </div>
  <?php endif; ?>

  <!-- Top Header -->
  <div class="po-header-section">
    <div class="po-title-group">
      <h1><i class="fas fa-file-invoice"></i> Purchase Order Management</h1>
      <p>Streamline administrative finalization, cost edits, and print-ready procurement documents.</p>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="po-cards-grid">
    <div class="po-card card-pending active" id="card-pending" onclick="setStatusTab('pending')">
      <div class="po-card-info">
        <span class="po-card-label">Pending Purchase Orders</span>
        <span class="po-card-val" id="val-pending">0</span>
      </div>
      <div class="po-card-icon"><i class="fas fa-file-alt"></i></div>
    </div>
    <div class="po-card card-approved" id="card-approved" onclick="setStatusTab('approved')">
      <div class="po-card-info">
        <span class="po-card-label">Approved Purchase Orders</span>
        <span class="po-card-val" id="val-approved">0</span>
      </div>
      <div class="po-card-icon"><i class="fas fa-check-circle"></i></div>
    </div>
    <div class="po-card card-printed" id="card-printed" onclick="setStatusTab('waiting')">
      <div class="po-card-info">
        <span class="po-card-label">Printed Purchase Orders</span>
        <span class="po-card-val" id="val-waiting">0</span>
      </div>
      <div class="po-card-icon"><i class="fas fa-print"></i></div>
    </div>
    <div class="po-card card-total" id="card-total" onclick="setStatusTab('completed')">
      <div class="po-card-info">
        <span class="po-card-label">Total Merchandise Orders</span>
        <span class="po-card-val" id="val-completed">0</span>
      </div>
      <div class="po-card-icon"><i class="fas fa-boxes"></i></div>
    </div>
  </div>

  <!-- Sub Tabs toggles Merchandise vs Fuel -->
  <div class="sub-tab-nav">
    <button type="button" class="sub-tab-btn active" id="subtab-merch" onclick="setSubTab('merch')">
      <i class="fas fa-boxes"></i> Merchandise
    </button>
    <button type="button" class="sub-tab-btn" id="subtab-fuel" onclick="setSubTab('fuel')">
      <i class="fas fa-gas-pump"></i> Fuel
    </button>
  </div>

  <!-- Search & Filters -->
  <div class="po-filters-box" style="grid-template-columns: repeat(6,1fr);">
    <div class="filter-group">
      <label>Search PO No.</label>
      <input type="text" id="search-po" class="filter-input" placeholder="e.g. PO-2026-0001" oninput="applyFilters()">
    </div>
    <div class="filter-group">
      <label>Search PR No.</label>
      <input type="text" id="search-pr" class="filter-input" placeholder="e.g. PR-2026-0001" oninput="applyFilters()">
    </div>
    <div class="filter-group">
      <label>Supplier</label>
      <input type="text" id="search-supplier" class="filter-input" placeholder="e.g. Petron" oninput="applyFilters()">
    </div>
    <div class="filter-group">
      <label>Status</label>
      <select id="search-status" class="filter-input" onchange="applyFilters()">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="waiting">Waiting Delivery</option>
        <option value="completed">Completed</option>
      </select>
    </div>
    <div class="filter-group">
      <label>Date</label>
      <input type="date" id="search-date" class="filter-input" onchange="applyFilters()">
    </div>
    <div class="filter-group">
      <button class="filter-btn-clear" onclick="clearFilters()">
        <i class="fas fa-undo"></i> Refresh
      </button>
    </div>
  </div>

  <!-- Purchase Order Table -->
  <div class="po-table-container">
    <table class="po-modern-table" id="poTable">
      <thead>
        <tr>
          <th>PO No.</th>
          <th>PR No.</th>
          <th>Supplier</th>
          <th>Generated By</th>
          <th>Date</th>
          <th style="text-align: center;">Products</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="poTableBody">
        <!-- Rows will be injected dynamically via JS -->
      </tbody>
    </table>
  </div>
</div>



<!-- Rejection Modal -->
<div class="reject-modal-overlay" id="rejectModal">
  <div class="reject-modal-box">
    <div class="reject-modal-header">Reject Purchase Request</div>
    <form method="POST" action="admin_purchase_orders.php">
      <input type="hidden" name="action" value="reject_batch">
      <input type="hidden" name="po_type" id="reject-po-type">
      <input type="hidden" name="pr_number" id="reject-pr-number">
      <div class="reject-modal-body">
        <p style="font-size: 13px; color: #475569; margin: 0 0 16px 0;">Are you sure you want to reject this purchase request? This action cannot be undone.</p>
        <div class="filter-group" style="display: flex; flex-direction: column; gap: 6px;">
          <label style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Reason for Rejection <span style="color: var(--petron-red);">*</span></label>
          <textarea name="reject_reason" class="form-field-input" required rows="3" placeholder="Explain why the request is rejected..."></textarea>
        </div>
      </div>
      <div class="reject-modal-footer">
        <button type="button" class="btn-po-action" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn-po-action btn-reject">Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<!-- Script to implement the interactive dynamic system -->
<script>
// JSON datasets passed from the PHP backend controller
const pendingRequests = <?= json_encode($pending_requests) ?>;
const generatedPos = <?= json_encode($generated_pos) ?>;
const waitingDeliveries = <?= json_encode($waiting_deliveries) ?>;
const completedPos = <?= json_encode($completed_pos) ?>;

// State management
let activeSubTab = '<?= $active_type ?>'; // 'merch' or 'fuel'
let activeStatusTab = '<?= $active_tab ?>'; // 'pending', 'approved', 'waiting', 'completed'
let expandedRowId = null;

// Initialize view
document.addEventListener("DOMContentLoaded", () => {
  // Move modals to document.body so position:fixed covers the full viewport
  // (not clipped by the .main container which has left:250px offset)
  const rejectModal = document.getElementById('rejectModal');
  if (rejectModal && rejectModal.parentElement !== document.body) document.body.appendChild(rejectModal);

  // Select active subtab toggle buttons
  if (activeSubTab === 'fuel') {
    document.getElementById('subtab-merch').classList.remove('active');
    document.getElementById('subtab-fuel').classList.add('active');
  }
  
  // Set initial tab from PHP state
  setStatusTab(activeStatusTab);
});


function setSubTab(type) {
  activeSubTab = type;
  document.querySelectorAll('.sub-tab-btn').forEach(btn => btn.classList.remove('active'));
  if (type === 'merch') {
    document.getElementById('subtab-merch').classList.add('active');
  } else {
    document.getElementById('subtab-fuel').classList.add('active');
  }
  
  expandedRowId = null;
  renderSummaryCounts();
  applyFilters();
}

function setStatusTab(tab) {
  activeStatusTab = tab;
  document.querySelectorAll('.po-card').forEach(card => card.classList.remove('active'));
  document.getElementById('card-' + tab).classList.add('active');
  
  expandedRowId = null;
  renderSummaryCounts();
  applyFilters();
}

function renderSummaryCounts() {
  // Count matching types
  const pendingCount = pendingRequests.filter(r => r.po_type === activeSubTab).length;
  const approvedCount = generatedPos.filter(r => r.po_type === activeSubTab).length;
  
  // For waiting deliveries we match type:
  // merchandise -> merch, fuel -> fuel
  const waitingCount = waitingDeliveries.filter(r => {
    const t = r.delivery_type === 'fuel' || r.delivery_type === 'fuel_oversight' ? 'fuel' : 'merch';
    return t === activeSubTab;
  }).length;

  const completedCount = completedPos.filter(r => {
    const t = r.delivery_type === 'fuel' || r.delivery_type === 'fuel_oversight' ? 'fuel' : 'merch';
    return t === activeSubTab;
  }).length;

  const totalCount = pendingCount + approvedCount + waitingCount + completedCount;

  document.getElementById('val-pending').textContent = pendingCount;
  document.getElementById('val-approved').textContent = approvedCount;
  document.getElementById('val-waiting').textContent = waitingCount;
  document.getElementById('val-completed').textContent = totalCount;
}

function clearFilters() {
  document.getElementById('search-po').value = '';
  document.getElementById('search-pr').value = '';
  document.getElementById('search-supplier').value = '';
  const ss = document.getElementById('search-status'); if(ss) ss.value = '';
  document.getElementById('search-date').value = '';
  applyFilters();
}

function applyFilters() {
  const searchPo  = document.getElementById('search-po').value.toLowerCase();
  const searchPr  = document.getElementById('search-pr').value.toLowerCase();
  const searchSup = document.getElementById('search-supplier').value.toLowerCase();
  const searchSt  = (document.getElementById('search-status')?.value || '');
  const searchDt  = document.getElementById('search-date').value;

  // If status filter is set, switch to matching tab
  if (searchSt && searchSt !== activeStatusTab) { setStatusTab(searchSt); return; }

  let dataset = [];
  if (activeStatusTab === 'pending')   dataset = pendingRequests.filter(r => r.po_type === activeSubTab);
  else if (activeStatusTab === 'approved')  dataset = generatedPos.filter(r => r.po_type === activeSubTab);
  else if (activeStatusTab === 'waiting')  dataset = waitingDeliveries.filter(r => {
    const t = r.delivery_type === 'fuel' ? 'fuel' : 'merch'; return t === activeSubTab;
  });
  else if (activeStatusTab === 'completed') dataset = completedPos.filter(r => {
    const t = r.delivery_type === 'fuel' ? 'fuel' : 'merch'; return t === activeSubTab;
  });

  const filtered = dataset.filter(item => {
    const poNo = (item.po_no || '').toLowerCase();
    const prNo = (item.pr_no || '').toLowerCase();
    const sup  = (item.supplier || 'petron corporation').toLowerCase();
    if (searchPo  && !poNo.includes(searchPo))  return false;
    if (searchPr  && !prNo.includes(searchPr))  return false;
    if (searchSup && !sup.includes(searchSup))  return false;
    if (searchDt) { const d = item.date || ''; if (!d.includes(searchDt)) return false; }
    return true;
  });

  renderTable(filtered);
}

// Safe data store â€“ avoids JSON-in-onclick escaping issues
const _rowStore = {};
let _rowStoreIdx = 0;
function _storeRow(r) {
  const key = 'r' + (_rowStoreIdx++);
  _rowStore[key] = r;
  return key;
}

function renderTable(data) {
  const tbody = document.getElementById('poTableBody');
  tbody.innerHTML = '';

  if (data.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);"><i class="fas fa-folder-open" style="font-size:24px;display:block;margin-bottom:8px;"></i>No purchase orders found.</td></tr>`;
    return;
  }

  // â”€â”€ Group rows by date so same-date entries appear as ONE row â”€â”€
  const groups = {};
  const order  = [];
  data.forEach(r => {
    const d = r.date || 'unknown';
    if (!groups[d]) { groups[d] = []; order.push(d); }
    groups[d].push(r);
  });

  let badgeClass = 'badge-pending', statusText = 'Pending Admin Review';
  if (activeStatusTab === 'approved')  { badgeClass = 'badge-approved';  statusText = 'Approved'; }
  else if (activeStatusTab === 'waiting')   { badgeClass = 'badge-printed';   statusText = 'Printed / Waiting Delivery'; }
  else if (activeStatusTab === 'completed') { badgeClass = 'badge-completed'; statusText = 'Completed'; }

  order.forEach(d => {
    const rows = groups[d];
    // Merge into a combined record for this date
    const first = rows[0];
    const merged = Object.assign({}, first, { _group: rows });

    // PO Nos â€“ collect unique non-null values
    const poNos = [...new Set(rows.map(r => r.po_no).filter(Boolean))];
    merged.po_no = poNos.length === 1 ? poNos[0]
                 : poNos.length > 1  ? poNos[0] + ' +' + (poNos.length - 1)
                 : null;

    // PR Nos â€“ collect all unique values
    const prNos = [...new Set(rows.map(r => r.pr_no).filter(Boolean))];
    merged.pr_no = prNos.join(', ') || null;
    merged._all_pr_nos = prNos;

    // Total product count across the group
    merged.total_items = rows.reduce((s, r) =>
      s + (r.total_items || (r.items ? r.items.length : 1)), 0);

    // Generated by â€“ first non-empty value
    merged.generated_by = rows.map(r => r.generated_by || r.manager_name).find(Boolean) || 'Manager';

    const key = _storeRow(merged);
    const poLabel = merged.po_no || '[Pending Approve]';
    const prLabel = merged.pr_no || '-';
    const dateFmt = formatDate(d);
    const products = merged.total_items + ' Product(s)';

    const tr = document.createElement('tr');
    tr.className = 'main-row';
    tr.id = 'row-' + key;
    tr.style.cursor = 'pointer';
    tr.onclick = () => toggleRowDetails(key);
    tr.innerHTML = `
      <td><a href="#" style="font-weight:700;color:#002F6C;text-decoration:none;" onclick="event.stopPropagation(); toggleRowDetails('${key}'); return false;">${escHtml(poLabel)}</a></td>
      <td><a href="#" style="font-family:monospace;font-size:12px;background:#F1F5F9;color:#475569;padding:2px 8px;border-radius:4px;font-weight:700;text-decoration:none;" onclick="event.stopPropagation(); toggleRowDetails('${key}'); return false;">${escHtml(prLabel)}</a></td>
      <td>${escHtml(merged.generated_by)}</td>
      <td>${dateFmt}</td>
      <td style="text-align:center;font-weight:700;">${products}</td>
      <td><span class="badge-status ${badgeClass}">${statusText}</span></td>
    `;
    tbody.appendChild(tr);
  });
}

function formatDate(dStr) {
  if (!dStr) return '';
  const date = new Date(dStr);
  if (isNaN(date)) return dStr;
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

let currentExpandedKey = null;

function toggleRowDetails(key) {
  const mainRow = document.getElementById('row-' + key);
  if (!mainRow) return;

  const existingDetailsRow = document.getElementById('details-' + key);
  if (existingDetailsRow) {
    existingDetailsRow.remove();
    mainRow.classList.remove('expanded');
    currentExpandedKey = null;
    return;
  }

  if (currentExpandedKey) {
    const prevDetails = document.getElementById('details-' + currentExpandedKey);
    if (prevDetails) prevDetails.remove();
    const prevMain = document.getElementById('row-' + currentExpandedKey);
    if (prevMain) prevMain.classList.remove('expanded');
  }

  currentExpandedKey = key;
  mainRow.classList.add('expanded');

  const r = _rowStore[key];
  const isPending = activeStatusTab === 'pending';
  const stationName = `<?= addslashes($station_name) ?>`;
  const stationAddr = `<?= addslashes($station_address ?? $station_name) ?>`;
  const deliveryAddress = (stationAddr && stationAddr !== stationName) ? stationAddr : stationName;

  const allPRs = (r._all_pr_nos && r._all_pr_nos.length > 1 ? r._all_pr_nos.join(', ') : r.pr_no) || 'â€”';

  const fmtDate = v => {
    if (!v || v === 'null') return 'â€”';
    const d = new Date(v);
    return isNaN(d) ? v : d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
  };

  const expDt = (() => { const d=new Date(); d.setDate(d.getDate()+3); return d.toISOString().split('T')[0]; })();

  let detailsHtml = '';
  if (isPending) {
    const prForForm = (r._all_pr_nos && r._all_pr_nos[0]) || r.pr_no || '';
    detailsHtml = `
      <form method="POST" action="admin_purchase_orders.php" id="poForm-${key}">
        <input type="hidden" name="action" value="finalize_batch">
        <input type="hidden" name="po_type" value="${escHtml(r.po_type || 'merch')}">
        <input type="hidden" name="po_date" value="${escHtml(r.date || '')}">
        <input type="hidden" name="pr_number" value="${escHtml(prForForm)}">
        <input type="hidden" name="submit_action" value="print_po">

        <div class="po-details-card">
          <p class="po-card-section-title"><i class="fas fa-info-circle"></i> Purchase Order Information</p>

          <div class="po-info-grid">
            <div>
              <div class="po-info-row">
                <span class="po-info-label">PO No.</span>
                <span class="po-info-value" style="color:#64748b; font-style:italic;">[Auto-Generated upon approval]</span>
              </div>
              <div class="po-info-row">
                <span class="po-info-label">PR No.</span>
                <span class="po-info-value">${escHtml(allPRs)}</span>
              </div>
              <div class="po-info-row">
                <span class="po-info-label">Supplier</span>
                <span class="po-info-value">Petron Corporation</span>
              </div>
              <div class="po-info-row">
                <span class="po-info-label">Branch</span>
                <span class="po-info-value">${escHtml(stationName)}</span>
              </div>
              <div class="po-info-row">
                <span class="po-info-label">Generated By</span>
                <span class="po-info-value">${escHtml(r.generated_by || r.manager_name)}</span>
              </div>
              <div class="po-info-row">
                <span class="po-info-label">PO Date</span>
                <span class="po-info-value">${fmtDate(r.date)}</span>
              </div>
            </div>
            <div>
              <div class="po-info-row">
                <span class="po-info-label">Delivery Date <span style="color:#E31837">*</span></span>
                <input type="date" name="expected_delivery_date" value="${expDt}" required class="po-info-input">
              </div>
              <div class="po-info-row">
                <span class="po-info-label">Delivery Time</span>
                <select name="expected_delivery_time" class="po-info-select">
                  <option value="09:00">09:00 AM</option>
                  <option value="14:00">02:00 PM</option>
                </select>
              </div>
              <div class="po-info-row">
                <span class="po-info-label">Payment Terms</span>
                <select name="payment_terms" class="po-info-select">
                  <option value="30 Days">30 Days (Net 30)</option>
                  <option value="Cash">Cash</option>
                  <option value="COD">COD</option>
                </select>
              </div>
              <div class="po-info-row" style="align-items:flex-start;">
                <span class="po-info-label" style="padding-top:4px;">Delivery Address</span>
                <div class="po-addr-box" style="flex:1;">${escHtml(deliveryAddress)}</div>
              </div>
              <div class="po-info-row" style="align-items:flex-start;">
                <span class="po-info-label" style="padding-top:4px;">Remarks</span>
                <textarea name="remarks" rows="2" class="po-textarea" style="flex:1;" placeholder="Enter remarks..."></textarea>
              </div>
            </div>
          </div>

          <p class="po-card-section-title" style="margin-top:20px;"><i class="fas fa-box-open"></i> Ordered Products</p>
          <div class="po-products-wrap">
            <table class="po-products-table">
              <thead>
                <tr>
                  <th>Product ID</th>
                  <th>Product Code</th>
                  <th>Product Name</th>
                  <th>Category</th>
                  <th class="right">Qty</th>
                  <th>Unit</th>
                  <th class="right">Unit Cost</th>
                  <th class="right">Total Amount</th>
                </tr>
              </thead>
              <tbody id="prod-body-${key}">
                <tr><td colspan="8" style="text-align:center;padding:14px;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading products...</td></tr>
              </tbody>
            </table>
          </div>

          <div style="display:flex; justify-content:space-between; align-items:center; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:12px 16px; margin-top:14px;">
            <div style="display:flex; flex-direction:column; gap:2px;">
              <span style="font-size:10px; font-weight:700; color:#0369a1; text-transform:uppercase;">Total Products</span>
              <span id="total-count-${key}" style="font-size:16px; font-weight:800; color:#1e293b;">&mdash;</span>
            </div>
            <div style="display:flex; flex-direction:column; gap:2px; text-align:right;">
              <span style="font-size:10px; font-weight:700; color:#0369a1; text-transform:uppercase;">Grand Total Amount</span>
              <span id="grand-total-${key}" style="font-size:18px; font-weight:800; color:#16a34a;">&mdash;</span>
            </div>
          </div>

          <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:18px;">
            <button type="button" class="po-ctrl-btn po-btn-rej" onclick="openRejectModal('${escHtml(r.po_type||'merch')}','${escHtml(prForForm)}')">
              <i class="fas fa-times-circle"></i> Reject Request
            </button>
            <button type="submit" class="po-ctrl-btn po-btn-approve">
              <i class="fas fa-print"></i> Print &amp; Approve Purchase Order
            </button>
          </div>
        </div>
      </form>`;
  } else {
    const batchId = r.po_no || r.pr_no || '';
    const printUrl = `print_po_new.php?batch_id=${encodeURIComponent(batchId)}&type=${encodeURIComponent(r.po_type||'merch')}&print=1`;

    detailsHtml = `
      <div class="po-details-card">
        <p class="po-card-section-title"><i class="fas fa-info-circle"></i> Purchase Order Information</p>

        <div class="po-info-grid">
          <div>
            <div class="po-info-row">
              <span class="po-info-label">PO No.</span>
              <span class="po-info-value">${escHtml(r.po_no)}</span>
            </div>
            <div class="po-info-row">
              <span class="po-info-label">PR No.</span>
              <span class="po-info-value">${escHtml(allPRs)}</span>
            </div>
            <div class="po-info-row">
              <span class="po-info-label">Supplier</span>
              <span class="po-info-value">Petron Corporation</span>
            </div>
            <div class="po-info-row">
              <span class="po-info-label">Branch</span>
              <span class="po-info-value">${escHtml(stationName)}</span>
            </div>
          </div>
          <div>
            <div class="po-info-row">
              <span class="po-info-label">Generated By</span>
              <span class="po-info-value">${escHtml(r.generated_by || r.manager_name)}</span>
            </div>
            <div class="po-info-row">
              <span class="po-info-label">PO Date</span>
              <span class="po-info-value">${fmtDate(r.date)}</span>
            </div>
            <div class="po-info-row">
              <span class="po-info-label">Expected Delivery</span>
              <span class="po-info-value">${fmtDate(r.expected_delivery_date)}</span>
            </div>
            <div class="po-info-row">
              <span class="po-info-label">Status</span>
              <span class="po-info-value" style="color:#002F6C; text-transform:uppercase; font-weight:700;">${escHtml(r.status)}</span>
            </div>
          </div>
        </div>

        <div class="po-info-grid" style="margin-top:4px;">
          <div>
            <div class="po-info-row" style="align-items:flex-start;">
              <span class="po-info-label" style="padding-top:4px;">Delivery Address</span>
              <div class="po-addr-box" style="flex:1;">${escHtml(deliveryAddress)}</div>
            </div>
          </div>
          <div>
            <div class="po-info-row" style="align-items:flex-start;">
              <span class="po-info-label" style="padding-top:4px;">Remarks / Notes</span>
              <div class="po-addr-box" style="flex:1; font-weight:400;">${escHtml(r.remarks || '-')}</div>
            </div>
          </div>
        </div>

        <p class="po-card-section-title" style="margin-top:20px;"><i class="fas fa-box-open"></i> Ordered Products</p>
        <div class="po-products-wrap">
          <table class="po-products-table">
            <thead>
              <tr>
                <th>Product ID</th>
                <th>Product Code</th>
                <th>Product Name</th>
                <th>Category</th>
                <th class="right">Qty</th>
                <th>Unit</th>
                <th class="right">Unit Cost</th>
                <th class="right">Total Amount</th>
              </tr>
            </thead>
            <tbody id="prod-body-${key}">
              <tr><td colspan="8" style="text-align:center;padding:14px;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading products...</td></tr>
            </tbody>
          </table>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:12px 16px; margin-top:14px;">
          <div style="display:flex; flex-direction:column; gap:2px;">
            <span style="font-size:10px; font-weight:700; color:#0369a1; text-transform:uppercase;">Total Products</span>
            <span id="total-count-${key}" style="font-size:16px; font-weight:800; color:#1e293b;">&mdash;</span>
          </div>
          <div style="display:flex; flex-direction:column; gap:2px; text-align:right;">
            <span style="font-size:10px; font-weight:700; color:#0369a1; text-transform:uppercase;">Grand Total Amount</span>
            <span id="grand-total-${key}" style="font-size:18px; font-weight:800; color:#16a34a;">&mdash;</span>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:18px;">
          <a href="${printUrl}" target="_blank" class="po-ctrl-btn" style="text-decoration:none; background:#002F6C; color:#fff; border-color:#002F6C;">
            <i class="fas fa-print"></i> Print PO Document
          </a>
        </div>
      </div>`;
  }


  const detailsRow = document.createElement('tr');
  detailsRow.id = 'details-' + key;
  detailsRow.className = 'details-row';
  detailsRow.innerHTML = `<td colspan="7" style="padding: 20px 24px; background: #f8fafc; border-bottom: 2px solid #cbd5e1;">${detailsHtml}</td>`;
  mainRow.parentNode.insertBefore(detailsRow, mainRow.nextSibling);

  fetchPOItemsInline(key, r, isPending);
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function recalcGrandTotalInline(key) {
  const form = document.getElementById('poForm-' + key);
  if (!form) return;
  const costInputs = form.querySelectorAll('.po-cost-input');
  let grandTotal = 0;
  costInputs.forEach(input => {
    const qty = parseFloat(input.getAttribute('data-qty')) || 0;
    const price = parseFloat(input.value) || 0;
    const rowTotal = qty * price;
    grandTotal += rowTotal;
    const rowId = input.getAttribute('data-row-id');
    const cell = document.getElementById(`rt-${key}-${rowId}`);
    if (cell) cell.textContent = '\u20b1' + rowTotal.toFixed(2);
  });
  const span = document.getElementById('grand-total-' + key);
  if (span) span.textContent = '\u20b1' + grandTotal.toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2});
}

function fetchPOItemsInline(key, r, isPending) {
  const targetTbody = document.getElementById('prod-body-' + key);
  if (!targetTbody) return;

  const poType = r.po_type || 'merch';
  let urls = [];

  if (isPending) {
    const prNos = (r._all_pr_nos && r._all_pr_nos.length)
      ? r._all_pr_nos
      : [r.pr_no].filter(Boolean);
    urls = prNos.map(pr =>
      `admin_purchase_orders.php?ajax=1&action=get_pending_items&type=${encodeURIComponent(poType)}&pr_number=${encodeURIComponent(pr)}`
    );
  } else if (activeStatusTab === 'waiting' || activeStatusTab === 'completed') {
    urls = [`admin_purchase_orders.php?ajax=1&action=get_delivery_details&batch_id=${encodeURIComponent(r.po_no||r.pr_no||'')}`];
  } else {
    const group = r._group || [r];
    urls = group.map(g =>
      `admin_purchase_orders.php?ajax=1&action=get_generated_items&type=${encodeURIComponent(g.po_type||poType)}&batch_id=${encodeURIComponent(g.po_no||'')}`
    );
  }

  Promise.all(urls.map(url => fetch(url).then(res => res.json()).catch(() => ({success:false,items:[]}))))
    .then(results => {
      const allItems = results.flatMap(d => (d.success && d.items) ? d.items : []);
      if (allItems.length === 0) {
        targetTbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:15px;color:#64748b;">No items found for this order.</td></tr>`;
        return;
      }
      let html = ''; let total = 0;
      allItems.forEach((item, idx) => {
        const pid = 'P' + String(idx+1).padStart(4,'0');
        const qty = parseFloat(item.quantity) || 0;
        const price = parseFloat(item.unit_price || item.cost_price || item.approved_price || 0);
        const rowTotal = qty * price;
        total += rowTotal;
        const rowBg = idx % 2 === 0 ? '#ffffff' : '#f8fafc';
        
        if (isPending) {
          html += `<tr style="background:${rowBg};">
            <td style="padding:9px 12px;font-weight:700;color:#002F6C;">${pid}</td>
            <td style="padding:9px 12px;font-family:monospace;font-size:11px;">${escHtml(item.product_code||'-')}</td>
            <td style="padding:9px 12px;font-weight:600;">${escHtml(item.product_name||'-')}</td>
            <td style="padding:9px 12px;">${escHtml(item.category||'-')}</td>
            <td style="padding:9px 12px;text-align:center;font-weight:700;">${qty.toLocaleString()}</td>
            <td style="padding:9px 12px;text-align:center;">${escHtml(item.unit||'pcs')}</td>
            <td style="padding:9px 12px;text-align:right;">
              <input type="number" step="0.01" name="items[${item.id}][price]" value="${price.toFixed(2)}" class="po-cost-input" style="width: 90px; padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: right;" oninput="recalcGrandTotalInline('${key}')" data-qty="${qty}" data-row-id="${item.id}">
              <input type="hidden" name="items[${item.id}][qty]" value="${qty}">
            </td>
            <td style="padding:9px 12px;text-align:right;font-weight:700;" id="rt-${key}-${item.id}">\u20b1${rowTotal.toFixed(2)}</td>
          </tr>`;
        } else {
          html += `<tr style="background:${rowBg};">
            <td style="padding:9px 12px;font-weight:700;color:#002F6C;">${pid}</td>
            <td style="padding:9px 12px;font-family:monospace;font-size:11px;">${escHtml(item.product_code||'-')}</td>
            <td style="padding:9px 12px;font-weight:600;">${escHtml(item.product_name||'-')}</td>
            <td style="padding:9px 12px;">${escHtml(item.category||'-')}</td>
            <td style="padding:9px 12px;text-align:center;font-weight:700;">${qty.toLocaleString()}</td>
            <td style="padding:9px 12px;text-align:center;">${escHtml(item.unit||'pcs')}</td>
            <td style="padding:9px 12px;text-align:right;">\u20b1${price.toFixed(2)}</td>
            <td style="padding:9px 12px;text-align:right;font-weight:700;">\u20b1${rowTotal.toFixed(2)}</td>
          </tr>`;
        }
      });
      targetTbody.innerHTML = html;
      const tc = document.getElementById('total-count-' + key);
      if (tc) tc.textContent = allItems.length + ' Product(s)';
      const gt = document.getElementById('grand-total-' + key);
      if (gt) gt.textContent = '\u20b1' + total.toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2});
    });
}

function openRejectModal(poType, prNumber) {
  document.getElementById('reject-po-type').value = poType;
  document.getElementById('reject-pr-number').value = prNumber;
  document.getElementById('rejectModal').classList.add('open');
}

function closeRejectModal() {
  document.getElementById('rejectModal').classList.remove('open');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
