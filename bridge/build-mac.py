# Crea ../downloads/ykan-bridge-macos-beta.zip: sorgenti del Bridge + "Avvia Ykan Bridge.command".
# Niente eseguibile: sul Mac serve Node; node-pty arriva da npm con i prebuild darwin.
# Python e non Node perche' lo zip deve portarsi i permessi di esecuzione (0755) del .command.
# Uso: python build-mac.py
import json, os, zipfile

root = os.path.dirname(os.path.abspath(__file__))
out = os.path.join(root, '..', 'downloads', 'ykan-bridge-macos-beta.zip')
pkg = json.load(open(os.path.join(root, 'package.json'), encoding='utf-8'))
pkg.pop('devDependencies', None)
pkg['scripts'] = {'start': 'node bridge-server.js'}

readme = f"""Ykan Bridge per macOS - BETA {pkg['version']}
================================
1. Installa Node.js LTS da https://nodejs.org e Claude Code (comando "claude").
2. Estrai lo zip in una cartella che non cancellerai (es. Documenti/Ykan Bridge).
3. Tasto destro su "Avvia Ykan Bridge.command" -> Apri -> Apri (solo la prima volta:
   il file non e' firmato). La prima volta installa le dipendenze, poi avvia il Bridge.
4. Lascia aperta la finestra del Terminale mentre usi Ykan, poi nella board:
   Settings -> Bridge -> Collega questo PC.

Beta: niente icona nella barra dei menu, niente avvio automatico e niente aggiornamento
automatico. Per aggiornare scarica il nuovo zip ed estrailo sopra la vecchia cartella.
Il Bridge accetta connessioni solo da questo Mac (127.0.0.1:51820).
"""

def add(z, name, data, mode=0o644):
    info = zipfile.ZipInfo('ykan-bridge-macos/' + name, (2026, 1, 1, 0, 0, 0))
    info.external_attr = (0o100000 | mode) << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    z.writestr(info, data)

with zipfile.ZipFile(out, 'w') as z:
    for f in ['bridge-server.js', 'wa-loader.cjs']:
        add(z, f, open(os.path.join(root, f), 'rb').read())
    add(z, 'package.json', json.dumps(pkg, indent=2) + '\n')
    add(z, 'LEGGIMI.txt', readme)
    cmd = open(os.path.join(root, 'mac', 'Avvia Ykan Bridge.command'), 'rb').read().replace(b'\r\n', b'\n')
    add(z, 'Avvia Ykan Bridge.command', cmd, 0o755)
print('Creato', os.path.normpath(out), os.path.getsize(out), 'byte')
