import re

path = r'c:\xampp\htdocs\group31petron_system_official4\public\superadmin_system_settings.php'

with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Locate the first occurrence of <script> after line 1300
# We can find the index of <script> and replace everything after it (leaving footer etc.)
start_idx = content.find('<script>')
if start_idx == -1:
    print("Error: <script> not found")
    exit(1)

# Now we find the footer part at the end
footer_text = "<?php include __DIR__ . '/../partials/footer.php'; ?>"
footer_idx = content.find(footer_text)
if footer_idx == -1:
    print("Error: footer not found")
    exit(1)

new_js = """<script>
/* ═══════════════════════════════════════════════════════════════════════════
   SYSTEM SETTINGS — JavaScript (Estate Form - All in One View)
   All API calls go to: backend/api/system_settings_api.php?action=...
═══════════════════════════════════════════════════════════════════════════ */

const API = '../backend/api/system_settings_api.php';

// Active station ID (0 = global)
let currentStationId = 0;

/* ── Toast Notifications ──────────────────────────────────────────────────── */
function showToast(message, type = 'success', duration = 3500) {
    const icons = {
        success: 'fas fa-check-circle',
        error:   'fas fa-times-circle',
        warning: 'fas fa-exclamation-triangle',
        info:    'fas fa-info-circle',
    };
    const container = document.getElementById('ss-toast-container');
    const toast = document.createElement('div');
    toast.className = `ss-toast ${type}`;
    toast.innerHTML = `<i class="${icons[type] || icons.info}"></i><span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(30px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 320);
    }, duration);
}

/* ── Set button loading state ────────────────────────────────────────────── */
function setBtnLoading(btnId, loading) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    if (loading) {
        btn._origHTML = btn.innerHTML;
        btn.innerHTML = '<span class="ss-spinner"></span> Saving…';
        btn.disabled = true;
    } else {
        btn.innerHTML = btn._origHTML || btn.innerHTML;
        btn.disabled = false;
    }
}

/* ── Station Selector Logic (Dropdown list) ────────────────────────────────── */
function filterStations(query) {
    const dropdown = document.getElementById('stationDropdown');
    if (!dropdown) return;
    dropdown.innerHTML = '';
    
    // Add global option first
    const globalOpt = document.createElement('div');
    globalOpt.style.padding = '10px 14px';
    globalOpt.style.cursor = 'pointer';
    globalOpt.style.fontSize = '13px';
    globalOpt.innerHTML = '<i class="fas fa-globe" style="color:#3b82f6;margin-right:8px;"></i>Global (all stations)';
    globalOpt.onclick = () => selectStation(0, 'Global');
    dropdown.appendChild(globalOpt);

    const filtered = STATIONS.filter(s => 
        s.name.toLowerCase().includes(query.toLowerCase()) || 
        s.address.toLowerCase().includes(query.toLowerCase())
    );

    if (filtered.length > 0 && query !== '') {
        filtered.forEach(st => {
            const opt = document.createElement('div');
            opt.style.padding = '10px 14px';
            opt.style.cursor = 'pointer';
            opt.style.borderTop = '1px solid var(--border-color)';
            opt.style.fontSize = '13px';
            opt.innerHTML = `<i class="fas fa-building" style="color:#6b7280;margin-right:8px;"></i>\${escHtml(st.name)} <span style="font-size:11px;color:#9ca3af;">(\${escHtml(st.address)})</span>`;
            opt.onclick = () => selectStation(st.id, st.name);
            dropdown.appendChild(opt);
        });
    }
    
    dropdown.style.display = 'block';
}

function selectStation(id, name) {
    currentStationId = id;
    document.getElementById('selectedStationId').value = id;
    document.getElementById('stationSearchInput').value = name === 'Global' ? '' : name;
    document.getElementById('stationDropdown').style.display = 'none';

    const clearBtn = document.getElementById('clearStationBtn');
    const banner = document.getElementById('selectedStationBanner');
    const bannerName = document.getElementById('selectedStationName');
    const saveScopeLabel = document.getElementById('save-scope-label');

    if (id > 0) {
        if (clearBtn) clearBtn.style.display = 'inline-block';
        if (banner) banner.style.display = 'block';
        if (bannerName) bannerName.textContent = name;
        if (saveScopeLabel) saveScopeLabel.textContent = `Station: \${name}`;
    } else {
        if (clearBtn) clearBtn.style.display = 'none';
        if (banner) banner.style.display = 'none';
        if (saveScopeLabel) saveScopeLabel.textContent = 'all stations globally';
    }

    showToast(`Switched view to: \${name}`, 'info');
    
    // Reload configurations for the chosen station scope
    loadCurrentLogo();
    loadAllSettings();
    loadAudit(1);
}

function clearStationFilter() {
    selectStation(0, 'Global');
}

// Close dropdown on click outside
document.addEventListener('click', function(e) {
    const container = document.getElementById('station-selector-card');
    const dropdown = document.getElementById('stationDropdown');
    if (container && !container.contains(e.target) && dropdown) {
        dropdown.style.display = 'none';
    }
});

/* ── Load All Settings on Page Load or Station Change ────────────────────── */
async function loadAllSettings() {
    try {
        const res  = await fetch(`\${API}?action=get_all&station_id=\${currentStationId}`);
        const data = await res.json();
        if (!data.success) return;
        const s = data.settings || {};

        // Theme Colors
        setColorField('primary',   s.primary_color?.value || '#002F6C');
        setColorField('button',    s.button_color?.value || '#002F6C');
        setColorField('sidebar',   s.sidebar_color?.value || '#1a1a2e');

        // Layout
        const style = document.getElementById('sidebar-style');
        if (style) style.value = s.sidebar_style?.value || 'inline';

        const scale = document.getElementById('font-scale');
        if (scale) scale.value = s.font_scale_layout?.value || '100';

        // Accessibility
        document.getElementById('toggle-high-contrast').checked = (s.high_contrast?.value === '1' || s.high_contrast?.value === 'true');

        const slider = document.getElementById('accessibility-font-scale');
        if (slider) {
            slider.value = s.font_scale_accessibility?.value || '100';
            updateFontScaleValue();
        }
    } catch(e) {
        console.warn('Could not load settings:', e);
    }
}

/* ── Load Current Logo ────────────────────────────────────────────────────── */
async function loadCurrentLogo() {
    try {
        const res  = await fetch(`\${API}?action=get_logo&station_id=\${currentStationId}`);
        const data = await res.json();
        if (data.success && data.logo_url) {
            document.getElementById('current-logo-img').src = '../' + data.logo_url + '?t=' + Date.now();
        }
    } catch(e) {}
}

/* ── Logo: Preview before upload ─────────────────────────────────────────── */
function previewLogoFile(input) {
    const file = input.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
        showToast('File exceeds 2MB limit.', 'error');
        input.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('current-logo-img').src = e.target.result;
        document.getElementById('btn-upload-logo').disabled = false;
    };
    reader.readAsDataURL(file);
}

function clearLogoInput() {
    document.getElementById('logo-file-input').value = '';
    document.getElementById('btn-upload-logo').disabled = true;
    loadCurrentLogo();
}

/* ── Logo: Upload ────────────────────────────────────────────────────────── */
async function uploadLogo() {
    const input = document.getElementById('logo-file-input');
    if (!input.files[0]) { showToast('Please select a file first.', 'warning'); return; }

    setBtnLoading('btn-upload-logo', true);
    const formData = new FormData();
    formData.append('logo', input.files[0]);
    formData.append('station_id', currentStationId);

    try {
        const res  = await fetch(`\${API}?action=save_logo`, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Logo updated successfully!', 'success');
            document.getElementById('current-logo-img').src = '../' + data.logo_url + '?t=' + Date.now();
            clearLogoInput();
        } else {
            showToast(data.message || 'Upload failed.', 'error');
        }
    } catch(e) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        setBtnLoading('btn-upload-logo', false);
    }
}

/* ── Logo: Reset to Default ──────────────────────────────────────────────── */
async function resetLogo() {
    if (!confirm('Reset logo to the default Petron logo?')) return;
    setBtnLoading('btn-reset-logo', true);
    
    const formData = new FormData();
    formData.append('station_id', currentStationId);

    try {
        const res  = await fetch(`\${API}?action=reset_logo`, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Logo reset to default.', 'success');
            document.getElementById('current-logo-img').src = '../' + data.logo_url + '?t=' + Date.now();
        } else {
            showToast(data.message || 'Reset failed.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    } finally {
        setBtnLoading('btn-reset-logo', false);
    }
}

/* ── Theme: Color helpers ─────────────────────────────────────────────────── */
function setColorField(name, value) {
    const picker = document.getElementById(`color-\${name}`);
    const hex    = document.getElementById(`hex-\${name}`);
    if (picker) picker.value = value;
    if (hex)    hex.value    = value;
}

function syncColorHex(name) {
    const picker = document.getElementById(`color-\${name}`);
    const hex    = document.getElementById(`hex-\${name}`);
    if (picker && hex) hex.value = picker.value;
}

function syncColorPicker(name) {
    const picker = document.getElementById(`color-\${name}`);
    const hex    = document.getElementById(`hex-\${name}`);
    if (hex && picker && /^#[0-9A-Fa-f]{6}$/.test(hex.value)) {
        picker.value = hex.value;
    }
}

/* ── Apply Color Scheme ───────────────────────────────────────────────────── */
async function applyColorScheme() {
    const payload = {
        primary_color: document.getElementById('hex-primary')?.value || '#002F6C',
        button_color: document.getElementById('hex-button')?.value || '#002F6C',
        sidebar_color: document.getElementById('hex-sidebar')?.value || '#1a1a2e',
        station_id: currentStationId
    };

    try {
        const res = await fetch(`\${API}?action=save_theme`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            showToast('Color scheme applied successfully!', 'success');
        } else {
            showToast(data.message || 'Failed to apply color scheme.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    }
}

/* ── Preview Layout ──────────────────────────────────────────────────────── */
function previewLayout() {
    const style = document.getElementById('sidebar-style')?.value || 'inline';
    const scale = document.getElementById('font-scale')?.value || '100';
    
    showToast(`Preview: Sidebar \${style}, Font \${scale}%`, 'info');
    document.documentElement.style.fontSize = `\${scale}%`;
}

/* ── High Contrast Toggle ────────────────────────────────────────────────── */
function toggleHighContrast() {
    const enabled = document.getElementById('toggle-high-contrast')?.checked;
    if (enabled) {
        document.body.classList.add('high-contrast-mode');
        showToast('High contrast mode enabled', 'info');
    } else {
        document.body.classList.remove('high-contrast-mode');
        showToast('High contrast mode disabled', 'info');
    }
}

/* ── Update Font Scale Value ──────────────────────────────────────────────── */
function updateFontScaleValue() {
    const slider = document.getElementById('accessibility-font-scale');
    const display = document.getElementById('font-scale-value');
    const preview = document.getElementById('font-preview');
    
    if (slider && display) {
        display.textContent = slider.value + '%';
    }
    
    if (preview && slider) {
        const baseSize = 14;
        const scale = parseInt(slider.value) / 100;
        preview.style.fontSize = (baseSize * scale) + 'px';
    }
}

/* ── Preview Accessibility Theme ─────────────────────────────────────────── */
function previewAccessibilityTheme() {
    const highContrast = document.getElementById('toggle-high-contrast')?.checked;
    const scale = document.getElementById('accessibility-font-scale')?.value || '100';
    
    let message = 'Preview: ';
    if (highContrast) message += 'High Contrast ON, ';
    message += `Font Scale \${scale}%`;
    
    showToast(message, 'info');
}

/* ── Save Accessibility Settings ─────────────────────────────────────────── */
async function saveAccessibilitySettings() {
    const payload = {
        high_contrast: document.getElementById('toggle-high-contrast')?.checked ? '1' : '0',
        font_scale: document.getElementById('accessibility-font-scale')?.value || '100',
        station_id: currentStationId
    };

    try {
        const res = await fetch(`\${API}?action=save_accessibility`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            showToast('Accessibility settings saved!', 'success');
        } else {
            showToast(data.message || 'Save failed.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    }
}

/* ── Save All Settings ────────────────────────────────────────────────────── */
async function saveAllSettings() {
    const payload = {
        primary_color: document.getElementById('hex-primary')?.value,
        button_color: document.getElementById('hex-button')?.value,
        sidebar_color: document.getElementById('hex-sidebar')?.value,
        sidebar_style: document.getElementById('sidebar-style')?.value,
        font_scale_layout: document.getElementById('font-scale')?.value,
        high_contrast: document.getElementById('toggle-high-contrast')?.checked ? '1' : '0',
        font_scale_accessibility: document.getElementById('accessibility-font-scale')?.value,
        station_id: currentStationId
    };

    try {
        const res = await fetch(`\${API}?action=save_all`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            showToast('All settings saved successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'Save failed.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    }
}

/* ── Initialize Drag & Drop for Card Arrangement ─────────────────────────── */
(function initCardDragDrop() {
    const list = document.getElementById('card-arrangement');
    if (!list) return;

    let draggedItem = null;

    list.addEventListener('dragstart', function(e) {
        draggedItem = e.target;
        e.target.classList.add('dragging');
    });

    list.addEventListener('dragend', function(e) {
        e.target.classList.remove('dragging');
    });

    list.addEventListener('dragover', function(e) {
        e.preventDefault();
        const afterElement = getDragAfterElement(list, e.clientY);
        if (afterElement == null) {
            list.appendChild(draggedItem);
        } else {
            list.insertBefore(draggedItem, afterElement);
        }
    });

    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.ss-sortable-item:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
})();

/* ── Audit Trail ─────────────────────────────────────────────────────────── */
let auditCurrentPage = 1;
let auditDebounceTimer = null;

function debounceAudit() {
    clearTimeout(auditDebounceTimer);
    auditDebounceTimer = setTimeout(() => loadAudit(1), 400);
}

async function loadAudit(page = 1) {
    auditCurrentPage = page;
    const group  = document.getElementById('audit-filter-group').value;
    const search = document.getElementById('audit-search').value;

    const loading = document.getElementById('audit-loading');
    const wrap    = document.getElementById('audit-table-wrap');
    if (loading) loading.style.display = 'block';
    if (wrap)    wrap.style.display    = 'none';

    const params = new URLSearchParams({ action: 'get_audit', page, group, search, station_id: currentStationId });

    try {
        const res  = await fetch(`\${API}?\${params}`);
        const data = await res.json();

        if (loading) loading.style.display = 'none';
        if (wrap)    wrap.style.display    = 'block';

        if (!data.success) {
            showToast(data.message || 'Failed to load audit log.', 'error');
            return;
        }

        renderAuditTable(data.data || []);
        renderAuditPagination(data.page, data.pages, data.total);
    } catch(e) {
        if (loading) loading.style.display = 'none';
        if (wrap)    wrap.style.display    = 'block';
        showToast('Network error loading audit log.', 'error');
    }
}

function renderAuditTable(rows) {
    const tbody = document.getElementById('audit-tbody');
    if (!tbody) return;

    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-secondary);">
            <i class="fas fa-inbox" style="font-size:24px;margin-bottom:8px;display:block;"></i>
            No audit records found.
        </td></tr>`;
        return;
    }

    const groupBadge = (g) => {
        const map = { branding: 'ss-badge-branding', theme: 'ss-badge-theme', layout: 'ss-badge-layout', accessibility: 'ss-badge-accessibility' };
        const cls = map[g] || 'ss-badge-general';
        return `<span class="ss-badge \${cls}">\${escHtml(g || 'general')}</span>`;
    };

    const truncate = (v, n = 30) => {
        if (!v) return '<span style="color:#9ca3af;">—</span>';
        const s = String(v);
        return escHtml(s.length > n ? s.substring(0, n) + '…' : s);
    };

    tbody.innerHTML = rows.map(r => `
        <tr>
            <td style="white-space:nowrap;">\${escHtml(r.created_at || '')}</td>
            <td><code style="font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">\${escHtml(r.setting_key || '')}</code></td>
            <td>\${groupBadge(r.setting_group)}</td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="\${escHtml(r.old_value || '')}">\${truncate(r.old_value)}</td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="\${escHtml(r.new_value || '')}">\${truncate(r.new_value)}</td>
            <td>\${escHtml(r.changed_by_name || 'System')}</td>
            <td style="font-family:monospace;font-size:12px;">\${escHtml(r.ip_address || '')}</td>
        </tr>
    `).join('');
}

function renderAuditPagination(current, total, recordCount) {
    const container = document.getElementById('audit-pagination');
    if (!container || total <= 1) { if (container) container.innerHTML = ''; return; }

    let html = `<span style="font-size:12px;color:var(--text-secondary);margin-right:8px;">\${recordCount} records</span>`;
    html += `<button class="ss-page-btn" onclick="loadAudit(\${current - 1})" \${current <= 1 ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i>
             </button>`;

    const range = 2;
    for (let i = 1; i <= total; i++) {
        if (i === 1 || i === total || (i >= current - range && i <= current + range)) {
            html += `<button class="ss-page-btn \${i === current ? 'active' : ''}" onclick="loadAudit(\${i})">\${i}</button>`;
        } else if (i === current - range - 1 || i === current + range + 1) {
            html += `<span style="padding:0 4px;color:var(--text-secondary);">…</span>`;
        }
    }

    html += `<button class="ss-page-btn" onclick="loadAudit(\${current + 1})" \${current >= total ? 'disabled' : ''}>
                <i class="fas fa-chevron-right"></i>
             </button>`;

    container.innerHTML = html;
}

/* ── Audit: Export CSV ───────────────────────────────────────────────────── */
async function exportAuditCSV() {
    const group  = document.getElementById('audit-filter-group').value;
    const search = document.getElementById('audit-search').value;

    showToast('Preparing CSV export…', 'info');

    let allRows = [];
    let page = 1, pages = 1;
    do {
        const params = new URLSearchParams({ action: 'get_audit', page, group, search, station_id: currentStationId });
        try {
            const res  = await fetch(`\${API}?\${params}`);
            const data = await res.json();
            if (!data.success) break;
            allRows = allRows.concat(data.data || []);
            pages = data.pages || 1;
            page++;
        } catch(e) { break; }
    } while (page <= pages);

    if (!allRows.length) { showToast('No records to export.', 'warning'); return; }

    const headers = ['Date/Time', 'Setting Key', 'Group', 'Old Value', 'New Value', 'Changed By', 'IP Address'];
    const csvRows = [headers.join(',')];
    allRows.forEach(r => {
        csvRows.push([
            csvCell(r.created_at),
            csvCell(r.setting_key),
            csvCell(r.setting_group),
            csvCell(r.old_value),
            csvCell(r.new_value),
            csvCell(r.changed_by_name),
            csvCell(r.ip_address),
        ].join(','));
    });

    const blob = new Blob([csvRows.join('\\n')], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `system_settings_audit_\${new Date().toISOString().slice(0,10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast(`Exported \${allRows.length} records.`, 'success');
}

function csvCell(v) {
    if (v === null || v === undefined) return '';
    return '"' + String(v).replace(/"/g, '""') + '"';
}

function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* ── Init ────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    loadCurrentLogo();
    loadAllSettings();
});
</script>

"""

# Replace in content
updated_content = content[:start_idx] + new_js + content[footer_idx:]

with open(path, 'w', encoding='utf-8') as f:
    f.write(updated_content)

print("Settings JS updated successfully.")
