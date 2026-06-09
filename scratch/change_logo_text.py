import os
import shutil
from PIL import Image

logo_path = r"c:\xampp\htdocs\group31petron_system_official4\assets\img\Petron Logo.png"
backup_path = r"c:\xampp\htdocs\group31petron_system_official4\assets\img\Petron Logo_orig.png"

if not os.path.exists(backup_path):
    print("Creating backup of original logo...")
    shutil.copy(logo_path, backup_path)

# Load the image
img = Image.open(logo_path).convert("RGBA")
width, height = img.size

# We found that the text is in range Y: 2013 to 2439.
# Let's change all non-transparent pixels below Y = 1980 to white.
modified_pixels = 0
for y in range(1980, height):
    for x in range(width):
        r, g, b, a = img.getpixel((x, y))
        if a > 0:
            # Change to white keeping original alpha
            img.putpixel((x, y), (255, 255, 255, a))
            modified_pixels += 1

print(f"Modified {modified_pixels} pixels in the text region.")

# Save the image
img.save(logo_path, "PNG")
print("Saved modified logo successfully!")
