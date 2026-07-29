#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="$ROOT_DIR/dist"
OUT_FILE="$DIST_DIR/color-clash-roku-v0.7.0.zip"

rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

cd "$ROOT_DIR"
zip -r "$OUT_FILE" manifest source components \
  -x "*/.DS_Store" "*/__MACOSX/*" "*/._*"

printf 'Created %s\n' "$OUT_FILE"
unzip -l "$OUT_FILE"
