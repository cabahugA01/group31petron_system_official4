<?php
$page_id = 'about';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.about-page-container {
    max-width: 820px;
    margin: 0 auto 60px;
    padding: 0 10px;
}

.about-card {
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 8px 30px rgba(0, 34, 68, 0.1);
    border: 1px solid rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.about-banner {
    background: linear-gradient(145deg, #002244 0%, #003366 50%, #001a33 100%);
    padding: 38px 28px 32px;
    text-align: center;
    color: #ffffff;
    position: relative;
    overflow: hidden;
}

.about-banner::before {
    content: '';
    position: absolute;
    top: -50px; right: -50px;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(227, 6, 19, 0.3) 0%, rgba(227, 6, 19, 0) 70%);
}

.about-logo {
    width: 90px;
    height: auto;
    margin-bottom: 12px;
    filter: drop-shadow(0 4px 12px rgba(0,0,0,0.3));
}

.about-title {
    font-size: 20px;
    font-weight: 800;
    margin: 0 0 6px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.about-subtitle {
    font-size: 12.5px;
    color: rgba(255, 255, 255, 0.85);
    margin: 0;
    letter-spacing: 0.5px;
}

.about-body {
    padding: 32px 36px 38px;
}

.about-desc-paragraph {
    font-size: 15px;
    line-height: 1.8;
    color: #334155;
    background: #f8fafc;
    border-left: 4px solid #002244;
    padding: 20px 24px;
    border-radius: 0 12px 12px 0;
    margin-bottom: 26px;
    text-align: justify;
}

.about-features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
}

.about-feature-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.2s ease;
}

.about-feature-box:hover {
    border-color: #003366;
    background: #f0f7ff;
    transform: translateY(-1px);
}

.about-feature-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #002244;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.about-feature-text {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
}

@media (max-width: 600px) {
    .about-body { padding: 22px 20px 28px; }
    .about-desc-paragraph { font-size: 14px; padding: 16px 18px; }
}
</style>

<div class="about-page-container">

    <div class="about-card">
        <div class="about-banner">
            <img src="../assets/img/Petron%20Logo.png" alt="Petron Logo" class="about-logo" onerror="this.style.display='none';">
            <h1 class="about-title">About System</h1>
            <p class="about-subtitle">Petron Station &amp; Service Center Management System</p>
        </div>

        <div class="about-body">
            <p class="about-desc-paragraph">
                The Petron Station Management System is a web-based back-office management system developed for the Petron franchise station located at Vamenta Blvd., Carmen, Cagayan de Oro City, Misamis Oriental. It supports inventory management, merchandise transactions, job order management, fuel sales and reconciliation, reporting, receivables, audit trail, and staff operations. The system currently serves one franchise branch and is designed with a scalable, nationwide-ready structure to support additional Petron franchise branches in the future.
            </p>

            <div class="about-features-grid">
                <div class="about-feature-box">
                    <div class="about-feature-icon"><i class="fas fa-boxes"></i></div>
                    <div class="about-feature-text">Inventory Management</div>
                </div>
                <div class="about-feature-box">
                    <div class="about-feature-icon"><i class="fas fa-gas-pump"></i></div>
                    <div class="about-feature-text">Fuel Sales &amp; Reconciliation</div>
                </div>
                <div class="about-feature-box">
                    <div class="about-feature-icon"><i class="fas fa-wrench"></i></div>
                    <div class="about-feature-text">Service Job Orders</div>
                </div>
                <div class="about-feature-box">
                    <div class="about-feature-icon"><i class="fas fa-history"></i></div>
                    <div class="about-feature-text">Comprehensive Audit Trail</div>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
