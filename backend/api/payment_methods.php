<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$action = $_GET['action'] ?? 'list';

switch($action) {
    case 'list':
        try {
            $stmt = $pdo->query("SELECT * FROM payment_methods WHERE status = 'Active' ORDER BY name");
            $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $methods]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'get':
        $id = $_GET['id'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ?");
            $stmt->execute([$id]);
            $method = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($method) {
                echo json_encode(['success' => true, 'data' => $method]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Payment method not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'add':
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Payment method name is required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO payment_methods (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $description]);
            echo json_encode(['success' => true, 'message' => 'Payment method added successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update':
        $id = $_POST['id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 'Active' : 'Inactive';

        if (empty($id) || empty($name)) {
            echo json_encode(['success' => false, 'error' => 'ID and name are required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("UPDATE payment_methods SET name = ?, description = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $description, $is_active, $id]);
            echo json_encode(['success' => true, 'message' => 'Payment method updated successfully']);
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
            $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Payment method deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
