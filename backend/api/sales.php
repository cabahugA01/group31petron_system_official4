<?php
require_once __DIR__ . '/lib.php';
require_login();

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET'){
  $sales = read_json('sales.json', []);
  
  // Handle fetching pending sales for Admin review
  if(($_GET['action'] ?? '') === 'pending'){
    $station_id = $_SESSION['user']['station_id'] ?? null;
    $pending = array_values(array_filter($sales, function($s) use ($station_id){
      $st = $s['status'] ?? 'Completed';
      return (strpos($st, 'Pending') !== false || strpos($st, 'For Approval') !== false)
          && ($s['station_id'] ?? '') == $station_id;
    }));
    // Sort by date/time descending (newest first) for Admin review
    usort($pending, function($a, $b){
        return strcmp($b['date'].' '.$b['time'], $a['date'].' '.$a['time']);
    });
    json_response(['ok'=>true, 'data'=>$pending]);
  }

  json_response(['ok'=>true,'data'=>$sales]);
}

if($method !== 'POST'){
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
if(!$payload) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);

$cart = isset($payload['cart']) ? $payload['cart'] : [];
$sale_id = $payload['id'] ?? null; // Existing ID if updating/approving
$payment_method = $payload['payment_method'] ?? 'Cash';
$amount_received = (float)($payload['amount_received'] ?? 0);
$customer = trim($payload['customer'] ?? 'Walk-in');
$status = $payload['status'] ?? 'Completed (Admin)'; // Pending (Staff), For Approval (Admin), Completed (Admin), Archived

if(!is_array($cart) || count($cart) === 0){
  json_response(['ok'=>false,'error'=>'Cart is empty'], 400);
}

$products = read_json('products.json', ['fuel'=>[], 'merchandise'=>[], 'services'=>[]]);

// Build lookup
$lookup = [];
foreach(['fuel','merchandise','services'] as $k){
  foreach($products[$k] as $p){ $lookup[$p['id']] = [$k, $p]; }
}

$items = [];
$total = 0;
foreach($cart as $line){
  $id = $line['id'] ?? '';
  $qty = (int)($line['qty'] ?? 1);
  if($qty < 1) $qty = 1;
  if(!isset($lookup[$id])) json_response(['ok'=>false,'error'=>"Unknown item: $id"], 400);
  [$type, $p] = $lookup[$id];
  $price = (float)($p['price'] ?? 0);
  if($price < 0) $price = 0;

  // Basic availability checks (Only check, don't deduct yet if Pending)
  if($type === 'merchandise' && strpos($status, 'Completed') !== false){
    $stock = (int)($p['stock'] ?? 0);
    if($stock < $qty){
      json_response(['ok'=>false,'error'=>"Insufficient stock for {$p['name']} (available: $stock)"], 400);
    }
  }
  if($type === 'fuel' && strpos($status, 'Completed') !== false){
    // For demo: 1 qty = 1 liter
    $lvl = (float)($p['level_l'] ?? 0);
    if($lvl < $qty){
      json_response(['ok'=>false,'error'=>"Insufficient fuel level for {$p['name']} (available: {$lvl} L)"], 400);
    }
  }

  $amount = $price * $qty;
  $total += $amount;
  $items[] = [
    'id'=>$id,
    'name'=>$p['name'],
    'qty'=>$qty,
    'price'=>$price,
    'amount'=>$amount,
    'type'=>$type
  ];
}

// Cash validation
$change = 0;
if(strtolower($payment_method) === 'cash' && strpos($status, 'Completed') !== false){
  if($amount_received < $total) json_response(['ok'=>false,'error'=>'Amount received is less than total'], 400);
  $change = $amount_received - $total;
}else{
  // For non-cash payments, don't force an "amount received" value.
  $amount_received = 0;
}

// Update inventory levels ONLY if status is Completed (Finalized)
if(strpos($status, 'Completed') !== false){
 foreach($items as $it){
  if($it['type'] === 'merchandise'){
    for($i=0;$i<count($products['merchandise']);$i++){
      if($products['merchandise'][$i]['id'] === $it['id']){
        $cur = (int)($products['merchandise'][$i]['stock'] ?? 0);
        $products['merchandise'][$i]['stock'] = max(0, $cur - $it['qty']);
        break;
      }
    }
  }
  if($it['type'] === 'fuel'){
    for($i=0;$i<count($products['fuel']);$i++){
      if($products['fuel'][$i]['id'] === $it['id']){
        $cur = (float)($products['fuel'][$i]['level_l'] ?? 0);
        // For demo: 1 qty = 1 liter
        $products['fuel'][$i]['level_l'] = max(0, $cur - $it['qty']);
        break;
      }
    }
  }
 }
 write_json('products.json', $products);
}

// Save sale
$sales = read_json('sales.json', []);

// If ID exists, we are updating (Approving/Editing). If not, create new.
$id = $sale_id ?: ('S' . date('ymd') . '-' . substr(bin2hex(random_bytes(6)), 0, 10));
$sale = [
  'id'=>$id,
  'date'=>date('Y-m-d'),
  'time'=>date('H:i:s'),
  'customer'=>$customer,
  'payment_method'=>$payment_method,
  'total'=>$total,
  'amount_received'=>$amount_received,
  'change'=>$change,
  'items'=>$items,
  'status'=>$status, // Pending, Completed, Archived
  'cashier'=> $payload['cashier'] ?? ($_SESSION['user']['name'] ?? 'Staff'), // Preserve original cashier if approving
  'approved_by'=> (strpos($status, 'Completed') !== false && $sale_id) ? ($_SESSION['user']['name'] ?? 'Admin') : null,
  'station_id'=>$_SESSION['user']['station_id'] ?? null,
  'updated_at'=>date('Y-m-d H:i:s')
];

$found = false;
foreach($sales as $k => $v){
    if(($v['id'] ?? '') === $id){
        $sales[$k] = array_merge($v, $sale); // Merge to keep original created_at if needed
        $found = true;
        break;
    }
}
if(!$found) $sales[] = $sale;

write_json('sales.json', $sales);

json_response(['ok'=>true,'data'=>$sale]);
?>