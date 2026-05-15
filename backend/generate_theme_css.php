<<?php
/**
 * Dynamic Theme CSS Generator
 * Serves as stylesheet endpoint: /backend/generate_theme_css.php
 * Reads ui_config (category='theme') and outputs CSS variables
 */

require_once __DIR__ . '/../public/db_connect.php';

// Fetch theme config from DB
function getThemeConfig() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT config_key, config_value FROM ui_config WHERE config_category = 'theme'");
        $config = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $config[$row['config_key']] = $row['config_value'];
        }
        return $config;
    } catch (Exception $e) {
        // Fallback defaults
        return [
            'theme_mode' => 'light',
            'primary_color' => '#002F6C',
            'secondary_color' => '#6b7280',
            'accent_color' => '#10b981',
            'text_size' => 'medium',
            'font_family' => 'default',
            'sidebar_position' => 'left',
            'sidebar_state' => 'expanded',
            'theme_preset' => 'default'
        ];
    }
}

$themeConfig = getThemeConfig();

// Set content type
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=300'); // 5min cache

// CSS Variables Output
?>
:root {
    /* Theme Mode */
    --theme-mode: <?= htmlspecialchars($themeConfig['theme_mode'] ?? 'light') ?>;
    
    /* Core Colors - Dynamic from DB */
    --primary-color: <?= htmlspecialchars($themeConfig['primary_color'] ?? '#002F6C') ?>;
    --primary-dark: <?= htmlspecialchars(darken($themeConfig['primary_color'] ?? '#002F6C', 20)) ?>;
    --secondary-color: <?= htmlspecialchars($themeConfig['secondary_color'] ?? '#6b7280') ?>;
    --accent-color: <?= htmlspecialchars($themeConfig['accent_color'] ?? '#10b981') ?>;
    --success-color: #10b981;
    --warning-color: #f59e0b;
    --danger-color: #ef4444;
    
    /* Backgrounds & Surfaces */
    --page-bg: <?= ($themeConfig['theme_mode'] ?? 'light') === 'dark' ? '#0f172a' : '#f8fafc' ?>;
    --surface: <?= ($themeConfig['theme_mode'] ?? 'light') === 'dark' ? '#1e293b' : '#ffffff' ?>;
    --surface-soft: <?= ($themeConfig['theme_mode'] ?? 'light') === 'dark' ? '#334155' : '#f1f5f9' ?>;
    
    /* Text */
    --text-primary: <?= ($themeConfig['theme_mode'] ?? 'light') === 'dark' ? '#f8fafc' : '#0f172a' ?>;
    --text-secondary: <?= ($themeConfig['theme_mode'] ?? 'light') === 'dark' ? '#cbd5e1' : '#64748b' ?>;
    
    /* Typography */
    --font-family: <?= $themeConfig['font_family'] === 'default' ? 'Inter, system-ui, -apple-system, sans-serif' : htmlspecialchars($themeConfig['font_family']) ?>;
    --text-base-size: <?= $themeConfig['text_size'] === 'small' ? '0.875rem' : ($themeConfig['text_size'] === 'large' ? '1.125rem' : '1rem') ?>;
    
    /* Layout */
    --sidebar-width: <?= ($themeConfig['sidebar_state'] ?? 'expanded') === 'compact' ? '70px' : '250px' ?>;
    --sidebar-position: <?= $themeConfig['sidebar_position'] ?? 'left' ?>; 
    
    /* Borders & Shadows */
    --border-color: rgba(15, 23, 42, 0.08);
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    
    /* Gradients */
    --gradient-primary: linear-gradient(135deg, var(--primary-color), <?= darken($themeConfig['primary_color'] ?? '#002F6C', 20) ?>);
    --gradient-sidebar: linear-gradient(135deg, var(--primary-color), <?= darken($themeConfig['primary_color'] ?? '#002F6C', 30) ?>);
    
    /* Icon Colors */
    --icon-primary: var(--primary-color);
    --icon-secondary: var(--secondary-color);
    --icon-accent: var(--accent-color);
    --icon-success: var(--success-color);
    --icon-warning: var(--warning-color);
    --icon-danger: var(--danger-color);
}

/* Dark Mode Override */
@media (prefers-color-scheme: dark) {
    :root[style*="--theme-mode: light"] {
        --page-bg: #f1f5f9;
        --surface: #ffffff;
        --surface-soft: #f8fafc;
        --text-primary: #0f172a;
        --text-secondary: #64748b;
    }
}

/* Light Mode Override */
@media (prefers-color-scheme: light) {
    :root[style*="--theme-mode: dark"] {
        --page-bg: #0f172a;
        --surface: #1e293b;
        --surface-soft: #334155;
        --text-primary: #f8fafc;
        --text-secondary: #cbd5e1;
    }
}

/* Typography Base */
* {
    font-family: var(--font-family);
    font-size: var(--text-base-size);
}

body {
    background: var(--page-bg);
    color: var(--text-primary);
}

/* Sidebar Dynamic Styling */
.sidebar {
    background: var(--gradient-sidebar);
    --sidebar-bg: var(--gradient-sidebar);
}

/* Cards & Surfaces */
.card, .system-card, .stat-card {
    background: var(--surface);
    border: 1px solid var(--border-color);
}

/* Buttons */
.btn-primary {
    background: var(--gradient-primary);
    border-color: var(--primary-color);
}

.btn-primary:hover {
    background: var(--primary-dark);
}

/* Icons */
.fas, .far, .fab {
    color: var(--icon-primary);
}

/* Stat Cards */
.stat-icon {
    background: var(--gradient-primary);
    color: white;
}

.metric-icon {
    background: var(--gradient-primary);
    color: white;
}

/* Header Elements */
.page-head h1, .h1 {
    color: var(--primary-color);
}

/* Scroll Toggle Button */
.scroll-toggle-btn {
    background: var(--gradient-primary);
    border-color: var(--primary-color);
}

/* Theme Preview Override (for theme_settings.php live preview) */
.theme-preview {
    --primary-color: <?= htmlspecialchars($_GET['preview_primary'] ?? ($themeConfig['primary_color'] ?? '#002F6C')) ?>;
    --gradient-primary: linear-gradient(135deg, var(--primary-color), <?= darken($_GET['preview_primary'] ?? ($themeConfig['primary_color'] ?? '#002F6C'), 20) ?>);
}

<?php
// Helper function for CSS-safe color darkening
function darken($hex, $percent) {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2)) * (100 - $percent) / 100;
    $g = hexdec(substr($hex, 2, 2)) * (100 - $percent) / 100;
    $b = hexdec(substr($hex, 4, 2)) * (100 - $percent) / 100;
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}
?>

