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

# Always stamp the actual launcher version, regardless of the source package version.
brs, version_count = re.subn(
    r'm\.versionLabel\.text = "v[^"\n]+"',
    'm.versionLabel.text = "v2.4 MULTI-GAME LAUNCHER"',
    brs,
    count=1,
)
if version_count != 1:
    raise SystemExit('Could not stamp v2.4 launcher version')

# Replace only the OK branch inside the lobby state. Stop before the mode-state block.
lobby_start = brs.find('  if m.state = "lobby"')
mode_start = brs.find('  if m.state = "mode"', lobby_start)
if lobby_start < 0 or mode_start < 0:
    raise SystemExit('Lobby routing block not found')
lobby_block = brs[lobby_start:mode_start]
lobby_block, route_count = re.subn(
    r'    else if key = "OK"\n.*?(?=    else\n      return false\n    end if\n    return true\n  end if)',
    '    else if key = "OK"\n      launchSelectedGame()\n',
    lobby_block,
    count=1,
    flags=re.DOTALL,
)
if route_count != 1:
    raise SystemExit('Could not replace lobby OK routing')
brs = brs[:lobby_start] + lobby_block + brs[mode_start:]

# Insert the reusable game launcher once.
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

# Normalize all Color Clash lobby copy, including older bullet/spacing variants.
brs = re.sub(
    r'm\.lobbyMessage\.text = "COLOR CLASH[^"\n]*"',
    'm.lobbyMessage.text = "COLOR CLASH  •  Press OK to create a Color Clash room"',
    brs,
)
brs = brs.replace(
    'm.lobbyMessage.text = "Color Clash is the next card game planned"',
    'm.lobbyMessage.text = "COLOR CLASH  •  Press OK to create a Color Clash room"',
)
brs = brs.replace('m.colorClashPrompt.text = "COMING SOON"', 'm.colorClashPrompt.text = "ROOM PAIRING READY"')
brs = brs.replace(
    'm.lobbyMessage.text = "TN Trivia will support teams and local questions"',
    'm.lobbyMessage.text = "WORD TILES  •  Build words and compete for points"',
)

# Preserve the selected game's identity on the shared room screen.
brs = brs.replace(
    'm.productTitle.text = "CONNECT FOUR"\n  m.lobbyGroup.visible = false',
    'if m.activeGame = "color-clash" then m.productTitle.text = "COLOR CLASH" else m.productTitle.text = "CONNECT FOUR"\n  m.lobbyGroup.visible = false',
)

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

obsolete = [
    'Color Clash is the next card game planned',
    'COLOR CLASH  •  Coming in a future update',
    'COLOR CLASH • Coming in a future update',
]
for item in obsolete:
    if item in brs:
        raise SystemExit(f'Obsolete Color Clash behavior remains: {item}')

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
if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -E 'Color Clash is the next card game planned|COLOR CLASH.*Coming in a future update' >/dev/null; then
  echo "Obsolete Color Clash coming-soon message remains" >&2
  exit 1
fi

echo "Created TN Game v2.4 multi-game launcher: $OUTPUT_ZIP"
