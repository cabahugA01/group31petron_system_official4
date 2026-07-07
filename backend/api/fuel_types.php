<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$action = $_GET['action'] ?? 'list';

switch($action) {
    case 'list':
        try {
            $stmt = $pdo->query("SELECT * FROM fuel_types ORDER BY name");
            $fuelTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $fuelTypes]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'get':
        $id = $_GET['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT * FROM fuel_types WHERE id = ?");
            $stmt->execute([$id]);
            $fuelType = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fuelType) {
                echo json_encode(['success' => true, 'data' => $fuelType]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Fuel type not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'add':
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Fuel type name is required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO fuel_types (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $description]);
            echo json_encode(['success' => true, 'message' => 'Fuel type added successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update':
        $id = $_POST['id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($id) || empty($name)) {
            echo json_encode(['success' => false, 'error' => 'ID and name are required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("UPDATE fuel_types SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $description, $id]);
            echo json_encode(['success' => true, 'message' => 'Fuel type updated successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'delete':
        $id = $_POST['id'] ?? 0;

        if (empty($id)) {
            echo json_encode(['success' => false, 'error' => 'ID is required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM fuel_types WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Fuel type deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
