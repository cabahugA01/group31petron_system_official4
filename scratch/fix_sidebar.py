path = r'c:\xampp\htdocs\group31petron_system_official4\public\superadmin_system_settings.php'

with open(path, 'rb') as f:
    raw = f.read()

# Normalize all \r\r\n to \r\n first
content = raw.replace(b'\r\r\n', b'\r\n').replace(b'\r\n', b'\n').decode('utf-8')

start_marker = '/* -- Sidebar Protection'
# Find where sidebar protection ends (after the last sub-item active rule)
# Look for the next main section comment to mark end
end_marker = '/* -- Page Layout'

start_idx = content.find(start_marker)
end_idx = content.find(end_marker)

if start_idx == -1 or end_idx == -1:
    print(f"ERROR: start={start_idx}, end={end_idx}")
    exit(1)

print(f"Replacing block from char {start_idx} to {end_idx}")

new_block = """/* -- Sidebar Protection (HARDCODED - immune to generate_theme_css.php) --------
   IMPORTANT: Do NOT use CSS variables here - generate_theme_css.php overwrites
   --sidebar-bg with var(--gradient-sidebar) which can become light/white.     */
aside.sidebar,
#mainSidebar,
.sidebar {
    background-color: #00264D !important;
    background: #00264D !important;
    background-image: none !important;
}

/* Force ALL sidebar icons to stay white - overrides .fas/.far/.fab in generate_theme_css */
.sidebar i,
.sidebar .fas,
.sidebar .far,
.sidebar .fab,
.sidebar .fa {
    color: #eeeeee !important;
}

/* Force nav-item text and background to stay correct on dark sidebar */
.sidebar .nav-item,
.sidebar a.nav-item {
    color: #eeeeee !important;
    background-color: transparent !important;
    background: transparent !important;
}

.sidebar .nav-item span,
.sidebar a.nav-item span {
    color: #eeeeee !important;
}

.sidebar .nav-item:hover,
.sidebar a.nav-item:hover {
    background-color: rgba(255,255,255,0.10) !important;
    background: rgba(255,255,255,0.10) !important;
    color: #ffffff !important;
}

.sidebar .nav-item:hover span,
.sidebar .nav-item:hover i {
    color: #ffffff !important;
}

.sidebar .nav-item.active,
.sidebar a.nav-item.active {
    background-color: #CC0000 !important;
    background: #CC0000 !important;
    color: #ffffff !important;
}

.sidebar .nav-item.active span,
.sidebar .nav-item.active i,
.sidebar .nav-item.active .ico i {
    color: #ffffff !important;
}

/* Sub-menu items */
.sidebar .sidebar-sub-item {
    color: rgba(238,238,238,0.85) !important;
    background-color: transparent !important;
}

.sidebar .sidebar-sub-item:hover {
    background-color: rgba(255,255,255,0.10) !important;
    color: #ffffff !important;
}

.sidebar .sidebar-sub-item.active {
    background-color: #CC0000 !important;
    color: #ffffff !important;
}

"""

new_content = content[:start_idx] + new_block + content[end_idx:]

# Write back with \r\n line endings for Windows
with open(path, 'w', encoding='utf-8', newline='\r\n') as f:
    f.write(new_content)

print("Sidebar protection CSS fixed successfully.")
