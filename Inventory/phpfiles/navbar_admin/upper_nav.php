<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
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

/* notify.js sits in the project root (INVENTORY/notify.js) - the same helper the Notifications page uses */
$notifyJs = '';
foreach ([__DIR__ . '/notify.js', __DIR__ . '/../notify.js', __DIR__ . '/../../notify.js'] as $candidate) {
    $path = webPathOf($candidate);
    if ($path !== '') {
        $notifyJs = $path;
        break;
    }
}
if ($notifyJs === '') { $notifyJs = 'notify.js'; }

// Current page (same default as admindash.php)
$currentPage = $_GET['page'] ?? 'dashboard';

// Page key => breadcrumb label
$pageTitles = [
    'dashboard'     => 'Dashboard',
    'inventory'     => 'Inventory Management',
    'employees'     => 'Employees Management',
    'notifications' => 'Notifications',
    'backup'        => 'Report Page',
];
$pageTitle = $pageTitles[$currentPage] ?? 'Dashboard';

// Admin identity comes from its own file (see admin_account.php), never from the employee sign-up account
$admin       = require __DIR__ . '/../../database/admin_account.php';
$displayName = $admin['username'];
$displayRole = $admin['role'];

// Initials for the avatar circle (e.g. "AD")
$initials = strtoupper(function_exists('mb_substr')
    ? mb_substr($displayName, 0, 2)
    : substr($displayName, 0, 2));

// Starting number for the bell badge. The browser replaces it with the real unread count.
$notificationCount = $notificationCount ?? 0;

$notificationsUrl = 'admindash.php?page=notifications';
$homeUrl          = 'admindash.php?page=dashboard';
?>

<header class="topbar">

    <!-- Breadcrumb -->
    <nav class="topbar-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">Home</a>
        <span class="sep">/</span>
        <span class="current"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>
    </nav>

    <!-- Notification + user -->
    <div class="user-info">

        <!-- Bell: opens the notification window (falls back to the Notifications page if JavaScript is off) -->
        <a href="<?= htmlspecialchars($notificationsUrl, ENT_QUOTES, 'UTF-8') ?>" class="notification-btn"
            id="notifBell" aria-label="Notifications" aria-haspopup="dialog">
            <i class="bi bi-bell"></i>
            <span class="notif-badge" id="notifBadge" <?= (int) $notificationCount > 0 ? '' : 'hidden' ?>><?= (int) $notificationCount ?></span>
        </a>

        <span class="user-divider"></span>

        <div class="user-profile">
            <div class="profile-img"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="user-text">
                <span class="username"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="user-role"><?= htmlspecialchars($displayRole, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

    </div>
</header>

<!-- =====================================================
     NOTIFICATION MODAL (opened by the bell)
===================================================== -->
<div class="modal fade" id="notifModal" tabindex="-1" aria-labelledby="notifModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-heading">
                    <span class="modal-eyebrow">Activity Center</span>
                    <h5 class="modal-title" id="notifModalTitle">
                        Notifications
                        <span class="nm-count" id="nmCount" hidden></span>
                    </h5>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="nm-list" id="nmList"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="nm-btn nm-btn-soft" id="nmMarkAll" hidden>Mark all as read</button>
                <a class="nm-btn nm-btn-main" href="<?= htmlspecialchars($notificationsUrl, ENT_QUOTES, 'UTF-8') ?>">
                    View all notifications
                </a>
            </div>

        </div>
    </div>
</div>

<style>
    /* ---------- bell + badge ---------- */
    .notification-btn { position: relative; }

    .notification-btn .notif-badge {
        position: absolute;
        top: -6px;
        right: -6px;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #b3261e;
        border: 2px solid #fff;
        border-radius: 999px;
        color: #fff;
        font-size: 0.65rem;
        font-weight: 700;
        line-height: 1;
    }

    .notification-btn .notif-badge[hidden] { display: none; }

    /* ---------- modal (same look as the Employees Management modals) ---------- */
    #notifModal .modal-dialog { max-width: 560px; }

    #notifModal .modal-content {
        overflow: hidden;
        padding: 0;
        background: #fbf7f2;
        border: 1px solid #b9967f;
        border-radius: 16px;
        box-shadow: 0 20px 50px rgba(47, 32, 25, 0.35);
        color: #2f2019;
        font-family: 'Inter', Arial, sans-serif;
    }

    #notifModal .modal-header {
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 20px 26px;
        background: linear-gradient(120deg, #5a3d2d 0%, #7a4f31 55%, #9a6a45 100%);
        border-bottom: 0;
        color: #fff;
    }

    #notifModal .modal-header::after {
        content: "";
        position: absolute;
        right: -60px;
        top: -110px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.07);
        pointer-events: none;
    }

    #notifModal .modal-heading { position: relative; z-index: 1; min-width: 0; }

    #notifModal .modal-eyebrow {
        display: block;
        margin-bottom: 3px;
        color: #ecd9c8;
        font-size: 0.68rem;
        font-weight: 600;
        letter-spacing: 1.8px;
        text-transform: uppercase;
    }

    #notifModal .modal-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
        color: #fff;
        font-size: 1.4rem;
        font-weight: 700;
        line-height: 1.2;
    }

    #notifModal .nm-count {
        padding: 3px 10px;
        background: rgba(255, 255, 255, 0.22);
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
    }

    #notifModal .nm-count[hidden] { display: none; }

    #notifModal .modal-header .btn-close {
        position: relative;
        z-index: 1;
        margin: 0;
        filter: invert(1) grayscale(1) brightness(2);
        opacity: 0.85;
    }

    #notifModal .modal-header .btn-close:hover { opacity: 1; }

    #notifModal .modal-body {
        padding: 18px 20px;
        background: #faf5ef;
    }

    /* ---------- list ---------- */
    #notifModal .nm-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    #notifModal .nm-item {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 14px;
        background: #fff;
        border: 1px solid rgba(180, 150, 125, 0.3);
        border-radius: 14px;
        box-shadow: 0 2px 8px rgba(80, 52, 36, 0.06);
        cursor: default;
    }

    #notifModal .nm-item.is-unread {
        border-left: 4px solid #7a4f31;
        background: #fffaf4;
        cursor: pointer;
    }

    #notifModal .nm-icon {
        flex: 0 0 44px;
        width: 44px;
        height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #efe2d6, #e4cfbd);
        border: 1px solid #e2d3c6;
        border-radius: 12px;
        color: #6f4e3d;
    }

    #notifModal .nm-icon svg { width: 22px; height: 22px; }

    #notifModal .nm-stock_in  .nm-icon { background: linear-gradient(135deg, #e3f5ea, #cdebd8); border-color: #b9d6c3; color: #1d7a45; }
    #notifModal .nm-stock_out .nm-icon { background: linear-gradient(135deg, #fdf0dc, #f7e0bd); border-color: #e6c9a6; color: #b25e09; }
    #notifModal .nm-new_item  .nm-icon { background: linear-gradient(135deg, #e8f0fb, #d3e2f6); border-color: #bccde6; color: #2f5d9a; }
    #notifModal .nm-low_stock .nm-icon { background: linear-gradient(135deg, #fde9eb, #f8d0d4); border-color: #f3b9bd; color: #b3261e; }
    #notifModal .nm-order     .nm-icon { background: linear-gradient(135deg, #f0eafa, #e0d5f3); border-color: #cfc0ea; color: #5b3d99; }

    #notifModal .nm-text { flex: 1; min-width: 0; }

    #notifModal .nm-top {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 4px;
    }

    #notifModal .nm-title {
        color: #2f2019;
        font-size: 0.95rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    #notifModal .nm-tag {
        padding: 2px 9px;
        background: #efe2d6;
        border: 1px solid #d6bba6;
        border-radius: 999px;
        color: #6b5446;
        font-size: 0.64rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    #notifModal .nm-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #b25e09;
    }

    #notifModal .nm-desc {
        margin: 0 0 6px;
        color: #4a382e;
        font-size: 0.86rem;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }

    #notifModal .nm-date { color: #8a7a6e; font-size: 0.75rem; font-weight: 600; }

    #notifModal .nm-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
        background: #fff;
        border: 1px dashed #d6bba6;
        border-radius: 14px;
        text-align: center;
        color: #7d6a5e;
    }

    #notifModal .nm-empty-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 60px;
        height: 60px;
        margin-bottom: 12px;
        background: #efe2d6;
        border-radius: 50%;
        color: #6f4e3d;
        font-size: 1.5rem;
    }

    #notifModal .nm-empty h5 { margin: 0 0 6px; color: #2f2019; font-size: 1.05rem; font-weight: 700; }
    #notifModal .nm-empty p  { margin: 0; font-size: 0.88rem; }

    /* ---------- footer buttons ---------- */
    #notifModal .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 16px 20px;
        background: #fbf7f2;
        border-top: 1px solid #e8dccf;
    }

    #notifModal .nm-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 42px;
        padding: 9px 20px;
        border-radius: 10px;
        font-family: inherit;
        font-size: 0.88rem;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        transition: filter 0.15s ease, transform 0.15s ease, background 0.15s ease;
    }

    #notifModal .nm-btn[hidden] { display: none; }

    #notifModal .nm-btn-soft {
        background: #efe2d6;
        border: 1px solid #d6bba6;
        color: #6f4e3d;
    }

    #notifModal .nm-btn-soft:hover { background: #e4d2c2; }

    #notifModal .nm-btn-main {
        background: linear-gradient(135deg, #7a5642, #5a3f31);
        border: 1px solid #5a3f31;
        color: #fff;
        box-shadow: 0 3px 8px rgba(90, 50, 25, 0.22);
    }

    #notifModal .nm-btn-main:hover { filter: brightness(1.1); transform: translateY(-1px); color: #fff; }

    @media (max-width: 520px) {
        #notifModal .modal-header { padding: 18px 20px; }
        #notifModal .modal-footer { flex-direction: column-reverse; }
        #notifModal .nm-btn { width: 100%; }
    }
</style>

<!-- Same helper the Notifications page uses (browser storage) -->
<script src="<?= htmlspecialchars($notifyJs, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    "use strict";

    var BOOTSTRAP_URL = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js";
    var MAX_SHOWN = 8;

    var bell     = document.getElementById("notifBell");
    var badge    = document.getElementById("notifBadge");
    var modalEl  = document.getElementById("notifModal");
    var listEl   = document.getElementById("nmList");
    var countEl  = document.getElementById("nmCount");
    var markAll  = document.getElementById("nmMarkAll");

    if (!bell || !modalEl) { return; }

    /* Move the window to <body> so the layout can't clip it or hide it behind the backdrop.
       Copies left over from an earlier load of the page are removed first. */
    var copies = Array.prototype.slice.call(document.querySelectorAll("#notifModal"));
    var fresh  = copies.find(function (n) { return n.parentElement !== document.body; }) || copies[copies.length - 1];
    copies.forEach(function (n) { if (n !== fresh) { n.remove(); } });
    if (fresh.parentElement !== document.body) { document.body.appendChild(fresh); }
    modalEl = fresh;
    listEl  = modalEl.querySelector("#nmList");
    countEl = modalEl.querySelector("#nmCount");
    markAll = modalEl.querySelector("#nmMarkAll");

    var LABELS = {
        low_stock: "Low stock", new_item: "New item", stock_in: "Stock in",
        stock_out: "Stock out", order: "Orders", system: "System"
    };

    var ICONS = {
        low_stock: '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18h.01"/>',
        new_item:  '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        stock_in:  '<path d="M12 19V5M5 12l7-7 7 7"/>',
        stock_out: '<path d="M12 5v14M19 12l-7 7-7-7"/>',
        order:     '<path d="M6 2l-2 4v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6l-2-4H6z"/><path d="M4 6h16"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        system:    '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v5h1"/>'
    };

    var SVG_OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';

    function el(tag, cls, text) {
        var x = document.createElement(tag);
        if (cls) { x.className = cls; }
        if (text !== undefined) { x.textContent = text; }
        return x;
    }

    function fmtDate(s) {
        var d = new Date(String(s).replace(" ", "T"));
        if (isNaN(d.getTime())) { return ""; }
        return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }) +
               " \u00b7 " + d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
    }

    function getAll() {
        try {
            return (window.Notify && Notify.list()) || [];
        } catch (err) {
            return [];
        }
    }

    /* red badge on the bell */
    function updateBadge() {
        var unread = getAll().filter(function (n) { return !n.is_read; }).length;
        badge.textContent = unread > 99 ? "99+" : unread;
        badge.hidden = unread === 0;
    }

    function emptyState() {
        var box = el("div", "nm-empty");
        var ic  = el("div", "nm-empty-icon");
        ic.innerHTML = '<i class="bi bi-bell"></i>';
        box.appendChild(ic);
        box.appendChild(el("h5", "", "No notifications"));
        box.appendChild(el("p", "", "You are all caught up. New alerts and updates will appear here."));
        return box;
    }

    function row(n) {
        var type = ICONS[n.type] ? n.type : "system";
        var a = el("article", "nm-item nm-" + type + (n.is_read ? "" : " is-unread"));

        var ic = el("div", "nm-icon");
        ic.innerHTML = SVG_OPEN + ICONS[type] + "</svg>";

        var text = el("div", "nm-text");
        var top  = el("div", "nm-top");
        top.appendChild(el("span", "nm-title", n.title));
        top.appendChild(el("span", "nm-tag", LABELS[type] || "General"));
        if (!n.is_read) { top.appendChild(el("span", "nm-dot")); }

        text.appendChild(top);
        if (n.description) { text.appendChild(el("p", "nm-desc", n.description)); }
        text.appendChild(el("span", "nm-date", fmtDate(n.date)));

        a.append(ic, text);

        /* clicking an unread notification marks it as read */
        if (!n.is_read) {
            a.title = "Click to mark as read";
            a.addEventListener("click", function () {
                try { Notify.markRead(n.id); } catch (err) {}
                renderModal();
            });
        }
        return a;
    }

    function renderModal() {
        var all    = getAll();
        var unread = all.filter(function (n) { return !n.is_read; }).length;

        /* newest first */
        var sorted = all.slice().sort(function (a, b) {
            return new Date(String(b.date).replace(" ", "T")) - new Date(String(a.date).replace(" ", "T"));
        });

        countEl.hidden = unread === 0;
        countEl.textContent = unread + " unread";
        markAll.hidden = unread === 0;

        listEl.innerHTML = "";

        if (!sorted.length) {
            listEl.appendChild(emptyState());
        } else {
            sorted.slice(0, MAX_SHOWN).forEach(function (n) { listEl.appendChild(row(n)); });

            if (sorted.length > MAX_SHOWN) {
                listEl.appendChild(el("p", "nm-date",
                    "Showing the latest " + MAX_SHOWN + " of " + sorted.length + " notifications."));
            }
        }

        updateBadge();
    }

    /* Bootstrap is normally loaded by the admin layout; load it only if it is missing */
    function loadBootstrap() {
        if (window.bootstrap) { return Promise.resolve(); }
        return new Promise(function (resolve, reject) {
            var s = document.createElement("script");
            s.src = BOOTSTRAP_URL;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    function openModal() {
        renderModal();
        loadBootstrap().then(function () {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }).catch(function () {
            /* could not load Bootstrap: go to the full page instead */
            window.location.href = bell.getAttribute("href");
        });
    }

    bell.addEventListener("click", function (ev) {
        /* let ctrl/shift/middle-click open the page normally */
        if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1) { return; }
        ev.preventDefault();
        openModal();
    });

    markAll.addEventListener("click", function () {
        try { Notify.markAllRead(); } catch (err) {}
        renderModal();
    });

    /* keep the badge and the window in sync with the Notifications page and other tabs */
    window.addEventListener("storage", function () {
        updateBadge();
        if (modalEl.classList.contains("show")) { renderModal(); }
    });

    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) { updateBadge(); }
    });

    updateBadge();
})();
</script>