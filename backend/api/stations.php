<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$action = $_GET['action'] ?? 'list';

switch($action) {
    case 'list':
        try {
            $stmt = $pdo->query("SELECT * FROM stations ORDER BY name");
            $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $stations]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'get':
        $id = $_GET['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT * FROM stations WHERE id = ?");
            $stmt->execute([$id]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($station) {
                echo json_encode(['success' => true, 'data' => $station]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Station not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'add':
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Station name is required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO stations (name, location, status) VALUES (?, ?, ?)");
            $stmt->execute([$name, $location, $status]);
            echo json_encode(['success' => true, 'message' => 'Station added successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update':
        $id = $_POST['id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (empty($id) || empty($name)) {
            echo json_encode(['success' => false, 'error' => 'ID and name are required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("UPDATE stations SET name = ?, location = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $location, $status, $id]);
            echo json_encode(['success' => true, 'message' => 'Station updated successfully']);
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
            $stmt = $pdo->prepare("DELETE FROM stations WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Station deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
