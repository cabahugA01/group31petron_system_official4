<?php
/**
 * UI Configuration Management
 * Provides database-driven configuration for UI elements
 */

class UIConfig {
    private static $config = null;
    private static $pdo = null;
    
    /**
     * Initialize UI configuration with database values
     */
    private static function init() {
        global $pdo;
        self::$pdo = $pdo;
        
        // Create config table if not exists
        self::createConfigTable();
        
        // Load configuration
        self::loadConfig();
    }
    
    /**
     * Create UI configuration table
     */
    private static function createConfigTable() {
        $sql = "CREATE TABLE IF NOT EXISTS ui_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            config_key VARCHAR(100) UNIQUE NOT NULL,
            config_value TEXT NOT NULL,
            config_type VARCHAR(20) DEFAULT 'string',
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        try {
            self::$pdo->exec($sql);
            self::insertDefaultValues();
        } catch (Exception $e) {
            error_log("UI Config table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Insert default configuration values
     */
    private static function insertDefaultValues() {
        $defaults = [
            'modal_max_width' => '600',
            'modal_max_height_vh' => '90',
            'station_selector_max_height' => '300',
            'station_selector_padding' => '12',
            'station_selector_gap' => '8',
            'typeahead_max_height' => '220',
            'modal_body_padding' => '24px 20px',
            'modal_footer_height_offset' => '140'
        ];
        
        foreach ($defaults as $key => $value) {
            $stmt = self::$pdo->prepare("INSERT IGNORE INTO ui_config (config_key, config_value, config_type, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([$key, $value, 'string', self::getDescription($key)]);
        }
    }
    
    /**
     * Get description for configuration keys
     */
    private static function getDescription($key) {
        $descriptions = [
            'modal_max_width' => 'Maximum width of modal dialogs in pixels',
            'modal_max_height_vh' => 'Maximum height of modal dialogs in viewport height units',
            'station_selector_max_height' => 'Maximum height of station selector in pixels',
            'station_selector_padding' => 'Padding for station selector in pixels',
            'station_selector_gap' => 'Gap between station selector items in pixels',
            'typeahead_max_height' => 'Maximum height of typeahead suggestions in pixels',
            'modal_body_padding' => 'Padding for modal body',
            'modal_footer_height_offset' => 'Height offset for modal footer calculations'
        ];
        
        return $descriptions[$key] ?? 'Configuration value';
    }
    
    /**
     * Load configuration from database
     */
    private static function loadConfig() {
        try {
            $stmt = self::$pdo->query("SELECT config_key, config_value FROM ui_config");
            $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            self::$config = array_merge(self::getDefaultConfig(), $results);
        } catch (Exception $e) {
            error_log("Failed to load UI config: " . $e->getMessage());
            self::$config = self::getDefaultConfig();
        }
    }
    
    /**
     * Get default configuration values
     */
    private static function getDefaultConfig() {
        return [
            'modal_max_width' => '600',
            'modal_max_height_vh' => '90',
            'station_selector_max_height' => '300',
            'station_selector_padding' => '12',
            'station_selector_gap' => '8',
            'typeahead_max_height' => '220',
            'modal_body_padding' => '24px 20px',
            'modal_footer_height_offset' => '140'
        ];
    }
    
    /**
     * Get configuration value
     */
    public static function get($key, $default = null) {
        if (self::$config === null) {
            self::init();
        }
        
        return self::$config[$key] ?? $default;
    }
    
    /**
     * Get configuration value with unit
     */
    public static function getWithUnit($key, $unit = 'px', $default = null) {
        $value = self::get($key, $default);
        return $value ? $value . $unit : '';
    }
    
    /**
     * Get modal CSS configuration
     */
    public static function getModalConfig() {
        if (self::$config === null) {
            self::init();
        }
        
        return [
            'max_width' => self::get('modal_max_width', '600') . 'px',
            'max_height' => self::get('modal_max_height_vh', '90') . 'vh',
            'body_max_height' => 'calc(' . self::get('modal_max_height_vh', '90') . 'vh - ' . self::get('modal_footer_height_offset', '140') . 'px)',
            'body_padding' => self::get('modal_body_padding', '24px 20px')
        ];
    }
    
    /**
     * Get station selector CSS configuration
     */
    public static function getStationSelectorConfig() {
        if (self::$config === null) {
            self::init();
        }
        
        return [
            'max_height' => self::get('station_selector_max_height', '300') . 'px',
            'padding' => self::get('station_selector_padding', '12') . 'px',
            'gap' => self::get('station_selector_gap', '8') . 'px'
        ];
    }
    
    /**
     * Get typeahead CSS configuration
     */
    public static function getTypeaheadConfig() {
        if (self::$config === null) {
            self::init();
        }
        
        return [
            'max_height' => self::get('typeahead_max_height', '220') . 'px'
        ];
    }
    
    /**
     * Update configuration value
     */
    public static function set($key, $value) {
        if (self::$config === null) {
            self::init();
        }
        
        try {
            $stmt = self::$pdo->prepare("INSERT INTO ui_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = ?, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$key, $value, $value]);
            
            self::$config[$key] = $value;
            return true;
        } catch (Exception $e) {
            error_log("Failed to update UI config: " . $e->getMessage());
            return false;
        }
    }
}
?>
