<!DOCTYPE html>
<html lang="en">

<head>
    <?php include 'phpfiles/header/admindash_header.php'; ?>
</head>

<body>

    <!-- TOP NAVBAR -->
    <?php include 'phpfiles/navbar_admin/upper_nav.php'; ?>

    <div class="dashboard-layout d-flex">

        <!-- LEFT SIDEBAR (outputs ONLY the <aside>, no wrapper) -->
        <?php include 'phpfiles/navbar_admin/leftside_nav.php'; ?>

        <!-- MAIN CONTENT -->
        <main class="main-content flex-grow-1">

            <?php
            $pages = [
                'dashboard' => 'admin/dashboard.php',
                'employees' => 'admin/EM.php',
                'inventory' => 'admin/IM.php',
                'notifications' => 'admin/notification.php',
                'backup' => 'admin/BDS.php'
            ];

            $page = $_GET['page'] ?? 'dashboard';

            if (isset($pages[$page])) {
                include $pages[$page];
            } else {
                include 'admin/dashboard.php';
            }
            ?>

        </main>

    </div>

    <!-- Bootstrap JavaScript (moved here from the sidebar file) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>