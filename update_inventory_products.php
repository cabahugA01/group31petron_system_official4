<?php
require_once __DIR__ . '/public/db_connect.php';

try {  echo "Adding columns to inventory_products...\n";  try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'"); } catch (Exception $e) {}  try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN min_stock INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}  try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN max_stock INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}  try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN sku VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}  try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Exception $e) {}  try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Exception $e) {}  try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN station_id INT NOT NULL DEFAULT 1"); } catch (Exception $e) {}  echo "inventory_products columns updated successfully!\n";
} catch (Exception $e) {  echo "ERROR: " . $e->getMessage() . "\n";
}
