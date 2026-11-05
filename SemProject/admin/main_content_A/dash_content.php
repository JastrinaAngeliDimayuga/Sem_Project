<!DOCTYPE html>
<html lang="en">

<head>

    <link rel="stylesheet" href="admin/assets/dashboard.css">

</head>

<body>

    <!-- Dashboard Header -->
    <div class="inventory-header">

        <h1 class="page-heading">Dashboard</h1>

        <!-- Inventory Tabs -->
        <ul class="nav inventory-tabs" role="tablist">

            <li class="nav-item" role="presentation">
                <a
                    class="nav-link active"
                    href="#ingredients"
                    id="ingredientsTab"
                    role="tab"
                    aria-controls="ingredientsTable"
                    aria-selected="true">
                    Ingredients
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>

            <li class="nav-item" role="presentation">
                <a
                    class="nav-link"
                    href="#cookies"
                    id="cookiesTab"
                    role="tab"
                    aria-controls="cookiesTable"
                    aria-selected="false">
                    Cookies
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>

            <li class="nav-item" role="presentation">
                <a
                    class="nav-link"
                    href="#supplies"
                    id="suppliesTab"
                    role="tab"
                    aria-controls="suppliesTable"
                    aria-selected="false">
                    Supplies
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>

        </ul>

    </div>

    <!-- Inventory Content -->
    <main class="inventory-content">

        <?php include 'dash_content/ingredients_table.php' ?>

        <?php include 'dash_content/cookie_table.php' ?>
        
        <?php include 'dash_content/supplies_table.php'?>

    </main>

    <!-- Tab Functionality -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            const tabs = document.querySelectorAll(
                '.inventory-tabs .nav-link'
            );

            const tables = {
                '#ingredients': document.getElementById('ingredientsTable'),
                '#cookies': document.getElementById('cookiesTable'),
                '#supplies': document.getElementById('suppliesTable')
            };

            function showTable(selectedTab) {

                Object.entries(tables).forEach(function ([key, table]) {

                    if (!table) {
                        return;
                    }

                    table.hidden = key !== selectedTab;

                });

                tabs.forEach(function (tab) {

                    const isActive =
                        tab.getAttribute('href') === selectedTab;

                    tab.classList.toggle('active', isActive);

                    tab.setAttribute(
                        'aria-selected',
                        String(isActive)
                    );

                });

            }

            tabs.forEach(function (tab) {

                tab.addEventListener('click', function (event) {

                    event.preventDefault();

                    const selectedTab = this.getAttribute('href');

                    if (tables[selectedTab]) {
                        showTable(selectedTab);
                    }

                });

            });

            const initialTab = window.location.hash;

            if (tables[initialTab]) {
                showTable(initialTab);
            } else {
                showTable('#ingredients');
            }

        });
    </script>

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>
</html>