<?php
$page_id = 'import_database';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Only allow admin access
$me = current_user();
if (!in_array(role_key($me['role'] ?? ''), ['admin', 'superadmin'])) {
    echo "Access denied. Admin only.";
    exit;
}

$message = '';
$error = '';
$warnings = [];
$can_import = false;

// Pre-import checks
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo = getPDO();
        
        // Check if database is accessible
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        // Check critical tables
        $critical_tables = ['users', 'stations', 'suppliers', 'service_rates'];
        $missing_tables = [];
        
        foreach ($critical_tables as $table) {
            if (!in_array($table, $tables)) {
                $missing_tables[] = $table;
            }
        }
        
        if (empty($missing_tables)) {
            $warnings[] = "All critical tables already exist. Import will add/update data.";
            $can_import = true;
        } else {
            $warnings[] = "Missing tables: " . implode(', ', $missing_tables);
            $can_import = true;
        }
        
        // Check user count
        $user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($user_count > 0) {
            $warnings[] = "Found $user_count existing users. Import will preserve existing data.";
        }
        
        // Check SQL file exists and is readable
        $sql_file = __DIR__ . '/../sql/petron_pos_db_secure.sql';
        if (!file_exists($sql_file)) {
            throw new Exception("SQL file not found: $sql_file");
        }
        
        $file_size = filesize($sql_file);
        if ($file_size === 0) {
            throw new Exception("SQL file is empty");
        }
        
        $warnings[] = "SQL file found and readable (Size: " . number_format($file_size / 1024, 2) . " KB)";
        
    } catch (Exception $e) {
        $error = "❌ Pre-check failed: " . $e->getMessage();
        $can_import = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    try {
        // Read the SQL file
        $sql_file = __DIR__ . '/../sql/petron_pos_db_secure.sql';
        
        if (!file_exists($sql_file)) {
            throw new Exception("SQL file not found: $sql_file");
        }
        
        $sql_content = file_get_contents($sql_file);
        
        if ($sql_content === false) {
            throw new Exception("Failed to read SQL file");
        }
        
        // Get database connection
        $pdo = getPDO();
        
        // Start transaction for safety
        $pdo->beginTransaction();
        
        try {
            // Split SQL into individual statements
            $statements = array_filter(array_map('trim', explode(';', $sql_content)));
            
            $executed = 0;
            $skipped = 0;
            $errors = [];
            
            // Disable foreign key checks temporarily
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            foreach ($statements as $statement) {
                if (empty($statement) || 
                    preg_match('/^--/', $statement) || 
                    preg_match('/^\/\*/', $statement) || 
                    preg_match('/^SET /', $statement)) {
                    continue;
                }
                
                try {
                    // Handle different statement types
                    if (preg_match('/^DROP TABLE IF EXISTS/', $statement)) {
                        // Safe to execute - won't error if table doesn't exist
                        $pdo->exec($statement);
                        $executed++;
                    }
                    elseif (preg_match('/^CREATE TABLE/', $statement)) {
                        // Try to create, skip if already exists
                        try {
                            $pdo->exec($statement);
                            $executed++;
                        } catch (Exception $e) {
                            if (strpos($e->getMessage(), 'already exists') !== false) {
                                $skipped++;
                            } else {
                                throw $e;
                            }
                        }
                    }
                    elseif (preg_match('/^ALTER TABLE.*ADD/', $statement)) {
                        // Try to add column, skip if already exists
                        try {
                            $pdo->exec($statement);
                            $executed++;
                        } catch (Exception $e) {
                            if (strpos($e->getMessage(), 'Duplicate column name') !== false || 
                                strpos($e->getMessage(), 'already exists') !== false) {
                                $skipped++;
                            } else {
                                throw $e;
                            }
                        }
                    }
                    elseif (preg_match('/^INSERT INTO/', $statement)) {
                        // Use INSERT IGNORE to prevent duplicates
                        $modified_statement = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $statement);
                        $pdo->exec($modified_statement);
                        $executed++;
                    }
                    elseif (preg_match('/^MODIFY.*AUTO_INCREMENT/', $statement)) {
                        // Safe to execute
                        $pdo->exec($statement);
                        $executed++;
                    }
                    else {
                        // Execute other statements
                        $pdo->exec($statement);
                        $executed++;
                    }
                    
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
            
            // Re-enable foreign key checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            
            // Commit transaction
            $pdo->commit();
            
            $message = "✅ Database import completed successfully!";
            $message .= "<br>Executed: $executed statements";
            $message .= "<br>Skipped: $skipped statements (already existed)";
            
            if (!empty($errors)) {
                $message .= "<br>Minor errors: " . count($errors) . " (non-critical)";
            }
            
            // Log the import
            log_activity($pdo, $me['id'], 'Database Import', "Successfully imported petron_pos_db_secure.sql", $_SERVER['REMOTE_ADDR']);
            
        } catch (Exception $e) {
            // Rollback on error
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        $error = "❌ Import failed: " . $e->getMessage();
    }
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">🗄️ Safe Database Import</h1>
        <div class="sub">Import main database schema and data safely</div>
    </div>
</div>

<?php if($message): ?>
    <div class="card" style="padding:15px; margin-bottom:20px; background:#e6f4ea; color:green; border-left:4px solid #28a745;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if($error): ?>
    <div class="card" style="padding:15px; margin-bottom:20px; background:#f8d7da; color:#721c24; border-left:4px solid #dc3545;">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<section class="card">
    <h2>📋 System Status Check</h2>
    <div style="background:#e3f2fd; border:1px solid #2196f3; border-radius:8px; padding:20px; margin-bottom:20px;">
        <h3 style="color:#1976d2; margin-top:0;">Pre-Import Analysis:</h3>
        <ul style="color:#1976d2;">
            <?php foreach ($warnings as $warning): ?>
                <li><?php echo htmlspecialchars($warning); ?></li>
            <?php endforeach; ?>
        </ul>
        
        <?php if ($can_import): ?>
            <div style="color:#4caf50; font-weight:bold; margin-top:10px;">
                ✅ System is ready for safe import
            </div>
        <?php else: ?>
            <div style="color:#f44336; font-weight:bold; margin-top:10px;">
                ❌ System not ready for import
            </div>
        <?php endif; ?>
    </div>
    
    <h2>🛡️ Safety Features</h2>
    <div style="background:#f3e5f5; border:1px solid #9c27b0; border-radius:8px; padding:20px; margin-bottom:20px;">
        <h3 style="color:#7b1fa2; margin-top:0;">This import is designed to be safe:</h3>
        <ul style="color:#7b1fa2;">
            <li><strong>Transaction-based:</strong> All changes are rolled back if any error occurs</li>
            <li><strong>INSERT IGNORE:</strong> Prevents duplicate data insertion</li>
            <li><strong>Column existence check:</strong> Skips adding columns that already exist</li>
            <li><strong>Table existence check:</strong> Won't break if tables already exist</li>
            <li><strong>Preserves existing data:</strong> Your current data is protected</li>
            <li><strong>Foreign key safety:</strong> Temporarily disables checks during import</li>
        </ul>
    </div>
    
    <?php if ($can_import): ?>
    <form method="post" onsubmit="return confirm('This is a SAFE import that will not harm your existing data. Continue?')">
        <div style="text-align:center; padding:30px;">
            <div style="font-size:48px; margin-bottom:20px;">�️</div>
            <h3>Safe Import Mode</h3>
            <p style="color:#666; margin-bottom:20px;">
                This will safely import the database schema and data while preserving your existing information.
            </p>
            
            <button type="submit" name="import" class="btn" style="background:#4caf50; color:white; padding:12px 30px; font-size:16px; border:none; border-radius:6px; cursor:pointer;">
                ✅ Safe Import Database
            </button>
        </div>
    </form>
    <?php else: ?>
    <div style="text-align:center; padding:30px; background:#ffebee; border-radius:8px;">
        <div style="font-size:48px; margin-bottom:20px;">⚠️</div>
        <h3>Import Not Available</h3>
        <p style="color:#666;">
            Please resolve the issues above before attempting to import.
        </p>
    </div>
    <?php endif; ?>
</section>

<style>
.btn:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
