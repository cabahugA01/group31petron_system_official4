<?php
// ============================================================
// SuperAdmin – Admin Map API
// backend/api/superadmin_admin_map_api.php
// Handles map-based admin assignment operations
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../rbac.php';

header('Content-Type: application/json');

// Ensure user is logged in as superadmin
require_login();
$me = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized access.']);
    exit;
}

// CSRF validation
$csrf = $_POST['csrf_token'] ?? '';
if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // ── Assign Admin to Station ──────────────────────────────
        case 'assign_admin_to_station':
            $station_id = (int)($_POST['station_id'] ?? 0);
            $admin_id   = (int)($_POST['admin_id'] ?? 0);

            if (!$station_id || !$admin_id) {
                throw new Exception('Station and Admin are required.');
            }

            // Check if station exists
            $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
            $stmt->execute([$station_id]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$station) {
                throw new Exception('Station not found.');
            }

            // Check if admin exists and is available
            $stmt = $pdo->prepare("
                SELECT id, first_name, last_name, station_id 
                FROM users 
                WHERE id = ? AND role = 'admin'
            ");
            $stmt->execute([$admin_id]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$admin) {
                throw new Exception('Admin not found.');
            }

            // Rule: 1 Admin per station only
            // Check if station already has an admin assigned
            $stmt = $pdo->prepare("
                SELECT id, first_name, last_name 
                FROM users 
                WHERE station_id = ? AND role = 'admin' AND id != ?
            ");
            $stmt->execute([$station_id, $admin_id]);
            $existing_admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_admin) {
                // Unassign the existing admin from this station
                $pdo->prepare("UPDATE users SET station_id = NULL WHERE id = ?")
                    ->execute([$existing_admin['id']]);
                
                log_user_action(
                    'Admin Unassignment', 
                    "Unassigned admin {$existing_admin['first_name']} {$existing_admin['last_name']} (ID: {$existing_admin['id']}) from station '{$station['name']}' (ID: $station_id) via map interface"
                );
            }

            // If admin is currently assigned to another station, unassign them
            if ($admin['station_id'] && $admin['station_id'] != $station_id) {
                $pdo->prepare("UPDATE users SET station_id = NULL WHERE id = ?")
                    ->execute([$admin_id]);
            }

            // Assign admin to station
            $stmt = $pdo->prepare("UPDATE users SET station_id = ? WHERE id = ?");
            $stmt->execute([$station_id, $admin_id]);

            // Log action
            log_user_action(
                'Admin Assignment', 
                "Assigned admin {$admin['first_name']} {$admin['last_name']} (ID: $admin_id) to station '{$station['name']}' (ID: $station_id) via map interface"
            );

            echo json_encode([
                'ok' => true,
                'message' => "Admin successfully assigned to {$station['name']}."
            ]);
            break;

        // ── Unassign Admin from Station ──────────────────────────
        case 'unassign_admin_from_station':
            $station_id = (int)($_POST['station_id'] ?? 0);

            if (!$station_id) {
                throw new Exception('Station ID is required.');
            }

            // Get station details
            $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
            $stmt->execute([$station_id]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$station) {
                throw new Exception('Station not found.');
            }

            // Get admin assigned to this station
            $stmt = $pdo->prepare("
                SELECT id, first_name, last_name 
                FROM users 
                WHERE station_id = ? AND role = 'admin'
            ");
            $stmt->execute([$station_id]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin) {
                throw new Exception('No admin assigned to this station.');
            }

            // Unassign admin
            $pdo->prepare("UPDATE users SET station_id = NULL WHERE id = ?")
                ->execute([$admin['id']]);

            // Log action
            log_user_action(
                'Admin Unassignment', 
                "Unassigned admin {$admin['first_name']} {$admin['last_name']} (ID: {$admin['id']}) from station '{$station['name']}' (ID: $station_id) via map interface"
            );

            echo json_encode([
                'ok' => true,
                'message' => "Admin successfully unassigned from {$station['name']}."
            ]);
            break;

        // ── Get Station Details ───────────────────────────────────
        case 'get_station_details':
            $station_id = (int)($_GET['station_id'] ?? $_POST['station_id'] ?? 0);

            if (!$station_id) {
                throw new Exception('Station ID is required.');
            }

            $stmt = $pdo->prepare("
                SELECT 
                    s.id,
                    s.name,
                    s.location,
                    s.status,
                    COALESCE(u.id, 0) AS admin_id,
                    COALESCE(CONCAT(u.first_name, ' ', u.last_name), '') AS admin_name,
                    COALESCE(u.email, '') AS admin_email,
                    COALESCE(u.phone_number, '') AS admin_phone,
                    COALESCE(u.status, '') AS admin_status
                FROM stations s
                LEFT JOIN users u ON u.station_id = s.id AND u.role = 'admin'
                WHERE s.id = ?
            ");
            $stmt->execute([$station_id]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$station) {
                throw new Exception('Station not found.');
            }

            echo json_encode([
                'ok' => true,
                'station' => $station
            ]);
            break;

        // ── Geocode Station ───────────────────────────────────────
        case 'geocode_station':
            $station_id = (int)($_POST['station_id'] ?? 0);

            if (!$station_id) {
                throw new Exception('Station ID is required.');
            }

            $stmt = $pdo->prepare("SELECT id, name, location, address FROM stations WHERE id = ?");
            $stmt->execute([$station_id]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$station) {
                throw new Exception('Station not found.');
            }

            // Determine address to geocode
            $raw_address = !empty($station['address']) ? $station['address'] : (!empty($station['location']) ? $station['location'] : $station['name']);
            
            // Clean the address to improve matching
            $clutter = [
                'NCR BULACAN SERVICE STATION',
                'BULACAN SERVICE STATION',
                'SERVICE STATION',
                'PETRON',
                'NCR',
                'MINDANAO',
                'SERVICE S',
                'SERVICE'
            ];
            $clean_address = $raw_address;
            foreach ($clutter as $word) {
                $clean_address = str_ireplace($word, '', $clean_address);
            }
            $clean_address = trim($clean_address, " \t\n\r\0\x0B,.-");
            $clean_address = preg_replace('/[,\s]+/', ' ', $clean_address);
            $clean_address = trim($clean_address);

            // Setup multi-stage fallback queries
            $queries = [];
            $queries[] = $clean_address; // Query 1: Full clean address
            
            // Query 2: If address contains junction, take before the junction
            $junction_words = ['/COR\./i', '/\bCOR\b/i', '/\bCORNER\b/i', '/\bAND\b/i', '/&/'];
            foreach ($junction_words as $pattern) {
                if (preg_match($pattern, $clean_address)) {
                    $parts = preg_split($pattern, $clean_address, 2);
                    $main_street = trim($parts[0]);
                    
                    $orig_parts = explode(',', $raw_address);
                    if (count($orig_parts) >= 3) {
                        $city_part = trim($orig_parts[1]) . ', ' . trim($orig_parts[2]);
                        foreach ($clutter as $word) {
                            $city_part = str_ireplace($word, '', $city_part);
                        }
                        $queries[] = $main_street . ', ' . $city_part;
                    }
                    $queries[] = $main_street;
                    break;
                }
            }

            // Query 3: First 3 parts of the address split by comma
            $address_parts = explode(',', $raw_address);
            if (count($address_parts) > 1) {
                $first_parts = implode(', ', array_map('trim', array_slice($address_parts, 0, min(3, count($address_parts)))));
                foreach ($clutter as $word) {
                    $first_parts = str_ireplace($word, '', $first_parts);
                }
                $queries[] = $first_parts;
            }

            // Query 4: Just the first part and the city
            if (count($address_parts) >= 3) {
                $queries[] = trim($address_parts[0]) . ', ' . trim($address_parts[2]);
                $queries[] = trim($address_parts[0]) . ', ' . trim($address_parts[1]);
            }
            
            $queries[] = str_replace(',', ' ', $clean_address);

            // Execute queries in sequence
            $coords = null;
            $used_query = '';
            
            foreach ($queries as $q) {
                $q = trim($q, " \t\n\r\0\x0B,.-");
                if (empty($q) || strlen($q) < 5) continue;
                
                $geocode_query = $q . ', Philippines';
                $url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($geocode_query) . "&limit=1";
                
                $options = [
                    'http' => [
                        'header' => "User-Agent: Petron Station Management System/1.0\r\n"
                    ]
                ];
                $context = stream_context_create($options);
                $response = @file_get_contents($url, false, $context);
                
                if ($response) {
                    $data = json_decode($response, true);
                    if (!empty($data[0])) {
                        $coords = [
                            'lat' => (float)$data[0]['lat'],
                            'lng' => (float)$data[0]['lon']
                        ];
                        $used_query = $geocode_query;
                        break;
                    }
                }
                usleep(100000); // 0.1s rate limiting compliance
            }

            if ($coords) {
                // Update database
                $pdo->prepare("UPDATE stations SET latitude = ?, longitude = ? WHERE id = ?")
                    ->execute([$coords['lat'], $coords['lng'], $station_id]);
                
                echo json_encode([
                    'ok' => true,
                    'latitude' => $coords['lat'],
                    'longitude' => $coords['lng']
                ]);
            } else {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Could not find exact coordinates for: ' . $raw_address
                ]);
            }
            break;

        default:
            throw new Exception('Invalid action.');
    }

} catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
?>
