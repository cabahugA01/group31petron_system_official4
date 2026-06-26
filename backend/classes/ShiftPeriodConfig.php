<?php

/**
 * Shift Period Configuration Class
 * Manages database-driven shift period configuration for all modules
 */
class ShiftPeriodConfig {
    private $pdo;
    private $station_id;
    private $shift_periods = null;
    private $cache_key = 'shift_periods_config';
    
    public function __construct(PDO $pdo, int $station_id = null) {
        $this->pdo = $pdo;
        $this->station_id = $station_id;
        $this->ensureShiftPeriodsTable();
    }
    
    /**
     * Ensure the shift periods table exists and has default data
     */
    private function ensureShiftPeriodsTable(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS shift_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shift_key VARCHAR(20) NOT NULL UNIQUE,
            shift_name VARCHAR(100) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            description TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_shift_key (shift_key),
            INDEX idx_active_sort (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Insert default shift periods if they don't exist
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM shift_periods");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($count == 0) {
            $this->pdo->exec("INSERT INTO shift_periods (shift_key, shift_name, start_time, end_time, description, sort_order) VALUES 
                ('first', 'First Shift: 6:00 AM – 2:00 PM', '06:00:00', '14:00:00', 'Morning shift for staff', 1),
                ('second', 'Second Shift: 2:00 PM – 12:00 Midnight', '14:00:00', '23:59:59', 'Afternoon shift for staff', 2)");
        }
    }
    
    /**
     * Get all active shift periods
     */
    public function getShiftPeriods(): array {
        if ($this->shift_periods === null) {
            $stmt = $this->pdo->prepare("
                SELECT id, shift_key, shift_name, start_time, end_time, description, sort_order
                FROM shift_periods 
                WHERE is_active = TRUE 
                ORDER BY sort_order ASC
            ");
            $stmt->execute();
            $this->shift_periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->shift_periods;
    }
    
    /**
     * Get shift periods as options for HTML select
     */
    public function getShiftPeriodOptions(): array {
        $periods = $this->getShiftPeriods();
        $options = [];
        
        foreach ($periods as $period) {
            $options[$period['shift_key']] = $period['shift_name'];
        }
        
        return $options;
    }
    
    /**
     * Get current shift based on current time
     */
    public function getCurrentShift(): ?array {
        // Fixed schedule rules:
        //   Shift 1 (first)  → 06:00:00 – 13:59:59  (6:00 AM – 2:00 PM)
        //   Shift 2 (second) → 14:00:00 – 23:59:59  (2:00 PM – 12:00 MN)
        //   Early morning (00:00 – 05:59) → Shift 2 (previous night still active)
        $current_time = date('H:i:s');
        $shift_key = ($current_time >= '06:00:00' && $current_time < '14:00:00') ? 'first' : 'second';

        foreach ($this->getShiftPeriods() as $period) {
            if ($period['shift_key'] === $shift_key) {
                return $period;
            }
        }

        // Fallback: return any active period (prefer second for night)
        $periods = $this->getShiftPeriods();
        foreach (array_reverse($periods) as $p) {
            if ($p['shift_key'] === $shift_key) return $p;
        }
        return $periods[0] ?? null;
    }
    
    /**
     * Get shift by key
     */
    public function getShiftByKey(string $shift_key): ?array {
        foreach ($this->getShiftPeriods() as $period) {
            if ($period['shift_key'] === $shift_key) {
                return $period;
            }
        }
        return null;
    }
    
    /**
     * Validate shift key
     */
    public function isValidShiftKey(string $shift_key): bool {
        foreach ($this->getShiftPeriods() as $period) {
            if ($period['shift_key'] === $shift_key) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get shift ID by key
     */
    public function getShiftIdByKey(string $shift_key): ?int {
        $period = $this->getShiftByKey($shift_key);
        return $period ? $period['id'] : null;
    }
    
    /**
     * Generate HTML select options for shift periods
     */
    public function generateShiftSelectOptions(string $selected_value = ''): string {
        $options = '';
        $periods = $this->getShiftPeriods();
        
        foreach ($periods as $period) {
            $selected = ($period['shift_key'] === $selected_value) ? 'selected' : '';
            $options .= "<option value='{$period['shift_key']}' {$selected}>{$period['shift_name']}</option>";
        }
        
        return $options;
    }
    
    /**
     * Get shift period for a specific time
     */
    public function getShiftForTime(string $time): ?array {
        foreach ($this->getShiftPeriods() as $period) {
            if ($time >= $period['start_time'] && $time <= $period['end_time']) {
                return $period;
            }
        }
        
        // Default to first shift for times outside defined ranges
        $periods = $this->getShiftPeriods();
        return $periods[0] ?? null;
    }
    
    /**
     * Update shift period configuration
     */
    public function updateShiftPeriod(int $id, array $data): bool {
        $allowed_fields = ['shift_name', 'start_time', 'end_time', 'description', 'is_active', 'sort_order'];
        $update_data = [];
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        $set_clause = [];
        $values = [];
        
        foreach ($update_data as $field => $value) {
            $set_clause[] = "$field = ?";
            $values[] = $value;
        }
        
        $values[] = $id;
        
        $stmt = $this->pdo->prepare("UPDATE shift_periods SET " . implode(', ', $set_clause) . " WHERE id = ?");
        return $stmt->execute($values);
    }
    
    /**
     * Add new shift period
     */
    public function addShiftPeriod(array $data): bool {
        $required_fields = ['shift_key', 'shift_name', 'start_time', 'end_time'];
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO shift_periods (shift_key, shift_name, start_time, end_time, description, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $data['shift_key'],
            $data['shift_name'],
            $data['start_time'],
            $data['end_time'],
            $data['description'] ?? null,
            $data['sort_order'] ?? 0
        ]);
    }
    
    /**
     * Delete shift period
     */
    public function deleteShiftPeriod(int $id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM shift_periods WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Clear cache when configuration changes
     */
    public function clearCache(): void {
        $this->shift_periods = null;
    }
}

/**
 * Helper function to get shift period configuration
 */
function getShiftPeriodConfig(PDO $pdo, int $station_id = null): ShiftPeriodConfig {
    return new ShiftPeriodConfig($pdo, $station_id);
}
