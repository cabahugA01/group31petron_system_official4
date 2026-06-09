from PIL import Image

img_path = r"c:\xampp\htdocs\group31petron_system_official4\assets\img\Petron Logo.png"
img = Image.open(img_path).convert("RGBA")
width, height = img.size

# Check opacity/pixel presence along Y axis to see where empty rows are
non_transparent_per_row = []
for y in range(height):
    count = 0
    for x in range(width):
        r, g, b, a = img.getpixel((x, y))
        if a > 50:
            count += 1
    non_transparent_per_row.append(count)

# Print row statistics to find gaps (rows with very few or zero non-transparent pixels)
gaps = []
for y in range(5, height - 5):
    # If a row has 0 pixels but adjacent rows have pixels, it's a gap
    if non_transparent_per_row[y] == 0 and (non_transparent_per_row[y-1] > 0 or non_transparent_per_row[y+1] > 0):
        gaps.append(y)

print(f"Total height: {height}")
print(f"Gaps found at Y indices: {gaps[:20]}")

# Let's also print non-zero ranges
ranges = []
in_range = False
start = 0
for y in range(height):
    if non_transparent_per_row[y] > 0 and not in_range:
        start = y
        in_range = True
    elif non_transparent_per_row[y] == 0 and in_range:
        ranges.append((start, y - 1))
        in_range = False
if in_range:
    ranges.append((start, height - 1))

print("Non-transparent Y ranges:")
for r in ranges:
    print(f"Range: {r}, height: {r[1] - r[0] + 1}")
