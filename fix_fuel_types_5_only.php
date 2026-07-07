<?php
/**
 * Fix Fuel Types - Keep only 5 main fuel types
 * Run this once to consolidate pump-specific entries into 5 main fuel types
 */

require_once __DIR__ . '/public/db_connect.php';

echo "<!DOCTYPE html><html><head><title>Fix Fuel Types</title>";
echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}</style></head><body>";
echo "<h1>Fuel Types Cleanup - 5 Main Types Only</h1>";

try {
    $pdo->beginTransaction();
    
    // Step 1: Get all current fuel types
    echo "<h3>Step 1: Current Fuel Types</h3>";
    $stmt = $pdo->query("SELECT id, name, category, description FROM fuel_types ORDER BY name");
    $current_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<ul>";
    foreach ($current_types as $type) {
        echo "<li>{$type['name']} (ID: {$type['id']})</li>";
    }
    echo "</ul>";
    
    // Step 2: Define the 5 main fuel types
    $main_fuel_types = [
        'Diesel' => [
            'category' => 'diesel',
            'description' => 'Regular Diesel Fuel',
            'variants' => ['DIESEL', 'Diesel 1', 'Diesel 2', 'DIESEL 1', 'DIESEL 2']
        ],
        'Turbo Diesel' => [
            'category' => 'diesel',
            'description' => 'High Performance Diesel',
            'variants' => ['TURBO DIESEL', 'Turbo Diesel 1', 'Turbo Diesel 2', 'TURBO DIESEL 1', 'TURBO DIESEL 2']
        ],
        'Kerosene' => [
            'category' => 'kerosene',
            'description' => 'Kerosene Fuel',
            'variants' => ['KEROSENE', 'Kerosene 1', 'KEROSENE 1']
        ],
        'XCS Plus' => [
            'category' => 'premium_gasoline',
            'description' => 'Premium Gasoline 95',
            'variants' => ['XCS PLUS', 'XCS Plus', 'XCS Plus 1', 'XCS PLUS 1']
        ],
        'Xtra UNL' => [
            'category' => 'unleaded',
            'description' => 'Unleaded Gasoline 91',
            'variants' => ['XTRA UNL', 'Xtra UNL', 'Xtra UNL 1', 'Xtra UNL 2', 'XTRA UNL 1', 'XTRA UNL 2']
        ]
    ];
    
    echo "<h3>Step 2: Target 5 Main Fuel Types</h3>";
    echo "<ol>";
    foreach (array_keys($main_fuel_types) as $fuel_name) {
        echo "<li><strong>{$fuel_name}</strong></li>";
    }
    echo "</ol>";
    
    // Step 3: Create or update the 5 main fuel types
    echo "<h3>Step 3: Creating/Updating Main Fuel Types</h3>";
    $fuel_type_map = []; // Maps old variant names to new main fuel type IDs
    
    foreach ($main_fuel_types as $fuel_name => $fuel_info) {
        // Check if main type exists
        $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE name = ? LIMIT 1");
        $stmt->execute([$fuel_name]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $fuel_type_id = $existing['id'];
            // Update description and category
            $pdo->prepare("UPDATE fuel_types SET category = ?, description = ? WHERE id = ?")
                ->execute([$fuel_info['category'], $fuel_info['description'], $fuel_type_id]);
            echo "<p class='info'>✓ Updated: {$fuel_name} (ID: {$fuel_type_id})</p>";
        } else {
            // Create new main fuel type
            $pdo->prepare("INSERT INTO fuel_types (name, category, description) VALUES (?, ?, ?)")
                ->execute([$fuel_name, $fuel_info['category'], $fuel_info['description']]);
            $fuel_type_id = $pdo->lastInsertId();
            echo "<p class='success'>✓ Created: {$fuel_name} (ID: {$fuel_type_id})</p>";
        }
        
        // Map all variants to this main fuel type ID
        foreach ($fuel_info['variants'] as $variant) {
            $fuel_type_map[strtolower(trim($variant))] = [
                'id' => $fuel_type_id,
                'name' => $fuel_name
            ];
        }
        $fuel_type_map[strtolower(trim($fuel_name))] = [
            'id' => $fuel_type_id,
            'name' => $fuel_name
        ];
    }
    
    // Step 4: Update fuel_inventory to use main fuel types
    echo "<h3>Step 4: Updating fuel_inventory</h3>";
    $stmt = $pdo->query("SELECT id, fuel_type FROM fuel_inventory");
    $inventories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($inventories as $inv) {
        $old_fuel_type = $inv['fuel_type'];
        $lookup_key = strtolower(trim($old_fuel_type));
        
        // Try to match with variant
        $new_fuel_type = null;
        foreach ($fuel_type_map as $variant_key => $mapping) {
            if (strpos($lookup_key, $variant_key) !== false || strpos($variant_key, $lookup_key) !== false) {
                $new_fuel_type = $mapping['name'];
                break;
            }
        }
        
        if ($new_fuel_type && $new_fuel_type !== $old_fuel_type) {
            $pdo->prepare("UPDATE fuel_inventory SET fuel_type = ? WHERE id = ?")
                ->execute([$new_fuel_type, $inv['id']]);
            echo "<p class='info'>Inventory #{$inv['id']}: '{$old_fuel_type}' → '{$new_fuel_type}'</p>";
        }
    }
    
    // Step 5: Update fuel_transactions to use main fuel types
    echo "<h3>Step 5: Updating fuel_transactions</h3>";
    $count = 0;
    foreach ($fuel_type_map as $variant_key => $mapping) {
        $stmt = $pdo->prepare("
            UPDATE fuel_transactions 
            SET fuel_type = ? 
            WHERE LOWER(TRIM(fuel_type)) LIKE ?
        ");
        $stmt->execute([$mapping['name'], '%' . $variant_key . '%']);
        if ($stmt->rowCount() > 0) {
            $count += $stmt->rowCount();
            echo "<p class='info'>Updated {$stmt->rowCount()} transactions to '{$mapping['name']}'</p>";
        }
    }
    echo "<p class='success'>✓ Total transactions updated: {$count}</p>";
    
    // Step 6: Update fuel_pumps to use main fuel types
    echo "<h3>Step 6: Updating fuel_pumps</h3>";
    $stmt = $pdo->query("SELECT id, pump_name FROM fuel_pumps");
    $pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($pumps as $pump) {
        $pump_name = $pump['pump_name'];
        $lookup_key = strtolower(trim($pump_name));
        
        // Determine which main fuel type this pump belongs to
        $matched_fuel_type = null;
        $matched_id = null;
        foreach ($fuel_type_map as $variant_key => $mapping) {
            if (strpos($lookup_key, $variant_key) !== false) {
                $matched_fuel_type = $mapping['name'];
                $matched_id = $mapping['id'];
                break;
            }
        }
        
        if ($matched_fuel_type && $matched_id) {
            $pdo->prepare("UPDATE fuel_pumps SET fuel_type_id = ? WHERE id = ?")
                ->execute([$matched_id, $pump['id']]);
            echo "<p class='info'>Pump '{$pump_name}' → Fuel Type ID {$matched_id} ({$matched_fuel_type})</p>";
        }
    }
    
    // Step 7: Delete old variant fuel types (keep only the 5 main ones)
    echo "<h3>Step 7: Removing Old Variant Fuel Types</h3>";
    $main_fuel_names = array_keys($main_fuel_types);
    $placeholders = str_repeat('?,', count($main_fuel_names) - 1) . '?';
    $stmt = $pdo->prepare("DELETE FROM fuel_types WHERE name NOT IN ($placeholders)");
    $stmt->execute($main_fuel_names);
    $deleted_count = $stmt->rowCount();
    echo "<p class='success'>✓ Deleted {$deleted_count} old variant fuel type entries</p>";
    
    $pdo->commit();
    
    echo "<h3 class='success'>✓✓✓ SUCCESS! Fuel types consolidated to 5 main types ✓✓✓</h3>";
    echo "<p><a href='public/manager_fuel_management_complete.php'>→ Go to Fuel Management</a></p>";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<h3 class='error'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</h3>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "</body></html>";
