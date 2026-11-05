<!DOCTYPE html>
<html lang="en">

<body>

<div class="dashboard-layout d-flex">

    <aside class="sidebar p-3 d-flex flex-column">
        <p class="menu-heading mt-3 mb-3 px-2"> EMPLOYEES </p>

        <ul class="nav flex-column">

    <!-- Dashboard Staff -->
            <li class="nav-item">
                <a href="staffdash.php?page=dashboard" class="nav-link active">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Dashboard
                </a>
            </li>

    <!-- Inventory Management -->
            <li class="nav-item">
                <a href="staffdash.php?page=inventory" class="nav-link active">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Inventory Management
                </a>
            </li>

    <!-- Stocking Records -->
            <li class="nav-item">
                <a href="staffdash.php?page=stocks" class="nav-link active">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Stocking Records
                </a>
            </li>
    <!-- Transaction -->
            <li class="nav-item">
                <a href="staffdash.php?page=transaction" class="nav-link active">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Transaction
                </a>
            </li>

    <!-- Logout Button -->
            <button type="button" class="btn logout-btn mt-auto">
                <i class="bi bi-box-arrow-right me-2"> Log Out </i>
            </button>
    </aside>

</div>




<!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>