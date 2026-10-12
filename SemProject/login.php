<DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Cookie Inventory System</title>


    
    <!--BOOTSTRAP LINK -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet">


    <!-- CSS LINK -->
    <link rel="stylesheet" href="assets/css/login.css">
</head>



    <!-- MAIN -->

<body>

    <div class="background-overlay"></div>
    <main class="container min-vh-100 d-flex justify-content-center align-items-center py-4">

        <div class="login-card">
            <h1 class="logo d-flex justify-content-center align-items-start gap-3 text-center">
                <span class="cookie-icon">🍪</span>
                <span>JB4kery</span>
            </h1>

        <form id="loginForm">

            <!-- Username -->
            <div class="mb-3">
            <label for="username" class="form-label"> Username </label>
            <input
                type="text"
                class="form-control"
                id="username"
                name="username"
                placeholder="Enter Username"
                autocomplete="username"
                required>
            </div>

            <!-- Password -->
            <div class="mb-3">
                <label for="password" form="form-label"> Password </label>
            <div class="input-group">
                <input
                type="password"
                class="form-control"
                id="password"
                name="password"
                placeholder="Enter Password"
                autocomplete="current-password"
                required>
            
            <button
                type="button"
                class="btn toggle-password"
                id="togglePassword"
                aria-label="Show Password">
            
            <svg
            viewBox="0 0 24 24"
            aria-hidden="true">

                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                <circle cx="12" cy="12" r="3"/>
            
            </svg>
        </button>

            </div>
            </div>

            <button type="submit" class="btn sign-in-btw w-100">Log In</button>

        </form>

        </div>

    </main>

    <!-- Bootstrap JS -->
    <script src="script.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


</body>