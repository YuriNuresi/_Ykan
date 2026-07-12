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
 * 2. Create a .env file ABOVE the web root (not web-accessible), containing:
 *      MCP_SECRET=your-long-random-string   (it IS the password)
 *      MCP_ROOT=/homez.NNN/youruser/www     (folder that CONTAINS your projects)
 *    Point MCP_ENV_FILE (below) at its absolute path. Every "Folder" you link to
 *    a swimlane in _Ykan is resolved relative to MCP_ROOT.
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
// CONFIG  —  secret + root are read from a .env file OUTSIDE the served folders
// ============================================================================
// Create a plain-text file (e.g. one level ABOVE this folder, not web-accessible):
//
//     MCP_SECRET=your-long-random-string
//     MCP_ROOT=/homez.NNN/youruser/www
//
// Point MCP_ENV_FILE at its absolute path. Keeping it above the web root means
// it is never served by Apache and never committed to git.
const MCP_ENV_FILE = __DIR__ . '/../.env';  // default: parent of the ykan/ folder
const MCP_ROOT_FALLBACK = '';               // optional: hardcode a root if you skip .env

/** Minimal KEY=VALUE .env parser (supports # comments and optional quotes). */
function mcp_parse_env(string $file): array {
    $out = [];
    if (!is_file($file) || !is_readable($file)) return $out;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $out[$k] = $v;
    }
    return $out;
}

/** Config value: .env wins, then a real environment variable, then default. */
function mcp_cfg(array $env, string $key, string $default = ''): string {
    if (isset($env[$key]) && $env[$key] !== '') return $env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

$mcpEnv = mcp_parse_env(MCP_ENV_FILE);
define('MCP_SECRET', mcp_cfg($mcpEnv, 'MCP_SECRET'));
define('MCP_ROOT',   mcp_cfg($mcpEnv, 'MCP_ROOT', MCP_ROOT_FALLBACK));

const MCP_DATA_FILE = __DIR__ . '/_Ykan_data.json';
const MCP_MAX_READ  = 500_000;   // max bytes returned by read_file
const MCP_MAX_WRITE = 2_000_000; // max bytes accepted by write_file
const MCP_AUDIT_LOG = __DIR__ . '/_ykan_mcp_audit.log';

// ============================================================================
// Boilerplate: headers / CORS / method routing
// ============================================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Mcp-Protocol-Version');

$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$accept  = $_SERVER['HTTP_ACCEPT'] ?? '';
$wantsSse = stripos($accept, 'text/event-stream') !== false;

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// GET: some MCP clients open a GET stream for server-initiated messages. We are
// stateless and have none, so signal that per the Streamable HTTP spec (405).
// A plain browser GET (no SSE) gets a small info page instead — no secret leaked.
if ($method === 'GET') {
    if ($wantsSse) {
        http_response_code(405);
        header('Allow: POST, OPTIONS');
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'name'      => '_Ykan MCP',
        'transport' => 'streamable-http (stateless JSON-RPC 2.0)',
        'usage'     => 'POST JSON-RPC to this URL with ?k=SECRET. Add as a custom connector in claude.ai.',
        'configured'=> MCP_SECRET !== '' && MCP_ROOT !== '' && realpath(MCP_ROOT) !== false,
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

if (MCP_SECRET === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'server not configured: MCP_SECRET missing (.env not found or unreadable)']);
    exit;
}
if (!hash_equals(MCP_SECRET, mcp_provided_secret())) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// Content-Type is chosen at output time (JSON vs SSE), see the dispatch tail.

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

function mcp_audit(string $tool, array $info): void {
    $line = date('c') . "\t" . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\t" . $tool
          . "\t" . json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(MCP_AUDIT_LOG, $line, FILE_APPEND);
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
            'description' => 'List active tasks, optionally filtered by project and/or column.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'project' => $projectArg,
                'column'  => ['type' => 'string', 'description' => 'Column name filter (partial match).'],
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
            ], 'required' => ['project', 'title']],
        ],
        [
            'name' => 'move_task',
            'description' => 'Move a task (matched by title) to another column.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'title'   => ['type' => 'string', 'description' => 'Task title (partial match).'],
                'column'  => ['type' => 'string', 'description' => 'Destination column name (partial match).'],
                'project' => $projectArg,
            ], 'required' => ['title', 'column']],
        ],
        [
            'name' => 'complete_task',
            'description' => 'Complete (archive) a task matched by title. Honors auto-regenerate tasks.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'title'   => ['type' => 'string', 'description' => 'Task title (partial match).'],
                'project' => $projectArg,
            ], 'required' => ['title']],
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
    ];
}

/** Execute a tool. Returns a plain-text result string. Throws McpError on failure. */
function mcp_run_tool(string $name, array $a): string {
    $data = mcp_load();

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
                $rows[] = "- [$col] {$c['title']} (" . ($c['priority'] ?? 'medium') . ($filter ? '' : ", @$lane") . ')';
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

            $card = [
                'id' => mcp_id('card'), 'title' => $title,
                'description' => (string)($a['description'] ?? ''),
                'priority' => $priority, 'due_date' => null, 'next_check' => null,
                'label_id' => null, 'column_id' => $colId, 'swimlane_id' => $lane['id'],
                'archived' => false, 'auto_regenerate' => false, 'regenerate_delay_days' => 0,
                'files' => [], 'position' => 0,
                'created_at' => date('Y-m-d H:i:s'), 'regenerated_count' => 0,
            ];
            $data['cards'][] = $card;
            mcp_save($data);
            mcp_audit('add_task', ['project' => $lane['name'], 'title' => $title]);
            return "Added task '$title' to project '{$lane['name']}'.";
        }

        case 'move_task': {
            $title = trim((string)($a['title'] ?? ''));
            $colName = trim((string)($a['column'] ?? ''));
            $filter = isset($a['project']) ? mcp_find_lane($data, $a['project']) : null;
            $target = null;
            foreach (($data['columns'] ?? []) as $col) {
                if (stripos($col['name'], $colName) !== false) { $target = $col; break; }
            }
            if (!$target) throw new McpError("Column '$colName' not found. Available: " . implode(', ', array_column($data['columns'] ?? [], 'name')));
            foreach ($data['cards'] as &$c) {
                if (!empty($c['archived'])) continue;
                if ($filter && ($c['swimlane_id'] ?? '') !== $filter['id']) continue;
                if (stripos($c['title'], $title) !== false) {
                    $c['column_id'] = $target['id'];
                    mcp_save($data);
                    mcp_audit('move_task', ['title' => $c['title'], 'column' => $target['name']]);
                    return "Moved '{$c['title']}' to '{$target['name']}'.";
                }
            }
            throw new McpError("Task '$title' not found.");
        }

        case 'complete_task': {
            $title = trim((string)($a['title'] ?? ''));
            $filter = isset($a['project']) ? mcp_find_lane($data, $a['project']) : null;
            foreach ($data['cards'] as &$c) {
                if (!empty($c['archived'])) continue;
                if ($filter && ($c['swimlane_id'] ?? '') !== $filter['id']) continue;
                if (stripos($c['title'], $title) !== false) {
                    $c['archived'] = true;
                    $c['archived_at'] = date('Y-m-d H:i:s');
                    $msg = "Completed and archived '{$c['title']}'.";
                    if (!empty($c['auto_regenerate'])) {
                        $delay = (int)($c['regenerate_delay_days'] ?? 0);
                        $due = $delay > 0 ? date('Y-m-d', strtotime("+$delay days")) : null;
                        $data['cards'][] = [
                            'id' => mcp_id('card'), 'title' => $c['title'],
                            'description' => $c['description'] ?? '', 'priority' => $c['priority'] ?? 'medium',
                            'due_date' => $due, 'next_check' => $due, 'label_id' => $c['label_id'] ?? null,
                            'column_id' => $data['columns'][0]['id'], 'swimlane_id' => $c['swimlane_id'],
                            'archived' => false, 'auto_regenerate' => true, 'regenerate_delay_days' => $delay,
                            'position' => 0, 'created_at' => date('Y-m-d H:i:s'),
                            'regenerated_count' => ($c['regenerated_count'] ?? 0) + 1, 'regenerated_from' => $c['id'],
                        ];
                        $msg .= ' Task auto-regenerated' . ($due ? " (due $due)" : '') . '.';
                    }
                    mcp_save($data);
                    mcp_audit('complete_task', ['title' => $c['title']]);
                    return $msg;
                }
            }
            throw new McpError("Task '$title' not found.");
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
// A session id keeps clients happy that require the Streamable HTTP header.
header('Mcp-Session-Id: ' . bin2hex(random_bytes(8)));

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    mcp_emit([mcp_rpc_error(null, -32700, 'Parse error')], false, $wantsSse);
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
mcp_emit($responses, $isBatch, $wantsSse);

/**
 * Send JSON-RPC responses either as plain JSON or as Server-Sent Events,
 * depending on what the client's Accept header asked for. claude.ai's remote
 * MCP client negotiates text/event-stream, so we honor it.
 */
function mcp_emit(array $responses, bool $isBatch, bool $wantsSse): void {
    if ($wantsSse) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        foreach ($responses as $r) {
            echo 'data: ' . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        }
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    $payload = $isBatch ? $responses : $responses[0];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
