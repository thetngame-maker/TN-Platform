#!/bin/bash

set -euo pipefail

APP_NAME="tn-game-connect-four"
VERSION="v0.2.7-brand"
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
SIZE = 192
SCALE = 4


def chunk(kind: bytes, data: bytes) -> bytes:
    return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)


def write_png(path: Path, pixels):
    raw = bytearray()
    for row in pixels:
        raw.append(0)
        for rgba in row:
            raw.extend(rgba)
    header = struct.pack('>IIBBBBB', SIZE, SIZE, 8, 6, 0, 0, 0)
    png = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', header) + chunk(b'IDAT', zlib.compress(bytes(raw), 9)) + chunk(b'IEND', b'')
    path.write_bytes(png)


def blend(samples):
    return tuple(round(sum(channel) / len(samples)) for channel in zip(*samples))


def token(base, edge, highlight, shadow, empty=False):
    cx = cy = SIZE / 2
    radius = SIZE * 0.43
    pixels = []
    for y in range(SIZE):
        row = []
        for x in range(SIZE):
            samples = []
            for sy in range(SCALE):
                for sx in range(SCALE):
                    px = x + (sx + 0.5) / SCALE
                    py = y + (sy + 0.5) / SCALE
                    dx, dy = px - cx, py - cy
                    d = math.hypot(dx, dy)
                    if d > radius:
                        samples.append((0, 0, 0, 0))
                        continue
                    if empty:
                        rim = d / radius
                        if rim > 0.88:
                            color = edge
                        else:
                            color = base
                    else:
                        rim = d / radius
                        if rim > 0.88:
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

write_png(OUT / 'token-empty.png', token((8, 34, 27), (29, 79, 63), (0, 0, 0), (0, 0, 0), True))
write_png(OUT / 'token-orange.png', token((249, 115, 22), (177, 61, 6), (255, 184, 112), (209, 74, 5)))
write_png(OUT / 'token-gold.png', token((255, 213, 79), (196, 142, 0), (255, 241, 160), (225, 166, 0)))
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
