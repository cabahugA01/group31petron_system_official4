import os, re

workspace = "c:\\xampp\\htdocs\\group31petron_system_official4"
pattern = re.compile(r"recent_merch", re.IGNORECASE)

for root, dirs, files in os.walk(workspace):
    if "vendor" in root or ".git" in root or ".gemini" in root:
        continue
    for file in files:
        if file.endswith(".php"):
            path = os.path.join(root, file)
            try:
                with open(path, "r", encoding="utf-8") as f:
                    content = f.read()
                if pattern.search(content):
                    print(f"Found in {path}")
            except Exception as e:
                pass
