<?php
/**
 * Manager Inventory Summary partial file
 * Clean, modern card design matching staff inventory style
 */
if (!isset($pdo) || !isset($me) || !isset($station_id)) {
    return;
}

$my_id = (int)$me['id'];

// 1. Outstanding POs (purchase orders where stock_in_done = 0)
$outstanding_pos = 0;
try {
    $s_po = $pdo->prepare("SELECT COUNT(DISTINCT po_number) FROM purchase_orders WHERE station_id = ? AND stock_in_done = 0");
    $s_po->execute([$station_id]);
    $outstanding_pos = (int)$s_po->fetchColumn();
} catch (Exception $e) {}

// 2. Pending Stock Requests
$pending_sr = 0;
try {
    $s_sr = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'Pending'");
    $s_sr->execute([$station_id]);
    $pending_sr = (int)$s_sr->fetchColumn();
} catch (Exception $e) {}
try {
    $s_srf = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id = ? AND status = 'Pending'");
    $s_srf->execute([$station_id]);
    $pending_sr += (int)$s_srf->fetchColumn();
} catch (Exception $e) {}

// 3. Total Active Products
$active_products = 0;
try {
    $s_ap = $pdo->prepare("
        SELECT COUNT(DISTINCT ip.id)
        FROM station_inventory si
        JOIN inventory_products ip ON ip.id = si.product_id
        WHERE si.station_id = ?
          AND LOWER(COALESCE(si.status, 'active')) = 'active'
          AND LOWER(COALESCE(ip.status, 'active')) <> 'inactive'
          AND LOWER(COALESCE(ip.category, '')) <> 'fuel'
    ");
    $s_ap->execute([$station_id]);
    $active_products = (int)$s_ap->fetchColumn();
} catch (Exception $e) {}

// 4. Total Low Stock Items
$low_stock = 0;
try {
    $s_ls = $pdo->prepare("
        SELECT COUNT(*) 
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category, '')) <> 'fuel'
          AND LOWER(COALESCE(ip.status, 'active')) <> 'inactive'
          AND LOWER(COALESCE(si.status, 'active')) = 'active'
          AND COALESCE(si.stock_level, ip.stock, 0) > 0 
          AND COALESCE(si.stock_level, ip.stock, 0) <= COALESCE(si.reorder_level, 10)
    ");
    $s_ls->execute([$station_id]);
    $low_stock = (int)$s_ls->fetchColumn();
} catch (Exception $e) {}

// 5. Total Out of Stock Items
$out_of_stock = 0;
try {
    $s_oos = $pdo->prepare("
        SELECT COUNT(*) 
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category, '')) <> 'fuel'
          AND LOWER(COALESCE(ip.status, 'active')) <> 'inactive'
          AND LOWER(COALESCE(si.status, 'active')) = 'active'
          AND COALESCE(si.stock_level, ip.stock, 0) <= 0
    ");
    $s_oos->execute([$station_id]);
    $out_of_stock = (int)$s_oos->fetchColumn();
} catch (Exception $e) {}
?>
<style>
/* Manager Summary Cards - Modern Design */
.manager-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.mgr-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 18px 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}

.mgr-stat-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    transform: translateY(-2px);
}

.mgr-stat-card-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    margin-bottom: 12px;
}

.mgr-stat-card-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.mgr-stat-card-value {
    font-size: 26px;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 4px;
}

.mgr-stat-card-subtitle {
    font-size: 11px;
    color: #94a3b8;
    font-weight: 500;
}

/* Color variants */
.mgr-stat-card.blue .mgr-stat-card-icon {
    background: linear-gradient(135deg, #002F70 0%, #004aad 100%);
    color: #fff;
}
.mgr-stat-card.blue .mgr-stat-card-value {
    color: #002F70;
}

.mgr-stat-card.orange .mgr-stat-card-icon {
    background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);
    color: #fff;
}
.mgr-stat-card.orange .mgr-stat-card-value {
    color: #ea580c;
}

.mgr-stat-card.green .mgr-stat-card-icon {
    background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);
    color: #fff;
}
.mgr-stat-card.green .mgr-stat-card-value {
    color: #16a34a;
}

.mgr-stat-card.yellow .mgr-stat-card-icon {
    background: linear-gradient(135deg, #ca8a04 0%, #eab308 100%);
    color: #fff;
}
.mgr-stat-card.yellow .mgr-stat-card-value {
    color: #ca8a04;
}

.mgr-stat-card.red .mgr-stat-card-icon {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    color: #fff;
}
.mgr-stat-card.red .mgr-stat-card-value {
    color: #dc2626;
}

/* Responsive */
@media (max-width: 768px) {
    .manager-summary-grid {
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 12px;
    }
    .mgr-stat-card {
        padding: 14px 16px;
    }
    .mgr-stat-card-icon {
        width: 38px;
        height: 38px;
        font-size: 18px;
        margin-bottom: 10px;
    }
    .mgr-stat-card-value {
        font-size: 22px;
    }
}
</style>

<div class="manager-summary-grid">
    <div class="mgr-stat-card blue">
        <div class="mgr-stat-card-icon">
            <i class="fas fa-file-invoice"></i>
        </div>
        <div class="mgr-stat-card-label">Outstanding POs</div>
        <div class="mgr-stat-card-value"><?= number_format($outstanding_pos) ?></div>
        <div class="mgr-stat-card-subtitle">Pending Delivery</div>
    </div>

    <div class="mgr-stat-card orange">
        <div class="mgr-stat-card-icon">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div class="mgr-stat-card-label">Pending Stock Requests</div>
        <div class="mgr-stat-card-value"><?= number_format($pending_sr) ?></div>
        <div class="mgr-stat-card-subtitle">Awaiting Validation</div>
    </div>

    <div class="mgr-stat-card green">
        <div class="mgr-stat-card-icon">
            <i class="fas fa-box-open"></i>
        </div>
        <div class="mgr-stat-card-label">Active Products</div>
        <div class="mgr-stat-card-value"><?= number_format($active_products) ?></div>
        <div class="mgr-stat-card-subtitle">Total Merchandise</div>
    </div>

    <div class="mgr-stat-card yellow">
        <div class="mgr-stat-card-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="mgr-stat-card-label">Low Stock Items</div>
        <div class="mgr-stat-card-value"><?= number_format($low_stock) ?></div>
        <div class="mgr-stat-card-subtitle">Need Reorder</div>
    </div>

    <div class="mgr-stat-card red">
        <div class="mgr-stat-card-icon">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="mgr-stat-card-label">Out of Stock</div>
        <div class="mgr-stat-card-value"><?= number_format($out_of_stock) ?></div>
        <div class="mgr-stat-card-subtitle">Critical Items</div>
    </div>
</div>
