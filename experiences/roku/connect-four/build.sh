#!/bin/bash

set -euo pipefail

APP_NAME="tn-game-connect-four"
VERSION="v0.2.0"
ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST_DIR="$ROOT_DIR/dist"
OUTPUT="$DIST_DIR/${APP_NAME}-${VERSION}.zip"

cd "$ROOT_DIR"

for required in manifest source components; do
    if [ ! -e "$required" ]; then
        echo "Error: Missing required Roku item: $required"
        exit 1
    fi
done

mkdir -p "$DIST_DIR"
rm -f "$OUTPUT"

zip -r "$OUTPUT" manifest source components \
    -x "*.DS_Store" \
    -x "__MACOSX/*" \
    -x "*/__MACOSX/*"

echo
echo "Roku package created:"
echo "$OUTPUT"
echo
unzip -l "$OUTPUT"
