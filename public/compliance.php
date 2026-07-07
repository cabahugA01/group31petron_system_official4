<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'compliance';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
$me = current_user();

$role = role_key($me['role'] ?? 'staff');
if (!in_array($role, ['manager','admin','superadmin'])) { header("Location: dashboard.php"); exit; }

include __DIR__ . '/../partials/header.php';
?>
<div class="page-head">
  <div>
    <h1 class="h1">Compliance</h1>
    <div class="sub">This page is part of the Compliance module.</div>
  </div>
</div>
<?php
$view = $_GET['view'] ?? 'safety';
$labels = ['safety'=>'Safety Checks Log','procedures'=>'Procedure Adherence Reports'];
?>
<section class="card">
  <div class="card-head">
    <div class="card-title"><i class="fas fa-shield"></i> <?php echo htmlspecialchars($labels[$view] ?? 'Compliance'); ?></div>
    <div class="muted">Compliance tracking (placeholder)</div>
  </div>
  <div style="padding:16px;">
    <a class="btn ghost" href="compliance.php?view=safety">Safety</a>
    <a class="btn ghost" href="compliance.php?view=procedures">Procedures</a>
    <div style="margin-top:12px;" class="muted">Add DB tables/logging for compliance checks when ready.</div>
  </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
