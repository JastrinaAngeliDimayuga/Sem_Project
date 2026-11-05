<?php
// Current page (same default as admindash.php)
$currentPage = $_GET['page'] ?? 'dashboard';

// Menu items: page key => label
$menuItems = [
    'dashboard' => 'Dashboard',
    'employees' => 'Employees Management',
    'inventory' => 'Inventory Management',
    'notifications' => 'Notifications',
    'backup' => 'Backup Date Save',
];
?>

<aside class="sidebar p-3 d-flex flex-column">

    <p class="menu-heading mt-3 mb-3 px-2">ADMIN</p>

    <ul class="nav flex-column">
        <?php foreach ($menuItems as $key => $label): ?>
            <li class="nav-item">
                <a href="admindash.php?page=<?= $key ?>" class="nav-link<?= $currentPage === $key ? ' active' : '' ?>">
                    <i class="bi bi-speedometer2 me-2"></i>
                    <?= $label ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Logout Button -->
    <button type="button" class="btn logout-btn mt-auto">
        <i class="bi bi-box-arrow-right me-2"></i> Log Out
    </button>

</aside>