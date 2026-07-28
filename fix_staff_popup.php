<?php
$filepath = 'c:/xampp/htdocs/group31petron_system_official4/public/staff_inventory_merchandise.php';
$content = file_get_contents($filepath);
$content = str_replace("\r\n", "\n", $content);

// 1. Update Popup HTML to give IDs to icon, title, status row
$old_popup = <<<'HTML'
<!-- ── Success popup ── -->
<div class="sr-success-overlay" id="srSuccessOverlay"></div>
<div class="sr-success-popup" id="srSuccessPopup">
    <div style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" style="color:#fff;font-size:22px;"></i>
    </div>
    <h3 style="margin:0 0 6px;color:#28a745;">Request Submitted!</h3>
    <p style="margin:0 0 6px;color:#333;font-size:13px;" id="srSuccessMsg">Your stock request is now <strong>Pending</strong> Manager review.</p>
    <p style="margin:0 0 18px;font-size:12px;color:#6c757d;">Status: <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-weight:700;">PENDING</span></p>
    <button onclick="closeSrSuccess()" class="txn-btn primary">OK</button>
</div>
HTML;

$new_popup = <<<'HTML'
<!-- ── Success popup ── -->
<div class="sr-success-overlay" id="srSuccessOverlay"></div>
<div class="sr-success-popup" id="srSuccessPopup">
    <div id="srPopupIcon" style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" id="srPopupIconI" style="color:#fff;font-size:22px;"></i>
    </div>
    <h3 id="srPopupTitle" style="margin:0 0 6px;color:#28a745;">REQUEST SUBMITTED!</h3>
    <p style="margin:0 0 6px;color:#333;font-size:13px;" id="srSuccessMsg">Your stock request is now <strong>Pending</strong> Manager review.</p>
    <p id="srPopupStatusRow" style="margin:0 0 18px;font-size:12px;color:#6c757d;">Status: <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-weight:700;">PENDING</span></p>
    <button onclick="closeSrSuccess()" class="txn-btn primary">OK</button>
</div>
HTML;

$content = str_replace($old_popup, $new_popup, $content);

// 2. Update srHandleSubmit response logic
$old_js = <<<'JS'
        if (res.success) {
            var srNo = res.request_no || '';
            var cnt  = res.inserted_count || items.length;
            var msg  = 'Successfully submitted stock requests for <strong>' + cnt + '</strong> item' + (cnt !== 1 ? 's' : '') + '.';
            if (srNo) msg += '<br><span style="font-size:12px;color:#64748b;">Request No: <strong>' + escHtml(srNo) + '</strong></span>';
            if (res.message && res.message.indexOf('skipped') !== -1) {
                msg += '<br><small style="color:#d97706;">' + escHtml(res.message.split('Note:')[1] || '') + '</small>';
            }
            document.getElementById('srSuccessMsg').innerHTML = msg;
        } else {
            document.getElementById('srSuccessMsg').innerHTML =
                '<span style="color:#dc2626;">' + escHtml(res.message || 'Submission failed. Please try again.') + '</span>';
        }
JS;

$new_js = <<<'JS'
        var popupIcon = document.getElementById('srPopupIcon');
        var popupTitle = document.getElementById('srPopupTitle');
        var popupStatus = document.getElementById('srPopupStatusRow');

        if (res.success) {
            if (popupIcon) {
                popupIcon.style.background = 'linear-gradient(135deg,#28a745,#20c997)';
                popupIcon.innerHTML = '<i class="fas fa-check" style="color:#fff;font-size:22px;"></i>';
            }
            if (popupTitle) {
                popupTitle.style.color = '#28a745';
                popupTitle.innerText = 'REQUEST SUBMITTED!';
            }
            if (popupStatus) popupStatus.style.display = 'block';

            var srNo = res.request_no || '';
            var cnt  = res.inserted_count || items.length;
            var msg  = 'Successfully submitted stock requests for <strong>' + cnt + '</strong> item' + (cnt !== 1 ? 's' : '') + '.';
            if (srNo) msg += '<br><span style="font-size:12px;color:#64748b;">Request No: <strong>' + escHtml(srNo) + '</strong></span>';
            if (res.message && res.message.indexOf('skipped') !== -1) {
                msg += '<br><small style="color:#d97706;">' + escHtml(res.message.split('Note:')[1] || '') + '</small>';
            }
            document.getElementById('srSuccessMsg').innerHTML = msg;
        } else {
            if (popupIcon) {
                popupIcon.style.background = 'linear-gradient(135deg,#dc2626,#ef4444)';
                popupIcon.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#fff;font-size:22px;"></i>';
            }
            if (popupTitle) {
                popupTitle.style.color = '#dc2626';
                popupTitle.innerText = 'SUBMISSION ERROR';
            }
            if (popupStatus) popupStatus.style.display = 'none';

            document.getElementById('srSuccessMsg').innerHTML =
                '<span style="color:#dc2626;">' + escHtml(res.message || 'Submission failed. Please try again.') + '</span>';
        }
JS;

$content = str_replace($old_js, $new_js, $content);

file_put_contents($filepath, $content);
echo "Successfully updated staff_inventory_merchandise.php!\n";
