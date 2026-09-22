<?php
/**
 * _Ykan - Minimal Kanban Board
 * Single-file PHP Kanban for Scrum/Agile projects
 *
 * @version 1.8.0
 * @license MIT
 * @requires PHP 8.2+
 *
 * ============================================================================
 * AI INTEGRATION GUIDE (for Claude, Gemini, GPT, etc.)
 * ============================================================================
 *
 * This Kanban board exposes a REST API that AI assistants can use to manage tasks.
 * Base URL: Same as this file (e.g., http://localhost/project/_Ykan.php)
 *
 * QUICK START FOR AI:
 * -------------------
 * 1. GET BOARD SUMMARY (human-readable):
 *    curl "http://localhost/path/_Ykan.php?api=get_summary" -X POST
 *
 *    Returns plain text summary of all tasks, perfect for AI context.
 *
 * 2. ADD A NEW TASK:
 *    curl "http://localhost/path/_Ykan.php?api=add_card" -X POST \
 *      -H "Content-Type: application/json" \
 *      -d '{"title":"Task name","description":"Details","priority":"high|medium|low"}'
 *
 *    For auto-regenerating tasks (recreate when archived):
 *      -d '{"title":"Update docs","auto_regenerate":true,"regenerate_delay_days":7}'
 *
 * 3. COMPLETE A TASK (by title):
 *    curl "http://localhost/path/_Ykan.php?api=complete_task" -X POST \
 *      -H "Content-Type: application/json" \
 *      -d '{"title":"Task name"}'
 *
 * 4. MOVE A TASK TO A COLUMN:
 *    curl "http://localhost/path/_Ykan.php?api=move_task" -X POST \
 *      -H "Content-Type: application/json" \
 *      -d '{"title":"Task name","column":"In Progress"}'
 *
 * 5. GET ALL DATA (JSON):
 *    curl "http://localhost/path/_Ykan.php?api=get_data" -X POST
 *
 * COLUMNS (default): "To Do", "In Progress", "Done"
 * PRIORITIES: "high", "medium", "low"
 * CATEGORIES: "bug", "feature", "refactor", "security", "docs", "test"
 *
 * TIPS FOR AI:
 * - Use get_summary first to understand current board state
 * - When completing work, use complete_task to archive it
 * - Add tasks for bugs found or improvements identified
 * - Use move_task to update progress (To Do -> In Progress -> Done)
 * - Tasks with auto_regenerate:true recreate automatically when archived
 * - Use regenerate_delay_days for recurring reminders (e.g., 7 = weekly)
 *
 * ============================================================================
 */

declare(strict_types=1);

const DATA_FILE = __DIR__ . '/_Ykan_data.json';
const THEMES_DIR = __DIR__ . '/themes';
const THEME_KEYS = ['bg','bg2','bg3','text','text2','border','accent','accent2','high','medium','low','shadow'];
const THEME_LAYOUT_KEYS = ['radius','card-radius','card-pad','gap','col-min','cell-pad','font-base','header-pad','blur'];
const THEME_SURFACE_KEYS = ['header-bg','header-text','swimlane-bg','colhead-bg','card-bg'];
const DEFAULT_DATA = [
    'config' => [
        'gemini_api_key' => '',
        'github_token' => '',
        'github_repo' => '',
        'theme' => 'light',
        'project_name' => 'My Project',
        'ai_language' => 'en',
    ],
    'columns' => [
        ['id' => 'col_1', 'name' => 'To Do', 'position' => 0],
        ['id' => 'col_2', 'name' => 'In Progress', 'position' => 1],
        ['id' => 'col_3', 'name' => 'Done', 'position' => 2]
    ],
    'swimlanes' => [
        ['id' => 'lane_1', 'name' => 'Default', 'position' => 0]
    ],
    'cards' => [],
    'labels' => [
        ['id' => 'lbl_1', 'name' => 'Bug', 'color' => '#ef4444'],
        ['id' => 'lbl_2', 'name' => 'Feature', 'color' => '#22c55e'],
        ['id' => 'lbl_3', 'name' => 'Task', 'color' => '#3b82f6'],
        ['id' => 'lbl_4', 'name' => 'Urgent', 'color' => '#f97316'],
        ['id' => 'lbl_5', 'name' => 'Recurring', 'color' => '#8b5cf6']
    ]
];

// === DATA FUNCTIONS ===
function loadData(): array {
    if (!file_exists(DATA_FILE)) {
        saveData(DEFAULT_DATA);
        return DEFAULT_DATA;
    }
    $content = file_get_contents(DATA_FILE);
    $data = json_decode($content, true) ?? DEFAULT_DATA;
    // Backfill short, human-friendly ids (#1, #2, …) on boards created before them.
    if (ykanEnsureSeq($data)) {
        saveData($data);
    }
    return $data;
}

function saveData(array $data): bool {
    return file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function generateId(string $prefix = 'id'): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
}

// === SHORT TASK IDS (#25) =====================================================
// Every card carries a short, globally-progressive integer 'seq' shown on the
// board and via MCP, so a task can be referenced as "#25" from the phone.
// The next value lives in config.next_seq and is shared with mcp.php through the
// same _Ykan_data.json, so numbers never collide between the two entry points.

function ykanMaxSeq(array $data): int {
    $max = 0;
    foreach (($data['cards'] ?? []) as $c) {
        if (isset($c['seq']) && (int)$c['seq'] > $max) $max = (int)$c['seq'];
    }
    return $max;
}

/** Reserve and return the next short id, initialising the counter if needed. */
function ykanAllocSeq(array &$data): int {
    if (!isset($data['config']['next_seq'])) {
        $data['config']['next_seq'] = ykanMaxSeq($data) + 1;
    }
    $seq = (int)$data['config']['next_seq'];
    $data['config']['next_seq'] = $seq + 1;
    return $seq;
}

/** Give every card missing a 'seq' one (in array order). True if anything changed. */
function ykanEnsureSeq(array &$data): bool {
    $changed = false;
    $max = ykanMaxSeq($data);
    // (Re)initialise the counter if missing, corrupt (<= max), or nothing is
    // numbered yet (fresh rollout) — the last case restarts cleanly from #1.
    if (!isset($data['config']['next_seq']) || (int)$data['config']['next_seq'] <= $max || $max === 0) {
        $data['config']['next_seq'] = $max + 1;
        $changed = true;
    }
    foreach (array_keys($data['cards'] ?? []) as $i) {
        if (!isset($data['cards'][$i]['seq']) || !is_int($data['cards'][$i]['seq'])) {
            $data['cards'][$i]['seq'] = ykanAllocSeq($data);
            $changed = true;
        }
    }
    return $changed;
}

// === .env reader (shared keys: MCP_ROOT, ANTHROPIC_KEY, etc.) =================
function ykanEnv(string $key): string {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env'] as $envPath) {
        if (!is_file($envPath) || !is_readable($envPath)) continue;
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*(.*)$/', $line, $m)) {
                $v = trim($m[1]);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $cache[$key] = $v;
                return $v;
            }
        }
    }
    $cache[$key] = '';
    return '';
}

// GitHub token: GITHUB_TOKEN in the server .env wins over the value saved in the board data,
// so the secret can stay out of the data file (and out of the Settings screen).
function ykanGithubToken(array $data): string {
    $env = ykanEnv('GITHUB_TOKEN');
    return $env !== '' ? $env : (string)($data['config']['github_token'] ?? '');
}

// === MCP project root + safe file browsing (shared model with mcp.php) ========
// The swimlane 'path' is relative to MCP_ROOT, configured in the hosting .env
// (same file mcp.php reads). We resolve it here so the board can browse a
// project's folder and let the user tag doc/structure files for the AI.

function ykanMcpRoot(): string {
    $root = ykanEnv('MCP_ROOT');
    return $root !== '' ? $root : dirname(__DIR__);
}

/** Lexically resolve ./ and ../ without touching the filesystem. */
function ykanCanonical(string $path): string {
    $abs = str_starts_with($path, '/');
    $out = [];
    foreach (explode('/', str_replace('\\', '/', $path)) as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') { array_pop($out); continue; }
        $out[] = $p;
    }
    return ($abs ? '/' : '') . implode('/', $out);
}

/** Resolve a swimlane's linked project folder to a real, confined base path. */
function ykanProjectBase(array $lane): string {
    $rel = trim((string)($lane['path'] ?? ''));
    if ($rel === '') throw new RuntimeException('Nessuna cartella collegata a questo progetto.');
    $rootReal = realpath(ykanMcpRoot());
    if ($rootReal === false) throw new RuntimeException('MCP_ROOT non valido sul server.');
    $baseReal = realpath($rootReal . '/' . $rel);
    if ($baseReal === false) throw new RuntimeException('La cartella collegata non esiste: ' . $rel);
    if ($baseReal !== $rootReal && !str_starts_with($baseReal, $rootReal . '/')) {
        throw new RuntimeException('La cartella collegata esce da MCP_ROOT.');
    }
    return $baseReal;
}

/** Confine a relative subpath inside a base folder; returns the absolute path. */
function ykanSafePath(string $baseReal, string $rel): string {
    if (strpos($rel, "\0") !== false) throw new RuntimeException('Path non valido.');
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $target = ykanCanonical($baseReal . '/' . $rel);
    if ($target !== $baseReal && !str_starts_with($target, $baseReal . '/')) {
        throw new RuntimeException('Il path esce dalla cartella del progetto.');
    }
    return $target;
}

// === PM LAYER (spec-driven: PRD → epic → cards) ==============================
// PRD/epic markdown live centralised in the _Ykan project folder, one space per
// target project: <root>/ykan/docs/pm/<project-slug>/{prd,epics}/<slug>.md

/** filename/dir-safe slug. */
function ykanPmSlug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 60) : 'pm-' . bin2hex(random_bytes(3));
}

/** Absolute path to <root>/ykan/docs/pm , creating it if missing. */
function ykanPmBase(array $data): string {
    $lane = null;
    foreach ($data['swimlanes'] as $l) {
        if (($l['path'] ?? '') === 'ykan' || strcasecmp((string)($l['name'] ?? ''), '_Ykan') === 0) { $lane = $l; break; }
    }
    if (!$lane) throw new RuntimeException('Swimlane _Ykan non collegata a una cartella: impossibile salvare i doc PM.');
    $pm = ykanProjectBase($lane) . '/docs/pm';
    if (!is_dir($pm) && !@mkdir($pm, 0775, true)) throw new RuntimeException('Impossibile creare docs/pm/.');
    return $pm;
}

/** Find a swimlane by exact name (case-insensitive). */
function ykanLaneByName(array $data, string $name): ?array {
    foreach ($data['swimlanes'] as $l) {
        if (strcasecmp((string)($l['name'] ?? ''), $name) === 0) return $l;
    }
    return null;
}

/** First markdown "# " heading, or the slug. */
function ykanPmTitle(string $md, string $fallback): string {
    if (preg_match('/^#\s+(.+)$/m', $md, $m)) return trim($m[1]);
    return $fallback;
}

/** Read a "key: value" line from a leading --- frontmatter block. */
function ykanPmFront(string $md, string $key): string {
    if (preg_match('/\A---\s*\n(.*?)\n---\s*\n/s', $md, $b)
        && preg_match('/^' . preg_quote($key, '/') . '\s*:\s*(.+)$/m', $b[1], $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Minimal Anthropic agent loop shared by the pm_* endpoints.
 * $tools: [] for a plain completion, or Anthropic tool defs.
 * $run:   fn(string $name, array $input): string  (throws on error)
 * Returns ['ok'=>bool, 'text'=>string, 'log'=>array, 'error'=>?string].
 */
function ykanPmAgent(string $system, string $userPrompt, array $tools = [], ?callable $run = null, int $maxTurns = 12): array {
    $apiKey = ykanEnv('ANTHROPIC_KEY');
    if ($apiKey === '') return ['ok' => false, 'text' => '', 'log' => [], 'error' => 'ANTHROPIC_KEY non configurata in .env'];

    $messages = [['role' => 'user', 'content' => $userPrompt]];
    $log = [];
    $lastText = '';
    @set_time_limit(300);

    for ($turn = 0; $turn < $maxTurns; $turn++) {
        $payload = [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 8192,
            'system' => $system,
            'messages' => $messages,
        ];
        if ($tools) $payload['tools'] = $tools;

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $err = json_decode((string)$response, true);
            return ['ok' => false, 'text' => $lastText, 'log' => $log,
                'error' => 'API Anthropic HTTP ' . $httpCode . ' — ' . ($err['error']['message'] ?? substr((string)$response, 0, 300))];
        }
        $resp = json_decode((string)$response, true);
        $content = $resp['content'] ?? null;
        if (!is_array($content)) return ['ok' => false, 'text' => $lastText, 'log' => $log, 'error' => 'Risposta API inattesa.'];
        $messages[] = ['role' => 'assistant', 'content' => $content];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') { $lastText = $block['text']; $log[] = ['text' => $block['text']]; }
        }
        if (($resp['stop_reason'] ?? 'end_turn') !== 'tool_use') break;

        $toolResults = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_use') continue;
            $log[] = ['tool' => $block['name'], 'input' => $block['input'] ?? []];
            try {
                $r = $run ? (string)$run($block['name'], (array)($block['input'] ?? [])) : 'ok';
                $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => $r];
                $log[] = ['tool_result' => mb_substr($r, 0, 400)];
            } catch (Throwable $e) {
                $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => 'Errore: ' . $e->getMessage(), 'is_error' => true];
                $log[] = ['tool_error' => $e->getMessage()];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $toolResults];
    }
    return ['ok' => true, 'text' => $lastText, 'log' => $log, 'error' => null];
}

/** Pull the first fenced/loose JSON object out of a model reply. */
function ykanPmExtractJson(string $text): ?array {
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
        $j = json_decode($m[1], true);
        if (is_array($j)) return $j;
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $j = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($j)) return $j;
    }
    return null;
}

// === THEMES ===================================================================
// A theme is a small JSON file in themes/ with a "colors" map keyed by the CSS
// variable names (without the leading --). Any AI can generate one; the board
// loads them into a live switcher on top of the built-in light/dark themes.

/** filename-safe slug from a theme name. */
function ykanThemeSlug(string $name): string {
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    return $s !== '' ? substr($s, 0, 60) : 'theme-' . bin2hex(random_bytes(3));
}

/** Validate + normalise a theme array. Missing colors are filled from $base. */
function ykanNormalizeTheme(array $t, array $base = []): array {
    $colorsIn = is_array($t['colors'] ?? null) ? $t['colors'] : [];
    $colors = [];
    foreach (THEME_KEYS as $k) {
        $v = $colorsIn[$k] ?? ($base[$k] ?? '');
        $colors[$k] = is_string($v) ? trim($v) : '';
    }
    // layout + surfaces are optional: keep only the tokens actually provided.
    $pick = function(array $src, array $keys): array {
        $out = [];
        foreach ($keys as $k) {
            $v = $src[$k] ?? '';
            if (is_string($v) || is_int($v) || is_float($v)) {
                $v = trim((string)$v);
                if ($v !== '') $out[$k] = $v;
            }
        }
        return $out;
    };
    $layout   = $pick(is_array($t['layout'] ?? null) ? $t['layout'] : [], THEME_LAYOUT_KEYS);
    $surfaces = $pick(is_array($t['surfaces'] ?? null) ? $t['surfaces'] : [], THEME_SURFACE_KEYS);

    // Raw CSS escape hatch — injected verbatim after the variables. Strip any
    // </style> so a theme can't break out of its <style> block.
    $css = is_string($t['css'] ?? null) ? str_ireplace('</style', '', $t['css']) : '';

    return [
        'name'        => trim((string)($t['name'] ?? 'Untitled theme')),
        'author'      => trim((string)($t['author'] ?? '')),
        'description' => trim((string)($t['description'] ?? '')),
        'dark'        => (bool)($t['dark'] ?? false),
        'colors'      => $colors,
        'layout'      => (object)$layout,   // empty object if none → valid JSON {}
        'surfaces'    => (object)$surfaces,
        'css'         => $css,
    ];
}

/** Read every theme file, newest first. Returns list with 'file' slug added. */
function ykanListThemes(): array {
    if (!is_dir(THEMES_DIR)) return [];
    $out = [];
    foreach (glob(THEMES_DIR . '/*.json') ?: [] as $path) {
        $t = json_decode((string)@file_get_contents($path), true);
        if (!is_array($t)) continue;
        $norm = ykanNormalizeTheme($t);
        $norm['file'] = basename($path, '.json');
        $out[] = $norm;
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Build the ":root{ --var:value; }" CSS for a stored theme file (or ''). */
function ykanThemeCss(string $file): string {
    $file = basename($file); // no path traversal
    $path = THEMES_DIR . '/' . $file . '.json';
    if (!is_file($path)) return '';
    $t = json_decode((string)@file_get_contents($path), true);
    if (!is_array($t)) return '';
    $t = ykanNormalizeTheme($t);
    $decls = [];
    foreach ($t['colors'] as $k => $v) {
        if ($v !== '') $decls[] = '--' . $k . ':' . $v;
    }
    foreach ((array)$t['layout'] as $k => $v) {
        if ($v !== '') $decls[] = '--' . $k . ':' . $v;
    }
    foreach ((array)$t['surfaces'] as $k => $v) {
        if ($v !== '') $decls[] = '--' . $k . ':' . $v;
    }
    $css = $decls ? ':root{' . implode(';', $decls) . '}' : '';
    if (!empty($t['css'])) $css .= "\n" . $t['css']; // raw theme CSS (already sanitised)
    return $css;
}

// === PROJECT SCANNER ===
function scanProject(string $rootDir, int $maxDepth = 3): array {
    $excludeDirs = ['vendor', 'node_modules', '.git', 'cache', 'storage', 'logs', 'tmp', 'temp', '.idea', '.vscode'];
    $excludeFiles = ['_Ykan.php', '_Ykan_data.json'];
    $keyFiles = ['README.md', 'readme.md', 'README.txt', 'composer.json', 'package.json', '.env.example', 'config.php', 'settings.php'];
    $sourceExts = ['php', 'js', 'ts', 'py', 'rb', 'go', 'java', 'cs'];

    $result = [
        'tree' => [],
        'key_files' => [],
        'entry_points' => [],
        'stats' => ['total_files' => 0, 'total_dirs' => 0, 'by_ext' => []]
    ];

    $totalContent = 0;
    $maxContent = 30000; // ~30KB limite

    // Scan directory tree
    $scanDir = function(string $dir, int $depth = 0, string $prefix = '') use (
        &$scanDir, &$result, &$totalContent, $maxContent, $rootDir,
        $excludeDirs, $excludeFiles, $keyFiles, $sourceExts, $maxDepth
    ) {
        if ($depth > $maxDepth) return;

        $items = @scandir($dir);
        if (!$items) return;

        $items = array_diff($items, ['.', '..']);
        sort($items);

        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            $relativePath = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $path);

            if (is_dir($path)) {
                if (in_array($item, $excludeDirs)) continue;
                $result['stats']['total_dirs']++;
                $result['tree'][] = $prefix . '📁 ' . $item . '/';
                $scanDir($path, $depth + 1, $prefix . '  ');
            } else {
                if (in_array($item, $excludeFiles)) continue;
                $result['stats']['total_files']++;

                // Stats by extension
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                $result['stats']['by_ext'][$ext] = ($result['stats']['by_ext'][$ext] ?? 0) + 1;

                $result['tree'][] = $prefix . '📄 ' . $item;

                // Key files content
                if (in_array($item, $keyFiles) && $totalContent < $maxContent) {
                    $content = @file_get_contents($path);
                    if ($content && strlen($content) < 5000) {
                        $result['key_files'][$relativePath] = $content;
                        $totalContent += strlen($content);
                    }
                }

                // Entry points (PHP files in root)
                if ($depth === 0 && $ext === 'php' && $totalContent < $maxContent) {
                    $content = @file_get_contents($path);
                    if ($content) {
                        $lines = array_slice(explode("\n", $content), 0, 50);
                        $preview = implode("\n", $lines);
                        $result['entry_points'][$item] = $preview;
                        $totalContent += strlen($preview);
                    }
                }
            }
        }
    };

    $scanDir($rootDir);

    return $result;
}

// === API HANDLER ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $data = loadData();
    $action = $_GET['api'];

    try {
        $result = match($action) {
            // Config
            'save_config' => (function() use (&$data, $input) {
                $data['config'] = array_merge($data['config'], $input);
                saveData($data);
                return ['success' => true];
            })(),

            // Columns
            'add_column' => (function() use (&$data, $input) {
                $col = [
                    'id' => generateId('col'),
                    'name' => $input['name'] ?? 'New Column',
                    'position' => count($data['columns'])
                ];
                $data['columns'][] = $col;
                saveData($data);
                return ['success' => true, 'column' => $col];
            })(),

            'update_column' => (function() use (&$data, $input) {
                foreach ($data['columns'] as &$col) {
                    if ($col['id'] === $input['id']) {
                        $col['name'] = $input['name'] ?? $col['name'];
                        break;
                    }
                }
                saveData($data);
                return ['success' => true];
            })(),

            'delete_column' => (function() use (&$data, $input) {
                $data['columns'] = array_values(array_filter($data['columns'], fn($c) => $c['id'] !== $input['id']));
                $data['cards'] = array_values(array_filter($data['cards'], fn($c) => $c['column_id'] !== $input['id']));
                saveData($data);
                return ['success' => true];
            })(),

            'reorder_columns' => (function() use (&$data, $input) {
                $order = $input['order'] ?? [];
                foreach ($data['columns'] as &$col) {
                    $pos = array_search($col['id'], $order);
                    if ($pos !== false) $col['position'] = $pos;
                }
                usort($data['columns'], fn($a, $b) => $a['position'] <=> $b['position']);
                saveData($data);
                return ['success' => true];
            })(),

            // Swimlanes
            'add_swimlane' => (function() use (&$data, $input) {
                $lane = [
                    'id' => generateId('lane'),
                    'name' => $input['name'] ?? 'New Swimlane',
                    'position' => count($data['swimlanes']),
                    // Project link (used by mcp.php for scoped file operations)
                    'path' => $input['path'] ?? '',
                    'url' => $input['url'] ?? '',
                    // Local folder on this machine (used by the local Bridge, not mcp.php)
                    'local_path' => $input['local_path'] ?? '',
                    'github_repo' => $input['github_repo'] ?? ''
                ];
                $data['swimlanes'][] = $lane;
                saveData($data);
                return ['success' => true, 'swimlane' => $lane];
            })(),

            'update_swimlane' => (function() use (&$data, $input) {
                foreach ($data['swimlanes'] as &$lane) {
                    if ($lane['id'] === $input['id']) {
                        $lane['name'] = $input['name'] ?? $lane['name'];
                        // Project link fields (only touched when provided)
                        if (array_key_exists('path', $input)) $lane['path'] = $input['path'];
                        if (array_key_exists('url', $input)) $lane['url'] = $input['url'];
                        if (array_key_exists('local_path', $input)) $lane['local_path'] = $input['local_path'];
                        if (array_key_exists('github_repo', $input) && preg_match('/^([\w.-]+\/[\w.-]+)?$/', (string)$input['github_repo'])) $lane['github_repo'] = $input['github_repo'];
                        // Doc/structure files the AI should study before working
                        if (array_key_exists('doc_files', $input)) $lane['doc_files'] = array_values($input['doc_files'] ?? []);
                        break;
                    }
                }
                saveData($data);
                return ['success' => true];
            })(),

            'delete_swimlane' => (function() use (&$data, $input) {
                if (count($data['swimlanes']) <= 1) {
                    return ['success' => false, 'error' => 'Cannot delete last swimlane'];
                }
                $firstLane = $data['swimlanes'][0]['id'];
                foreach ($data['cards'] as &$card) {
                    if ($card['swimlane_id'] === $input['id']) {
                        $card['swimlane_id'] = $firstLane;
                    }
                }
                $data['swimlanes'] = array_values(array_filter($data['swimlanes'], fn($l) => $l['id'] !== $input['id']));
                saveData($data);
                return ['success' => true];
            })(),

            'reorder_swimlanes' => (function() use (&$data, $input) {
                $order = $input['order'] ?? [];
                foreach ($data['swimlanes'] as &$lane) {
                    $pos = array_search($lane['id'], $order);
                    if ($pos !== false) $lane['position'] = $pos;
                }
                usort($data['swimlanes'], fn($a, $b) => $a['position'] <=> $b['position']);
                saveData($data);
                return ['success' => true];
            })(),

            // Browse a project's linked folder (for tagging doc/structure files)
            'list_project_files' => (function() use ($data, $input) {
                $lane = null;
                foreach ($data['swimlanes'] as $l) {
                    if ($l['id'] === ($input['id'] ?? '')) { $lane = $l; break; }
                }
                if (!$lane) return ['success' => false, 'error' => 'Swimlane non trovata'];
                $base = ykanProjectBase($lane); // throws if not linked / invalid
                $sub  = (string)($input['subpath'] ?? '');
                $dir  = ykanSafePath($base, $sub);
                if (!is_dir($dir)) return ['success' => false, 'error' => 'Non è una cartella: ' . $sub];
                $sub  = trim(str_replace('\\', '/', $sub), '/');
                $entries = [];
                foreach (@scandir($dir) ?: [] as $it) {
                    if ($it === '.' || $it === '..' || $it === '.ykan_backups') continue;
                    $full  = $dir . '/' . $it;
                    $isDir = is_dir($full);
                    $rel   = ltrim(($sub !== '' ? $sub . '/' : '') . $it, '/');
                    $entries[] = [
                        'name' => $it,
                        'type' => $isDir ? 'dir' : 'file',
                        'rel'  => $rel,
                        'size' => $isDir ? null : (@filesize($full) ?: 0),
                    ];
                }
                usort($entries, fn($a, $b) =>
                    [$a['type'] === 'file' ? 1 : 0, strtolower($a['name'])]
                    <=> [$b['type'] === 'file' ? 1 : 0, strtolower($b['name'])]
                );
                return [
                    'success'   => true,
                    'subpath'   => $sub,
                    'entries'   => $entries,
                    'doc_files' => array_values($lane['doc_files'] ?? []),
                ];
            })(),

            // Themes
            'list_themes' => ['success' => true, 'themes' => ykanListThemes()],

            'save_theme' => (function() use ($input) {
                $theme = $input['theme'] ?? null;
                // Also accept a raw JSON string in 'json'
                if (!is_array($theme) && isset($input['json'])) {
                    $theme = json_decode((string)$input['json'], true);
                }
                if (!is_array($theme)) return ['success' => false, 'error' => 'JSON tema non valido.'];
                $norm = ykanNormalizeTheme($theme);
                if ($norm['name'] === '') return ['success' => false, 'error' => 'Il tema deve avere un nome.'];
                if (!is_dir(THEMES_DIR) && !@mkdir(THEMES_DIR, 0775, true)) {
                    return ['success' => false, 'error' => 'Impossibile creare la cartella themes/.'];
                }
                $slug = ykanThemeSlug($norm['name']);
                $path = THEMES_DIR . '/' . $slug . '.json';
                if (file_put_contents($path, json_encode($norm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
                    return ['success' => false, 'error' => 'Scrittura del tema fallita (permessi?).'];
                }
                $norm['file'] = $slug;
                return ['success' => true, 'theme' => $norm];
            })(),

            'delete_theme' => (function() use ($input) {
                $file = basename((string)($input['file'] ?? ''));
                if ($file === '') return ['success' => false, 'error' => 'File tema mancante.'];
                $path = THEMES_DIR . '/' . $file . '.json';
                if (is_file($path)) @unlink($path);
                return ['success' => true];
            })(),

            // Cards
            'add_card' => (function() use (&$data, $input) {
                $card = [
                    'id' => generateId('card'),
                    'seq' => ykanAllocSeq($data),
                    'title' => $input['title'] ?? 'New Card',
                    'description' => $input['description'] ?? '',
                    'priority' => $input['priority'] ?? 'medium',
                    'due_date' => $input['due_date'] ?? null,
                    'next_check' => $input['next_check'] ?? null,
                    'label_id' => $input['label_id'] ?? null,
                    'column_id' => $input['column_id'] ?? $data['columns'][0]['id'],
                    'swimlane_id' => $input['swimlane_id'] ?? $data['swimlanes'][0]['id'],
                    'archived' => false,
                    'auto_regenerate' => $input['auto_regenerate'] ?? false,
                    'regenerate_delay_days' => $input['regenerate_delay_days'] ?? 0,
                    'files' => $input['files'] ?? [],
                    // PM layer (epic / dependencies / estimate / acceptance)
                    'epic' => $input['epic'] ?? null,
                    'depends_on' => $input['depends_on'] ?? [],
                    'parallel' => $input['parallel'] ?? false,
                    'estimate' => $input['estimate'] ?? null,
                    'acceptance' => $input['acceptance'] ?? [],
                    'position' => count(array_filter($data['cards'], fn($c) =>
                        $c['column_id'] === ($input['column_id'] ?? $data['columns'][0]['id']) &&
                        $c['swimlane_id'] === ($input['swimlane_id'] ?? $data['swimlanes'][0]['id'])
                    )),
                    'created_at' => date('Y-m-d H:i:s'),
                    'regenerated_count' => 0
                ];
                $data['cards'][] = $card;
                saveData($data);
                return ['success' => true, 'card' => $card];
            })(),

            'update_card' => (function() use (&$data, $input) {
                foreach ($data['cards'] as &$card) {
                    if ($card['id'] === $input['id']) {
                        $prevCol = $card['column_id'] ?? null;
                        $card = array_merge($card, array_filter($input, fn($k) => $k !== 'id', ARRAY_FILTER_USE_KEY));
                        $card['updated_at'] = date('Y-m-d H:i:s');
                        if (($card['column_id'] ?? null) !== $prevCol) $card['moved_at'] = $card['updated_at'];
                        break;
                    }
                }
                saveData($data);
                return ['success' => true];
            })(),

            'move_card' => (function() use (&$data, $input) {
                foreach ($data['cards'] as &$card) {
                    if ($card['id'] === $input['id']) {
                        if (($card['column_id'] ?? null) !== $input['column_id']) {
                            $card['moved_at'] = date('Y-m-d H:i:s');
                            $card['updated_at'] = $card['moved_at'];
                        }
                        $card['column_id'] = $input['column_id'];
                        $card['swimlane_id'] = $input['swimlane_id'];
                        $card['position'] = $input['position'] ?? 0;
                        break;
                    }
                }
                saveData($data);
                return ['success' => true];
            })(),

            'archive_card' => (function() use (&$data, $input) {
                $regeneratedCard = null;
                foreach ($data['cards'] as &$card) {
                    if ($card['id'] === $input['id']) {
                        $card['archived'] = true;
                        $card['archived_at'] = date('Y-m-d H:i:s');

                        // Auto-regenerate: create a new card if enabled
                        if (!empty($card['auto_regenerate'])) {
                            $delayDays = $card['regenerate_delay_days'] ?? 0;
                            $dueDate = null;
                            if ($delayDays > 0) {
                                $dueDate = date('Y-m-d', strtotime("+{$delayDays} days"));
                            }

                            $regeneratedCard = [
                                'id' => generateId('card'),
                                'seq' => ykanAllocSeq($data),
                                'title' => $card['title'],
                                'description' => $card['description'] ?? '',
                                'priority' => $card['priority'] ?? 'medium',
                                'due_date' => $dueDate,
                                'next_check' => $dueDate,
                                'label_id' => $card['label_id'] ?? null,
                                'column_id' => $data['columns'][0]['id'], // Back to first column (To Do)
                                'swimlane_id' => $card['swimlane_id'],
                                'archived' => false,
                                'auto_regenerate' => true,
                                'regenerate_delay_days' => $delayDays,
                                'position' => 0,
                                'created_at' => date('Y-m-d H:i:s'),
                                'regenerated_count' => ($card['regenerated_count'] ?? 0) + 1,
                                'regenerated_from' => $card['id'],
                                'epic' => $card['epic'] ?? null,
                                'depends_on' => $card['depends_on'] ?? [],
                                'parallel' => $card['parallel'] ?? false,
                                'estimate' => $card['estimate'] ?? null,
                                'acceptance' => array_map(
                                    fn($a) => ['text' => $a['text'] ?? '', 'done' => false],
                                    $card['acceptance'] ?? []
                                )
                            ];
                            $data['cards'][] = $regeneratedCard;
                        }
                        break;
                    }
                }
                saveData($data);
                return [
                    'success' => true,
                    'regenerated' => $regeneratedCard !== null,
                    'new_card' => $regeneratedCard
                ];
            })(),

            'restore_card' => (function() use (&$data, $input) {
                foreach ($data['cards'] as &$card) {
                    if ($card['id'] === $input['id']) {
                        $card['archived'] = false;
                        unset($card['archived_at']);
                        break;
                    }
                }
                saveData($data);
                return ['success' => true];
            })(),

            'delete_card' => (function() use (&$data, $input) {
                $data['cards'] = array_values(array_filter($data['cards'], fn($c) => $c['id'] !== $input['id']));
                saveData($data);
                return ['success' => true];
            })(),

            // Labels
            'add_label' => (function() use (&$data, $input) {
                $label = [
                    'id' => generateId('lbl'),
                    'name' => $input['name'] ?? 'New Label',
                    'color' => $input['color'] ?? '#6b7280'
                ];
                $data['labels'][] = $label;
                saveData($data);
                return ['success' => true, 'label' => $label];
            })(),

            'update_label' => (function() use (&$data, $input) {
                foreach ($data['labels'] as &$label) {
                    if ($label['id'] === $input['id']) {
                        if (isset($input['name'])) $label['name'] = $input['name'];
                        if (isset($input['color'])) $label['color'] = $input['color'];
                        break;
                    }
                }
                saveData($data);
                return ['success' => true];
            })(),

            'delete_label' => (function() use (&$data, $input) {
                $data['labels'] = array_values(array_filter($data['labels'], fn($l) => $l['id'] !== $input['id']));
                foreach ($data['cards'] as &$card) {
                    if ($card['label_id'] === $input['id']) {
                        $card['label_id'] = null;
                    }
                }
                saveData($data);
                return ['success' => true];
            })(),

            // Get all data
            'get_data' => (function() use ($data) {
                return ['success' => true, 'data' => $data];
            })(),

            // === AI-FRIENDLY ENDPOINTS ===

            // Get board summary (plain text for AI)
            'get_summary' => (function() use ($data) {
                $projectName = $data['config']['project_name'] ?? 'Project';
                $columns = array_column($data['columns'], 'name', 'id');
                $swimlanes = array_column($data['swimlanes'], 'name', 'id');
                $labels = array_column($data['labels'], 'name', 'id');
                $activeCards = array_filter($data['cards'], fn($c) => !$c['archived']);

                $summary = "# KANBAN: {$projectName}\n\n";

                // Group cards by column
                foreach ($data['columns'] as $col) {
                    $colCards = array_filter($activeCards, fn($c) => $c['column_id'] === $col['id']);
                    $count = count($colCards);
                    $summary .= "## {$col['name']} ({$count})\n";

                    if ($count === 0) {
                        $summary .= "   (empty)\n";
                    } else {
                        foreach ($colCards as $card) {
                            $priority = strtoupper($card['priority'] ?? 'medium');
                            $label = isset($card['label_id']) ? ($labels[$card['label_id']] ?? '') : '';
                            $labelStr = $label ? " [{$label}]" : '';
                            $lane = $swimlanes[$card['swimlane_id']] ?? '';
                            $laneStr = ($lane && $lane !== 'Default') ? " @{$lane}" : '';
                            $dueStr = $card['due_date'] ? " (due: {$card['due_date']})" : '';

                            $summary .= "   - [{$priority}]{$labelStr} {$card['title']}{$laneStr}{$dueStr}\n";
                            if (!empty($card['description'])) {
                                $desc = substr($card['description'], 0, 100);
                                $summary .= "     > {$desc}\n";
                            }
                        }
                    }
                    $summary .= "\n";
                }

                // Archived count
                $archivedCount = count(array_filter($data['cards'], fn($c) => $c['archived']));
                if ($archivedCount > 0) {
                    $summary .= "## Archived: {$archivedCount} tasks\n";
                }

                return ['success' => true, 'summary' => $summary];
            })(),

            // Complete task by title (archive it)
            'complete_task' => (function() use (&$data, $input) {
                $title = trim($input['title'] ?? '');
                if (empty($title)) {
                    return ['success' => false, 'error' => 'Title required'];
                }

                $regeneratedCard = null;
                foreach ($data['cards'] as &$card) {
                    if (!$card['archived'] && stripos($card['title'], $title) !== false) {
                        $card['archived'] = true;
                        $card['archived_at'] = date('Y-m-d H:i:s');

                        // Auto-regenerate: create a new card if enabled
                        if (!empty($card['auto_regenerate'])) {
                            $delayDays = $card['regenerate_delay_days'] ?? 0;
                            $dueDate = null;
                            if ($delayDays > 0) {
                                $dueDate = date('Y-m-d', strtotime("+{$delayDays} days"));
                            }

                            $regeneratedCard = [
                                'id' => generateId('card'),
                                'seq' => ykanAllocSeq($data),
                                'title' => $card['title'],
                                'description' => $card['description'] ?? '',
                                'priority' => $card['priority'] ?? 'medium',
                                'due_date' => $dueDate,
                                'next_check' => $dueDate,
                                'label_id' => $card['label_id'] ?? null,
                                'column_id' => $data['columns'][0]['id'],
                                'swimlane_id' => $card['swimlane_id'],
                                'archived' => false,
                                'auto_regenerate' => true,
                                'regenerate_delay_days' => $delayDays,
                                'position' => 0,
                                'created_at' => date('Y-m-d H:i:s'),
                                'regenerated_count' => ($card['regenerated_count'] ?? 0) + 1,
                                'regenerated_from' => $card['id'],
                                'epic' => $card['epic'] ?? null,
                                'depends_on' => $card['depends_on'] ?? [],
                                'parallel' => $card['parallel'] ?? false,
                                'estimate' => $card['estimate'] ?? null,
                                'acceptance' => array_map(
                                    fn($a) => ['text' => $a['text'] ?? '', 'done' => false],
                                    $card['acceptance'] ?? []
                                )
                            ];
                            $data['cards'][] = $regeneratedCard;
                        }

                        saveData($data);
                        $msg = "Task '{$card['title']}' completed and archived";
                        if ($regeneratedCard) {
                            $msg .= ". Task auto-regenerated" . ($delayDays > 0 ? " (due: {$dueDate})" : "");
                        }
                        return ['success' => true, 'message' => $msg, 'regenerated' => $regeneratedCard !== null];
                    }
                }

                return ['success' => false, 'error' => "Task '{$title}' not found"];
            })(),

            // Move task to column by title
            'move_task' => (function() use (&$data, $input) {
                $title = trim($input['title'] ?? '');
                $columnName = trim($input['column'] ?? '');

                if (empty($title) || empty($columnName)) {
                    return ['success' => false, 'error' => 'Title and column required'];
                }

                // Find column
                $targetColumn = null;
                foreach ($data['columns'] as $col) {
                    if (stripos($col['name'], $columnName) !== false) {
                        $targetColumn = $col;
                        break;
                    }
                }

                if (!$targetColumn) {
                    $colNames = implode(', ', array_column($data['columns'], 'name'));
                    return ['success' => false, 'error' => "Column '{$columnName}' not found. Available: {$colNames}"];
                }

                // Find and move card
                foreach ($data['cards'] as &$card) {
                    if (!$card['archived'] && stripos($card['title'], $title) !== false) {
                        $oldCol = '';
                        foreach ($data['columns'] as $c) {
                            if ($c['id'] === $card['column_id']) $oldCol = $c['name'];
                        }
                        $card['column_id'] = $targetColumn['id'];
                        saveData($data);
                        return ['success' => true, 'message' => "Task '{$card['title']}' moved from '{$oldCol}' to '{$targetColumn['name']}'"];
                    }
                }

                return ['success' => false, 'error' => "Task '{$title}' not found"];
            })(),

            // Gemini
            'gemini_analyze' => (function() use ($data, $input) {
                $apiKey = $data['config']['gemini_api_key'] ?? '';
                if (empty($apiKey)) {
                    return ['success' => false, 'error' => 'Gemini API Key not configured'];
                }

                $activeCards = array_filter($data['cards'], fn($c) => !$c['archived']);
                $prompt = $input['prompt'] ?? 'analyze';

                $boardSummary = "Kanban Board: " . ($data['config']['project_name'] ?? 'Project') . "\n\n";
                $boardSummary .= "Columns: " . implode(', ', array_column($data['columns'], 'name')) . "\n";
                $boardSummary .= "Swimlanes: " . implode(', ', array_column($data['swimlanes'], 'name')) . "\n\n";
                $boardSummary .= "Active cards:\n";

                foreach ($activeCards as $card) {
                    $col = array_values(array_filter($data['columns'], fn($c) => $c['id'] === $card['column_id']))[0]['name'] ?? 'N/A';
                    $lane = array_values(array_filter($data['swimlanes'], fn($l) => $l['id'] === $card['swimlane_id']))[0]['name'] ?? 'N/A';
                    $boardSummary .= "- [{$card['priority']}] {$card['title']} (Column: {$col}, Swimlane: {$lane})";
                    if ($card['due_date']) $boardSummary .= " - Due: {$card['due_date']}";
                    $boardSummary .= "\n";
                    if ($card['description']) $boardSummary .= "  Desc: {$card['description']}\n";
                }

                $lang = $data['config']['ai_language'] ?? 'en';
                $langInstruction = $lang !== 'en' ? " Respond in " . match($lang) {
                    'it' => 'Italian', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'pt' => 'Portuguese', default => 'English'
                } . "." : "";

                $systemPrompt = match($prompt) {
                    'suggest_tasks' => "You are an expert Agile project manager. Analyze the Kanban board and suggest useful new tasks for the project. Be concise.{$langInstruction}",
                    'analyze' => "You are an expert Agile project manager. Analyze the Kanban board and suggest which task to proceed with, any priorities to review, and estimated timelines. Be concise and practical.{$langInstruction}",
                    'estimate' => "You are an expert Agile project manager. Analyze the tasks and provide realistic time estimates to complete them.{$langInstruction}",
                    default => "You are an assistant for Agile project management.{$langInstruction}"
                };

                $payload = [
                    'contents' => [
                        ['parts' => [['text' => $boardSummary . "\n\nRequest: " . ($input['custom_prompt'] ?? $prompt)]]]
                    ],
                    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                    'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 8192]
                ];

                $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_TIMEOUT => 30
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    $errorMsg = match($httpCode) {
                        429 => 'Rate limit reached. Wait 1-2 minutes and try again.',
                        401, 403 => 'API Key invalid or without permissions.',
                        500, 502, 503 => 'Gemini service temporarily unavailable.',
                        default => 'Gemini API error: ' . $httpCode
                    };
                    return ['success' => false, 'error' => $errorMsg];
                }

                $result = json_decode($response, true);
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? 'No response';

                return ['success' => true, 'response' => $text];
            })(),

            // Analyze Project
            'analyze_project' => (function() use ($data) {
                $apiKey = $data['config']['gemini_api_key'] ?? '';
                if (empty($apiKey)) {
                    return ['success' => false, 'error' => 'Gemini API Key not configured'];
                }

                // Scan project
                $projectData = scanProject(__DIR__);

                // Build context
                $context = "=== PROJECT ANALYSIS ===\n\n";
                $context .= "Project: " . ($data['config']['project_name'] ?? 'N/A') . "\n\n";

                // Stats
                $context .= "📊 STATISTICS:\n";
                $context .= "- Total files: {$projectData['stats']['total_files']}\n";
                $context .= "- Folders: {$projectData['stats']['total_dirs']}\n";
                if (!empty($projectData['stats']['by_ext'])) {
                    arsort($projectData['stats']['by_ext']);
                    $topExts = array_slice($projectData['stats']['by_ext'], 0, 5, true);
                    $context .= "- File types: " . implode(', ', array_map(fn($k, $v) => "$k($v)", array_keys($topExts), $topExts)) . "\n";
                }
                $context .= "\n";

                // Tree
                $context .= "📁 STRUCTURE:\n";
                $context .= implode("\n", array_slice($projectData['tree'], 0, 100)) . "\n\n";

                // Key files
                if (!empty($projectData['key_files'])) {
                    $context .= "📄 KEY FILES:\n";
                    foreach ($projectData['key_files'] as $file => $content) {
                        $context .= "--- {$file} ---\n{$content}\n\n";
                    }
                }

                // Entry points
                if (!empty($projectData['entry_points'])) {
                    $context .= "🚀 ENTRY POINTS (first 50 lines):\n";
                    foreach ($projectData['entry_points'] as $file => $content) {
                        $context .= "--- {$file} ---\n{$content}\n\n";
                    }
                }

                // Current board state
                $activeCards = array_filter($data['cards'], fn($c) => !$c['archived']);
                if (!empty($activeCards)) {
                    $context .= "📋 CURRENT KANBAN TASKS:\n";
                    foreach ($activeCards as $card) {
                        $col = array_values(array_filter($data['columns'], fn($c) => $c['id'] === $card['column_id']))[0]['name'] ?? 'N/A';
                        $context .= "- [{$card['priority']}] {$card['title']} ({$col})\n";
                    }
                }

                $systemPrompt = <<<PROMPT
Analyze the project and respond ONLY with this JSON (no other text):

{"analysis":{"overview":"Max 2 sentences","tech_stack":["max 5 tech"],"architecture":"one line","strengths":["max 3"],"concerns":["max 3"]},"suggested_tasks":[{"title":"short","description":"max 1 sentence","priority":"high|medium|low","category":"bug|feature|refactor|security|docs|test"}]}

IMPORTANT RULES:
- Pure JSON, NO markdown, NO ```
- Max 5 suggested tasks
- Keep text SHORT and concise
PROMPT;
                $lang = $data['config']['ai_language'] ?? 'en';
                if ($lang !== 'en') {
                    $langName = match($lang) { 'it' => 'Italian', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'pt' => 'Portuguese', default => 'English' };
                    $systemPrompt .= "\n- Respond in {$langName}";
                }

                $payload = [
                    'contents' => [['parts' => [['text' => $context]]]],
                    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                    'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 4096]
                ];

                $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_TIMEOUT => 60
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    $errorMsg = match($httpCode) {
                        429 => 'Rate limit reached. Wait 1-2 minutes and try again.',
                        401, 403 => 'API Key invalid or without permissions.',
                        500, 502, 503 => 'Gemini service temporarily unavailable.',
                        default => 'Gemini API error: ' . $httpCode
                    };
                    return ['success' => false, 'error' => $errorMsg];
                }

                $result = json_decode($response, true);
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Clean JSON from markdown code blocks (more robust)
                $text = trim($text);
                $text = preg_replace('/^```json\s*/i', '', $text);
                $text = preg_replace('/^```\s*/i', '', $text);
                $text = preg_replace('/```\s*$/i', '', $text);
                $text = trim($text);

                // Try to extract JSON if there's extra text
                if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
                    $text = $matches[0];
                }

                $parsed = json_decode($text, true);
                if (!$parsed || json_last_error() !== JSON_ERROR_NONE) {
                    // Return raw for debug
                    return [
                        'success' => false,
                        'error' => 'Invalid response from Gemini. Try again.',
                        'debug' => substr($result['candidates'][0]['content']['parts'][0]['text'] ?? 'empty', 0, 500)
                    ];
                }

                return ['success' => true, 'result' => $parsed];
            })(),

            // Verify task with AI
            'verify_task' => (function() use ($data, $input) {
                $apiKey = $data['config']['gemini_api_key'] ?? '';
                if (empty($apiKey)) {
                    return ['success' => false, 'error' => 'Gemini API Key not configured'];
                }

                $cardId = $input['card_id'] ?? '';
                $card = null;
                foreach ($data['cards'] as $c) {
                    if ($c['id'] === $cardId) {
                        $card = $c;
                        break;
                    }
                }

                if (!$card) {
                    return ['success' => false, 'error' => 'Card not found'];
                }

                $files = $card['files'] ?? [];
                if (empty($files)) {
                    return ['success' => false, 'error' => 'No files associated with task'];
                }

                // Read file contents
                $filesContent = "";
                $maxSize = 200000; // 200KB max per file
                $totalSize = 0;
                $maxTotal = 500000; // 500KB total max

                foreach ($files as $filePath) {
                    $fullPath = __DIR__ . '/' . ltrim($filePath, '/');
                    if (!file_exists($fullPath)) {
                        $filesContent .= "\n--- FILE: {$filePath} ---\n[FILE NOT FOUND]\n";
                        continue;
                    }

                    $size = filesize($fullPath);
                    if ($size > $maxSize) {
                        // For large files, read first and last portions
                        $content = file_get_contents($fullPath, false, null, 0, 80000);
                        $content .= "\n\n[... CONTENT TRUNCATED ...]\n\n";
                        $content .= file_get_contents($fullPath, false, null, max(0, $size - 80000));
                        $filesContent .= "\n--- FILE: {$filePath} (truncated, original: {$size} bytes) ---\n{$content}\n";
                        $totalSize += strlen($content);
                        continue;
                    }

                    $content = file_get_contents($fullPath);
                    $totalSize += strlen($content);

                    if ($totalSize > $maxTotal) {
                        $filesContent .= "\n--- FILE: {$filePath} ---\n[TOTAL LIMIT REACHED]\n";
                        break;
                    }

                    $filesContent .= "\n--- FILE: {$filePath} ---\n{$content}\n";
                }

                // Build prompt
                $label = '';
                foreach ($data['labels'] as $l) {
                    if ($l['id'] === $card['label_id']) {
                        $label = $l['name'];
                        break;
                    }
                }

                $taskContext = "TASK: {$card['title']}\n";
                $taskContext .= "TYPE: {$label}\n";
                $taskContext .= "DESCRIPTION: {$card['description']}\n";
                $taskContext .= "PRIORITY: {$card['priority']}\n";

                $systemPrompt = <<<PROMPT
You are an assistant for verifying software development tasks. Analyze the task and provided files.

RESPOND with this JSON format (nothing else):
{
    "status": "completed|partial|not_completed|bug_found",
    "confidence": 0-100,
    "analysis": "Brief analysis of what you found",
    "evidence": "Where in the code you found evidence (line, function, etc)",
    "suggestion": "If not completed or bug: what to do. If completed: null",
    "can_close": true/false
}

RULES:
- "completed": the task seems done, the code reflects what was requested
- "partial": some parts done, others missing
- "not_completed": you find no evidence that the task was done
- "bug_found": you identified a bug in the code related to the task
- can_close=true only if you're reasonably sure the task is completed
- Be specific in evidence, cite lines or functions
PROMPT;
                $lang = $data['config']['ai_language'] ?? 'en';
                if ($lang !== 'en') {
                    $langName = match($lang) { 'it' => 'Italian', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'pt' => 'Portuguese', default => 'English' };
                    $systemPrompt .= "\n- Write analysis, evidence, and suggestion fields in {$langName}";
                }

                $payload = [
                    'contents' => [['parts' => [['text' => $taskContext . "\n\nFILE ASSOCIATI:\n" . $filesContent]]]],
                    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                    'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 8192]
                ];

                $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_TIMEOUT => 60
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    $errorMsg = match($httpCode) {
                        429 => 'Rate limit reached. Wait 1-2 minutes.',
                        401, 403 => 'API Key invalid.',
                        default => 'API error: ' . $httpCode
                    };
                    return ['success' => false, 'error' => $errorMsg];
                }

                $result = json_decode($response, true);
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Clean and parse JSON
                $text = trim($text);
                $text = preg_replace('/^```json\s*/i', '', $text);
                $text = preg_replace('/```\s*$/i', '', $text);
                if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
                    $text = $matches[0];
                }

                $parsed = json_decode($text, true);
                if (!$parsed) {
                    return ['success' => true, 'result' => ['status' => 'error', 'analysis' => $text, 'can_close' => false]];
                }

                return ['success' => true, 'result' => $parsed];
            })(),

            // AI Auto-categorize
            'ai_categorize' => (function() use ($data, $input) {
                $apiKey = $data['config']['gemini_api_key'] ?? '';
                if (empty($apiKey)) return ['success' => false, 'error' => 'API Key not configured'];

                $title = $input['title'] ?? '';
                $desc = $input['description'] ?? '';
                $labels = array_map(fn($l) => $l['name'], $data['labels']);
                $lanes = array_map(fn($l) => $l['name'], $data['swimlanes']);

                $lang = $data['config']['ai_language'] ?? 'en';
                $langSuffix = $lang !== 'en' ? " Respond in " . match($lang) { 'it' => 'Italian', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'pt' => 'Portuguese', default => 'English' } . "." : "";
                $prompt = "Analyze this task and suggest categorization.\n\nTitle: {$title}\nDescription: {$desc}\n\nAvailable labels: " . implode(', ', $labels) . "\nAvailable swimlanes: " . implode(', ', $lanes) . "\n\nRespond ONLY with JSON (nothing else):\n{\"priority\":\"high|medium|low\",\"label\":\"label_name\",\"swimlane\":\"swimlane_name\",\"reason\":\"brief reason\"}{$langSuffix}";

                $payload = [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 256]
                ];

                $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 30]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) return ['success' => false, 'error' => 'API error: ' . $httpCode];

                $result = json_decode($response, true);
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $text = preg_replace('/^```json\s*|\s*```$/i', '', trim($text));
                if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
                $parsed = json_decode($text, true);

                return ['success' => true, 'suggestion' => $parsed];
            })(),

            // AI Estimate
            'ai_estimate' => (function() use ($data, $input) {
                $apiKey = $data['config']['gemini_api_key'] ?? '';
                if (empty($apiKey)) return ['success' => false, 'error' => 'API Key not configured'];

                $title = $input['title'] ?? '';
                $desc = $input['description'] ?? '';

                $lang = $data['config']['ai_language'] ?? 'en';
                $langSuffix = $lang !== 'en' ? " Respond in " . match($lang) { 'it' => 'Italian', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'pt' => 'Portuguese', default => 'English' } . "." : "";
                $prompt = "Estimate the time needed to complete this software development task.\n\nTitle: {$title}\nDescription: {$desc}\n\nRespond ONLY with JSON:\n{\"estimate\":\"e.g.: 2h, 1 day, 3-5 days\",\"complexity\":\"low|medium|high\",\"breakdown\":[\"step1\",\"step2\"]}{$langSuffix}";

                $payload = [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 512]
                ];

                $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 30]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) return ['success' => false, 'error' => 'API error: ' . $httpCode];

                $result = json_decode($response, true);
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $text = preg_replace('/^```json\s*|\s*```$/i', '', trim($text));
                if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
                $parsed = json_decode($text, true);

                return ['success' => true, 'estimate' => $parsed];
            })(),

            // Daily Standup AI
            'ai_standup' => (function() use ($data) {
                $apiKey = $data['config']['gemini_api_key'] ?? '';
                if (empty($apiKey)) return ['success' => false, 'error' => 'API Key not configured'];

                $summary = "";
                $cols = array_column($data['columns'], 'name', 'id');
                $lanes = array_column($data['swimlanes'], 'name', 'id');
                foreach ($data['cards'] as $c) {
                    if ($c['archived']) continue;
                    $col = $cols[$c['column_id']] ?? 'Unknown';
                    $lane = $lanes[$c['swimlane_id']] ?? '';
                    $summary .= "- [{$col}] {$c['title']} (priority: {$c['priority']}, swimlane: {$lane})\n";
                }

                $lang = $data['config']['ai_language'] ?? 'en';
                $langSuffix = $lang !== 'en' ? " Respond in " . match($lang) { 'it' => 'Italian', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'pt' => 'Portuguese', default => 'English' } . "." : "";
                $prompt = "Generate an Agile Daily Standup report based on these tasks:\n\n{$summary}\n\nReport format:\n1. **Completed yesterday** (tasks in Done)\n2. **In progress today** (tasks In Progress)\n3. **Next up** (priority tasks in To Do)\n4. **Blockers/Risks**\n\nMax 200 words.{$langSuffix}";

                $payload = [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 1024]
                ];

                $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 30]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) return ['success' => false, 'error' => 'API error: ' . $httpCode];

                $result = json_decode($response, true);
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                return ['success' => true, 'standup' => $text];
            })(),

            // Git TODO Scanner
            'scan_todos' => (function() {
                $projectDir = __DIR__;
                $todos = [];
                $extensions = ['php', 'js', 'ts', 'jsx', 'tsx', 'css', 'html', 'py', 'java', 'c', 'cpp', 'h', 'go', 'rs'];

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($projectDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file) {
                    if ($file->isDir()) continue;
                    $ext = strtolower($file->getExtension());
                    if (!in_array($ext, $extensions)) continue;

                    // Skip vendor, node_modules, libraries, backups, etc
                    $path = $file->getPathname();
                    $filename = $file->getFilename();
                    if (preg_match('/(vendor|node_modules|\.git|cache|tmp)/i', $path)) continue;
                    if (preg_match('/(phpliteadmin|SimpleXLSX|Copia|backup|_Ykan)/i', $filename)) continue;

                    $content = @file_get_contents($path);
                    if (!$content) continue;

                    $lines = explode("\n", $content);
                    foreach ($lines as $lineNum => $line) {
                        // Match TODO/FIXME/etc only in comments (preceded by //, #, *, or <!--)
                        if (preg_match('/(\/\/|#|\*|<!--)\s*(TODO|FIXME|HACK|XXX|BUG)[\s:]+(.+)/i', $line, $m)) {
                            $relativePath = str_replace($projectDir . DIRECTORY_SEPARATOR, '', $path);
                            $todos[] = [
                                'type' => strtoupper($m[2]),
                                'text' => trim($m[3]),
                                'file' => $relativePath,
                                'line' => $lineNum + 1
                            ];
                        }
                    }
                }

                return ['success' => true, 'todos' => $todos, 'count' => count($todos)];
            })(),

            // Burndown Data
            'burndown_data' => (function() use ($data) {
                // Calculate burndown based on card history
                $stats = [
                    'total_cards' => count(array_filter($data['cards'], fn($c) => !$c['archived'])),
                    'completed' => 0,
                    'in_progress' => 0,
                    'todo' => 0,
                    'by_day' => []
                ];

                $doneColIds = [];
                foreach ($data['columns'] as $col) {
                    if (stripos($col['name'], 'done') !== false || stripos($col['name'], 'completat') !== false) {
                        $doneColIds[] = $col['id'];
                    }
                }
                $progressColIds = [];
                foreach ($data['columns'] as $col) {
                    if (stripos($col['name'], 'progress') !== false || stripos($col['name'], 'corso') !== false) {
                        $progressColIds[] = $col['id'];
                    }
                }

                foreach ($data['cards'] as $c) {
                    if ($c['archived']) continue;
                    if (in_array($c['column_id'], $doneColIds)) $stats['completed']++;
                    elseif (in_array($c['column_id'], $progressColIds)) $stats['in_progress']++;
                    else $stats['todo']++;
                }

                // Calculate velocity (cards completed per day based on archived)
                $archivedByDay = [];
                foreach ($data['cards'] as $c) {
                    if ($c['archived'] && !empty($c['archived_at'])) {
                        $day = substr($c['archived_at'], 0, 10);
                        $archivedByDay[$day] = ($archivedByDay[$day] ?? 0) + 1;
                    }
                }
                ksort($archivedByDay);
                $stats['velocity'] = $archivedByDay;
                $stats['avg_velocity'] = count($archivedByDay) > 0 ? round(array_sum($archivedByDay) / count($archivedByDay), 1) : 0;

                return ['success' => true, 'stats' => $stats];
            })(),

            // GitHub API - List Issues
            'github_issues' => (function() use ($data) {
                $token = ykanGithubToken($data);
                $repo = $data['config']['github_repo'] ?? '';
                if (empty($token) || empty($repo)) {
                    return ['success' => false, 'error' => 'GitHub not configured. Go to Settings.'];
                }

                $ch = curl_init("https://api.github.com/repos/{$repo}/issues?state=all&per_page=50");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$token}",
                        "Accept: application/vnd.github+json",
                        "User-Agent: _Ykan-Kanban",
                        "X-GitHub-Api-Version: 2022-11-28"
                    ],
                    CURLOPT_TIMEOUT => 15
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    $err = json_decode($response, true);
                    return ['success' => false, 'error' => 'GitHub API: ' . ($err['message'] ?? "HTTP {$httpCode}")];
                }

                $issues = json_decode($response, true);
                // Filter out pull requests (they appear in issues endpoint too)
                $issues = array_filter($issues, fn($i) => !isset($i['pull_request']));
                $issues = array_values($issues);

                return ['success' => true, 'issues' => $issues];
            })(),

            // GitHub API - List Pull Requests
            'github_prs' => (function() use ($data) {
                $token = ykanGithubToken($data);
                $repo = $data['config']['github_repo'] ?? '';
                if (empty($token) || empty($repo)) {
                    return ['success' => false, 'error' => 'GitHub not configured'];
                }

                $ch = curl_init("https://api.github.com/repos/{$repo}/pulls?state=all&per_page=30");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$token}",
                        "Accept: application/vnd.github+json",
                        "User-Agent: _Ykan-Kanban",
                        "X-GitHub-Api-Version: 2022-11-28"
                    ],
                    CURLOPT_TIMEOUT => 15
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    return ['success' => false, 'error' => 'GitHub API error: ' . $httpCode];
                }

                return ['success' => true, 'prs' => json_decode($response, true)];
            })(),

            // GitHub API - List Commits
            'github_commits' => (function() use ($data) {
                $token = ykanGithubToken($data);
                $repo = $data['config']['github_repo'] ?? '';
                if (empty($token) || empty($repo)) {
                    return ['success' => false, 'error' => 'GitHub not configured'];
                }

                $ch = curl_init("https://api.github.com/repos/{$repo}/commits?per_page=20");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$token}",
                        "Accept: application/vnd.github+json",
                        "User-Agent: _Ykan-Kanban",
                        "X-GitHub-Api-Version: 2022-11-28"
                    ],
                    CURLOPT_TIMEOUT => 15
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    return ['success' => false, 'error' => 'GitHub API error: ' . $httpCode];
                }

                return ['success' => true, 'commits' => json_decode($response, true)];
            })(),

            // GitHub API - Create Issue from Card
            'github_create_issue' => (function() use ($data, $input) {
                $token = ykanGithubToken($data);
                $repo = $data['config']['github_repo'] ?? '';
                if (empty($token) || empty($repo)) {
                    return ['success' => false, 'error' => 'GitHub not configured'];
                }

                $title = $input['title'] ?? '';
                $body = $input['body'] ?? '';
                $labels = $input['labels'] ?? [];

                if (empty($title)) {
                    return ['success' => false, 'error' => 'Title required'];
                }

                $payload = json_encode([
                    'title' => $title,
                    'body' => $body . "\n\n---\n_Created from _Ykan Kanban_",
                    'labels' => $labels
                ]);

                $ch = curl_init("https://api.github.com/repos/{$repo}/issues");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$token}",
                        "Accept: application/vnd.github+json",
                        "User-Agent: _Ykan-Kanban",
                        "X-GitHub-Api-Version: 2022-11-28",
                        "Content-Type: application/json"
                    ],
                    CURLOPT_TIMEOUT => 15
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 201) {
                    $err = json_decode($response, true);
                    return ['success' => false, 'error' => 'Error creating issue: ' . ($err['message'] ?? $httpCode)];
                }

                return ['success' => true, 'issue' => json_decode($response, true)];
            })(),

            // GitHub API - Close Issue
            'github_close_issue' => (function() use ($data, $input) {
                $token = ykanGithubToken($data);
                $repo = $data['config']['github_repo'] ?? '';
                if (empty($token) || empty($repo)) {
                    return ['success' => false, 'error' => 'GitHub not configured'];
                }

                $issueNumber = $input['issue_number'] ?? 0;
                if (!$issueNumber) {
                    return ['success' => false, 'error' => 'Issue number required'];
                }

                $payload = json_encode(['state' => 'closed']);

                $ch = curl_init("https://api.github.com/repos/{$repo}/issues/{$issueNumber}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => 'PATCH',
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$token}",
                        "Accept: application/vnd.github+json",
                        "User-Agent: _Ykan-Kanban",
                        "X-GitHub-Api-Version: 2022-11-28",
                        "Content-Type: application/json"
                    ],
                    CURLOPT_TIMEOUT => 15
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    return ['success' => false, 'error' => 'Error closing issue: ' . $httpCode];
                }

                return ['success' => true, 'issue' => json_decode($response, true)];
            })(),

            // GitHub API - Repo Info
            'github_repo_info' => (function() use ($data) {
                $token = ykanGithubToken($data);
                $repo = $data['config']['github_repo'] ?? '';
                if (empty($token) || empty($repo)) {
                    return ['success' => false, 'error' => 'GitHub not configured'];
                }

                $ch = curl_init("https://api.github.com/repos/{$repo}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        "Authorization: Bearer {$token}",
                        "Accept: application/vnd.github+json",
                        "User-Agent: _Ykan-Kanban",
                        "X-GitHub-Api-Version: 2022-11-28"
                    ],
                    CURLOPT_TIMEOUT => 15
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    return ['success' => false, 'error' => 'GitHub API error: ' . $httpCode];
                }

                return ['success' => true, 'repo' => json_decode($response, true)];
            })(),

            // Status of several GitHub repos at once (last push, visibility, open issues/PRs).
            // Uses the configured token when present; public repos also work without it.
            'github_repo_status' => (function() use ($data, $input) {
                $token = ykanGithubToken($data);
                $out = [];
                foreach (array_slice((array)($input['repos'] ?? []), 0, 30) as $repo) {
                    $repo = (string)$repo;
                    if (!preg_match('/^[\w.-]+\/[\w.-]+$/', $repo)) continue;
                    $headers = ['Accept: application/vnd.github+json', 'User-Agent: _Ykan-Kanban', 'X-GitHub-Api-Version: 2022-11-28'];
                    if ($token !== '') $headers[] = "Authorization: Bearer {$token}";
                    $ch = curl_init("https://api.github.com/repos/{$repo}");
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
                    $response = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($code === 200) {
                        $r = json_decode($response, true);
                        $out[$repo] = ['ok' => true, 'private' => !empty($r['private']), 'pushed_at' => $r['pushed_at'] ?? null,
                            'default_branch' => $r['default_branch'] ?? 'main', 'open_issues' => $r['open_issues_count'] ?? 0,
                            'url' => $r['html_url'] ?? '', 'archived' => !empty($r['archived'])];
                    } else {
                        $out[$repo] = ['ok' => false, 'code' => $code,
                            'error' => $code === 0 ? 'GitHub non raggiungibile dal server'
                                : ($code === 404 ? ($token === '' ? 'non trovato (privato? imposta il token in Settings)' : 'non trovato o token senza accesso') : 'errore ' . $code)];
                    }
                }
                return ['success' => true, 'repos' => (object)$out, 'has_token' => $token !== ''];
            })(),

            // === CLAUDE AGENT EXECUTOR ===
            'claude_execute' => (function() use ($data, $input) {
                $apiKey = ykanEnv('ANTHROPIC_KEY');
                if ($apiKey === '') {
                    return ['success' => false, 'error' => 'ANTHROPIC_KEY non configurata in .env'];
                }

                $cardId = $input['card_id'] ?? '';
                $card = null;
                foreach ($data['cards'] as $c) {
                    if ($c['id'] === $cardId) { $card = $c; break; }
                }
                if (!$card) return ['success' => false, 'error' => 'Card non trovata'];

                $lane = null;
                foreach ($data['swimlanes'] as $l) {
                    if ($l['id'] === $card['swimlane_id']) { $lane = $l; break; }
                }
                $projectName = $lane ? $lane['name'] : 'Default';

                $col = null;
                foreach ($data['columns'] as $c) {
                    if ($c['id'] === $card['column_id']) { $col = $c; break; }
                }
                $label = null;
                foreach ($data['labels'] as $l) {
                    if ($l['id'] === ($card['label_id'] ?? '')) { $label = $l; break; }
                }

                $hasFolder = $lane && trim($lane['path'] ?? '') !== '';

                $prompt = "Sei un agente autonomo che esegue task di sviluppo software.\n\n";
                $prompt .= "## Task\n";
                $prompt .= "**Titolo:** {$card['title']}\n";
                $prompt .= "**Priorità:** {$card['priority']}\n";
                if ($label) $prompt .= "**Label:** {$label['name']}\n";
                if ($col) $prompt .= "**Colonna:** {$col['name']}\n";
                if ($card['description']) $prompt .= "**Descrizione:**\n{$card['description']}\n";
                $prompt .= "\n**Progetto:** {$projectName}\n";
                if ($lane && $lane['url']) $prompt .= "**URL:** {$lane['url']}\n";
                if ($card['files'] && count($card['files']) > 0) {
                    $prompt .= "\n**File coinvolti:**\n";
                    foreach ($card['files'] as $f) $prompt .= "- {$f}\n";
                }

                if (!empty($lane['doc_files'])) {
                    $prompt .= "\n**File di documentazione del progetto (leggili per contesto):**\n";
                    foreach ($lane['doc_files'] as $f) $prompt .= "- {$f}\n";
                }

                $prompt .= "\n## Istruzioni\n";
                $prompt .= "1. Leggi i file coinvolti e la documentazione per capire il contesto\n";
                $prompt .= "2. Implementa le modifiche richieste dal task\n";
                $prompt .= "3. Quando hai finito, usa complete_task per segnare il task come completato\n";
                $prompt .= "4. Rispondi con un breve riepilogo di cosa hai fatto\n";
                $prompt .= "\nSe il task non è chiaro o ci sono ambiguità, fai del tuo meglio per interpretarlo.\n";

                $tools = [];
                if ($hasFolder) {
                    $tools[] = ['name' => 'list_files', 'description' => "Elenca file/cartelle nel progetto {$projectName}.", 'input_schema' => ['type' => 'object', 'properties' => ['subpath' => ['type' => 'string', 'description' => 'Sotto-cartella (opzionale).']]]];
                    $tools[] = ['name' => 'read_file', 'description' => "Leggi un file di testo dal progetto {$projectName}.", 'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'description' => 'Path relativo alla root del progetto.']], 'required' => ['path']]];
                    $tools[] = ['name' => 'edit_file', 'description' => "Modifica un file con find-and-replace. Crea un backup prima.", 'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'description' => 'Path relativo.'], 'search' => ['type' => 'string', 'description' => 'Testo esatto da trovare.'], 'replace' => ['type' => 'string', 'description' => 'Testo sostitutivo.'], 'replace_all' => ['type' => 'boolean', 'description' => 'Sostituisci tutte le occorrenze (default false).']], 'required' => ['path', 'search', 'replace']]];
                    $tools[] = ['name' => 'write_file', 'description' => "Crea o sovrascrivi un file. Crea un backup prima.", 'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'description' => 'Path relativo.'], 'content' => ['type' => 'string', 'description' => 'Contenuto completo del file.']], 'required' => ['path', 'content']]];
                    $tools[] = ['name' => 'search_files', 'description' => "Cerca testo nei file del progetto.", 'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Testo da cercare (case-insensitive).'], 'subpath' => ['type' => 'string', 'description' => 'Sotto-cartella (opzionale).']], 'required' => ['query']]];
                }
                $tools[] = ['name' => 'complete_task', 'description' => 'Segna il task come completato (archiviato).', 'input_schema' => ['type' => 'object', 'properties' => (object)[]]];

                $system = "Sei un agente di sviluppo software autonomo. Il tuo compito è eseguire il task descritto dall'utente usando i tool a disposizione. "
                    . "DEVI usare i tool per leggere i file, capire il codice, fare le modifiche necessarie, e poi chiamare complete_task quando hai finito. "
                    . "Non rispondere mai solo con testo — agisci sempre tramite i tool. "
                    . "Inizia leggendo i file rilevanti con list_files e read_file per capire il contesto.";

                $maxTurns = 25;
                $messages = [['role' => 'user', 'content' => $prompt]];
                $log = [];
                $completed = false;

                set_time_limit(300);

                for ($turn = 0; $turn < $maxTurns; $turn++) {
                    $payload = [
                        'model' => 'claude-sonnet-4-20250514',
                        'max_tokens' => 8192,
                        'system' => $system,
                        'tools' => $tools,
                        'messages' => $messages,
                    ];

                    $ch = curl_init('https://api.anthropic.com/v1/messages');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'x-api-key: ' . $apiKey,
                            'anthropic-version: 2023-06-01',
                        ],
                        CURLOPT_POSTFIELDS => json_encode($payload),
                        CURLOPT_TIMEOUT => 120,
                    ]);

                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode !== 200) {
                        $err = json_decode($response, true);
                        $log[] = ['error' => 'API Anthropic: HTTP ' . $httpCode . ' — ' . ($err['error']['message'] ?? $response)];
                        break;
                    }

                    $resp = json_decode($response, true);
                    if (!isset($resp['content'])) {
                        $log[] = ['error' => 'Risposta API inattesa: ' . substr($response, 0, 500)];
                        break;
                    }
                    $content = $resp['content'];
                    $stopReason = $resp['stop_reason'] ?? 'end_turn';

                    $messages[] = ['role' => 'assistant', 'content' => $content];

                    foreach ($content as $block) {
                        if (($block['type'] ?? '') === 'text') {
                            $log[] = ['text' => $block['text']];
                        }
                    }

                    if ($stopReason !== 'tool_use') {
                        $log[] = ['debug' => "stop_reason={$stopReason}, turn={$turn}"];
                        break;
                    }

                    $toolResults = [];
                    foreach ($content as $block) {
                        if ($block['type'] !== 'tool_use') continue;
                        $toolName = $block['name'];
                        $toolInput = $block['input'] ?? [];
                        $toolId = $block['id'];

                        $log[] = ['tool' => $toolName, 'input' => $toolInput];

                        try {
                            $result = match($toolName) {
                                'list_files' => (function() use ($data, $lane, $toolInput) {
                                    $base = ykanProjectBase($lane);
                                    $dir = ykanSafePath($base, (string)($toolInput['subpath'] ?? ''));
                                    if (!is_dir($dir)) throw new RuntimeException('Non è una cartella.');
                                    $items = @scandir($dir) ?: [];
                                    $out = [];
                                    foreach ($items as $it) {
                                        if ($it === '.' || $it === '..' || $it === '.ykan_backups') continue;
                                        $full = $dir . '/' . $it;
                                        $out[] = (is_dir($full) ? '[dir]  ' : '[file] ') . $it . (is_file($full) ? ' (' . filesize($full) . ' B)' : '');
                                    }
                                    sort($out);
                                    return implode("\n", $out) ?: '(cartella vuota)';
                                })(),
                                'read_file' => (function() use ($lane, $toolInput) {
                                    $base = ykanProjectBase($lane);
                                    $file = ykanSafePath($base, (string)($toolInput['path'] ?? ''));
                                    if (!is_file($file)) throw new RuntimeException('File non trovato: ' . ($toolInput['path'] ?? ''));
                                    if (filesize($file) > 500000) throw new RuntimeException('File troppo grande (>500KB).');
                                    return (string)file_get_contents($file);
                                })(),
                                'edit_file' => (function() use ($lane, $toolInput) {
                                    $base = ykanProjectBase($lane);
                                    $file = ykanSafePath($base, (string)($toolInput['path'] ?? ''));
                                    if (!is_file($file)) throw new RuntimeException('File non trovato.');
                                    $search = (string)($toolInput['search'] ?? '');
                                    $replace = (string)($toolInput['replace'] ?? '');
                                    if ($search === '') throw new RuntimeException('search vuoto.');
                                    $content = (string)file_get_contents($file);
                                    $count = substr_count($content, $search);
                                    if ($count === 0) throw new RuntimeException('Testo non trovato nel file.');
                                    if ($count > 1 && empty($toolInput['replace_all'])) {
                                        throw new RuntimeException("Testo trovato {$count} volte. Usa replace_all=true o rendi unico.");
                                    }
                                    $backupDir = $base . '/.ykan_backups/' . dirname(ltrim(substr($file, strlen($base)), '/'));
                                    @mkdir($backupDir, 0775, true);
                                    @copy($file, $base . '/.ykan_backups/' . ltrim(substr($file, strlen($base)), '/') . '.' . date('Ymd-His'));
                                    $new = empty($toolInput['replace_all'])
                                        ? preg_replace('/' . preg_quote($search, '/') . '/', str_replace('$', '\\$', $replace), $content, 1)
                                        : str_replace($search, $replace, $content);
                                    file_put_contents($file, $new);
                                    $n = empty($toolInput['replace_all']) ? 1 : $count;
                                    return "Modificato {$toolInput['path']} ({$n} sostituzione/i). Backup creato.";
                                })(),
                                'write_file' => (function() use ($lane, $toolInput) {
                                    $base = ykanProjectBase($lane);
                                    $file = ykanSafePath($base, (string)($toolInput['path'] ?? ''));
                                    $content = (string)($toolInput['content'] ?? '');
                                    if (strlen($content) > 2000000) throw new RuntimeException('Contenuto troppo grande (>2MB).');
                                    if (is_file($file)) {
                                        @mkdir(dirname($base . '/.ykan_backups/' . ltrim(substr($file, strlen($base)), '/')), 0775, true);
                                        @copy($file, $base . '/.ykan_backups/' . ltrim(substr($file, strlen($base)), '/') . '.' . date('Ymd-His'));
                                    }
                                    @mkdir(dirname($file), 0775, true);
                                    file_put_contents($file, $content);
                                    return (is_file($file) ? 'Sovrascritto' : 'Creato') . " {$toolInput['path']} (" . strlen($content) . ' bytes).';
                                })(),
                                'search_files' => (function() use ($lane, $toolInput) {
                                    $base = ykanProjectBase($lane);
                                    $start = ykanSafePath($base, (string)($toolInput['subpath'] ?? ''));
                                    $query = (string)($toolInput['query'] ?? '');
                                    if ($query === '') throw new RuntimeException('query vuota.');
                                    $skipDirs = ['.git', 'node_modules', 'vendor', '.ykan_backups', 'cache', 'tmp'];
                                    $skipExt = ['png','jpg','jpeg','gif','webp','ico','pdf','zip','gz','mp4','mp3','woff','woff2','ttf'];
                                    $hits = [];
                                    $it = new RecursiveIteratorIterator(
                                        new RecursiveCallbackFilterIterator(
                                            new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS),
                                            function ($cur) use ($skipDirs) { return !($cur->isDir() && in_array($cur->getFilename(), $skipDirs, true)); }
                                        )
                                    );
                                    foreach ($it as $f) {
                                        if (count($hits) >= 50) break;
                                        if (!$f->isFile() || $f->getSize() > 500000) continue;
                                        if (in_array(strtolower($f->getExtension()), $skipExt, true)) continue;
                                        $rel = ltrim(substr($f->getPathname(), strlen($base)), '/');
                                        $lines = @file($f->getPathname(), FILE_IGNORE_NEW_LINES);
                                        if (!$lines) continue;
                                        foreach ($lines as $i => $line) {
                                            if (stripos($line, $query) !== false) {
                                                $hits[] = $rel . ':' . ($i + 1) . ': ' . trim(substr($line, 0, 200));
                                                if (count($hits) >= 50) break;
                                            }
                                        }
                                    }
                                    return $hits ? implode("\n", $hits) : "Nessun risultato per '{$query}'.";
                                })(),
                                'complete_task' => (function() use (&$completed, $card) {
                                    $completed = true;
                                    return "Task '{$card['title']}' segnato come completato. Verrà archiviato al termine.";
                                })(),
                                default => throw new RuntimeException("Tool sconosciuto: {$toolName}")
                            };
                            $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $toolId, 'content' => $result];
                            $log[] = ['tool_result' => substr($result, 0, 500)];
                        } catch (Throwable $e) {
                            $errMsg = 'Errore: ' . $e->getMessage();
                            $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $toolId, 'content' => $errMsg, 'is_error' => true];
                            $log[] = ['tool_error' => $errMsg];
                        }
                    }

                    $messages[] = ['role' => 'user', 'content' => $toolResults];
                }

                $toolCalls = count(array_filter($log, fn($e) => isset($e['tool'])));
                $edits = count(array_filter($log, fn($e) => isset($e['tool']) && in_array($e['tool'], ['edit_file', 'write_file'])));
                $summary = '';
                foreach ($log as $entry) {
                    if (isset($entry['text'])) $summary = $entry['text'];
                }

                $execution = [
                    'date' => date('Y-m-d H:i:s'),
                    'status' => $completed ? 'completed' : 'partial',
                    'tool_calls' => $toolCalls,
                    'edits' => $edits,
                    'summary' => mb_substr($summary, 0, 500),
                    'log' => $log,
                ];

                $freshData = loadData();
                foreach ($freshData['cards'] as &$c) {
                    if ($c['id'] === $cardId) {
                        if (!isset($c['claude_runs'])) $c['claude_runs'] = [];
                        $c['claude_runs'][] = $execution;
                        if ($completed) {
                            $c['archived'] = true;
                            $c['archived_at'] = date('Y-m-d H:i:s');
                        }
                        break;
                    }
                }
                saveData($freshData);

                return [
                    'success' => true,
                    'completed' => $completed,
                    'summary' => $summary,
                    'log' => $log,
                    'turns' => $toolCalls,
                    'edits' => $edits,
                ];
            })(),

            // Check if Anthropic key is configured
            'claude_status' => (function() {
                $key = ykanEnv('ANTHROPIC_KEY');
                return ['success' => true, 'configured' => $key !== ''];
            })(),

            // Remember which Claude Code session was started for a card (PLAY passes a fixed --session-id).
            'link_session' => (function() use (&$data, $input) {
                $sid = (string)($input['session_id'] ?? '');
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sid))
                    return ['success' => false, 'error' => 'session_id non valido'];
                foreach ($data['cards'] as &$card) {
                    if ($card['id'] !== ($input['card_id'] ?? '')) continue;
                    $card['sessions'] = $card['sessions'] ?? [];
                    foreach ($card['sessions'] as $s) if (($s['id'] ?? '') === $sid) return ['success' => true];
                    $card['sessions'][] = ['id' => $sid, 'at' => date('Y-m-d H:i:s'), 'role' => $input['role'] ?? 'played'];
                    $card['updated_at'] = date('Y-m-d H:i:s');
                    saveData($data);
                    return ['success' => true];
                }
                return ['success' => false, 'error' => 'card non trovata'];
            })(),

            // Mark a Claude Code session as concluded (or reopen it with status = null).
            'session_state' => (function() use (&$data, $input) {
                $sid = (string)($input['session_id'] ?? '');
                if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/', $sid)) return ['success' => false, 'error' => 'session_id non valido'];
                $states = $data['session_states'] ?? [];
                if (empty($input['status'])) {
                    unset($states[$sid]);
                } else {
                    $states[$sid] = [
                        'status' => 'concluded',
                        'how' => in_array($input['how'] ?? '', ['archived', 'split'], true) ? $input['how'] : 'archived',
                        'at' => date('Y-m-d H:i:s'),
                        'tasks_created' => array_values(array_map('intval', (array)($input['tasks_created'] ?? []))),
                        'summary' => mb_substr((string)($input['summary'] ?? ''), 0, 1500),
                    ];
                }
                $data['session_states'] = $states ?: new stdClass();
                saveData($data);
                return ['success' => true];
            })(),

            // === LOOSE ENDS ("Cose non chiuse") =============================
            // Rule-based scan of the board: what was left open, and what happened lately.
            'loose_ends' => (function() use ($data, $input) {
                $staleDays = max(1, (int)($input['stale_days'] ?? 3));
                $now = time();
                $lanes = []; foreach ($data['swimlanes'] as $l) $lanes[$l['id']] = $l['name'];
                $cols = [];  foreach ($data['columns'] as $c) $cols[$c['id']] = $c['name'];
                $isDone = fn($c) => !empty($c['archived']) || preg_match('/done|fatto|chius|completat/i', $cols[$c['column_id']] ?? '');
                $firstCol = $data['columns'][0]['id'] ?? null;
                $last = function($c) {
                    $ts = array_filter([$c['updated_at'] ?? null, $c['moved_at'] ?? null, $c['archived_at'] ?? null, $c['created_at'] ?? null]);
                    return $ts ? max(array_map('strtotime', $ts)) : 0;
                };
                $bySeq = []; foreach ($data['cards'] as $c) if (isset($c['seq'])) $bySeq[$c['seq']] = $c;
                $items = [];
                $add = function($kind, $sev, $c, $detail, $ts = null) use (&$items, $lanes, $cols, $last) {
                    $items[] = ['kind' => $kind, 'severity' => $sev, 'id' => $c['id'], 'seq' => $c['seq'] ?? null,
                        'title' => $c['title'], 'project' => $lanes[$c['swimlane_id']] ?? '?',
                        'column' => $cols[$c['column_id']] ?? '?', 'detail' => $detail,
                        'since' => date('Y-m-d H:i', $ts ?? $last($c)), 'priority' => $c['priority'] ?? 'medium'];
                };
                $epicOpen = [];
                foreach ($data['cards'] as $c) {
                    $done = $isDone($c);
                    $age = (int)floor(($now - $last($c)) / 86400);
                    $col = $cols[$c['column_id']] ?? '';
                    if (!empty($c['epic']) && ($c['swimlane_id'] ?? '')) {
                        $k = $c['swimlane_id'] . '|' . $c['epic'];
                        $epicOpen[$k] ??= ['total' => 0, 'done' => 0, 'last' => 0, 'card' => $c];
                        $epicOpen[$k]['total']++; if ($done) $epicOpen[$k]['done']++;
                        $epicOpen[$k]['last'] = max($epicOpen[$k]['last'], $last($c));
                    }
                    if (!empty($c['archived'])) continue;
                    if (!$done && preg_match('/progress|doing|corso|lavor/i', $col) && $age >= $staleDays)
                        $add('stale_doing', 'high', $c, "In \"$col\" da $age giorni senza attività");
                    if (!$done && preg_match('/claude do/i', $col) && $age >= 1)
                        $add('claude_do_waiting', 'medium', $c, "In coda in \"$col\" da $age giorni");
                    if (!$done && ($c['priority'] ?? '') === 'high' && $c['column_id'] === $firstCol && $age >= 7)
                        $add('high_not_started', 'medium', $c, "Priorità alta mai iniziata ($age giorni in \"$col\")");
                    if (!$done && !empty($c['due_date']) && strtotime($c['due_date']) < strtotime('today'))
                        $add('overdue', 'high', $c, 'Scaduta il ' . $c['due_date'], strtotime($c['due_date']));
                    if (!$done && !empty($c['next_check']) && strtotime($c['next_check']) <= $now)
                        $add('check_due', 'medium', $c, 'Controllo previsto il ' . $c['next_check'], strtotime($c['next_check']));
                    $acc = $c['acceptance'] ?? [];
                    if ($acc) {
                        $accDone = count(array_filter($acc, fn($a) => !empty($a['done'])));
                        if ($accDone < count($acc) && ($done || $accDone > 0))
                            $add('acceptance_partial', $done ? 'high' : 'medium', $c,
                                ($done ? 'Chiusa ma ' : '') . "criteri di accettazione $accDone/" . count($acc));
                    }
                    foreach (($c['depends_on'] ?? []) as $dep) {
                        $d = $bySeq[$dep] ?? null;
                        if (!$done && $d && !$isDone($d) && preg_match('/progress|doing|corso|lavor/i', $col))
                            $add('blocked', 'high', $c, "In corso ma dipende da #$dep ancora aperta");
                    }
                }
                foreach ($epicOpen as $e) {
                    if ($e['done'] > 0 && $e['done'] < $e['total'])
                        $add('epic_partial', 'medium', $e['card'], "Epic \"{$e['card']['epic']}\": {$e['done']}/{$e['total']} task fatti", $e['last']);
                }
                usort($items, fn($a, $b) => [['high' => 0, 'medium' => 1, 'low' => 2][$a['severity']], $a['since']] <=> [['high' => 0, 'medium' => 1, 'low' => 2][$b['severity']], $b['since']]);

                $recent = [];
                $cut = strtotime('-' . max(1, (int)($input['recent_days'] ?? 7)) . ' days');
                foreach ($data['cards'] as $c) {
                    $ev = [];
                    if (!empty($c['created_at'])) $ev[] = ['created', $c['created_at']];
                    if (!empty($c['moved_at'])) $ev[] = ['moved', $c['moved_at']];
                    if (!empty($c['archived_at'])) $ev[] = ['done', $c['archived_at']];
                    foreach ($ev as [$type, $when]) {
                        if (strtotime($when) < $cut) continue;
                        $recent[] = ['type' => $type, 'when' => $when, 'seq' => $c['seq'] ?? null, 'title' => $c['title'],
                            'project' => $lanes[$c['swimlane_id']] ?? '?', 'column' => $cols[$c['column_id']] ?? '?'];
                    }
                }
                usort($recent, fn($a, $b) => strcmp($b['when'], $a['when']));
                return ['success' => true, 'items' => $items, 'recent' => $recent, 'stale_days' => $staleDays];
            })(),

            // === PM LAYER ENDPOINTS =========================================
            // List PRDs and epics for a project, with live board progress.
            'pm_list' => (function() use ($data, $input) {
                $project = trim((string)($input['project'] ?? ''));
                if ($project === '') return ['success' => false, 'error' => 'project mancante'];
                $slug = ykanPmSlug($project);
                $dir = ykanPmBase($data) . '/' . $slug;
                $scan = function(string $sub) use ($dir) {
                    $out = [];
                    foreach (@glob($dir . '/' . $sub . '/*.md') ?: [] as $f) {
                        $md = (string)file_get_contents($f);
                        $s = basename($f, '.md');
                        $out[] = [
                            'slug' => $s,
                            'title' => ykanPmTitle($md, $s),
                            'status' => ykanPmFront($md, 'stato'),
                            'mtime' => date('Y-m-d H:i', (int)@filemtime($f)),
                        ];
                    }
                    usort($out, fn($a, $b) => strcmp($b['mtime'], $a['mtime']));
                    return $out;
                };
                // board progress per epic (cards matched by card.epic == epic slug)
                $lane = ykanLaneByName($data, $project);
                $doneCols = [];
                foreach ($data['columns'] as $c) {
                    if (preg_match('/done|fatto|chius|completat/i', $c['name'])) $doneCols[] = $c['id'];
                }
                $epics = $scan('epics');
                foreach ($epics as &$e) {
                    $tot = 0; $done = 0;
                    foreach ($data['cards'] as $c) {
                        if (($c['epic'] ?? '') !== $e['slug']) continue;
                        if ($lane && $c['swimlane_id'] !== $lane['id']) continue;
                        $tot++;
                        if (!empty($c['archived']) || in_array($c['column_id'], $doneCols, true)) $done++;
                    }
                    $e['cards_total'] = $tot;
                    $e['cards_done'] = $done;
                }
                unset($e);
                return ['success' => true, 'project' => $project, 'slug' => $slug,
                    'prds' => $scan('prd'), 'epics' => $epics];
            })(),

            // Raw markdown of one PRD or epic.
            'pm_read' => (function() use ($data, $input) {
                $project = trim((string)($input['project'] ?? ''));
                $kind = ($input['kind'] ?? '') === 'epic' ? 'epics' : 'prd';
                $slug = ykanPmSlug((string)($input['slug'] ?? ''));
                if ($project === '' || $slug === '') return ['success' => false, 'error' => 'parametri mancanti'];
                $file = ykanPmBase($data) . '/' . ykanPmSlug($project) . '/' . $kind . '/' . $slug . '.md';
                if (!is_file($file)) return ['success' => false, 'error' => 'Documento non trovato'];
                return ['success' => true, 'content' => mb_substr((string)file_get_contents($file), 0, 120000)];
            })(),

            // Idea + answers → PRD markdown, saved under prd/.
            'pm_prd' => (function() use ($data, $input) {
                $project = trim((string)($input['project'] ?? ''));
                $idea = trim((string)($input['idea'] ?? ''));
                $context = trim((string)($input['context'] ?? ''));
                if ($project === '' || $idea === '') return ['success' => false, 'error' => 'project e idea sono obbligatori'];
                $slug = ykanPmSlug($input['slug'] ?? $idea);
                $today = date('Y-m-d');

                // Quick-capture: skeleton PRD with no LLM call.
                if (!empty($input['stub'])) {
                    $md = "---\nprd: {$slug}\nprogetto: {$project}\npriorita: medium\nstato: draft\ncreato: {$today}\n---\n\n"
                        . "# PRD: {$idea}\n\n## Problema\n" . ($context !== '' ? $context : "_(da compilare)_")
                        . "\n\n## Utenti e scenari\n_(da compilare)_\n\n## Requisiti funzionali\n- RF1 …\n\n"
                        . "## Criteri di successo\n_(da compilare)_\n\n## Vincoli\n_(da compilare)_\n\n## Fuori scope\n_(da compilare)_\n";
                    $dir = ykanPmBase($data) . '/' . ykanPmSlug($project) . '/prd';
                    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return ['success' => false, 'error' => 'mkdir prd/ fallita'];
                    if (file_put_contents($dir . '/' . $slug . '.md', $md) === false) return ['success' => false, 'error' => 'scrittura PRD fallita'];
                    return ['success' => true, 'slug' => $slug, 'content' => $md, 'stub' => true];
                }

                $system = "Sei un product manager esperto. Scrivi PRD asciutti, concreti, in italiano. "
                    . "Rispondi SOLO con il markdown del PRD, senza preamboli, senza ```.";
                $prompt = "Progetto: {$project}\nIdea: {$idea}\n\n"
                    . ($context !== '' ? "Contesto/risposte dell'utente:\n{$context}\n\n" : "")
                    . "Produci un PRD con questo scheletro esatto (compila ogni sezione, non lasciare placeholder):\n\n"
                    . "---\nprd: {$slug}\nprogetto: {$project}\npriorita: medium\nstato: draft\ncreato: {$today}\n---\n\n"
                    . "# PRD: <titolo>\n\n## Problema\n\n## Utenti e scenari\n\n## Requisiti funzionali\n- RF1 …\n\n## Criteri di successo\n\n## Vincoli\n\n## Fuori scope\n";
                $res = ykanPmAgent($system, $prompt, [], null, 3);
                if (!$res['ok']) return ['success' => false, 'error' => $res['error']];
                $md = trim($res['text']);
                if ($md === '') return ['success' => false, 'error' => 'Il modello non ha prodotto testo'];
                $dir = ykanPmBase($data) . '/' . ykanPmSlug($project) . '/prd';
                if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return ['success' => false, 'error' => 'mkdir prd/ fallita'];
                if (file_put_contents($dir . '/' . $slug . '.md', $md) === false) return ['success' => false, 'error' => 'scrittura PRD fallita'];
                return ['success' => true, 'slug' => $slug, 'content' => $md];
            })(),

            // PRD → technical epic markdown, saved under epics/.
            'pm_epic' => (function() use ($data, $input) {
                $project = trim((string)($input['project'] ?? ''));
                $slug = ykanPmSlug((string)($input['slug'] ?? ''));
                if ($project === '' || $slug === '') return ['success' => false, 'error' => 'project e slug obbligatori'];
                $pdir = ykanPmBase($data) . '/' . ykanPmSlug($project);
                $prdFile = $pdir . '/prd/' . $slug . '.md';
                if (!is_file($prdFile)) return ['success' => false, 'error' => 'PRD non trovato: crealo prima'];
                $prd = (string)file_get_contents($prdFile);

                $ctx = '';
                $lane = ykanLaneByName($data, $project);
                if ($lane && !empty($lane['doc_files'])) {
                    try {
                        $base = ykanProjectBase($lane);
                        foreach (array_slice($lane['doc_files'], 0, 4) as $df) {
                            $p = ykanSafePath($base, is_array($df) ? ($df['path'] ?? '') : (string)$df);
                            if (is_file($p) && filesize($p) < 40000) {
                                $ctx .= "\n\n### " . basename($p) . "\n" . file_get_contents($p);
                            }
                        }
                    } catch (Throwable $e) { /* project not linked — skip context */ }
                }
                $today = date('Y-m-d');
                $system = "Sei un tech lead. Scrivi epic tecnici concreti in italiano, ancorati al codice reale quando c'è contesto. "
                    . "Rispondi SOLO con il markdown, senza ``` e senza preamboli.";
                $prompt = "PRD:\n{$prd}\n"
                    . ($ctx !== '' ? "\nContesto del progetto (documentazione):{$ctx}\n" : "")
                    . "\nProduci l'epic con questo scheletro (max 10 task nell'anteprima, stima S/M/L = sforzo):\n\n"
                    . "---\nepic: {$slug}\nprogetto: {$project}\nprd: docs/pm/" . ykanPmSlug($project) . "/prd/{$slug}.md\nstato: draft\ncreato: {$today}\n---\n\n"
                    . "# Epic: <titolo>\n\n## Decisioni di architettura\n\n## Approccio tecnico\n\n## Task (anteprima)\n1. <titolo> — dipende da: — / parallelo: sì|no / stima: M\n";
                $res = ykanPmAgent($system, $prompt, [], null, 3);
                if (!$res['ok']) return ['success' => false, 'error' => $res['error']];
                $md = trim($res['text']);
                if ($md === '') return ['success' => false, 'error' => 'Il modello non ha prodotto testo'];
                $edir = $pdir . '/epics';
                if (!is_dir($edir) && !@mkdir($edir, 0775, true)) return ['success' => false, 'error' => 'mkdir epics/ fallita'];
                if (file_put_contents($edir . '/' . $slug . '.md', $md) === false) return ['success' => false, 'error' => 'scrittura epic fallita'];
                return ['success' => true, 'slug' => $slug, 'content' => $md];
            })(),

            // Epic → Ykan cards (epic/estimate/parallel/acceptance/depends_on set),
            // then rewrite the epic with a task table carrying the #seq ids.
            'pm_breakdown' => (function() use ($data, $input) {
                $project = trim((string)($input['project'] ?? ''));
                $slug = ykanPmSlug((string)($input['slug'] ?? ''));
                if ($project === '' || $slug === '') return ['success' => false, 'error' => 'project e slug obbligatori'];
                $lane = ykanLaneByName($data, $project);
                if (!$lane) return ['success' => false, 'error' => 'Progetto (swimlane) non trovato: ' . $project];
                $edir = ykanPmBase($data) . '/' . ykanPmSlug($project) . '/epics';
                $epicFile = $edir . '/' . $slug . '.md';
                if (!is_file($epicFile)) return ['success' => false, 'error' => 'Epic non trovato: crealo prima'];
                $epicMd = (string)file_get_contents($epicFile);

                $system = "Sei un tech lead che scompone un epic in task atomici. "
                    . "Rispondi SOLO con un oggetto JSON valido, nient'altro.";
                $prompt = "Epic:\n{$epicMd}\n\n"
                    . "Restituisci: {\"tasks\":[{\"ref\":\"t1\",\"title\":\"…\",\"description\":\"…\",\"priority\":\"high|medium|low\","
                    . "\"estimate\":\"S|M|L\",\"parallel\":true,\"depends_on\":[\"t0\"],\"acceptance\":[\"criterio…\"]}]}\n"
                    . "Regole: max 10 task; ref univoci (t1,t2,…); depends_on cita solo ref di questa lista; "
                    . "description breve e operativa; acceptance verificabili.";
                $res = ykanPmAgent($system, $prompt, [], null, 3);
                if (!$res['ok']) return ['success' => false, 'error' => $res['error']];
                $json = ykanPmExtractJson($res['text']);
                if (!$json || !isset($json['tasks']) || !is_array($json['tasks'])) {
                    return ['success' => false, 'error' => 'JSON task non valido dal modello'];
                }

                $fresh = loadData();
                $claudeLabel = null;
                foreach ($fresh['labels'] as $l) { if (strcasecmp($l['name'], 'Claude') === 0) { $claudeLabel = $l['id']; break; } }
                $firstCol = $fresh['columns'][0]['id'];
                $refToSeq = [];
                $created = [];
                $newCards = [];

                foreach (array_slice($json['tasks'], 0, 10) as $i => $t) {
                    if (empty($t['title'])) continue;
                    $seq = ykanAllocSeq($fresh);
                    $ref = (string)($t['ref'] ?? ('t' . ($i + 1)));
                    $refToSeq[$ref] = $seq;
                    $acc = [];
                    foreach ((array)($t['acceptance'] ?? []) as $a) {
                        $a = trim((string)$a);
                        if ($a !== '') $acc[] = ['text' => $a, 'done' => false];
                    }
                    $card = [
                        'id' => generateId('card'),
                        'seq' => $seq,
                        'title' => (string)$t['title'],
                        'description' => (string)($t['description'] ?? ''),
                        'priority' => in_array($t['priority'] ?? '', ['high', 'medium', 'low'], true) ? $t['priority'] : 'medium',
                        'due_date' => null,
                        'next_check' => null,
                        'label_id' => $claudeLabel,
                        'column_id' => $firstCol,
                        'swimlane_id' => $lane['id'],
                        'archived' => false,
                        'auto_regenerate' => false,
                        'regenerate_delay_days' => 0,
                        'files' => [],
                        'epic' => $slug,
                        'depends_on' => [], // filled in second pass
                        'parallel' => !empty($t['parallel']),
                        'estimate' => in_array($t['estimate'] ?? '', ['S', 'M', 'L'], true) ? $t['estimate'] : null,
                        'acceptance' => $acc,
                        'position' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                        'regenerated_count' => 0,
                        '_ref' => $ref,
                        '_deps_ref' => array_values(array_filter(array_map('strval', (array)($t['depends_on'] ?? [])))),
                    ];
                    $newCards[] = $card;
                }
                // second pass: resolve depends_on refs → seqs
                foreach ($newCards as &$c) {
                    $c['depends_on'] = array_values(array_filter(array_map(
                        fn($r) => $refToSeq[$r] ?? null, $c['_deps_ref']
                    )));
                    $created[] = ['seq' => $c['seq'], 'title' => $c['title'], 'depends_on' => $c['depends_on']];
                    unset($c['_ref'], $c['_deps_ref']);
                    $fresh['cards'][] = $c;
                }
                unset($c);
                saveData($fresh);

                // rewrite the epic: swap frontmatter stato + (re)build the task table
                $rows = "| # | Titolo | depends_on | parallel | stima | stato |\n|---|--------|------------|----------|-------|-------|\n";
                foreach ($created as $c) {
                    $deps = $c['depends_on'] ? '#' . implode(' #', $c['depends_on']) : '—';
                    $row = null;
                    foreach ($newCards as $x) { if ($x['seq'] === $c['seq']) { $row = $x; break; } }
                    $title = str_replace(['|', "\n"], ['/', ' '], $c['title']);
                    $par = ($row && !empty($row['parallel'])) ? 'sì' : 'no';
                    $est = ($row && !empty($row['estimate'])) ? $row['estimate'] : '—';
                    $rows .= "| #{$c['seq']} | {$title} | {$deps} | {$par} | {$est} | To Do |\n";
                }
                $taskBlock = "## Task\n\n{$rows}\n## Log avanzamento\n- " . date('Y-m-d H:i')
                    . " — epic sincronizzato, " . count($created) . " card create\n";

                $epicMd2 = preg_replace('/^stato\s*:\s*.+$/m', 'stato: synced', $epicMd, 1) ?? $epicMd;
                $pos = strpos($epicMd2, '## Task');
                if ($pos !== false) {
                    $epicMd2 = rtrim(substr($epicMd2, 0, $pos)) . "\n\n" . $taskBlock;
                } else {
                    $epicMd2 = rtrim($epicMd2) . "\n\n" . $taskBlock;
                }
                @file_put_contents($epicFile, $epicMd2);

                return ['success' => true, 'created' => $created, 'count' => count($created)];
            })(),

            default => ['success' => false, 'error' => 'Unknown action']
        };

        echo json_encode($result);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// === LOAD DATA FOR HTML ===
$data = loadData();
$dataJson = json_encode($data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>_Ykan - <?= htmlspecialchars($data['config']['project_name'] ?? 'Kanban') ?></title>
    <style>
        :root {
            --bg: #f8fafc; --bg2: #e2e8f0; --bg3: #fff; --text: #1e293b; --text2: #64748b;
            --border: #cbd5e1; --accent: #3b82f6; --accent2: #2563eb;
            --high: #ef4444; --medium: #f59e0b; --low: #22c55e;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            /* Layout tokens — themes can override these for different densities/shapes */
            --radius: 8px;        /* swimlanes, modals, panels */
            --card-radius: 6px;   /* task cards */
            --card-pad: 10px;     /* card inner padding */
            --gap: 8px;           /* space between cards */
            --col-min: 280px;     /* column width → density */
            --cell-pad: 8px;      /* column body padding */
            --font-base: 13px;    /* base font size */
            --header-pad: 12px 20px; /* top header padding */
            --blur: 0px;          /* glass backdrop blur (0 = off) */
            /* Surface tokens — default to the flat colors above, but a theme can
               set them to a gradient/image for bolder headers, bars and cards. */
            --header-bg: var(--bg2);   /* top app bar */
            --header-text: var(--text);/* text/icons in the top app bar */
            --swimlane-bg: var(--bg2); /* project (swimlane) bar */
            --colhead-bg: var(--bg2);  /* column titles row (To Do / In Progress…) */
            --card-bg: var(--bg3);     /* task cards */
        }
        .dark {
            --bg: #0f172a; --bg2: #1e293b; --bg3: #334155; --text: #f1f5f9; --text2: #94a3b8;
            --border: #475569; --shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { overflow-x: hidden; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; font-size: var(--font-base); overflow-x: hidden; }

        /* Header */
        .header { display: flex; align-items: center; gap: 12px; padding: var(--header-pad); background: var(--header-bg); color: var(--header-text); border-bottom: 1px solid var(--border); }
        .header h1 { font-size: 18px; font-weight: 600; cursor: pointer; }
        .header h1:hover { color: var(--accent); }
        .header-actions { display: flex; gap: 8px; margin-left: auto; }

        /* Buttons */
        .btn { padding: 6px 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg3); color: var(--text); cursor: pointer; font-size: 13px; transition: all 0.15s; }
        .btn:hover { border-color: var(--accent); color: var(--accent); }
        .btn-icon { padding: 6px 8px; }
        .btn-primary { background: var(--accent); color: white; border-color: var(--accent); }
        .btn-primary:hover { background: var(--accent2); }
        .btn-danger { color: var(--high); }
        .btn-danger:hover { background: var(--high); color: white; border-color: var(--high); }

        /* Board */
        .board-container { padding: 16px; overflow-x: auto; }
        .board { display: flex; flex-direction: column; gap: 0; min-width: fit-content; }

        /* Swimlane */
        .swimlane { border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 12px; overflow: hidden; }
        .swimlane-header { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--swimlane-bg); border-bottom: 1px solid var(--border); cursor: pointer; }
        .swimlane-toggle { transition: transform 0.2s; font-size: 12px; color: var(--text2); }
        .swimlane.collapsed .swimlane-toggle { transform: rotate(-90deg); }
        .swimlane.collapsed .cells-row { display: none; }
        .swimlane.collapsed .column-header-row { border-bottom: none; }
        .swimlane-name { font-weight: 600; font-size: 14px; color: var(--text); user-select: none; }
        .swimlane-actions { margin-left: auto; display: flex; gap: 4px; opacity: 0; transition: opacity 0.15s; }
        .swimlane:hover .swimlane-actions { opacity: 1; }

        /* Columns */
        .columns-row { display: flex; }
        .column-header-row { display: flex; background: var(--colhead-bg); border-bottom: 1px solid var(--border); }
        .column-header { flex: 1; min-width: var(--col-min); padding: 10px 12px; display: flex; align-items: center; gap: 8px; border-right: 1px solid var(--border); }
        .column-header:last-child { border-right: none; }
        .column-name { font-weight: 500; font-size: 13px; background: transparent; border: none; color: var(--text); flex: 1; }
        .column-name:focus { outline: 1px solid var(--accent); border-radius: 4px; }
        .column-count { font-size: 11px; background: var(--bg); padding: 2px 6px; border-radius: 10px; color: var(--text2); }
        .column-actions { display: flex; gap: 2px; opacity: 0; transition: opacity 0.15s; }
        .column-header:hover .column-actions { opacity: 1; }

        /* Cells */
        .cells-row { display: flex; }
        /* Reserve the same trailing width as the "+ add column" button so the
           header cells stay aligned with the card cells below them. */
        .cells-row::after { content: ''; flex: 0 0 58px; }
        .cell { flex: 1; min-width: var(--col-min); min-height: 120px; padding: var(--cell-pad); border-right: 1px solid var(--border); background: var(--bg); }
        .cell:last-child { border-right: none; }
        .cell.drag-over { background: var(--bg2); }

        /* Cards */
        .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--card-radius); padding: var(--card-pad); margin-bottom: var(--gap); cursor: grab; box-shadow: var(--shadow); transition: all 0.15s; backdrop-filter: blur(var(--blur)); -webkit-backdrop-filter: blur(var(--blur)); }
        .card:hover { border-color: var(--accent); }
        .card.dragging { opacity: 0.5; transform: rotate(2deg); }
        .card-header { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
        .card-title { font-weight: 500; font-size: 13px; flex: 1; word-break: break-word; }
        .card-seq { flex-shrink: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; font-weight: 600; color: var(--text2); background: var(--bg3); border: 1px solid var(--border); border-radius: 4px; padding: 0 5px; line-height: 18px; margin-top: 1px; }
        .card-priority { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
        .card-priority.high { background: var(--high); }
        .card-priority.medium { background: var(--medium); }
        .card-priority.low { background: var(--low); }
        .card-label { font-size: 10px; padding: 2px 6px; border-radius: 4px; color: white; display: inline-block; margin-bottom: 6px; }
        .card-desc { font-size: 12px; color: var(--text2); margin-bottom: 6px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .card-meta { display: flex; gap: 8px; font-size: 11px; color: var(--text2); }
        .card-meta span { display: flex; align-items: center; gap: 2px; }
        .card-overdue { color: var(--high) !important; }

        /* Add buttons */
        .add-card-btn { width: 100%; padding: 8px; border: 1px dashed var(--border); border-radius: 6px; background: transparent; color: var(--text2); cursor: pointer; font-size: 12px; }
        .add-card-btn:hover { border-color: var(--accent); color: var(--accent); }
        .add-column-btn, .add-swimlane-btn { padding: 8px 16px; border: 1px dashed var(--border); border-radius: 6px; background: transparent; color: var(--text2); cursor: pointer; font-size: 12px; margin: 8px; }
        /* Fixed-width so it matches the .cells-row::after spacer (46 + 2×6 margin = 58px). */
        .column-header-row .add-column-btn { flex: 0 0 46px; width: 46px; min-width: 46px; padding: 8px 0; margin: 6px; }
        .add-column-btn:hover, .add-swimlane-btn:hover { border-color: var(--accent); color: var(--accent); }

        /* Modal */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .modal-overlay.active { display: flex; }
        .modal { background: var(--bg3); border-radius: 12px; padding: 20px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .modal h2 { font-size: 16px; margin-bottom: 16px; }
        .modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }

        /* Form */
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px; color: var(--text2); }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); color: var(--text); font-size: 13px; }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline: none; border-color: var(--accent); }
        .form-group textarea { resize: vertical; min-height: 60px; }

        /* Toast */
        .toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 2000; }
        .toast { padding: 12px 16px; border-radius: 8px; margin-top: 8px; font-size: 13px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); animation: slideIn 0.3s ease; }
        .toast.success { background: var(--low); color: white; }
        .toast.error { background: var(--high); color: white; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes claudeProgress { 0% { width: 10%; margin-left: 0; } 50% { width: 60%; margin-left: 20%; } 100% { width: 10%; margin-left: 90%; } }

        /* Archive Panel */
        .archive-panel { position: fixed; right: 0; top: 0; bottom: 0; width: 350px; background: var(--bg3); border-left: 1px solid var(--border); transform: translateX(100%); transition: transform 0.3s; z-index: 500; overflow-y: auto; }
        .archive-panel.open { transform: translateX(0); }
        .archive-header { padding: 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .archive-list { padding: 12px; }
        .archive-card { opacity: 0.7; }

        /* GitHub Panel */
        .github-panel { position: fixed; right: 0; top: 0; bottom: 0; width: 420px; background: var(--bg3); border-left: 1px solid var(--border); transform: translateX(100%); transition: transform 0.3s; z-index: 501; display: flex; flex-direction: column; }
        .github-panel.open { transform: translateX(0); }
        .github-header { padding: 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .github-tabs { display: flex; border-bottom: 1px solid var(--border); }
        .github-tab { flex: 1; padding: 10px; text-align: center; cursor: pointer; font-size: 12px; border: none; background: none; color: var(--text2); transition: all 0.2s; }
        .github-tab:hover { background: var(--bg2); }
        .github-tab.active { color: var(--accent); border-bottom: 2px solid var(--accent); margin-bottom: -1px; }
        .github-content { flex: 1; padding: 12px; overflow-y: auto; }
        .github-item { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin-bottom: 8px; cursor: pointer; transition: border-color 0.2s; }
        .github-item:hover { border-color: var(--accent); }
        .github-item-header { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
        .github-item-title { font-size: 13px; font-weight: 500; flex: 1; }
        .github-item-number { font-size: 11px; color: var(--text2); }
        .github-item-meta { font-size: 11px; color: var(--text2); display: flex; gap: 12px; }
        .github-item-labels { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 6px; }
        .github-label { font-size: 10px; padding: 2px 6px; border-radius: 10px; color: #fff; }
        .github-state { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 500; }
        .github-state.open { background: #238636; color: white; }
        .github-state.closed { background: #8957e5; color: white; }
        .github-state.merged { background: #8957e5; color: white; }
        .github-empty { text-align: center; padding: 40px 20px; color: var(--text2); }
        .github-loading { text-align: center; padding: 40px 20px; color: var(--text2); }
        .github-repo-stats { display: flex; gap: 16px; padding: 12px; background: var(--bg2); border-radius: 8px; margin-bottom: 12px; justify-content: center; }
        .github-stat { text-align: center; }
        .github-stat-value { font-size: 18px; font-weight: 600; }
        .github-stat-label { font-size: 10px; color: var(--text2); }

        /* Gemini Panel */
        .gemini-panel { position: fixed; left: 0; top: 0; bottom: 0; width: 400px; background: var(--bg3); border-right: 1px solid var(--border); transform: translateX(-100%); transition: transform 0.3s; z-index: 500; display: flex; flex-direction: column; }
        .gemini-panel.open { transform: translateX(0); }
        .gemini-header { padding: 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .gemini-content { flex: 1; padding: 16px; overflow-y: auto; }
        .gemini-actions { padding: 12px 16px; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: 8px; }
        .gemini-response { white-space: pre-wrap; font-size: 13px; line-height: 1.6; }
        .gemini-loading { text-align: center; padding: 20px; color: var(--text2); }

        /* Project Analysis */
        .analysis-section { margin-bottom: 16px; }
        .analysis-section h4 { font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--accent); }
        .analysis-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
        .analysis-tag { font-size: 11px; padding: 2px 8px; background: var(--bg2); border-radius: 12px; color: var(--text2); }
        .analysis-list { font-size: 12px; color: var(--text2); padding-left: 16px; }
        .analysis-list li { margin-bottom: 4px; }

        /* Suggested Tasks */
        .suggested-task { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin-bottom: 8px; }
        .suggested-task:hover { border-color: var(--accent); }
        .suggested-task-header { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
        .suggested-task-title { font-weight: 500; font-size: 13px; flex: 1; }
        .suggested-task-priority { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; }
        .suggested-task-priority.high { background: var(--high); }
        .suggested-task-priority.medium { background: var(--medium); }
        .suggested-task-priority.low { background: var(--low); }
        .suggested-task-desc { font-size: 12px; color: var(--text2); margin-bottom: 8px; }
        .suggested-task-footer { display: flex; align-items: center; justify-content: space-between; }
        .suggested-task-category { font-size: 10px; padding: 2px 6px; background: var(--bg2); border-radius: 4px; color: var(--text2); }
        .suggested-task-add { padding: 4px 10px; font-size: 11px; background: var(--accent); color: white; border: none; border-radius: 4px; cursor: pointer; }
        .suggested-task-add:hover { background: var(--accent2); }
        .suggested-task.added { opacity: 0.5; pointer-events: none; }
        .suggested-task.added .suggested-task-add { background: var(--low); }

        /* PM Panel (spec-driven: PRD → epic → cards) */
        .pm-panel { position: fixed; right: 0; top: 0; bottom: 0; width: 440px; max-width: 100%;
            /* opaque even when the theme makes --bg3 a translucent "glass" colour:
               paint a solid --bg underneath, then the token on top */
            background: linear-gradient(var(--bg3), var(--bg3)), var(--bg);
            background-color: var(--bg); border-left: 1px solid var(--border); transform: translateX(100%);
            transition: transform 0.3s; z-index: 502; display: flex; flex-direction: column;
            backdrop-filter: none; -webkit-backdrop-filter: none; box-shadow: -8px 0 24px rgba(0,0,0,0.18); }
        .pm-panel.open { transform: translateX(0); }
        .pm-panel .pm-head { padding: 14px 16px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px; }
        .pm-panel .pm-head h3 { font-size: 15px; flex: 1; }
        .pm-panel .pm-body { flex: 1; overflow-y: auto; padding: 14px 16px; }
        .pm-panel select, .pm-panel input, .pm-panel textarea {
            background: var(--bg); color: var(--text); border: 1px solid var(--border);
            border-radius: 6px; padding: 6px 8px; font-size: 12px; font-family: inherit; }
        .pm-sec { margin-bottom: 18px; }
        .pm-sec > h4 { font-size: 12px; text-transform: uppercase; letter-spacing: .04em;
            color: var(--text2); margin-bottom: 8px; }
        .pm-epic { border: 1px solid var(--border); border-radius: 8px; padding: 10px; margin-bottom: 8px; background: var(--bg); }
        .pm-epic-top { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
        .pm-epic-top strong { font-size: 13px; flex: 1; }
        .pm-epic-st { font-size: 10px; padding: 1px 7px; border-radius: 999px; background: var(--bg2); color: var(--text2); }
        .pm-epic-st.synced { background: var(--accent); color: #fff; }
        .pm-epic-st.done { background: var(--low); color: #fff; }
        .pm-prog { height: 5px; border-radius: 3px; background: var(--bg2); overflow: hidden; margin: 4px 0 8px; }
        .pm-prog > i { display: block; height: 100%; background: var(--low); }
        .pm-epic-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .pm-epic-actions .btn { padding: 3px 8px; font-size: 11px; }
        .pm-doc { font-size: 12px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;
            margin-bottom: 6px; cursor: pointer; display: flex; gap: 8px; align-items: center; background: var(--bg); }
        .pm-doc:hover { border-color: var(--accent); }
        .pm-out { white-space: pre-wrap; font-size: 12px; line-height: 1.5; background: var(--bg2);
            border-radius: 8px; padding: 10px; margin-top: 8px; max-height: 340px; overflow-y: auto; }
        .pm-busy { text-align: center; color: var(--text2); padding: 20px; font-size: 12px; }

        /* Responsive */
        @media (max-width: 768px) {
            .column-header, .cell { min-width: 240px; }
            .gemini-panel, .archive-panel { width: 100%; }
        }

        /* Filters Bar */
        .filters-bar {
            display: flex; gap: 8px; padding: 8px 16px; background: var(--bg2);
            border-bottom: 1px solid var(--border); align-items: center; flex-wrap: wrap;
        }
        .search-input {
            padding: 6px 12px; border: 1px solid var(--border); border-radius: 6px;
            background: var(--bg); color: var(--text); font-size: 13px; width: 180px;
        }
        .search-input:focus { outline: none; border-color: var(--accent); }
        .filter-select {
            padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;
            background: var(--bg); color: var(--text); font-size: 12px; cursor: pointer;
        }
        .filter-select:focus { outline: none; border-color: var(--accent); }
        .card.filtered-out { display: none !important; }

        /* === PM layer (epic / dependencies / acceptance) === */
        .card.pm-blocked { opacity: 0.55; }
        .card.pm-blocked:hover { opacity: 0.8; }
        .pm-chip { font-size: 10px; padding: 1px 6px; border-radius: 999px; color: #fff;
            display: inline-flex; align-items: center; gap: 3px; max-width: 140px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pm-badges { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; margin: 2px 0 6px; }
        .pm-mini { font-size: 10px; padding: 1px 5px; border-radius: 4px; background: var(--bg3);
            border: 1px solid var(--border); color: var(--text2); }
        .pm-mini.pm-lock { color: #fff; background: var(--medium); border-color: transparent; }
        .pm-acc-wrap { margin-top: 4px; }
        .pm-acc-bar { height: 4px; border-radius: 2px; background: var(--bg3); overflow: hidden; }
        .pm-acc-bar > i { display: block; height: 100%; background: var(--low); }
        .pm-box { background: var(--bg2); padding: 12px; border-radius: 8px; margin-top: 12px; }
        .pm-box > label { font-weight: 500; margin-bottom: 8px; display: block; }
        .pm-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .pm-tag { display: inline-flex; align-items: center; gap: 6px; padding: 3px 8px;
            background: var(--bg); border: 1px solid var(--border); border-radius: 999px; font-size: 12px; }
        .pm-tag button { border: none; background: none; color: var(--high); cursor: pointer; font-size: 14px; line-height: 1; }
        .pm-acc-item { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 13px; }
        .pm-acc-item.done span { text-decoration: line-through; color: var(--text2); }

        /* Changelog Panel */
        .changelog-btn {
            position: fixed; bottom: 20px; right: 20px; width: 36px; height: 36px;
            border-radius: 50%; background: var(--bg2); border: 1px solid var(--border);
            color: var(--text2); font-size: 16px; cursor: pointer; z-index: 100;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .changelog-btn:hover { background: var(--accent); color: white; border-color: var(--accent); }
        .changelog-panel {
            position: fixed; bottom: 0; left: 0; right: 0; max-height: 0;
            background: var(--bg); border-top: 1px solid var(--border);
            overflow: hidden; transition: max-height 0.3s ease; z-index: 200;
        }
        .changelog-panel.active { max-height: 400px; }
        .changelog-content {
            padding: 20px; max-width: 800px; margin: 0 auto;
            max-height: 380px; overflow-y: auto;
        }
        .changelog-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .changelog-title { font-size: 18px; font-weight: 600; }
        .changelog-close { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text2); }
        .changelog-version { margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
        .changelog-version:last-of-type { border-bottom: none; }
        .changelog-version h3 { font-size: 14px; color: var(--accent); margin-bottom: 8px; }
        .changelog-version ul { margin: 0; padding-left: 20px; font-size: 13px; color: var(--text2); }
        .changelog-version li { margin-bottom: 4px; }
        .changelog-footer { margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--border); font-size: 11px; color: var(--text2); text-align: center; }
        .changelog-footer a { color: var(--accent); text-decoration: none; }
        /* Modal e pannelli sempre opachi: con i temi "glass" --bg3 è semitrasparente e il testo si mescola con quello sotto */
        .modal, .archive-panel, .github-panel, .gemini-panel {
            background: linear-gradient(var(--bg3), var(--bg3)), var(--bg);
            background-color: var(--bg);
            backdrop-filter: none; -webkit-backdrop-filter: none;
        }
        /* Schede Kanban / Dashboard */
        .view-tabs { display: flex; gap: 2px; margin-left: 16px; }
        .view-tabs button { background: transparent; border: none; border-bottom: 2px solid transparent; color: inherit; opacity: .65; padding: 6px 12px; font-size: 13px; font-weight: 500; cursor: pointer; }
        .view-tabs button:hover { opacity: 1; }
        .view-tabs button.active { opacity: 1; border-bottom-color: var(--accent); }
        .dash-view { display: none; padding: 16px 20px 40px; max-width: 980px; margin: 0 auto; }
        body[data-view="dashboard"] .filters-bar, body[data-view="dashboard"] .board-container { display: none; }
        body[data-view="dashboard"] .dash-view { display: block; }
        .dash-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .dash-toolbar h2 { font-size: 18px; margin: 0; }
        .dash-bridge { font-size: 11px; padding: 2px 8px; border-radius: 10px; background: var(--bg2); color: var(--text2); }
        .dash-bridge.ok { color: #16a34a; } .dash-bridge.ko { color: #dc2626; }
        .dash-h { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text2); margin: 22px 0 8px; }
        .dash-proj { font-weight: 600; font-size: 13px; margin: 12px 0 6px; }
        .dash-item { border: 1px solid var(--border); border-left: 3px solid var(--medium, #f59e0b); border-radius: 6px; padding: 8px 10px; margin-bottom: 5px; background: var(--bg3, transparent); }
        .dash-item.high { border-left-color: var(--high, #ef4444); }
        .dash-item.clickable { cursor: pointer; }
        .dash-row { display: flex; gap: 8px; align-items: baseline; }
        .dash-sub { font-size: 12px; color: var(--text2); margin-top: 2px; }
        .dash-chip { display: inline-block; font-size: 11px; padding: 0 6px; border-radius: 8px; background: var(--bg2); border: 1px solid var(--border); margin-left: 4px; cursor: pointer; }
        .dash-empty { padding: 12px; color: var(--text2); font-size: 13px; }
        .dash-day { font-size: 12px; font-weight: 600; margin: 10px 0 4px; }
        .dash-ev { font-size: 12px; color: var(--text2); padding: 1px 0; }
        .dash-ev b { color: var(--text); font-weight: 500; }
        .dash-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 14px; }
        .dash-tabs button { background: transparent; border: none; border-bottom: 2px solid transparent; color: inherit; opacity: .65; padding: 8px 14px; font-size: 13px; font-weight: 500; cursor: pointer; margin-bottom: -1px; }
        .dash-tabs button:hover { opacity: 1; }
        .dash-tabs button.active { opacity: 1; border-bottom-color: var(--accent); }
        .dash-count { display: inline-block; min-width: 18px; text-align: center; font-size: 11px; font-weight: 600; padding: 0 6px; border-radius: 9px; background: var(--bg2); color: var(--text2); margin-left: 4px; }
        .dash-tabs button.active .dash-count { background: var(--accent); color: #fff; }
        .dash-hint { font-size: 12px; color: var(--text2); margin-bottom: 10px; }
        .dash-projcard { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; background: var(--bg3, transparent); }
        .dash-projhead { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 14px; }
        .dash-when { font-size: 11px; color: var(--text2); max-width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dash-ok { font-size: 11px; color: #16a34a; }
        .dash-revbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
        .dash-rev { display: flex; gap: 10px; align-items: center; border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; margin-bottom: 6px; }
        .wk-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .wk-summary { display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 18px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--bg3, transparent); }
        .wk-tiles { display: flex; gap: 8px; }
        .wk-tile { display: flex; flex-direction: column; align-items: center; gap: 2px; width: 34px; font-size: 10px; color: var(--text2); }
        .wk-tile b { font-size: 13px; color: var(--text); }
        .wk-bar { height: 44px; width: 100%; display: flex; align-items: flex-end; background: var(--bg2); border-radius: 4px; overflow: hidden; }
        .wk-bar i { display: block; width: 100%; background: var(--accent); min-height: 2px; border-radius: 4px 4px 0 0; }
        .wk-stats { display: flex; gap: 18px; margin-left: auto; }
        .wk-stats div { text-align: center; } .wk-stats b { display: block; font-size: 20px; line-height: 1.1; } .wk-stats span { font-size: 11px; color: var(--text2); }
        .wk-day { display: flex; gap: 14px; margin-bottom: 16px; }
        .wk-date { width: 52px; flex-shrink: 0; text-align: center; padding-top: 2px; }
        .wk-date b { display: block; font-size: 24px; line-height: 1; }
        .wk-date span { font-size: 11px; color: var(--text2); text-transform: uppercase; }
        .wk-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8px; padding-left: 14px; border-left: 2px solid var(--border); }
        .wk-proj { border-left: 3px solid var(--border); background: var(--bg3, transparent); border-radius: 0 8px 8px 0; padding: 6px 10px; border-top: 1px solid var(--border); border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .wk-projname { font-size: 12px; font-weight: 700; margin-bottom: 3px; } .wk-projname span { font-weight: 400; color: var(--text2); margin-left: 4px; }
        .wk-ev { display: flex; gap: 8px; align-items: baseline; font-size: 13px; padding: 2px 0; }
        .wk-time { font-size: 11px; color: var(--text2); width: 38px; flex-shrink: 0; font-variant-numeric: tabular-nums; }
        .wk-ic { width: 18px; flex-shrink: 0; text-align: center; }
        .wk-txt { flex: 1; min-width: 0; }
        .wk-seq { font-size: 11px; font-weight: 600; color: var(--text2); }
        .wk-act a { font-size: 11px; color: var(--accent); text-decoration: none; }
        /* Task cliccabili (#N) colorati per stato: verde To Do, arancio Claude Do / In corso, rosso scuro Fatto o archiviato */
        .dash-chip.st-todo, .dash-legend i.st-todo { background: #16a34a; border-color: #16a34a; }
        .dash-chip.st-doing, .dash-legend i.st-doing { background: #ea580c; border-color: #ea580c; }
        .dash-chip.st-done, .dash-legend i.st-done { background: #991b1b; border-color: #991b1b; }
        .dash-chip.st-todo, .dash-chip.st-doing, .dash-chip.st-done { color: #fff; font-weight: 600; }
        .dash-legend { margin-left: auto; align-self: center; display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--text2); padding-right: 4px; }
        .dash-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-left: 8px; }
        /* Revisione sessioni: gruppi per progetto e giudizio ben visibile */
        .rv-group { margin-bottom: 16px; }
        .rv-grouphead { display: flex; align-items: center; gap: 8px; padding: 6px 2px; margin-bottom: 6px; border-bottom: 2px solid var(--border); font-size: 14px; }
        .rv-grouphead span.rv-sum { font-size: 11px; color: var(--text2); margin-left: auto; }
        .rv-row { display: flex; gap: 10px; align-items: flex-start; border: 1px solid var(--border); border-left: 4px solid var(--border); border-radius: 8px; padding: 8px 10px; margin-bottom: 6px; background: var(--bg3, transparent); }
        .rv-row.v-conclude { border-left-color: #2563eb; } .rv-row.v-resume { border-left-color: #7c3aed; } .rv-row.v-split { border-left-color: #0d9488; }
        .rv-title { font-size: 13px; font-weight: 600; }
        .rv-meta { font-size: 11px; color: var(--text2); margin-top: 1px; }
        .rv-decision { display: flex; gap: 8px; align-items: baseline; margin-top: 5px; font-size: 12.5px; }
        .rv-pill { flex-shrink: 0; font-size: 11px; font-weight: 700; padding: 1px 9px; border-radius: 10px; color: #fff; white-space: nowrap; }
        .rv-pill.conclude { background: #2563eb; } .rv-pill.resume { background: #7c3aed; } .rv-pill.split { background: #0d9488; } .rv-pill.none { background: #64748b; }
        .rv-tasks { font-size: 12px; color: var(--text2); margin: 3px 0 0 2px; }
        .rv-actions { display: flex; gap: 6px; flex-shrink: 0; }
        .rv-filters { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
        .rv-filters button { border: 1px solid var(--border); background: transparent; color: inherit; border-radius: 14px; padding: 3px 11px; font-size: 12px; cursor: pointer; }
        .rv-filters button.active { background: var(--accent); border-color: var(--accent); color: #fff; }
        /* Scheda Git */
        .git-summary { display: flex; gap: 22px; flex-wrap: wrap; margin-bottom: 14px; padding: 10px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--bg3, transparent); }
        .git-summary div b { display: block; font-size: 20px; line-height: 1.1; } .git-summary div span { font-size: 11px; color: var(--text2); }
        .git-row { display: flex; gap: 12px; align-items: center; border: 1px solid var(--border); border-radius: 10px; padding: 9px 12px; margin-bottom: 7px; background: var(--bg3, transparent); }
        .git-row.norepo { opacity: .6; }
        .git-main { flex: 1; min-width: 0; }
        .git-name { font-size: 14px; font-weight: 600; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .git-last { font-size: 12px; color: var(--text2); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .git-state { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 5px; }
        .git-tag { font-size: 11px; padding: 1px 8px; border-radius: 10px; background: var(--bg2); border: 1px solid var(--border); white-space: nowrap; }
        .git-tag.warn { background: #ea580c; border-color: #ea580c; color: #fff; }
        .git-tag.ok { color: #16a34a; border-color: #16a34a; }
        .git-tag.info { background: #2563eb; border-color: #2563eb; color: #fff; }
        .sess-msg { margin: 6px 0; padding: 6px 10px; border-radius: 8px; border: 1px solid var(--border); }
        .sess-msg.user { background: var(--bg2); }
        .sess-who { font-size: 11px; font-weight: 600; color: var(--text2); margin-bottom: 2px; }
        .sess-who span { font-weight: 400; margin-left: 6px; }
        .sess-text { font-size: 13px; white-space: pre-wrap; word-break: break-word; }
        .sess-tool { font-size: 11px; color: var(--text2); padding: 1px 10px; }
        .sess-badge { font-size: 11px; padding: 1px 8px; border-radius: 10px; background: var(--bg2); border: 1px solid var(--border); }
        .sess-badge.done { color: #16a34a; border-color: #16a34a; }
    </style>
    <!-- Custom theme overrides (populated on load + when switching themes) -->
    <style id="customThemeStyle"><?= !empty($data['config']['theme_file']) ? ykanThemeCss($data['config']['theme_file']) : '' ?></style>
    <!-- Terminale live (pannello Sessioni/Bridge): xterm.js da CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.css">
    <script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.js"></script>
</head>
<?php
    // Body base class: a custom theme supplies all vars itself (no .dark base);
    // otherwise fall back to the built-in light/dark.
    $bodyClass = !empty($data['config']['theme_file'])
        ? ''
        : ($data['config']['theme'] === 'dark' ? 'dark' : '');
?>
<body class="<?= $bodyClass ?>">
    <!-- Header -->
    <header class="header">
        <h1 id="projectName" onclick="openConfigModal()"><?= htmlspecialchars($data['config']['project_name'] ?? 'My Project') ?></h1>
        <nav class="view-tabs">
            <button id="tabKanban" class="active" onclick="showView('kanban')">Kanban</button>
            <button id="tabDash" onclick="showView('dashboard')">Dashboard</button>
        </nav>
        <div class="header-actions">
            <button class="btn btn-icon" onclick="toggleGemini()" title="Gemini AI">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            </button>
            <button class="btn btn-icon" onclick="toggleArchive()" title="Archive">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/></svg>
            </button>
            <button class="btn btn-icon" onclick="toggleGithub()" title="GitHub">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
            </button>
            <button class="btn btn-icon" onclick="togglePm()" title="PM — PRD / epic / breakdown">📋</button>
            <button class="btn btn-icon" onclick="pmQuickIdea()" title="Cattura un'idea → PRD">📝</button>
            <select id="themeSelect" class="filter-select" onchange="onThemeSelect(this.value)" title="Tema attivo" style="max-width:150px">
                <option value="light">☀️ Light</option>
                <option value="dark">🌙 Dark</option>
            </select>
            <button class="btn btn-icon" onclick="openThemesModal()" title="Gestisci temi">🎨</button>
            <button class="btn btn-icon" onclick="toggleTheme()" title="Toggle light/dark">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
            </button>
            <button class="btn btn-icon" onclick="openConfigModal()" title="Settings">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </button>
        </div>
    </header>

    <!-- Search & Filters Bar -->
    <div class="filters-bar">
        <input type="text" id="searchInput" class="search-input" placeholder="🔍 Search..." oninput="applyFilters()">
        <select id="filterLabel" class="filter-select" onchange="applyFilters()">
            <option value="">All labels</option>
            <?php foreach ($data['labels'] as $l): ?>
            <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterPriority" class="filter-select" onchange="applyFilters()">
            <option value="">All priorities</option>
            <option value="high">🔴 High</option>
            <option value="medium">🟡 Medium</option>
            <option value="low">🟢 Low</option>
        </select>
        <select id="filterDue" class="filter-select" onchange="applyFilters()">
            <option value="">All due dates</option>
            <option value="overdue">⚠️ Overdue</option>
            <option value="today">📅 Today</option>
            <option value="week">📆 This week</option>
            <option value="none">❌ No due date</option>
        </select>
        <select id="filterEpic" class="filter-select" onchange="applyFilters()" title="Filtra per epic">
            <option value="">🎯 All epics</option>
        </select>
        <button class="btn btn-icon" onclick="clearFilters()" title="Reset filters">✕</button>
        <span style="flex:1"></span>
        <button class="btn" onclick="openProjectsModal()" title="Link swimlanes to project folders (for mobile/MCP)">🔗 Projects</button>
        <button class="btn" onclick="generateStandup()" title="Daily Standup AI">📋 Standup</button>
        <button class="btn" onclick="scanTodos()" title="Scan TODO in files">🔍 TODO</button>
        <button class="btn" onclick="showBurndown()" title="Burndown Chart">📈 Burndown</button>
        <button class="btn" onclick="exportJSON()" title="Export JSON">📥 JSON</button>
        <button class="btn" onclick="exportCSV()" title="Export CSV">📊 CSV</button>
    </div>

    <!-- Board -->
    <div class="board-container">
        <div id="board" class="board"></div>
        <button class="add-swimlane-btn" onclick="addSwimlane()">+ Add Swimlane</button>
    </div>

    <!-- Dashboard (scheda: cose non chiuse + attività recente, con sessioni Claude collegate ai task) -->
    <section id="dashboardView" class="dash-view">
        <div class="dash-toolbar">
            <h2>Dashboard</h2>
            <span id="dashBridge" class="dash-bridge"></span>
            <span style="flex:1"></span>
            <label style="font-size:12px;color:var(--text2)">Progetto
                <select id="dashProject" onchange="dashSetFilter()" style="width:auto;padding:2px 4px;max-width:170px"></select></label>
            <label style="font-size:12px;color:var(--text2)">Periodo
                <select id="dashDays" onchange="dashSetFilter()" style="width:auto;padding:2px 4px">
                    <option value="7">ultimi 7 giorni</option><option value="14">ultimi 14 giorni</option>
                    <option value="30">ultimi 30 giorni</option><option value="90">ultimi 90 giorni</option>
                </select></label>
            <label style="font-size:12px;color:var(--text2);display:flex;align-items:center;gap:4px" title="Quando concludi o riapri una sessione, aggiorna anche l'archivio di Claude Desktop (con backup dei file toccati)">
                <input type="checkbox" id="dashDesk" onchange="deskSetting(this.checked)" style="width:auto"> archivia anche in Claude Desktop</label>
            <button class="btn" onclick="loadDashboard()">↻ Aggiorna</button>
        </div>
        <div id="dashTabs" class="dash-tabs">
            <button data-tab="resume" onclick="dashTab('resume')">Da riprendere <span class="dash-count"></span></button>
            <button data-tab="week" onclick="dashTab('week')">Attività <span class="dash-count"></span></button>
            <button data-tab="review" onclick="dashTab('review')">Revisione sessioni <span class="dash-count"></span></button>
            <button data-tab="git" onclick="dashTab('git')">Git <span class="dash-count"></span></button>
            <span class="dash-legend"><i class="st-todo"></i>To Do <i class="st-doing"></i>Claude Do / In corso <i class="st-done"></i>Fatto / archiviato</span>
        </div>
        <div id="dashboardBody"></div>
    </section>

    <!-- Gemini Panel -->
    <div id="geminiPanel" class="gemini-panel">
        <div class="gemini-header">
            <h3>Gemini AI</h3>
            <button class="btn btn-icon" onclick="toggleGemini()">&times;</button>
        </div>
        <div id="geminiContent" class="gemini-content">
            <p style="color: var(--text2)">Use the buttons below to analyze the board with Gemini AI.</p>
        </div>
        <div class="gemini-actions">
            <button class="btn btn-primary" onclick="analyzeProject()" style="background:linear-gradient(135deg,#667eea,#764ba2);border:none">
                🔍 Analyze Project
            </button>
            <hr style="border:none;border-top:1px solid var(--border);margin:8px 0">
            <button class="btn" onclick="askGemini('analyze')">Analyze Board</button>
            <button class="btn" onclick="askGemini('suggest_tasks')">Suggest Tasks</button>
            <button class="btn" onclick="askGemini('estimate')">Estimate Time</button>
            <div class="form-group" style="margin:0">
                <input type="text" id="geminiCustom" placeholder="Custom question...">
            </div>
            <button class="btn btn-primary" onclick="askGeminiCustom()">Ask</button>
        </div>
    </div>

    <!-- Archive Panel -->
    <div id="archivePanel" class="archive-panel">
        <div class="archive-header">
            <h3>Archive</h3>
            <button class="btn btn-icon" onclick="toggleArchive()">&times;</button>
        </div>
        <div id="archiveList" class="archive-list"></div>
    </div>

    <!-- GitHub Panel -->
    <div id="githubPanel" class="github-panel">
        <div class="github-header">
            <h3>GitHub</h3>
            <button class="btn btn-icon" onclick="toggleGithub()">&times;</button>
        </div>
        <div id="githubRepoStats" class="github-repo-stats" style="display:none"></div>
        <div class="github-tabs">
            <button class="github-tab active" onclick="switchGithubTab('issues')">🐛 Issues</button>
            <button class="github-tab" onclick="switchGithubTab('prs')">🔀 Pull Requests</button>
            <button class="github-tab" onclick="switchGithubTab('commits')">📜 Commits</button>
        </div>
        <div id="githubContent" class="github-content">
            <div class="github-empty">
                <p>Configure GitHub Token and Repository in Settings to see issues, PRs and commits.</p>
                <button class="btn btn-primary" onclick="openConfigModal()" style="margin-top:12px">⚙️ Settings</button>
            </div>
        </div>
    </div>

    <!-- PM Panel (spec-driven project manager) -->
    <div id="pmPanel" class="pm-panel">
        <div class="pm-head">
            <h3>📋 Project Manager</h3>
            <select id="pmProject" onchange="pmRefresh()" style="max-width:150px"></select>
            <button class="btn btn-icon" onclick="togglePm()">&times;</button>
        </div>
        <div class="pm-body" id="pmBody">
            <div class="pm-busy">Seleziona un progetto…</div>
        </div>
    </div>

    <!-- Card Modal -->
    <div id="cardModal" class="modal-overlay">
        <div class="modal">
            <h2 id="cardModalTitle">New Card</h2>
            <form id="cardForm">
                <input type="hidden" id="cardId">
                <input type="hidden" id="cardColumnId">
                <input type="hidden" id="cardSwimlaneId">
                <div class="form-group" id="templateGroup" style="display:none">
                    <label>📋 Template</label>
                    <select id="cardTemplate" onchange="applyTemplate()">
                        <option value="">Select template...</option>
                        <option value="bug">🐛 Bug Report</option>
                        <option value="feature">✨ Feature Request</option>
                        <option value="task">📌 Generic Task</option>
                        <option value="docs">📚 Documentation</option>
                        <option value="refactor">🔧 Refactoring</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" id="cardTitleInput" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="cardDescInput"></textarea>
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <div style="display:flex;gap:8px">
                        <select id="cardPriorityInput" style="flex:1">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                        <button type="button" class="btn" onclick="aiSuggestCategory()" title="AI suggests label and priority">🤖 Auto</button>
                        <button type="button" class="btn" onclick="aiEstimate()" title="AI estimates time">⏱️</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Label</label>
                    <select id="cardLabelInput">
                        <option value="">None</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Due Date</label>
                    <input type="date" id="cardDueInput">
                </div>
                <div class="form-group">
                    <label>Next Check Date</label>
                    <input type="date" id="cardNextCheckInput">
                </div>
                <div class="form-group" style="background:var(--bg2);padding:12px;border-radius:8px;margin-top:16px">
                    <label style="font-weight:500;margin-bottom:8px;display:block">📁 Associated Files</label>
                    <div id="cardFilesList" style="margin-bottom:8px"></div>
                    <div style="display:flex;gap:8px">
                        <input type="text" id="cardFileInput" placeholder="path/file.php (relative)" style="flex:1;padding:6px 8px;font-size:12px">
                        <button type="button" class="btn" onclick="addFileToCard()">+ Add</button>
                    </div>
                    <p style="font-size:10px;color:var(--text2);margin-top:4px">Relative paths to project folder</p>
                </div>

                <!-- PM / Spec: epic, dependencies, estimate, acceptance criteria -->
                <div class="form-group pm-box">
                    <label>🎯 PM / Spec</label>
                    <div class="pm-row" style="margin-bottom:8px">
                        <div style="flex:1;min-width:150px">
                            <label style="font-size:11px;color:var(--text2)">Epic</label>
                            <input type="text" id="cardEpicInput" list="pmEpicList" placeholder="es. sistema-achievement" style="width:100%;padding:6px 8px;font-size:12px">
                            <datalist id="pmEpicList"></datalist>
                        </div>
                        <div>
                            <label style="font-size:11px;color:var(--text2)">Stima</label>
                            <select id="cardEstimateInput" style="padding:6px 8px;font-size:12px">
                                <option value="">—</option>
                                <option value="S">S</option>
                                <option value="M">M</option>
                                <option value="L">L</option>
                            </select>
                        </div>
                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-top:14px;cursor:pointer">
                            <input type="checkbox" id="cardParallel" style="width:15px;height:15px"> ‖ parallelo
                        </label>
                    </div>
                    <div style="margin-bottom:8px">
                        <label style="font-size:11px;color:var(--text2);display:block;margin-bottom:4px">Dipende da (task sbloccanti)</label>
                        <div id="cardDependsList" class="pm-row" style="margin-bottom:4px"></div>
                        <select id="cardDependsPick" style="width:100%;padding:6px 8px;font-size:12px" onchange="addCardDep(this.value); this.value='';">
                            <option value="">+ aggiungi dipendenza…</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px;color:var(--text2);display:block;margin-bottom:4px">Acceptance criteria</label>
                        <div id="cardAccList"></div>
                        <div style="display:flex;gap:8px;margin-top:4px">
                            <input type="text" id="cardAccInput" placeholder="criterio verificabile…" style="flex:1;padding:6px 8px;font-size:12px"
                                onkeydown="if(event.key==='Enter'){event.preventDefault();addCardAcc();}">
                            <button type="button" class="btn" onclick="addCardAcc()">+ Add</button>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="background:var(--bg2);padding:12px;border-radius:8px;margin-top:12px">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px">
                        <input type="checkbox" id="cardAutoRegenerate" style="width:16px;height:16px">
                        <span style="font-weight:500">Auto-regenerating Task</span>
                        <span style="font-size:11px;color:var(--text2)">(recreates when archived)</span>
                    </label>
                    <div id="regenerateOptions" style="display:none;margin-top:8px">
                        <label style="font-size:11px;color:var(--text2)">Regeneration delay (days)</label>
                        <input type="number" id="cardRegenerateDelay" min="0" value="0" style="width:80px;padding:4px 8px">
                        <span style="font-size:11px;color:var(--text2);margin-left:8px">0 = immediate</span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-warning" id="deleteCardBtn" onclick="deleteCard()" style="display:none">📦 Archive</button>
                    <button type="button" class="btn" id="verifyCardBtn" onclick="verifyTaskWithAI()" style="display:none;background:linear-gradient(135deg,#667eea,#764ba2);color:white;border:none">🤖 AI Verify</button>
                    <button type="button" class="btn" id="executeClaudeBtn" onclick="executeWithClaude()" style="display:none;background:linear-gradient(135deg,#d97706,#ea580c);color:white;border:none">🏖️ Claude Go</button>
                    <button type="button" class="btn" id="openClaudeAppBtn" onclick="openInClaudeApp()" style="display:none;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:white;border:none">📱 Claude App</button>
                    <button type="button" class="btn" id="playLocalBtn" onclick="playCardInTerminal()" style="display:none;background:linear-gradient(135deg,#059669,#10b981);color:white;border:none" title="Apre un terminale locale con Claude Code già avviato sul contesto di questo task">▶️ PLAY locale</button>
                    <span style="flex:1"></span>
                    <button type="button" class="btn" onclick="closeCardModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
                <div id="verifyResult" style="display:none;margin-top:12px;padding:12px;border-radius:8px;font-size:12px"></div>
                <div id="claudeRunsPanel" style="display:none;margin-top:12px;border:1px solid var(--border);border-radius:8px;overflow:hidden">
                    <div onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'" style="padding:10px 12px;background:var(--bg2);cursor:pointer;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500">
                        <span>🤖 Storico esecuzioni Claude</span>
                        <span id="claudeRunsCount" style="font-size:11px;padding:1px 6px;border-radius:8px;background:var(--accent);color:white"></span>
                        <span style="margin-left:auto;font-size:11px;color:var(--text2)">▼</span>
                    </div>
                    <div id="claudeRunsList" style="display:none;max-height:300px;overflow-y:auto"></div>
                </div>
                <div id="cardSessions" style="display:none;margin-top:12px;border:1px solid var(--border);border-radius:8px;padding:10px 12px"></div>
            </form>
        </div>
    </div>

    <!-- Config Modal -->
    <div id="configModal" class="modal-overlay">
        <div class="modal">
            <h2>Settings</h2>
            <form id="configForm">
                <div class="form-group">
                    <label>Project Name</label>
                    <input type="text" id="configProjectName">
                </div>
                <div class="form-group">
                    <label>AI Response Language</label>
                    <select id="configLanguage">
                        <option value="en">English</option>
                        <option value="it">Italiano</option>
                        <option value="es">Español</option>
                        <option value="fr">Français</option>
                        <option value="de">Deutsch</option>
                        <option value="pt">Português</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Gemini API Key</label>
                    <input type="password" id="configGeminiKey" placeholder="Enter your API key...">
                </div>
                <div class="form-group" id="claudeKeyStatus" style="background:var(--bg2);padding:10px 12px;border-radius:8px">
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:0">
                        <span>🏖️ Claude Agent</span>
                        <span id="claudeKeyBadge" style="font-size:11px;padding:2px 8px;border-radius:10px"></span>
                    </label>
                    <small style="color:var(--text2);font-size:11px;display:block;margin-top:4px">Aggiungi <code>ANTHROPIC_KEY=sk-ant-...</code> nel file <code>.env</code> per abilitare l'esecuzione automatica dei task.</small>
                </div>
                <hr style="margin:16px 0;border:none;border-top:1px solid var(--border)">
                <div class="form-group">
                    <label>GitHub Token <span style="font-weight:normal;color:var(--text2)">(Personal Access Token)</span></label>
                    <input type="password" id="configGithubToken" placeholder="ghp_xxxxxxxxxxxx...">
                    <small style="color:var(--text2);font-size:11px">Generate from: GitHub → Settings → Developer settings → Personal access tokens</small>
                    <small id="configGithubEnvNote" style="display:none;color:#16a34a;font-size:11px;margin-top:3px">Il server usa già <code>GITHUB_TOKEN</code> dalla <code>.env</code>: ha la precedenza e questo campo si può lasciare vuoto.</small>
                </div>
                <div class="form-group">
                    <label>GitHub Repository <span style="font-weight:normal;color:var(--text2)">(owner/repo)</span></label>
                    <input type="text" id="configGithubRepo" placeholder="e.g. yourusername/_Ykan">
                </div>
                <hr style="margin:16px 0;border:none;border-top:1px solid var(--border)">
                <div class="form-group">
                    <label>Labels</label>
                    <div id="labelsManager"></div>
                    <button type="button" class="btn" onclick="addLabel()" style="margin-top:8px">+ Add Label</button>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" onclick="closeConfigModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Projects Modal (link swimlanes to hosting folders for mobile/MCP editing) -->
    <div id="projectsModal" class="modal-overlay">
        <div class="modal">
            <h2>🔗 Projects</h2>
            <p style="color:var(--text2);font-size:13px;margin-bottom:12px">
                Each swimlane can be linked to a folder on your hosting. Once linked, the
                mobile/MCP endpoint (<code>mcp.php</code>) can read and edit files inside that
                folder — scoped to it, nothing else.
                <strong>Folder</strong> is relative to the MCP root configured in <code>mcp.php</code>
                (e.g. <code>clienteA</code> or <code>sites/shopX</code>).
            </p>
            <div id="projectsManager"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-primary" onclick="closeProjectsModal()">Done</button>
            </div>
        </div>
    </div>

    <!-- Project Docs Modal (tag files/folders the AI should study for a project) -->
    <div id="docsModal" class="modal-overlay">
        <div class="modal" style="max-width:640px">
            <h2 style="display:flex;align-items:center;gap:8px">📄 Documentazione — <span id="docsProjName"></span></h2>
            <p style="color:var(--text2);font-size:13px;margin-bottom:12px">
                Spunta i file e le cartelle che l'AI deve studiare prima di lavorare su questo
                progetto (doc, struttura, note…). La selezione è salvata nello swimlane.
            </p>

            <div id="docsNoFolder" style="display:none">
                <p style="font-size:13px;margin-bottom:8px">Questo progetto non è ancora collegato a una cartella.</p>
                <div class="form-group">
                    <label style="font-size:11px">Cartella (relativa a MCP root)</label>
                    <input type="text" id="docsFolderInput" placeholder="es. becrafty oppure sites/shopX">
                </div>
                <button class="btn btn-primary" onclick="docsSaveFolder()">Collega cartella</button>
            </div>

            <div id="docsBrowserWrap" style="display:none">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;flex-wrap:wrap">
                    <span style="font-size:11px;color:var(--text2)">Cartella:</span>
                    <code style="font-size:11px" id="docsFolderLabel"></code>
                    <span style="flex:1"></span>
                    <span id="docsBreadcrumb" style="font-size:12px"></span>
                </div>
                <div id="docsBrowser" style="border:1px solid var(--border);border-radius:8px;max-height:300px;overflow-y:auto;padding:4px"></div>

                <div style="margin-top:12px">
                    <div style="font-size:12px;font-weight:600;margin-bottom:4px">Assegnati (<span id="docsSelCount">0</span>)</div>
                    <div id="docsSelected" style="display:flex;flex-wrap:wrap;gap:6px"></div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeDocsModal()">Chiudi</button>
                <button type="button" class="btn btn-primary" id="docsSaveBtn" onclick="saveDocs()" style="display:none">Salva</button>
            </div>
        </div>
    </div>

    <!-- Inizializza repository (git init + collegamento opzionale a GitHub, via Bridge) -->
    <div id="gitInitModal" class="modal-overlay">
        <div class="modal" style="max-width:580px">
            <h2>🌱 Inizializza repository — <span id="gitInitName"></span></h2>
            <div style="font-size:12px;margin-bottom:10px"><code id="gitInitDir"></code></div>
            <div class="form-group">
                <label>Collega a un repository GitHub esistente (facoltativo)</label>
                <input type="text" id="gitInitRemote" placeholder="owner/nome-repo">
                <div id="gitInitHint" style="font-size:11px;color:var(--text2);margin-top:3px"></div>
            </div>
            <label style="font-size:13px;display:flex;gap:6px;align-items:center;margin-bottom:10px"><input type="checkbox" id="gitInitIgnore" checked style="width:auto"> Crea un .gitignore con impostazioni sicure (solo se manca)</label>
            <div style="font-size:12px;color:var(--text2);border:1px solid var(--border);border-radius:8px;padding:8px 10px">
                <b style="color:var(--text)">Cosa farà:</b> <code>git init</code> nella cartella. Se indichi un repository GitHub lo collega come <code>origin</code>, scarica il suo stato
                (<code>git fetch</code>) e allinea l'indice: i tuoi file restano com'erano e la scheda Git mostrerà le differenze rispetto a GitHub.<br>
                <b style="color:var(--text)">Cosa NON farà:</b> nessun commit, nessun push, nessuna modifica ai file esistenti.
            </div>
            <div id="gitInitLog" style="font-size:12px;margin-top:10px"></div>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeGitInit()">Chiudi</button>
                <button type="button" class="btn btn-primary" id="gitInitGo" onclick="gitInitRun()">Inizializza</button>
            </div>
        </div>
    </div>

    <!-- Session panel ("Vedi": trascrizione + task della sessione + riprendi / archivia / scomponi) -->
    <div id="sessionModal" class="modal-overlay">
        <div class="modal" style="max-width:920px;width:92vw;max-height:90vh;display:flex;flex-direction:column">
            <div id="sessHead"></div>
            <div id="sessBody" style="overflow-y:auto;flex:1;min-height:180px;border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-top:8px"></div>
            <div class="modal-actions" id="sessActions"></div>
        </div>
    </div>

    <!-- Sessions Modal (local Claude Code session history, read-only via local Bridge) -->
    <div id="sessionsModal" class="modal-overlay">
        <div class="modal" style="max-width:640px">
            <h2 style="display:flex;align-items:center;gap:8px">🕒 Sessioni Claude Code — <span id="sessionsProjName"></span></h2>
            <p style="color:var(--text2);font-size:13px;margin-bottom:12px">
                Storico letto in sola lettura dal Bridge locale sulla cartella collegata a questo
                progetto. Il Bridge gira sul tuo PC, non sull'hosting.
            </p>
            <div id="sessionsList" style="max-height:400px;overflow-y:auto"></div>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeSessionsModal();openTerminalModal(sessionsState.laneId)">🖥️ Apri terminale</button>
                <button type="button" class="btn" onclick="closeSessionsModal()">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- Terminal Modal (live shell via local Bridge, xterm.js) -->
    <div id="terminalModal" class="modal-overlay">
        <div class="modal" style="max-width:820px">
            <h2 style="display:flex;align-items:center;gap:8px">🖥️ Terminale — <span id="terminalProjName"></span></h2>
            <p style="color:var(--text2);font-size:13px;margin-bottom:8px">
                Shell reale sul tuo PC, nella cartella locale del progetto, via Bridge locale.
            </p>
            <div id="terminalContainer" style="height:420px;background:#000;border-radius:8px;overflow:hidden;padding:4px"></div>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeTerminalModal()">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- Themes Modal -->
    <div id="themesModal" class="modal-overlay">
        <div class="modal" style="max-width:620px">
            <h2>🎨 Temi</h2>
            <p style="color:var(--text2);font-size:13px;margin-bottom:12px">
                Light e Dark sono i due temi di default. Puoi aggiungerne altri: incolla o carica
                un file <code>.json</code> di tema (fatto anche da un'altra AI) e switcha al volo dall'header.
            </p>

            <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
                <button class="btn" onclick="copyThemeSpec()">📋 Copia spec per AI</button>
                <button class="btn" onclick="copyCurrentTheme()">📄 Esporta tema attuale</button>
                <label class="btn" style="cursor:pointer">📂 Carica .json
                    <input type="file" accept=".json,application/json" onchange="uploadThemeFile(event)" style="display:none">
                </label>
            </div>

            <div style="font-size:12px;font-weight:600;margin-bottom:6px">Temi installati</div>
            <div id="themesList" style="margin-bottom:16px"></div>

            <div style="font-size:12px;font-weight:600;margin-bottom:6px">Aggiungi tema (incolla JSON)</div>
            <textarea id="themeJsonInput" placeholder='{ "name": "...", "colors": { ... } }'
                style="width:100%;min-height:120px;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:12px;font-family:monospace"></textarea>

            <div class="modal-actions">
                <button type="button" class="btn" onclick="closeThemesModal()">Chiudi</button>
                <button type="button" class="btn btn-primary" onclick="saveThemeFromInput()">Salva tema</button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <script>
    // === STATE ===
    let boardData = <?= $dataJson ?>;
    // true when the server .env provides GITHUB_TOKEN (the value itself never reaches the browser)
    const ykanGithubEnvToken = <?= ykanEnv('GITHUB_TOKEN') !== '' ? 'true' : 'false' ?>;
    let draggedCard = null;
    // Which swimlanes are collapsed, persisted across render() calls so moving/editing
    // a card doesn't reset every project back to "all collapsed".
    let collapsedLanes = new Set(boardData.swimlanes.map(l => l.id));

    // === API ===
    async function api(action, data = {}) {
        try {
            const res = await fetch(`?api=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await res.json();
            const silentActions = ['get_data', 'gemini_analyze', 'claude_execute', 'claude_status', 'list_themes', 'burndown_data', 'loose_ends', 'link_session', 'session_state', 'github_repo_status'];
            if (result.success) {
                if (!silentActions.includes(action)) {
                    toast('Salvato', 'success');
                }
            } else if (!silentActions.includes(action)) {
                toast(result.error || 'Errore', 'error');
            }
            return result;
        } catch (e) {
            toast('Errore: ' + e.message, 'error');
            return { success: false, error: e.message };
        }
    }

    // === TOAST ===
    function toast(msg, type = 'success') {
        const container = document.getElementById('toastContainer');
        const t = document.createElement('div');
        t.className = `toast ${type}`;
        t.textContent = msg;
        container.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    }

    // === RENDER ===
    function render() {
        const board = document.getElementById('board');
        const activeCards = boardData.cards.filter(c => !c.archived);

        board.innerHTML = boardData.swimlanes
            .sort((a, b) => a.position - b.position)
            .map((lane, index) => `
                <div class="swimlane${collapsedLanes.has(lane.id) ? ' collapsed' : ''}" data-lane-id="${lane.id}">
                    <div class="swimlane-header" onclick="toggleSwimlane('${lane.id}', event)">
                        <span class="swimlane-toggle">▼</span>
                        <span class="swimlane-name">${escHtml(lane.name)}</span>
                        ${safeUrl(lane.url) ? `<a class="swimlane-url" href="${escHtml(safeUrl(lane.url))}" target="_blank" rel="noopener noreferrer" title="Open project: ${escHtml(safeUrl(lane.url))}" onclick="event.stopPropagation()" style="text-decoration:none;font-size:13px">🌐</a>` : ''}
                        ${lane.path ? `<span class="swimlane-link" title="Linked to folder: ${escHtml(lane.path)}" onclick="event.stopPropagation();openProjectsModal('${lane.id}')" style="cursor:pointer;font-size:13px">🔗</span>` : ''}
                        ${lane.local_path ? `<span title="Sessioni Claude Code locali" onclick="event.stopPropagation();openSessionsModal('${lane.id}')" style="cursor:pointer;font-size:13px">🕒</span>` : ''}
                        ${lane.local_path ? `<span title="Apri terminale locale" onclick="event.stopPropagation();openTerminalModal('${lane.id}')" style="cursor:pointer;font-size:13px">🖥️</span>` : ''}
                        ${(lane.doc_files && lane.doc_files.length) ? `<span title="${lane.doc_files.length} file di documentazione assegnati" style="font-size:12px;cursor:pointer" onclick="event.stopPropagation();openDocsModal('${lane.id}')">📄${lane.doc_files.length}</span>` : ''}
                        <div class="swimlane-actions" onclick="event.stopPropagation()">
                            <button class="btn btn-icon" onclick="renameSwimlane('${lane.id}')" title="Rinomina">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button class="btn btn-icon" onclick="openDocsModal('${lane.id}')" title="Documentazione del progetto (file per l'AI)">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                            </button>
                            <button class="btn btn-icon" onclick="moveSwimlane('${lane.id}', -1)" title="Sposta su" ${index === 0 ? 'disabled style="opacity:0.3"' : ''}>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg>
                            </button>
                            <button class="btn btn-icon" onclick="moveSwimlane('${lane.id}', 1)" title="Sposta giù" ${index === boardData.swimlanes.length - 1 ? 'disabled style="opacity:0.3"' : ''}>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                            </button>
                            <button class="btn btn-icon btn-danger" onclick="deleteSwimlane('${lane.id}')" title="Elimina">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="column-header-row">
                        ${boardData.columns.sort((a, b) => a.position - b.position).map(col => {
                            const count = activeCards.filter(c => c.column_id === col.id && c.swimlane_id === lane.id).length;
                            return `
                                <div class="column-header" data-col-id="${col.id}">
                                    <input class="column-name" value="${escHtml(col.name)}" onchange="updateColumn('${col.id}', this.value)">
                                    <span class="column-count">${count}</span>
                                    <div class="column-actions">
                                        <button class="btn btn-icon btn-danger" onclick="deleteColumn('${col.id}')" title="Elimina">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                        <button class="add-column-btn" onclick="addColumn()">+</button>
                    </div>
                    <div class="cells-row">
                        ${boardData.columns.sort((a, b) => a.position - b.position).map(col => `
                            <div class="cell" data-col-id="${col.id}" data-lane-id="${lane.id}"
                                ondragover="onDragOver(event)" ondragleave="onDragLeave(event)" ondrop="onDrop(event)">
                                ${activeCards
                                    .filter(c => c.column_id === col.id && c.swimlane_id === lane.id)
                                    .sort((a, b) => a.position - b.position)
                                    .map(card => renderCard(card)).join('')}
                                <button class="add-card-btn" onclick="openCardModal(null, '${col.id}', '${lane.id}')">+ Add Card</button>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `).join('');

        renderArchive();
        refreshEpicFilter();
    }

    function renderCard(card) {
        const label = boardData.labels.find(l => l.id === card.label_id);
        const isOverdue = card.due_date && new Date(card.due_date) < new Date();
        const isRecurring = card.auto_regenerate;
        const regenCount = card.regenerated_count || 0;
        const hasFiles = card.files && card.files.length > 0;
        const runs = card.claude_runs || [];
        const lastRun = runs.length > 0 ? runs[runs.length - 1] : null;
        const runBadge = lastRun
            ? `<span title="Claude: ${lastRun.status} — ${lastRun.date}${lastRun.edits ? ', ' + lastRun.edits + ' file modificati' : ''}" style="font-size:11px;padding:1px 5px;border-radius:8px;background:${lastRun.status === 'completed' ? 'var(--low)' : 'var(--medium)'};color:white;cursor:help">🤖${runs.length > 1 ? '×' + runs.length : ''}</span>`
            : '';
        // PM layer
        const pmEpic = card.epic ? String(card.epic) : '';
        const pmDeps = card.depends_on || [];
        const pmBlockedBy = cardBlockedBy(card);
        const pmAcc = card.acceptance || [];
        const pmAccDone = pmAcc.filter(a => a.done).length;
        const pmBadges = (pmEpic || pmDeps.length || card.parallel || card.estimate)
            ? `<div class="pm-badges">
                ${pmEpic ? `<span class="pm-chip" style="background:${pmEpicColor(pmEpic)}" title="Epic: ${escHtml(pmEpic)}">🎯 ${escHtml(pmEpic)}</span>` : ''}
                ${pmBlockedBy.length
                    ? `<span class="pm-mini pm-lock" title="Bloccata — attende #${pmBlockedBy.join(', #')}">🔒 #${pmBlockedBy.join(' #')}</span>`
                    : (pmDeps.length ? `<span class="pm-mini" title="Dipende da #${pmDeps.join(', #')} — sbloccata">🔓 ${pmDeps.length}</span>` : '')}
                ${card.parallel ? `<span class="pm-mini" title="Eseguibile in parallelo">‖</span>` : ''}
                ${card.estimate ? `<span class="pm-mini" title="Stima sforzo">${escHtml(card.estimate)}</span>` : ''}
               </div>`
            : '';
        const pmAccBar = pmAcc.length
            ? `<div class="pm-acc-wrap" title="Acceptance ${pmAccDone}/${pmAcc.length}"><div class="pm-acc-bar"><i style="width:${Math.round(pmAccDone / pmAcc.length * 100)}%"></i></div></div>`
            : '';
        return `
            <div class="card${pmBlockedBy.length ? ' pm-blocked' : ''}" draggable="true" data-card-id="${card.id}"
                ondragstart="onDragStart(event)" ondragend="onDragEnd(event)"
                onclick="openCardModal('${card.id}')">
                <div class="card-header">
                    <div class="card-priority ${card.priority}"></div>
                    ${card.seq ? `<span class="card-seq" title="ID task — dì «risolvi il task ${card.seq}»">#${card.seq}</span>` : ''}
                    <div class="card-title">${escHtml(card.title)}</div>
                    ${hasFiles ? `<span title="${card.files.length} file associati" style="font-size:12px">📁</span>` : ''}
                    ${isRecurring ? '<span title="Task autorigenerante" style="font-size:12px">🔄</span>' : ''}
                    ${runBadge}
                </div>
                ${label ? `<span class="card-label" style="background:${label.color}">${escHtml(label.name)}</span>` : ''}
                ${pmBadges}
                ${pmAccBar}
                ${card.description ? `<div class="card-desc">${renderMarkdown(card.description)}</div>` : ''}
                <div class="card-meta">
                    ${card.due_date ? `<span class="${isOverdue ? 'card-overdue' : ''}">📅 ${card.due_date}</span>` : ''}
                    ${card.next_check ? `<span>🔔 ${card.next_check}</span>` : ''}
                    ${regenCount > 0 ? `<span title="Rigenerato ${regenCount} volte" style="color:var(--accent)">×${regenCount}</span>` : ''}
                </div>
            </div>
        `;
    }

    function renderArchive() {
        const archived = boardData.cards
            .filter(c => c.archived)
            .sort((a, b) => (b.archived_at || '').localeCompare(a.archived_at || ''));
        document.getElementById('archiveList').innerHTML = archived.length
            ? archived.map(card => `
                <div class="card archive-card">
                    <div class="card-header">
                        <div class="card-priority ${card.priority}"></div>
                        ${card.seq ? `<span class="card-seq" title="ID task">#${card.seq}</span>` : ''}
                        <div class="card-title">${escHtml(card.title)}</div>
                    </div>
                    <div class="card-meta">
                        <span>Archiviata: ${card.archived_at || 'N/A'}</span>
                    </div>
                    <div style="margin-top:8px;display:flex;gap:4px">
                        <button class="btn" onclick="restoreCard('${card.id}')">Ripristina</button>
                        <button class="btn btn-danger" onclick="permanentDeleteCard('${card.id}')">Elimina</button>
                    </div>
                </div>
            `).join('')
            : '<p style="color:var(--text2);text-align:center">Nessuna card archiviata</p>';
    }

    function renderLabelsManager() {
        document.getElementById('labelsManager').innerHTML = boardData.labels.map(l => `
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:4px">
                <input type="color" value="${l.color}" onchange="updateLabelColor('${l.id}', this.value)" style="width:30px;height:24px;border:none;cursor:pointer">
                <input type="text" value="${escHtml(l.name)}" onchange="updateLabelName('${l.id}', this.value)" style="flex:1;padding:4px 8px;border:1px solid var(--border);border-radius:4px;background:var(--bg);color:var(--text)">
                <button type="button" class="btn btn-icon btn-danger" onclick="deleteLabel('${l.id}')">&times;</button>
            </div>
        `).join('');
    }

    // === DRAG & DROP ===
    function onDragStart(e) {
        draggedCard = e.target;
        e.target.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    }

    function onDragEnd(e) {
        e.target.classList.remove('dragging');
        draggedCard = null;
        document.querySelectorAll('.cell').forEach(c => c.classList.remove('drag-over'));
    }

    function onDragOver(e) {
        e.preventDefault();
        e.currentTarget.classList.add('drag-over');
    }

    function onDragLeave(e) {
        e.currentTarget.classList.remove('drag-over');
    }

    async function onDrop(e) {
        e.preventDefault();
        e.currentTarget.classList.remove('drag-over');
        if (!draggedCard) return;

        const cardId = draggedCard.dataset.cardId;
        const newColId = e.currentTarget.dataset.colId;
        const newLaneId = e.currentTarget.dataset.laneId;

        const card = boardData.cards.find(c => c.id === cardId);
        if (card) {
            card.column_id = newColId;
            card.swimlane_id = newLaneId;
            render();
            await api('move_card', { id: cardId, column_id: newColId, swimlane_id: newLaneId });
        }
    }

    // === COLUMNS ===
    async function addColumn() {
        const result = await api('add_column', { name: 'New Column' });
        if (result.success) {
            boardData.columns.push(result.column);
            render();
        }
    }

    async function updateColumn(id, name) {
        const col = boardData.columns.find(c => c.id === id);
        if (col) col.name = name;
        await api('update_column', { id, name });
    }

    async function deleteColumn(id) {
        if (boardData.columns.length <= 1) {
            toast('Cannot delete the last column', 'error');
            return;
        }
        if (!confirm('Delete this column and all its cards?')) return;
        boardData.columns = boardData.columns.filter(c => c.id !== id);
        boardData.cards = boardData.cards.filter(c => c.column_id !== id);
        render();
        await api('delete_column', { id });
    }

    // === SWIMLANES ===
    function toggleSwimlane(laneId, event) {
        if (event.target.tagName === 'INPUT') return;
        const swimlane = document.querySelector(`.swimlane[data-lane-id="${laneId}"]`);
        if (!swimlane) return;
        const nowCollapsed = swimlane.classList.toggle('collapsed');
        if (nowCollapsed) collapsedLanes.add(laneId);
        else collapsedLanes.delete(laneId);
    }

    async function moveSwimlane(laneId, direction) {
        const lanes = boardData.swimlanes.sort((a, b) => a.position - b.position);
        const currentIndex = lanes.findIndex(l => l.id === laneId);
        const newIndex = currentIndex + direction;

        if (newIndex < 0 || newIndex >= lanes.length) return;

        // Swap positions
        const temp = lanes[currentIndex].position;
        lanes[currentIndex].position = lanes[newIndex].position;
        lanes[newIndex].position = temp;

        render();
        await api('reorder_swimlanes', { order: lanes.sort((a, b) => a.position - b.position).map(l => l.id) });
    }

    async function addSwimlane() {
        const result = await api('add_swimlane', { name: 'New Swimlane' });
        if (result.success) {
            boardData.swimlanes.push(result.swimlane);
            render();
        }
    }

    async function updateSwimlane(id, name) {
        const lane = boardData.swimlanes.find(l => l.id === id);
        if (lane) lane.name = name;
        await api('update_swimlane', { id, name });
    }

    async function renameSwimlane(id) {
        const lane = boardData.swimlanes.find(l => l.id === id);
        if (!lane) return;
        const name = prompt('Nome swimlane:', lane.name);
        if (name === null) return;
        const trimmed = name.trim();
        if (!trimmed || trimmed === lane.name) return;
        await updateSwimlane(id, trimmed);
        render();
    }

    async function deleteSwimlane(id) {
        if (boardData.swimlanes.length <= 1) {
            toast('Cannot delete the last swimlane', 'error');
            return;
        }
        if (!confirm('Delete this swimlane? Cards will be moved.')) return;
        const firstLane = boardData.swimlanes[0].id;
        boardData.cards.forEach(c => { if (c.swimlane_id === id) c.swimlane_id = firstLane; });
        boardData.swimlanes = boardData.swimlanes.filter(l => l.id !== id);
        collapsedLanes.delete(id);
        render();
        await api('delete_swimlane', { id });
    }

    // === PROJECTS (swimlane <-> hosting folder links, used by mcp.php) ===
    // When a laneId is given (e.g. the 🔗 in a swimlane header) the modal is
    // scoped to that single project; with no arg (toolbar button) it shows all.
    let projectsFilterLaneId = null;
    function openProjectsModal(laneId) {
        projectsFilterLaneId = laneId || null;
        renderProjectsManager();
        document.getElementById('projectsModal').classList.add('active');
    }

    function closeProjectsModal() {
        document.getElementById('projectsModal').classList.remove('active');
    }

    function renderProjectsManager() {
        const container = document.getElementById('projectsManager');
        let lanes = [...boardData.swimlanes].sort((a, b) => a.position - b.position);
        if (projectsFilterLaneId) lanes = lanes.filter(l => l.id === projectsFilterLaneId);
        container.innerHTML = lanes.map(lane => `
            <div class="project-row" style="border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px">
                <div style="font-weight:600;margin-bottom:6px">${escHtml(lane.name)} ${lane.path ? '🔗' : ''}</div>
                <div class="form-group" style="margin-bottom:6px">
                    <label style="font-size:11px">Folder (relative to MCP root)</label>
                    <input type="text" id="proj-path-${lane.id}" value="${escHtml(lane.path || '')}" placeholder="e.g. clienteA">
                </div>
                <div class="form-group" style="margin-bottom:6px">
                    <label style="font-size:11px">Public URL (optional)</label>
                    <input type="text" id="proj-url-${lane.id}" value="${escHtml(lane.url || '')}" placeholder="https://...">
                </div>
                <div class="form-group" style="margin-bottom:6px">
                    <label style="font-size:11px">Local folder (on this machine, e.g. for the local Bridge)</label>
                    <input type="text" id="proj-local-${lane.id}" value="${escHtml(lane.local_path || '')}" placeholder="e.g. C:\\Script locali\\clienteA">
                </div>
                <button class="btn btn-primary" onclick="saveProjectLink('${lane.id}')">Save link</button>
            </div>
        `).join('');
    }

    async function saveProjectLink(id) {
        const path = document.getElementById('proj-path-' + id).value.trim();
        const url = document.getElementById('proj-url-' + id).value.trim();
        const local_path = document.getElementById('proj-local-' + id).value.trim();
        const lane = boardData.swimlanes.find(l => l.id === id);
        if (lane) { lane.path = path; lane.url = url; lane.local_path = local_path; }
        await api('update_swimlane', { id, path, url, local_path });
        renderProjectsManager();
        render();
    }

    // === DASHBOARD (schede: Da riprendere / Ultimi 7 giorni / Revisione sessioni) ===
    const DASH_KINDS = {
        stale_doing: ['⏳', 'In corso ma ferma'], blocked: ['⛔', 'Bloccata'], overdue: ['📅', 'Scaduta'],
        acceptance_partial: ['☑️', 'Criteri incompleti'], epic_partial: ['🧩', 'Epic a metà'],
        high_not_started: ['🔥', 'Alta priorità mai iniziata'], claude_do_waiting: ['🤖', 'In coda per Claude'],
        check_due: ['🔔', 'Controllo da fare'],
        session_question: ['💬', 'Sessione in attesa di tua risposta'],
        session_unanswered: ['✋', 'Sessione interrotta'],
        session_open_task: ['🧵', 'Sessione su task ancora aperto']
    };
    const DASH_DONE_RE = /done|fatto|chius|completat/i;
    const DASH_DOING_RE = /progress|doing|corso|lavor/i;
    let dashData = null;
    let dashTabName = 'resume';
    try { dashTabName = localStorage.getItem('ykan_dash_tab') || 'resume'; } catch (_) {}
    // Filtro comune a tutte le schede: progetto + periodo
    let dashProject = '', dashDays = 14, dashStaleDays = 3;
    try {
        const f = JSON.parse(localStorage.getItem('ykan_dash_filters') || '{}');
        if (typeof f.project === 'string') dashProject = f.project;
        if ([7, 14, 30, 90].includes(f.days)) dashDays = f.days;
        if ([1, 3, 7, 14].includes(f.stale)) dashStaleDays = f.stale;
    } catch (_) {}
    const dashSel = new Set();

    function dashSaveFilters() {
        try { localStorage.setItem('ykan_dash_filters', JSON.stringify({ project: dashProject, days: dashDays, stale: dashStaleDays })); } catch (_) {}
    }
    function dashSyncFilterUi() {
        const sel = document.getElementById('dashProject');
        const names = boardData.swimlanes.slice().sort((a, b) => a.position - b.position).map(l => l.name);
        if (dashProject && !names.includes(dashProject)) dashProject = '';
        sel.innerHTML = '<option value="">Tutti i progetti</option>' + names.map(n => `<option value="${escHtml(n)}" ${n === dashProject ? 'selected' : ''}>${escHtml(n)}</option>`).join('');
        document.getElementById('dashDays').value = String(dashDays);
        document.getElementById('dashDesk').checked = deskArchiveOn;
    }
    function dashSetFilter() {
        dashProject = document.getElementById('dashProject').value;
        dashDays = parseInt(document.getElementById('dashDays').value, 10);
        dashSaveFilters();
        loadDashboard();
    }
    const dashInProject = name => !dashProject || name === dashProject;

    // Opzione: quando concludi/riapri una sessione, fallo anche nell'archivio di Claude Desktop (via Bridge)
    let deskArchiveOn = true; // acceso di default; si spegne dalla casella in alto e la scelta resta memorizzata
    try { deskArchiveOn = localStorage.getItem('ykan_desk_archive') !== '0'; } catch (_) {}
    function deskSetting(on) { deskArchiveOn = on; try { localStorage.setItem('ykan_desk_archive', on ? '1' : '0'); } catch (_) {} }
    let deskHintShown = false;
    async function deskArchive(ids, archived) {
        if (!ids.length) return;
        if (!deskArchiveOn) {
            if (!deskHintShown) { deskHintShown = true; toast('Claude Desktop non aggiornato: per archiviarla anche lì attiva "archivia anche in Claude Desktop" in alto', 'success'); }
            return;
        }
        try {
            const r = await fetch(BRIDGE_URL + '/desktop-archive', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids, archived })
            });
            const j = await r.json();
            if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
            const verb = archived ? 'archiviate' : 'riaperte';
            toast(`Claude Desktop: ${j.changed} ${verb}` + (j.missing && j.missing.length ? `, ${j.missing.length} non trovate` : '') + (j.errors && j.errors.length ? `, ${j.errors.length} errori` : ''), j.errors && j.errors.length ? 'error' : 'success');
        } catch (e) {
            toast('Claude Desktop: non aggiornato (' + e.message + ')', 'error');
        }
    }

    function showView(view) {
        if (view !== 'dashboard') view = 'kanban';
        document.body.dataset.view = view;
        document.getElementById('tabKanban').classList.toggle('active', view === 'kanban');
        document.getElementById('tabDash').classList.toggle('active', view === 'dashboard');
        try { localStorage.setItem('ykan_view', view); } catch (_) {}
        if (view === 'dashboard') loadDashboard();
    }

    const dashTs = v => { const t = new Date(String(v).replace(' ', 'T')).getTime(); return isNaN(t) ? 0 : t; };
    function dashAgo(ts) {
        const d = Math.floor((Date.now() - ts) / 86400000);
        return d <= 0 ? 'oggi' : d === 1 ? 'ieri' : d + ' giorni fa';
    }
    function dashColor(name) { let h = 0; for (const ch of String(name)) h = (h * 31 + ch.charCodeAt(0)) % 360; return `hsl(${h} 55% 48%)`; }
    function dashCardDone(c) {
        const col = boardData.columns.find(x => x.id === c.column_id);
        return !!c.archived || DASH_DONE_RE.test(col ? col.name : '');
    }

    async function fetchAllSessions() {
        const out = { ok: false, anyLane: false, byLane: {} };
        const lanes = boardData.swimlanes.filter(l => l.local_path);
        out.anyLane = lanes.length > 0;
        await Promise.all(lanes.map(async l => {
            try {
                const r = await fetch(BRIDGE_URL + '/sessions?dir=' + encodeURIComponent(l.local_path));
                if (!r.ok) throw new Error('HTTP ' + r.status);
                out.byLane[l.id] = (await r.json()).sessions || [];
                out.byLane[l.id].forEach(s => sessionIndex.set(s.id, { laneId: l.id, s }));
                out.ok = true;
            } catch (_) { /* bridge spento: si mostrano solo i task */ }
        }));
        return out;
    }

    // Sessions -> loose ends + task links. A session is linked to a task either explicitly
    // (PLAY stored its id on the card) or because the Bridge saw the task number in it.
    function dashSessions(sess) {
        const colName = Object.fromEntries(boardData.columns.map(c => [c.id, c.name]));
        const bySeq = new Map(boardData.cards.filter(c => c.seq).map(c => [c.seq, c]));
        const explicit = new Map();
        boardData.cards.forEach(c => (c.sessions || []).forEach(s => {
            if (!explicit.has(s.id)) explicit.set(s.id, new Set());
            explicit.get(s.id).add(c.seq);
        }));
        const all = [], items = [];
        const now = Date.now();
        for (const lane of boardData.swimlanes) {
            for (const s of (sess.byLane[lane.id] || [])) {
                const expSeqs = explicit.get(s.id) || new Set();
                const seqs = new Set([...(s.tasks || []), ...expSeqs]);
                const cards = [...seqs].map(q => bySeq.get(q)).filter(Boolean);
                const ageDays = Math.floor((now - new Date(s.modified).getTime()) / 86400000);
                all.push({ s, lane, cards, ageDays });
                if (s.auto || s.isArchived || ageDays > dashDays || sessState(s.id)) continue;

                const label = s.title || s.preview || '(senza titolo)';
                const base = { project: lane.name, laneId: lane.id, sessionId: s.id, title: label, since: s.modified };
                if (s.endsWithQuestion) {
                    items.push({ ...base, kind: 'session_question', severity: 'medium', column: 'sessione',
                        detail: 'Claude ha chiesto: «' + (s.lastSnippet || '').slice(-140).replace(/\s+/g, ' ') + '»' });
                } else if (s.lastRole === 'user' && s.turns > 0) {
                    items.push({ ...base, kind: 'session_unanswered', severity: 'medium', column: 'sessione',
                        detail: 'L\'ultimo messaggio è tuo, senza risposta' });
                }
                if (ageDays >= 1) {
                    for (const c of cards) {
                        const doing = DASH_DOING_RE.test(colName[c.column_id] || '');
                        if (!dashCardDone(c) && (expSeqs.has(c.seq) || doing)) {
                            items.push({ ...base, kind: 'session_open_task', severity: 'medium', column: colName[c.column_id] || '',
                                detail: 'Ha lavorato su #' + c.seq + ' «' + c.title + '», ancora in "' + (colName[c.column_id] || '?') + '"' });
                        }
                    }
                }
            }
        }
        return { all, items };
    }

    async function loadDashboard() {
        const body = document.getElementById('dashboardBody');
        body.innerHTML = '<div class="dash-empty">Carico…</div>';
        dashSyncFilterUi();
        dashGit = null;
        const [res, sess, reviews] = await Promise.all([
            api('loose_ends', { stale_days: dashStaleDays, recent_days: dashDays }), fetchAllSessions(), fetchReviews()]);
        if (!res.success) { body.innerHTML = '<div class="dash-empty">Errore nel caricamento.</div>'; return; }

        const bridge = document.getElementById('dashBridge');
        if (!sess.anyLane) {
            bridge.className = 'dash-bridge'; bridge.textContent = 'sessioni: nessuna cartella locale collegata';
        } else if (sess.ok) {
            bridge.className = 'dash-bridge ok'; bridge.textContent = '● Bridge connesso';
        } else {
            bridge.className = 'dash-bridge ko'; bridge.textContent = '● Bridge non raggiungibile (solo task)';
        }
        dashData = { res, sd: dashSessions(sess), reviews };
        dashRender();
    }

    // Giudizi di Claude sulle sessioni (dal Bridge): validi solo se la sessione non è cambiata dopo la revisione
    async function fetchReviews() {
        try {
            const r = await fetch(BRIDGE_URL + '/reviews');
            if (!r.ok) return {};
            return (await r.json()).sessions || {};
        } catch (_) { return {}; }
    }
    function dashReviewOf(r) {
        const v = dashData && dashData.reviews && dashData.reviews[r.s.id];
        return v && v.modified === r.s.modified ? v : null;
    }

    function dashTab(name) {
        dashTabName = name;
        try { localStorage.setItem('ykan_dash_tab', name); } catch (_) {}
        dashRender();
    }

    function dashOpenCard(id) { openCardModal(id); }
    function dashChipClass(c) {
        if (dashCardDone(c)) return 'st-done';
        const col = boardData.columns.find(x => x.id === c.column_id);
        return /claude do|progress|doing|corso|lavor/i.test(col ? col.name : '') ? 'st-doing' : 'st-todo';
    }
    function dashChips(cards) {
        return cards.map(c => `<span class="dash-chip ${dashChipClass(c)}" title="${escHtml(c.title)}" onclick="event.stopPropagation();dashOpenCard('${escHtml(c.id)}')">#${c.seq}</span>`).join('');
    }

    // Last thing done per project: newest of card changes and session activity
    function dashProjectActivity(sd) {
        const act = {};
        const bump = (name, ts, what) => { if (ts && (!act[name] || ts > act[name].ts)) act[name] = { ts, what }; };
        const laneName = Object.fromEntries(boardData.swimlanes.map(l => [l.id, l.name]));
        boardData.cards.forEach(c => {
            const ts = Math.max(dashTs(c.updated_at || 0), dashTs(c.moved_at || 0), dashTs(c.archived_at || 0), dashTs(c.created_at || 0));
            bump(laneName[c.swimlane_id], ts, (c.seq ? '#' + c.seq + ' ' : '') + c.title);
        });
        sd.all.filter(r => !r.s.auto).forEach(r => bump(r.lane.name, new Date(r.s.modified).getTime(), 'sessione «' + (r.s.title || r.s.preview || 'senza titolo') + '»'));
        return act;
    }

    function dashRender() {
        if (!dashData) return;
        const { res, sd } = dashData;
        const counts = {
            resume: [...res.items, ...sd.items].filter(i => dashInProject(i.project)).length,
            week: dashWeekEvents(res, sd).length,
            review: dashReviewRows(sd).length,
            git: dashGit ? dashGitRows().filter(g => g.info.repo && (g.info.changed || g.info.untracked || g.info.ahead)).length : '…'
        };
        document.querySelectorAll('#dashTabs button').forEach(b => {
            b.classList.toggle('active', b.dataset.tab === dashTabName);
            b.querySelector('.dash-count').textContent = counts[b.dataset.tab];
        });
        const body = document.getElementById('dashboardBody');
        if (dashTabName === 'week') body.innerHTML = dashWeekHtml(res, sd);
        else if (dashTabName === 'review') body.innerHTML = dashReviewHtml(sd);
        else if (dashTabName === 'git') { body.innerHTML = dashGitHtml(); if (!dashGit) dashGitLoad(); }
        else body.innerHTML = dashResumeHtml(res, sd);
    }

    // ---- Tab "Da riprendere": progetti per ultima attività, poi le cose aperte ----
    function dashResumeHtml(res, sd) {
        const rank = { high: 0, medium: 1, low: 2 };
        const items = [...res.items, ...sd.items].filter(i => dashInProject(i.project));
        const byProject = {};
        items.forEach(i => (byProject[i.project] = byProject[i.project] || []).push(i));
        const act = dashProjectActivity(sd);
        const recentMs = dashDays * 86400000;
        const projects = boardData.swimlanes
            .filter(l => dashInProject(l.name))
            .map(l => ({ lane: l, items: byProject[l.name] || [], act: act[l.name] }))
            .filter(p => p.items.length || (p.act && Date.now() - p.act.ts < recentMs))
            .sort((a, b) => (b.act ? b.act.ts : 0) - (a.act ? a.act.ts : 0));
        const staleOpt = v => `<option value="${v}" ${dashStaleDays === v ? 'selected' : ''}>${v}</option>`;
        const head = `<div class="dash-hint">Progetti ordinati per ultima attività. Segnalo i task fermi da
            <select onchange="dashStaleDays=parseInt(this.value,10);dashSaveFilters();loadDashboard()" style="width:auto;padding:1px 3px">${[1, 3, 7, 14].map(staleOpt).join('')}</select> giorni.</div>`;
        if (!projects.length) return head + '<div class="dash-empty">Niente lasciato in sospeso e nessuna attività recente. 🎉</div>';

        return head + projects.map(p => {
            const list = [...p.items].sort((a, b) => rank[a.severity] - rank[b.severity] || dashTs(b.since) - dashTs(a.since));
            return `<div class="dash-projcard">
                <div class="dash-projhead">
                    <span class="wk-dot" style="background:${dashColor(p.lane.name)}"></span>
                    <b>${escHtml(p.lane.name)}</b>
                    ${list.length ? `<span class="dash-count">${list.length}</span>` : '<span class="dash-ok">✓ niente in sospeso</span>'}
                    <span style="flex:1"></span>
                    ${p.act ? `<span class="dash-when" title="${escHtml(p.act.what)}">ultima attività ${dashAgo(p.act.ts)} · ${escHtml(p.act.what.slice(0, 50))}${p.act.what.length > 50 ? '…' : ''}</span>` : ''}
                </div>
                ${list.map(dashItemHtml).join('')}
            </div>`;
        }).join('');
    }

    function dashItemHtml(i) {
        const [icon, label] = DASH_KINDS[i.kind] || ['•', i.kind];
        const isSession = !!i.sessionId;
        const click = isSession ? '' : ` onclick="dashOpenCard('${escHtml(i.id)}')"`;
        return `<div class="dash-item ${i.severity === 'high' ? 'high' : ''} ${isSession ? '' : 'clickable'}"${click}>
            <div class="dash-row">
                <span title="${escHtml(label)}">${icon}</span>
                <span style="font-size:13px;font-weight:500">${i.seq ? '#' + i.seq + ' ' : ''}${escHtml(i.title)}</span>
                <span style="flex:1"></span>
                <span style="font-size:11px;color:var(--text2);white-space:nowrap">${escHtml(i.column)}</span>
                ${isSession ? `<button class="btn" style="padding:2px 8px;font-size:11px" onclick="openSessionPanel('${escHtml(i.laneId)}','${escHtml(i.sessionId)}')">Vedi</button>` : ''}
            </div>
            <div class="dash-sub">${escHtml(label)} — ${escHtml(i.detail)}</div>
        </div>`;
    }

    // ---- Tab "Attività": timeline per giorno e progetto ----
    function dashWeekEvents(res, sd) {
        const cut = Date.now() - dashDays * 86400000;
        const bySeq = new Map(boardData.cards.filter(c => c.seq).map(c => [c.seq, c]));
        const events = res.recent.map(e => ({ ts: dashTs(e.when), project: e.project, type: e.type, seq: e.seq, title: e.title, card: bySeq.get(e.seq) }));
        sd.all.filter(r => !r.s.auto && new Date(r.s.modified).getTime() >= cut).forEach(r => events.push({
            ts: new Date(r.s.modified).getTime(), project: r.lane.name, type: 'session', title: r.s.title || r.s.preview || 'senza titolo',
            r, concluded: !!sessState(r.s.id)
        }));
        return events.filter(e => e.ts && dashInProject(e.project)).sort((a, b) => b.ts - a.ts);
    }

    function dashWeekHtml(res, sd) {
        const events = dashWeekEvents(res, sd);
        if (!events.length) return '<div class="dash-empty">Nessuna attività nel periodo selezionato.</div>';
        const dayKey = ts => { const d = new Date(ts); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
        const byDay = {};
        events.forEach(e => (byDay[dayKey(e.ts)] = byDay[dayKey(e.ts)] || []).push(e));

        const tiles = [];
        for (let i = Math.min(dashDays, 14) - 1; i >= 0; i--) {
            const d = new Date(Date.now() - i * 86400000);
            tiles.push({ d, n: (byDay[dayKey(d.getTime())] || []).length });
        }
        const max = Math.max(1, ...tiles.map(t => t.n));
        const stat = t => events.filter(e => e.type === t).length;
        const summary = `<div class="wk-summary">
            <div class="wk-tiles">${tiles.map(t => `<div class="wk-tile" title="${t.n} eventi">
                <div class="wk-bar"><i style="height:${Math.round(t.n / max * 100)}%"></i></div>
                <span>${escHtml(t.d.toLocaleDateString('it-IT', { weekday: 'short' }))}</span><b>${t.d.getDate()}</b></div>`).join('')}</div>
            <div class="wk-stats">
                <div><b>${stat('session')}</b><span>sessioni</span></div>
                <div><b>${stat('done')}</b><span>task chiusi</span></div>
                <div><b>${stat('created')}</b><span>task creati</span></div>
                <div><b>${stat('moved')}</b><span>spostamenti</span></div>
            </div></div>`;

        const ICON = { session: '🕒', created: '➕', moved: '➡️', done: '✅' };
        const VERB = { session: 'Sessione', created: 'Creato', moved: 'Spostato', done: 'Chiuso' };
        const days = Object.keys(byDay).sort().reverse().map(k => {
            const d = new Date(k + 'T12:00:00');
            const byProj = {};
            byDay[k].forEach(e => (byProj[e.project] = byProj[e.project] || []).push(e));
            return `<div class="wk-day">
                <div class="wk-date"><b>${d.getDate()}</b><span>${escHtml(d.toLocaleDateString('it-IT', { weekday: 'short', month: 'short' }))}</span></div>
                <div class="wk-body">${Object.entries(byProj).map(([proj, evs]) => `
                    <div class="wk-proj" style="border-left-color:${dashColor(proj)}">
                        <div class="wk-projname">${escHtml(proj)} <span>${evs.length}</span></div>
                        ${evs.map(e => {
                            const time = new Date(e.ts).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
                            let txt, act = '';
                            if (e.type === 'session') {
                                txt = `«${escHtml(e.title)}» ${dashChips(e.r.cards)} ${e.concluded ? '<span class="sess-badge done">✔ conclusa</span>' : ''}`;
                                act = `<a href="#" onclick="openSessionPanel('${escHtml(e.r.lane.id)}','${escHtml(e.r.s.id)}');return false">vedi</a>`;
                            } else {
                                txt = `${e.seq ? '<span class="wk-seq">#' + e.seq + '</span> ' : ''}${escHtml(e.title)}`;
                                if (e.card) act = `<a href="#" onclick="dashOpenCard('${escHtml(e.card.id)}');return false">apri</a>`;
                            }
                            return `<div class="wk-ev"><span class="wk-time">${time}</span><span class="wk-ic" title="${VERB[e.type]}">${ICON[e.type]}</span><span class="wk-txt">${txt}</span><span class="wk-act">${act}</span></div>`;
                        }).join('')}
                    </div>`).join('')}
                </div>
            </div>`;
        }).join('');
        return summary + days;
    }

    // ---- Tab "Revisione sessioni": passa in rassegna le sessioni non concluse ----
    function dashReviewRows(sd) {
        return sd.all.filter(r => !r.s.auto && !r.s.isArchived && !sessState(r.s.id) && r.ageDays <= dashDays && dashInProject(r.lane.name))
            .sort((a, b) => new Date(b.s.modified) - new Date(a.s.modified));
    }

    function dashReviewHint(r) {
        const colName = Object.fromEntries(boardData.columns.map(c => [c.id, c.name]));
        const open = r.cards.filter(c => !dashCardDone(c) && DASH_DOING_RE.test(colName[c.column_id] || ''));
        if (r.s.endsWithQuestion) return { icon: '💬', text: 'attende una tua risposta', likelyDone: false };
        if (r.s.lastRole === 'user' && r.s.turns > 0) return { icon: '✋', text: 'ultimo messaggio tuo, senza risposta', likelyDone: false };
        if (open.length) return { icon: '🧵', text: open.length + ' task ancora in corso', likelyDone: false };
        return { icon: '✔️', text: 'sembra conclusa', likelyDone: true };
    }

    const DASH_VERDICT = { conclude: ['✅', 'Concludi'], resume: ['▶️', 'Da riprendere'], split: ['🧩', 'Scomponi'] };

    let dashRevFilter = 'all';
    function dashSetRevFilter(f) { dashRevFilter = f; dashRender(); }

    function dashReviewHtml(sd) {
        const all = dashReviewRows(sd).map(r => ({ r, v: dashReviewOf(r) }));
        const kindOf = x => x.v ? x.v.verdict : 'none';
        const n = { conclude: 0, resume: 0, split: 0, none: 0, tasks: 0 };
        all.forEach(x => { n[kindOf(x)]++; if (x.v) n.tasks += (x.v.tasks || []).length; });
        const applicable = n.conclude + n.split;
        const shown = all.filter(x => dashRevFilter === 'all' || kindOf(x) === dashRevFilter);
        const fbtn = (key, label, count) => `<button class="${dashRevFilter === key ? 'active' : ''}" onclick="dashSetRevFilter('${key}')">${label} ${count}</button>`;

        const tool = `<div class="dash-revbar">
            <button class="btn btn-primary" ${applicable ? '' : 'disabled'} onclick="dashApplyAllReviews()" title="Concludi le sessioni e aggiungi al Kanban i task che Claude ha individuato">🤖 Applica i giudizi di Claude (${n.conclude} concluse, ${n.split} scomposte in ${n.tasks} task)</button>
            <button class="btn" onclick="dashReviewSelectLikely()">Seleziona quelle che sembrano concluse</button>
            <button class="btn" onclick="dashSel.clear();dashRender()">Deseleziona</button>
            <span style="flex:1"></span>
            <button class="btn" ${dashSel.size ? '' : 'disabled'} onclick="dashBulkConclude()">✅ Archivia ${dashSel.size || ''} selezionate come concluse</button>
        </div>
        <div class="rv-filters">
            ${fbtn('all', 'Tutte', all.length)}${fbtn('conclude', '✅ Da concludere', n.conclude)}${fbtn('split', '🧩 Da scomporre', n.split)}${fbtn('resume', '▶️ Da riprendere', n.resume)}${fbtn('none', 'Senza giudizio', n.none)}
        </div>`;
        if (!shown.length) return tool + '<div class="dash-empty">Nessuna sessione con questo filtro nel periodo selezionato. 🎉</div>';

        const groups = [];
        shown.forEach(x => {
            let g = groups.find(q => q.lane.id === x.r.lane.id);
            if (!g) { g = { lane: x.r.lane, items: [] }; groups.push(g); }
            g.items.push(x);
        });
        return tool + groups.map(g => {
            const c = { conclude: 0, split: 0, resume: 0 };
            g.items.forEach(x => { if (x.v) c[x.v.verdict]++; });
            const sum = [c.conclude && c.conclude + ' da concludere', c.split && c.split + ' da scomporre', c.resume && c.resume + ' da riprendere'].filter(Boolean).join(' · ');
            return `<div class="rv-group">
                <div class="rv-grouphead"><span class="wk-dot" style="background:${dashColor(g.lane.name)}"></span><b>${escHtml(g.lane.name)}</b>
                    <span class="dash-count">${g.items.length}</span><span class="rv-sum">${escHtml(sum)}</span></div>
                ${g.items.map(({ r, v }) => {
                    const h = dashReviewHint(r);
                    const kind = v ? v.verdict : 'none';
                    const pill = v
                        ? { conclude: '✅ Claude: concludi', resume: '▶️ Claude: da riprendere', split: '🧩 Claude: scomponi in ' + (v.tasks || []).length + ' task' }[v.verdict]
                        : 'Nessun giudizio';
                    const why = v ? v.reason : h.icon + ' ' + h.text;
                    const act = v && v.verdict === 'split'
                        ? `<button class="btn" style="padding:2px 8px;font-size:11px" onclick="dashApplyOne('${escHtml(r.s.id)}')">🧩 Applica</button>`
                        : v && v.verdict === 'resume' ? ''
                        : `<button class="btn" style="padding:2px 8px;font-size:11px" onclick="dashConcludeOne('${escHtml(r.s.id)}')">✅ Concludi</button>`;
                    return `<div class="rv-row v-${kind}">
                        <input type="checkbox" ${dashSel.has(r.s.id) ? 'checked' : ''} onchange="dashToggleSel('${escHtml(r.s.id)}',this.checked)" style="width:auto;margin-top:3px">
                        <div style="flex:1;min-width:0">
                            <div class="rv-title">${escHtml(r.s.title || r.s.preview || '(senza titolo)')} ${dashChips(r.cards)}</div>
                            <div class="rv-meta">${escHtml(new Date(r.s.modified).toLocaleDateString('it-IT'))} · ${r.s.turns || 0} tuoi messaggi</div>
                            <div class="rv-decision"><span class="rv-pill ${kind}">${pill}</span><span>${escHtml(why)}</span></div>
                            ${v && v.verdict === 'split' ? `<div class="rv-tasks">${v.tasks.map(t => '➕ ' + escHtml(t.title)).join('<br>')}</div>` : ''}
                        </div>
                        <div class="rv-actions">
                            <button class="btn" style="padding:2px 8px;font-size:11px" onclick="openSessionPanel('${escHtml(r.lane.id)}','${escHtml(r.s.id)}')">Vedi</button>${act}
                        </div>
                    </div>`;
                }).join('')}
            </div>`;
        }).join('');
    }

    // ---- Tab "Git": stato dei repository dei progetti (via Bridge) + stato su GitHub (via token della board) ----
    let dashGit = null; // { byLane, ok, anyLane, gh, hasToken }

    // Quale repository GitHub corrisponde al progetto: origin locale, collegamento salvato, oppure suggerimento dal README
    function dashGitRepoOf(lane, info) {
        if (info && info.repo && info.remote && info.remote.repo) return info.remote.repo;
        return lane.github_repo || (info && info.suggestedRemote) || '';
    }

    async function dashGitLoad() {
        const lanes = boardData.swimlanes.filter(l => l.local_path);
        const byLane = {};
        let ok = false;
        await Promise.all(lanes.map(async l => {
            try {
                const r = await fetch(BRIDGE_URL + '/git?dir=' + encodeURIComponent(l.local_path));
                if (r.ok) { byLane[l.id] = await r.json(); ok = true; }
            } catch (_) { /* bridge spento */ }
        }));
        const repos = [...new Set(boardData.swimlanes.map(l => dashGitRepoOf(l, byLane[l.id])).filter(Boolean))];
        let gh = {}, hasToken = false;
        if (repos.length) {
            const r = await api('github_repo_status', { repos });
            if (r.success) { gh = r.repos || {}; hasToken = !!r.has_token; }
        }
        dashGit = { byLane, ok, anyLane: lanes.length > 0, gh, hasToken, asked: repos.length > 0 };
        dashRender();
    }

    function dashGitRows() {
        return boardData.swimlanes.filter(l => dashInProject(l.name)).map(l => ({
            lane: l, info: !l.local_path ? { noLocal: true } : (dashGit && dashGit.byLane[l.id]) || null
        })).filter(x => x.info);
    }

    function dashGhTag(repo) {
        const g = dashGit.gh[repo];
        if (!g) return `<span class="git-tag">GitHub: ${escHtml(repo)}</span>`;
        if (!g.ok) return `<span class="git-tag" title="${escHtml(g.error)}">GitHub: ${escHtml(repo)} · ${escHtml(g.error)}</span>`;
        const push = g.pushed_at ? 'ultimo push ' + dashAgo(new Date(g.pushed_at).getTime()) : 'nessun push';
        return `<a class="git-tag" href="${escHtml(g.url)}" target="_blank" rel="noopener noreferrer" title="Apri su GitHub">GitHub${g.private ? ' 🔒' : ''}: ${escHtml(repo)} · ${push}${g.open_issues ? ' · ' + g.open_issues + ' issue/PR aperte' : ''}</a>`;
    }

    function dashGitHtml() {
        if (!dashGit) return '<div class="dash-empty">Leggo i repository…</div>';
        if (dashGit.anyLane && !dashGit.ok) return '<div class="dash-empty">Bridge locale non raggiungibile: avvialo per vedere lo stato dei repository.</div>';
        const rows = dashGitRows();
        const repos = rows.filter(x => x.info.repo);
        const dirty = repos.filter(x => x.info.changed || x.info.untracked).length;
        const toPush = repos.filter(x => x.info.ahead).length;
        const noRepo = rows.filter(x => !x.info.repo).length;
        const ts = x => x.info.last ? new Date(x.info.last.date).getTime() : 0;
        rows.sort((a, b) => (b.info.repo ? 1 : 0) - (a.info.repo ? 1 : 0) || ts(b) - ts(a));

        const banner = (dashGit.asked && !dashGit.hasToken)
            ? `<div class="dash-hint" style="padding:8px 12px;border:1px dashed var(--border);border-radius:8px">Il token GitHub non è impostato: vedo solo i repository pubblici. Inseriscilo tu in
                <a href="#" onclick="openConfigModal();return false">⚙️ Settings → GitHub Token</a> per vedere anche quelli privati.</div>` : '';
        const summary = `<div class="git-summary">
            <div><b>${repos.length}</b><span>con repository</span></div>
            <div><b>${dirty}</b><span>con modifiche da committare</span></div>
            <div><b>${toPush}</b><span>con commit da pushare</span></div>
            <div><b>${noRepo}</b><span>senza repository</span></div></div>`;

        return banner + summary + rows.map(({ lane, info }) => {
            const head = `<span class="wk-dot" style="background:${dashColor(lane.name)}"></span>${escHtml(lane.name)}`;
            const ghRepo = dashGitRepoOf(lane, info.noLocal ? null : info);
            if (info.noLocal) return `<div class="git-row norepo"><div class="git-main"><div class="git-name">${head} ${ghRepo ? dashGhTag(ghRepo) : ''}</div><div class="git-last">Nessuna cartella locale collegata (🔗 Projects)</div></div></div>`;
            if (info.gitMissing) return `<div class="git-row norepo"><div class="git-main"><div class="git-name">${head}</div><div class="git-last">Git non trovato sul PC</div></div></div>`;
            if (!info.exists) return `<div class="git-row norepo"><div class="git-main"><div class="git-name">${head}</div><div class="git-last">La cartella locale non esiste</div></div></div>`;
            if (!info.repo) {
                const known = ghRepo && dashGit.gh[ghRepo] && dashGit.gh[ghRepo].ok;
                return `<div class="git-row norepo" style="opacity:1">
                    <div class="git-main">
                        <div class="git-name">${head} <span class="git-tag">nessun repository locale</span> ${ghRepo ? dashGhTag(ghRepo) : ''}</div>
                        <div class="git-last">${known
                            ? 'Su GitHub esiste un repository con questo progetto, ma la cartella locale non è un clone (è una semplice copia).'
                            : escHtml(info.note || 'Cartella senza Git.')}</div>
                    </div>
                    <button class="btn btn-primary" style="padding:3px 10px;font-size:12px" onclick="openGitInit('${escHtml(lane.id)}')">🌱 Inizializza repository…</button>
                </div>`;
            }

            const tags = [];
            if (info.changed) tags.push(`<span class="git-tag warn">✎ ${info.changed} modificati</span>`);
            if (info.untracked) tags.push(`<span class="git-tag warn">＋ ${info.untracked} non tracciati</span>`);
            if (info.ahead) tags.push(`<span class="git-tag info">⬆ ${info.ahead} da pushare</span>`);
            if (info.behind) tags.push(`<span class="git-tag info">⬇ ${info.behind} da scaricare</span>`);
            if (!info.changed && !info.untracked && !info.ahead && !info.behind) tags.push(`<span class="git-tag ok">✓ ${info.hasUpstream ? 'pulito e allineato' : 'pulito'}</span>`);
            if (!info.hasUpstream) tags.push('<span class="git-tag">nessun upstream</span>');
            const remote = info.remote
                ? (info.remote.url ? dashGhTag(info.remote.repo) : `<span class="git-tag">remote: ${escHtml(info.remote.host)}</span>`)
                : '<span class="git-tag">nessun remote</span>';
            const last = info.last
                ? `«${escHtml(info.last.subject.slice(0, 80))}» · ${escHtml(info.last.author)} · ${dashAgo(new Date(info.last.date).getTime())} (${escHtml(new Date(info.last.date).toLocaleDateString('it-IT'))}) · <code>${escHtml(info.last.hash)}</code>`
                : 'Nessun commit';
            return `<div class="git-row">
                <div class="git-main">
                    <div class="git-name">${head} <span class="git-tag">⎇ ${escHtml(info.branch || '?')}</span>${remote}
                        ${info.atRoot ? '' : `<span class="git-tag" title="${escHtml(info.root)}">repo nella cartella superiore</span>`}</div>
                    <div class="git-last">${last}</div>
                    <div class="git-state">${tags.join('')}</div>
                </div>
                <button class="btn" style="padding:2px 8px;font-size:11px" onclick="openTerminalModal('${escHtml(lane.id)}')">🖥️ Terminale</button>
            </div>`;
        }).join('');
    }

    // ---- Inizializza repository (git init + collegamento opzionale a GitHub) ----
    let gitInitLane = null;

    function openGitInit(laneId) {
        const lane = boardData.swimlanes.find(l => l.id === laneId);
        if (!lane) return;
        const info = (dashGit && dashGit.byLane[laneId]) || {};
        gitInitLane = lane;
        document.getElementById('gitInitName').textContent = lane.name;
        document.getElementById('gitInitDir').textContent = lane.local_path;
        const suggested = lane.github_repo || info.suggestedRemote || '';
        document.getElementById('gitInitRemote').value = suggested;
        document.getElementById('gitInitHint').textContent = suggested
            ? (lane.github_repo ? 'Collegamento già salvato per questo progetto.' : 'Suggerito dal README della cartella: controlla che sia quello giusto.')
            : 'Lascia vuoto per creare solo il repository locale.';
        document.getElementById('gitInitLog').innerHTML = '';
        const go = document.getElementById('gitInitGo');
        go.disabled = false; go.textContent = 'Inizializza';
        document.getElementById('gitInitModal').classList.add('active');
    }
    function closeGitInit() { document.getElementById('gitInitModal').classList.remove('active'); gitInitLane = null; }

    async function gitInitRun() {
        const lane = gitInitLane;
        if (!lane) return;
        const remote = document.getElementById('gitInitRemote').value.trim();
        if (remote && !/^[\w.-]+\/[\w.-]+$/.test(remote)) { toast('Formato non valido: usa owner/nome-repo', 'error'); return; }
        const go = document.getElementById('gitInitGo'), log = document.getElementById('gitInitLog');
        go.disabled = true; go.textContent = '⏳ Lavoro… (con un remote può richiedere qualche secondo)';
        log.innerHTML = '';
        try {
            const gh = dashGit && dashGit.gh[remote];
            const r = await fetch(BRIDGE_URL + '/git/init', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ dir: lane.local_path, remote, branch: gh && gh.default_branch, gitignore: document.getElementById('gitInitIgnore').checked })
            });
            const j = await r.json();
            if (!r.ok) throw new Error(j.error || ('HTTP ' + r.status));
            log.innerHTML = (j.steps || []).map(s => `<div style="color:${s.ok ? '#16a34a' : '#dc2626'}">${s.ok ? '✓' : '✗'} ${escHtml(s.label)}${!s.ok && s.out ? ' — ' + escHtml(s.out.slice(0, 200)) : ''}</div>`).join('')
                + (j.ok ? '' : `<div style="color:#dc2626;margin-top:4px">${escHtml(j.error || 'Non riuscito')}</div>`);
            if (j.ok) {
                if (remote && j.linked) { lane.github_repo = remote; api('update_swimlane', { id: lane.id, github_repo: remote }); }
                go.textContent = 'Fatto';
                dashGit = null; dashRender();
                toast('Repository inizializzato', 'success');
            } else { go.disabled = false; go.textContent = 'Riprova'; }
        } catch (e) {
            log.innerHTML = `<div style="color:#dc2626">Non riuscito: ${escHtml(e.message)}. Il Bridge è aggiornato e acceso?</div>`;
            go.disabled = false; go.textContent = 'Riprova';
        }
    }

    function dashToggleSel(id, on) { if (on) dashSel.add(id); else dashSel.delete(id); dashRender(); }
    function dashReviewSelectLikely() {
        dashReviewRows(dashData.sd).forEach(r => { if (dashReviewHint(r).likelyDone) dashSel.add(r.s.id); });
        dashRender();
    }

    async function dashConclude(ids) {
        const done = [];
        for (const id of ids) {
            const res = await api('session_state', { session_id: id, status: 'concluded', how: 'archived' });
            if (res.success) { setSessState(id, { status: 'concluded', how: 'archived', at: new Date().toISOString(), tasks_created: [], summary: '' }); done.push(id); }
            dashSel.delete(id);
        }
        await deskArchive(done, true);
    }
    async function dashConcludeOne(id) { await dashConclude([id]); toast('Sessione archiviata come conclusa', 'success'); loadDashboard(); }
    async function dashBulkConclude() {
        const ids = [...dashSel];
        if (!ids.length || !confirm('Archiviare ' + ids.length + ' sessioni come concluse?')) return;
        await dashConclude(ids);
        toast(ids.length + ' sessioni archiviate come concluse', 'success');
        loadDashboard();
    }

    // Applica il giudizio di Claude su una sessione: concludi, oppure crea i task individuati e concludi
    async function dashApplyReview(r, v) {
        if (v.verdict === 'conclude') {
            const res = await api('session_state', { session_id: r.s.id, status: 'concluded', how: 'archived', summary: v.reason });
            if (res.success) setSessState(r.s.id, { status: 'concluded', how: 'archived', at: new Date().toISOString(), tasks_created: [], summary: v.reason });
            return { concluded: !!res.success, tasks: 0 };
        }
        const firstCol = boardData.columns[0];
        const claudeLabel = boardData.labels.find(l => /^claude$/i.test(l.name));
        const created = [];
        for (const t of (v.tasks || [])) {
            const lane = boardData.swimlanes.find(l => l.name === t.project) || r.lane;
            const res = await api('add_card', {
                title: t.title, priority: t.priority, swimlane_id: lane.id, column_id: firstCol.id, label_id: claudeLabel ? claudeLabel.id : null,
                description: (t.description || '') + '\n\n— Da revisione sessione «' + (r.s.title || r.s.preview || 'senza titolo') + '» (' + r.s.id.slice(0, 8) + ')'
            });
            if (res.success && res.card) {
                res.card.sessions = [{ id: r.s.id, role: 'created' }];
                boardData.cards.push(res.card);
                created.push(res.card.seq);
                api('link_session', { card_id: res.card.id, session_id: r.s.id, role: 'created' });
            }
        }
        const res = await api('session_state', { session_id: r.s.id, status: 'concluded', how: 'split', tasks_created: created, summary: v.reason });
        if (res.success) setSessState(r.s.id, { status: 'concluded', how: 'split', at: new Date().toISOString(), tasks_created: created, summary: v.reason });
        return { concluded: !!res.success, tasks: created.length };
    }

    async function dashApplyOne(id) {
        const r = dashData.sd.all.find(x => x.s.id === id), v = r && dashReviewOf(r);
        if (!v) return;
        const out = await dashApplyReview(r, v);
        toast(out.tasks ? out.tasks + ' task aggiunti, sessione conclusa' : 'Sessione conclusa', 'success');
        if (out.concluded) await deskArchive([id], true);
        render(); loadDashboard();
    }

    async function dashApplyAllReviews() {
        const todo = dashReviewRows(dashData.sd).map(r => ({ r, v: dashReviewOf(r) })).filter(x => x.v && x.v.verdict !== 'resume');
        if (!todo.length) return;
        const nc = todo.filter(x => x.v.verdict === 'conclude').length;
        const ns = todo.length - nc, nt = todo.reduce((a, x) => a + (x.v.tasks || []).length, 0);
        if (!confirm('Applico i giudizi di Claude?\n\n• Archivio ' + nc + ' sessioni come concluse\n• Scompongo ' + ns + ' sessioni: aggiungo ' + nt + ' task al Kanban e le concludo\n• Le sessioni "da riprendere" restano dove sono\n\nÈ reversibile: puoi riaprire una sessione o archiviare i task.')) return;
        const btn = document.querySelector('.dash-revbar .btn-primary');
        let done = 0, tasks = 0;
        const concludedIds = [];
        for (const x of todo) {
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Applico… ' + (++done) + '/' + todo.length; }
            const out = await dashApplyReview(x.r, x.v);
            tasks += out.tasks;
            if (out.concluded) concludedIds.push(x.r.s.id);
        }
        toast(todo.length + ' sessioni chiuse, ' + tasks + ' task aggiunti', 'success');
        await deskArchive(concludedIds, true);
        render(); loadDashboard();
    }

    // === SESSION PANEL ("Vedi": trascrizione, task della sessione, riprendi / archivia / scomponi) ===
    const sessionIndex = new Map(); // sessionId -> { laneId, s }
    let sessView = null;

    function sessState(id) { const st = boardData.session_states; return (st && !Array.isArray(st)) ? st[id] : undefined; }
    function setSessState(id, val) {
        if (!boardData.session_states || Array.isArray(boardData.session_states)) boardData.session_states = {};
        if (val) boardData.session_states[id] = val; else delete boardData.session_states[id];
    }

    async function fetchLaneSessions(lane) {
        const r = await fetch(BRIDGE_URL + '/sessions?dir=' + encodeURIComponent(lane.local_path));
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const list = (await r.json()).sessions || [];
        list.forEach(s => sessionIndex.set(s.id, { laneId: lane.id, s }));
        return list;
    }

    // Cards tied to a session: created / worked on / completed (Bridge detection + links stored on cards)
    function sessionCards(s) {
        const bySeq = new Map(boardData.cards.filter(c => c.seq).map(c => [c.seq, c]));
        const st = sessState(s.id);
        const roles = s.taskRoles || { created: [], completed: [], moved: [], read: [] };
        const created = new Set([...(roles.created || []), ...((st && st.tasks_created) || [])]);
        const completed = new Set(roles.completed || []);
        const worked = new Set([...(roles.moved || []), ...(roles.read || [])]);
        boardData.cards.forEach(c => (c.sessions || []).forEach(l => {
            if (l.id !== s.id) return;
            if (l.role === 'created') created.add(c.seq); else worked.add(c.seq);
        }));
        (s.tasks || []).forEach(q => { if (!created.has(q) && !completed.has(q)) worked.add(q); });
        completed.forEach(q => { created.delete(q); worked.delete(q); });
        created.forEach(q => worked.delete(q));
        const pick = set => [...set].map(q => bySeq.get(q)).filter(Boolean);
        return { created: pick(created), worked: pick(worked), completed: pick(completed) };
    }

    async function openSessionPanel(laneId, sessionId) {
        let entry = sessionIndex.get(sessionId);
        const lane = boardData.swimlanes.find(l => l.id === laneId);
        if (!entry && lane) { try { await fetchLaneSessions(lane); entry = sessionIndex.get(sessionId); } catch (_) {} }
        if (!entry || !lane) { toast('Sessione non trovata (Bridge acceso?)', 'error'); return; }
        sessView = { lane, s: entry.s, transcript: null, showTools: false, proposals: null, busy: false };
        document.getElementById('sessionModal').classList.add('active');
        renderSessionPanel();
        try {
            const r = await fetch(BRIDGE_URL + '/session?dir=' + encodeURIComponent(lane.local_path) + '&id=' + encodeURIComponent(sessionId));
            if (!r.ok) throw new Error('HTTP ' + r.status);
            if (sessView && sessView.s.id === sessionId) { sessView.transcript = await r.json(); renderSessionPanel(true); }
        } catch (e) {
            if (sessView) { sessView.transcript = { error: true, messages: [] }; renderSessionPanel(); }
        }
    }

    function closeSessionPanel() {
        document.getElementById('sessionModal').classList.remove('active');
        sessView = null;
    }

    function sessTaskLine(label, cards) {
        if (!cards.length) return '';
        return `<div style="font-size:12px;margin-top:3px"><b>${label}</b> ${cards.map(c =>
            `<span class="dash-chip ${dashChipClass(c)}" title="${escHtml(c.title)}" onclick="sessOpenCard('${escHtml(c.id)}')">#${c.seq} ${escHtml(c.title.slice(0, 40))}${c.title.length > 40 ? '…' : ''}</span>`).join('')}</div>`;
    }

    function sessOpenCard(id) { closeSessionPanel(); openCardModal(id); }

    function renderSessionPanel(scrollEnd) {
        if (!sessView) return;
        const { lane, s } = sessView;
        const st = sessState(s.id);
        const tc = sessionCards(s);
        const title = s.title || s.preview || '(senza titolo)';

        document.getElementById('sessHead').innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <h2 style="margin:0;font-size:17px">🕒 ${escHtml(title)}</h2>
                ${st ? `<span class="sess-badge done">✔ conclusa${st.how === 'split' ? ' (scomposta in task)' : ''}</span>` : ''}
                <span style="flex:1"></span>
                <button class="btn btn-icon" onclick="closeSessionPanel()" title="Chiudi">&times;</button>
            </div>
            <div style="font-size:12px;color:var(--text2);margin-top:2px">${escHtml(lane.name)} · ${escHtml(new Date(s.modified).toLocaleString('it-IT'))} · ${s.turns || 0} tuoi messaggi · <code>${escHtml(s.id.slice(0, 8))}</code></div>
            ${st && st.summary ? `<div style="font-size:12px;margin-top:6px;padding:6px 8px;background:var(--bg2);border-radius:6px">${escHtml(st.summary)}</div>` : ''}
            <div style="margin-top:6px">
                ${sessTaskLine('➕ Task creati:', tc.created)}
                ${sessTaskLine('🔧 Task lavorati:', tc.worked)}
                ${sessTaskLine('✅ Task conclusi:', tc.completed)}
                ${(!tc.created.length && !tc.worked.length && !tc.completed.length) ? '<div style="font-size:12px;color:var(--text2)">Nessun task collegato a questa sessione.</div>' : ''}
            </div>`;

        const body = document.getElementById('sessBody');
        if (sessView.proposals) {
            renderSessionProposals(body);
        } else if (!sessView.transcript) {
            body.innerHTML = '<div class="dash-empty">Carico la sessione…</div>';
        } else if (sessView.transcript.error) {
            body.innerHTML = '<div class="dash-empty">Impossibile leggere la trascrizione: il Bridge è acceso?</div>';
        } else {
            const t = sessView.transcript;
            const msgs = t.messages.filter(m => sessView.showTools || m.role !== 'tool');
            body.innerHTML = `
                <div style="display:flex;gap:10px;align-items:center;font-size:12px;color:var(--text2);margin-bottom:4px">
                    <label><input type="checkbox" ${sessView.showTools ? 'checked' : ''} onchange="sessView.showTools=this.checked;renderSessionPanel()" style="width:auto"> mostra strumenti</label>
                    <span>${t.total} messaggi${t.truncated ? ' (mostrati i primi 200 e gli ultimi)' : ''}</span>
                </div>
                ${msgs.map(m => m.role === 'tool'
                    ? `<div class="sess-tool">🔧 ${escHtml(m.text)}</div>`
                    : `<div class="sess-msg ${m.role}"><div class="sess-who">${m.role === 'user' ? 'Tu' : 'Claude'}<span>${m.ts ? escHtml(new Date(m.ts).toLocaleString('it-IT')) : ''}</span></div><div class="sess-text">${escHtml(m.text)}</div></div>`
                ).join('')}`;
            if (scrollEnd) body.scrollTop = body.scrollHeight;
        }

        const act = document.getElementById('sessActions');
        if (sessView.proposals) {
            const n = sessView.proposals.tasks.length;
            act.innerHTML = `
                <button class="btn" onclick="sessCancelProposals()">Indietro</button>
                <button class="btn btn-primary" id="sessConfirmBtn" onclick="sessConfirmSplit()">${n ? '➕ Aggiungi i task selezionati e segna conclusa' : '✅ Nessun task da aggiungere: segna conclusa'}</button>`;
        } else if (st) {
            act.innerHTML = `
                <button class="btn" onclick="sessReopen()">↩️ Riapri</button>
                <button class="btn btn-primary" onclick="sessResume()">▶️ Riprendi</button>`;
        } else {
            act.innerHTML = `
                <button class="btn" onclick="sessResume()">▶️ Riprendi</button>
                <button class="btn" onclick="sessConclude()">✅ Archivia come conclusa</button>
                <button class="btn btn-primary" id="sessSplitBtn" onclick="sessSplit()">🧩 Scomponi in task e chiudi</button>`;
        }
    }

    function sessResume() {
        const { lane, s } = sessView;
        closeSessionPanel();
        openTerminalModal(lane.id, { launch: 'resume', sessionId: s.id });
    }

    async function sessSetConcluded(how, extra) {
        const { s } = sessView;
        const payload = { session_id: s.id, status: 'concluded', how, ...(extra || {}) };
        const res = await api('session_state', payload);
        if (!res.success) { toast(res.error || 'Errore', 'error'); return false; }
        setSessState(s.id, { status: 'concluded', how, at: new Date().toISOString(), tasks_created: payload.tasks_created || [], summary: payload.summary || '' });
        await deskArchive([s.id], true);
        return true;
    }

    function sessRefreshViews() {
        if (document.body.dataset.view === 'dashboard') loadDashboard();
    }

    async function sessConclude() {
        if (await sessSetConcluded('archived')) {
            toast('Sessione archiviata come conclusa', 'success');
            closeSessionPanel(); sessRefreshViews();
        }
    }

    async function sessReopen() {
        const { s } = sessView;
        const res = await api('session_state', { session_id: s.id, status: null });
        if (!res.success) { toast(res.error || 'Errore', 'error'); return; }
        setSessState(s.id, null);
        await deskArchive([s.id], false);
        renderSessionPanel(); sessRefreshViews();
    }

    async function sessSplit() {
        if (sessView.busy) return;
        const { lane, s } = sessView;
        const doneCol = c => c.archived || DASH_DONE_RE.test((boardData.columns.find(x => x.id === c.column_id) || {}).name || '');
        const tasks = boardData.cards.filter(c => c.swimlane_id === lane.id && c.seq).map(c => ({
            seq: c.seq, title: c.title, done: !!doneCol(c),
            column: (boardData.columns.find(x => x.id === c.column_id) || {}).name || ''
        }));
        sessView.busy = true;
        const btn = document.getElementById('sessSplitBtn');
        btn.disabled = true; btn.textContent = '⏳ Claude sta leggendo la sessione… (10-60 s)';
        try {
            const r = await fetch(BRIDGE_URL + '/analyze', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ dir: lane.local_path, sessionId: s.id, project: lane.name, tasks })
            });
            const j = await r.json();
            if (!r.ok) throw new Error(j.error || ('HTTP ' + r.status));
            sessView.proposals = { summary: j.summary || '', tasks: (j.tasks || []).map(t => ({ ...t, checked: true })) };
            renderSessionPanel();
        } catch (e) {
            toast('Analisi non riuscita: ' + e.message, 'error');
            btn.disabled = false; btn.textContent = '🧩 Scomponi in task e chiudi';
        } finally {
            if (sessView) sessView.busy = false;
        }
    }

    function sessCancelProposals() { sessView.proposals = null; renderSessionPanel(); }

    function renderSessionProposals(body) {
        const p = sessView.proposals;
        body.innerHTML = `
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text2);margin:2px 0 6px">Cosa è successo</div>
            <div style="font-size:13px;margin-bottom:12px">${escHtml(p.summary || '—')}</div>
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text2);margin-bottom:6px">Task da aggiungere al Kanban di ${escHtml(sessView.lane.name)} (${p.tasks.length})</div>
            ${p.tasks.length ? p.tasks.map((t, i) => `
                <div class="dash-item" style="display:flex;gap:8px;align-items:flex-start">
                    <input type="checkbox" ${t.checked ? 'checked' : ''} onchange="sessView.proposals.tasks[${i}].checked=this.checked" style="width:auto;margin-top:5px">
                    <div style="flex:1">
                        <input type="text" value="${escHtml(t.title)}" oninput="sessView.proposals.tasks[${i}].title=this.value" style="font-weight:500;width:100%">
                        <div class="dash-sub">${escHtml(t.description)}</div>
                    </div>
                    <select onchange="sessView.proposals.tasks[${i}].priority=this.value" style="width:auto">
                        ${['high', 'medium', 'low'].map(v => `<option value="${v}" ${t.priority === v ? 'selected' : ''}>${v}</option>`).join('')}
                    </select>
                </div>`).join('') : '<div class="dash-empty">Non ho trovato nulla rimasto in sospeso e non già presente sulla board.</div>'}`;
    }

    async function sessConfirmSplit() {
        const { lane, s, proposals } = sessView;
        const btn = document.getElementById('sessConfirmBtn');
        btn.disabled = true;
        const firstCol = boardData.columns[0];
        const claudeLabel = boardData.labels.find(l => /^claude$/i.test(l.name));
        const created = [];
        for (const t of proposals.tasks.filter(t => t.checked && t.title.trim())) {
            const res = await api('add_card', {
                title: t.title.trim(), priority: t.priority,
                description: (t.description || '') + '\n\n— Da sessione «' + (s.title || s.preview || 'senza titolo') + '» (' + s.id.slice(0, 8) + ')',
                swimlane_id: lane.id, column_id: firstCol.id, label_id: claudeLabel ? claudeLabel.id : null
            });
            if (res.success && res.card) {
                boardData.cards.push(res.card);
                created.push(res.card.seq);
                api('link_session', { card_id: res.card.id, session_id: s.id, role: 'created' });
                res.card.sessions = [{ id: s.id, role: 'created' }];
            }
        }
        if (await sessSetConcluded('split', { tasks_created: created, summary: proposals.summary })) {
            toast(created.length ? created.length + ' task aggiunti, sessione conclusa' : 'Sessione conclusa', 'success');
            render();
            closeSessionPanel(); sessRefreshViews();
        } else {
            btn.disabled = false;
        }
    }

    // Sessions related to a card, shown inside the card modal
    async function renderCardSessions(card) {
        const box = document.getElementById('cardSessions');
        const lane = boardData.swimlanes.find(l => l.id === card.swimlane_id);
        if (!lane || !lane.local_path || !card.seq) { box.style.display = 'none'; return; }
        box.style.display = 'block';
        box.innerHTML = '<div style="font-size:13px;font-weight:500">🕒 Sessioni Claude</div><div class="dash-empty">Carico…</div>';
        let list;
        try { list = await fetchLaneSessions(lane); } catch (_) {
            box.innerHTML = '<div style="font-size:13px;font-weight:500">🕒 Sessioni Claude</div><div class="dash-empty">Bridge locale non raggiungibile.</div>';
            return;
        }
        if (document.getElementById('cardId').value !== card.id) return; // card changed while loading
        const explicit = new Set((card.sessions || []).map(x => x.id));
        const rel = list.filter(s => (s.tasks || []).includes(card.seq) || explicit.has(s.id) || (sessState(s.id) && (sessState(s.id).tasks_created || []).includes(card.seq)));
        const rows = rel.map(s => {
            const tc = sessionCards(s);
            const role = tc.created.some(c => c.id === card.id) ? '➕ creata' : tc.completed.some(c => c.id === card.id) ? '✅ conclusa' : '🔧 lavorata';
            const st = sessState(s.id);
            return `<div style="display:flex;gap:8px;align-items:center;padding:4px 0;border-top:1px solid var(--border)">
                <span style="font-size:11px;color:var(--text2);white-space:nowrap">${escHtml(new Date(s.modified).toLocaleDateString('it-IT'))}</span>
                <span style="font-size:13px;flex:1">${escHtml(s.title || s.preview || '(senza titolo)')}</span>
                <span class="sess-badge">${role}</span>
                ${st ? '<span class="sess-badge done">✔ conclusa</span>' : ''}
                <button type="button" class="btn" style="padding:2px 8px;font-size:11px" onclick="closeCardModal();openSessionPanel('${escHtml(lane.id)}','${escHtml(s.id)}')">Vedi</button>
            </div>`;
        }).join('');
        box.innerHTML = '<div style="font-size:13px;font-weight:500;margin-bottom:4px">🕒 Sessioni Claude (' + rel.length + ')</div>'
            + (rows || '<div class="dash-empty">Nessuna sessione collegata a questo task.</div>');
    }

    // === SESSIONS (local Claude Code session history, read-only via local Bridge) ===
    const BRIDGE_URL = 'http://127.0.0.1:51820';
    let sessionsState = { laneId: null };

    async function openSessionsModal(laneId) {
        const lane = boardData.swimlanes.find(l => l.id === laneId);
        if (!lane || !lane.local_path) return;
        sessionsState.laneId = laneId;
        document.getElementById('sessionsProjName').textContent = lane.name;
        const list = document.getElementById('sessionsList');
        list.innerHTML = '<div style="padding:12px;color:var(--text2);font-size:13px">Carico…</div>';
        document.getElementById('sessionsModal').classList.add('active');
        try {
            const res = await fetch(BRIDGE_URL + '/sessions?dir=' + encodeURIComponent(lane.local_path));
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            renderSessionsList(data.sessions || []);
        } catch (e) {
            list.innerHTML = `<div style="padding:12px;font-size:13px;color:var(--text2)">
                Bridge locale non raggiungibile su <code>${escHtml(BRIDGE_URL)}</code>.<br>
                Avvialo sul tuo PC con: <code>node bridge/bridge-server.js</code>
            </div>`;
        }
    }

    function closeSessionsModal() {
        document.getElementById('sessionsModal').classList.remove('active');
    }

    function renderSessionsList(sessions) {
        const list = document.getElementById('sessionsList');
        if (!sessions.length) {
            list.innerHTML = '<div style="padding:12px;color:var(--text2);font-size:13px">Nessuna sessione trovata per questa cartella.</div>';
            return;
        }
        sessions.forEach(s => sessionIndex.set(s.id, { laneId: sessionsState.laneId, s }));
        list.innerHTML = sessions.map(s => `
            <div style="border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px${s.isArchived ? ';opacity:0.6' : ''}">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline">
                    <span style="font-size:13px;font-weight:600">${escHtml(s.title || s.preview || '(senza titolo)')}</span>
                    <code style="font-size:10px;color:var(--text2);white-space:nowrap">${escHtml((s.id || '').slice(0, 8))}</code>
                </div>
                <div style="display:flex;justify-content:space-between;gap:8px;margin-top:4px;align-items:flex-end">
                    ${s.title && s.preview ? `<span style="font-size:12px;color:var(--text2);white-space:pre-wrap">${escHtml(s.preview)}</span>` : '<span></span>'}
                    <span style="display:flex;align-items:center;gap:8px;white-space:nowrap">
                        <span style="font-size:11px;color:var(--text2)">${escHtml(new Date(s.modified).toLocaleString('it-IT'))}</span>
                        <button class="btn" style="padding:2px 8px;font-size:11px" onclick="viewSession('${escHtml(s.id)}')">Vedi</button>
                    </span>
                </div>
            </div>
        `).join('');
    }

    function viewSession(sessionId) {
        const laneId = sessionsState.laneId;
        closeSessionsModal();
        openSessionPanel(laneId, sessionId);
    }

    // === TERMINAL (live shell via local Bridge, xterm.js) ===
    let termInstance = null, termFitAddon = null, termSocket = null, termResizeHandler = null;

    function openTerminalModal(laneId, context) {
        const lane = boardData.swimlanes.find(l => l.id === laneId);
        if (!lane || !lane.local_path) return;
        context = context || {}; // { launch: 'claude', prompt: '...' } to seed Claude Code with a task's context
        document.getElementById('terminalProjName').textContent = lane.name;
        closeTerminalModal(); // tear down any previous instance first
        document.getElementById('terminalModal').classList.add('active');

        const container = document.getElementById('terminalContainer');
        termInstance = new Terminal({ convertEol: true, fontSize: 13, theme: { background: '#000000' } });
        termFitAddon = new FitAddon.FitAddon();
        termInstance.loadAddon(termFitAddon);
        termInstance.open(container);
        termFitAddon.fit();

        termInstance.onData(data => {
            if (termSocket && termSocket.readyState === WebSocket.OPEN) {
                termSocket.send(JSON.stringify({ type: 'input', data }));
            }
        });

        termResizeHandler = () => {
            if (!termFitAddon) return;
            termFitAddon.fit();
            if (termSocket && termSocket.readyState === WebSocket.OPEN) {
                termSocket.send(JSON.stringify({ type: 'resize', cols: termInstance.cols, rows: termInstance.rows }));
            }
        };
        window.addEventListener('resize', termResizeHandler);

        let wsUrl = 'ws://127.0.0.1:51820/pty?dir=' + encodeURIComponent(lane.local_path);
        if (context.launch) wsUrl += '&launch=' + encodeURIComponent(context.launch);
        if (context.prompt) wsUrl += '&prompt=' + encodeURIComponent(context.prompt);
        if (context.sessionId) wsUrl += '&sessionId=' + encodeURIComponent(context.sessionId);
        termSocket = new WebSocket(wsUrl);
        termSocket.onopen = () => {
            termFitAddon.fit();
            termSocket.send(JSON.stringify({ type: 'resize', cols: termInstance.cols, rows: termInstance.rows }));
        };
        termSocket.onmessage = ev => {
            const msg = JSON.parse(ev.data);
            if (msg.type === 'data') termInstance.write(msg.data);
            else if (msg.type === 'exit') termInstance.writeln('\r\n[processo terminato, codice ' + msg.code + ']');
        };
        termSocket.onerror = () => {
            termInstance.writeln('\r\nBridge locale non raggiungibile su ws://127.0.0.1:51820. Avvialo con: node bridge/bridge-server.js');
        };
    }

    function closeTerminalModal() {
        document.getElementById('terminalModal').classList.remove('active');
        if (termResizeHandler) { window.removeEventListener('resize', termResizeHandler); termResizeHandler = null; }
        if (termSocket) { try { termSocket.close(); } catch (_) {} termSocket = null; }
        if (termInstance) { termInstance.dispose(); termInstance = null; }
        document.getElementById('terminalContainer').innerHTML = '';
    }

    // === PROJECT DOCS (files the AI studies before working) ===
    let docsState = { laneId: null, subpath: '', selected: new Map() }; // path -> type

    function openDocsModal(laneId) {
        const lane = boardData.swimlanes.find(l => l.id === laneId);
        if (!lane) return;
        docsState = { laneId, subpath: '', selected: new Map() };
        (lane.doc_files || []).forEach(d => {
            if (typeof d === 'string') docsState.selected.set(d, 'file');
            else if (d && d.path) docsState.selected.set(d.path, d.type || 'file');
        });
        document.getElementById('docsProjName').textContent = lane.name;
        document.getElementById('docsFolderInput').value = lane.path || '';
        document.getElementById('docsModal').classList.add('active');

        if (!lane.path) {
            document.getElementById('docsNoFolder').style.display = 'block';
            document.getElementById('docsBrowserWrap').style.display = 'none';
            document.getElementById('docsSaveBtn').style.display = 'none';
        } else {
            document.getElementById('docsNoFolder').style.display = 'none';
            document.getElementById('docsBrowserWrap').style.display = 'block';
            document.getElementById('docsSaveBtn').style.display = '';
            document.getElementById('docsFolderLabel').textContent = lane.path;
            renderDocsSelected();
            docsNavigate('');
        }
    }

    function closeDocsModal() {
        document.getElementById('docsModal').classList.remove('active');
    }

    async function docsSaveFolder() {
        const path = document.getElementById('docsFolderInput').value.trim();
        if (!path) { toast('Inserisci una cartella', 'error'); return; }
        const lane = boardData.swimlanes.find(l => l.id === docsState.laneId);
        if (lane) lane.path = path;
        await api('update_swimlane', { id: docsState.laneId, path });
        render();
        openDocsModal(docsState.laneId); // reopen now that it's linked
    }

    function docsGo(relEnc) { docsNavigate(decodeURIComponent(relEnc)); }

    async function docsNavigate(subpath) {
        docsState.subpath = subpath || '';
        const browser = document.getElementById('docsBrowser');
        browser.innerHTML = '<div style="padding:12px;color:var(--text2);font-size:13px">Carico…</div>';
        const res = await api('list_project_files', { id: docsState.laneId, subpath: docsState.subpath });
        if (!res.success) {
            browser.innerHTML = `<div style="padding:12px;color:var(--danger,#ef4444);font-size:13px">${escHtml(res.error || 'Errore')}</div>`;
            return;
        }
        renderDocsBreadcrumb();
        renderDocsBrowser(res.entries || []);
    }

    function renderDocsBreadcrumb() {
        const parts = docsState.subpath ? docsState.subpath.split('/') : [];
        let acc = '';
        const crumbs = [`<a href="#" onclick="docsNavigate('');return false" style="color:var(--accent)">/</a>`];
        parts.forEach(p => {
            acc = acc ? acc + '/' + p : p;
            const target = acc;
            crumbs.push(`<a href="#" onclick="docsNavigate('${target}');return false" style="color:var(--accent)">${escHtml(p)}</a>`);
        });
        document.getElementById('docsBreadcrumb').innerHTML = crumbs.join(' <span style="color:var(--text2)">/</span> ');
    }

    function renderDocsBrowser(entries) {
        const browser = document.getElementById('docsBrowser');
        if (!entries.length) { browser.innerHTML = '<div style="padding:12px;color:var(--text2);font-size:13px">(cartella vuota)</div>'; return; }
        browser.innerHTML = entries.map(e => {
            const checked = docsState.selected.has(e.rel) ? 'checked' : '';
            const icon = e.type === 'dir' ? '📁' : '📄';
            const relEnc = encodeURIComponent(e.rel);
            const size = e.type === 'file' ? `<span style="color:var(--text2);font-size:11px">${fmtBytes(e.size)}</span>` : '';
            const nameCell = e.type === 'dir'
                ? `<a href="#" onclick="docsGo('${relEnc}');return false" style="color:var(--text);text-decoration:none;flex:1">${icon} ${escHtml(e.name)}/</a>`
                : `<span onclick="toggleDoc('${relEnc}','file')" style="flex:1;cursor:pointer">${icon} ${escHtml(e.name)}</span>`;
            return `<div style="display:flex;align-items:center;gap:8px;padding:5px 6px;border-radius:5px;font-size:13px" onmouseover="this.style.background='var(--bg2,rgba(0,0,0,0.04))'" onmouseout="this.style.background=''">
                        <input type="checkbox" ${checked} onchange="toggleDoc('${relEnc}','${e.type}')" style="width:auto;margin:0;cursor:pointer">
                        ${nameCell}
                        ${size}
                    </div>`;
        }).join('');
    }

    function toggleDoc(relEnc, type) {
        const rel = decodeURIComponent(relEnc);
        if (docsState.selected.has(rel)) docsState.selected.delete(rel);
        else docsState.selected.set(rel, type);
        renderDocsSelected();
    }

    function renderDocsSelected() {
        const wrap = document.getElementById('docsSelected');
        document.getElementById('docsSelCount').textContent = docsState.selected.size;
        if (!docsState.selected.size) { wrap.innerHTML = '<span style="color:var(--text2);font-size:12px">Nessuno</span>'; return; }
        wrap.innerHTML = [...docsState.selected.entries()].map(([rel, type]) =>
            `<span style="display:inline-flex;align-items:center;gap:4px;background:var(--bg,rgba(0,0,0,0.05));border:1px solid var(--border);border-radius:12px;padding:2px 8px;font-size:12px">
                ${type === 'dir' ? '📁' : '📄'} ${escHtml(rel)}
                <a href="#" onclick="toggleDoc('${encodeURIComponent(rel)}','${type}');return false" style="color:var(--text2);text-decoration:none;font-weight:700">×</a>
            </span>`
        ).join('');
    }

    async function saveDocs() {
        const doc_files = [...docsState.selected.entries()].map(([path, type]) => ({ path, type }));
        const lane = boardData.swimlanes.find(l => l.id === docsState.laneId);
        if (lane) lane.doc_files = doc_files;
        await api('update_swimlane', { id: docsState.laneId, doc_files });
        render();
        closeDocsModal();
    }

    function fmtBytes(n) {
        n = n || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1024 / 1024).toFixed(1) + ' MB';
    }

    // === CARDS ===
    let cardFiles = [];

    // === PM layer helpers (epic / dependencies / acceptance) ===
    let cardDepends = [];   // seq numbers this card depends on
    let cardAcc = [];        // [{text, done}]

    function pmColName(card) {
        return (boardData.columns.find(x => x.id === card.column_id) || {}).name || '';
    }
    function pmIsDone(card) {
        return !!card && (card.archived || /done|fatto|chius|completat/i.test(pmColName(card)));
    }
    function pmEpicsList() {
        const s = new Set();
        boardData.cards.forEach(c => { if (c.epic) s.add(String(c.epic)); });
        return [...s].sort();
    }
    function pmEpicColor(epic) {
        let h = 0; const str = String(epic || '');
        for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
        return `hsl(${h} 55% 45%)`;
    }
    // Returns the seq list of unfinished dependencies (empty = not blocked).
    function cardBlockedBy(card) {
        const deps = card.depends_on || [];
        if (!deps.length) return [];
        return deps.filter(seq => {
            const dep = boardData.cards.find(c => Number(c.seq) === Number(seq));
            return dep && !pmIsDone(dep); // unknown seq => treated as satisfied
        });
    }

    function renderCardPM() {
        document.getElementById('pmEpicList').innerHTML =
            pmEpicsList().map(e => `<option value="${escHtml(e)}"></option>`).join('');
        const laneId = document.getElementById('cardSwimlaneId').value;
        const selfId = document.getElementById('cardId').value;
        const sibs = boardData.cards.filter(c => !c.archived && c.swimlane_id === laneId && c.id !== selfId && c.seq);
        const pick = document.getElementById('cardDependsPick');
        pick.innerHTML = '<option value="">+ aggiungi dipendenza…</option>' +
            sibs.filter(c => !cardDepends.includes(Number(c.seq)))
                .map(c => `<option value="${c.seq}">#${c.seq} — ${escHtml(c.title)}</option>`).join('');
        document.getElementById('cardDependsList').innerHTML = cardDepends.length
            ? cardDepends.map(seq => {
                const d = boardData.cards.find(c => Number(c.seq) === Number(seq));
                const done = d && pmIsDone(d);
                return `<span class="pm-tag" title="${d ? escHtml(d.title) : 'task sconosciuto'}">${done ? '✅' : '⛔'} #${seq}<button type="button" onclick="removeCardDep(${seq})">&times;</button></span>`;
            }).join('')
            : '<span style="font-size:11px;color:var(--text2)">nessuna</span>';
        document.getElementById('cardAccList').innerHTML = cardAcc.length
            ? cardAcc.map((a, i) => `
                <div class="pm-acc-item ${a.done ? 'done' : ''}">
                    <input type="checkbox" ${a.done ? 'checked' : ''} onchange="toggleCardAcc(${i})">
                    <span style="flex:1">${escHtml(a.text)}</span>
                    <button type="button" class="btn btn-icon btn-danger" onclick="removeCardAcc(${i})" style="padding:2px 6px">&times;</button>
                </div>`).join('')
            : '<p style="font-size:11px;color:var(--text2);margin:0">nessun criterio</p>';
    }
    function addCardDep(seq) { seq = Number(seq); if (!seq || cardDepends.includes(seq)) return; cardDepends.push(seq); renderCardPM(); }
    function removeCardDep(seq) { cardDepends = cardDepends.filter(s => Number(s) !== Number(seq)); renderCardPM(); }
    function addCardAcc() { const el = document.getElementById('cardAccInput'); const t = el.value.trim(); if (!t) return; cardAcc.push({ text: t, done: false }); el.value = ''; renderCardPM(); }
    function toggleCardAcc(i) { if (cardAcc[i]) cardAcc[i].done = !cardAcc[i].done; renderCardPM(); }
    function removeCardAcc(i) { cardAcc.splice(i, 1); renderCardPM(); }

    function refreshEpicFilter() {
        const sel = document.getElementById('filterEpic');
        if (!sel) return;
        const cur = sel.value;
        sel.innerHTML = ['<option value="">🎯 All epics</option>',
            '<option value="__blocked__">🔒 Bloccate</option>',
            '<option value="__none__">— senza epic</option>']
            .concat(pmEpicsList().map(e => `<option value="${escHtml(e)}">${escHtml(e)}</option>`))
            .join('');
        sel.value = cur;
    }

    function openCardModal(cardId = null, colId = null, laneId = null) {
        const modal = document.getElementById('cardModal');
        const form = document.getElementById('cardForm');
        const deleteBtn = document.getElementById('deleteCardBtn');
        const verifyBtn = document.getElementById('verifyCardBtn');
        const claudeBtn = document.getElementById('executeClaudeBtn');
        const autoRegenCheckbox = document.getElementById('cardAutoRegenerate');
        const regenOptions = document.getElementById('regenerateOptions');

        // Reset verify result
        document.getElementById('verifyResult').style.display = 'none';

        // Populate labels dropdown
        const labelSelect = document.getElementById('cardLabelInput');
        labelSelect.innerHTML = '<option value="">None</option>' +
            boardData.labels.map(l => `<option value="${l.id}">${escHtml(l.name)}</option>`).join('');

        if (cardId) {
            const card = boardData.cards.find(c => c.id === cardId);
            if (!card) return;
            document.getElementById('cardModalTitle').textContent = card.seq ? ('Edit Card #' + card.seq) : 'Edit Card';
            document.getElementById('cardId').value = card.id;
            document.getElementById('cardColumnId').value = card.column_id;
            document.getElementById('cardSwimlaneId').value = card.swimlane_id;
            document.getElementById('cardTitleInput').value = card.title;
            document.getElementById('cardDescInput').value = card.description || '';
            document.getElementById('cardPriorityInput').value = card.priority;
            document.getElementById('cardLabelInput').value = card.label_id || '';
            document.getElementById('cardDueInput').value = card.due_date || '';
            document.getElementById('cardNextCheckInput').value = card.next_check || '';
            // Auto-regenerate fields
            autoRegenCheckbox.checked = card.auto_regenerate || false;
            document.getElementById('cardRegenerateDelay').value = card.regenerate_delay_days || 0;
            regenOptions.style.display = autoRegenCheckbox.checked ? 'block' : 'none';
            // Files
            cardFiles = card.files || [];
            renderCardFiles();
            // PM / Spec
            cardDepends = (card.depends_on || []).map(Number);
            cardAcc = (card.acceptance || []).map(a => ({ text: a.text, done: !!a.done }));
            document.getElementById('cardEpicInput').value = card.epic || '';
            document.getElementById('cardEstimateInput').value = card.estimate || '';
            document.getElementById('cardParallel').checked = !!card.parallel;
            renderCardPM();
            deleteBtn.style.display = 'block';
            verifyBtn.style.display = cardFiles.length > 0 ? 'block' : 'none';
            claudeBtn.style.display = 'block';
            document.getElementById('openClaudeAppBtn').style.display = 'block';
            const cardLane = boardData.swimlanes.find(l => l.id === card.swimlane_id);
            document.getElementById('playLocalBtn').style.display = (cardLane && cardLane.local_path) ? 'block' : 'none';
            renderClaudeRuns(card);
            renderCardSessions(card);
            document.getElementById('templateGroup').style.display = 'none';
        } else {
            document.getElementById('cardModalTitle').textContent = 'New Card';
            form.reset();
            document.getElementById('cardId').value = '';
            document.getElementById('cardColumnId').value = colId;
            document.getElementById('cardSwimlaneId').value = laneId;
            autoRegenCheckbox.checked = false;
            document.getElementById('cardRegenerateDelay').value = 0;
            regenOptions.style.display = 'none';
            cardFiles = [];
            renderCardFiles();
            // PM / Spec
            cardDepends = [];
            cardAcc = [];
            document.getElementById('cardEpicInput').value = '';
            document.getElementById('cardEstimateInput').value = '';
            document.getElementById('cardParallel').checked = false;
            renderCardPM();
            deleteBtn.style.display = 'none';
            verifyBtn.style.display = 'none';
            claudeBtn.style.display = 'none';
            document.getElementById('openClaudeAppBtn').style.display = 'none';
            document.getElementById('playLocalBtn').style.display = 'none';
            document.getElementById('cardSessions').style.display = 'none';
            document.getElementById('claudeRunsPanel').style.display = 'none';
            document.getElementById('templateGroup').style.display = 'block';
            document.getElementById('cardTemplate').value = '';
        }

        modal.classList.add('active');
    }

    function renderCardFiles() {
        const container = document.getElementById('cardFilesList');
        const verifyBtn = document.getElementById('verifyCardBtn');
        if (cardFiles.length === 0) {
            container.innerHTML = '<p style="font-size:11px;color:var(--text2);margin:0">No associated files</p>';
            if (verifyBtn) verifyBtn.style.display = 'none';
        } else {
            container.innerHTML = cardFiles.map((f, i) => `
                <div style="display:flex;align-items:center;gap:8px;padding:4px 8px;background:var(--bg);border-radius:4px;margin-bottom:4px;font-size:12px">
                    <span style="flex:1;word-break:break-all">📄 ${escHtml(f)}</span>
                    <button type="button" class="btn btn-icon btn-danger" onclick="removeFileFromCard(${i})" style="padding:2px 6px">&times;</button>
                </div>
            `).join('');
            if (verifyBtn && document.getElementById('cardId').value) verifyBtn.style.display = 'block';
        }
    }

    function addFileToCard() {
        const input = document.getElementById('cardFileInput');
        const file = input.value.trim();
        if (!file) return;
        if (!cardFiles.includes(file)) {
            cardFiles.push(file);
            renderCardFiles();
        }
        input.value = '';
    }

    function removeFileFromCard(index) {
        cardFiles.splice(index, 1);
        renderCardFiles();
    }

    async function verifyTaskWithAI() {
        const cardId = document.getElementById('cardId').value;
        if (!cardId) return;

        const resultDiv = document.getElementById('verifyResult');
        resultDiv.style.display = 'block';
        resultDiv.style.background = 'var(--bg)';
        resultDiv.innerHTML = '<p style="text-align:center;color:var(--text2)">🔍 Analysis in progress...</p>';

        const result = await api('verify_task', { card_id: cardId });

        if (!result.success) {
            resultDiv.style.background = 'rgba(239,68,68,0.1)';
            resultDiv.innerHTML = `<p style="color:var(--high)">❌ ${escHtml(result.error)}</p>`;
            return;
        }

        const r = result.result;
        const statusColors = {
            'completed': { bg: 'rgba(34,197,94,0.1)', icon: '✅', color: 'var(--low)' },
            'partial': { bg: 'rgba(245,158,11,0.1)', icon: '⚠️', color: 'var(--medium)' },
            'not_completed': { bg: 'rgba(239,68,68,0.1)', icon: '❌', color: 'var(--high)' },
            'bug_found': { bg: 'rgba(239,68,68,0.1)', icon: '🐛', color: 'var(--high)' }
        };
        const s = statusColors[r.status] || statusColors['not_completed'];

        resultDiv.style.background = s.bg;
        resultDiv.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <span style="font-size:20px">${s.icon}</span>
                <strong style="color:${s.color};text-transform:uppercase">${r.status}</strong>
                <span style="margin-left:auto;font-size:11px;color:var(--text2)">Confidence: ${r.confidence || '?'}%</span>
            </div>
            <p style="margin-bottom:6px"><strong>Analysis:</strong> ${escHtml(r.analysis || '')}</p>
            ${r.evidence ? `<p style="margin-bottom:6px;font-size:11px;color:var(--text2)"><strong>Evidence:</strong> ${escHtml(r.evidence)}</p>` : ''}
            ${r.suggestion ? `<p style="margin-bottom:6px;padding:8px;background:var(--bg2);border-radius:4px"><strong>💡 Suggestion:</strong> ${escHtml(r.suggestion)}</p>` : ''}
            ${r.can_close ? `<button class="btn btn-primary" onclick="deleteCard()" style="margin-top:8px">✓ Archive Task</button>` : ''}
        `;
    }

    function renderClaudeRuns(card) {
        const runs = card.claude_runs || [];
        const panel = document.getElementById('claudeRunsPanel');
        const list = document.getElementById('claudeRunsList');
        const count = document.getElementById('claudeRunsCount');

        if (runs.length === 0) {
            panel.style.display = 'none';
            return;
        }

        panel.style.display = 'block';
        count.textContent = runs.length;

        list.innerHTML = runs.slice().reverse().map((run, i) => {
            const statusIcon = run.status === 'completed' ? '✅' : '⚠️';
            const statusColor = run.status === 'completed' ? 'var(--low)' : 'var(--medium)';
            const toolCalls = run.tool_calls || 0;
            const edits = run.edits || 0;
            const logEntries = run.log || [];

            let toolsHtml = '';
            const tools = logEntries.filter(e => e.tool);
            if (tools.length > 0) {
                toolsHtml = '<div style="margin-top:8px;border-top:1px solid var(--border);padding-top:8px">';
                logEntries.forEach(e => {
                    if (e.tool) {
                        const isWrite = ['edit_file', 'write_file'].includes(e.tool);
                        const icon = isWrite ? '✏️' : e.tool === 'read_file' ? '📖' : e.tool === 'search_files' ? '🔍' : e.tool === 'list_files' ? '📂' : e.tool === 'complete_task' ? '✅' : '🔧';
                        const inputStr = e.input ? (e.input.path || e.input.query || e.input.subpath || '') : '';
                        toolsHtml += `<div style="padding:3px 0;font-size:11px;display:flex;gap:6px;align-items:flex-start">`
                            + `<span>${icon}</span>`
                            + `<span style="font-weight:500;min-width:80px">${escHtml(e.tool)}</span>`
                            + `<span style="color:var(--text2);word-break:break-all">${escHtml(inputStr)}</span>`
                            + `</div>`;
                    } else if (e.tool_error) {
                        toolsHtml += `<div style="padding:3px 0;font-size:11px;color:var(--high)">❌ ${escHtml(e.tool_error)}</div>`;
                    }
                });
                toolsHtml += '</div>';
            }

            const summaryText = run.summary || '';
            return `
                <div style="padding:12px;border-bottom:1px solid var(--border)">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                        <span>${statusIcon}</span>
                        <strong style="font-size:12px;color:${statusColor}">${run.status === 'completed' ? 'Completato' : 'Parziale'}</strong>
                        <span style="font-size:11px;color:var(--text2)">${escHtml(run.date)}</span>
                        <span style="margin-left:auto;font-size:11px;color:var(--text2)">${toolCalls} tool · ${edits} edit</span>
                    </div>
                    ${summaryText ? `<div style="font-size:12px;color:var(--text);line-height:1.4;margin-bottom:4px;max-height:80px;overflow-y:auto;white-space:pre-wrap">${escHtml(summaryText)}</div>` : ''}
                    ${toolsHtml}
                </div>
            `;
        }).join('');
    }

    async function executeWithClaude() {
        const cardId = document.getElementById('cardId').value;
        if (!cardId) return;
        const card = boardData.cards.find(c => c.id === cardId);
        if (!card) return;

        const statusResp = await api('claude_status', {});
        if (statusResp.configured) {
            executeWithClaudeAgent(cardId, card);
        } else {
            executeWithClaudeManual(card);
        }
    }

    function openInClaudeApp() {
        const cardId = document.getElementById('cardId').value;
        if (!cardId) return;
        const card = boardData.cards.find(c => c.id === cardId);
        if (!card) return;

        const lane = boardData.swimlanes.find(l => l.id === card.swimlane_id);
        const label = card.label_id ? boardData.labels.find(l => l.id === card.label_id) : null;
        const projectName = lane ? lane.name : 'Default';

        let prompt = `Task Ykan — ${projectName}: ${card.title}\n`;
        if (label) prompt += `[${label.name}] `;
        prompt += `Priorità: ${card.priority}\n`;
        if (card.description) prompt += `\n${card.description}\n`;
        if (card.files && card.files.length > 0) {
            prompt += `\nFile: ${card.files.join(', ')}\n`;
        }
        prompt += `\nUsa i tool _Ykan MCP per leggere i file del progetto "${projectName}", implementare le modifiche, e poi spostare il task in Done.`;

        navigator.clipboard.writeText(prompt).then(() => {
            toast('Prompt copiato negli appunti!', 'success');
        }).catch(() => {});

        window.location.href = 'https://claude.ai/new';
    }

    function playCardInTerminal() {
        const cardId = document.getElementById('cardId').value;
        if (!cardId) return;
        const card = boardData.cards.find(c => c.id === cardId);
        if (!card) return;
        const lane = boardData.swimlanes.find(l => l.id === card.swimlane_id);
        if (!lane || !lane.local_path) return;

        const label = card.label_id ? boardData.labels.find(l => l.id === card.label_id) : null;
        let prompt = `Task Ykan #${card.seq || ''} — ${card.title}\n`;
        if (label) prompt += `[${label.name}] `;
        prompt += `Priorità: ${card.priority}\n`;
        if (card.description) prompt += `\n${card.description}\n`;
        if (card.files && card.files.length > 0) prompt += `\nFile: ${card.files.join(', ')}\n`;

        // Fixed session id: lets the Dashboard know this session belongs to this task
        const sessionId = crypto.randomUUID();
        card.sessions = (card.sessions || []).concat([{ id: sessionId, at: new Date().toISOString() }]);
        api('link_session', { card_id: card.id, session_id: sessionId });

        closeCardModal();
        openTerminalModal(lane.id, { launch: 'claude', prompt, sessionId });
    }

    function executeWithClaudeManual(card) {
        const lane = boardData.swimlanes.find(l => l.id === card.swimlane_id);
        const col = boardData.columns.find(c => c.id === card.column_id);
        const label = card.label_id ? boardData.labels.find(l => l.id === card.label_id) : null;
        const projectName = lane ? lane.name : 'Default';
        const projectUrl = lane && lane.url ? lane.url : '';

        let prompt = `Devo completare questo task dal mio Kanban (_Ykan).\n\n`;
        prompt += `**Progetto:** ${projectName}\n`;
        if (projectUrl) prompt += `**URL:** ${projectUrl}\n`;
        prompt += `**Task:** ${card.title}\n`;
        prompt += `**Priorità:** ${card.priority}\n`;
        if (label) prompt += `**Label:** ${label.name}\n`;
        if (col) prompt += `**Colonna attuale:** ${col.name}\n`;
        if (card.description) prompt += `**Descrizione:**\n${card.description}\n`;
        if (card.files && card.files.length > 0) {
            prompt += `\n**File coinvolti:**\n`;
            card.files.forEach(f => prompt += `- ${f}\n`);
        }
        prompt += `\n---\n`;
        prompt += `Usa i tool MCP di _Ykan per:\n`;
        prompt += `1. Leggi i file del progetto "${projectName}" per capire il contesto\n`;
        prompt += `2. Implementa le modifiche necessarie per risolvere il task\n`;
        prompt += `3. Quando hai finito, sposta il task "${card.title}" nella colonna "Done" con move_task\n`;
        prompt += `\nSe qualcosa non è chiaro, chiedi prima di procedere.`;

        navigator.clipboard.writeText(prompt).then(() => {
            toast('Prompt copiato! Si apre Claude.ai...', 'success');
        }).catch(() => {
            toast('Prompt pronto, si apre Claude.ai', 'success');
        });

        setTimeout(() => {
            window.open('https://claude.ai/new', '_blank');
        }, 500);
    }

    async function executeWithClaudeAgent(cardId, card) {
        if (!confirm(`Eseguire "${card.title}" con Claude Agent?\n\nClaude leggerà i file, farà le modifiche e completerà il task automaticamente.`)) return;

        const resultDiv = document.getElementById('verifyResult');
        resultDiv.style.display = 'block';
        resultDiv.style.background = 'linear-gradient(135deg, rgba(217,119,6,0.1), rgba(234,88,12,0.1))';
        resultDiv.innerHTML = `
            <div style="text-align:center;padding:20px">
                <div style="font-size:24px;margin-bottom:8px">🏖️</div>
                <p style="font-weight:500;margin-bottom:4px">Claude sta lavorando...</p>
                <p style="font-size:11px;color:var(--text2)">Rilassati, ci pensa lui. Può richiedere 1-3 minuti.</p>
                <div style="margin-top:12px;width:100%;height:3px;background:var(--border);border-radius:2px;overflow:hidden">
                    <div style="width:30%;height:100%;background:linear-gradient(90deg,#d97706,#ea580c);border-radius:2px;animation:claudeProgress 2s ease-in-out infinite"></div>
                </div>
            </div>
        `;

        try {
            const result = await api('claude_execute', { card_id: cardId });

            if (!result.success) {
                resultDiv.style.background = 'rgba(239,68,68,0.1)';
                resultDiv.innerHTML = `<p style="color:var(--high)">❌ ${escHtml(result.error)}</p>`;
                return;
            }

            const bg = result.completed ? 'rgba(34,197,94,0.1)' : 'rgba(245,158,11,0.1)';
            const icon = result.completed ? '✅' : '⚠️';
            const status = result.completed ? 'COMPLETATO' : 'PARZIALE';

            let logHtml = '';
            if (result.log) {
                const toolCalls = result.log.filter(e => e.tool);
                if (toolCalls.length > 0) {
                    logHtml = '<details style="margin-top:8px"><summary style="cursor:pointer;font-size:11px;color:var(--text2)">Log esecuzione (' + toolCalls.length + ' tool call)</summary><div style="margin-top:8px;font-size:11px;max-height:200px;overflow-y:auto">';
                    result.log.forEach(e => {
                        if (e.tool) logHtml += `<div style="padding:4px 8px;margin-bottom:2px;background:var(--bg2);border-radius:4px">🔧 <strong>${escHtml(e.tool)}</strong> ${escHtml(JSON.stringify(e.input).substring(0, 100))}</div>`;
                        else if (e.tool_result) logHtml += `<div style="padding:4px 8px;margin-bottom:2px;font-size:10px;color:var(--text2)">→ ${escHtml(e.tool_result)}</div>`;
                        else if (e.tool_error) logHtml += `<div style="padding:4px 8px;margin-bottom:2px;color:var(--high)">❌ ${escHtml(e.tool_error)}</div>`;
                    });
                    logHtml += '</div></details>';
                }
            }

            resultDiv.style.background = bg;
            resultDiv.innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                    <span style="font-size:20px">${icon}</span>
                    <strong style="text-transform:uppercase">${status}</strong>
                    <span style="margin-left:auto;font-size:11px;color:var(--text2)">${result.turns || 0} tool calls</span>
                </div>
                ${result.summary ? `<div style="white-space:pre-wrap;font-size:13px;line-height:1.5">${escHtml(result.summary)}</div>` : ''}
                ${logHtml}
            `;

            // Save run locally so the panel updates without reload
            if (!card.claude_runs) card.claude_runs = [];
            card.claude_runs.push({
                date: new Date().toISOString().replace('T', ' ').substring(0, 19),
                status: result.completed ? 'completed' : 'partial',
                tool_calls: result.turns || 0,
                edits: result.edits || 0,
                summary: result.summary || '',
                log: result.log || [],
            });
            renderClaudeRuns(card);

            if (result.completed) {
                card.archived = true;
                card.archived_at = new Date().toISOString().split('T')[0];
                setTimeout(() => {
                    closeCardModal();
                    render();
                    toast(`"${card.title}" completato da Claude! 🏖️`, 'success');
                }, 3000);
            }
        } catch (err) {
            resultDiv.style.background = 'rgba(239,68,68,0.1)';
            resultDiv.innerHTML = `<p style="color:var(--high)">❌ Errore di rete: ${escHtml(err.message)}</p>`;
        }
    }

    function closeCardModal() {
        document.getElementById('cardModal').classList.remove('active');
    }

    document.getElementById('cardForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('cardId').value;
        const cardData = {
            title: document.getElementById('cardTitleInput').value,
            description: document.getElementById('cardDescInput').value,
            priority: document.getElementById('cardPriorityInput').value,
            label_id: document.getElementById('cardLabelInput').value || null,
            due_date: document.getElementById('cardDueInput').value || null,
            next_check: document.getElementById('cardNextCheckInput').value || null,
            auto_regenerate: document.getElementById('cardAutoRegenerate').checked,
            regenerate_delay_days: parseInt(document.getElementById('cardRegenerateDelay').value) || 0,
            files: cardFiles,
            epic: document.getElementById('cardEpicInput').value.trim() || null,
            estimate: document.getElementById('cardEstimateInput').value || null,
            parallel: document.getElementById('cardParallel').checked,
            depends_on: cardDepends.map(Number),
            acceptance: cardAcc.map(a => ({ text: a.text, done: !!a.done }))
        };

        if (id) {
            cardData.id = id;
            const card = boardData.cards.find(c => c.id === id);
            if (card) Object.assign(card, cardData);
            await api('update_card', cardData);
        } else {
            cardData.column_id = document.getElementById('cardColumnId').value;
            cardData.swimlane_id = document.getElementById('cardSwimlaneId').value;
            const result = await api('add_card', cardData);
            if (result.success) boardData.cards.push(result.card);
        }

        closeCardModal();
        render();
    });

    async function deleteCard() {
        const id = document.getElementById('cardId').value;
        if (!id || !confirm('Archive this card?')) return;
        const card = boardData.cards.find(c => c.id === id);
        if (card) {
            card.archived = true;
            card.archived_at = new Date().toISOString().split('T')[0];
        }
        closeCardModal();

        const result = await api('archive_card', { id });

        // If auto-regenerated, add new card to local data
        if (result.regenerated && result.new_card) {
            boardData.cards.push(result.new_card);
            toast(`Task "${result.new_card.title}" auto-regenerated`, 'success');
        }

        render();
    }

    async function restoreCard(id) {
        const card = boardData.cards.find(c => c.id === id);
        if (card) {
            card.archived = false;
            delete card.archived_at;
        }
        render();
        await api('restore_card', { id });
    }

    async function permanentDeleteCard(id) {
        if (!confirm('Permanently delete this card?')) return;
        boardData.cards = boardData.cards.filter(c => c.id !== id);
        render();
        await api('delete_card', { id });
    }

    // === CONFIG ===
    async function openConfigModal() {
        document.getElementById('configProjectName').value = boardData.config.project_name || '';
        document.getElementById('configLanguage').value = boardData.config.ai_language || 'en';
        document.getElementById('configGeminiKey').value = boardData.config.gemini_api_key || '';
        document.getElementById('configGithubToken').value = boardData.config.github_token || '';
        document.getElementById('configGithubEnvNote').style.display = ykanGithubEnvToken ? 'block' : 'none';
        document.getElementById('configGithubRepo').value = boardData.config.github_repo || '';
        renderLabelsManager();
        document.getElementById('configModal').classList.add('active');

        const badge = document.getElementById('claudeKeyBadge');
        badge.textContent = '...';
        badge.style.background = 'var(--bg)';
        badge.style.color = 'var(--text2)';
        const status = await api('claude_status', {});
        if (status.configured) {
            badge.textContent = 'Attivo';
            badge.style.background = 'var(--low)';
            badge.style.color = 'white';
        } else {
            badge.textContent = 'Non configurato';
            badge.style.background = 'var(--medium)';
            badge.style.color = 'white';
        }
    }

    function closeConfigModal() {
        document.getElementById('configModal').classList.remove('active');
    }

    document.getElementById('configForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const config = {
            project_name: document.getElementById('configProjectName').value,
            ai_language: document.getElementById('configLanguage').value,
            gemini_api_key: document.getElementById('configGeminiKey').value,
            github_token: document.getElementById('configGithubToken').value,
            github_repo: document.getElementById('configGithubRepo').value,
            theme: boardData.config.theme
        };
        boardData.config = config;
        document.getElementById('projectName').textContent = config.project_name;
        document.title = `_Ykan - ${config.project_name}`;
        closeConfigModal();
        await api('save_config', config);
    });

    // === LABELS ===
    async function addLabel() {
        const result = await api('add_label', { name: 'New Label', color: '#6b7280' });
        if (result.success) {
            boardData.labels.push(result.label);
            renderLabelsManager();
        }
    }

    async function updateLabelName(id, name) {
        const label = boardData.labels.find(l => l.id === id);
        if (label) label.name = name;
        await api('update_label', { id, name });
    }

    async function updateLabelColor(id, color) {
        const label = boardData.labels.find(l => l.id === id);
        if (label) label.color = color;
        await api('update_label', { id, color });
    }

    async function deleteLabel(id) {
        boardData.labels = boardData.labels.filter(l => l.id !== id);
        renderLabelsManager();
        await api('delete_label', { id });
    }

    // === THEME ===
    const THEME_KEYS = ['bg','bg2','bg3','text','text2','border','accent','accent2','high','medium','low','shadow'];
    const THEME_LAYOUT_KEYS = ['radius','card-radius','card-pad','gap','col-min','cell-pad','font-base','header-pad','blur'];
    const THEME_SURFACE_KEYS = ['header-bg','header-text','swimlane-bg','colhead-bg','card-bg'];
    let customThemes = []; // [{file,name,author,description,dark,colors,layout,surfaces,css}]

    function toggleTheme() {
        // Toggle strictly between the two built-in themes (clears any custom).
        const next = (boardData.config.theme === 'dark' && !boardData.config.theme_file) ? 'light' : 'dark';
        onThemeSelect(next);
    }

    // Apply a theme by id: 'light' | 'dark' | 'file:<slug>'
    function applyThemeById(id) {
        const styleEl = document.getElementById('customThemeStyle');
        if (id === 'light' || id === 'dark') {
            styleEl.textContent = '';
            document.body.classList.toggle('dark', id === 'dark');
        } else if (id.startsWith('file:')) {
            const slug = id.slice(5);
            const t = customThemes.find(x => x.file === slug);
            if (!t) { applyThemeById('light'); return; }
            document.body.classList.remove('dark'); // custom supplies all vars
            styleEl.textContent = themeToCss(t);
        }
    }

    function themeToCss(t) {
        const colors = t.colors || {}, layout = t.layout || {}, surfaces = t.surfaces || {};
        const decls = [];
        THEME_KEYS.forEach(k => { if (colors[k]) decls.push(`--${k}:${colors[k]}`); });
        THEME_LAYOUT_KEYS.forEach(k => { if (layout[k]) decls.push(`--${k}:${layout[k]}`); });
        THEME_SURFACE_KEYS.forEach(k => { if (surfaces[k]) decls.push(`--${k}:${surfaces[k]}`); });
        let css = decls.length ? `:root{${decls.join(';')}}` : '';
        if (t.css) css += '\n' + String(t.css).replace(/<\/style/gi, '');
        return css;
    }

    function currentThemeId() {
        return boardData.config.theme_file ? ('file:' + boardData.config.theme_file) : (boardData.config.theme || 'light');
    }

    function onThemeSelect(id) {
        applyThemeById(id);
        if (id.startsWith('file:')) {
            boardData.config.theme_file = id.slice(5);
            const t = customThemes.find(x => x.file === boardData.config.theme_file);
            boardData.config.theme = (t && t.dark) ? 'dark' : 'light';
        } else {
            boardData.config.theme_file = '';
            boardData.config.theme = id;
        }
        syncThemeSelect();
        api('save_config', boardData.config);
    }

    function syncThemeSelect() {
        const sel = document.getElementById('themeSelect');
        if (sel) sel.value = currentThemeId();
    }

    function populateThemeSelect() {
        const sel = document.getElementById('themeSelect');
        if (!sel) return;
        const opts = ['<option value="light">☀️ Light</option>', '<option value="dark">🌙 Dark</option>'];
        customThemes.forEach(t => {
            opts.push(`<option value="file:${t.file}">${t.dark ? '🌘' : '🎨'} ${escHtml(t.name)}</option>`);
        });
        sel.innerHTML = opts.join('');
        syncThemeSelect();
    }

    async function loadThemes() {
        const res = await api('list_themes');
        customThemes = (res && res.themes) ? res.themes : [];
        populateThemeSelect();
    }

    function openThemesModal() {
        renderThemesList();
        document.getElementById('themesModal').classList.add('active');
    }
    function closeThemesModal() {
        document.getElementById('themesModal').classList.remove('active');
    }

    function renderThemesList() {
        const box = document.getElementById('themesList');
        if (!customThemes.length) {
            box.innerHTML = '<div style="color:var(--text2);font-size:13px">Nessun tema personalizzato ancora.</div>';
            return;
        }
        box.innerHTML = customThemes.map(t => {
            const swatches = ['bg','bg2','accent','accent2','high','low']
                .map(k => `<span title="${k}" style="width:16px;height:16px;border-radius:3px;border:1px solid rgba(128,128,128,.4);background:${t.colors[k] || 'transparent'}"></span>`).join('');
            const active = currentThemeId() === ('file:' + t.file);
            return `<div style="display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:6px">
                        <div style="display:flex;gap:3px">${swatches}</div>
                        <div style="flex:1">
                            <div style="font-weight:600;font-size:13px">${escHtml(t.name)} ${t.dark ? '🌘' : ''} ${active ? '<span style="color:var(--accent);font-size:11px">• attivo</span>' : ''}</div>
                            <div style="font-size:11px;color:var(--text2)">${escHtml(t.author || '—')}${t.description ? ' · ' + escHtml(t.description) : ''}</div>
                        </div>
                        <button class="btn" onclick="onThemeSelect('file:${t.file}');renderThemesList()">Applica</button>
                        <button class="btn btn-danger" onclick="deleteTheme('${t.file}')" title="Elimina">✕</button>
                    </div>`;
        }).join('');
    }

    async function saveThemeFromInput() {
        const raw = document.getElementById('themeJsonInput').value.trim();
        if (!raw) { toast('Incolla il JSON del tema', 'error'); return; }
        let parsed;
        try { parsed = JSON.parse(raw); } catch (e) { toast('JSON non valido: ' + e.message, 'error'); return; }
        const res = await api('save_theme', { theme: parsed });
        if (!res.success) return;
        document.getElementById('themeJsonInput').value = '';
        await loadThemes();
        renderThemesList();
        toast('Tema salvato', 'success');
    }

    function uploadThemeFile(ev) {
        const file = ev.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            document.getElementById('themeJsonInput').value = reader.result;
            saveThemeFromInput();
        };
        reader.readAsText(file);
        ev.target.value = '';
    }

    async function deleteTheme(fileSlug) {
        if (!confirm('Eliminare questo tema?')) return;
        if (boardData.config.theme_file === fileSlug) onThemeSelect('light');
        await api('delete_theme', { file: fileSlug });
        await loadThemes();
        renderThemesList();
    }

    function currentColors() {
        // Read the effective computed CSS variables → a colors object.
        const cs = getComputedStyle(document.body);
        const colors = {};
        THEME_KEYS.forEach(k => { colors[k] = cs.getPropertyValue('--' + k).trim(); });
        return colors;
    }

    function currentLayout() {
        const cs = getComputedStyle(document.body);
        const layout = {};
        THEME_LAYOUT_KEYS.forEach(k => { layout[k] = cs.getPropertyValue('--' + k).trim(); });
        return layout;
    }

    function copyCurrentTheme() {
        const theme = {
            name: (boardData.config.project_name || 'My') + ' theme',
            author: 'me', description: '', dark: document.body.classList.contains('dark'),
            colors: currentColors(), layout: currentLayout()
        };
        navigator.clipboard.writeText(JSON.stringify(theme, null, 2))
            .then(() => toast('Tema attuale copiato', 'success'))
            .catch(() => toast('Copia non riuscita', 'error'));
    }

    function copyThemeSpec() {
        const spec = `Create a full theme for a Kanban board as a JSON object with EXACTLY this shape.
It controls BOTH colors AND layout dimensions (density, shapes, spacing).

{
  "name": "Theme name",
  "author": "who made it",
  "description": "one line",
  "dark": true,
  "colors": {
    "bg":      "#RRGGBB",   // app background (page)
    "bg2":     "#RRGGBB",   // header / swimlane / column bars
    "bg3":     "#RRGGBB",   // cards, modals, panels (raised surfaces) — may be rgba() for glass
    "text":    "#RRGGBB",   // primary text
    "text2":   "#RRGGBB",   // secondary / muted text
    "border":  "#RRGGBB",   // borders & dividers
    "accent":  "#RRGGBB",   // primary accent (buttons, links, focus)
    "accent2": "#RRGGBB",   // accent hover / stronger accent
    "high":    "#RRGGBB",   // high priority (red-ish)
    "medium":  "#RRGGBB",   // medium priority (amber-ish)
    "low":     "#RRGGBB",   // low priority (green-ish)
    "shadow":  "0 1px 3px rgba(0,0,0,0.3)"  // full CSS box-shadow value
  },
  "layout": {
    "radius":      "8px",       // corners of swimlanes/modals/panels
    "card-radius": "6px",       // corners of task cards (big = rounded, 0 = square)
    "card-pad":    "10px",      // inner padding of cards (small = dense)
    "gap":         "8px",       // vertical space between cards
    "col-min":     "280px",     // column width → density (e.g. 200px dense, 340px roomy)
    "cell-pad":    "8px",       // padding inside a column body
    "font-base":   "13px",      // base font size (11px compact, 15px large)
    "header-pad":  "12px 20px", // top header padding
    "blur":        "0px"        // backdrop blur for glassmorphism (e.g. 12px). If >0, make bg3 an rgba() with alpha ~0.5
  },
  "surfaces": {                 // OPTIONAL. Any of these may be a gradient/image, not just a color.
    "header-bg":   "linear-gradient(135deg,#5b2be0,#c026d3)", // top app bar background
    "header-text": "#ffffff",   // text/icons color inside the top app bar
    "swimlane-bg": "linear-gradient(90deg,#1e293b,#334155)",  // the project (swimlane) bar
    "colhead-bg":  "#1e293b",   // the column-titles row (To Do / In Progress …)
    "card-bg":     "#1c2233"    // task cards (can be an rgba() for glass)
  },
  "css": ""                     // OPTIONAL escape hatch: raw CSS injected verbatim for full control.
}

Selectors you can target in "css": .header, .swimlane-header, .column-header-row,
.column-header, .cell, .card, .card:hover, .btn, .btn-primary, .column-count, .card-title.

Rules:
- "dark": true if the background is dark, false if light.
- Ensure strong contrast: text on bg, bg2 and bg3 must be easily readable (WCAG AA).
- "colors" is required in full. "layout", "surfaces" and "css" are optional.
- Use "surfaces" for BOLD headers with gradients (header-bg / swimlane-bg / colhead-bg). If a header
  gets a dark gradient, set "header-text" to a light color for contrast.
- "css" lets you add glows, borders, hover effects, custom fonts, etc. Keep it valid CSS only.
- For glass: set "blur" ~12px and "card-bg"/"bg3" to an rgba() like "rgba(255,255,255,0.08)".
- Output ONLY the JSON, no markdown, no comments.`;
        navigator.clipboard.writeText(spec)
            .then(() => toast('Spec copiata — incollala in qualsiasi AI', 'success'))
            .catch(() => toast('Copia non riuscita', 'error'));
    }

    // === PANELS ===
    function pmCloseOthers() {
        ['archivePanel', 'geminiPanel', 'githubPanel', 'pmPanel'].forEach(id =>
            document.getElementById(id).classList.remove('open'));
    }

    function toggleArchive() {
        const p = document.getElementById('archivePanel');
        const open = !p.classList.contains('open');
        pmCloseOthers();
        p.classList.toggle('open', open);
    }

    function toggleGemini() {
        const p = document.getElementById('geminiPanel');
        const open = !p.classList.contains('open');
        pmCloseOthers();
        p.classList.toggle('open', open);
    }

    function toggleGithub() {
        const panel = document.getElementById('githubPanel');
        const isOpening = !panel.classList.contains('open');
        pmCloseOthers();
        panel.classList.toggle('open', isOpening);

        if (isOpening && (boardData.config.github_token || ykanGithubEnvToken) && boardData.config.github_repo) {
            loadGithubData('issues');
            loadGithubRepoInfo();
        }
    }

    // === PM PANEL (spec-driven project manager) ===
    let pmData = null; // last pm_list response

    function togglePm() {
        const p = document.getElementById('pmPanel');
        const open = !p.classList.contains('open');
        pmCloseOthers();
        p.classList.toggle('open', open);
        if (open) {
            const sel = document.getElementById('pmProject');
            if (!sel.options.length) {
                sel.innerHTML = [...boardData.swimlanes].sort((a, b) => a.position - b.position)
                    .map(l => `<option value="${escHtml(l.name)}">${escHtml(l.name)}</option>`).join('');
            }
            // default to the first non-collapsed lane
            const firstOpen = [...boardData.swimlanes].sort((a, b) => a.position - b.position)
                .find(l => !collapsedLanes.has(l.id));
            if (firstOpen && !sel.dataset.touched) sel.value = firstOpen.name;
            pmRefresh();
        }
    }

    function pmProject() { return document.getElementById('pmProject').value; }

    async function pmRefresh() {
        document.getElementById('pmProject').dataset.touched = '1';
        const body = document.getElementById('pmBody');
        body.innerHTML = '<div class="pm-busy">Carico…</div>';
        const res = await api('pm_list', { project: pmProject() });
        if (!res.success) { body.innerHTML = `<div class="pm-busy">⚠️ ${escHtml(res.error || 'errore')}</div>`; return; }
        pmData = res;
        pmRenderList();
    }

    function pmRenderList() {
        const body = document.getElementById('pmBody');
        const proj = pmProject();
        const epics = pmData.epics || [];
        const prds = pmData.prds || [];
        const epicSlugs = new Set(epics.map(e => e.slug));
        const orphanPrds = prds.filter(p => !epicSlugs.has(p.slug));

        body.innerHTML = `
            <div class="pm-sec">
                <h4>Nuovo</h4>
                <button class="btn btn-primary" style="width:100%" onclick="pmNewPrd()">＋ PRD da un'idea</button>
                <div id="pmNewPrdForm" style="display:none;margin-top:8px">
                    <input id="pmIdea" placeholder="Idea in una riga…" style="width:100%;margin-bottom:6px">
                    <textarea id="pmContext" placeholder="Contesto: problema, utenti, criteri di successo, fuori scope…" style="width:100%;min-height:70px;margin-bottom:6px"></textarea>
                    <label style="display:flex;align-items:center;gap:6px;font-size:11px;color:var(--text2);margin-bottom:6px">
                        <input type="checkbox" id="pmStub"> solo bozza (nessuna chiamata AI)
                    </label>
                    <button class="btn btn-primary" style="width:100%" onclick="pmDoPrd()">Crea PRD</button>
                </div>
            </div>

            <div class="pm-sec">
                <h4>Epic (${epics.length})</h4>
                ${epics.length ? epics.map(e => {
                    const tot = e.cards_total || 0, done = e.cards_done || 0;
                    const pct = tot ? Math.round(done / tot * 100) : 0;
                    const st = (e.status || 'draft').toLowerCase();
                    return `
                    <div class="pm-epic">
                        <div class="pm-epic-top">
                            <strong>${escHtml(e.title || e.slug)}</strong>
                            <span class="pm-epic-st ${st}">${escHtml(e.status || 'draft')}</span>
                        </div>
                        ${tot ? `<div class="pm-prog"><i style="width:${pct}%"></i></div>
                        <div style="font-size:11px;color:var(--text2);margin-bottom:6px">${done}/${tot} card in Done</div>` : ''}
                        <div class="pm-epic-actions">
                            <button class="btn" onclick="pmView('epic','${escHtml(e.slug)}')">📄 Epic</button>
                            <button class="btn" onclick="pmDoBreakdown('${escHtml(e.slug)}')">🔨 Breakdown</button>
                            <button class="btn" onclick="pmNext('${escHtml(e.slug)}')">▶ Next</button>
                            <button class="btn" onclick="pmGraph('${escHtml(e.slug)}')">🕸 Grafo</button>
                        </div>
                    </div>`;
                }).join('') : '<p style="font-size:12px;color:var(--text2)">Nessun epic. Parti da un PRD.</p>'}
            </div>

            ${orphanPrds.length ? `
            <div class="pm-sec">
                <h4>PRD senza epic (${orphanPrds.length})</h4>
                ${orphanPrds.map(p => `
                    <div class="pm-doc">
                        <span style="flex:1" onclick="pmView('prd','${escHtml(p.slug)}')">📝 ${escHtml(p.title || p.slug)}</span>
                        <button class="btn" onclick="pmDoEpic('${escHtml(p.slug)}')">→ Epic</button>
                    </div>`).join('')}
            </div>` : ''}

            <div class="pm-sec">
                <h4>Report</h4>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <button class="btn" style="flex:1" onclick="pmStandup()">📋 Standup</button>
                    <button class="btn" style="flex:1" onclick="pmBlocked()">⛔ Blocked</button>
                    <button class="btn" style="flex:1" onclick="pmDashboard()">📊 Dashboard</button>
                </div>
            </div>

            <div id="pmOut"></div>
        `;
    }

    function pmNewPrd() {
        const f = document.getElementById('pmNewPrdForm');
        f.style.display = f.style.display === 'none' ? 'block' : 'none';
    }

    function pmOut(html) { const o = document.getElementById('pmOut'); if (o) o.innerHTML = html; }
    function pmBusy(msg) { pmOut(`<div class="pm-busy">⏳ ${escHtml(msg)}</div>`); }

    async function pmDoPrd() {
        const idea = document.getElementById('pmIdea').value.trim();
        if (!idea) { toast('Scrivi un\'idea', 'error'); return; }
        const context = document.getElementById('pmContext').value.trim();
        const stub = document.getElementById('pmStub') && document.getElementById('pmStub').checked;
        pmBusy(stub ? 'Creo la bozza…' : 'Genero il PRD con Claude…');
        const res = await api('pm_prd', { project: pmProject(), idea, context, stub });
        if (!res.success) { pmOut(`<div class="pm-busy">⚠️ ${escHtml(res.error)}</div>`); return; }
        toast('PRD creato: ' + res.slug, 'success');
        await pmRefresh();
        pmOut(`<div class="pm-out">${escHtml(res.content)}</div>`);
    }

    // Quick-capture: open the panel, expand the New PRD form, focus the idea.
    function pmQuickIdea() {
        const p = document.getElementById('pmPanel');
        if (!p.classList.contains('open')) togglePm();
        setTimeout(() => {
            const f = document.getElementById('pmNewPrdForm');
            if (f) f.style.display = 'block';
            const i = document.getElementById('pmIdea');
            if (i) i.focus();
        }, 120);
    }

    async function pmDoEpic(slug) {
        pmBusy('Genero l\'epic tecnico…');
        const res = await api('pm_epic', { project: pmProject(), slug });
        if (!res.success) { pmOut(`<div class="pm-busy">⚠️ ${escHtml(res.error)}</div>`); return; }
        toast('Epic creato: ' + res.slug, 'success');
        await pmRefresh();
        pmOut(`<div class="pm-out">${escHtml(res.content)}</div>`);
    }

    async function pmDoBreakdown(slug) {
        if (!confirm(`Breakdown dell'epic "${slug}"? Crea le card su Ykan.`)) return;
        pmBusy('Scompongo l\'epic in card…');
        const res = await api('pm_breakdown', { project: pmProject(), slug });
        if (!res.success) { pmOut(`<div class="pm-busy">⚠️ ${escHtml(res.error)}</div>`); return; }
        toast(`${res.count} card create`, 'success');
        const data = await api('get_data');
        if (data.success) { boardData = data.data || data; render(); }
        await pmRefresh();
        pmOut(`<div class="pm-out">${res.created.map(c => `#${c.seq} — ${escHtml(c.title)}${c.depends_on && c.depends_on.length ? '  (dopo #' + c.depends_on.join(' #') + ')' : ''}`).join('\n')}</div>`);
    }

    async function pmView(kind, slug) {
        pmBusy('Carico…');
        const res = await api('pm_read', { project: pmProject(), kind, slug });
        if (!res.success) { pmOut(`<div class="pm-busy">⚠️ ${escHtml(res.error)}</div>`); return; }
        pmOut(`<div class="pm-out">${escHtml(res.content)}</div>`);
    }

    // --- deterministic reports (no API) ---
    function pmLaneCards(slug) {
        const lane = boardData.swimlanes.find(l => l.name === pmProject());
        return boardData.cards.filter(c => !c.archived &&
            (!lane || c.swimlane_id === lane.id) &&
            (!slug || String(c.epic || '') === slug));
    }

    function pmNext(slug) {
        const cards = pmLaneCards(slug).filter(c => !pmIsDone(c));
        const ready = cards.filter(c => cardBlockedBy(c).length === 0);
        if (!ready.length) {
            pmOut(`<div class="pm-out">Nessuna card sbloccata in "${escHtml(slug)}". Vedi ⛔ Blocked.</div>`);
            return;
        }
        const rank = { high: 0, medium: 1, low: 2 };
        ready.sort((a, b) => (rank[a.priority] ?? 1) - (rank[b.priority] ?? 1));
        const next = ready[0];
        const others = ready.slice(1).filter(c => c.parallel);
        pmOut(`<div class="pm-out"><strong>▶ Prossima:</strong> #${next.seq} — ${escHtml(next.title)} [${next.priority}]
${others.length ? '\n‖ in parallelo: ' + others.map(c => '#' + c.seq).join(', ') : ''}</div>`);
        const el = document.querySelector(`.card[data-card-id="${next.id}"]`);
        if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.style.outline = '2px solid var(--accent)';
            setTimeout(() => el.style.outline = '', 2500);
        }
    }

    function pmStandup() {
        const lane = boardData.swimlanes.find(l => l.name === pmProject());
        const all = boardData.cards.filter(c => !lane || c.swimlane_id === lane.id);
        const active = all.filter(c => !c.archived);
        const inProg = active.filter(c => /progress|corso/i.test(pmColName(c)));
        const doneRecent = all.filter(c => c.archived)
            .sort((a, b) => (b.archived_at || '').localeCompare(a.archived_at || '')).slice(0, 6);
        const blocked = active.filter(c => cardBlockedBy(c).length);
        const nextUp = active.filter(c => !pmIsDone(c) && !cardBlockedBy(c).length &&
            /to.?do|backlog/i.test(pmColName(c))).slice(0, 6);
        const line = c => `#${c.seq} ${escHtml(c.title)}${c.epic ? ' · 🎯' + escHtml(c.epic) : ''}`;
        pmOut(`<div class="pm-out"><strong>📋 Standup — ${escHtml(pmProject())} — ${new Date().toISOString().slice(0, 10)}</strong>

▶ In corso (${inProg.length})
${inProg.map(line).join('\n') || '—'}

✅ Chiuse di recente (${doneRecent.length})
${doneRecent.map(line).join('\n') || '—'}

⏭ Prossime sbloccate (${nextUp.length})
${nextUp.map(line).join('\n') || '—'}

🔒 Bloccate (${blocked.length})
${blocked.map(c => line(c) + ' — attende #' + cardBlockedBy(c).join(' #')).join('\n') || '—'}</div>`);
    }

    function pmBlocked() {
        const lane = boardData.swimlanes.find(l => l.name === pmProject());
        const blocked = boardData.cards.filter(c => !c.archived &&
            (!lane || c.swimlane_id === lane.id) && cardBlockedBy(c).length);
        if (!blocked.length) { pmOut('<div class="pm-out">Niente di bloccato. 🎉</div>'); return; }
        pmOut(`<div class="pm-out">${blocked.map(c => {
            const by = cardBlockedBy(c).map(seq => {
                const d = boardData.cards.find(x => Number(x.seq) === Number(seq));
                return `#${seq}${d ? ' (' + escHtml(d.title.slice(0, 40)) + ')' : ''}`;
            });
            return `#${c.seq} ${escHtml(c.title)}\n   ⤷ attende: ${by.join(', ')}`;
        }).join('\n\n')}</div>`);
    }

    // --- dependency mini-graph (layered DAG) ---
    function pmGraph(slug) {
        const nodes = pmLaneCards(slug);
        if (!nodes.length) { pmOut(`<div class="pm-out">Nessuna card per "${escHtml(slug)}".</div>`); return; }
        const bySeq = new Map(nodes.map(c => [Number(c.seq), c]));
        const depthOf = new Map();
        const calc = (c, seen) => {
            const s = Number(c.seq);
            if (depthOf.has(s)) return depthOf.get(s);
            if (seen.has(s)) return 0;               // cycle guard
            seen.add(s);
            const deps = (c.depends_on || []).filter(d => bySeq.has(Number(d)));
            const d = deps.length ? 1 + Math.max(...deps.map(x => calc(bySeq.get(Number(x)), seen))) : 0;
            depthOf.set(s, d);
            return d;
        };
        nodes.forEach(c => calc(c, new Set()));
        const cols = {};
        nodes.forEach(c => { const d = depthOf.get(Number(c.seq)); (cols[d] = cols[d] || []).push(c); });
        const COLW = 168, ROWH = 56, PADX = 12, PADY = 12, NW = 148, NH = 40;
        const maxRows = Math.max(...Object.values(cols).map(a => a.length));
        const W = (Object.keys(cols).length) * COLW + PADX;
        const H = maxRows * ROWH + PADY;
        const pos = new Map();
        Object.keys(cols).forEach(d => cols[d].forEach((c, i) => {
            pos.set(Number(c.seq), { x: d * COLW + PADX, y: i * ROWH + PADY });
        }));
        const stColor = c => pmIsDone(c) ? 'var(--low)'
            : (/progress|corso/i.test(pmColName(c)) ? 'var(--medium)'
            : (cardBlockedBy(c).length ? 'var(--high)' : 'var(--bg2)'));
        let edges = '';
        nodes.forEach(c => (c.depends_on || []).forEach(d => {
            if (!pos.has(Number(d))) return;
            const a = pos.get(Number(d)), b = pos.get(Number(c.seq));
            edges += `<line x1="${a.x + NW}" y1="${a.y + NH / 2}" x2="${b.x}" y2="${b.y + NH / 2}"
                stroke="var(--text2)" stroke-width="1.5" marker-end="url(#pmarr)"/>`;
        }));
        const rects = nodes.map(c => {
            const p = pos.get(Number(c.seq));
            const t = ('#' + c.seq + ' ' + c.title).slice(0, 22);
            return `<g style="cursor:pointer" onclick="closePmOverlayThenOpen('${c.id}')">
                <rect x="${p.x}" y="${p.y}" width="${NW}" height="${NH}" rx="6"
                    fill="${stColor(c)}" stroke="var(--border)"/>
                <text x="${p.x + 8}" y="${p.y + 24}" font-size="11" fill="var(--text)">${escHtml(t)}</text>
            </g>`;
        }).join('');
        pmOut(`<div class="pm-out" style="overflow:auto">
            <div style="font-size:11px;color:var(--text2);margin-bottom:6px">🟩 done · 🟧 in corso · 🟥 bloccata · ⬜ to&nbsp;do — clic per aprire</div>
            <svg width="${W}" height="${Math.max(H, 60)}" xmlns="http://www.w3.org/2000/svg">
                <defs><marker id="pmarr" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
                    <path d="M0,0 L8,4 L0,8 z" fill="var(--text2)"/></marker></defs>
                ${edges}${rects}
            </svg></div>`);
    }
    function closePmOverlayThenOpen(id) { openCardModal(id); }

    // --- PM dashboard: WIP, flow health, per-epic ---
    function pmDashboard() {
        const lane = boardData.swimlanes.find(l => l.name === pmProject());
        const active = boardData.cards.filter(c => !c.archived && (!lane || c.swimlane_id === lane.id));
        const bar = (n, max) => {
            const w = max ? Math.round(n / max * 100) : 0;
            return `<span style="display:inline-block;height:8px;width:${w}%;min-width:2px;background:var(--accent);border-radius:2px;vertical-align:middle"></span>`;
        };
        const cols = [...boardData.columns].sort((a, b) => a.position - b.position);
        const perCol = cols.map(col => ({ name: col.name, n: active.filter(c => c.column_id === col.id).length }));
        const maxCol = Math.max(1, ...perCol.map(x => x.n));
        const blocked = active.filter(c => cardBlockedBy(c).length);
        const ready = active.filter(c => !pmIsDone(c) && !cardBlockedBy(c).length && c.parallel);
        const withAcc = active.filter(c => (c.acceptance || []).length);
        const inProg = active.filter(c => /progress|corso/i.test(pmColName(c)));
        const now = Date.now();
        const stale = inProg.filter(c => c.created_at && (now - new Date(c.created_at.replace(' ', 'T')).getTime()) > 10 * 864e5);
        const epics = {};
        active.forEach(c => { if (c.epic) (epics[c.epic] = epics[c.epic] || []).push(c); });
        const epicRows = Object.entries(epics).map(([name, cs]) => {
            const done = cs.filter(c => pmIsDone(c)).length;
            const blk = cs.filter(c => cardBlockedBy(c).length).length;
            return `🎯 ${escHtml(name)} — ${done}/${cs.length}${blk ? '  🔒' + blk : ''}`;
        }).join('\n') || '—';
        pmOut(`<div class="pm-out"><strong>📊 Dashboard — ${escHtml(pmProject())}</strong>

WIP per colonna
${perCol.map(x => `${x.name.padEnd(13, ' ').slice(0, 13)} ${String(x.n).padStart(3)} ${bar(x.n, maxCol)}`).join('\n')}

Flusso
bloccate         ${String(blocked.length).padStart(3)}
parallele pronte  ${String(ready.length).padStart(3)}
con acceptance   ${String(withAcc.length).padStart(3)} / ${active.length}
ferme >10g (in corso) ${String(stale.length).padStart(3)}${stale.length ? '  → #' + stale.map(c => c.seq).join(' #') : ''}

Epic
${epicRows}</div>`);
    }

    let githubCache = { issues: null, prs: null, commits: null, repo: null };
    let currentGithubTab = 'issues';

    function switchGithubTab(tab) {
        currentGithubTab = tab;
        document.querySelectorAll('.github-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`.github-tab[onclick*="${tab}"]`).classList.add('active');
        loadGithubData(tab);
    }

    async function loadGithubRepoInfo() {
        if (githubCache.repo) {
            renderGithubRepoStats(githubCache.repo);
            return;
        }
        const result = await api('github_repo_info');
        if (result.success) {
            githubCache.repo = result.repo;
            renderGithubRepoStats(result.repo);
        }
    }

    function renderGithubRepoStats(repo) {
        const el = document.getElementById('githubRepoStats');
        el.style.display = 'flex';
        el.innerHTML = `
            <div class="github-stat"><div class="github-stat-value">⭐ ${repo.stargazers_count}</div><div class="github-stat-label">Stars</div></div>
            <div class="github-stat"><div class="github-stat-value">🍴 ${repo.forks_count}</div><div class="github-stat-label">Forks</div></div>
            <div class="github-stat"><div class="github-stat-value">👁️ ${repo.watchers_count}</div><div class="github-stat-label">Watchers</div></div>
            <div class="github-stat"><div class="github-stat-value">🐛 ${repo.open_issues_count}</div><div class="github-stat-label">Issues</div></div>
        `;
    }

    async function loadGithubData(tab) {
        const content = document.getElementById('githubContent');

        if (!(boardData.config.github_token || ykanGithubEnvToken) || !boardData.config.github_repo) {
            content.innerHTML = `<div class="github-empty">
                <p>Configure GitHub Token and Repository in Settings.</p>
                <button class="btn btn-primary" onclick="openConfigModal()" style="margin-top:12px">⚙️ Settings</button>
            </div>`;
            return;
        }

        // Check cache
        if (githubCache[tab]) {
            renderGithubTab(tab, githubCache[tab]);
            return;
        }

        content.innerHTML = '<div class="github-loading">Loading...</div>';

        let result;
        if (tab === 'issues') result = await api('github_issues');
        else if (tab === 'prs') result = await api('github_prs');
        else if (tab === 'commits') result = await api('github_commits');

        if (!result.success) {
            content.innerHTML = `<div class="github-empty" style="color:var(--high)">${escHtml(result.error)}</div>`;
            return;
        }

        githubCache[tab] = result;
        renderGithubTab(tab, result);
    }

    function renderGithubTab(tab, data) {
        const content = document.getElementById('githubContent');

        if (tab === 'issues') {
            const issues = data.issues || [];
            if (issues.length === 0) {
                content.innerHTML = '<div class="github-empty">No issues found</div>';
                return;
            }
            content.innerHTML = issues.map(i => `
                <div class="github-item" onclick="window.open('${i.html_url}', '_blank')">
                    <div class="github-item-header">
                        <span class="github-state ${i.state}">${i.state === 'open' ? '🟢 Open' : '🟣 Closed'}</span>
                        <span class="github-item-number">#${i.number}</span>
                    </div>
                    <div class="github-item-title">${escHtml(i.title)}</div>
                    <div class="github-item-meta">
                        <span>👤 ${i.user?.login || 'unknown'}</span>
                        <span>📅 ${new Date(i.created_at).toLocaleDateString('it')}</span>
                        <span>💬 ${i.comments}</span>
                    </div>
                    ${i.labels?.length ? `<div class="github-item-labels">${i.labels.map(l => `<span class="github-label" style="background:#${l.color}">${escHtml(l.name)}</span>`).join('')}</div>` : ''}
                    <button class="btn" style="margin-top:8px;padding:4px 8px;font-size:11px" onclick="event.stopPropagation();createCardFromIssue(${JSON.stringify(i).replace(/"/g, '&quot;')})">+ Create Card</button>
                </div>
            `).join('');
        }

        else if (tab === 'prs') {
            const prs = data.prs || [];
            if (prs.length === 0) {
                content.innerHTML = '<div class="github-empty">No Pull Requests found</div>';
                return;
            }
            content.innerHTML = prs.map(pr => `
                <div class="github-item" onclick="window.open('${pr.html_url}', '_blank')">
                    <div class="github-item-header">
                        <span class="github-state ${pr.merged_at ? 'merged' : pr.state}">${pr.merged_at ? '🟣 Merged' : (pr.state === 'open' ? '🟢 Open' : '🔴 Closed')}</span>
                        <span class="github-item-number">#${pr.number}</span>
                    </div>
                    <div class="github-item-title">${escHtml(pr.title)}</div>
                    <div class="github-item-meta">
                        <span>👤 ${pr.user?.login || 'unknown'}</span>
                        <span>📅 ${new Date(pr.created_at).toLocaleDateString('it')}</span>
                        <span>📝 ${pr.commits || '?'} commits</span>
                    </div>
                </div>
            `).join('');
        }

        else if (tab === 'commits') {
            const commits = data.commits || [];
            if (commits.length === 0) {
                content.innerHTML = '<div class="github-empty">No commits found</div>';
                return;
            }
            content.innerHTML = commits.map(c => `
                <div class="github-item" onclick="window.open('${c.html_url}', '_blank')">
                    <div class="github-item-title">${escHtml(c.commit?.message?.split('\n')[0] || 'No message')}</div>
                    <div class="github-item-meta">
                        <span>👤 ${c.commit?.author?.name || c.author?.login || 'unknown'}</span>
                        <span>📅 ${new Date(c.commit?.author?.date).toLocaleDateString('it')}</span>
                        <span style="font-family:monospace;font-size:10px">${c.sha?.substring(0,7)}</span>
                    </div>
                </div>
            `).join('');
        }
    }

    function createCardFromIssue(issue) {
        const title = `[#${issue.number}] ${issue.title}`;
        const desc = `**GitHub Issue:** [#${issue.number}](${issue.html_url})\n\n${issue.body || ''}\n\n---\n_Imported from GitHub_`;
        const priority = issue.labels?.some(l => l.name.toLowerCase().includes('bug') || l.name.toLowerCase().includes('critical')) ? 'high' : 'medium';

        document.getElementById('cardId').value = '';
        document.getElementById('cardTitleInput').value = title;
        document.getElementById('cardDescInput').value = desc;
        document.getElementById('cardPriorityInput').value = priority;
        document.getElementById('cardColumnId').value = boardData.columns[0]?.id || '';
        document.getElementById('cardSwimlaneId').value = boardData.swimlanes[0]?.id || '';
        document.getElementById('cardModalTitle').textContent = 'New Card from GitHub Issue';
        document.getElementById('templateGroup').style.display = 'block';
        document.getElementById('cardModal').classList.add('active');
        toggleGithub();
    }

    function refreshGithub() {
        githubCache = { issues: null, prs: null, commits: null, repo: null };
        loadGithubData(currentGithubTab);
        loadGithubRepoInfo();
    }

    // === GEMINI ===
    async function askGemini(prompt) {
        if (!boardData.config.gemini_api_key) {
            toast('Configure Gemini API Key first', 'error');
            openConfigModal();
            return;
        }

        const content = document.getElementById('geminiContent');
        content.innerHTML = '<div class="gemini-loading">Analysis in progress...</div>';

        const result = await api('gemini_analyze', { prompt });
        if (result.success) {
            content.innerHTML = `<div class="gemini-response">${escHtml(result.response)}</div>`;
        } else {
            content.innerHTML = `<div class="gemini-response" style="color:var(--high)">${escHtml(result.error)}</div>`;
        }
    }

    async function askGeminiCustom() {
        const input = document.getElementById('geminiCustom');
        const question = input.value.trim();
        if (!question) return;

        if (!boardData.config.gemini_api_key) {
            toast('Configure Gemini API Key first', 'error');
            openConfigModal();
            return;
        }

        const content = document.getElementById('geminiContent');
        content.innerHTML = '<div class="gemini-loading">Analysis in progress...</div>';
        input.value = '';

        const result = await api('gemini_analyze', { prompt: 'custom', custom_prompt: question });
        if (result.success) {
            content.innerHTML = `<div class="gemini-response">${escHtml(result.response)}</div>`;
        } else {
            content.innerHTML = `<div class="gemini-response" style="color:var(--high)">${escHtml(result.error)}</div>`;
        }
    }

    // === PROJECT ANALYSIS ===
    async function analyzeProject() {
        if (!boardData.config.gemini_api_key) {
            toast('Configure Gemini API Key first', 'error');
            openConfigModal();
            return;
        }

        const content = document.getElementById('geminiContent');
        content.innerHTML = '<div class="gemini-loading">🔍 Scanning project...<br><small>Analyzing structure, key files and entry points</small></div>';

        const result = await api('analyze_project');
        if (!result.success) {
            let errorHtml = `<div class="gemini-response" style="color:var(--high)">${escHtml(result.error)}</div>`;
            if (result.debug) {
                errorHtml += `<details style="margin-top:12px;font-size:11px"><summary style="cursor:pointer;color:var(--text2)">Debug response</summary><pre style="margin-top:8px;padding:8px;background:var(--bg);border-radius:4px;overflow:auto;max-height:200px">${escHtml(result.debug)}</pre></details>`;
            }
            content.innerHTML = errorHtml;
            return;
        }

        const data = result.result;
        const analysis = data.analysis || {};
        const tasks = data.suggested_tasks || [];

        let html = '';

        // Analysis Section
        html += '<div class="analysis-section">';
        html += '<h4>📊 Overview</h4>';
        html += `<p style="font-size:12px;color:var(--text2);margin-bottom:8px">${escHtml(analysis.overview || 'N/A')}</p>`;

        if (analysis.tech_stack?.length) {
            html += '<div class="analysis-tags">';
            analysis.tech_stack.forEach(t => html += `<span class="analysis-tag">${escHtml(t)}</span>`);
            html += '</div>';
        }

        if (analysis.architecture) {
            html += `<p style="font-size:11px;color:var(--text2)"><strong>Architecture:</strong> ${escHtml(analysis.architecture)}</p>`;
        }
        html += '</div>';

        // Strengths
        if (analysis.strengths?.length) {
            html += '<div class="analysis-section">';
            html += '<h4>✅ Strengths</h4>';
            html += '<ul class="analysis-list">';
            analysis.strengths.forEach(s => html += `<li>${escHtml(s)}</li>`);
            html += '</ul></div>';
        }

        // Concerns
        if (analysis.concerns?.length) {
            html += '<div class="analysis-section">';
            html += '<h4>⚠️ Concerns</h4>';
            html += '<ul class="analysis-list">';
            analysis.concerns.forEach(c => html += `<li>${escHtml(c)}</li>`);
            html += '</ul></div>';
        }

        // Suggested Tasks
        if (tasks.length) {
            html += '<div class="analysis-section">';
            html += '<h4>📋 Suggested Tasks</h4>';
            tasks.forEach((task, i) => {
                const taskJson = JSON.stringify(task).replace(/'/g, "\\'").replace(/"/g, '&quot;');
                html += `
                    <div class="suggested-task" id="suggested-task-${i}">
                        <div class="suggested-task-header">
                            <div class="suggested-task-priority ${task.priority || 'medium'}"></div>
                            <div class="suggested-task-title">${escHtml(task.title)}</div>
                        </div>
                        <div class="suggested-task-desc">${escHtml(task.description || '')}</div>
                        <div class="suggested-task-footer">
                            <span class="suggested-task-category">${escHtml(task.category || 'task')}</span>
                            <button class="suggested-task-add" onclick="addSuggestedTask(${i}, '${taskJson}')">+ Add</button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
        }

        content.innerHTML = html;
    }

    async function addSuggestedTask(index, taskJson) {
        const task = JSON.parse(taskJson.replace(/&quot;/g, '"'));
        const taskEl = document.getElementById(`suggested-task-${index}`);

        // Map category to label
        const categoryToLabel = {
            'bug': boardData.labels.find(l => l.name.toLowerCase().includes('bug'))?.id,
            'feature': boardData.labels.find(l => l.name.toLowerCase().includes('feature'))?.id,
            'security': boardData.labels.find(l => l.name.toLowerCase().includes('urgent'))?.id,
            'refactor': boardData.labels.find(l => l.name.toLowerCase().includes('task'))?.id
        };

        const cardData = {
            title: task.title,
            description: task.description || '',
            priority: task.priority || 'medium',
            label_id: categoryToLabel[task.category] || null,
            column_id: boardData.columns[0]?.id, // First column (To Do)
            swimlane_id: boardData.swimlanes[0]?.id
        };

        const result = await api('add_card', cardData);
        if (result.success) {
            boardData.cards.push(result.card);
            render();
            taskEl.classList.add('added');
            taskEl.querySelector('.suggested-task-add').textContent = '✓ Added';
            toast(`Task "${task.title}" added!`, 'success');
        }
    }

    // Normalize a user-entered project URL: add a scheme if missing, block
    // non-http(s) schemes (e.g. javascript:) so the link is safe to render.
    function safeUrl(str) {
        const url = (str || '').trim();
        if (!url) return '';
        if (/^https?:\/\//i.test(url)) return url;
        if (/^[a-z][a-z0-9+.-]*:/i.test(url)) return ''; // some other scheme -> reject
        return 'https://' + url; // scheme-less -> assume https
    }

    // === UTILS ===
    function escHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderMarkdown(text) {
        if (!text) return '';
        return escHtml(text)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/`(.+?)`/g, '<code style="background:var(--bg2);padding:1px 4px;border-radius:3px;font-size:11px">$1</code>')
            .replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2" target="_blank" style="color:var(--accent)">$1</a>')
            .replace(/^- (.+)$/gm, '• $1')
            .replace(/\n/g, '<br>');
    }

    // === INIT ===
    render();
    loadThemes(); // populate the theme switcher (built-ins + custom files)
    setTimeout(() => {
        let v = 'kanban';
        try { v = localStorage.getItem('ykan_view') || 'kanban'; } catch (_) {}
        showView(v);
    }, 0);

    // Enter key for Gemini custom input
    document.getElementById('geminiCustom').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') askGeminiCustom();
    });

    // Auto-regenerate checkbox toggle
    document.getElementById('cardAutoRegenerate').addEventListener('change', (e) => {
        document.getElementById('regenerateOptions').style.display = e.target.checked ? 'block' : 'none';
    });

    // Check if first run
    if (!boardData.config.gemini_api_key && boardData.cards.length === 0) {
        setTimeout(openConfigModal, 500);
    }

    // === FILTERS ===
    function applyFilters() {
        const search = document.getElementById('searchInput').value.toLowerCase();
        const labelFilter = document.getElementById('filterLabel').value;
        const priorityFilter = document.getElementById('filterPriority').value;
        const dueFilter = document.getElementById('filterDue').value;
        const epicFilter = document.getElementById('filterEpic').value;
        const today = new Date().toISOString().split('T')[0];
        const weekEnd = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

        document.querySelectorAll('.card[data-card-id]').forEach(cardEl => {
            const cardId = cardEl.dataset.cardId;
            const card = boardData.cards.find(c => c.id === cardId);
            if (!card) return;

            let show = true;

            // Search filter
            if (search && !card.title.toLowerCase().includes(search) &&
                !(card.description || '').toLowerCase().includes(search)) {
                show = false;
            }

            // Label filter
            if (labelFilter && card.label_id !== labelFilter) show = false;

            // Priority filter
            if (priorityFilter && card.priority !== priorityFilter) show = false;

            // Due date filter
            if (dueFilter) {
                const due = card.due_date;
                if (dueFilter === 'overdue' && (!due || due >= today)) show = false;
                if (dueFilter === 'today' && due !== today) show = false;
                if (dueFilter === 'week' && (!due || due < today || due > weekEnd)) show = false;
                if (dueFilter === 'none' && due) show = false;
            }

            // Epic filter
            if (epicFilter) {
                if (epicFilter === '__blocked__') { if (!cardBlockedBy(card).length) show = false; }
                else if (epicFilter === '__none__') { if (card.epic) show = false; }
                else if (String(card.epic || '') !== epicFilter) show = false;
            }

            cardEl.classList.toggle('filtered-out', !show);
        });

        // Auto-expand swimlanes with visible results
        const hasActiveFilters = search || labelFilter || priorityFilter || dueFilter || epicFilter;
        if (hasActiveFilters) {
            document.querySelectorAll('.swimlane').forEach(swimlane => {
                const visibleCards = swimlane.querySelectorAll('.card[data-card-id]:not(.filtered-out)');
                if (visibleCards.length > 0) {
                    swimlane.classList.remove('collapsed');
                }
            });
        }
    }

    function clearFilters() {
        document.getElementById('searchInput').value = '';
        document.getElementById('filterLabel').value = '';
        document.getElementById('filterPriority').value = '';
        document.getElementById('filterDue').value = '';
        document.getElementById('filterEpic').value = '';
        applyFilters();
    }

    // === EXPORT ===
    function exportJSON() {
        const data = JSON.stringify(boardData, null, 2);
        downloadFile(data, `kanban_${Date.now()}.json`, 'application/json');
        toast('Board exported to JSON', 'success');
    }

    function exportCSV() {
        const headers = ['ID', 'Title', 'Description', 'Priority', 'Label', 'Column', 'Swimlane', 'Due Date', 'Archived', 'Created'];
        const rows = boardData.cards.map(c => {
            const label = boardData.labels.find(l => l.id === c.label_id)?.name || '';
            const col = boardData.columns.find(x => x.id === c.column_id)?.name || '';
            const lane = boardData.swimlanes.find(x => x.id === c.swimlane_id)?.name || '';
            return [c.id, c.title, c.description || '', c.priority, label, col, lane, c.due_date || '', c.archived ? 'Yes' : 'No', c.created_at || '']
                .map(v => `"${String(v).replace(/"/g, '""')}"`).join(',');
        });
        const csv = [headers.join(','), ...rows].join('\n');
        downloadFile(csv, `kanban_${Date.now()}.csv`, 'text/csv');
        toast('Board exported to CSV', 'success');
    }

    function downloadFile(content, filename, type) {
        const blob = new Blob([content], { type });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = filename; a.click();
        URL.revokeObjectURL(url);
    }

    // === TEMPLATES ===
    const cardTemplates = {
        bug: {
            title: '[BUG] ',
            description: '**Bug description:**\n\n**Steps to reproduce:**\n- \n\n**Expected behavior:**\n\n**Current behavior:**\n',
            priority: 'high',
            label_id: 'lbl_1'
        },
        feature: {
            title: '[FEATURE] ',
            description: '**Feature description:**\n\n**Motivation:**\n\n**Acceptance criteria:**\n- \n',
            priority: 'medium',
            label_id: 'lbl_2'
        },
        task: {
            title: '',
            description: '**Objective:**\n\n**Notes:**\n',
            priority: 'medium',
            label_id: 'lbl_3'
        },
        docs: {
            title: '[DOCS] ',
            description: '**Section to document:**\n\n**Files involved:**\n- \n',
            priority: 'low',
            label_id: 'lbl_5'
        },
        refactor: {
            title: '[REFACTOR] ',
            description: '**Code to refactor:**\n\n**Motivation:**\n\n**Proposed approach:**\n',
            priority: 'low',
            label_id: 'lbl_3'
        }
    };

    function applyTemplate() {
        const tpl = cardTemplates[document.getElementById('cardTemplate').value];
        if (!tpl) return;
        document.getElementById('cardTitleInput').value = tpl.title;
        document.getElementById('cardDescInput').value = tpl.description;
        document.getElementById('cardPriorityInput').value = tpl.priority;
        document.getElementById('cardLabelInput').value = tpl.label_id;
        document.getElementById('cardTitleInput').focus();
    }

    // === AI FEATURES ===
    async function aiSuggestCategory() {
        const title = document.getElementById('cardTitleInput').value;
        const desc = document.getElementById('cardDescInput').value;
        if (!title) { toast('Enter a title first', 'error'); return; }

        toast('🤖 AI analysis in progress...', 'info');
        const result = await api('ai_categorize', { title, description: desc });

        if (!result.success) { toast(result.error, 'error'); return; }

        const s = result.suggestion;
        if (s.priority) document.getElementById('cardPriorityInput').value = s.priority;
        if (s.label) {
            const labelOpt = [...document.getElementById('cardLabelInput').options].find(o => o.text === s.label);
            if (labelOpt) document.getElementById('cardLabelInput').value = labelOpt.value;
        }
        toast(`✅ ${s.reason || 'Categorization completed'}`, 'success');
    }

    async function aiEstimate() {
        const title = document.getElementById('cardTitleInput').value;
        const desc = document.getElementById('cardDescInput').value;
        if (!title) { toast('Enter a title first', 'error'); return; }

        toast('🤖 Estimating...', 'info');
        const result = await api('ai_estimate', { title, description: desc });

        if (!result.success) { toast(result.error, 'error'); return; }

        const e = result.estimate;
        let msg = `⏱️ Estimate: ${e.estimate || '?'} (complexity: ${e.complexity || '?'})`;
        if (e.breakdown?.length) msg += '\n\nSteps:\n• ' + e.breakdown.join('\n• ');
        alert(msg);
    }

    async function generateStandup() {
        toast('🤖 Generating standup...', 'info');
        const result = await api('ai_standup');

        if (!result.success) { toast(result.error, 'error'); return; }

        // Show in a modal or alert
        const standupHtml = renderMarkdown(result.standup);
        const div = document.createElement('div');
        div.innerHTML = `
            <div style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center" onclick="this.remove()">
                <div style="background:var(--bg);padding:24px;border-radius:12px;max-width:600px;max-height:80vh;overflow:auto" onclick="event.stopPropagation()">
                    <h2 style="margin-bottom:16px">📋 Daily Standup</h2>
                    <div style="font-size:14px;line-height:1.6">${standupHtml}</div>
                    <button class="btn btn-primary" style="margin-top:16px;width:100%" onclick="this.parentElement.parentElement.remove()">Close</button>
                </div>
            </div>`;
        document.body.appendChild(div);
    }

    // Git TODO Scanner
    async function scanTodos() {
        toast('🔍 Scanning TODO in code...', 'info');
        const result = await api('scan_todos');

        if (!result.success) { toast(result.error, 'error'); return; }

        const todos = result.todos || [];
        const typeColors = { TODO: '#3b82f6', FIXME: '#ef4444', HACK: '#f59e0b', XXX: '#8b5cf6', BUG: '#dc2626' };

        let html = '';
        if (todos.length === 0) {
            html = '<p style="color:var(--text2);text-align:center;padding:20px">No TODO found in code!</p>';
        } else {
            html = `<p style="margin-bottom:12px;color:var(--text2)">Found <strong>${todos.length}</strong> TODO in project:</p>`;
            html += '<div style="max-height:400px;overflow-y:auto">';
            todos.forEach(t => {
                html += `<div style="background:var(--bg2);padding:10px;border-radius:8px;margin-bottom:8px;border-left:3px solid ${typeColors[t.type] || '#666'}">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                        <span style="font-weight:600;color:${typeColors[t.type] || '#666'}">${t.type}</span>
                        <span style="font-size:11px;color:var(--text2)">${t.file}:${t.line}</span>
                    </div>
                    <div style="font-size:13px">${escHtml(t.text)}</div>
                    <button class="btn" style="margin-top:8px;padding:4px 8px;font-size:11px" onclick="createCardFromTodo('${escHtml(t.type)}', '${escHtml(t.text).replace(/'/g, "\\'")}', '${escHtml(t.file)}', ${t.line})">
                        + Create Card
                    </button>
                </div>`;
            });
            html += '</div>';
        }

        const legendHtml = `
            <details style="margin-top:16px;padding:12px;background:var(--bg2);border-radius:8px;font-size:12px">
                <summary style="cursor:pointer;font-weight:600;color:var(--accent)">📖 Supported tags (click to expand)</summary>
                <div style="margin-top:12px;font-family:monospace;line-height:2">
                    <div><code style="background:var(--bg);padding:2px 6px;border-radius:4px;color:#3b82f6">// TODO: task description</code> <span style="color:var(--text2)">- Task to complete</span></div>
                    <div><code style="background:var(--bg);padding:2px 6px;border-radius:4px;color:#ef4444">// FIXME: bug description</code> <span style="color:var(--text2)">- Bug to fix (high priority)</span></div>
                    <div><code style="background:var(--bg);padding:2px 6px;border-radius:4px;color:#dc2626">// BUG: bug description</code> <span style="color:var(--text2)">- Known bug (high priority)</span></div>
                    <div><code style="background:var(--bg);padding:2px 6px;border-radius:4px;color:#f59e0b">// HACK: workaround description</code> <span style="color:var(--text2)">- Temporary workaround</span></div>
                    <div><code style="background:var(--bg);padding:2px 6px;border-radius:4px;color:#8b5cf6">// XXX: important note</code> <span style="color:var(--text2)">- Needs attention</span></div>
                </div>
                <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);color:var(--text2)">
                    <strong>Accepted formats:</strong> <code>TAG:</code> or <code>TAG </code> followed by text<br>
                    <strong>Extensions:</strong> .php .js .ts .jsx .tsx .css .html .py .java .c .cpp .h .go .rs
                </div>
            </details>`;

        const div = document.createElement('div');
        div.innerHTML = `
            <div style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center" onclick="this.remove()">
                <div style="background:var(--bg);padding:24px;border-radius:12px;max-width:700px;width:90%;max-height:80vh;overflow:auto" onclick="event.stopPropagation()">
                    <h2 style="margin-bottom:16px">🔍 TODO Scanner</h2>
                    ${html}
                    ${legendHtml}
                    <button class="btn btn-primary" style="margin-top:16px;width:100%" onclick="this.parentElement.parentElement.remove()">Close</button>
                </div>
            </div>`;
        document.body.appendChild(div);
    }

    function createCardFromTodo(type, text, file, line) {
        const title = `[${type}] ${text.substring(0, 50)}${text.length > 50 ? '...' : ''}`;
        const desc = `**Source:** \`${file}:${line}\`\n\n**Description:**\n${text}\n\n**Action required:**\n- [ ] Resolve the ${type}`;
        const priority = type === 'BUG' || type === 'FIXME' ? 'high' : (type === 'HACK' ? 'medium' : 'low');

        document.getElementById('cardId').value = '';
        document.getElementById('cardTitleInput').value = title;
        document.getElementById('cardDescInput').value = desc;
        document.getElementById('cardPriorityInput').value = priority;
        document.getElementById('cardFilesInput').value = file;
        document.getElementById('cardColumnId').value = boardData.columns[0]?.id || '';
        document.getElementById('cardSwimlaneId').value = boardData.swimlanes[0]?.id || '';
        document.getElementById('cardModalTitle').textContent = 'New Card from TODO';
        document.getElementById('templateGroup').style.display = 'block';
        document.getElementById('cardModal').classList.add('active');

        // Close TODO modal
        document.querySelector('[onclick="this.parentElement.parentElement.remove()"]')?.parentElement?.parentElement?.remove();
    }

    // Burndown Chart
    async function showBurndown() {
        toast('📈 Calculating statistics...', 'info');
        const result = await api('burndown_data');

        if (!result.success) { toast(result.error, 'error'); return; }

        const s = result.stats;
        const total = s.total_cards;
        const pctDone = total > 0 ? Math.round((s.completed / total) * 100) : 0;
        const pctProgress = total > 0 ? Math.round((s.in_progress / total) * 100) : 0;
        const pctTodo = total > 0 ? Math.round((s.todo / total) * 100) : 0;

        // Build velocity chart (simple bar chart)
        let velocityHtml = '';
        const velocityDays = Object.entries(s.velocity || {});
        if (velocityDays.length > 0) {
            const maxVel = Math.max(...velocityDays.map(([,v]) => v));
            velocityHtml = '<div style="margin-top:16px"><h4 style="margin-bottom:8px;color:var(--accent)">Velocity (cards/day)</h4>';
            velocityHtml += '<div style="display:flex;align-items:end;gap:4px;height:80px">';
            velocityDays.slice(-14).forEach(([day, count]) => {
                const h = maxVel > 0 ? Math.round((count / maxVel) * 60) : 0;
                velocityHtml += `<div style="flex:1;display:flex;flex-direction:column;align-items:center" title="${day}: ${count}">
                    <span style="font-size:10px;color:var(--text2)">${count}</span>
                    <div style="width:100%;height:${h}px;background:var(--accent);border-radius:2px"></div>
                    <span style="font-size:8px;color:var(--text2);margin-top:2px">${day.slice(-5)}</span>
                </div>`;
            });
            velocityHtml += '</div></div>';
        }

        const div = document.createElement('div');
        div.innerHTML = `
            <div style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center" onclick="this.remove()">
                <div style="background:var(--bg);padding:24px;border-radius:12px;max-width:500px;width:90%" onclick="event.stopPropagation()">
                    <h2 style="margin-bottom:16px">📈 Burndown Chart</h2>

                    <div style="display:flex;justify-content:space-around;text-align:center;margin-bottom:20px">
                        <div>
                            <div style="font-size:32px;font-weight:600;color:var(--low)">${s.completed}</div>
                            <div style="font-size:12px;color:var(--text2)">Completed</div>
                        </div>
                        <div>
                            <div style="font-size:32px;font-weight:600;color:var(--medium)">${s.in_progress}</div>
                            <div style="font-size:12px;color:var(--text2)">In Progress</div>
                        </div>
                        <div>
                            <div style="font-size:32px;font-weight:600;color:var(--high)">${s.todo}</div>
                            <div style="font-size:12px;color:var(--text2)">To Do</div>
                        </div>
                    </div>

                    <div style="background:var(--bg2);border-radius:8px;height:24px;overflow:hidden;display:flex">
                        <div style="width:${pctDone}%;background:var(--low);transition:width 0.3s" title="Completed ${pctDone}%"></div>
                        <div style="width:${pctProgress}%;background:var(--medium);transition:width 0.3s" title="In progress ${pctProgress}%"></div>
                        <div style="width:${pctTodo}%;background:var(--high);transition:width 0.3s" title="To do ${pctTodo}%"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text2);margin-top:4px">
                        <span>🟢 ${pctDone}%</span>
                        <span>🟡 ${pctProgress}%</span>
                        <span>🔴 ${pctTodo}%</span>
                    </div>

                    ${velocityHtml}

                    <div style="margin-top:16px;padding:12px;background:var(--bg2);border-radius:8px">
                        <div style="display:flex;justify-content:space-between;font-size:13px">
                            <span>Total active tasks:</span>
                            <strong>${total}</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:13px;margin-top:4px">
                            <span>Average velocity:</span>
                            <strong>${s.avg_velocity} cards/day</strong>
                        </div>
                    </div>

                    <button class="btn btn-primary" style="margin-top:16px;width:100%" onclick="this.parentElement.parentElement.remove()">Close</button>
                </div>
            </div>`;
        document.body.appendChild(div);
    }

    // Changelog toggle
    function toggleChangelog() {
        document.getElementById('changelogPanel').classList.toggle('active');
    }
    </script>

    <!-- Changelog Button & Panel -->
    <button class="changelog-btn" onclick="toggleChangelog()" title="Changelog">?</button>
    <div id="changelogPanel" class="changelog-panel">
        <div class="changelog-content">
            <div class="changelog-header">
                <span class="changelog-title">_Ykan Changelog</span>
                <button class="changelog-close" onclick="toggleChangelog()">&times;</button>
            </div>

            <div class="changelog-version">
                <h3>v1.8.0 - July 2026</h3>
                <ul>
                    <li>🎨 <strong>Custom Themes</strong> - JSON themes in <code>themes/</code> with a live switcher on top of light/dark</li>
                    <li>🖌️ <strong>Theme editor</strong> - create, save and delete themes; override colors, layout density/shape and surfaces (gradients, glass)</li>
                    <li>📄 <strong>Theme template</strong> - documented <code>_TEMPLATE.jsonc</code> reference for humans & AIs</li>
                    <li>📱 <strong>MCP remote control</strong> - manage the board and edit project files from the Claude mobile app via <code>mcp.php</code></li>
                    <li>🔗 <strong>Projects</strong> - link swimlanes to project folders and browse their files safely</li>
                    <li>🔐 <strong>.env config</strong> - <code>mcp.php</code> now reads <code>MCP_SECRET</code>/<code>MCP_ROOT</code> from <code>.env</code> instead of hardcoded constants</li>
                </ul>
            </div>

            <div class="changelog-version">
                <h3>v1.7.0 - December 2025</h3>
                <ul>
                    <li>🌍 <strong>Full English UI</strong> - complete interface translation</li>
                    <li>🗣️ <strong>AI Response Language</strong> - choose AI response language (EN, IT, ES, FR, DE, PT)</li>
                    <li>⚙️ <strong>Language Settings</strong> - new config option for preferred language</li>
                </ul>
            </div>

            <div class="changelog-version">
                <h3>v1.6.0 - December 2025</h3>
                <ul>
                    <li>🐙 <strong>GitHub Integration</strong> - connect your repository</li>
                    <li>🐛 <strong>View Issues</strong> - see all repo issues</li>
                    <li>🔀 <strong>Pull Requests</strong> - monitor open/closed PRs</li>
                    <li>📜 <strong>Commits</strong> - latest project commits</li>
                    <li>📊 <strong>Repo Stats</strong> - stars, forks, watchers</li>
                    <li>➕ <strong>Issue → Card</strong> - create cards from GitHub issues</li>
                    <li>🔗 <strong>Direct Links</strong> - open issues/PR/commit on GitHub</li>
                </ul>
            </div>

            <div class="changelog-version">
                <h3>v1.5.0 - December 2025</h3>
                <ul>
                    <li>🔍 <strong>Instant Search</strong> - search all tasks</li>
                    <li>🏷️ <strong>Advanced Filters</strong> - by label, priority, due date</li>
                    <li>📝 <strong>Markdown Support</strong> - format descriptions</li>
                    <li>📥 <strong>Export JSON/CSV</strong> - export complete board</li>
                    <li>📋 <strong>Card Templates</strong> - Bug, Feature, Task, Docs, Refactor</li>
                    <li>🤖 <strong>AI Auto-categorization</strong> - suggests label/priority</li>
                    <li>⏱️ <strong>AI Time Estimation</strong> - time and complexity</li>
                    <li>📋 <strong>Daily Standup AI</strong> - generates Agile reports</li>
                    <li>🔍 <strong>TODO Scanner</strong> - find TODO/FIXME in code</li>
                    <li>📈 <strong>Burndown Chart</strong> - statistics and velocity</li>
                </ul>
            </div>

            <div class="changelog-version">
                <h3>v1.4.0 - December 2025</h3>
                <ul>
                    <li>Added <strong>Associated Files</strong> system to tasks</li>
                    <li>New <strong>🤖 AI Verify</strong> button - analyze files and verify task completion</li>
                    <li>Gemini reads associated files and suggests bug fixes</li>
                    <li>📁 indicator on cards with associated files</li>
                    <li>Increased file limits (200KB/file, 500KB total)</li>
                    <li>Added this Changelog panel</li>
                </ul>
            </div>

            <div class="changelog-version">
                <h3>v1.3.0 - December 2025</h3>
                <ul>
                    <li>Implemented <strong>Auto-regenerating Tasks</strong> - recreate when archived</li>
                    <li>Fields <code>auto_regenerate</code> and <code>regenerate_delay_days</code></li>
                    <li>🔄 indicator on auto-regenerating cards</li>
                    <li>Regeneration counter (×N)</li>
                    <li>Default "Recurring" label</li>
                </ul>
            </div>

            <div class="changelog-version">
                <h3>v1.2.0 - December 2025</h3>
                <ul>
                    <li>Added <strong>AI Integration Guide</strong> in PHP header</li>
                    <li>Endpoint <code>get_summary</code> - text summary for AI</li>
                    <li>Endpoint <code>complete_task</code> - archive task by title</li>
                    <li>Endpoint <code>move_task</code> - move task by title</li>
                    <li><strong>Analyze Project</strong> function - scan codebase and suggest tasks</li>
                    <li>Click on suggested tasks to add them to kanban</li>
                </ul>
            </div>

            <div class="changelog-version">
                <h3>v1.1.0 - December 2025</h3>
                <ul>
                    <li><strong>Gemini AI</strong> integration (gemini-2.5-flash)</li>
                    <li>Board analysis with AI</li>
                    <li>Priority and time suggestions</li>
                    <li>Gemini side panel</li>
                    <li>API Key configuration via modal</li>
                </ul>
            </div>

            <div class="changelog-version">
                <h3>v1.0.0 - December 2025</h3>
                <ul>
                    <li>Single-file PHP Kanban board</li>
                    <li>Customizable columns (To Do, In Progress, Done)</li>
                    <li>Swimlanes with collapsible accordion</li>
                    <li>↑↓ arrows to reorder swimlanes</li>
                    <li>Drag & drop cards between columns</li>
                    <li>Labels with colors</li>
                    <li>Priority (high/medium/low)</li>
                    <li>Due dates and next check</li>
                    <li>Archive system (sorted by date)</li>
                    <li>Light/dark theme</li>
                    <li>Auto-save to JSON</li>
                    <li>Toast notifications</li>
                </ul>
            </div>

            <div class="changelog-footer">
                Developed by <strong>Yuri Nuresi</strong> &bull; <a href="mailto:yurrena@gmail.com">yurrena@gmail.com</a>
            </div>
        </div>
    </div>
</body>
</html>
