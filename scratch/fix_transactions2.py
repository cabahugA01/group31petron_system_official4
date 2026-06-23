import os

def read(p):
    with open(p,'rb') as f: return f.read()
def write(p,d):
    with open(p,'wb') as f: f.write(d)
    print("  Written:", p)
def rep(d,old,new,label):
    if old in d:
        print("  OK:", label)
        return d.replace(old,new,1)
    print("  NOT FOUND:", label)
    return d

BASE = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')

# ─── Fix 1n: add int-head+txn-btn CSS to transactions.php ────────────────────
TXN = os.path.join(BASE, 'public', 'transactions.php')
d = read(TXN)

# the actual style marker (box drawing horizontal: \xe2\x94\x80)
STYLE_MARKER = b'<style>\r\n\r\n/* \xe2\x94\x80\xe2\x94\x80 Uniform table design \xe2\x94\x80\xe2\x94\x80 */'
inthead_css = (
    b'<style>\r\n\r\n'
    b'/* == PAGE HEADER - matches SuperAdmin int-head standard == */\r\n'
    b'.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }\r\n'
    b'.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }\r\n'
    b'.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }\r\n'
    b'\r\n'
    b'/* == UNIFIED TRANSACTION ACTION BUTTONS - outline design == */\r\n'
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
    b'.txn-btn-payment:hover { background:#7c3aed !important; color:#fff !important; }\r\n'
    b'\r\n'
    b'/* -- Uniform table design -- */'
)
d = rep(d, STYLE_MARKER, inthead_css, '1n: added int-head + txn-btn CSS')
write(TXN, d)

# ─── Fix 2a: page-head -> int-head in admin_transactions_oversight.php ────────
ATO = os.path.join(BASE, 'public', 'admin_transactions_oversight.php')
d = read(ATO)

# actual bytes from debug: \xc3\xa2\xe2\x82\xac\xe2\x80\x98 for the dash-like char
old_ato_head = (b'<div class="page-head">\r\n    <div>\r\n        <h1 class="h1">Oversight Dashboard</h1>\r\n'
                b'        <div class="sub">System\xc3\xa2\xe2\x82\xac\xe2\x80\x98wide monitoring of validated transactions and receivables.</div>\r\n'
                b'    </div>')
new_ato_head = (b'<div class="int-head">\r\n    <div>\r\n        <h1><i class="fas fa-eye"></i> Oversight Dashboard</h1>\r\n'
                b'        <div class="sub">System-wide monitoring of validated transactions and receivables.</div>\r\n'
                b'    </div>')
d = rep(d, old_ato_head, new_ato_head, '2a: admin page-head -> int-head')
write(ATO, d)

print("\nAll done.")
