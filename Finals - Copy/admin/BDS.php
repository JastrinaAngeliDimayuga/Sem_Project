<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');


if (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Only the admin can open this page.');
}

const BACKUP_DIR = __DIR__ . '/backups';
const LOW_STOCK_LEVEL = 10;          // an item is "Low stock" at or below this quantity
const MAX_REPORT_ROWS = 5000;

/* True when this file is opened directly, false when included by the dashboard */
$isStandalone = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ---------- HELPERS ---------- */
if (!function_exists('e')) {
    function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('peso')) {
    function peso(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }
}

function numText($n): string
{
    $s = rtrim(rtrim(number_format((float)$n, 2, '.', ','), '0'), '.');
    return $s === '' ? '0' : $s;
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

/* ---------- DATABASE ---------- */

/* PDO connection from signupdb.php (looked for in this folder and the ones above), or null */
function bdsDb(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }

    $pdo = null;
    try {
        if (!function_exists('db')) {
            $dir = __DIR__;
            for ($i = 0; $i < 5; $i++) {
                if (is_file($dir . '/signupdb.php')) {
                    require_once $dir . '/signupdb.php';
                    break;
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }
        if (function_exists('db')) {
            $pdo = db();
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    } catch (Throwable $e) {
        error_log('bds connection failed: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

/* rows of a query, or null when it fails (missing table / column, no connection) */
function bdsRows(string $sql, array $params = []): ?array
{
    $pdo = bdsDb();
    if (!$pdo) {
        return null;
    }
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('bds query failed: ' . $e->getMessage());
        return null;
    }
}

/* first query that works */
function bdsFirstRows(array $sqls, array $params = []): ?array
{
    foreach ($sqls as $sql) {
        $rows = bdsRows($sql, $params);
        if ($rows !== null) {
            return $rows;
        }
    }
    return null;
}

/* ---------- REPORTS ---------- */
function reportTypes(): array
{
    return [
        'sales' => [
            'label'       => 'Sales Report',
            'columns'     => ['Transaction', 'Customer', 'Items', 'Total', 'Payment', 'Status', 'Date'],
            'total_label' => 'Total Amount',
            'total_money' => true,
            'items_label' => 'Cookies Sold',
            'uses_dates'  => true,
        ],
        'inventory' => [
            'label'       => 'Inventory Report',
            'columns'     => ['Item', 'Type', 'Category', 'Quantity', 'Unit', 'Status', 'Last Updated'],
            'total_label' => 'Stock Value',
            'total_money' => true,
            'items_label' => 'Units in Stock',
            'uses_dates'  => false,
        ],
        'stocking' => [
            'label'       => 'Stocking Records',
            'columns'     => ['Movement', 'Item', 'Inventory', 'Quantity', 'Supplier / Reason', 'Recorded By', 'Balance After', 'Date'],
            'total_label' => 'Total Stock In',
            'total_money' => false,
            'items_label' => 'Total Stock Out',
            'uses_dates'  => true,
        ],
    ];
}

const INV_LABELS = ['ingredients' => 'Ingredients', 'cookies' => 'Cookies', 'supplies' => 'Supplies'];

/*
   getReport() returns:
   [
     'rows'  => [ ['cell 1', 'cell 2', ...], ... ],   // same order as the columns
     'total' => 0,                                    // see "total_label" above
     'items' => 0,                                    // see "items_label" above
     'error' => ''                                    // filled when the data could not be read
   ]
*/
function getReport(string $type, string $from, string $to): array
{
    $out = ['rows' => [], 'total' => 0, 'items' => 0, 'error' => ''];

    if (!bdsDb()) {
        $out['error'] = 'The database could not be reached. Check that signupdb.php is in the project.';
        return $out;
    }

    $fmt = "'Mon DD, YYYY HH12:MI AM'";
    $tz  = "'Asia/Manila'";

    /* ----- sales ----- */
    if ($type === 'sales') {
        $rows = bdsRows(
            "SELECT o.id, o.customer, o.items, o.total, o.payment, o.status,
                    to_char(o.ordered_at AT TIME ZONE $tz, $fmt) AS made_at
               FROM sales_orders o
              WHERE (o.ordered_at AT TIME ZONE $tz)::date BETWEEN :from AND :to
              ORDER BY o.ordered_at DESC, o.id DESC
              LIMIT " . MAX_REPORT_ROWS,
            [':from' => $from, ':to' => $to]
        );

        if ($rows === null) {
            $out['error'] = 'The sales tables could not be read. Run sales_update.sql in pgAdmin first.';
            return $out;
        }

        foreach ($rows as $r) {
            $customer = trim((string)$r['customer']);
            $out['rows'][] = [
                '#' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT),
                $customer !== '' ? $customer : 'Walk-in',
                (string)(int)$r['items'],
                peso((float)$r['total']),
                (string)($r['payment'] ?? ''),
                (string)($r['status'] ?? ''),
                (string)($r['made_at'] ?? ''),
            ];
            $out['total'] += (float)$r['total'];
            $out['items'] += (int)$r['items'];
        }
        return $out;
    }

    /* ----- inventory (the stock as it is right now) ----- */
    if ($type === 'inventory') {
        $withMove =
            "SELECT i.name, i.type, i.category, i.quantity, i.unit, i.price, i.expiration,
                    to_char(COALESCE(m.last_at AT TIME ZONE $tz, i.date_added::timestamp), $fmt) AS updated
               FROM inventory_items i
               LEFT JOIN (SELECT item_id, MAX(created_at) AS last_at
                            FROM stock_records
                           WHERE item_id IS NOT NULL
                           GROUP BY item_id) m ON m.item_id = i.id
              ORDER BY i.type, i.name
              LIMIT " . MAX_REPORT_ROWS;
        $plain =
            "SELECT i.name, i.type, i.category, i.quantity, i.unit, i.price, i.expiration,
                    to_char(i.date_added::timestamp, $fmt) AS updated
               FROM inventory_items i
              ORDER BY i.type, i.name
              LIMIT " . MAX_REPORT_ROWS;
        $bare =
            "SELECT i.name, i.type, i.category, i.quantity, i.unit, i.price, i.expiration, NULL AS updated
               FROM inventory_items i
              ORDER BY i.type, i.name
              LIMIT " . MAX_REPORT_ROWS;

        $rows = bdsFirstRows([$withMove, $plain, $bare]);

        if ($rows === null) {
            $out['error'] = 'The inventory table could not be read.';
            return $out;
        }

        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $qty = (float)$r['quantity'];

            if ($r['type'] === 'Ingredients' && !empty($r['expiration']) && $r['expiration'] < $today) {
                $status = 'Expired';
            } elseif ($qty <= 0) {
                $status = 'Out of stock';
            } elseif ($qty <= LOW_STOCK_LEVEL) {
                $status = 'Low stock';
            } else {
                $status = 'In stock';
            }

            $out['rows'][] = [
                (string)$r['name'],
                (string)$r['type'],
                trim((string)($r['category'] ?? '')) !== '' ? (string)$r['category'] : '-',
                numText($qty),
                (string)($r['unit'] ?? ''),
                $status,
                (string)($r['updated'] ?? '-') !== '' ? (string)($r['updated'] ?? '-') : '-',
            ];

            $out['items'] += $qty;
            if ($r['price'] !== null) {
                $out['total'] += $qty * (float)$r['price'];
            }
        }
        return $out;
    }

    /* ----- stocking records (stock in and stock out) ----- */
    $rows = bdsRows(
        "SELECT r.movement_type, r.item_name, r.inventory, r.quantity, r.unit, r.supplier,
                r.recorded_by, r.balance_after,
                to_char(r.created_at AT TIME ZONE $tz, $fmt) AS at_local
           FROM stock_records r
          WHERE (r.created_at AT TIME ZONE $tz)::date BETWEEN :from AND :to
          ORDER BY r.created_at DESC, r.id DESC
          LIMIT " . MAX_REPORT_ROWS,
        [':from' => $from, ':to' => $to]
    );

    if ($rows === null) {
        $out['error'] = 'The stock records could not be read. Run inventory_stock_update.sql in pgAdmin first.';
        return $out;
    }

    foreach ($rows as $r) {
        $in   = $r['movement_type'] === 'IN';
        $unit = (string)($r['unit'] ?? '');
        $qty  = (float)$r['quantity'];
        $who  = trim((string)($r['recorded_by'] ?? ''));

        $out['rows'][] = [
            $in ? 'Stock in' : 'Stock out',
            (string)$r['item_name'],
            INV_LABELS[$r['inventory']] ?? (string)$r['inventory'],
            ($in ? '+' : '-') . numText($qty) . ' ' . $unit,
            trim((string)($r['supplier'] ?? '')) !== '' ? trim((string)$r['supplier']) : '-',
            $who !== '' ? $who : '-',
            numText($r['balance_after']) . ' ' . $unit,
            (string)($r['at_local'] ?? ''),
        ];

        if ($in) {
            $out['total'] += $qty;
        } else {
            $out['items'] += $qty;
        }
    }
    return $out;
}

function exportReportCsv(string $type, array $columns, array $rows, string $from, string $to): void
{
    cleanOutput();
    $filename = sprintf('%s_%s_to_%s.csv', $type, $from, $to);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");            // so Excel shows ₱ and accents correctly
    fputcsv($out, $columns);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/* ---------- BACKUP FUNCTIONS ---------- */
const BACKUP_TYPES = [
    'full'      => 'Full backup',
    'sales'     => 'Sales only',
    'inventory' => 'Inventory & stocks only',
    'data'      => 'Data only',          // older backups
];

/* the tables saved by each backup type (fixed names, never taken from the browser) */
function backupTables(string $type): array
{
    $sales = ['sales_orders', 'sales_order_lines'];
    $inv   = ['inventory_items', 'stock_records'];

    switch ($type) {
        case 'sales':     return $sales;
        case 'inventory': return $inv;
        default:          return array_merge($sales, $inv);
    }
}

function ensureBackupDir(): void
{
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0750, true);
    }
    // block direct browser access to backup files
    if (is_dir(BACKUP_DIR) && !is_file(BACKUP_DIR . '/.htaccess')) {
        @file_put_contents(BACKUP_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
    }
}

/* Reads every row of the tables of this backup type from the database.
   Tables that cannot be read are listed in $skipped. */
function getBackupData(string $type, array &$skipped = []): array
{
    $data = [];
    foreach (backupTables($type) as $table) {
        $rows = bdsRows('SELECT * FROM ' . $table . ' ORDER BY 1');
        if ($rows === null) {
            $skipped[] = $table;
            continue;
        }
        $data[$table] = $rows;
    }
    return $data;
}

/* Returns the new file name, or null (the reason is put in $error) */
function createBackup(string $type, string &$error = ''): ?string
{
    if (!isset(BACKUP_TYPES[$type]) || $type === 'data') {
        $type = 'full';
    }

    if (!bdsDb()) {
        $error = 'The database could not be reached, so nothing was saved.';
        return null;
    }

    ensureBackupDir();
    if (!is_dir(BACKUP_DIR) || !is_writable(BACKUP_DIR)) {
        $error = 'The backups folder is not writable. Check the folder permissions.';
        return null;
    }

    $skipped = [];
    $tables  = getBackupData($type, $skipped);

    if (!$tables) {
        $error = 'None of the tables could be read (' . implode(', ', $skipped) . '). Run the update .sql files in pgAdmin first.';
        return null;
    }

    $counts = [];
    foreach ($tables as $name => $rows) {
        $counts[$name] = count($rows);
    }

    $payload = [
        'created_at'     => date('c'),
        'type'           => $type,
        'record_counts'  => $counts,
        'skipped_tables' => $skipped,
        'tables'         => $tables,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $error = 'The data could not be converted to a backup file.';
        return null;
    }

    $filename = sprintf('backup_%s_%s.json', date('Ymd_His'), $type);
    if (@file_put_contents(BACKUP_DIR . '/' . $filename, $json, LOCK_EX) === false) {
        $error = 'The backup file could not be written. Check the folder permissions.';
        return null;
    }

    /* tell the admin when something was left out */
    if ($skipped) {
        $error = 'Saved, but these tables were missing and left out: ' . implode(', ', $skipped) . '.';
    }
    return $filename;
}

function isSafeBackupName(string $name): bool
{
    return (bool)preg_match('/^backup_\d{8}_\d{6}_(full|sales|inventory|data)\.json$/', $name);
}

function backupTypeLabel(string $name): string
{
    if (preg_match('/_(full|sales|inventory|data)\.json$/', $name, $m)) {
        return BACKUP_TYPES[$m[1]] ?? 'Backup';
    }
    return 'Backup';
}

function getBackups(): array
{
    if (!is_dir(BACKUP_DIR)) {
        return [];
    }
    $backups = [];
    foreach (glob(BACKUP_DIR . '/backup_*.json') ?: [] as $path) {
        $name = basename($path);
        if (!isSafeBackupName($name)) {
            continue;
        }
        $backups[] = [
            'name'    => $name,
            'size'    => (int)filesize($path),
            'created' => (int)filemtime($path),
            'type'    => backupTypeLabel($name),
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

/* ---------- AUTOMATIC BACKUP ---------- */
const SCHEDULES = ['off' => 0, 'daily' => 86400, 'weekly' => 604800, 'monthly' => 2592000];

function getSchedule(): string
{
    $file = BACKUP_DIR . '/schedule.txt';
    $value = is_file($file) ? trim((string)@file_get_contents($file)) : 'off';
    return isset(SCHEDULES[$value]) ? $value : 'off';
}

function saveSchedule(string $choice): bool
{
    ensureBackupDir();
    if (!isset(SCHEDULES[$choice])) {
        $choice = 'off';
    }
    return @file_put_contents(BACKUP_DIR . '/schedule.txt', $choice, LOCK_EX) !== false;
}

/* creates a full backup when the chosen schedule says one is due (checked whenever this page opens) */
function runAutoBackup(): void
{
    $schedule = getSchedule();
    $interval = SCHEDULES[$schedule];
    if ($interval <= 0) {
        return;
    }

    $backups = getBackups();
    $last    = $backups[0]['created'] ?? 0;
    if (time() - $last < $interval) {
        return;
    }

    $error = '';
    $file  = createBackup('full', $error);
    if ($file) {
        setFlash('success', 'Automatic backup created: ' . $file . ($error !== '' ? ' ' . $error : ''));
    } else {
        setFlash('error', 'Automatic backup failed. ' . $error);
    }
}

/* ---------- ICONS (inline SVG, no CDN needed) ---------- */
if (!function_exists('icon')) {
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
}

/* ---------- DOWNLOAD / EXPORT (GET) ---------- */
$types = reportTypes();
$type  = $_GET['type'] ?? 'sales';
if (!isset($types[$type])) {
    $type = 'sales';
}

/* dates must be real calendar dates (Y-m-d); From is never after To */
function cleanDate($value, string $fallback): string
{
    $value = (string)$value;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : $fallback;
}

$from = cleanDate($_GET['from'] ?? '', date('Y-m-01'));
$to   = cleanDate($_GET['to']   ?? '', date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

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
            $error = '';
            $file  = createBackup((string)($_POST['backup_type'] ?? 'full'), $error);
            if ($file) {
                setFlash('success', 'Backup created: ' . $file . ($error !== '' ? ' ' . $error : ''));
            } else {
                setFlash('error', 'The backup could not be created. ' . $error);
            }

        } elseif ($action === 'delete_backup') {
            deleteBackup((string)($_POST['file'] ?? ''))
                ? setFlash('success', 'Backup deleted.')
                : setFlash('error', 'The backup could not be deleted.');

        } elseif ($action === 'save_schedule') {
            $choice = (string)($_POST['schedule'] ?? 'off');
            saveSchedule($choice)
                ? setFlash('success', 'Automatic backup schedule saved.')
                : setFlash('error', 'The schedule could not be saved. Check the backups folder permissions.');
        }
    }
    redirectTo(selfUrl() . '#backup');
}

/* a due automatic backup is made when the page is opened */
runAutoBackup();

/* ---------- PAGE DATA ---------- */
$report     = getReport($type, $from, $to);
$typeInfo   = $types[$type];
$columns    = $typeInfo['columns'];
$backups    = getBackups();
$flash      = pullFlash();
$schedule   = getSchedule();
$lastBackup = $backups[0]['created'] ?? null;
$pageParam  = $_GET['page'] ?? null;
$dbReady    = bdsDb() !== null;

$periodText = $typeInfo['uses_dates']
    ? date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to))
    : 'As of ' . date('M j, Y');
$periodSub  = $typeInfo['uses_dates'] ? 'Selected dates' : 'Current stock (dates not used)';

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

    <?php if (!$dbReady): ?>
        <div class="rp-alert is-error" role="alert">The database could not be reached. Check that signupdb.php is in the project.</div>
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

            <?php if ($report['error'] !== ''): ?>
                <div class="rp-alert is-error" role="alert"><?= e($report['error']) ?></div>
            <?php endif; ?>

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
                        <span class="rp-stat-label"><?= e($typeInfo['total_label']) ?></span>
                        <span class="rp-stat-value"><?= $typeInfo['total_money'] ? peso((float)$report['total']) : e(numText($report['total'])) ?></span>
                        <span class="rp-stat-sub"><?= $typeInfo['uses_dates'] ? 'For the period' : 'Right now' ?></span>
                    </div>
                    <span class="rp-stat-icon rp-ic-green"><?= icon('amount') ?></span>
                </article>
                <article class="rp-stat">
                    <div>
                        <span class="rp-stat-label"><?= e($typeInfo['items_label']) ?></span>
                        <span class="rp-stat-value"><?= e(numText($report['items'])) ?></span>
                        <span class="rp-stat-sub">Counted in total</span>
                    </div>
                    <span class="rp-stat-icon rp-ic-amber"><?= icon('items') ?></span>
                </article>
                <article class="rp-stat">
                    <div>
                        <span class="rp-stat-label">Period</span>
                        <span class="rp-stat-period"><?= e($periodText) ?></span>
                        <span class="rp-stat-sub"><?= e($periodSub) ?></span>
                    </div>
                    <span class="rp-stat-icon rp-ic-red"><?= icon('calendar') ?></span>
                </article>
            </div>

            <div class="rp-card">
                <div class="rp-card-head">
                    <div>
                        <h2><?= e($typeInfo['label']) ?></h2>
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
                                        <p>No records found<?= $typeInfo['uses_dates'] ? ' for this period' : '' ?>.</p>
                                        <small><?= $typeInfo['uses_dates'] ? 'Try a different date range or report type.' : 'Add items in Inventory Management first.' ?></small>
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
                        Save a copy of your sales transactions, inventory items and stock records.
                        Download it and keep it somewhere safe.
                    </p>

                    <form method="post" action="<?= e(selfUrl()) ?>" class="rp-stack">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="create_backup">

                        <div class="rp-field">
                            <label for="backup_type">Backup type</label>
                            <select id="backup_type" name="backup_type">
                                <option value="full">Full backup (sales, inventory and stocks)</option>
                                <option value="sales">Sales and transactions only</option>
                                <option value="inventory">Inventory and stocks only</option>
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
                        Choose how often a full backup should be created. When one is due, it is saved
                        as soon as this page is opened.
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