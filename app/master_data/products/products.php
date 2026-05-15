<?php
require_once __DIR__ . '/lib.php';

$products = read_json('products.json', ['fuel'=>[], 'merchandise'=>[], 'services'=>[]]);

// Optional query: ?type=fuel|merchandise|services
$type = isset($_GET['type']) ? $_GET['type'] : null;

if($type){
  if(!isset($products[$type])) json_response(['ok'=>false,'error'=>'Invalid type'], 400);
  json_response(['ok'=>true,'data'=>$products[$type]]);
}

json_response(['ok'=>true,'data'=>$products]);
?>