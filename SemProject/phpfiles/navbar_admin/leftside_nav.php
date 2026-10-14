<!DOCTYPE html>
<html lang="en">

<body>

<div class="dashboard-layout d-flex">

    <aside class="sidebar p-3 d-flex flex-column">
        <p class="menu-heading mt-3 mb-3 px-2"> ADMIN </p>

        <ul class="nav flex-column">

    <!-- Dashboard Admin -->
            <li class="nav-item">
                <a href="admindash.php?page=dashboard" class="nav-link active">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Dashboard
                </a>
            </li>

    <!-- Employees Management -->
            <li class="nav-item">
                <a href="admindash.php?page=employees" class="nav-link active">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Employees Management
                </a>
            </li>

    <!-- Inventory Management -->
            <li class="nav-item">
                <a href="admindash.php?page=inventory" class="nav-link active">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Inventory Management
                </a>
            </li>
    <!-- Notifiation -->
            <li class="nav-item">
                <a href="admindash.php?page=notifications" class="nav-link active">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Notifications
                </a>
            </li>

    <!-- Backup Data Save -->
            <li class="nav-item">
                <a href="admindash.php?page=backup" class="nav-link active">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Backup Date Save
                </a>
            </li>
        </ul>

    <!-- Logout Button -->
            <button type="button" class="btn logout-btn mt-auto">
                <i class="bi bi-box-arrow-right me-2"> Log Out </i>
            </button>
    </aside>

    <main>
<!-- PHP FUNCTION -->
    </main>

</div>




<!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>