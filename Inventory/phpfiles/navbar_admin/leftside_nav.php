<?php
/* ---------- LOG OUT (handled in this same file) ----------
   The popup's "Yes" button calls this page with ?logout=1.
   It ends the session; the browser then goes to login.php. */
if (isset($_GET['logout'])) {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];

        if (!headers_sent() && ini_get('session.use_cookies')) {
            $c = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $c['path'], $c['domain'], $c['secure'], $c['httponly']);
        }

        session_destroy();
    }

    if (!headers_sent()) {
        header('Location: login.php');
    } else {
        echo '<script>window.location.replace("login.php");</script>';
    }
    exit;
}

// Current page (same default as admindash.php)
$currentPage = $_GET['page'] ?? 'dashboard';

// Menu items: page key => [label, Bootstrap icon]
$menuItems = [
    'dashboard'     => ['Dashboard',             'bi-grid'],
    'inventory'     => ['Inventory Management',  'bi-box-seam'],
    'employees'     => ['Employees Management',  'bi-people'],
    'notifications' => ['Notifications',         'bi-bell'],
    'backup'        => ['Report Page',           'bi-file-earmark-bar-graph'],
];

// Number of unread notifications (set this from your database before including this file)
$notificationCount = $notificationCount ?? 0;
?>

<style>
    /* ===== Log out confirmation popup ===== */
    .lo-overlay {
        position: fixed;
        inset: 0;
        z-index: 3000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(47, 32, 25, 0.55);
        animation: lo-fade 0.15s ease-out;
    }

    .lo-overlay[hidden] {
        display: none;
    }

    .lo-dialog {
        width: 100%;
        max-width: 400px;
        padding: 28px 26px 24px;
        background: #fbf7f2;
        border: 1px solid #b9967f;
        border-radius: 16px;
        box-shadow: 0 20px 50px rgba(47, 32, 25, 0.35);
        text-align: center;
        color: #2f2019;
        animation: lo-pop 0.18s ease-out;
    }

    .lo-icon {
        width: 58px;
        height: 58px;
        margin: 0 auto 14px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #efe2d6;
        border: 1px solid #d6bba6;
        color: #6f4e3d;
        font-size: 26px;
    }

    .lo-title {
        margin: 0 0 8px;
        font-family: Georgia, "Times New Roman", serif;
        font-size: 1.35rem;
        font-weight: 700;
    }

    .lo-text {
        margin: 0 0 22px;
        font-size: 0.95rem;
        line-height: 1.5;
        color: #7d6a5e;
    }

    .lo-actions {
        display: flex;
        gap: 10px;
    }

    .lo-btn {
        flex: 1;
        padding: 11px 14px;
        border-radius: 9px;
        font-size: 0.92rem;
        font-weight: 700;
        cursor: pointer;
        border: 1px solid transparent;
        transition: background 0.15s ease;
    }

    .lo-btn-cancel {
        background: #efe2d6;
        border-color: #d6bba6;
        color: #2f2019;
    }

    .lo-btn-cancel:hover {
        background: #e6d5c5;
    }

    .lo-btn-confirm {
        background: #6f4e3d;
        border-color: #6f4e3d;
        color: #fff;
    }

    .lo-btn-confirm:hover {
        background: #5c3f31;
        border-color: #5c3f31;
    }

    .lo-btn:focus-visible {
        outline: 3px solid rgba(111, 78, 61, 0.45);
        outline-offset: 2px;
    }

    @keyframes lo-fade {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    @keyframes lo-pop {
        from { opacity: 0; transform: translateY(10px) scale(0.97); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
</style>

<aside class="sidebar">

    <!-- Brand -->
    <a href="admindash.php?page=dashboard" class="sidebar-brand">
        <span class="sidebar-logo">B4</span>
        <span class="sidebar-brand-text">
            <span class="sidebar-brand-name">B4jkery</span>
            <span class="sidebar-brand-sub">Inventory System</span>
        </span>
    </a>

    <p class="menu-heading">ADMIN</p>

    <ul class="nav flex-column">
        <?php foreach ($menuItems as $key => $item): ?>
            <li class="nav-item">
                <a href="admindash.php?page=<?= $key ?>" class="nav-link<?= $currentPage === $key ? ' active' : '' ?>">
                    <i class="bi <?= $item[1] ?> me-2"></i>
                    <span><?= $item[0] ?></span>
                    <?php if ($key === 'notifications' && $notificationCount > 0): ?>
                        <span class="nav-badge"><?= (int) $notificationCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Logout Button: asks first -->
    <button type="button" class="btn logout-btn" id="logout-btn">
        <i class="bi bi-box-arrow-right me-2"></i> Log Out
    </button>

</aside>

<!-- LOG OUT CONFIRMATION -->
<div class="lo-overlay" id="logout-overlay" hidden>
    <div class="lo-dialog" role="dialog" aria-modal="true"
         aria-labelledby="lo-title" aria-describedby="lo-text">
        <div class="lo-icon">
            <i class="bi bi-box-arrow-right"></i>
        </div>
        <h2 class="lo-title" id="lo-title">Log out?</h2>
        <p class="lo-text" id="lo-text">Are you sure you want to log out of your admin account?</p>
        <div class="lo-actions">
            <button type="button" class="lo-btn lo-btn-cancel" id="logout-cancel">Cancel</button>
            <button type="button" class="lo-btn lo-btn-confirm" id="logout-confirm">Yes, log out</button>
        </div>
    </div>
</div>

<script>
(function () {
    var openBtn = document.getElementById('logout-btn');
    var overlay = document.getElementById('logout-overlay');
    var cancel  = document.getElementById('logout-cancel');
    var confirm = document.getElementById('logout-confirm');

    if (!openBtn || !overlay) { return; }

    /* "Yes" calls this same dashboard page with ?logout=1 (handled at the top of this file),
       then sends you to login.php */
    var LOGOUT_URL = '<?= htmlspecialchars(basename($_SERVER['SCRIPT_NAME']), ENT_QUOTES, 'UTF-8') ?>?logout=1';
    var LOGIN_URL  = 'login.php';

    /* move the popup to <body> so no parent layout can trap or hide it */
    document.body.appendChild(overlay);

    function openPopup() {
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        cancel.focus();
    }

    function closePopup() {
        overlay.hidden = true;
        document.body.style.overflow = '';
        openBtn.focus();
    }

    openBtn.addEventListener('click', openPopup);
    cancel.addEventListener('click', closePopup);

    confirm.addEventListener('click', function () {
        confirm.disabled = true;
        cancel.disabled = true;
        confirm.textContent = 'Logging out...';

        function goToLogin() { window.location.href = LOGIN_URL; }

        if (window.fetch) {
            fetch(LOGOUT_URL, { credentials: 'same-origin' }).then(goToLogin, goToLogin);
        } else {
            window.location.href = LOGOUT_URL;   // old browsers: the server sends them to login.php
        }
    });

    /* click the dark area outside the box to cancel */
    overlay.addEventListener('click', function (ev) {
        if (ev.target === overlay) { closePopup(); }
    });

    document.addEventListener('keydown', function (ev) {
        if (overlay.hidden) { return; }

        if (ev.key === 'Escape') {
            closePopup();
        } else if (ev.key === 'Tab') {
            /* keep keyboard focus inside the popup */
            if (ev.shiftKey && document.activeElement === cancel) {
                ev.preventDefault();
                confirm.focus();
            } else if (!ev.shiftKey && document.activeElement === confirm) {
                ev.preventDefault();
                cancel.focus();
            }
        }
    });
})();
</script>