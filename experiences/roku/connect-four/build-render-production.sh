#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

THIN_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.0.0-thin-client.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-connect-four-v1.0.1-display-sync.zip"
STAGE_DIR="$ROOT_DIR/.display-sync-stage"

# Build from the persistent DisplayLoopTask client.
./build-thin-client-v1.sh

if [ ! -f "$THIN_ZIP" ]; then
  echo "Expected thin-client Roku build not found: $THIN_ZIP" >&2
  exit 1
fi

rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR"
unzip -q "$THIN_ZIP" -d "$STAGE_DIR"

# Give this build a unique on-screen version so stale artifacts are obvious.
python3 <<'PY'
from pathlib import Path
path = Path('.display-sync-stage/components/MainScene.brs')
text = path.read_text()
text = text.replace('m.versionLabel.text = "v1.0 THIN"', 'm.versionLabel.text = "v1.0.1 SYNC"')
if 'm.versionLabel.text = "v1.0.1 SYNC"' not in text:
    raise SystemExit('Could not stamp v1.0.1 SYNC version label')
path.write_text(text)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$STAGE_DIR"
  zip -qr "$OUTPUT_ZIP" . \
    -x '*.DS_Store' '__MACOSX/*' '*.render-backup' '*.thin-client-backup'
)
rm -rf "$STAGE_DIR"

# Validate Roku package structure and production networking.
unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'manifest' || {
  echo "Invalid Roku package: manifest is not at ZIP root" >&2
  exit 1
}

unzip -Z1 "$OUTPUT_ZIP" | grep -qx 'components/DisplayLoopTask.brs' || {
  echo "Invalid Roku package: persistent display task is missing" >&2
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

unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'DisplayLoopTask' || {
  echo "Invalid Roku package: MainScene is not using DisplayLoopTask" >&2
  exit 1
}

unzip -p "$OUTPUT_ZIP" components/DisplayLoopTask.brs | grep -q '/tv?t=' || {
  echo "Invalid Roku package: continuous TV-state polling is missing" >&2
  exit 1
}

unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -q 'v1.0.1 SYNC' || {
  echo "Invalid Roku package: unique sync version label is missing" >&2
  exit 1
}

echo "Created $OUTPUT_ZIP"
