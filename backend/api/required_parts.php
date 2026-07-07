<?php
/**
 * Required Parts API
 * Provides database-driven access to service types and their required parts
 */

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

/**
 * Helper function to get merchandise parts for a service type
 */
function getMerchandisePartsForService($serviceName, $pdo) {
    // Service type to merchandise mapping based on user specifications
    $servicePartsMapping = [
        'Oil Change' => [
            'Engine Oil – HD 30',
            'Engine Oil – HD 40', 
            'Engine Oil – Ultron Touring',
            'Engine Oil – Blaze Racing',
            'Engine Oil – MO 30',
            'Engine Oil – MO 40',
            'Oil Filter – Nomis',
            'Oil Filter – VIC',
            'Oil Filter – Sakura',
            'Oil Filter – C-series filters',
            'Gasket Maker'
        ],
        'Tire Repair' => [
            'Tire Valve Rubber',
            'Tire Valve Steel',
            'MP1 Patch (Med)',
            'MP2 Patch (Large)',
            'CT20 Radial Patch',
            'Valkarn Cement'
        ],
        'Calibration' => [
            'Hydrotur (oil/lube)',
            'MP Grease (sealant)',
            'Standard Gauge (from accessories)'
        ],
        'General Maintenance' => [
            'MP Grease',
            'WD-40',
            'Petromate Penetrating Oil',
            'Armor All (Small/Big)',
            'VS1 Protector (Small/Big)',
            'Chamois/Kanebo'
        ],
        'Engine Repair' => [
            'Engine Oil – HD series',
            'Engine Oil – Ultron',
            'Engine Oil – Blaze Racing',
            'Engine Oil – Trekker',
            'Oil Filter – Nomis',
            'Oil Filter – VIC',
            'Oil Filter – Sakura',
            'Oil Filter – C-series filters',
            'Coolant – Regular',
            'Coolant – Green',
            'Coolant – Pink',
            'Gasket Maker'
        ],
        'Brake Service' => [
            'Brake Fluid 900ml',
            'Brake Fluid Med',
            'Brake Fluid Small',
            'Break Cleaner Hardex'
        ],
        'Electrical' => [
            'WD-40',
            'Petromate Penetrating Oil',
            'MP Grease (for terminals)'
        ],
        'Air Conditioning' => [
            'Coolant Green',
            'Coolant Pink',
            'AC Filter (Oil/Fuel Filter variants)',
            'O-rings (from accessories)'
        ],
        'Transmission Service' => [
            'ATF Premium',
            'ATF HTF',
            'Transmission Filter (Fuel/Oil Filter variants)',
            'Gasket Maker'
        ],
        'Suspension Repair' => [
            'MP Grease (for bushings/ball joints)',
            'Shock Absorber (if stocked)'
        ],
        'Wheel Alignment' => [
            'Tire Valve Rubber',
            'Tire Valve Steel',
            'Alignment Bolts/Wheel Weights (from accessories)'
        ],
        'Battery Replacement' => [
            'Car Battery (if stocked under accessories)',
            'MP Grease (small packs for terminals)'
        ],
        'Diagnostic Check' => [
            'OBD Scanner (tool, if stocked)',
            'Diagnostic Printout Paper (office supply)'
        ],
        'Detailing / Cleaning' => [
            'Clean N Shine Shampoo',
            'Armor All (Small/Big)',
            'Tire Black (Small/Big)',
            'Chamois/Kanebo',
            'Air Freshener – Neo Shaldan',
            'Air Freshener – California Scents',
            'Air Freshener – Little Trees',
            'Air Freshener – Glade Spray'
        ]
    ];
    
    $parts = [];
    
    // Get the required part names for this service type
    $requiredParts = isset($servicePartsMapping[$serviceName]) ? $servicePartsMapping[$serviceName] : [];
    
    if (empty($requiredParts)) {
        // For service types not in mapping, return empty array for manual input
        return [];
    }
    
    // Find matching merchandise products in inventory
    foreach ($requiredParts as $partName) {
        // Normalize the part name - replace en dash with hyphen for matching
        $normalizedName = str_replace('–', '-', $partName);
        
        // Try to find exact match first with normalized name
        $stmt = $pdo->prepare("SELECT id, product_name, category, unit_cost FROM inventory_products WHERE product_name LIKE ? AND category != 'Fuel' LIMIT 1");
        $stmt->execute(["%" . $normalizedName . "%"]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            $parts[] = [
                'part_name' => $product['product_name'],
                'part_category' => $product['category'],
                'is_merchandise' => true,
                'inventory_product_id' => $product['id'],
                'unit_of_measure' => 'pc',
                'default_quantity' => 1,
                'is_default_selected' => true,
                'sort_order' => count($parts),
                'inventory_product_name' => $product['product_name'],
                'inventory_cost' => (float)$product['unit_cost']
            ];
        } else {
            // If not found in inventory, add as manual part
            $parts[] = [
                'part_name' => $partName,
                'part_category' => 'Manual Input',
                'is_merchandise' => true,
                'inventory_product_id' => null,
                'unit_of_measure' => 'pc',
                'default_quantity' => 1,
                'is_default_selected' => true,
                'sort_order' => count($parts),
                'inventory_product_name' => 'Not in inventory',
                'inventory_cost' => 0
            ];
        }
    }
    
    return $parts;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $pdo = $GLOBALS['pdo'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'get_service_types':
            // Get all active service types
            $stmt = $pdo->query("
                SELECT service_key, service_name, base_rate_per_hour, icon_class, color_class, 
                       allows_custom_input, allows_manual_parts, sort_order
                FROM job_order_service_types 
                WHERE active = TRUE 
                ORDER BY sort_order, service_name
            ");
            $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $service_types
            ]);
            break;

        case 'get_required_parts':
            // Get required parts for a specific service type
            $service_type = $_GET['service_type'] ?? '';
            
            if (empty($service_type)) {
                throw new Exception('Service type is required');
            }

            // First try to get from service_type_inventory_mapping table
            $stmt = $pdo->prepare("
                SELECT 
                    stim.required_part as part_name,
                    stim.part_category,
                    stim.is_merchandise,
                    NULL as inventory_product_id,
                    'pc' as unit_of_measure,
                    1 as default_quantity,
                    TRUE as is_default_selected,
                    stim.sort_order,
                    'Not in inventory' as inventory_product_name,
                    0 as inventory_cost
                FROM service_type_inventory_mapping stim
                WHERE stim.service_type = ? 
                ORDER BY stim.sort_order, stim.required_part
            ");
            
            $stmt->execute([$service_type]);
            $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no parts found in service_type_inventory_mapping, try the old tables
            if (empty($parts)) {
                $stmt = $pdo->prepare("
                    SELECT 
                        rpm.part_name,
                        rpm.part_category,
                        rpm.is_merchandise,
                        rpm.inventory_product_id,
                        rpm.unit_of_measure,
                        rpm.default_quantity,
                        strp.is_default_selected,
                        strp.sort_order,
                        COALESCE(ip.product_name, 'Not in inventory') as inventory_product_name,
                        COALESCE(ip.unit_cost, 0) as inventory_cost
                    FROM service_type_required_parts strp
                    JOIN required_parts_master rpm ON strp.part_id = rpm.id
                    LEFT JOIN inventory_products ip ON rpm.inventory_product_id = ip.id
                    WHERE strp.service_type_key = ? 
                      AND rpm.active = TRUE
                    ORDER BY strp.sort_order, rpm.part_name
                ");
                $stmt->execute([$service_type]);
                $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $parts,
                'service_type' => $service_type
            ]);
            break;

        case 'get_all_service_parts':
            // Get all service types with their required parts
            // First try service_type_inventory_mapping
            $stmt = $pdo->query("
                SELECT 
                    st.service_key,
                    st.service_name,
                    st.base_rate_per_hour,
                    st.icon_class,
                    st.color_class,
                    stim.required_part as part_name,
                    stim.part_category,
                    stim.is_merchandise,
                    NULL as inventory_product_id,
                    'pc' as unit_of_measure,
                    1 as default_quantity,
                    TRUE as is_default_selected,
                    stim.sort_order,
                    'Not in inventory' as inventory_product_name,
                    0 as inventory_cost
                FROM job_order_service_types st
                LEFT JOIN service_type_inventory_mapping stim ON st.service_name = stim.service_type
                WHERE st.active = TRUE
                ORDER BY st.sort_order, stim.sort_order, stim.required_part
            ");
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no results from service_type_inventory_mapping, try old tables
            if (empty($results) || (count($results) === 1 && $results[0]['part_name'] === null)) {
                $stmt = $pdo->query("
                    SELECT 
                        st.service_key,
                        st.service_name,
                        st.base_rate_per_hour,
                        st.icon_class,
                        st.color_class,
                        rpm.part_name,
                        rpm.part_category,
                        rpm.is_merchandise,
                        rpm.inventory_product_id,
                        rpm.unit_of_measure,
                        rpm.default_quantity,
                        strp.is_default_selected,
                        strp.sort_order,
                        COALESCE(ip.product_name, 'Not in inventory') as inventory_product_name,
                        COALESCE(ip.unit_cost, 0) as inventory_cost
                    FROM job_order_service_types st
                    LEFT JOIN service_type_required_parts strp ON st.service_key = strp.service_type_key
                    LEFT JOIN required_parts_master rpm ON strp.part_id = rpm.id AND rpm.active = TRUE
                    LEFT JOIN inventory_products ip ON rpm.inventory_product_id = ip.id
                    WHERE st.active = TRUE
                    ORDER BY st.sort_order, strp.sort_order, rpm.part_name
                ");
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Group by service type
            $service_parts = [];
            foreach ($results as $row) {
                $service_key = $row['service_key'];
                
                if (!isset($service_parts[$service_key])) {
                    $service_parts[$service_key] = [
                        'service_key' => $row['service_key'],
                        'service_name' => $row['service_name'],
                        'base_rate_per_hour' => $row['base_rate_per_hour'],
                        'icon_class' => $row['icon_class'],
                        'color_class' => $row['color_class'],
                        'parts' => []
                    ];
                }
                
                if ($row['part_name']) {
                    $service_parts[$service_key]['parts'][] = [
                        'part_name' => $row['part_name'],
                        'part_category' => $row['part_category'],
                        'is_merchandise' => (bool)$row['is_merchandise'],
                        'inventory_product_id' => $row['inventory_product_id'],
                        'unit_of_measure' => $row['unit_of_measure'],
                        'default_quantity' => (int)$row['default_quantity'],
                        'is_default_selected' => (bool)$row['is_default_selected'],
                        'sort_order' => (int)$row['sort_order'],
                        'inventory_product_name' => $row['inventory_product_name'],
                        'inventory_cost' => (float)$row['inventory_cost']
                    ];
                }
            }
            
            // If no parts found in previous queries, try service_parts_map table (our main mapping table)
            $has_parts = false;
            foreach ($service_parts as $service) {
                if (!empty($service['parts'])) {
                    $has_parts = true;
                    break;
                }
            }
            
            if (!$has_parts) {
                $stmt = $pdo->query("
                    SELECT 
                        st.service_key,
                        st.service_name,
                        st.base_rate_per_hour,
                        st.icon_class,
                        st.color_class,
                        ip.product_name as part_name,
                        ip.category as part_category,
                        1 as is_merchandise,
                        ip.id as inventory_product_id,
                        'pc' as unit_of_measure,
                        1 as default_quantity,
                        TRUE as is_default_selected,
                        spm.sort_order,
                        ip.product_name as inventory_product_name,
                        ip.unit_cost as inventory_cost
                    FROM job_order_service_types st
                    LEFT JOIN service_parts_map spm ON st.service_key = spm.service_key
                    LEFT JOIN inventory_products ip ON spm.part_name = ip.product_name
                    WHERE st.active = TRUE
                    ORDER BY st.sort_order, spm.sort_order, ip.product_name
                ");
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Group by service type
                $service_parts = [];
                foreach ($results as $row) {
                    $service_key = $row['service_key'];
                    
                    if (!isset($service_parts[$service_key])) {
                        $service_parts[$service_key] = [
                            'service_key' => $row['service_key'],
                            'service_name' => $row['service_name'],
                            'base_rate_per_hour' => $row['base_rate_per_hour'],
                            'icon_class' => $row['icon_class'],
                            'color_class' => $row['color_class'],
                            'parts' => []
                        ];
                    }
                    
                    // Add part if it exists
                    if ($row['part_name']) {
                        $service_parts[$service_key]['parts'][] = [
                            'part_name' => $row['part_name'],
                            'part_category' => $row['part_category'],
                            'is_merchandise' => (bool)$row['is_merchandise'],
                            'inventory_product_id' => $row['inventory_product_id'],
                            'unit_of_measure' => $row['unit_of_measure'],
                            'default_quantity' => (int)$row['default_quantity'],
                            'is_default_selected' => (bool)$row['is_default_selected'],
                            'sort_order' => (int)$row['sort_order'],
                            'inventory_product_name' => $row['inventory_product_name'],
                            'inventory_cost' => (float)$row['inventory_cost']
                        ];
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => array_values($service_parts)
            ]);
            break;

        case 'get_part_categories':
            // Get all part categories
            $stmt = $pdo->query("
                SELECT category_key, category_name, description, sort_order
                FROM part_categories 
                WHERE active = TRUE 
                ORDER BY sort_order, category_name
            ");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $categories
            ]);
            break;

        case 'search_parts':
            // Search parts by name or category
            $search_term = $_GET['search'] ?? '';
            
            if (empty($search_term)) {
                throw new Exception('Search term is required');
            }
            
            $stmt = $pdo->prepare("
                SELECT 
                    rpm.id,
                    rpm.part_name,
                    rpm.part_category,
                    rpm.is_merchandise,
                    rpm.inventory_product_id,
                    rpm.unit_of_measure,
                    rpm.default_quantity,
                    COALESCE(ip.product_name, 'Not in inventory') as inventory_product_name,
                    COALESCE(ip.unit_cost, 0) as inventory_cost
                FROM required_parts_master rpm
                LEFT JOIN inventory_products ip ON rpm.inventory_product_id = ip.id
                WHERE rpm.active = TRUE 
                  AND (rpm.part_name LIKE ? OR rpm.part_category LIKE ?)
                ORDER BY rpm.part_category, rpm.part_name
                LIMIT 50
            ");
            
            $search_param = "%{$search_term}%";
            $stmt->execute([$search_param, $search_param]);
            $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $parts,
                'search_term' => $search_term
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
