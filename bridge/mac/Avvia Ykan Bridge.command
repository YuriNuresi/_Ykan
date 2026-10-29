#!/bin/bash
# Ykan Bridge per macOS (beta): installa le dipendenze la prima volta e avvia il Bridge.
# Lascia aperta questa finestra del Terminale finché usi Ykan.
cd "$(dirname "$0")" || exit 1

# Node da Homebrew o dall'installer ufficiale (Finder non carica il PATH della shell)
export PATH="/opt/homebrew/bin:/usr/local/bin:$HOME/.local/bin:$HOME/.claude/local:$PATH"

if ! command -v node >/dev/null 2>&1; then
    echo "Serve Node.js 20 o superiore: scaricalo da https://nodejs.org (versione LTS), installalo e riapri questo file."
    open "https://nodejs.org/it/download"
    read -r -p "Premi Invio per chiudere."
    exit 1
fi

if [ ! -d node_modules ] || [ package.json -nt node_modules ]; then
    echo "Prima installazione delle dipendenze (un minuto)..."
    npm install --omit=dev || { read -r -p "npm install non riuscito. Premi Invio per chiudere."; exit 1; }
    touch node_modules
fi

command -v claude >/dev/null 2>&1 || echo "Attenzione: comando 'claude' non trovato. Installa Claude Code per PLAY e Riprendi."

echo "Ykan Bridge in ascolto su http://127.0.0.1:51820 — lascia aperta questa finestra."
exec node bridge-server.js
