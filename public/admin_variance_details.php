<?php
/**
 * ADMIN VARIANCE DETAILS (STATION-SCOPED)
 * Full-page detail view for a single fuel variance report.
 * Access: admin and superadmin roles only.
 */

$page_id = 'admin_variance_reports';

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

$report_id = (int)($_GET['id'] ?? 0);
if ($report_id <= 0) {
    $_SESSION['error'] = 'Invalid variance report ID.';
    header('Location: admin_variance_reports.php'); exit;
}

// ── Fetch the variance report (station-scoped) ────────────────────────────────
$vr = null;
try {
    $stmt = $pdo->prepare("
        SELECT fvr.*,
               s.name  AS station_name,
               u.name  AS investigator_name
        FROM fuel_variance_reports fvr
        LEFT JOIN stations s ON s.id = fvr.station_id
        LEFT JOIN users    u ON u.id = fvr.investigated_by
        WHERE fvr.id = ? AND fvr.station_id = ?
        LIMIT 1
    ");
    $stmt->execute([$report_id, $station_id]);
    $vr = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

if (!$vr) {
    $_SESSION['error'] = 'Variance report not found or does not belong to your station.';
    header('Location: admin_variance_reports.php'); exit;
}

// ── Severity classification ───────────────────────────────────────────────────
$abs_pct = abs((float)($vr['variance_percent'] ?? 0));
if ($abs_pct > 5) {
    $sev_label = 'Critical';
    $sev_color = '#dc2626';
    $sev_bg    = '#fee2e2';
    $sev_icon  = 'fa-exclamation-triangle';
} elseif ($abs_pct >= 2) {
    $sev_label = 'Significant';
    $sev_color = '#d97706';
    $sev_bg    = '#fffbeb';
    $sev_icon  = 'fa-exclamation-circle';
} else {
    $sev_label = 'Minor';
    $sev_color = '#16a34a';
    $sev_bg    = '#f0fdf4';
    $sev_icon  = 'fa-info-circle';
}

// ── Status colors ─────────────────────────────────────────────────────────────
$status_map = [
    'Open'               => ['color' => '#991b1b', 'bg' => '#fee2e2'],
    'Under Investigation'=> ['color' => '#92400e', 'bg' => '#fef3c7'],
    'Resolved'           => ['color' => '#166534', 'bg' => '#dcfce7'],
];
$st = $vr['status'] ?? 'Open';
$sc = $status_map[$st] ?? ['color' => '#374151', 'bg' => '#f1f5f9'];

// ── Log ───────────────────────────────────────────────────────────────────────
try {
    log_activity($pdo, $me['id'], 'View Variance Detail',
        "Admin {$me['name']} viewed variance report #{$report_id}");
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>

<!-- ── Page Header ─────────────────────────────────────────────────────────── -->
<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:22px;">
    <div>
        <h1 class="h1" style="margin:0 0 4px 0;">
            <i class="fas fa-chart-line"></i> Variance Report #<?php echo $report_id; ?>
        </h1>
        <div class="sub">Detailed compliance view &mdash; <?php echo htmlspecialchars($vr['fuel_type'] ?? ''); ?> &bull; <?php echo date('F d, Y', strtotime($vr['report_date'])); ?></div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a href="admin_variance_reports.php"
           style="background:#002F6C;color:#fff;text-decoration:none;height:36px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <button onclick="window.print()"
                style="background:#6c757d;color:#fff;border:none;height:36px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<!-- ── Summary Cards ──────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;">

    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-calendar-alt" style="color:#2563eb;font-size:18px;"></i>
        </div>
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;">Report Date</div>
            <div style="font-size:16px;font-weight:700;color:#1e293b;"><?php echo date('M d, Y', strtotime($vr['report_date'])); ?></div>
        </div>
    </div>

    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-gas-pump" style="color:#16a34a;font-size:18px;"></i>
        </div>
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;">Fuel Type</div>
            <div style="font-size:16px;font-weight:700;color:#1e293b;"><?php echo htmlspecialchars($vr['fuel_type'] ?? '—'); ?></div>
        </div>
    </div>

    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:14px;border-left:4px solid <?php echo $sev_color; ?>;">
        <div style="width:42px;height:42px;border-radius:10px;background:<?php echo $sev_bg; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas <?php echo $sev_icon; ?>" style="color:<?php echo $sev_color; ?>;font-size:18px;"></i>
        </div>
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;">Severity</div>
            <div style="font-size:16px;font-weight:700;color:<?php echo $sev_color; ?>;"><?php echo $sev_label; ?></div>
        </div>
    </div>

    <div class="card" style="padding:16px 18px;display:flex;align-items:center;gap:14px;border-left:4px solid <?php echo $sc['color']; ?>;">
        <div style="width:42px;height:42px;border-radius:10px;background:<?php echo $sc['bg']; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-flag" style="color:<?php echo $sc['color']; ?>;font-size:18px;"></i>
        </div>
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;">Status</div>
            <div style="font-size:16px;font-weight:700;color:<?php echo $sc['color']; ?>;"><?php echo htmlspecialchars($st); ?></div>
        </div>
    </div>

</div>

<!-- ── Two-column detail ──────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

    <!-- Variance Analysis -->
    <div class="card" style="padding:20px;">
        <div style="font-size:14px;font-weight:700;color:#002F6C;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
            <i class="fas fa-chart-bar" style="margin-right:6px;"></i> Variance Analysis
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div style="background:#f8fafc;border-radius:8px;padding:14px;text-align:center;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;margin-bottom:4px;">Expected Stock</div>
                <div style="font-size:24px;font-weight:700;color:#2563eb;"><?php echo number_format((float)($vr['expected_stock'] ?? 0), 2); ?><span style="font-size:13px;color:#64748b;"> L</span></div>
            </div>
            <div style="background:#f8fafc;border-radius:8px;padding:14px;text-align:center;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;margin-bottom:4px;">Actual Stock</div>
                <div style="font-size:24px;font-weight:700;color:#0891b2;"><?php echo number_format((float)($vr['actual_stock'] ?? 0), 2); ?><span style="font-size:13px;color:#64748b;"> L</span></div>
            </div>
            <div style="background:<?php echo $sev_bg; ?>;border-radius:8px;padding:14px;text-align:center;border:1px solid <?php echo $sev_color; ?>20;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;margin-bottom:4px;">Variance (L)</div>
                <div style="font-size:24px;font-weight:700;color:<?php echo $sev_color; ?>;">
                    <?php
                    $vl = (float)($vr['variance_liters'] ?? 0);
                    echo ($vl >= 0 ? '+' : '') . number_format($vl, 2);
                    ?><span style="font-size:13px;color:#64748b;"> L</span>
                </div>
            </div>
            <div style="background:<?php echo $sev_bg; ?>;border-radius:8px;padding:14px;text-align:center;border:1px solid <?php echo $sev_color; ?>20;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;margin-bottom:4px;">Variance (%)</div>
                <div style="font-size:24px;font-weight:700;color:<?php echo $sev_color; ?>;">
                    <?php
                    $vp = (float)($vr['variance_percent'] ?? 0);
                    echo ($vp >= 0 ? '+' : '') . number_format($vp, 2);
                    ?>%
                </div>
            </div>
        </div>
    </div>

    <!-- Investigation Info -->
    <div class="card" style="padding:20px;">
        <div style="font-size:14px;font-weight:700;color:#002F6C;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
            <i class="fas fa-search" style="margin-right:6px;"></i> Investigation Details
        </div>

        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <tr>
                <td style="padding:8px 0;color:#64748b;font-weight:600;width:40%;">Investigated By</td>
                <td style="padding:8px 0;color:#1e293b;"><?php echo $vr['investigator_name'] ? htmlspecialchars($vr['investigator_name']) : '<span style="color:#94a3b8;font-style:italic;">Not yet investigated</span>'; ?></td>
            </tr>
            <tr style="border-top:1px solid #f1f5f9;">
                <td style="padding:8px 0;color:#64748b;font-weight:600;">Created At</td>
                <td style="padding:8px 0;color:#1e293b;"><?php echo isset($vr['created_at']) && $vr['created_at'] ? date('M d, Y H:i', strtotime($vr['created_at'])) : date('M d, Y', strtotime($vr['report_date'])); ?></td>
            </tr>
            <tr style="border-top:1px solid #f1f5f9;">
                <td style="padding:8px 0;color:#64748b;font-weight:600;">Last Updated</td>
                <td style="padding:8px 0;color:#1e293b;"><?php echo isset($vr['updated_at']) && $vr['updated_at'] ? date('M d, Y H:i', strtotime($vr['updated_at'])) : '<span style="color:#94a3b8;font-style:italic;">Never</span>'; ?></td>
            </tr>
            <tr style="border-top:1px solid #f1f5f9;">
                <td style="padding:8px 0;color:#64748b;font-weight:600;">Station</td>
                <td style="padding:8px 0;color:#1e293b;font-weight:600;"><?php echo htmlspecialchars($vr['station_name'] ?? 'This Station'); ?></td>
            </tr>
            <tr style="border-top:1px solid #f1f5f9;">
                <td style="padding:8px 0;color:#64748b;font-weight:600;">Report ID</td>
                <td style="padding:8px 0;color:#1e293b;">#<?php echo $report_id; ?></td>
            </tr>
        </table>
    </div>

</div>

<!-- ── Reason & Notes ────────────────────────────────────────────────────── -->
<?php if (!empty($vr['reason']) || !empty($vr['resolution_notes'])): ?>
<div style="display:grid;grid-template-columns:<?php echo (!empty($vr['reason']) && !empty($vr['resolution_notes'])) ? '1fr 1fr' : '1fr'; ?>;gap:16px;margin-bottom:16px;">

    <?php if (!empty($vr['reason'])): ?>
    <div class="card" style="padding:20px;">
        <div style="font-size:14px;font-weight:700;color:#002F6C;margin-bottom:12px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
            <i class="fas fa-comment-alt" style="margin-right:6px;"></i> Initial Reason / Notes
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:14px;font-size:13px;color:#374151;line-height:1.7;border-left:3px solid #002F6C;">
            <?php echo nl2br(htmlspecialchars($vr['reason'])); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($vr['resolution_notes'])): ?>
    <div class="card" style="padding:20px;">
        <div style="font-size:14px;font-weight:700;color:#002F6C;margin-bottom:12px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
            <i class="fas fa-clipboard-check" style="margin-right:6px;"></i> Investigation / Resolution Notes
        </div>
        <div style="background:#f0fdf4;border-radius:8px;padding:14px;font-size:13px;color:#374151;line-height:1.7;border-left:3px solid #16a34a;">
            <?php echo nl2br(htmlspecialchars($vr['resolution_notes'])); ?>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- ── No notes fallback ──────────────────────────────────────────────────── -->
<?php if (empty($vr['reason']) && empty($vr['resolution_notes'])): ?>
<div class="card" style="padding:24px;text-align:center;color:#94a3b8;margin-bottom:16px;">
    <i class="fas fa-comment-slash" style="font-size:32px;margin-bottom:10px;opacity:0.4;display:block;"></i>
    <div style="font-size:14px;font-weight:600;color:#64748b;margin-bottom:4px;">No notes recorded</div>
    <div style="font-size:13px;">No initial reason or investigation notes have been added for this report.</div>
</div>
<?php endif; ?>

<!-- ── Footer actions ────────────────────────────────────────────────────── -->
<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;padding-top:16px;border-top:1px solid #e2e8f0;">
    <a href="admin_variance_reports.php"
       style="background:#f1f5f9;color:#374151;text-decoration:none;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;border:1px solid #e2e8f0;">
        <i class="fas fa-list"></i> Back to All Reports
    </a>
    <button onclick="window.print()"
            style="background:#002F6C;color:#fff;border:none;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
        <i class="fas fa-print"></i> Print This Report
    </button>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
