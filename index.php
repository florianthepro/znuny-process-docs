<?php
declare(strict_types=1);

const DATA_FILE = __DIR__ . '/config.json';
const MAX_PROCESSES = 500;
const MAX_STEPS = 150;
const MAX_FIELDS = 250;
const MAX_VARIABLES = 150;

function cleanString(mixed $value, int $max = 10000): string
{
    $text = trim(is_scalar($value) ? (string) $value : '');
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    return substr($text, 0, $max);
}

function uid(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function defaultConfig(): array
{
    return [
        'schemaVersion' => 3,
        'configuration' => [
            'fields' => [
                ['id' => 'field-type', 'key' => 'Type', 'label' => 'Type', 'type' => 'select', 'values' => ['Change', 'Compliance', 'Incident', 'Incident:Major', 'Investigation', 'Operation&Maintenance', 'Problem', 'RfC', 'RMA', 'ServiceRequest', 'SPAM', 'Unclassified'], 'defaultValue' => '', 'required' => true],
                ['id' => 'field-queue', 'key' => 'Queue', 'label' => 'Queue', 'type' => 'select', 'values' => ['Consulting', 'RMA', 'SPAM', 'Support'], 'defaultValue' => '', 'required' => true],
                ['id' => 'field-service', 'key' => 'Service', 'label' => 'Service', 'type' => 'select', 'values' => ['Basic Support', 'Extra Support', 'Internal', 'Managed Service'], 'defaultValue' => '', 'required' => true],
                ['id' => 'field-owner', 'key' => 'Owner', 'label' => 'Owner', 'type' => 'text', 'values' => [], 'defaultValue' => '', 'required' => true],
                ['id' => 'field-state', 'key' => 'State', 'label' => 'State', 'type' => 'select', 'values' => [], 'defaultValue' => '', 'required' => false],
                ['id' => 'field-priority', 'key' => 'Priority', 'label' => 'Priority', 'type' => 'select', 'values' => [], 'defaultValue' => '', 'required' => false],
                ['id' => 'field-responsible', 'key' => 'Responsible', 'label' => 'Responsible', 'type' => 'text', 'values' => [], 'defaultValue' => '', 'required' => false],
                ['id' => 'field-customer-id', 'key' => 'CustomerID', 'label' => 'CustomerID', 'type' => 'text', 'values' => [], 'defaultValue' => '', 'required' => false],
            ],
            'linkTypes' => ['Normal', 'ParentChild'],
        ],
        'processes' => [],
    ];
}

function normalizeValues(mixed $value): array
{
    if (is_string($value)) {
        $list = preg_split('/\R/u', $value) ?: [];
    } elseif (is_array($value)) {
        $list = $value;
    } else {
        return [];
    }

    $out = [];
    foreach (array_slice($list, 0, 200) as $item) {
        $text = cleanString($item, 300);
        if ($text !== '' && !in_array($text, $out, true)) {
            $out[] = $text;
        }
    }
    return $out;
}

function normalizeField(array $field): array
{
    $type = cleanString($field['type'] ?? 'text', 30);
    if (!in_array($type, ['text', 'select', 'number'], true)) {
        $type = 'text';
    }

    return [
        'id' => cleanString($field['id'] ?? '', 100) ?: uid('field'),
        'key' => cleanString($field['key'] ?? $field['name'] ?? '', 120),
        'label' => cleanString($field['label'] ?? $field['name'] ?? $field['key'] ?? '', 160),
        'type' => $type,
        'values' => normalizeValues($field['values'] ?? $field['options'] ?? []),
        'defaultValue' => cleanString($field['defaultValue'] ?? $field['default'] ?? '', 5000),
        'required' => (bool) ($field['required'] ?? false),
    ];
}

function normalizeVariable(array $variable): array
{
    $type = cleanString($variable['type'] ?? 'text', 30);
    if (!in_array($type, ['text', 'select', 'number', 'random'], true)) {
        $type = 'text';
    }

    return [
        'id' => cleanString($variable['id'] ?? '', 100) ?: uid('variable'),
        'name' => cleanString($variable['name'] ?? '', 160),
        'type' => $type,
        'values' => normalizeValues($variable['values'] ?? []),
        'prefill' => cleanString($variable['prefill'] ?? $variable['default'] ?? '', 5000),
        'minimum' => isset($variable['minimum']) && $variable['minimum'] !== '' ? (float) $variable['minimum'] : null,
        'maximum' => isset($variable['maximum']) && $variable['maximum'] !== '' ? (float) $variable['maximum'] : null,
        'hidden' => (bool) ($variable['hidden'] ?? false),
        'marked' => (bool) ($variable['marked'] ?? false),
    ];
}

function normalizeStep(array $step): array
{
    $fields = [];
    foreach (array_slice(is_array($step['fields'] ?? null) ? $step['fields'] : [], 0, MAX_FIELDS) as $rawField) {
        if (!is_array($rawField)) {
            continue;
        }
        $key = cleanString($rawField['key'] ?? $rawField['field'] ?? '', 120);
        if ($key === '') {
            continue;
        }
        $fields[] = [
            'id' => cleanString($rawField['id'] ?? '', 100) ?: uid('step-field'),
            'key' => $key,
            'value' => cleanString($rawField['value'] ?? '', 5000),
            'enabled' => (bool) ($rawField['enabled'] ?? true),
        ];
    }

    $meta = is_array($step['meta'] ?? null) ? $step['meta'] : [];
    $condition = is_array($meta['condition'] ?? null) ? $meta['condition'] : [];
    return [
        'id' => cleanString($step['id'] ?? '', 100) ?: uid('step'),
        'action' => cleanString($step['action'] ?? 'note', 50),
        'subject' => cleanString($step['subject'] ?? $step['title'] ?? '', 5000),
        'body' => cleanString($step['body'] ?? $step['text'] ?? '', 30000),
        'fields' => $fields,
        'links' => [],
        'meta' => [
            'system' => cleanString($meta['system'] ?? '', 5000),
            'url' => cleanString($meta['url'] ?? '', 5000),
            'address' => cleanString($meta['address'] ?? '', 5000),
            'tasks' => [],
            'condition' => [
                'variable' => cleanString($condition['variable'] ?? '', 160),
                'operator' => cleanString($condition['operator'] ?? 'always', 40),
                'value' => cleanString($condition['value'] ?? '', 5000),
            ],
        ],
    ];
}

function normalizeProcess(array $process): array
{
    $variables = [];
    foreach (array_slice(is_array($process['variables'] ?? null) ? $process['variables'] : [], 0, MAX_VARIABLES) as $rawVariable) {
        if (!is_array($rawVariable)) {
            continue;
        }
        $variable = normalizeVariable($rawVariable);
        if ($variable['name'] !== '') {
            $variables[] = $variable;
        }
    }

    $steps = [];
    foreach (array_slice(is_array($process['steps'] ?? null) ? $process['steps'] : [], 0, MAX_STEPS) as $step) {
        if (!is_array($step)) {
            continue;
        }
        $steps[] = normalizeStep($step);
    }

    if (!$steps) {
        $steps[] = [
            'id' => uid('step'),
            'action' => 'ticket-create',
            'subject' => '',
            'body' => '',
            'fields' => [],
            'links' => [],
            'meta' => [
                'system' => '',
                'url' => '',
                'address' => '',
                'tasks' => [],
                'condition' => ['variable' => '', 'operator' => 'always', 'value' => ''],
            ],
        ];
    }

    return [
        'id' => cleanString($process['id'] ?? '', 100) ?: uid('process'),
        'name' => cleanString($process['name'] ?? 'Unbenannter Prozess', 160),
        'description' => cleanString($process['description'] ?? '', 1000),
        'yamlName' => cleanString($process['yamlName'] ?? '', 200),
        'published' => (bool) ($process['published'] ?? true),
        'customerMarks' => is_array($process['customerMarks'] ?? null) ? array_values($process['customerMarks']) : [],
        'variables' => $variables,
        'steps' => $steps,
        'updatedAt' => cleanString($process['updatedAt'] ?? '', 50) ?: date(DATE_ATOM),
    ];
}

function loadConfig(): array
{
    if (!is_file(DATA_FILE)) {
        $default = defaultConfig();
        file_put_contents(DATA_FILE, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        return $default;
    }

    $raw = file_get_contents(DATA_FILE);
    if ($raw === false) {
        throw new RuntimeException('config.json konnte nicht gelesen werden.');
    }

    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('config.json enthält kein gültiges Objekt.');
    }

    $cfg = is_array($decoded['configuration'] ?? null) ? $decoded['configuration'] : [];
    $fields = [];
    foreach (array_slice(is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [], 0, MAX_FIELDS) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $normalized = normalizeField($field);
        if ($normalized['key'] !== '') {
            $fields[] = $normalized;
        }
    }

    $linkTypes = normalizeValues($cfg['linkTypes'] ?? ['Normal', 'ParentChild']);
    if (!$linkTypes) {
        $linkTypes = ['Normal', 'ParentChild'];
    }

    $processes = [];
    foreach (array_slice(is_array($decoded['processes'] ?? null) ? $decoded['processes'] : [], 0, MAX_PROCESSES) as $process) {
        if (!is_array($process)) {
            continue;
        }
        $processes[] = normalizeProcess($process);
    }

    return [
        'schemaVersion' => 3,
        'configuration' => ['fields' => $fields ?: defaultConfig()['configuration']['fields'], 'linkTypes' => $linkTypes],
        'processes' => $processes,
    ];
}

function saveConfig(array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents(DATA_FILE, $json, LOCK_EX) === false) {
        throw new RuntimeException('config.json konnte nicht gespeichert werden.');
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function actionTitle(string $action): string
{
    $labels = [
        'ticket-create' => 'Ticket erstellen',
        'ticket-edit' => 'Ticketfeld ändern',
        'title-edit' => 'Titel ändern',
        'note' => 'Hinweis',
        'external-task' => 'Externes System',
        'phone-in' => 'Telefon eingehend',
        'phone-out' => 'Telefon ausgehend',
        'email-in' => 'E-Mail eingehend',
        'email-out' => 'E-Mail ausgehend',
        'link' => 'Verknüpfung',
        'close' => 'Ticket schließen'
    ];

    return $labels[$action] ?? 'Schritt';
}

session_start();
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$config = defaultConfig();
$error = '';
try {
    $config = loadConfig();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Ungültige Anfrage.');
        }

        if (!hash_equals($_SESSION['csrf'], (string) ($payload['csrf'] ?? ''))) {
            throw new RuntimeException('Sitzung abgelaufen.');
        }

        $op = cleanString($payload['op'] ?? '', 40);
        $config = loadConfig();

        if ($op === 'save-config') {
            $incoming = is_array($payload['configuration'] ?? null) ? $payload['configuration'] : [];
            $fields = [];
            foreach (array_slice(is_array($incoming['fields'] ?? null) ? $incoming['fields'] : [], 0, MAX_FIELDS) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $normalized = normalizeField($field);
                if ($normalized['key'] !== '') {
                    $fields[] = $normalized;
                }
            }
            if (!$fields) {
                throw new RuntimeException('Mindestens ein Feld ist erforderlich.');
            }

            $config['configuration'] = [
                'fields' => $fields,
                'linkTypes' => normalizeValues($incoming['linkTypes'] ?? ['Normal', 'ParentChild']) ?: ['Normal', 'ParentChild'],
            ];
            saveConfig($config);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($op === 'save-process') {
            $processInput = is_array($payload['process'] ?? null) ? $payload['process'] : [];
            $process = normalizeProcess($processInput);
            if ($process['name'] === '') {
                throw new RuntimeException('Prozessname fehlt.');
            }
            $process['updatedAt'] = date(DATE_ATOM);

            $updated = false;
            foreach ($config['processes'] as $index => $existing) {
                if (($existing['id'] ?? '') === ($process['id'] ?? '')) {
                    $config['processes'][$index] = $process;
                    $updated = true;
                    break;
                }
            }

            if (!$updated) {
                if (count($config['processes']) >= MAX_PROCESSES) {
                    throw new RuntimeException('Zu viele Prozesse.');
                }
                $config['processes'][] = $process;
            }

            saveConfig($config);
            echo json_encode(['ok' => true, 'id' => $process['id']]);
            exit;
        }

        if ($op === 'delete-process') {
            $id = cleanString($payload['id'] ?? '', 100);
            $config['processes'] = array_values(array_filter($config['processes'], fn ($process) => ($process['id'] ?? '') !== $id));
            saveConfig($config);
            echo json_encode(['ok' => true]);
            exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$page = cleanString($_GET['page'] ?? 'library', 20);
$id = cleanString($_GET['id'] ?? '', 100);
$current = null;
foreach ($config['processes'] as $process) {
    if (($process['id'] ?? '') === $id) {
        $current = $process;
        break;
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Znuny Prozessdokumentation</title>
    <style>
        :root {
            --bg: #f4f6f9;
            --panel: #ffffff;
            --panel-alt: #f9fafb;
            --text: #1a2433;
            --muted: #5d6b7d;
            --border: #dfe6ee;
            --primary: #175ea8;
            --primary-soft: #dfeefc;
            --danger: #a33a3a;
            --success: #1a7a4a;
            --shadow: 0 14px 38px rgba(10, 20, 35, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
        }
        a { color: var(--primary); text-decoration: none; }
        button, input, select, textarea { font: inherit; }
        button {
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--text);
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
        }
        button:hover { background: #f2f5f8; }
        .primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .primary:hover { background: #144f94; }
        .danger { color: var(--danger); }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 24px 20px 60px; }
        .topbar {
            background: #fff;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .topbar-inner {
            max-width: 1180px;
            margin: 0 auto;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .brand { font-size: 18px; font-weight: 700; }
        .nav { margin-left: auto; display: flex; gap: 8px; }
        .nav a { padding: 7px 10px; color: var(--muted); border-radius: 8px; }
        .nav a:hover { background: var(--panel-alt); color: var(--text); }
        .page-head {
            display: flex; align-items: flex-end; justify-content: space-between; gap: 14px;
            margin: 18px 0 20px;
        }
        .page-head h1 { margin: 0; font-size: 30px; letter-spacing: -0.04em; }
        .muted { color: var(--muted); }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 18px;
        }
        .card h2 { margin: 0 0 10px; font-size: 18px; }
        .card p { margin: 8px 0; color: var(--muted); }
        .empty {
            background: var(--panel);
            border: 1px dashed var(--border);
            border-radius: 12px;
            padding: 20px;
            color: var(--muted);
            text-align: center;
        }
        .tabs {
            display: flex; gap: 8px; margin: 10px 0 20px;
            border-bottom: 1px solid var(--border); padding-bottom: 8px;
        }
        .tabs a {
            padding: 8px 12px; border-radius: 8px; color: var(--muted); font-weight: 600;
        }
        .tabs a.active {
            background: var(--primary-soft); color: var(--primary); border: 1px solid #cfe3ff;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 18px;
        }
        .process-row {
            display: grid;
            grid-template-columns: minmax(180px, 1.3fr) minmax(220px, 2fr) 90px auto;
            gap: 12px;
            align-items: center;
            padding: 14px 16px;
            margin-bottom: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--panel);
        }
        .editor-head {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 18px;
            box-shadow: var(--shadow);
        }
        .field-grid, .meta-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        label {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-weight: 600;
            font-size: 12px;
        }
        input, select, textarea {
            width: 100%;
            background: #fff;
            border: 1px solid #cdd7e1;
            border-radius: 9px;
            padding: 9px 10px;
            color: var(--text);
        }
        textarea { min-height: 110px; resize: vertical; }
        .step {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 14px;
            margin: 12px 0;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .step-head {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px;
            background: var(--panel-alt);
            border-bottom: 1px solid var(--border);
            font-weight: 700;
        }
        .step-head .spacer { margin-left: auto; }
        .step-body { padding: 14px 16px; }
        .required-fields, .field-list { display: grid; gap: 10px; }
        .required-fields { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .field-row {
            display: grid; grid-template-columns: minmax(150px, 1fr) minmax(180px, 1.5fr) 88px; gap: 8px; align-items: end;
            border-top: 1px solid var(--border); padding-top: 10px; margin-top: 10px;
        }
        .field-row:first-child { border-top: 0; padding-top: 0; margin-top: 0; }
        .plus-button {
            display: block;
            margin: 14px auto;
            width: 44px; height: 44px; border-radius: 50%;
            background: var(--primary); color: #fff; border-color: var(--primary);
            font-size: 24px; line-height: 1;
        }
        .status {
            position: fixed; right: 18px; bottom: 18px;
            background: #1d2733; color: #fff; padding: 10px 12px; border-radius: 9px;
            opacity: 0; transform: translateY(10px); transition: .2s ease;
            pointer-events: none;
        }
        .status.show { opacity: 1; transform: translateY(0); }
        .row { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 10px; }
        .c3 { grid-column: span 3; }
        .c4 { grid-column: span 4; }
        .c6 { grid-column: span 6; }
        .c8 { grid-column: span 8; }
        .c12 { grid-column: span 12; }
        .hidden { display: none !important; }
        @media (max-width: 820px) {
            .process-row { grid-template-columns: 1fr; }
            .field-grid, .meta-grid, .required-fields { grid-template-columns: 1fr; }
            .row { display: block; }
            .row > * { margin-bottom: 12px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">Znuny Prozessdoku</div>
            <nav class="nav">
                <a href="?page=library">Prozesse</a>
                <a href="?page=editor">Editor</a>
                <a href="?page=config">Konfiguration</a>
            </nav>
        </div>
    </header>

    <main class="wrap">
        <?php if ($error): ?>
            <div class="empty"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($page === 'library'): ?>
            <div class="page-head">
                <h1>Prozesse</h1>
                <div class="actions">
                    <a class="button primary" href="?page=editor">Neuer Prozess</a>
                </div>
            </div>
            <div class="grid">
                <?php if (!$config['processes']): ?>
                    <div class="empty">Keine Prozesse vorhanden.</div>
                <?php endif; ?>
                <?php foreach ($config['processes'] as $process): ?>
                    <article class="card">
                        <h2><?= h($process['name']) ?></h2>
                        <p><?= h($process['description']) ?: 'Keine Beschreibung.' ?></p>
                        <p><?= count($process['steps']) ?> Schritte</p>
                        <div class="actions">
                            <a class="button primary" href="?page=view&id=<?= h($process['id']) ?>">Öffnen</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

        <?php elseif ($page === 'config'): ?>
            <div class="tabs">
                <a href="?page=library">Prozesse</a>
                <a href="?page=editor">Editor</a>
                <a class="active" href="?page=config">Konfiguration</a>
            </div>
            <div class="page-head">
                <h1>Znuny-Felder</h1>
                <div class="actions">
                    <button id="save-config" class="primary" type="button">Speichern</button>
                </div>
            </div>
            <div id="config-panel" class="panel"></div>

        <?php elseif ($page === 'editor' || $page === 'view'): ?>
            <?php if ($page === 'editor'): ?>
                <div class="tabs">
                    <a href="?page=library">Prozesse</a>
                    <a class="active" href="?page=editor">Editor</a>
                    <a href="?page=config">Konfiguration</a>
                </div>
                <?php $process = $current ?? ['id' => '', 'name' => '', 'description' => '', 'yamlName' => '', 'published' => true, 'variables' => [], 'steps' => [[
                    'id' => uid('step'),
                    'action' => 'ticket-create',
                    'subject' => '',
                    'body' => '',
                    'fields' => [
                        ['id' => uid('step-field'), 'key' => 'Type', 'value' => '', 'enabled' => true],
                        ['id' => uid('step-field'), 'key' => 'Queue', 'value' => '', 'enabled' => true],
                        ['id' => uid('step-field'), 'key' => 'Service', 'value' => '', 'enabled' => true],
                    ],
                    'links' => [],
                    'meta' => ['system' => '', 'url' => '', 'address' => '', 'tasks' => [], 'condition' => ['variable' => '', 'operator' => 'always', 'value' => '']]
                ]];
                ?>
                <div class="editor-head">
                    <div class="field-grid">
                        <div>
                            <label>Prozessname</label>
                            <input id="process-name" value="<?= h($process['name']) ?>">
                        </div>
                        <div>
                            <label>YAML-Dateiname</label>
                            <input id="yaml-name" value="<?= h($process['yamlName']) ?>">
                        </div>
                    </div>
                    <div class="meta-grid" style="margin-top:12px;">
                        <div>
                            <label>Kurzbeschreibung</label>
                            <input id="process-description" value="<?= h($process['description']) ?>">
                        </div>
                        <div>
                            <label>Veröffentlicht</label>
                            <select id="process-published">
                                <option value="1" <?= $process['published'] ? 'selected' : '' ?>>Ja</option>
                                <option value="0" <?= !$process['published'] ? 'selected' : '' ?>>Nein</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div id="editor-steps"></div>
                <button id="add-step" class="plus-button" type="button" aria-label="Schritt hinzufügen">+</button>
                <div class="actions">
                    <button id="save-process" class="primary" type="button">Prozess speichern</button>
                </div>
            <?php else: ?>
                <?php if (!$current): ?>
                    <div class="empty">Prozess nicht gefunden.</div>
                <?php else: ?>
                    <div class="page-head">
                        <h1><?= h($current['name']) ?></h1>
                        <a class="button" href="?page=library">Zurück</a>
                    </div>
                    <div class="panel">
                        <div id="view-process"></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty">Seite nicht gefunden.</div>
        <?php endif; ?>
    </main>

    <div id="status" class="status" aria-live="polite"></div>

    <script>
        const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
        const CONFIG = <?= json_encode($config['configuration'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const PAGE = <?= json_encode($page) ?>;
        const CURRENT = <?= json_encode($current ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        function notice(text, bad = false) {
            const s = document.getElementById('status');
            s.textContent = text;
            s.classList.toggle('show', true);
            s.style.background = bad ? '#913535' : '#1d2733';
            setTimeout(() => s.classList.remove('show'), 2600);
        }

        async function api(payload) {
            const response = await fetch(location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ ...payload, csrf: CSRF })
            });
            const json = await response.json();
            if (!response.ok || !json.ok) {
                throw new Error(json.error || 'Fehler beim Speichern.');
            }
            return json;
        }

        const stepLabels = {
            'ticket-create': 'Ticket erstellen',
            'ticket-edit': 'Ticketfeld ändern',
            'title-edit': 'Titel ändern',
            'note': 'Hinweis',
            'external-task': 'Externes System',
            'phone-in': 'Telefon eingehend',
            'phone-out': 'Telefon ausgehend',
            'email-in': 'E-Mail eingehend',
            'email-out': 'E-Mail ausgehend',
            'link': 'Verknüpfung',
            'close': 'Ticket schließen'
        };

        function fieldDef(key) {
            return (CONFIG.fields || []).find(f => f.key === key) || { key, label: key, type: 'text', values: [] };
        }

        function makeInput(def, value) {
            if (def.type === 'select') {
                const select = document.createElement('select');
                (def.values || []).forEach(item => {
                    const option = document.createElement('option');
                    option.value = item;
                    option.textContent = item;
                    if (item === value) option.selected = true;
                    select.appendChild(option);
                });
                return select;
            }
            const input = document.createElement('input');
            input.type = def.type === 'number' ? 'number' : 'text';
            input.value = value || '';
            return input;
        }

        function blankStep(action = 'note') {
            return {
                id: 'step-' + crypto.randomUUID(),
                action,
                subject: '',
                body: '',
                fields: [],
                links: [],
                meta: {
                    system: '',
                    url: '',
                    address: '',
                    tasks: [],
                    condition: { variable: '', operator: 'always', value: '' }
                }
            };
        }

        function createProcessModel() {
            return {
                id: '',
                name: '',
                description: '',
                yamlName: '',
                published: true,
                customerMarks: [],
                variables: [],
                steps: [
                    {
                        id: 'step-' + crypto.randomUUID(),
                        action: 'ticket-create',
                        subject: '',
                        body: '',
                        fields: [
                            { id: 'sf-' + crypto.randomUUID(), key: 'Type', value: '', enabled: true },
                            { id: 'sf-' + crypto.randomUUID(), key: 'Queue', value: '', enabled: true },
                            { id: 'sf-' + crypto.randomUUID(), key: 'Service', value: '', enabled: true }
                        ],
                        links: [],
                        meta: {
                            system: '',
                            url: '',
                            address: '',
                            tasks: [],
                            condition: { variable: '', operator: 'always', value: '' }
                        }
                    }
                ]
            };
        }

        let processModel = null;

        function initEditor() {
            processModel = CURRENT && CURRENT.id ? structuredClone(CURRENT) : createProcessModel();
            const name = document.getElementById('process-name');
            const desc = document.getElementById('process-description');
            const yaml = document.getElementById('yaml-name');
            const published = document.getElementById('process-published');

            name.value = processModel.name || '';
            desc.value = processModel.description || '';
            yaml.value = processModel.yamlName || '';
            published.value = processModel.published ? '1' : '0';

            name.addEventListener('input', () => processModel.name = name.value);
            desc.addEventListener('input', () => processModel.description = desc.value);
            yaml.addEventListener('input', () => processModel.yamlName = yaml.value);
            published.addEventListener('change', () => processModel.published = published.value === '1');

            renderEditorSteps();

            document.getElementById('add-step').addEventListener('click', () => {
                processModel.steps.push(blankStep('note'));
                renderEditorSteps();
            });

            document.getElementById('save-process').addEventListener('click', async () => {
                try {
                    processModel.name = name.value.trim();
                    processModel.description = desc.value.trim();
                    processModel.yamlName = yaml.value.trim();
                    processModel.published = published.value === '1';

                    if (!processModel.name) {
                        throw new Error('Prozessname fehlt.');
                    }
                    if (!processModel.steps.length) {
                        throw new Error('Mindestens ein Schritt ist erforderlich.');
                    }
                    const first = processModel.steps[0];
                    if (!first.fields.some(f => f.key === 'Type' && String(f.value || '').trim())) {
                        throw new Error('Erster Schritt: Type ist erforderlich.');
                    }
                    if (!first.fields.some(f => f.key === 'Queue' && String(f.value || '').trim())) {
                        throw new Error('Erster Schritt: Queue ist erforderlich.');
                    }
                    if (!first.fields.some(f => f.key === 'Service' && String(f.value || '').trim())) {
                        throw new Error('Erster Schritt: Service ist erforderlich.');
                    }

                    const result = await api({ op: 'save-process', process: processModel });
                    processModel.id = result.id || processModel.id || 'process-' + crypto.randomUUID();
                    notice('Prozess gespeichert.');
                    setTimeout(() => window.location.href = '?page=editor', 800);
                } catch (e) {
                    notice(e.message, true);
                }
            });
        }

        function renderEditorSteps() {
            const root = document.getElementById('editor-steps');
            if (!root) return;
            root.innerHTML = '';

            processModel.steps.forEach((step, index) => {
                const card = document.createElement('section');
                card.className = 'step';

                const head = document.createElement('div');
                head.className = 'step-head';
                head.innerHTML = `<span>${index + 1}. ${stepLabels[step.action] || 'Schritt'}</span>`;

                const action = document.createElement('select');
                Object.entries(stepLabels).forEach(([key, label]) => {
                    const option = document.createElement('option');
                    option.value = key;
                    option.textContent = label;
                    if (key === step.action) option.selected = true;
                    action.appendChild(option);
                });
                action.onchange = () => {
                    step.action = action.value;
                    renderEditorSteps();
                };

                const move = document.createElement('button');
                move.type = 'button';
                move.textContent = 'Löschen';
                move.className = 'danger';
                move.onclick = () => {
                    if (processModel.steps.length === 1) {
                        notice('Der letzte Schritt darf nicht gelöscht werden.', true);
                        return;
                    }
                    processModel.steps.splice(index, 1);
                    renderEditorSteps();
                };

                head.appendChild(action);
                head.appendChild(move);
                card.appendChild(head);

                const body = document.createElement('div');
                body.className = 'step-body';

                if (step.action === 'ticket-create' || step.action === 'ticket-edit' || step.action === 'note' || step.action === 'close') {
                    const fieldBox = document.createElement('div');
                    fieldBox.className = 'required-fields';

                    const requiredKeys = ['Type', 'Queue', 'Service'];
                    const chosen = step.fields || [];
                    requiredKeys.forEach(key => {
                        let found = chosen.find(item => item.key === key);
                        if (!found) {
                            found = { id: 'sf-' + crypto.randomUUID(), key, value: '', enabled: true };
                            chosen.push(found);
                        }
                        const def = fieldDef(key);
                        const row = document.createElement('div');
                        row.className = 'field-row';

                        const labelWrap = document.createElement('div');
                        const lab = document.createElement('label');
                        lab.textContent = def.label;
                        labelWrap.appendChild(lab);

                        const input = makeInput(def, found.value || '');
                        input.addEventListener('input', () => { found.value = input.value; });
                        input.addEventListener('change', () => { found.value = input.value; });

                        row.appendChild(labelWrap);
                        row.appendChild(input);
                        fieldBox.appendChild(row);
                    });

                    body.appendChild(fieldBox);
                }

                if (step.action === 'ticket-create' || step.action === 'note' || step.action === 'close' || step.action === 'ticket-edit') {
                    const subjectWrap = document.createElement('div');
                    const subjectLabel = document.createElement('label');
                    subjectLabel.textContent = step.action === 'close' ? 'Abschlussnotiz' : 'Betreff';
                    const subjectInput = document.createElement('textarea');
                    subjectInput.value = step.subject || '';
                    subjectInput.oninput = () => step.subject = subjectInput.value;
                    subjectWrap.appendChild(subjectLabel);
                    subjectWrap.appendChild(subjectInput);
                    body.appendChild(subjectWrap);

                    const bodyWrap = document.createElement('div');
                    const bodyLabel = document.createElement('label');
                    bodyLabel.textContent = 'Text';
                    const bodyInput = document.createElement('textarea');
                    bodyInput.value = step.body || '';
                    bodyInput.oninput = () => step.body = bodyInput.value;
                    bodyWrap.appendChild(bodyLabel);
                    bodyWrap.appendChild(bodyInput);
                    body.appendChild(bodyWrap);
                }

                if (step.action === 'title-edit') {
                    const inputWrap = document.createElement('div');
                    const label = document.createElement('label');
                    label.textContent = 'Titel';
                    const input = document.createElement('textarea');
                    input.value = step.subject || '';
                    input.oninput = () => step.subject = input.value;
                    inputWrap.appendChild(label);
                    inputWrap.appendChild(input);
                    body.appendChild(inputWrap);
                }

                if (step.action === 'link') {
                    const linkWrap = document.createElement('div');
                    linkWrap.className = 'row';
                    const typeWrap = document.createElement('div');
                    typeWrap.className = 'c6';
                    const label1 = document.createElement('label');
                    label1.textContent = 'Linktyp';
                    const select = document.createElement('select');
                    (CONFIG.linkTypes || ['Normal', 'ParentChild']).forEach(type => {
                        const option = document.createElement('option');
                        option.value = type;
                        option.textContent = type;
                        if ((step.links[0] && step.links[0].type) === type) option.selected = true;
                        select.appendChild(option);
                    });
                    select.onchange = () => {
                        if (!step.links[0]) step.links[0] = { id: 'link-' + crypto.randomUUID(), object: 'Ticket', type: select.value, target: '' };
                        step.links[0].type = select.value;
                    };
                    typeWrap.appendChild(label1);
                    typeWrap.appendChild(select);

                    const targetWrap = document.createElement('div');
                    targetWrap.className = 'c6';
                    const label2 = document.createElement('label');
                    label2.textContent = 'Ziel';
                    const target = document.createElement('input');
                    target.value = (step.links[0] && step.links[0].target) || '';
                    target.oninput = () => {
                        if (!step.links[0]) step.links[0] = { id: 'link-' + crypto.randomUUID(), object: 'Ticket', type: select.value, target: '' };
                        step.links[0].target = target.value;
                    };
                    targetWrap.appendChild(label2);
                    targetWrap.appendChild(target);

                    linkWrap.appendChild(typeWrap);
                    linkWrap.appendChild(targetWrap);
                    body.appendChild(linkWrap);
                }

                card.appendChild(body);
                root.appendChild(card);
            });
        }

        function renderView() {
            const root = document.getElementById('view-process');
            if (!root || !CURRENT) return;
            root.innerHTML = '';

            const process = CURRENT;
            const heading = document.createElement('h2');
            heading.textContent = process.name || 'Prozess';
            root.appendChild(heading);

            process.steps.forEach((step, index) => {
                const card = document.createElement('div');
                card.className = 'panel';
                card.style.marginTop = '12px';
                const title = document.createElement('h3');
                title.textContent = `${index + 1}. ${stepLabels[step.action] || 'Schritt'}`;
                const body = document.createElement('div');

                if (step.subject) {
                    const label = document.createElement('strong');
                    label.textContent = 'Betreff: ';
                    const text = document.createElement('span');
                    text.textContent = step.subject;
                    body.appendChild(label);
                    body.appendChild(text);
                    body.appendChild(document.createElement('br'));
                }

                if (step.body) {
                    const label = document.createElement('strong');
                    label.textContent = 'Text: ';
                    const text = document.createElement('span');
                    text.textContent = step.body;
                    body.appendChild(label);
                    body.appendChild(text);
                    body.appendChild(document.createElement('br'));
                }

                if (step.fields && step.fields.length) {
                    step.fields.forEach(field => {
                        const label = document.createElement('strong');
                        label.textContent = `${field.key}: `;
                        const value = document.createElement('span');
                        value.textContent = field.value || '';
                        body.appendChild(label);
                        body.appendChild(value);
                        body.appendChild(document.createElement('br'));
                    });
                }

                card.appendChild(title);
                card.appendChild(body);
                root.appendChild(card);
            });
        }

        function initConfigEditor() {
            const root = document.getElementById('config-panel');
            if (!root) return;
            const fields = (CONFIG.fields || []).map(field => ({ ...field }));

            function draw() {
                root.innerHTML = '';
                fields.forEach((field, index) => {
                    const row = document.createElement('div');
                    row.className = 'process-row';
                    row.innerHTML = `
                        <div>
                            <label>Feldname</label>
                            <input value="${field.key || ''}" data-role="key" data-index="${index}">
                        </div>
                        <div>
                            <label>Bezeichnung</label>
                            <input value="${field.label || ''}" data-role="label" data-index="${index}">
                        </div>
                        <div>
                            <label>Typ</label>
                            <select data-role="type" data-index="${index}">
                                <option value="text" ${field.type === 'text' ? 'selected' : ''}>text</option>
                                <option value="select" ${field.type === 'select' ? 'selected' : ''}>select</option>
                                <option value="number" ${field.type === 'number' ? 'selected' : ''}>number</option>
                            </select>
                        </div>
                        <div>
                            <button type="button" class="danger" data-remove="${index}">Entfernen</button>
                        </div>
                    `;
                    root.appendChild(row);
                });

                const addBtn = document.createElement('button');
                addBtn.type = 'button';
                addBtn.textContent = 'Feld hinzufügen';
                addBtn.className = 'primary';
                addBtn.onclick = () => {
                    fields.push({ id: 'field-' + crypto.randomUUID(), key: '', label: '', type: 'text', values: [], defaultValue: '', required: false });
                    draw();
                };
                root.appendChild(addBtn);
            }

            root.addEventListener('input', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const index = Number(target.dataset.index);
                const role = target.dataset.role;
                if (Number.isNaN(index) || !role || !fields[index]) return;
                fields[index][role] = target.value;
            });

            root.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const index = Number(target.dataset.index);
                const role = target.dataset.role;
                if (Number.isNaN(index) || !role || !fields[index]) return;
                if (role === 'type') {
                    fields[index].type = target.value;
                }
            });

            root.addEventListener('click', async (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const removeIndex = Number(target.dataset.remove);
                if (!Number.isNaN(removeIndex)) {
                    fields.splice(removeIndex, 1);
                    draw();
                    return;
                }
            });

            document.getElementById('save-config').addEventListener('click', async () => {
                try {
                    const sanitized = fields
                        .filter(field => String(field.key || '').trim())
                        .map(field => ({
                            id: field.id || 'field-' + crypto.randomUUID(),
                            key: String(field.key || '').trim(),
                            label: String(field.label || field.key || '').trim(),
                            type: field.type || 'text',
                            values: Array.isArray(field.values) ? field.values : [],
                            defaultValue: field.defaultValue || '',
                            required: !!field.required
                        }));
                    if (!sanitized.length) {
                        throw new Error('Mindestens ein Feld ist erforderlich.');
                    }

                    await api({ op: 'save-config', configuration: { fields: sanitized, linkTypes: CONFIG.linkTypes || ['Normal', 'ParentChild'] } });
                    notice('Konfiguration gespeichert.');
                } catch (e) {
                    notice(e.message, true);
                }
            });

            draw();
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (PAGE === 'editor') initEditor();
            if (PAGE === 'config') initConfigEditor();
            if (PAGE === 'view' && CURRENT) renderView();
        });
    </script>
</body>
</html>
