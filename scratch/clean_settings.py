import os
import re

path = r'c:\xampp\htdocs\group31petron_system_official4\public\superadmin_system_settings.php'

with open(path, 'rb') as f:
    raw = f.read()

# Let's decode as utf-8 (ignoring or replacing errors)
content = raw.decode('utf-8', errors='replace')

# Replacements for corrupted chars
replacements = {
    'â€“': ' - ',
    'â€”': ' - ',
    'â€¦': '...',
    'â”€': '-',
    'â•': '=',
    'â€': '"',
    'â€˜': "'",
    'â€™': "'",
    'ðŸ”¹': '🔹',
    'ðŸ”¥': '🔥',
    'âœ“': '✓',
    'âœ–': '✗',
    'ï¼š': ':',
    'Â': '', # Remove lone Â character
}

for bad, good in replacements.items():
    content = content.replace(bad, good)

# Clean up comments box-drawing characters
content = re.sub(r'â•+', '=', content)
content = re.sub(r'â”€+', '-', content)
content = re.sub(r'â•', '=', content)
content = re.sub(r'â”€', '-', content)

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Settings page cleaned and written successfully.")
