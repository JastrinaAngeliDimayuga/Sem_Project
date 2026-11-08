<!DOCTYPE html>
<html lang="en">

<body>

<style>
    /* ===== Notification bell + badge ===== */
    .topbar .notif-wrap {
        position: relative;
        display: inline-block;
    }

    .topbar .notification-btn {
        position: relative;
        display: inline-flex;
        align-items: center;
    }

    /* inline SVG bell: always visible, no icon font needed */
    .topbar .notif-bell-svg {
        width: 23px;
        height: 23px;
        fill: currentColor;
        display: block;
    }

    .topbar .notif-badge {
        position: absolute;
        top: -7px;
        right: -9px;
        min-width: 19px;
        height: 19px;
        padding: 0 5px;
        border-radius: 999px;
        background: #d92d20;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        line-height: 19px;
        text-align: center;
        border: 2px solid #3b2b24;
        box-sizing: content-box;
        pointer-events: none;
    }

    .topbar .notif-badge[hidden] {
        display: none;
    }

    /* the bell rings when a new notification arrives */
    .topbar .notification-btn.is-ringing .notif-bell-svg {
        transform-origin: 50% 0;
        animation: notif-ring 0.9s ease-in-out;
    }

    @keyframes notif-ring {
        0%   { transform: rotate(0); }
        15%  { transform: rotate(18deg); }
        30%  { transform: rotate(-16deg); }
        45%  { transform: rotate(12deg); }
        60%  { transform: rotate(-8deg); }
        75%  { transform: rotate(4deg); }
        100% { transform: rotate(0); }
    }

    /* ===== Dropdown preview ===== */
    .topbar .notif-dropdown {
        position: absolute;
        top: calc(100% + 16px);
        right: -70px;
        width: 360px;
        max-width: 92vw;
        background: #fff;
        color: #352720;
        border: 1px solid #e4d9d0;
        border-radius: 14px;
        box-shadow: 0 14px 36px rgba(51, 37, 31, 0.28);
        z-index: 2000;
        overflow: hidden;
        text-align: left;
        font-size: 14px;
        animation: notif-pop 0.14s ease-out;
    }

    .topbar .notif-dropdown[hidden] {
        display: none;
    }

    /* little arrow pointing at the bell */
    .topbar .notif-dropdown::before {
        content: "";
        position: absolute;
        top: -7px;
        right: 78px;
        width: 12px;
        height: 12px;
        background: #f4efeb;
        border-left: 1px solid #e4d9d0;
        border-top: 1px solid #e4d9d0;
        transform: rotate(45deg);
    }

    @keyframes notif-pop {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .topbar .notif-dd-head {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 13px 16px;
        background: #f4efeb;
        border-bottom: 1px solid #e4d9d0;
    }

    .topbar .notif-dd-head strong {
        font-size: 15px;
    }

    .topbar .notif-dd-markall {
        border: 0;
        background: none;
        padding: 0;
        color: #8a5a3c;
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
    }

    .topbar .notif-dd-markall:hover {
        text-decoration: underline;
    }

    .topbar .notif-dd-markall[hidden] {
        display: none;
    }

    .topbar .notif-dd-list {
        max-height: 340px;
        overflow-y: auto;
    }

    .topbar .notif-dd-item {
        display: flex;
        gap: 11px;
        padding: 12px 16px;
        border-bottom: 1px solid #f0e8e1;
        cursor: pointer;
    }

    .topbar .notif-dd-item:hover {
        background: #faf6f2;
    }

    .topbar .notif-dd-item.is-unread {
        background: #fffaf5;
    }

    .topbar .notif-dd-dot {
        flex: 0 0 10px;
        width: 10px;
        height: 10px;
        margin-top: 5px;
        border-radius: 50%;
        background: #c9b8aa;
    }

    .topbar .notif-dd-item.is-unread .notif-dd-dot {
        background: var(--dot, #8a5a3c);
    }

    .topbar .notif-dd-body {
        min-width: 0;
        flex: 1;
    }

    .topbar .notif-dd-title {
        margin: 0 0 2px;
        font-size: 13.5px;
        font-weight: 600;
        color: #352720;
    }

    .topbar .notif-dd-item.is-unread .notif-dd-title {
        font-weight: 700;
    }

    .topbar .notif-dd-desc {
        margin: 0 0 4px;
        font-size: 12.5px;
        line-height: 1.4;
        color: #7a6a60;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .topbar .notif-dd-time {
        font-size: 11.5px;
        color: #a08f84;
    }

    .topbar .notif-dd-empty {
        padding: 30px 16px;
        text-align: center;
        color: #7a6a60;
        font-size: 13px;
    }

    .topbar .notif-dd-footer {
        display: block;
        padding: 12px 16px;
        text-align: center;
        background: #f4efeb;
        border-top: 1px solid #e4d9d0;
        color: #3b2b24;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
    }

    .topbar .notif-dd-footer:hover {
        background: #ece3db;
        color: #3b2b24;
    }
</style>

<nav class="navbar topbar px-3 px-md-4">
    <a href="#" class="brand">B4jkery</a>

    <div class="user-info d-flex align-items-center gap-5">

        <!-- Bell: click it to preview the latest notifications -->
        <div class="notif-wrap">
            <a href="admindash.php?page=notifications" class="notification-btn" id="notif-bell"
               aria-label="Notifications" title="Notifications" aria-haspopup="true" aria-expanded="false">
                <svg class="notif-bell-svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4a1.5 1.5 0 0 0-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                <span class="notif-badge" id="notif-badge" hidden>0</span>
            </a>

            <div class="notif-dropdown" id="notif-dropdown" role="dialog" aria-label="Latest notifications" hidden>
                <div class="notif-dd-head">
                    <strong>Notifications</strong>
                    <button type="button" class="notif-dd-markall" id="notif-dd-markall" hidden>Mark all as read</button>
                </div>
                <div class="notif-dd-list" id="notif-dd-list"></div>
                <a class="notif-dd-footer" href="admindash.php?page=notifications">View all notifications</a>
            </div>
        </div>

        <span class="username">Username</span>
        <img src="" alt="Profile Picture" class="profile-img">

    </div>
</nav>

<script>
(function () {
    /* Reads the same browser storage key that notify.js writes to. No database. */
    var KEY       = 'adminNotifications';
    var PREVIEW   = 5;     // how many notifications the dropdown shows
    var bell      = document.getElementById('notif-bell');
    var badge     = document.getElementById('notif-badge');
    var dropdown  = document.getElementById('notif-dropdown');
    var listEl    = document.getElementById('notif-dd-list');
    var markAllEl = document.getElementById('notif-dd-markall');

    if (!bell || !badge || !dropdown) { return; }

    var DOT = {
        stock_in: '#027a48', stock_out: '#b54708', new_item: '#175cd3',
        low_stock: '#b42318', order: '#6941c6', system: '#8a5a3c'
    };

    /* ---------- storage ---------- */
    function readAll() {
        try {
            var list = JSON.parse(localStorage.getItem(KEY));
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return [];
        }
    }

    function writeAll(list) {
        try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {}
    }

    function unreadCount() {
        return readAll().filter(function (n) { return !n.is_read; }).length;
    }

    function fmtDate(s) {
        var d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return ''; }
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' \u00b7 ' +
               d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    /* ---------- badge ---------- */
    function show(count, ring) {
        badge.hidden = count === 0;
        badge.textContent = count > 99 ? '99+' : String(count);

        var label = count > 0 ? 'Notifications, ' + count + ' unread' : 'Notifications';
        bell.setAttribute('aria-label', label);
        bell.title = label;

        if (ring) {
            bell.classList.remove('is-ringing');
            void bell.offsetWidth;            // restart the animation
            bell.classList.add('is-ringing');
        }
    }

    var last = unreadCount();
    show(last, false);

    /* ---------- dropdown ---------- */
    function renderList() {
        var all = readAll();
        var unread = all.filter(function (n) { return !n.is_read; }).length;

        markAllEl.hidden = unread === 0;
        listEl.innerHTML = '';

        if (!all.length) {
            var empty = document.createElement('div');
            empty.className = 'notif-dd-empty';
            empty.textContent = 'No notifications yet.';
            listEl.appendChild(empty);
            return;
        }

        all.slice(0, PREVIEW).forEach(function (n) {
            var item = document.createElement('div');
            item.className = 'notif-dd-item' + (n.is_read ? '' : ' is-unread');
            item.style.setProperty('--dot', DOT[n.type] || DOT.system);

            var dot = document.createElement('span');
            dot.className = 'notif-dd-dot';

            var body = document.createElement('div');
            body.className = 'notif-dd-body';

            var title = document.createElement('p');
            title.className = 'notif-dd-title';
            title.textContent = n.title || '';

            var desc = document.createElement('p');
            desc.className = 'notif-dd-desc';
            desc.textContent = n.description || '';

            var time = document.createElement('span');
            time.className = 'notif-dd-time';
            time.textContent = fmtDate(n.date);

            body.append(title, desc, time);
            item.append(dot, body);

            /* clicking a notification marks it as read */
            item.addEventListener('click', function () {
                var list = readAll();
                list.forEach(function (x) { if (x.id === n.id) { x.is_read = true; } });
                writeAll(list);
                renderList();
                check();
            });

            listEl.appendChild(item);
        });
    }

    function openDropdown() {
        renderList();
        dropdown.hidden = false;
        bell.setAttribute('aria-expanded', 'true');
    }

    function closeDropdown() {
        dropdown.hidden = true;
        bell.setAttribute('aria-expanded', 'false');
    }

    bell.addEventListener('click', function (ev) {
        ev.preventDefault();                  // stay on this page, show the preview
        if (dropdown.hidden) { openDropdown(); } else { closeDropdown(); }
    });

    markAllEl.addEventListener('click', function () {
        var list = readAll();
        list.forEach(function (x) { x.is_read = true; });
        writeAll(list);
        renderList();
        check();
    });

    /* click outside or press Escape to close */
    document.addEventListener('click', function (ev) {
        if (!dropdown.hidden && !ev.target.closest('.notif-wrap')) { closeDropdown(); }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !dropdown.hidden) { closeDropdown(); bell.focus(); }
    });

    /* ---------- keep everything up to date ---------- */
    function check() {
        var now = unreadCount();
        if (now !== last) {
            show(now, now > last);
            last = now;
            if (!dropdown.hidden) { renderList(); }
        }
    }

    /* another tab (employee) saved something */
    window.addEventListener('storage', function (ev) {
        if (ev.key === KEY) {
            check();
            if (!dropdown.hidden) { renderList(); }
        }
    });

    /* coming back to this tab */
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { check(); }
    });

    /* same-tab changes do not fire "storage", so check every 2 seconds */
    setInterval(check, 2000);
})();
</script>

</body>

</html>