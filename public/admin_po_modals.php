<?php // ─── Modals + JS for Purchase Orders Oversight ────────────────────────────────── ?>

<!-- FINALIZE MODAL -->
<div id="finalizeModal" class="po-modal-ov">
  <div class="po-modal-box" style="width:800px; max-width:95vw;">
    <h3 id="finModalTitle"><i class="fas fa-file-signature"></i> Finalize Purchase Order</h3>
    <form method="POST" id="finalizeForm">
      <input type="hidden" name="action" value="finalize_batch">
      <input type="hidden" id="modalPoType" name="po_type" value="">
      <input type="hidden" id="modalPoDate" name="po_date" value="">
      
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px; text-align:left;">
        
        <!-- LEFT COLUMN: PO info & Schedule & Payment -->
        <div>
          <!-- Purchase Order Info Card -->
          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:12px;">
            <h4 style="margin-top:0; margin-bottom:8px; font-size:12px; color:#0f172a; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:4px;"><i class="fas fa-file-invoice"></i> Purchase Order Info</h4>
            
            <div class="po-form-grp" style="margin-bottom:8px;">
              <label style="font-weight:600; font-size:11px;">PO Number (Auto-generated)</label>
              <input type="text" id="modalBatchId" name="batch_id_override" required style="font-family:monospace; font-weight:700; width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px;">
            </div>
            
            <div style="font-size:11px; color:#334155; line-height:1.6;">
              <div><strong>Supplier:</strong> <span id="lblSupplier">Petron Corporation</span></div>
              <div><strong>Purchase Date:</strong> <span id="lblPurchaseDate">June 26, 2026</span></div>
              <div><strong>Created By:</strong> <span><?php echo htmlspecialchars($me['name'] ?? 'Kathrine Pepito'); ?> (Admin)</span></div>
              <div><strong>Status:</strong> <span id="lblStatus" style="font-weight:600; color:#fd7e14;">Pending</span></div>
            </div>
          </div>
          
          <!-- Delivery Schedule Card -->
          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:12px;">
            <h4 style="margin-top:0; margin-bottom:8px; font-size:12px; color:#0f172a; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:4px;"><i class="fas fa-truck"></i> Delivery Schedule</h4>
            
            <div class="po-form-grp" style="margin-bottom:8px;">
              <label style="font-weight:600; font-size:11px;">Expected Delivery Date <span style="color:#dc2626;">*</span></label>
              <input type="date" name="expected_delivery_date" required min="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px;">
            </div>
            
            <div class="po-form-grp">
              <label style="font-weight:600; font-size:11px;">Expected Delivery Time <span style="color:#dc2626;">*</span></label>
              <select name="expected_delivery_time" required style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px;">
                <option value="09:00">09:00 AM</option>
                <option value="14:00">02:00 PM</option>
              </select>
            </div>
          </div>

          <!-- Payment Terms Card -->
          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px;">
            <h4 style="margin-top:0; margin-bottom:8px; font-size:12px; color:#0f172a; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:4px;"><i class="fas fa-credit-card"></i> Payment Terms</h4>
            <div class="po-form-grp">
              <select name="payment_terms" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px;">
                <option value="30 Days">30 Days (Net 30)</option>
                <option value="Cash">Cash</option>
                <option value="Credit">Credit</option>
                <option value="COD">COD</option>
              </select>
            </div>
          </div>
        </div>

        <!-- RIGHT COLUMN: Location & Personnel & Instructions -->
        <div>
          <!-- Delivery Location Card -->
          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:12px;">
            <h4 style="margin-top:0; margin-bottom:8px; font-size:12px; color:#0f172a; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:4px;"><i class="fas fa-map-marker-alt"></i> Delivery Location</h4>
            <div class="po-form-grp">
              <textarea readonly style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:11px; background:#e2e8f0; font-family:monospace; resize:none;" rows="3"><?php echo htmlspecialchars($station_name . "\n" . $station_address); ?></textarea>
            </div>
          </div>

          <!-- Receiving Personnel Card -->
          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:12px;">
            <h4 style="margin-top:0; margin-bottom:8px; font-size:12px; color:#0f172a; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:4px;"><i class="fas fa-user-check"></i> Receiving Personnel</h4>
            <div class="po-form-grp">
              <select name="receiving_personnel_select" onchange="toggleSpecificStaff(this)" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; margin-bottom:6px;">
                <option value="Any Assigned Staff">Any Assigned Staff</option>
                <option value="Specific Staff">Specific Staff...</option>
              </select>
              <input type="text" id="receiving_personnel_custom" name="receiving_personnel" value="Any Assigned Staff" placeholder="Enter specific staff name..." style="display:none; width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px;">
            </div>
          </div>

          <!-- Delivery Instructions Card -->
          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:12px;">
            <h4 style="margin-top:0; margin-bottom:8px; font-size:12px; color:#0f172a; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:4px;"><i class="fas fa-clipboard-list"></i> Delivery Instructions</h4>
            <div class="po-form-grp">
              <select onchange="addInstruction(this)" style="width:100%; padding:4px; border:1px solid #cbd5e1; border-radius:4px; font-size:11px; margin-bottom:6px; background:#fff;">
                <option value="">-- Add Quick Instruction --</option>
                <option value="Deliver to Warehouse A.">Deliver to Warehouse A.</option>
                <option value="Contact station before arrival.">Contact station before arrival.</option>
                <option value="Unload at receiving area.">Unload at receiving area.</option>
                <option value="Deliver all items in one shipment.">Deliver all items in one shipment.</option>
              </select>
              <textarea id="modalDeliveryInstructions" name="delivery_instructions" rows="2" placeholder="Enter detailed delivery instructions..." style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:11px;"></textarea>
            </div>
          </div>

          <!-- Remarks Card -->
          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px;">
            <h4 style="margin-top:0; margin-bottom:8px; font-size:12px; color:#0f172a; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:4px;"><i class="fas fa-comment"></i> Remarks</h4>
            <div class="po-form-grp">
              <textarea name="remarks" rows="2" placeholder="Optional remarks..." style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:11px;"></textarea>
            </div>
          </div>

        </div>
      </div>
      
      <!-- ITEMS TABLE -->
      <div style="max-height:180px; overflow-y:auto; margin-bottom:14px; border:1px solid #dee2e6; border-radius:6px;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead style="background:#f1f5f9; position:sticky; top:0;">
            <tr>
              <th style="padding:9px 8px; text-align:left; border-bottom:1px solid #dee2e6;">#</th>
              <th style="padding:9px 8px; text-align:left; border-bottom:1px solid #dee2e6;">Product</th>
              <th style="padding:9px 8px; text-align:center; width:90px; border-bottom:1px solid #dee2e6;">Qty</th>
              <th style="padding:9px 8px; text-align:right; width:110px; border-bottom:1px solid #dee2e6;">Unit Price (₱)</th>
              <th style="padding:9px 8px; text-align:right; width:110px; border-bottom:1px solid #dee2e6;">Total (₱)</th>
            </tr>
          </thead>
          <tbody id="modalItemsBody">
            <tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading items...</td></tr>
          </tbody>
        </table>
      </div>
      
      <!-- ACTION BUTTONS -->
      <div class="po-modal-footer" style="display:flex; justify-content:flex-end; gap:8px; align-items:center;">
        <input type="hidden" id="submitActionInput" name="submit_action" value="finalize_po">
        
        <!-- Cancel -->
        <button type="button" class="po-ctrl-btn po-btn-back" onclick="document.getElementById('finalizeModal').classList.remove('show')">Cancel</button>
        
        <!-- Finalize -->
        <button type="submit" class="po-ctrl-btn po-btn-fin" style="padding:8px 16px;" onclick="setSubmitAction('finalize_po')">
          <i class="fas fa-check-circle"></i> Finalize Purchase Order
        </button>
      </div>
    </form>
  </div>
</div>

<!-- REJECT MODAL -->
<div id="rejectModal" class="po-modal-ov">
  <div class="po-modal-box" style="width:460px;">
    <h3><i class="fas fa-times-circle"></i> Reject Purchase Order</h3>
    <form method="POST">
      <input type="hidden" name="action" value="reject_batch">
      <input type="hidden" id="rejectPoType" name="po_type" value="">
      <input type="hidden" id="rejectPoDate" name="po_date" value="">
      <div class="po-info-box" style="border-left-color:#dc2626; background:#fef2f2; color:#7f1d1d;">
        <i class="fas fa-exclamation-triangle"></i> All pending items for this date group will be rejected.
      </div>
      <div class="po-form-grp"><label>Reason for Rejection <span style="color:#dc2626">*</span></label><textarea name="reject_reason" required rows="3" placeholder="Enter reason..."></textarea></div>
      <div class="po-modal-footer">
        <button type="button" class="po-ctrl-btn po-btn-back" onclick="document.getElementById('rejectModal').classList.remove('show')">Cancel</button>
        <button type="submit" class="po-ctrl-btn po-btn-rej"><i class="fas fa-times"></i> Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<script>
var _fmt = function(n){ return parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); };
function escPO(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function setSubmitAction(act) {
    document.getElementById('submitActionInput').value = act;
}

function toggleSpecificStaff(sel) {
    var customInput = document.getElementById('receiving_personnel_custom');
    if (sel.value === 'Specific Staff') {
        customInput.style.display = 'block';
        customInput.value = '';
        customInput.required = true;
    } else {
        customInput.style.display = 'none';
        customInput.value = sel.value;
        customInput.required = false;
    }
}

function addInstruction(sel) {
    if (sel.value === '') return;
    var ta = document.getElementById('modalDeliveryInstructions');
    if (ta.value !== '') {
        ta.value += "\n" + sel.value;
    } else {
        ta.value = sel.value;
    }
    sel.value = ''; // reset select
}

// ── Finalize Modal ─────────────────────────────────────────────────────────────
function recalcRowTotal(id){
    var q = parseFloat(document.querySelector('[name="items['+id+'][qty]"]').value) || 0;
    var p = parseFloat(document.querySelector('[name="items['+id+'][price]"]').value) || 0;
    document.getElementById('rt-'+id).textContent = '₱' + (q*p).toLocaleString('en-US',{minimumFractionDigits:2});
}

function openFinalizeSingle(date, type, po) {
    document.getElementById('modalPoType').value = type;
    document.getElementById('modalPoDate').value = date;
    document.getElementById('finModalTitle').innerHTML =
        '<i class="fas fa-file-signature"></i> Finalize PO &mdash; <span style="font-family:monospace;">' + escPO(po.po_no) + '</span>';

    // Populate labels
    document.getElementById('lblSupplier').textContent = po.supplier || 'Petron Corporation';
    document.getElementById('lblPurchaseDate').textContent = po.date_created || 'Today';
    document.getElementById('lblStatus').textContent = po.status || 'Pending';

    // Set default expected date (+3 days)
    var defaultDate = new Date();
    defaultDate.setDate(defaultDate.getDate() + 3);
    var yyyy = defaultDate.getFullYear();
    var mm = String(defaultDate.getMonth() + 1).padStart(2, '0');
    var dd = String(defaultDate.getDate()).padStart(2, '0');
    document.querySelector('[name="expected_delivery_date"]').value = yyyy + '-' + mm + '-' + dd;

    // Reset instruction fields and customs
    document.getElementById('modalDeliveryInstructions').value = "Deliver all items in one shipment. Contact station before arrival.";
    document.getElementById('receiving_personnel_custom').value = "Any Assigned Staff";
    document.getElementById('receiving_personnel_custom').style.display = 'none';
    document.querySelector('[name="receiving_personnel_select"]').value = "Any Assigned Staff";
    document.querySelector('[name="payment_terms"]').value = "30 Days";
    document.querySelector('[name="remarks"]').value = "";

    // Suggest a proper batch ID
    var today = new Date();
    var ddStr = String(today.getFullYear())
           + String(today.getMonth()+1).padStart(2,'0')
           + String(today.getDate()).padStart(2,'0');
    var prefix = (type === 'fuel') ? 'POF-' : 'POM-';
    document.getElementById('modalBatchId').value = po.po_no && po.po_no.length > 10 ? po.po_no : (prefix + ddStr + '-001');

    // Load all pending items for this date/type
    var tbody = document.getElementById('modalItemsBody');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading items...</td></tr>';

    fetch('admin_po_ajax.php?action=get_pending_items&type=' + encodeURIComponent(type) + '&date=' + encodeURIComponent(date))
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data.items || data.items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8;">No pending items found for this date.</td></tr>';
                return;
            }
            var html = '';
            data.items.forEach(function(item, idx) {
                var qty   = parseFloat(item.quantity || 0);
                var price = parseFloat(item.unit_price || 0);
                var total = (qty * price).toFixed(2);
                html += '<tr>'
                    + '<td style="padding:8px;color:#94a3b8;">' + (idx+1) + '</td>'
                    + '<td style="padding:8px;font-weight:600;">' + escPO(item.product_name || '—') + '</td>'
                    + '<td style="padding:8px;text-align:center;">'
                    +   '<input type="number" step="any" name="items['+item.id+'][qty]" value="'+qty+'"'
                    +   ' style="width:75px;text-align:center;padding:4px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;"'
                    +   ' oninput="recalcRowTotal('+item.id+')">'
                    + '</td>'
                    + '<td style="padding:8px;text-align:right;">'
                    +   '<input type="number" step="any" name="items['+item.id+'][price]" value="'+price+'"'
                    +   ' style="width:95px;text-align:right;padding:4px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;"'
                    +   ' oninput="recalcRowTotal('+item.id+')">'
                    + '</td>'
                    + '<td style="padding:8px;text-align:right;font-weight:700;" id="rt-'+item.id+'">₱'
                    +   parseFloat(total).toLocaleString('en-US',{minimumFractionDigits:2})
                    + '</td>'
                    + '</tr>';
            });
            tbody.innerHTML = html;
        })
        .catch(function(){
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#dc3545;">Error loading items.</td></tr>';
        });

    document.getElementById('finalizeModal').classList.add('show');
}

// ── Reject Modal ───────────────────────────────────────────────────────────────
function openReject(type, date){
    document.getElementById('rejectPoType').value = type;
    document.getElementById('rejectPoDate').value = date;
    document.getElementById('rejectModal').classList.add('show');
}

// Auto-dismiss flash messages
setTimeout(function(){
    document.querySelectorAll('.flash-ok,.flash-err').forEach(function(el){ el.style.display='none'; });
}, 5000);

// Pagination for both tables
if (typeof setupTablePagination === 'function') {
    setupTablePagination('poTableMerch', null, 'poPaginationMerch', 15);
    setupTablePagination('poTableFuel',  null, 'poPaginationFuel',  15);
}
</script>

<div id="poPaginationMerch" style="padding:6px 0;"></div>
<div id="poPaginationFuel"  style="padding:6px 0;"></div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
