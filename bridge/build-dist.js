#!/usr/bin/env node
'use strict';

// Costruisce dist/ykan-bridge/: ykan-bridge.exe (Node SEA) + node_modules (ws, node-pty)
// + LEGGIMI.txt. Uso: npm run build   (Windows x64)

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const root = __dirname;
const out = path.join(root, 'dist', 'ykan-bridge');
const blob = path.join(root, 'dist', 'sea-prep.blob');
const exe = path.join(out, 'ykan-bridge.exe');
const rm = p => fs.rmSync(p, { recursive: true, force: true });

rm(path.join(root, 'dist'));
fs.mkdirSync(out, { recursive: true });

const seaConfig = path.join(root, 'dist', 'sea-config.json');
fs.writeFileSync(seaConfig, JSON.stringify({
    main: path.join(root, 'bridge-server.js'),
    output: blob,
    disableExperimentalSEAWarning: true
}));
execFileSync(process.execPath, ['--experimental-sea-config', seaConfig], { stdio: 'inherit' });

fs.copyFileSync(process.execPath, exe);
execFileSync(process.execPath, [
    path.join(root, 'node_modules', 'postject', 'dist', 'cli.js'),
    exe, 'NODE_SEA_BLOB', blob,
    '--sentinel-fuse', 'NODE_SEA_FUSE_fce680ab2cc467b6e072b8b5df1996b2'
], { stdio: 'inherit' });

const nm = path.join(root, 'node_modules');
const outNm = path.join(out, 'node_modules');
fs.cpSync(path.join(nm, 'ws'), path.join(outNm, 'ws'), { recursive: true });
fs.cpSync(path.join(nm, 'node-pty'), path.join(outNm, 'node-pty'), {
    recursive: true,
    filter: src => {
        const rel = path.relative(path.join(nm, 'node-pty'), src).replace(/\\/g, '/');
        if (rel.endsWith('.pdb')) return false;
        if (rel === 'src' || rel.startsWith('src/') || rel === 'deps' || rel.startsWith('deps/')) return false;
        if (/^prebuilds\/(darwin|linux)/.test(rel)) return false;
        if (/^prebuilds\/win32-arm64/.test(rel)) return false;
        return true;
    }
});

fs.writeFileSync(path.join(out, 'LEGGIMI.txt'),
`Ykan Bridge
===========
Doppio click su ykan-bridge.exe: si apre una finestra e il Bridge resta in ascolto
su http://127.0.0.1:51820 (solo su questo PC). Lascia la finestra aperta mentre usi Ykan.

Non spostare l'exe fuori da questa cartella: la cartella node_modules deve stargli accanto.
Serve Claude Code installato (comando "claude") per i pulsanti PLAY e Riprendi.
`);

console.log('\nFatto:', out);
