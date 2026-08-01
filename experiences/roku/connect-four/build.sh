#!/bin/bash

set -euo pipefail

APP_NAME="tn-game-connect-four"
VERSION="v0.3.1-bot"
ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST_DIR="$ROOT_DIR/dist"
IMAGE_DIR="$ROOT_DIR/images"
OUTPUT="$DIST_DIR/${APP_NAME}-${VERSION}.zip"

cd "$ROOT_DIR"

for required in manifest source components; do
    if [ ! -e "$required" ]; then
        echo "Error: Missing required Roku item: $required"
        exit 1
    fi
done

mkdir -p "$DIST_DIR" "$IMAGE_DIR"

python3 <<'PY'
import math
import struct
import zlib
from pathlib import Path

OUT = Path('images')
OUT.mkdir(exist_ok=True)

def chunk(kind: bytes, data: bytes) -> bytes:
    return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)

def write_png(path: Path, width: int, height: int, pixels):
    raw = bytearray()
    for row in pixels:
        raw.append(0)
        for rgba in row:
            raw.extend(rgba)
    header = struct.pack('>IIBBBBB', width, height, 8, 6, 0, 0, 0)
    png = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', header) + chunk(b'IDAT', zlib.compress(bytes(raw), 9)) + chunk(b'IEND', b'')
    path.write_bytes(png)

def blend(samples):
    return tuple(round(sum(channel) / len(samples)) for channel in zip(*samples))

def token(base, edge, highlight, shadow, empty=False, size=192, scale=4):
    cx = cy = size / 2
    radius = size * 0.43
    pixels = []
    for y in range(size):
        row = []
        for x in range(size):
            samples = []
            for sy in range(scale):
                for sx in range(scale):
                    px = x + (sx + 0.5) / scale
                    py = y + (sy + 0.5) / scale
                    dx, dy = px - cx, py - cy
                    d = math.hypot(dx, dy)
                    if d > radius:
                        samples.append((0, 0, 0, 0))
                        continue
                    rim = d / radius
                    if empty:
                        color = edge if rim > 0.88 else base
                    elif rim > 0.88:
                        color = edge
                    else:
                        light_d = math.hypot(dx + radius * 0.30, dy + radius * 0.34)
                        shadow_d = math.hypot(dx - radius * 0.34, dy - radius * 0.42)
                        if light_d < radius * 0.34:
                            color = highlight
                        elif shadow_d < radius * 0.44 and dy > 0:
                            color = shadow
                        else:
                            color = base
                    samples.append((*color, 255))
            row.append(blend(samples))
        pixels.append(row)
    return pixels

def rounded_rect(width, height, radius, fill, border=None, border_width=0, scale=2):
    pixels = []
    outer_radius = min(radius, width / 2, height / 2)
    inner_radius = max(0, outer_radius - border_width)
    def inside(px, py, inset, corner_radius):
        left, top = inset, inset
        right, bottom = width - inset, height - inset
        if px < left or px >= right or py < top or py >= bottom:
            return False
        if corner_radius <= 0:
            return True
        cx = left + corner_radius if px < left + corner_radius else right - corner_radius if px > right - corner_radius else px
        cy = top + corner_radius if py < top + corner_radius else bottom - corner_radius if py > bottom - corner_radius else py
        return (px - cx) ** 2 + (py - cy) ** 2 <= corner_radius ** 2
    for y in range(height):
        row = []
        for x in range(width):
            samples = []
            for sy in range(scale):
                for sx in range(scale):
                    px = x + (sx + 0.5) / scale
                    py = y + (sy + 0.5) / scale
                    if not inside(px, py, 0, outer_radius):
                        samples.append((0, 0, 0, 0))
                    elif border and border_width > 0 and not inside(px, py, border_width, inner_radius):
                        samples.append((*border, 255))
                    else:
                        samples.append((*fill, 255))
            row.append(blend(samples))
        pixels.append(row)
    return pixels

write_png(OUT / 'token-empty.png', 192, 192, token((8, 34, 27), (29, 79, 63), (0, 0, 0), (0, 0, 0), True))
palettes = {
    'orange': ((249,115,22),(177,61,6),(255,184,112),(209,74,5)),
    'gold': ((255,213,79),(196,142,0),(255,241,160),(225,166,0)),
    'blue': ((59,130,246),(29,78,216),(147,197,253),(37,99,235)),
    'purple': ((168,85,247),(107,33,168),(216,180,254),(126,34,206)),
    'green': ((34,197,94),(21,128,61),(134,239,172),(22,163,74)),
    'pink': ((236,72,153),(157,23,77),(251,182,206),(219,39,119)),
}
for name, colors in palettes.items():
    write_png(OUT / f'token-{name}.png', 192, 192, token(*colors))

surfaces = [
    ('brand-pill.png', 300, 76, 24, (249, 115, 22), None, 0),
    ('status-card.png', 1280, 132, 28, (10, 28, 22), (38, 96, 76), 3),
    ('board-card.png', 790, 680, 34, (11, 37, 29), (42, 112, 89), 4),
    ('player-card.png', 300, 380, 34, (16, 44, 34), (42, 112, 89), 3),
    ('footer-card.png', 1060, 92, 28, (8, 23, 18), (33, 72, 60), 3),
    ('lobby-card.png', 500, 500, 38, (10, 31, 24), (33, 72, 60), 4),
    ('lobby-card-selected.png', 500, 500, 38, (16, 44, 34), (249, 115, 22), 8),
    ('mode-card.png', 900, 132, 28, (10, 31, 24), (33, 72, 60), 4),
    ('mode-card-selected.png', 900, 132, 28, (16, 44, 34), (249, 115, 22), 7),
]
for name, width, height, radius, fill, border, border_width in surfaces:
    write_png(OUT / name, width, height, rounded_rect(width, height, radius, fill, border, border_width))
PY

rm -f "$OUTPUT"

zip -r "$OUTPUT" manifest source components images \
    -x "*.DS_Store" \
    -x "__MACOSX/*" \
    -x "*/__MACOSX/*"

echo
echo "Roku package created:"
echo "$OUTPUT"
echo
unzip -l "$OUTPUT"
