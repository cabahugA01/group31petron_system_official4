<?php
/**
 * Shared inventory data helpers.
 *
 * Keep inventory-facing pages aligned to the database source of truth instead
 * of page-local product or tank lists.
 */

function inventory_get_fuel_tank_config(PDO $pdo, int $station_id): array
{
    if ($station_id <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            fuel_type,
            COALESCE(NULLIF(current_level, 0), current_stock, 0) AS current_level,
            COALESCE(capacity, 0) AS capacity,
            last_updated
        FROM fuel_inventory
        WHERE station_id = ?
        ORDER BY fuel_type, id
    ");
    $stmt->execute([$station_id]);

    $rows = [];
    $seq = 1;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fuel_type = trim((string)($row['fuel_type'] ?? ''));
        if ($fuel_type === '') {
            $fuel_type = 'Fuel #' . (int)$row['id'];
        }

        $rows[] = [
            'fuel_inventory_id' => (int)$row['id'],
            'fuel_type' => $fuel_type,
            'label' => strtoupper($fuel_type) . ' TANK',
            'tank' => 'Fuel inventory record #' . (int)$row['id'],
            'tanker_num' => $seq++,
            'capacity' => (float)($row['capacity'] ?? 0),
            'current_level' => (float)($row['current_level'] ?? 0),
            'last_updated' => $row['last_updated'] ?? null,
        ];
    }

    return $rows;
}

function inventory_fuel_type_options(array $rows): array
{
    $types = [];
    foreach ($rows as $row) {
        $type = trim((string)($row['fuel_type'] ?? ''));
        if ($type !== '') {
            $types[$type] = $type;
        }
    }
    natcasesort($types);
    return array_values($types);
}
?>
