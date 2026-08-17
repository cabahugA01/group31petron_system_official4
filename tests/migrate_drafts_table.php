<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== MIGRATING user_form_drafts TABLE ===\n";

$sql = "CREATE TABLE IF NOT EXISTS user_form_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    station_id INT NULL,
    module_key VARCHAR(100) NOT NULL,
    draft_key VARCHAR(150) NOT NULL,
    form_data LONGTEXT NOT NULL,
    status ENUM('draft', 'submitted', 'discarded') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_module (user_id, module_key),
    INDEX idx_user_status (user_id, status),
    INDEX idx_station_module (station_id, module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

try {
    $pdo->exec($sql);
    echo "[OK] Table user_form_drafts created or verified.\n";
} catch (Exception $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
}
