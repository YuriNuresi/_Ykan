<?php
/**
 * ONE-TIME SETUP — rinnova il collegamento OAuth di ai.portale3d@gmail.com
 * aggiungendo lo scope Google Tasks (oltre a Gmail send/readonly già concessi).
 *
 * Apri: https://ykan.portale3d.it/gmail_auth.php?k=MCP_SECRET
 * Accedi come ai.portale3d@gmail.com e concedi l'accesso.
 * Google reindirizza a gmail_callback.php, che scambia il code per i token
 * e salva il nuovo GMAIL_REFRESH_TOKEN (con scope estesi) nel .env di root.
 *
 * Al termine: CANCELLA questo file e gmail_callback.php dal server.
 */
declare(strict_types=1);

function ga_load_env(): array {
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

$env = ga_load_env();
$secret = (string)($env['MCP_SECRET'] ?? '');
$provided = (string)($_GET['k'] ?? '');
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    echo 'Unauthorized.';
    exit;
}

$clientId = (string)($env['GOOGLE_CLIENT_ID'] ?? '');
if ($clientId === '') {
    echo 'GOOGLE_CLIENT_ID mancante in .env — configuralo prima di procedere.';
    exit;
}

$redirectUri = 'https://ykan.portale3d.it/gmail_callback.php';
$params = [
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/gmail.send '
                      . 'https://www.googleapis.com/auth/gmail.readonly '
                      . 'https://www.googleapis.com/auth/tasks',
    'access_type'   => 'offline',
    'prompt'        => 'consent',
    'state'         => $secret,
    'login_hint'    => 'ai.portale3d@gmail.com',
];
$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $url);
exit;
