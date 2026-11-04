<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BJ4kery | Login</title>

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

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/style/login.css">

    <!-- ADDED: styles for face login -->
    <style>
        .or-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            margin: 14px 0 10px;
            color: #745039;
            font-size: 13px;
        }

        .or-divider::before,
        .or-divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #c6a18a;
        }

        .face-btn,
        .face-start {
            display: block;
            width: 100%;
            min-height: 46px;
            padding: 9px 18px;
            color: #755039;
            background: #fff4ed;
            border: 1px solid #b99379;
            border-radius: 8px;
            font-family: 'Quicksand', sans-serif;
            font-size: 17px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color .2s ease, color .2s ease;
        }

        .face-btn:hover,
        .face-start:hover {
            color: #fff;
            background: #80583d;
            border-color: #80583d;
        }

        .face-start:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .face-modal {
            background: #f8cbb5;
            border-radius: 18px;
            border: 1px solid rgba(128, 88, 61, .25);
        }

        .face-modal .modal-title {
            font-family: 'Quicksand', sans-serif;
            font-weight: 700;
            color: #6b4937;
        }

        .cam {
            width: 240px;
            height: 240px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid #c6a18a;
            margin: 6px auto 10px;
            background: #f1f3f5;
            transition: border-color .3s;
        }

        .cam.ok {
            border-color: #22c55e;
        }

        .cam.fail {
            border-color: #dc3545;
        }

        .cam video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
        }

        #faceMsg {
            min-height: 1.6em;
            margin: 8px 0 14px;
            font-weight: 700;
            color: #6b4937;
        }
    </style>
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
                    <input type="text" class="form-control" name="username" id="username" placeholder="Username"
                        autocomplete="username">

                    <i class="fa-solid fa-circle-user user-icon"></i>
                </div>

                <!-- Password -->
                <div class="input-box">
                    <input type="password" class="form-control" name="password" id="password" placeholder="Password"
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

                <!-- ADDED: Face login for employees and managers -->
                <div class="or-divider">or</div>
                <button type="button" class="face-btn" id="openFace">
                    <i class="fa-solid fa-face-smile"></i> Login with Face
                </button>

                <!-- Sign Up -->
                <p class="signup-text">
                    Don't have an account yet?
                    <a href="signup.php" class="signup-link">Sign Up</a>
                </p>

            </form>

        </section>

    </main>

    <!-- ADDED: Face login modal -->
    <div class="modal fade" id="faceModal" tabindex="-1" aria-labelledby="faceTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content face-modal">
                <div class="modal-header border-0">
                    <h5 class="modal-title" id="faceTitle">🍪 Face Login</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="cam" id="cam">
                        <video id="video" autoplay muted playsinline></video>
                    </div>
                    <p id="faceMsg">Opening camera…</p>
                    <button type="button" class="face-start" id="faceStart" disabled>Scan My Face</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- jQuery Validation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-validate/1.22.1/jquery.validate.min.js"></script>

    <!-- Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- ADDED: Face recognition library (runs in the browser) -->
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.js"></script>

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

    <!-- ADDED: Face recognition login (camera opens first, then the face is detected) -->
    <script>
        /* =====================================================================
           WHO CAN LOG IN WITH THEIR FACE
           Put one clear, front-facing photo of each employee / manager in
           assets/faces/ and list it here. The same person can have several
           photos (use the same "name") for better accuracy.
           role must be "employee" or "manager".
           ===================================================================== */
        const KNOWN_FACES = [
            {name: "Manager Name", role: "manager", image: "assets/faces/manager1.jpg"},
            {name: "Employee One", role: "employee", image: "assets/faces/employee1.jpg"},
            {name: "Employee Two", role: "employee", image: "assets/faces/employee2.jpg"}
        ];

        const HOME_PAGE = {                      // where each role goes after a face login
            manager: "managerdash.php",
            employee: "employeedash.php"
        };
        const MAX_DISTANCE = 0.45;               // lower = stricter
        const MIN_GAP = 0.05;                    // best match must beat a different person by this much
        const MAX_FACE_FAILS = 3;                // failed face scans before the button locks
        const MODEL_URL = "https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model/";

        /* ================= ELEMENTS / STATE ================= */
        const faceModalEl = document.getElementById("faceModal");
        const faceModal = new bootstrap.Modal(faceModalEl);
        const video = document.getElementById("video");
        const cam = document.getElementById("cam");
        const faceMsg = document.getElementById("faceMsg");
        const faceStart = document.getElementById("faceStart");

        let stream = null;
        let run = 0;
        let modelsPromise = null;
        let knownPromise = null;
        let faceFails = 0;

        const nextFrame = () => new Promise(r => requestAnimationFrame(r));
        const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
        const ear = p => (dist(p[1], p[5]) + dist(p[2], p[4])) / (2 * dist(p[0], p[3]));

        /* ================= MODELS ================= */
        function loadModels() {
            if (!modelsPromise) {
                modelsPromise = Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
                ]).then(() => true).catch(() => {modelsPromise = null; return false;});
            }
            return modelsPromise;
        }

        /* ================= KNOWN FACES (computed from the photos) ================= */
        function loadKnownFaces() {
            if (!knownPromise) {
                knownPromise = (async () => {
                    const list = [];
                    for (const person of KNOWN_FACES) {
                        try {
                            const img = await faceapi.fetchImage(person.image);
                            const det = await faceapi
                                .detectSingleFace(img, new faceapi.SsdMobilenetv1Options({minConfidence: 0.5}))
                                .withFaceLandmarks()
                                .withFaceDescriptor();
                            if (det) list.push({name: person.name, role: person.role, descriptor: det.descriptor});
                        } catch {
                            /* photo missing or unreadable: skip it */
                        }
                    }
                    return list;
                })();
            }
            return knownPromise;
        }

        /* ================= CAMERA ================= */
        function stopCamera() {
            run++;                               // cancels any scan that is still running
            if (stream) stream.getTracks().forEach(t => t.stop());
            stream = null;
            video.srcObject = null;
        }

        // Opens the camera and shows the live picture right away
        async function openCamera() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error("nocamera");
            }
            stream = await navigator.mediaDevices.getUserMedia({
                video: {facingMode: "user", width: {ideal: 640}, height: {ideal: 480}},
                audio: false
            });
            video.srcObject = stream;
            await video.play().catch(() => { });
            while (!video.videoWidth) await nextFrame();
        }

        /* ================= LIVENESS / DETECTION ================= */
        function measure(det) {
            const p = det.landmarks.positions;
            return {
                ratio: (p[30].x - p[0].x) / (p[16].x - p[0].x),
                ear: (ear(p.slice(36, 42)) + ear(p.slice(42, 48))) / 2
            };
        }

        async function waitFor(id, check) {
            const opt = new faceapi.TinyFaceDetectorOptions({inputSize: 320, scoreThreshold: 0.5});
            const t0 = performance.now();

            while (true) {
                if (id !== run) throw new Error("cancelled");
                if (performance.now() - t0 > 15000) throw new Error("timeout");

                const det = await faceapi.detectSingleFace(video, opt).withFaceLandmarks();
                if (det && check(measure(det))) return;

                await nextFrame();
            }
        }

        // The camera is already open: check the face is live, then return its descriptor
        async function scanFace(id) {
            // 1) look straight
            faceMsg.textContent = "Look straight at the camera";
            let hold = 0;
            await waitFor(id, m => {
                if (m.ratio < 0.43 || m.ratio > 0.57) {hold = 0; return false;}
                if (!hold) hold = performance.now();
                return performance.now() - hold > 700;
            });

            // 2) one random liveness challenge
            const challenge = ["left", "right", "blink"][Math.floor(Math.random() * 3)];

            if (challenge === "blink") {
                faceMsg.textContent = "Now BLINK 👁️";
                const open = [];
                let closed = false, openLevel = 0;

                await waitFor(id, m => {
                    if (open.length < 5) {
                        open.push(m.ear);
                        openLevel = open.reduce((a, b) => a + b) / open.length;
                        return false;
                    }
                    if (!closed) {
                        if (m.ear < openLevel * 0.88) closed = true;
                        return false;
                    }
                    return m.ear > openLevel * 0.94;
                });
            } else {
                faceMsg.textContent = challenge === "right" ? "Turn your head RIGHT ➡️" : "Turn your head LEFT ⬅️";
                await waitFor(id, m => challenge === "right" ? m.ratio < 0.38 : m.ratio > 0.62);
            }

            // 3) look straight again, then capture
            faceMsg.textContent = "Look straight again…";
            await waitFor(id, m => m.ratio > 0.45 && m.ratio < 0.55);

            faceMsg.textContent = "Checking…";
            const det = await faceapi
                .detectSingleFace(video, new faceapi.SsdMobilenetv1Options({minConfidence: 0.5}))
                .withFaceLandmarks()
                .withFaceDescriptor();
            if (!det) throw new Error("noface");

            return det.descriptor;
        }

        /* ================= MATCHING ================= */
        function findMatch(descriptor, known) {
            let best = null, bestDist = Infinity, secondDiffPerson = Infinity;

            for (const f of known) {
                const d = faceapi.euclideanDistance(descriptor, f.descriptor);
                if (d < bestDist) {
                    if (best && best.name !== f.name) secondDiffPerson = Math.min(secondDiffPerson, bestDist);
                    bestDist = d;
                    best = f;
                } else if (best && f.name !== best.name) {
                    secondDiffPerson = Math.min(secondDiffPerson, d);
                }
            }

            if (!best || bestDist > MAX_DISTANCE) return null;
            if (secondDiffPerson - bestDist < MIN_GAP) return null;
            return best;
        }

        /* ================= FLOW: camera first, then detection ================= */
        async function startFlow() {
            if (faceFails >= MAX_FACE_FAILS) {
                faceStart.disabled = true;
                faceMsg.textContent = "Too many failed attempts. Face login is locked.";
                return;
            }

            stopCamera();
            const id = ++run;

            cam.className = "cam";
            faceStart.disabled = true;
            faceStart.textContent = "Scanning…";

            try {
                // 1) open the camera first so the person can see themselves
                faceMsg.textContent = "Opening camera…";
                await openCamera();
                if (id !== run) {stopCamera(); return;}

                // 2) then prepare face recognition (the live picture stays on screen)
                faceMsg.textContent = "Camera ready. Loading face recognition…";
                if (!(await loadModels())) throw new Error("models");
                if (id !== run) return;

                const known = await loadKnownFaces();
                if (id !== run) return;
                if (!known.length) throw new Error("nofaces");

                // 3) detect the face and check it is live
                const descriptor = await scanFace(id);
                if (id !== run) return;

                stopCamera();

                const match = findMatch(descriptor, known);
                if (!match) throw new Error("denied");

                faceFails = 0;
                cam.className = "cam ok";
                faceMsg.textContent = "Welcome, " + match.name + "! Signing you in…";
                setTimeout(() => (window.location.href = HOME_PAGE[match.role]), 800);

            } catch (e) {
                if (e.message === "cancelled" || id !== run) return;

                stopCamera();
                cam.className = "cam fail";

                if (e.message === "denied" || e.message === "noface") faceFails++;

                const name = e.name || "";
                faceMsg.textContent =
                    e.message === "timeout" ? "Timed out. Please try again."
                        : e.message === "noface" ? "No face detected. Try again in good lighting."
                            : e.message === "models" ? "Face recognition could not load. Check your connection."
                                : e.message === "nofaces" ? "No employee/manager photos found. Check KNOWN_FACES and assets/faces/."
                                    : e.message === "denied" ? "Face not recognized."
                                        : (e.message === "nocamera" || name === "NotFoundError" || name === "OverconstrainedError")
                                            ? "No camera was found on this device."
                                            : (name === "NotAllowedError" || name === "SecurityError")
                                                ? "Camera access was blocked. Allow the camera in your browser, then try again."
                                                : name === "NotReadableError" ? "The camera is being used by another app."
                                                    : "Could not open the camera. Please try again.";

                if (faceFails >= MAX_FACE_FAILS) {
                    faceMsg.textContent = "Too many failed attempts. Face login is locked.";
                    faceStart.disabled = true;
                } else {
                    faceStart.disabled = false;
                    faceStart.textContent = "Try Again";
                }
            }
        }

        /* ================= EVENTS ================= */
        document.getElementById("openFace").onclick = () => {
            cam.className = "cam";
            faceStart.textContent = "Scan My Face";
            faceStart.disabled = true;
            faceMsg.textContent = "Opening camera…";
            faceModal.show();
        };

        // the camera opens as soon as the pop-up is visible, then detection starts on its own
        faceModalEl.addEventListener("shown.bs.modal", startFlow);
        faceModalEl.addEventListener("hidden.bs.modal", stopCamera);
        window.addEventListener("pagehide", stopCamera);

        faceStart.onclick = startFlow;
    </script>

</body>

</html>