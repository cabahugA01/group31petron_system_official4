<?php
/**
 * API Endpoint: Get Previous Pump Reading
 * Fetches the most recent reading for a given pump and shift combination
 */

require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../lib.php';

// Get current user from session
$user = $_SESSION['user'] ?? null;

if (!$user) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');

$station_id = (int)($_GET['station_id'] ?? 0);
$shift = $_GET['shift'] ?? '';
$reading_date = $_GET['reading_date'] ?? date('Y-m-d');

if ($station_id <= 0 || !$shift) {
    echo json_encode(['error' => 'Missing parameters: station_id and shift are required']);
    exit;
}

try {
    // Get the most recent reading for this pump/shift combination
    // Look for readings on the same date or previous dates
    $stmt = $pdo->prepare("
        SELECT 
            dr.id,
            dr.previous_reading,
            dr.current_reading,
            dr.reading_date,
            dr.shift,
            dr.user_name,
            fs.fuel_type
        FROM fuel_daily_readings dr
        LEFT JOIN fuel_stations fs ON dr.fuel_station_id = fs.id
        LEFT JOIN users u ON dr.user_id = u.id
        WHERE dr.fuel_station_id = ?
          AND dr.shift = ?
          AND dr.reading_date <= ?
          AND dr.status = 'Verified'
        ORDER BY dr.reading_date DESC, dr.id DESC
        LIMIT 1
    ");
    
    $stmt->execute([$station_id, $shift, $reading_date]);
    $reading = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reading) {
        echo json_encode([
            'success' => true,
            'previous_reading' => $reading['current_reading'], // The current reading of the previous session becomes the previous for new one
            'pump_fuel_type' => $reading['fuel_type'],
            'last_reading_date' => $reading['reading_date'],
            'last_shift' => $reading['shift'],
            'last_user' => $reading['user_name']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'previous_reading' => null,
            'message' => 'No previous reading found for this pump and shift on or before this date'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
