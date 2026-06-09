import os
from PIL import Image

img_path = r"c:\xampp\htdocs\group31petron_system_official4\assets\img\Petron Logo.png"
if os.path.exists(img_path):
    img = Image.open(img_path)
    print(f"Format: {img.format}, Size: {img.size}, Mode: {img.mode}")
    # Print some info about colors
    colors = img.getcolors(maxcolors=10000)
    if colors:
        print(f"Number of colors: {len(colors)}")
        # Sort by frequency
        colors.sort(key=lambda x: x[0], reverse=True)
        print("Top 10 colors:")
        for count, color in colors[:10]:
            print(f"Color: {color}, Count: {count}")
    else:
        print("Too many colors or color count not available.")
else:
    print("File not found")
