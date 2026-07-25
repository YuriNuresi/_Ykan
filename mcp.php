<?php
/**
 * _Ykan MCP endpoint
 * -----------------------------------------------------------------------------
 * A single-file, remote MCP server (Streamable HTTP, stateless) that lets
 * Claude — from the mobile app or claude.ai in the cloud — manage the _Ykan
 * board AND read/edit files of the projects hosted on the same OVH account.
 *
 * It speaks JSON-RPC 2.0 over HTTP POST, which is exactly what shared hosting
 * (no persistent processes) can serve.
 *
 * ============================================================================
 * SETUP (3 steps)
 * ============================================================================
 * 1. Put this file next to _Ykan.php (same folder, e.g. ykan.portale3d.it/).
 * 2. Set the two constants below:
 *      - MCP_SECRET : a long random string. It IS the password. Treat the whole
 *                     URL as a secret.
 *      - MCP_ROOT   : absolute path to the folder that CONTAINS your projects
 *                     (usually your hosting web root). Every "Folder" you link
 *                     to a swimlane in _Ykan is resolved relative to this.
 * 3. In claude.ai -> Settings -> Connectors -> Add custom connector, paste:
 *      https://ykan.portale3d.it/mcp.php?k=YOUR_SECRET
 *    It then works in the Android app too.
 *
 * SECURITY MODEL
 * --------------
 * - Access is gated by MCP_SECRET (in the URL). Only HTTPS. Keep it out of git.
 * - File tools can ONLY touch folders you explicitly linked to a swimlane in
 *   _Ykan (the "Projects" popup). Nothing outside a linked folder is reachable.
 * - Every write is backed up first (<folder>/.ykan_backups/...) and logged
 *   (_ykan_mcp_audit.log next to this file).
 * ============================================================================
 */

declare(strict_types=1);

// ============================================================================
// CONFIG  —  loaded from a .env file, NOT hardcoded here.
// Put these two keys in the .env in your hosting root (the folder that CONTAINS
// your project folders, i.e. one level above this file):
//     MCP_SECRET=<a long random string — it is the password>
//     MCP_ROOT=<absolute path that contains your project folders>
// A local .env next to mcp.php (ykan/.env) also works and takes precedence.
// ============================================================================

/** Minimal .env reader: KEY=VALUE per line, ignores comments and blank lines. */
function mcp_load_env(): array {
    $out = [];
    // Search order: next to mcp.php first, then one directory up (hosting root).
    foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env'] as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            // Strip one layer of surrounding quotes, if present.
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '' && !array_key_exists($key, $out)) $out[$key] = $val; // first file wins
        }
    }
    return $out;
}

$__env = mcp_load_env();
define('MCP_SECRET', (string)($__env['MCP_SECRET'] ?? ''));
define('MCP_ROOT',   (string)($__env['MCP_ROOT']   ?? ''));

const MCP_DATA_FILE = __DIR__ . '/_Ykan_data.json';
const MCP_MAX_READ  = 500_000;   // max bytes returned by read_file
const MCP_MAX_WRITE = 2_000_000; // max bytes accepted by write_file
const MCP_AUDIT_LOG = __DIR__ . '/_ykan_mcp_audit.log';
const MCP_DB_BACKUP_DIR = __DIR__ . '/.db_backups';

// DB credentials from root .env (DB_*)
define('MCP_DB_HOST',    (string)($__env['DB_HOST']    ?? ''));
define('MCP_DB_PORT',    (int)   ($__env['DB_PORT']    ?? 3306));
define('MCP_DB_NAME',    (string)($__env['DB_NAME']    ?? ''));
define('MCP_DB_USER',    (string)($__env['DB_USER']    ?? ''));
define('MCP_DB_PASS',    (string)($__env['DB_PASS']    ?? ''));
define('MCP_DB_CHARSET', (string)($__env['DB_CHARSET'] ?? 'utf8mb4'));

// Mail settings
define('MCP_MAIL_TO',   (string)($__env['ADMIN_EMAILS'] ?? 'yurrena@gmail.com'));
define('MCP_MAIL_FROM', (string)($__env['SMTP_FROM']    ?? 'noreply@portale3d.it'));

// OVH API credentials (from .env). Create them once at
// https://www.ovh.com/auth/api/createToken — no setup page needed, just paste.
define('MCP_OVH_ENDPOINT',     (string)($__env['OVH_ENDPOINT']     ?? 'ovh-eu'));
define('MCP_OVH_APP_KEY',      (string)($__env['OVH_APP_KEY']      ?? ''));
define('MCP_OVH_APP_SECRET',   (string)($__env['OVH_APP_SECRET']   ?? ''));
define('MCP_OVH_CONSUMER_KEY', (string)($__env['OVH_CONSUMER_KEY'] ?? ''));

// ============================================================================
// Boilerplate: headers / CORS / method routing
// ============================================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Mcp-Protocol-Version');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Simple info page for humans hitting the URL in a browser (no secret leaked).
if ($method === 'GET' && !isset($_GET['mcp'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'name'      => '_Ykan MCP',
        'transport' => 'streamable-http (stateless JSON-RPC 2.0)',
        'usage'     => 'POST JSON-RPC to this URL with ?k=SECRET. Add as a custom connector in claude.ai.',
        'configured'=> MCP_SECRET !== '' && MCP_ROOT !== '' && is_dir(MCP_ROOT),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- Auth gate ----------------------------------------------------------------
function mcp_provided_secret(): string {
    if (isset($_GET['k'])) return (string)$_GET['k'];
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) return trim(substr($hdr, 7));
    return '';
}

if (MCP_SECRET === '' || !hash_equals(MCP_SECRET, mcp_provided_secret())) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => MCP_SECRET === '' ? 'server not configured (.env missing MCP_SECRET)' : 'unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// Data helpers (same on-disk format as _Ykan.php)
// ============================================================================
final class McpError extends Exception {}

function mcp_load(): array {
    if (!is_file(MCP_DATA_FILE)) return [];
    $raw = file_get_contents(MCP_DATA_FILE);
    return json_decode($raw, true) ?: [];
}

function mcp_save(array $data): void {
    file_put_contents(MCP_DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function mcp_id(string $prefix = 'card'): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
}

// --- Short task ids (#25) ---------------------------------------------------
// Shared with _Ykan.php via the same _Ykan_data.json: every card gets a
// globally-progressive integer 'seq', the next value kept in config.next_seq.

function mcp_max_seq(array $data): int {
    $max = 0;
    foreach (($data['cards'] ?? []) as $c) {
        if (isset($c['seq']) && (int)$c['seq'] > $max) $max = (int)$c['seq'];
    }
    return $max;
}

/** Reserve and return the next short id, initialising the counter if needed. */
function mcp_alloc_seq(array &$data): int {
    if (!isset($data['config']['next_seq'])) {
        $data['config']['next_seq'] = mcp_max_seq($data) + 1;
    }
    $seq = (int)$data['config']['next_seq'];
    $data['config']['next_seq'] = $seq + 1;
    return $seq;
}

/** Give every card missing a 'seq' one. Returns true if anything changed. */
function mcp_ensure_seq(array &$data): bool {
    $changed = false;
    $max = mcp_max_seq($data);
    // (Re)initialise the counter if it is missing, corrupt (<= max), or no card
    // is numbered yet (fresh rollout) — the last case restarts cleanly from #1.
    if (!isset($data['config']['next_seq']) || (int)$data['config']['next_seq'] <= $max || $max === 0) {
        $data['config']['next_seq'] = $max + 1;
        $changed = true;
    }
    foreach (array_keys($data['cards'] ?? []) as $i) {
        if (!isset($data['cards'][$i]['seq']) || !is_int($data['cards'][$i]['seq'])) {
            $data['cards'][$i]['seq'] = mcp_alloc_seq($data);
            $changed = true;
        }
    }
    return $changed;
}

/** Locate an active card by short id (#25 / 25) or title (partial). Returns array index or null. */
function mcp_find_card_index(array $data, string $needle, ?array $filter): ?int {
    $needle = trim($needle);
    $bySeq = preg_match('/^#?(\d+)$/', $needle, $m) ? (int)$m[1] : null;
    foreach (($data['cards'] ?? []) as $i => $c) {
        if (!empty($c['archived'])) continue;
        if ($filter && ($c['swimlane_id'] ?? '') !== $filter['id']) continue;
        if ($bySeq !== null) {
            if ((int)($c['seq'] ?? 0) === $bySeq) return $i;
        } elseif ($needle !== '' && stripos($c['title'], $needle) !== false) {
            return $i;
        }
    }
    return null;
}

function mcp_audit(string $tool, array $info): void {
    $line = date('c') . "\t" . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\t" . $tool
          . "\t" . json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(MCP_AUDIT_LOG, $line, FILE_APPEND);
}

// ---- Database (lazy singleton) -------------------------------------------------
function mcp_pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (MCP_DB_HOST === '' || MCP_DB_NAME === '') {
        throw new McpError('Database not configured (DB_HOST / DB_NAME missing from .env).');
    }
    $dsn = 'mysql:host=' . MCP_DB_HOST . ';port=' . MCP_DB_PORT
         . ';dbname=' . MCP_DB_NAME . ';charset=' . MCP_DB_CHARSET;
    $pdo = new PDO($dsn, MCP_DB_USER, MCP_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// ---- Mailer (riuso pattern Faro: mail() di PHP) --------------------------------
function mcp_send_mail(string $to, string $subject, string $html): array {
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers  = 'From: Ykan <' . MCP_MAIL_FROM . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=utf-8\r\n";
    $ok = @mail($to, $subjectEnc, $html, $headers, '-f' . MCP_MAIL_FROM);
    return [$ok, $ok ? 'inviata con mail()' : 'mail() ha restituito false'];
}

// ---- OVH API (signed REST calls) ---------------------------------------------
// Region -> API base URL. Default ovh-eu (europe).
function mcp_ovh_base(): string {
    $map = [
        'ovh-eu'        => 'https://eu.api.ovh.com/1.0',
        'ovh-ca'        => 'https://ca.api.ovh.com/1.0',
        'ovh-us'        => 'https://api.us.ovhcloud.com/1.0',
        'kimsufi-eu'    => 'https://eu.api.kimsufi.com/1.0',
        'kimsufi-ca'    => 'https://ca.api.kimsufi.com/1.0',
        'soyoustart-eu' => 'https://eu.api.soyoustart.com/1.0',
        'soyoustart-ca' => 'https://ca.api.soyoustart.com/1.0',
    ];
    return $map[MCP_OVH_ENDPOINT] ?? $map['ovh-eu'];
}

/**
 * Perform a signed OVH API request. $path starts with '/', e.g. '/me'.
 * Returns the decoded JSON (array/scalar) on success; throws McpError otherwise.
 * Signature = "$1$" . sha1(AppSecret+ConsumerKey+METHOD+URL+BODY+TIMESTAMP).
 */
function mcp_ovh_request(string $method, string $path, ?array $body = null) {
    if (MCP_OVH_APP_KEY === '' || MCP_OVH_APP_SECRET === '' || MCP_OVH_CONSUMER_KEY === '') {
        throw new McpError('OVH API not configured (OVH_APP_KEY / OVH_APP_SECRET / OVH_CONSUMER_KEY missing from .env).');
    }
    $base    = mcp_ovh_base();
    $url     = $base . $path;
    $bodyStr = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Use OVH server time to avoid local clock drift breaking the signature.
    $ch = curl_init($base . '/auth/time');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $t = curl_exec($ch);
    curl_close($ch);
    $timestamp = ($t !== false && is_numeric(trim((string)$t))) ? (int)trim((string)$t) : time();

    $toSign = MCP_OVH_APP_SECRET . '+' . MCP_OVH_CONSUMER_KEY . '+' . $method . '+' . $url . '+' . $bodyStr . '+' . $timestamp;
    $sig    = '$1$' . sha1($toSign);

    $headers = [
        'X-Ovh-Application: ' . MCP_OVH_APP_KEY,
        'X-Ovh-Consumer: '    . MCP_OVH_CONSUMER_KEY,
        'X-Ovh-Timestamp: '   . $timestamp,
        'X-Ovh-Signature: '   . $sig,
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new McpError("OVH request failed: $err");
    $decoded = json_decode($resp, true);
    if ($code >= 400) {
        $msg = (is_array($decoded) && isset($decoded['message'])) ? $decoded['message'] : $resp;
        throw new McpError("OVH API error $code on $method $path: $msg");
    }
    return $decoded;
}

// ---- Swimlane (= project) lookup ---------------------------------------------
function mcp_find_lane(array $data, string $project): ?array {
    $needle = trim(mb_strtolower($project));
    foreach (($data['swimlanes'] ?? []) as $l) {
        if (mb_strtolower($l['name']) === $needle) return $l;
    }
    foreach (($data['swimlanes'] ?? []) as $l) {
        if ($needle !== '' && stripos($l['name'], $needle) !== false) return $l;
    }
    return null;
}

function mcp_project_names(array $data): string {
    return implode(', ', array_map(fn($l) => $l['name'], $data['swimlanes'] ?? []));
}

// ============================================================================
// Filesystem safety (Phase 2)
// ============================================================================

/** Lexically resolve ./ and ../ without touching the filesystem. */
function mcp_canonical(string $path): string {
    $abs = str_starts_with($path, '/');
    $out = [];
    foreach (explode('/', $path) as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') { array_pop($out); continue; }
        $out[] = $p;
    }
    return ($abs ? '/' : '') . implode('/', $out);
}

/** Resolve the real, on-disk base folder for a linked project (swimlane). */
function mcp_project_dir(array $data, string $project): array {
    $lane = mcp_find_lane($data, $project);
    if (!$lane) {
        throw new McpError("Project '$project' not found. Available: " . mcp_project_names($data));
    }
    $rel = trim((string)($lane['path'] ?? ''));
    if ($rel === '') {
        throw new McpError("Project '{$lane['name']}' has no linked folder. Link one in _Ykan -> Projects.");
    }
    $rootReal = realpath(MCP_ROOT);
    if ($rootReal === false) {
        throw new McpError('MCP_ROOT is not configured correctly on the server.');
    }
    $baseReal = realpath($rootReal . '/' . $rel);
    if ($baseReal === false) {
        throw new McpError("Linked folder for '{$lane['name']}' does not exist on disk: $rel");
    }
    if ($baseReal !== $rootReal && !str_starts_with($baseReal, $rootReal . '/')) {
        throw new McpError('Linked folder escapes MCP_ROOT — refusing.');
    }
    return [$lane, $baseReal];
}

/** Confine a relative path inside a base folder; returns absolute target path. */
function mcp_safe_path(string $baseReal, string $rel): string {
    if (strpos($rel, "\0") !== false) throw new McpError('Invalid path.');
    $rel = ltrim($rel, '/');
    $target = mcp_canonical($baseReal . '/' . $rel);
    if ($target !== $baseReal && !str_starts_with($target, $baseReal . '/')) {
        throw new McpError('Path escapes the project folder.');
    }
    // Defend against symlinks pointing outside, when the target already exists.
    $rp = realpath($target);
    if ($rp !== false && $rp !== $baseReal && !str_starts_with($rp, $baseReal . '/')) {
        throw new McpError('Path escapes the project folder (symlink).');
    }
    return $target;
}

/** Back up an existing file before it is overwritten. */
function mcp_backup(string $baseReal, string $absFile): void {
    if (!is_file($absFile)) return;
    $rel  = ltrim(substr($absFile, strlen($baseReal)), '/');
    $dest = $baseReal . '/.ykan_backups/' . $rel . '.' . date('Ymd-His');
    @mkdir(dirname($dest), 0775, true);
    @copy($absFile, $dest);
}

// ============================================================================
// TOOLS
// ============================================================================
function mcp_tool_defs(): array {
    $projectArg = ['type' => 'string', 'description' => 'Project name (matches a swimlane in _Ykan).'];
    return [
        // -------- Phase 1: Kanban --------
        [
            'name' => 'list_projects',
            'description' => 'List all projects (swimlanes), whether each is linked to a folder, and its task count.',
            'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
        ],
        [
            'name' => 'board_summary',
            'description' => 'Human-readable summary of the board. Optionally filter to a single project.',
            'inputSchema' => ['type' => 'object', 'properties' => ['project' => $projectArg]],
        ],
        [
            'name' => 'list_tasks',
            'description' => 'List active tasks (each prefixed with its short id, e.g. #25), optionally filtered by project and/or column.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'project' => $projectArg,
                'column'  => ['type' => 'string', 'description' => 'Column name filter (partial match).'],
            ]],
        ],
        [
            'name' => 'get_task',
            'description' => 'Get the full details of one task (title, project, column, priority, files, description) by its short id like "25" (or "#25"), or by title.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'id'      => ['type' => 'string', 'description' => 'Short task id, e.g. "25" or "#25".'],
                'title'   => ['type' => 'string', 'description' => 'Task title (partial match) — use if you do not have the id.'],
                'project' => $projectArg,
            ]],
        ],
        [
            'name' => 'add_task',
            'description' => 'Add a task (card) to a project. Goes to the first column ("To Do") unless a column is given.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'project'     => $projectArg,
                'title'       => ['type' => 'string', 'description' => 'Task title.'],
                'description' => ['type' => 'string', 'description' => 'Task details (optional).'],
                'priority'    => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'column'      => ['type' => 'string', 'description' => 'Target column name (optional, partial match).'],
                'label'       => ['type' => 'string', 'description' => 'Label name (optional, partial match, e.g. "Claude", "Bug").'],
            ], 'required' => ['project', 'title']],
        ],
        [
            'name' => 'move_task',
            'description' => 'Move a task to another column. Match it by short id (e.g. "25") or by title.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'id'      => ['type' => 'string', 'description' => 'Short task id, e.g. "25" or "#25".'],
                'title'   => ['type' => 'string', 'description' => 'Task title (partial match) — use if you do not have the id.'],
                'column'  => ['type' => 'string', 'description' => 'Destination column name (partial match).'],
                'project' => $projectArg,
            ], 'required' => ['column']],
        ],
        [
            'name' => 'complete_task',
            'description' => 'Complete (archive) a task, matched by short id (e.g. "25") or by title. Honors auto-regenerate tasks.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'id'      => ['type' => 'string', 'description' => 'Short task id, e.g. "25" or "#25".'],
                'title'   => ['type' => 'string', 'description' => 'Task title (partial match) — use if you do not have the id.'],
                'project' => $projectArg,
            ]],
        ],
        // -------- Phase 2: Files (scoped to a linked project folder) --------
        [
            'name' => 'list_files',
            'description' => 'List files/folders inside a linked project folder.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'project' => $projectArg,
                'subpath' => ['type' => 'string', 'description' => 'Subfolder relative to the project root (optional).'],
            ], 'required' => ['project']],
        ],
        [
            'name' => 'read_file',
            'description' => 'Read a text file from a linked project folder.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'project' => $projectArg,
                'path'    => ['type' => 'string', 'description' => 'File path relative to the project root.'],
            ], 'required' => ['project', 'path']],
        ],
        [
            'name' => 'edit_file',
            'description' => 'Find-and-replace edit inside a file. Backs up the file first. Fails if the search text is not found.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'project'     => $projectArg,
                'path'        => ['type' => 'string', 'description' => 'File path relative to the project root.'],
                'search'      => ['type' => 'string', 'description' => 'Exact text to find.'],
                'replace'     => ['type' => 'string', 'description' => 'Replacement text.'],
                'replace_all' => ['type' => 'boolean', 'description' => 'Replace every occurrence (default false = must be unique).'],
            ], 'required' => ['project', 'path', 'search', 'replace']],
        ],
        [
            'name' => 'write_file',
            'description' => 'Create or overwrite a file with new content. Backs up any existing file first.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'project' => $projectArg,
                'path'    => ['type' => 'string', 'description' => 'File path relative to the project root.'],
                'content' => ['type' => 'string', 'description' => 'Full new file content.'],
            ], 'required' => ['project', 'path', 'content']],
        ],
        [
            'name' => 'search_files',
            'description' => 'Search for text across a linked project folder. Returns file:line matches.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'project'     => $projectArg,
                'query'       => ['type' => 'string', 'description' => 'Text to search for (case-insensitive).'],
                'subpath'     => ['type' => 'string', 'description' => 'Limit to a subfolder (optional).'],
                'max_results' => ['type' => 'integer', 'description' => 'Max matches to return (default 50).'],
            ], 'required' => ['project', 'query']],
        ],
        // -------- Phase 0: Database --------
        [
            'name' => 'db_list_tables',
            'description' => 'List all tables in the MySQL database, with row counts.',
            'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
        ],
        [
            'name' => 'db_schema',
            'description' => 'Show the CREATE TABLE statement (schema) for a table.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'table' => ['type' => 'string', 'description' => 'Table name.'],
            ], 'required' => ['table']],
        ],
        [
            'name' => 'db_query',
            'description' => 'Run a read-only SELECT query. Returns up to 200 rows as formatted text.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'sql'   => ['type' => 'string', 'description' => 'SELECT query to execute.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (default 200, max 1000).'],
            ], 'required' => ['sql']],
        ],
        [
            'name' => 'db_exec',
            'description' => 'Execute a write query (INSERT, UPDATE, DELETE, ALTER, etc.). ALWAYS call db_dump_table on affected tables before a batch of writes.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'sql' => ['type' => 'string', 'description' => 'SQL statement to execute.'],
            ], 'required' => ['sql']],
        ],
        [
            'name' => 'db_dump_table',
            'description' => 'Backup a single table to a SQL dump file in ykan/.db_backups/. Call this BEFORE any batch of write operations on that table.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'table' => ['type' => 'string', 'description' => 'Table name to backup.'],
            ], 'required' => ['table']],
        ],
        // -------- Phase 0: Mail --------
        [
            'name' => 'send_digest_mail',
            'description' => 'Send an HTML email digest/report to the admin (yurrena@gmail.com). Used by the nightly routine to send the task summary.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'subject' => ['type' => 'string', 'description' => 'Email subject line.'],
                'html'    => ['type' => 'string', 'description' => 'HTML body content.'],
            ], 'required' => ['subject', 'html']],
        ],
        // -------- Phase 3: OVH control panel (signed API) --------
        [
            'name' => 'ovh_whoami',
            'description' => 'Test the OVH API credentials. Returns the account (nichandle, name, email). Use this first to confirm the connection works.',
            'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
        ],
        [
            'name' => 'list_dns_records',
            'description' => 'List DNS records of a zone (id, type, subdomain, ttl, target). Optionally filter by subdomain and/or record type.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'zone'      => ['type' => 'string', 'description' => 'DNS zone name, e.g. "portale3d.it".'],
                'subDomain' => ['type' => 'string', 'description' => 'Filter by subdomain (optional, e.g. "shop").'],
                'fieldType' => ['type' => 'string', 'description' => 'Filter by record type (optional, e.g. "A", "CNAME", "MX", "TXT").'],
            ], 'required' => ['zone']],
        ],
        [
            'name' => 'add_dns_record',
            'description' => 'Create a DNS record in a zone and refresh it so it goes live. Use for subdomains (A/AAAA/CNAME), mail (MX), verification (TXT), etc.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'zone'      => ['type' => 'string', 'description' => 'DNS zone name, e.g. "portale3d.it".'],
                'fieldType' => ['type' => 'string', 'description' => 'Record type: A, AAAA, CNAME, MX, TXT, SRV, NS...'],
                'target'    => ['type' => 'string', 'description' => 'Record value (IP for A, hostname for CNAME, etc.).'],
                'subDomain' => ['type' => 'string', 'description' => 'Subdomain part (optional; empty = zone root).'],
                'ttl'       => ['type' => 'integer', 'description' => 'TTL in seconds (optional, 0 = default).'],
            ], 'required' => ['zone', 'fieldType', 'target']],
        ],
        [
            'name' => 'delete_dns_record',
            'description' => 'Delete a DNS record by its id (get it from list_dns_records) and refresh the zone.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'zone' => ['type' => 'string', 'description' => 'DNS zone name.'],
                'id'   => ['type' => 'integer', 'description' => 'Record id to delete.'],
            ], 'required' => ['zone', 'id']],
        ],
        [
            'name' => 'create_email_redirect',
            'description' => 'Create an email redirection on an OVH email domain (from -> to). Optionally keep a local copy.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'domain'    => ['type' => 'string', 'description' => 'Email domain, e.g. "portale3d.it".'],
                'from'      => ['type' => 'string', 'description' => 'Source address, e.g. "info@portale3d.it".'],
                'to'        => ['type' => 'string', 'description' => 'Destination address.'],
                'localCopy' => ['type' => 'boolean', 'description' => 'Keep a copy in the source mailbox (default false).'],
            ], 'required' => ['domain', 'from', 'to']],
        ],
        [
            'name' => 'attach_subdomain',
            'description' => 'Attach a (sub)domain to a folder on a shared hosting so Apache serves a site for it. Pair with add_dns_record (A/CNAME) for a full subdomain.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'service' => ['type' => 'string', 'description' => 'Hosting service name (from list_hostings), e.g. "portale3d.it".'],
                'domain'  => ['type' => 'string', 'description' => 'Full (sub)domain to attach, e.g. "shop.portale3d.it".'],
                'path'    => ['type' => 'string', 'description' => 'Folder to serve, relative to the hosting web root, e.g. "shop".'],
                'ssl'     => ['type' => 'boolean', 'description' => 'Enable SSL for the domain (default true).'],
            ], 'required' => ['service', 'domain', 'path']],
        ],
        [
            'name' => 'list_hostings',
            'description' => 'List your OVH shared hosting service names (use one as the "service" for attach_subdomain).',
            'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
        ],
    ];
}

/** Execute a tool. Returns a plain-text result string. Throws McpError on failure. */
function mcp_run_tool(string $name, array $a): string {
    $data = mcp_load();
    // One-off backfill of short ids for boards created before them.
    if ($data && mcp_ensure_seq($data)) mcp_save($data);

    switch ($name) {

        // ---------------- Phase 1 ----------------
        case 'list_projects': {
            $cards = $data['cards'] ?? [];
            $out = [];
            foreach (($data['swimlanes'] ?? []) as $l) {
                $n = count(array_filter($cards, fn($c) => ($c['swimlane_id'] ?? '') === $l['id'] && empty($c['archived'])));
                $linked = trim((string)($l['path'] ?? '')) !== '';
                $out[] = sprintf('- %s%s — %d active task(s)%s',
                    $l['name'],
                    $linked ? " [folder: {$l['path']}]" : ' [no folder linked]',
                    $n,
                    !empty($l['url']) ? " ({$l['url']})" : ''
                );
            }
            return $out ? "Projects:\n" . implode("\n", $out) : 'No projects yet.';
        }

        case 'board_summary': {
            $cols   = array_column($data['columns'] ?? [], 'name', 'id');
            $lanes  = array_column($data['swimlanes'] ?? [], 'name', 'id');
            $labels = array_column($data['labels'] ?? [], 'name', 'id');
            $filter = isset($a['project']) ? mcp_find_lane($data, $a['project']) : null;
            $active = array_filter($data['cards'] ?? [], fn($c) => empty($c['archived'])
                && (!$filter || ($c['swimlane_id'] ?? '') === $filter['id']));

            $title = $filter ? "Project: {$filter['name']}" : ($data['config']['project_name'] ?? 'Board');
            $s = "# $title\n\n";
            foreach (($data['columns'] ?? []) as $col) {
                $cc = array_filter($active, fn($c) => ($c['column_id'] ?? '') === $col['id']);
                $s .= "## {$col['name']} (" . count($cc) . ")\n";
                if (!$cc) { $s .= "  (empty)\n"; }
                foreach ($cc as $c) {
                    $pri = strtoupper($c['priority'] ?? 'medium');
                    $lane = $lanes[$c['swimlane_id'] ?? ''] ?? '';
                    $laneStr = (!$filter && $lane && $lane !== 'Default') ? " @$lane" : '';
                    $lbl = isset($c['label_id']) ? ($labels[$c['label_id']] ?? '') : '';
                    $lblStr = $lbl ? " [$lbl]" : '';
                    $s .= "  - [$pri]$lblStr {$c['title']}$laneStr\n";
                }
                $s .= "\n";
            }
            return $s;
        }

        case 'list_tasks': {
            $cols   = array_column($data['columns'] ?? [], 'name', 'id');
            $lanes  = array_column($data['swimlanes'] ?? [], 'name', 'id');
            $filter = isset($a['project']) ? mcp_find_lane($data, $a['project']) : null;
            $colName = isset($a['column']) ? trim($a['column']) : '';
            $rows = [];
            foreach (($data['cards'] ?? []) as $c) {
                if (!empty($c['archived'])) continue;
                if ($filter && ($c['swimlane_id'] ?? '') !== $filter['id']) continue;
                $col = $cols[$c['column_id'] ?? ''] ?? '?';
                if ($colName !== '' && stripos($col, $colName) === false) continue;
                $lane = $lanes[$c['swimlane_id'] ?? ''] ?? '';
                $sid = isset($c['seq']) ? '#' . $c['seq'] . ' ' : '';
                $rows[] = "- {$sid}[$col] {$c['title']} (" . ($c['priority'] ?? 'medium') . ($filter ? '' : ", @$lane") . ')';
            }
            return $rows ? implode("\n", $rows) : 'No matching tasks.';
        }

        case 'add_task': {
            $lane = mcp_find_lane($data, (string)($a['project'] ?? ''));
            if (!$lane) throw new McpError("Project '" . ($a['project'] ?? '') . "' not found. Available: " . mcp_project_names($data));
            $title = trim((string)($a['title'] ?? ''));
            if ($title === '') throw new McpError('Title is required.');

            $colId = $data['columns'][0]['id'] ?? 'col_1';
            if (!empty($a['column'])) {
                foreach (($data['columns'] ?? []) as $col) {
                    if (stripos($col['name'], (string)$a['column']) !== false) { $colId = $col['id']; break; }
                }
            }
            $priority = in_array($a['priority'] ?? 'medium', ['high', 'medium', 'low'], true) ? $a['priority'] : 'medium';

            $labelId = null;
            if (!empty($a['label'])) {
                foreach (($data['labels'] ?? []) as $lbl) {
                    if (stripos($lbl['name'], (string)$a['label']) !== false) { $labelId = $lbl['id']; break; }
                }
            }

            $seq = mcp_alloc_seq($data);
            $card = [
                'id' => mcp_id('card'), 'seq' => $seq, 'title' => $title,
                'description' => (string)($a['description'] ?? ''),
                'priority' => $priority, 'due_date' => null, 'next_check' => null,
                'label_id' => $labelId, 'column_id' => $colId, 'swimlane_id' => $lane['id'],
                'archived' => false, 'auto_regenerate' => false, 'regenerate_delay_days' => 0,
                'files' => [], 'position' => 0,
                'created_at' => date('Y-m-d H:i:s'), 'regenerated_count' => 0,
            ];
            $data['cards'][] = $card;
            mcp_save($data);
            mcp_audit('add_task', ['project' => $lane['name'], 'title' => $title, 'seq' => $seq]);
            return "Added task #$seq '$title' to project '{$lane['name']}'.";
        }

        case 'move_task': {
            $needle = trim((string)($a['id'] ?? $a['title'] ?? ''));
            $colName = trim((string)($a['column'] ?? ''));
            $filter = isset($a['project']) ? mcp_find_lane($data, $a['project']) : null;
            $target = null;
            foreach (($data['columns'] ?? []) as $col) {
                if (stripos($col['name'], $colName) !== false) { $target = $col; break; }
            }
            if (!$target) throw new McpError("Column '$colName' not found. Available: " . implode(', ', array_column($data['columns'] ?? [], 'name')));
            $i = mcp_find_card_index($data, $needle, $filter);
            if ($i === null) throw new McpError("Task '$needle' not found.");
            $data['cards'][$i]['column_id'] = $target['id'];
            mcp_save($data);
            $c = $data['cards'][$i];
            mcp_audit('move_task', ['seq' => $c['seq'] ?? null, 'title' => $c['title'], 'column' => $target['name']]);
            return 'Moved #' . ($c['seq'] ?? '?') . " '{$c['title']}' to '{$target['name']}'.";
        }

        case 'complete_task': {
            $needle = trim((string)($a['id'] ?? $a['title'] ?? ''));
            $filter = isset($a['project']) ? mcp_find_lane($data, $a['project']) : null;
            $i = mcp_find_card_index($data, $needle, $filter);
            if ($i === null) throw new McpError("Task '$needle' not found.");
            $c = &$data['cards'][$i];
            $c['archived'] = true;
            $c['archived_at'] = date('Y-m-d H:i:s');
            $msg = 'Completed and archived #' . ($c['seq'] ?? '?') . " '{$c['title']}'.";
            if (!empty($c['auto_regenerate'])) {
                $delay = (int)($c['regenerate_delay_days'] ?? 0);
                $due = $delay > 0 ? date('Y-m-d', strtotime("+$delay days")) : null;
                $data['cards'][] = [
                    'id' => mcp_id('card'), 'seq' => mcp_alloc_seq($data), 'title' => $c['title'],
                    'description' => $c['description'] ?? '', 'priority' => $c['priority'] ?? 'medium',
                    'due_date' => $due, 'next_check' => $due, 'label_id' => $c['label_id'] ?? null,
                    'column_id' => $data['columns'][0]['id'], 'swimlane_id' => $c['swimlane_id'],
                    'archived' => false, 'auto_regenerate' => true, 'regenerate_delay_days' => $delay,
                    'position' => 0, 'created_at' => date('Y-m-d H:i:s'),
                    'regenerated_count' => ($c['regenerated_count'] ?? 0) + 1, 'regenerated_from' => $c['id'],
                ];
                $msg .= ' Task auto-regenerated' . ($due ? " (due $due)" : '') . '.';
            }
            unset($c);
            mcp_save($data);
            mcp_audit('complete_task', ['seq' => $data['cards'][$i]['seq'] ?? null, 'title' => $data['cards'][$i]['title']]);
            return $msg;
        }

        case 'get_task': {
            $needle = trim((string)($a['id'] ?? $a['title'] ?? ''));
            $filter = isset($a['project']) ? mcp_find_lane($data, $a['project']) : null;
            $i = mcp_find_card_index($data, $needle, $filter);
            if ($i === null) throw new McpError("Task '$needle' not found.");
            $c = $data['cards'][$i];
            $cols  = array_column($data['columns'] ?? [], 'name', 'id');
            $lanes = array_column($data['swimlanes'] ?? [], 'name', 'id');
            $labels = array_column($data['labels'] ?? [], 'name', 'id');
            $out = [];
            $out[] = 'Task #' . ($c['seq'] ?? '?') . ': ' . $c['title'];
            $out[] = 'Project: ' . ($lanes[$c['swimlane_id'] ?? ''] ?? '?');
            $out[] = 'Column: ' . ($cols[$c['column_id'] ?? ''] ?? '?') . '   Priority: ' . ($c['priority'] ?? 'medium');
            if (!empty($c['label_id'])) $out[] = 'Label: ' . ($labels[$c['label_id']] ?? '?');
            if (!empty($c['due_date'])) $out[] = 'Due: ' . $c['due_date'];
            if (!empty($c['files'])) $out[] = 'Files: ' . implode(', ', array_map(fn($f) => is_array($f) ? ($f['path'] ?? '') : $f, $c['files']));
            $out[] = '';
            $out[] = trim((string)($c['description'] ?? '')) !== '' ? $c['description'] : '(no description)';
            return implode("\n", $out);
        }

        // ---------------- Phase 2 ----------------
        case 'list_files': {
            [$lane, $base] = mcp_project_dir($data, (string)($a['project'] ?? ''));
            $dir = mcp_safe_path($base, (string)($a['subpath'] ?? ''));
            if (!is_dir($dir)) throw new McpError('Not a folder: ' . ($a['subpath'] ?? '/'));
            $items = @scandir($dir) ?: [];
            $out = [];
            foreach ($items as $it) {
                if ($it === '.' || $it === '..' || $it === '.ykan_backups') continue;
                $full = $dir . '/' . $it;
                $out[] = (is_dir($full) ? '[dir]  ' : '[file] ') . $it . (is_file($full) ? ' (' . filesize($full) . ' B)' : '');
            }
            sort($out);
            return $out ? "Project '{$lane['name']}' — " . ($a['subpath'] ?? '/') . ":\n" . implode("\n", $out) : '(empty folder)';
        }

        case 'read_file': {
            [$lane, $base] = mcp_project_dir($data, (string)($a['project'] ?? ''));
            $file = mcp_safe_path($base, (string)($a['path'] ?? ''));
            if (!is_file($file)) throw new McpError('File not found: ' . ($a['path'] ?? ''));
            if (filesize($file) > MCP_MAX_READ) throw new McpError('File too large to read (> ' . MCP_MAX_READ . ' bytes).');
            return (string)file_get_contents($file);
        }

        case 'edit_file': {
            [$lane, $base] = mcp_project_dir($data, (string)($a['project'] ?? ''));
            $file = mcp_safe_path($base, (string)($a['path'] ?? ''));
            if (!is_file($file)) throw new McpError('File not found: ' . ($a['path'] ?? ''));
            $search  = (string)($a['search'] ?? '');
            $replace = (string)($a['replace'] ?? '');
            if ($search === '') throw new McpError('search must not be empty.');
            $content = (string)file_get_contents($file);
            $count = substr_count($content, $search);
            if ($count === 0) throw new McpError('Search text not found in file.');
            if ($count > 1 && empty($a['replace_all'])) {
                throw new McpError("Search text appears $count times. Set replace_all=true or make it unique.");
            }
            $new = empty($a['replace_all'])
                ? preg_replace('/' . preg_quote($search, '/') . '/', str_replace('$', '\\$', $replace), $content, 1)
                : str_replace($search, $replace, $content);
            mcp_backup($base, $file);
            if (file_put_contents($file, $new) === false) throw new McpError('Write failed (check file permissions).');
            mcp_audit('edit_file', ['project' => $lane['name'], 'path' => $a['path'], 'replacements' => empty($a['replace_all']) ? 1 : $count]);
            return "Edited {$a['path']} in '{$lane['name']}' (" . (empty($a['replace_all']) ? 1 : $count) . ' replacement(s)). Backup saved.';
        }

        case 'write_file': {
            [$lane, $base] = mcp_project_dir($data, (string)($a['project'] ?? ''));
            $file = mcp_safe_path($base, (string)($a['path'] ?? ''));
            $content = (string)($a['content'] ?? '');
            if (strlen($content) > MCP_MAX_WRITE) throw new McpError('Content too large (> ' . MCP_MAX_WRITE . ' bytes).');
            $existed = is_file($file);
            mcp_backup($base, $file);
            @mkdir(dirname($file), 0775, true);
            if (file_put_contents($file, $content) === false) throw new McpError('Write failed (check file permissions).');
            mcp_audit('write_file', ['project' => $lane['name'], 'path' => $a['path'], 'bytes' => strlen($content), 'existed' => $existed]);
            return ($existed ? 'Overwrote ' : 'Created ') . "{$a['path']} in '{$lane['name']}' (" . strlen($content) . ' bytes)' . ($existed ? '. Backup saved.' : '.');
        }

        case 'search_files': {
            [$lane, $base] = mcp_project_dir($data, (string)($a['project'] ?? ''));
            $start = mcp_safe_path($base, (string)($a['subpath'] ?? ''));
            $query = (string)($a['query'] ?? '');
            if ($query === '') throw new McpError('query must not be empty.');
            $max = max(1, (int)($a['max_results'] ?? 50));
            $skipDirs = ['.git', 'node_modules', 'vendor', '.ykan_backups', 'cache', 'tmp'];
            $skipExt  = ['png','jpg','jpeg','gif','webp','ico','pdf','zip','gz','tar','mp4','mp3','woff','woff2','ttf','eot'];
            $hits = [];
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS),
                    function ($cur) use ($skipDirs) {
                        return !($cur->isDir() && in_array($cur->getFilename(), $skipDirs, true));
                    }
                )
            );
            foreach ($it as $f) {
                if (count($hits) >= $max) break;
                if (!$f->isFile() || $f->getSize() > MCP_MAX_READ) continue;
                if (in_array(strtolower($f->getExtension()), $skipExt, true)) continue;
                $rel = ltrim(substr($f->getPathname(), strlen($base)), '/');
                $lines = @file($f->getPathname(), FILE_IGNORE_NEW_LINES);
                if (!$lines) continue;
                foreach ($lines as $i => $line) {
                    if (stripos($line, $query) !== false) {
                        $hits[] = $rel . ':' . ($i + 1) . ': ' . trim(substr($line, 0, 200));
                        if (count($hits) >= $max) break;
                    }
                }
            }
            return $hits ? implode("\n", $hits) : "No matches for '$query'.";
        }
        // ---------------- Phase 0: Database ----------------
        case 'db_list_tables': {
            $pdo = mcp_pdo();
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (!$tables) return 'No tables found.';
            $out = [];
            foreach ($tables as $t) {
                $row = $pdo->query("SELECT COUNT(*) AS c FROM `" . str_replace('`', '``', $t) . "`")->fetch();
                $out[] = sprintf('- %s (%s rows)', $t, number_format((int)$row['c']));
            }
            return "Tables in " . MCP_DB_NAME . ":\n" . implode("\n", $out);
        }

        case 'db_schema': {
            $table = trim((string)($a['table'] ?? ''));
            if ($table === '') throw new McpError('table is required.');
            $pdo = mcp_pdo();
            $stmt = $pdo->query("SHOW CREATE TABLE `" . str_replace('`', '``', $table) . "`");
            $row = $stmt->fetch();
            if (!$row) throw new McpError("Table '$table' not found.");
            return $row['Create Table'] ?? $row[array_keys($row)[1]] ?? 'No schema.';
        }

        case 'db_query': {
            $sql = trim((string)($a['sql'] ?? ''));
            if ($sql === '') throw new McpError('sql is required.');
            if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql)) {
                throw new McpError('db_query allows only SELECT / SHOW / DESCRIBE / EXPLAIN. Use db_exec for writes.');
            }
            $limit = max(1, min(1000, (int)($a['limit'] ?? 200)));
            if (!preg_match('/\bLIMIT\s+\d/i', $sql)) {
                $sql = rtrim($sql, "; \t\n\r") . " LIMIT $limit";
            }
            $pdo = mcp_pdo();
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();
            if (!$rows) return '(no rows)';
            $cols = array_keys($rows[0]);
            $lines = [implode("\t", $cols)];
            foreach ($rows as $r) {
                $vals = [];
                foreach ($cols as $c) $vals[] = $r[$c] === null ? 'NULL' : (string)$r[$c];
                $lines[] = implode("\t", $vals);
            }
            $result = implode("\n", $lines);
            if (strlen($result) > 100_000) $result = substr($result, 0, 100_000) . "\n... (truncated)";
            mcp_audit('db_query', ['sql' => substr($sql, 0, 500), 'rows' => count($rows)]);
            return $result;
        }

        case 'db_exec': {
            $sql = trim((string)($a['sql'] ?? ''));
            if ($sql === '') throw new McpError('sql is required.');
            if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql)) {
                throw new McpError('Use db_query for read queries, not db_exec.');
            }
            $pdo = mcp_pdo();
            $affected = $pdo->exec($sql);
            mcp_audit('db_exec', ['sql' => substr($sql, 0, 500), 'affected' => $affected]);
            return "Executed. Rows affected: $affected";
        }

        case 'db_dump_table': {
            $table = trim((string)($a['table'] ?? ''));
            if ($table === '') throw new McpError('table is required.');
            $safeTable = str_replace('`', '``', $table);
            $pdo = mcp_pdo();
            // Verify table exists
            $check = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
            if (!$check) throw new McpError("Table '$table' not found.");
            // Get CREATE TABLE
            $create = $pdo->query("SHOW CREATE TABLE `$safeTable`")->fetch();
            $createSql = $create['Create Table'] ?? $create[array_keys($create)[1]] ?? '';
            // Dump rows
            $rows = $pdo->query("SELECT * FROM `$safeTable`")->fetchAll();
            $dump = "-- Backup of `$table` — " . date('Y-m-d H:i:s') . "\n";
            $dump .= "-- Rows: " . count($rows) . "\n\n";
            $dump .= "DROP TABLE IF EXISTS `$safeTable`;\n$createSql;\n\n";
            if ($rows) {
                $cols = array_keys($rows[0]);
                $colList = implode(', ', array_map(fn($c) => "`" . str_replace('`', '``', $c) . "`", $cols));
                foreach ($rows as $r) {
                    $vals = [];
                    foreach ($cols as $c) {
                        $vals[] = $r[$c] === null ? 'NULL' : $pdo->quote((string)$r[$c]);
                    }
                    $dump .= "INSERT INTO `$safeTable` ($colList) VALUES (" . implode(', ', $vals) . ");\n";
                }
            }
            @mkdir(MCP_DB_BACKUP_DIR, 0775, true);
            $file = MCP_DB_BACKUP_DIR . '/' . $table . '_' . date('Ymd-His') . '.sql';
            if (file_put_contents($file, $dump) === false) {
                throw new McpError('Failed to write backup file.');
            }
            mcp_audit('db_dump_table', ['table' => $table, 'rows' => count($rows), 'file' => basename($file)]);
            return "Backup saved: " . basename($file) . " (" . count($rows) . " rows, " . strlen($dump) . " bytes)";
        }

        // ---------------- Phase 0: Mail ----------------
        case 'send_digest_mail': {
            $subject = trim((string)($a['subject'] ?? ''));
            $html    = (string)($a['html'] ?? '');
            if ($subject === '') throw new McpError('subject is required.');
            if ($html === '') throw new McpError('html is required.');
            [$ok, $info] = mcp_send_mail(MCP_MAIL_TO, $subject, $html);
            mcp_audit('send_digest_mail', ['to' => MCP_MAIL_TO, 'subject' => $subject, 'ok' => $ok]);
            if (!$ok) throw new McpError("Mail failed: $info");
            return "Mail sent to " . MCP_MAIL_TO . " — subject: $subject ($info)";
        }

        // ---------------- Phase 3: OVH control panel ----------------
        case 'ovh_whoami': {
            $me = mcp_ovh_request('GET', '/me');
            if (!is_array($me)) throw new McpError('Unexpected response from /me.');
            $name = trim(($me['firstname'] ?? '') . ' ' . ($me['name'] ?? ''));
            return "OVH connection OK.\n"
                 . 'Account: ' . ($me['nichandle'] ?? '?') . "\n"
                 . 'Name: '    . ($name !== '' ? $name : '?') . "\n"
                 . 'Email: '   . ($me['email'] ?? '?');
        }

        case 'list_dns_records': {
            $zone = trim((string)($a['zone'] ?? ''));
            if ($zone === '') throw new McpError('zone is required.');
            $q = [];
            if (!empty($a['subDomain'])) $q['subDomain'] = (string)$a['subDomain'];
            if (!empty($a['fieldType'])) $q['fieldType'] = (string)$a['fieldType'];
            $path = '/domain/zone/' . rawurlencode($zone) . '/record' . ($q ? '?' . http_build_query($q) : '');
            $ids = mcp_ovh_request('GET', $path);
            if (!is_array($ids) || !$ids) return "No records in '$zone' for the given filter.";
            $out = [];
            foreach (array_slice($ids, 0, 100) as $id) {
                $r = mcp_ovh_request('GET', '/domain/zone/' . rawurlencode($zone) . '/record/' . (int)$id);
                if (!is_array($r)) continue;
                $sub = ($r['subDomain'] ?? '') === '' ? '@' : $r['subDomain'];
                $out[] = sprintf('#%d  %-6s  %-20s  ttl=%s  ->  %s',
                    (int)($r['id'] ?? $id), $r['fieldType'] ?? '?', $sub,
                    (string)($r['ttl'] ?? 0), $r['target'] ?? '?');
            }
            $more = count($ids) > 100 ? "\n... (" . (count($ids) - 100) . ' more)' : '';
            return "DNS records in '$zone':\n" . implode("\n", $out) . $more;
        }

        case 'add_dns_record': {
            $zone      = trim((string)($a['zone'] ?? ''));
            $fieldType = strtoupper(trim((string)($a['fieldType'] ?? '')));
            $target    = trim((string)($a['target'] ?? ''));
            if ($zone === '' || $fieldType === '' || $target === '') {
                throw new McpError('zone, fieldType and target are required.');
            }
            $body = ['fieldType' => $fieldType, 'subDomain' => (string)($a['subDomain'] ?? ''), 'target' => $target];
            if (isset($a['ttl'])) $body['ttl'] = (int)$a['ttl'];
            $rec = mcp_ovh_request('POST', '/domain/zone/' . rawurlencode($zone) . '/record', $body);
            mcp_ovh_request('POST', '/domain/zone/' . rawurlencode($zone) . '/refresh');
            $id = is_array($rec) ? ($rec['id'] ?? '?') : '?';
            $fqdn = ($body['subDomain'] !== '' ? $body['subDomain'] . '.' : '') . $zone;
            mcp_audit('add_dns_record', ['zone' => $zone, 'type' => $fieldType, 'sub' => $body['subDomain'], 'target' => $target, 'id' => $id]);
            return "Created $fieldType record #$id: $fqdn -> $target. Zone refreshed (live).";
        }

        case 'delete_dns_record': {
            $zone = trim((string)($a['zone'] ?? ''));
            $id   = (int)($a['id'] ?? 0);
            if ($zone === '' || $id <= 0) throw new McpError('zone and a valid numeric id are required.');
            mcp_ovh_request('DELETE', '/domain/zone/' . rawurlencode($zone) . '/record/' . $id);
            mcp_ovh_request('POST', '/domain/zone/' . rawurlencode($zone) . '/refresh');
            mcp_audit('delete_dns_record', ['zone' => $zone, 'id' => $id]);
            return "Deleted DNS record #$id from '$zone'. Zone refreshed.";
        }

        case 'create_email_redirect': {
            $domain = trim((string)($a['domain'] ?? ''));
            $from   = trim((string)($a['from'] ?? ''));
            $to     = trim((string)($a['to'] ?? ''));
            if ($domain === '' || $from === '' || $to === '') throw new McpError('domain, from and to are required.');
            $body = ['from' => $from, 'to' => $to, 'localCopy' => !empty($a['localCopy'])];
            mcp_ovh_request('POST', '/email/domain/' . rawurlencode($domain) . '/redirection', $body);
            mcp_audit('create_email_redirect', ['domain' => $domain, 'from' => $from, 'to' => $to]);
            return "Created email redirection: $from -> $to" . (!empty($a['localCopy']) ? ' (local copy kept)' : '') . '.';
        }

        case 'attach_subdomain': {
            $service = trim((string)($a['service'] ?? ''));
            $domain  = trim((string)($a['domain'] ?? ''));
            $path    = trim((string)($a['path'] ?? ''));
            if ($service === '' || $domain === '' || $path === '') throw new McpError('service, domain and path are required.');
            $body = ['domain' => $domain, 'path' => $path, 'ssl' => !array_key_exists('ssl', $a) ? true : (bool)$a['ssl']];
            mcp_ovh_request('POST', '/hosting/web/' . rawurlencode($service) . '/attachedDomain', $body);
            mcp_audit('attach_subdomain', ['service' => $service, 'domain' => $domain, 'path' => $path]);
            return "Attached $domain -> folder '$path' on hosting '$service'"
                 . ($body['ssl'] ? ' (SSL on)' : '') . ". Note: DNS must also point $domain here (use add_dns_record).";
        }

        case 'list_hostings': {
            $svcs = mcp_ovh_request('GET', '/hosting/web');
            if (!is_array($svcs) || !$svcs) return 'No shared hosting services found on this account.';
            return "Shared hosting services:\n- " . implode("\n- ", $svcs);
        }
    }
    throw new McpError("Unknown tool: $name");
}

// ============================================================================
// JSON-RPC 2.0 dispatch
// ============================================================================
function mcp_result(mixed $id, array $result): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}
function mcp_rpc_error(mixed $id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function mcp_handle(array $msg): ?array {
    $id     = $msg['id'] ?? null;
    $method = $msg['method'] ?? '';
    $params = $msg['params'] ?? [];
    $isNotification = !array_key_exists('id', $msg);

    switch ($method) {
        case 'initialize':
            return mcp_result($id, [
                'protocolVersion' => $params['protocolVersion'] ?? '2025-06-18',
                'capabilities'    => ['tools' => (object)[]],
                'serverInfo'      => ['name' => '_Ykan MCP', 'version' => '1.0.0'],
            ]);

        case 'notifications/initialized':
        case 'notifications/cancelled':
            return null; // notifications get no response

        case 'ping':
            return mcp_result($id, (object)[]);

        case 'tools/list':
            return mcp_result($id, ['tools' => mcp_tool_defs()]);

        case 'tools/call':
            $name = $params['name'] ?? '';
            $args = $params['arguments'] ?? [];
            try {
                $text = mcp_run_tool($name, is_array($args) ? $args : []);
                return mcp_result($id, ['content' => [['type' => 'text', 'text' => $text]]]);
            } catch (McpError $e) {
                return mcp_result($id, ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true]);
            } catch (Throwable $e) {
                return mcp_result($id, ['content' => [['type' => 'text', 'text' => 'Server error: ' . $e->getMessage()]], 'isError' => true]);
            }
    }

    if ($isNotification) return null;
    return mcp_rpc_error($id, -32601, "Method not found: $method");
}

// ---- Read + dispatch the request body ----------------------------------------
$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    echo json_encode(mcp_rpc_error(null, -32700, 'Parse error'));
    exit;
}

// Support JSON-RPC batch (array of messages) and single messages.
$isBatch = array_is_list($body) && $body !== [];
$messages = $isBatch ? $body : [$body];
$responses = [];
foreach ($messages as $m) {
    if (!is_array($m)) continue;
    $r = mcp_handle($m);
    if ($r !== null) $responses[] = $r;
}

if (!$responses) { http_response_code(202); exit; } // only notifications
echo json_encode($isBatch ? $responses : $responses[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
