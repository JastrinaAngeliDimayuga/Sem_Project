<?php
declare(strict_types=1);

/* ==========================================================
   transaction.php — Transaction View (connected to dashboard_e.php)
   Open:    transaction.php            (shows the latest sale's receipt)
            transaction.php?id=18      (shows one sale's receipt)
   Styles:  staff/assets/transaction.css

   Left card  = "Cookie purchases": every customer order recorded
                in dashboard_e.php. Click an order to see its receipt.
   Right card = the receipt of the selected order.

   Orders are saved in this browser (localStorage key
   "salesTransactions"), the same list dashboard_e.php writes to.
   ========================================================== */

function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function icon(string $name): string
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
    ];
    $size = in_array($name, ['bag', 'receipt', 'cookie', 'trend'], true) ? 28 : 20;

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
    <title id="page-title">Transaction — Cookie Shop</title>
    <link rel="stylesheet" href="staff/assets/transaction.css">
    <style>
        /* small additions for the clickable order list */
        .orders-scroll { max-height: 380px; overflow: auto; }
        .orders-table tbody tr { cursor: pointer; }
        .orders-table tbody tr:hover { background: rgba(0, 0, 0, .04); }
        .orders-table tbody tr.is-active { background: rgba(0, 0, 0, .08); }
        .orders-table tbody tr.is-active td { font-weight: 600; }
        .orders-table a { color: inherit; font-weight: 600; text-decoration: none; }
        .orders-table a:hover { text-decoration: underline; }

        /* keep Subtotal / Discount on one line even if the site has its own .row style */
        .tv-page .totals .row { display: flex; flex-wrap: nowrap; margin: 0; }
        .tv-page .totals .row > * { width: auto; max-width: none; padding: 0; margin: 0; flex-shrink: 1; }

        /* printing only the receipt */
        @media print {
            .tv-topbar, .breadcrumb, .stats, .orders-card, .actions { display: none !important; }
            .view-grid { display: block !important; }
        }
    </style>
</head>
<body>

<div class="tv-page">

<header class="tv-topbar">
    <h1>Transaction View</h1>
    <nav class="tv-topnav" aria-label="Transaction pages">
        <a class="tv-nav-btn" href="transaction.php">History <?= icon('right') ?></a>
    </nav>
</header>

<main class="shell">

    <!-- Shown when there are no sales at all -->
    <section class="card" id="empty-state" hidden>
        <div class="card-head">
            <h2>No transactions yet</h2>
        </div>
        <p>There are no sales yet. Record an order in the dashboard and it will show up here.</p>
        <div class="actions">
            <a class="btn" href="dashboard_e.php">Go to dashboard</a>
        </div>
    </section>

    <div id="sale-view" hidden>

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

            <!-- Cookie purchases: all customer orders -->
            <section class="card orders-card" aria-labelledby="items-title">
                <div class="card-head">
                    <h2 id="items-title">Cookie purchases</h2>
                    <span class="pill" id="orders-pill">0 orders</span>
                </div>

                <div class="table-wrap orders-scroll">
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
                </div>

                <div class="pager">
                    <span id="pager-text">Sale 1 of 1</span>
                    <div class="pager-links">
                        <a class="btn ghost small is-disabled" id="prev-link" href="#"
                           aria-disabled="true" tabindex="-1"><?= icon('left') ?> Previous sale</a>
                        <a class="btn ghost small is-disabled" id="next-link" href="#"
                           aria-disabled="true" tabindex="-1">Next sale <?= icon('right') ?></a>
                    </div>
                </div>
            </section>

            <!-- Receipt of the selected order -->
            <aside class="card" aria-labelledby="details-title">
                <div class="card-head">
                    <h2 id="details-title">Receipt</h2>
                    <span class="pill" id="status-pill">Completed</span>
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

</main>

</div>

<script>
(function () {
    var SALES_KEY = 'salesTransactions';   /* same key used by dashboard_e.php */

    var sales = [];        /* all orders, oldest first */
    var current = null;    /* id of the order whose receipt is shown */

    function $(id) { return document.getElementById(id); }

    /* ---------- storage ---------- */
    function readSales() {
        try {
            var saved = JSON.parse(localStorage.getItem(SALES_KEY));
            return Array.isArray(saved) ? saved : [];
        } catch (e) {
            return [];
        }
    }

    function loadSales() {
        sales = readSales().sort(function (a, b) { return Number(a.id) - Number(b.id); });
    }

    /* ---------- formatting ---------- */
    function peso(n) {
        n = Number(n) || 0;
        return '\u20b1' + n.toLocaleString('en-US', { maximumFractionDigits: 0 });
    }

    function code(id) {
        var s = String(id);
        while (s.length < 4) { s = '0' + s; }
        return '#' + s;
    }

    function pad2(n) { return n < 10 ? '0' + n : String(n); }

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
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
        if (!d) { return String(sale.date || '') + ' ' + String(sale.time || ''); }
        var day = d.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
        var time = sale.iso
            ? d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
            : String(sale.time || '');
        return day + ' \u00b7 ' + time;
    }

    function whenShort(sale) {
        if (sale.date === todayStr()) { return 'Today ' + String(sale.time || ''); }
        var d = parseDate(sale);
        if (!d) { return String(sale.date || '') + ' ' + String(sale.time || ''); }
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
        return saleSubtotal(sale) - (Number(sale.discount) || 0);
    }

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

    /* ---------- drawing ---------- */
    function drawOrders() {
        var body = $('orders-body');
        body.textContent = '';

        /* newest order first */
        sales.slice().reverse().forEach(function (s) {
            var tr = document.createElement('tr');
            var active = String(s.id) === String(current);
            if (active) { tr.className = 'is-active'; tr.setAttribute('aria-selected', 'true'); }

            var link = document.createElement('a');
            link.href = 'transaction.php?id=' + encodeURIComponent(s.id);
            link.textContent = code(s.id);

            tr.appendChild(td(link));
            tr.appendChild(td(s.customer || 'Walk-in customer'));
            var cookieList = document.createElement('div');
            saleLines(s).forEach(function (l) {
                var item = document.createElement('div');
                item.textContent = l.name + ' \u00d7 ' + l.qty;
                cookieList.appendChild(item);
            });
            tr.appendChild(td(cookieList));
            tr.appendChild(td(peso(saleTotal(s)), 'num'));
            tr.appendChild(td(s.payment || ''));
            tr.appendChild(td(whenShort(s)));

            tr.addEventListener('click', function (ev) {
                ev.preventDefault();
                select(s.id, true);
            });
            body.appendChild(tr);
        });

        setText('orders-pill', sales.length + (sales.length === 1 ? ' order' : ' orders'));
    }

    function drawReceipt(sale, index) {
        var lines = saleLines(sale);
        var cookies = saleCookies(sale);
        var subtotal = saleSubtotal(sale);
        var discount = Number(sale.discount) || 0;
        var total = subtotal - discount;
        var average = cookies > 0 ? total / cookies : 0;

        document.title = 'Transaction ' + code(sale.id) + ' \u2014 Cookie Shop';

        /* summary cards */
        setText('stat-total', peso(total));
        setText('stat-cookies', String(cookies));
        setText('stat-flavors', String(lines.length));
        setText('stat-average', '\u20b1' + average.toLocaleString('en-US', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        }));

        /* receipt header + details */
        setText('status-pill', sale.status || 'Completed');
        $('status-pill').classList.toggle('ok', String(sale.status || 'Completed').toLowerCase() === 'completed');
        setText('tx-code', code(sale.id));
        setText('tx-when', whenLong(sale));
        setText('m-customer', sale.customer || 'Walk-in customer');
        setText('m-payment', sale.payment || '');
        setText('m-employee', sale.employee || 'Employee');
        setText('m-items', cookies + ' cookies');

        /* totals */
        setText('t-subtotal', peso(subtotal));
        setText('t-discount', peso(discount));
        setText('t-total', peso(total));

        /* previous / next sale */
        setText('pager-text', 'Sale ' + (index + 1) + ' of ' + sales.length);
        setPager($('prev-link'), index > 0 ? sales[index - 1].id : null);
        setPager($('next-link'), index < sales.length - 1 ? sales[index + 1].id : null);
    }

    function indexOf(id) {
        for (var i = 0; i < sales.length; i++) {
            if (String(sales[i].id) === String(id)) { return i; }
        }
        return -1;
    }

    /* show the receipt of one order (push = also update the address bar) */
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
        drawReceipt(sale, index);

        /* keep the selected row visible in the list */
        var active = document.querySelector('#orders-body tr.is-active');
        if (active && active.scrollIntoView) { active.scrollIntoView({ block: 'nearest' }); }
    }

    function start(id) {
        loadSales();

        if (!sales.length) {
            $('sale-view').hidden = true;
            $('empty-state').hidden = false;
            return;
        }
        $('empty-state').hidden = true;
        $('sale-view').hidden = false;
        select(id, false);
    }

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
        var total = subtotal - discount;

        var out = [];
        var line = new Array(41).join('=');
        var dash = new Array(41).join('-');

        out.push('COOKIE SHOP RECEIPT');
        out.push(line);
        out.push('Transaction : ' + code(sale.id));
        out.push('Date        : ' + whenLong(sale));
        out.push('Customer    : ' + (sale.customer || 'Walk-in customer'));
        out.push('Payment     : ' + (sale.payment || ''));
        out.push('Processed by: ' + (sale.employee || 'Employee'));
        out.push(dash);
        lines.forEach(function (l) {
            var amount = (Number(l.price) || 0) * (Number(l.qty) || 0);
            out.push(
                padRight(l.name, 24) + ' ' +
                padLeft(l.qty, 2) + ' x ' + padRight(peso(l.price), 4) + ' ' +
                padLeft(peso(amount), 6)
            );
        });
        out.push(dash);
        out.push(padRight('Subtotal', 30) + ' ' + padLeft(peso(subtotal), 9));
        out.push(padRight('Discount', 30) + ' ' + padLeft(peso(discount), 9));
        out.push(padRight('TOTAL PAID', 30) + ' ' + padLeft(peso(total), 9));

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
        if (sales.length) { select(idFromUrl(), false); }
    });

    /* a new order was recorded in the dashboard (another tab) */
    window.addEventListener('storage', function (ev) {
        if (ev.key === SALES_KEY) { start(current); }
    });

    /* coming back to this tab */
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { start(current !== null ? current : idFromUrl()); }
    });

    start(idFromUrl());
})();
</script>

</body>
</html>