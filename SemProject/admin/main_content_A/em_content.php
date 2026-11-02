
<!DOCTYPE html>
<html lang="en">
<head>

    <link rel="stylesheet" href="admin/assets/em.css">
    
</head>

<body>

    <!-- Top Navigation -->
    <header class="top-header">
        <span>Employees Management</span>
    </header>

    <!-- Navigation Tabs and Search -->
    <section class="dashboard-background">
        <div class="container-fluid px-4">
            <div class="row align-items-center g-3">

                <div class="col-12 col-md">
                    <ul class="nav employee-tabs">
                        
                    <?php include 'em_content/list_nav.php' ?>
                    <?php include 'em_content/active_nav.php' ?>
                    <?php include 'em_content/inactive_nav.php' ?>

                    </ul>
                </div>

                <div class="col-12 col-md-4 col-lg-3">
                    <div class="search-box">
                        <input
                            type="search"
                            id="searchInput"
                            class="form-control"
                            placeholder="Search..."
                            aria-label="Search employees">

                        <i class="bi bi-search"></i>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- Employee Management Panel -->
    <main class="container-fluid dashboard-container">
        <div class="employee-panel">

            <!-- Panel Heading -->
            <div class="d-flex justify-content-between
                align-items-center flex-wrap gap-2 panel-header">

                <h2 class="brand-title">B4jkery</h2>

                <button class="btn add-employee-btn"
                    type="button"
                    id="addEmployeeBtn">

                    <i class="bi bi-plus-lg"></i>
                    Add Employee
                </button>
            </div>

            <!-- Employee Table -->
            <div class="table-responsive">
                <table class="table employee-table mb-0">

                    <thead>
                        <tr>
                            <th scope="col">Status</th>
                            <th scope="col">Username</th>
                            <th scope="col">Full Name</th>
                            <th scope="col">Email</th>
                            <th scope="col" class="text-center">
                                Actions
                            </th>
                        </tr>
                    </thead>
                        <?php include 'em_content/edit_delete.php' ?>
                </table>
            </div>

        </div>
    </main>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Edit Employee
        function editEmployee(button) {
            const row = button.closest("tr");
            const cells = row.querySelectorAll("td");

            const username = cells[1].textContent.trim();
            const fullName = cells[2].textContent.trim();
            const email = cells[3].textContent.trim();

            const newUsername = prompt(
                "Enter username:", username
            );

            if (newUsername === null) return;

            const newName = prompt(
                "Enter full name:", fullName
            );

            if (newName === null) return;

            const newEmail = prompt(
                "Enter email:", email
            );

            if (newEmail === null) return;

            if (
                !newUsername.trim() ||
                !newName.trim() ||
                !newEmail.trim()
            ) {
                alert("All fields are required.");
                return;
            }

            if (
                !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
                    newEmail.trim()
                )
            ) {
                alert("Please enter a valid email address.");
                return;
            }

            cells[1].textContent = newUsername.trim();
            cells[2].textContent = newName.trim();
            cells[3].textContent = newEmail.trim();

            alert("Employee updated successfully!");
            filterEmployees();
        }

        // Delete Employee
        function deleteEmployee(button) {
            const row = button.closest("tr");
            const username = row.cells[1].textContent.trim();

            const confirmed = confirm(
                "Are you sure you want to delete " +
                username + "?"
            );

            if (confirmed) {
                row.remove();
                alert("Employee deleted successfully!");
            }
        }

        // Search and filter employees
        const searchInput =
            document.getElementById("searchInput");

        const filterButtons =
            document.querySelectorAll("[data-filter]");

        let currentFilter = "all";

        function filterEmployees() {
            const searchTerm =
                searchInput.value.toLowerCase().trim();

            const rows = document.querySelectorAll(
                "#employeeTableBody tr"
            );

            rows.forEach(function(row) {
                const text =
                    row.textContent.toLowerCase();

                const status = row.querySelector(".status");

                const isActive =
                    status.classList.contains("active");

                const matchesSearch =
                    text.includes(searchTerm);

                const matchesFilter =
                    currentFilter === "all" ||
                    (currentFilter === "active" && isActive) ||
                    (currentFilter === "inactive" && !isActive);

                row.style.display =
                    matchesSearch && matchesFilter
                        ? ""
                        : "none";
            });
        }

        searchInput.addEventListener(
            "input",
            filterEmployees
        );

        filterButtons.forEach(function(button) {
            button.addEventListener("click", function() {
                filterButtons.forEach(function(item) {
                    item.classList.remove("active");
                });

                this.classList.add("active");
                currentFilter = this.dataset.filter;

                filterEmployees();
            });
        });
    </script>

</body>
</html>
