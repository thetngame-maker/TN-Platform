#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

SOURCE_ZIP="$ROOT_DIR/dist/tn-game-platform-v2.0-shell.zip"
OUTPUT_ZIP="$ROOT_DIR/dist/tn-game-platform-v2.4-launcher.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

./build-platform-shell-v2.0.sh

test -f "$SOURCE_ZIP"
unzip -q "$SOURCE_ZIP" -d "$WORK_DIR"

python3 - "$WORK_DIR" <<'PY'
from pathlib import Path
import re
import sys

root = Path(sys.argv[1])
brs_path = root / "components" / "MainScene.brs"
brs = brs_path.read_text()

brs = brs.replace('m.versionLabel.text = "v2.3 COLOR CLASH PAIRING"', 'm.versionLabel.text = "v2.4 MULTI-GAME LAUNCHER"')

# Replace the lobby OK action regardless of whitespace or an older one-line implementation.
lobby_ok = re.compile(
    r'    else if key = "OK"\n(?:.|\n)*?    else\n      return false\n    end if\n    return true\n  end if',
    re.MULTILINE,
)
replacement = '''    else if key = "OK"
      launchSelectedGame()
    else
      return false
    end if
    return true
  end if'''
brs, count = lobby_ok.subn(replacement, brs, count=1)
if count != 1:
    raise SystemExit('Could not replace lobby OK routing block')

# Insert one reusable launcher before the lobby selection renderer.
marker = 'sub updateLobbySelection()\n'
launcher = '''sub launchSelectedGame()
  if m.lobbySelection = 0
    m.activeGame = "connect-four"
    showModeSelect()
  else if m.lobbySelection = 1
    m.activeGame = "color-clash"
    startColorClashPairing()
  else
    m.activeGame = "word-tiles"
    m.lobbyMessage.text = "WORD TILES  •  Coming after Color Clash gameplay"
  end if
end sub

'''
if 'sub launchSelectedGame()' not in brs:
    if marker not in brs:
        raise SystemExit('Launcher insertion marker not found')
    brs = brs.replace(marker, launcher + marker, 1)

# Make the home selection text accurately describe what OK will do.
brs = brs.replace('m.colorClashPrompt.text = "COMING SOON"', 'm.colorClashPrompt.text = "ROOM PAIRING READY"')
brs = brs.replace('m.lobbyMessage.text = "Color Clash is the next card game planned"', 'm.lobbyMessage.text = "COLOR CLASH  •  Press OK to create a Color Clash room"')
brs = brs.replace('m.lobbyMessage.text = "COLOR CLASH  •  Coming in a future update"', 'm.lobbyMessage.text = "COLOR CLASH  •  Press OK to create a Color Clash room"')
brs = brs.replace('m.lobbyMessage.text = "TN Trivia will support teams and local questions"', 'm.lobbyMessage.text = "WORD TILES  •  Build words and compete for points"')

# The shared room screen must keep the selected game identity.
brs = brs.replace('m.productTitle.text = "CONNECT FOUR"\n  m.lobbyGroup.visible = false', 'if m.activeGame = "color-clash" then m.productTitle.text = "COLOR CLASH" else m.productTitle.text = "CONNECT FOUR"\n  m.lobbyGroup.visible = false')

required = [
    'v2.4 MULTI-GAME LAUNCHER',
    'sub launchSelectedGame()',
    'm.activeGame = "color-clash"',
    'startColorClashPairing()',
    '&game=color-clash',
    'COLOR CLASH  •  Press OK to create a Color Clash room',
]
for item in required:
    if item not in brs:
        raise SystemExit(f'Multi-game launcher marker missing: {item}')

# Explicitly reject the obsolete Color Clash behavior.
if 'Color Clash is the next card game planned' in brs or 'COLOR CLASH  •  Coming in a future update' in brs:
    raise SystemExit('Obsolete Color Clash lobby behavior remains')

brs_path.write_text(brs)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR"
  zip -qr "$OUTPUT_ZIP" .
)

unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'manifest' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'v2.4 MULTI-GAME LAUNCHER' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'sub launchSelectedGame()' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'startColorClashPairing()' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F '&game=color-clash' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'Press OK to create a Color Clash room' >/dev/null
if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'Coming in a future update' >/dev/null; then
  echo "Obsolete Color Clash coming-soon message remains" >&2
  exit 1
fi

echo "Created TN Game v2.4 multi-game launcher: $OUTPUT_ZIP"
