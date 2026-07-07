<?php
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;

$voided = [];
try {  $stmt = $pdo->prepare("  SELECT  vt.*,  'Manager' AS voided_by_name  FROM voided_transactions vt  WHERE vt.station_id = ?  ORDER BY vt.void_date DESC  ");  $stmt->execute([$station_id]);  $voided = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {  echo "Error: " . $e->getMessage() . "\n";
}

$void_items_map = [];
try {  if (!empty($voided)) {  $void_txn_ids = array_unique(array_column($voided, 'transaction_id'));  $void_txn_ids_str = implode("','", array_map(function($id) {  return str_replace("'", "''", $id);  }, $void_txn_ids));  $void_stmt = $pdo->query("  SELECT mt.transaction_id AS txn_id, mti.product_name, mti.quantity, mti.unit_price, mti.subtotal,  COALESCE(mti.item_type,'merchandise') AS item_type  FROM merchandise_transactions mt  INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id  WHERE mt.transaction_id IN ('$void_txn_ids_str')  ORDER BY mt.transaction_id, mti.id ASC  ");  foreach ($void_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {  $void_items_map[$item['txn_id']][] = $item;  }  }
} catch (Exception $e) {  echo "Pre-fetch Error: " . $e->getMessage() . "\n";
}

echo "=== RENDERED ITEMS COLUMN ===\n";
foreach ($voided as $v) {  echo "VOID ID: " . $v['id'] . "\n";  echo "TXN ID: " . $v['transaction_id'] . "\n";  echo "FIELDS CHANGED: " . var_export($v['fields_changed'] ?? null, true) . "\n";  echo "HTML OUTPUT:\n";  // Paste identical display code from public/voided_transactions.php  $v_fields = !empty($v['fields_changed']) ? json_decode($v['fields_changed'], true) : null;  $txn_id  = $v['transaction_id'];  if (!empty($v_fields['voided_items'])) {  foreach ($v_fields['voided_items'] as $item) {  $qty  = (float)$item['quantity'];  $price = (float)$item['unit_price'];  $sub  = (float)$item['subtotal'];  echo '  [snapshot] ' . $item['product_name'] . ' - ' . $qty . ' x ₱' . number_format($price, 2) . ' = ₱' . number_format($sub, 2) . "\n";  }  } elseif (!empty($void_items_map[$txn_id])) {  foreach ($void_items_map[$txn_id] as $item) {  $is_svc = ($item['item_type'] === 'service');  $icon  = $is_svc ? '' : '';  $qty  = isset($item['quantity'])  ? (float)$item['quantity']  : 1;  $price  = isset($item['unit_price']) ? (float)$item['unit_price'] : 0;  $sub  = isset($item['subtotal'])  ? (float)$item['subtotal']  : 0;  echo '  [live] ' . $icon . ' ' . $item['product_name'] . ' - Qty: ' . $qty . ' x ₱' . number_format($price, 2) . ' = ₱' . number_format($sub, 2) . "\n";  }  } else {  $amt = (float)$v['amount'];  echo '  [fallback] ₱' . number_format($amt, 2) . " voided (Item details not available – legacy record)\n";  }  echo "---------------------------\n";
}
?>
