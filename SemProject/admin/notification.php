
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>

    <link rel="stylesheet" href="admin/assets/notifi.css">
</head>
<body>

    <div class="notification-panel">

        <!-- Header -->
        <div class="notification-header">
            Notifications
        </div>

        <!-- Notification Container -->
        <div class="notification-container">

            <!-- Low Stock Notification -->
            <div class="notification-item">
                <div class="notification-icon"></div>

                <div class="notification-content">
                    <p class="notification-title">Low Stock</p>
                    <p class="notification-description">
                        information about the low stock
                    </p>
                </div>

                <span class="notification-date">date</span>
            </div>

            <!-- Other Notifications -->
            <div class="notification-item placeholder-item"></div>
            <div class="notification-item placeholder-item"></div>
            <div class="notification-item placeholder-item"></div>
            <div class="notification-item placeholder-item"></div>

        </div>
    </div>

    <!-- Bootstrap JavaScript -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>
</html>
