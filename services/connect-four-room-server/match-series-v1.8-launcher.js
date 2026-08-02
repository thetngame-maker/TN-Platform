const fs = require('fs');
const path = require('path');

const basePath = path.join(__dirname, 'server.js');
const seriesBasePath = path.join(__dirname, '.server-v1.8-series-base.js');
const runtimePath = path.join(__dirname, '.server-v1.8-series-runtime.js');
let source = fs.readFileSync(basePath, 'utf8');

function replaceOnce(input, needle, replacement, label) {
  if (!input.includes(needle)) throw new Error(`v1.8 patch failed: ${label}`);
  return input.replace(needle, replacement);
}

source = replaceOnce(source,
  "    players: publicPlayers(room),\n    game: room.game",
  "    players: publicPlayers(room),\n    series: room.series,\n    game: room.game",
  'public room series');

source = replaceOnce(source,
  "    players: [],\n    game: null",
  "    players: [],\n    series: { bestOf: 3, winsNeeded: 2, round: 1, scores: {}, complete: false, winnerId: '', winnerName: '' },\n    game: null",
  'room series state');

source = replaceOnce(source,
  "function startRoom(room) {\n  if (room.players.length < 2) return false;\n  room.status = 'playing';\n  room.game = {\n    board: emptyBoard(),\n    turnPlayerId: room.players[0].id,",
  "function startRoom(room) {\n  if (room.players.length < 2) return false;\n  if (!room.series) room.series = { bestOf: 3, winsNeeded: 2, round: 1, scores: {}, complete: false, winnerId: '', winnerName: '' };\n  for (const player of room.players) if (room.series.scores[player.id] == null) room.series.scores[player.id] = 0;\n  room.status = 'playing';\n  const starterIndex = (Math.max(1, room.series.round) - 1) % room.players.length;\n  room.game = {\n    board: emptyBoard(),\n    turnPlayerId: room.players[starterIndex].id,",
  'round starter');
source = source.replace("    message: `${room.players[0].name} goes first`", "    message: `${room.players[starterIndex].name} goes first • Round ${room.series.round}`");
source = source.replace("  if (room.players[0].isBot) scheduleBot(room);", "  if (room.players[starterIndex].isBot) scheduleBot(room);");
source = replaceOnce(source,
  "    room.game.message = `${player.name} connected four!`;",
  "    room.series.scores[player.id] = (room.series.scores[player.id] || 0) + 1;\n    const score = room.series.scores[player.id];\n    if (score >= room.series.winsNeeded) {\n      room.series.complete = true; room.series.winnerId = player.id; room.series.winnerName = player.name;\n      room.game.message = `${player.name} wins the best-of-${room.series.bestOf} series!`;\n    } else room.game.message = `${player.name} wins round ${room.series.round}!`;",
  'score round win');
source = replaceOnce(source,
  "    code: room.code, mode: room.mode, difficulty: room.difficulty, version: room.version,\n    screen: finished ? 'finished' : 'playing',",
  "    code: room.code, mode: room.mode, difficulty: room.difficulty, version: Date.now(), series: room.series,\n    screen: finished ? 'finished' : 'playing',",
  'tv series state');
source = source.replace("title: finished ? (game.winnerName ? `${game.winnerName.toUpperCase()} WINS!` : 'DRAW GAME')", "title: finished ? (room.series?.complete ? `${room.series.winnerName.toUpperCase()} WINS THE SERIES!` : (game.winnerName ? `${game.winnerName.toUpperCase()} WINS ROUND ${room.series?.round || 1}!` : 'DRAW GAME'))");
source = source.replace("subtitle: finished ? `${game.message || 'Game finished'}  •  Press OK to play again`", "subtitle: finished ? `${game.message || 'Game finished'}  •  ${room.series?.complete ? 'Press OK for a new series' : 'Press OK for the next round'}`");
source = replaceOnce(source,
  "      const data = await readBody(req);\n      const playerName = String(data.name || '').trim().slice(0, 18);",
  "      const data = await readBody(req);\n      if (room.players.filter(player => !player.isBot).length === 0) { const requestedBestOf = Number(data.bestOf); const bestOf = [1,3,5].includes(requestedBestOf) ? requestedBestOf : 3; room.series.bestOf = bestOf; room.series.winsNeeded = Math.floor(bestOf / 2) + 1; }\n      const playerName = String(data.name || '').trim().slice(0, 18);",
  'series selection');
source = replaceOnce(source,
  "    if (req.method === 'POST' && action === 'restart') {\n      if (room.players.length < 2) return json(res, 409, { error: 'Two players are required' });\n      startRoom(room); return json(res, 200, tvState(room));\n    }",
  "    if (req.method === 'POST' && action === 'restart') {\n      if (room.players.length < 2) return json(res, 409, { error: 'Two players are required' });\n      if (room.series?.complete) { room.series.round = 1; room.series.scores = {}; room.series.complete = false; room.series.winnerId = ''; room.series.winnerName = ''; } else room.series.round = Math.max(1, Number(room.series?.round || 1)) + 1;\n      startRoom(room); return json(res, 200, tvState(room));\n    }",
  'series restart');
source = source.replace("'Cache-Control': 'no-store',", "'Cache-Control': 'no-store, no-cache, must-revalidate',\n    'Pragma': 'no-cache',\n    'Expires': '0',");
fs.writeFileSync(seriesBasePath, source);

let phoneSource = fs.readFileSync(path.join(__dirname, 'phone-polish-v1.6.js'), 'utf8');
phoneSource = replaceOnce(phoneSource, "const sourcePath = path.join(__dirname, 'server.js');", "const sourcePath = path.join(__dirname, '.server-v1.8-series-base.js');", 'phone series base');
phoneSource = phoneSource.replace('PHONE CONTROLLER v1.6', 'PHONE CONTROLLER v1.8.1 SERIES SYNC');
phoneSource = phoneSource.replace('.colors{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}', '.series-picker{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:8px 0 4px}.series-choice{padding:12px 6px;background:#102a21;color:#a7bdb5;font-weight:900}.series-choice.selected{background:#f97316;color:#fff}.series-score{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:10px;background:#071510;border:1px solid #21483c;border-radius:16px;padding:12px;margin:4px 0 16px;text-align:center}.series-score strong{font-size:24px}.series-meta{color:#739b8e;font-size:13px;font-weight:800}.colors{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}');
phoneSource = phoneSource.replace('<label>Choose your color</label><div class="colors">${swatches}</div>', '<label>Match length</label><div class="series-picker"><button class="series-choice" type="button" data-bestof="1" onclick="selectSeries(1)">SINGLE</button><button class="series-choice selected" type="button" data-bestof="3" onclick="selectSeries(3)">BEST OF 3</button><button class="series-choice" type="button" data-bestof="5" onclick="selectSeries(5)">BEST OF 5</button></div><label>Choose your color</label><div class="colors">${swatches}</div>');
phoneSource = phoneSource.replace('<div class="status" id="statusLabel"></div><div class="columns">', '<div id="seriesScore" class="series-score"><div><span id="leftName">PLAYER 1</span><br><strong id="leftScore">0</strong></div><div class="series-meta"><span id="seriesLabel">BEST OF 3</span><br><span id="roundLabel">ROUND 1</span></div><div><span id="rightName">PLAYER 2</span><br><strong id="rightScore">0</strong></div></div><div class="status" id="statusLabel"></div><div class="columns">');
phoneSource = phoneSource.replace("let activeRoomCode='',playerToken='',playerId='',selectedColor='orange',", "let activeRoomCode='',playerToken='',playerId='',selectedColor='orange',selectedBestOf=3,");
phoneSource = phoneSource.replace('function selectColor(color){', "function selectSeries(bestOf){selectedBestOf=bestOf;document.querySelectorAll('.series-choice').forEach(button=>button.classList.toggle('selected',Number(button.dataset.bestof)===bestOf));}function selectColor(color){");
phoneSource = phoneSource.replace("body:JSON.stringify({name:playerName,color:selectedColor})", "body:JSON.stringify({name:playerName,color:selectedColor,bestOf:selectedBestOf})");
phoneSource = phoneSource.replace("if(room.game){const current=", "if(room.series){const p0=room.players[0],p1=room.players[1];document.getElementById('leftName').textContent=p0?.name||'PLAYER 1';document.getElementById('rightName').textContent=p1?.name||'PLAYER 2';document.getElementById('leftScore').textContent=room.series.scores?.[p0?.id]||0;document.getElementById('rightScore').textContent=room.series.scores?.[p1?.id]||0;document.getElementById('seriesLabel').textContent=room.series.bestOf===1?'SINGLE GAME':'BEST OF '+room.series.bestOf;document.getElementById('roundLabel').textContent=room.series.complete?'SERIES FINAL':'ROUND '+room.series.round;}if(room.game){const current=");
phoneSource = phoneSource.replace("const polishedPage = String.raw`", "const polishedPage = `");
phoneSource = phoneSource.replace('.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:17px}', '.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:17px}.session-actions{display:none;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}.session-actions.show{display:grid}.session-button{padding:15px 10px;background:#123126;color:#fff;font-weight:900}.session-button.primary{background:#f97316}');
phoneSource = phoneSource.replace('<div class="hint" id="hintLabel">Watch the TV for the board.</div></section>', '<div class="hint" id="hintLabel">Watch the TV for the board.</div><div id="sessionActions" class="session-actions"><button id="playAgainButton" class="session-button primary" type="button" onclick="playAgain()">NEXT ROUND</button><button class="session-button" type="button" onclick="leaveGame()">LEAVE GAME</button></div></section>');
phoneSource = phoneSource.replace('async function dropPiece(column)', "async function playAgain(){if(!activeRoomCode)return;try{const response=await fetch('/api/rooms/'+activeRoomCode+'/restart',{method:'POST'});if(!response.ok)throw new Error('Could not continue series');document.getElementById('sessionActions').classList.remove('show');toast('Round started');pollRoom();}catch(error){toast(error.message);}}function leaveGame(){clearTimeout(pollTimer);activeRoomCode='';playerToken='';playerId='';lastVersion=-1;movePending=false;joinPending=false;document.getElementById('gamePanel').hidden=true;document.getElementById('joinPanel').hidden=false;document.getElementById('joinButton').disabled=false;document.getElementById('joinButton').textContent='JOIN GAME';document.getElementById('sessionActions').classList.remove('show');history.replaceState({},'',location.pathname);window.scrollTo({top:0,behavior:'smooth'});}async function dropPiece(column)");
phoneSource = phoneSource.replace("const finished=room.status==='finished';const turn=", "const finished=room.status==='finished';document.getElementById('sessionActions').classList.toggle('show',finished);document.getElementById('playAgainButton').textContent=room.series?.complete?'NEW SERIES':'NEXT ROUND';const turn=");
fs.writeFileSync(runtimePath, phoneSource);
require(runtimePath);
