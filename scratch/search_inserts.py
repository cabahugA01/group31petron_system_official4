import os
import re

pattern = re.compile(r'INSERT\s+INTO\s+`?fuel_transactions`?', re.IGNORECASE)

for root, dirs, files in os.walk('.'):
    for file in files:
        if file.endswith('.php') or file.endswith('.js') or file.endswith('.py'):
            path = os.path.join(root, file)
            try:
                with open(path, 'r', encoding='utf-8', errors='ignore') as f:
                    content = f.read()
                if pattern.search(content):
                    print(f"Found in: {path}")
                    # Print matching lines
                    for i, line in enumerate(content.split('\n'), 1):
                        if pattern.search(line):
                            print(f"  Line {i}: {line.strip()}")
            except Exception as e:
                pass
