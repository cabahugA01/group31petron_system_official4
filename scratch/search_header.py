import sys

path = r'c:\xampp\htdocs\group31petron_system_official4\partials\header.php'

# Try UTF-8 first
try:
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    print("Read as UTF-8 successfully. Total characters:", len(content))
except Exception as e:
    print("UTF-8 failed:", e)

# Try UTF-16
try:
    with open(path, 'r', encoding='utf-16') as f:
        content = f.read()
    print("Read as UTF-16 successfully. Total characters:", len(content))
except Exception as e:
    print("UTF-16 failed:", e)

# Let's search for some terms in content
terms = ['sidebar', 'style', ':root', 'system_settings', 'active', 'theme']
for t in terms:
    count = content.lower().count(t.lower())
    print(f"Term '{t}' count: {count}")

# Print first 200 characters of content
print("Start of file:")
print(content[:500])
