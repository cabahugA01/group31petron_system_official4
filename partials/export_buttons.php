<?php
/**
 * Reusable Export Buttons Partial
 *
 * Usage:
 *   $export_table_id = 'myTableId';
 *   $export_filename = 'my_export';
 *   $export_title    = 'My Report Title';
 *   $export_back_url = '';   // optional — if set, a Back button appears
 *   require __DIR__ . '/export_buttons.php';
 *
 * All variables are optional and have sensible defaults.
 */
$export_table_id = $export_table_id ?? 'mainTable';
$export_filename = $export_filename ?? 'export';
$export_title    = $export_title    ?? 'Report';
$export_back_url = $export_back_url ?? '';

// Rows-per-page select id (optional — if set, the dropdown is rendered)
$export_rows_select_id   = $export_rows_select_id   ?? '';
$export_pagination_id    = $export_pagination_id    ?? '';
$export_default_rows     = $export_default_rows     ?? 25;
?>
<div style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">

  <!-- Excel -->
  <button onclick="exportTableToExcel('<?= htmlspecialchars($export_table_id) ?>','<?= htmlspecialchars($export_filename) ?>.xls')"
          title="Export to Excel"
          style="background:#1d6f42;color:#fff;border:none;display:inline-flex;align-items:center;
                 gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;
                 font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s;"
          onmouseover="this.style.background='#155231'"
          onmouseout="this.style.background='#1d6f42'">
    <i class="fas fa-file-excel"></i> Excel
  </button>

  <!-- CSV -->
  <button onclick="exportTableToCSV('<?= htmlspecialchars($export_table_id) ?>','<?= htmlspecialchars($export_filename) ?>.csv')"
          title="Export to CSV"
          style="background:#003d7a;color:#fff;border:none;display:inline-flex;align-items:center;
                 gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;
                 font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s;"
          onmouseover="this.style.background='#002855'"
          onmouseout="this.style.background='#003d7a'">
    <i class="fas fa-file-csv"></i> CSV
  </button>

  <!-- PDF -->
  <button onclick="exportTableToPDF('<?= htmlspecialchars($export_table_id) ?>','<?= htmlspecialchars($export_title) ?>')"
          title="Export to PDF / Print"
          style="background:#dc2626;color:#fff;border:none;display:inline-flex;align-items:center;
                 gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;
                 font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s;"
          onmouseover="this.style.background='#b91c1c'"
          onmouseout="this.style.background='#dc2626'">
    <i class="fas fa-file-pdf"></i> PDF
  </button>

  <?php if ($export_back_url): ?>
  <!-- Back -->
  <a href="<?= htmlspecialchars($export_back_url) ?>"
     title="Go Back"
     style="background:#6c7280;color:#fff;text-decoration:none;display:inline-flex;align-items:center;
            gap:6px;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;
            transition:background .2s;"
     onmouseover="this.style.background='#4b5563'"
     onmouseout="this.style.background='#6c7280'">
    <i class="fas fa-arrow-left"></i> Back
  </a>
  <?php endif; ?>

</div>
<?php
// Reset variables to avoid leaking into next include
unset($export_table_id, $export_filename, $export_title, $export_back_url,
      $export_rows_select_id, $export_pagination_id, $export_default_rows);
?>
