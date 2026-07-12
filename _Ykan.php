<?php
/**
 * _Ykan - Minimal Kanban Board
 * Single-file PHP Kanban for Scrum/Agile projects
 *
 * @version 1.7.0
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
const DEFAULT_DATA = [
    'config' => [
        'gemini_api_key' => '',
        'github_token' => '',
        'github_repo' => '',
        'theme' => 'light',
        'project_name' => 'My Project',
        'ai_language' => 'en'
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
    return json_decode($content, true) ?? DEFAULT_DATA;
}

function saveData(array $data): bool {
    return file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function generateId(string $prefix = 'id'): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
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
                    'url' => $input['url'] ?? ''
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

            // Cards
            'add_card' => (function() use (&$data, $input) {
                $card = [
                    'id' => generateId('card'),
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
                        $card = array_merge($card, array_filter($input, fn($k) => $k !== 'id', ARRAY_FILTER_USE_KEY));
                        break;
                    }
                }
                saveData($data);
                return ['success' => true];
            })(),

            'move_card' => (function() use (&$data, $input) {
                foreach ($data['cards'] as &$card) {
                    if ($card['id'] === $input['id']) {
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
                                'regenerated_from' => $card['id']
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
                                'regenerated_from' => $card['id']
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
                $token = $data['config']['github_token'] ?? '';
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
                $token = $data['config']['github_token'] ?? '';
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
                $token = $data['config']['github_token'] ?? '';
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
                $token = $data['config']['github_token'] ?? '';
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
                $token = $data['config']['github_token'] ?? '';
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
                $token = $data['config']['github_token'] ?? '';
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
        }
        .dark {
            --bg: #0f172a; --bg2: #1e293b; --bg3: #334155; --text: #f1f5f9; --text2: #94a3b8;
            --border: #475569; --shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        /* Header */
        .header { display: flex; align-items: center; gap: 12px; padding: 12px 20px; background: var(--bg2); border-bottom: 1px solid var(--border); }
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
        .swimlane { border: 1px solid var(--border); border-radius: 8px; margin-bottom: 12px; overflow: hidden; }
        .swimlane-header { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--bg2); border-bottom: 1px solid var(--border); cursor: pointer; }
        .swimlane-toggle { transition: transform 0.2s; font-size: 12px; color: var(--text2); }
        .swimlane.collapsed .swimlane-toggle { transform: rotate(-90deg); }
        .swimlane.collapsed .cells-row { display: none; }
        .swimlane.collapsed .column-header-row { border-bottom: none; }
        .swimlane-name { font-weight: 600; font-size: 14px; background: transparent; border: none; color: var(--text); }
        .swimlane-name:focus { outline: 1px solid var(--accent); border-radius: 4px; }
        .swimlane-actions { margin-left: auto; display: flex; gap: 4px; opacity: 0; transition: opacity 0.15s; }
        .swimlane:hover .swimlane-actions { opacity: 1; }

        /* Columns */
        .columns-row { display: flex; }
        .column-header-row { display: flex; background: var(--bg2); border-bottom: 1px solid var(--border); }
        .column-header { flex: 1; min-width: 280px; padding: 10px 12px; display: flex; align-items: center; gap: 8px; border-right: 1px solid var(--border); }
        .column-header:last-child { border-right: none; }
        .column-name { font-weight: 500; font-size: 13px; background: transparent; border: none; color: var(--text); flex: 1; }
        .column-name:focus { outline: 1px solid var(--accent); border-radius: 4px; }
        .column-count { font-size: 11px; background: var(--bg); padding: 2px 6px; border-radius: 10px; color: var(--text2); }
        .column-actions { display: flex; gap: 2px; opacity: 0; transition: opacity 0.15s; }
        .column-header:hover .column-actions { opacity: 1; }

        /* Cells */
        .cells-row { display: flex; }
        .cell { flex: 1; min-width: 280px; min-height: 120px; padding: 8px; border-right: 1px solid var(--border); background: var(--bg); }
        .cell:last-child { border-right: none; }
        .cell.drag-over { background: var(--bg2); }

        /* Cards */
        .card { background: var(--bg3); border: 1px solid var(--border); border-radius: 6px; padding: 10px; margin-bottom: 8px; cursor: grab; box-shadow: var(--shadow); transition: all 0.15s; }
        .card:hover { border-color: var(--accent); }
        .card.dragging { opacity: 0.5; transform: rotate(2deg); }
        .card-header { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
        .card-title { font-weight: 500; font-size: 13px; flex: 1; word-break: break-word; }
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
    </style>
</head>
<body class="<?= $data['config']['theme'] === 'dark' ? 'dark' : '' ?>">
    <!-- Header -->
    <header class="header">
        <h1 id="projectName" onclick="openConfigModal()"><?= htmlspecialchars($data['config']['project_name'] ?? 'My Project') ?></h1>
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
            <button class="btn btn-icon" onclick="toggleTheme()" title="Theme">
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
                    <span style="flex:1"></span>
                    <button type="button" class="btn" onclick="closeCardModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
                <div id="verifyResult" style="display:none;margin-top:12px;padding:12px;border-radius:8px;font-size:12px"></div>
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
                <hr style="margin:16px 0;border:none;border-top:1px solid var(--border)">
                <div class="form-group">
                    <label>GitHub Token <span style="font-weight:normal;color:var(--text2)">(Personal Access Token)</span></label>
                    <input type="password" id="configGithubToken" placeholder="ghp_xxxxxxxxxxxx...">
                    <small style="color:var(--text2);font-size:11px">Generate from: GitHub → Settings → Developer settings → Personal access tokens</small>
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

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <script>
    // === STATE ===
    let boardData = <?= $dataJson ?>;
    let draggedCard = null;

    // === API ===
    async function api(action, data = {}) {
        try {
            const res = await fetch(`?api=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await res.json();
            if (result.success) {
                if (action !== 'get_data' && action !== 'gemini_analyze') {
                    toast('Salvato', 'success');
                }
            } else {
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
                <div class="swimlane${index > 0 ? ' collapsed' : ''}" data-lane-id="${lane.id}">
                    <div class="swimlane-header" onclick="toggleSwimlane('${lane.id}', event)">
                        <span class="swimlane-toggle">▼</span>
                        <input class="swimlane-name" value="${escHtml(lane.name)}" onchange="updateSwimlane('${lane.id}', this.value)" onclick="event.stopPropagation()">
                        ${lane.path ? `<span class="swimlane-link" title="Linked to folder: ${escHtml(lane.path)}" onclick="event.stopPropagation();openProjectsModal()" style="cursor:pointer;font-size:13px">🔗</span>` : ''}
                        <div class="swimlane-actions" onclick="event.stopPropagation()">
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
    }

    function renderCard(card) {
        const label = boardData.labels.find(l => l.id === card.label_id);
        const isOverdue = card.due_date && new Date(card.due_date) < new Date();
        const isRecurring = card.auto_regenerate;
        const regenCount = card.regenerated_count || 0;
        const hasFiles = card.files && card.files.length > 0;
        return `
            <div class="card" draggable="true" data-card-id="${card.id}"
                ondragstart="onDragStart(event)" ondragend="onDragEnd(event)"
                onclick="openCardModal('${card.id}')">
                <div class="card-header">
                    <div class="card-priority ${card.priority}"></div>
                    <div class="card-title">${escHtml(card.title)}</div>
                    ${hasFiles ? `<span title="${card.files.length} file associati" style="font-size:12px">📁</span>` : ''}
                    ${isRecurring ? '<span title="Task autorigenerante" style="font-size:12px">🔄</span>' : ''}
                </div>
                ${label ? `<span class="card-label" style="background:${label.color}">${escHtml(label.name)}</span>` : ''}
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
        if (swimlane) swimlane.classList.toggle('collapsed');
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

    async function deleteSwimlane(id) {
        if (boardData.swimlanes.length <= 1) {
            toast('Cannot delete the last swimlane', 'error');
            return;
        }
        if (!confirm('Delete this swimlane? Cards will be moved.')) return;
        const firstLane = boardData.swimlanes[0].id;
        boardData.cards.forEach(c => { if (c.swimlane_id === id) c.swimlane_id = firstLane; });
        boardData.swimlanes = boardData.swimlanes.filter(l => l.id !== id);
        render();
        await api('delete_swimlane', { id });
    }

    // === PROJECTS (swimlane <-> hosting folder links, used by mcp.php) ===
    function openProjectsModal() {
        renderProjectsManager();
        document.getElementById('projectsModal').classList.add('active');
    }

    function closeProjectsModal() {
        document.getElementById('projectsModal').classList.remove('active');
    }

    function renderProjectsManager() {
        const container = document.getElementById('projectsManager');
        const lanes = [...boardData.swimlanes].sort((a, b) => a.position - b.position);
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
                <button class="btn btn-primary" onclick="saveProjectLink('${lane.id}')">Save link</button>
            </div>
        `).join('');
    }

    async function saveProjectLink(id) {
        const path = document.getElementById('proj-path-' + id).value.trim();
        const url = document.getElementById('proj-url-' + id).value.trim();
        const lane = boardData.swimlanes.find(l => l.id === id);
        if (lane) { lane.path = path; lane.url = url; }
        await api('update_swimlane', { id, path, url });
        renderProjectsManager();
        render();
    }

    // === CARDS ===
    let cardFiles = [];

    function openCardModal(cardId = null, colId = null, laneId = null) {
        const modal = document.getElementById('cardModal');
        const form = document.getElementById('cardForm');
        const deleteBtn = document.getElementById('deleteCardBtn');
        const verifyBtn = document.getElementById('verifyCardBtn');
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
            document.getElementById('cardModalTitle').textContent = 'Edit Card';
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
            deleteBtn.style.display = 'block';
            verifyBtn.style.display = cardFiles.length > 0 ? 'block' : 'none';
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
            deleteBtn.style.display = 'none';
            verifyBtn.style.display = 'none';
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
            files: cardFiles
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
    function openConfigModal() {
        document.getElementById('configProjectName').value = boardData.config.project_name || '';
        document.getElementById('configLanguage').value = boardData.config.ai_language || 'en';
        document.getElementById('configGeminiKey').value = boardData.config.gemini_api_key || '';
        document.getElementById('configGithubToken').value = boardData.config.github_token || '';
        document.getElementById('configGithubRepo').value = boardData.config.github_repo || '';
        renderLabelsManager();
        document.getElementById('configModal').classList.add('active');
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
    function toggleTheme() {
        const isDark = document.body.classList.toggle('dark');
        boardData.config.theme = isDark ? 'dark' : 'light';
        api('save_config', boardData.config);
    }

    // === PANELS ===
    function toggleArchive() {
        document.getElementById('archivePanel').classList.toggle('open');
        document.getElementById('geminiPanel').classList.remove('open');
        document.getElementById('githubPanel').classList.remove('open');
    }

    function toggleGemini() {
        document.getElementById('geminiPanel').classList.toggle('open');
        document.getElementById('archivePanel').classList.remove('open');
        document.getElementById('githubPanel').classList.remove('open');
    }

    function toggleGithub() {
        const panel = document.getElementById('githubPanel');
        const isOpening = !panel.classList.contains('open');
        panel.classList.toggle('open');
        document.getElementById('archivePanel').classList.remove('open');
        document.getElementById('geminiPanel').classList.remove('open');

        if (isOpening && boardData.config.github_token && boardData.config.github_repo) {
            loadGithubData('issues');
            loadGithubRepoInfo();
        }
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

        if (!boardData.config.github_token || !boardData.config.github_repo) {
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

            cardEl.classList.toggle('filtered-out', !show);
        });

        // Auto-expand swimlanes with visible results
        const hasActiveFilters = search || labelFilter || priorityFilter || dueFilter;
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
