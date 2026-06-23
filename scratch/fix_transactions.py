import os, sys

def read(p):
    with open(p, 'rb') as f: return f.read()
def write(p, d):
    with open(p, 'wb') as f: f.write(d)
    print("  Written:", p)
def rep(d, old, new, label):
    if old in d:
        print("  OK:", label)
        return d.replace(old, new, 1)
    print("  NOT FOUND:", label)
    return d

BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')

# ─── 1. transactions.php (Manager) ───────────────────────────────────────────
TXN = os.path.join(BASE, 'public', 'transactions.php')
d = read(TXN)

# 1a. page-head -> int-head
d = rep(d,
b'<div class="page-head">\r\n    <div>\r\n        <h1 class="h1">\r\n            <?php if ($active_tab === \'validated\'): ?>\r\n                <i class="fas fa-check-circle" style="color:#22c55e;"></i> Validated Transactions\r\n            <?php else: ?>\r\n                <i class="fas fa-hourglass-half" style="color:#002F70;"></i> Pending Transactions\r\n            <?php endif; ?>\r\n        </h1>\r\n        <div class="sub">\r\n            <?php if ($active_tab === \'validated\'): ?>\r\n                Read-only history \xe2\x80\x94 all approved, adjusted &amp; rejected transactions\r\n            <?php else: ?>\r\n                Validation queue \xe2\x80\x94 Approve, Reject, or Adjust all Merchandise &amp; Job Order entries\r\n            <?php endif; ?>\r\n        </div>\r\n    </div>\r\n</div>',
b'<div class="int-head">\r\n    <div>\r\n        <h1>\r\n            <?php if ($active_tab === \'validated\'): ?>\r\n                <i class="fas fa-check-circle"></i> Validated Transactions\r\n            <?php else: ?>\r\n                <i class="fas fa-hourglass-half"></i> Pending Transactions\r\n            <?php endif; ?>\r\n        </h1>\r\n        <div class="sub">\r\n            <?php if ($active_tab === \'validated\'): ?>\r\n                Read-only history &mdash; all approved, adjusted &amp; rejected transactions\r\n            <?php else: ?>\r\n                Validation queue &mdash; Approve, Reject, or Adjust all Merchandise &amp; Job Order entries\r\n            <?php endif; ?>\r\n        </div>\r\n    </div>\r\n</div>',
'1a: page-head -> int-head')

# 1b. Status filter emojis
d = rep(d,
b'<option value="pending"  <?php echo ($status_f===\'pending\')  ? \'selected\':\'\'; ?>>\xf0\x9f\x95\x90 Pending</option>\r\n                    <option value="verified" <?php echo ($status_f===\'verified\') ? \'selected\':\'\'; ?>>\xe2\x9c\x85 Verified</option>\r\n                    <option value="rejected" <?php echo ($status_f===\'rejected\') ? \'selected\':\'\'; ?>>\xe2\x86\xa9 Returned</option>',
b'<option value="pending"  <?php echo ($status_f===\'pending\')  ? \'selected\':\'\'; ?>>Pending</option>\r\n                    <option value="verified" <?php echo ($status_f===\'verified\') ? \'selected\':\'\'; ?>>Verified</option>\r\n                    <option value="rejected" <?php echo ($status_f===\'rejected\') ? \'selected\':\'\'; ?>>Returned</option>',
'1b: Status emojis removed')

# 1c. Type filter emojis
d = rep(d,
b'<option value="merchandise" <?php echo ($type_f===\'merchandise\') ? \'selected\':\'\'; ?>>\xf0\x9f\x9b\x92 Merchandise</option>\r\n                    <option value="jo"          <?php echo ($type_f===\'jo\')          ? \'selected\':\'\'; ?>>\xf0\x9f\x94\xa7 Job Order Only</option>\r\n                    <option value="combined"    <?php echo ($type_f===\'combined\')    ? \'selected\':\'\'; ?>>\xf0\x9f\x93\xa6 JO with Merch</option>',
b'<option value="merchandise" <?php echo ($type_f===\'merchandise\') ? \'selected\':\'\'; ?>>Merchandise</option>\r\n                    <option value="jo"          <?php echo ($type_f===\'jo\')          ? \'selected\':\'\'; ?>>Job Order Only</option>\r\n                    <option value="combined"    <?php echo ($type_f===\'combined\')    ? \'selected\':\'\'; ?>>JO with Merchandise</option>',
'1c: Type emojis removed')

# 1d. JO Approve + Reject (first occurrence)
d = rep(d,
b'<button type="submit" class="jo-act-btn" style="background:#28a745;"><i class="fas fa-check"></i> Approve</button>\r\n                            </form>\r\n                            <!-- JO: Reject -->\r\n                            <button type="button" class="jo-act-btn" style="background:#dc3545;" onclick="openJORejectModal(',
b'<button type="submit" class="txn-btn txn-btn-approve"><i class="fas fa-check"></i> Approve</button>\r\n                            </form>\r\n                            <!-- JO: Reject -->\r\n                            <button type="button" class="txn-btn txn-btn-reject" onclick="openJORejectModal(',
'1d: JO Approve/Reject buttons')

# 1e. JO Adjust
d = rep(d,
b'<!-- JO: Adjust -->\r\n                            <button type="button" class="jo-act-btn" style="background:#002F6C;" onclick="openJOAdjustModal(',
b'<!-- JO: Adjust -->\r\n                            <button type="button" class="txn-btn txn-btn-adjust" onclick="openJOAdjustModal(',
'1e: JO Adjust button')

# 1f. Merch Approve + Reject
d = rep(d,
b'<button type="submit" class="jo-act-btn" style="background:#28a745;"><i class="fas fa-check"></i> Approve</button>\r\n                            </form>\r\n                            <!-- Merch: Reject -->\r\n                            <button type="button" class="jo-act-btn" style="background:#dc3545;" onclick="openRejectModal(',
b'<button type="submit" class="txn-btn txn-btn-approve"><i class="fas fa-check"></i> Approve</button>\r\n                            </form>\r\n                            <!-- Merch: Reject -->\r\n                            <button type="button" class="txn-btn txn-btn-reject" onclick="openRejectModal(',
'1f: Merch Approve/Reject buttons')

# 1g. Merch Adjust
d = rep(d,
b'<!-- Merch: Adjust -->\r\n                            <button type="button" class="jo-act-btn" style="background:#002F6C;" onclick="openAdjustModal(',
b'<!-- Merch: Adjust -->\r\n                            <button type="button" class="txn-btn txn-btn-adjust" onclick="openAdjustModal(',
'1g: Merch Adjust button')

# 1h. Start button
d = rep(d,
b'<button type="submit" class="jo-act-btn" style="background:#17a2b8;" onclick="return confirm(\'Mark as In Progress?\')">',
b'<button type="submit" class="txn-btn txn-btn-info" onclick="return confirm(\'Mark as In Progress?\')">',
'1h: Start button')

# 1i. Complete button
d = rep(d,
b'<button type="submit" class="jo-act-btn" style="background:#28a745;" onclick="return confirm(\'Mark service as Completed?\')">',
b'<button type="submit" class="txn-btn txn-btn-approve" onclick="return confirm(\'Mark service as Completed?\')">',
'1i: Complete button')

# 1j. Payment button (remove dynamic colors)
old_pay = (b'<button type="button" class="jo-act-btn" style="background:<?php echo $pbg; ?>;color:<?php echo $pfc; ?>;" title="Set Payment Status"\r\n                                onclick="openPaymentModal(<?php echo $rowId; ?>, \'<?php echo htmlspecialchars($src); ?>\', \'<?php echo htmlspecialchars($t[\'payment_status\'] ?? \'Unpaid\'); ?>\', <?php echo (float)$t[\'total\']; ?>)">\r\n                                <i class="fas fa-credit-card"></i> <?php echo ($cur_pay === \'paid\') ? \'Paid \xe2\x9c\x93\' : \'Set Payment\'; ?>')
new_pay = (b'<button type="button" class="txn-btn txn-btn-payment" title="Set Payment Status"\r\n                                onclick="openPaymentModal(<?php echo $rowId; ?>, \'<?php echo htmlspecialchars($src); ?>\', \'<?php echo htmlspecialchars($t[\'payment_status\'] ?? \'Unpaid\'); ?>\', <?php echo (float)$t[\'total\']; ?>)">\r\n                                <i class="fas fa-credit-card"></i> <?php echo ($cur_pay === \'paid\') ? \'Paid\' : \'Set Payment\'; ?>')
d = rep(d, old_pay, new_pay, '1j: Payment button')

# 1k. Receipt merch
d = rep(d,
b'<button type="button" class="jo-act-btn" style="background:#6c757d;" title="Print Receipt"\r\n                                onclick="printReceiptPopupImmune(\'<?php echo htmlspecialchars($receiptId, ENT_QUOTES); ?>\', \'merchandise\')">',
b'<button type="button" class="txn-btn txn-btn-secondary" title="Print Receipt"\r\n                                onclick="printReceiptPopupImmune(\'<?php echo htmlspecialchars($receiptId, ENT_QUOTES); ?>\', \'merchandise\')">',
'1k: Receipt merch button')

# 1l. Receipt JO
d = rep(d,
b'<button type="button" class="jo-act-btn" style="background:#6c757d;" title="Print Receipt"\r\n                                onclick="printReceiptPopupImmune(\'<?php echo htmlspecialchars($receiptId, ENT_QUOTES); ?>\', \'job_order\')">',
b'<button type="button" class="txn-btn txn-btn-secondary" title="Print Receipt"\r\n                                onclick="printReceiptPopupImmune(\'<?php echo htmlspecialchars($receiptId, ENT_QUOTES); ?>\', \'job_order\')">',
'1l: Receipt JO button')

# 1m. flt-btn CSS: solid -> outline
old_flt = (b'.flt-btn {\r\n    display: inline-flex;\r\n    align-items: center;\r\n    gap: 6px;\r\n    padding: 0 16px;\r\n    height: 36px;\r\n    border: none;\r\n    border-radius: 7px;\r\n    font-size: 13px;\r\n    font-weight: 600;\r\n    cursor: pointer;\r\n    text-decoration: none;\r\n    white-space: nowrap;\r\n    transition: all .2s ease;\r\n    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);\r\n    border: 1px solid rgba(255, 255, 255, 0.2);\r\n    position: relative;\r\n    overflow: hidden;\r\n}\r\n\r\n.flt-btn:hover { \r\n    transform: translateY(-1px);\r\n    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);\r\n}\r\n\r\n.flt-btn:active {\r\n    transform: translateY(0);\r\n    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);\r\n}\r\n\r\n.flt-btn-search { background: #002F6C; color: #fff; border-color: #001a4d; }\r\n.flt-btn-reset  { background: #002F6C; color: #fff; border-color: #001a4d; }\r\n.flt-btn-excel  { background: #1d6f42; color: #fff; border-color: #164a32; }\r\n.flt-btn-pdf    { background: #c0392b; color: #fff; border-color: #a02e1f; }')
new_flt = (b'.flt-btn {\r\n    display: inline-flex;\r\n    align-items: center;\r\n    gap: 6px;\r\n    padding: 0 16px;\r\n    height: 36px;\r\n    border-radius: 7px;\r\n    font-size: 13px;\r\n    font-weight: 600;\r\n    cursor: pointer;\r\n    text-decoration: none;\r\n    white-space: nowrap;\r\n    transition: all .18s;\r\n    background: white !important;\r\n    border: 1px solid transparent;\r\n}\r\n\r\n.flt-btn-search { color: #00264D !important; border-color: #00264D !important; }\r\n.flt-btn-search:hover { background: #00264D !important; color: #fff !important; }\r\n.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }\r\n.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }\r\n.flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }\r\n.flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }\r\n.flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }\r\n.flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }')
d = rep(d, old_flt, new_flt, '1m: flt-btn outline style')

# 1n. Add int-head + txn-btn CSS block at style tag
inthead_css = (b'\r\n/* == PAGE HEADER - matches SuperAdmin int-head standard == */\r\n'
               b'.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }\r\n'
               b'.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }\r\n'
               b'.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }\r\n'
               b'\r\n/* == UNIFIED TRANSACTION ACTION BUTTONS - outline design == */\r\n'
               b'.txn-btn { display:flex; align-items:center; justify-content:center; gap:5px; padding:5px 10px; border-radius:5px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap; line-height:1; width:100%; transition:all .18s; background:white !important; border:1px solid transparent; text-decoration:none; }\r\n'
               b'.txn-btn-approve { color:#16a34a !important; border-color:#16a34a !important; }\r\n'
               b'.txn-btn-approve:hover { background:#16a34a !important; color:#fff !important; }\r\n'
               b'.txn-btn-reject { color:#dc2626 !important; border-color:#dc2626 !important; }\r\n'
               b'.txn-btn-reject:hover { background:#dc2626 !important; color:#fff !important; }\r\n'
               b'.txn-btn-adjust { color:#00264D !important; border-color:#00264D !important; }\r\n'
               b'.txn-btn-adjust:hover { background:#00264D !important; color:#fff !important; }\r\n'
               b'.txn-btn-info { color:#0284c7 !important; border-color:#0284c7 !important; }\r\n'
               b'.txn-btn-info:hover { background:#0284c7 !important; color:#fff !important; }\r\n'
               b'.txn-btn-secondary { color:#6b7280 !important; border-color:#6b7280 !important; }\r\n'
               b'.txn-btn-secondary:hover { background:#6b7280 !important; color:#fff !important; }\r\n'
               b'.txn-btn-payment { color:#7c3aed !important; border-color:#7c3aed !important; }\r\n'
               b'.txn-btn-payment:hover { background:#7c3aed !important; color:#fff !important; }\r\n')

# Find the <style> tag right before the Uniform table design comment
STYLE_MARKER = b'<style>\r\n\r\n/* \xe2\x80\x95\xe2\x80\x95 Uniform table design \xe2\x80\x95\xe2\x80\x95 */'
if STYLE_MARKER in d:
    d = d.replace(STYLE_MARKER, b'<style>' + inthead_css + b'\r\n/* -- Uniform table design -- */', 1)
    print("  OK: 1n: added int-head + txn-btn CSS")
else:
    # Try alternate
    STYLE_MARKER2 = b'<style>\r\n\r\n/* \xe2\x80\x95\xe2\x80\x95'
    idx = d.find(STYLE_MARKER2)
    if idx >= 0:
        d = d[:idx + 7] + inthead_css + d[idx + 7:]
        print("  OK: 1n: added int-head + txn-btn CSS (alt)")
    else:
        print("  NOT FOUND: 1n: style marker")

write(TXN, d)


# ─── 2. admin_transactions_oversight.php (Admin) ─────────────────────────────
ATO = os.path.join(BASE, 'public', 'admin_transactions_oversight.php')
d = read(ATO)

# 2a. page-head -> int-head (the character between "System" and "wide" is non-breaking hyphen \xe2\x80\x91)
d = rep(d,
b'<div class="page-head">\r\n    <div>\r\n        <h1 class="h1">Oversight Dashboard</h1>\r\n        <div class="sub">System\xe2\x80\x91wide monitoring of validated transactions and receivables.</div>\r\n    </div>',
b'<div class="int-head">\r\n    <div>\r\n        <h1><i class="fas fa-eye"></i> Oversight Dashboard</h1>\r\n        <div class="sub">System-wide monitoring of validated transactions and receivables.</div>\r\n    </div>',
'2a: page-head -> int-head')

# 2b. Add int-head CSS in the style block
ato_inthead_css = (b'\r\n/* == PAGE HEADER - matches SuperAdmin int-head standard == */\r\n'
                   b'.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }\r\n'
                   b'.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }\r\n'
                   b'.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }\r\n')
MARKER_ATO = b'<style>\r\n.ato-flt-grp {'
if MARKER_ATO in d:
    d = d.replace(MARKER_ATO, b'<style>' + ato_inthead_css + b'\r\n.ato-flt-grp {', 1)
    print("  OK: 2b: added int-head CSS to ato")
else:
    print("  NOT FOUND: 2b: ato style marker")

write(ATO, d)
print("\nAll done.")
