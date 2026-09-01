<?php
/**
 * Public Maintenance Status API Endpoint
 * Accessible without login so login pages, client heartbeats, and apps can check maintenance state.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../../public/db_connect.php';

try {
    $stmt = $pdo->prepare("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_key IN ('maintenance_mode', 'system_status', 'maintenance_message', 'maintenance_end_time', 'last_system_update') 
          AND station_id = 0
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $is_maintenance = (!empty($rows['maintenance_mode']) && ($rows['maintenance_mode'] === '1' || $rows['maintenance_mode'] == 1));
    $status = $rows['system_status'] ?? ($is_maintenance ? 'Maintenance' : 'Online');
    $message = !empty($rows['maintenance_message']) ? $rows['maintenance_message'] : 'The system is currently undergoing scheduled maintenance to improve performance and stability. Please check back shortly.';
    $end_time = $rows['maintenance_end_time'] ?? '';
    $last_update = $rows['last_system_update'] ?? date('Y-m-d H:i:s');

    $remaining_seconds = 0;
    if ($is_maintenance && !empty($end_time)) {
        $end_ts = strtotime($end_time);
        $now_ts = time();
        if ($end_ts && $end_ts > $now_ts) {
            $remaining_seconds = $end_ts - $now_ts;
        }
    }

    echo json_encode([
        'success'           => true,
        'maintenance_mode'  => $is_maintenance,
        'system_status'     => $status,
        'message'           => $message,
        'end_time'          => $end_time,
        'server_time'       => date('Y-m-d H:i:s'),
        'remaining_seconds' => $remaining_seconds
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success'          => false,
        'maintenance_mode' => false,
        'message'          => $e->getMessage()
    ]);
}
