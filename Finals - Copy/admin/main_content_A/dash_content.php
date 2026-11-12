<?php
/* =====================================================================
   ADMIN DASHBOARD CONTENT
   Location: admin/main_content_A/dash_content.php

   - Loads its own stylesheet: admin/assets/dashboard.css
   - SALES and INVENTORY both come from PostgreSQL (through signupdb.php):
       * sales_orders + sales_order_lines  = the customer orders the staff
         dashboard (dashboard_e.php) records, the same ones Transaction View
         shows. Run sales_update.sql once if these tables do not exist yet.
       * inventory_items                    = Inventory Management
   - Shows: weekly sales analytics (sales, transactions and cookies sold for
     every week), sales transactions, inventory records, inventory counts
     and expiry alerts
   - The "Data sources" box is hidden. To see it, add &debug=1 to the address
     (example: admindash.php?page=dashboard&debug=1)
   ===================================================================== */

/* ---------- 0. OPTIONAL SETTINGS ---------- */
$expiryDays = 30;           // ingredients expiring within this many days show up as alerts

/* Which orders count in the sales numbers and the weekly analytics:
     'all'       = every recorded order (Pending and Completed)
     'completed' = only orders marked Complete in Transaction View */
$salesCount = 'all';

/* ---------- 1. HELPERS ---------- */
if (!function_exists('ad_h')) {
    function ad_h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ad_money')) {
    function ad_money($n) { return '₱' . number_format((float) $n, 2); }
}
if (!function_exists('ad_pg')) {                      // PostgreSQL rows, or null when the query fails
    function ad_pg($pdo, $sql) {
        if (!$pdo) { return null; }
        try {
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('dash_content query failed: ' . $e->getMessage());
            return null;
        }
    }
}

/* ---------- 2. POSTGRESQL CONNECTION ---------- */
$pg = null;
$pgNote = '';

try {
    if (!function_exists('db')) {
        $root = dirname(__DIR__, 2);                       // project root (INVENTORY/)
        require_once $root . '/signupdb.php';
    }
    $pg = db();
    $pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pgNote = 'Connected to PostgreSQL.';
} catch (Throwable $e) {
    $pg = null;
    $pgNote = 'PostgreSQL connection failed: ' . $e->getMessage();
    error_log('dash_content postgres connection failed: ' . $e->getMessage());
}

/* is the sales table there? */
$salesReady = $pg && ad_pg($pg, 'SELECT 1 FROM sales_orders LIMIT 1') !== null;

$adErrors = [];
if (!$pg) {
    $adErrors[] = 'The database could not be reached. Check that signupdb.php is in the project root.';
} elseif (!$salesReady) {
    $adErrors[] = 'The sales tables are missing. Run sales_update.sql in pgAdmin, then reload this page.';
}

/* ---------- 3. SALES NUMBERS (sales_orders) ---------- */
$weeks = (int) ($_GET['weeks'] ?? 8);
if (!in_array($weeks, [4, 8, 12], true)) { $weeks = 8; }

$todaySales = $weekSales = $lastSales = 0.0;
$todayCount = $weekCount = $lastCount = 0;
$weekCookies = $lastCookies = 0;
$byWeek = [];
$sales  = [];

if ($salesReady) {
    /* every date is worked out in Philippine time; PostgreSQL weeks start on Monday */
    $local     = "(o.ordered_at AT TIME ZONE 'Asia/Manila')";
    $nowLocal  = "(NOW() AT TIME ZONE 'Asia/Manila')";
    $weekStart = "date_trunc('week', $nowLocal)";
    $onlyDone  = $salesCount === 'completed' ? "AND o.status = 'Completed'" : '';

    $sum = "COALESCE(SUM(o.total), 0) AS tot, COUNT(*) AS cnt, COALESCE(SUM(o.items), 0) AS cookies";

    $r = ad_pg($pg, "SELECT $sum FROM sales_orders o WHERE $local::date = $nowLocal::date $onlyDone");
    $todaySales = (float) ($r[0]['tot'] ?? 0);
    $todayCount = (int) ($r[0]['cnt'] ?? 0);

    $r = ad_pg($pg, "SELECT $sum FROM sales_orders o WHERE $local >= $weekStart $onlyDone");
    $weekSales   = (float) ($r[0]['tot'] ?? 0);
    $weekCount   = (int) ($r[0]['cnt'] ?? 0);
    $weekCookies = (int) ($r[0]['cookies'] ?? 0);

    $r = ad_pg($pg, "SELECT $sum FROM sales_orders o
                      WHERE $local >= $weekStart - INTERVAL '1 week' AND $local < $weekStart $onlyDone");
    $lastSales   = (float) ($r[0]['tot'] ?? 0);
    $lastCount   = (int) ($r[0]['cnt'] ?? 0);
    $lastCookies = (int) ($r[0]['cookies'] ?? 0);

    /* one row per week that has orders, keyed by the Monday of that week (Y-m-d) */
    $n = $weeks - 1;
    $weekly = ad_pg($pg,
        "SELECT to_char(date_trunc('week', $local), 'YYYY-MM-DD') AS wk, $sum
           FROM sales_orders o
          WHERE $local >= $weekStart - INTERVAL '$n week' $onlyDone
          GROUP BY 1
          ORDER BY 1") ?? [];
    foreach ($weekly as $w) { $byWeek[$w['wk']] = $w; }

    $sales = ad_pg($pg,
        "SELECT o.id, o.customer, o.payment, o.status, o.total, o.items,
                to_char($local, 'YYYY-MM-DD HH24:MI:SS') AS made_at
           FROM sales_orders o
          ORDER BY o.id DESC
          LIMIT 100") ?? [];
}

if ($lastSales > 0) {
    $change = (($weekSales - $lastSales) / $lastSales) * 100;
} else {
    $change = $weekSales > 0 ? 100 : 0;
}
$changeUp  = $change >= 0;
$changeTxt = ($changeUp ? '+' : '') . number_format($change, 0) . '% vs last week';

/* weekly series: one entry per week (oldest first), empty weeks = 0 */
$tz = new DateTimeZone('Asia/Manila');
$chartLabels  = [];
$chartSales   = [];
$chartCounts  = [];
$chartCookies = [];
for ($i = $weeks - 1; $i >= 0; $i--) {
    $monday = new DateTime('monday this week', $tz);
    if ($i > 0) { $monday->modify("-$i week"); }
    $sunday = (clone $monday)->modify('+6 days');
    $key = $monday->format('Y-m-d');

    $chartLabels[]  = $monday->format('M j') . ' - ' . $sunday->format('M j');
    $chartSales[]   = round((float) ($byWeek[$key]['tot'] ?? 0), 2);
    $chartCounts[]  = (int) ($byWeek[$key]['cnt'] ?? 0);
    $chartCookies[] = (int) ($byWeek[$key]['cookies'] ?? 0);
}
$rangeTotal = array_sum($chartSales);
$rangeCount = array_sum($chartCounts);
$rangeCookies = array_sum($chartCookies);
$bestIdx    = $rangeTotal > 0 ? array_search(max($chartSales), $chartSales, true) : false;
$thisIdx    = $weeks - 1;                                   // the last entry is the current week

/* ---------- 4. INVENTORY NUMBERS (inventory_items) ---------- */
$countIngredients = $countProducts = $countSupplies = 0;
$expiredCount = $expiringCount = 0;
$alerts   = [];
$stockLog = [];

if ($pg) {
    $days = max(1, (int) $expiryDays);

    foreach (ad_pg($pg, 'SELECT type, COUNT(*) AS cnt FROM inventory_items GROUP BY type') ?? [] as $r) {
        if ($r['type'] === 'Ingredients') { $countIngredients = (int) $r['cnt']; }
        if ($r['type'] === 'Products')    { $countProducts    = (int) $r['cnt']; }
        if ($r['type'] === 'Supplies')    { $countSupplies    = (int) $r['cnt']; }
    }

    $r = ad_pg($pg,
        "SELECT COALESCE(SUM(CASE WHEN expiration < CURRENT_DATE THEN 1 ELSE 0 END), 0) AS expired,
                COALESCE(SUM(CASE WHEN expiration >= CURRENT_DATE AND expiration <= CURRENT_DATE + $days THEN 1 ELSE 0 END), 0) AS soon
           FROM inventory_items
          WHERE type = 'Ingredients' AND expiration IS NOT NULL");
    $expiredCount  = (int) ($r[0]['expired'] ?? 0);
    $expiringCount = (int) ($r[0]['soon'] ?? 0);

    $alerts = ad_pg($pg,
        "SELECT name, unit, expiration, (expiration - CURRENT_DATE) AS days_left
           FROM inventory_items
          WHERE type = 'Ingredients' AND expiration IS NOT NULL AND expiration <= CURRENT_DATE + $days
          ORDER BY expiration ASC, name ASC
          LIMIT 8") ?? [];

    /* find a readable name column in the users table (only whitelisted column names are used) */
    $userCols = [];
    foreach (ad_pg($pg,
        "SELECT column_name FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = 'users'") ?? [] as $r) {
        $userCols[strtolower($r['column_name'])] = true;
    }

    $nameExpr = 'NULL';
    if (isset($userCols['full_name'])) {
        $nameExpr = 'u.full_name';
    } elseif (isset($userCols['first_name']) && isset($userCols['last_name'])) {
        $nameExpr = "NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), '')";
    } elseif (isset($userCols['name'])) {
        $nameExpr = 'u.name';
    } elseif (isset($userCols['username'])) {
        $nameExpr = 'u.username';
    } elseif (isset($userCols['email'])) {
        $nameExpr = 'u.email';
    }

    /* newest first; tries the richest query first and falls back step by step */
    $recCols = 'i.type, i.name, i.unit, i.price, i.category, i.date_added, i.created_at, i.created_by';
    $recTail = 'ORDER BY i.created_at DESC, i.id DESC LIMIT 100';
    $recJoin = 'FROM inventory_items i LEFT JOIN users u ON u.id = i.created_by';
    $recPlain = 'FROM inventory_items i';

    foreach ([
        "SELECT $recCols, i.added_by_role AS rec_role, i.added_by_name AS rec_name, $nameExpr AS added_by_name $recJoin $recTail",
        "SELECT $recCols, i.added_by_role AS rec_role, i.added_by_name AS rec_name, NULL AS added_by_name $recPlain $recTail",
        "SELECT $recCols, NULL AS rec_role, NULL AS rec_name, $nameExpr AS added_by_name $recJoin $recTail",
        "SELECT $recCols, NULL AS rec_role, NULL AS rec_name, NULL AS added_by_name $recPlain $recTail",
    ] as $recSql) {
        $res = ad_pg($pg, $recSql);
        if ($res !== null) { $stockLog = $res; break; }
    }
}

$totalItems = $countIngredients + $countProducts + $countSupplies;
$alertCount = $expiredCount + $expiringCount;

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

    <?php /* "Data sources" is only shown when the address has &debug=1 */ ?>
    <?php if (isset($_GET['debug'])): ?>
    <details class="ad-diag">
        <summary>Data sources</summary>
        <ul>
            <li>Database: <?= ad_h($pgNote) ?></li>
            <li>Sales: <?= $salesReady ? 'sales_orders and sales_order_lines (' . ($salesCount === 'completed' ? 'completed orders only' : 'all orders') . ' are counted)' : 'not available' ?></li>
            <li>Inventory: inventory_items</li>
        </ul>
    </details>
    <?php endif; ?>

    <!-- ============ HERO ============ -->
    <section class="ad-hero">
        <div>
            <span class="ad-eyebrow">Business Overview</span>
            <h1 class="ad-hero-title">Dashboard</h1>
            <p class="ad-hero-text">Track weekly sales, review transactions and watch your inventory in one place.</p>
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
                <span class="ad-stat-label">Inventory Items</span>
                <span class="ad-stat-value"><?= $totalItems ?></span>
                <span class="ad-stat-sub"><?= $countIngredients ?> ingredients, <?= $countProducts ?> products, <?= $countSupplies ?> supplies</span>
            </div>
            <span class="ad-stat-icon ad-ic-amber"><i class="bi bi-boxes"></i></span>
        </div>

        <div class="ad-stat">
            <div>
                <span class="ad-stat-label">Expiring Soon</span>
                <span class="ad-stat-value"><?= $alertCount ?></span>
                <span class="ad-stat-sub"><?= $expiredCount ?> expired, <?= $expiringCount ?> within <?= (int) $expiryDays ?> days</span>
            </div>
            <span class="ad-stat-icon ad-ic-red"><i class="bi bi-hourglass-split"></i></span>
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
                    <button type="button" class="ad-tab" data-tab="stock" role="tab">Inventory Records</button>
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
                        <p class="ad-muted" id="ad-range-note">Last <?= $weeks ?> weeks &middot; <?= ad_money($rangeTotal) ?> total
                            &middot; <?= $rangeCount ?> transaction<?= $rangeCount === 1 ? '' : 's' ?>
                            &middot; <?= $rangeCookies ?> cookie<?= $rangeCookies === 1 ? '' : 's' ?> sold
                            <?php if ($bestIdx !== false): ?>
                                &middot; Best week: <?= ad_h($chartLabels[$bestIdx]) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <form method="get" class="ad-range">
                        <input type="hidden" name="page" value="dashboard">
                        <?php if (isset($_GET['debug'])): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
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
                                <th class="ad-num">Cookies sold</th>
                                <th class="ad-num">Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = count($chartLabels) - 1; $i >= 0; $i--): ?>
                                <tr class="ad-static">
                                    <td>
                                        <?= ad_h($chartLabels[$i]) ?>
                                        <?php if ($i === $thisIdx): ?><span class="ad-pill ad-pill-in">This week</span><?php endif; ?>
                                    </td>
                                    <td class="ad-num"><?= $chartCounts[$i] ?></td>
                                    <td class="ad-num"><?= $chartCookies[$i] ?></td>
                                    <td class="ad-num ad-strong"><?= ad_money($chartSales[$i]) ?></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr class="ad-static">
                                <td class="ad-strong">Total (<?= $weeks ?> weeks)</td>
                                <td class="ad-num ad-strong"><?= $rangeCount ?></td>
                                <td class="ad-num ad-strong"><?= $rangeCookies ?></td>
                                <td class="ad-num ad-strong"><?= ad_money($rangeTotal) ?></td>
                            </tr>
                        </tfoot>
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
                                <th class="ad-num">Cookies</th>
                                <th>Status</th>
                                <th class="ad-num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales as $row): ?>
                                <tr>
                                    <td><span class="ad-strong">#<?= ad_h(str_pad((string) $row['id'], 4, '0', STR_PAD_LEFT)) ?></span></td>
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
                                    <td class="ad-num"><?= (int) $row['items'] ?></td>
                                    <td>
                                        <span class="ad-pill <?= $row['status'] === 'Completed' ? 'ad-pill-in' : 'ad-pill-neutral' ?>"><?= ad_h($row['status']) ?></span>
                                    </td>
                                    <td class="ad-num ad-strong"><?= ad_money($row['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$sales): ?>
                                <tr class="ad-static"><td colspan="7" class="ad-empty">No sales transactions yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ===== INVENTORY RECORDS (from Inventory Management) ===== -->
            <div class="ad-panel" id="ad-panel-stock" hidden>
                <div class="ad-table-wrap">
                    <table class="ad-table">
                        <thead>
                            <tr>
                                <th>Date Added</th>
                                <th>Item</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Unit</th>
                                <th class="ad-num">Price</th>
                                <th>Added by</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stockLog as $row): ?>
                                <tr>
                                    <?php
                                        try {
                                            $addedAt = !empty($row['created_at'])
                                                ? (new DateTime($row['created_at']))->setTimezone(new DateTimeZone('Asia/Manila'))
                                                : null;
                                        } catch (Throwable $e) {
                                            $addedAt = null;
                                        }
                                    ?>
                                    <td>
                                        <?= ad_h($addedAt ? $addedAt->format('M j, Y') : date('M j, Y', strtotime($row['date_added']))) ?>
                                        <?php if ($addedAt): ?>
                                            <span class="ad-sub"><?= ad_h($addedAt->format('g:i A')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="ad-strong"><?= ad_h($row['name']) ?></span></td>
                                    <td>
                                        <span class="ad-pill <?= $row['type'] === 'Products' ? 'ad-pill-in' : 'ad-pill-neutral' ?>">
                                            <?= ad_h($row['type']) ?>
                                        </span>
                                    </td>
                                    <td><?= ad_h($row['category'] ?? '-') ?: '-' ?></td>
                                    <td><?= ad_h($row['unit']) ?></td>
                                    <td class="ad-num ad-strong"><?= $row['price'] !== null ? ad_money($row['price']) : '-' ?></td>
                                    <?php
                                        $recRole = $row['rec_role'] ?? null;
                                        if ($recRole === 'admin')     { $isEmp = false; }
                                        elseif ($recRole === 'user')  { $isEmp = true; }
                                        else                          { $isEmp = $row['created_by'] !== null; }   // older rows

                                        $who = trim((string) ($row['rec_name'] ?? ''));
                                        if ($who === '') { $who = trim((string) ($row['added_by_name'] ?? '')); }
                                        if ($who === '') {
                                            $who = $isEmp
                                                ? ($row['created_by'] !== null ? 'Employee #' . $row['created_by'] : 'Employee')
                                                : 'Admin';
                                        }
                                    ?>
                                    <td>
                                        <span class="ad-strong"><?= ad_h($who) ?></span>
                                        <span class="ad-pill <?= $isEmp ? 'ad-pill-in' : 'ad-pill-neutral' ?>"><?= $isEmp ? 'Employee' : 'Admin' ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$stockLog): ?>
                                <tr class="ad-static"><td colspan="7" class="ad-empty">No inventory items yet. Add some in Inventory Management.</td></tr>
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

            <!-- Expiry alerts -->
            <div class="ad-card">
                <div class="ad-side-head">
                    <span class="ad-side-icon ad-ic-red"><i class="bi bi-bell"></i></span>
                    <span class="ad-side-title">Expiry Alerts</span>
                    <span class="ad-count"><?= $alertCount ?></span>
                </div>

                <?php foreach ($alerts as $a):
                    $daysLeft = (int) $a['days_left'];
                    $expired  = $daysLeft < 0; ?>
                    <div class="ad-alert">
                        <span class="ad-side-icon <?= $expired ? 'ad-ic-red' : 'ad-ic-amber' ?>">
                            <i class="bi <?= $expired ? 'bi-x-circle' : 'bi-exclamation-triangle' ?>"></i>
                        </span>
                        <div>
                            <span class="ad-alert-name"><?= ad_h($a['name']) ?></span>
                            <span class="ad-alert-text">
                                <?php if ($expired): ?>
                                    Expired on <?= ad_h(date('M j, Y', strtotime($a['expiration']))) ?>.
                                <?php elseif ($daysLeft === 0): ?>
                                    Expires today.
                                <?php else: ?>
                                    Expires in <?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?> (<?= ad_h(date('M j, Y', strtotime($a['expiration']))) ?>)
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!$alerts): ?>
                    <div class="ad-alert ad-alert-ok">
                        <span class="ad-side-icon ad-ic-green"><i class="bi bi-check-circle"></i></span>
                        <div>
                            <span class="ad-alert-name">All good</span>
                            <span class="ad-alert-text">No ingredients are expired or close to expiring.</span>
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
                    <span>Cookies sold (this / last)</span>
                    <strong><?= $weekCookies ?> / <?= $lastCookies ?></strong>
                </div>
                <div class="ad-sum-row">
                    <span>Average sale</span>
                    <strong><?= ad_money($weekCount > 0 ? $weekSales / $weekCount : 0) ?></strong>
                </div>
                <div class="ad-sum-row">
                    <span>Inventory items</span>
                    <strong><?= $totalItems ?></strong>
                </div>
            </div>

        </aside>
    </section>
</div>

<!-- the numbers behind the chart (also read again on every refresh) -->
<script type="application/json" id="ad-data"><?= json_encode([
    'labels' => $chartLabels,
    'sales'  => $chartSales,
    'counts' => $chartCounts,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
(function () {
    /* ---------- weekly sales chart: bars = sales, line = number of transactions ---------- */
    var data   = JSON.parse(document.getElementById('ad-data').textContent);
    var canvas = document.getElementById('ad-chart');
    var chart  = null;

    if (canvas && window.Chart) {
        chart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Sales (₱)',
                        data: data.sales,
                        yAxisID: 'y',
                        backgroundColor: '#8a5733',
                        hoverBackgroundColor: '#6b4a37',
                        borderRadius: 8,
                        maxBarThickness: 46,
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Transactions',
                        data: data.counts,
                        yAxisID: 'y2',
                        borderColor: '#2f7d4f',
                        backgroundColor: '#2f7d4f',
                        borderWidth: 2,
                        tension: 0.25,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, labels: { color: '#7d6a5e', boxWidth: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (c) {
                                if (c.datasetIndex === 0) {
                                    return ' Sales: ₱' + Number(c.parsed.y).toLocaleString(undefined, { minimumFractionDigits: 2 });
                                }
                                return ' ' + c.parsed.y + ' transaction' + (c.parsed.y === 1 ? '' : 's');
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#7d6a5e', maxRotation: 0, autoSkip: true } },
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        grid: { color: 'rgba(0,0,0,0.06)' },
                        ticks: { color: '#7d6a5e', callback: function (v) { return '₱' + Number(v).toLocaleString(); } }
                    },
                    y2: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { color: '#2f7d4f', precision: 0, stepSize: 1 }
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

        footL.textContent = 'Showing ' + shown + ' of ' + rows.length + (current === 'stock' ? ' records' : ' transactions');
        footR.textContent = 'Latest 100 records are loaded';
    }

    function showTab(name) {
        if (!panels[name]) { name = 'analytics'; }
        current = name;

        tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === name); });
        Object.keys(panels).forEach(function (k) { panels[k].hidden = (k !== name); });

        search.disabled = (name === 'analytics');
        search.placeholder = name === 'analytics' ? 'Search is for the record tabs' : 'Search records';
        if (name === 'analytics') { search.value = ''; footL.textContent = 'Weekly sales are counted Monday to Sunday.'; }

        filterRows();
        if (history.replaceState) { history.replaceState(null, '', '#' + name); }
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { showTab(t.dataset.tab); });
    });
    search.addEventListener('input', filterRows);

    /* ---------- keep sales and inventory up to date (every 30 seconds) ---------- */
    function refreshData() {
        if (document.hidden) { return; }

        fetch(location.pathname + location.search, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) { return res.ok ? res.text() : Promise.reject(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');

                ['.ad-stats', '#ad-range-note', '.ad-week-table-wrap', '#ad-panel-sales', '#ad-panel-stock', '.ad-side'].forEach(function (sel) {
                    var fresh = doc.querySelector(sel);
                    var live  = document.querySelector(sel);
                    if (fresh && live) { live.innerHTML = fresh.innerHTML; }
                });

                /* update the chart without redrawing the whole page */
                var freshData = doc.getElementById('ad-data');
                if (freshData && chart) {
                    try {
                        var d = JSON.parse(freshData.textContent);
                        chart.data.labels = d.labels;
                        chart.data.datasets[0].data = d.sales;
                        chart.data.datasets[1].data = d.counts;
                        chart.update();
                    } catch (e) { }
                }

                if (current !== 'analytics') { filterRows(); }
            })
            .catch(function () { /* try again at the next interval */ });
    }

    setInterval(refreshData, 30000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { refreshData(); }
    });

    showTab(location.hash ? location.hash.replace('#', '') : 'analytics');
})();
</script>