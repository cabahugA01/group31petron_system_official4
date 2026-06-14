path = r'c:\xampp\htdocs\group31petron_system_official4\public\superadmin_system_settings.php'

with open(path, 'r', encoding='utf-8') as f:
    lines = f.readlines()

# Remove lines 1113-1128 (0-indexed: 1112-1127)
# These are the orphan preview-bar / preview-body / save theme button / extra </section>
del lines[1112:1128]

with open(path, 'w', encoding='utf-8') as f:
    f.writelines(lines)

print("Removed orphan lines. Total:", len(lines))
