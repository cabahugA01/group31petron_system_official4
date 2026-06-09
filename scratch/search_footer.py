with open(r"c:\xampp\htdocs\group31petron_system_official4\public\login.php", "r", encoding="utf-8") as f:
    lines = f.readlines()

for i, line in enumerate(lines):
    if "page-footer" in line or "page_footer" in line or "footer" in line:
        print(f"Line {i+1}: {line.strip()}")
