<?php
/**
 * Ykan <-> Google Tasks sync.
 *
 * Scope concordato: lo stato completato/non-completato è bidirezionale.
 * Titolo/descrizione/priorità/scadenza viaggiano SOLO Ykan -> Google (un
 * completamento fatto dal telefono su Google Tasks chiude anche la card su
 * Ykan; un'edit del testo fatta su Google Tasks NON torna indietro su Ykan).
 *
 * Una Google Tasklist per swimlane Ykan (stesso nome), creata al bisogno.
 * Mapping persistito dentro _Ykan_data.json:
 *   - swimlane.google_tasklist_id
 *   - card.google_task_id
 *
 * Richiamato da:
 *  - mcp.php, tool "sync_google_tasks" (on-demand)
 *  - cron.php, ogni tick (l'hosting gira al massimo 1 volta/ora)
 *
 * Limita le chiamate API per invocazione (YKAN_TASKS_SYNC_OP_CAP) per stare
 * dentro i tempi di esecuzione di PHP su hosting condiviso: un primo backfill
 * grande si completa su più tick successivi, in modo idempotente e sicuro
 * (le card già mappate vengono semplicemente saltate/aggiornate, non ricreate).
 */

declare(strict_types=1);

const YKAN_TASKS_SYNC_OP_CAP = 40;

if (!function_exists('ykan_tasks_sync')) {

function ykan_ts_load_env(): array {
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

/** Marks a card (by index) completed, honoring auto_regenerate — mirrors mcp.php's complete_task case. */
function ykan_ts_complete_card(array &$data, int $i): void {
    $c = &$data['cards'][$i];
    if (!empty($c['archived'])) { unset($c); return; }
    $c['archived'] = true;
    $c['archived_at'] = date('Y-m-d H:i:s');
    if (!empty($c['auto_regenerate'])) {
        $delay = (int)($c['regenerate_delay_days'] ?? 0);
        $due = $delay > 0 ? date('Y-m-d', strtotime("+$delay days")) : null;
        $maxSeq = 0;
        foreach (($data['cards'] ?? []) as $cc) {
            if (isset($cc['seq']) && (int)$cc['seq'] > $maxSeq) $maxSeq = (int)$cc['seq'];
        }
        $nextSeq = max($maxSeq + 1, (int)($data['config']['next_seq'] ?? 1));
        $data['config']['next_seq'] = $nextSeq + 1;
        $data['cards'][] = [
            'id' => 'card_' . bin2hex(random_bytes(8)), 'seq' => $nextSeq, 'title' => $c['title'],
            'description' => $c['description'] ?? '', 'priority' => $c['priority'] ?? 'medium',
            'due_date' => $due, 'next_check' => $due, 'label_id' => $c['label_id'] ?? null,
            'column_id' => $data['columns'][0]['id'] ?? null, 'swimlane_id' => $c['swimlane_id'],
            'archived' => false, 'auto_regenerate' => true, 'regenerate_delay_days' => $delay,
            'position' => 0, 'created_at' => date('Y-m-d H:i:s'),
            'regenerated_count' => ($c['regenerated_count'] ?? 0) + 1, 'regenerated_from' => $c['id'],
            'google_task_id' => null,
        ];
    }
    unset($c);
}

function ykan_tasks_sync(): array {
    $problems = [];
    $env = ykan_ts_load_env();
    $clientId     = (string)($env['GOOGLE_CLIENT_ID'] ?? '');
    $clientSecret = (string)($env['GOOGLE_CLIENT_SECRET'] ?? '');
    $refreshToken = (string)($env['GMAIL_REFRESH_TOKEN'] ?? '');
    if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
        return ['ok' => false, 'error' => 'Google non configurato (client id/secret/refresh token mancanti in .env).'];
    }

    $gmailMailer = __DIR__ . '/../gmail_mailer.php';
    if (!is_file($gmailMailer)) return ['ok' => false, 'error' => 'gmail_mailer.php mancante sul server.'];
    require_once $gmailMailer;
    if (!function_exists('gmail_access_token') || !function_exists('gmail_api')) {
        return ['ok' => false, 'error' => 'gmail_mailer.php non espone le funzioni attese.'];
    }

    $dataFile = __DIR__ . '/_Ykan_data.json';
    if (!is_file($dataFile)) return ['ok' => false, 'error' => '_Ykan_data.json non trovato.'];
    $data = json_decode((string) file_get_contents($dataFile), true);
    if (!is_array($data)) return ['ok' => false, 'error' => '_Ykan_data.json illeggibile.'];

    try {
        $token = gmail_access_token($clientId, $clientSecret, $refreshToken);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Token refresh fallito: ' . $e->getMessage()];
    }

    $stats = ['tasklistsCreated' => 0, 'created' => 0, 'updated' => 0, 'completedOnGoogle' => 0, 'completedOnYkan' => 0];
    $opCount = 0;

    foreach (($data['swimlanes'] ?? []) as $li => $lane) {
        if ($opCount >= YKAN_TASKS_SYNC_OP_CAP) break;

        try {
            // 1) Ensure a Google tasklist exists for this swimlane.
            $tasklistId = $data['swimlanes'][$li]['google_tasklist_id'] ?? null;
            if (!$tasklistId) {
                $res = gmail_api($token, 'POST', 'https://tasks.googleapis.com/tasks/v1/users/@me/lists', ['title' => $lane['name']]);
                $opCount++;
                $tasklistId = $res['id'] ?? null;
                if (!$tasklistId) { $problems[] = "'{$lane['name']}': impossibile creare la tasklist."; continue; }
                $data['swimlanes'][$li]['google_tasklist_id'] = $tasklistId;
                $stats['tasklistsCreated']++;
            }

            // 2) Fetch existing Google tasks in this list (paginated).
            $googleTasks = [];
            $pageToken = null;
            do {
                $url = "https://tasks.googleapis.com/tasks/v1/lists/$tasklistId/tasks?showCompleted=true&showHidden=true&maxResults=100";
                if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);
                $page = gmail_api($token, 'GET', $url);
                $opCount++;
                foreach (($page['items'] ?? []) as $t) $googleTasks[$t['id']] = $t;
                $pageToken = $page['nextPageToken'] ?? null;
            } while ($pageToken);

            // 3) Walk this swimlane's cards.
            foreach (($data['cards'] ?? []) as $ci => $card) {
                if (($card['swimlane_id'] ?? '') !== $lane['id']) continue;
                if ($opCount >= YKAN_TASKS_SYNC_OP_CAP) break;

                $taskId = $data['cards'][$ci]['google_task_id'] ?? null;
                $existsOnGoogle = $taskId && isset($googleTasks[$taskId]);

                if (empty($card['archived'])) {
                    $due = !empty($card['due_date']) ? $card['due_date'] . 'T00:00:00.000Z' : null;
                    $notes = trim((string)($card['description'] ?? ''));

                    if (!$existsOnGoogle) {
                        $body = ['title' => $card['title'], 'notes' => $notes, 'status' => 'needsAction'];
                        if ($due) $body['due'] = $due;
                        $res = gmail_api($token, 'POST', "https://tasks.googleapis.com/tasks/v1/lists/$tasklistId/tasks", $body);
                        $opCount++;
                        if (!empty($res['id'])) {
                            $data['cards'][$ci]['google_task_id'] = $res['id'];
                            $stats['created']++;
                        }
                    } else {
                        $gt = $googleTasks[$taskId];
                        if (($gt['status'] ?? '') === 'completed') {
                            // Completed on Google -> close it on Ykan too (honors auto_regenerate).
                            ykan_ts_complete_card($data, $ci);
                            $stats['completedOnYkan']++;
                        } else {
                            // Only PATCH if something actually differs — comparing is free (in-memory,
                            // from the single list call above), patching is not. Without this check the
                            // op cap gets eaten by re-patching already-synced tasks on every tick, and
                            // swimlanes further down the list never get a turn.
                            $currentDue = $gt['due'] ?? null;
                            $needsPatch = ($gt['title'] ?? '') !== $card['title']
                                || ($gt['notes'] ?? '') !== $notes
                                || $currentDue !== $due;
                            if ($needsPatch) {
                                $body = ['title' => $card['title'], 'notes' => $notes];
                                if ($due) $body['due'] = $due;
                                gmail_api($token, 'PATCH', "https://tasks.googleapis.com/tasks/v1/lists/$tasklistId/tasks/$taskId", $body);
                                $opCount++;
                                $stats['updated']++;
                            }
                        }
                    }
                } else {
                    // Archived on Ykan -> mark completed on Google if not already.
                    if ($existsOnGoogle && ($googleTasks[$taskId]['status'] ?? '') !== 'completed') {
                        gmail_api($token, 'PATCH', "https://tasks.googleapis.com/tasks/v1/lists/$tasklistId/tasks/$taskId", ['status' => 'completed']);
                        $opCount++;
                        $stats['completedOnGoogle']++;
                    }
                }
            }
        } catch (Throwable $e) {
            $problems[] = "'{$lane['name']}': " . $e->getMessage();
        }
    }

    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $summary = sprintf(
        '%d tasklist create, %d task creati, %d aggiornati, %d completati su Google, %d completati su Ykan',
        $stats['tasklistsCreated'], $stats['created'], $stats['updated'], $stats['completedOnGoogle'], $stats['completedOnYkan']
    );
    if ($opCount >= YKAN_TASKS_SYNC_OP_CAP) $summary .= ' (limite operazioni raggiunto, continua al prossimo giro)';
    if ($problems) $summary .= ' — problemi: ' . implode('; ', $problems);

    return ['ok' => true, 'summary' => $summary, 'stats' => $stats];
}

}
