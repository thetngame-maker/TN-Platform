#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_FILE="$ROOT_DIR/components/MainScene.brs"
BACKUP_FILE="$ROOT_DIR/components/MainScene.brs.render-backup"
OLD_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.3.1-bot.zip"
NEW_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.4.1-render.zip"

cp "$SOURCE_FILE" "$BACKUP_FILE"
restore_source() {
  if [ -f "$BACKUP_FILE" ]; then mv "$BACKUP_FILE" "$SOURCE_FILE"; fi
}
trap restore_source EXIT

python3 <<'PY'
from pathlib import Path

path = Path('components/MainScene.brs')
text = path.read_text()
text = text.replace('m.baseUrl = "http://192.168.1.127:8070"', 'm.baseUrl = "https://tn-game-connect-four-server.onrender.com"')
text = text.replace('m.versionLabel.text = "v0.3.2 QR"', 'm.versionLabel.text = "v0.4.1 RENDER"')
text = text.replace('m.versionLabel.text = "v0.4.0 SERVER"', 'm.versionLabel.text = "v0.4.1 RENDER"')
path.write_text(text)
PY

./build.sh
[ -f "$OLD_FILE" ] || { echo "Missing base build output"; exit 1; }
cp "$OLD_FILE" "$NEW_FILE"
echo "Created $NEW_FILE"
