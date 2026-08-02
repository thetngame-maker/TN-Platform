const fs = require('fs');
const path = require('path');

const sourcePath = path.join(__dirname, 'phone-polish-v1.6.js');
const runtimePath = path.join(__dirname, '.phone-session-v1.7-runtime.js');
let source = fs.readFileSync(sourcePath, 'utf8');

source = source.replace('PHONE CONTROLLER v1.6', 'PHONE CONTROLLER v1.7');
source = source.replace(
  '.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:17px}',
  '.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:17px}.session-actions{display:none;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}.session-actions.show{display:grid}.session-button{padding:15px 10px;background:#123126;color:#fff;font-weight:900}.session-button.primary{background:#f97316}'
);
source = source.replace(
  '<div class="hint" id="hintLabel">Watch the TV for the board.</div></section>',
  '<div class="hint" id="hintLabel">Watch the TV for the board.</div><div id="sessionActions" class="session-actions"><button class="session-button primary" type="button" onclick="playAgain()">PLAY AGAIN</button><button class="session-button" type="button" onclick="leaveGame()">LEAVE GAME</button></div></section>'
);
source = source.replace(
  'async function dropPiece(column)',
  "async function playAgain(){if(!activeRoomCode)return;try{const response=await fetch('/api/rooms/'+activeRoomCode+'/restart',{method:'POST'});if(!response.ok)throw new Error('Could not restart');document.getElementById('sessionActions').classList.remove('show');toast('New round started');pollRoom();}catch(error){toast(error.message);}}function leaveGame(){clearTimeout(pollTimer);activeRoomCode='';playerToken='';playerId='';lastVersion=-1;movePending=false;joinPending=false;document.getElementById('gamePanel').hidden=true;document.getElementById('joinPanel').hidden=false;document.getElementById('joinButton').disabled=false;document.getElementById('joinButton').textContent='JOIN GAME';document.getElementById('sessionActions').classList.remove('show');history.replaceState({},'',location.pathname);window.scrollTo({top:0,behavior:'smooth'});}async function dropPiece(column)"
);
source = source.replace(
  "const finished=room.status==='finished';const turn=",
  "const finished=room.status==='finished';document.getElementById('sessionActions').classList.toggle('show',finished);const turn="
);
source = source.replace("const polishedPage = String.raw`", "const polishedPage = `");

fs.writeFileSync(runtimePath, source);
require(runtimePath);
