<?php
require_once __DIR__ . '/../public/db_connect.php';
// Update station id=1 (Petron CDO) with proper address details
$pdo->prepare("
    UPDATE stations SET
        name = 'Petron Carmen',
        address = 'Vamenta Blvd., Carmen, City of Cagayan de Oro, Misamis Oriental',
        location = 'Carmen, Cagayan de Oro',
        contact_number = 'N/A',
        updated_at = NOW()
    WHERE id = 1
")->execute();
echo "Station ID 1 updated.\n";
// Verify
$row = $pdo->query("SELECT id, name, address, location, contact_number FROM stations WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
