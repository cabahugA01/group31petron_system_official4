<?php
/**
 * Database Management System
 * Provides database maintenance, backup, restore, and table management
 */

class DatabaseManagement {
    private static $pdo = null;
    private static $backupPath = null;

    // ── Init ─────────────────────────────────────────────────────
    private static function init(): void {
        global $pdo;
        self::$pdo = $pdo;
        self::$backupPath = __DIR__ . '/../backups/database/';
        if (!file_exists(self::$backupPath)) {
            mkdir(self::$backupPath, 0755, true);
        }
    }

    // ── Whitelist: only these tables are manageable ───────────────
    // Populated dynamically from the actual DB — no hardcoded list.
    private static function getAllowedTables(): array {
        try {
            $stmt = self::$pdo->query("SHOW TABLES");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            return [];
        }
    }

    private static function assertAllowed(string $table): void {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name.");
        }
        $allowed = self::getAllowedTables();
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException("Table '{$table}' not found in database.");
        }
    }

    // ── Get all tables (DB-driven, no hardcoded list) ─────────────
    public static function getAllTables(): array {
        self::init();
        return self::getAllowedTables();
    }

    // ── Get table structure ───────────────────────────────────────
    public static function getTableStructure(string $tableName): ?array {
        self::init();
        self::assertAllowed($tableName);
        try {
            // DESCRIBE cannot use prepared statement placeholders for identifiers
            $cols = self::$pdo->query("DESCRIBE `{$tableName}`")->fetchAll(PDO::FETCH_ASSOC);

            $info = self::$pdo->prepare("SHOW TABLE STATUS LIKE ?");
            $info->execute([$tableName]);
            $ti = $info->fetch(PDO::FETCH_ASSOC);

            return [
                'name'    => $tableName,
                'columns' => $cols,
                'engine'  => $ti['Engine']  ?? '—',
                'rows'    => $ti['Rows']    ?? 0,
                'size'    => self::formatBytes(($ti['Data_length'] ?? 0) + ($ti['Index_length'] ?? 0)),
                'created' => $ti['Create_time'] ?? '—',
            ];
        } catch (Exception $e) {
            error_log("getTableStructure failed: " . $e->getMessage());
            return null;
        }
    }

    // ── Get table data with pagination ────────────────────────────
    public static function getTableData(string $tableName, int $page = 1, int $limit = 50, string $search = ''): ?array {
        self::init();
        self::assertAllowed($tableName);
        try {
            $offset = ($page - 1) * $limit;
            $params = [];

            // Build WHERE clause for search
            $where = '';
            if ($search !== '') {
                // Get column names safely
                $cols = self::$pdo->query("DESCRIBE `{$tableName}`")->fetchAll(PDO::FETCH_COLUMN);
                $conditions = [];
                foreach ($cols as $col) {
                    $conditions[] = "`{$col}` LIKE ?";
                    $params[] = "%{$search}%";
                }
                $where = ' WHERE (' . implode(' OR ', $conditions) . ')';
            }

            $total = (int)self::$pdo->prepare("SELECT COUNT(*) FROM `{$tableName}`{$where}")
                ->execute($params) ? self::$pdo->prepare("SELECT COUNT(*) FROM `{$tableName}`{$where}")->execute($params) : 0;

            // Re-execute cleanly
            $cntStmt = self::$pdo->prepare("SELECT COUNT(*) FROM `{$tableName}`{$where}");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();

            $dataStmt = self::$pdo->prepare("SELECT * FROM `{$tableName}`{$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
            $dataStmt->execute($params);
            $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'data'  => $data,
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
                'pages' => max(1, (int)ceil($total / $limit)),
            ];
        } catch (Exception $e) {
            error_log("getTableData failed: " . $e->getMessage());
            return null;
        }
    }

    // ── Create backup ─────────────────────────────────────────────
    public static function createBackup(string $backupType = 'full', array $tables = []): array {
        self::init();
        try {
            $timestamp = date('Y-m-d_H-i-s');
            $filename  = "backup_{$backupType}_{$timestamp}.sql";
            $filepath  = self::$backupPath . $filename;

            $allTables = self::getAllowedTables();
            if (empty($tables)) {
                $tables = $allTables;
            } else {
                $tables = array_values(array_intersect($tables, $allTables));
            }

            $sql  = "-- Database Backup\n";
            $sql .= "-- Type: {$backupType}\n";
            $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Generated by Petron Station Management System\n\n";

            foreach ($tables as $table) {
                $sql .= self::getTableCreateSQL($table);
                $sql .= self::getTableInsertSQL($table);
                $sql .= "\n\n";
            }

            if (file_put_contents($filepath, $sql) === false) {
                throw new \RuntimeException("Failed to write backup file to {$filepath}");
            }

            self::logDatabaseOperation('backup_created', $filename, 0);

            return [
                'success'  => true,
                'filename' => $filename,
                'filepath' => $filepath,
                'size'     => self::formatBytes(filesize($filepath)),
                'tables'   => $tables,
            ];
        } catch (Exception $e) {
            error_log("createBackup failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Restore from backup ───────────────────────────────────────
    public static function restoreFromBackup(string $filename): array {
        self::init();
        try {
            // Sanitize filename — no path traversal
            $filename = basename($filename);
            $filepath = self::$backupPath . $filename;

            if (!file_exists($filepath)) {
                throw new \RuntimeException("Backup file not found: {$filename}");
            }

            $sql = file_get_contents($filepath);
            if ($sql === false) {
                throw new \RuntimeException("Failed to read backup file.");
            }

            $statements = array_filter(array_map('trim', explode(";\n", $sql)));

            self::$pdo->beginTransaction();
            $executed = 0;
            foreach ($statements as $stmt) {
                if ($stmt === '' || preg_match('/^--/', $stmt)) continue;
                try {
                    self::$pdo->exec($stmt);
                    $executed++;
                } catch (\PDOException $e) {
                    if (stripos($e->getMessage(), 'already exists') === false) throw $e;
                }
            }
            self::$pdo->commit();

            self::logDatabaseOperation('restore_completed', $filename, 0);

            return ['success' => true, 'statements_executed' => $executed, 'filename' => $filename];
        } catch (Exception $e) {
            if (self::$pdo->inTransaction()) self::$pdo->rollBack();
            error_log("restoreFromBackup failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Get available backups ─────────────────────────────────────
    public static function getBackups(): array {
        self::init();
        try {
            $files   = glob(self::$backupPath . "*.sql") ?: [];
            $backups = [];
            foreach ($files as $file) {
                $fn = basename($file);
                $backups[] = [
                    'filename' => $fn,
                    'filepath' => $file,
                    'size'     => self::formatBytes(filesize($file)),
                    'created'  => date('Y-m-d H:i:s', filemtime($file)),
                    'type'     => (strpos($fn, 'backup_full_') !== false) ? 'Full' : 'Partial',
                ];
            }
            usort($backups, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));
            return $backups;
        } catch (Exception $e) {
            error_log("getBackups failed: " . $e->getMessage());
            return [];
        }
    }

    // ── Delete backup ─────────────────────────────────────────────
    public static function deleteBackup(string $filename): array {
        self::init();
        try {
            $filename = basename($filename);
            $filepath = self::$backupPath . $filename;
            if (!file_exists($filepath)) throw new \RuntimeException("Backup file not found.");
            if (!unlink($filepath)) throw new \RuntimeException("Failed to delete backup file.");
            self::logDatabaseOperation('backup_deleted', $filename, 0);
            return ['success' => true];
        } catch (Exception $e) {
            error_log("deleteBackup failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Optimize database ─────────────────────────────────────────
    public static function optimizeDatabase(): array {
        self::init();
        try {
            $tables  = self::getAllowedTables();
            $results = [];
            foreach ($tables as $table) {
                $results[$table] = [
                    'analyze'  => self::$pdo->query("ANALYZE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC),
                    'optimize' => self::$pdo->query("OPTIMIZE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC),
                    'check'    => self::$pdo->query("CHECK TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC),
                ];
            }
            self::logDatabaseOperation('database_optimized', count($tables) . ' tables', 0);
            return ['success' => true, 'results' => $results, 'tables_processed' => count($tables)];
        } catch (Exception $e) {
            error_log("optimizeDatabase failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Re-index database ─────────────────────────────────────────
    public static function runIndexing(): array {
        self::init();
        try {
            $tables  = self::getAllowedTables();
            $results = [];
            foreach ($tables as $table) {
                $results[$table] = [
                    'repair'  => self::$pdo->query("REPAIR TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC),
                    'analyze' => self::$pdo->query("ANALYZE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC),
                ];
            }
            self::logDatabaseOperation('database_indexing', count($tables) . ' tables', 0);
            return ['success' => true, 'results' => $results, 'indexes_processed' => count($tables)];
        } catch (Exception $e) {
            error_log("runIndexing failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Soft delete ───────────────────────────────────────────────
    public static function softDelete(string $tableName, int $recordId, int $userId, string $userRole): array {
        self::init();
        self::assertAllowed($tableName);
        try {
            // Ensure soft-delete columns exist
            $cols = self::$pdo->query("DESCRIBE `{$tableName}`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('is_deleted', $cols, true)) {
                self::$pdo->exec("ALTER TABLE `{$tableName}`
                    ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL,
                    ADD COLUMN `deleted_by` INT NULL DEFAULT NULL");
            }
            $stmt = self::$pdo->prepare("UPDATE `{$tableName}` SET is_deleted=1, deleted_at=NOW(), deleted_by=? WHERE id=?");
            $stmt->execute([$userId, $recordId]);
            if ($stmt->rowCount() === 0) throw new \RuntimeException("Record #{$recordId} not found in {$tableName}.");
            self::logDatabaseOperation('soft_delete', "{$tableName}:#{$recordId}", $userId);
            return ['success' => true];
        } catch (Exception $e) {
            error_log("softDelete failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Restore soft-deleted record ───────────────────────────────
    public static function restoreSoftDeleted(string $tableName, int $recordId, int $userId): array {
        self::init();
        self::assertAllowed($tableName);
        try {
            $stmt = self::$pdo->prepare("UPDATE `{$tableName}` SET is_deleted=0, deleted_at=NULL, deleted_by=NULL WHERE id=?");
            $stmt->execute([$recordId]);
            if ($stmt->rowCount() === 0) throw new \RuntimeException("Record #{$recordId} not found in {$tableName}.");
            self::logDatabaseOperation('soft_restore', "{$tableName}:#{$recordId}", $userId);
            return ['success' => true];
        } catch (Exception $e) {
            error_log("restoreSoftDeleted failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Get soft-deleted records ──────────────────────────────────
    public static function getSoftDeletedRecords(string $tableName, int $page = 1, int $limit = 50, string $search = ''): ?array {
        self::init();
        self::assertAllowed($tableName);
        try {
            $offset = ($page - 1) * $limit;

            // Check if table has is_deleted column
            $cols = self::$pdo->query("DESCRIBE `{$tableName}`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('is_deleted', $cols, true)) {
                return ['data' => [], 'total' => 0, 'page' => 1, 'pages' => 0];
            }

            $params = [];
            $extra  = '';
            if ($search !== '') {
                $conds = [];
                foreach ($cols as $col) {
                    if ($col === 'is_deleted') continue;
                    $conds[] = "`{$col}` LIKE ?";
                    $params[] = "%{$search}%";
                }
                if ($conds) $extra = ' AND (' . implode(' OR ', $conds) . ')';
            }

            $cntStmt = self::$pdo->prepare("SELECT COUNT(*) FROM `{$tableName}` WHERE is_deleted=1{$extra}");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();

            $dataStmt = self::$pdo->prepare("SELECT * FROM `{$tableName}` WHERE is_deleted=1{$extra} ORDER BY deleted_at DESC LIMIT {$limit} OFFSET {$offset}");
            $dataStmt->execute($params);
            $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            return ['data' => $data, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $limit))];
        } catch (Exception $e) {
            error_log("getSoftDeletedRecords failed: " . $e->getMessage());
            return null;
        }
    }

    // ── Private helpers ───────────────────────────────────────────
    private static function getTableCreateSQL(string $table): string {
        try {
            $stmt = self::$pdo->query("SHOW CREATE TABLE `{$table}`");
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            $ddl  = $row['Create Table'] ?? '';
            return "-- Table: `{$table}`\nDROP TABLE IF EXISTS `{$table}`;\n{$ddl};\n\n";
        } catch (Exception $e) {
            return "-- Could not get CREATE for `{$table}`: " . $e->getMessage() . "\n";
        }
    }

    private static function getTableInsertSQL(string $table): string {
        try {
            $stmt = self::$pdo->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) return "-- No data in `{$table}`\n";

            $cols   = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $values = [];
            foreach ($rows as $row) {
                $escaped = array_map(fn($v) => $v === null ? 'NULL' : self::$pdo->quote((string)$v), $row);
                $values[] = '(' . implode(', ', $escaped) . ')';
            }
            return "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $values) . ";\n";
        } catch (Exception $e) {
            return "-- Could not get data for `{$table}`: " . $e->getMessage() . "\n";
        }
    }

    private static function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow   = $bytes > 0 ? (int)floor(log($bytes) / log(1024)) : 0;
        $pow   = min($pow, count($units) - 1);
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    // ── Audit logging — uses activity_logs (same as rest of app) ──
    private static function logDatabaseOperation(string $operation, string $details, int $userId): void {
        global $pdo;
        try {
            // Try activity_logs first (standard app table)
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())"
            )->execute([$userId ?: null, "DB {$operation}", $details]);
        } catch (Exception $e) {
            // Silently ignore — logging should never break the main operation
            error_log("logDatabaseOperation failed: " . $e->getMessage());
        }
    }
}

