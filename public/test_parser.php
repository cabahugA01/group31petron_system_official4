<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$stations = $pdo->query("SELECT id, name, location, address, region FROM stations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Let's test a parser on each station
function parse_ph_address($s) {
    $id = $s['id'];
    $name = $s['name'];
    $raw = $s['address'] ?: $s['name'];
    
    // Replace mojibake
    $raw = str_replace('??', 'ñ', $raw);
    $raw = str_replace(['????', '???'], 'ñ', $raw);
    $raw = preg_replace('/\s*\((?:Car Care Center|Treats Store|Service Station)\)/i', '', $raw);
    $raw = trim($raw);
    
    // Split by comma
    $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
    $count = count($parts);
    
    $street = '';
    $barangay = '';
    $city = '';
    $province = '';
    
    // Special cases like Station 1
    if ($id == 1 || stripos($name, 'CDO - Kauswagan') !== false) {
        return [
            'street' => 'National Highway (RN Pelaez Blvd), Zone 5',
            'barangay' => 'Kauswagan',
            'city' => 'Cagayan de Oro City',
            'province' => 'Misamis Oriental',
            'region' => 'Region X'
        ];
    }
    
    // NCR detection
    $is_ncr = false;
    foreach ($parts as $p) {
        if (preg_match('/\b(NCR|Metro Manila|Manila|Quezon City|Caloocan|Las Piñas|Makati|Malabon|Mandaluyong|Marikina|Muntinlupa|Navotas|Parañaque|Pasay|Pasig|San Juan|Taguig|Valenzuela|Pateros|Third District|Second District|Frist District|First District|Fourth District)\b/i', $p)) {
            $is_ncr = true;
            break;
        }
    }
    
    // Clean district / NCR tags from last part
    $last = $parts[$count - 1] ?? '';
    if (preg_match('/^(?:\((?:Third|Second|Frist|First|Fourth) District\)|NCR|Metro Manila)$/i', $last)) {
        array_pop($parts);
        $count = count($parts);
        $is_ncr = true;
    }
    
    if ($is_ncr) {
        $ncr_cities = [
            'City Of Manila' => 'Manila',
            'Manila' => 'Manila',
            'Quezon City' => 'Quezon City',
            'City Of Caloocan' => 'Caloocan',
            'Caloocan' => 'Caloocan',
            'City Of Las Piñas' => 'Las Piñas',
            'Las Piñas' => 'Las Piñas',
            'City Of Makati' => 'Makati',
            'Makati' => 'Makati',
            'City Of Malabon' => 'Malabon',
            'Malabon' => 'Malabon',
            'City Of Mandaluyong' => 'Mandaluyong',
            'Mandaluyong' => 'Mandaluyong',
            'City Of Marikina' => 'Marikina',
            'Marikina' => 'Marikina',
            'City Of Muntinlupa' => 'Muntinlupa',
            'Muntinlupa' => 'Muntinlupa',
            'City Of Navotas' => 'Navotas',
            'Navotas' => 'Navotas',
            'City Of Parañaque' => 'Parañaque',
            'Parañaque City' => 'Parañaque',
            'Parañaque' => 'Parañaque',
            'Pasay City' => 'Pasay',
            'City Of Pasay' => 'Pasay',
            'Pasay' => 'Pasay',
            'City Of Pasig' => 'Pasig',
            'Pasig City' => 'Pasig',
            'Pasig' => 'Pasig',
            'San Juan City' => 'San Juan',
            'City Of San Juan' => 'San Juan',
            'San Juan' => 'San Juan',
            'Taguig City' => 'Taguig',
            'City Of Taguig' => 'Taguig',
            'Taguig' => 'Taguig',
            'Valenzuela City' => 'Valenzuela',
            'City Of Valenzuela' => 'Valenzuela',
            'Valenzuela' => 'Valenzuela',
            'Pateros' => 'Pateros'
        ];
        
        $found_city = '';
        foreach ($parts as $idx => $p) {
            foreach ($ncr_cities as $alias => $cname) {
                if (stripos($p, $alias) !== false) {
                    $found_city = $cname;
                    break 2;
                }
            }
        }
        if (!$found_city) $found_city = 'Manila';
        
        // Barangay detection
        $found_brgy = '';
        foreach ($parts as $idx => $p) {
            if (preg_match('/\b(Barangay|Brgy\.?|Bgy\.?|Pob\.?|Poblacion)\s*([^,]+)/i', $p, $m)) {
                $found_brgy = trim($p);
                break;
            }
        }
        if (!$found_brgy && $count >= 2) {
            $found_brgy = $parts[1];
        }
        
        $street = $parts[0] ?? '';
        return [
            'street' => $street,
            'barangay' => $found_brgy ?: 'Poblacion',
            'city' => $found_city . (stripos($found_city, 'City') === false && $found_city !== 'Pateros' ? ' City' : ''),
            'province' => 'Metro Manila',
            'region' => 'NCR'
        ];
    }
    
    // Non-NCR
    if ($count >= 4) {
        $province = $parts[$count - 1];
        $city = $parts[$count - 2];
        $barangay = $parts[$count - 3];
        $street = implode(', ', array_slice($parts, 0, $count - 3));
    } elseif ($count == 3) {
        $province = $parts[2];
        $city = $parts[1];
        $barangay = 'Poblacion';
        $street = $parts[0];
    } elseif ($count == 2) {
        $province = $parts[1];
        $city = $parts[0];
        $barangay = 'Poblacion';
        $street = 'National Highway';
    } else {
        $province = $s['location'] ?: 'Unknown';
        $city = $raw;
        $barangay = 'Poblacion';
        $street = 'Main Road';
    }
    
    // Clean Bulacan NCR Bulacan or similar
    $province = trim(preg_replace('/\bNCR\b|\bBulacan\s+NCR\b/i', '', $province));
    if (empty($province) && stripos($raw, 'Bulacan') !== false) $province = 'Bulacan';
    
    return [
        'street' => $street,
        'barangay' => $barangay,
        'city' => $city,
        'province' => $province,
        'region' => $s['region']
    ];
}

$sample_parsed = [];
for ($i = 0; $i < 30; $i++) {
    $s = $stations[$i];
    $sample_parsed[] = [
        'id' => $s['id'],
        'name' => $s['name'],
        'parsed' => parse_ph_address($s)
    ];
}

echo json_encode($sample_parsed, JSON_PRETTY_PRINT);
