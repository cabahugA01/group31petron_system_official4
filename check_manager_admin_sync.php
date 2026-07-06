<?php
/**  * Check if Manager's approved requests match Admin's visible POs  */
require_once __DIR__ . '/backend/lib.php';
require_once __DIR__ . '/public/db_connect.php';
require_login();  $station_id = user_station_id();  echo "<pre style='background:#f5f5f5; padding:20px; font-family:monospace;'>";
echo "=================================================================\n";
echo "  MANAGER → ADMIN DATA SYNC VERIFICATION\n";
echo "=================================================================\n\n";  echo "Station ID: $station_id\n\n";  // ============================================================================
// MANAGER SIDE: Check approved merchandise requests
// ============================================================================
echo "--- MANAGER SIDE: MERCHANDISE REQUESTS ---\n\n";  $stmt = $pdo->prepare("  SELECT id, item_name, item_id, requested_quantity, approved_quantity, status, created_at, manager_id  FROM stock_requests  WHERE station_id = ?  ORDER BY created_at DESC
");
$stmt->execute([$station_id]);
$manager_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo "Total Merchandise Requests: " . count($manager_requests) . "\n\n";  $pending = 0; $approved = 0; $rejected = 0;
foreach ($manager_requests as $req) {  $st = strtolower($req['status']);  if (str_contains($st, 'pending')) $pending++;  elseif (str_contains($st, 'approved')) $approved++;  elseif (str_contains($st, 'rejected')) $rejected++;
}  echo "Breakdown:\n";
echo "  - Pending: $pending\n";
echo "  - Approved: $approved\n";
echo "  - Rejected: $rejected\n\n";  // ============================================================================
// ADMIN SIDE: Check merchandise POs
// ============================================================================
echo "--- ADMIN SIDE: MERCHANDISE PURCHASE ORDERS ---\n\n";  $stmt = $pdo->prepare("  SELECT po.id, po.request_id, po.product_name, po.quantity, po.status, po.created_at,  sr.id as source_request_id, sr.item_name as source_item_name  FROM purchase_orders po  LEFT JOIN stock_requests sr ON po.request_id = sr.id  WHERE po.station_id = ? AND po.type = 'merch'  ORDER BY po.created_at DESC
");
$stmt->execute([$station_id]);
$admin_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo "Total Merchandise POs: " . count($admin_pos) . "\n\n";  if (count($admin_pos) > 0) {  echo "PO Details:\n";  foreach ($admin_pos as $po) {  echo "  PO ID: {$po['id']} | Request ID: {$po['request_id']} | {$po['product_name']} | Qty: {$po['quantity']} | Status: {$po['status']}\n";  }
}  echo "\n";  // ============================================================================
// FIND ORPHANED REQUESTS (Approved but no PO created)
// ============================================================================
echo "--- ORPHANED REQUESTS (Approved but NO PO) ---\n\n";  $stmt = $pdo->prepare("  SELECT sr.id, sr.item_name, sr.requested_quantity, sr.status, sr.created_at  FROM stock_requests sr  LEFT JOIN purchase_orders po ON po.request_id = sr.id AND po.type = 'merch'  WHERE sr.station_id = ?  AND LOWER(sr.status) LIKE '%approved%'  AND po.id IS NULL  ORDER BY sr.created_at DESC
");
$stmt->execute([$station_id]);
$orphaned_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);  if (count($orphaned_requests) > 0) {  echo "Found " . count($orphaned_requests) . " approved requests WITHOUT purchase orders:\n\n";  foreach ($orphaned_requests as $req) {  echo "  Request ID: {$req['id']} | {$req['item_name']} | Qty: {$req['requested_quantity']} | Status: {$req['status']} | Date: {$req['created_at']}\n";  }  echo "\n";  echo "ISSUE: Manager approved these requests but POs were NOT created!\n";
} else {  echo "No orphaned requests. All approved requests have corresponding POs.\n";
}  echo "\n";  // ============================================================================
// FUEL SIDE CHECK
// ============================================================================
echo "--- MANAGER SIDE: FUEL REQUESTS ---\n\n";  $stmt = $pdo->prepare("  SELECT id, fuel_type, requested_liters, approved_liters, status, created_at  FROM fuel_stock_requests  WHERE station_id = ?  ORDER BY created_at DESC
");
$stmt->execute([$station_id]);
$fuel_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo "Total Fuel Requests: " . count($fuel_requests) . "\n\n";  $fuel_pending = 0; $fuel_approved = 0; $fuel_rejected = 0;
foreach ($fuel_requests as $req) {  $st = strtolower($req['status']);  if (str_contains($st, 'pending')) $fuel_pending++;  elseif (str_contains($st, 'approved')) $fuel_approved++;  elseif (str_contains($st, 'rejected')) $fuel_rejected++;
}  echo "Breakdown:\n";
echo "  - Pending: $fuel_pending\n";
echo "  - Approved: $fuel_approved\n";
echo "  - Rejected: $fuel_rejected\n\n";  echo "--- ADMIN SIDE: FUEL PURCHASE ORDERS ---\n\n";  $stmt = $pdo->prepare("  SELECT fpo.id, fpo.po_number, ft.name AS fuel_name, fpo.volume, fpo.status, fpo.created_at  FROM fuel_purchase_orders fpo  LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id  WHERE fpo.station_id = ?  ORDER BY fpo.created_at DESC
");
$stmt->execute([$station_id]);
$fuel_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo "Total Fuel POs: " . count($fuel_pos) . "\n\n";  if (count($fuel_pos) > 0) {  echo "Fuel PO Details:\n";  foreach ($fuel_pos as $po) {  echo "  PO ID: {$po['id']} | PO#: {$po['po_number']} | {$po['fuel_name']} | Volume: {$po['volume']} L | Status: {$po['status']}\n";  }
}  echo "\n";  // ============================================================================
// SUMMARY
// ============================================================================
echo "=================================================================\n";
echo "  SUMMARY\n";
echo "=================================================================\n\n";  echo "MERCHANDISE:\n";
echo "  Manager Requests: " . count($manager_requests) . " ($pending pending, $approved approved, $rejected rejected)\n";
echo "  Admin POs: " . count($admin_pos) . "\n";
echo "  Orphaned (Approved but no PO): " . count($orphaned_requests) . "\n\n";  echo "FUEL:\n";
echo "  Manager Requests: " . count($fuel_requests) . " ($fuel_pending pending, $fuel_approved approved, $fuel_rejected rejected)\n";
echo "  Admin POs: " . count($fuel_pos) . "\n\n";  if (count($manager_requests) == count($admin_pos) && count($orphaned_requests) == 0) {  echo "PERFECT SYNC! Manager and Admin have matching data.\n";
} else {  echo "SYNC ISSUE DETECTED!\n";  echo "\nPOSSIBLE CAUSES:\n";  echo "1. Manager approved requests but didn't click 'Generate PO' button\n";  echo "2. PO creation failed due to database error\n";  echo "3. Manager only reviewed/remarked without approving\n";  echo "\nRECOMMENDATION:\n";  echo "- Check orphaned requests above\n";  echo "- Manager should go back and click 'Generate PO' for approved requests\n";  echo "- Or fix the approval workflow to auto-create POs\n";
}  echo "\n=================================================================\n";
echo "</pre>";
?>
