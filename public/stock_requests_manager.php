<?php
// Manager Stock Requests Validation Page
$page_id = 'stock_requests';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// Manager only access
if ($role !== 'manager') {
  header('Location: dashboard.php');
  exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8em;
    font-weight: bold;
    color: white;
}

.status-pending { background-color: #ffc107; color: #212529; }
.status-approved { background-color: #28a745; }
.status-rejected { background-color: #dc3545; }
.status-completed { background-color: #17a2b8; }

.action-buttons {
    display: flex;
    gap: 5px;
}

.btn-sm {
    padding: 4px 8px;
    font-size: 0.8em;
}

.request-details {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 15px;
}

.request-item {
    border-left: 4px solid #007bff;
    padding: 15px;
    margin-bottom: 15px;
    background: white;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.request-item.pending {
    border-left-color: #ffc107;
}

.request-item.approved {
    border-left-color: #28a745;
}

.request-item.rejected {
    border-left-color: #dc3545;
}

.modal-body textarea {
    resize: vertical;
}

.table-responsive {
    max-height: 600px;
    overflow-y: auto;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1">Stock Requests Management</h1>
        <div class="sub">Validate and approve staff stock requests</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
        <button onclick="exportRequests()" class="btn primary"><i class="fas fa-download"></i> Export</button>
    </div>
</div>

<div class="tabs pills">
    <button class="tab active" data-tab="pending">Pending Requests</button>
    <button class="tab" data-tab="approved">Approved Requests</button>
    <button class="tab" data-tab="all">All Requests</button>
</div>

<!-- Pending Requests -->
<section class="card" id="pending-section">
    <div class="card-head">
        <div class="card-title">Pending Stock Requests</div>
        <div class="muted">Requests awaiting your approval</div>
    </div>
    <div class="table-wrap">
        <div class="table-responsive">
            <table class="table" id="pendingTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Staff</th>
                        <th>Item</th>
                        <th>SKU</th>
                        <th>Current Stock</th>
                        <th>Requested</th>
                        <th>Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="pendingBody">
                    <!-- Requests will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Approved Requests -->
<section class="card hidden" id="approved-section">
    <div class="card-head">
        <div class="card-title">Approved Stock Requests</div>
        <div class="muted">Requests that have been approved</div>
    </div>
    <div class="table-wrap">
        <div class="table-responsive">
            <table class="table" id="approvedTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Staff</th>
                        <th>Item</th>
                        <th>SKU</th>
                        <th>Requested</th>
                        <th>Approved</th>
                        <th>Approved By</th>
                        <th>Approved Date</th>
                        <th>Manager Notes</th>
                    </tr>
                </thead>
                <tbody id="approvedBody">
                    <!-- Requests will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>
</section>


<!-- All Requests -->
<section class="card hidden" id="all-section">
    <div class="card-head">
        <div class="card-title">All Stock Requests</div>
        <div class="muted">Complete history of stock requests</div>
    </div>
    <div class="table-wrap">
        <div class="table-responsive">
            <table class="table" id="allTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Staff</th>
                        <th>Item</th>
                        <th>SKU</th>
                        <th>Current Stock</th>
                        <th>Requested</th>
                        <th>Approved</th>
                        <th>Status</th>
                        <th>Manager Notes</th>
                    </tr>
                </thead>
                <tbody id="allBody">
                    <!-- Requests will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Approve Request Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="approveModalLabel">
                    <i class="fas fa-check-circle"></i> Approve Stock Request
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="request-details" id="approveRequestDetails">
                    <!-- Request details will be populated here -->
                </div>
                <form id="approveForm">
                    <input type="hidden" id="approveRequestId" name="request_id">
                    
                    <div class="mb-3">
                        <label for="approvedQuantity" class="form-label">Approved Quantity *</label>
                        <input type="number" step="0.01" class="form-control" id="approvedQuantity" name="approved_quantity" 
                               min="0.01" required>
                        <small class="text-muted">Enter the quantity to approve</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="approvedPrice" class="form-label">Unit Price *</label>
                        <input type="number" step="0.01" class="form-control" id="approvedPrice" name="approved_price" 
                               min="0" required>
                        <small class="text-muted">Edit price if necessary</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="managerNotes" class="form-label">Manager Notes</label>
                        <textarea class="form-control" id="managerNotes" name="manager_notes" rows="3" 
                                  placeholder="Optional notes for this approval"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmApprove">
                    <i class="fas fa-check"></i> Approve Request
                </button>
            </div>
        </div>
    </div>
</div>



<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('section.card').forEach(s => s.classList.add('hidden'));
            
            this.classList.add('active');
            const tabName = this.dataset.tab;
            document.getElementById(tabName + '-section').classList.remove('hidden');
            
            // Load data for the tab
            loadRequests(tabName);
        });
    });

    // Initialize modals
    const approveModal = new bootstrap.Modal(document.getElementById('approveModal'));

    // Load initial data
    loadRequests('pending');

    function loadRequests(status) {
        fetch('backend/api/stock_requests.php?action=get_requests')
        .then(response => response.json())
        .then(data => {
            const requests = data.requests || [];
            const filteredRequests = status === 'all' ? requests : requests.filter(r => r.status.toLowerCase() === status);
            
            if (status === 'pending') {
                renderPendingRequests(filteredRequests);
            } else if (status === 'approved') {
                renderApprovedRequests(filteredRequests);
            } else {
                renderAllRequests(filteredRequests);
            }
        })
        .catch(error => {
            console.error('Error loading requests:', error);
        });
    }

    function renderPendingRequests(requests) {
        const tbody = document.getElementById('pendingBody');
        tbody.innerHTML = '';
        
        if (requests.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center">No pending requests</td></tr>';
            return;
        }
        
        requests.forEach(request => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${formatDate(request.created_at)}</td>
                <td>${request.staff_name}</td>
                <td>${request.item_name}</td>
                <td>${request.item_sku}</td>
                <td>${request.current_stock}</td>
                <td>${request.requested_quantity}</td>
                <td>${request.remarks || '-'}</td>
                <td>
                        <button class="btn btn-sm btn-success approve-btn" 
                                data-request-id="${request.id}"
                                data-item-name="${request.item_name}"
                                data-requested-qty="${request.requested_quantity}"
                                data-cost-per-unit="${request.cost_per_unit || 0}">
                            <i class="fas fa-check"></i> Approve
                        </button>
                    </div>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    function renderApprovedRequests(requests) {
        const tbody = document.getElementById('approvedBody');
        tbody.innerHTML = '';
        
        if (requests.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center">No approved requests</td></tr>';
            return;
        }
        
        requests.forEach(request => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${formatDate(request.created_at)}</td>
                <td>${request.staff_name}</td>
                <td>${request.item_name}</td>
                <td>${request.item_sku}</td>
                <td>${request.requested_quantity}</td>
                <td>${request.approved_quantity}</td>
                <td>${request.manager_name || '-'}</td>
                <td>${formatDate(request.approved_at)}</td>
                <td>${request.manager_notes || '-'}</td>
            `;
            tbody.appendChild(row);
        });
    }


    function renderAllRequests(requests) {
        const tbody = document.getElementById('allBody');
        tbody.innerHTML = '';
        
        if (requests.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center">No requests found</td></tr>';
            return;
        }
        
        requests.forEach(request => {
            const row = document.createElement('tr');
            const statusBadge = `<span class="status-badge status-${request.status.toLowerCase()}">${request.status}</span>`;
            
            row.innerHTML = `
                <td>${formatDate(request.created_at)}</td>
                <td>${request.staff_name}</td>
                <td>${request.item_name}</td>
                <td>${request.item_sku}</td>
                <td>${request.current_stock}</td>
                <td>${request.requested_quantity}</td>
                <td>${request.approved_quantity || '-'}</td>
                <td>${statusBadge}</td>
                <td>${request.manager_notes || '-'}</td>
            `;
            tbody.appendChild(row);
        });
    }

    // Handle approve button clicks
    document.addEventListener('click', function(e) {
        if (e.target.closest('.approve-btn')) {
            const btn = e.target.closest('.approve-btn');
            const requestId = btn.dataset.requestId;
            const itemName = btn.dataset.itemName;
            const requestedQty = btn.dataset.requestedQty;
            const costPerUnit = btn.dataset.costPerUnit;
            
            // Populate modal
            document.getElementById('approveRequestId').value = requestId;
            document.getElementById('approvedQuantity').value = requestedQty;
            document.getElementById('approvedPrice').value = costPerUnit;
            document.getElementById('managerNotes').value = '';
            
            document.getElementById('approveRequestDetails').innerHTML = `
                <strong>Item:</strong> ${itemName}<br>
                <strong>Requested Quantity:</strong> ${requestedQty}<br>
                <strong>Staff:</strong> ${btn.closest('tr').cells[1].textContent}
            `;
            
            approveModal.show();
        }
    });


    // Confirm approve
    document.getElementById('confirmApprove').addEventListener('click', function() {
        const form = document.getElementById('approveForm');
        const formData = new FormData(form);
        
        const approvedQuantity = parseInt(formData.get('approved_quantity'));
        if (approvedQuantity < 1) {
            alert('Please enter a valid approved quantity');
            return;
        }
        
        fetch('backend/api/stock_requests.php?action=approve_request', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Stock request approved successfully!');
                approveModal.hide();
                loadRequests('pending');
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while approving the request');
        });
    });


    function formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }

    function exportRequests() {
        // Simple CSV export
        fetch('backend/api/stock_requests.php?action=get_requests')
        .then(response => response.json())
        .then(data => {
            const requests = data.requests || [];
            let csv = 'Date,Staff,Item,SKU,Current Stock,Requested,Approved,Status,Manager Notes\n';
            
            requests.forEach(request => {
                csv += `"${formatDate(request.created_at)}","${request.staff_name}","${request.item_name}","${request.item_sku}",${request.current_stock},${request.requested_quantity},${request.approved_quantity || ''},"${request.status}","${request.manager_notes || ''}"\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `stock_requests_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
        })
        .catch(error => {
            console.error('Error exporting requests:', error);
            alert('Error exporting requests');
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
