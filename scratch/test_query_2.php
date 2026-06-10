<?php
require_once __DIR__ . '/../public/db_connect.php';
$station_id = 1253;
try {
    $stmt = $pdo->prepare("
        SELECT
            fi.fuel_type,
            fi.current_level,
            fi.current_stock,
            fi.latest_calibration,
            fi.price_per_liter,
            fi.last_updated,
            fi.fuel_type_id,
            fp.id            AS pump_db_id,
            fp.pump_number,
            fp.calibration_value,
            fp.calibration_updated_at AS last_calibration_date,
            fp.status        AS pump_status,
            u.name           AS calibration_encoded_by
        FROM fuel_inventory fi
        LEFT JOIN fuel_pumps fp
            ON fp.station_id = fi.station_id
            AND fp.fuel_type_id = fi.fuel_type_id
        LEFT JOIN users u ON fp.calibration_updated_by = u.id
        WHERE fi.station_id = ?
        ORDER BY fi.fuel_type ASC, fp.pump_number ASC
    ");
    $stmt->execute([$station_id]);
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Count: " . count($res) . "\n";
    print_r($res);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
