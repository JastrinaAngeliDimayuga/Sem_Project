<?php
declare(strict_types=1);

/* ==========================================================
   transaction.php — Transaction View (connected to dashboard_e.php)
   Open:    transaction.php            (shows the latest sale)
            transaction.php?id=18      (shows one sale)
   Styles:  staff/assets/transaction.css

   Tab "Sale details" = stats, items purchased, transaction details.
   Tab "New sale"     = orders that are waiting (recorded in dashboard_e.php).
                        Pick Pending or Complete for each one; a Complete
                        order goes straight to History.
   Tab "History"      = every COMPLETED sale; click one to open it.

   Orders are read from this browser (localStorage key
   "salesTransactions"), the same list dashboard_e.php writes to.
   Every order the dashboard records waits under New sale (whatever
   status the dashboard gave it) until you choose Complete there or in
   Sale details; only then does it move to History.
   The date and time come from the order itself (set when it was ordered).
   ========================================================== */

function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function icon(string $name, int $size = 0): string
{
    $paths = [
        'bag'      => '<path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'receipt'  => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
        'cookie'   => '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="10" r="1" fill="currentColor"/><circle cx="14" cy="9" r="1" fill="currentColor"/><circle cx="14" cy="14" r="1" fill="currentColor"/><circle cx="9.5" cy="14.5" r="1" fill="currentColor"/>',
        'trend'    => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'printer'  => '<path d="M7 9V3h10v6"/><rect x="4" y="9" width="16" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'download' => '<path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M5 20h14"/>',
        'left'     => '<path d="M15 6l-6 6 6 6"/>',
        'right'    => '<path d="M9 6l6 6-6 6"/>',
        'list'     => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
        'check'    => '<path d="M5 12l5 5 9-10"/>',
    ];
    $default = in_array($name, ['bag', 'receipt', 'cookie', 'trend'], true) ? 26 : 20;
    $size = $size > 0 ? $size : $default;

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title id="page-title">Transaction View — B4jkery</title>
    <link rel="stylesheet" href="staff/assets/transaction.css">
</head>
<body>

<div class="tv-page">

    <div class="tv-main">

        <main class="tv-content">

            <!-- ===== Hero ===== -->
            <section class="tv-hero">
                <span class="tv-date" id="today-pill"></span>
                <p class="tv-eyebrow">Sales monitoring</p>
                <h1>Transaction View</h1>
                <p>Choose pending or complete for each new order. Complete orders go straight to history.</p>
            </section>

            <div id="sale-view">

                <!-- ===== Tabs ===== -->
                <div class="tv-tabs" role="tablist" aria-label="Transaction sections">
                    <button type="button" class="tv-tab is-active" id="tab-details" role="tab"
                            aria-selected="true" aria-controls="panel-details"><?= icon('receipt', 18) ?> Sale details</button>
                    <button type="button" class="tv-tab" id="tab-new" role="tab"
                            aria-selected="false" aria-controls="panel-new"><?= icon('bag', 18) ?> New sale</button>
                    <button type="button" class="tv-tab" id="tab-history" role="tab"
                            aria-selected="false" aria-controls="panel-history"><?= icon('list', 18) ?> History</button>
                </div>

                <!-- ===== Tab 1: sale details ===== -->
                <div id="panel-details" role="tabpanel" aria-labelledby="tab-details">

                    <button type="button" class="breadcrumb" id="back-btn">
                        <?= icon('left', 16) ?> <span id="back-text">Back to transaction history</span>
                    </button>

                    <section class="stats" aria-label="Sale summary">
                        <article class="stat">
                            <div class="icon-box"><?= icon('bag') ?></div>
                            <div><strong id="stat-total">₱0</strong><span>Total paid</span></div>
                        </article>
                        <article class="stat">
                            <div class="icon-box"><?= icon('cookie') ?></div>
                            <div><strong id="stat-cookies">0</strong><span>Cookies bought</span></div>
                        </article>
                        <article class="stat">
                            <div class="icon-box"><?= icon('receipt') ?></div>
                            <div><strong id="stat-flavors">0</strong><span>Flavors</span></div>
                        </article>
                        <article class="stat">
                            <div class="icon-box"><?= icon('trend') ?></div>
                            <div><strong id="stat-average">₱0.00</strong><span>Average per cookie</span></div>
                        </article>
                    </section>

                    <div class="view-grid">

                        <!-- Items purchased -->
                        <section class="card" aria-labelledby="items-title">
                            <div class="card-head">
                                <h2 id="items-title">Items purchased</h2>
                                <span class="pill" id="items-pill">0 cookies</span>
                            </div>

                            <div class="table-wrap">
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Item</th>
                                            <th scope="col" class="num">Price</th>
                                            <th scope="col" class="num">Qty</th>
                                            <th scope="col" class="num">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody id="items-body"></tbody>
                                    <tfoot>
                                        <tr>
                                            <td>Total</td>
                                            <td></td>
                                            <td class="num" id="foot-qty">0</td>
                                            <td class="num" id="foot-amount">₱0</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div class="pager">
                                <span id="pager-text">Sale 0 of 0</span>
                                <div class="pager-links">
                                    <a class="btn ghost small is-disabled" id="prev-link" href="#"
                                       aria-disabled="true" tabindex="-1"><?= icon('left', 16) ?> Previous sale</a>
                                    <a class="btn ghost small is-disabled" id="next-link" href="#"
                                       aria-disabled="true" tabindex="-1">Next sale <?= icon('right', 16) ?></a>
                                </div>
                            </div>
                        </section>

                        <!-- Transaction details -->
                        <aside class="card" aria-labelledby="details-title">
                            <div class="card-head">
                                <h2 id="details-title">Transaction details</h2>
                                <span class="pill" id="status-pill">No sale yet</span>
                            </div>

                            <div class="tx-id">
                                <div class="icon-box"><?= icon('receipt') ?></div>
                                <div>
                                    <strong id="tx-code">#0000</strong>
                                    <span id="tx-when"></span>
                                </div>
                            </div>

                            <dl class="meta">
                                <div><dt>Customer</dt><dd id="m-customer"></dd></div>
                                <div><dt>Payment method</dt><dd id="m-payment"></dd></div>
                                <div><dt>Processed by</dt><dd id="m-employee"></dd></div>
                                <div><dt>Total items</dt><dd id="m-items"></dd></div>
                            </dl>

                            <div class="totals">
                                <div class="row"><span>Subtotal</span><span id="t-subtotal">₱0</span></div>
                                <div class="row"><span>Discount</span><span id="t-discount">₱0</span></div>
                                <div class="grand"><span>Total paid</span><b id="t-total">₱0</b></div>
                            </div>

                            <div class="actions">
                                <button type="button" class="btn wide" id="complete-btn" hidden>
                                    <?= icon('check') ?> Mark as complete
                                </button>
                                <button type="button" class="btn ghost" onclick="window.print()">
                                    <?= icon('printer') ?> Print receipt
                                </button>
                                <button type="button" class="btn" id="download-btn">
                                    <?= icon('download') ?> Download
                                </button>
                            </div>
                        </aside>

                    </div>
                </div>

                <!-- ===== Tab 2: new sales (choose pending or complete) ===== -->
                <div id="panel-new" role="tabpanel" aria-labelledby="tab-new" hidden>
                    <section class="card" aria-labelledby="pending-title">
                        <div class="card-head">
                            <h2 id="pending-title">New sales</h2>
                            <span class="pill pending" id="pending-pill">0 waiting</span>
                        </div>

                        <div class="table-wrap">
                            <table class="items-table pending-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Order</th>
                                        <th scope="col">Customer</th>
                                        <th scope="col">Cookies ordered</th>
                                        <th scope="col" class="num">Total</th>
                                        <th scope="col">Date</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="pending-body"></tbody>
                            </table>
                            <div class="no-match" id="pending-empty" hidden>No new sales waiting.</div>
                        </div>
                        <p class="hint">Choose <b>Complete</b> to move an order to History. Orders left on <b>Pending</b> stay here.</p>
                    </section>
                </div>

                <!-- ===== Tab 3: history (completed sales) ===== -->
                <div id="panel-history" role="tabpanel" aria-labelledby="tab-history" hidden>
                    <section class="card orders-card" aria-labelledby="history-title">
                        <div class="card-head">
                            <h2 id="history-title">Transaction history</h2>
                            <span class="pill" id="orders-pill">0 orders</span>
                        </div>

                        <input type="search" class="search" id="order-search"
                               placeholder="Search by order, customer, cookie or payment"
                               aria-label="Search orders" autocomplete="off">

                        <div class="table-wrap">
                            <table class="items-table orders-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Order</th>
                                        <th scope="col">Customer</th>
                                        <th scope="col">Cookies ordered</th>
                                        <th scope="col" class="num">Total</th>
                                        <th scope="col">Payment</th>
                                        <th scope="col">Date</th>
                                    </tr>
                                </thead>
                                <tbody id="orders-body"></tbody>
                            </table>
                            <div class="no-match" id="no-match" hidden>No orders yet.</div>
                        </div>
                    </section>
                </div>

            </div>

        </main>
    </div>

</div>

<script>
(function () {
    var SALES_KEY = 'salesTransactions';   /* same key used by dashboard_e.php */
    var TABS = ['details', 'new', 'history'];

    var sales = [];        /* all sales (pending + completed), oldest first */
    var current = null;    /* id of the sale shown in "Sale details" */
    var query = '';        /* history search text */
    var backTab = 'history';

    function $(id) { return document.getElementById(id); }

    /* ---------- storage ---------- */
    function readSales() {
        try {
            var saved = JSON.parse(localStorage.getItem(SALES_KEY));
            return Array.isArray(saved) ? saved.filter(function (s) { return s && typeof s === 'object'; }) : [];
        } catch (e) {
            return [];
        }
    }

    function loadSales() {
        sales = readSales().sort(function (a, b) { return Number(a.id) - Number(b.id); });
    }

    function saveSales() {
        try { localStorage.setItem(SALES_KEY, JSON.stringify(sales)); return true; }
        catch (e) { return false; }
    }

    /* An order is DONE only after it was marked Complete on this page
       (reviewed / completedIso). Every other order, including orders the
       dashboard saved with status "Completed", is waiting under New sale. */
    function isPending(sale) {
        return !(sale.reviewed === true || sale.completedIso);
    }

    function isDone(sale) { return !isPending(sale); }

    /* ---------- formatting ---------- */
    function peso(n) {
        n = Number(n) || 0;
        return '₱' + n.toLocaleString('en-US', { maximumFractionDigits: 0 });
    }

    function code(id) {
        var s = String(id);
        while (s.length < 4) { s = '0' + s; }
        return '#' + s;
    }

    function pad2(n) { return n < 10 ? '0' + n : String(n); }

    function dateStr(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function todayStr() { return dateStr(new Date()); }

    function timeStr(d) {
        return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    function parseDate(sale) {
        var d = sale.iso ? new Date(sale.iso) : null;
        if (d && !isNaN(d.getTime())) { return d; }
        var p = String(sale.date || '').split('-');
        if (p.length === 3) {
            d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
            if (!isNaN(d.getTime())) { return d; }
        }
        return null;
    }

    function whenLong(sale) {
        var d = parseDate(sale);
        if (!d) { return (String(sale.date || '') + ' ' + String(sale.time || '')).trim(); }
        var day = d.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
        var time = sale.iso ? timeStr(d) : String(sale.time || '');
        return time ? day + ' · ' + time : day;
    }

    function whenShort(sale) {
        if (sale.date === todayStr()) { return ('Today ' + String(sale.time || '')).trim(); }
        var d = parseDate(sale);
        if (!d) { return (String(sale.date || '') + ' ' + String(sale.time || '')).trim(); }
        return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) +
            ' ' + String(sale.time || '');
    }

    function padRight(s, n) { s = String(s); while (s.length < n) { s += ' '; } return s; }
    function padLeft(s, n)  { s = String(s); while (s.length < n) { s = ' ' + s; } return s; }

    function setText(id, text) { $(id).textContent = text; }

    /* ---------- small DOM helpers ---------- */
    function td(content, cls) {
        var c = document.createElement('td');
        if (cls) { c.className = cls; }
        if (content instanceof Node) { c.appendChild(content); } else { c.textContent = content; }
        return c;
    }

    function cookieListNode(sale) {
        var list = document.createElement('div');
        saleLines(sale).forEach(function (l) {
            var item = document.createElement('div');
            item.textContent = l.name + ' × ' + l.qty;
            list.appendChild(item);
        });
        return list;
    }

    function saleLines(sale) {
        return Array.isArray(sale.lines) ? sale.lines : [];
    }

    function saleCookies(sale) {
        var n = 0;
        saleLines(sale).forEach(function (l) { n += Number(l.qty) || 0; });
        return n;
    }

    function saleSubtotal(sale) {
        var n = 0;
        saleLines(sale).forEach(function (l) { n += (Number(l.price) || 0) * (Number(l.qty) || 0); });
        return n;
    }

    function saleTotal(sale) {
        return Math.max(0, saleSubtotal(sale) - (Number(sale.discount) || 0));
    }

    function matches(sale) {
        if (!query) { return true; }
        var hay = [
            code(sale.id), sale.customer || 'Walk-in customer', sale.payment || '',
            saleLines(sale).map(function (l) { return l.name; }).join(' ')
        ].join(' ').toLowerCase();
        return hay.indexOf(query) !== -1;
    }

    /* ---------- tabs ---------- */
    function showTab(name) {
        TABS.forEach(function (t) {
            var on = t === name;
            $('panel-' + t).hidden = !on;
            $('tab-' + t).classList.toggle('is-active', on);
            $('tab-' + t).setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (name === 'history') {
            var active = document.querySelector('#orders-body tr.is-active');
            if (active && active.scrollIntoView) { active.scrollIntoView({ block: 'nearest' }); }
        }
    }

    TABS.forEach(function (t) {
        $('tab-' + t).addEventListener('click', function () { showTab(t); });
    });
    $('back-btn').addEventListener('click', function () { showTab(backTab); });

    /* ---------- previous / next sale buttons ---------- */
    function setPager(link, id) {
        if (id === null) {
            link.href = '#';
            link.classList.add('is-disabled');
            link.setAttribute('aria-disabled', 'true');
            link.tabIndex = -1;
            link.dataset.id = '';
        } else {
            link.href = 'transaction.php?id=' + encodeURIComponent(id);
            link.classList.remove('is-disabled');
            link.removeAttribute('aria-disabled');
            link.removeAttribute('tabindex');
            link.dataset.id = String(id);
        }
    }

    /* ---------- drawing: history (completed sales only) ---------- */
    function drawOrders() {
        var body = $('orders-body');
        body.textContent = '';
        var done = sales.filter(isDone);
        var shown = 0;

        /* newest order first */
        done.slice().reverse().forEach(function (s) {
            if (!matches(s)) { return; }
            shown++;

            var tr = document.createElement('tr');
            if (String(s.id) === String(current)) { tr.className = 'is-active'; tr.setAttribute('aria-selected', 'true'); }

            var link = document.createElement('a');
            link.href = 'transaction.php?id=' + encodeURIComponent(s.id);
            link.textContent = code(s.id);

            tr.appendChild(td(link));
            tr.appendChild(td(s.customer || 'Walk-in customer'));
            tr.appendChild(td(cookieListNode(s)));
            tr.appendChild(td(peso(saleTotal(s)), 'num'));
            tr.appendChild(td(s.payment || ''));
            tr.appendChild(td(whenShort(s)));

            tr.addEventListener('click', function (ev) {
                ev.preventDefault();
                select(s.id, true);
                showTab('details');
                window.scrollTo(0, 0);
            });
            body.appendChild(tr);
        });

        $('no-match').textContent = done.length ? 'No orders match your search.' : 'No orders yet.';
        $('no-match').hidden = shown !== 0;
        setText('orders-pill', done.length + (done.length === 1 ? ' order' : ' orders'));
    }

    /* ---------- drawing: pending sales ---------- */
    function drawPending() {
        var body = $('pending-body');
        body.textContent = '';
        var pending = sales.filter(isPending);

        pending.slice().reverse().forEach(function (s) {
            var tr = document.createElement('tr');
            if (String(s.id) === String(current)) { tr.className = 'is-active'; }

            var link = document.createElement('a');
            link.href = 'transaction.php?id=' + encodeURIComponent(s.id);
            link.textContent = code(s.id);
            link.style.fontWeight = '700';

            var choose = document.createElement('select');
            choose.className = 'status-select';
            choose.setAttribute('aria-label', 'Status of order ' + code(s.id));
            [['pending', 'Pending'], ['complete', 'Complete']].forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o[0];
                opt.textContent = o[1];
                choose.appendChild(opt);
            });
            choose.value = 'pending';
            choose.addEventListener('click', function (ev) { ev.stopPropagation(); });
            choose.addEventListener('change', function () {
                if (choose.value === 'complete') { completeSale(s.id); } else { setPending(s.id); }
            });

            tr.appendChild(td(link));
            tr.appendChild(td(s.customer || 'Walk-in customer'));
            tr.appendChild(td(cookieListNode(s)));
            tr.appendChild(td(peso(saleTotal(s)), 'num'));
            tr.appendChild(td(whenShort(s)));
            tr.appendChild(td(choose));

            tr.addEventListener('click', function (ev) {
                if (ev.target.closest && ev.target.closest('a')) { ev.preventDefault(); }
                select(s.id, true);
                showTab('details');
                window.scrollTo(0, 0);
            });
            body.appendChild(tr);
        });

        $('pending-empty').hidden = pending.length !== 0;
        setText('pending-pill', pending.length + ' waiting');
    }

    /* ---------- drawing: sale details ---------- */
    function drawItems(sale) {
        var body = $('items-body');
        body.textContent = '';
        var lines = saleLines(sale);
        var qty = 0;

        lines.forEach(function (l) {
            var q = Number(l.qty) || 0;
            var p = Number(l.price) || 0;
            qty += q;

            var tr = document.createElement('tr');
            tr.appendChild(td(String(l.name || 'Item'), 'item-name'));
            tr.appendChild(td(peso(p), 'num'));
            tr.appendChild(td(String(q), 'num'));
            tr.appendChild(td(peso(p * q), 'num'));
            body.appendChild(tr);
        });

        if (!lines.length) {
            var empty = document.createElement('tr');
            var cell = td('No items recorded for this sale.');
            cell.colSpan = 4;
            empty.appendChild(cell);
            body.appendChild(empty);
        }

        setText('foot-qty', String(qty));
        setText('foot-amount', peso(saleSubtotal(sale)));
        setText('items-pill', qty + (qty === 1 ? ' cookie' : ' cookies'));
    }

    function drawReceipt(sale, index) {
        var lines = saleLines(sale);
        var cookies = saleCookies(sale);
        var subtotal = saleSubtotal(sale);
        var discount = Number(sale.discount) || 0;
        var total = saleTotal(sale);
        var average = cookies > 0 ? total / cookies : 0;
        var pending = isPending(sale);

        document.title = 'Transaction ' + code(sale.id) + ' — B4jkery';

        /* summary cards */
        setText('stat-total', peso(total));
        setText('stat-cookies', String(cookies));
        setText('stat-flavors', String(lines.length));
        setText('stat-average', '₱' + average.toLocaleString('en-US', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        }));

        /* details */
        var status = pending ? 'Pending' : 'Completed';
        setText('status-pill', status);
        $('status-pill').classList.toggle('ok', String(status).toLowerCase() === 'completed');
        $('status-pill').classList.toggle('pending', pending);
        setText('tx-code', code(sale.id));
        setText('tx-when', whenLong(sale));
        setText('m-customer', sale.customer || 'Walk-in customer');
        setText('m-payment', sale.payment || '—');
        setText('m-employee', sale.employee || 'Employee');
        setText('m-items', cookies + (cookies === 1 ? ' cookie' : ' cookies'));

        /* totals */
        setText('t-subtotal', peso(subtotal));
        setText('t-discount', peso(discount));
        setText('t-total', peso(total));

        drawItems(sale);

        /* pending sales can be completed from here; back link goes to the right tab */
        $('complete-btn').hidden = !pending;
        backTab = pending ? 'new' : 'history';
        setText('back-text', pending ? 'Back to new sales' : 'Back to transaction history');

        /* previous / next sale */
        setText('pager-text', 'Sale ' + (index + 1) + ' of ' + sales.length);
        setPager($('prev-link'), index > 0 ? sales[index - 1].id : null);
        setPager($('next-link'), index < sales.length - 1 ? sales[index + 1].id : null);
    }

    /* same layout, empty values (used when no sale has been recorded yet) */
    function drawBlank() {
        current = null;
        document.title = 'Transaction View — B4jkery';

        setText('stat-total', '₱0');
        setText('stat-cookies', '0');
        setText('stat-flavors', '0');
        setText('stat-average', '₱0.00');

        setText('status-pill', 'No sale yet');
        $('status-pill').classList.remove('ok');
        $('status-pill').classList.remove('pending');
        setText('tx-code', '#0000');
        setText('tx-when', new Date().toLocaleDateString('en-US', {
            weekday: 'long', month: 'short', day: 'numeric', year: 'numeric'
        }));
        setText('m-customer', '—');
        setText('m-payment', '—');
        setText('m-employee', '—');
        setText('m-items', '0 cookies');

        setText('t-subtotal', '₱0');
        setText('t-discount', '₱0');
        setText('t-total', '₱0');

        drawItems({ lines: [] });

        $('complete-btn').hidden = true;
        backTab = 'history';
        setText('back-text', 'Back to transaction history');

        setText('pager-text', 'Sale 0 of 0');
        setPager($('prev-link'), null);
        setPager($('next-link'), null);
    }

    function indexOf(id) {
        for (var i = 0; i < sales.length; i++) {
            if (String(sales[i].id) === String(id)) { return i; }
        }
        return -1;
    }

    /* show one sale (push = also update the address bar) */
    function select(id, push) {
        var index = indexOf(id);
        if (index === -1) { index = sales.length - 1; }   /* unknown id: latest sale */

        var sale = sales[index];
        current = sale.id;

        if (push) {
            try { history.pushState({ id: sale.id }, '', 'transaction.php?id=' + encodeURIComponent(sale.id)); }
            catch (e) { }
        }

        drawOrders();
        drawPending();
        drawReceipt(sale, index);
    }

    function start(id) {
        loadSales();

        if (!sales.length) {
            drawOrders();
            drawPending();
            drawBlank();
            return;
        }
        select(id, false);
    }

    /* ---------- pending -> completed / cancelled ---------- */
    function completeSale(id) {
        loadSales();
        var i = indexOf(id);
        if (i === -1) { return; }
        sales[i].status = 'Completed';
        sales[i].reviewed = true;
        sales[i].completedIso = new Date().toISOString();
        saveSales();
        start(id);
        showTab('history');       /* a completed sale now lives in History */
        window.scrollTo(0, 0);
    }

    function setPending(id) {
        loadSales();
        var i = indexOf(id);
        if (i === -1) { return; }
        sales[i].status = 'Pending';
        sales[i].reviewed = false;
        delete sales[i].completedIso;
        saveSales();
        start(current);
    }

    $('complete-btn').addEventListener('click', function () {
        if (current !== null) { completeSale(current); }
    });

    /* ---------- today's date in the hero ---------- */
    function drawToday() {
        setText('today-pill', new Date().toLocaleDateString('en-US', {
            weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
        }));
    }

    /* ---------- search ---------- */
    $('order-search').addEventListener('input', function () {
        query = this.value.trim().toLowerCase();
        drawOrders();
    });

    /* ---------- previous / next buttons ---------- */
    ['prev-link', 'next-link'].forEach(function (name) {
        $(name).addEventListener('click', function (ev) {
            ev.preventDefault();
            var id = this.dataset.id;
            if (id) { select(id, true); }
        });
    });

    /* ---------- text receipt download ---------- */
    $('download-btn').addEventListener('click', function () {
        var index = indexOf(current);
        if (index === -1) { return; }
        var sale = sales[index];
        var lines = saleLines(sale);
        var subtotal = saleSubtotal(sale);
        var discount = Number(sale.discount) || 0;
        var total = saleTotal(sale);

        var out = [];
        var line = new Array(45).join('=');
        var dash = new Array(45).join('-');

        out.push('B4JKERY RECEIPT');
        out.push(line);
        out.push('Transaction : ' + code(sale.id));
        out.push('Status      : ' + (isPending(sale) ? 'Pending' : 'Completed'));
        out.push('Date        : ' + whenLong(sale));
        out.push('Customer    : ' + (sale.customer || 'Walk-in customer'));
        out.push('Payment     : ' + (sale.payment || ''));
        out.push('Processed by: ' + (sale.employee || 'Employee'));
        out.push(dash);
        lines.forEach(function (l) {
            var amount = (Number(l.price) || 0) * (Number(l.qty) || 0);
            out.push(
                padRight(l.name, 22) + ' ' +
                padLeft(l.qty, 3) + ' x ' + padLeft(peso(l.price), 5) + ' ' +
                padLeft(peso(amount), 8)
            );
        });
        out.push(dash);
        out.push(padRight('Subtotal', 34) + ' ' + padLeft(peso(subtotal), 9));
        out.push(padRight('Discount', 34) + ' ' + padLeft(peso(discount), 9));
        out.push(padRight('TOTAL PAID', 34) + ' ' + padLeft(peso(total), 9));

        var blob = new Blob([out.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'receipt-' + code(sale.id).replace('#', '') + '.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });

    /* ---------- browser back / forward and other tabs ---------- */
    function idFromUrl() {
        var v = new URLSearchParams(window.location.search).get('id');
        return v === null || v === '' ? null : v;
    }

    window.addEventListener('popstate', function () {
        if (sales.length) { select(idFromUrl(), false); showTab('details'); }
    });

    /* a sale was recorded or changed in another tab (e.g. the dashboard) */
    window.addEventListener('storage', function (ev) {
        if (ev.key === SALES_KEY) { start(current !== null ? current : idFromUrl()); }
    });

    /* coming back to this browser tab */
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { start(current !== null ? current : idFromUrl()); }
    });

    drawToday();
    start(idFromUrl());
})();
</script>

</body>
</html>