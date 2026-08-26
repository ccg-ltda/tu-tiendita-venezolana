import { spawn } from 'node:child_process';

const options = { cwd: process.cwd(), env: process.env, stdio: 'inherit' };
const backend = spawn(process.execPath, ['--env-file-if-exists=.env', '--watch', 'server/index.js'], options);
const frontend = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1'], options);
const children = [backend, frontend];

function stop() {
  for (const child of children) {
    if (!child.killed) child.kill();
  }
}

process.on('SIGINT', () => { stop(); process.exit(0); });
process.on('SIGTERM', () => { stop(); process.exit(0); });
for (const child of children) child.on('exit', (code) => { if (code && code !== 0) { stop(); process.exit(code); } });
