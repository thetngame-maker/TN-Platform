const fs = require('fs');
const path = require('path');

const sourcePath = path.join(__dirname, 'guest-profile-v2.0-launcher.js');
const launcherPath = path.join(__dirname, '.account-signin-v2.1-runtime-launcher.js');
let launcher = fs.readFileSync(sourcePath, 'utf8');

launcher = launcher.replace(
  "const launcherPath = path.join(__dirname, '.guest-profile-v2.0-runtime-launcher.js');",
  "const launcherPath = path.join(__dirname, '.account-signin-v2.1-profile-launcher.js');"
);

const marker = "launcher = launcher.replace(marker, profilePatch + '\\n' + marker);";
if (!launcher.includes(marker)) throw new Error('v2.1 account patch failed: profile marker');

const accountPatch = String.raw`
phoneSource = phoneSource.replace('PHONE CONTROLLER v2.0 GUEST PROFILE', 'PHONE CONTROLLER v2.1 ACCOUNT FOUNDATION');
phoneSource = phoneSource.replace(
  '.profile-card{display:grid;',
  '.account-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:0 0 14px}.account-button{padding:12px 8px;background:#123126;color:#fff;font-weight:900}.account-button.primary{background:#f97316}.account-note{color:#739b8e;font-size:12px;line-height:1.4;margin:-5px 0 14px}.account-badge{display:inline-flex;align-items:center;gap:7px;color:#7fd5b3;font-size:12px;font-weight:900}.profile-card{display:grid;'
);
phoneSource = phoneSource.replace(
  '<section id="joinPanel" class="card"><h2>Join the board</h2><div class="profile-card">',
  '<section id="joinPanel" class="card"><h2>Join the board</h2><div class="account-actions"><button class="account-button primary" type="button" onclick="useGuestProfile()">CONTINUE AS GUEST</button><button class="account-button" type="button" onclick="openAccountLink()">SIGN IN</button></div><div id="accountNote" class="account-note">Guest play is ready. Sign-in linking is the foundation for syncing your TN Game profile across devices.</div><div class="profile-card">'
);
phoneSource = phoneSource.replace(
  "const PROFILE_KEY='tnGameGuestProfileV2';",
  "const PROFILE_KEY='tnGameGuestProfileV2',ACCOUNT_KEY='tnGameAccountV21';"
);
phoneSource = phoneSource.replace(
  'function loadProfile(){',
  "function loadAccount(){try{return JSON.parse(localStorage.getItem(ACCOUNT_KEY)||'{}')}catch{return {}}}function saveAccount(account){localStorage.setItem(ACCOUNT_KEY,JSON.stringify(account||{}));renderAccount();}function renderAccount(){const account=loadAccount();const note=document.getElementById('accountNote');if(!note)return;if(account.id){note.innerHTML='<span class=\"account-badge\">● SIGNED IN AS '+escapeHtml(account.name||'TN GAME PLAYER')+'</span><br>Account ID '+escapeHtml(account.id)+' • Profile sync adapter ready';}else note.textContent='Guest play is ready. Sign-in linking is the foundation for syncing your TN Game profile across devices.';}function escapeHtml(value){return String(value||'').replace(/[&<>\"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]));}function useGuestProfile(){saveAccount({});toast('Continuing as guest');}function openAccountLink(){const current=loadProfile();const name=prompt('TN Game account display name',current.name||'');if(!name||name.trim().length<2)return;const account={id:'TN-'+Math.random().toString(36).slice(2,8).toUpperCase(),name:name.trim().slice(0,18),linkedAt:new Date().toISOString(),provider:'foundation'};saveAccount(account);saveProfile({name:account.name});document.getElementById('playerNameInput').value=account.name;toast('Account foundation linked');}function loadProfile(){"
);
phoneSource = phoneSource.replace(
  "function restoreProfile(){const profile=loadProfile();",
  "function restoreProfile(){renderAccount();const account=loadAccount();const profile=loadProfile();if(account.name)profile.name=account.name;"
);
phoneSource = phoneSource.replace(
  "saveProfile({name:playerName,color:selectedColor});",
  "saveProfile({name:playerName,color:selectedColor});const account=loadAccount();if(account.id&&account.name!==playerName)saveAccount({...account,name:playerName});"
);
`;

launcher = launcher.replace(
  marker,
  "launcher = launcher.replace(marker, profilePatch + '\\n' + accountPatch + '\\n' + marker);"
);
launcher = launcher.replace(
  "const profilePatch = String.raw`",
  "const accountPatch = String.raw`" + accountPatch.replace(/`/g, '\\`') + "`;\n\nconst profilePatch = String.raw`"
);

fs.writeFileSync(launcherPath, launcher);
require(launcherPath);
