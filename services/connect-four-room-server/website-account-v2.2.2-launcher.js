const fs = require('fs');
const path = require('path');

const sourcePath = path.join(__dirname, 'website-account-v2.2-launcher.js');
const fixedPath = path.join(__dirname, '.website-account-v2.2.2-fixed-launcher.js');
let source = fs.readFileSync(sourcePath, 'utf8');

const markerStart = source.indexOf('const accountMarker = ');
const markerEndNeedle = "if (!accountSource.includes(accountMarker)) throw new Error('v2.2 account bridge patch failed: account marker');";
const markerEnd = source.indexOf(markerEndNeedle, markerStart);
if (markerStart < 0 || markerEnd < 0) throw new Error('v2.2.2 fix failed: marker block not found');

const newMarkerBlock = `const accountMarkerMatch = accountSource.match(/launcher = launcher\\.replace\\(marker, profilePatch \\+ [\\s\\S]*? \\+ marker\\);/);
if (!accountMarkerMatch) throw new Error('v2.2 account bridge patch failed: account marker');
const accountMarker = accountMarkerMatch[0];`;
source = source.slice(0, markerStart) + newMarkerBlock + source.slice(markerEnd + markerEndNeedle.length);

const replaceStart = source.indexOf('accountSource = accountSource.replace(\n  accountMarker,');
const replaceEndNeedle = '\n);\naccountSource = accountSource.replace(\n  "const accountPatch = String.raw`"';
const replaceEnd = source.indexOf(replaceEndNeedle, replaceStart);
if (replaceStart < 0 || replaceEnd < 0) throw new Error('v2.2.2 fix failed: account replacement block not found');

const newReplaceBlock = `const accountReplacement = accountMarker.replace(
  / \\+ marker\\);$/,
  " + '\\\\n' + websitePatch + '\\\\n' + marker);"
);
accountSource = accountSource.replace(accountMarker, accountReplacement);`;
source = source.slice(0, replaceStart) + newReplaceBlock + source.slice(replaceEnd + 3);

fs.writeFileSync(fixedPath, source);
require(fixedPath);
