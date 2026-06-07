<?php
/**
 * Database Migration: Rename users table columns to new standardized schema
 * Run once via: http://localhost/group31petron_system_official4/database/migrate_users_columns.php
 * DELETE this file after successful migration!
 */

require_once __DIR__ . '/../public/db_connect.php';

$steps   = [];
$errors  = [];
$success = true;

function step($pdo, &$steps, &$errors, $label, $sql) {
    try {
        $pdo->exec($sql);
        $steps[] = "✅ $label";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Ignore "duplicate column" and "doesn't exist" — means already migrated
        if (strpos($msg, 'Duplicate column') !== false
            || strpos($msg, "Can't DROP") !== false
            || strpos($msg, "check that column") !== false
            || strpos($msg, 'already exists') !== false) {
            $steps[] = "⏭️  $label (already done — skipped)";
        } else {
            $steps[]  = "❌ $label — FAILED";
            $errors[] = "$label: $msg";
        }
    }
}

// ── 0. Show current users columns ────────────────────────────────────
$existing = [];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) $existing[] = $c['Field'];
    $steps[] = "ℹ️  Current columns: " . implode(', ', $existing);
} catch (PDOException $e) {
    $errors[] = "Cannot read users table: " . $e->getMessage();
    $success  = false;
}

if ($success && !empty($existing)) {

    // ── 1. Drop FK constraints that reference users(id) ──────────────
    try {
        $fks = $pdo->query("
            SELECT TABLE_NAME, CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME   = 'users'
              AND REFERENCED_COLUMN_NAME  = 'id'
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fks as $fk) {
            step($pdo, $steps, $errors,
                "Drop FK {$fk['CONSTRAINT_NAME']} on {$fk['TABLE_NAME']}",
                "ALTER TABLE `{$fk['TABLE_NAME']}` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`"
            );
        }
        if (empty($fks)) $steps[] = "ℹ️  No FK constraints to drop";
    } catch (PDOException $e) {
        $errors[] = "FK lookup failed: " . $e->getMessage();
    }

    // ── 2. Rename id → user_id ───────────────────────────────────────
    if (in_array('id', $existing) && !in_array('user_id', $existing)) {
        step($pdo, $steps, $errors,
            "Rename id → user_id",
            "ALTER TABLE users CHANGE COLUMN `id` `user_id` INT(11) NOT NULL AUTO_INCREMENT"
        );
    } elseif (in_array('user_id', $existing)) {
        $steps[] = "⏭️  user_id already exists — skipped";
    }

    // ── 3. Rename phone → phone_number ───────────────────────────────
    if (in_array('phone', $existing) && !in_array('phone_number', $existing)) {
        step($pdo, $steps, $errors,
            "Rename phone → phone_number",
            "ALTER TABLE users CHANGE COLUMN `phone` `phone_number` VARCHAR(20) DEFAULT NULL"
        );
    } elseif (in_array('phone_number', $existing)) {
        $steps[] = "⏭️  phone_number already exists — skipped";
    }

    // ── 4. Rename password → password_hash ───────────────────────────
    if (in_array('password', $existing) && !in_array('password_hash', $existing)) {
        step($pdo, $steps, $errors,
            "Rename password → password_hash",
            "ALTER TABLE users CHANGE COLUMN `password` `password_hash` VARCHAR(255) NOT NULL DEFAULT ''"
        );
    } elseif (in_array('password_hash', $existing)) {
        $steps[] = "⏭️  password_hash already exists — skipped";
    }

    // ── 5. Ensure status ENUM is correct ────────────────────────────
    step($pdo, $steps, $errors,
        "Update status column to ENUM('Active','Locked','Disabled')",
        "ALTER TABLE users MODIFY COLUMN `status` ENUM('Active','Locked','Disabled') NOT NULL DEFAULT 'Active'"
    );

    // ── 6. Capitalize existing status values ────────────────────────
    step($pdo, $steps, $errors, "Capitalize status=active → Active",
        "UPDATE users SET status='Active' WHERE LOWER(status)='active'");
    step($pdo, $steps, $errors, "Capitalize status=locked → Locked",
        "UPDATE users SET status='Locked' WHERE LOWER(status)='locked'");
    step($pdo, $steps, $errors, "Capitalize status=disabled → Disabled",
        "UPDATE users SET status='Disabled' WHERE LOWER(status)='disabled'");

    // ── 7. Ensure phone_number & email unique indexes ────────────────
    step($pdo, $steps, $errors,
        "Ensure unique index on phone_number",
        "ALTER TABLE users ADD UNIQUE INDEX idx_phone_number (phone_number)"
    );

    // ── 8. Re-add FK constraints pointing to user_id ────────────────
    $fk_tables = [
        ['activity_logs',         'user_id', 'fk_log_user'],
        ['password_reset_tokens', 'user_id', 'fk_prt_user'],
        ['login_attempts',        'user_id', 'fk_la_user'],
        ['audit_logs',            'user_id', 'fk_audit_user'],
        ['labor_sessions',        'user_id', 'fk_ls_user'],
    ];

    foreach ($fk_tables as [$tbl, $col, $fk_name]) {
        try {
            // Only add FK if table and column exist
            $tbl_exists = $pdo->query("SHOW TABLES LIKE '{$tbl}'")->fetchColumn();
            if (!$tbl_exists) continue;
            $col_check = $pdo->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'")->fetch();
            if (!$col_check) continue;

            step($pdo, $steps, $errors,
                "Re-add FK on {$tbl}.{$col} → users.user_id",
                "ALTER TABLE `{$tbl}` ADD CONSTRAINT `{$fk_name}`
                 FOREIGN KEY (`{$col}`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE"
            );
        } catch (PDOException $e) {
            $steps[] = "⏭️  FK on {$tbl} skipped: " . $e->getMessage();
        }
    }

    // ── 9. Verify final column list ──────────────────────────────────
    try {
        $final = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $final_cols = array_column($final, 'Field');
        $steps[] = "ℹ️  Final columns: " . implode(', ', $final_cols);

        $required = ['user_id', 'phone_number', 'password_hash', 'status'];
        $missing  = array_diff($required, $final_cols);
        if (empty($missing)) {
            $steps[] = "✅ All required columns present!";
        } else {
            $steps[] = "⚠️  Still missing: " . implode(', ', $missing);
            $errors[] = "Missing columns after migration: " . implode(', ', $missing);
        }
    } catch (PDOException $e) {}
}

$overall = empty($errors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DB Migration | Petron System</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#0d1117;min-height:100vh;padding:30px 16px;color:#c9d1d9}
.page{max-width:700px;margin:auto}
h1{font-size:20px;font-weight:800;margin-bottom:4px;color:#58a6ff}
.sub{font-size:13px;color:#8b949e;margin-bottom:24px}
.card{background:#161b22;border:1px solid #30363d;border-radius:12px;margin-bottom:16px;overflow:hidden}
.card-head{padding:12px 18px;background:#21262d;font-size:13px;font-weight:700;color:#58a6ff;border-bottom:1px solid #30363d}
.card-body{padding:16px 18px}
.step{font-size:13px;padding:5px 0;border-bottom:1px solid #21262d;font-family:monospace}
.step:last-child{border-bottom:none}
.banner{padding:14px 18px;border-radius:10px;font-size:14px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.ok{background:#0d4429;border:1px solid #238636;color:#3fb950}
.fail{background:#490202;border:1px solid #f85149;color:#f85149}
.err-item{font-size:12px;font-family:monospace;padding:6px 10px;background:#1c1c1c;border-left:3px solid #f85149;margin-bottom:6px;border-radius:4px;color:#ff7b72}
.action-row{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}
.btn{padding:10px 20px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;cursor:pointer;border:none;font-family:inherit}
.btn-blue{background:#1f6feb;color:#fff}
.btn-blue:hover{background:#388bfd}
.btn-red{background:#b62324;color:#fff}
.btn-red:hover{background:#da3633}
</style>
</head>
<body>
<div class="page">
<h1>🗄️ Database Migration — Users Table</h1>
<p class="sub">Renames: id→user_id, phone→phone_number, password→password_hash | Updates status to Title Case</p>

<div class="banner <?php echo $overall ? 'ok' : 'fail'; ?>">
    <?php echo $overall ? '✅ Migration completed successfully!' : '❌ Migration completed with errors — check below'; ?>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <div class="card-head">❌ Errors</div>
    <div class="card-body">
        <?php foreach ($errors as $e): ?>
            <div class="err-item"><?php echo htmlspecialchars($e); ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">📋 Migration Steps</div>
    <div class="card-body">
        <?php foreach ($steps as $s): ?>
            <div class="step"><?php echo htmlspecialchars($s); ?></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="action-row">
    <a href="../public/forgot_password.php" class="btn btn-blue">→ Test Forgot Password</a>
    <a href="../public/login.php" class="btn btn-blue">→ Test Login</a>
    <?php if ($overall): ?>
    <a href="" onclick="fetch('').then(()=>alert('Already done'))" class="btn btn-red">🗑️ Delete this file when done!</a>
    <?php endif; ?>
</div>

<p style="margin-top:16px;font-size:12px;color:#8b949e">
    ⚠️ <strong>Security:</strong> Delete <code>database/migrate_users_columns.php</code> after verifying everything works.
</p>
</div>
</body>
</html>
