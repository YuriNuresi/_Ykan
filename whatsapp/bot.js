// Bot WhatsApp per _Ykan: si collega come dispositivo (come WhatsApp Web, QR code)
// e risponde con Claude ai soli numeri in whitelist. Claude risponde solo con testo:
// nessuno strumento, nessun accesso ai file del PC.
const fs = require('fs');
const path = require('path');
const pino = require('pino');
const qrcode = require('qrcode-terminal');
const Anthropic = require('@anthropic-ai/sdk');
const {
    default: makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion,
} = require('@whiskeysockets/baileys');

const DIR = __dirname;
const DATA = path.join(DIR, 'data'); // storico conversazioni e log
const HISTORY_TURNS = 20;

// .env minimale: CHIAVE=valore, righe con # ignorate
const env = {};
try {
    for (const line of fs.readFileSync(path.join(DIR, '.env'), 'utf8').split(/\r?\n/)) {
        const m = line.match(/^\s*([\w.]+)\s*=\s*(.*?)\s*$/);
        if (m && !line.trim().startsWith('#')) env[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
    }
} catch { console.error('Manca il file .env: copia .env.example in .env e compilalo.'); process.exit(1); }

const whitelist = new Set((env.WA_WHITELIST || '').split(',').map(n => n.replace(/\D/g, '')).filter(Boolean));
const model = env.WA_CLAUDE_MODEL || 'claude-opus-5-5';
const system = env.WA_BOT_PROMPT || "Sei l'assistente WhatsApp di Yuri. Rispondi nella lingua di chi scrive, "
    + 'in modo cordiale e breve: massimo 500 caratteri, testo semplice senza markdown. '
    + 'Non hai accesso a file, calendari o sistemi: se ti chiedono qualcosa che non sai, dillo e proponi che Yuri risponderà appena possibile. '
    + 'Non promettere appuntamenti, prezzi o impegni a nome di Yuri.';
if (!env.ANTHROPIC_KEY) { console.error('ANTHROPIC_KEY mancante nel .env'); process.exit(1); }
if (!whitelist.size) console.warn('WA_WHITELIST vuota: il bot non risponderà a nessuno.');

const claude = new Anthropic({ apiKey: env.ANTHROPIC_KEY });
fs.mkdirSync(DATA, { recursive: true });

const log = msg => {
    const line = new Date().toISOString() + ' ' + msg;
    console.log(line);
    fs.appendFileSync(path.join(DATA, 'bot.log'), line + '\n');
};
const historyFile = num => path.join(DATA, 'chat_' + num + '.json');
const readHistory = num => { try { return JSON.parse(fs.readFileSync(historyFile(num), 'utf8')); } catch { return []; } };

async function askClaude(history) {
    const res = await claude.beta.messages.create({
        model,
        max_tokens: 2048,
        system,
        messages: history,
        output_config: { effort: 'low' },
        betas: ['server-side-fallback-2026-07-01'],
        fallbacks: 'default', // se il modello rifiuta, l'API riprova da sola su un modello di riserva
    });
    if (res.stop_reason === 'refusal') return 'Su questo non posso aiutarti, mi spiace. Yuri ti risponderà appena possibile.';
    return res.content.filter(b => b.type === 'text').map(b => b.text).join('').trim();
}

// Il numero del mittente: con gli account recenti WhatsApp usa id anonimi (@lid),
// il numero vero arriva nei campi alternativi della chiave.
function senderNumber(key) {
    for (const jid of [key.remoteJidAlt, key.senderPn, key.remoteJid]) {
        if (jid && jid.endsWith('@s.whatsapp.net')) return jid.split('@')[0].split(':')[0];
    }
    return '';
}

async function start() {
    const { state, saveCreds } = await useMultiFileAuthState(path.join(DIR, 'auth'));
    const { version } = await fetchLatestBaileysVersion();
    const sock = makeWASocket({ version, auth: state, logger: pino({ level: 'warn' }), markOnlineOnConnect: false });

    sock.ev.on('creds.update', saveCreds);
    sock.ev.on('connection.update', ({ connection, lastDisconnect, qr }) => {
        if (qr) {
            console.log('\nWhatsApp sul telefono → Impostazioni → Dispositivi collegati → Collega un dispositivo, e inquadra:\n');
            qrcode.generate(qr, { small: true });
        }
        if (connection === 'open') log('Collegato. Whitelist: ' + [...whitelist].join(', '));
        if (connection === 'close') {
            const code = lastDisconnect?.error?.output?.statusCode;
            if (code === DisconnectReason.loggedOut) {
                log('Dispositivo scollegato dal telefono. Cancella la cartella auth e riavvia per un nuovo QR.');
                process.exit(1);
            }
            log('Connessione chiusa (' + code + '), riconnetto…');
            setTimeout(start, 3000);
        }
    });

    sock.ev.on('messages.upsert', async ({ messages, type }) => {
        if (type !== 'notify') return; // solo messaggi nuovi, non lo storico sincronizzato
        for (const msg of messages) {
            const jid = msg.key.remoteJid || '';
            if (msg.key.fromMe || jid.endsWith('@g.us') || jid === 'status@broadcast') continue; // niente gruppi né stati
            const num = senderNumber(msg.key);
            if (!whitelist.has(num)) continue;

            const text = (msg.message?.conversation || msg.message?.extendedTextMessage?.text || '').trim();
            if (!text) continue;
            if (['/reset', 'reset'].includes(text.toLowerCase())) {
                fs.rmSync(historyFile(num), { force: true });
                await sock.sendMessage(jid, { text: 'Conversazione azzerata.' });
                continue;
            }

            let history = readHistory(num);
            history.push({ role: 'user', content: text });
            history = history.slice(-HISTORY_TURNS);
            while (history.length && history[0].role !== 'user') history.shift(); // deve iniziare da un messaggio utente

            try {
                await sock.readMessages([msg.key]);
                await sock.sendPresenceUpdate('composing', jid);
                const reply = await askClaude(history);
                await sock.sendPresenceUpdate('paused', jid);
                if (!reply) continue;
                history.push({ role: 'assistant', content: reply });
                fs.writeFileSync(historyFile(num), JSON.stringify(history));
                await sock.sendMessage(jid, { text: reply });
                log('Risposto a ' + num);
            } catch (e) {
                log('Errore con ' + num + ': ' + e.message);
            }
        }
    });
}

start();
