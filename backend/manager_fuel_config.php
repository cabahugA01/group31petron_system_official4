<?php
/**
 * Manager Fuel Management Configuration System
 * Database-driven configuration to eliminate hardcoded values
 */

class ManagerFuelConfig {
    private $pdo;
    private $station_id;
    private $config_cache = [];
    
    public function __construct($pdo, $station_id) {
        $this->pdo = $pdo;
        $this->station_id = $station_id;
        $this->initializeConfigTables();
        $this->loadDefaultConfig();
    }
    
    /**
     * Initialize configuration tables if they don't exist
     */
    private function initializeConfigTables() {
        try {
            // Create fuel_management_config table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS fuel_management_config (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    config_key VARCHAR(100) UNIQUE NOT NULL,
                    config_value TEXT NOT NULL,
                    data_type VARCHAR(20) DEFAULT 'string',
                    description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ");
            
            // Create suppliers table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS fuel_suppliers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    supplier_name VARCHAR(255) NOT NULL,
                    contact_person VARCHAR(255),
                    contact_phone VARCHAR(50),
                    contact_email VARCHAR(255),
                    address TEXT,
                    is_active BOOLEAN DEFAULT TRUE,
                    station_id INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (station_id) REFERENCES stations(id)
                )
            ");
            
        } catch (Exception $e) {
            error_log("Error initializing config tables: " . $e->getMessage());
        }
    }
    
    /**
     * Load default configuration values
     */
    private function loadDefaultConfig() {
        $defaults = [
            // System settings
            'default_supplier_id' => '1',
            'default_price_per_liter' => '50.00',
            'delivery_lead_time_days' => '3',
            'variance_threshold_percentage' => '5.0',
            'critical_variance_percentage' => '10.0',
            'low_stock_threshold_percentage' => '20.0',
            'critical_stock_threshold_percentage' => '10.0',

            // UI color settings — Petron brand palette
            'primary_color'   => '#00264D',
            'accent_color'    => '#CC0000',
            'success_color'   => '#28A745',
            'warning_color'   => '#FFC107',
            'danger_color'    => '#DC3545',
            'info_color'      => '#17A2B8',
            'secondary_color' => '#666666',
            'bg_color'        => '#F2F2F2',
            'max_tab_content_height' => '70vh',
            'grid_min_width' => '280px',
            'stats_grid_min_width' => '180px',

            // Business rules
            'max_delivery_volume' => '50000',
            'min_delivery_volume' => '1',
            'max_price_per_liter' => '200',
            'min_price_per_liter' => '1',
            'shift_history_days' => '7',
            'shifts_per_day' => '3',

            // Alert settings
            'enable_low_stock_alerts' => 'true',
            'enable_variance_alerts' => 'true',
            'alert_check_interval_hours' => '1',

            // Export settings
            'export_date_format' => 'Y-m-d',
            'export_time_format' => 'H:i:s',
            'export_decimal_places' => '2'
        ];

        // Color keys are always force-updated to ensure brand consistency
        $color_keys = ['primary_color','accent_color','success_color','warning_color',
                       'danger_color','info_color','secondary_color','bg_color'];

        foreach ($defaults as $key => $value) {
            $this->config_cache[$key] = $value;
            if (in_array($key, $color_keys)) {
                // Always write colors to DB so stale values are overwritten
                $this->saveConfigValue($key, $value);
            } elseif (!isset($this->config_cache[$key])) {
                $this->saveConfigValue($key, $value);
            }
        }
    }
    
    /**
     * Get configuration value
     */
    public function getConfig($key, $default = null) {
        if (!isset($this->config_cache[$key])) {
            $this->loadConfigValue($key);
        }
        
        return $this->config_cache[$key] ?? $default;
    }
    
    /**
     * Load configuration value from database
     */
    private function loadConfigValue($key) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT config_value, data_type FROM fuel_management_config 
                WHERE config_key = ?
            ");
            $stmt->execute([$key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $value = $this->castValue($result['config_value'], $result['data_type']);
                $this->config_cache[$key] = $value;
            }
        } catch (Exception $e) {
            error_log("Error loading config value '$key': " . $e->getMessage());
        }
    }
    
    /**
     * Save configuration value to database
     */
    private function saveConfigValue($key, $value) {
        try {
            $data_type = gettype($value);
            if ($data_type === 'double') $data_type = 'float';
            if ($data_type === 'boolean') $data_type = 'bool';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO fuel_management_config (config_key, config_value, data_type)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), data_type = VALUES(data_type)
            ");
            $stmt->execute([$key, $value, $data_type]);
        } catch (Exception $e) {
            error_log("Error saving config value '$key': " . $e->getMessage());
        }
    }
    
    /**
     * Cast value to appropriate type
     */
    private function castValue($value, $type) {
        switch ($type) {
            case 'int':
            case 'integer':
                return (int)$value;
            case 'float':
            case 'double':
                return (float)$value;
            case 'bool':
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            default:
                return $value;
        }
    }
    
    /**
     * Get suppliers for current station
     */
    public function getSuppliers() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, supplier_name, contact_person 
                FROM fuel_suppliers 
                WHERE station_id = ? AND is_active = TRUE
                ORDER BY supplier_name
            ");
            $stmt->execute([$this->station_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching suppliers: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get default supplier
     */
    public function getDefaultSupplier() {
        $defaultId = $this->getConfig('default_supplier_id');
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, supplier_name 
                FROM fuel_suppliers 
                WHERE id = ? AND station_id = ? AND is_active = TRUE
            ");
            $stmt->execute([$defaultId, $this->station_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching default supplier: " . $e->getMessage());
            return ['id' => $defaultId, 'supplier_name' => 'Default Supplier'];
        }
    }
    
    /**
     * Get UI color configuration
     */
    public function getColors() {
        return [
            'primary'   => $this->getConfig('primary_color',   '#00264D'),
            'accent'    => $this->getConfig('accent_color',    '#CC0000'),
            'success'   => $this->getConfig('success_color',   '#28A745'),
            'warning'   => $this->getConfig('warning_color',   '#FFC107'),
            'danger'    => $this->getConfig('danger_color',    '#DC3545'),
            'info'      => $this->getConfig('info_color',      '#17A2B8'),
            'secondary' => $this->getConfig('secondary_color', '#666666'),
            'bg'        => $this->getConfig('bg_color',        '#F2F2F2'),
        ];
    }
    
    /**
     * Get business rule configuration
     */
    public function getBusinessRules() {
        return [
            'default_price_per_liter' => $this->getConfig('default_price_per_liter'),
            'delivery_lead_time_days' => $this->getConfig('delivery_lead_time_days'),
            'variance_threshold_percentage' => $this->getConfig('variance_threshold_percentage'),
            'critical_variance_percentage' => $this->getConfig('critical_variance_percentage'),
            'low_stock_threshold_percentage' => $this->getConfig('low_stock_threshold_percentage'),
            'critical_stock_threshold_percentage' => $this->getConfig('critical_stock_threshold_percentage'),
            'max_delivery_volume' => $this->getConfig('max_delivery_volume'),
            'min_delivery_volume' => $this->getConfig('min_delivery_volume'),
            'max_price_per_liter' => $this->getConfig('max_price_per_liter'),
            'min_price_per_liter' => $this->getConfig('min_price_per_liter')
        ];
    }
    
    /**
     * Get UI configuration
     */
    public function getUIConfig() {
        return [
            'max_tab_content_height' => $this->getConfig('max_tab_content_height'),
            'grid_min_width' => $this->getConfig('grid_min_width'),
            'stats_grid_min_width' => $this->getConfig('stats_grid_min_width'),
            'shift_history_days' => $this->getConfig('shift_history_days'),
            'shifts_per_day' => $this->getConfig('shifts_per_day')
        ];
    }
    
    /**
     * Update configuration value
     */
    public function updateConfig($key, $value) {
        $this->config_cache[$key] = $value;
        $this->saveConfigValue($key, $value);
    }
    
    /**
     * Add new supplier
     */
    public function addSupplier($name, $contactPerson = '', $phone = '', $email = '', $address = '') {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO fuel_suppliers 
                (supplier_name, contact_person, contact_phone, contact_email, address, station_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $contactPerson, $phone, $email, $address, $this->station_id]);
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            error_log("Error adding supplier: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get manager fuel configuration instance
 */
function getManagerFuelConfig($pdo, $station_id) {
    static $config = null;
    if ($config === null) {
        $config = new ManagerFuelConfig($pdo, $station_id);
    }
    return $config;
}
?>
