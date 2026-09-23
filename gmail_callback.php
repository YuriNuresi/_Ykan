<?php
/**
 * ONE-TIME SETUP — callback OAuth per gmail_auth.php.
 * Riceve il ?code= da Google, lo scambia per un refresh_token e lo salva
 * in .env (root) come GMAIL_REFRESH_TOKEN. Vedi gmail_auth.php per l'avvio.
 */
declare(strict_types=1);

function gc_load_env(): array {
    $out = [];
    foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env'] as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '' && !array_key_exists($key, $out)) $out[$key] = $val;
        }
    }
    return $out;
}

$env = gc_load_env();
$secret = (string)($env['MCP_SECRET'] ?? '');
$state = (string)($_GET['state'] ?? '');
if ($secret === '' || !hash_equals($secret, $state)) {
    http_response_code(401);
    echo 'Stato non valido o scaduto. Riparti da gmail_auth.php.';
    exit;
}

if (!empty($_GET['error'])) {
    echo 'Errore restituito da Google: ' . htmlspecialchars((string)$_GET['error']);
    exit;
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    echo 'Nessun "code" ricevuto nella query string.';
    exit;
}

$clientId     = (string)($env['GOOGLE_CLIENT_ID'] ?? '');
$clientSecret = (string)($env['GOOGLE_CLIENT_SECRET'] ?? '');
$redirectUri  = 'https://ykan.portale3d.it/gmail_callback.php';

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    echo 'Errore curl durante lo scambio del code: ' . htmlspecialchars($err);
    exit;
}

$data = json_decode($resp, true);
if (!is_array($data) || empty($data['refresh_token'])) {
    echo '<pre>Nessun refresh_token nella risposta di Google:' . "\n"
        . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
    echo '<p>Se avevi già autorizzato questa app in passato, Google a volte non';
    echo ' ri-emette il refresh_token. Revoca l\'accesso da';
    echo ' <a href="https://myaccount.google.com/permissions" target="_blank">myaccount.google.com/permissions</a>';
    echo ' (con ai.portale3d@gmail.com) e riprova da gmail_auth.php.</p>';
    exit;
}

$refreshToken = (string)$data['refresh_token'];

// Salva in .env di root (una cartella sopra ykan/), sostituendo la riga se già presente.
$envPath = dirname(__DIR__) . '/.env';
$content = is_file($envPath) ? (string)file_get_contents($envPath) : '';
$line = 'GMAIL_REFRESH_TOKEN=' . $refreshToken;
if (preg_match('/^GMAIL_REFRESH_TOKEN=.*$/m', $content)) {
    $content = preg_replace('/^GMAIL_REFRESH_TOKEN=.*$/m', $line, $content);
} else {
    $content = rtrim($content) . "\n\n# ── Gmail (ai.portale3d@gmail.com) ─────────────────────────────────────────\n"
        . $line . "\nGMAIL_FROM=ai.portale3d@gmail.com\n";
}
$ok = @file_put_contents($envPath, $content);

if ($ok !== false) {
    echo '<h2>✅ Google collegato (Gmail + Tasks)</h2>';
    echo '<p>Refresh token salvato in .env come <code>GMAIL_REFRESH_TOKEN</code>.</p>';
    echo '<p><b>Ora cancella gmail_auth.php e gmail_callback.php dal server.</b></p>';
} else {
    echo '<h2>⚠️ Token ottenuto ma il salvataggio in .env è fallito</h2>';
    echo '<p>Copia questo valore a mano in .env come <code>GMAIL_REFRESH_TOKEN</code>:</p>';
    echo '<pre>' . htmlspecialchars($refreshToken) . '</pre>';
}
