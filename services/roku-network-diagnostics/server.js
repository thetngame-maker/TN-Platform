import http from 'node:http';

const PORT = Number(process.env.PORT || 8090);
let state = { counter: 0, color: 'green', message: 'Hello Roku', version: 1, updatedAt: Date.now() };

function sendJson(res, status, body) {
  const payload = Buffer.from(JSON.stringify(body), 'utf8');
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'content-length': payload.length,
    'cache-control': 'no-store, no-cache, must-revalidate',
    'access-control-allow-origin': '*',
    'access-control-allow-headers': 'content-type',
    'access-control-allow-methods': 'GET,POST,OPTIONS',
    connection: 'close'
  });
  res.end(payload);
}

function page() {
  return `<!doctype html><html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:system-ui;background:#06110e;color:white;margin:0;padding:28px}main{max-width:650px;margin:auto}h1{color:#ff7315}.value{font-size:96px;text-align:center;margin:30px}.row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}button{font-size:22px;padding:20px;border:0;border-radius:14px;background:#ff7315;color:white;font-weight:700}.colors{margin-top:18px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.status{margin-top:24px;color:#9fd2be}</style><main><h1>TN Game Network Diagnostics</h1><p>Use these controls to change the value shown on the Roku.</p><div class="value" id="value">0</div><div class="row"><button onclick="act('decrement')">−1</button><button onclick="act('reset')">Reset</button><button onclick="act('increment')">+1</button></div><div class="colors"><button onclick="color('red')">Red</button><button onclick="color('blue')">Blue</button><button onclick="color('green')">Green</button><button onclick="color('gold')">Gold</button></div><p class="status" id="status">Ready</p><script>async function refresh(){const r=await fetch('/api/test/state',{cache:'no-store'});const s=await r.json();value.textContent=s.counter;status.textContent='Version '+s.version+' • '+s.message+' • '+s.color}async function act(name){await fetch('/api/test/'+name,{method:'POST'});refresh()}async function color(name){await fetch('/api/test/color',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({color:name})});refresh()}setInterval(refresh,1000);refresh()</script></main></html>`;
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  if (req.method === 'OPTIONS') return sendJson(res, 204, {});
  if (req.method === 'GET' && url.pathname === '/api/test/state') return sendJson(res, 200, state);
  if (req.method === 'GET' && url.pathname === '/api/test/ping') return sendJson(res, 200, { ok: true, now: Date.now() });
  if (req.method === 'POST' && url.pathname === '/api/test/increment') state.counter += 1;
  else if (req.method === 'POST' && url.pathname === '/api/test/decrement') state.counter -= 1;
  else if (req.method === 'POST' && url.pathname === '/api/test/reset') state.counter = 0;
  else if (req.method === 'POST' && url.pathname === '/api/test/color') {
    let raw=''; for await (const chunk of req) raw += chunk;
    try { const data=JSON.parse(raw||'{}'); state.color=String(data.color||'green'); } catch {}
  } else if (req.method === 'GET' && url.pathname === '/') {
    const html = Buffer.from(page(), 'utf8');
    res.writeHead(200, {'content-type':'text/html; charset=utf-8','content-length':html.length,'cache-control':'no-store',connection:'close'});
    return res.end(html);
  } else return sendJson(res, 404, { error: 'Not found' });
  state.version += 1; state.updatedAt = Date.now(); state.message = `Updated to ${state.counter}`;
  return sendJson(res, 200, state);
});

server.listen(PORT, '0.0.0.0', () => console.log(`TN Game Roku diagnostics: http://0.0.0.0:${PORT}`));
