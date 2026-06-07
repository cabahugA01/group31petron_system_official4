<?php
/**
 * ============================================================
 *  fix_users_schema_final.php
 *  ONE-TIME migration: align `users` table to the required schema
 *
 *  Required fields after migration:
 *    user_id, first_name, last_name, station_id, email,
 *    username, phone_number, password_hash, role, status,
 *    created_at, updated_at
 *
 *  Safe to run multiple times – detects state before acting.
 * ============================================================
 */

// ── bootstrap ────────────────────────────────────────────────
require_once __DIR__ . '/../public/db_connect.php';

header('Content-Type: text/html; charset=utf-8');

function ok($msg)  { echo "<div class='ok'>✅ $msg</div>"; ob_flush(); flush(); }
function err($msg) { echo "<div class='err'>❌ $msg</div>"; ob_flush(); flush(); }
function info($msg){ echo "<div class='info'>ℹ️  $msg</div>"; ob_flush(); flush(); }
function head($msg){ echo "<h2>$msg</h2>"; ob_flush(); flush(); }

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Users Schema Fix – Final</title>
<style>
  body { font-family: 'Courier New', monospace; background:#0d1117; color:#c9d1d9;
         max-width:860px; margin:40px auto; padding:20px; }
  h1   { color:#58a6ff; border-bottom:2px solid #30363d; padding-bottom:8px; }
  h2   { color:#e3b341; margin-top:24px; }
  .ok  { color:#3fb950; margin:4px 0; }
  .err { color:#f85149; margin:4px 0; }
  .info{ color:#58a6ff; margin:4px 0; }
  .warn{ color:#e3b341; margin:4px 0; }
  table{ border-collapse:collapse; width:100%; margin-top:16px; font-size:13px; }
  th   { background:#161b22; color:#58a6ff; padding:8px 10px; border:1px solid #30363d; }
  td   { padding:7px 10px; border:1px solid #30363d; }
  tr:nth-child(even) td { background:#161b22; }
  .badge{ padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
  .r-sa { background:#1f6feb; color:#fff; }
  .r-ad { background:#388bfd30; color:#79c0ff; border:1px solid #388bfd; }
  .r-mg { background:#3fb95030; color:#3fb950; border:1px solid #3fb950; }
  .r-st { background:#e3b34130; color:#e3b341; border:1px solid #e3b341; }
  .s-ac { background:#3fb95030; color:#3fb950; border:1px solid #3fb950; }
  .s-lk { background:#f8514930; color:#f85149; border:1px solid #f85149; }
  .s-ds { background:#30363d; color:#8b949e; border:1px solid #484f58; }
  pre  { background:#161b22; padding:16px; border:1px solid #30363d;
         border-radius:6px; overflow-x:auto; color:#e6edf3; font-size:12px; }
</style>
</head>
<body>
<h1>🔧 Users Table Schema Fix – Final</h1>
<?php

// ── 1. Introspect current columns ────────────────────────────
head('Step 1 – Detecting current schema');

$colStmt = $pdo->query("SHOW COLUMNS FROM `users`");
$cols = [];
foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cols[$c['Field']] = $c;
}

$hasOldId        = isset($cols['id']);
$hasUserId       = isset($cols['user_id']);
$hasPassword     = isset($cols['password']);
$hasPasswordHash = isset($cols['password_hash']);
$hasPhone        = isset($cols['phone']);
$hasPhoneNumber  = isset($cols['phone_number']);
$hasEmpId        = isset($cols['emp_id']);
$hasFirstName    = isset($cols['first_name']);
$hasLastName     = isset($cols['last_name']);
$hasRole         = isset($cols['role']);
$hasStatus       = isset($cols['status']);
$hasCreatedAt    = isset($cols['created_at']);
$hasUpdatedAt    = isset($cols['updated_at']);

info("Columns found: " . implode(', ', array_keys($cols)));

// Decide migration path
$needsFullMigration    = $hasOldId && !$hasUserId;   // old schema – full rebuild
$needsPartialMigration = $hasUserId;                 // new schema – just patch gaps

if (!$needsFullMigration && !$needsPartialMigration) {
    err("Cannot determine table state. Manual intervention required.");
    echo "</body></html>";
    exit;
}

// ── 2. FULL MIGRATION (old schema → new schema) ──────────────
if ($needsFullMigration) {
    head('Step 2 – Full migration (old → new schema)');

    // Guard: backup must not already exist
    $bkCheck = $pdo->query("SHOW TABLES LIKE 'users_backup_old'")->rowCount();
    if ($bkCheck > 0) {
        err("Backup table <code>users_backup_old</code> already exists – migration was partially run before.");
        info("To proceed: DROP TABLE users_backup_old; then reload this page.");
        echo "</body></html>"; exit;
    }

    try {
        $pdo->beginTransaction();

        // 2a. Load existing rows
        $oldRows = $pdo->query("SELECT * FROM `users`")->fetchAll(PDO::FETCH_ASSOC);
        ok("Loaded " . count($oldRows) . " existing users");

        // 2b. Backup
        $pdo->exec("ALTER TABLE `users` RENAME TO `users_backup_old`");
        ok("Old table backed up as <code>users_backup_old</code>");

        // 2c. Create new table
        $pdo->exec("
        CREATE TABLE `users` (
          `user_id`       INT(11)      NOT NULL AUTO_INCREMENT,
          `first_name`    VARCHAR(100) NOT NULL,
          `last_name`     VARCHAR(100) NOT NULL,
          `station_id`    INT(11)      DEFAULT NULL,
          `email`         VARCHAR(255) DEFAULT NULL,
          `username`      VARCHAR(100) DEFAULT NULL,
          `phone_number`  VARCHAR(20)  DEFAULT NULL,
          `password_hash` VARCHAR(255) NOT NULL,
          `role`          ENUM('SuperAdmin','Admin','Manager','Staff') NOT NULL DEFAULT 'Staff',
          `status`        ENUM('Active','Locked','Disabled')          NOT NULL DEFAULT 'Active',
          `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`user_id`),
          UNIQUE KEY `uq_email`        (`email`),
          UNIQUE KEY `uq_username`     (`username`),
          UNIQUE KEY `uq_phone_number` (`phone_number`),
          INDEX `idx_station` (`station_id`),
          INDEX `idx_role`    (`role`),
          INDEX `idx_status`  (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        ok("New table created with correct schema");

        // 2d. Migrate rows
        $insert = $pdo->prepare("
            INSERT INTO `users`
              (user_id, first_name, last_name, station_id,
               email, username, phone_number, password_hash,
               role, status, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $roleMap = [
            'superadmin' => 'SuperAdmin',
            'admin'      => 'Admin',
            'manager'    => 'Manager',
            'staff'      => 'Staff',
        ];
        $statusMap = [
            'active'   => 'Active',
            'inactive' => 'Disabled',
            'disabled' => 'Disabled',
            'locked'   => 'Locked',
        ];

        foreach ($oldRows as $u) {
            $roleRaw   = strtolower(trim($u['role']   ?? 'staff'));
            $statusRaw = strtolower(trim($u['status'] ?? 'active'));

            $role   = $roleMap[$roleRaw]   ?? 'Staff';
            $status = $statusMap[$statusRaw] ?? 'Active';

            // phone: try 'phone_number' then 'phone'
            $phone = $u['phone_number'] ?? $u['phone'] ?? null;

            // password: prefer password_hash, fall back to password
            $pwHash = $u['password_hash'] ?? $u['password'] ?? '';

            $insert->execute([
                $u['id'],
                $u['first_name']  ?? 'Unknown',
                $u['last_name']   ?? 'User',
                $u['station_id']  ?? null,
                $u['email']       ?? null,
                $u['username']    ?? null,
                $phone,
                $pwHash,
                $role,
                $status,
                $u['created_at']  ?? date('Y-m-d H:i:s'),
                $u['updated_at']  ?? date('Y-m-d H:i:s'),
            ]);

            ok("Migrated user ID {$u['id']} – {$u['first_name']} {$u['last_name']} ($role / $status)");
        }

        $pdo->commit();
        ok("Transaction committed – full migration done");

    } catch (Throwable $e) {
        $pdo->rollBack();
        err("Migration failed: " . $e->getMessage());
        // Try to restore
        try {
            $pdo->exec("DROP TABLE IF EXISTS `users`");
            $pdo->exec("ALTER TABLE `users_backup_old` RENAME TO `users`");
            ok("Rollback successful – original table restored");
        } catch (Throwable $re) {
            err("Rollback also failed: " . $re->getMessage());
        }
        echo "</body></html>"; exit;
    }
}

// ── 3. PARTIAL MIGRATION (new schema – patch any missing gaps) ─
if ($needsPartialMigration) {
    head('Step 2 – Partial patch (new schema, filling any gaps)');

    // Re-read columns after possible full migration above
    $colStmt2 = $pdo->query("SHOW COLUMNS FROM `users`");
    $cols2 = [];
    foreach ($colStmt2->fetchAll(PDO::FETCH_ASSOC) as $c) { $cols2[$c['Field']] = $c; }

    $patches = [];

    // Ensure phone_number exists
    if (!isset($cols2['phone_number'])) {
        $patches[] = "ADD COLUMN `phone_number` VARCHAR(20) DEFAULT NULL AFTER `username`";
    }

    // Ensure password_hash exists
    if (!isset($cols2['password_hash'])) {
        $patches[] = "ADD COLUMN `password_hash` VARCHAR(255) NOT NULL DEFAULT '' AFTER `phone_number`";
    }

    // Ensure role is correct ENUM
    if (isset($cols2['role'])) {
        $currentType = $cols2['role']['Type'];
        if (strpos($currentType, 'SuperAdmin') === false) {
            $patches[] = "MODIFY COLUMN `role` ENUM('SuperAdmin','Admin','Manager','Staff') NOT NULL DEFAULT 'Staff'";
        }
    }

    // Ensure status is correct ENUM
    if (isset($cols2['status'])) {
        $currentType = $cols2['status']['Type'];
        if (strpos($currentType, 'Active') === false || strpos($currentType, 'Locked') === false) {
            $patches[] = "MODIFY COLUMN `status` ENUM('Active','Locked','Disabled') NOT NULL DEFAULT 'Active'";
        }
    }

    // Ensure unique keys exist
    $idxStmt = $pdo->query("SHOW INDEX FROM `users`");
    $idxNames = [];
    foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $idxNames[$i['Key_name']] = true;
    }

    if (!isset($idxNames['uq_email']) && isset($cols2['email'])) {
        $patches[] = "ADD UNIQUE KEY `uq_email` (`email`)";
    }
    if (!isset($idxNames['uq_username']) && isset($cols2['username'])) {
        $patches[] = "ADD UNIQUE KEY `uq_username` (`username`)";
    }
    if (!isset($idxNames['uq_phone_number']) && isset($cols2['phone_number'])) {
        // will be added after column exists
        $patches[] = "ADD UNIQUE KEY `uq_phone_number` (`phone_number`)";
    }

    if (empty($patches)) {
        ok("Schema is already up-to-date — no patches needed");
    } else {
        try {
            $sql = "ALTER TABLE `users` " . implode(",\n  ", $patches);
            info("Running: <pre>$sql</pre>");
            $pdo->exec($sql);
            ok("Patches applied successfully");
        } catch (Throwable $e) {
            err("Patch failed: " . $e->getMessage());
        }
    }

    // Migrate data in phone column → phone_number if old 'phone' column still exists
    $colStmt3 = $pdo->query("SHOW COLUMNS FROM `users`");
    $cols3 = [];
    foreach ($colStmt3->fetchAll(PDO::FETCH_ASSOC) as $c) { $cols3[$c['Field']] = $c; }

    if (isset($cols3['phone']) && isset($cols3['phone_number'])) {
        $pdo->exec("UPDATE `users` SET `phone_number` = `phone` WHERE `phone_number` IS NULL AND `phone` IS NOT NULL");
        ok("Copied data from <code>phone</code> → <code>phone_number</code>");
    }

    if (isset($cols3['password']) && isset($cols3['password_hash'])) {
        $pdo->exec("UPDATE `users` SET `password_hash` = `password` WHERE (`password_hash` = '' OR `password_hash` IS NULL) AND `password` != ''");
        ok("Copied data from <code>password</code> → <code>password_hash</code>");
    }

    // Normalise ENUM values in role/status
    $pdo->exec("UPDATE `users` SET `role` = 'SuperAdmin' WHERE LOWER(`role`) = 'superadmin'");
    $pdo->exec("UPDATE `users` SET `role` = 'Admin'      WHERE LOWER(`role`) = 'admin'");
    $pdo->exec("UPDATE `users` SET `role` = 'Manager'    WHERE LOWER(`role`) = 'manager'");
    $pdo->exec("UPDATE `users` SET `role` = 'Staff'      WHERE LOWER(`role`) = 'staff'");
    $pdo->exec("UPDATE `users` SET `status` = 'Active'   WHERE LOWER(`status`) = 'active'");
    $pdo->exec("UPDATE `users` SET `status` = 'Locked'   WHERE LOWER(`status`) = 'locked'");
    $pdo->exec("UPDATE `users` SET `status` = 'Disabled' WHERE LOWER(`status`) IN ('inactive','disabled')");
    ok("ENUM values normalised");
}

// ── 4. Verify final schema ────────────────────────────────────
head('Step 3 – Final schema verification');

$required = ['user_id','first_name','last_name','station_id','email',
             'username','phone_number','password_hash','role','status',
             'created_at','updated_at'];

$finalCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $finalCols[$c['Field']] = $c;
}

$allGood = true;
foreach ($required as $f) {
    if (isset($finalCols[$f])) {
        ok("Column <code>$f</code> ✓  ({$finalCols[$f]['Type']})");
    } else {
        err("Column <code>$f</code> is MISSING");
        $allGood = false;
    }
}

// ── 5. Show all users ─────────────────────────────────────────
head('Step 4 – Current users in table');

$roleClass   = ['SuperAdmin'=>'r-sa','Admin'=>'r-ad','Manager'=>'r-mg','Staff'=>'r-st'];
$statusClass = ['Active'=>'s-ac','Locked'=>'s-lk','Disabled'=>'s-ds'];

// Determine correct PK column name for SELECT
$pkCol = isset($finalCols['user_id']) ? 'user_id' : 'id';

$users = $pdo->query("SELECT * FROM `users` ORDER BY `$pkCol`")->fetchAll(PDO::FETCH_ASSOC);

echo "<table>
<thead><tr>
  <th>user_id</th><th>first_name</th><th>last_name</th>
  <th>station_id</th><th>email</th><th>username</th>
  <th>phone_number</th><th>role</th><th>status</th>
  <th>created_at</th>
</tr></thead><tbody>";

foreach ($users as $u) {
    $uid   = $u['user_id']      ?? $u['id']          ?? '—';
    $fn    = htmlspecialchars($u['first_name']    ?? '—');
    $ln    = htmlspecialchars($u['last_name']     ?? '—');
    $sid   = $u['station_id']   ?? '<em>NULL</em>';
    $email = htmlspecialchars($u['email']         ?? '—');
    $uname = htmlspecialchars($u['username']      ?? '—');
    $phone = htmlspecialchars($u['phone_number']  ?? '—');
    $role  = $u['role']   ?? '—';
    $stat  = $u['status'] ?? '—';
    $rc    = $roleClass[$role]   ?? '';
    $sc    = $statusClass[$stat] ?? '';
    $cat   = $u['created_at'] ?? '—';

    echo "<tr>
      <td>$uid</td><td>$fn</td><td>$ln</td>
      <td>$sid</td><td>$email</td><td>$uname</td>
      <td>$phone</td>
      <td><span class='badge $rc'>$role</span></td>
      <td><span class='badge $sc'>$stat</span></td>
      <td>$cat</td>
    </tr>";
}
echo "</tbody></table>";

// ── 6. Summary ────────────────────────────────────────────────
head('Summary');
if ($allGood) {
    echo "<div class='ok' style='font-size:18px;font-weight:700;margin-top:16px'>
        ✅ All required columns are present. Migration complete!
    </div>";
    echo "<div class='info' style='margin-top:12px'>
        You can delete this file after verifying everything is correct:<br>
        <code>database/fix_users_schema_final.php</code>
    </div>";
} else {
    echo "<div class='err' style='font-size:18px;font-weight:700'>
        ⚠️ Some columns are still missing – check errors above.
    </div>";
}
?>
</body>
</html>
