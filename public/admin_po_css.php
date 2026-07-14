<?php
// ─── CSS for Purchase Orders Oversight ───────────────────────────────────────
?>
<style>
.po-int-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.po-int-head h1{font-size:22px;font-weight:700;color:#00264D;margin:0;text-transform:uppercase;display:flex;align-items:center;gap:8px;}
.po-int-head .sub{font-size:13px;color:#666;margin-top:4px;}
.po-sum-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:22px;}
.po-sum-card{background:#fff;border-radius:10px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.07);border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;}
.po-sum-label{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
.po-sum-val{font-size:26px;font-weight:800;color:#002F70;margin-top:2px;text-decoration:none !important;}
.po-sum-icon{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;}

/* Icon colors for different card types */
.po-sum-card.blue .po-sum-icon{color:#002F70;}
.po-sum-card.orange .po-sum-icon{color:#fd7e14;}
.po-sum-card.green .po-sum-icon{color:#28a745;}
.po-sum-card.teal .po-sum-icon{color:#17a2b8;}
.po-sum-card.red .po-sum-icon{color:#dc3545;}
.po-filter-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:18px;}
.po-filter-bar input,.po-filter-bar select{padding:7px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;color:#334155;outline:none;}
.po-filter-bar input:focus,.po-filter-bar select:focus{border-color:#002F70;}

/* Main Control Button Base Style */
.po-ctrl-btn{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:8px 16px;
    border-radius:7px;
    font-size:12.5px;
    font-weight:600;
    cursor:pointer;
    border:1px solid transparent;
    text-decoration:none;
    white-space:nowrap;
    transition:all .18s;
    line-height:1.2;
}

/* Force standard Back/Outline style on ALL action buttons, including <button> tags */
.po-btn-back{
    background:#ffffff !important;
    background-color:#ffffff !important;
    color:#475569 !important;
    border:1px solid #94a3b8 !important;
    border-color:#94a3b8 !important;
    box-shadow:none !important;
}
.po-btn-back:hover{
    background:#f1f5f9 !important;
    background-color:#f1f5f9 !important;
    color:#334155 !important;
    border-color:#64748b !important;
}

/* Export Buttons Style */
.po-btn-exp{
    background:#fff !important;
    background-color:#fff !important;
    color:#002F70 !important;
    border:1px solid #002F70 !important;
    border-color:#002F70 !important;
}
.po-btn-exp:hover{
    background:#002F70 !important;
    background-color:#002F70 !important;
    color:#fff !important;
}

/* Reject / Danger Button */
.po-btn-rej {
    background: #fff !important;
    color: #dc2626 !important;
    border: 1.5px solid #fca5a5 !important;
    font-weight: 600;
    padding: 9px 18px;
}
.po-btn-rej:hover {
    background: #fef2f2 !important;
    border-color: #ef4444 !important;
    color: #b91c1c !important;
}

/* Approve / Print Button */
.po-btn-approve {
    background: #16a34a !important;
    color: #fff !important;
    border: 1.5px solid #16a34a !important;
    padding: 9px 20px;
    font-weight: 700;
    letter-spacing: 0.2px;
}
.po-btn-approve:hover {
    background: #15803d !important;
    border-color: #15803d !important;
}

.po-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;}
.po-table{width:100%;border-collapse:collapse;font-size:13px;}
.po-table th{background:#002F70;color:#fff;padding:12px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;text-align:center;}
.po-table td{padding:12px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle;text-align:center;}
.po-table tbody tr:last-child td{border-bottom:none;}
.po-table tbody tr:hover{background:#f8fafc;}
.po-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;}
.po-badge-pending{background:#fff3cd;color:#856404;}
.po-badge-approved{background:#d1ecf1;color:#0c5460;}
.po-badge-delivered{background:#d4edda;color:#155724;}
.po-badge-cancelled{background:#f8d7da;color:#721c24;}
.po-badge-merch{background:#e8f4fd;color:#004085;}
.po-badge-fuel{background:#fff8e1;color:#795548;}

.po-modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;}
.po-modal-ov.show{display:flex;}
.po-modal-box{background:#fff;border-radius:12px;padding:26px;width:620px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.2);}
.po-modal-box h3{margin:0 0 16px;font-size:15px;font-weight:800;color:#002F70;display:flex;align-items:center;gap:8px;text-transform:uppercase;}
.po-form-grp{margin-bottom:13px;}
.po-form-grp label{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.po-form-grp input,.po-form-grp textarea,.po-form-grp select{width:100%;padding:8px 11px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;box-sizing:border-box;}
.po-form-grp input:focus,.po-form-grp textarea:focus{outline:none;border-color:#002F70;}
.po-modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;}
.po-info-box{background:#e8f4fd;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;font-size:12px;color:#002F70;margin-bottom:14px;}
.flash-ok{position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:99999;background:#d4edda;color:#155724;border:1px solid #c3e6cb;box-shadow:0 4px 16px rgba(0,0,0,.15);padding:12px 20px;border-radius:8px;display:flex;align-items:center;gap:8px;animation:sda .3s ease-out;}
.flash-err{position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:99999;background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;box-shadow:0 4px 16px rgba(0,0,0,.15);padding:12px 20px;border-radius:8px;display:flex;align-items:center;gap:8px;animation:sda .3s ease-out;}
.po-btn-fin{
    background:#fff !important;
    color:#16a34a !important;
    border:1px solid #16a34a !important;
}
.po-btn-fin:hover{
    background:#16a34a !important;
    color:#fff !important;
}
.po-btn-rej{
    background:#fff !important;
    color:#dc2626 !important;
    border:1px solid #dc2626 !important;
}
.po-btn-rej:hover{
    background:#dc2626 !important;
    color:#fff !important;
}
@keyframes sda{from{top:-60px;opacity:0}to{top:24px;opacity:1}}

/* ── PO Tabs ─────────────────────────────────────────────────────────── */
.po-tabs-nav {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    background: transparent;
    border-radius: 0;
    overflow: visible;
    box-shadow: none;
    border-bottom: none;
}
.po-tab-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    border-radius: 6px;
    transition: all .2s;
    text-transform: none;
    letter-spacing: normal;
    box-shadow: none;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #475569;
}
.po-tab-btn:hover {
    background: #f8fafc;
    color: #0f172a;
    border-color: #94a3b8;
}
.po-tab-btn.active-merch {
    background: #002F6C !important;
    color: #fff !important;
    border-color: #002F6C !important;
}
.po-tab-btn.active-fuel {
    background: #002F6C !important;
    color: #fff !important;
    border-color: #002F6C !important;
}
.po-tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 800;
    background: #e2e8f0;
    color: #475569;
}
.po-tab-btn.active-merch .po-tab-badge,
.po-tab-btn.active-fuel .po-tab-badge {
    background: rgba(255, 255, 255, 0.2) !important;
    color: #fff !important;
}
.po-tab-pane { display: none; }
.po-tab-pane.active { display: block; }

/* ── Inline PO Details Card ──────────────────────────────── */
.po-details-card {
    background: transparent;
    border: none;
    border-radius: 0;
    padding: 0;
    margin: 0;
    width: 100%;
    max-width: 100%;
    box-shadow: none;
    box-sizing: border-box;
}
.po-card-section-title {
    font-size: 13px;
    font-weight: 700;
    color: #002F6C;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 8px;
    margin: 0 0 14px 0;
    display: flex;
    align-items: center;
    gap: 7px;
}
.po-card-section-title + .po-card-section-title,
.po-card-section-title:not(:first-child) {
    margin-top: 20px;
}
.po-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 32px;
    margin-bottom: 16px;
}
.po-info-row {
    display: flex;
    align-items: flex-start;
    gap: 0;
    padding: 7px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 12px;
}
.po-info-row:last-child { border-bottom: none; }
.po-info-label {
    width: 160px;
    flex-shrink: 0;
    font-weight: 700;
    color: #475569;
    padding-top: 2px;
    line-height: 1.4;
}
.po-info-value {
    color: #0f172a;
    font-weight: 600;
    flex: 1;
    line-height: 1.4;
}
.po-info-input {
    padding: 5px 9px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 12px;
    flex: 1;
    max-width: 280px;
    box-sizing: border-box;
}
.po-info-select {
    padding: 5px 9px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 12px;
    flex: 1;
    max-width: 280px;
    background: #fff;
    box-sizing: border-box;
}
.po-addr-box {
    font-size: 11px;
    color: #334155;
    background: #f8fafc;
    padding: 8px 11px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    line-height: 1.5;
    font-weight: 600;
}
.po-textarea {
    width: 100%;
    padding: 7px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 12px;
    resize: none;
    font-family: inherit;
    box-sizing: border-box;
}
/* Products table inside card */
.po-products-wrap { overflow-x: auto; margin-top: 4px; }
.po-products-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.po-products-table th {
    background: #002F6C;
    color: #fff;
    padding: 8px 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    white-space: nowrap;
    text-align: left;
}
.po-products-table th.right, .po-products-table td.right { text-align: right; }
.po-products-table td {
    padding: 8px 10px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    color: #334155;
}
.po-products-table tbody tr:last-child td { border-bottom: none; }
.po-products-table tbody tr:hover { background: #f8fafc; }
.po-cost-input {
    width: 100px;
    padding: 4px 7px;
    border: 1px solid #cbd5e1;
    border-radius: 5px;
    font-size: 12px;
    text-align: right;
}
</style>
