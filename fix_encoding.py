#!/usr/bin/env python3
# -*- coding: utf-8 -*-

with open('public/staff_inventory_fuel.php', 'r', encoding='utf-8', errors='ignore') as f:
    content = f.read()

# Count before
before_count = content.count('\u00e2\u0080\u0093')
print(f'Found {before_count} instances of malformed UTF-8 sequences')

# Replace the malformed UTF-8 byte sequence
# The bytes â€" are UTF-8 encoded as E2 80 93 (which is an em-dash U+2013)
# But they're being displayed incorrectly
content = content.replace('\u00e2\u0080\u0093', '-')
content = content.replace('â€"', '-')

# Also handle if it's stored as actual bytes
import codecs
content = content.encode('utf-8', errors='ignore').decode('utf-8', errors='ignore')

with open('public/staff_inventory_fuel.php', 'w', encoding='utf-8') as f:
    f.write(content)

print('Fixed encoding in public/staff_inventory_fuel.php')
