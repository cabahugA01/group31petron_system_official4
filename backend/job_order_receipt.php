<?php
/**  * Job Order Receipt — Backend  *  * Dual-purpose file:  *  1. Class JobOrderReceipt  — used by joborder.php after INSERT to save receipt to DB  *  2. GET ?action=print&job_order_id=XXX — outputs a full printable HTML page  */  require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/lib.php';  // ══════════════════════════════════════════════════════════════════════════════
// CLASS
// ══════════════════════════════════════════════════════════════════════════════
class JobOrderReceipt {  private $pdo;  private $station_id;  public function __construct($pdo, $station_id) {  $this->pdo  = $pdo;  $this->station_id = $station_id;  }  // ── Generate a unique receipt number ─────────────────────────────────────  private function generateReceiptNumber() {  $prefix = 'RCPT-' . date('Ymd') . '-';  try {  $stmt = $this->pdo->prepare(  "SELECT COUNT(*) AS cnt FROM job_order_receipts  WHERE DATE(created_at) = CURDATE() AND station_id = ?"  );  $stmt->execute([$this->station_id]);  $n = (int)$stmt->fetchColumn();  } catch (Exception $e) {  $n = mt_rand(1, 999);  }  return $prefix . str_pad($n + 1, 3, '0', STR_PAD_LEFT);  }  // ── Ensure receipts table exists ──────────────────────────────────────────  private function ensureTable() {  try {  $this->pdo->exec("  CREATE TABLE IF NOT EXISTS job_order_receipts (  id  INT AUTO_INCREMENT PRIMARY KEY,  job_order_id  VARCHAR(50)  NOT NULL,  receipt_number  VARCHAR(50)  NOT NULL,  receipt_html  LONGTEXT  NOT NULL,  receipt_data  JSON  NULL,  station_id  INT  NOT NULL,  created_by  INT  NULL,  created_at  DATETIME  DEFAULT CURRENT_TIMESTAMP,  updated_at  DATETIME  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,  INDEX idx_jo  (job_order_id),  INDEX idx_stn (station_id)  )  ");  } catch (Exception $e) { /* already exists */ }  }  // ── Fetch job order row with joined names ─────────────────────────────────  public function fetchJobOrder($job_order_id) {  // Try by job_order_id first, then by job_order_number (same value)  $stmt = $this->pdo->prepare("  SELECT jo.*,  s.name  AS station_name,  s.address  AS station_address,  s.location  AS station_location,  s.vat_tin  AS station_vat_tin,  COALESCE(m.full_name, 'Unassigned') AS mechanic_name,  COALESCE(u.name, 'Staff')  AS created_by_name  FROM  job_orders jo  LEFT JOIN stations  s ON s.id = jo.station_id  LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id  LEFT JOIN users  u ON u.user_id = jo.created_by  WHERE  (jo.job_order_id = ? OR jo.job_order_number = ?)  LIMIT  1  ");  $stmt->execute([$job_order_id, $job_order_id]);  return $stmt->fetch(PDO::FETCH_ASSOC);  }  // ── Build receipt HTML fragment ───────────────────────────────────────────  public function buildReceiptHtml($job) {  ob_start();  $job_order_data = $job;  include __DIR__ . '/../templates/job_order_receipt.php';  return ob_get_clean();  }  // ── Save receipt to DB ────────────────────────────────────────────────────  public function saveReceipt($job_order_id, $html, $receipt_number, $job) {  $this->ensureTable();  try {  $stmt = $this->pdo->prepare("  INSERT INTO job_order_receipts  (job_order_id, receipt_number, receipt_html, receipt_data, station_id, created_by)  VALUES (?, ?, ?, ?, ?, ?)  ON DUPLICATE KEY UPDATE  receipt_html = VALUES(receipt_html),  receipt_data = VALUES(receipt_data),  updated_at  = NOW()  ");  $stmt->execute([  $job_order_id,  $receipt_number,  $html,  json_encode($job),  $this->station_id,  $_SESSION['user_id'] ?? null,  ]);  // Stamp receipt_number back onto the job order row  try {  $this->pdo->prepare(  "UPDATE job_orders SET receipt_number = ? WHERE job_order_id = ? OR job_order_number = ?"  )->execute([$receipt_number, $job_order_id, $job_order_id]);  } catch (Exception $e) { /* column may not exist yet */ }  return true;  } catch (Exception $e) {  error_log("Receipt save failed: " . $e->getMessage());  return false;  }  }  // ── Main entry: generate + save ───────────────────────────────────────────  public function generateAndSaveReceipt($job_order_id) {  try {  $job = $this->fetchJobOrder($job_order_id);  if (!$job) {  return ['success' => false, 'error' => "Job order not found: $job_order_id"];  }  $receipt_number = $this->generateReceiptNumber();  $html  = $this->buildReceiptHtml($job);  $this->saveReceipt($job_order_id, $html, $receipt_number, $job);  // Audit log  try {  $this->pdo->prepare("  INSERT INTO job_order_audit  (job_order_id, action, before_status, after_status,  performed_by, performed_at, notes, ip_address, user_agent)  VALUES (?, 'RECEIPT_GENERATED', 'Created', 'Generated', ?, NOW(), ?, ?, ?)  ")->execute([  $job['id'] ?? $job_order_id,  $_SESSION['user_id'] ?? null,  "Receipt $receipt_number generated for $job_order_id",  $_SERVER['REMOTE_ADDR']  ?? '',  $_SERVER['HTTP_USER_AGENT'] ?? '',  ]);  } catch (Exception $e) { /* audit table may differ */ }  return ['success' => true, 'receipt_number' => $receipt_number, 'html' => $html];  } catch (Exception $e) {  error_log("generateAndSaveReceipt error: " . $e->getMessage());  return ['success' => false, 'error' => $e->getMessage()];  }  }
}  // ══════════════════════════════════════════════════════════════════════════════
// HTTP ENDPOINT  — GET ?action=print&job_order_id=XXX
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {  require_login();  $me  = current_user();  $station_id = user_station_id();  if ($_GET['action'] === 'print') {  $job_order_id = trim($_GET['job_order_id'] ?? '');  if ($job_order_id === '') {  http_response_code(400);  echo '<p style="font-family:sans-serif;color:red;padding:20px">Missing job_order_id parameter.</p>';  exit;  }  $gen = new JobOrderReceipt($pdo, $station_id);  $job = $gen->fetchJobOrder($job_order_id);  if (!$job) {  http_response_code(404);  echo '<p style="font-family:sans-serif;color:red;padding:20px">Job order <strong>'  . htmlspecialchars($job_order_id) . '</strong> not found.</p>';  exit;  }  // Build receipt fragment  $receipt_html = $gen->buildReceiptHtml($job);  // Determine receipt number (use saved one or generate on-the-fly)  $receipt_number = $job['receipt_number'] ?? ('RCPT-' . strtoupper(substr(md5($job_order_id), 0, 8)));  header('Content-Type: text/html; charset=UTF-8');  $jo_safe = htmlspecialchars($job_order_id);  ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Job Order — <?php echo $jo_safe; ?></title>
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<style>
/* ── Reset ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}  /* ── Screen ── */
@media screen{  body{background:#d1d5db;font-family:'Courier New',Courier,monospace;padding:20px 12px 60px}  .jo-page{max-width:320px;margin:0 auto;background:#fff;border-radius:8px;  box-shadow:0 4px 24px rgba(0,0,0,.22);padding:16px 14px 16px}  .jo-toolbar{max-width:320px;margin:0 auto 14px;display:flex;gap:8px;justify-content:flex-end}  .jo-toolbar button{padding:9px 18px;border:none;border-radius:5px;font-size:13px;  font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px}  .btn-print{background:#003d7a;color:#fff}  .btn-print:hover{background:#002a56}  .btn-close{background:#6c757d;color:#fff}  .btn-close:hover{background:#545b62}
}  /* ── Print ── */
@page {  size: 80mm auto;  /* thermal receipt width, height auto-fits content */  margin: 4mm 3mm;  /* small margins — real receipt printers use ~3–4mm */
}
@media print{  body{margin:0;padding:0;background:#fff}  .jo-page{box-shadow:none;border-radius:0;padding:0;max-width:80mm;width:80mm}  .jo-toolbar{display:none!important}
}  /* ── Receipt body ── */
.jo-receipt{font-family:'Courier New',Courier,monospace;font-size:11.5px;color:#111;line-height:1.5}  /* Header */
.jo-r-head{text-align:center;margin-bottom:8px}
.jo-r-logo-img{width:68px;height:auto}
.jo-r-brand{font-size:12px;font-weight:700;color:#003d7a;margin-top:4px;letter-spacing:.4px}
.jo-r-branch{font-size:11px;font-weight:600;margin-top:2px}
.jo-r-address,.jo-r-tin{font-size:9.5px;color:#555;margin-top:1px}  /* Dividers */
.jo-r-div{border-top:1px dashed #888;margin:7px 0}
.jo-r-div2{border-top:3px double #111;margin:7px 0}  /* Title */
.jo-r-title{text-align:center;font-size:14px;font-weight:900;letter-spacing:1px;margin:5px 0 2px}
.jo-r-sub{text-align:center;font-size:9.5px;color:#555;margin-bottom:3px}  /* Section label */
.jo-r-lbl{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;  color:#003d7a;margin:5px 0 3px}  /* Key-value rows */
.jo-r-row{display:flex;justify-content:space-between;align-items:baseline;  margin-bottom:2px;gap:6px;font-size:11px}
.jo-r-key{color:#555;flex-shrink:0;min-width:88px}
.jo-r-val{text-align:right;word-break:break-word}
.jo-r-bold{font-weight:700}
.jo-r-note{font-size:9.5px;color:#666;font-style:italic;margin:2px 0}  /* Grand total */
.jo-r-grand{font-size:14px;font-weight:900;padding:3px 0}  /* Parts table */
.jo-r-th{display:flex;font-size:9px;font-weight:700;letter-spacing:.5px;  text-transform:uppercase;color:#555;border-bottom:1px solid #ccc;  padding-bottom:2px;margin-bottom:2px}
.jo-r-tr{display:flex;font-size:10.5px;margin-bottom:3px;align-items:flex-start;  border-bottom:1px dotted #ddd;padding-bottom:2px}
.jo-r-td-name{flex:1}
.jo-r-td-qty{width:26px;text-align:center;flex-shrink:0}
.jo-r-td-price{width:60px;text-align:right;flex-shrink:0}
.jo-r-td-sub{width:66px;text-align:right;flex-shrink:0;font-weight:600}
.jo-r-remarks{display:block;font-size:9px;color:#888;font-style:italic}  /* Badges */
.jo-r-badge{display:inline-block;font-size:8px;font-weight:700;padding:1px 5px;  border-radius:8px;margin-left:3px;vertical-align:middle}
.badge-inv{background:#d1fae5;color:#065f46}
.badge-manual{background:#fef3c7;color:#92400e}  /* Status badge */
.jo-r-status{display:inline-block;font-size:9px;font-weight:700;padding:2px 7px;  border-radius:10px;color:#fff}
.s-paid{background:#16a34a}.s-pending{background:#d97706}
.s-pending-payment{background:#dc2626}.s-credit{background:#7c3aed}  /* QR */
.jo-r-qr{text-align:center;margin:7px 0}
.jo-r-qr-lbl{font-size:9px;color:#888;margin-bottom:4px}
.jo-r-qr img{width:88px;height:88px}
.jo-r-qr-txt{font-size:8px;color:#aaa;word-break:break-all}  /* Footer */
.jo-r-foot{text-align:center;margin-top:7px}
.jo-r-foot-title{font-size:11px;font-weight:700;margin-bottom:3px}
.jo-r-foot-line{font-size:9.5px;color:#555;margin-bottom:2px}
.jo-r-foot-meta{font-size:8.5px;color:#aaa;margin-top:5px}
</style>
</head>
<body>  <div class="jo-toolbar">  <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>  <button class="btn-close" onclick="window.close()"><i class="fas fa-times"></i> Close</button>
</div>  <div class="jo-page">  <?php echo $receipt_html; ?>
</div>  <script>
window.addEventListener('load', function() {  setTimeout(function(){ window.print(); }, 500);
});
window.onafterprint = function() {  if (window.opener) {  window.close();  }
};
</script>
</body>
</html>  <?php  exit;  }  // Other actions (generate, history) — JSON responses  $gen = new JobOrderReceipt($pdo, $station_id);  switch ($_GET['action']) {  case 'generate':  $job_order_id = $_GET['job_order_id'] ?? '';  echo json_encode($gen->generateAndSaveReceipt($job_order_id));  break;  default:  echo json_encode(['success' => false, 'error' => 'Unknown action']);  }
}
