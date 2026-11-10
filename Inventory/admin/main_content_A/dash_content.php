<?php
/* =====================================================================
   ADMIN DASHBOARD CONTENT
   Location: admin/main_content_A/dash_content.php

   - Loads its own stylesheet: admin/assets/dashboard.css
   - Finds your database connection and your tables automatically
   - Shows: weekly sales analytics, sales transactions, inventory transactions
   ===================================================================== */

/* ---------- 0. OPTIONAL SETTINGS ----------
   Normally you do NOT need to touch this. The page detects your tables by itself
   (open "Detected database mapping" on the dashboard to see what it picked).
   If it picks the wrong table/column, fill in the matching part below.      */
$defaultReorder = 10;   // used when an inventory table has no "reorder level" column

$override = [
    // 'sales' => ['table' => 'transactions', 'id' => 'id', 'customer' => 'customer_name',
    //             'payment' => 'payment_method', 'total' => 'total_amount', 'date' => 'created_at'],
    // 'items' => ['table' => 'transaction_items', 'sale_id' => 'transaction_id', 'qty' => 'quantity'],
    // 'stock' => ['table' => 'stocking_records', 'date' => 'created_at', 'qty' => 'quantity',
    //             'type' => 'type', 'name' => 'item_name', 'item_id' => null, 'by' => 'recorded_by', 'unit' => null],
    // 'inventory' => [
    //     ['table' => 'ingredients', 'id' => 'id', 'name' => 'name', 'qty' => 'quantity',
    //      'unit' => 'unit', 'reorder' => 'reorder_level', 'label' => 'Ingredients'],
    // ],
];

$dbFallback = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => ''];   // name '' = auto-find

/* ---------- 1. HELPERS ---------- */
if (!function_exists('ad_h')) {
    function ad_h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ad_q')) {
    function ad_q($name) { return '`' . str_replace('`', '``', (string) $name) . '`'; }
}
if (!function_exists('ad_money')) {
    function ad_money($n) { return '₱' . number_format((float) $n, 2); }
}
if (!function_exists('ad_num')) {
    function ad_num($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); }
}
if (!function_exists('ad_rows')) {                    // rows, or null when the query fails
    function ad_rows($db, $sql) {
        if (!$db) { return null; }
        try {
            $res = $db->query($sql);
            return $res ? $res->fetch_all(MYSQLI_ASSOC) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
if (!function_exists('ad_pick')) {                    // first column matching one of the patterns
    function ad_pick(array $cols, array $patterns, array $types = []) {
        foreach ($patterns as $p) {
            foreach ($cols as $name => $type) {
                if (preg_match($p, $name) && (!$types || in_array($type, $types, true))) { return $name; }
            }
        }
        return null;
    }
}
if (!function_exists('ad_col')) {
    function ad_col($alias, $col, $fallback = 'NULL') { return $col ? $alias . '.' . ad_q($col) : $fallback; }
}

/* ---------- 2. FIND THE DATABASE CONNECTION ---------- */
$db = null;
$dbNote = '';

foreach (array_merge($GLOBALS, get_defined_vars()) as $v) {        // reuse a connection the page already made
    if ($v instanceof mysqli) { $db = $v; break; }
}

if (!$db) {
    // read credentials from your connection file WITHOUT running it
    $guess = $dbFallback;
    $root  = dirname(__DIR__, 2);
    $dirs  = [$root, $root . '/phpfiles', $root . '/includes', $root . '/config', dirname(__DIR__)];

    foreach ($dirs as $dir) {
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            if (!preg_match('/(db|conn|connect|connection|config|database)/i', basename($file))) { continue; }
            $code = @file_get_contents($file);
            if ($code === false || stripos($code, 'mysql') === false) { continue; }

            if (preg_match('/(?:new\s+mysqli|mysqli_connect)\s*\(\s*([\'"])([^\'"]*)\1\s*,\s*([\'"])([^\'"]*)\3\s*,\s*([\'"])([^\'"]*)\5\s*,\s*([\'"])([^\'"]*)\7/i', $code, $m)) {
                $guess = ['host' => $m[2], 'user' => $m[4], 'pass' => $m[6], 'name' => $m[8]];
                break 2;
            }
            if (preg_match('/\$(?:db_?name|dbname|database|db)\s*=\s*([\'"])([^\'"]+)\1/i', $code, $m)) {
                $guess['name'] = $m[2];
                if (preg_match('/\$(?:db_?user|username|user)\s*=\s*([\'"])([^\'"]*)\1/i', $code, $u)) { $guess['user'] = $u[2]; }
                if (preg_match('/\$(?:db_?pass|password|pass)\s*=\s*([\'"])([^\'"]*)\1/i', $code, $p)) { $guess['pass'] = $p[2]; }
                if (preg_match('/\$(?:db_?host|servername|host|server)\s*=\s*([\'"])([^\'"]*)\1/i', $code, $h)) { $guess['host'] = $h[2]; }
                break 2;
            }
        }
    }

    try {
        if ($guess['name'] === '') { throw new Exception('no database name known'); }
        $db = new mysqli($guess['host'], $guess['user'], $guess['pass'], $guess['name']);
        $dbNote = 'Connected to database "' . $guess['name'] . '".';
    } catch (Throwable $e) {
        // name missing or wrong: connect without a database and pick the best one
        try {
            $db = new mysqli($guess['host'], $guess['user'], $guess['pass']);
            $rows = ad_rows($db,
                "SELECT TABLE_SCHEMA AS s, TABLE_NAME AS t FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA NOT IN ('information_schema','mysql','performance_schema','sys','phpmyadmin')");
            $score = [];
            foreach ($rows ?? [] as $r) {
                $score[$r['s']] = ($score[$r['s']] ?? 0)
                    + (preg_match('/(transaction|sale|order|ingredient|cookie|inventory|suppl|stock|employee)/i', $r['t']) ? 1 : 0);
            }
            foreach ($score as $schemaName => $n) {
                if (stripos($schemaName, 'inventory') !== false) { $score[$schemaName] = $n + 2; }
            }
            if ($score) {
                arsort($score);
                $best = array_key_first($score);
                $db->select_db($best);
                $dbNote = 'Database "' . $best . '" was picked automatically.';
            } else {
                $db = null;
            }
        } catch (Throwable $e2) {
            $db = null;
        }
    }
}

if ($db) { try { $db->set_charset('utf8mb4'); } catch (Throwable $e) {} }

/* ---------- 3. DETECT YOUR TABLES AND COLUMNS ---------- */
$NUM = ['int', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'float', 'double', 'numeric'];
$DT  = ['date', 'datetime', 'timestamp'];

$schema = [];
foreach (ad_rows($db,
    "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, LOWER(DATA_TYPE) AS d, COLUMN_KEY AS k
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
      ORDER BY TABLE_NAME, ORDINAL_POSITION") ?? [] as $r) {
    $schema[$r['t']]['cols'][$r['c']] = $r['d'];
    if ($r['k'] === 'PRI' && !isset($schema[$r['t']]['pri'])) { $schema[$r['t']]['pri'] = $r['c']; }
}

$map = ['sales' => null, 'items' => null, 'stock' => null, 'inventory' => []];

/* -- sales table: name has transaction/sale/order, and has a money column + a date column -- */
$bestScore = 0;
foreach ($schema as $t => $info) {
    if (!preg_match('/(transaction|sale|order|receipt)/i', $t)) { continue; }
    if (preg_match('/(item|detail|line|product|stock|inventory)/i', $t)) { continue; }

    $cols = $info['cols'];
    $tot  = ad_pick($cols, ['/^(total_amount|grand_total|total_price|total_sales|total|amount|subtotal)$/i', '/total|amount/i'], $NUM);
    $dt   = ad_pick($cols, ['/^(created_at|transaction_date|sale_date|order_date|date_created|datetime|date|timestamp)$/i', '/date|time|created/i'], $DT)
         ?? ad_pick($cols, ['/date|time|created/i']);
    if (!$tot || !$dt) { continue; }

    $score = preg_match('/^transactions?$/i', $t) ? 3 : (preg_match('/^sales?$/i', $t) ? 2 : 1);
    if ($score > $bestScore) {
        $bestScore = $score;
        $map['sales'] = [
            'table'    => $t,
            'id'       => $info['pri'] ?? ad_pick($cols, ['/^id$/i', '/_id$/i']) ?? array_key_first($cols),
            'customer' => ad_pick($cols, ['/customer/i']),
            'payment'  => ad_pick($cols, ['/payment|pay_?method|mode/i']),
            'total'    => $tot,
            'date'     => $dt,
        ];
    }
}

/* -- items inside a sale (optional) -- */
foreach ($schema as $t => $info) {
    if (!$map['sales'] || $t === $map['sales']['table']) { continue; }
    if (!preg_match('/(item|detail|line|product)/i', $t) || !preg_match('/(transaction|sale|order)/i', $t)) { continue; }
    $saleId = ad_pick($info['cols'], ['/^(transaction|sale|order)_?id$/i']);
    $qty    = ad_pick($info['cols'], ['/^(quantity|qty)$/i'], $NUM);
    if ($saleId && $qty) { $map['items'] = ['table' => $t, 'sale_id' => $saleId, 'qty' => $qty]; break; }
}

/* -- stock in / out history (optional) -- */
foreach ($schema as $t => $info) {
    if ($map['sales'] && $t === $map['sales']['table']) { continue; }
    if (!preg_match('/(stocking|stock_?record|record|history|movement|restock|stock_?in|stock_?out|stock_?log)/i', $t)) { continue; }
    if (preg_match('/(transaction|sale|order)/i', $t) && !preg_match('/stock/i', $t)) { continue; }

    $cols = $info['cols'];
    $qty  = ad_pick($cols, ['/^(quantity|qty|quantity_added|qty_added|added_quantity|quantity_change)$/i', '/qty|quantity/i'], $NUM);
    $dt   = ad_pick($cols, ['/^(created_at|date|datetime|record_date|stock_date|timestamp)$/i', '/date|time|created/i']);
    if (!$qty || !$dt) { continue; }

    $map['stock'] = [
        'table'   => $t,
        'date'    => $dt,
        'qty'     => $qty,
        'type'    => ad_pick($cols, ['/^(type|action|movement_type|transaction_type|stock_type)$/i']),
        'name'    => ad_pick($cols, ['/^(item_name|ingredient_name|product_name|name|item)$/i']),
        'item_id' => ad_pick($cols, ['/^(item_id|product_id|ingredient_id|inventory_id)$/i']),
        'by'      => ad_pick($cols, ['/^(recorded_by|added_by|updated_by|employee|employee_name|staff|user|username|by)$/i']),
        'unit'    => ad_pick($cols, ['/^unit/i']),
    ];
    break;
}

/* -- inventory tables: anything with an item name + a quantity (ingredients, cookies, supplies...) -- */
foreach ($schema as $t => $info) {
    if ($map['sales'] && $t === $map['sales']['table']) { continue; }
    if ($map['items'] && $t === $map['items']['table']) { continue; }
    if ($map['stock'] && $t === $map['stock']['table']) { continue; }
    if (preg_match('/(user|employee|account|admin|notif|backup|login|staff|session|setting|transaction|sale|order|record|history|log$)/i', $t)) { continue; }

    $cols = $info['cols'];
    $name = ad_pick($cols, ['/^(item_name|ingredient_name|supply_name|supplies_name|cookie_name|cookie_product|product_name|name)$/i', '/name/i']);
    $qty  = ad_pick($cols, ['/^(quantity|qty|stock|stock_quantity|current_stock|quantity_in_stock|on_hand|stock_level)$/i', '/(quantity|qty|stock)/i'], $NUM);
    if (!$name || !$qty) { continue; }

    $map['inventory'][] = [
        'table'   => $t,
        'id'      => $info['pri'] ?? ad_pick($cols, ['/^id$/i']),
        'name'    => $name,
        'qty'     => $qty,
        'unit'    => ad_pick($cols, ['/^unit/i']),
        'reorder' => ad_pick($cols, ['/reorder/i', '/(min|low|threshold|alert)/i'], $NUM),
        'label'   => ucwords(str_replace('_', ' ', $t)),
    ];
}

/* -- manual overrides win over detection -- */
foreach ($override as $key => $val) { $map[$key] = $val; }

$adErrors = [];
if (!$db)                  { $adErrors[] = 'Could not connect to the database. Check that MySQL is running in XAMPP.'; }
elseif (!$map['sales'])    { $adErrors[] = 'No sales table was found (looked for a table named like transaction/sale/order with a total and a date column). Use the $override block at the top of dash_content.php.'; }
if ($db && !$map['inventory']) { $adErrors[] = 'No inventory tables were found (looked for tables with an item name and a quantity column). Use the $override block at the top of dash_content.php.'; }

/* ---------- 4. BUILD THE QUERIES ---------- */
$weeks = (int) ($_GET['weeks'] ?? 8);
if (!in_array($weeks, [4, 8, 12], true)) { $weeks = 8; }

/* sales numbers */
$todaySales = $weekSales = $lastSales = 0.0;
$todayCount = $weekCount = $lastCount = 0;
$byWeek = [];
$sales  = [];

if ($map['sales']) {
    $s    = $map['sales'];
    $sT   = ad_q($s['table']);
    $sD   = 's.' . ad_q($s['date']);
    $sTot = 's.' . ad_q($s['total']);
    $sId  = 's.' . ad_q($s['id']);

    $r = ad_rows($db, "SELECT COALESCE(SUM($sTot),0) AS tot, COUNT(*) AS cnt FROM $sT s WHERE DATE($sD) = CURDATE()");
    $todaySales = (float) ($r[0]['tot'] ?? 0);  $todayCount = (int) ($r[0]['cnt'] ?? 0);

    $r = ad_rows($db, "SELECT COALESCE(SUM($sTot),0) AS tot, COUNT(*) AS cnt FROM $sT s WHERE YEARWEEK($sD,1) = YEARWEEK(CURDATE(),1)");
    $weekSales = (float) ($r[0]['tot'] ?? 0);   $weekCount = (int) ($r[0]['cnt'] ?? 0);

    $r = ad_rows($db, "SELECT COALESCE(SUM($sTot),0) AS tot, COUNT(*) AS cnt FROM $sT s WHERE YEARWEEK($sD,1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK),1)");
    $lastSales = (float) ($r[0]['tot'] ?? 0);   $lastCount = (int) ($r[0]['cnt'] ?? 0);

    $weekly = ad_rows($db,
        "SELECT YEARWEEK($sD,1) AS yw, COALESCE(SUM($sTot),0) AS tot, COUNT(*) AS cnt
           FROM $sT s
          WHERE $sD >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL " . ($weeks - 1) . " WEEK)
          GROUP BY YEARWEEK($sD,1)") ?? [];
    foreach ($weekly as $w) { $byWeek[(string) $w['yw']] = $w; }

    $itemsExpr = 'NULL';
    if ($map['items']) {
        $it = $map['items'];
        $itemsExpr = '(SELECT COALESCE(SUM(i.' . ad_q($it['qty']) . '),0) FROM ' . ad_q($it['table']) . ' i WHERE i.' . ad_q($it['sale_id']) . ' = ' . $sId . ')';
    }

    $sales = ad_rows($db,
        "SELECT $sId AS id,
                " . ad_col('s', $s['customer'] ?? null, "''") . " AS customer,
                " . ad_col('s', $s['payment'] ?? null, "''") . " AS payment,
                $sTot AS total, $sD AS made_at, $itemsExpr AS items
           FROM $sT s
          ORDER BY $sD DESC
          LIMIT 100") ?? [];
}

if ($lastSales > 0) {
    $change = (($weekSales - $lastSales) / $lastSales) * 100;
} else {
    $change = $weekSales > 0 ? 100 : 0;
}
$changeUp  = $change >= 0;
$changeTxt = ($changeUp ? '+' : '') . number_format($change, 0) . '% vs last week';

/* weekly series: one bar per week, empty weeks = 0 */
$chartLabels = [];
$chartSales  = [];
$chartCounts = [];
for ($i = $weeks - 1; $i >= 0; $i--) {
    $monday = new DateTime('monday this week');
    if ($i > 0) { $monday->modify("-$i week"); }
    $sunday = (clone $monday)->modify('+6 days');
    $key = $monday->format('oW');                          // same format as YEARWEEK(date, 1)

    $chartLabels[] = $monday->format('M j') . ' - ' . $sunday->format('M j');
    $chartSales[]  = round((float) ($byWeek[$key]['tot'] ?? 0), 2);
    $chartCounts[] = (int) ($byWeek[$key]['cnt'] ?? 0);
}
$rangeTotal = array_sum($chartSales);
$bestIdx    = $rangeTotal > 0 ? array_search(max($chartSales), $chartSales, true) : false;

/* inventory numbers (all inventory tables combined) */
$totalItems = $inStock = $lowStock = $outStock = 0;
$alerts = [];
$invSql = '';

if ($map['inventory']) {
    $parts = [];
    foreach ($map['inventory'] as $src) {
        $parts[] = 'SELECT CAST(' . ad_q($src['name']) . ' AS CHAR) AS name, '
                 . ad_q($src['qty']) . ' AS qty, '
                 . (!empty($src['unit']) ? 'CAST(' . ad_q($src['unit']) . ' AS CHAR)' : "''") . ' AS unit, '
                 . (!empty($src['reorder']) ? ad_q($src['reorder']) : (int) $defaultReorder) . ' AS reorder '
                 . 'FROM ' . ad_q($src['table']);
    }
    $invSql = implode(' UNION ALL ', $parts);

    $r = ad_rows($db,
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(qty > reorder),0) AS ok,
                COALESCE(SUM(qty > 0 AND qty <= reorder),0) AS low,
                COALESCE(SUM(qty <= 0),0) AS out_of
           FROM ($invSql) inv");
    $totalItems = (int) ($r[0]['total'] ?? 0);
    $inStock    = (int) ($r[0]['ok'] ?? 0);
    $lowStock   = (int) ($r[0]['low'] ?? 0);
    $outStock   = (int) ($r[0]['out_of'] ?? 0);

    $alerts = ad_rows($db,
        "SELECT name, qty, unit, reorder FROM ($invSql) inv
          WHERE qty <= reorder
          ORDER BY qty ASC, name ASC
          LIMIT 8") ?? [];
}
$alertCount = $lowStock + $outStock;

/* inventory transactions (stock in / out) */
$stockLog = [];
if ($map['stock']) {
    $k = $map['stock'];
    $kT = ad_q($k['table']);

    $join  = '';
    $unitE = "''";
    if (!empty($k['name'])) {
        $nameE = 'CAST(k.' . ad_q($k['name']) . ' AS CHAR)';
        $unitE = !empty($k['unit']) ? 'CAST(k.' . ad_q($k['unit']) . ' AS CHAR)' : "''";
    } elseif (!empty($k['item_id']) && count($map['inventory']) === 1 && !empty($map['inventory'][0]['id'])) {
        $inv   = $map['inventory'][0];
        $join  = 'LEFT JOIN ' . ad_q($inv['table']) . ' i ON i.' . ad_q($inv['id']) . ' = k.' . ad_q($k['item_id']);
        $nameE = 'i.' . ad_q($inv['name']);
        $unitE = !empty($inv['unit']) ? 'i.' . ad_q($inv['unit']) : "''";
    } elseif (!empty($k['item_id'])) {
        $nameE = "CONCAT('Item #', k." . ad_q($k['item_id']) . ')';
    } else {
        $nameE = "'-'";
    }

    $stockLog = ad_rows($db,
        "SELECT k." . ad_q($k['date']) . " AS made_at,
                " . ad_col('k', $k['type'] ?? null) . " AS type,
                k." . ad_q($k['qty']) . " AS qty,
                " . ad_col('k', $k['by'] ?? null) . " AS by_user,
                $nameE AS name, $unitE AS unit
           FROM $kT k $join
          ORDER BY k." . ad_q($k['date']) . " DESC
          LIMIT 100") ?? [];
}

$inWords = ['in', 'stock in', 'stock-in', 'stockin', 'add', 'added', 'restock', 'restocked', 'received', 'increase', 'purchase'];

/* ---------- 5. STYLESHEET LINK (admin/assets/dashboard.css) ---------- */
$cssFile = realpath(dirname(__DIR__) . '/assets/dashboard.css');
$docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
if ($cssFile && $docRoot && stripos($cssFile, $docRoot) === 0) {
    $cssHref = str_replace('\\', '/', substr($cssFile, strlen($docRoot)));
} else {
    $cssHref = 'admin/assets/dashboard.css';
}
$cssHref .= '?v=' . ($cssFile ? filemtime($cssFile) : '1');
?>
<link rel="stylesheet" href="<?= ad_h($cssHref) ?>">

<div class="ad-wrap">

    <?php foreach ($adErrors as $err): ?>
        <div class="ad-notice"><i class="bi bi-exclamation-triangle me-2"></i><?= ad_h($err) ?></div>
    <?php endforeach; ?>

    <details class="ad-diag">
        <summary>Detected database mapping</summary>
        <ul>
            <li><?= ad_h($dbNote !== '' ? $dbNote : ($db ? 'Using the existing database connection.' : 'No database connection.')) ?></li>
            <li>Sales: <?= $map['sales'] ? ad_h($map['sales']['table'] . ' (total: ' . $map['sales']['total'] . ', date: ' . $map['sales']['date'] . ')') : 'not found' ?></li>
            <li>Sale items: <?= $map['items'] ? ad_h($map['items']['table']) : 'not found (item counts hidden)' ?></li>
            <li>Inventory: <?php
                $names = [];
                foreach ($map['inventory'] as $src) { $names[] = $src['table'] . ' (' . $src['qty'] . (!empty($src['reorder']) ? ', reorder: ' . $src['reorder'] : ', reorder: default ' . $defaultReorder) . ')'; }
                echo $names ? ad_h(implode(', ', $names)) : 'not found';
            ?></li>
            <li>Stock history: <?= $map['stock'] ? ad_h($map['stock']['table']) : 'not found' ?></li>
        </ul>
    </details>

    <!-- ============ HERO ============ -->
    <section class="ad-hero">
        <div>
            <span class="ad-eyebrow">Business Overview</span>
            <h1 class="ad-hero-title">Dashboard</h1>
            <p class="ad-hero-text">Track weekly sales, review transactions and watch your stock levels in one place.</p>
        </div>
        <a href="admindash.php?page=inventory" class="ad-hero-btn">
            <i class="bi bi-plus-lg"></i> Add Item
        </a>
    </section>

    <!-- ============ STAT CARDS ============ -->
    <section class="ad-stats">
        <div class="ad-stat">
            <div>
                <span class="ad-stat-label">Today's Sales</span>
                <span class="ad-stat-value"><?= ad_money($todaySales) ?></span>
                <span class="ad-stat-sub"><?= $todayCount ?> transaction<?= $todayCount === 1 ? '' : 's' ?> today</span>
            </div>
            <span class="ad-stat-icon ad-ic-brown"><i class="bi bi-cash-coin"></i></span>
        </div>

        <div class="ad-stat">
            <div>
                <span class="ad-stat-label">This Week</span>
                <span class="ad-stat-value"><?= ad_money($weekSales) ?></span>
                <span class="ad-stat-sub <?= $changeUp ? 'ad-up' : 'ad-down' ?>">
                    <i class="bi <?= $changeUp ? 'bi-arrow-up-right' : 'bi-arrow-down-right' ?>"></i>
                    <?= ad_h($changeTxt) ?>
                </span>
            </div>
            <span class="ad-stat-icon ad-ic-green"><i class="bi bi-graph-up-arrow"></i></span>
        </div>

        <div class="ad-stat">
            <div>
                <span class="ad-stat-label">Low Stock</span>
                <span class="ad-stat-value"><?= $lowStock ?></span>
                <span class="ad-stat-sub">At or below reorder level</span>
            </div>
            <span class="ad-stat-icon ad-ic-amber"><i class="bi bi-exclamation-triangle"></i></span>
        </div>

        <div class="ad-stat">
            <div>
                <span class="ad-stat-label">Out of Stock</span>
                <span class="ad-stat-value"><?= $outStock ?></span>
                <span class="ad-stat-sub">Needs restocking</span>
            </div>
            <span class="ad-stat-icon ad-ic-red"><i class="bi bi-x-circle"></i></span>
        </div>
    </section>

    <!-- ============ MAIN GRID ============ -->
    <section class="ad-grid">

        <!-- LEFT: tabbed panel -->
        <div class="ad-card">

            <div class="ad-card-head">
                <div class="ad-tabs" role="tablist">
                    <button type="button" class="ad-tab active" data-tab="analytics" role="tab">Analytics</button>
                    <button type="button" class="ad-tab" data-tab="sales" role="tab">Sales Transactions</button>
                    <button type="button" class="ad-tab" data-tab="stock" role="tab">Inventory Transactions</button>
                </div>

                <div class="ad-search">
                    <i class="bi bi-search"></i>
                    <input type="text" id="ad-search" placeholder="Search transactions" autocomplete="off">
                </div>
            </div>

            <!-- ===== ANALYTICS ===== -->
            <div class="ad-panel" id="ad-panel-analytics">

                <div class="ad-analytics-top">
                    <div>
                        <h2 class="ad-h2">Weekly Sales</h2>
                        <p class="ad-muted">Last <?= $weeks ?> weeks &middot; <?= ad_money($rangeTotal) ?> total
                            <?php if ($bestIdx !== false): ?>
                                &middot; Best week: <?= ad_h($chartLabels[$bestIdx]) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <form method="get" class="ad-range">
                        <input type="hidden" name="page" value="dashboard">
                        <label for="ad-weeks">Range</label>
                        <select name="weeks" id="ad-weeks" onchange="this.form.submit()">
                            <?php foreach ([4, 8, 12] as $w): ?>
                                <option value="<?= $w ?>"<?= $w === $weeks ? ' selected' : '' ?>>Last <?= $w ?> weeks</option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <div class="ad-chart-box">
                    <canvas id="ad-chart"></canvas>
                </div>

                <div class="ad-week-table-wrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th class="ad-num">Transactions</th>
                                <th class="ad-num">Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = count($chartLabels) - 1; $i >= 0; $i--): ?>
                                <tr class="ad-static">
                                    <td><?= ad_h($chartLabels[$i]) ?></td>
                                    <td class="ad-num"><?= $chartCounts[$i] ?></td>
                                    <td class="ad-num ad-strong"><?= ad_money($chartSales[$i]) ?></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== SALES TRANSACTIONS ===== -->
            <div class="ad-panel" id="ad-panel-sales" hidden>
                <div class="ad-table-wrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Transaction</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Payment</th>
                                <th class="ad-num">Items</th>
                                <th class="ad-num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales as $row): ?>
                                <tr>
                                    <td><span class="ad-strong">#<?= ad_h($row['id']) ?></span></td>
                                    <td>
                                        <?= ad_h(date('M j, Y', strtotime($row['made_at']))) ?>
                                        <span class="ad-sub"><?= ad_h(date('g:i A', strtotime($row['made_at']))) ?></span>
                                    </td>
                                    <td><?= ad_h($row['customer'] !== null && $row['customer'] !== '' ? $row['customer'] : 'Walk-in') ?></td>
                                    <td>
                                        <?php if ($row['payment'] !== null && $row['payment'] !== ''): ?>
                                            <span class="ad-pill ad-pill-neutral"><?= ad_h($row['payment']) ?></span>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td class="ad-num"><?= $row['items'] !== null ? (int) $row['items'] : '-' ?></td>
                                    <td class="ad-num ad-strong"><?= ad_money($row['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$sales): ?>
                                <tr class="ad-static"><td colspan="6" class="ad-empty">No sales transactions yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== INVENTORY TRANSACTIONS ===== -->
            <div class="ad-panel" id="ad-panel-stock" hidden>
                <div class="ad-table-wrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Item</th>
                                <th>Type</th>
                                <th class="ad-num">Quantity</th>
                                <th>Unit</th>
                                <th>Recorded by</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stockLog as $row):
                                $qtyVal = (float) $row['qty'];
                                if ($row['type'] !== null && $row['type'] !== '') {
                                    $isIn = in_array(strtolower(trim((string) $row['type'])), $inWords, true);
                                } else {
                                    $isIn = $qtyVal >= 0;                  // no type column: positive = stock in
                                } ?>
                                <tr>
                                    <td>
                                        <?= ad_h(date('M j, Y', strtotime($row['made_at']))) ?>
                                        <span class="ad-sub"><?= ad_h(date('g:i A', strtotime($row['made_at']))) ?></span>
                                    </td>
                                    <td><span class="ad-strong"><?= ad_h($row['name'] ?? 'Deleted item') ?></span></td>
                                    <td>
                                        <span class="ad-pill <?= $isIn ? 'ad-pill-in' : 'ad-pill-out' ?>">
                                            <?= $isIn ? 'Stock In' : 'Stock Out' ?>
                                        </span>
                                    </td>
                                    <td class="ad-num ad-strong"><?= $isIn ? '+' : '-' ?><?= ad_h(ad_num(abs($qtyVal))) ?></td>
                                    <td><?= ad_h($row['unit'] ?? '') ?></td>
                                    <td><?= ad_h($row['by_user'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$stockLog): ?>
                                <tr class="ad-static"><td colspan="6" class="ad-empty">No inventory transactions yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="ad-card-foot">
                <span id="ad-foot-left">Weekly sales are counted Monday to Sunday.</span>
                <span id="ad-foot-right"></span>
            </div>
        </div>

        <!-- RIGHT: side cards -->
        <aside class="ad-side">

            <!-- Stock alerts -->
            <div class="ad-card">
                <div class="ad-side-head">
                    <span class="ad-side-icon ad-ic-red"><i class="bi bi-bell"></i></span>
                    <span class="ad-side-title">Stock Alerts</span>
                    <span class="ad-count"><?= $alertCount ?></span>
                </div>

                <?php foreach ($alerts as $a):
                    $out = (float) $a['qty'] <= 0; ?>
                    <div class="ad-alert">
                        <span class="ad-side-icon <?= $out ? 'ad-ic-red' : 'ad-ic-amber' ?>">
                            <i class="bi <?= $out ? 'bi-x-circle' : 'bi-exclamation-triangle' ?>"></i>
                        </span>
                        <div>
                            <span class="ad-alert-name"><?= ad_h($a['name']) ?></span>
                            <span class="ad-alert-text">
                                <?php if ($out): ?>
                                    Out of stock. Reorder now.
                                <?php else: ?>
                                    <?= ad_h(ad_num($a['qty'])) ?> <?= ad_h($a['unit']) ?> left (reorder at <?= ad_h(ad_num($a['reorder'])) ?>)
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!$alerts): ?>
                    <div class="ad-alert ad-alert-ok">
                        <span class="ad-side-icon ad-ic-green"><i class="bi bi-check-circle"></i></span>
                        <div>
                            <span class="ad-alert-name">All stocked up</span>
                            <span class="ad-alert-text">No items are at or below reorder level.</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Weekly summary -->
            <div class="ad-card ad-summary">
                <div class="ad-side-head">
                    <span class="ad-side-icon ad-ic-brown"><i class="bi bi-calendar-week"></i></span>
                    <span class="ad-side-title">Week Summary</span>
                </div>

                <div class="ad-sum-row">
                    <span>This week</span>
                    <strong><?= ad_money($weekSales) ?></strong>
                </div>
                <div class="ad-sum-row">
                    <span>Last week</span>
                    <strong><?= ad_money($lastSales) ?></strong>
                </div>
                <div class="ad-sum-row">
                    <span>Transactions (this / last)</span>
                    <strong><?= $weekCount ?> / <?= $lastCount ?></strong>
                </div>
                <div class="ad-sum-row">
                    <span>Average sale</span>
                    <strong><?= ad_money($weekCount > 0 ? $weekSales / $weekCount : 0) ?></strong>
                </div>
                <div class="ad-sum-row">
                    <span>Inventory items</span>
                    <strong><?= $totalItems ?> (<?= $inStock ?> in stock)</strong>
                </div>
            </div>

        </aside>
    </section>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
(function () {
    /* ---------- weekly sales chart ---------- */
    var labels = <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var sales  = <?= json_encode($chartSales) ?>;
    var counts = <?= json_encode($chartCounts) ?>;
    var canvas = document.getElementById('ad-chart');

    if (canvas && window.Chart) {
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Sales',
                    data: sales,
                    backgroundColor: '#8a5733',
                    hoverBackgroundColor: '#6b4a37',
                    borderRadius: 8,
                    maxBarThickness: 46
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (c) { return ' ₱' + Number(c.parsed.y).toLocaleString(undefined, {minimumFractionDigits: 2}); },
                            afterLabel: function (c) { return ' ' + counts[c.dataIndex] + ' transaction(s)'; }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#7d6a5e', maxRotation: 0, autoSkip: true } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.06)' },
                        ticks: { color: '#7d6a5e', callback: function (v) { return '₱' + Number(v).toLocaleString(); } }
                    }
                }
            }
        });
    }

    /* ---------- tabs + search ---------- */
    var tabs    = document.querySelectorAll('.ad-tab');
    var panels  = { analytics: document.getElementById('ad-panel-analytics'),
                    sales:     document.getElementById('ad-panel-sales'),
                    stock:     document.getElementById('ad-panel-stock') };
    var search  = document.getElementById('ad-search');
    var footL   = document.getElementById('ad-foot-left');
    var footR   = document.getElementById('ad-foot-right');
    var current = 'analytics';

    function filterRows() {
        if (current === 'analytics') { footR.textContent = ''; return; }

        var term = search.value.trim().toLowerCase();
        var rows = panels[current].querySelectorAll('tbody tr:not(.ad-static)');
        var shown = 0;

        rows.forEach(function (tr) {
            var match = tr.textContent.toLowerCase().indexOf(term) !== -1;
            tr.style.display = match ? '' : 'none';
            if (match) { shown++; }
        });

        footL.textContent = 'Showing ' + shown + ' of ' + rows.length + ' transactions';
        footR.textContent = 'Latest 100 records are loaded';
    }

    function showTab(name) {
        if (!panels[name]) { name = 'analytics'; }
        current = name;

        tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === name); });
        Object.keys(panels).forEach(function (k) { panels[k].hidden = (k !== name); });

        search.disabled = (name === 'analytics');
        search.placeholder = name === 'analytics' ? 'Search is for transaction tabs' : 'Search transactions';
        if (name === 'analytics') { search.value = ''; footL.textContent = 'Weekly sales are counted Monday to Sunday.'; }

        filterRows();
        if (history.replaceState) { history.replaceState(null, '', '#' + name); }
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { showTab(t.dataset.tab); });
    });
    search.addEventListener('input', filterRows);

    showTab(location.hash ? location.hash.replace('#', '') : 'analytics');
})();
</script>