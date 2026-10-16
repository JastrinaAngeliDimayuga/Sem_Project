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

    <!-- Face recognition library (runs in the browser) -->
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.js"></script>

    <style>
        .verify-status { font-size: .9rem; margin: 8px 0 0; min-height: 1.3em; text-align: center; }
        .verify-status.info { color: #6c757d; }
        .verify-status.ok { color: #198754; font-weight: 600; }
        .verify-status.error { color: #dc3545; font-weight: 600; }
        .id-preview, #facePreview { max-width: 100%; }
        .id-preview { max-height: 180px; margin-top: 10px; border-radius: 8px; }

        .liveness-wrap { position: relative; width: 240px; height: 240px; margin: 12px auto; }
        .liveness-ring { position: absolute; inset: 0; width: 100%; height: 100%; }
        .ring-track { fill: none; stroke: #e5e7eb; stroke-width: 8; transition: stroke .3s; }
        .ring-progress {
            fill: none; stroke: #22c55e; stroke-width: 8; stroke-linecap: round;
            stroke-dasharray: 703.7; stroke-dashoffset: 703.7;
            transform: rotate(-90deg); transform-origin: 120px 120px;
            transition: stroke-dashoffset .45s ease, stroke .3s;
        }
        .liveness-wrap.scanning .ring-track { stroke: #bbf7d0; }
        .liveness-wrap.success .ring-track { stroke: #22c55e; }
        .liveness-wrap.error .ring-track { stroke: #fecaca; }
        .liveness-wrap.error .ring-progress { stroke: #dc3545; stroke-dashoffset: 0; }
        .liveness-circle {
            position: absolute; inset: 16px; border-radius: 50%; overflow: hidden;
            background: #f1f3f5; display: flex; align-items: center; justify-content: center;
            text-align: center; color: #6c757d; font-size: .85rem;
        }
        .liveness-circle video, .liveness-circle img {
            width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1);
        }
        .liveness-check {
            position: absolute; right: 6px; bottom: 6px; width: 46px; height: 46px; border-radius: 50%;
            background: #22c55e; color: #fff; font-size: 26px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .25);
        }
        .liveness-check[hidden] { display: none; }
        .live-instruction { text-align: center; font-weight: 700; font-size: 1.05rem; margin: 6px 0 0; min-height: 1.5em; }
        .live-chips { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin-top: 8px; }
        .live-chip { font-size: .75rem; padding: 3px 10px; border-radius: 999px; background: #e9ecef; color: #6c757d; }
        .live-chip.current { background: #fff3cd; color: #664d03; }
        .live-chip.done { background: #d1e7dd; color: #0f5132; }
    </style>
</head>

<body>

    <main class="signup-box">

        <section class="cookie-panel"></section>

        <section class="signup-panel">

            <div class="brand">
                <h1>🍪 Sign Up</h1>
                <p class="signup-subtitle" id="step-title">User Information</p>
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
                        <input class="form-control" type="email" name="email" placeholder="Email"
                            autocomplete="email" required>
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
                        <label for="validId" class="upload-label">Upload Valid ID (clear photo of your face on the ID)</label>
                        <input type="file" id="validId" name="validId" accept="image/*" required>
                        <img id="idPreview" class="id-preview" alt="Valid ID preview" hidden>
                        <p class="verify-status info" id="idStatus"></p>
                    </div>

                </div>

                <!-- STEP 4: FACE VERIFICATION (liveness) -->
                <div class="form-step">
                    <p class="section-description">Face Verification</p>

                    <div class="liveness-wrap" id="livenessWrap">
                        <svg class="liveness-ring" viewBox="0 0 240 240" aria-hidden="true">
                            <circle class="ring-track" cx="120" cy="120" r="112"></circle>
                            <circle class="ring-progress" id="ringProgress" cx="120" cy="120" r="112"></circle>
                        </svg>
                        <div class="liveness-circle">
                            <video id="camera" autoplay muted playsinline hidden></video>
                            <img id="facePreview" alt="Captured face" hidden>
                            <span id="cameraPlaceholder">Tap “Start” to begin</span>
                        </div>
                        <div class="liveness-check" id="livenessCheck" hidden>✓</div>
                    </div>

                    <p class="live-instruction" id="liveInstruction">Get ready to verify your face</p>
                    <div class="live-chips" id="liveChips"></div>
                    <p class="verify-status info" id="faceStatus"></p>

                    <div class="camera-actions">
                        <button type="button" class="secondary-btn" id="startVerify">Start Face Verification</button>
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
        /* ================= CONFIG ================= */
        const REGISTER_URL = "register.php";   // your backend endpoint
        const MATCH_THRESHOLD = 0.5;           // lower = stricter (0.6 is face-api default)
        const LIVENESS = {
            turnRight: 0.38,    // nose position ratio below this = turned right
            turnLeft: 0.62,     // nose position ratio above this = turned left
            centerMin: 0.43,    // "looking straight" range
            centerMax: 0.57,
            blinkRatio: 0.75,   // eye closes below 75% of its open size = blink
            holdMs: 700,        // how long to hold still when looking straight
            stepTimeout: 15000  // ms allowed per step
        };
        const MODEL_URL = "https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model/";

        /* ================= STATE ================= */
        const form = document.getElementById("signup-form");
        const steps = [...document.querySelectorAll(".form-step")];
        const dots = [...document.querySelectorAll(".step-dot")];
        const titles = [
            "User Information",
            "Personal Information",
            "Valid ID",
            "Face Verification"
        ];
        const LAST = steps.length - 1;

        let current = 0;
        let stream = null;
        let captured = false;
        let faceVerified = false;
        let faceBlob = null;
        let faceScores = {};
        let idDescriptor = null;
        let busy = false;

        /* ================= MODEL LOADING ================= */
        const modelsPromise = Promise.all([
            faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL),
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
        ]).then(() => true).catch(() => false);

        function setStatus(id, text, type = "info") {
            const el = document.getElementById(id);
            el.textContent = text;
            el.className = "verify-status " + type;
        }

        async function getDescriptor(source) {
            const result = await faceapi
                .detectSingleFace(source, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 }))
                .withFaceLandmarks()
                .withFaceDescriptor();
            return result ? result.descriptor : null;
        }

        /* ================= STEPS ================= */
        function showStep(index) {
            current = index;

            steps.forEach((step, i) => step.classList.toggle("active", i === index));
            dots.forEach((dot, i) => dot.classList.toggle("active", i <= index));

            document.getElementById("step-title").textContent = titles[index];
            document.getElementById("backBtn").hidden = index === 0;
            document.getElementById("nextBtn").hidden = index === LAST;
            document.getElementById("submitBtn").hidden = index !== LAST;
            document.getElementById("form-message").textContent = "";

            if (index !== LAST) stopCamera();
        }

        function validateStep() {
            const fields = steps[current].querySelectorAll("input:not([type=checkbox]), select");

            for (const field of fields) {
                if (!field.checkValidity()) {
                    field.reportValidity();
                    return false;
                }
            }

            if (busy) {
                alert("Please wait, the image is still being analyzed.");
                return false;
            }

            if (current === 0 &&
                document.getElementById("password").value !==
                document.getElementById("confirm-password").value) {
                alert("Passwords do not match.");
                return false;
            }

            if (current === 2 && !idDescriptor) {
                alert("We could not read a face from your Valid ID. Please upload a clearer photo.");
                return false;
            }

            if (current === LAST) {
                if (!faceVerified) {
                    alert("Please complete the face verification first.");
                    return false;
                }
            }

            return true;
        }

        document.getElementById("nextBtn").onclick = () => {
            if (validateStep()) showStep(current + 1);
        };

        document.getElementById("backBtn").onclick = () => {
            if (current > 0) showStep(current - 1);
        };

        /* ================= AGE ================= */
        const birthInput = document.getElementById("birthDate");
        birthInput.max = new Date().toISOString().split("T")[0];

        birthInput.addEventListener("change", e => {
            const birth = new Date(e.target.value + "T00:00:00");
            const today = new Date();

            let age = today.getFullYear() - birth.getFullYear();

            if (today.getMonth() < birth.getMonth() ||
                (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) {
                age--;
            }

            document.getElementById("age").value = age >= 0 && e.target.value ? age : "";
        });

        /* ================= ID UPLOADS (extract face from ID) ================= */
        function resetFace() {
            stopCamera();
            captured = false;
            faceVerified = false;
            faceBlob = null;
            faceScores = {};
            document.getElementById("facePreview").hidden = true;
            document.getElementById("cameraPlaceholder").hidden = false;
            document.getElementById("livenessWrap").className = "liveness-wrap";
            document.getElementById("livenessCheck").hidden = true;
            document.getElementById("ringProgress").style.strokeDashoffset = RING_LENGTH;
            document.getElementById("liveInstruction").textContent = "Get ready to verify your face";
            document.getElementById("liveChips").innerHTML = "";
            const btn = document.getElementById("startVerify");
            btn.textContent = "Start Face Verification";
            btn.disabled = false;
            btn.hidden = false;
            setStatus("faceStatus", "", "info");
        }

        function setupIdUpload({ inputId, previewId, statusId, label, onDescriptor }) {
            document.getElementById(inputId).addEventListener("change", async e => {
                const file = e.target.files[0];
                const preview = document.getElementById(previewId);

                onDescriptor(null);
                resetFace();

                if (!file) {
                    preview.hidden = true;
                    setStatus(statusId, "", "info");
                    return;
                }

                if (file.size > 5 * 1024 * 1024) {
                    alert("Maximum image size is 5 MB.");
                    e.target.value = "";
                    preview.hidden = true;
                    setStatus(statusId, "", "info");
                    return;
                }

                preview.src = URL.createObjectURL(file);
                preview.hidden = false;

                busy = true;
                setStatus(statusId, "Analyzing " + label + "...", "info");

                try {
                    const ready = await modelsPromise;
                    if (!ready) throw new Error("models");

                    await preview.decode();
                    const descriptor = await getDescriptor(preview);

                    if (descriptor) {
                        onDescriptor(descriptor);
                        setStatus(statusId, "Face detected on " + label + " ✓", "ok");
                    } else {
                        setStatus(statusId,
                            "No face detected. Upload a clear, well-lit, uncropped photo of the ID.", "error");
                    }
                } catch {
                    setStatus(statusId,
                        "Face recognition could not load. Check your internet connection and refresh.", "error");
                } finally {
                    busy = false;
                }
            });
        }

        setupIdUpload({
            inputId: "validId", previewId: "idPreview", statusId: "idStatus",
            label: "Valid ID", onDescriptor: d => (idDescriptor = d)
        });


        /* ================= LIVENESS FACE VERIFICATION ================= */
        const RING_LENGTH = 2 * Math.PI * 112;
        const camera = document.getElementById("camera");
        const wrap = document.getElementById("livenessWrap");
        const startBtn = document.getElementById("startVerify");
        const LABELS = {center: "Look straight", right: "Turn right", left: "Turn left", blink: "Blink"};
        const INSTRUCTIONS = {
            center: "Look straight at the camera",
            right: "Slowly turn your head to the RIGHT ➡️",
            left: "Now slowly turn your head to the LEFT ⬅️",
            blink: "Look straight and BLINK 👁️"
        };

        let session = 0;   // changes whenever a verification run is cancelled

        const nextFrame = () => new Promise(r => requestAnimationFrame(r));

        function setRing(fraction) {
            document.getElementById("ringProgress").style.strokeDashoffset = RING_LENGTH * (1 - fraction);
        }

        function renderChips(stages, done) {
            document.getElementById("liveChips").innerHTML = stages.map((st, i) =>
                `<span class="live-chip ${i < done ? "done" : i === done ? "current" : ""}">${i < done ? "✓ " : ""}${LABELS[st]}</span>`
            ).join("");
        }

        function stopCamera() {
            session++;
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            camera.srcObject = null;
            camera.hidden = true;
        }

        async function openCamera() {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {facingMode: "user", width: {ideal: 640}, height: {ideal: 480}},
                audio: false
            });
            camera.srcObject = stream;
            camera.hidden = false;
            document.getElementById("cameraPlaceholder").hidden = true;
            document.getElementById("facePreview").hidden = true;
            await camera.play().catch(() => {});
            while (!camera.videoWidth) await nextFrame();
        }

        function snapshot() {
            const canvas = document.createElement("canvas");
            canvas.width = camera.videoWidth;
            canvas.height = camera.videoHeight;
            canvas.getContext("2d").drawImage(camera, 0, 0, canvas.width, canvas.height);
            return canvas;
        }

        const dist2d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

        function eyeRatio(p) {   // eye aspect ratio: smaller = more closed
            return (dist2d(p[1], p[5]) + dist2d(p[2], p[4])) / (2 * dist2d(p[0], p[3]));
        }

        function measure(det) {
            const pts = det.landmarks.positions;
            const nose = pts[30], jawA = pts[0], jawB = pts[16];
            return {
                // ~0.5 = straight; < 0.5 = user's right; > 0.5 = user's left
                ratio: (nose.x - jawA.x) / (jawB.x - jawA.x),
                ear: (eyeRatio(pts.slice(36, 42)) + eyeRatio(pts.slice(42, 48))) / 2,
                box: det.detection.box
            };
        }

        async function waitFor(id, check) {
            const start = performance.now();
            const options = new faceapi.TinyFaceDetectorOptions({inputSize: 320, scoreThreshold: 0.5});

            while (true) {
                if (id !== session) throw new Error("cancelled");
                if (performance.now() - start > LIVENESS.stepTimeout) throw new Error("timeout");

                const det = await faceapi.detectSingleFace(camera, options).withFaceLandmarks();
                if (id !== session) throw new Error("cancelled");

                if (!det) {
                    setStatus("faceStatus", "No face detected. Stay inside the circle.", "info");
                } else {
                    const m = measure(det);
                    const w = m.box.width / camera.videoWidth;
                    const cx = (m.box.x + m.box.width / 2) / camera.videoWidth;

                    if (w < 0.22) setStatus("faceStatus", "Move a little closer.", "info");
                    else if (w > 0.8) setStatus("faceStatus", "Move a little farther.", "info");
                    else if (cx < 0.25 || cx > 0.75) setStatus("faceStatus", "Center your face in the circle.", "info");
                    else {
                        setStatus("faceStatus", "", "info");
                        if (check(m)) return m;
                    }
                }
                await nextFrame();
            }
        }

        async function runLiveness(id) {
            const challenges = ["right", "left", "blink"].sort(() => Math.random() - 0.5);
            const stages = ["center", ...challenges];
            let faceCanvas = null;
            let baseline = 0;

            setRing(0);

            for (let i = 0; i < stages.length; i++) {
                const stage = stages[i];
                renderChips(stages, i);
                document.getElementById("liveInstruction").textContent = INSTRUCTIONS[stage];

                let holdStart = 0, closed = false;
                const earSamples = [];

                const checks = {
                    center: m => {
                        if (m.ratio < LIVENESS.centerMin || m.ratio > LIVENESS.centerMax) {
                            holdStart = 0; earSamples.length = 0;
                            return false;
                        }
                        if (!holdStart) holdStart = performance.now();
                        earSamples.push(m.ear);
                        return performance.now() - holdStart >= LIVENESS.holdMs;
                    },
                    right: m => m.ratio < LIVENESS.turnRight,
                    left: m => m.ratio > LIVENESS.turnLeft,
                    blink: m => {
                        if (m.ratio < 0.35 || m.ratio > 0.65) return false;   // face the camera while blinking
                        if (m.ear < baseline * LIVENESS.blinkRatio) closed = true;
                        else if (closed && m.ear > baseline * 0.85) return true;
                        return false;
                    }
                };

                await waitFor(id, checks[stage]);

                if (stage === "center") {
                    earSamples.sort((a, b) => a - b);
                    baseline = earSamples[Math.floor(earSamples.length * 0.75)] || 0.3;

                    // compare the straight-on face with the uploaded Valid ID
                    document.getElementById("liveInstruction").textContent = "Checking your face...";
                    faceCanvas = snapshot();
                    const live = await getDescriptor(faceCanvas);
                    if (id !== session) throw new Error("cancelled");
                    if (!live) throw new Error("noface");

                    const d = faceapi.euclideanDistance(live, idDescriptor);
                    faceScores = {idDistance: d};
                    if (d > MATCH_THRESHOLD) throw new Error("mismatch");
                }

                setRing((i + 1) / stages.length);
            }

            renderChips(stages, stages.length);

            // success: ring is fully green
            faceBlob = await new Promise(res => faceCanvas.toBlob(res, "image/jpeg", 0.92));
            document.getElementById("facePreview").src = faceCanvas.toDataURL("image/jpeg", 0.92);
            stopCamera();
            document.getElementById("facePreview").hidden = false;

            captured = true;
            faceVerified = true;
            wrap.className = "liveness-wrap success";
            document.getElementById("livenessCheck").hidden = false;
            document.getElementById("liveInstruction").textContent = "Verification complete!";
            setStatus("faceStatus", "Face verified ✓ You can now press Confirm.", "ok");
            startBtn.hidden = true;
        }

        startBtn.onclick = async () => {
            if (!idDescriptor) {
                alert("Please upload your Valid ID with a visible face first.");
                return;
            }

            resetFace();
            startBtn.disabled = true;
            startBtn.textContent = "Verifying...";

            let ok = false;
            try {
                if (!(await modelsPromise)) throw new Error("models");
                await openCamera();
                const id = session;
                wrap.className = "liveness-wrap scanning";
                await runLiveness(id);
                ok = true;
            } catch (err) {
                if (err.message === "cancelled") return;

                stopCamera();
                wrap.className = "liveness-wrap error";
                document.getElementById("cameraPlaceholder").hidden = false;
                document.getElementById("liveInstruction").textContent = "Verification failed";

                const messages = {
                    timeout: "Time's up. Please try again and follow the instructions.",
                    mismatch: "Your face does not match your Valid ID. Try again or upload a clearer ID.",
                    noface: "We couldn't read your face. Try again in good lighting.",
                    models: "Face recognition could not load. Check your connection and refresh."
                };
                setStatus("faceStatus",
                    messages[err.message] || "Please allow camera access and try again.", "error");
            } finally {
                if (!ok) {
                    startBtn.disabled = false;
                    startBtn.textContent = "Try Again";
                }
            }
        };

        /* ================= SUBMIT ================= */
        form.addEventListener("submit", async event => {
            event.preventDefault();

            if (!validateStep()) return;

            if (!document.getElementById("consent").checked) {
                alert("Please provide consent.");
                return;
            }

            const message = document.getElementById("form-message");
            const submitBtn = document.getElementById("submitBtn");

            const data = new FormData(form);          // includes the validId file
            data.append("faceImage", faceBlob, "face.jpg");
            data.append("idFaceDistance", faceScores.idDistance);

            submitBtn.disabled = true;
            message.textContent = "Creating your account...";

            try {
                const res = await fetch(REGISTER_URL, { method: "POST", body: data });
                const result = await res.json().catch(() => ({}));

                if (res.ok && result.success !== false) {
                    message.textContent = "Account created! Redirecting to login...";
                    setTimeout(() => (window.location.href = "login.php"), 1500);
                } else {
                    message.textContent = result.message || "Registration failed. Please try again.";
                    submitBtn.disabled = false;
                }
            } catch {
                message.textContent = "Could not reach the server. Please try again.";
                submitBtn.disabled = false;
            }
        });

        window.addEventListener("pagehide", stopCamera);

        showStep(0);
    </script>

</body>

</html>