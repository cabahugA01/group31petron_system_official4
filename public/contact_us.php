<?php
$page_id = 'contact_us';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

// Retrieve configured Owner Email and Contact from Admin account (id=4)
$owner_name  = 'Romeca Katherine Jane Tello Pepito';
$owner_phone = '+63 917 791 8140';
$owner_email = 'rtpepito.coc@phinmaed.com';

try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, phone_number FROM users WHERE role = 'admin' OR id = 4 ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $adm = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($adm) {
        $fn = trim(($adm['first_name'] ?? '') . ' ' . ($adm['last_name'] ?? ''));
        if ($fn) $owner_name = $fn;
        if (!empty($adm['email'])) $owner_email = $adm['email'];
        if (!empty($adm['phone_number'])) $owner_phone = $adm['phone_number'];
    }
} catch (Exception $e) {}

$dev_name  = 'Christian Valencia';
$dev_email = 'christianval0813@gmail.com';
$dev_phone = '+63 928 808 9251';

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.contact-page-container {
    max-width: 820px;
    margin: 0 auto 60px;
    padding: 0 10px;
}

.contact-header {
    background: linear-gradient(145deg, #002244 0%, #003366 50%, #001a33 100%);
    border-radius: 16px;
    padding: 32px 24px;
    text-align: center;
    color: #ffffff;
    margin-bottom: 24px;
    box-shadow: 0 8px 24px rgba(0, 34, 68, 0.25);
    border: 1px solid rgba(255, 255, 255, 0.1);
    position: relative;
    overflow: hidden;
}

.contact-header h1 {
    font-size: 22px;
    font-weight: 800;
    margin: 0 0 6px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.contact-header p {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.8);
    margin: 0;
}

.contact-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.contact-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.08);
    padding: 28px 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.contact-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(0,0,0,0.1);
}

.contact-badge {
    position: absolute;
    top: 16px;
    right: 16px;
    background: rgba(0, 34, 68, 0.08);
    color: #002244;
    font-size: 10px;
    font-weight: 800;
    padding: 4px 10px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

.contact-icon-circle {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, #002244, #004488);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin-bottom: 18px;
    box-shadow: 0 6px 16px rgba(0, 34, 68, 0.25);
}

.contact-icon-circle.owner {
    background: linear-gradient(135deg, #E30613, #a0000a);
}

.contact-name {
    font-size: 16px;
    font-weight: 800;
    color: #002244;
    margin-bottom: 4px;
}

.contact-role {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 18px;
}

.contact-info-list {
    width: 100%;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 20px;
    text-align: left;
}

.contact-info-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #1e293b;
    padding: 6px 0;
    word-break: break-all;
}

.contact-info-item:not(:last-child) {
    border-bottom: 1px solid #edf2f7;
}

.contact-info-item i {
    color: #002244;
    font-size: 14px;
    width: 18px;
    text-align: center;
    flex-shrink: 0;
}

.contact-btn-group {
    display: flex;
    gap: 10px;
    width: 100%;
}

.contact-action-btn {
    flex: 1;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.btn-email {
    background: #002244;
    color: #ffffff;
}
.btn-email:hover {
    background: #003366;
    color: #ffffff;
    transform: translateY(-1px);
}

.btn-call {
    background: #16a34a;
    color: #ffffff;
}
.btn-call:hover {
    background: #15803d;
    color: #ffffff;
    transform: translateY(-1px);
}

@media (max-width: 680px) {
    .contact-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="contact-page-container">

    <div class="contact-header">
        <h1><i class="fas fa-phone-alt" style="margin-right:8px;"></i>Contact Us</h1>
        <p>Official Contact Information for Station Admin/Owner and System Developer</p>
    </div>

    <div class="contact-grid">
        <!-- Station Admin / Owner Card -->
        <div class="contact-card">
            <span class="contact-badge">Station Management</span>
            <div class="contact-icon-circle owner">
                <i class="fas fa-user-tie"></i>
            </div>
            <div class="contact-name"><?php echo htmlspecialchars($owner_name); ?></div>
            <div class="contact-role">Station Admin / Owner</div>

            <div class="contact-info-list">
                <div class="contact-info-item">
                    <i class="fas fa-phone-alt"></i>
                    <span><?php echo htmlspecialchars($owner_phone); ?></span>
                </div>
                <div class="contact-info-item">
                    <i class="fas fa-envelope"></i>
                    <span><?php echo htmlspecialchars($owner_email); ?></span>
                </div>
                <div class="contact-info-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>Vamenta Blvd., Carmen, CDO</span>
                </div>
            </div>

            <div class="contact-btn-group">
                <a href="mailto:<?php echo htmlspecialchars($owner_email); ?>" class="contact-action-btn btn-email">
                    <i class="fas fa-envelope"></i> Email Owner
                </a>
                <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $owner_phone)); ?>" class="contact-action-btn btn-call">
                    <i class="fas fa-phone"></i> Call Owner
                </a>
            </div>
        </div>

        <!-- Developer Card -->
        <div class="contact-card">
            <span class="contact-badge">Technical Support</span>
            <div class="contact-icon-circle">
                <i class="fas fa-laptop-code"></i>
            </div>
            <div class="contact-name"><?php echo htmlspecialchars($dev_name); ?></div>
            <div class="contact-role">System Developer</div>

            <div class="contact-info-list">
                <div class="contact-info-item">
                    <i class="fas fa-phone-alt"></i>
                    <span><?php echo htmlspecialchars($dev_phone); ?></span>
                </div>
                <div class="contact-info-item">
                    <i class="fas fa-envelope"></i>
                    <span><?php echo htmlspecialchars($dev_email); ?></span>
                </div>
                <div class="contact-info-item">
                    <i class="fas fa-code-branch"></i>
                    <span>Petron System Engineering</span>
                </div>
            </div>

            <div class="contact-btn-group">
                <a href="mailto:<?php echo htmlspecialchars($dev_email); ?>" class="contact-action-btn btn-email">
                    <i class="fas fa-envelope"></i> Email Developer
                </a>
                <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $dev_phone)); ?>" class="contact-action-btn btn-call">
                    <i class="fas fa-phone"></i> Call Developer
                </a>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
