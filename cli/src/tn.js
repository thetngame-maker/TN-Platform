#!/usr/bin/env node
import { build, createService, doctor, info, testPlatform } from './commands.js';

const [command = 'help', ...args] = process.argv.slice(2);
const json = args.includes('--json');
const filtered = args.filter(arg => arg !== '--json');

async function main() {
  switch (command) {
    case 'info': await info({ json }); break;
    case 'doctor': process.exitCode = (await doctor({ json })).ok ? 0 : 1; break;
    case 'build': await build(); break;
    case 'test': await testPlatform(); break;
    case 'create':
      if (filtered[0] !== 'service') throw new Error('Usage: tn create service <name>');
      await createService(filtered[1]); break;
    case 'help':
    default:
      console.log(`TN Platform CLI\n\nCommands:\n  tn info [--json]\n  tn doctor [--json]\n  tn test\n  tn build\n  tn create service <name>`);
  }
}

main().catch(error => { console.error(`TN CLI error: ${error.message}`); process.exitCode = 1; });
