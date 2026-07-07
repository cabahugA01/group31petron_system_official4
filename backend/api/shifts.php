<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$action = $_GET['action'] ?? 'list';

switch($action) {
    case 'list':
        try {
            $stmt = $pdo->query("SELECT * FROM shifts ORDER BY start_time");
            $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $shifts]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'get':
        $id = $_GET['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT * FROM shifts WHERE id = ?");
            $stmt->execute([$id]);
            $shift = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($shift) {
                echo json_encode(['success' => true, 'data' => $shift]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Shift not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'add':
        $name = trim($_POST['name'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name) || empty($start_time) || empty($end_time)) {
            echo json_encode(['success' => false, 'error' => 'Name, start time, and end time are required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO shifts (name, start_time, end_time, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $start_time, $end_time, $description]);
            echo json_encode(['success' => true, 'message' => 'Shift added successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update':
        $id = $_POST['id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($id) || empty($name) || empty($start_time) || empty($end_time)) {
            echo json_encode(['success' => false, 'error' => 'ID, name, start time, and end time are required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("UPDATE shifts SET name = ?, start_time = ?, end_time = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $start_time, $end_time, $description, $id]);
            echo json_encode(['success' => true, 'message' => 'Shift updated successfully']);
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
            $stmt = $pdo->prepare("DELETE FROM shifts WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Shift deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
