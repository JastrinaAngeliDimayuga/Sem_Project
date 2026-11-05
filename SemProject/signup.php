<?php
/* ============================================================
   signup.php  -  page + all the sign-up logic that can run on the server
   (PostgreSQL version: needs db.php and schema.sql)
   ============================================================ */

require_once __DIR__ . '/signupdb.php';

const MATCH_THRESHOLD = 0.5;   // lower = stricter (0.6 is face-api default)

/* ---------- ID TYPE DETECTION ----------
   Keywords are written in plain capitals; spaces and punctuation
   are ignored when matching. Edit these lists if a real ID of
   yours is not being recognized. */
const ID_KEYWORDS = [
    "National ID" => [
        "PHILIPPINE IDENTIFICATION CARD",
        "PHILIPPINE IDENTIFICATION",
        "PAMBANSANG PAGKAKAKILANLAN",
        "PAGKAKAKILANLAN",
        "PHILSYS",
        "PHILIPPINE STATISTICS AUTHORITY",
    ],
    "Passport" => [
        "PASSPORT",
        "PASAPORTE",
        "DEPARTMENT OF FOREIGN AFFAIRS",
    ],
    "Driver's License" => [
        "DRIVERS LICENSE",
        "DRIVER LICENSE",
        "LAND TRANSPORTATION OFFICE",
        "LAND TRANSPORTATION",
        "NON PROFESSIONAL",
        "LICENSE NO",
    ],
    "UMID" => [
        "UNIFIED MULTI PURPOSE",
        "UNIFIED MULTIPURPOSE",
        "UMID",
        "SOCIAL SECURITY SYSTEM",
        "GOVERNMENT SERVICE INSURANCE SYSTEM",
    ],
    "PhilHealth ID" => [
        "PHILHEALTH",
        "PHILIPPINE HEALTH INSURANCE CORPORATION",
        "PHILIPPINE HEALTH INSURANCE",
    ],
];

// words that show up on school IDs (these are not accepted as a government ID)
const STUDENT_KEYWORDS = [
    "STUDENT",
    "UNIVERSITY",
    "COLLEGE",
    "SCHOOL",
    "ACADEMY",
    "INSTITUTE",
    "ENROLLED",
];

// extra words used only for "Other Government ID"
const OTHER_GOV_KEYWORDS = [
    "REPUBLIC OF THE PHILIPPINES",
    "REPUBLIKA NG PILIPINAS",
    "PROFESSIONAL REGULATION COMMISSION",
    "COMMISSION ON ELECTIONS",
    "BUREAU OF INTERNAL REVENUE",
    "NATIONAL BUREAU OF INVESTIGATION",
    "POSTAL IDENTITY",
    "PAG IBIG",
    "PERSON WITH DISABILITY",
    "SENIOR CITIZEN",
];

function normText(string $s): string
{
    return preg_replace('/[^A-Z0-9<]/', '', strtoupper($s));
}

// approximate substring search: allows ~20% OCR mistakes in longer keywords
function fuzzyIncludes(string $text, string $keyword): bool
{
    if (strpos($text, $keyword) !== false)
        return true;
    if (strlen($keyword) < 8)
        return false;

    $m = strlen($keyword);
    $maxErr = (int) floor($m * 0.2);
    $n = strlen($text);
    $prev = range(0, $m);

    for ($i = 1; $i <= $n; $i++) {
        $curr = [0];
        for ($j = 1; $j <= $m; $j++) {
            $cost = $text[$i - 1] === $keyword[$j - 1] ? 0 : 1;
            $curr[$j] = min($prev[$j] + 1, $curr[$j - 1] + 1, $prev[$j - 1] + $cost);
        }
        if ($curr[$m] <= $maxErr)
            return true;
        $prev = $curr;
    }
    return false;
}

function countHits(string $text, array $list): int
{
    $found = 0;
    foreach ($list as $k) {
        if (fuzzyIncludes($text, normText($k)))
            $found++;
    }
    return $found;
}

function analyzeIdText(string $raw): array
{
    $text = normText($raw);
    $scores = [];

    foreach (ID_KEYWORDS as $type => $words)
        $scores[$type] = countHits($text, $words);

    // a passport also has a machine-readable line like P<PHL...
    $mrz = strtoupper(preg_replace('/\s/u', '', $raw));
    if (preg_match('/P[<«][A-Z]{3}/u', $mrz))
        $scores["Passport"] += 2;

    return [
        "scores" => $scores,
        "student" => countHits($text, STUDENT_KEYWORDS),
        "otherGov" => countHits($text, OTHER_GOV_KEYWORDS),
    ];
}

/** Returns [ok(bool), message(string)] */
function checkIdType(string $selected, string $idText): array
{
    if ($selected === "")
        return [false, "Select the ID type above."];

    $a = analyzeIdText($idText);
    $scores = $a["scores"];
    $student = $a["student"];
    $otherGov = $a["otherGov"];

    // best matching known type
    $bestType = null;
    $bestScore = 0;
    foreach ($scores as $type => $score) {
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestType = $type;
        }
    }

    if ($selected === "Other Government ID") {
        $anyGov = $bestScore > 0 || $otherGov > 0;
        if ($student > 0 && !$anyGov) {
            return [false, "This looks like a school or student ID, which is not accepted."];
        }
        if ($anyGov)
            return [true, "Government ID detected ✓"];
        return [false, "We could not recognize this as a government ID. Upload a clear, uncropped photo of the front."];
    }

    $own = $scores[$selected] ?? 0;

    if ($own > 0 && $own >= $bestScore)
        return [true, $selected . " detected ✓"];

    if ($bestType && $bestScore > $own) {
        return [
            false,
            "This looks like a " . $bestType . ", not a " . $selected .
            ". Upload the correct ID or change the ID type."
        ];
    }
    if ($student > 0) {
        return [false, "This looks like a school or student ID, not a " . $selected . "."];
    }
    return [
        false,
        "We could not confirm this is a " . $selected .
        ". Upload a clear, well-lit, uncropped photo of the front of the ID."
    ];
}

/* ---------- AGE ---------- */
/** Returns the age in years, or null when the date is invalid / in the future. */
function calcAge(string $birthDate): ?int
{
    $birth = DateTime::createFromFormat('Y-m-d', $birthDate);
    if (!$birth || $birth->format('Y-m-d') !== $birthDate)
        return null;

    $today = new DateTime('today');
    $age = (int) $birth->diff($today)->y;

    return $birth > $today ? null : $age;
}

/* ---------- STEP 1 ---------- */
function validatePasswords(string $password, string $confirm): array
{
    if (strlen($password) < 8)
        return [false, "Password must be at least 8 characters."];
    if ($password !== $confirm)
        return [false, "Passwords do not match."];
    return [true, ""];
}

/* ---------- DATABASE: duplicate check ---------- */
/** Returns "" when free, otherwise a message saying what is already taken. */
function accountTakenMessage(string $username, string $email): string
{
    $st = db()->prepare(
        'SELECT (LOWER(username) = LOWER(:u)) AS u_taken, (LOWER(email) = LOWER(:e)) AS e_taken
           FROM users
          WHERE LOWER(username) = LOWER(:u2) OR LOWER(email) = LOWER(:e2)'
    );
    $st->execute([':u' => $username, ':e' => $email, ':u2' => $username, ':e2' => $email]);

    $userTaken = false;
    $emailTaken = false;
    foreach ($st->fetchAll() as $row) {
        $userTaken = $userTaken || $row['u_taken'];
        $emailTaken = $emailTaken || $row['e_taken'];
    }

    if ($userTaken)
        return "That username is already taken.";
    if ($emailTaken)
        return "That email is already registered.";
    return "";
}

/* ---------- FACE MATCH ---------- */
function faceMatches(float $distance): bool
{
    return $distance <= MATCH_THRESHOLD;
}

/* ============================================================
   REQUEST HANDLING (the same file answers the page's calls)
   ============================================================ */
function fail(string $message, int $code = 422): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'ok' => false, 'message' => $message]);
    exit;
}

function saveImage(string $field, string $prefix): string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK)
        fail('Image upload failed.');
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024)
        fail('Maximum image size is 5 MB.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext)
        fail('Only JPG, PNG or WEBP images are allowed.');

    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir))
        mkdir($dir, 0750, true);

    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], "$dir/$name"))
        fail('Could not save the image.', 500);
    return $name;
}

function deleteUpload(string $name): void
{
    $path = __DIR__ . '/uploads/' . basename($name);
    if (is_file($path))
        @unlink($path);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    /* ----- final submit ----- */
    if (($_POST['action'] ?? '') === 'register') {
        $p = fn(string $k) => trim((string) ($_POST[$k] ?? ''));

        foreach (['username', 'email', 'password', 'confirmPassword', 'firstName', 'lastName', 'birthDate', 'phone', 'idType'] as $f) {
            if ($p($f) === '')
                fail('Please fill in all required fields.');
        }
        if (!filter_var($p('email'), FILTER_VALIDATE_EMAIL))
            fail('Invalid email address.');

        [$ok, $msg] = validatePasswords((string) $_POST['password'], (string) $_POST['confirmPassword']);
        if (!$ok)
            fail($msg);

        if (calcAge($p('birthDate')) === null)
            fail('Invalid birth date.');

        if (($_POST['consent'] ?? '') !== '1')
            fail('Please provide consent.');

        [$ok, $msg] = checkIdType($p('idType'), (string) ($_POST['idText'] ?? ''));
        if (!$ok)
            fail($msg);

        if (!isset($_POST['idFaceDistance']) || !faceMatches((float) $_POST['idFaceDistance'])) {
            fail('Your face does not match your Valid ID.');
        }

        try {
            $pdo = db();

            // ID type must exist in the id_types table
            $st = $pdo->prepare('SELECT id FROM id_types WHERE name = :n');
            $st->execute([':n' => $p('idType')]);
            $idTypeId = $st->fetchColumn();
            if ($idTypeId === false)
                fail('Invalid ID type.');

            // username / email must be free
            $taken = accountTakenMessage($p('username'), $p('email'));
            if ($taken !== '')
                fail($taken, 409);
        } catch (PDOException $e) {
            error_log('signup db error: ' . $e->getMessage());
            fail('Could not connect to the database. Please try again later.', 500);
        }

        $validIdFile = saveImage('validId', 'id');
        $faceFile = saveImage('faceImage', 'face');

        $passwordHash = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
        $nullIfEmpty = fn(string $v) => $v === '' ? null : $v;

        try {
            $pdo->beginTransaction();

            // 1) users
            $st = $pdo->prepare(
                'INSERT INTO users (username, email, password_hash)
                 VALUES (:username, :email, :hash)
                 RETURNING id'
            );
            $st->execute([
                ':username' => $p('username'),
                ':email' => $p('email'),
                ':hash' => $passwordHash,
            ]);
            $userId = (int) $st->fetchColumn();

            // 2) user_profiles
            $st = $pdo->prepare(
                'INSERT INTO user_profiles
                    (user_id, first_name, middle_name, last_name, extension_name, birth_date, phone)
                 VALUES (:uid, :first, :middle, :last, :ext, :birth, :phone)'
            );
            $st->execute([
                ':uid' => $userId,
                ':first' => $p('firstName'),
                ':middle' => $nullIfEmpty($p('middleName')),
                ':last' => $p('lastName'),
                ':ext' => $nullIfEmpty($p('extension')),
                ':birth' => $p('birthDate'),
                ':phone' => $p('phone'),
            ]);

            // 3) user_verifications
            $st = $pdo->prepare(
                'INSERT INTO user_verifications
                    (user_id, id_type_id, valid_id_path, face_image_path, face_distance, consent_given)
                 VALUES (:uid, :type, :idfile, :facefile, :dist, TRUE)'
            );
            $st->execute([
                ':uid' => $userId,
                ':type' => (int) $idTypeId,
                ':idfile' => $validIdFile,
                ':facefile' => $faceFile,
                ':dist' => round((float) $_POST['idFaceDistance'], 4),
            ]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();

            // nothing was saved, so remove the uploaded images too
            deleteUpload($validIdFile);
            deleteUpload($faceFile);

            if ($e->getCode() === '23505')   // unique violation (someone registered at the same moment)
                fail('That username or email is already registered.', 409);

            error_log('signup insert error: ' . $e->getMessage());
            fail('Could not create your account. Please try again.', 500);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    /* ----- small logic calls from the page ----- */
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    switch ($in['action'] ?? '') {
        case 'age':
            $age = calcAge((string) ($in['birthDate'] ?? ''));
            echo json_encode(['ok' => $age !== null, 'age' => $age]);
            break;

        case 'validateStep':
            if ((int) ($in['step'] ?? -1) === 0) {
                [$ok, $msg] = validatePasswords((string) ($in['password'] ?? ''), (string) ($in['confirmPassword'] ?? ''));

                // also check the username / email are not used yet (database)
                if ($ok) {
                    $username = trim((string) ($in['username'] ?? ''));
                    $email = trim((string) ($in['email'] ?? ''));
                    try {
                        $msg = accountTakenMessage($username, $email);
                        $ok = $msg === '';
                    } catch (PDOException $e) {
                        error_log('signup db error: ' . $e->getMessage());
                        $ok = false;
                        $msg = 'Could not connect to the database. Please try again later.';
                    }
                }
                echo json_encode(['ok' => $ok, 'message' => $msg]);
            } else {
                echo json_encode(['ok' => true, 'message' => '']);
            }
            break;

        case 'idType':
            [$ok, $msg] = checkIdType((string) ($in['idType'] ?? ''), (string) ($in['text'] ?? ''));
            echo json_encode(['ok' => $ok, 'message' => $msg]);
            break;

        case 'faceMatch':
            echo json_encode(['ok' => faceMatches((float) ($in['distance'] ?? 9))]);
            break;

        default:
            fail('Unknown action.', 400);
    }
    exit;
}
?>
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

    <!-- OCR library (reads the text printed on the ID to detect its type) -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"></script>

    <style>
        .verify-status {
            font-size: .9rem;
            margin: 8px 0 0;
            min-height: 1.3em;
            text-align: center;
        }

        .verify-status.info {
            color: #6c757d;
        }

        .verify-status.ok {
            color: #198754;
            font-weight: 600;
        }

        .verify-status.error {
            color: #dc3545;
            font-weight: 600;
        }

        .id-preview,
        #facePreview {
            max-width: 100%;
        }

        .id-preview {
            max-height: 180px;
            margin-top: 10px;
            border-radius: 8px;
        }

        .liveness-wrap {
            position: relative;
            width: 240px;
            height: 240px;
            margin: 12px auto;
        }

        .liveness-ring {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
        }

        .ring-track {
            fill: none;
            stroke: #e5e7eb;
            stroke-width: 8;
            transition: stroke .3s;
        }

        .ring-progress {
            fill: none;
            stroke: #22c55e;
            stroke-width: 8;
            stroke-linecap: round;
            stroke-dasharray: 703.7;
            stroke-dashoffset: 703.7;
            transform: rotate(-90deg);
            transform-origin: 120px 120px;
            transition: stroke-dashoffset .45s ease, stroke .3s;
        }

        .liveness-wrap.scanning .ring-track {
            stroke: #bbf7d0;
        }

        .liveness-wrap.success .ring-track {
            stroke: #22c55e;
        }

        .liveness-wrap.error .ring-track {
            stroke: #fecaca;
        }

        .liveness-wrap.error .ring-progress {
            stroke: #dc3545;
            stroke-dashoffset: 0;
        }

        .liveness-circle {
            position: absolute;
            inset: 16px;
            border-radius: 50%;
            overflow: hidden;
            background: #f1f3f5;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #6c757d;
            font-size: .85rem;
        }

        .liveness-circle video,
        .liveness-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
        }

        .liveness-check {
            position: absolute;
            right: 6px;
            bottom: 6px;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: #22c55e;
            color: #fff;
            font-size: 26px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .25);
        }

        .liveness-check[hidden] {
            display: none;
        }

        .live-instruction {
            text-align: center;
            font-weight: 700;
            font-size: 1.05rem;
            margin: 6px 0 0;
            min-height: 1.5em;
        }

        .live-chips {
            display: flex;
            gap: 6px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .live-chip {
            font-size: .75rem;
            padding: 3px 10px;
            border-radius: 999px;
            background: #e9ecef;
            color: #6c757d;
        }

        .live-chip.current {
            background: #fff3cd;
            color: #664d03;
        }

        .live-chip.done {
            background: #d1e7dd;
            color: #0f5132;
        }
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
                        <label for="validId" class="upload-label">Upload Valid ID (clear photo of your face on the
                            ID)</label>
                        <input type="file" id="validId" name="validId" accept="image/*" required>
                        <img id="idPreview" class="id-preview" alt="Valid ID preview" hidden>
                        <p class="verify-status info" id="idStatus"></p>
                        <!-- result of the ID type check -->
                        <p class="verify-status info" id="idTypeStatus"></p>
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
        const REGISTER_URL = "signup.php";   // this same file handles the submit
        const API_URL = "signup.php";        // this same file runs the PHP logic
        const LIVENESS = {
            turnRight: 0.38,    // nose position ratio below this = turned right
            turnLeft: 0.62,     // nose position ratio above this = turned left
            centerMin: 0.43,    // "looking straight" range
            centerMax: 0.57,
            blinkClose: 0.88,   // eye counts as closed below 88% of its recent open level
            blinkOpen: 0.94,    // eye counts as reopened above 94% of its open level
            holdMs: 700         // how long to hold still when looking straight
        };
        const MODEL_URL = "https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model/";

        /* ================= PHP LOGIC CALL ================= */
        async function api(action, payload = {}) {
            const res = await fetch(API_URL, {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({action, ...payload})
            });
            return res.json();
        }

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
        let idText = null;        // text read from the uploaded ID (null = not read yet)
        let idTypeOk = false;     // true when the ID matches the selected ID type

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
                .detectSingleFace(source, new faceapi.SsdMobilenetv1Options({minConfidence: 0.5}))
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

        async function validateStep() {
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

            // password check + "username/email already used" check run in PHP
            if (current === 0) {
                try {
                    const r = await api("validateStep", {
                        step: 0,
                        username: form.elements["username"].value,
                        email: form.elements["email"].value,
                        password: document.getElementById("password").value,
                        confirmPassword: document.getElementById("confirm-password").value
                    });
                    if (!r.ok) {
                        alert(r.message || "Passwords do not match.");
                        return false;
                    }
                } catch {
                    alert("Could not reach the server. Please try again.");
                    return false;
                }
            }

            if (current === 2 && !idDescriptor) {
                alert("We could not read a face from your Valid ID. Please upload a clearer photo.");
                return false;
            }

            // the uploaded ID must match the selected ID type
            if (current === 2 && !idTypeOk) {
                alert("The uploaded ID does not match the selected ID type. " +
                    "Please upload the correct ID or change the ID type.");
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

        document.getElementById("nextBtn").onclick = async () => {
            if (await validateStep()) showStep(current + 1);
        };

        document.getElementById("backBtn").onclick = () => {
            if (current > 0) showStep(current - 1);
        };

        /* ================= AGE (calculated in PHP) ================= */
        const birthInput = document.getElementById("birthDate");
        birthInput.max = new Date().toISOString().split("T")[0];

        birthInput.addEventListener("change", async e => {
            const ageInput = document.getElementById("age");

            if (!e.target.value) {
                ageInput.value = "";
                return;
            }

            try {
                const r = await api("age", {birthDate: e.target.value});
                ageInput.value = r.ok ? r.age : "";
            } catch {
                ageInput.value = "";
            }
        });

        /* ================= ID TYPE DETECTION (matching runs in PHP) =================
           The browser still reads the text off the ID (OCR); PHP decides
           whether that text matches the selected ID type. The keyword
           lists are at the top of this file (PHP section).
        ================================================================ */
        async function checkIdType() {
            const selected = document.querySelector("[name=idType]").value;

            idTypeOk = false;

            if (idText === null) {
                setStatus("idTypeStatus", "", "info");
                return;
            }
            if (!selected) {
                setStatus("idTypeStatus", "Select the ID type above.", "error");
                return;
            }

            try {
                const r = await api("idType", {idType: selected, text: idText});
                idTypeOk = !!r.ok;
                setStatus("idTypeStatus", r.message, r.ok ? "ok" : "error");
            } catch {
                setStatus("idTypeStatus", "Could not reach the server. Please try again.", "error");
            }
        }

        /* ----- OCR ----- */
        let ocrWorkerPromise = null;

        function getOcrWorker() {
            if (!ocrWorkerPromise) {
                ocrWorkerPromise = Tesseract.createWorker("eng").catch(err => {
                    ocrWorkerPromise = null;
                    throw err;
                });
            }
            return ocrWorkerPromise;
        }

        // enlarge small photos and boost contrast so the text is easier to read
        function toOcrCanvas(img) {
            const w = img.naturalWidth, h = img.naturalHeight;
            let scale = 1;
            if (w > 1800) scale = 1800 / w;
            else if (w < 1200) scale = 1200 / w;

            const canvas = document.createElement("canvas");
            canvas.width = Math.round(w * scale);
            canvas.height = Math.round(h * scale);

            const ctx = canvas.getContext("2d");
            ctx.filter = "grayscale(1) contrast(1.3)";
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            return canvas;
        }

        async function readIdText(img) {
            const worker = await getOcrWorker();
            const result = await worker.recognize(toOcrCanvas(img));
            return result.data.text || "";
        }

        document.querySelector("[name=idType]").addEventListener("change", checkIdType);

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

        function setupIdUpload({inputId, previewId, statusId, label, onDescriptor}) {
            document.getElementById(inputId).addEventListener("change", async e => {
                const file = e.target.files[0];
                const preview = document.getElementById(previewId);

                onDescriptor(null);
                resetFace();

                // forget the previous ID's text and type result
                idText = null;
                idTypeOk = false;
                setStatus("idTypeStatus", "", "info");

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
                }

                // read the text on the ID (browser OCR), then PHP checks it matches the selected ID type
                if (idDescriptor) {
                    setStatus("idTypeStatus", "Checking the ID type (this can take a few seconds)...", "info");
                    try {
                        idText = await readIdText(preview);
                    } catch {
                        idText = null;
                        idTypeOk = false;
                        setStatus("idTypeStatus",
                            "The ID type check could not load. Check your internet connection and upload the ID again.",
                            "error");
                    }
                    if (idText !== null) await checkIdType();
                }

                busy = false;
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
            await camera.play().catch(() => { });
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

        async function waitFor(id, check, inputSize = 320) {
            const options = new faceapi.TinyFaceDetectorOptions({inputSize, scoreThreshold: 0.5});

            while (true) {
                if (id !== session) throw new Error("cancelled");

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

            setRing(0);

            for (let i = 0; i < stages.length; i++) {
                const stage = stages[i];
                renderChips(stages, i);
                document.getElementById("liveInstruction").textContent = INSTRUCTIONS[stage];

                let holdStart = 0, closed = false, closedAt = 0;
                const earSamples = [];
                const recentEar = [];

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
                        if (m.ratio < 0.25 || m.ratio > 0.75) return false;   // roughly facing the camera
                        const now = performance.now();

                        // collect a few "open eye" samples first
                        if (recentEar.length < 5) {
                            recentEar.push(m.ear);
                            return false;
                        }

                        const sorted = [...recentEar].sort((a, b) => a - b);
                        const open = sorted[Math.floor(sorted.length / 2)];

                        if (!closed) {
                            if (m.ear < open * LIVENESS.blinkClose) {
                                closed = true;
                                closedAt = now;
                            } else {
                                recentEar.push(m.ear);                 // keep tracking the open level
                                if (recentEar.length > 12) recentEar.shift();
                            }
                            return false;
                        }

                        if (m.ear > open * LIVENESS.blinkOpen) return true;   // eye reopened = blink done
                        if (now - closedAt > 1500) closed = false;            // eyes stuck "closed"? reset
                        return false;
                    }
                };

                await waitFor(id, checks[stage], stage === "blink" ? 224 : 320);

                if (stage === "center") {
                    // compare the straight-on face with the uploaded Valid ID
                    document.getElementById("liveInstruction").textContent = "Checking your face...";
                    faceCanvas = snapshot();
                    const live = await getDescriptor(faceCanvas);
                    if (id !== session) throw new Error("cancelled");
                    if (!live) throw new Error("noface");

                    const d = faceapi.euclideanDistance(live, idDescriptor);
                    faceScores = {idDistance: d};

                    // the match threshold is decided in PHP
                    let match;
                    try {
                        match = await api("faceMatch", {distance: d});
                    } catch {
                        throw new Error("server");
                    }
                    if (id !== session) throw new Error("cancelled");
                    if (!match.ok) throw new Error("mismatch");
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
                    models: "Face recognition could not load. Check your connection and refresh.",
                    server: "Could not reach the server. Please try again."
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

            if (!(await validateStep())) return;

            if (!document.getElementById("consent").checked) {
                alert("Please provide consent.");
                return;
            }

            const message = document.getElementById("form-message");
            const submitBtn = document.getElementById("submitBtn");

            const data = new FormData(form);          // includes the validId file
            data.append("faceImage", faceBlob, "face.jpg");
            data.append("idFaceDistance", faceScores.idDistance);
            data.append("idText", idText || "");      // PHP re-checks the ID type
            data.append("consent", "1");              // saved in the database (consent_given)
            data.append("action", "register");

            submitBtn.disabled = true;
            message.textContent = "Creating your account...";

            try {
                const res = await fetch(REGISTER_URL, {method: "POST", body: data});
                const result = await res.json().catch(() => ({}));

                if (res.ok && result.success !== false) {
                    message.textContent = "Account created! Redirecting to login...";
                    setTimeout(() => (window.location.href = "staffdash.php"), 1500);
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