<?php
$filepath = 'c:/xampp/htdocs/group31petron_system_official4/public/manager_stock_request_review.php';
$content = file_get_contents($filepath);
$content = str_replace("\r\n", "\n", $content);

// Find location of returnPrModal
$pos = strpos($content, '<!-- Return Request Modal -->');
if ($pos === false) {
    echo "ERROR: returnPrModal marker not found!\n";
    exit;
}

// Keep everything before returnPrModal
$top_content = substr($content, 0, $pos);

$bottom_content = <<<'HTML'
    <!-- Return Request Modal -->
    <div class="modal-overlay" id="returnPrModal" style="z-index: 10030;">
        <div class="modal-box" style="max-width: 480px;">
            <div class="modal-header" style="background: #b91c1c;">
                <h3 class="modal-title"><i class="fas fa-undo"></i> Return Purchase Request</h3>
            </div>
            <form method="POST" action="" id="returnPrForm">
                <input type="hidden" name="action" value="return_pr_to_staff">
                <input type="hidden" name="pr_number" id="returnPrNumber" value="">
                <input type="hidden" name="pr_type" id="returnPrType" value="">
                <input type="hidden" name="request_ids" id="returnRequestIds" value="">
                <div class="modal-body">
                    <p style="font-size:13.5px; color:#475569; margin:0 0 14px 0;">Specify the reason for returning this request to staff for correction.</p>
                    <div class="field-group">
                        <label>Reason / Notes <span style="color:#dc2626;">*</span></label>
                        <textarea name="return_reason" id="returnPrReason" rows="4" required placeholder="e.g. Incorrect quantity, wrong product listed..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-pr btn-outline-pr" onclick="closeModal('returnPrModal')">Cancel</button>
                    <button type="submit" class="btn-pr" style="background:#b91c1c !important; color:#fff !important; border:none !important;"><i class="fas fa-paper-plane"></i> Return to Staff</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var currentPoItemForModal = null;

function switchMainTab(tab) {
    var prBtn = document.getElementById('mainTabPrBtn');
    var histBtn = document.getElementById('mainTabHistoryBtn');
    var prSec = document.getElementById('mainPrSection');
    var histSec = document.getElementById('mainHistorySection');
    var prCards = document.getElementById('prSummaryCardsGrid');
    var histCards = document.getElementById('historySummaryCardsGrid');

    if (tab === 'history') {
        if (histBtn) {
            histBtn.style.setProperty('color', '#ffffff', 'important');
            histBtn.style.setProperty('background-color', '#002F6C', 'important');
            histBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
        }
        if (prBtn) {
            prBtn.style.setProperty('color', '#475569', 'important');
            prBtn.style.setProperty('background-color', '#f8fafc', 'important');
            prBtn.style.setProperty('border', '1.5px solid #cbd5e1', 'important');
        }
        if (prSec) prSec.style.display = 'none';
        if (histSec) histSec.style.display = 'block';
        if (prCards) prCards.style.display = 'none';
        if (histCards) histCards.style.display = 'grid';
        filterPurchaseHistory();
    } else {
        if (prBtn) {
            prBtn.style.setProperty('color', '#ffffff', 'important');
            prBtn.style.setProperty('background-color', '#002F6C', 'important');
            prBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
        }
        if (histBtn) {
            histBtn.style.setProperty('color', '#475569', 'important');
            histBtn.style.setProperty('background-color', '#f8fafc', 'important');
            histBtn.style.setProperty('border', '1.5px solid #cbd5e1', 'important');
        }
        if (prSec) prSec.style.display = 'block';
        if (histSec) histSec.style.display = 'none';
        if (prCards) prCards.style.display = 'grid';
        if (histCards) histCards.style.display = 'none';
    }

    try {
        var url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
        localStorage.setItem('pr_review_active_maintab', tab);
    } catch(e) {}
}

function switchPendingCategory(type) {
    var merchBtn = document.getElementById('subtabMerchBtn');
    var fuelBtn  = document.getElementById('subtabFuelBtn');
    var merchSec = document.getElementById('pendingMerchSection');
    var fuelSec  = document.getElementById('pendingFuelSection');

    if (type === 'fuel') {
        if (fuelBtn) {
            fuelBtn.style.setProperty('color', '#002F6C', 'important');
            fuelBtn.style.setProperty('background-color', '#eff6ff', 'important');
            fuelBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
        }
        if (merchBtn) {
            merchBtn.style.setProperty('color', '#64748b', 'important');
            merchBtn.style.setProperty('background-color', '#fff', 'important');
            merchBtn.style.setProperty('border', '1.5px solid #e2e8f0', 'important');
        }
        if (fuelSec)  fuelSec.style.display  = 'block';
        if (merchSec) merchSec.style.display  = 'none';
    } else {
        if (merchBtn) {
            merchBtn.style.setProperty('color', '#002F6C', 'important');
            merchBtn.style.setProperty('background-color', '#eff6ff', 'important');
            merchBtn.style.setProperty('border', '1.5px solid #002F6C', 'important');
        }
        if (fuelBtn) {
            fuelBtn.style.setProperty('color', '#64748b', 'important');
            fuelBtn.style.setProperty('background-color', '#fff', 'important');
            fuelBtn.style.setProperty('border', '1.5px solid #e2e8f0', 'important');
        }
        if (merchSec) merchSec.style.display = 'block';
        if (fuelSec)  fuelSec.style.display  = 'none';
    }

    try { localStorage.setItem('pr_review_active_category', type); } catch(e) {}
}

function switchPendingSubTab(type) {
    if (type === 'history') switchMainTab('history');
    else switchPendingCategory(type);
}

document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var tabParam = urlParams.get('tab');
    var savedMainTab = tabParam || localStorage.getItem('pr_review_active_maintab') || 'pr';
    switchMainTab(savedMainTab);

    var savedCat = localStorage.getItem('pr_review_active_category') || 'merch';
    switchPendingCategory(savedCat);
});

// Inline accordion toggle
var _openPrKey = null;
function toggleInlinePr(key) {
    var detailRow = document.getElementById('detail_' + key);
    var headerRow = document.getElementById('row_' + key);
    if (!detailRow) return;

    if (detailRow.classList.contains('open')) {
        detailRow.classList.remove('open');
        headerRow.classList.remove('expanded');
        _openPrKey = null;
        return;
    }

    if (_openPrKey && _openPrKey !== key) {
        var prev = document.getElementById('detail_' + _openPrKey);
        var prevRow = document.getElementById('row_' + _openPrKey);
        if (prev) prev.classList.remove('open');
        if (prevRow) prevRow.classList.remove('expanded');
    }
    
    detailRow.classList.add('open');
    headerRow.classList.add('expanded');
    _openPrKey = key;
}

// Modal helpers
function openReturnPrModal(prNo, type, reqIds) {
    document.getElementById('returnPrNumber').value = prNo;
    document.getElementById('returnPrType').value   = type;
    document.getElementById('returnRequestIds').value = reqIds || '';
    document.getElementById('returnPrReason').value = '';
    openModal('returnPrModal');
}

function openModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.remove('open');
        document.body.style.overflow = '';
    }
}

function formatMoney(value) {
    return 'PHP ' + Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function updateMerchSummary(key) {
    var form = document.getElementById('form_' + key);
    if (!form) return;
    var qtyInputs = form.querySelectorAll('.merch-qty-input');
    var totalQty = 0;
    var grandTotal = 0;

    qtyInputs.forEach(function(input) {
        var qty = parseFloat(input.value) || 0;
        var cost = parseFloat(input.dataset.unitCost) || 0;
        var rowTotal = qty * cost;
        totalQty += qty;
        grandTotal += rowTotal;
        var rowTotalEl = document.getElementById('total_' + input.dataset.itemId);
        if (rowTotalEl) {
            rowTotalEl.textContent = formatMoney(rowTotal);
        }
    });

    var countEl = document.getElementById('summary_count_' + key);
    var totalEl = document.getElementById('summary_total_' + key);
    if (countEl) countEl.textContent = totalQty;
    if (totalEl) totalEl.textContent = formatMoney(grandTotal);
}

function updateFuelSummary(key) {
    var form = document.getElementById('form_' + key);
    if (!form) return;
    var litersInput = form.querySelector('.fuel-liters-input');
    var liters = parseFloat(litersInput ? litersInput.value : 0) || 0;
    var cost = parseFloat(litersInput ? litersInput.dataset.costPerLiter : 0) || 0;
    var total = liters * cost;

    var calcEl = document.getElementById('calc_total_' + key);
    var summaryEl = document.getElementById('summary_total_' + key);
    if (calcEl) calcEl.textContent = formatMoney(total);
    if (summaryEl) summaryEl.textContent = formatMoney(total);
}

function filterPurchaseHistory() {
    var search = (document.getElementById('histSearchPo')?.value || '').toLowerCase().trim();
    var cat = (document.getElementById('histCategoryFilter')?.value || '').toLowerCase();
    var supp = (document.getElementById('histSupplierFilter')?.value || '').toLowerCase();
    var start = document.getElementById('histStartDate')?.value || '';
    var end = document.getElementById('histEndDate')?.value || '';
    var status = (document.getElementById('histStatusFilter')?.value || '').toLowerCase();

    var rows = document.querySelectorAll('#purchaseHistoryTbody tr');
    rows.forEach(function(r) {
        if (!r.getAttribute('data-po')) return;
        var po = (r.getAttribute('data-po') || '').toLowerCase();
        var rCat = (r.getAttribute('data-category') || '').toLowerCase();
        var rSupp = (r.getAttribute('data-supplier') || '').toLowerCase();
        var rStatus = (r.getAttribute('data-status') || '').toLowerCase();
        var rDate = r.getAttribute('data-date') || '';
        var text = r.innerText.toLowerCase();

        var show = true;
        if (search && !text.includes(search)) show = false;
        if (cat && rCat !== cat) show = false;
        if (supp && !rSupp.includes(supp)) show = false;
        if (status && !rStatus.includes(status)) show = false;
        if (start && rDate < start) show = false;
        if (end && rDate > end) show = false;

        r.style.display = show ? '' : 'none';
    });
}

function resetHistoryFilter() {
    ['histSearchPo', 'histCategoryFilter', 'histSupplierFilter', 'histStartDate', 'histEndDate', 'histStatusFilter'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    filterPurchaseHistory();
}

function openPurchaseHistoryModal(item) {
    currentPoItemForModal = item;
    
    document.getElementById('modalPoTitle').innerText = 'Purchase History - ' + (item.po_number || 'N/A');
    document.getElementById('mPoNo').innerText = item.po_number || 'N/A';
    document.getElementById('mCategory').innerHTML = item.category_type === 'fuel' ? '⛽ Fuel' : '📦 Merchandise';
    document.getElementById('mSupplier').innerText = item.supplier_name || 'Petron Corporation';
    document.getElementById('mRequestedBy').innerText = item.requested_by_name || 'Manager';
    document.getElementById('mApprovedBy').innerText = item.approved_by_name || 'Admin';
    document.getElementById('mDateOrdered').innerText = item.date_ordered ? item.date_ordered.substring(0, 10) : '—';
    document.getElementById('mDateReceived').innerText = item.date_received && item.date_received !== '0000-00-00 00:00:00' ? item.date_received.substring(0, 10) : '—';
    document.getElementById('mStatus').innerHTML = '<span class="status-badge status-approved">' + (item.status || 'Completed') + '</span>';

    // Delivery Info
    document.getElementById('mDrNo').innerText = item.dr_number || 'N/A';
    document.getElementById('mInvoiceNo').innerText = item.sales_invoice_no || 'N/A';
    document.getElementById('mReceivedBy').innerText = item.received_by_name || 'Staff';
    document.getElementById('mDeliveryDate').innerText = item.delivery_date || item.date_received || '—';

    // Items table
    var container = document.getElementById('modalItemsTableContainer');
    var html = '';

    if (item.category_type === 'merchandise') {
        html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        html += '<thead><tr style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; color:#475569; font-size:11px; text-transform:uppercase;">';
        html += '<th style="padding:10px 12px; text-align:left;">SKU</th>';
        html += '<th style="padding:10px 12px; text-align:left;">Product</th>';
        html += '<th style="padding:10px 12px; text-align:center;">Qty</th>';
        html += '<th style="padding:10px 12px; text-align:center;">UOM</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Unit Cost</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Total</th>';
        html += '</tr></thead><tbody>';

        if (item.items && item.items.length > 0) {
            item.items.forEach(function(it) {
                var uCost = parseFloat(it.unit_price || 0);
                var tCost = parseFloat(it.total_price || (uCost * (it.quantity || 1)));
                html += '<tr style="border-bottom:1px solid #f1f5f9;">';
                html += '<td style="padding:10px 12px; font-family:monospace; color:#002F6C; font-weight:700;">' + (it.sku || 'N/A') + '</td>';
                html += '<td style="padding:10px 12px; font-weight:600; color:#334155;">' + (it.product_name || 'Item') + '</td>';
                html += '<td style="padding:10px 12px; text-align:center; font-weight:700;">' + (it.quantity || 1) + '</td>';
                html += '<td style="padding:10px 12px; text-align:center; color:#64748b;">' + (it.unit || 'pcs') + '</td>';
                html += '<td style="padding:10px 12px; text-align:right;">₱' + uCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '<td style="padding:10px 12px; text-align:right; font-weight:700; color:#002F6C;">₱' + tCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '</tr>';
            });
        } else {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#94a3b8;">No items detailed</td></tr>';
        }
        html += '</tbody></table>';
    } else {
        // Fuel
        html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
        html += '<thead><tr style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; color:#475569; font-size:11px; text-transform:uppercase;">';
        html += '<th style="padding:10px 12px; text-align:left;">Fuel Type</th>';
        html += '<th style="padding:10px 12px; text-align:center;">Liters</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Cost/Liter</th>';
        html += '<th style="padding:10px 12px; text-align:right;">Total</th>';
        html += '</tr></thead><tbody>';

        if (item.items && item.items.length > 0) {
            item.items.forEach(function(it) {
                var cPerL = parseFloat(it.cost_per_liter || 0);
                var ltrs = parseFloat(it.liters || 0);
                var tCost = parseFloat(it.total_price || (cPerL * ltrs));
                html += '<tr style="border-bottom:1px solid #f1f5f9;">';
                html += '<td style="padding:10px 12px; font-weight:700; color:#002F6C;">' + (it.fuel_type || 'Fuel') + '</td>';
                html += '<td style="padding:10px 12px; text-align:center; font-weight:700;">' + ltrs.toLocaleString() + ' L</td>';
                html += '<td style="padding:10px 12px; text-align:right;">₱' + cPerL.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '<td style="padding:10px 12px; text-align:right; font-weight:700; color:#002F6C;">₱' + tCost.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>';
                html += '</tr>';
            });
        }
        html += '</tbody></table>';
    }

    container.innerHTML = html;

    openModal('viewPurchaseHistoryModal');
}

function closePurchaseHistoryModal() {
    closeModal('viewPurchaseHistoryModal');
}

function printModalPO() {
    if (!currentPoItemForModal) return;
    window.open('print_po_new.php?po_id=' + encodeURIComponent(currentPoItemForModal.po_number) + '&type=' + encodeURIComponent(currentPoItemForModal.category_type), '_blank');
}

function printModalInvoice() {
    if (!currentPoItemForModal) return;
    window.open('print_supplier_invoice.php?po_id=' + encodeURIComponent(currentPoItemForModal.po_number) + '&type=' + encodeURIComponent(currentPoItemForModal.category_type), '_blank');
}
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
HTML;

file_put_contents($filepath, $top_content . $bottom_content);
echo "Cleanly restored manager_stock_request_review.php bottom HTML & JS!\n";
