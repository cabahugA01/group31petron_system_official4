<?php
/**
 * Module Configuration Management
 * Provides database-driven configuration for system modules
 */

class ModuleConfig {
    private static $config = null;
    private static $pdo = null;
    
    /**
     * Initialize module configuration with database values
     */
    private static function init() {
        global $pdo;
        self::$pdo = $pdo;
        
        // Create config tables if not exist
        self::createConfigTables();
        
        // Load configuration
        self::loadConfig();
    }
    
    /**
     * Create module configuration tables
     */
    private static function createConfigTables() {
        // Modules table - tracks which modules are enabled/disabled
        $modulesSql = "CREATE TABLE IF NOT EXISTS module_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_key VARCHAR(100) UNIQUE NOT NULL,
            module_name VARCHAR(200) NOT NULL,
            module_description TEXT,
            is_enabled TINYINT(1) DEFAULT 1,
            module_order INT DEFAULT 0,
            version VARCHAR(20) DEFAULT 'v1.0',
            last_updated VARCHAR(50) DEFAULT 'Aug 05, 2026',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        // Module configuration table - stores module-specific settings
        $configSql = "CREATE TABLE IF NOT EXISTS module_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_key VARCHAR(100) NOT NULL,
            config_key VARCHAR(100) NOT NULL,
            config_value TEXT NOT NULL,
            config_type VARCHAR(20) DEFAULT 'string',
            config_category VARCHAR(50) DEFAULT 'general',
            description TEXT,
            is_encrypted TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_module_config (module_key, config_key),
            FOREIGN KEY (module_key) REFERENCES module_settings(module_key) ON DELETE CASCADE
        )";
        
        // Module audit trail table
        $auditSql = "CREATE TABLE IF NOT EXISTS module_config_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_key VARCHAR(100) NOT NULL,
            config_key VARCHAR(100),
            action_type ENUM('enable', 'disable', 'update', 'create') NOT NULL,
            old_value TEXT,
            new_value TEXT,
            changed_by INT NOT NULL,
            changed_by_role VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_module_key (module_key),
            INDEX idx_timestamp (timestamp)
        )";
        
        try {
            self::$pdo->exec($modulesSql);
            // Ensure columns exist if table was created previously without them
            try {
                self::$pdo->exec("ALTER TABLE module_settings ADD COLUMN IF NOT EXISTS version VARCHAR(20) DEFAULT 'v1.0'");
                self::$pdo->exec("ALTER TABLE module_settings ADD COLUMN IF NOT EXISTS last_updated VARCHAR(50) DEFAULT 'Aug 05, 2026'");
                self::$pdo->exec("ALTER TABLE module_settings ADD COLUMN IF NOT EXISTS user_access VARCHAR(255) DEFAULT 'Admin, Manager, Staff'");
                self::$pdo->exec("UPDATE module_settings SET user_access = 'Admin, Manager, Staff' WHERE user_access IS NULL OR user_access = '' OR user_access = 'Admin, Manager' OR user_access = 'Admin,Manager'");
            } catch (Exception $ex) {
                // Skip if error
            }
            self::$pdo->exec($configSql);
            self::$pdo->exec($auditSql);
            self::insertDefaultModules();
        } catch (Exception $e) {
            error_log("Module Config table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Insert default modules and configurations
     */
    private static function insertDefaultModules() {
        // Strict Developer Modules
        $defaultModules = [
            'dashboard' => [
                'name' => 'Dashboard',
                'description' => 'System dashboard KPI cards, real-time charts, and quick action configurations.',
                'enabled' => 1,
                'order' => 1,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'default_landing_page' => ['value' => 'dashboard', 'type' => 'string', 'category' => 'General', 'desc' => 'Default Landing Page'],
                    'dashboard_refresh_interval' => ['value' => '30', 'type' => 'integer', 'category' => 'General', 'desc' => 'Dashboard Refresh Interval (seconds)'],
                    'enable_kpi_cards' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable KPI Cards'],
                    'enable_charts' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Real-time Charts'],
                    'enable_quick_actions' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Quick Actions']
                ]
            ],
            'transactions' => [
                'name' => 'Transactions',
                'description' => 'Auto numbering formats, POS transaction defaults, and void approval controls.',
                'enabled' => 1,
                'order' => 2,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'auto_transaction_numbering' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Auto Transaction Numbering'],
                    'or_number_format' => ['value' => 'OR-{YYYY}{MM}{DD}-{6DIGITS}', 'type' => 'string', 'category' => 'General', 'desc' => 'Official Receipt (OR) Number Format'],
                    'job_order_number_format' => ['value' => 'JO-{YYYY}{MM}{DD}-{6DIGITS}', 'type' => 'string', 'category' => 'General', 'desc' => 'Job Order Number Format'],
                    'default_transaction_status' => ['value' => 'pending', 'type' => 'string', 'category' => 'General', 'desc' => 'Default Transaction Status'],
                    'enable_void_transaction' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Void Transaction Option']
                ]
            ],
            'fuel_management' => [
                'name' => 'Fuel Management',
                'description' => 'Fuel reconciliation tolerance rules, calibration computation, and meter validations.',
                'enabled' => 1,
                'order' => 3,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'enable_fuel_reconciliation' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Fuel Reconciliation'],
                    'enable_calibration' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Calibration Computations'],
                    'enable_meter_reading_validation' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Meter Reading Validation'],
                    'decimal_precision' => ['value' => '3', 'type' => 'integer', 'category' => 'General', 'desc' => 'Decimal Precision for Liters'],
                    'default_fuel_unit' => ['value' => 'Liters', 'type' => 'string', 'category' => 'General', 'desc' => 'Default Fuel Measurement Unit']
                ]
            ],
            'inventory' => [
                'name' => 'Inventory',
                'description' => 'FIFO accounting rules, low/critical stock threshold levels, and batch tracking.',
                'enabled' => 1,
                'order' => 4,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'enable_batch_tracking' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Batch Tracking'],
                    'enable_expiration_monitoring' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Expiration Monitoring'],
                    'enable_fifo' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable FIFO (First In, First Out)'],
                    'enable_low_stock_alert' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Low Stock Alert'],
                    'enable_critical_stock_alert' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Critical Stock Alert']
                ]
            ],
            'customers' => [
                'name' => 'Customers',
                'description' => 'Customer registration setup, vehicle service history logs, credit and fleet cards.',
                'enabled' => 1,
                'order' => 5,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'enable_customer_registration' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Customer Registration'],
                    'enable_vehicle_history' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Vehicle Service History'],
                    'enable_credit_account' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Customer Credit Account'],
                    'enable_fleet_card' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Fleet Card Integration']
                ]
            ],
            'product_pricing' => [
                'name' => 'Product & Pricing',
                'description' => 'SKU/Barcode code validation, price change approval workflows, and price histories.',
                'enabled' => 1,
                'order' => 6,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'enable_sku_validation' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable SKU Validation'],
                    'enable_barcode' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Barcode System'],
                    'enable_price_approval_workflow' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Price Approval Workflow'],
                    'enable_price_history' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Price History Auditing']
                ]
            ],
            'calendar' => [
                'name' => 'Calendar',
                'description' => 'Public holidays config, shift reminder notifications, and equipment schedules.',
                'enabled' => 1,
                'order' => 7,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'enable_holidays' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Holidays on Calendar'],
                    'enable_reminder_notifications' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Reminder Notifications'],
                    'enable_maintenance_schedule' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Maintenance Schedule']
                ]
            ],
            'reports' => [
                'name' => 'Reports',
                'description' => 'PDF, Excel, and CSV export modules, header/footer branding setups.',
                'enabled' => 1,
                'order' => 8,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'enable_pdf_export' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable PDF Export'],
                    'enable_excel_export' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Excel Export'],
                    'enable_csv_export' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable CSV Export'],
                    'default_paper_size' => ['value' => 'A4', 'type' => 'string', 'category' => 'General', 'desc' => 'Default Paper Size'],
                    'report_header' => ['value' => 'PETRON CORPORATION - STATION REPORT', 'type' => 'string', 'category' => 'General', 'desc' => 'Report Header Logo/Text'],
                    'report_footer' => ['value' => 'Thank you for choosing Petron. This is a system-generated report.', 'type' => 'string', 'category' => 'General', 'desc' => 'Report Footer Disclaimer']
                ]
            ],
            'notifications' => [
                'name' => 'Notifications',
                'description' => 'Alert banner durations, low stock alerts, backups, and approval popups.',
                'enabled' => 1,
                'order' => 9,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'success_banner_duration' => ['value' => '5', 'type' => 'integer', 'category' => 'General', 'desc' => 'Success Banner Duration (seconds)'],
                    'error_banner_duration' => ['value' => '10', 'type' => 'integer', 'category' => 'General', 'desc' => 'Error Banner Duration (seconds)'],
                    'enable_low_stock_alert' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Low Stock Alert'],
                    'enable_approval_alert' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Approval Alert'],
                    'enable_backup_alert' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Backup Alert']
                ]
            ],
            'backup_restore' => [
                'name' => 'Backup & Restore',
                'description' => 'Automated background DB backups, retention policies, and storage cleanup.',
                'enabled' => 1,
                'order' => 10,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'enable_scheduled_backup' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Scheduled Backup'],
                    'backup_frequency' => ['value' => 'daily', 'type' => 'string', 'category' => 'General', 'desc' => 'Backup Frequency (daily, weekly, monthly)'],
                    'storage_location' => ['value' => 'C:/xampp/backups/', 'type' => 'string', 'category' => 'General', 'desc' => 'Storage Location'],
                    'retention_period' => ['value' => '30', 'type' => 'integer', 'category' => 'General', 'desc' => 'Retention Period (days)'],
                    'auto_cleanup' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Auto Cleanup Old Backups']
                ]
            ],
            'audit_trail' => [
                'name' => 'Audit Trail',
                'description' => 'System error monitoring, log levels, automated database log archiving.',
                'enabled' => 1,
                'order' => 11,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'enable_audit_logs' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Audit Logs'],
                    'enable_error_logs' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Enable Error Logs'],
                    'log_retention' => ['value' => '365', 'type' => 'integer', 'category' => 'General', 'desc' => 'Log Retention Period (days)'],
                    'auto_archive_logs' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'Auto Archive Logs']
                ]
            ],
            'api_integration' => [
                'name' => 'API / Integration',
                'description' => 'Third-party REST integrations, webhooks, SMTP, and SMS Gateways.',
                'enabled' => 1,
                'order' => 12,
                'version' => 'v1.0',
                'last_updated' => 'Aug 05, 2026',
                'settings' => [
                    'api_status' => ['value' => '1', 'type' => 'boolean', 'category' => 'General', 'desc' => 'API Status (Active)'],
                    'api_keys' => ['value' => 'petron_live_key_9f81a7b0', 'type' => 'string', 'category' => 'General', 'desc' => 'API Keys'],
                    'webhook_settings' => ['value' => 'http://localhost/group31petron_system_official4/api/webhook.php', 'type' => 'string', 'category' => 'General', 'desc' => 'Webhook URL'],
                    'smtp_email_settings' => ['value' => 'smtp.gmail.com:587', 'type' => 'string', 'category' => 'General', 'desc' => 'SMTP Email Settings'],
                    'sms_gateway' => ['value' => 'Twilio Gateway SMS Integration (future-ready)', 'type' => 'string', 'category' => 'General', 'desc' => 'SMS Gateway (future-ready)']
                ]
            ]
        ];
        
        try {
            // Delete old non-developer modules and settings to clear the database cleanly
            $newKeys = array_keys($defaultModules);
            $placeholders = implode(',', array_fill(0, count($newKeys), '?'));
            
            $stmt = self::$pdo->prepare("DELETE FROM module_config WHERE module_key NOT IN ($placeholders)");
            $stmt->execute($newKeys);
            
            $stmt = self::$pdo->prepare("DELETE FROM module_settings WHERE module_key NOT IN ($placeholders)");
            $stmt->execute($newKeys);
            
            // Insert or Update the 12 Strict Developer Modules
            foreach ($defaultModules as $moduleKey => $moduleData) {
                $stmt = self::$pdo->prepare("
                    INSERT INTO module_settings (module_key, module_name, module_description, is_enabled, module_order, version, last_updated)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        module_name = VALUES(module_name),
                        module_description = VALUES(module_description),
                        module_order = VALUES(module_order),
                        version = VALUES(version),
                        last_updated = VALUES(last_updated)
                ");
                $stmt->execute([
                    $moduleKey,
                    $moduleData['name'],
                    $moduleData['description'],
                    $moduleData['enabled'],
                    $moduleData['order'],
                    $moduleData['version'],
                    $moduleData['last_updated']
                ]);
                
                // Insert or Update configurations for each module
                foreach ($moduleData['settings'] as $configKey => $configData) {
                    $stmt = self::$pdo->prepare("
                        INSERT INTO module_config (module_key, config_key, config_value, config_type, config_category, description)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            config_type = VALUES(config_type),
                            config_category = VALUES(config_category),
                            description = VALUES(description)
                    ");
                    $stmt->execute([
                        $moduleKey,
                        $configKey,
                        $configData['value'],
                        $configData['type'],
                        $configData['category'],
                        $configData['desc']
                    ]);
                }
            }
        } catch (Exception $e) {
            error_log("Failed to insert default modules: " . $e->getMessage());
        }
    }
    
    /**
     * Load configuration from database
     */
    private static function loadConfig() {
        try {
            // Load modules
            $stmt = self::$pdo->query("SELECT module_key, module_name, module_description, is_enabled, user_access, module_order, version, last_updated FROM module_settings ORDER BY module_order");
            self::$config['modules'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Load module configurations
            $stmt = self::$pdo->query("SELECT * FROM module_config ORDER BY module_key, config_category, config_key");
            self::$config['settings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to load module config: " . $e->getMessage());
            self::$config = ['modules' => [], 'settings' => []];
        }
    }
    
    /**
     * Get all modules
     */
    public static function getModules() {
        if (self::$config === null) {
            self::init();
        }
        return self::$config['modules'];
    }
    
    /**
     * Get enabled modules only
     */
    public static function getEnabledModules() {
        if (self::$config === null) {
            self::init();
        }
        return array_filter(self::$config['modules'], function($module) {
            return $module['is_enabled'] == 1;
        });
    }
    
    /**
     * Get module by key
     */
    public static function getModule($moduleKey) {
        if (self::$config === null) {
            self::init();
        }
        
        foreach (self::$config['modules'] as $module) {
            if ($module['module_key'] === $moduleKey) {
                return $module;
            }
        }
        return null;
    }
    
    /**
     * Check if module is enabled
     */
    public static function isModuleEnabled($moduleKey) {
        $module = self::getModule($moduleKey);
        return $module && $module['is_enabled'] == 1;
    }
    
    /**
     * Get module settings
     */
    public static function getModuleSettings($moduleKey) {
        if (self::$config === null) {
            self::init();
        }
        
        $settings = [];
        foreach (self::$config['settings'] as $setting) {
            if ($setting['module_key'] === $moduleKey) {
                $settings[] = $setting;
            }
        }
        return $settings;
    }
    
    /**
     * Get specific module setting
     */
    public static function getModuleSetting($moduleKey, $configKey, $default = null) {
        if (self::$config === null) {
            self::init();
        }
        
        foreach (self::$config['settings'] as $setting) {
            if ($setting['module_key'] === $moduleKey && $setting['config_key'] === $configKey) {
                return self::parseConfigValue($setting['config_value'], $setting['config_type']);
            }
        }
        return $default;
    }
    
    /**
     * Parse configuration value based on type
     */
    private static function parseConfigValue($value, $type) {
        switch ($type) {
            case 'boolean':
                return (bool)$value;
            case 'integer':
                return (int)$value;
            case 'decimal':
                return (float)$value;
            case 'array':
                return explode(',', $value);
            default:
                return $value;
        }
    }
    
    /**
     * Enable/disable module
     */
    public static function setModuleStatus($moduleKey, $enabled, $userId, $userRole) {
        if (self::$config === null) {
            self::init();
        }
        
        try {
            $stmt = self::$pdo->prepare("UPDATE module_settings SET is_enabled = ?, updated_at = CURRENT_TIMESTAMP WHERE module_key = ?");
            $stmt->execute([$enabled ? 1 : 0, $moduleKey]);
            
            // Log to audit trail
            self::logModuleChange($moduleKey, null, $enabled ? 'enable' : 'disable', null, $enabled, $userId, $userRole);
            
            // Update cached config
            foreach (self::$config['modules'] as &$module) {
                if ($module['module_key'] === $moduleKey) {
                    $module['is_enabled'] = $enabled ? 1 : 0;
                    break;
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to update module status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update module setting
     */
    public static function updateModuleSetting($moduleKey, $configKey, $newValue, $userId, $userRole) {
        if (self::$config === null) {
            self::init();
        }
        
        try {
            // Get old value for audit
            $oldValue = self::getModuleSetting($moduleKey, $configKey);
            
            $stmt = self::$pdo->prepare("UPDATE module_config SET config_value = ?, updated_at = CURRENT_TIMESTAMP WHERE module_key = ? AND config_key = ?");
            $stmt->execute([$newValue, $moduleKey, $configKey]);
            
            // Log to audit trail
            self::logModuleChange($moduleKey, $configKey, 'update', $oldValue, $newValue, $userId, $userRole);
            
            // Update cached config
            foreach (self::$config['settings'] as &$setting) {
                if ($setting['module_key'] === $moduleKey && $setting['config_key'] === $configKey) {
                    $setting['config_value'] = $newValue;
                    break;
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to update module setting: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log module configuration changes to audit trail
     */
    private static function logModuleChange($moduleKey, $configKey, $action, $oldValue, $newValue, $userId, $userRole) {
        try {
            $stmt = self::$pdo->prepare("INSERT INTO module_config_audit (module_key, config_key, action_type, old_value, new_value, changed_by, changed_by_role, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $moduleKey,
                $configKey,
                $action,
                is_string($oldValue) ? $oldValue : json_encode($oldValue),
                is_string($newValue) ? $newValue : json_encode($newValue),
                $userId,
                $userRole,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Failed to log module change: " . $e->getMessage());
        }
    }
    
    /**
     * Get audit trail for module
     */
    public static function getModuleAuditTrail($moduleKey = null, $limit = 100) {
        if (self::$config === null) {
            self::init();
        }
        
        try {
            $sql = "SELECT mca.*, u.username FROM module_config_audit mca LEFT JOIN users u ON mca.changed_by = u.id WHERE 1=1";
            $params = [];
            
            if ($moduleKey) {
                $sql .= " AND mca.module_key = ?";
                $params[] = $moduleKey;
            }
            
            $sql .= " ORDER BY mca.timestamp DESC LIMIT " . (int)$limit;
            
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to get module audit trail: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get settings grouped by category for a module
     */
    public static function getModuleSettingsByCategory($moduleKey) {
        $settings = self::getModuleSettings($moduleKey);
        $grouped = [];
        
        foreach ($settings as $setting) {
            $category = $setting['config_category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $setting;
        }
        
        return $grouped;
    }
}
?>
