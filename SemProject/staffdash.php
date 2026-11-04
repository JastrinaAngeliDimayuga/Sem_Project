<!DOCTYPE html>
<html lang="en">

<head>
    <?php include 'phpfiles/header/staffdash_header.php'; ?>
</head>

<body>

    <!-- TOP NAVBAR -->
    <?php include 'phpfiles/navbar_employees/upper_nav.php'; ?>

    <div class="dashboard-layout d-flex">

        <!-- LEFT SIDEBAR -->
        <?php include 'phpfiles/navbar_employees/leftside_nav.php'; ?>




        <!-- MAIN CONTENT -->
        <main class="main-content flex-grow-1">

            <?php
         
            $pages = [
                'dashboard'     => 'staff/dashboard_e.php',
                'inventory'     => 'staff/IM.php',
                'stocks'        => 'staff/SR.php',
                'transaction'   => 'staff/transaction.php',
            ];

            $page = $_GET['page'] ?? 'dashboard';

            if (isset($pages[$page])) {
                include $pages[$page];
            } else {
                include 'staff/dashboard.php';
            }
            ?>

        </main>

    </div>

</body>
</html>