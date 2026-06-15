-- ============================================================================
-- System Settings Table
-- Stores all estate form configurations: logo, colors, layout, accessibility
-- ============================================================================

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL COMMENT 'Unique key for the setting',
    `setting_value` TEXT NULL COMMENT 'Value of the setting (JSON or plain text)',
    `station_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = global, >0 = specific station',
    `category` VARCHAR(50) NULL DEFAULT NULL COMMENT 'branding, theme, layout, accessibility',
    `updated_by` INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'User ID who last updated',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_setting` (`setting_key`, `station_id`),
    KEY `idx_station_id` (`station_id`),
    KEY `idx_category` (`category`),
    KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Insert Default Global Settings
-- ============================================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `station_id`, `category`, `updated_at`) 
VALUES
    -- Default Colors
    ('global_color_primary', '#002F6C', 0, 'theme', NOW()),
    ('global_color_accent', '#CC0000', 0, 'theme', NOW()),
    ('global_color_success', '#16a34a', 0, 'theme', NOW()),
    ('global_color_warning', '#d97706', 0, 'theme', NOW()),
    ('global_color_danger', '#dc2626', 0, 'theme', NOW()),
    
    -- Default Layout
    ('global_layout_sidebar_style', 'inline', 0, 'layout', NOW()),
    ('global_layout_card_arrangement', 'grid', 0, 'layout', NOW()),
    ('global_layout_base_font_size', '14', 0, 'layout', NOW()),
    
    -- Default Accessibility
    ('global_accessibility_high_contrast', '0', 0, 'accessibility', NOW()),
    ('global_accessibility_font_scale', '100', 0, 'accessibility', NOW()),
    ('global_accessibility_focus_indicators', '0', 0, 'accessibility', NOW()),
    ('global_accessibility_reduce_motion', '0', 0, 'accessibility', NOW())
ON DUPLICATE KEY UPDATE 
    updated_at = NOW();

-- ============================================================================
-- Create Uploads Directory Structure (Note: Run manually via PHP/mkdir)
-- ============================================================================
-- Directory: uploads/logos/
-- Permissions: 0755 (rwxr-xr-x)
