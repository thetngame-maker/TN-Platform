const fs = require('fs');
const path = require('path');
const Module = require('module');

const serverPath = path.join(__dirname, 'server.js');
let source = fs.readFileSync(serverPath, 'utf8');

const oldStyle = '.column.ready{box-shadow:0 0 0 2px #ffb06f inset,0 8px 22px #ff741733}.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:18px}';
const newStyle = `.column.ready{box-shadow:0 0 0 2px #ffb06f inset,0 8px 22px #ff741733}.phone-board-wrap{margin:6px 0 18px;padding:10px;background:#07130f;border:1px solid #21483c;border-radius:20px}.phone-board{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;aspect-ratio:7/6;padding:7px;background:#123c30;border-radius:15px;box-shadow:inset 0 0 0 1px #2a6955}.phone-cell{display:grid;place-items:center;min-width:0}.phone-slot{width:100%;aspect-ratio:1;border-radius:50%;background:#06110e;box-shadow:inset 0 3px 8px #0009,0 1px 0 #ffffff10}.phone-slot.piece-1,.phone-slot.piece-2{background:var(--piece-color);box-shadow:inset 0 2px 3px #fff5,0 2px 6px #0008}.phone-slot.last{outline:3px solid #fff;outline-offset:-3px;animation:lastPulse 1.1s ease-in-out infinite alternate}.phone-slot.winner{outline:3px solid #fff;outline-offset:-3px;filter:brightness(1.25)}@keyframes lastPulse{to{transform:scale(.84);box-shadow:0 0 16px var(--piece-color)}}.board-caption{text-align:center;color:#739b8e;font-size:13px;margin:8px 0 0}.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:18px}`;

const oldMarkup = '<div class="columns">${buttons}</div><div class="hint" id="hintLabel">Your phone is the controller. Watch the TV for the board.</div>';
const newMarkup = '<div class="phone-board-wrap"><div id="phoneBoard" class="phone-board" aria-label="Live Connect Four board"></div><div class="board-caption">Live board preview</div></div><div class="columns">${buttons}</div><div class="hint" id="hintLabel">Choose a column below.</div>';

const oldScriptStart = "let activeRoomCode='',playerToken='',playerId='',selectedColor='orange',pollTimer=null,movePending=false;const colorHex={orange:'#f97316',gold:'#ffd54f',blue:'#3b82f6',purple:'#a855f7',green:'#22c55e',pink:'#ec4899'};const columnButtons=()=>[...document.querySelectorAll('.column')];";
const newScriptStart = `${oldScriptStart}function renderPhoneBoard(room){const board=room?.game?.board||Array.from({length:6},()=>Array(7).fill(0));const players=room?.players||[];const last=room?.game?.lastMove;const winners=room?.game?.winningCells||[];const host=document.getElementById('phoneBoard');if(!host)return;host.innerHTML=board.flatMap((row,rowIndex)=>row.map((piece,colIndex)=>{const player=piece?players[piece-1]:null;const color=player?colorHex[player.color]:'#06110e';const isLast=last&&last.row===rowIndex&&last.col===colIndex;const isWinner=winners.some(cell=>cell.row===rowIndex&&cell.col===colIndex);return '<div class="phone-cell"><div class="phone-slot piece-'+piece+(isLast?' last':'')+(isWinner?' winner':'')+'" style="--piece-color:'+color+'"></div></div>';})).join('');}`;

const oldPoll = "if(room.game){const current=room.players.find(player=>player.id===room.game.turnPlayerId);";
const newPoll = "renderPhoneBoard(room);if(room.game){const current=room.players.find(player=>player.id===room.game.turnPlayerId);";

for (const [before, after, label] of [
  [oldStyle, newStyle, 'controller styles'],
  [oldMarkup, newMarkup, 'controller board markup'],
  [oldScriptStart, newScriptStart, 'board renderer'],
  [oldPoll, newPoll, 'poll render hook']
]) {
  if (!source.includes(before)) throw new Error(`Could not patch ${label}`);
  source = source.replace(before, after);
}

const runtimeModule = new Module(serverPath, module);
runtimeModule.filename = serverPath;
runtimeModule.paths = Module._nodeModulePaths(__dirname);
runtimeModule._compile(source, serverPath);
