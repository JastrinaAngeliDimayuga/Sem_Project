<!DOCTYPE html>
<html lang="en">

<head>
    <?php include 'phpfiles/header/staffdash_header.php'; ?>
</head>

<body>

<div class="app-shell">

    <!-- LEFT SIDEBAR -->
    <?php include 'phpfiles/navbar_employees/leftside_nav.php'; ?>

    <div class="main-area">

        <!-- TOP NAVBAR -->
        <?php include 'phpfiles/navbar_employees/upper_nav.php'; ?>

        <!-- MAIN CONTENT -->
        <main class="content-area main-content">

            <?php
            $pages = [
                'dashboard'   => 'staff/dashboard_e.php',
                'inventory'   => 'staff/IM.php',
                'stocks'      => 'staff/SR.php',
                'transaction' => 'staff/transaction.php',
            ];

            $page = $_GET['page'] ?? 'dashboard';

            if (isset($pages[$page])) {
                include $pages[$page];
            } else {
                include $pages['dashboard'];
            }
            ?>

        </main>

    </div>
</div>

</body>
</html>