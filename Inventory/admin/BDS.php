<?php
/* Start the session only if the dashboard has not already started it */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- ACCESS CONTROL ----------
   Uncomment once your login page sets this session value.
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}
*/

const BACKUP_DIR = __DIR__ . '/backups';

/* True when this file is opened directly, false when included by the dashboard */
$isStandalone = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ---------- HELPERS ---------- */
function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function peso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024)    return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pullFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function csrfValid(): bool
{
    return hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '');
}

/* Builds a link back to whichever page is showing this report
   (keeps ?page=... when it is embedded in the dashboard). */
function selfUrl(array $params = []): string
{
    $base = [];
    if (isset($_GET['page'])) {
        $base['page'] = (string)$_GET['page'];
    }
    $query = http_build_query(array_merge($base, $params));
    return $_SERVER['SCRIPT_NAME'] . ($query !== '' ? '?' . $query : '');
}

/* Redirect that still works if some output has already been sent */
function redirectTo(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    $safe = e($url);
    echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">';
    echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
    exit;
}

/* Throw away anything already buffered (nav bar, etc.) before sending a file */
function cleanOutput(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

/* ---------- REPORT DATA ----------
   Connect your database here.
   getReport() must return:
   [
     'rows'  => [ ['cell 1', 'cell 2', ...], ... ]   // same order as the columns below
     'total' => 0,                                    // total amount for the period
     'items' => 0,                                    // total items for the period
   ]
*/
function reportTypes(): array
{
    return [
        'sales' => [
            'label'   => 'Sales Report',
            'columns' => ['Transaction', 'Customer', 'Items', 'Total', 'Payment', 'Date'],
        ],
        'inventory' => [
            'label'   => 'Inventory Report',
            'columns' => ['Item', 'Category', 'Quantity', 'Unit', 'Status', 'Last Updated'],
        ],
        'stocking' => [
            'label'   => 'Stocking Records',
            'columns' => ['Item', 'Quantity Added', 'Supplier', 'Received By', 'Date'],
        ],
    ];
}

function getReport(string $type, string $from, string $to): array
{
    return ['rows' => [], 'total' => 0, 'items' => 0];
}

function exportReportCsv(string $type, array $columns, array $rows, string $from, string $to): void
{
    cleanOutput();
    $filename = sprintf('%s_%s_to_%s.csv', $type, $from, $to);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/* ---------- BACKUP FUNCTIONS ---------- */
function ensureBackupDir(): void
{
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0750, true);
        // block direct browser access to backup files
        file_put_contents(BACKUP_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
    }
}

/* Connect your database here.
   Return every table you want inside the backup:
   [ 'cookies' => [ [...row...], ... ], 'ingredients' => [ ... ] ]
*/
function getBackupData(string $type): array
{
    return [];
}

function createBackup(string $type): ?string
{
    ensureBackupDir();
    $type     = $type === 'data' ? 'data' : 'full';
    $filename = sprintf('backup_%s_%s.json', date('Ymd_His'), $type);

    $payload = [
        'created_at' => date('c'),
        'type'       => $type,
        'tables'     => getBackupData($type),
    ];

    $written = file_put_contents(
        BACKUP_DIR . '/' . $filename,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    return $written === false ? null : $filename;
}

function isSafeBackupName(string $name): bool
{
    return (bool)preg_match('/^backup_\d{8}_\d{6}_(full|data)\.json$/', $name);
}

function getBackups(): array
{
    if (!is_dir(BACKUP_DIR)) {
        return [];
    }
    $backups = [];
    foreach (glob(BACKUP_DIR . '/backup_*.json') as $path) {
        $name = basename($path);
        if (!isSafeBackupName($name)) {
            continue;
        }
        $backups[] = [
            'name'    => $name,
            'size'    => filesize($path),
            'created' => filemtime($path),
            'type'    => str_ends_with($name, '_data.json') ? 'Data only' : 'Full backup',
        ];
    }
    usort($backups, fn($a, $b) => $b['created'] <=> $a['created']);
    return $backups;
}

function deleteBackup(string $name): bool
{
    return isSafeBackupName($name) && @unlink(BACKUP_DIR . '/' . $name);
}

function downloadBackup(string $name): void
{
    $path = BACKUP_DIR . '/' . $name;
    if (!isSafeBackupName($name) || !is_file($path)) {
        cleanOutput();
        http_response_code(404);
        exit('Backup not found.');
    }
    cleanOutput();
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ---------- ICONS (inline SVG, no CDN needed) ---------- */
function icon(string $name): string
{
    $paths = [
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
        'print'    => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'records'  => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h6"/>',
        'amount'   => '<path d="M5 8h14l-1.2 11H6.2L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'items'    => '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="9" r="1"/><circle cx="15" cy="10" r="1"/><circle cx="10" cy="15" r="1"/><circle cx="15" cy="15" r="1"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'file'     => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6z"/><path d="M14 3v6h6"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($paths[$name] ?? '') . '</svg>';
}

/* ---------- DOWNLOAD / EXPORT (GET) ---------- */
$types = reportTypes();
$type  = $_GET['type'] ?? 'sales';
if (!isset($types[$type])) {
    $type = 'sales';
}
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

if (isset($_GET['download'])) {
    downloadBackup((string)$_GET['download']);
}
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $r = getReport($type, $from, $to);
    exportReportCsv($type, $types[$type]['columns'], $r['rows'], $from, $to);
}

/* ---------- FORM ACTIONS (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        setFlash('error', 'Your session expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_backup') {
            $file = createBackup($_POST['backup_type'] ?? 'full');
            $file
                ? setFlash('success', 'Backup created: ' . $file)
                : setFlash('error', 'The backup could not be created. Check folder permissions.');
            $_SESSION['last_backup'] = time();

        } elseif ($action === 'delete_backup') {
            deleteBackup((string)($_POST['file'] ?? ''))
                ? setFlash('success', 'Backup deleted.')
                : setFlash('error', 'The backup could not be deleted.');

        } elseif ($action === 'save_schedule') {
            $allowed = ['off', 'daily', 'weekly', 'monthly'];
            $choice  = $_POST['schedule'] ?? 'off';
            // Store this in your database and run backups from a cron job / scheduled task.
            $_SESSION['auto_backup'] = in_array($choice, $allowed, true) ? $choice : 'off';
            setFlash('success', 'Automatic backup schedule saved.');
        }
    }
    redirectTo(selfUrl() . '#backup');
}

/* ---------- PAGE DATA ---------- */
$report     = getReport($type, $from, $to);
$columns    = $types[$type]['columns'];
$backups    = getBackups();
$flash      = pullFlash();
$schedule   = $_SESSION['auto_backup'] ?? 'off';
$lastBackup = $backups[0]['created'] ?? null;
$pageParam  = $_GET['page'] ?? null;

/* stylesheet: version number changes whenever bds.css is saved, so the browser never shows an old copy */
$cssVer = 0;
foreach ([__DIR__ . '/assets/bds.css', __DIR__ . '/admin/assets/bds.css', __DIR__ . '/../assets/bds.css', __DIR__ . '/../admin/assets/bds.css'] as $cssFile) {
    if (is_file($cssFile)) {
        $cssVer = (int)@filemtime($cssFile);
        break;
    }
}
$cssHref = 'admin/assets/bds.css?v=' . ($cssVer ?: time());
?>
<?php if ($isStandalone): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Page | B4jkery</title>
    <link rel="stylesheet" href="<?= e($cssHref) ?>">
</head>
<body>
<?php else: ?>
<link rel="stylesheet" href="<?= e($cssHref) ?>">
<?php endif; ?>

<div class="report-page">
<div class="rp-wrap">

    <!-- Hero -->
    <section class="rp-hero">
        <div class="rp-hero-copy">
            <span class="rp-eyebrow">Records &amp; Safety</span>
            <h1 class="rp-hero-title">Report Page</h1>
            <p class="rp-hero-text">
                Generate sales, inventory and stocking reports, and keep safe copies of your data.
            </p>
        </div>
    </section>

    <?php if ($flash): ?>
        <div class="rp-alert is-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- Main panel -->
    <section class="rp-panel">

        <div class="rp-toolbar">
            <div class="rp-tabs" role="tablist">
                <button type="button" class="rp-tab active" data-tab="reports" role="tab">
                    <?= icon('chart') ?> Reports
                </button>
                <button type="button" class="rp-tab" data-tab="backup" role="tab">
                    <?= icon('database') ?> Data Backup
                </button>
            </div>
        </div>

        <div class="rp-body">

        <!-- ================= REPORTS TAB ================= -->
        <section class="rp-tab-panel active" id="tab-reports" role="tabpanel">

            <form method="get" class="rp-card rp-filter">
                <?php if ($pageParam !== null): ?>
                    <input type="hidden" name="page" value="<?= e((string)$pageParam) ?>">
                <?php endif; ?>
                <div class="rp-field">
                    <label for="type">Report type</label>
                    <select id="type" name="type">
                        <?php foreach ($types as $key => $t): ?>
                            <option value="<?= e($key) ?>" <?= $key === $type ? 'selected' : '' ?>><?= e($t['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rp-field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?= e($from) ?>">
                </div>
                <div class="rp-field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?= e($to) ?>">
                </div>
                <button type="submit" class="rp-btn rp-btn-primary">Generate</button>
            </form>

            <div class="rp-stats">
                <article class="rp-stat">
                    <div>
                        <span class="rp-stat-label">Records</span>
                        <span class="rp-stat-value"><?= count($report['rows']) ?></span>
                        <span class="rp-stat-sub">In this report</span>
                    </div>
                    <span class="rp-stat-icon rp-ic-brown"><?= icon('records') ?></span>
                </article>
                <article class="rp-stat">
                    <div>
                        <span class="rp-stat-label">Total Amount</span>
                        <span class="rp-stat-value"><?= peso((float)$report['total']) ?></span>
                        <span class="rp-stat-sub">For the period</span>
                    </div>
                    <span class="rp-stat-icon rp-ic-green"><?= icon('amount') ?></span>
                </article>
                <article class="rp-stat">
                    <div>
                        <span class="rp-stat-label">Items</span>
                        <span class="rp-stat-value"><?= (int)$report['items'] ?></span>
                        <span class="rp-stat-sub">Counted in total</span>
                    </div>
                    <span class="rp-stat-icon rp-ic-amber"><?= icon('items') ?></span>
                </article>
                <article class="rp-stat">
                    <div>
                        <span class="rp-stat-label">Period</span>
                        <span class="rp-stat-period"><?= e(date('M j, Y', strtotime($from))) ?> – <?= e(date('M j, Y', strtotime($to))) ?></span>
                        <span class="rp-stat-sub">Selected dates</span>
                    </div>
                    <span class="rp-stat-icon rp-ic-red"><?= icon('calendar') ?></span>
                </article>
            </div>

            <div class="rp-card">
                <div class="rp-card-head">
                    <div>
                        <h2><?= e($types[$type]['label']) ?></h2>
                        <p class="rp-card-sub"><?= count($report['rows']) ?> record<?= count($report['rows']) === 1 ? '' : 's' ?></p>
                    </div>
                    <div class="rp-btn-group">
                        <a class="rp-btn rp-btn-outline" href="<?= e(selfUrl(['type' => $type, 'from' => $from, 'to' => $to, 'export' => 'csv'])) ?>">
                            <?= icon('download') ?> Export CSV
                        </a>
                        <button type="button" class="rp-btn rp-btn-outline" onclick="window.print()">
                            <?= icon('print') ?> Print
                        </button>
                    </div>
                </div>

                <div class="rp-table-wrap">
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $col): ?>
                                    <th><?= e($col) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report['rows'])): ?>
                                <tr class="rp-empty-row">
                                    <td colspan="<?= count($columns) ?>">
                                        <span class="rp-empty-icon"><?= icon('file') ?></span>
                                        <p>No records found for this period.</p>
                                        <small>Try a different date range or report type.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report['rows'] as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $cell): ?>
                                            <td><?= e((string)$cell) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ================= BACKUP TAB ================= -->
        <section class="rp-tab-panel" id="tab-backup" role="tabpanel">

            <div class="rp-backup-grid">

                <!-- Create backup -->
                <div class="rp-card">
                    <span class="rp-card-icon rp-ic-brown"><?= icon('database') ?></span>
                    <h2>Back up your data</h2>
                    <p class="rp-muted">
                        Save a copy of your inventory, sales and employee records. Download it and keep it somewhere safe.
                    </p>

                    <form method="post" action="<?= e(selfUrl()) ?>" class="rp-stack">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="create_backup">

                        <div class="rp-field">
                            <label for="backup_type">Backup type</label>
                            <select id="backup_type" name="backup_type">
                                <option value="full">Full backup (all records)</option>
                                <option value="data">Data only (no settings)</option>
                            </select>
                        </div>

                        <button type="submit" class="rp-btn rp-btn-primary rp-btn-block">
                            <?= icon('shield') ?> Create backup now
                        </button>
                    </form>

                    <p class="rp-last-backup">
                        <?= icon('clock') ?>
                        Last backup:
                        <strong><?= $lastBackup ? e(date('M j, Y · g:i A', $lastBackup)) : 'Never' ?></strong>
                    </p>
                </div>

                <!-- Automatic backup -->
                <div class="rp-card">
                    <span class="rp-card-icon rp-ic-amber"><?= icon('clock') ?></span>
                    <h2>Automatic backup</h2>
                    <p class="rp-muted">
                        Choose how often a backup should be created automatically.
                    </p>

                    <form method="post" action="<?= e(selfUrl()) ?>" class="rp-stack">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="save_schedule">

                        <div class="rp-radio-list">
                            <?php foreach (['off' => 'Off', 'daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'] as $value => $label): ?>
                                <label class="rp-radio <?= $schedule === $value ? 'selected' : '' ?>">
                                    <input type="radio" name="schedule" value="<?= $value ?>" <?= $schedule === $value ? 'checked' : '' ?>>
                                    <span><?= e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" class="rp-btn rp-btn-outline rp-btn-block">Save schedule</button>
                    </form>
                </div>
            </div>

            <!-- Backup history -->
            <div class="rp-card">
                <div class="rp-card-head">
                    <div>
                        <h2>Backup history</h2>
                        <p class="rp-card-sub">Download or delete saved backups</p>
                    </div>
                    <span class="rp-pill"><?= count($backups) ?> file<?= count($backups) === 1 ? '' : 's' ?></span>
                </div>

                <div class="rp-table-wrap">
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <th>File name</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Created</th>
                                <th class="rp-col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($backups)): ?>
                                <tr class="rp-empty-row">
                                    <td colspan="5">
                                        <span class="rp-empty-icon"><?= icon('database') ?></span>
                                        <p>No backups yet.</p>
                                        <small>Create your first backup to see it listed here.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($backups as $b): ?>
                                    <tr>
                                        <td><?= e($b['name']) ?></td>
                                        <td><span class="rp-pill"><?= e($b['type']) ?></span></td>
                                        <td><?= e(formatBytes($b['size'])) ?></td>
                                        <td><?= e(date('M j, Y · g:i A', $b['created'])) ?></td>
                                        <td class="rp-col-actions">
                                            <div class="rp-row-actions">
                                                <a class="rp-icon-btn" href="<?= e(selfUrl(['download' => $b['name']])) ?>" title="Download" aria-label="Download <?= e($b['name']) ?>">
                                                    <?= icon('download') ?>
                                                </a>
                                                <form method="post" action="<?= e(selfUrl()) ?>" class="rp-inline-form" onsubmit="return confirm('Delete this backup? This cannot be undone.');">
                                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                    <input type="hidden" name="action" value="delete_backup">
                                                    <input type="hidden" name="file" value="<?= e($b['name']) ?>">
                                                    <button type="submit" class="rp-icon-btn is-danger" title="Delete" aria-label="Delete <?= e($b['name']) ?>">
                                                        <?= icon('trash') ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        </div><!-- /.rp-body -->
    </section>

    <script>
        // Tab switching (remembers the tab through the URL hash)
        (function () {
            var tabs   = document.querySelectorAll('.rp-tab');
            var panels = document.querySelectorAll('.rp-tab-panel');

            function show(name) {
                tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === name); });
                panels.forEach(function (p) { p.classList.toggle('active', p.id === 'tab-' + name); });
                history.replaceState(null, '', location.pathname + location.search + '#' + name);
            }

            tabs.forEach(function (t) {
                t.addEventListener('click', function () { show(t.dataset.tab); });
            });

            if (location.hash === '#backup') { show('backup'); }

            // Highlight the chosen schedule option
            document.querySelectorAll('.rp-radio input').forEach(function (input) {
                input.addEventListener('change', function () {
                    document.querySelectorAll('.rp-radio').forEach(function (o) { o.classList.remove('selected'); });
                    input.closest('.rp-radio').classList.add('selected');
                });
            });
        })();
    </script>

</div>
</div>

<?php if ($isStandalone): ?>
</body>
</html>
<?php endif; ?>