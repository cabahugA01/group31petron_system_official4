<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

$page_id = 'developer_reports';
$station_name = '';
include __DIR__ . '/../partials/header.php';

// Database-driven report configuration
$report_sections = [
    'technical' => [
        'title' => 'Technical Reports',
        'description' => 'System usage, performance metrics',
        'icon' => 'fas fa-chart-line',
        'color' => '#3b82f6',
        'tables' => ['audit_log', 'activity_logs', 'system_logs']
    ],
    'security' => [
        'title' => 'Security Reports',
        'description' => 'Login attempts, failed authentications',
        'icon' => 'fas fa-shield-alt',
        'color' => '#ef4444',
        'tables' => ['audit_log', 'users', 'login_attempts']
    ],
    'developer_audit' => [
        'title' => 'Developer Audit Reports',
        'description' => 'Changes in code/config',
        'icon' => 'fas fa-code-branch',
        'color' => '#10b981',
        'tables' => ['audit_log', 'system_config', 'module_config']
    ]
];

// Alias for backward compatibility
$report_groups = $report_sections; 

?>

<style>
    :root {
        --primary-color: #003366;
        --secondary-color: #667085;
        --success-color: #10b981;
        --warning-color: #f59e0b;
        --danger-color: #ef4444;
        --info-color: #3b82f6;
        --bg-primary: #ffffff;
        --bg-secondary: #f8fafc;
        --bg-tertiary: #f1f5f9;
        --text-primary: #111827;
        --text-secondary: #6b7280;
        --text-tertiary: #9ca3af;
        --border-color: #e5e7eb;
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        --radius-sm: 6px;
        --radius-md: 8px;
        --radius-lg: 12px;
        --radius-xl: 16px;
    }

    .main-content {
        padding: 24px;
        background: var(--bg-secondary);
        min-height: 100vh;
    }
    
        
    .filter-section {
        margin-bottom: 20px;
    }
    
    .filter-form {
        display: grid;
        grid-template-columns: 1fr 2fr auto;
        gap: 16px;
        align-items: end;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .form-group label {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.875rem;
    }
    
    .form-control {
        padding: 10px 14px;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        background: var(--bg-primary);
        color: var(--text-primary);
        font-size: 0.875rem;
        transition: all 0.2s ease;
    }
    
    .form-control:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
    }
    
    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: var(--radius-md);
        font-weight: 600;
        font-size: 0.875rem;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    
    .btn-primary {
        background: var(--primary-color);
        color: white;
    }
    
    .btn-primary:hover {
        background: #002244;
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }
    
    .btn-secondary {
        background: var(--bg-tertiary);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
    }
    
    .btn-secondary:hover {
        background: var(--bg-secondary);
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }
    
    .stat-card {
        background: var(--bg-primary);
        border-radius: var(--radius-xl);
        padding: 24px;
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border-color);
        transition: all 0.3s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    
    .stat-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
    }
    
    .stat-card h3 {
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }
    
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: var(--radius-lg);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    
    .stat-icon.technical {
        background: rgba(59, 130, 246, 0.1);
        color: var(--info-color);
    }
    
    .stat-icon.security {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger-color);
    }
    
    .stat-icon.audit {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success-color);
    }
    
    .stat-card-content {
        color: var(--text-secondary);
        line-height: 1.6;
    }
    
    .stat-card-content ul {
        margin: 0;
        padding-left: 20px;
    }
    
    .stat-card-content li {
        margin-bottom: 6px;
    }
    
    .reports-section {
        margin-bottom: 32px;
    }
    
    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        padding: 0 4px;
    }
    
    .section-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .section-badge {
        background: var(--primary-color);
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .single-report-container {
        max-width: 100%;
        margin-bottom: 32px;
    }
    
    .report-card {
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .report-card:hover {
        transform: translateY(-2px);
    }
    
    .report-card-header {
        padding: 20px 24px 16px;
        border-bottom: 1px solid var(--border-color);
        background: var(--bg-secondary);
    }
    
    .report-card-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    
    .report-type-badge {
        padding: 4px 10px;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .badge-technical {
        background: rgba(59, 130, 246, 0.1);
        color: var(--info-color);
    }
    
    .badge-security {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger-color);
    }
    
    .badge-audit {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success-color);
    }
    
    .report-card-body {
        padding: 20px 24px;
    }
    
    .table-container {
        overflow: hidden;
        max-width: 100%;
    }
    
    .data-table {
        width: 100%;
        min-width: 100%;
        max-width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
        table-layout: fixed;
    }
    
    .data-table th {
        background: var(--bg-tertiary);
        padding: 6px 8px;
        text-align: left;
        font-weight: 600;
        color: var(--text-primary);
        border-bottom: 1px solid var(--border-color);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 0.85rem;
    }
    
    .data-table td {
        padding: 6px 8px;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-secondary);
        vertical-align: top;
        word-wrap: break-word;
        overflow-wrap: break-word;
        hyphens: auto;
        line-height: 1.2;
        font-size: 0.85rem;
    }
    
    /* Highly compressed column widths for single screen viewing */
    .data-table th:nth-child(1), .data-table td:nth-child(1) { width: 40px; } /* Id */
    .data-table th:nth-child(2), .data-table td:nth-child(2) { width: 55px; } /* User Id */
    .data-table th:nth-child(3), .data-table td:nth-child(3) { width: 65px; } /* Log Type */
    .data-table th:nth-child(4), .data-table td:nth-child(4) { width: 75px; } /* Action Type */
    .data-table th:nth-child(5), .data-table td:nth-child(5) { width: 85px; } /* Action Details */
    .data-table th:nth-child(6), .data-table td:nth-child(6) { width: 55px; } /* Entity Type */
    .data-table th:nth-child(7), .data-table td:nth-child(7) { width: 45px; } /* Entity Id */
    .data-table th:nth-child(8), .data-table td:nth-child(8) { width: 75px; } /* Old Values */
    .data-table th:nth-child(9), .data-table td:nth-child(9) { width: 75px; } /* New Values */
    .data-table th:nth-child(10), .data-table td:nth-child(10) { width: 85px; } /* IP Address */
    .data-table th:nth-child(11), .data-table td:nth-child(11) { width: 100px; } /* User Agent */
    .data-table th:nth-child(12), .data-table td:nth-child(12) { width: 45px; } /* Status */
    .data-table th:nth-child(13), .data-table td:nth-child(13) { width: 85px; } /* Error Message */
    .data-table th:nth-child(14), .data-table td:nth-child(14) { width: 75px; } /* Created At */
    
    .data-table tbody tr:hover {
        background: var(--bg-secondary);
    }
    
    .data-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    .empty-state, .alert-message {
        padding: 32px;
        text-align: center;
        color: var(--text-secondary);
        background: var(--bg-secondary);
        border-radius: var(--radius-md);
        border: 1px solid var(--border-color);
        margin: 0;
    }
    
    .alert-message {
        background: rgba(245, 158, 11, 0.1);
        border-color: rgba(245, 158, 11, 0.3);
        color: var(--warning-color);
    }
    
    .empty-state-icon, .alert-icon {
        font-size: 2rem;
        margin-bottom: 12px;
        opacity: 0.5;
    }
    
    .empty-state-title, .alert-title {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 8px;
    }
    
    .empty-state-text, .alert-text {
        font-size: 0.875rem;
        line-height: 1.5;
    }
    
    .report-actions {
        display: flex;
        gap: 8px;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid var(--border-color);
    }
    
    .report-details {
        margin-top: 16px;
        padding: 14px 16px;
        border-radius: var(--radius-md);
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
        font-size: 0.875rem;
        line-height: 1.5;
    }

    .btn-sm {
        padding: 6px 12px;
        font-size: 0.75rem;
    }
    
    .btn-icon {
        padding: 6px;
        border-radius: var(--radius-sm);
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        .main-content {
            padding: 16px;
        }
        
        .page-header {
            padding: 20px;
        }
        
        .page-header-content {
            flex-direction: column;
            align-items: stretch;
        }
        
        .filter-form {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .reports-grid {
            grid-template-columns: 1fr;
        }
        
        .section-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }
        
        .data-table {
            font-size: 0.8rem;
        }
        
        .data-table th,
        .data-table td {
            padding: 8px 12px;
        }
    }
    
    /* Loading and Animation States */
    .loading {
        opacity: 0.6;
        pointer-events: none;
    }
    
    .skeleton {
        background: linear-gradient(90deg, var(--bg-tertiary) 25%, var(--bg-secondary) 50%, var(--bg-tertiary) 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
    }
    
    @keyframes loading {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
    
    /* Custom Scrollbar */
    .table-container::-webkit-scrollbar {
        height: 6px;
        width: 6px;
    }
    
    .table-container::-webkit-scrollbar-track {
        background: var(--bg-secondary);
        border-radius: var(--radius-sm);
    }
    
    .table-container::-webkit-scrollbar-thumb {
        background: var(--border-color);
        border-radius: var(--radius-sm);
    }
    
    .table-container::-webkit-scrollbar-thumb:hover {
        background: var(--text-tertiary);
    }
    
    /* Responsive Table Adjustments */
    @media (max-width: 1200px) {
        .data-table {
            font-size: 0.8rem;
        }
        
        .data-table th,
        .data-table td {
            padding: 5px 6px;
        }
        
        .data-table th {
            font-size: 0.75rem;
        }
    }
    
    @media (max-width: 768px) {
        .data-table {
            font-size: 0.75rem;
        }
        
        .data-table th,
        .data-table td {
            padding: 4px 5px;
        }
        
        .data-table th {
            font-size: 0.7rem;
        }
    }
    
    /* Scroll to Top Button */
    .scroll-to-top {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 40px;
        height: 40px;
        background: var(--primary-color);
        color: white;
        border: none;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        box-shadow: var(--shadow-md);
        z-index: 1000;
        transition: all 0.3s ease;
        opacity: 0;
        visibility: hidden;
    }
    
    .scroll-to-top.visible {
        opacity: 1;
        visibility: visible;
    }
    
    .scroll-to-top:hover {
        background: #002244;
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }
    
    .scroll-to-top:active {
        transform: translateY(0);
    }
</style>

<div class="page-head">
    <div>
        <h1 class="h1" style="font-weight: 800;">REPORTS</h1>
        <div class="sub" style="font-weight: 400; color: #666;">System/Domain Technical Monitoring & Security Audit</div>
    </div>
    <div class="actions">
        <button class="btn dark" onclick="location.reload()">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<main class="main-content">
    <div class="stats-grid">
        <?php foreach ($report_sections as $key => $section): ?>
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3><?php echo htmlspecialchars($section['title']); ?></h3>
                    <div class="stat-icon <?php echo $key; ?>" style="background: <?php echo $section['color']; ?>20; color: <?php echo $section['color']; ?>;">
                        <i class="<?php echo $section['icon']; ?>"></i>
                    </div>
                </div>
                <div class="stat-card-content">
                    <p><?php echo htmlspecialchars($section['description']); ?></p>
                    <ul>
                        <?php foreach ($section['tables'] as $table): ?>
                            <li><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $table))); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="report-actions">
                    <a href="<?php 
                        echo $key === 'technical' ? 'reports_technical.php' : 
                             ($key === 'security' ? 'reports_security.php' : 
                             ($key === 'developer_audit' ? 'reports_developer_audit.php' : 'reports_audit_trail.php')); 
                    ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-eye"></i> View Report
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php include __DIR__ . '/../partials/footer.php'; ?>
