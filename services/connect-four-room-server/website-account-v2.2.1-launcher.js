const fs = require('fs');
const path = require('path');

const sourcePath = path.join(__dirname, 'website-account-v2.2-launcher.js');
const fixedPath = path.join(__dirname, '.website-account-v2.2.1-fixed-launcher.js');
let source = fs.readFileSync(sourcePath, 'utf8');

const markerBlock = /const accountMarker = [\s\S]*?if \(!accountSource\.includes\(accountMarker\)\) throw new Error\('v2\.2 account bridge patch failed: account marker'\);/;
if (!markerBlock.test(source)) {
  throw new Error('v2.2.1 fix failed: account marker block not found');
}

source = source.replace(markerBlock, String.raw`const accountMarker = "\"launcher = launcher.replace(marker, profilePatch + '\\\\n' + accountPatch + '\\\\n' + marker);\"";
if (!accountSource.includes(accountMarker)) throw new Error('v2.2 account bridge patch failed: account marker');`);

source = source.replace(
  'accountSource = accountSource.replace(\n  accountMarker,\n  "launcher = launcher.replace(marker, profilePatch + \'\\\\n\' + accountPatch + \'\\\\n\' + websitePatch + \'\\\\n\' + marker);"\n);',
  'accountSource = accountSource.replace(\n  accountMarker,\n  "\\\"launcher = launcher.replace(marker, profilePatch + \'\\\\\\\\n\' + accountPatch + \'\\\\\\\\n\' + websitePatch + \'\\\\\\\\n\' + marker);\\\""\n);'
);

fs.writeFileSync(fixedPath, source);
require(fixedPath);
