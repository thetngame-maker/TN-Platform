const fs = require('fs');
const path = require('path');

const sourcePath = path.join(__dirname, 'account-signin-v2.1-launcher.js');
const runtimePath = path.join(__dirname, '.color-clash-phone-v2.3.1-runtime-launcher.js');
let source = fs.readFileSync(sourcePath, 'utf8');

source = source.replace(
  "const launcherPath = path.join(__dirname, '.account-signin-v2.1-runtime-launcher.js');",
  "const launcherPath = path.join(__dirname, '.color-clash-phone-v2.3.1-account-launcher.js');"
);

const marker = "const accountPatch = String.raw`";
if (!source.includes(marker)) throw new Error('Color Clash phone patch failed: account patch marker');

const gameBrandPatch = String.raw`
phoneSource = phoneSource.replace(
  '</body></html>',
  '<script>(function(){const params=new URLSearchParams(location.search);if(params.get("game")!=="color-clash")return;document.title="TN Game Color Clash";function applyBrand(){const headings=[...document.querySelectorAll("h1")];headings.forEach(el=>{if(el.textContent.includes("CONNECT FOUR"))el.innerHTML=el.innerHTML.replace("CONNECT FOUR","COLOR CLASH")});const controller=[...document.querySelectorAll("body *")].find(el=>el.children.length===0&&el.textContent.includes("PHONE CONTROLLER"));if(controller)controller.textContent="PHONE CONTROLLER v2.3 COLOR CLASH PAIRING";const joinHeading=document.querySelector("#joinPanel h2");if(joinHeading)joinHeading.textContent="Join Color Clash";const joinButton=document.getElementById("joinButton");if(joinButton&&!joinButton.disabled)joinButton.textContent="JOIN COLOR CLASH";const gameHeading=document.querySelector("#gamePanel h2");if(gameHeading&&gameHeading.textContent.includes("CONNECT FOUR"))gameHeading.textContent="COLOR CLASH";}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",applyBrand);else applyBrand();})();</script></body></html>'
);
`;

source = source.replace(
  marker,
  "const gameBrandPatch = String.raw`" + gameBrandPatch.replace(/`/g, '\\`') + "`;\n\n" + marker
);

const chainMarker = "profilePatch + '\\n' + accountPatch + '\\n' + marker";
if (!source.includes(chainMarker)) throw new Error('Color Clash phone patch failed: launcher chain marker');
source = source.replace(
  chainMarker,
  "profilePatch + '\\n' + accountPatch + '\\n' + gameBrandPatch + '\\n' + marker"
);

fs.writeFileSync(runtimePath, source);
require(runtimePath);
