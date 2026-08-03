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
mkdir -p "$WORK_DIR/components"
cp "$ROOT_DIR/components/GameRouter.brs" "$WORK_DIR/components/GameRouter.brs"

python3 - "$WORK_DIR" <<'PY'
from pathlib import Path
import re
import sys

root = Path(sys.argv[1])
brs_path = root / "components" / "MainScene.brs"
xml_path = root / "components" / "MainScene.xml"
brs = brs_path.read_text()
xml = xml_path.read_text()

if 'pkg:/components/GameRouter.brs' not in xml:
    xml = xml.replace(
        '<script type="text/brightscript" uri="pkg:/components/MainScene.brs" />',
        '<script type="text/brightscript" uri="pkg:/components/GameRouter.brs" />\n  <script type="text/brightscript" uri="pkg:/components/MainScene.brs" />',
        1,
    )
xml_path.write_text(xml)

brs, version_count = re.subn(
    r'm\.versionLabel\.text = "v[^"\n]+"',
    'm.versionLabel.text = "v3.0 MODULE ARCHITECTURE"',
    brs,
    count=1,
)
if version_count != 1:
    raise SystemExit('Could not stamp v3.0 module architecture version')

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

marker = 'sub updateLobbySelection()\n'
launcher = '''sub launchSelectedGame()
  route = routeForSelection(m.lobbySelection)
  m.activeGame = route.id
  m.activeRoute = route

  if route.id = "connect-four"
    showModeSelect()
  else if route.id = "color-clash"
    startColorClashPairing()
  else
    m.lobbyMessage.text = "WORD TILES  •  Module registered and coming after Color Clash gameplay"
  end if
end sub

'''
if 'sub launchSelectedGame()' not in brs:
    if marker not in brs:
        raise SystemExit('Launcher insertion marker not found')
    brs = brs.replace(marker, launcher + marker, 1)
else:
    brs = re.sub(
        r'sub launchSelectedGame\(\)\n.*?end sub\n\n',
        launcher,
        brs,
        count=1,
        flags=re.DOTALL,
    )

brs = re.sub(
    r'm\.lobbyMessage\.text = "COLOR CLASH[^"\n]*"',
    'm.lobbyMessage.text = "COLOR CLASH  •  Press OK to create a Color Clash room"',
    brs,
)
brs = brs.replace('m.colorClashPrompt.text = "COMING SOON"', 'm.colorClashPrompt.text = "ROOM PAIRING READY"')
brs = brs.replace(
    'm.lobbyMessage.text = "TN Trivia will support teams and local questions"',
    'm.lobbyMessage.text = "WORD TILES  •  Build words and compete for points"',
)

brs = brs.replace(
    'm.productTitle.text = "CONNECT FOUR"\n  m.lobbyGroup.visible = false',
    'if m.activeRoute <> invalid then m.productTitle.text = m.activeRoute.title else m.productTitle.text = "CONNECT FOUR"\n  m.lobbyGroup.visible = false',
)

# Remove every legacy QR destination, not only the first one. Older builds can
# contain more than one showJoinPanel implementation; every generated QR must
# use the active game's registered controller path.
brs = re.sub(
    r'if m\.activeGame = "color-clash"\n\s*joinUrl = m\.baseUrl \+ "/color-clash\?room=" \+ m\.roomCode\n\s*else\n\s*joinUrl = m\.baseUrl \+ "/\?room=" \+ m\.roomCode\n\s*end if',
    'joinUrl = controllerUrlForGame(m.baseUrl, m.activeRoute, m.roomCode)',
    brs,
)
brs = re.sub(
    r'joinUrl = m\.baseUrl \+ "/\?room=" \+ m\.roomCode\n\s*if m\.activeGame = "color-clash" then joinUrl \+= "&game=color-clash"',
    'joinUrl = controllerUrlForGame(m.baseUrl, m.activeRoute, m.roomCode)',
    brs,
)
brs = re.sub(
    r'joinUrl = m\.baseUrl \+ "/\?room=" \+ m\.roomCode',
    'joinUrl = controllerUrlForGame(m.baseUrl, m.activeRoute, m.roomCode)',
    brs,
)

required = [
    'v3.0 MODULE ARCHITECTURE',
    'sub launchSelectedGame()',
    'routeForSelection(m.lobbySelection)',
    'controllerUrlForGame(m.baseUrl, m.activeRoute, m.roomCode)',
    'startColorClashPairing()',
]
for item in required:
    if item not in brs:
        raise SystemExit(f'Module architecture marker missing: {item}')

if 'joinUrl = m.baseUrl + "/?room=" + m.roomCode' in brs:
    raise SystemExit('Legacy Connect Four-only QR destination remains')

router_calls = brs.count('joinUrl = controllerUrlForGame(m.baseUrl, m.activeRoute, m.roomCode)')
if router_calls < 1:
    raise SystemExit('No modular QR routing calls found')

brs_path.write_text(brs)
PY

rm -f "$OUTPUT_ZIP"
(
  cd "$WORK_DIR"
  zip -qr "$OUTPUT_ZIP" .
)

unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'manifest' >/dev/null
unzip -Z1 "$OUTPUT_ZIP" | grep -Fx 'components/GameRouter.brs' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.xml | grep -F 'pkg:/components/GameRouter.brs' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'v3.0 MODULE ARCHITECTURE' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'routeForSelection(m.lobbySelection)' >/dev/null
unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'controllerUrlForGame(m.baseUrl, m.activeRoute, m.roomCode)' >/dev/null
unzip -p "$OUTPUT_ZIP" components/GameRouter.brs | grep -F '"color-clash"' >/dev/null
unzip -p "$OUTPUT_ZIP" components/GameRouter.brs | grep -F '"/color-clash"' >/dev/null
if unzip -p "$OUTPUT_ZIP" components/MainScene.brs | grep -F 'joinUrl = m.baseUrl + "/?room=" + m.roomCode' >/dev/null; then
  echo "Legacy Connect Four-only QR destination remains" >&2
  exit 1
fi

echo "Created TN Game v3.0 module architecture: $OUTPUT_ZIP"
