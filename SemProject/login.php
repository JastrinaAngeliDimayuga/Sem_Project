<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BJ4kery | Login</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@400;500;600;700;800&family=Quicksand:wght@400;500;600;700&family=Saira:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/style/login.css">
</head>

<body>

    <main class="login-box">

        <!-- Left cookie background -->
        <div class="cookie-panel" aria-hidden="true"></div>

        <!-- Right login panel -->
        <section class="login-panel">

            <!-- BJ4kery branding ABOVE the username -->
            <div class="brand">
                <h1>🍪 Login</h1>
                <p class="login-subtitle">Welcome back, sweet tooth!</p>
            </div>

            <form id="myForm" novalidate>

                <!-- Username -->
                <div class="input-box">
                    <input
                        type="text"
                        class="form-control"
                        name="username"
                        id="username"
                        placeholder="Username"
                        autocomplete="username">

                    <i class="fa-solid fa-circle-user user-icon"></i>
                </div>

                <!-- Password -->
                <div class="input-box">
                    <input
                        type="password"
                        class="form-control"
                        name="password"
                        id="password"
                        placeholder="Password"
                        autocomplete="current-password">

                    <i class="fa-solid fa-lock pass-icon"></i>
                </div>

                <!-- Forgot Password -->
                <div class="forgot-wrapper">
                    <a href="forgotpassword.php" class="forgot">
                        Forgot Password?
                    </a>
                </div>

                <!-- Error Message -->
                <div id="login-error" role="alert"></div>

                <!-- Login Button -->
                <button type="submit" class="login-btn">
                    Login
                </button>

                <!-- Sign Up -->
                <p class="signup-text">
                    Don't have an account yet?
                    <a href="signup.php" class="signup-link">Sign Up</a>
                </p>

            </form>

        </section>

    </main>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- jQuery Validation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-validate/1.22.1/jquery.validate.min.js"></script>

    <!-- Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        $(document).ready(function () {

            let attempt = 0;

            $("#myForm").validate({

                rules: {
                    username: {
                        required: true,
                        minlength: 5,
                        maxlength: 10
                    },
                    password: {
                        required: true,
                        minlength: 5,
                        maxlength: 10
                    }
                },

                messages: {
                    username: {
                        required: "Please enter your username.",
                        minlength: "Username must be at least 5 characters.",
                        maxlength: "Username must not be more than 10 characters."
                    },
                    password: {
                        required: "Please enter your password.",
                        minlength: "Password must be at least 5 characters.",
                        maxlength: "Password must not be more than 10 characters."
                    }
                },

                errorElement: "span",
                errorClass: "error",

                errorPlacement: function (error, element) {
                    error.insertAfter(element.closest(".input-box"));
                },

                submitHandler: function (form) {

                    const correctUsername = "akarisaki";
                    const correctPassword = "meowaki";

                    const username = $("#username").val();
                    const password = $("#password").val();

                    $("#login-error").text("");

                    if (
                        username === correctUsername &&
                        password === correctPassword
                    ) {
                        window.location.href = "admindash.php";
                    } else {

                        attempt++;

                        if (attempt >= 3) {

                            $("#login-error").text(
                                "Too many failed attempts. Login is locked."
                            );

                            $(".login-btn").prop("disabled", true);
                            $("#username, #password").prop("disabled", true);

                        } else {

                            $("#login-error").text(
                                "Incorrect username or password."
                            );

                        }
                    }

                    return false;
                }

            });

        });
    </script>

</body>
</html>
