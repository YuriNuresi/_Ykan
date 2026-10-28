#!/usr/bin/env node
'use strict';

// Costruisce dist/ykan-bridge/: ykan-bridge.exe (Node SEA) + node_modules (ws, node-pty)
// + LEGGIMI.txt. Uso: npm run build   (Windows x64)
// Crea anche ../downloads/*.zip (Bridge + estensione) scaricabili da Settings → Strumenti.

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
// WhatsApp: Baileys e le sue dipendenze (tutto l'albero, letto dai package.json)
const copyTree = (name, seen = new Set()) => {
    if (seen.has(name) || !fs.existsSync(path.join(nm, name, 'package.json'))) return;
    seen.add(name);
    fs.cpSync(path.join(nm, name), path.join(outNm, name), { recursive: true });
    const pkg = JSON.parse(fs.readFileSync(path.join(nm, name, 'package.json'), 'utf8'));
    for (const dep of Object.keys(Object.assign({}, pkg.dependencies, pkg.optionalDependencies))) copyTree(dep, seen);
};
const waSeen = new Set();
for (const m of ['@whiskeysockets/baileys', 'qrcode', 'pino']) copyTree(m, waSeen);
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

// Versione accanto all'exe: il Bridge la legge da qui (dentro l'exe non c'e' package.json)
const version = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8')).version;
fs.writeFileSync(path.join(out, 'package.json'), JSON.stringify({ name: 'ykan-bridge', version }, null, 2));

// Icona nella tray + avvio automatico nascosto: stessi script della cartella sorgente
// (tray.ps1 usa ykan-bridge.exe se lo trova accanto a se').
for (const f of ['tray.ps1', 'autostart-hidden.vbs', 'install-autostart.bat', 'uninstall-autostart.bat']) {
    fs.copyFileSync(path.join(root, f), path.join(out, f));
}

fs.writeFileSync(path.join(out, 'LEGGIMI.txt'),
`Ykan Bridge
===========
Estrai tutta la cartella (non avviare l'exe da dentro lo zip), poi:

- install-autostart.bat (una volta): il Bridge parte da solo a ogni accesso a Windows,
  in background, senza finestre. Per avviarlo subito: doppio click su autostart-hidden.vbs.
- Icona "Y" vicino all'orologio (verde = attivo, rossa = fermo): tasto destro per
  Riavvia / Apri log / Termina.
- Aggiornamenti: tasto destro sull'icona -> "Aggiorna alla x.y.z" (o il pulsante nella board).
  Il Bridge scarica la versione nuova da Ykan e si riavvia da solo (serve il PC collegato).
- uninstall-autostart.bat toglie l'avvio automatico.
- ykan-bridge.exe da solo apre invece una finestra di terminale (utile per vedere gli errori).

Il Bridge resta in ascolto su http://127.0.0.1:51820 (solo su questo PC).

Non spostare l'exe fuori da questa cartella: la cartella node_modules deve stargli accanto.
Serve Claude Code installato (comando "claude") per i pulsanti PLAY e Riprendi.
`);

// Zip per Settings → Strumenti della board: finiscono in ../downloads/ (da caricare su OVH
// accanto a _Ykan.php). Usa il tar di Windows (bsdtar), che sa scrivere zip.
const downloads = path.join(root, '..', 'downloads');
fs.mkdirSync(downloads, { recursive: true });
const tar = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe');
const zip = (name, parent, folder) => {
    const dest = path.join(downloads, name);
    rm(dest);
    execFileSync(tar, ['-a', '-cf', dest, '-C', parent, folder], { stdio: 'inherit' });
    console.log('Zip:', dest);
};
zip('ykan-bridge-windows.zip', path.join(root, 'dist'), 'ykan-bridge');

const ext = path.join(root, 'dist', 'ykan-usage-extension');
fs.cpSync(path.join(root, '..', 'extension'), ext, { recursive: true });
zip('ykan-usage-extension.zip', path.join(root, 'dist'), 'ykan-usage-extension');

console.log('\nFatto:', out);
