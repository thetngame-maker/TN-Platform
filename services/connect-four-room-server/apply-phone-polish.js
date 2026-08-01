const fs = require('fs');
const path = require('path');

const serverPath = path.join(__dirname, 'server.js');
let source = fs.readFileSync(serverPath, 'utf8');

if (!source.includes('PHONE CONTROLLER v1.6')) {
  source = source.replace(
    '<title>TN Game Connect Four</title><style>',
    '<title>TN Game Connect Four</title><style>'
  );

  source = source.replace(
    '.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:18px}</style>',
    '.hint{text-align:center;color:#739b8e;font-size:14px;margin-top:18px}.controller-version{text-align:right;color:#739b8e;font-size:12px;font-weight:900;letter-spacing:.08em;margin-bottom:12px}.connection{display:inline-flex;align-items:center;gap:8px;color:#8bb7aa;font-size:14px;font-weight:800;margin-bottom:12px}.connection-dot{width:9px;height:9px;border-radius:50%;background:#22c55e;box-shadow:0 0 14px #22c55e}.connection.reconnecting .connection-dot{background:#ffd54f;box-shadow:0 0 14px #ffd54f}.column{border-radius:999px;aspect-ratio:.72;padding:0;font-size:0;position:relative}.column::after{content:"";position:absolute;inset:18%;border-radius:50%;background:#ff7417;box-shadow:inset 0 6px 0 #ffb06f,0 8px 20px #ff741744}.column.ready{transform:translateY(-2px);box-shadow:0 0 0 2px #ffb06f inset,0 10px 26px #ff741744}.card.active{box-shadow:0 0 0 2px #ff7417,0 20px 50px #0007;animation:pulse 1.4s ease-in-out infinite alternate}@keyframes pulse{from{box-shadow:0 0 0 2px #ff7417,0 20px 50px #0007}to{box-shadow:0 0 0 4px #ff9a4a,0 20px 60px #ff741733}}</style>'
  );

  source = source.replace(
    '<body><main><h1><span class="brand">TN GAME</span><br>CONNECT FOUR</h1>',
    '<body><main><div class="controller-version">PHONE CONTROLLER v1.6</div><h1><span class="brand">TN GAME</span><br>CONNECT FOUR</h1>'
  );

  source = source.replace(
    '<section id="gamePanel" class="card" hidden><h2 id="turnLabel">Connected</h2>',
    '<section id="gamePanel" class="card" hidden><div id="connectionLabel" class="connection"><span class="connection-dot"></span><span>Connected</span></div><h2 id="turnLabel">Connected</h2>'
  );

  source = source.replace(
    "async function dropPiece(column){if(movePending)return;movePending=true;setButtons(false);try{",
    "async function dropPiece(column){if(movePending)return;movePending=true;document.getElementById('statusLabel').textContent='Sending move…';setButtons(false);try{"
  );

  source = source.replace(
    "document.getElementById('statusLabel').textContent=response.ok?'Piece dropped in column '+(column+1):(data.error||'Move failed');",
    "document.getElementById('statusLabel').textContent=response.ok?'Move accepted • Column '+(column+1):(data.error||'Move failed');"
  );

  source = source.replace(
    "const mine=room.status==='playing'&&room.game.turnPlayerId===playerId;",
    "const mine=room.status==='playing'&&room.game.turnPlayerId===playerId;document.getElementById('gamePanel').classList.toggle('active',mine);document.getElementById('connectionLabel').classList.remove('reconnecting');document.querySelector('#connectionLabel span:last-child').textContent='Connected';"
  );

  source = source.replace(
    "}catch{document.getElementById('statusLabel').textContent='Reconnecting…';setButtons(false);}pollTimer=setTimeout(pollRoom,500);}",
    "}catch{document.getElementById('statusLabel').textContent='Reconnecting…';document.getElementById('connectionLabel').classList.add('reconnecting');document.querySelector('#connectionLabel span:last-child').textContent='Reconnecting';setButtons(false);}pollTimer=setTimeout(pollRoom,500);}document.addEventListener('visibilitychange',()=>{if(!document.hidden&&activeRoomCode)pollRoom();});"
  );

  source = source.replace(
    "if (req.method === 'OPTIONS') return json(res, 204, {});",
    "if (req.method === 'OPTIONS') return json(res, 204, {});\n    if (req.method === 'GET' && url.pathname === '/version') return json(res, 200, { service: 'tn-game-connect-four', controller: 'v1.6', branch: process.env.RENDER_GIT_BRANCH || 'unknown' });"
  );

  fs.writeFileSync(serverPath, source);
  console.log('Applied PHONE CONTROLLER v1.6 runtime patch');
} else {
  console.log('PHONE CONTROLLER v1.6 patch already present');
}
