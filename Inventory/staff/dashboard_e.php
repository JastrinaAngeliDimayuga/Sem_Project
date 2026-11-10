<?php


$employeeName = $_SESSION['employee_name'] ?? 'Employee';

/* Inline SVG icons so the page works without any CDN */
function icon(string $name): string
{
    $paths = [
        'sales'        => '<path d="M5 8h14l-1.2 11H6.2L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'transactions' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h6"/>',
        'cookies'      => '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="9" r="1"/><circle cx="15" cy="10" r="1"/><circle cx="10" cy="15" r="1"/><circle cx="15" cy="15" r="1"/>',
        'low'          => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18h.01"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($paths[$name] ?? '') . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <link rel="stylesheet" href="staff/assets/dashboard_e.css?v=<?= @filemtime('staff/assets/dashboard_e.css') ?>">
</head>
<body>

<div class="page">

    <!-- HERO BANNER -->
    <header class="hero">
        <div class="hero-main">
            <p class="hero-eyebrow">Sales overview</p>
            <h1 class="hero-title">Dashboard</h1>
            <p class="hero-text">
                Record orders, follow today's sales and keep an eye on your cookie stock.
            </p>
        </div>
        <span class="hero-date"><?= date('D, j M Y') ?></span>
    </header>

    <!-- OVERVIEW CARDS -->
    <section class="stats" aria-label="Today's overview">
        <article class="stat-card">
            <div class="stat-text">
                <span class="stat-label">Today's Sales</span>
                <span class="stat-number" id="stat-sales">₱0</span>
                <span class="stat-note">Recorded today</span>
            </div>
            <div class="stat-icon stat-icon-sales"><?= icon('sales') ?></div>
        </article>

        <article class="stat-card">
            <div class="stat-text">
                <span class="stat-label">Transactions</span>
                <span class="stat-number" id="stat-tx">0</span>
                <span class="stat-note">Orders completed</span>
            </div>
            <div class="stat-icon stat-icon-tx"><?= icon('transactions') ?></div>
        </article>

        <article class="stat-card">
            <div class="stat-text">
                <span class="stat-label">Cookies Sold</span>
                <span class="stat-number" id="stat-cookies">0</span>
                <span class="stat-note">Pieces today</span>
            </div>
            <div class="stat-icon stat-icon-cookies"><?= icon('cookies') ?></div>
        </article>

        <article class="stat-card" id="stat-low-card">
            <div class="stat-text">
                <span class="stat-label">Low Stocks</span>
                <span class="stat-number" id="stat-low">0</span>
                <span class="stat-note">Need restocking</span>
            </div>
            <div class="stat-icon stat-icon-low"><?= icon('low') ?></div>
        </article>
    </section>

    <main class="panel">

        <div class="alert alert-success" id="msg-ok" role="status" hidden></div>
        <div class="alert alert-danger" id="msg-err" role="alert" hidden></div>

        <div class="sale-grid">

            <!-- NEW ORDER -->
            <div class="box">
                <h2 class="section-title">New order</h2>

                <form id="sale-form" autocomplete="off">
                    <div class="form-row">
                        <div class="field">
                            <label for="customer">Customer name</label>
                            <input type="text" id="customer" name="customer" placeholder="Enter customer name">
                        </div>
                        <div class="field">
                            <label for="payment">Payment method</label>
                            <select id="payment" name="payment">
                                <option>Cash</option>
                                <option>GCash</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row form-row-add">
                        <div class="field field-grow">
                            <label for="product">Cookie Product</label>
                            <select id="product" name="product"></select>
                        </div>
                        <div class="field field-qty">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity" min="1" step="1" value="1">
                        </div>
                        <button type="button" id="add-btn" class="btn btn-light">Add to order</button>
                    </div>

                    <div class="table-wrap">
                        <table class="stock-table">
                            <thead>
                                <tr>
                                    <th>Cookie Product</th>
                                    <th>Price</th>
                                    <th>Qty</th>
                                    <th>Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="order-body"></tbody>
                        </table>
                    </div>

                    <div class="total-row">
                        <div>
                            <span class="stat-label">Total amount</span>
                            <div class="total-amount" id="order-total">₱0</div>
                        </div>
                        <button type="submit" class="btn btn-dark">Record order</button>
                    </div>
                </form>
            </div>

            <!-- STOCK AFTER THIS ORDER -->
            <div class="box">
                <h2 class="section-title">Stock after this order</h2>

                <div class="table-wrap">
                    <table class="stock-table">
                        <thead>
                            <tr>
                                <th>Cookie Product</th>
                                <th>Before</th>
                                <th>After</th>
                            </tr>
                        </thead>
                        <tbody id="preview-body"></tbody>
                    </table>
                </div>
                <p class="note">Stocks update automatically when you record the order.</p>
            </div>
        </div>

        <!-- RECENT TRANSACTIONS -->
        <div class="box">
            <h2 class="section-title">Recent transactions</h2>

            <div class="table-wrap">
                <table class="stock-table">
                    <thead>
                        <tr>
                            <th>Transaction</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody id="tx-body"></tbody>
                </table>
            </div>
        </div>

    </main>

</div>

<script>
(function () {
    var EMPLOYEE    = <?= json_encode($employeeName, JSON_UNESCAPED_UNICODE) ?>;

    /* Browser storage keys (shared with Inventory Management, Stocking Record and transaction.php) */
    var ITEMS_KEY   = 'inventoryItems';
    var RECORDS_KEY = 'stockingRecords';
    var SALES_KEY   = 'salesTransactions';
    var ORDER_KEY   = 'currentOrder';
    var LOW_STOCK_LEVEL = 10;

    function $(id) { return document.getElementById(id); }

    /* ---------- storage ---------- */
    function readList(key) {
        try {
            var saved = JSON.parse(localStorage.getItem(key));
            return Array.isArray(saved) ? saved : [];
        } catch (e) {
            return [];
        }
    }

    function writeList(key, list) {
        try {
            localStorage.setItem(key, JSON.stringify(list));
            return true;
        } catch (e) {
            return false;
        }
    }

    /* ---------- formatting ---------- */
    function peso(n) {
        n = Number(n) || 0;
        var whole = Number.isInteger(n);
        return '\u20b1' + n.toLocaleString('en-US', {
            minimumFractionDigits: whole ? 0 : 2,
            maximumFractionDigits: 2
        });
    }

    function fmtQty(n) {
        return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    function pad2(n) { return n < 10 ? '0' + n : String(n); }

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function timeNow() {
        return new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    function fmtDate(s) {
        var p = String(s).split('-');
        if (p.length !== 3) { return s; }
        var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
        return isNaN(d.getTime()) ? s : d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
    }

    function code(id) {
        var s = String(id);
        while (s.length < 4) { s = '0' + s; }
        return '#' + s;
    }

    /* ---------- small DOM helpers ---------- */
    function el(tag, text, cls) {
        var n = document.createElement(tag);
        if (text !== undefined && text !== null) { n.textContent = text; }
        if (cls) { n.className = cls; }
        return n;
    }

    function cell(content) {
        var td = el('td');
        if (content instanceof Node) { td.appendChild(content); } else { td.textContent = content; }
        return td;
    }

    function emptyRow(cols, text) {
        var tr = el('tr', null, 'empty-row');
        var td = el('td', text);
        td.colSpan = cols;
        tr.appendChild(td);
        return tr;
    }

    /* ---------- order (kept for this browser tab) ---------- */
    var order = {};
    try { order = JSON.parse(sessionStorage.getItem(ORDER_KEY)) || {}; } catch (e) { order = {}; }

    function saveOrder() {
        try { sessionStorage.setItem(ORDER_KEY, JSON.stringify(order)); } catch (e) { }
    }

    /* ---------- cookie products = cookies added in Inventory Management ---------- */
    function products() {
        var records = readList(RECORDS_KEY);

        return readList(ITEMS_KEY)
            .filter(function (i) { return i.type === 'Products' && String(i.name || '').trim() !== ''; })
            .map(function (i) {
                var name = String(i.name).trim();
                var unit = i.unit ? String(i.unit).trim() : '';
                var balance = 0;

                records.forEach(function (r) {
                    if (r.inventory === 'cookies' && r.item_name === name && (!unit || r.unit === unit)) {
                        balance += (r.movement_type === 'IN' ? 1 : -1) * Number(r.quantity);
                    }
                });

                return {
                    id: String(i.id),
                    name: name,
                    price: Number(i.price) || 0,
                    unit: unit,
                    stock: Math.round(balance * 100) / 100
                };
            })
            .sort(function (a, b) { return a.name.localeCompare(b.name); });
    }

    function productMap() {
        var m = {};
        products().forEach(function (p) { m[p.id] = p; });
        return m;
    }

    /* unit used for the Stock out record of a cookie */
    function unitFor(p) {
        if (p.unit) { return p.unit; }
        var records = readList(RECORDS_KEY);
        for (var i = records.length - 1; i >= 0; i--) {
            if (records[i].inventory === 'cookies' && records[i].item_name === p.name && records[i].unit) {
                return records[i].unit;
            }
        }
        return 'Pieces';
    }

    function message(ok, text, link) {
        $('msg-ok').hidden = true;
        $('msg-err').hidden = true;
        var box = $(ok ? 'msg-ok' : 'msg-err');
        box.textContent = text;
        if (link) {
            box.appendChild(document.createTextNode(' '));
            var a = el('a', 'View receipt');
            a.href = link;
            box.appendChild(a);
        }
        box.hidden = false;
    }

    function clearMessages() {
        $('msg-ok').hidden = true;
        $('msg-err').hidden = true;
    }

    /* ---------- drawing ---------- */
    function drawProductSelect() {
        var sel = $('product');
        var keep = sel.value;
        var list = products();
        sel.textContent = '';

        if (!list.length) {
            var none = el('option', 'No cookie products yet');
            none.value = '';
            sel.appendChild(none);
            sel.disabled = true;
            $('add-btn').disabled = true;
            return;
        }
        sel.disabled = false;
        $('add-btn').disabled = false;

        list.forEach(function (p) {
            var o = el('option', p.name + ' - ' + peso(p.price) + ' (' + fmtQty(p.stock) + ' in stock)');
            o.value = p.id;
            sel.appendChild(o);
        });
        if (keep) { sel.value = keep; }
    }

    function orderTotal(pm) {
        var total = 0;
        Object.keys(order).forEach(function (id) {
            if (pm[id]) { total += pm[id].price * order[id]; }
        });
        return total;
    }

    function drawOrder() {
        var pm = productMap();
        var body = $('order-body');
        body.textContent = '';

        var ids = Object.keys(order).filter(function (id) { return pm[id]; });
        if (!ids.length) {
            body.appendChild(emptyRow(5, 'No items added yet.'));
        } else {
            ids.forEach(function (id) {
                var p = pm[id];
                var qty = order[id];
                var tr = el('tr');
                tr.appendChild(cell(p.name));
                tr.appendChild(cell(peso(p.price)));
                tr.appendChild(cell(String(qty)));
                tr.appendChild(cell(peso(p.price * qty)));

                var rm = el('button', '\u00d7', 'remove-btn');
                rm.type = 'button';
                rm.title = 'Remove item';
                rm.setAttribute('aria-label', 'Remove ' + p.name);
                rm.addEventListener('click', function () {
                    delete order[id];
                    saveOrder();
                    clearMessages();
                    drawAll();
                });
                tr.appendChild(cell(rm));
                body.appendChild(tr);
            });
        }
        $('order-total').textContent = peso(orderTotal(pm));
    }

    function drawPreview() {
        var body = $('preview-body');
        body.textContent = '';
        var list = products();
        if (!list.length) {
            body.appendChild(emptyRow(3, 'No cookie products yet.'));
            return;
        }
        list.forEach(function (p) {
            var after = p.stock - (order[p.id] || 0);
            var tr = el('tr');
            tr.appendChild(cell(p.name));
            tr.appendChild(cell(fmtQty(p.stock)));

            var td = el('td', fmtQty(after) + ' ');
            if (after <= LOW_STOCK_LEVEL) { td.appendChild(el('span', 'Low', 'status status-low')); }
            tr.appendChild(td);
            body.appendChild(tr);
        });
    }

    function drawStats() {
        var today = todayStr();
        var sales = 0, tx = 0, sold = 0;
        readList(SALES_KEY).forEach(function (s) {
            if (s.date !== today) { return; }
            sales += Number(s.total) || 0;
            tx++;
            sold += Number(s.items) || 0;
        });
        var low = products().filter(function (p) { return p.stock <= LOW_STOCK_LEVEL; }).length;

        $('stat-sales').textContent = peso(sales);
        $('stat-tx').textContent = String(tx);
        $('stat-cookies').textContent = String(sold);
        $('stat-low').textContent = String(low);
        $('stat-low-card').classList.toggle('stat-alert', low > 0);
    }

    function drawTransactions() {
        var body = $('tx-body');
        body.textContent = '';
        var today = todayStr();
        var list = readList(SALES_KEY).sort(function (a, b) { return b.id - a.id; }).slice(0, 20);

        if (!list.length) {
            body.appendChild(emptyRow(6, 'No transactions yet.'));
            return;
        }
        list.forEach(function (s) {
            var tr = el('tr');
            var link = el('a', code(s.id));
            link.href = 'transaction.php?id=' + encodeURIComponent(s.id);
            tr.appendChild(cell(link));
            tr.appendChild(cell(s.customer));
            tr.appendChild(cell(String(s.items)));
            tr.appendChild(cell(peso(s.total)));
            tr.appendChild(cell(el('span', s.payment, 'status status-pay')));
            tr.appendChild(cell(s.date === today ? s.time : fmtDate(s.date) + ' ' + s.time));
            body.appendChild(tr);
        });
    }

    function drawAll() {
        drawProductSelect();
        drawOrder();
        drawPreview();
        drawStats();
        drawTransactions();
    }

    /* ---------- actions ---------- */
    $('add-btn').addEventListener('click', function () {
        clearMessages();
        var pm = productMap();
        var id = $('product').value;
        var qty = Math.floor(Number($('quantity').value));

        if (!pm[id]) { message(false, 'Choose a cookie product first.'); return; }
        if (!isFinite(qty) || qty < 1) { message(false, 'Enter a quantity of 1 or more.'); return; }

        var wanted = (order[id] || 0) + qty;
        if (wanted > pm[id].stock) {
            message(false, 'Not enough stock. Only ' + fmtQty(pm[id].stock) + ' of "' + pm[id].name + '" available.');
            return;
        }
        order[id] = wanted;
        saveOrder();
        $('quantity').value = 1;
        drawAll();
    });

    $('sale-form').addEventListener('submit', function (ev) {
        ev.preventDefault();
        clearMessages();

        var pm = productMap();
        var ids = Object.keys(order).filter(function (id) { return pm[id]; });
        if (!ids.length) { message(false, 'Add items to the order first.'); return; }

        /* check the stock again, it may have changed on another page */
        for (var i = 0; i < ids.length; i++) {
            if (order[ids[i]] > pm[ids[i]].stock) {
                message(false, 'Not enough stock for "' + pm[ids[i]].name + '". Only ' +
                    fmtQty(pm[ids[i]].stock) + ' available.');
                drawAll();
                return;
            }
        }

        var customer = $('customer').value.trim() || 'Walk-in customer';
        var lines = ids.map(function (id) {
            return { itemId: id, name: pm[id].name, price: pm[id].price, qty: order[id] };
        });
        var items = 0;
        var total = 0;
        lines.forEach(function (l) { items += l.qty; total += l.price * l.qty; });

        /* next transaction number */
        var sales = readList(SALES_KEY);
        var nextId = sales.reduce(function (m, s) { return Math.max(m, Number(s.id) || 0); }, 0) + 1;

        var sale = {
            id: nextId,
            customer: customer,
            payment: $('payment').value,
            employee: EMPLOYEE,
            discount: 0,
            status: 'Completed',
            lines: lines,
            items: items,
            total: total,
            date: todayStr(),
            time: timeNow(),
            iso: new Date().toISOString()
        };

        /* one Stock out record per cookie sold: this lowers the stock everywhere */
        var records = readList(RECORDS_KEY);
        var stamp = Date.now();
        lines.forEach(function (l, n) {
            records.push({
                id: stamp + n,
                movement_type: 'OUT',
                inventory: 'cookies',
                item_name: l.name,
                quantity: l.qty,
                unit: unitFor(pm[l.itemId]),
                supplier: 'Sale ' + code(nextId),
                date_received: todayStr(),
                received_by: EMPLOYEE,
                remarks: customer
            });
        });

        var newSales = sales.concat([sale]);
        if (!writeList(SALES_KEY, newSales)) {
            message(false, 'The order could not be saved. The browser storage is full or blocked.');
            return;
        }
        if (!writeList(RECORDS_KEY, records)) {
            writeList(SALES_KEY, sales); /* undo, so stock and transactions stay matched */
            message(false, 'The stock could not be updated. The order was not saved.');
            return;
        }

        order = {};
        saveOrder();
        $('customer').value = '';
        $('payment').selectedIndex = 0;
        drawAll();
        message(true, 'Order ' + code(nextId) + ' recorded and stocks updated.',
            'transaction.php?id=' + encodeURIComponent(nextId));
    });

    /* things may have changed on another page or tab */
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { drawAll(); }
    });
    window.addEventListener('storage', function (ev) {
        if (ev.key === ITEMS_KEY || ev.key === RECORDS_KEY || ev.key === SALES_KEY) { drawAll(); }
    });

    drawAll();
})();
</script>

</body>
</html>