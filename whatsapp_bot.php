<?php
/**
 * WhatsApp bot — risponde con Claude ai numeri in whitelist.
 *
 * Webhook della WhatsApp Business Platform (Cloud API di Meta). Riceve i messaggi,
 * chiede la risposta all'API di Claude (solo testo, nessuno strumento, nessun accesso ai file)
 * e la rimanda su WhatsApp. Chi non è in whitelist viene ignorato.
 *
 * Chiavi nel .env (stesso file letto da _Ykan.php: accanto a questo file o nella cartella sopra):
 *   WA_VERIFY_TOKEN     stringa a scelta, da ripetere nella configurazione webhook su Meta
 *   WA_APP_SECRET       App secret dell'app Meta (firma X-Hub-Signature-256)
 *   WA_ACCESS_TOKEN     token di accesso (meglio quello permanente di un system user)
 *   WA_PHONE_NUMBER_ID  ID del numero mittente (non il numero di telefono)
 *   WA_WHITELIST        numeri ammessi, formato internazionale senza "+", separati da virgola
 *   ANTHROPIC_KEY       chiave API Anthropic (già usata da _Ykan)
 *   WA_CLAUDE_MODEL     opzionale, default claude-opus-5-5
 *   WA_BOT_PROMPT       opzionale, istruzioni del bot (altrimenti quelle qui sotto)
 */

const WA_GRAPH = 'https://graph.facebook.com/v21.0/';
const WA_HISTORY_TURNS = 20;            // messaggi ricordati per numero (utente + bot)
const WA_DATA_DIR = __DIR__ . '/_wa_bot'; // storico e log, negato via .htaccess

function waEnv(string $key): string {
    static $env = null;
    if ($env === null) {
        $env = [];
        foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env'] as $f) {
            if (!is_readable($f)) continue;
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = ltrim($line);
                if ($line === '' || $line[0] === '#' || ($eq = strpos($line, '=')) === false) continue;
                $k = trim(substr($line, 0, $eq));
                $v = trim(substr($line, $eq + 1));
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
                if ($k !== '' && !array_key_exists($k, $env)) $env[$k] = $v; // il primo file vince
            }
        }
    }
    return (string)($env[$key] ?? '');
}

function waLog(string $msg): void {
    if (!is_dir(WA_DATA_DIR)) @mkdir(WA_DATA_DIR, 0700, true);
    @file_put_contents(WA_DATA_DIR . '/bot.log', date('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function waWhitelist(): array {
    return array_values(array_filter(array_map(fn($n) => preg_replace('/\D/', '', $n), explode(',', waEnv('WA_WHITELIST')))));
}

function waHistoryFile(string $from): string {
    return WA_DATA_DIR . '/chat_' . preg_replace('/\D/', '', $from) . '.json';
}

function waAskClaude(array $history): string {
    $key = waEnv('ANTHROPIC_KEY');
    if ($key === '') throw new RuntimeException('ANTHROPIC_KEY non configurata');
    $system = waEnv('WA_BOT_PROMPT') ?: "Sei l'assistente WhatsApp di Yuri. Rispondi in italiano (o nella lingua di chi scrive), "
        . "in modo cordiale e breve: massimo 500 caratteri, testo semplice senza markdown. "
        . "Non hai accesso a file, calendari o sistemi: se ti chiedono qualcosa che non sai, dillo e proponi che Yuri risponderà appena possibile. "
        . "Non promettere appuntamenti, prezzi o impegni a nome di Yuri.";
    $payload = [
        'model' => waEnv('WA_CLAUDE_MODEL') ?: 'claude-opus-5-5',
        'max_tokens' => 2048,
        'system' => $system,
        'messages' => $history,
        'output_config' => ['effort' => 'low'],
        'fallbacks' => 'default', // se il modello rifiuta, l'API riprova da sola su un modello di riserva
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$res, true);
    if ($code !== 200) throw new RuntimeException('Anthropic HTTP ' . $code . ' — ' . ($j['error']['message'] ?? substr((string)$res, 0, 200)));
    if (($j['stop_reason'] ?? '') === 'refusal') return 'Su questo non posso aiutarti, mi spiace. Yuri ti risponderà appena possibile.';
    $text = '';
    foreach ($j['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    return trim($text);
}

function waSend(string $to, string $text): void {
    $ch = curl_init(WA_GRAPH . waEnv('WA_PHONE_NUMBER_ID') . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . waEnv('WA_ACCESS_TOKEN')],
        CURLOPT_POSTFIELDS => json_encode([
            'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text',
            'text' => ['body' => mb_substr($text, 0, 4000)],
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) waLog("invio a $to fallito: HTTP $code " . substr((string)$res, 0, 300));
}

function waHandle(array $msg): void {
    $from = preg_replace('/\D/', '', (string)($msg['from'] ?? ''));
    if ($from === '' || !in_array($from, waWhitelist(), true)) { waLog("ignorato $from (non in whitelist)"); return; }

    // Meta può rimandare lo stesso evento: ogni id di messaggio si gestisce una volta sola
    $seenFile = WA_DATA_DIR . '/seen.json';
    $seen = json_decode((string)@file_get_contents($seenFile), true) ?: [];
    $id = (string)($msg['id'] ?? '');
    if ($id !== '' && in_array($id, $seen, true)) return;
    $seen[] = $id;
    @file_put_contents($seenFile, json_encode(array_slice($seen, -500)), LOCK_EX);

    if (($msg['type'] ?? '') !== 'text') { waSend($from, 'Per ora leggo solo messaggi di testo 🙂'); return; }
    $text = trim((string)($msg['text']['body'] ?? ''));
    if ($text === '') return;

    $hf = waHistoryFile($from);
    if (in_array(mb_strtolower($text), ['/reset', 'reset'], true)) { @unlink($hf); waSend($from, 'Conversazione azzerata.'); return; }

    $history = json_decode((string)@file_get_contents($hf), true) ?: [];
    $history[] = ['role' => 'user', 'content' => $text];
    $history = array_slice($history, -WA_HISTORY_TURNS);
    while ($history && $history[0]['role'] !== 'user') array_shift($history); // la conversazione deve iniziare da un messaggio utente

    try {
        $reply = waAskClaude($history);
    } catch (Throwable $e) {
        waLog("errore Claude per $from: " . $e->getMessage());
        return;
    }
    if ($reply === '') return;
    $history[] = ['role' => 'assistant', 'content' => $reply];
    @file_put_contents($hf, json_encode($history, JSON_UNESCAPED_UNICODE), LOCK_EX);
    waSend($from, $reply);
    waLog("risposto a $from");
}

// --- Verifica del webhook (GET, una volta sola dalla console Meta) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = waEnv('WA_VERIFY_TOKEN');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $token !== '' && hash_equals($token, (string)($_GET['hub_verify_token'] ?? ''))) {
        echo (string)($_GET['hub_challenge'] ?? '');
    } else {
        http_response_code(403);
    }
    exit;
}

// --- Messaggi in arrivo (POST) ---
$raw = (string)file_get_contents('php://input');
$secret = waEnv('WA_APP_SECRET');
$sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($secret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) {
    http_response_code(403);
    exit;
}

// Risponde subito 200 a Meta (altrimenti ritenta), poi lavora
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
ignore_user_abort(true);
set_time_limit(120);

$body = json_decode($raw, true) ?: [];
foreach ($body['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        foreach ($change['value']['messages'] ?? [] as $msg) waHandle($msg);
    }
}
