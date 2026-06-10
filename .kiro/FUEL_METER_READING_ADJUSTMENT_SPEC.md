# Fuel Transaction (Meter Reading) – Adjustment Table (Manager Side)

## Status: 📋 READY FOR IMPLEMENTATION

## Overview
Add a **4th tab** to `manager_fuel_adjustments.php` for correcting meter reading discrepancies in fuel transactions. This allows managers to adjust incorrect staff-encoded readings and track variances.

---

## User Requirements (Cebuano)

**Detailed Specification:**
> "Fuel Transaction (Meter Reading) – Adjustment Table Design (Manager side)
> - Transaction ID → unique identifier sa meter reading entry
> - Fuel Type (17 tanker names) → Diesel 1, Diesel 2, Turbo Diesel, XCS, Kerosene, XTRA Unleaded, etc. (auto‑display tanan fuel types per tanker)
> - Previous Reading (System auto‑pull) → gikan sa last validated entry
> - Present Reading (Staff input) → gi‑encode sa staff
> - Calibration (Staff input) → liters nga gi‑encode kung naay test/adjustment
> - Liters Sold (System auto‑compute) → Present − Previous − Calibration
> - Actual Liters (Manager input) → kung naay discrepancy, manager mo‑encode ug correct value
> - Variance Value (System auto‑compute) → difference sa system computed vs manager actual
> - Reason (Manager input) → ngano naay discrepancy (ex. calibration test, staff error, pump variance)
> - Status (System auto‑update) → flagged → cleared or pending"

---

## Tab Structure in `manager_fuel_adjustments.php`

### Current Tabs:
1. **Fuel Deliveries** - DR vs Dipstick adjustments
2. **Fuel Transactions** - Tank level corrections (with Beginning/Ending columns)
3. **Adjustment History** - Historical records

### NEW Tab 4:
4. **Meter Reading Adjustments** - Correct staff-encoded transaction readings

---

## Table Columns Design

| Column | Source | Type | Editable | Description |
|--------|--------|------|----------|-------------|
| **Transaction ID** | `fuel_transactions.transaction_id` | Text | No | Unique identifier (e.g., FUEL2026125343720) |
| **Fuel Type** | `fuel_transactions.fuel_type` | Badge | No | Diesel, Turbo Diesel, XCS, Kerosene, XTRA UNL |
| **Pump #** | `fuel_pumps.pump_number` | Text | No | Pump reference |
| **Previous Reading** | `fuel_transactions.previous_reading` | Number | No | Beginning meter reading (auto-pulled) |
| **Present Reading** | `fuel_transactions.present_reading` | Number | No | Ending meter reading (staff input) |
| **Calibration** | `fuel_transactions.calibration` | Number | No | Calibration test value (staff input) |
| **Liters Sold (Computed)** | `fuel_transactions.liters_sold` | Number | No | Formula: Present - Previous - Calibration |
| **Actual Liters** | Manager Input | Input Field | **YES** | Manager correction if discrepancy exists |
| **Variance** | Auto-computed | Number | No | Formula: Actual Liters - Liters Sold |
| **Reason** | Manager Input | Textarea | **YES** | Explanation for adjustment |
| **Status** | `fuel_transactions.status` | Badge | Auto | Flagged / Cleared / Pending |
| **Actions** | - | Buttons | - | Adjust / View Details |

---

## SQL Query to Fetch Flagged Transactions

```sql
SELECT 
    ft.id,
    ft.transaction_id,
    ft.transaction_date,
    ft.fuel_type,
    ft.pump_id,
    fp.pump_number,
    ft.previous_reading,
    ft.present_reading,
    ft.calibration,
    ft.liters_sold,
    ft.price_per_liter,
    ft.total_amount,
    ft.status,
    ft.reject_reason,
    ft.notes,
    ft.staff_id,
    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as staff_name,
    u.username as staff_username,
    ft.shift_name,
    ft.shift_period
FROM fuel_transactions ft
LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
LEFT JOIN users u ON ft.staff_id = u.id
WHERE ft.station_id = ?
AND (
    ft.status = 'Flagged' 
    OR ft.status LIKE '%flag%'
    OR ft.reject_reason LIKE '%discrepancy%'
)
ORDER BY ft.transaction_date DESC, ft.created_at DESC
LIMIT 50
```

---

## POST Action: Adjust Meter Reading

### Form Submission
```php
if ($action === 'adjust_meter_reading') {
    $tx_id = (int)($_POST['transaction_id'] ?? 0);
    $actual_liters = (float)($_POST['actual_liters'] ?? 0);
    $adjustment_reason = trim($_POST['adjustment_reason'] ?? '');
    
    // Validate
    if ($tx_id <= 0) throw new Exception("Invalid transaction ID");
    if ($actual_liters < 0) throw new Exception("Actual liters cannot be negative");
    if (empty($adjustment_reason)) throw new Exception("Adjustment reason is required");
    
    // Fetch current transaction
    $stmt = $pdo->prepare("SELECT * FROM fuel_transactions 
                           WHERE id = ? AND station_id = ?");
    $stmt->execute([$tx_id, $station_id]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tx) throw new Exception("Transaction not found");
    
    $old_liters = (float)$tx['liters_sold'];
    $variance = $actual_liters - $old_liters;
    
    // Update transaction
    $stmt = $pdo->prepare("UPDATE fuel_transactions 
                           SET liters_sold = ?,
                               total_amount = ? * price_per_liter,
                               status = 'Cleared',
                               reject_reason = ?,
                               validated_by = ?,
                               validated_at = NOW()
                           WHERE id = ? AND station_id = ?");
    $stmt->execute([
        $actual_liters,
        $actual_liters,
        $adjustment_reason,
        $me['id'],
        $tx_id,
        $station_id
    ]);
    
    // Adjust inventory: old_liters - actual_liters (return difference to stock)
    $diff_liters = $old_liters - $actual_liters;
    $pdo->prepare("UPDATE fuel_inventory 
                   SET current_level = COALESCE(current_level, 0) + ?,
                       last_updated = NOW()
                   WHERE station_id = ? 
                   AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))")
        ->execute([$diff_liters, $station_id, $tx['fuel_type']]);
    
    // Log adjustment
    $details = "Meter Reading Adjusted for {$tx['fuel_type']} - " .
               "Transaction: {$tx['transaction_id']} - " .
               "Liters: {$old_liters} L → {$actual_liters} L - " .
               "Variance: " . number_format($variance, 2) . " L - " .
               "Reason: {$adjustment_reason}";
    
    $pdo->prepare("INSERT INTO audit_logs 
                   (user_id, action_type, entity_type, entity_id, details, station_id, ip_address, created_at)
                   VALUES (?, 'Adjust', 'fuel_transaction', ?, ?, ?, ?, NOW())")
        ->execute([$me['id'], $tx_id, $details, $station_id, $_SERVER['REMOTE_ADDR'] ?? '']);
    
    $_SESSION['success'] = "Meter reading adjusted successfully. Variance: " . 
                           number_format($variance, 2) . " L";
}
```

---

## UI Implementation

### Tab Button (Add after "Adjustment History")
```html
<button class="adj-tab-btn" 
        onclick="switchAdjTab('adj-meter-readings',this)" 
        id="btn-adj-meter-readings"
        style="padding:9px 20px;border:none;background:none;font-weight:600;font-size:.85rem;color:#64748b;border-bottom:3px solid transparent;cursor:pointer;transition:all .2s;">
    <i class="fas fa-tachometer-alt"></i> Meter Reading Adjustments
    <?php if ($flagged_count > 0): ?>
    <span style="background:#dc2626;color:#fff;border-radius:10px;padding:1px 7px;font-size:.7rem;margin-left:4px;">
        <?php echo $flagged_count; ?>
    </span>
    <?php endif; ?>
</button>
```

### Tab Content Panel
```html
<div id="adj-meter-readings" class="adj-tab-panel" style="display:none;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
        
        <!-- Info Banner -->
        <div style="background:#fef3c7;border-bottom:1px solid #fcd34d;padding:12px 16px;display:flex;align-items:center;gap:10px;">
            <i class="fas fa-info-circle" style="color:#d97706;font-size:18px;"></i>
            <div style="font-size:.82rem;color:#92400e;">
                <strong>Meter Reading Adjustments:</strong> Review and correct staff-encoded fuel transaction readings with discrepancies.
            </div>
        </div>

        <!-- Table -->
        <div style="overflow-x:auto;padding:14px 16px;">
            <table class="data-table" style="margin-bottom:0;font-size:.82rem;">
                <thead>
                    <tr>
                        <th style="background:#002F70;color:#fff;">Transaction ID</th>
                        <th style="background:#002F70;color:#fff;">Fuel Type</th>
                        <th style="background:#002F70;color:#fff;">Pump</th>
                        <th style="background:#002F70;color:#fff;text-align:right;">Previous</th>
                        <th style="background:#002F70;color:#fff;text-align:right;">Present</th>
                        <th style="background:#002F70;color:#fff;text-align:right;">Calibration</th>
                        <th style="background:#002F70;color:#fff;text-align:right;">Liters (Computed)</th>
                        <th style="background:#002F70;color:#fff;text-align:right;">Actual Liters</th>
                        <th style="background:#002F70;color:#fff;text-align:right;">Variance</th>
                        <th style="background:#002F70;color:#fff;">Status</th>
                        <th style="background:#002F70;color:#fff;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($flagged_transactions as $ftx): 
                        $computed_liters = $ftx['liters_sold'];
                        $variance_display = '—';
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="font-size:.75rem;font-weight:600;color:#002F70;">
                            <?php echo htmlspecialchars($ftx['transaction_id']); ?>
                        </td>
                        <td>
                            <span style="background:#e8f4fd;color:#0056b3;padding:2px 7px;border-radius:6px;font-size:.72rem;font-weight:700;">
                                <?php echo htmlspecialchars($ftx['fuel_type']); ?>
                            </span>
                        </td>
                        <td style="color:#64748b;">
                            <?php echo $ftx['pump_number'] ? 'Pump #' . htmlspecialchars($ftx['pump_number']) : '—'; ?>
                        </td>
                        <td style="text-align:right;color:#334155;">
                            <?php echo number_format($ftx['previous_reading'], 2); ?>
                        </td>
                        <td style="text-align:right;color:#334155;font-weight:600;">
                            <?php echo number_format($ftx['present_reading'], 2); ?>
                        </td>
                        <td style="text-align:right;color:#64748b;">
                            <?php echo number_format($ftx['calibration'], 2); ?>
                        </td>
                        <td style="text-align:right;font-weight:700;color:#1e293b;">
                            <?php echo number_format($computed_liters, 2); ?> L
                        </td>
                        <td style="text-align:right;">
                            <input type="number" 
                                   id="actual_<?php echo $ftx['id']; ?>" 
                                   step="0.01" 
                                   min="0"
                                   value="<?php echo $computed_liters; ?>"
                                   onchange="calculateVariance(<?php echo $ftx['id']; ?>, <?php echo $computed_liters; ?>)"
                                   style="width:100px;padding:4px 7px;border:1px solid #cbd5e1;border-radius:5px;font-size:.82rem;text-align:right;font-weight:700;">
                        </td>
                        <td style="text-align:right;" id="variance_<?php echo $ftx['id']; ?>">
                            <span style="color:#94a3b8;">—</span>
                        </td>
                        <td>
                            <?php
                            $status = strtolower($ftx['status']);
                            if (strpos($status, 'flag') !== false) {
                                echo '<span style="color:#dc2626;font-weight:700;font-size:.72rem;">Flagged</span>';
                            } elseif ($status === 'cleared') {
                                echo '<span style="color:#16a34a;font-weight:700;font-size:.72rem;">Cleared</span>';
                            } else {
                                echo '<span style="color:#d97706;font-weight:700;font-size:.72rem;">Pending</span>';
                            }
                            ?>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" 
                                    onclick="adjustMeterReading(<?php echo $ftx['id']; ?>, '<?php echo htmlspecialchars($ftx['transaction_id'], ENT_QUOTES); ?>', <?php echo $computed_liters; ?>)"
                                    style="background:#002F70;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:.7rem;font-weight:700;cursor:pointer;">
                                <i class="fas fa-edit"></i> Adjust
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($flagged_transactions)): ?>
                    <tr>
                        <td colspan="11" style="text-align:center;padding:40px;color:#94a3b8;">
                            <i class="fas fa-check-circle" style="font-size:48px;margin-bottom:12px;opacity:.5;"></i>
                            <p style="margin:0;">Walay flagged transactions. All meter readings are accurate!</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
```

### Adjustment Modal
```html
<div id="meterReadingModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <h3>Adjust Meter Reading</h3>
            <span class="modal-close" onclick="closeMeterModal()">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="adjust_meter_reading">
            <input type="hidden" name="transaction_id" id="meter_tx_id">
            
            <div style="background:#f8fafc;padding:14px;border-radius:8px;margin-bottom:14px;">
                <div style="font-size:.82rem;color:#64748b;margin-bottom:8px;font-weight:600;">
                    Transaction: <span id="meter_display_id" style="color:#002F70;font-weight:700;"></span>
                </div>
                <div style="font-size:.82rem;color:#64748b;">
                    Computed Liters: <span id="meter_computed" style="color:#1e293b;font-weight:700;"></span> L
                </div>
            </div>
            
            <div class="form-group">
                <label>Actual Liters <span style="color:#dc2626;">*</span></label>
                <input type="number" name="actual_liters" id="meter_actual_input" 
                       step="0.01" min="0" required
                       placeholder="Enter corrected liters value..."
                       oninput="updateModalVariance()"
                       style="width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:.85rem;">
            </div>
            
            <div style="background:#eff6ff;padding:12px;border-radius:8px;margin-bottom:14px;border:1px solid #bfdbfe;">
                <div style="font-size:.82rem;color:#1e40af;font-weight:600;">
                    Variance: <span id="modal_variance_display" style="font-size:1.1rem;font-weight:800;">0.00 L</span>
                </div>
            </div>
            
            <div class="form-group">
                <label>Adjustment Reason <span style="color:#dc2626;">*</span></label>
                <textarea name="adjustment_reason" required minlength="10"
                          placeholder="Explain why this adjustment is needed (e.g., calibration test error, meter malfunction, staff encoding error...)..."
                          style="width:100%;min-height:100px;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:.85rem;resize:vertical;"></textarea>
            </div>
            
            <div style="background:#fef3c7;padding:10px;border-radius:6px;margin-bottom:14px;border-left:4px solid #d97706;">
                <div style="font-size:.75rem;color:#92400e;">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> This will update inventory levels and create an audit log entry.
                </div>
            </div>
            
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="closeMeterModal()"
                        style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#002F70;color:#fff;padding:8px 16px;border-radius:6px;border:none;cursor:pointer;font-weight:700;">
                    <i class="fas fa-save"></i> Save Adjustment
                </button>
            </div>
        </form>
    </div>
</div>
```

### JavaScript Functions
```javascript
function calculateVariance(txId, computedLiters) {
    const actualInput = document.getElementById(`actual_${txId}`);
    const varianceCell = document.getElementById(`variance_${txId}`);
    
    const actualLiters = parseFloat(actualInput.value) || 0;
    const variance = actualLiters - computedLiters;
    
    let varianceHtml = '';
    if (Math.abs(variance) < 0.01) {
        varianceHtml = '<span style="color:#94a3b8;">—</span>';
    } else if (variance > 0) {
        varianceHtml = `<span style="color:#16a34a;font-weight:700;">+${variance.toFixed(2)} L</span>`;
    } else {
        varianceHtml = `<span style="color:#dc2626;font-weight:700;">${variance.toFixed(2)} L</span>`;
    }
    
    varianceCell.innerHTML = varianceHtml;
}

function adjustMeterReading(txId, txDisplayId, computedLiters) {
    document.getElementById('meter_tx_id').value = txId;
    document.getElementById('meter_display_id').textContent = txDisplayId;
    document.getElementById('meter_computed').textContent = computedLiters.toFixed(2);
    document.getElementById('meter_actual_input').value = computedLiters.toFixed(2);
    document.getElementById('modal_variance_display').textContent = '0.00 L';
    document.getElementById('meterReadingModal').style.display = 'block';
}

function updateModalVariance() {
    const computedLiters = parseFloat(document.getElementById('meter_computed').textContent);
    const actualLiters = parseFloat(document.getElementById('meter_actual_input').value) || 0;
    const variance = actualLiters - computedLiters;
    
    const varianceSpan = document.getElementById('modal_variance_display');
    varianceSpan.textContent = (variance >= 0 ? '+' : '') + variance.toFixed(2) + ' L';
    
    if (variance > 0) {
        varianceSpan.style.color = '#16a34a';
    } else if (variance < 0) {
        varianceSpan.style.color = '#dc2626';
    } else {
        varianceSpan.style.color = '#64748b';
    }
}

function closeMeterModal() {
    document.getElementById('meterReadingModal').style.display = 'none';
}
```

---

## Implementation Checklist

- [ ] Add SQL query to fetch flagged transactions (~line 1200)
- [ ] Add POST action handler `adjust_meter_reading` (~line 250)
- [ ] Add 4th tab button in HTML (~line 1246)
- [ ] Add tab content panel with table (~line 1390)
- [ ] Add adjustment modal HTML (~line 1650)
- [ ] Add JavaScript functions for variance calculation
- [ ] Test with sample flagged transactions
- [ ] Verify inventory updates correctly
- [ ] Check audit log entries
- [ ] Test status transitions (Flagged → Cleared)

---

**File to Modify:** `public/manager_fuel_adjustments.php`
**Estimated Lines to Add:** ~350 lines
**Testing Priority:** HIGH (affects inventory and financials)

---

**Document Created:** June 10, 2026
**Status:** Ready for Implementation
