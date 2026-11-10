<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$employeeName = $_SESSION['employee_name'] ?? 'Employee';
$units        = ['Packs', 'Pieces', 'Boxes', 'Bottles', 'Kilograms', 'Grams', 'Liters', 'Milliliters'];
$today        = date('Y-m-d');

/* Inventories. The items come from Inventory Management (browser storage key "inventoryItems"). */
$inventories = [
    'ingredients' => 'Ingredients',
    'supplies'    => 'Supplies',
    'cookies'     => 'Cookies',
];

/* Which page (tab) is open: add, stock or records */
$tab = (string)($_GET['tab'] ?? 'add');
if (!in_array($tab, ['add', 'stock', 'records'], true)) {
    $tab = 'add';
}

if (!function_exists('webPathOf')) {
    /* Turns a file on disk into a web path, so links work from any page that includes this one */
    function webPathOf(string $fsPath): string
    {
        $real = realpath($fsPath);
        $doc  = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if (!$real || !$doc) { return ''; }
        $real = str_replace('\\', '/', $real);
        $doc  = rtrim(str_replace('\\', '/', $doc), '/');
        return stripos($real, $doc) === 0 ? substr($real, strlen($doc)) : '';
    }
}

/* notify.js sits in the project root (SEMPROJECT/notify.js) */
$notifyJs = webPathOf(__DIR__ . '/../notify.js') ?: 'notify.js';

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* Link to one of the pages. Keeps the rest of the address (like ?page=stocks). */
function pageUrl(string $tab): string
{
    $keep = $_GET;
    foreach (['tab', 'q', 'inv', 'type', 'from', 'to'] as $k) {
        unset($keep[$k]);
    }
    return '?' . http_build_query(array_merge($keep, ['tab' => $tab]));
}

$jsConfig = [
    'employee'    => $employeeName,
    'today'       => $today,
    'inventories' => $inventories,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stocking Record</title>
    <link rel="stylesheet" href="staff/assets/sr.css">
</head>
<body class="sr-body">

<div class="sr-page">

    <main class="sr-content">

        <!-- HERO BANNER (same style as the Dashboard) -->
        <header class="sr-hero">
            <div>
                <p class="sr-hero-eyebrow">Stock Monitoring</p>
                <h1 class="sr-hero-title">Stocking Record</h1>
                <p class="sr-hero-text">Record stock in and stock out, check what is left, and review past records in one place.</p>
            </div>
            <div class="sr-hero-date"><?= e(date('l, F j, Y')) ?></div>
        </header>

        <div class="sr-alert sr-alert-success" id="sr-flash" role="status" hidden></div>

        <div class="sr-alert sr-alert-error" id="sr-errors" role="alert" hidden>
            <strong>Please fix the following:</strong>
            <ul id="sr-error-list"></ul>
        </div>

        <?php
        $tabItems = [
            'add'     => ['Record stock',  '<svg class="sr-tab-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9zM9 12h6M9 16h4"/></svg>'],
            'stock'   => ['Current stock', '<svg class="sr-tab-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3zM4 7.5l8 4.5 8-4.5M12 12v9"/></svg>'],
            'records' => ['Records',       '<svg class="sr-tab-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg>'],
        ];
        ?>
        <nav class="sr-tabs" aria-label="Stocking sections">
            <?php foreach ($tabItems as $k => [$label, $icon]): ?>
                <a class="sr-tab<?= $tab === $k ? ' is-active' : '' ?>" href="<?= e(pageUrl($k)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>>
                    <?= $icon ?><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <section class="sr-panel">

<?php if ($tab === 'add'): ?>
            <!-- ADD RECORD FORM -->
            <h2 class="sr-section-title">Record stock movement</h2>

            <form class="sr-record-form" id="record-form" action="#">

                <div class="sr-field sr-field-type">
                    <span class="sr-label-text" id="type-label">Type</span>
                    <div class="sr-type-row">
                        <div class="sr-type-toggle" role="radiogroup" aria-labelledby="type-label">
                            <input type="radio" class="sr-type-radio sr-type-in" id="type_in" name="movement_type" value="IN" checked>
                            <label for="type_in" class="sr-type-label sr-type-label-in">Stock in</label>

                            <input type="radio" class="sr-type-radio sr-type-out" id="type_out" name="movement_type" value="OUT">
                            <label for="type_out" class="sr-type-label sr-type-label-out">Stock out</label>
                        </div>

                        <button type="button" class="sr-btn sr-btn-light sr-btn-scan" id="scan-open" aria-haspopup="dialog">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 8v8M11 8v8M15 8v8M18 8v8"/></svg>
                            Scan barcode
                        </button>
                    </div>
                </div>

                <div class="sr-field">
                    <label for="inventory">Inventory</label>
                    <select id="inventory" name="inventory" required>
                        <option value="">Choose inventory</option>
                        <?php foreach ($inventories as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sr-field">
                    <label for="item_name">Item name</label>
                    <select id="item_name" name="item_name" required disabled>
                        <option value="">Choose an inventory first</option>
                    </select>
                    <p class="sr-item-info" id="item-info" aria-live="polite"></p>
                </div>

                <div class="sr-field">
                    <label for="quantity">Quantity</label>
                    <input type="number" id="quantity" name="quantity" min="1" step="1" inputmode="numeric" required>
                </div>

                <div class="sr-field">
                    <label for="unit">Unit</label>
                    <select id="unit" name="unit" required>
                        <option value="">Choose unit</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= e($u) ?>"><?= e($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sr-field">
                    <label for="date_received">Date</label>
                    <input type="date" id="date_received" name="date_received" max="<?= e($today) ?>" required
                           value="<?= e($today) ?>">
                </div>

                <div class="sr-field sr-field-supplier">
                    <label for="supplier">Supplier / reason <span class="sr-optional">(optional)</span></label>
                    <input type="text" id="supplier" name="supplier" maxlength="100"
                           placeholder="Supplier for stock in, reason for stock out">
                </div>

                <div class="sr-field sr-field-remarks">
                    <label for="remarks">Remarks <span class="sr-optional">(optional)</span></label>
                    <input type="text" id="remarks" name="remarks" maxlength="255">
                </div>

                <div class="sr-form-actions">
                    <button type="submit" class="sr-btn">Save record</button>
                </div>
            </form>

            <!-- BARCODE SCAN WINDOW -->
            <dialog class="sr-modal" id="scan-modal" aria-labelledby="scan-title">
                <div class="sr-modal-head">
                    <div class="sr-modal-heading">
                        <span class="sr-modal-eyebrow">Quick recording</span>
                        <h2 id="scan-title">Scan barcode</h2>
                    </div>
                    <button type="button" class="sr-modal-x" id="scan-x" aria-label="Close">&times;</button>
                </div>

                <div class="sr-modal-body">
                    <p class="sr-modal-note">
                        Every scan records one stock movement for the item with that barcode.
                        Use the camera, or click the box below and scan with a barcode scanner (or type the code and press Enter).
                    </p>

                    <div class="sr-modal-controls">
                        <div class="sr-field">
                            <span class="sr-label-text" id="scan-type-label">Type</span>
                            <div class="sr-type-toggle" role="radiogroup" aria-labelledby="scan-type-label">
                                <input type="radio" class="sr-type-radio" id="scan_type_in" name="scan_type" value="IN" checked>
                                <label for="scan_type_in" class="sr-type-label sr-type-label-in">Stock in</label>

                                <input type="radio" class="sr-type-radio" id="scan_type_out" name="scan_type" value="OUT">
                                <label for="scan_type_out" class="sr-type-label sr-type-label-out">Stock out</label>
                            </div>
                        </div>

                        <div class="sr-field sr-field-qty">
                            <label for="scan-qty">Quantity</label>
                            <input type="number" id="scan-qty" min="1" step="1" inputmode="numeric" value="1">
                        </div>

                        <div class="sr-field sr-field-unit">
                            <label for="scan-unit">Unit</label>
                            <select id="scan-unit">
                                <option value="">Use the item's unit</option>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= e($u) ?>"><?= e($u) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="sr-camera">
                        <div class="sr-camera-actions">
                            <button type="button" class="sr-btn sr-btn-light" id="scan-cam-start">Start camera</button>
                            <button type="button" class="sr-btn sr-btn-light" id="scan-cam-stop" hidden>Stop camera</button>
                        </div>
                        <video class="sr-camera-video" id="scan-video" playsinline muted hidden></video>
                        <p class="sr-modal-note" id="scan-cam-note" hidden></p>
                    </div>

                    <div class="sr-field">
                        <label for="scan-manual">Type or scan a barcode</label>
                        <div class="sr-scan-row">
                            <input type="text" id="scan-manual" autocomplete="off" placeholder="Click here, then scan or type the code">
                            <button type="button" class="sr-btn sr-scan-btn" id="scan-add">Record</button>
                        </div>
                    </div>

                    <div class="sr-scan-last" id="scan-last" hidden></div>

                    <ul class="sr-scan-log" id="scan-log" aria-live="polite">
                        <li class="sr-scan-empty">No scans yet.</li>
                    </ul>
                </div>

                <div class="sr-modal-foot">
                    <button type="button" class="sr-btn" id="scan-done">Done</button>
                </div>
            </dialog>

<?php elseif ($tab === 'stock'): ?>
            <!-- CURRENT STOCK: every item from Inventory Management is listed here -->
            <h2 class="sr-section-title">Current stock</h2>

            <div class="sr-table-wrap sr-stock-wrap">
                <table class="sr-record-table">
                    <thead>
                        <tr>
                            <th>Inventory</th>
                            <th>Item</th>
                            <th>Date added</th>
                            <th>Total in</th>
                            <th>Total out</th>
                            <th>Balance</th>
                            <th>Unit</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="stock-body"></tbody>
                </table>
            </div>

<?php else: ?>
            <!-- SEARCH + FILTER -->
            <h2 class="sr-section-title">Records</h2>

            <form class="sr-filter-bar" id="filter-form" action="#">
                <div class="sr-field sr-field-search">
                    <label for="q">Search</label>
                    <input type="search" id="q" name="q" placeholder="Item or supplier">
                </div>
                <div class="sr-field">
                    <label for="inv">Inventory</label>
                    <select id="inv" name="inv">
                        <option value="">All</option>
                        <?php foreach ($inventories as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sr-field">
                    <label for="type">Type</label>
                    <select id="type" name="type">
                        <option value="">All</option>
                        <option value="IN">Stock in</option>
                        <option value="OUT">Stock out</option>
                    </select>
                </div>
                <div class="sr-field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from">
                </div>
                <div class="sr-field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to">
                </div>
                <div class="sr-filter-actions">
                    <button type="button" class="sr-btn sr-btn-light" id="clear-filters" hidden>Clear</button>
                </div>
            </form>

            <!-- RECORDS TABLE -->
            <div class="sr-table-wrap">
                <table class="sr-record-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Inventory</th>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Supplier / reason</th>
                            <th>Recorded by</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody id="records-body"></tbody>
                </table>
            </div>

<?php endif; ?>

        </section>
    </main>

</div>

<script type="application/json" id="sr-config"><?= json_encode($jsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- NOTIFICATION HELPER (browser storage, no database) -->
<script src="<?= e($notifyJs) ?>"></script>

<script>
(function () {
    var cfg = JSON.parse(document.getElementById('sr-config').textContent);

    /* Everything is saved in this browser, no database needed.
       "inventoryItems"     = items from Inventory Management (read only here)
       "stockingRecords"    = the stock records saved on this page
       "adminNotifications" = notifications for the admin (written through notify.js) */
    var ITEMS_KEY   = 'inventoryItems';
    var RECORDS_KEY = 'stockingRecords';
    var TYPE_TO_INV = { 'Ingredients': 'ingredients', 'Products': 'cookies', 'Supplies': 'supplies' };
    var invKeys     = Object.keys(cfg.inventories);

    /* ---------- Storage ---------- */
    function readList(key) {
        try {
            var saved = JSON.parse(localStorage.getItem(key));
            return Array.isArray(saved) ? saved : [];
        } catch (e) {
            return [];
        }
    }

    function saveRecords(list) {
        try {
            localStorage.setItem(RECORDS_KEY, JSON.stringify(list));
            return true;
        } catch (e) {
            return false;
        }
    }

    var records = readList(RECORDS_KEY);

    /* ---------- Items per inventory, from Inventory Management ---------- */
    function addedFromId(id) {
        var d = new Date(Number(id));
        if (isNaN(d.getTime()) || d.getFullYear() < 2000) { return ''; }
        return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
    }

    /* The barcode of an item is read from the saved item in Inventory Management.
       It looks for the field "barcode" (or barCode, barcode_number, code, sku). */
    function barcodeOf(li) {
        return String(li.barcode || li.barCode || li.barcode_number || li.code || li.sku || '').trim();
    }

    function loadInventoryItems() {
        var out = {};
        invKeys.forEach(function (k) { out[k] = []; });

        readList(ITEMS_KEY).forEach(function (li) {
            var key  = TYPE_TO_INV[li.type];
            var name = String(li.name || '').trim();
            if (!key || !out[key] || name === '') { return; }

            var found = null;
            out[key].forEach(function (it) { if (it.name === name) { found = it; } });

            if (found) {
                if (!found.barcode) { found.barcode = barcodeOf(li); }
                return;
            }

            out[key].push({
                name: name,
                unit: li.unit ? String(li.unit).trim() : '',
                added: addedFromId(li.id),
                barcode: barcodeOf(li)
            });
        });

        invKeys.forEach(function (k) {
            out[k].sort(function (a, b) { return a.name.localeCompare(b.name); });
        });
        return out;
    }

    var items = loadInventoryItems();

    /* ---------- Helpers ---------- */
    function fmtQty(n) {
        return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    function fmtDate(s) {
        var p = String(s).split('-');
        if (p.length !== 3) { return s; }
        var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
        return isNaN(d.getTime()) ? s : d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
    }

    function balanceOf(key, name, unit) {
        var total = 0;
        records.forEach(function (r) {
            if (r.inventory === key && r.item_name === name && (!unit || r.unit === unit)) {
                total += (r.movement_type === 'IN' ? 1 : -1) * Number(r.quantity);
            }
        });
        return Math.round(total * 100) / 100;
    }

    /* message when a stock out is bigger than what is available (empty text = fine) */
    function stockError(type, key, name, q, unit) {
        if (type !== 'OUT') { return ''; }
        var available = balanceOf(key, name, unit);
        if (available >= Number(q)) { return ''; }
        return available > 0
            ? 'Not enough stock. Only ' + fmtQty(available) + ' ' + unit + ' of "' + name + '" available.'
            : 'No stock available for "' + name + '" in ' + unit + '.';
    }

    function newId() {
        var id = Date.now();
        while (records.some(function (r) { return r.id === id; })) { id++; }
        return id;
    }

    /* saves one record and tells the admin; returns the new balance, or null if it could not be saved */
    function commitRecord(record) {
        records.push(record);

        if (!saveRecords(records)) {
            records.pop();
            return null;
        }

        var bal = balanceOf(record.inventory, record.item_name, record.unit);

        /* Send a notification to the admin (saved in browser storage) */
        if (window.Notify) {
            try {
                Notify.stockMoved(record, bal, cfg.inventories[record.inventory] || record.inventory);
            } catch (e) {
                console.error('Could not create notification.', e);
            }
        }
        return bal;
    }

    function cell(tr, text, cls) {
        var td = document.createElement('td');
        if (cls) { td.className = cls; }
        td.textContent = text;
        tr.appendChild(td);
        return td;
    }

    function emptyRow(body, cols, text) {
        var tr = document.createElement('tr');
        tr.className = 'sr-empty-row';
        var td = document.createElement('td');
        td.colSpan = cols;
        td.textContent = text;
        tr.appendChild(td);
        body.appendChild(tr);
    }

    var flash     = document.getElementById('sr-flash');
    var errBox    = document.getElementById('sr-errors');
    var errList   = document.getElementById('sr-error-list');

    function showErrors(list) {
        errList.innerHTML = '';
        list.forEach(function (m) {
            var li = document.createElement('li');
            li.textContent = m;
            errList.appendChild(li);
        });
        errBox.hidden = !list.length;
        if (list.length) { flash.hidden = true; }
    }

    function showFlash(text) {
        flash.textContent = text;
        flash.hidden = false;
        errBox.hidden = true;
    }

    /* ================= CURRENT STOCK TAB ================= */
    var stockBody = document.getElementById('stock-body');

    function renderStock() {
        var rows = [];
        var used = {};

        invKeys.forEach(function (key) {
            items[key].forEach(function (it) {
                var perUnit = {};
                records.forEach(function (r) {
                    if (r.inventory === key && r.item_name === it.name) {
                        var p = perUnit[r.unit] || (perUnit[r.unit] = { tin: 0, tout: 0 });
                        if (r.movement_type === 'IN') { p.tin += Number(r.quantity); } else { p.tout += Number(r.quantity); }
                    }
                });
                used[key + '|' + it.name] = true;

                var units = Object.keys(perUnit);
                if (!units.length) {
                    rows.push({ key: key, name: it.name, added: it.added, tin: 0, tout: 0, unit: it.unit });
                } else {
                    units.forEach(function (u) {
                        rows.push({ key: key, name: it.name, added: it.added, tin: perUnit[u].tin, tout: perUnit[u].tout, unit: u });
                    });
                }
            });
        });

        /* records of items that are no longer in Inventory Management */
        var extra = {};
        records.forEach(function (r) {
            if (used[r.inventory + '|' + r.item_name]) { return; }
            var id = r.inventory + '|' + r.item_name + '|' + r.unit;
            var p = extra[id] || (extra[id] = { key: r.inventory, name: r.item_name, added: '', tin: 0, tout: 0, unit: r.unit });
            if (r.movement_type === 'IN') { p.tin += Number(r.quantity); } else { p.tout += Number(r.quantity); }
        });
        Object.keys(extra).forEach(function (id) { rows.push(extra[id]); });

        rows.sort(function (a, b) {
            var d = invKeys.indexOf(a.key) - invKeys.indexOf(b.key);
            if (d !== 0) { return d; }
            d = a.name.localeCompare(b.name);
            return d !== 0 ? d : String(a.unit).localeCompare(String(b.unit));
        });

        stockBody.innerHTML = '';

        if (!rows.length) {
            emptyRow(stockBody, 8, 'No items yet. Add a product in Inventory Management and it will show up here.');
            return;
        }

        rows.forEach(function (r) {
            var balance = Math.round((r.tin - r.tout) * 100) / 100;
            var tr = document.createElement('tr');

            cell(tr, cfg.inventories[r.key] || '-');
            cell(tr, r.name, 'sr-item-cell');
            cell(tr, r.added || '-');
            cell(tr, fmtQty(r.tin));
            cell(tr, fmtQty(r.tout));

            var tdBal = document.createElement('td');
            var strong = document.createElement('strong');
            strong.textContent = fmtQty(balance);
            tdBal.appendChild(strong);
            tr.appendChild(tdBal);

            cell(tr, r.unit || '-');

            var tdSt = document.createElement('td');
            var pill = document.createElement('span');
            pill.className = 'sr-status ' + (balance <= 0 ? 'sr-status-out' : 'sr-status-ok');
            pill.textContent = balance <= 0 ? 'Out of stock' : 'In stock';
            tdSt.appendChild(pill);
            tr.appendChild(tdSt);

            stockBody.appendChild(tr);
        });
    }

    if (stockBody) { renderStock(); }

    /* ================= RECORDS TAB ================= */
    var recordsBody = document.getElementById('records-body');

    function renderRecords() {
        var q    = document.getElementById('q').value.trim().toLowerCase();
        var inv  = document.getElementById('inv').value;
        var type = document.getElementById('type').value;
        var from = document.getElementById('from').value;
        var to   = document.getElementById('to').value;
        var filtered = !!(q || inv || type || from || to);

        document.getElementById('clear-filters').hidden = !filtered;

        var list = records.filter(function (r) {
            if (q && String(r.item_name).toLowerCase().indexOf(q) < 0 &&
                     String(r.supplier || '').toLowerCase().indexOf(q) < 0) { return false; }
            if (inv && r.inventory !== inv) { return false; }
            if (type && r.movement_type !== type) { return false; }
            if (from && r.date_received < from) { return false; }
            if (to && r.date_received > to) { return false; }
            return true;
        });

        list.sort(function (a, b) {
            if (a.date_received !== b.date_received) { return a.date_received < b.date_received ? 1 : -1; }
            return b.id - a.id;
        });
        list = list.slice(0, 200);

        recordsBody.innerHTML = '';

        if (!list.length) {
            emptyRow(recordsBody, 9, filtered ? 'No records match your search.' : 'No stock records yet. Use the Record stock tab to add one.');
            return;
        }

        list.forEach(function (r) {
            var isIn = r.movement_type === 'IN';
            var tr = document.createElement('tr');

            cell(tr, fmtDate(r.date_received));

            var tdType = document.createElement('td');
            var pill = document.createElement('span');
            pill.className = 'sr-type-pill ' + (isIn ? 'sr-type-pill-in' : 'sr-type-pill-out');
            pill.textContent = isIn ? 'Stock in' : 'Stock out';
            tdType.appendChild(pill);
            tr.appendChild(tdType);

            cell(tr, cfg.inventories[r.inventory] || '-');
            cell(tr, r.item_name, 'sr-item-cell');

            var tdQty = document.createElement('td');
            var badge = document.createElement('span');
            badge.className = 'sr-qty-badge' + (isIn ? '' : ' sr-qty-badge-out');
            badge.textContent = (isIn ? '+' : '-') + fmtQty(r.quantity);
            tdQty.appendChild(badge);
            tr.appendChild(tdQty);

            cell(tr, r.unit);

            [r.supplier, null, r.remarks].forEach(function (v, i) {
                if (i === 1) { cell(tr, r.received_by || ''); return; }
                var td = document.createElement('td');
                if (v) {
                    td.textContent = v;
                } else {
                    var m = document.createElement('span');
                    m.className = 'sr-muted';
                    m.textContent = 'None';
                    td.appendChild(m);
                }
                tr.appendChild(td);
            });

            recordsBody.appendChild(tr);
        });
    }

    if (recordsBody) {
        var filterForm = document.getElementById('filter-form');
        filterForm.addEventListener('submit', function (ev) { ev.preventDefault(); });
        ['q', 'inv', 'type', 'from', 'to'].forEach(function (id) {
            var el = document.getElementById(id);
            el.addEventListener('input', renderRecords);
            el.addEventListener('change', renderRecords);
        });
        document.getElementById('clear-filters').addEventListener('click', function () {
            filterForm.reset();
            renderRecords();
        });
        renderRecords();
    }

    /* ================= RECORD STOCK TAB ================= */
    var form = document.getElementById('record-form');
    if (!form) { return; }

    var inv      = document.getElementById('inventory');
    var item     = document.getElementById('item_name');
    var unit     = document.getElementById('unit');
    var qty      = document.getElementById('quantity');
    var dateIn   = document.getElementById('date_received');
    var supplier = document.getElementById('supplier');
    var remarks  = document.getElementById('remarks');
    var itemInfo = document.getElementById('item-info');

    function currentItem() {
        var list = inv.value && items[inv.value] ? items[inv.value] : [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].name === item.value) { return list[i]; }
        }
        return null;
    }

    function setUnit(u) {
        for (var i = 0; i < unit.options.length; i++) {
            if (unit.options[i].value.toLowerCase() === u.toLowerCase()) {
                unit.value = unit.options[i].value;
                return;
            }
        }
        var o = document.createElement('option');
        o.value = u;
        o.textContent = u;
        unit.appendChild(o);
        unit.value = u;
    }

    /* Unit, current stock and date added show up automatically when an item is chosen */
    function updateItemInfo() {
        var it = currentItem();
        var parts = [];

        if (!it) {
            itemInfo.textContent = '';
            unit.classList.remove('sr-locked');
            unit.removeAttribute('tabindex');
            return;
        }

        if (it.unit) {
            setUnit(it.unit);
            unit.classList.add('sr-locked');
            unit.setAttribute('tabindex', '-1');
            parts.push('Unit: ' + it.unit);
        } else {
            unit.classList.remove('sr-locked');
            unit.removeAttribute('tabindex');
        }

        var u = it.unit || unit.value;
        parts.push('Current stock: ' + fmtQty(balanceOf(inv.value, it.name, u)) + (u ? ' ' + u : ''));

        if (it.added) { parts.push('Added to inventory: ' + it.added); }
        itemInfo.textContent = parts.join(' \u00b7 ');
    }

    function fillItems(key) {
        var list = key && items[key] ? items[key] : [];
        var first = document.createElement('option');
        first.value = '';

        item.innerHTML = '';
        if (!key || !items[key]) {
            first.textContent = 'Choose an inventory first';
            item.disabled = true;
        } else if (!list.length) {
            first.textContent = 'No items yet. Add one in Inventory Management.';
            item.disabled = true;
        } else {
            first.textContent = 'Choose item';
            item.disabled = false;
        }
        item.appendChild(first);

        list.forEach(function (it) {
            var o = document.createElement('option');
            o.value = it.name;
            o.textContent = it.name;
            item.appendChild(o);
        });
        updateItemInfo();
    }

    inv.addEventListener('change', function () { fillItems(inv.value); });
    item.addEventListener('change', updateItemInfo);
    unit.addEventListener('change', updateItemInfo);

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();

        var type = document.getElementById('type_out').checked ? 'OUT' : 'IN';
        var q    = qty.value.trim();
        var errs = [];

        if (!inv.value || !items[inv.value]) { errs.push('Choose an inventory (Ingredients, Supplies or Cookies).'); }
        if (!item.value || !currentItem()) { errs.push('Choose an item.'); }
        if (!/^\d{1,8}$/.test(q) || Number(q) < 1) { errs.push('Enter the quantity as a whole number, 1 or more.'); }
        if (!unit.value) { errs.push('Choose a unit.'); }
        if (supplier.value.length > 100) { errs.push('Supplier / reason must be 100 characters or fewer.'); }
        if (remarks.value.length > 255) { errs.push('Remarks must be 255 characters or fewer.'); }
        if (!dateIn.value || dateIn.value > cfg.today) { errs.push('Enter a valid date that is not in the future.'); }

        if (!errs.length) {
            var stockMsg = stockError(type, inv.value, item.value, q, unit.value);
            if (stockMsg) { errs.push(stockMsg); }
        }

        if (errs.length) {
            showErrors(errs);
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        var record = {
            id: newId(),
            movement_type: type,
            inventory: inv.value,
            item_name: item.value,
            quantity: Number(q),
            unit: unit.value,
            supplier: supplier.value.trim(),
            date_received: dateIn.value,
            received_by: cfg.employee,
            remarks: remarks.value.trim()
        };

        var bal = commitRecord(record);
        if (bal === null) {
            showErrors(['The record could not be saved. The browser storage is full or blocked.']);
            return;
        }

        showFlash((type === 'IN' ? 'Stock in recorded. ' : 'Stock out recorded. ') +
                  'Current stock of ' + record.item_name + ': ' + fmtQty(bal) + ' ' + record.unit + '.');

        /* keep the same type and inventory so several records are quick to add */
        var keepType = type, keyInv = inv.value;
        form.reset();
        document.getElementById(keepType === 'OUT' ? 'type_out' : 'type_in').checked = true;
        dateIn.value = cfg.today;
        inv.value = keyInv;
        fillItems(keyInv);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    /* ================= BARCODE SCAN ================= */
    var scanModal   = document.getElementById('scan-modal');
    var scanOpen    = document.getElementById('scan-open');
    var scanQty     = document.getElementById('scan-qty');
    var scanUnit    = document.getElementById('scan-unit');
    var scanManual  = document.getElementById('scan-manual');
    var scanLog     = document.getElementById('scan-log');
    var scanLast    = document.getElementById('scan-last');
    var camStart    = document.getElementById('scan-cam-start');
    var camStop     = document.getElementById('scan-cam-stop');
    var camVideo    = document.getElementById('scan-video');
    var camNote     = document.getElementById('scan-cam-note');

    var camStream = null;
    var camTimer  = null;
    var detector  = null;
    var lastCode  = '';
    var lastTime  = 0;

    function findByBarcode(code) {
        var want = String(code).trim().toLowerCase();
        for (var i = 0; i < invKeys.length; i++) {
            var list = items[invKeys[i]];
            for (var j = 0; j < list.length; j++) {
                if (list[j].barcode && list[j].barcode.toLowerCase() === want) {
                    return { key: invKeys[i], item: list[j] };
                }
            }
        }
        return null;
    }

    function logScan(text, ok) {
        var empty = scanLog.querySelector('.sr-scan-empty');
        if (empty) { empty.remove(); }

        var li = document.createElement('li');
        li.className = ok ? 'sr-scan-ok' : 'sr-scan-bad';
        li.textContent = text;
        scanLog.insertBefore(li, scanLog.firstChild);

        while (scanLog.children.length > 30) { scanLog.removeChild(scanLog.lastChild); }
    }

    function showLast(name, line) {
        scanLast.innerHTML = '';
        var strong = document.createElement('strong');
        strong.textContent = name;
        var span = document.createElement('span');
        span.textContent = line;
        scanLast.appendChild(strong);
        scanLast.appendChild(span);
        scanLast.hidden = false;
    }

    /* one scanned code = one stock record */
    function handleCode(raw) {
        var code = String(raw || '').trim();
        if (!code) { return; }

        /* the camera sees the same barcode many times: ignore repeats for a moment */
        var now = Date.now();
        if (code === lastCode && now - lastTime < 2500) { return; }
        lastCode = code;
        lastTime = now;

        var found = findByBarcode(code);
        if (!found) {
            logScan('No item found for barcode ' + code + '. Save this barcode on the item in Inventory Management.', false);
            return;
        }

        var type = document.getElementById('scan_type_out').checked ? 'OUT' : 'IN';
        var q    = scanQty.value.trim();
        var u    = found.item.unit || scanUnit.value;

        if (!/^\d{1,8}$/.test(q) || Number(q) < 1) {
            logScan('Enter the quantity as a whole number, 1 or more.', false);
            return;
        }
        if (!u) {
            logScan(found.item.name + ' has no unit. Choose a unit in the scan window first.', false);
            return;
        }

        var stockMsg = stockError(type, found.key, found.item.name, q, u);
        if (stockMsg) {
            logScan(stockMsg, false);
            return;
        }

        var record = {
            id: newId(),
            movement_type: type,
            inventory: found.key,
            item_name: found.item.name,
            quantity: Number(q),
            unit: u,
            supplier: supplier.value.trim(),
            date_received: cfg.today,
            received_by: cfg.employee,
            remarks: 'Barcode ' + code
        };

        var bal = commitRecord(record);
        if (bal === null) {
            logScan('The record could not be saved. The browser storage is full or blocked.', false);
            return;
        }

        var label = cfg.inventories[found.key] || found.key;
        logScan((type === 'IN' ? 'Stock in' : 'Stock out') + ': ' + (type === 'IN' ? '+' : '-') + fmtQty(q) + ' ' + u +
                ' of ' + found.item.name + ' (' + label + '). Current stock: ' + fmtQty(bal) + ' ' + u + '.', true);
        showLast(found.item.name, 'Current stock: ' + fmtQty(bal) + ' ' + u);
        updateItemInfo();
    }

    /* ---------- camera ---------- */
    function stopCamera() {
        if (camTimer) { clearInterval(camTimer); camTimer = null; }
        if (camStream) {
            camStream.getTracks().forEach(function (t) { t.stop(); });
            camStream = null;
        }
        camVideo.srcObject = null;
        camVideo.hidden = true;
        camStop.hidden = true;
        camStart.hidden = false;
    }

    function camMessage(text) {
        camNote.textContent = text;
        camNote.hidden = !text;
    }

    function startCamera() {
        camMessage('');

        if (!('BarcodeDetector' in window) || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            camMessage('This browser cannot scan with the camera. Use a barcode scanner or type the code below, or open this page in Chrome or Edge.');
            return;
        }

        try {
            detector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code'] });
        } catch (e) {
            try { detector = new BarcodeDetector(); } catch (e2) { detector = null; }
        }
        if (!detector) {
            camMessage('Barcode detection is not available in this browser. Use a barcode scanner or type the code below.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (stream) {
            camStream = stream;
            camVideo.srcObject = stream;
            camVideo.hidden = false;
            camStart.hidden = true;
            camStop.hidden = false;
            camVideo.play();

            camTimer = setInterval(function () {
                if (camVideo.readyState < 2) { return; }
                detector.detect(camVideo).then(function (codes) {
                    if (codes && codes.length) { handleCode(codes[0].rawValue); }
                }).catch(function () {});
            }, 300);
        }).catch(function () {
            camMessage('The camera could not be opened. Allow camera access in the browser, or use a barcode scanner.');
        });
    }

    camStart.addEventListener('click', startCamera);
    camStop.addEventListener('click', stopCamera);

    /* ---------- typed / scanner-gun input ---------- */
    function submitManual() {
        var code = scanManual.value;
        scanManual.value = '';
        lastCode = '';          /* a typed code is always recorded, even if it repeats */
        handleCode(code);
        scanManual.focus();
    }

    scanManual.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            submitManual();
        }
    });
    document.getElementById('scan-add').addEventListener('click', submitManual);

    /* ---------- open / close ---------- */
    function openScan() {
        document.getElementById(document.getElementById('type_out').checked ? 'scan_type_out' : 'scan_type_in').checked = true;
        camMessage('');

        if (typeof scanModal.showModal === 'function') {
            scanModal.showModal();
        } else {
            scanModal.setAttribute('open', '');
        }
        scanManual.focus();
    }

    function closeScan() {
        stopCamera();
        if (typeof scanModal.close === 'function') {
            scanModal.close();
        } else {
            scanModal.removeAttribute('open');
        }
    }

    scanOpen.addEventListener('click', openScan);
    document.getElementById('scan-x').addEventListener('click', closeScan);
    document.getElementById('scan-done').addEventListener('click', closeScan);
    scanModal.addEventListener('close', stopCamera);

    /* click the dark area outside the window to close it */
    scanModal.addEventListener('click', function (ev) {
        if (ev.target === scanModal) { closeScan(); }
    });

    /* if the records or items were changed in another tab, reload them */
    window.addEventListener('storage', function (ev) {
        if (ev.key === RECORDS_KEY) { records = readList(RECORDS_KEY); updateItemInfo(); }
        if (ev.key === ITEMS_KEY) { items = loadInventoryItems(); fillItems(inv.value); }
    });
})();
</script>

</body>
</html>