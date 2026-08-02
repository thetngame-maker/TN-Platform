const fs = require('fs');
const path = require('path');

const baseServerPath = path.join(__dirname, 'server.js');
const authServerPath = path.join(__dirname, '.server-v2.2-account-base.js');
const matchSourcePath = path.join(__dirname, 'match-series-v1.8-launcher.js');
const matchRuntimePath = path.join(__dirname, '.match-series-v2.2-account-launcher.js');
const guestSourcePath = path.join(__dirname, 'guest-profile-v2.0-launcher.js');
const guestRuntimePath = path.join(__dirname, '.guest-profile-v2.2-account-launcher.js');
const accountSourcePath = path.join(__dirname, 'account-signin-v2.1-launcher.js');
const accountRuntimePath = path.join(__dirname, '.website-account-v2.2-runtime-launcher.js');

function replaceOnce(input, needle, replacement, label) {
  if (!input.includes(needle)) throw new Error(`v2.2 account bridge patch failed: ${label}`);
  return input.replace(needle, replacement);
}

// Add a same-origin server proxy for the WordPress JWT endpoint. This keeps
// passwords away from the Roku app and avoids browser CORS restrictions.
let serverSource = fs.readFileSync(baseServerPath, 'utf8');
serverSource = replaceOnce(
  serverSource,
  "const BOT_NAMES = { easy: 'Rookie Bot', medium: 'Smart Bot', hard: 'Expert Bot' };",
  "const BOT_NAMES = { easy: 'Rookie Bot', medium: 'Smart Bot', hard: 'Expert Bot' };\nconst TN_GAME_SITE_URL = String(process.env.TN_GAME_SITE_URL || 'https://thetngame.com').replace(/\\/$/, '');\nconst TN_GAME_JWT_PATH = String(process.env.TN_GAME_JWT_PATH || '/wp-json/jwt-auth/v1/token');",
  'auth settings'
);
serverSource = replaceOnce(
  serverSource,
  "    if ((req.method === 'POST' && url.pathname === '/api/rooms') || (req.method === 'GET' && url.pathname === '/api/rooms/create')) return json(res, 201, publicRoom(createRoom({ mode: url.searchParams.get('mode') || 'human', difficulty: url.searchParams.get('difficulty') || 'easy' })));",
  `    if (req.method === 'GET' && url.pathname === '/api/auth/config') return json(res, 200, { enabled: Boolean(TN_GAME_SITE_URL), siteUrl: TN_GAME_SITE_URL });
    if (req.method === 'POST' && url.pathname === '/api/auth/login') {
      const credentials = await readBody(req);
      const username = String(credentials.username || credentials.email || '').trim();
      const password = String(credentials.password || '');
      if (!username || password.length < 1) return json(res, 400, { error: 'Enter your TN Game email and password' });
      let authResponse;
      try {
        authResponse = await fetch(TN_GAME_SITE_URL + TN_GAME_JWT_PATH, {
          method: 'POST',
          headers: { 'content-type': 'application/json', accept: 'application/json' },
          body: JSON.stringify({ username, password })
        });
      } catch (error) {
        return json(res, 502, { error: 'Could not reach the TN Game website' });
      }
      let authData = {};
      try { authData = await authResponse.json(); } catch {}
      if (!authResponse.ok || !authData.token) {
        const message = authData.message || authData.error || 'TN Game sign-in failed';
        return json(res, authResponse.status >= 400 && authResponse.status < 500 ? 401 : 502, { error: String(message).replace(/<[^>]+>/g, '') });
      }
      return json(res, 200, {
        account: {
          id: String(authData.user_id || authData.id || authData.user_email || username),
          name: String(authData.user_display_name || authData.user_nicename || authData.user_email || username).slice(0, 40),
          email: String(authData.user_email || username),
          source: 'thetngame.com'
        },
        token: String(authData.token)
      });
    }
    if ((req.method === 'POST' && url.pathname === '/api/rooms') || (req.method === 'GET' && url.pathname === '/api/rooms/create')) return json(res, 201, publicRoom(createRoom({ mode: url.searchParams.get('mode') || 'human', difficulty: url.searchParams.get('difficulty') || 'easy' })));`,
  'auth routes'
);
fs.writeFileSync(authServerPath, serverSource);

// Point the proven series/profile/account launcher chain at the authenticated
// server base while preserving all working gameplay and profile behavior.
let matchSource = fs.readFileSync(matchSourcePath, 'utf8');
matchSource = replaceOnce(
  matchSource,
  "const basePath = path.join(__dirname, 'server.js');",
  "const basePath = path.join(__dirname, '.server-v2.2-account-base.js');",
  'series server base'
);
matchSource = matchSource.replace("const seriesBasePath = path.join(__dirname, '.server-v1.8-series-base.js');", "const seriesBasePath = path.join(__dirname, '.server-v2.2-series-base.js');");
matchSource = matchSource.replace("const runtimePath = path.join(__dirname, '.server-v1.8-series-runtime.js');", "const runtimePath = path.join(__dirname, '.server-v2.2-series-runtime.js');");
matchSource = matchSource.replace("const sourcePath = path.join(__dirname, '.server-v1.8-series-base.js');", "const sourcePath = path.join(__dirname, '.server-v2.2-series-base.js');");
fs.writeFileSync(matchRuntimePath, matchSource);

let guestSource = fs.readFileSync(guestSourcePath, 'utf8');
guestSource = replaceOnce(
  guestSource,
  "const sourcePath = path.join(__dirname, 'match-series-v1.8-launcher.js');",
  "const sourcePath = path.join(__dirname, '.match-series-v2.2-account-launcher.js');",
  'guest series launcher'
);
guestSource = guestSource.replace("const launcherPath = path.join(__dirname, '.guest-profile-v2.0-runtime-launcher.js');", "const launcherPath = path.join(__dirname, '.guest-profile-v2.2-account-runtime-launcher.js');");
fs.writeFileSync(guestRuntimePath, guestSource);

let accountSource = fs.readFileSync(accountSourcePath, 'utf8');
accountSource = replaceOnce(
  accountSource,
  "const sourcePath = path.join(__dirname, 'guest-profile-v2.0-launcher.js');",
  "const sourcePath = path.join(__dirname, '.guest-profile-v2.2-account-launcher.js');",
  'account guest launcher'
);
accountSource = accountSource.replace("const launcherPath = path.join(__dirname, '.account-signin-v2.1-runtime-launcher.js');", "const launcherPath = path.join(__dirname, '.account-signin-v2.2-website-runtime-launcher.js');");

const accountMarker = "launcher = launcher.replace(marker, profilePatch + '\\n' + accountPatch + '\\n' + marker);";
if (!accountSource.includes(accountMarker)) throw new Error('v2.2 account bridge patch failed: account marker');

const websitePatch = String.raw`
phoneSource = phoneSource.replace('PHONE CONTROLLER v2.1 ACCOUNT FOUNDATION', 'PHONE CONTROLLER v2.2 WEBSITE ACCOUNT');
phoneSource = phoneSource.replace(
  '.account-actions{display:grid;',
  '.website-login{display:none;background:#071510;border:1px solid #21483c;border-radius:16px;padding:14px;margin:0 0 14px}.website-login.show{display:block}.website-login input{margin:6px 0 10px}.website-login button{width:100%}.account-actions{display:grid;'
);
phoneSource = phoneSource.replace(
  '<div id="accountNote" class="account-note">Guest play is ready. Sign-in linking is the foundation for syncing your TN Game profile across devices.</div>',
  '<div id="accountNote" class="account-note">Continue as a guest or sign in with your thetngame.com account.</div><div id="websiteLogin" class="website-login"><label for="accountEmail">TN Game email or username</label><input id="accountEmail" type="text" autocomplete="username" placeholder="Email or username"><label for="accountPassword">Password</label><input id="accountPassword" type="password" autocomplete="current-password" placeholder="Password"><button id="accountLoginButton" type="button" onclick="submitWebsiteLogin()">SIGN IN TO TN GAME</button></div>'
);
phoneSource = phoneSource.replace(
  "function openAccountLink(){const current=loadProfile();const name=prompt('TN Game account display name',current.name||'');if(!name||name.trim().length<2)return;const account={id:'TN-'+Math.random().toString(36).slice(2,8).toUpperCase(),name:name.trim().slice(0,18),linkedAt:new Date().toISOString(),provider:'foundation'};saveAccount(account);saveProfile({name:account.name});document.getElementById('playerNameInput').value=account.name;toast('Account foundation linked');}",
  "function openAccountLink(){document.getElementById('websiteLogin').classList.toggle('show');setTimeout(()=>document.getElementById('accountEmail')?.focus(),60);}async function submitWebsiteLogin(){const username=document.getElementById('accountEmail').value.trim();const password=document.getElementById('accountPassword').value;const button=document.getElementById('accountLoginButton');if(!username||!password)return toast('Enter your TN Game login');button.disabled=true;button.textContent='SIGNING IN...';try{const response=await fetch('/api/auth/login',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({username,password})});const data=await response.json();if(!response.ok)throw new Error(data.error||'Sign-in failed');const account={...data.account,token:data.token,linkedAt:new Date().toISOString(),provider:'thetngame.com'};saveAccount(account);saveProfile({name:account.name});document.getElementById('playerNameInput').value=account.name;document.getElementById('websiteLogin').classList.remove('show');document.getElementById('accountPassword').value='';toast('Signed in to TN Game');}catch(error){toast(error.message);}finally{button.disabled=false;button.textContent='SIGN IN TO TN GAME';}}"
);
phoneSource = phoneSource.replace(
  "if(account.id){note.innerHTML='<span class=\"account-badge\">● SIGNED IN AS '+escapeHtml(account.name||'TN GAME PLAYER')+'</span><br>Account ID '+escapeHtml(account.id)+' • Profile sync adapter ready';}",
  "if(account.id){note.innerHTML='<span class=\"account-badge\">● SIGNED IN AS '+escapeHtml(account.name||'TN GAME PLAYER')+'</span><br>'+escapeHtml(account.email||'thetngame.com account')+' • Website account connected';}"
);
`;

accountSource = accountSource.replace(
  accountMarker,
  "launcher = launcher.replace(marker, profilePatch + '\\n' + accountPatch + '\\n' + websitePatch + '\\n' + marker);"
);
accountSource = accountSource.replace(
  "const accountPatch = String.raw`",
  "const websitePatch = String.raw`" + websitePatch.replace(/`/g, '\\`') + "`;\n\nconst accountPatch = String.raw`"
);
fs.writeFileSync(accountRuntimePath, accountSource);
require(accountRuntimePath);
