<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// Current page (same default as staffdash.php)
$currentPage = $_GET['page'] ?? 'dashboard';

// Page key => breadcrumb label
$pageTitles = [
    'dashboard'   => 'Dashboard',
    'inventory'   => 'Inventory Management',
    'stocks'      => 'Stocking Records',
    'transaction' => 'Transaction',
];
$pageTitle = $pageTitles[$currentPage] ?? 'Dashboard';

$homeUrl = 'staffdash.php?page=dashboard';

// Employee identity = the username typed at sign-up (users table).
// See database/employees_account.php
$employee       = require __DIR__ . '/../../database/employees_account.php';
$displayName    = $employee['username'];
$displayRole    = $employee['role'];
$profilePicture = $employee['picture'];

// Initials for the avatar circle, used when there is no picture
$initials = strtoupper(function_exists('mb_substr')
    ? mb_substr($displayName, 0, 2)
    : substr($displayName, 0, 2));
?>

<header class="topbar">

    <!-- Breadcrumb -->
    <nav class="topbar-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">Home</a>
        <span class="sep">/</span>
        <span class="current"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>
    </nav>

    <!-- User -->
    <div class="user-info">
        <div class="user-profile">
            <div class="profile-img<?= $profilePicture !== '' ? ' has-photo' : '' ?>">
                <?php if ($profilePicture !== ''): ?>
                    <img src="<?= htmlspecialchars($profilePicture, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php else: ?>
                    <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
            <div class="user-text">
                <span class="username"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="user-role"><?= htmlspecialchars($displayRole, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </div>
</header>

<style>
    /* ---------- profile picture in the top bar ---------- */
    .topbar .profile-img.has-photo {
        overflow: hidden;
        padding: 0;
    }

    .topbar .profile-img img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
    }
</style>