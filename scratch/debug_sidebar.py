path = r'c:\xampp\htdocs\group31petron_system_official4\public\superadmin_system_settings.php'

with open(path, 'rb') as f:
    raw = f.read()

content = raw.decode('utf-8')

# Find the sidebar protection start
start_marker = '/* -- Sidebar Protection'
start_idx = content.find(start_marker)
print(f"Start index: {start_idx}")

if start_idx != -1:
    # Print 2000 chars from that point to see what we have
    snippet = content[start_idx:start_idx+1500]
    print("Found block:")
    print(repr(snippet[:500]))
