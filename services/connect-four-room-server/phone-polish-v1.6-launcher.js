const fs = require('fs');
const path = require('path');

const sourcePath = path.join(__dirname, 'phone-polish-v1.6.js');
const runtimePath = path.join(__dirname, '.phone-polish-v1.6-fixed.js');

let source = fs.readFileSync(sourcePath, 'utf8');
const original = 'const polishedPage = String.raw`';
const fixed = 'const polishedPage = `';

if (!source.includes(original)) {
  throw new Error('Could not locate the v1.6 phone template declaration');
}

source = source.replace(original, fixed);
fs.writeFileSync(runtimePath, source);
require(runtimePath);
