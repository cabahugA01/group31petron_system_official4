<?php
require_once __DIR__ . '/lib.php';
require_login();

$dataFile = __DIR__ . '/../data/products.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function loadData($file) {
    if (!file_exists($file)) {
        return ['fuel' => [], 'merchandise' => []];
    }
    $json = file_get_contents($file);
    return json_decode($json, true) ?: ['fuel' => [], 'merchandise' => []];
}

function saveData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

if ($method === 'GET') {
    try {
        $data = loadData($dataFile);
        json_response(['ok' => true, 'data' => $data]);
    } catch (Exception $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    try {
        $data = loadData($dataFile);
        
        switch ($action) {
            case 'fuel_stock_in':
                $id = $input['id'] ?? '';
                $liters = floatval($input['liters'] ?? 0);
                if (!$id || $liters <= 0) {
                    json_response(['ok' => false, 'error' => 'Invalid input'], 400);
                }
                
                foreach ($data['fuel'] as &$fuel) {
                    if ($fuel['id'] === $id) {
                        $fuel['level_l'] = ($fuel['level_l'] ?? 0) + $liters;
                        saveData($dataFile, $data);
                        json_response(['ok' => true, 'data' => ['message' => 'Fuel stock updated']]);
                    }
                }
                json_response(['ok' => false, 'error' => 'Fuel not found'], 404);
                break;
                
            case 'merch_add':
                $item = $input['item'] ?? [];
                if (empty($item['name']) || empty($item['sku'])) {
                    json_response(['ok' => false, 'error' => 'Name and SKU are required'], 400);
                }
                
                $newItem = [
                    'id' => $item['id'] ?: uniqid('merch_'),
                    'type' => 'merchandise',
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'category' => $item['category'] ?? '',
                    'stock' => intval($item['stock'] ?? 0),
                    'cost' => floatval($item['cost'] ?? 0),
                    'price' => floatval($item['price'] ?? 0)
                ];
                
                $data['merchandise'][] = $newItem;
                saveData($dataFile, $data);
                json_response(['ok' => true, 'data' => ['message' => 'Merchandise added']]);
                break;
                
            case 'merch_update':
                $item = $input['item'] ?? [];
                if (empty($item['name']) || empty($item['sku']) || empty($item['id'])) {
                    json_response(['ok' => false, 'error' => 'Name, SKU and ID are required'], 400);
                }
                
                $found = false;
                foreach ($data['merchandise'] as &$merch) {
                    if ($merch['id'] === $item['id']) {
                        $merch['name'] = $item['name'];
                        $merch['sku'] = $item['sku'];
                        $merch['category'] = $item['category'] ?? '';
                        $merch['stock'] = intval($item['stock'] ?? 0);
                        $merch['cost'] = floatval($item['cost'] ?? 0);
                        $merch['price'] = floatval($item['price'] ?? 0);
                        $found = true;
                        break;
                    }
                }
                
                if ($found) {
                    saveData($dataFile, $data);
                    json_response(['ok' => true, 'data' => ['message' => 'Merchandise updated']]);
                } else {
                    json_response(['ok' => false, 'error' => 'Merchandise not found'], 404);
                }
                break;
                
            case 'merch_delete':
                $id = $input['id'] ?? '';
                if (!$id) {
                    json_response(['ok' => false, 'error' => 'Missing id'], 400);
                }
                
                $data['merchandise'] = array_filter($data['merchandise'], function($m) use ($id) {
                    return $m['id'] !== $id;
                });
                $data['merchandise'] = array_values($data['merchandise']);
                saveData($dataFile, $data);
                json_response(['ok' => true, 'data' => ['message' => 'Merchandise deleted']]);
                break;
                
            default:
                json_response(['ok' => false, 'error' => 'Invalid action'], 400);
        }
    } catch (Exception $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
?>
