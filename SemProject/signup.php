<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up</title>
    <link rel="stylesheet" href="assets/style/signup.css">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@400;500;600;700;800&family=Quicksand:wght@400;500;600;700&family=Saira:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>

<body>

    <main class="signup-box">

        <section class="cookie-panel"></section>

        <section class="signup-panel">

            <div class="brand">
                <h1>🍪 Sign Up</h1>
                <p class="signup-subtitle" id="step-title">
                    User Information
                </p>
            </div>

            <div class="step-indicator">
                <span class="step-dot active">1</span>
                <span class="step-line"></span>
                <span class="step-dot">2</span>
                <span class="step-line"></span>
                <span class="step-dot">3</span>
                <span class="step-line"></span>
                <span class="step-dot">4</span>
            </div>

            <form id="signup-form">

                <!-- STEP 1: USER INFORMATION -->
                <div class="form-step active">
                    <div class="input-box">
                        <input class="form-control" type="text" name="username" placeholder="Create Username"
                            autocomplete="username" required>
                    </div>

                    <div class="input-box">
                        <input class="form-control" type="email" name="email" placeholder="Email" autocomplete="email"
                            required>
                    </div>

                    <div class="input-box">
                        <input class="form-control" type="password" id="password" name="password"
                            placeholder="Create Password" minlength="8" required>
                    </div>

                    <div class="input-box">
                        <input class="form-control" type="password" id="confirm-password" name="confirmPassword"
                            placeholder="Confirm Password" required>
                    </div>
                </div>

                <!-- STEP 2: PERSONAL INFORMATION -->
                <div class="form-step">
                    <div class="input-box">
                        <input class="form-control" type="text" name="firstName" placeholder="First Name" required>
                    </div>

                    <div class="input-box">
                        <input class="form-control" type="text" name="middleName" placeholder="Middle Name">
                    </div>

                    <div class="input-box">
                        <input class="form-control" type="text" name="lastName" placeholder="Last Name" required>
                    </div>

                    <div class="input-box">
                        <input class="form-control" type="text" name="extension" placeholder="Extension Name">
                    </div>

                    <div class="input-row">
                        <div class="input-box">
                            <input class="form-control" type="date" id="birthDate" name="birthDate" required>
                        </div>

                        <div class="input-box">
                            <input class="form-control" type="number" id="age" name="age" placeholder="Age" readonly
                                required>
                        </div>
                    </div>

                    <div class="input-box">
                        <input class="form-control" type="tel" name="phone" placeholder="Phone Number" required>
                    </div>
                </div>

                <!-- STEP 3: VALID ID -->
                <div class="form-step">
                    <p class="section-description">Valid ID (Any Government ID)</p>

                    <div class="input-box">
                        <select class="form-control" name="idType" required>
                            <option value="">Select ID Type</option>
                            <option>National ID</option>
                            <option>Passport</option>
                            <option>Driver's License</option>
                            <option>UMID</option>
                            <option>PhilHealth ID</option>
                            <option>Other Government ID</option>
                        </select>
                    </div>

                    <div class="upload-box">
                        <label for="validId" class="upload-label">
                            Upload Valid ID
                        </label>

                        <input type="file" id="validId" name="validId" accept="image/*" required>

                        <img id="idPreview" class="id-preview" alt="Valid ID preview" hidden>
                    </div>
                </div>

                <!-- STEP 4: FACE RECOGNITION -->
                <div class="form-step">
                    <p class="section-description">Face Recognition</p>

                    <div class="camera-circle">
                        <video id="camera" autoplay muted playsinline hidden></video>

                        <img id="facePreview" alt="Captured face" hidden>

                        <span id="cameraPlaceholder">
                            Face Recognition
                        </span>
                    </div>

                    <div class="camera-actions">
                        <button type="button" class="secondary-btn" id="openCamera">Open Camera</button>

                        <button type="button" class="secondary-btn" id="captureFace" disabled>Capture</button>
                    </div>

                    <label class="consent-label">
                        <input type="checkbox" id="consent" required>
                        I consent to face image processing for verification.
                    </label>
                </div>

                <p id="form-message" class="form-message"></p>

                <!-- NAVIGATION -->
                <div class="button-group">
                    <button type="button" class="back-btn" id="backBtn" hidden>Back</button>

                    <button type="button" class="signup-btn" id="nextBtn">Next</button>

                    <button type="submit" class="signup-btn" id="submitBtn" hidden>Confirm</button>
                </div>

                <p class="login-text">
                    Already have an account?
                    <a href="login.php" class="login-link">Log In</a>
                </p>

            </form>
        </section>
    </main>

    <script>
        const form = document.getElementById("signup-form");
        const steps = [...document.querySelectorAll(".form-step")];
        const dots = [...document.querySelectorAll(".step-dot")];
        const titles = [
            "User Information",
            "Personal Information",
            "Valid ID",
            "Face Recognition"
        ];

        let current = 0;
        let stream = null;
        let captured = false;

        function showStep(index) {
            current = index;

            steps.forEach((step, i) =>
                step.classList.toggle("active", i === index)
            );

            dots.forEach((dot, i) =>
                dot.classList.toggle("active", i <= index)
            );

            document.getElementById("step-title").textContent = titles[index];
            document.getElementById("backBtn").hidden = index === 0;
            document.getElementById("nextBtn").hidden = index === 3;
            document.getElementById("submitBtn").hidden = index !== 3;
            document.getElementById("form-message").textContent = "";

            if (index !== 3) stopCamera();
        }

        function validateStep() {
            const fields = steps[current].querySelectorAll(
                "input:not([type=checkbox]), select"
            );

            for (const field of fields) {
                if (!field.checkValidity()) {
                    field.reportValidity();
                    return false;
                }
            }

            if (current === 0 &&
                document.getElementById("password").value !==
                document.getElementById("confirm-password").value) {
                alert("Passwords do not match.");
                return false;
            }

            if (current === 3 && !captured) {
                alert("Please capture your face first.");
                return false;
            }

            return true;
        }

        document.getElementById("nextBtn").onclick = () => {
            if (validateStep()) showStep(current + 1);
        };

        document.getElementById("backBtn").onclick = () => {
            if (current > 0) showStep(current - 1);
        };

        /* Calculate age */
        document.getElementById("birthDate").addEventListener("change", e => {
            const birth = new Date(e.target.value + "T00:00:00");
            const today = new Date();

            let age = today.getFullYear() - birth.getFullYear();

            if (today.getMonth() < birth.getMonth() ||
                (today.getMonth() === birth.getMonth() &&
                    today.getDate() < birth.getDate())) {
                age--;
            }

            document.getElementById("age").value =
                age >= 0 && e.target.value ? age : "";
        });

        /* Preview valid ID */
        document.getElementById("validId").addEventListener("change", e => {
            const file = e.target.files[0];
            const preview = document.getElementById("idPreview");

            if (file) {
                if (file.size > 5 * 1024 * 1024) {
                    alert("Maximum image size is 5 MB.");
                    e.target.value = "";
                    preview.hidden = true;
                    return;
                }

                preview.src = URL.createObjectURL(file);
                preview.hidden = false;
            }
        });

        /* Camera */
        const camera = document.getElementById("camera");

        document.getElementById("openCamera").onclick = async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: {facingMode: "user"},
                    audio: false
                });

                camera.srcObject = stream;
                camera.hidden = false;
                document.getElementById("cameraPlaceholder").hidden = true;
                document.getElementById("captureFace").disabled = false;
            } catch {
                alert("Please allow camera access.");
            }
        };

        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }

            camera.srcObject = null;
            camera.hidden = true;
            document.getElementById("captureFace").disabled = true;
        }

        document.getElementById("captureFace").onclick = () => {
            if (!stream || !camera.videoWidth) return;

            const canvas = document.createElement("canvas");
            canvas.width = camera.videoWidth;
            canvas.height = camera.videoHeight;

            canvas.getContext("2d").drawImage(
                camera, 0, 0, canvas.width, canvas.height
            );

            const preview = document.getElementById("facePreview");
            preview.src = canvas.toDataURL("image/jpeg");
            preview.hidden = false;
            camera.hidden = true;

            document.getElementById("cameraPlaceholder").hidden = true;
            captured = true;
            stopCamera();
        };

        /* Submit registration */
        form.addEventListener("submit", event => {
            event.preventDefault();

            if (!validateStep()) return;

            if (!document.getElementById("consent").checked) {
                alert("Please provide consent.");
                return;
            }

            document.getElementById("form-message").textContent =
                "Registration form completed. Connect your verification API to create the account.";

            stopCamera();
        });

        window.addEventListener("pagehide", stopCamera);

        showStep(0);
    </script>

</body>

</html>