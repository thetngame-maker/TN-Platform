const fs = require('fs');
const path = require('path');

const sourcePath = path.join(__dirname, 'match-series-v1.8-launcher.js');
const launcherPath = path.join(__dirname, '.guest-profile-v2.0-runtime-launcher.js');
let launcher = fs.readFileSync(sourcePath, 'utf8');

// Keep the proven v1.8 series base filename because the generated phone-polish
// launcher explicitly reads that file. Only give the final runtime a v2.0 name.
launcher = launcher.replace(
  "const runtimePath = path.join(__dirname, '.server-v1.8-series-runtime.js');",
  "const runtimePath = path.join(__dirname, '.server-v2.0-profile-runtime.js');"
);

const marker = 'fs.writeFileSync(runtimePath, phoneSource);';
if (!launcher.includes(marker)) throw new Error('v2.0 profile patch failed: runtime write marker');

const profilePatch = String.raw`
phoneSource = phoneSource.replace('PHONE CONTROLLER v1.8.1 SERIES SYNC', 'PHONE CONTROLLER v2.0 GUEST PROFILE');
phoneSource = phoneSource.replace(
  '.colors{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}',
  '.profile-card{display:grid;grid-template-columns:54px 1fr auto;align-items:center;gap:12px;background:#071510;border:1px solid #21483c;border-radius:18px;padding:12px;margin:0 0 14px}.profile-avatar{width:54px;height:54px;border-radius:50%;display:grid;place-items:center;background:#f97316;color:#fff;font-size:24px;font-weight:950}.profile-name{font-weight:950;font-size:18px}.profile-meta{color:#739b8e;font-size:12px;font-weight:800}.profile-record{text-align:right;color:#a7bdb5;font-size:12px;font-weight:850}.colors{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}'
);
phoneSource = phoneSource.replace(
  '<section id="joinPanel" class="card"><h2>Join the board</h2>',
  '<section id="joinPanel" class="card"><h2>Join the board</h2><div class="profile-card"><div id="profileAvatar" class="profile-avatar">P</div><div><div id="profileName" class="profile-name">Guest Player</div><div class="profile-meta">Saved on this phone</div></div><div id="profileRecord" class="profile-record">0 W<br>0 L</div></div>'
);
phoneSource = phoneSource.replace(
  'function selectSeries(bestOf){',
  "const PROFILE_KEY='tnGameGuestProfileV2';let recordedSeriesKey='';function loadProfile(){try{return JSON.parse(localStorage.getItem(PROFILE_KEY)||'{}')}catch{return {}}}function saveProfile(patch){const current=loadProfile();const next={name:'',color:'orange',wins:0,losses:0,games:0,...current,...patch};localStorage.setItem(PROFILE_KEY,JSON.stringify(next));renderProfile(next);return next}function renderProfile(profile=loadProfile()){const name=profile.name||'Guest Player';document.getElementById('profileAvatar').textContent=name.trim().charAt(0).toUpperCase()||'P';document.getElementById('profileName').textContent=name;document.getElementById('profileRecord').innerHTML=(profile.wins||0)+' W<br>'+(profile.losses||0)+' L';}function restoreProfile(){const profile=loadProfile();if(profile.name)document.getElementById('playerNameInput').value=profile.name;if(profile.color&&colorHex[profile.color])selectColor(profile.color);renderProfile(profile);}function recordSeries(room){if(!room.series?.complete||!playerId)return;const key=room.code+':'+room.series.round+':'+room.series.winnerId+':'+room.series.bestOf;if(recordedSeriesKey===key||localStorage.getItem('tnGameRecordedSeries')===key)return;recordedSeriesKey=key;localStorage.setItem('tnGameRecordedSeries',key);const profile=loadProfile();const won=room.series.winnerId===playerId;saveProfile({games:(profile.games||0)+1,wins:(profile.wins||0)+(won?1:0),losses:(profile.losses||0)+(won?0:1)});toast(won?'Profile win recorded':'Profile result recorded');}function selectSeries(bestOf){"
);
phoneSource = phoneSource.replace(
  "selectedColor=data.room.players.find(player=>player.id===playerId)?.color||selectedColor;",
  "selectedColor=data.room.players.find(player=>player.id===playerId)?.color||selectedColor;saveProfile({name:playerName,color:selectedColor});"
);
phoneSource = phoneSource.replace(
  "if(room.series){const p0=room.players[0],p1=room.players[1];",
  "if(room.series){recordSeries(room);const p0=room.players[0],p1=room.players[1];"
);
phoneSource = phoneSource.replace(
  "document.addEventListener('visibilitychange',()=>{if(!document.hidden&&activeRoomCode)pollRoom();});",
  "document.addEventListener('visibilitychange',()=>{if(!document.hidden&&activeRoomCode)pollRoom();});document.addEventListener('DOMContentLoaded',restoreProfile);"
);
`;

launcher = launcher.replace(marker, profilePatch + '\n' + marker);
fs.writeFileSync(launcherPath, launcher);
require(launcherPath);
