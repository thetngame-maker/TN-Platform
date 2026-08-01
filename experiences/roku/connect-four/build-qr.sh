#!/bin/bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

./build.sh

OLD_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.3.1-bot.zip"
NEW_FILE="$ROOT_DIR/dist/tn-game-connect-four-v0.3.2-qr.zip"

if [ ! -f "$OLD_FILE" ]; then
  echo "Error: Expected build output was not found: $OLD_FILE"
  exit 1
fi

cp "$OLD_FILE" "$NEW_FILE"

echo
echo "QR release package created:"
echo "$NEW_FILE"
