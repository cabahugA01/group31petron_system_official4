<?php
/**
 * setup_password_resets_table.php
 * Creates the password_resets table required for phone-based OTP reset.
 * Safe to run multiple times.
 */
require_once __DIR__ . '/../public/db_connect.php';
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Setup password_resets Table</title>
<style>
  body{font-family:monospace;background:#0d1117;color:#c9d1d9;padding:30px;max-width:700px;margin:auto}
  h1{color:#58a6ff;border-bottom:2px solid #30363d;padding-bottom:8px}
  .ok{color:#3fb950}.err{color:#f85149}.info{color:#e3b341}
  pre{background:#161b22;padding:16px;border:1px solid #30363d;border-radius:6px;color:#e6edf3;overflow-x:auto}
</style></head><body>
<h1>🔧 Setup: password_resets Table</h1>
<?php

try {
    // Drop & recreate for clean setup (idempotent)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `password_resets` (
          `id`           INT(11)      NOT NULL AUTO_INCREMENT,
          `phone_number` VARCHAR(20)  NOT NULL,
          `otp_code`     CHAR(6)      NOT NULL,
          `expiry`       DATETIME     NOT NULL,
          `status`       ENUM('unused','used') NOT NULL DEFAULT 'unused',
          `ip_address`   VARCHAR(45)  DEFAULT NULL,
          `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          INDEX `idx_phone_otp` (`phone_number`, `otp_code`),
          INDEX `idx_expiry`    (`expiry`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<div class='ok'>✅ Table <code>password_resets</code> is ready.</div>";

    // Verify columns
    $cols = $pdo->query("SHOW COLUMNS FROM password_resets")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    foreach ($cols as $c) {
        echo str_pad($c['Field'], 16) . " | " . str_pad($c['Type'], 30) . " | " . $c['Null'] . " | " . $c['Default'] . "\n";
    }
    echo "</pre>";

    echo "<div class='ok'>✅ Done! You can now delete this file.</div>";
} catch (Throwable $e) {
    echo "<div class='err'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>
</body></html>
