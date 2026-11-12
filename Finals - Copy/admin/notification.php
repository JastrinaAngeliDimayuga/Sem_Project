<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/* ---------- ACCESS CONTROL ----------
   Uncomment once your login page sets this session value.
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}
*/

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

/* The notifications API (database/notifications_db.php in the project root) */
$apiUrl = webPathOf(__DIR__ . '/../database/notifications_db.php') ?: '../database/notifications_db.php';

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/* Inline SVG icons (no icon font needed) */
function icon(string $type): string
{
    $paths = [
        'system'  => '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v5h1"/>',
        'bell'    => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'unread'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'read'    => '<path d="M5 12l5 5L20 7"/>',
        'warning' => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18h.01"/>',
        'trash'   => '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($paths[$type] ?? $paths['system']) . '</svg>';
}

/* finds notifi.css wherever it is in your project */
$cssCandidates = ['admin/assets/notifi.css', 'assets/notifi.css', 'assets/css/notifi.css', 'admin/notifi.css'];
$cssHref = 'admin/assets/notifi.css';
$cssVer  = time();
foreach ($cssCandidates as $c) {
    $file = __DIR__ . '/../' . $c;
    if (is_file($file)) {
        $cssHref = webPathOf($file) ?: $c;
        $cssVer  = (int) @filemtime($file);
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link rel="stylesheet" href="<?= e($cssHref) ?>?v=<?= $cssVer ?>">
</head>
<body>
<div class="nt-page">
<div class="nt-wrap">

    <!-- Hero -->
    <section class="nt-hero">
        <div class="nt-hero-copy">
            <span class="nt-eyebrow">Activity Center</span>
            <h1 class="nt-hero-title">Notifications</h1>
            <p class="nt-hero-text">
                Stay up to date with stock movements, new items and system alerts.
            </p>
        </div>

        <div class="nt-hero-actions">
            <button type="button" class="nt-hero-btn" id="mark-all" hidden>
                <?= icon('read') ?> Mark all as read
            </button>
            <button type="button" class="nt-hero-btn is-ghost" id="clear-all" hidden>
                <?= icon('trash') ?> Clear all
            </button>
        </div>
    </section>

    <!-- Main panel -->
    <section class="nt-panel">

        <div class="nt-toolbar">
            <div class="nt-tabs" id="notif-tabs" aria-label="Filter notifications">
                <button type="button" class="nt-tab active" data-filter="all">
                    All <span class="nt-tab-count" data-count="all">0</span>
                </button>
                <button type="button" class="nt-tab" data-filter="unread">
                    Unread <span class="nt-tab-count" data-count="unread">0</span>
                </button>
                <button type="button" class="nt-tab" data-filter="stock_in">
                    Stock in <span class="nt-tab-count" data-count="stock_in">0</span>
                </button>
                <button type="button" class="nt-tab" data-filter="stock_out">
                    Stock out <span class="nt-tab-count" data-count="stock_out">0</span>
                </button>
                <button type="button" class="nt-tab" data-filter="inventory">
                    Inventory <span class="nt-tab-count" data-count="inventory">0</span>
                </button>
            </div>
        </div>

        <div class="nt-list-head">
            <h2 class="nt-list-title" id="nt-list-title">All Notifications</h2>
            <p class="nt-list-sub" id="nt-list-sub">Loading...</p>
        </div>

        <div class="nt-list">
            <!-- filled by JavaScript from the database -->
            <div class="nt-grid" id="notif-list"></div>
        </div>

    </section>
</div>
</div>

<script>
(function () {
    var API     = <?= json_encode($apiUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
    var list    = document.getElementById('notif-list');
    var markAll = document.getElementById('mark-all');
    var clearEl = document.getElementById('clear-all');
    var tabsEl  = document.getElementById('notif-tabs');
    var filter  = 'all';
    var all     = [];          // notifications loaded from the database
    var loaded  = false;
    var errorMsg = '';

    var LABELS = {
        low_stock: 'Low stock', new_item: 'New item', stock_in: 'Stock in',
        stock_out: 'Stock out', order: 'Orders', system: 'System'
    };

    var TITLES = {
        all: 'All Notifications', unread: 'Unread Notifications',
        stock_in: 'Stock In', stock_out: 'Stock Out', inventory: 'Inventory Alerts'
    };

    var ICONS = {
        low_stock: '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18h.01"/>',
        new_item:  '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        stock_in:  '<path d="M12 19V5M5 12l7-7 7 7"/>',
        stock_out: '<path d="M12 5v14M19 12l-7 7-7-7"/>',
        order:     '<path d="M6 2l-2 4v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6l-2-4H6z"/><path d="M4 6h16"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        system:    '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v5h1"/>'
    };

    var FILTERS = {
        all:       function () { return true; },
        unread:    function (n) { return !n.is_read; },
        stock_in:  function (n) { return n.type === 'stock_in'; },
        stock_out: function (n) { return n.type === 'stock_out'; },
        inventory: function (n) { return n.type === 'new_item' || n.type === 'low_stock' || n.type === 'system'; }
    };

    var SVG_OPEN  = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
    var CHECK_SVG = SVG_OPEN + '<path d="M5 12l5 5L20 7"/></svg>';
    var TRASH_SVG = SVG_OPEN + '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg>';
    var BELL_SVG  = SVG_OPEN + '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>';

    /* ---------- talking to the database API ---------- */

    function call(action, extra) {
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
            body: JSON.stringify(Object.assign({ action: action }, extra || {}))
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { throw new Error(d.message || 'Request failed.'); }
            return d;
        });
    }

    /* sends a change, and reloads the real data if the server refused it */
    function send(action, extra) {
        call(action, extra).catch(function (err) {
            errorMsg = err.message || 'Could not save the change.';
            load();
        });
    }

    function load() {
        return fetch(API + '?action=list', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'fetch' }
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                all = Array.isArray(d.notifications) ? d.notifications : [];
                loaded = true;
                render();
            } else {
                errorMsg = d.message || 'Could not load notifications.';
                render();
            }
        })
        .catch(function () {
            errorMsg = 'Could not reach the server.';
            render();
        });
    }

    /* ---------- helpers ---------- */

    function svg(type) {
        return SVG_OPEN.replace('stroke-width="2"', 'stroke-width="1.8"') +
               (ICONS[type] || ICONS.system) + '</svg>';
    }

    function fmtDate(s) {
        var d = new Date(String(s));
        if (isNaN(d.getTime())) { return ''; }
        var date = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'Asia/Manila' });
        var time = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' });
        return date + ' \u00b7 ' + time;
    }

    function el(tag, cls, text) {
        var x = document.createElement(tag);
        if (cls) { x.className = cls; }
        if (text !== undefined) { x.textContent = text; }
        return x;
    }

    function detail(label, value, full) {
        var box = el('div', 'nt-detail' + (full ? ' full' : ''));
        box.appendChild(el('div', 'nt-detail-label', label));
        box.appendChild(el('div', 'nt-detail-value', value || '\u2014'));
        return box;
    }

    function setText(id, value) {
        document.getElementById(id).textContent = value;
    }

    function renderStats() {
        var unread = all.filter(function (n) { return !n.is_read; }).length;

        Array.prototype.forEach.call(document.querySelectorAll('[data-count]'), function (c) {
            var f = FILTERS[c.getAttribute('data-count')] || FILTERS.all;
            c.textContent = all.filter(f).length;
        });

        markAll.hidden = unread === 0;
        clearEl.hidden = all.length === 0;
    }

    function emptyState(hasAny) {
        var box = el('div', 'nt-empty');
        var ic  = el('div', 'nt-empty-icon');
        ic.innerHTML = BELL_SVG;
        box.appendChild(ic);
        box.appendChild(el('h5', '', hasAny ? 'Nothing here' : 'No notifications'));
        box.appendChild(el('p', '', hasAny
            ? 'There are no notifications in this tab.'
            : 'You are all caught up. New alerts and updates will appear here.'));
        return box;
    }

    function errorState(message) {
        var box = el('div', 'nt-empty');
        var ic  = el('div', 'nt-empty-icon');
        ic.innerHTML = BELL_SVG;
        box.appendChild(ic);
        box.appendChild(el('h5', '', 'Could not load notifications'));
        box.appendChild(el('p', '', message));
        return box;
    }

    function card(n) {
        var type = ICONS[n.type] ? n.type : 'system';

        var a = el('article', 'nt-item nt-' + type + (n.is_read ? '' : ' is-unread'));

        var ic = el('div', 'nt-avatar');
        ic.setAttribute('aria-hidden', 'true');
        ic.innerHTML = svg(type);

        var content = el('div', 'nt-item-content');

        /* top row: title + status pill on the left, buttons on the right */
        var top   = el('div', 'nt-item-top');
        var title = el('div', 'nt-item-title');
        title.appendChild(el('span', 'nt-item-name', n.title));
        title.appendChild(el('span', 'nt-tag', LABELS[type] || 'General'));
        if (!n.is_read) { title.appendChild(el('span', 'nt-status is-unread', 'Unread')); }
        top.appendChild(title);

        var acts = el('div', 'nt-actions');

        if (!n.is_read) {
            var read = el('button', 'nt-read');
            read.type = 'button';
            read.innerHTML = CHECK_SVG + ' Mark as read';
            read.addEventListener('click', function () {
                n.is_read = true;
                render();
                send('mark_read', { id: n.id });
            });
            acts.appendChild(read);
        }

        var del = el('button', 'nt-delete');
        del.type = 'button';
        del.innerHTML = TRASH_SVG + ' Delete';
        del.addEventListener('click', function () {
            all = all.filter(function (x) { return x.id !== n.id; });
            render();
            send('delete', { id: n.id });
        });
        acts.appendChild(del);
        top.appendChild(acts);

        /* detail chips */
        var details = el('div', 'nt-details');
        details.appendChild(detail('Details', n.description));
        details.appendChild(detail('When', fmtDate(n.date)));

        content.append(top, details);
        a.append(ic, content);
        return a;
    }

    function render() {
        var items = all.filter(FILTERS[filter] || FILTERS.all);

        renderStats();

        Array.prototype.forEach.call(tabsEl.querySelectorAll('.nt-tab'), function (t) {
            t.classList.toggle('active', t.getAttribute('data-filter') === filter);
        });

        setText('nt-list-title', TITLES[filter] || TITLES.all);

        list.innerHTML = '';

        if (!loaded) {
            if (errorMsg) {
                setText('nt-list-sub', 'Something went wrong');
                list.appendChild(errorState(errorMsg));
            } else {
                setText('nt-list-sub', 'Loading...');
            }
            return;
        }

        setText('nt-list-sub', errorMsg ||
            (items.length + (items.length === 1 ? ' notification' : ' notifications')));

        if (!items.length) {
            list.appendChild(emptyState(all.length > 0));
            return;
        }

        items.forEach(function (n) { list.appendChild(card(n)); });
    }

    /* ---------- events ---------- */

    tabsEl.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.nt-tab');
        if (!btn) { return; }
        filter = btn.getAttribute('data-filter');
        render();
    });

    markAll.addEventListener('click', function () {
        all.forEach(function (n) { n.is_read = true; });
        render();
        send('mark_all_read');
    });

    clearEl.addEventListener('click', function () {
        if (confirm('Delete all notifications?')) {
            all = [];
            render();
            send('clear_all');
        }
    });

    /* Refresh when the admin returns to this tab */
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { errorMsg = ''; load(); }
    });

    /* Refresh every 30 seconds so new stock movements show up */
    setInterval(function () {
        if (!document.hidden) { errorMsg = ''; load(); }
    }, 30000);

    render();
    load();
})();
</script>

</body>
</html>