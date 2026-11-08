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

/* notify.js sits in the project root (SEMPROJECT/notify.js) */
$notifyJs = webPathOf(__DIR__ . '/../notify.js') ?: 'notify.js';

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/* Inline SVG for the empty state */
function icon(string $type): string
{
    $paths = [
        'system' => '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v5h1"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $paths[$type] . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>

    <?php
    /* finds notifi.css wherever it is in your project */
    $cssCandidates = ['admin/assets/notifi.css', 'assets/notifi.css', 'assets/css/notifi.css', 'admin/notifi.css'];
    $cssHref = 'admin/assets/notifi.css';
    foreach ($cssCandidates as $c) {
        if (is_file(__DIR__ . '/../' . $c)) {
            $cssHref = webPathOf(__DIR__ . '/../' . $c) ?: $c;
            break;
        }
    }
    ?>
    <link rel="stylesheet" href="<?= e($cssHref) ?>?v=<?= (int)@filemtime(__DIR__ . '/assets/notifi.css') ?>">
</head>
<body>

    <div class="notification-panel">

        <!-- HEADER BAND -->
        <header class="notification-header">
            <span class="notification-header-icon">
                <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="width:22px;height:22px"><path d="M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4a1.5 1.5 0 0 0-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
            </span>
            <h1>Notifications</h1>

            <span class="notification-count" id="notif-count" hidden></span>

            <div class="notification-actions">
                <button type="button" class="notification-btn" id="mark-all" hidden>Mark all as read</button>
                <button type="button" class="notification-btn notification-btn-danger" id="clear-all" hidden>Clear all</button>
            </div>
        </header>

        <div class="notification-body">

            <!-- TABS (filters) -->
            <nav class="notification-tabs" id="notif-tabs" aria-label="Filter notifications">
                <button type="button" class="notification-tab is-active" data-filter="all">All</button>
                <button type="button" class="notification-tab" data-filter="unread">Unread</button>
                <button type="button" class="notification-tab" data-filter="stock_in">Stock in</button>
                <button type="button" class="notification-tab" data-filter="stock_out">Stock out</button>
                <button type="button" class="notification-tab" data-filter="inventory">Inventory</button>
            </nav>

            <!-- NOTIFICATION CONTAINER (filled by JavaScript from browser storage) -->
            <main class="notification-container" id="notif-list"></main>
        </div>

        <!-- EMPTY STATE TEMPLATE -->
        <template id="notif-empty">
            <div class="notification-empty">
                <div class="empty-icon"><?= icon('system') ?></div>
                <p class="empty-title">No notifications</p>
                <p class="empty-text">
                    You are all caught up. New alerts and updates will appear here.
                </p>
            </div>
        </template>
    </div>

    <script src="<?= e($notifyJs) ?>"></script>
    <script>
    (function () {
        var list    = document.getElementById('notif-list');
        var countEl = document.getElementById('notif-count');
        var markAll = document.getElementById('mark-all');
        var clearEl = document.getElementById('clear-all');
        var tabsEl  = document.getElementById('notif-tabs');
        var filter  = 'all';

        var LABELS = {
            low_stock: 'Low stock', new_item: 'New item', stock_in: 'Stock in',
            stock_out: 'Stock out', order: 'Orders', system: 'System'
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

        var SVG_OPEN  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
        var CHECK_SVG = SVG_OPEN + '<path d="M5 12l5 5L20 7"/></svg>';
        var TRASH_SVG = SVG_OPEN + '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg>';

        function svg(type) {
            return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                   (ICONS[type] || ICONS.system) + '</svg>';
        }

        function fmtDate(s) {
            var d = new Date(String(s).replace(' ', 'T'));
            if (isNaN(d.getTime())) { return ''; }
            var date = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            var time = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            return date + ' \u00b7 ' + time;
        }

        function el(tag, cls, text) {
            var x = document.createElement(tag);
            if (cls) { x.className = cls; }
            if (text !== undefined) { x.textContent = text; }
            return x;
        }

        function detail(label, value) {
            var box = el('div', 'notification-detail');
            box.appendChild(el('div', 'notification-detail-label', label));
            box.appendChild(el('div', 'notification-detail-value', value));
            return box;
        }

        function removeOne(id) {
            var rest = Notify.list().filter(function (x) { return x.id !== id; });
            try { localStorage.setItem(Notify.KEY, JSON.stringify(rest)); } catch (e) {}
        }

        function render() {
            var all    = Notify.list();
            var unread = all.filter(function (n) { return !n.is_read; }).length;

            countEl.hidden = unread === 0;
            countEl.textContent = unread + ' unread';
            markAll.hidden = unread === 0;
            clearEl.hidden = all.length === 0;

            Array.prototype.forEach.call(tabsEl.querySelectorAll('.notification-tab'), function (t) {
                t.classList.toggle('is-active', t.getAttribute('data-filter') === filter);
            });

            var items = all.filter(FILTERS[filter] || FILTERS.all);
            list.innerHTML = '';

            if (!items.length) {
                var empty = document.getElementById('notif-empty').content.cloneNode(true);
                if (all.length) {
                    empty.querySelector('.empty-title').textContent = 'Nothing here';
                    empty.querySelector('.empty-text').textContent = 'There are no notifications in this tab.';
                }
                list.appendChild(empty);
                return;
            }

            items.forEach(function (n) {
                var type = ICONS[n.type] ? n.type : 'system';

                var a = el('article', 'notification-item notification-' + type + (n.is_read ? '' : ' is-unread'));

                var ic = el('div', 'notification-icon');
                ic.innerHTML = svg(type);

                var content = el('div', 'notification-content');

                /* top row: title + tag on the left, buttons on the right */
                var top  = el('div', 'notification-top');
                var wrap = el('div', 'notification-title-wrap');
                wrap.appendChild(el('p', 'notification-title', n.title));
                wrap.appendChild(el('span', 'notification-tag', LABELS[type] || 'General'));
                top.appendChild(wrap);

                var acts = el('div', 'notification-row-actions');

                if (!n.is_read) {
                    var read = el('button', 'notification-btn');
                    read.type = 'button';
                    read.innerHTML = CHECK_SVG + ' Mark as read';
                    read.addEventListener('click', function () { Notify.markRead(n.id); render(); });
                    acts.appendChild(read);
                }

                var del = el('button', 'notification-btn notification-btn-danger');
                del.type = 'button';
                del.innerHTML = TRASH_SVG + ' Delete';
                del.addEventListener('click', function () { removeOne(n.id); render(); });
                acts.appendChild(del);

                top.appendChild(acts);

                /* detail boxes */
                var details = el('div', 'notification-details');
                details.appendChild(detail('Details', n.description));
                details.appendChild(detail('When', fmtDate(n.date)));

                content.append(top, details);
                a.append(ic, content);
                list.appendChild(a);
            });
        }

        tabsEl.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.notification-tab');
            if (!btn) { return; }
            filter = btn.getAttribute('data-filter');
            render();
        });

        markAll.addEventListener('click', function () { Notify.markAllRead(); render(); });

        clearEl.addEventListener('click', function () {
            if (confirm('Delete all notifications?')) { Notify.clearAll(); render(); }
        });

        /* Updates live when an employee saves something in another tab */
        window.addEventListener('storage', function (ev) {
            if (ev.key === Notify.KEY) { render(); }
        });

        /* Also refresh when the admin returns to this tab */
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { render(); }
        });

        render();
    })();
    </script>

</body>
</html>