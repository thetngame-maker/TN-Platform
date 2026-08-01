#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_FILE="$ROOT_DIR/components/MainScene.brs"
BACKUP_FILE="$ROOT_DIR/components/MainScene.brs.render-backup"
BASE_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v0.3.1-bot.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-render-production.zip"

cp "$SOURCE_FILE" "$BACKUP_FILE"
restore_source() {
  if [ -f "$BACKUP_FILE" ]; then mv "$BACKUP_FILE" "$SOURCE_FILE"; fi
}
trap restore_source EXIT

python3 <<'PY'
from pathlib import Path

path = Path('components/MainScene.brs')
text = path.read_text()
text = text.replace(
    'm.baseUrl = "http://192.168.1.127:8070"',
    'm.baseUrl = "https://tn-game-connect-four-server.onrender.com"'
)
text = text.replace(
    'm.versionLabel.text = "v0.3.2 QR"',
    'm.versionLabel.text = "v1.0 RENDER"'
)
if '192.168.1.127' in text:
    raise SystemExit('Local server URL remains in MainScene.brs')
path.write_text(text)
PY

./build.sh

if [ ! -f "$BASE_ZIP" ]; then
  echo "Expected Roku build not found: $BASE_ZIP" >&2
  exit 1
fi

cp "$BASE_ZIP" "$OUTPUT_ZIP"

# Validate that manifest is at ZIP root and the local IP is absent.
unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest' || {
  echo "Invalid Roku package: manifest is not at ZIP root" >&2
  exit 1
}

if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q '192\.168\.1\.127'; then
  echo "Invalid Roku package: local server URL is still present" >&2
  exit 1
fi

unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'tn-game-connect-four-server.onrender.com' || {
  echo "Invalid Roku package: Render URL is missing" >&2
  exit 1
}

echo "Created $OUTPUT_ZIP"
