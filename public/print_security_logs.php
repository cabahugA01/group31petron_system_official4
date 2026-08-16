<?php
// ============================================================
// Print Security Logs View
// public/print_security_logs.php
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');

// Only SuperAdmin access
if ($role !== 'superadmin') {
    die("Access denied. SuperAdmin role required.");
}

// Validate CSRF
$csrf = $_GET['csrf_token'] ?? '';
if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    die("Invalid security token.");
}

// Build query
$where = [];
$params = [];

$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$station = $_GET['station'] ?? '';
$action_type = $_GET['action_type'] ?? '';

if (!empty($date_from)) {
    $where[] = "DATE(raw_time) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where[] = "DATE(raw_time) <= ?";
    $params[] = $date_to;
}
if (!empty($user_id)) {
    $where[] = "(user_id = ? OR user LIKE ?)";
    $params[] = $user_id;
    $params[] = "%$user_id%";
}
if (!empty($station)) {
    $where[] = "station_id = ?";
    $params[] = $station;
}
if (!empty($action_type)) {
    $where[] = "action = ?";
    $params[] = $action_type;
}

$where_clause = count($where) > 0 ? "WHERE " . implode(' AND ', $where) : "";

$query = "
    SELECT * FROM (
        (
            SELECT 
                al.created_at as raw_time,
                DATE_FORMAT(al.created_at, '%Y-%m-%d %H:%i:%s') as timestamp,
                CONCAT(u.first_name, ' ', u.last_name) as user,
                al.action_type as action,
                al.ip_address as ip,
                al.status as status,
                al.user_id,
                u.station_id,
                al.action_details as details
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
        )
        UNION ALL
        (
            SELECT 
                ual.created_at as raw_time,
                DATE_FORMAT(ual.created_at, '%Y-%m-%d %H:%i:%s') as timestamp,
                CONCAT(u.first_name, ' ', u.last_name) as user,
                ual.action as action,
                ual.ip_address as ip,
                'success' as status,
                ual.user_id,
                u.station_id,
                ual.details as details
            FROM user_activity_logs ual
            LEFT JOIN users u ON u.id = ual.user_id
        )
        UNION ALL
        (
            SELECT 
                se.created_at as raw_time,
                DATE_FORMAT(se.created_at, '%Y-%m-%d %H:%i:%s') as timestamp,
                se.source as user,
                se.event_type as action,
                'system' as ip,
                se.severity as status,
                NULL as user_id,
                NULL as station_id,
                se.message as details
            FROM system_events se
        )
    ) AS unified_logs
    $where_clause
    ORDER BY raw_time DESC
    LIMIT 1000
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get station name if filtered
$station_name = "All Stations";
if (!empty($station)) {
    $st_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $st_stmt->execute([$station]);
    $station_name = $st_stmt->fetchColumn() ?: "Unknown Station";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Petron Security Audit Logs</title>
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #333;
            margin: 0;
            padding: 40px;
            font-size: 13px;
            line-height: 1.5;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #00264D;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .title {
            margin: 0;
            color: #00264D;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }
        .subtitle {
            margin: 5px 0 0 0;
            color: #666;
            font-size: 13px;
        }
        .meta-info {
            text-align: right;
            font-size: 11px;
            color: #555;
            line-height: 1.6;
        }
        .filters-summary {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            gap: 24px;
            font-size: 12px;
        }
        .filter-item {
            margin: 0;
        }
        .filter-label {
            font-weight: 600;
            color: #00264D;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th {
            background: #f1f5f9;
            color: #00264D;
            font-weight: 700;
            text-align: left;
            padding: 10px 12px;
            border-bottom: 2px solid #cbd5e0;
            font-size: 11px;
            text-transform: uppercase;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-failed {
            background: #fee2e2;
            color: #991b1b;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
            @page {
                size: portrait;
                margin: 1.5cm;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1 class="title">PETRON CORPORATION</h1>
            <p class="subtitle">Security Audit & Activity Logs Report</p>
        </div>
        <div class="meta-info">
            <div><strong>Generated By:</strong> <?php echo htmlspecialchars($me['first_name'] . ' ' . $me['last_name']); ?></div>
            <div><strong>Date Generated:</strong> <?php echo date('Y-m-d H:i:s'); ?></div>
            <div><strong>Total Records:</strong> <?php echo count($logs); ?></div>
        </div>
    </div>

    <div class="filters-summary">
        <p class="filter-item"><span class="filter-label">Date Range:</span> <?php echo ($date_from ?: 'Start') . ' to ' . ($date_to ?: 'Present'); ?></p>
        <p class="filter-item"><span class="filter-label">Station:</span> <?php echo htmlspecialchars($station_name); ?></p>
        <p class="filter-item"><span class="filter-label">Action Filter:</span> <?php echo $action_type ?: 'All Actions'; ?></p>
        <?php if (!empty($user_id)): ?>
            <p class="filter-item"><span class="filter-label">User ID:</span> <?php echo htmlspecialchars($user_id); ?></p>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 15%;">Timestamp</th>
                <th style="width: 20%;">User</th>
                <th style="width: 20%;">Action</th>
                <th style="width: 35%;">Details</th>
                <th style="width: 10%;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($logs) === 0): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 30px; color: #999;">No security logs found matching the selected criteria.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log['timestamp']); ?></td>
                        <td><?php echo htmlspecialchars($log['user']); ?></td>
                        <td><strong><?php echo htmlspecialchars($log['action']); ?></strong></td>
                        <td style="color: #555;"><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                        <td>
                            <span class="badge <?php echo $log['status'] === 'success' ? 'badge-success' : 'badge-failed'; ?>">
                                <?php echo htmlspecialchars($log['status']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- SYSTEM DEVELOPED BY SIGNATURE (Print Only) -->
    <table class="print-only-sig" style="width:100%; margin-top:35px; page-break-inside:avoid; border:none; border-collapse:collapse;">
        <tr>
            <td style="border:none;"></td>
            <td style="border:none; width:220px; text-align:center; vertical-align:bottom;">
                <div style="font-size:10px; font-weight:700; color:#333; margin-bottom:25px; text-transform:uppercase;">SYSTEM DEVELOPED BY:</div>
                <div style="border-top:1px solid #000; padding-top:4px; font-weight:700; font-size:11px; color:#000;">
                    <?php echo htmlspecialchars(trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Super Admin')); ?>
                </div>
                <div style="font-size:9.5px; color:#555; margin-top:2px;">Super Admin</div>
            </td>
        </tr>
    </table>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            window.print();
            // Automatically close the print window after printing
            window.onafterprint = () => window.close();
        });
    </script>
</body>
</html>
