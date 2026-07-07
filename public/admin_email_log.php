<?php
require_once __DIR__ . '/db_connect.php';
require_login();
require_once __DIR__ . '/../backend/lib.php';

$logfile = __DIR__ . '/../email_send.log';
$entries = [];
if (file_exists($logfile)) {
    $lines = file($logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $ln) {
        $d = json_decode($ln, true);
        if ($d) $entries[] = $d;
    }
}

include __DIR__ . '/../partials/header.php';
?>
<div class="page-head">
    <h1 class="h1">Email Send Log</h1>
    <div class="sub">Structured logs for OTP and transactional emails</div>
</div>

<div class="card" style="padding:20px;">
    <?php if (empty($entries)): ?>
        <div class="muted">No log entries found.</div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr><th>Time</th><th>To</th><th>Result</th><th>Attempts</th></tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $e): ?>
                <tr>
                    <td><?php echo htmlspecialchars($e['time'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($e['to'] ?? ''); ?></td>
                    <td>
                        <?php
                        $success = false;
                        foreach ($e['attempts'] ?? [] as $a) { if (!empty($a['success'])) $success = true; }
                        echo $success ? '<span style="color:green;font-weight:700;">SUCCESS</span>' : '<span style="color:#c0392b;font-weight:700;">FAILED</span>';
                        ?>
                    </td>
                    <td style="font-family:monospace; font-size:0.9em; white-space:pre-wrap;">
                        <?php foreach ($e['attempts'] ?? [] as $a):
                            echo htmlspecialchars(json_encode($a, JSON_UNESCAPED_SLASHES)) . "\n";
                        endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
