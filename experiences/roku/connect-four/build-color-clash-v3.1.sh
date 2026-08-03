#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

ZIP="$ROOT_DIR/dist/tn-game-platform-v2.4-launcher.zip"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

./build-multi-game-launcher-v2.4.sh
unzip -q "$ZIP" -d "$WORK_DIR"

python3 - "$WORK_DIR/components/MainScene.brs" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
brs = path.read_text()

brs = brs.replace(
    'm.versionLabel.text = "v3.0 MODULE ARCHITECTURE"',
    'm.versionLabel.text = "v3.1 COLOR CLASH PLAYABLE"',
    1,
)

# Add the selected game to whichever line builds the room-creation URL.
# This deliberately uses endpoint detection instead of matching one exact
# BrightScript assignment, because earlier platform builders may rewrite it.
if '&game=color-clash' not in brs:
    lines = brs.splitlines()
    patched = False
    for index, line in enumerate(lines):
        if '/api/rooms/create?mode=' in line:
            indent = line[: len(line) - len(line.lstrip())]
            lines.insert(
                index + 1,
                indent + 'if m.activeRoute <> invalid and m.activeRoute.id = "color-clash" then url += "&game=color-clash"',
            )
            patched = True
            break
    if not patched:
        raise SystemExit('Room creation endpoint not found in generated MainScene.brs')
    brs = '\n'.join(lines) + ('\n' if brs.endswith('\n') else '')

apply_marker = '''sub applyTvState(data as object)
  if data.screen = invalid then return'''
apply_patch = '''sub applyTvState(data as object)
  if data.screen = invalid then return
  if data.game <> invalid and data.game = "color-clash"
    applyColorClashTvState(data)
    return
  end if'''
if 'sub applyColorClashTvState(data as object)' not in brs:
    if apply_marker not in brs:
        raise SystemExit('TV state marker not found')
    brs = brs.replace(apply_marker, apply_patch, 1)

player_marker = 'sub applyPlayers(players as dynamic, currentPlayerId as dynamic)\n'
color_renderer = '''sub applyColorClashTvState(data as object)
  m.state = data.screen
  applyPlayers(data.players, data.currentPlayerId)
  m.productTitle.text = "COLOR CLASH"
  m.roomLabel.text = "ROOM " + m.roomCode
  m.playersLabel.text = valueOr(data.playersLabel, "")

  if data.screen = "lobby"
    showJoinPanel()
    return
  end if

  m.joinGroup.visible = false
  m.title.text = valueOr(data.title, "COLOR CLASH")
  m.subtitle.text = valueOr(data.subtitle, "Match the color or number")
  m.boardGroup.visible = true
  clearBoard()

  if data.discard <> invalid and data.discard.color <> invalid
    setPieceUri(m.cells[2][3], colorAsset(data.discard.color))
  end if
end sub

'''
if 'sub applyColorClashTvState(data as object)' not in brs:
    if player_marker not in brs:
        raise SystemExit('Player renderer insertion marker not found')
    brs = brs.replace(player_marker, color_renderer + player_marker, 1)

required = [
    'v3.1 COLOR CLASH PLAYABLE',
    '&game=color-clash',
    'sub applyColorClashTvState(data as object)',
]
for marker in required:
    if marker not in brs:
        raise SystemExit(f'Color Clash v3.1 marker missing: {marker}')

path.write_text(brs)
PY

rm -f "$ZIP"
(
  cd "$WORK_DIR"
  zip -qr "$ZIP" .
)

unzip -p "$ZIP" components/MainScene.brs | grep -F 'v3.1 COLOR CLASH PLAYABLE' >/dev/null
unzip -p "$ZIP" components/MainScene.brs | grep -F '&game=color-clash' >/dev/null
unzip -p "$ZIP" components/MainScene.brs | grep -F 'sub applyColorClashTvState(data as object)' >/dev/null

echo "Created TN Game Color Clash v3.1 playable Roku package: $ZIP"
