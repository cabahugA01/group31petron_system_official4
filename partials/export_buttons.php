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
<style>
/* Export button protection — immune to global theme button-color override */
.exp-btn {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    height: 36px !important;
    padding: 8px 14px !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    text-decoration: none !important;
    border: none !important;
    transition: filter .2s !important;
    white-space: nowrap !important;
}
.exp-btn:hover { filter: brightness(0.88) !important; }
.exp-btn-excel { background-color: #1d6f42 !important; background: #1d6f42 !important; color: #fff !important; }
.exp-btn-csv   { background-color: #003d7a !important; background: #003d7a !important; color: #fff !important; }
.exp-btn-pdf   { background-color: #dc2626 !important; background: #dc2626 !important; color: #fff !important; }
.exp-btn-back  { background-color: #6c7280 !important; background: #6c7280 !important; color: #fff !important; }
</style>
<div style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">

  <!-- Excel -->
  <button onclick="exportTableToExcel('<?= htmlspecialchars($export_table_id) ?>','<?= htmlspecialchars($export_filename) ?>.xls')"
          title="Export to Excel"
          class="exp-btn exp-btn-excel">
    <i class="fas fa-file-excel"></i> Excel
  </button>

  <!-- CSV -->
  <button onclick="exportTableToCSV('<?= htmlspecialchars($export_table_id) ?>','<?= htmlspecialchars($export_filename) ?>.csv')"
          title="Export to CSV"
          class="exp-btn exp-btn-csv">
    <i class="fas fa-file-csv"></i> CSV
  </button>

  <!-- PDF -->
  <button onclick="exportTableToPDF('<?= htmlspecialchars($export_table_id) ?>','<?= htmlspecialchars($export_title) ?>')"
          title="Export to PDF / Print"
          class="exp-btn exp-btn-pdf">
    <i class="fas fa-file-pdf"></i> PDF
  </button>

  <?php if ($export_back_url): ?>
  <!-- Back -->
  <a href="<?= htmlspecialchars($export_back_url) ?>"
     title="Go Back"
     class="exp-btn exp-btn-back">
    <i class="fas fa-arrow-left"></i> Back
  </a>
  <?php endif; ?>

</div>
<?php
// Reset variables to avoid leaking into next include
unset($export_table_id, $export_filename, $export_title, $export_back_url,
      $export_rows_select_id, $export_pagination_id, $export_default_rows);
?>

