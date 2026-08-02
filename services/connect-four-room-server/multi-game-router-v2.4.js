const http = require('http');
const { URL } = require('url');

const originalCreateServer = http.createServer.bind(http);

function colorClashPage(roomCode = '') {
  const safeRoom = String(roomCode || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 8).toUpperCase();
  const colors = ['orange', 'gold', 'blue', 'purple', 'green', 'pink'];
  const swatches = colors.map((color, index) => `<button class="swatch ${index === 0 ? 'selected' : ''}" type="button" data-color="${color}" aria-label="${color}" onclick="selectColor('${color}')"></button>`).join('');
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#06110e"><title>TN Game Color Clash</title><style>
:root{color-scheme:dark;--orange:#f97316;--gold:#ffd54f;--blue:#3b82f6;--purple:#a855f7;--green:#22c55e;--pink:#ec4899;--panel:#0b1e18;--muted:#a7bdb5}*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;background:#06110e;color:#fff;margin:0;min-height:100vh;padding:calc(24px + env(safe-area-inset-top)) 22px calc(28px + env(safe-area-inset-bottom))}main{max-width:680px;margin:auto}.brand{color:var(--orange);font-weight:950}h1{font-size:clamp(34px,8vw,58px);line-height:1.03;margin:0 0 14px}.version{color:#739b8e;font-size:14px;font-weight:900;letter-spacing:.12em;margin-bottom:24px}.card{background:var(--panel);border:1px solid #21483c;border-radius:26px;padding:22px;box-shadow:0 20px 50px #0007}h2{font-size:clamp(28px,7vw,44px);margin:0 0 10px}p{color:var(--muted);line-height:1.5}label{display:block;color:var(--muted);font-weight:800;margin:14px 0 7px}input,button{font:inherit;border-radius:16px;border:1px solid #27604c}input{width:100%;padding:17px;background:#081712;color:#fff;font-size:20px}button{background:var(--orange);color:#fff;font-weight:900;padding:17px;cursor:pointer}.swatches{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0 22px}.swatch{width:58px;height:58px;border-radius:50%;padding:0;border:4px solid transparent}.swatch.selected{border-color:#fff;box-shadow:0 0 0 3px #21483c}.swatch[data-color=orange]{background:var(--orange)}.swatch[data-color=gold]{background:var(--gold)}.swatch[data-color=blue]{background:var(--blue)}.swatch[data-color=purple]{background:var(--purple)}.swatch[data-color=green]{background:var(--green)}.swatch[data-color=pink]{background:var(--pink)}.join{width:100%;font-size:22px}.status{margin-top:16px;min-height:24px;color:#7fd5b3;font-weight:800}.game-badge{display:inline-block;background:#123126;border:1px solid #27604c;border-radius:999px;padding:8px 12px;color:#7fd5b3;font-weight:900;margin-bottom:18px}</style></head><body><main><div class="brand">TN GAME</div><h1>COLOR CLASH</h1><div class="version">PHONE CONTROLLER v2.5 SCENE ROUTER</div><section class="card"><div class="game-badge">COLOR CLASH ROOM</div><h2>Join Color Clash</h2><p>This controller has its own permanent game route and no longer depends on Connect Four query-string routing.</p><label for="room">Room code</label><input id="room" maxlength="8" value="${safeRoom}" autocomplete="off"><label for="name">Player name</label><input id="name" maxlength="18" placeholder="Your name" autocomplete="name"><label>Choose your color</label><div class="swatches">${swatches}</div><button class="join" type="button" onclick="joinGame()">JOIN COLOR CLASH</button><div class="status" id="status"></div></section></main><script>
let selectedColor='orange';
function selectColor(color){selectedColor=color;document.querySelectorAll('.swatch').forEach(button=>button.classList.toggle('selected',button.dataset.color===color));}
async function joinGame(){const room=document.getElementById('room').value.trim().toUpperCase();const name=document.getElementById('name').value.trim();const status=document.getElementById('status');if(room.length<4){status.textContent='Enter the room code shown on the TV.';return}if(name.length<2){status.textContent='Enter your player name.';return}status.textContent='Joining Color Clash…';try{const response=await fetch('/api/rooms/'+encodeURIComponent(room)+'/join',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({name,color:selectedColor,game:'color-clash'})});const data=await response.json();if(!response.ok)throw new Error(data.error||'Could not join');localStorage.setItem('tnGameColorClashPlayer',JSON.stringify({room,player:data.player}));status.textContent='Connected to Color Clash. Watch the TV for the next step.';}catch(error){status.textContent=error.message;}}
</script></body></html>`;
}

http.createServer = function patchedCreateServer(listener) {
  return originalCreateServer(async (req, res) => {
    try {
      const url = new URL(req.url || '/', 'http://localhost');
      const isColorClashPath = url.pathname === '/color-clash';
      const isLegacyColorClashQuery = url.pathname === '/' && url.searchParams.get('game') === 'color-clash';
      if (req.method === 'GET' && (isColorClashPath || isLegacyColorClashQuery)) {
        const body = Buffer.from(colorClashPage(url.searchParams.get('room') || ''));
        res.writeHead(200, {
          'Content-Type': 'text/html; charset=utf-8',
          'Content-Length': body.length,
          'Cache-Control': 'no-store',
          Connection: 'close'
        });
        res.end(body);
        return;
      }
      return listener(req, res);
    } catch (error) {
      console.error(error);
      res.statusCode = 500;
      res.end('Server error');
    }
  });
};

require('./account-signin-v2.1-launcher.js');
