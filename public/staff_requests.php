<?php
/**
 * Staff Master Data Requests Tracking & Action Hub
 * Handles redirection from approval notifications directly into the transaction form,
 * or displays all requests submitted by the logged-in staff member.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'staff_requests';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// If single request ID is requested and it's Approved, auto-redirect to transaction hub with data applied
if (!empty($_GET['id'])) {
    $req_id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM master_data_requests WHERE id = ?");
        $stmt->execute([$req_id]);
        $target_req = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($target_req && strcasecmp($target_req['status'], 'Approved') === 0) {
            header("Location: staff_transactions_hub.php?section=merchandise&apply_mdr={$req_id}");
            exit;
        }
    } catch (Exception $e) {}
}

// Fetch all requests submitted by this user
$my_requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            COALESCE(
                NULLIF(TRIM(CONCAT(COALESCE(rev.first_name, ''), ' ', COALESCE(rev.last_name, ''))), ''),
                NULLIF(TRIM(rev.name), ''),
                NULLIF(TRIM(rev.username), ''),
                'Manager'
            ) AS reviewer_name
        FROM master_data_requests r
        LEFT JOIN users rev ON r.reviewed_by = rev.id
        WHERE r.requested_by = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([(int)$me['id']]);
    $my_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("staff_requests.php query error: " . $e->getMessage());
}

require_once __DIR__ . '/../partials/header.php';
?>
<div class="main-content" style="padding: 24px; background: #f8fafc; min-height: calc(100vh - 120px);">
    <div style="max-width: 1200px; margin: 0 auto;">
        
        <!-- Header -->
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <div>
                <h1 style="font-size: 22px; font-weight: 800; color: #002F70; margin: 0 0 4px 0;">
                    <i class="fas fa-clipboard-list" style="margin-right: 8px;"></i> My Master Data Requests
                </h1>
                <p style="font-size: 13px; color: #64748b; margin: 0;">
                    Track items you requested to be added into the system (Vehicles, Products, Services).
                </p>
            </div>
            <a href="staff_transactions_hub.php?section=merchandise" class="txn-btn secondary" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; padding:9px 16px; font-size:13px; font-weight:700; background:#ffffff; border:1.5px solid #cbd5e1; border-radius:7px; color:#334155;">
                <i class="fas fa-arrow-left"></i> Back to Transactions
            </a>
        </div>

        <!-- Requests Card -->
        <div style="background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow: hidden;">
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead>
                        <tr style="background: #002F70; color: #ffffff; font-weight: 700; text-transform: uppercase; font-size: 11.5px; letter-spacing: 0.4px;">
                            <th style="padding: 12px 14px;">Req No.</th>
                            <th style="padding: 12px 14px;">Category</th>
                            <th style="padding: 12px 14px;">Requested Details</th>
                            <th style="padding: 12px 14px; text-align: center;">Status</th>
                            <th style="padding: 12px 14px;">Date Submitted</th>
                            <th style="padding: 12px 14px;">Processed By</th>
                            <th style="padding: 12px 14px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($my_requests)): ?>
                            <tr>
                                <td colspan="7" style="padding: 32px; text-align: center; color: #94a3b8; font-size: 14px;">
                                    <i class="fas fa-inbox" style="font-size: 32px; display: block; margin-bottom: 8px; color: #cbd5e1;"></i>
                                    No requests found. You can request new vehicles, products, or services directly from the Transaction Hub.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($my_requests as $row): 
                                $payload = json_decode($row['data_payload'], true) ?: [];
                                $status = ucfirst(strtolower($row['status']));
                                $status_badge = 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;';
                                if ($status === 'Approved') {
                                    $status_badge = 'background:#dcfce7; color:#166534; border:1px solid #bbf7d0;';
                                } elseif ($status === 'Rejected') {
                                    $status_badge = 'background:#fee2e2; color:#991b1b; border:1px solid #fecaca;';
                                }
                            ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.1s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                                <td style="padding: 12px 14px; font-weight: 800; font-family: monospace; color: #002F70;">
                                    <?= htmlspecialchars($row['request_no']) ?>
                                </td>
                                <td style="padding: 12px 14px; font-weight: 700; color: #334155;">
                                    <span style="background: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-size: 11px; border: 1px solid #e2e8f0;">
                                        <?= htmlspecialchars($row['category']) ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 14px; color: #1e293b;">
                                    <?php if ($row['category'] === 'Vehicle'): ?>
                                        <strong><?= htmlspecialchars(($payload['vehicle_brand'] ?? '') . ' ' . ($payload['vehicle_model'] ?? '')) ?></strong>
                                        <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                            Type: <?= htmlspecialchars($payload['vehicle_type'] ?? 'N/A') ?> 
                                            <?= !empty($payload['fuel_type']) ? ' • Fuel: ' . htmlspecialchars($payload['fuel_type']) : '' ?>
                                        </div>
                                    <?php elseif ($row['category'] === 'Merchandise Product'): ?>
                                        <strong><?= htmlspecialchars($payload['product_name'] ?? '') ?></strong>
                                        <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                            Cat: <?= htmlspecialchars($payload['category'] ?? 'N/A') ?> • Price: ₱<?= number_format((float)($payload['selling_price'] ?? $payload['price'] ?? 0), 2) ?>
                                        </div>
                                    <?php elseif ($row['category'] === 'Service Type'): ?>
                                        <strong><?= htmlspecialchars($payload['service_name'] ?? '') ?></strong>
                                        <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                            Cat: <?= htmlspecialchars($payload['service_category'] ?? $payload['category'] ?? 'N/A') ?> • Fee: ₱<?= number_format((float)($payload['suggested_price'] ?? $payload['default_price'] ?? 0), 2) ?>
                                        </div>
                                    <?php else: ?>
                                        <?= htmlspecialchars(substr($row['data_payload'], 0, 50)) ?>
                                    <?php endif; ?>

                                    <?php if (!empty($row['rejection_reason'])): ?>
                                        <div style="margin-top: 4px; font-size: 11.5px; color: #dc2626; background: #fef2f2; padding: 3px 8px; border-radius: 4px; display: inline-block;">
                                            <i class="fas fa-exclamation-circle"></i> Reason: <?= htmlspecialchars($row['rejection_reason']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 14px; text-align: center;">
                                    <span style="display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; <?= $status_badge ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 14px; color: #475569; font-size: 12.5px;">
                                    <?= date('M d, Y h:i A', strtotime($row['created_at'])) ?>
                                </td>
                                <td style="padding: 12px 14px; color: #334155; font-weight: 600;">
                                    <?= ($status !== 'Pending') ? htmlspecialchars($row['reviewer_name']) : '<span style="color:#94a3b8;">—</span>' ?>
                                </td>
                                <td style="padding: 12px 14px; text-align: center;">
                                    <?php if ($status === 'Approved'): ?>
                                        <a href="staff_transactions_hub.php?section=merchandise&apply_mdr=<?= (int)$row['id'] ?>" 
                                           style="display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; background: #002F70; color: #ffffff; border-radius: 6px; font-size: 12px; font-weight: 700; text-decoration: none; box-shadow: 0 1px 2px rgba(0,47,112,0.2);">
                                            <i class="fas fa-play" style="font-size: 10px;"></i> Use in Job Order
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
<?php
require_once __DIR__ . '/../partials/footer.php';
?>