<?php
/**
 * Staff Merchandise Inventory
 * One "Stock Request" button → modal with checkboxes to pick items → submit (no qty, manager sets it).
 * 
 * NOTE: Profit margin display is hidden from staff view (only price is shown, not the +profit).
 */
$page_id = 'inv_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

$merch_inventory = [];
$msg = '';
try {
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name AS name,
               ip.category     AS category_name,
               ip.unit_price   AS price,
               ip.unit_cost    AS cost,
               ip.sku,
               ip.status,
               COALESCE(si.stock_level, ip.stock, 0) AS stock_level,
               COALESCE(si.capacity, 0)              AS capacity,
               COALESCE(si.reorder_level, 10)        AS reorder_level,
               si.last_updated AS last_updated
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading merchandise: ' . $e->getMessage();
}

$all_categories = [];
try {
    $catStmt = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE LOWER(COALESCE(category,'')) NOT IN ('fuel') AND category IS NOT NULL AND category <> '' ORDER BY category");
    $all_categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$js_items  = [];
foreach ($merch_inventory as $item) {
    // Filter: Staff should only see Active products
    $status = strtolower($item['status'] ?? 'active');
    if ($status !== 'active') {
        continue; // Skip pending/inactive products for staff
    }
    
    $stock = (float)($item['stock_level'] ?? 0);
    $capacity = (float)($item['capacity'] ?? 0);
    
    // If no capacity set, use category-based defaults
    if ($capacity <= 0) {
        $cat = strtoupper(trim($item['category_name'] ?? ''));
        
        // Category-based capacity defaults
        if (strpos($cat, 'OIL') !== false && strpos($cat, 'ENGINE') !== false) {
            $capacity = 100; // Motor oil: 100 pcs
        } elseif (strpos($cat, 'COOLANT') !== false || strpos($cat, 'FLUID') !== false) {
            $capacity = strpos($cat, 'BRAKE') !== false ? 50 : 80; // Brake fluid: 50, Coolant: 80
        } elseif (strpos($cat, 'GREASE') !== false || strpos($cat, 'LUBE') !== false) {
            $capacity = 100;
        } elseif (strpos($cat, 'FILTER') !== false) {
            $capacity = 150;
        } elseif (strpos($cat, 'ACCESSORI') !== false || strpos($cat, 'TIRE') !== false || strpos($cat, 'PROTECTANT') !== false || strpos($cat, 'WAX') !== false) {
            $capacity = 200; // Accessories, tire care: 200 pcs
        } elseif (strpos($cat, 'FRESHENER') !== false) {
            $capacity = 250; // Air fresheners: 250 pcs
        } elseif (strpos($cat, 'BEVERAGE') !== false || strpos($cat, 'DRINK') !== false) {
            $capacity = 500; // Beverages: 500 pcs
        } elseif (strpos($cat, 'SNACK') !== false || strpos($cat, 'CHIP') !== false || strpos($cat, 'BISCUIT') !== false || strpos($cat, 'COOKIE') !== false || strpos($cat, 'NOODLE') !== false) {
            $capacity = 500; // Snacks: 500 pcs
        } elseif (strpos($cat, 'CHOCOLATE') !== false || strpos($cat, 'CANDY') !== false) {
            $capacity = 400;
        } elseif (strpos($cat, 'PATCH') !== false || strpos($cat, 'REPAIR') !== false) {
            $capacity = 100;
        } elseif (strpos($cat, 'CHEMICAL') !== false || strpos($cat, 'TREATMENT') !== false || strpos($cat, 'ADDITIVE') !== false) {
            $capacity = 80;
        } else {
            $capacity = 100; // Default fallback
        }
    }
    
    $reord = (float)($item['reorder_level'] ?? 10);
    
    // Calculate variance (placeholder until physical count data exists)
    $variance = 0;
    
    // Status determination based on fill percentage
    $fill_pct = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
    if      ($stock <= 0)      { $st = 'OUT OF STOCK'; $sc = '#dc3545'; $st_cls = 'out'; }
    elseif  ($fill_pct <= 10)  { $st = 'CRITICAL';     $sc = '#dc3545'; $st_cls = 'critical'; }
    elseif  ($fill_pct <= 25)  { $st = 'LOW';          $sc = '#fd7e14'; $st_cls = 'low'; }
    else                       { $st = 'AVAILABLE';    $sc = '#28a745'; $st_cls = 'ok';  }
    
    $js_items[] = [
        'id'       => (int)$item['id'],
        'name'     => $item['name'],
        'sku'      => $item['sku'] ?? '',
        'category' => $item['category_name'] ?? '',
        'stock'    => (int)$stock,
        'capacity' => (int)$capacity,
        'variance' => $variance,
        'fill_pct' => round($fill_pct, 1),
        'status'   => $st,
        'color'    => $sc,
        'stCls'    => $st_cls,
    ];
}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* Prevent page overflow */
body, html { overflow-x: hidden; max-width: 100%; }
.page-head { max-width: 100%; overflow: hidden; }
.inv-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:20px; max-width: 100%; overflow: hidden; }
.inv-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.inv-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.inv-card-body  { padding:20px; }
.cat-header td { font-weight:700; background:#e9ecef !important; color:#495057 !important; text-transform:uppercase; font-size:.8em; letter-spacing:.5px; border-bottom:2px solid #dee2e6; padding:8px 12px; }
.cost-col  { color:#6c757d; font-size:.9em; }
.price-col { color:#28a745; font-weight:700; }

/* ── Fill bar for Current Level ── */
.fill-bar-wrap { background:#e9ecef; border-radius:3px; height:5px; overflow:hidden; margin-bottom:2px; width:100%; }
.fill-bar-inner { height:100%; border-radius:3px; }

/* ── Status badge ── */
.status-badge {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}

#merchSearch { padding:8px 12px; border:1px solid #ced4da; border-radius:4px; font-size:14px; width:100%; max-width:100%; }
#merchSearch:focus { border-color:#80bdff; outline:0; box-shadow:0 0 0 .2rem rgba(0,123,255,.25); }
.search-wrap { max-width:300px; margin-bottom:14px; }

/* ══ FIX: Remove horizontal scrolling ══ */
.table-wrap { 
    padding: 0; 
    overflow-x: auto;
    overflow-y: visible;
    width: 100%;
    max-width: 100%;
}
#merchTable { 
    width: 100%; 
    table-layout: fixed;
    border-collapse: collapse;
}
#merchTable thead th,
#merchTable tbody td {
    padding: 10px 8px;
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
}
#merchTable thead th {
    white-space: nowrap;
}
#merchTable tbody td {
    border: none;
    border-bottom: 1px solid #e9ecef;
    white-space: normal;
    word-wrap: break-word;
}
/* Column widths - 8 columns total */
#merchTable th:nth-child(1),
#merchTable td:nth-child(1) { width: 10%; }
#merchTable th:nth-child(2),
#merchTable td:nth-child(2) { width: 22%; }
#merchTable th:nth-child(3),
#merchTable td:nth-child(3) { width: 18%; }
#merchTable th:nth-child(4),
#merchTable td:nth-child(4) { width: 12%; text-align: center; }
#merchTable th:nth-child(5),
#merchTable td:nth-child(5) { width: 13%; text-align: center; }
#merchTable th:nth-child(6),
#merchTable td:nth-child(6) { width: 10%; text-align: center; }
#merchTable th:nth-child(7),
#merchTable td:nth-child(7) { width: 8%; text-align: center; }
#merchTable th:nth-child(8),
#merchTable td:nth-child(8) { width: 12%; text-align: center; }

/* Tablet optimization */
@media (max-width: 1024px) {
    #merchTable th:nth-child(7),
    #merchTable td:nth-child(7) {
        display: none; /* Hide Variance on tablets */
    }
    #merchTable th:nth-child(1),
    #merchTable td:nth-child(1) { width: 12%; }
    #merchTable th:nth-child(2),
    #merchTable td:nth-child(2) { width: 24%; }
    #merchTable th:nth-child(3),
    #merchTable td:nth-child(3) { width: 20%; }
    #merchTable th:nth-child(4),
    #merchTable td:nth-child(4) { width: 14%; }
    #merchTable th:nth-child(5),
    #merchTable td:nth-child(5) { width: 14%; }
    #merchTable th:nth-child(6),
    #merchTable td:nth-child(6) { width: 12%; }
    #merchTable th:nth-child(8),
    #merchTable td:nth-child(8) { width: 14%; }
}

/* Mobile optimization */
@media (max-width: 768px) {
    #merchTable th:nth-child(1),
    #merchTable td:nth-child(1),
    #merchTable th:nth-child(4),
    #merchTable td:nth-child(4),
    #merchTable th:nth-child(7),
    #merchTable td:nth-child(7),
    #merchTable th:nth-child(8),
    #merchTable td:nth-child(8) {
        display: none; /* Hide SKU, Capacity, Variance, Timestamp on mobile */
    }
    #merchTable th:nth-child(2),
    #merchTable td:nth-child(2) { width: 40%; }
    #merchTable th:nth-child(3),
    #merchTable td:nth-child(3) { width: 25%; }
    #merchTable th:nth-child(5),
    #merchTable td:nth-child(5) { width: 20%; }
    #merchTable th:nth-child(6),
    #merchTable td:nth-child(6) { width: 15%; }
    .inv-card-body { padding: 12px; }
    #merchTable thead th,
    #merchTable tbody td {
        padding: 8px 6px;
        font-size: 12px;
    }
}

/* ── Modal ── */
.sr-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9999; align-items:center; justify-content:center; }
.sr-modal-overlay.open { display:flex; }
.sr-modal-box { background:#fff; border-radius:14px; padding:28px; width:680px; max-width:calc(100vw - 32px); max-height:calc(100vh - 40px); overflow-y:auto; box-shadow:0 24px 80px rgba(0,0,0,.3); animation:srIn .2s ease; }
@keyframes srIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.sr-modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; padding-bottom:14px; border-bottom:2px solid #e9ecef; }
.sr-modal-title { font-size:1.05rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.sr-modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#adb5bd; }
.sr-modal-close:hover { color:#333; }
.sr-modal-footer { display:flex; gap:10px; justify-content:flex-end; align-items:center; margin-top:16px; padding-top:14px; border-top:1px solid #e9ecef; }
.sr-info-box { background:#e8f4fd; border-left:4px solid #002F70; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:12px; color:#002F70; line-height:1.6; }

/* ── Modal search ── */
#srModalSearch { width:100%; padding:8px 12px; border:1px solid #ced4da; border-radius:6px; font-size:13px; margin-bottom:10px; box-sizing:border-box; }
#srModalSearch:focus { border-color:#002F70; outline:none; box-shadow:0 0 0 2px rgba(0,47,112,.12); }

/* ── Select-all bar ── */
.sr-select-bar { display:flex; align-items:center; gap:8px; padding:8px 14px; background:#f8f9fa; border-radius:6px; margin-bottom:10px; font-size:12px; color:#495057; font-weight:600; }
.sr-select-bar input { width:16px; height:16px; accent-color:#002F70; cursor:pointer; }

/* ── Checkbox rows ── */
.sr-cb-row { display:flex; align-items:center; gap:12px; padding:9px 14px; border-radius:8px; border:1px solid #dee2e6; margin-bottom:6px; cursor:pointer; transition:background .1s; user-select:none; }
.sr-cb-row:hover   { background:#f0f4ff; }
.sr-cb-row.checked { background:#eef2ff; border-color:#90a8e0; }
.sr-cb-row.out      { border-left:4px solid #dc3545; }
.sr-cb-row.critical { border-left:4px solid #dc3545; }
.sr-cb-row.low      { border-left:4px solid #fd7e14; }
.sr-cb-row.ok       { border-left:4px solid #28a745; }
.sr-cb { width:17px; height:17px; accent-color:#002F70; cursor:pointer; flex-shrink:0; }
.sr-cb-info { flex:1; min-width:0; }
.sr-cb-name { font-weight:700; font-size:13px; color:#212529; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sr-cb-meta { font-size:11px; color:#6c757d; margin-top:1px; }

/* ── Category label inside modal ── */
.sr-cat-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6c757d; padding:6px 4px 3px; }

/* ── Success popup ── */
.sr-success-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:10998; }
.sr-success-popup { display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:10999; background:#fff; padding:28px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.25); text-align:center; min-width:300px; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-boxes"></i> Merchandise Inventory</h1>
        <div class="sub">MANAGE MERCHANDISE ITEMS AND MONITOR STOCK LEVELS.</div>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../partials/staff_inventory_summary.php'; ?>

<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-box"></i> Merchandise Stock</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php
            $export_table_id      = 'merchTable';
            $export_filename      = 'merch_inventory_' . date('Ymd');
            $export_title         = 'Merchandise Inventory';
            $export_rows_select_id = 'merchRowsLimit';
            $export_default_rows  = 25;
            require __DIR__ . '/../partials/export_buttons.php';
            ?>
            <button onclick="openSrModal()"
                    style="background:#002F70;color:#fff;border:none;display:inline-flex;align-items:center;gap:7px;height:36px;padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                <i class="fas fa-box"></i> Stock Request
            </button>
        </div>
    </div>
    <div class="inv-card-body">
        <div class="search-wrap">
            <input id="merchSearch" placeholder="&#128269; Search products..." autocomplete="off" />
        </div>
        <div class="table-wrap">
            <table class="table" id="merchTable">
                <thead>
                    <tr>
                        <th>Item Code / SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Capacity / Max Stock</th>
                        <th>Current Level</th>
                        <th>Status</th>
                        <th class="col-var">Variance</th>
                        <th class="col-time">Timestamp</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php if (empty($merch_inventory)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:28px;color:#6c757d;">No merchandise data available.</td></tr>
                <?php else: ?>
                    <?php
                    // Group items by category
                    $categories = [];
                    foreach ($merch_inventory as $item) {
                        $cat = $item['category_name'] ?? 'Uncategorized';
                        $categories[$cat][] = $item;
                    }
                    
                    // Sort categories
                    ksort($categories);
                    
                    // Display each category with header
                    foreach ($categories as $cat_label => $items):
                    ?>
                        <tr class="cat-header">
                            <td colspan="8"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td>
                        </tr>
                        <?php foreach ($items as $item):
                            // Filter: Staff should only see Active products
                            $item_status = strtolower($item['status'] ?? 'active');
                            if ($item_status !== 'active') {
                                continue; // Skip pending/inactive products for staff
                            }
                            
                            $stock  = (float)($item['stock_level'] ?? 0);
                            $capacity = (float)($item['capacity'] ?? 0);
                            
                            // If no capacity set, use category-based defaults
                            if ($capacity <= 0) {
                                $cat = strtoupper(trim($item['category_name'] ?? ''));
                                
                                // Category-based capacity defaults
                                if (strpos($cat, 'OIL') !== false && strpos($cat, 'ENGINE') !== false) {
                                    $capacity = 100;
                                } elseif (strpos($cat, 'COOLANT') !== false || strpos($cat, 'FLUID') !== false) {
                                    $capacity = strpos($cat, 'BRAKE') !== false ? 50 : 80;
                                } elseif (strpos($cat, 'GREASE') !== false || strpos($cat, 'LUBE') !== false) {
                                    $capacity = 100;
                                } elseif (strpos($cat, 'FILTER') !== false) {
                                    $capacity = 150;
                                } elseif (strpos($cat, 'ACCESSORI') !== false || strpos($cat, 'TIRE') !== false || strpos($cat, 'PROTECTANT') !== false || strpos($cat, 'WAX') !== false) {
                                    $capacity = 200;
                                } elseif (strpos($cat, 'FRESHENER') !== false) {
                                    $capacity = 250;
                                } elseif (strpos($cat, 'BEVERAGE') !== false || strpos($cat, 'DRINK') !== false) {
                                    $capacity = 500;
                                } elseif (strpos($cat, 'SNACK') !== false || strpos($cat, 'CHIP') !== false || strpos($cat, 'BISCUIT') !== false || strpos($cat, 'COOKIE') !== false || strpos($cat, 'NOODLE') !== false) {
                                    $capacity = 500;
                                } elseif (strpos($cat, 'CHOCOLATE') !== false || strpos($cat, 'CANDY') !== false) {
                                    $capacity = 400;
                                } elseif (strpos($cat, 'PATCH') !== false || strpos($cat, 'REPAIR') !== false) {
                                    $capacity = 100;
                                } elseif (strpos($cat, 'CHEMICAL') !== false || strpos($cat, 'TREATMENT') !== false || strpos($cat, 'ADDITIVE') !== false) {
                                    $capacity = 80;
                                } else {
                                    $capacity = 100;
                                }
                            }
                            
                            $reord  = (float)($item['reorder_level'] ?? 10);
                            
                            // Calculate variance (placeholder until physical count data exists)
                            $variance = 0;
                            
                            // Status determination based on fill percentage
                            $fill_pct = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
                            if      ($stock <= 0)      { $st_label = 'Out of Stock'; $st_color = '#dc3545'; }
                            elseif  ($fill_pct <= 10)  { $st_label = 'Critical';     $st_color = '#dc3545'; }
                            elseif  ($fill_pct <= 25)  { $st_label = 'Low';          $st_color = '#fd7e14'; }
                            else                       { $st_label = 'Available';    $st_color = '#28a745'; }
                            
                            // Timestamp formatting
                            $timestamp = '—';
                            if (isset($item['last_updated']) && $item['last_updated']) {
                                try {
                                    $dt = new DateTime($item['last_updated']);
                                    $timestamp = $dt->format('M d, Y g:i A');
                                } catch (Exception $e) {
                                    $timestamp = '—';
                                }
                            }
                            
                            // Variance display with color
                            $var_display = '0.00';
                            $var_color = '#6c757d';
                            if (abs($variance) > 0.01) {
                                $var_display = ($variance > 0 ? '+' : '') . number_format($variance, 2);
                                $var_color = $variance < 0 ? '#dc3545' : '#28a745';
                            }
                        ?>
                    <tr class="merch-row" data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>">
                        <td><code style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($item['sku'] ?? ''); ?></code></td>
                        <td>
                            <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></td>
                        <td style="font-weight:600;color:#002F70;">
                            <?php echo number_format($capacity, 0); ?>
                        </td>
                        <td>
                            <div class="fill-bar-wrap">
                                <div class="fill-bar-inner" style="width:<?php echo min(100, round($fill_pct)); ?>%;background:<?php echo $st_color; ?>;"></div>
                            </div>
                            <span style="font-size:10px;color:#64748b;">
                                <?php echo number_format($stock, 0); ?> · <?php echo round($fill_pct, 1); ?>%
                            </span>
                        </td>
                        <td>
                            <span class="status-badge" style="background:<?php echo $st_color; ?>20;color:<?php echo $st_color; ?>;border:1px solid <?php echo $st_color; ?>40;">
                                <?php echo $st_label; ?>
                            </span>
                        </td>
                        <td class="col-var" style="font-weight:700;color:<?php echo $var_color; ?>;">
                            <?php echo $var_display; ?>
                        </td>
                        <td class="col-time" style="font-size:11px;color:#64748b;">
                            <?php echo $timestamp; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="merchPagination"></div>
    </div>
</div>

<!-- ══ STOCK REQUEST MODAL ══ -->
<div class="sr-modal-overlay" id="srModal">
    <div class="sr-modal-box">
        <div class="sr-modal-head">
            <div class="sr-modal-title">
                <i class="fas fa-box" style="color:#002F70;"></i>
                Stock Request
            </div>
            <button class="sr-modal-close" id="srModalClose">&times;</button>
        </div>

        <div class="sr-info-box">
            <i class="fas fa-info-circle"></i>
            <strong>Select the items you want to request, then click Submit.</strong><br>
            &bull; Only Critical, Low, and Out-of-Stock items are shown<br>
            &bull; Manager will review and set the approved quantity<br>
            &bull; Audit trail logged: Staff ID, Item, Timestamp
        </div>

        <input type="text" id="srModalSearch" placeholder="&#128269; Filter items..." autocomplete="off">

        <div class="sr-select-bar">
            <input type="checkbox" id="srSelectAll">
            <label for="srSelectAll" style="cursor:pointer;margin:0;">Select All</label>
            <span id="srSelectedCount" style="margin-left:auto;color:#002F70;font-weight:700;"></span>
        </div>

        <div id="srCheckList" style="max-height:380px;overflow-y:auto;padding-right:4px;"></div>

        <div id="srError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px 14px;border-radius:6px;margin-top:10px;font-size:13px;"></div>

        <div class="sr-modal-footer">
            <button type="button" id="srCancelBtn"
                    style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">
                Cancel
            </button>
            <button type="button" id="srSubmitBtn"
                    style="padding:9px 22px;background:#002F70;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
        </div>
    </div>
</div>

<!-- ── Success popup ── -->
<div class="sr-success-overlay" id="srSuccessOverlay"></div>
<div class="sr-success-popup" id="srSuccessPopup">
    <div style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" style="color:#fff;font-size:22px;"></i>
    </div>
    <h3 style="margin:0 0 8px;color:#28a745;">Request Submitted!</h3>
    <p style="margin:0 0 18px;color:#333;font-size:14px;line-height:1.5;" id="srSuccessMsg">
        Your stock request is now <strong>Pending</strong> Manager review.
    </p>
    <button onclick="closeSrSuccess()" style="background:#002F70;color:#fff;border:none;padding:9px 26px;border-radius:6px;cursor:pointer;font-weight:600;">OK</button>
</div>


<script>
var allMerchData = <?php echo json_encode(array_values($js_items)); ?>;

// ── Table search ──────────────────────────────────────────────────────────────
document.getElementById('merchSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        r.style.display = (r.getAttribute('data-name') || '').indexOf(q) !== -1 ? '' : 'none';
    });
});

// ── Open modal ────────────────────────────────────────────────────────────────
function openSrModal() {
    document.getElementById('srModalSearch').value = '';
    renderSrCheckList('');
    syncSrSelectAll();
    document.getElementById('srError').style.display = 'none';
    document.getElementById('srSubmitBtn').disabled  = false;
    document.getElementById('srSubmitBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    document.getElementById('srModal').classList.add('open');
    setTimeout(function() { document.getElementById('srModalSearch').focus(); }, 120);
}

function renderSrCheckList(filter) {
    var q = (filter || '').toLowerCase();
    // Group by category - FILTER OUT AVAILABLE ITEMS (only show Critical, Low, Out of Stock)
    var cats = {};
    allMerchData.forEach(function(it, idx) {
        // Skip items with 'ok' status (AVAILABLE) - only show 'out', 'low', 'critical'
        if (it.stCls === 'ok') return;
        
        if (q && it.name.toLowerCase().indexOf(q) === -1 &&
                 it.sku.toLowerCase().indexOf(q) === -1 &&
                 it.category.toLowerCase().indexOf(q) === -1) return;
        if (!cats[it.category]) cats[it.category] = [];
        cats[it.category].push({ it: it, idx: idx });
    });

    // Snapshot currently checked idxs before re-render
    var checkedIdxs = {};
    document.querySelectorAll('.sr-item-cb:checked').forEach(function(cb) {
        checkedIdxs[cb.dataset.idx] = true;
    });

    var html = '';
    Object.keys(cats).forEach(function(cat) {
        html += '<div class="sr-cat-label">' + escHtml(cat) + '</div>';
        cats[cat].forEach(function(entry) {
            var it  = entry.it;
            var idx = entry.idx;
            var badge = '<span style="background:' + it.color + '20;color:' + it.color + ';border:1px solid ' + it.color + '40;border-radius:20px;padding:1px 6px;font-size:10px;font-weight:700;">' + escHtml(it.status) + '</span>';
            var wasChecked = !!checkedIdxs[idx];
            html += '<label class="sr-cb-row ' + it.stCls + (wasChecked ? ' checked' : '') + '" data-idx="' + idx + '">' +
                '<input type="checkbox" class="sr-cb sr-item-cb" data-idx="' + idx + '" ' + (wasChecked ? 'checked' : '') + '>' +
                '<div class="sr-cb-info">' +
                    '<div class="sr-cb-name">' + escHtml(it.name) + '</div>' +
                    '<div class="sr-cb-meta">' + escHtml(it.sku) + ' &bull; Stock: ' + it.stock + ' / ' + it.capacity + ' (' + it.fill_pct + '%) &bull; ' + badge + '</div>' +
                '</div>' +
            '</label>';
        });
    });

    if (!html) html = '<div style="text-align:center;padding:24px;color:#6c757d;"><i class="fas fa-check-circle" style="font-size:32px;color:#28a745;margin-bottom:8px;"></i><br><strong>All items are in stock!</strong><br>No Critical, Low, or Out-of-Stock items to request.</div>';
    document.getElementById('srCheckList').innerHTML = html;

    // Highlight row when checkbox changes
    document.querySelectorAll('.sr-item-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            this.closest('.sr-cb-row').classList.toggle('checked', this.checked);
            syncSrSelectAll();
        });
    });

    syncSrSelectAll();
}

function syncSrSelectAll() {
    var all     = document.querySelectorAll('.sr-item-cb');
    var checked = document.querySelectorAll('.sr-item-cb:checked');
    var sa = document.getElementById('srSelectAll');
    sa.indeterminate = checked.length > 0 && checked.length < all.length;
    sa.checked       = all.length > 0 && checked.length === all.length;
    document.getElementById('srSelectedCount').textContent =
        checked.length > 0 ? checked.length + ' selected' : '';
}

document.getElementById('srSelectAll').addEventListener('change', function() {
    var c = this.checked;
    document.querySelectorAll('.sr-item-cb').forEach(function(cb) { cb.checked = c; });
    document.querySelectorAll('.sr-cb-row').forEach(function(row) { row.classList.toggle('checked', c); });
    syncSrSelectAll();
});

// Modal search filter
document.getElementById('srModalSearch').addEventListener('input', function() {
    renderSrCheckList(this.value);
});

// ── Close ─────────────────────────────────────────────────────────────────────
function closeSrModal() { document.getElementById('srModal').classList.remove('open'); }
document.getElementById('srModalClose').addEventListener('click', closeSrModal);
document.getElementById('srCancelBtn').addEventListener('click', closeSrModal);
document.getElementById('srModal').addEventListener('click', function(e) { if (e.target === this) closeSrModal(); });
document.addEventListener('keydown', function(e) { 
    if (e.key === 'Escape') {
        closeSrModal();
    }
});

// ── Submit ────────────────────────────────────────────────────────────────────
document.getElementById('srSubmitBtn').addEventListener('click', function() {
    var checked = document.querySelectorAll('.sr-item-cb:checked');
    if (checked.length === 0) {
        var el = document.getElementById('srError');
        el.textContent = 'Please select at least one item.';
        el.style.display = 'block';
        return;
    }

    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    document.getElementById('srError').style.display = 'none';

    var queue = [];
    checked.forEach(function(cb) { queue.push(allMerchData[parseInt(cb.dataset.idx)]); });

    var results = { ok: 0, fail: 0, errors: [] };

    function submitNext() {
        if (queue.length === 0) {
            closeSrModal();
            var msg = results.ok + ' request' + (results.ok !== 1 ? 's' : '') + ' submitted successfully.';
            if (results.fail > 0) msg += ' ' + results.fail + ' failed: ' + results.errors.join('; ');
            document.getElementById('srSuccessMsg').innerHTML = msg;
            document.getElementById('srSuccessPopup').style.display  = 'block';
            document.getElementById('srSuccessOverlay').style.display = 'block';
            setTimeout(closeSrSuccess, 6000);
            return;
        }
        var it = queue.shift();
        fetch('../backend/api/stock_request.php?action=create', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                item_id:            it.id,
                sku:                it.sku,
                item_name:          it.name,
                item_category:      it.category,
                current_stock:      it.stock,
                requested_quantity: 0,
                remarks:            ''
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) results.ok++;
            else { results.fail++; results.errors.push(it.name + ': ' + (res.message || 'error')); }
            submitNext();
        })
        .catch(function() {
            results.fail++;
            results.errors.push(it.name + ': network error');
            submitNext();
        });
    }
    submitNext();
});

function closeSrSuccess() {
    document.getElementById('srSuccessPopup').style.display  = 'none';
    document.getElementById('srSuccessOverlay').style.display = 'none';
    // Redirect to Stock Request page to see submitted requests
    window.location.href = 'staff_stock_requests.php#tab-merch';
}
function escHtml(str) { var d = document.createElement('div'); d.appendChild(document.createTextNode(str)); return d.innerHTML; }

document.addEventListener('DOMContentLoaded', function() {
    ['srModal','srSuccessOverlay','srSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    setupTablePagination('merchTable', 'merchRowsLimit', 'merchPagination', 50);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
