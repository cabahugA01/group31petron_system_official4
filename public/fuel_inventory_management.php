<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Inventory & PO Management - Petron POS</title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        .admin-dashboard {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
        }
        .inventory-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
            transition: transform 0.2s;
        }
        .inventory-card:hover {
            transform: translateY(-2px);
        }
        .stock-indicator {
            height: 8px;
            border-radius: 4px;
            margin-top: 10px;
        }
        .stock-critical { background: #dc3545; }
        .stock-low { background: #ffc107; }
        .stock-moderate { background: #17a2b8; }
        .stock-good { background: #28a745; }
        .po-card {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid #007bff;
        }
        .po-pending { border-left-color: #ffc107; }
        .po-delivered { border-left-color: #28a745; }
        .po-cancelled { border-left-color: #dc3545; }
        .alert-card {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
        }
        .form-section {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .btn-create-po {
            background: #007bff;
            border: none;
            border-radius: 8px;
            padding: 10px 25px;
            font-weight: bold;
        }
        .consumption-chart {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php include '../partials/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Admin Dashboard Header -->
        <div class="admin-dashboard">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2>Fuel Inventory & PO Management</h2>
                    <p class="mb-0">Manage fuel inventory, create purchase orders, and monitor stock levels</p>
                </div>
                <div class="col-md-4 text-end">
                    <button class="btn btn-light btn-lg" onclick="showCreatePOForm()">
Create PO
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Low Stock Alerts -->
        <div class="row mb-4" id="alertsSection" style="display: none;">
            <div class="col-12">
                <div class="alert alert-warning">
                    <h5>Low Stock Alerts</h5>
                    <div id="alertsList"></div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Current Inventory Status -->
            <div class="col-md-8">
                <h4 class="mb-3">Current Inventory Status</h4>
                <div id="inventoryList">
                    <div class="text-center p-4">
Loading inventory...
                    </div>
                </div>
                
                <!-- Consumption Chart -->
                <div class="consumption-chart mt-4">
                    <h5>30-Day Consumption Trend</h5>
                    <canvas id="consumptionChart" height="100"></canvas>
                </div>
            </div>
            
            <!-- Recent Purchase Orders -->
            <div class="col-md-4">
                <h4 class="mb-3">Recent Purchase Orders</h4>
                <div id="poList">
                    <div class="text-center p-4">
Loading purchase orders...
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="card mt-3">
                    <div class="card-body">
                        <h6>Monthly Summary</h6>
                        <div id="monthlyStats"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create PO Modal -->
    <div class="modal fade" id="createPOModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Fuel Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createPOForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="fuelType" class="form-label">Fuel Type</label>
                                    <select class="form-select" id="fuelType" required>
                                        <option value="">Select fuel type...</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="supplier" class="form-label">Supplier</label>
                                    <select class="form-select" id="supplier" required>
                                        <option value="">Select supplier...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="volume" class="form-label">Volume (Liters)</label>
                                    <input type="number" step="0.01" class="form-control" id="volume" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="unitPrice" class="form-label">Unit Price (₱/L)</label>
                                    <input type="number" step="0.01" class="form-control" id="unitPrice" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="expectedDelivery" class="form-label">Expected Delivery</label>
                                    <input type="date" class="form-control" id="expectedDelivery" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" rows="3" placeholder="Additional notes..."></textarea>
                        </div>
                        
                        <div class="text-center">
                            <div class="alert alert-info">
                                <strong>Total Amount:</strong> ₱<span id="totalAmount">0.00</span>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createPurchaseOrder()">Create PO</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Confirm Delivery Modal -->
    <div class="modal fade" id="confirmDeliveryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delivery</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="deliveryDetails"></div>
                    <form id="confirmDeliveryForm">
                        <div class="mb-3">
                            <label for="actualVolume" class="form-label">Actual Delivered Volume (Liters)</label>
                            <input type="number" step="0.01" class="form-control" id="actualVolume" required>
                        </div>
                        <div class="mb-3">
                            <label for="deliveryNotes" class="form-label">Delivery Notes</label>
                            <textarea class="form-control" id="deliveryNotes" rows="3" placeholder="Any discrepancies or notes..."></textarea>
                        </div>
                        <input type="hidden" id="deliveryPOId">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="confirmDelivery()">Confirm Delivery</button>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../partials/footer.php'; ?>
    
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/vendor/chart.js/chart.umd.min.js"></script>
    <script>
        let currentUser = null;
        let createPOModal = null;
        let confirmDeliveryModal = null;
        let consumptionChart = null;
        
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
                
                // Check if user has admin role
                if (!['admin', 'superadmin'].includes(currentUser.role.toLowerCase())) {
                    alert('Access denied. Admin access required.');
                    window.location.href = '../public/dashboard.php';
                    return;
                }
                
                // Initialize modals
                createPOModal = new bootstrap.Modal(document.getElementById('createPOModal'));
                confirmDeliveryModal = new bootstrap.Modal(document.getElementById('confirmDeliveryModal'));
                
                // Load data
                await loadInventoryStatus();
                await loadPurchaseOrders();
                await loadLowStockAlerts();
                await loadConsumptionData();
                
                // Setup event listeners
                setupEventListeners();
                
            } catch (error) {
                console.error('Initialization error:', error);
                alert('Failed to initialize page');
            }
        }
        
        function setupEventListeners() {
            // PO form calculations
            ['volume', 'unitPrice'].forEach(id => {
                document.getElementById(id).addEventListener('input', calculateTotalAmount);
            });
        }
        
        async function loadInventoryStatus() {
            try {
                const response = await fetch('../backend/api/fuel_purchase_orders.php?action=get_inventory_status');
                const data = await response.json();
                
                if (data.success) {
                    displayInventory(data.data);
                }
            } catch (error) {
                console.error('Error loading inventory:', error);
            }
        }
        
        function displayInventory(inventory) {
            const inventoryList = document.getElementById('inventoryList');
            
            if (inventory.length > 0) {
                inventoryList.innerHTML = `
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Product Name</th>
                                    <th>Description</th>
                                    <th>Current Stock</th>
                                    <th>Status</th>
                                    <th>Days Left</th>
                                    <th>Daily Avg</th>
                                    <th>Monthly</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${inventory.map(item => `
                                    <tr>
                                        <td><strong>${item.fuel_type_name}</strong></td>
                                        <td>${item.description || 'N/A'}</td>
                                        <td><h6 class="mb-0">${item.current_stock.toFixed(0)}L</h6></td>
                                        <td><span class="badge bg-${item.stock_status.color}">${item.stock_status.text}</span></td>
                                        <td>${item.days_of_stock} days</td>
                                        <td>${(item.daily_average || 0).toFixed(0)}L</td>
                                        <td>${(item.monthly_deliveries || 0).toFixed(0)}L</td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" onclick="quickCreatePO('${item.fuel_type_name}', ${item.fuel_type_id})">
Quick PO
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            } else {
                inventoryList.innerHTML = `
                    <div class="text-center p-4 text-muted">
No inventory data available
                    </div>
                `;
            }
        }
        
        async function loadPurchaseOrders() {
            try {
                const response = await fetch('../backend/api/fuel_purchase_orders.php?action=get_po_list');
                const data = await response.json();
                
                if (data.success) {
                    displayPurchaseOrders(data.data);
                }
            } catch (error) {
                console.error('Error loading POs:', error);
            }
        }
        
        function displayPurchaseOrders(purchaseOrders) {
            const poList = document.getElementById('poList');
            
            if (purchaseOrders.length > 0) {
                poList.innerHTML = purchaseOrders.slice(0, 5).map(po => `
                    <div class="po-card po-${po.status}">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6>${po.po_number}</h6>
                                <small class="text-muted">${po.fuel_type_name} • ${po.volume}L</small><br>
                                <small>Supplier: ${po.supplier_name}</small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-${getStatusColor(po.status)}">${po.status}</span><br>
                                <small>₱${po.total_amount.toFixed(2)}</small>
                            </div>
                        </div>
                        <div class="mt-2">
                            ${po.status === 'pending' ? 
                                `<button class="btn btn-sm btn-success" onclick="showConfirmDelivery(${po.id}, '${po.po_number}', '${po.fuel_type_name}', ${po.volume})">
Confirm Delivery
                                </button>` : 
                                `<small class="text-muted">Created: ${new Date(po.created_at).toLocaleDateString()}</small>`
                            }
                        </div>
                    </div>
                `).join('');
                
                // Update monthly stats
                updateMonthlyStats(purchaseOrders);
            } else {
                poList.innerHTML = `
                    <div class="text-center p-4 text-muted">
No purchase orders found
                    </div>
                `;
            }
        }
        
        function getStatusColor(status) {
            const colors = {
                'pending': 'warning',
                'delivered': 'success',
                'cancelled': 'danger'
            };
            return colors[status] || 'secondary';
        }
        
        function updateMonthlyStats(purchaseOrders) {
            const currentMonth = new Date().getMonth();
            const currentYear = new Date().getFullYear();
            
            const monthlyPOs = purchaseOrders.filter(po => {
                const poDate = new Date(po.created_at);
                return poDate.getMonth() === currentMonth && poDate.getFullYear() === currentYear;
            });
            
            const totalVolume = monthlyPOs.reduce((sum, po) => sum + po.volume, 0);
            const totalAmount = monthlyPOs.reduce((sum, po) => sum + po.total_amount, 0);
            const pendingCount = monthlyPOs.filter(po => po.status === 'pending').length;
            
            document.getElementById('monthlyStats').innerHTML = `
                <div class="d-flex justify-content-between mb-2">
                    <span>POs Created:</span>
                    <strong>${monthlyPOs.length}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Total Volume:</span>
                    <strong>${totalVolume.toFixed(0)}L</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Total Value:</span>
                    <strong>₱${totalAmount.toFixed(2)}</strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Pending Delivery:</span>
                    <strong>${pendingCount}</strong>
                </div>
            `;
        }
        
        async function loadLowStockAlerts() {
            try {
                const response = await fetch('../backend/api/fuel_purchase_orders.php?action=get_low_stock_alerts');
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    displayLowStockAlerts(data.data);
                }
            } catch (error) {
                console.error('Error loading alerts:', error);
            }
        }
        
        function displayLowStockAlerts(alerts) {
            const alertsSection = document.getElementById('alertsSection');
            const alertsList = document.getElementById('alertsList');
            
            alertsList.innerHTML = alerts.map(alert => `
                <div class="alert-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <strong>${alert.fuel_type_name}</strong> - Critical level: ${alert.current_stock}L
                        </div>
                        <button class="btn btn-sm btn-warning" onclick="quickCreatePO('${alert.fuel_type_name}', ${alert.fuel_type_id})">
                            Create PO
                        </button>
                    </div>
                </div>
            `).join('');
            
            alertsSection.style.display = 'block';
        }
        
        async function loadConsumptionData() {
            try {
                const response = await fetch('../backend/api/fuel_purchase_orders.php?action=get_consumption_report&days=30');
                const data = await response.json();
                
                if (data.success) {
                    displayConsumptionChart(data.data);
                }
            } catch (error) {
                console.error('Error loading consumption data:', error);
            }
        }
        
        function displayConsumptionChart(consumption) {
            const ctx = document.getElementById('consumptionChart').getContext('2d');
            
            consumptionChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: consumption.map(c => c.fuel_type),
                    datasets: [{
                        label: 'Total Consumed (Liters)',
                        data: consumption.map(c => c.total_consumed),
                        backgroundColor: 'rgba(54, 162, 235, 0.8)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Liters'
                            }
                        }
                    }
                }
            });
        }
        
        function showCreatePOForm() {
            loadFuelTypes();
            loadSuppliers();
            
            // Set minimum date to today
            document.getElementById('expectedDelivery').min = new Date().toISOString().split('T')[0];
            
            createPOModal.show();
        }
        
        async function loadFuelTypes() {
            try {
                const response = await fetch('../backend/api/fuel_types.php?action=list');
                const data = await response.json();
                
                if (data.success) {
                    const select = document.getElementById('fuelType');
                    select.innerHTML = '<option value="">Select fuel type...</option>' +
                        data.data.map(ft => `<option value="${ft.id}">${ft.name}</option>`).join('');
                }
            } catch (error) {
                console.error('Error loading fuel types:', error);
            }
        }
        
        async function loadSuppliers() {
            try {
                const response = await fetch('../backend/api/fuel_purchase_orders.php?action=get_suppliers');
                const data = await response.json();
                
                if (data.success) {
                    const select = document.getElementById('supplier');
                    select.innerHTML = '<option value="">Select supplier...</option>' +
                        data.data.map(s => `<option value="${s.id}">${s.name}</option>`).join('');
                }
            } catch (error) {
                console.error('Error loading suppliers:', error);
            }
        }
        
        function calculateTotalAmount() {
            const volume = parseFloat(document.getElementById('volume').value) || 0;
            const unitPrice = parseFloat(document.getElementById('unitPrice').value) || 0;
            const total = volume * unitPrice;
            
            document.getElementById('totalAmount').textContent = total.toFixed(2);
        }
        
        async function createPurchaseOrder() {
            const form = document.getElementById('createPOForm');
            const formData = new FormData(form);
            
            try {
                const response = await fetch('../backend/api/fuel_purchase_orders.php?action=create_po', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Purchase order created successfully! PO Number: ' + data.po_number);
                    createPOModal.hide();
                    form.reset();
                    await loadPurchaseOrders();
                    await loadInventoryStatus();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error creating PO:', error);
                alert('Failed to create purchase order');
            }
        }
        
        function quickCreatePO(fuelTypeName, fuelTypeId) {
            showCreatePOForm();
            // Pre-select fuel type
            setTimeout(() => {
                document.getElementById('fuelType').value = fuelTypeId;
            }, 500);
        }
        
        function showConfirmDelivery(poId, poNumber, fuelType, expectedVolume) {
            document.getElementById('deliveryPOId').value = poId;
            document.getElementById('deliveryDetails').innerHTML = `
                <div class="alert alert-info">
                    <h6>${poNumber}</h6>
                    <p class="mb-0">
                        <strong>Fuel Type:</strong> ${fuelType}<br>
                        <strong>Expected Volume:</strong> ${expectedVolume}L
                    </p>
                </div>
            `;
            document.getElementById('actualVolume').value = expectedVolume;
            
            confirmDeliveryModal.show();
        }
        
        async function confirmDelivery() {
            const poId = document.getElementById('deliveryPOId').value;
            const actualVolume = document.getElementById('actualVolume').value;
            const deliveryNotes = document.getElementById('deliveryNotes').value;
            
            if (!actualVolume || actualVolume <= 0) {
                alert('Please enter a valid actual volume');
                return;
            }
            
            try {
                const response = await fetch('../backend/api/fuel_purchase_orders.php?action=confirm_delivery', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `po_id=${poId}&actual_volume=${actualVolume}&delivery_notes=${encodeURIComponent(deliveryNotes)}`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Delivery confirmed and inventory updated!');
                    confirmDeliveryModal.hide();
                    await loadPurchaseOrders();
                    await loadInventoryStatus();
                    await loadLowStockAlerts();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error confirming delivery:', error);
                alert('Failed to confirm delivery');
            }
        }
    </script>
</body>
</html>
