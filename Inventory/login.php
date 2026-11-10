<?php


session_start();
require_once __DIR__ . '/signupdb.php';

const DEBUG = false;
const MAX_FAILS = 5;              // failed attempts allowed before the lock
const LOCK_SECONDS = 300;
const AFTER_LOGIN = 'staffdash.php';
const ADMIN_HOME = 'admindash.php';

const FACE_MAX_DISTANCE = 0.55;   // live face vs the face read from the Valid ID (lower = stricter)
const FACE_MIN_GAP = 0.04;        // best match must beat a different person by this much
const FACE_SAME_PERSON = 0.5;     // the 2nd live face (after the motion test) must match the 1st live face
const FACE_REF_SLACK = 0.05;      // the 2nd live face may be this much looser vs the Valid ID than the 1st
const FACE_CANDIDATE_TTL = 90;    // seconds the 'who is this' answer stays valid

// the "uploads" folder sits next to login.php
const UPLOAD_DIR = __DIR__ . '/uploads';
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
const PHOTO_TYPES = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

const ADMIN_USERNAME = 'admin';
// better: set the ADMIN_PASSWORD environment variable on the server instead of keeping it in code
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'admin321');

/* ================= helpers ================= */

function jsonOut(array $data, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();                 // throw away anything PHP printed by mistake
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function errDetail(Throwable $e): string
{
    return (DEBUG || ($_SESSION['role'] ?? '') === 'admin')
        ? $e->getMessage()
        : 'Something went wrong. Please try again later.';
}

function dbFail(PDOException $e): void
{
    error_log('login db error: ' . $e->getMessage());
    jsonOut([
        'success' => false,
        'message' => DEBUG
            ? 'Database error: ' . $e->getMessage()
            : 'Could not connect to the database. Please try again later.'
    ], 500);
}

function failAndMaybeLock(string $message): void
{
    $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;

    if ($_SESSION['login_fails'] >= MAX_FAILS) {
        $_SESSION['login_fails'] = 0;
        $_SESSION['lock_until'] = time() + LOCK_SECONDS;
        jsonOut([
            'success' => false,
            'locked' => true,
            'message' => 'Too many failed attempts. Login is locked. Try again later.'
        ], 429);
    }
    jsonOut(['success' => false, 'message' => $message], 401);
}

function startLoginSession(?int $userId, string $username, string $role): void
{
    session_regenerate_id(true);
    $_SESSION['login_fails'] = 0;
    unset($_SESSION['lock_until']);
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $role;
}

function ensureFaceTable(): void
{
    $pdo = db();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS face_profiles (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            role TEXT NOT NULL,
            username TEXT,
            descriptor TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )'
    );
    $pdo->exec('ALTER TABLE face_profiles ADD COLUMN IF NOT EXISTS photo BYTEA');
    $pdo->exec('ALTER TABLE face_profiles ADD COLUMN IF NOT EXISTS photo_mime TEXT');
    $pdo->exec('ALTER TABLE face_profiles ADD COLUMN IF NOT EXISTS photo_file TEXT');
    $pdo->exec('ALTER TABLE face_profiles ADD COLUMN IF NOT EXISTS user_id INTEGER');
}

function validDescriptor($d): bool
{
    if (!is_array($d) || count($d) !== 128) {
        return false;
    }
    foreach ($d as $v) {
        if (!is_int($v) && !is_float($v)) {
            return false;
        }
    }
    return true;
}

function euclid(array $a, array $b): float
{
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $d = $a[$i] - $b[$i];
        $sum += $d * $d;
    }
    return sqrt($sum);
}

/* All enrolled faces together with their account, in ONE query.
   - Only faces that belong to a REAL account count (old / deleted test accounts are ignored).
   - If an account has a Valid ID on file, ONLY the face read from that Valid ID is used
     (a face saved earlier from the camera is ignored for that account). */
function loadFaceRows(): array
{
    $sql = 'SELECT f.id, f.name, f.role, f.username, f.descriptor,
                   u.id AS uid, u.username AS real_username
              FROM face_profiles f
              JOIN users u ON u.id = f.user_id
                          OR (f.user_id IS NULL AND LOWER(u.username) = LOWER(f.username))
             WHERE f.role <> \'admin\'
               AND (f.photo_file IS NOT NULL
                    OR NOT EXISTS (SELECT 1 FROM user_verifications v WHERE v.user_id = u.id))';

    for ($try = 0; $try < 2; $try++) {
        try {
            return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $code = (string) $e->getCode();
            if ($code === '42P01') {                    // table does not exist yet
                return [];
            }
            if ($code === '42703' && $try === 0) {      // user_id column missing: add it, retry once
                ensureFaceTable();
                continue;
            }
            throw $e;
        }
    }
    return [];
}

/* The Valid ID photo file (inside uploads) that belongs to this account, or null. */
function userIdFile(int $uid): ?string
{
    try {
        $st = db()->prepare('SELECT valid_id_path FROM user_verifications WHERE user_id = :u LIMIT 1');
        $st->execute([':u' => $uid]);
        $file = basename((string) $st->fetchColumn());
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($file !== '' && isset(PHOTO_TYPES[$ext]) && is_file(UPLOAD_DIR . '/' . $file)) {
            return $file;
        }
    } catch (PDOException $e) {
        error_log('userIdFile: ' . $e->getMessage());
    }
    return null;
}

/* The account whose OWN Valid ID may be read for face enrollment:
   a logged-in 'user', or someone who just finished sign-up
   (signup.php sets $_SESSION['pending_face_user'] = the new user's id). */
function ownFaceUid(): int
{
    if (($_SESSION['role'] ?? '') === 'user' && (int) ($_SESSION['user_id'] ?? 0) > 0) {
        return (int) $_SESSION['user_id'];
    }
    return (int) ($_SESSION['pending_face_user'] ?? 0);
}

/* What does this account still need?
     'id'   = it has a Valid ID photo but its saved face is NOT from that photo -> read the Valid ID
     'live' = no Valid ID photo and no face saved yet -> use the camera
     null   = nothing to do */
function faceEnrollMode(int $uid, string $username): ?string
{
    $hasId = userIdFile($uid) !== null;
    $row = false;
    try {
        $st = db()->prepare(
            'SELECT photo_file FROM face_profiles
              WHERE (user_id IS NOT NULL AND user_id = :u)
                 OR (user_id IS NULL AND LOWER(username) = LOWER(:n))
              ORDER BY (photo_file IS NOT NULL) DESC
              LIMIT 1'
        );
        $st->execute([':u' => $uid, ':n' => $username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $row = false;     // table/column not there yet -> not enrolled
    }

    if ($hasId) {
        return ($row && !empty($row['photo_file'])) ? null : 'id';
    }
    return $row ? null : 'live';
}

/* ================= GET routes ================= */

// admin only: view a photo stored in face_profiles
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['photo'])) {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit;
    }
    try {
        $st = db()->prepare('SELECT photo, photo_mime FROM face_profiles WHERE id = :i');
        $st->execute([':i' => (int) $_GET['photo']]);
        $row = $st->fetch(PDO::FETCH_NUM);
        if ($row && $row[0] !== null) {
            header('Content-Type: ' . ($row[1] ?: 'image/jpeg'));
            echo is_resource($row[0]) ? stream_get_contents($row[0]) : $row[0];
            exit;
        }
    } catch (PDOException $e) {
        error_log('photo view: ' . $e->getMessage());
    }
    http_response_code(404);
    exit;
}

// the account's OWN Valid ID photo (logged-in user, or someone who just signed up),
// so the browser can read the face once
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['myid'])) {
    $uid = ownFaceUid();
    if ($uid <= 0) {
        http_response_code(403);
        exit;
    }
    $file = userIdFile($uid);
    if ($file !== null) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . PHOTO_TYPES[$ext]);
        header('Content-Length: ' . filesize(UPLOAD_DIR . '/' . $file));
        header('Cache-Control: private, no-store');
        readfile(UPLOAD_DIR . '/' . $file);
        exit;
    }
    http_response_code(404);
    exit;
}

/* ================= POST: login / face / enroll ================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();                              // stray warnings can never break the JSON reply
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');

    // uncaught errors still answer with JSON (and are written to the PHP error log)
    set_exception_handler(function (Throwable $e) {
        error_log('login uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        jsonOut(['success' => false, 'message' => 'Server error: ' . errDetail($e)], 500);
    });
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('login fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            $admin = (($_SESSION['role'] ?? '') === 'admin') || DEBUG;
            echo json_encode(['success' => false, 'message' => $admin ? 'Server error: ' . $err['message'] : 'Something went wrong. Please try again later.']);
        }
    });

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string) ($in['action'] ?? 'login');

    /* ---------- ENROLL SELF (from the account's OWN Valid ID) ----------
       Works for a logged-in user AND for someone who just finished sign-up. */
    if ($action === 'enroll_self') {
        $uid = ownFaceUid();
        if ($uid <= 0) {
            jsonOut(['success' => false, 'message' => 'Please sign up or log in first.'], 403);
        }
        $desc = $in['descriptor'] ?? null;
        if (!validDescriptor($desc)) {
            jsonOut(['success' => false, 'message' => 'Invalid face data.'], 422);
        }
        $file = userIdFile($uid);
        if ($file === null) {
            jsonOut(['success' => false, 'message' => 'No Valid ID photo found for this account.'], 404);
        }
        $path = UPLOAD_DIR . '/' . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (filesize($path) > MAX_PHOTO_BYTES) {
            jsonOut(['success' => false, 'message' => 'The Valid ID photo is too large.'], 422);
        }

        $fp = null;
        try {
            $pdo = db();
            ensureFaceTable();

            $st = $pdo->prepare(
                'SELECT u.username, p.first_name, p.last_name
                   FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
                  WHERE u.id = :i'
            );
            $st->execute([':i' => $uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                jsonOut(['success' => false, 'message' => 'Account not found.'], 404);
            }
            $full = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));

            $fp = fopen($path, 'rb');
            if ($fp === false) {
                jsonOut(['success' => false, 'message' => 'Could not read the Valid ID photo.'], 500);
            }

            $pdo->beginTransaction();
            // replace EVERY older face of this account (camera or ID) with the one from the Valid ID
            $pdo->prepare(
                'DELETE FROM face_profiles
                  WHERE user_id = :u
                     OR photo_file = :f
                     OR (user_id IS NULL AND LOWER(username) = LOWER(:n))'
            )->execute([':u' => $uid, ':f' => $file, ':n' => $u['username']]);

            $ins = $pdo->prepare(
                'INSERT INTO face_profiles (name, role, username, user_id, descriptor, photo, photo_mime, photo_file)
                 VALUES (:n, :r, :un, :uid, :d, :p, :m, :f)'
            );
            $ins->bindValue(':n', $full !== '' ? $full : $u['username']);
            $ins->bindValue(':r', 'user');
            $ins->bindValue(':un', $u['username']);
            $ins->bindValue(':uid', $uid, PDO::PARAM_INT);
            $ins->bindValue(':d', json_encode(array_values($desc)));
            $ins->bindParam(':p', $fp, PDO::PARAM_LOB);
            $ins->bindValue(':m', PHOTO_TYPES[$ext]);
            $ins->bindValue(':f', $file);
            $ins->execute();
            $pdo->commit();
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            dbFail($e);
        } finally {
            if (is_resource($fp)) {      // PDO may already have closed it
                fclose($fp);
            }
        }

        unset($_SESSION['pending_face_user']);   // one use only
        jsonOut(['success' => true]);
    }

    /* ---------- ENROLL LIVE (an employee WITHOUT a Valid ID saves their OWN face from the camera) ----------
       Only for someone who is already logged in with a password.
       Accounts that have a Valid ID photo are NOT allowed here: their face must come from the Valid ID. */
    if ($action === 'enroll_live') {
        $sessRole = (string) ($_SESSION['role'] ?? '');
        $sessUser = (string) ($_SESSION['username'] ?? '');
        if ($sessRole === '' || $sessUser === '') {
            jsonOut(['success' => false, 'message' => 'Please log in first.'], 403);
        }
        if ($sessRole === 'admin') {
            jsonOut(['success' => false, 'message' => 'Face login is not used for the admin account.'], 403);
        }
        $desc = $in['descriptor'] ?? null;
        if (!validDescriptor($desc)) {
            jsonOut(['success' => false, 'message' => 'Invalid face data.'], 422);
        }
        $uid = (int) ($_SESSION['user_id'] ?? 0);

        if ($uid > 0 && userIdFile($uid) !== null) {
            jsonOut(['success' => false, 'message' => 'Your face is enrolled from your Valid ID.'], 409);
        }

        try {
            $pdo = db();
            ensureFaceTable();
            $pdo->beginTransaction();

            // replace any older face of this same account
            $pdo->prepare(
                'DELETE FROM face_profiles
                  WHERE (user_id IS NOT NULL AND user_id = :u)
                     OR (user_id IS NULL AND LOWER(username) = LOWER(:n))'
            )->execute([':u' => $uid > 0 ? $uid : -1, ':n' => $sessUser]);

            $ins = $pdo->prepare(
                'INSERT INTO face_profiles (name, role, username, user_id, descriptor)
                 VALUES (:n, :r, :un, :uid, :d)'
            );
            $ins->bindValue(':n', $sessUser);
            $ins->bindValue(':r', $sessRole);
            $ins->bindValue(':un', $sessUser);
            $ins->bindValue(':uid', $uid > 0 ? $uid : null, $uid > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $ins->bindValue(':d', json_encode(array_values($desc)));
            $ins->execute();
            $pdo->commit();
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            dbFail($e);
        }
        jsonOut(['success' => true]);
    }

    // still locked from too many wrong attempts?
    if (($_SESSION['lock_until'] ?? 0) > time()) {
        jsonOut([
            'success' => false,
            'locked' => true,
            'message' => 'Too many failed attempts. Login is locked. Try again later.'
        ], 429);
    }

    /* ---------- FACE LOGIN, step 1: WHO is this? ----------
       The browser sends this as soon as the first straight-on face is seen, so the
       server works while the person does the motion test.
       The live face is compared with the face read from each account's Valid ID. */
    if ($action === 'face_identify') {
        $t0 = microtime(true);
        $descriptor = $in['descriptor'] ?? null;
        if (!validDescriptor($descriptor)) {
            jsonOut(['success' => false, 'message' => 'Invalid face data. Please try again.'], 422);
        }
        unset($_SESSION['face_candidate']);

        try {
            $rows = loadFaceRows();
        } catch (PDOException $e) {
            dbFail($e);
        }

        // best (smallest) distance for each person
        $best = [];
        foreach ($rows as $r) {
            $ref = json_decode($r['descriptor'], true);
            if (!validDescriptor($ref)) {
                continue;
            }
            $d = euclid($descriptor, $ref);
            $key = $r['uid'] !== null ? 'u' . $r['uid'] : $r['role'] . '|' . ($r['username'] ?? '') . '|' . $r['name'];
            if (!isset($best[$key]) || $d < $best[$key]['dist']) {
                $best[$key] = ['row' => $r, 'dist' => $d, 'ref' => $ref];
            }
        }

        if (!$best) {
            jsonOut([
                'success' => false,
                'message' => 'Face login is not set up yet. Log in with your password once and your face will be saved from your Valid ID.'
            ], 409);
        }

        $list = array_values($best);
        usort($list, fn($a, $b) => $a['dist'] <=> $b['dist']);

        $first = $list[0];
        $secondDist = $list[1]['dist'] ?? INF;

        if ($first['dist'] > FACE_MAX_DISTANCE || ($secondDist - $first['dist']) < FACE_MIN_GAP) {
            // handy when tuning: shows the closest accounts and their distances in the PHP error log
            error_log('face_identify rejected. closest: ' . json_encode(array_map(
                fn($x) => ['user' => $x['row']['real_username'] ?? $x['row']['username'], 'dist' => round($x['dist'], 3)],
                array_slice($list, 0, 3)
            )));
            failAndMaybeLock('Face not recognized.');
        }

        // the account name that will be shown: the real username from the users table
        $p = $first['row'];
        $display = !empty($p['real_username']) ? $p['real_username']
            : (!empty($p['username']) ? $p['username'] : $p['name']);

        $candUid = $p['uid'] !== null ? (int) $p['uid'] : null;

        // remembered on the SERVER only (the browser cannot change it)
        $_SESSION['face_candidate'] = [
            'ref' => $first['ref'],                 // the face saved from the Valid ID
            'first' => array_values($descriptor),   // the first live face (before the motion test)
            'user_id' => $candUid,
            'username' => $display,
            'role' => $p['role'],
            't' => time(),
        ];

        jsonOut(['success' => true, 'ms' => round((microtime(true) - $t0) * 1000)]);
    }

    /* ---------- FACE LOGIN, step 2: same person after the motion test? -> log in ---------- */
    if ($action === 'face') {
        $descriptor2 = $in['descriptor2'] ?? null;
        if (!validDescriptor($descriptor2)) {
            jsonOut(['success' => false, 'message' => 'Invalid face data. Please try again.'], 422);
        }

        $cand = $_SESSION['face_candidate'] ?? null;
        unset($_SESSION['face_candidate']);          // one use only

        if (!is_array($cand) || (time() - (int) $cand['t']) > FACE_CANDIDATE_TTL) {
            jsonOut(['success' => false, 'message' => 'Face check expired. Please try again.'], 408);
        }

        // the face after the motion test must be the SAME live person as before the motion test,
        // and must still match the face saved from the Valid ID
        $sameLive = euclid($descriptor2, $cand['first']);
        $matchId = euclid($descriptor2, $cand['ref']);
        if ($sameLive > FACE_SAME_PERSON || $matchId > FACE_MAX_DISTANCE + FACE_REF_SLACK) {
            failAndMaybeLock('Face verification failed. Please try again.');
        }

        startLoginSession($cand['user_id'], $cand['username'], $cand['role']);

        jsonOut([
            'success' => true,
            'name' => $cand['username'],             // shown in "Welcome, ..."
            'redirect' => AFTER_LOGIN
        ]);
    }

    /* ---------- PASSWORD LOGIN ---------- */
    $username = trim((string) ($in['username'] ?? ''));
    $password = (string) ($in['password'] ?? '');

    if ($username === '' || $password === '') {
        jsonOut(['success' => false, 'message' => 'Please enter your username and password.'], 422);
    }

    $userId = null;
    $role = null;
    $redirect = AFTER_LOGIN;
    $user = null;

    if (hash_equals(ADMIN_USERNAME, $username) && hash_equals(ADMIN_PASSWORD, $password)) {
        $role = 'admin';
        $redirect = ADMIN_HOME;
    } else {
        try {
            $st = db()->prepare(
                'SELECT id, username, password_hash
                   FROM users
                  WHERE LOWER(username) = LOWER(:u1) OR LOWER(email) = LOWER(:u2)
                  ORDER BY (LOWER(username) = LOWER(:u3)) DESC
                  LIMIT 1'
            );
            $st->execute([':u1' => $username, ':u2' => $username, ':u3' => $username]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            dbFail($e);
        }

        if ($user && password_verify($password, $user['password_hash'])) {
            $userId = (int) $user['id'];
            $username = $user['username'];
            $role = 'user';
        }
    }

    if ($role === null) {
        failAndMaybeLock('Incorrect username or password.');
    }

    startLoginSession($userId, $username, $role);

    // does this account still need a saved face?
    //   'id'   = read the Valid ID photo (the saved face is missing or was made from the camera)
    //   'live' = use the camera (only when the account has NO Valid ID photo)
    //   admin never needs a face
    $enrollHow = null;
    if ($role !== 'admin' && $userId) {
        $enrollHow = faceEnrollMode($userId, $username);
    }

    jsonOut(['success' => true, 'redirect' => $redirect, 'enroll_mode' => $enrollHow]);
}

// someone who just signed up (signup.php sets this): their face is saved automatically on this page
$pendingFace = !empty($_SESSION['pending_face_user']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BJ4kery | Login</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Quicksand:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- all login + face-login styles live here (after Bootstrap so they win) -->
    <link rel="stylesheet" href="assets/style/login.css">
</head>

<body>

    <main class="login-box">

        <!-- LEFT: brand panel -->
        <aside class="cookie-panel">

            <div class="brand-row">
                <div class="brand-logo">B4</div>
                <div>
                    <div class="brand-name">B4jkery</div>
                    <div class="brand-sub">Inventory System</div>
                </div>
            </div>

            <div class="hero">
                <div class="hero-eyebrow">Cookie Inventory</div>
                <h2>Welcome! to B4jkery</h2>
                <p>Let’s keep your bakery well-stocked today.</p>
            </div>


        </aside>

        <!-- RIGHT: blurred photo background + login card -->
        <section class="login-panel">

            <div class="login-bg" aria-hidden="true"></div>

            <div class="login-card">

                <div class="card-head">
                    <h1>Log in</h1>
                </div>

                <form id="myForm" novalidate>

                    <div class="input-box">
                        <label class="field-label" for="username">Username</label>
                        <input type="text" class="form-control" name="username" id="username"
                            placeholder="Enter your username" autocomplete="username">
                    </div>

                    <div class="input-box">
                        <label class="field-label" for="password">Password</label>
                        <input type="password" class="form-control" name="password" id="password"
                            placeholder="Enter your password" autocomplete="current-password">
                    </div>

                    <div class="forgot-wrapper">
                        <a href="forgotpassword.php" class="forgot">Forgot password?</a>
                    </div>

                    <div id="login-error" role="alert"></div>

                    <button type="submit" class="login-btn">Log In</button>

                    <div class="or-divider">or</div>
                    <button type="button" class="face-btn" id="openFace">
                        <i class="fa-solid fa-face-smile"></i> Login with Face
                    </button>

                    <p class="signup-text">
                        New here?
                        <a href="signup.php" class="signup-link">Create an account</a>
                    </p>

                </form>

            </div>

        </section>

    </main>

    <!-- Face login modal -->
    <div class="modal fade" id="faceModal" tabindex="-1" aria-labelledby="faceTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content face-modal">
                <div class="modal-header border-0">
                    <h5 class="modal-title" id="faceTitle">🍪 Face Login</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">

                    <div class="liveness-wrap" id="cam">
                        <svg class="liveness-ring" viewBox="0 0 240 240" aria-hidden="true">
                            <circle class="ring-track" cx="120" cy="120" r="112"></circle>
                            <circle class="ring-progress" id="ringProgress" cx="120" cy="120" r="112"></circle>
                        </svg>
                        <div class="liveness-circle">
                            <video id="video" autoplay muted playsinline></video>
                        </div>
                        <div class="liveness-check" id="livenessCheck" hidden>✓</div>
                    </div>

                    <p class="live-instruction" id="liveInstruction">Get ready…</p>
                    <div class="live-chips" id="liveChips"></div>

                    <p id="faceMsg">Opening camera…</p>
                    <button type="button" class="face-start" id="faceStart" disabled>Scan My Face</button>
                </div>
            </div>
        </div>
    </div>

    <!-- "Welcome ..." notification -->
    <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3">
        <div id="welcomeToast" class="toast align-items-center text-bg-success border-0" role="alert"
            aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body fs-6 fw-semibold" id="welcomeText"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"
                    aria-label="Close"></button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-validate/1.22.1/jquery.validate.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.js"></script>

    <script>
        $(document).ready(function () {

            $("#myForm").validate({

                rules: {
                    username: {required: true},
                    password: {required: true}
                },

                messages: {
                    username: {required: "Please enter your username."},
                    password: {required: "Please enter your password."}
                },

                errorElement: "span",
                errorClass: "error",

                errorPlacement: function (error, element) {
                    error.insertAfter(element.closest(".input-box"));
                },

                submitHandler: function (form) {

                    const $btn = $(".login-btn");
                    $("#login-error").text("");
                    $btn.prop("disabled", true);

                    fetch("login.php", {
                        method: "POST",
                        headers: {"Content-Type": "application/json"},
                        body: JSON.stringify({
                            action: "login",
                            username: $("#username").val().trim(),
                            password: $("#password").val()
                        })
                    })
                        .then(res => res.json().catch(() => ({})))
                        .then(result => {
                            if (result.success) {

                                // the face is missing or was saved from the camera: read the Valid ID (one time only)
                                if (result.enroll_mode === "id") {
                                    $("#login-error").text("Setting up face login from your Valid ID (one time only)…");
                                    Promise.race([
                                        enrollSelf(),
                                        new Promise((_, rej) => setTimeout(() => rej(new Error("slow")), 25000))
                                    ])
                                        .catch(e => console.warn("Face setup skipped:", e))
                                        .finally(() => (window.location.href = result.redirect));
                                    return;
                                }

                                // employee WITHOUT a Valid ID photo: save the face from the camera (one time only)
                                if (result.enroll_mode === "live") {
                                    enrollLive(result.redirect);
                                    return;
                                }

                                window.location.href = result.redirect;
                                return;
                            }

                            $("#login-error").text(result.message || "Login failed. Please try again.");

                            if (result.locked) {
                                $("#username, #password").prop("disabled", true);
                            } else {
                                $btn.prop("disabled", false);
                            }
                        })
                        .catch(() => {
                            $("#login-error").text("Could not reach the server. Please try again.");
                            $btn.prop("disabled", false);
                        });

                    return false;
                }

            });

        });
    </script>

    <!-- Face recognition login (steady, properly-detected face + motion test) -->
    <script>
        /* =====================================================================
           WHO CAN LOG IN WITH THEIR FACE
           - everyone who signed up: the face is read from their Valid ID photo
             (right after sign-up, or at the first password login)
           - an account that has a Valid ID photo ALWAYS uses the face from that photo
           - employees without any Valid ID photo: after their first password login
             the camera opens once and saves their face (action "enroll_live")
           - the admin does NOT use face login (password only)
           ===================================================================== */
        const PENDING_FACE = <?= json_encode($pendingFace) ?>;   // true right after sign-up

        // Models load from assets/models/ when that folder exists (fastest), otherwise from the CDN.
        const CDN_MODELS = "https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model/";
        const LOCAL_MODELS = "assets/models/";
        let MODEL_URL = CDN_MODELS;

        async function pickModelUrl() {
            try {
                const r = await fetch(LOCAL_MODELS + "tiny_face_detector_model.json", {method: "HEAD", cache: "no-store"});
                if (r.ok) return LOCAL_MODELS;
            } catch { }
            return CDN_MODELS;
        }

        // small detector input = fast frames. Landmarks/face print don't depend on it.
        const FAST_OPT = new faceapi.TinyFaceDetectorOptions({inputSize: 160, scoreThreshold: 0.5});
        const PRINT_OPT = new faceapi.TinyFaceDetectorOptions({inputSize: 224, scoreThreshold: 0.5});

        const LIVENESS = {
            turnRight: 0.38,   // nose ratio below this = turned right
            turnLeft: 0.62,    // nose ratio above this = turned left
            centerMin: 0.41,   // "looking straight" range
            centerMax: 0.59,
            blinkClose: 0.88,
            blinkOpen: 0.94,
            holdMs: 200,       // how long to hold still when looking straight
            stageTimeout: 12000,
            // --- steadiness / proper detection ---
            minScore: 0.6,         // detector confidence needed before a straight-on frame counts
            looseScore: 0.4,       // lower bar while turning / blinking (the face is partly turned)
            steadyFrames: 4,       // this many calm frames in a row before we trust it
            steadyMove: 0.02,      // max face movement per frame (fraction of video width)
            captureTimeout: 6000   // how long we wait for a steady, clear face
        };

        /* ================= ELEMENTS / STATE ================= */
        const faceModalEl = document.getElementById("faceModal");
        const faceModal = new bootstrap.Modal(faceModalEl);
        const video = document.getElementById("video");
        const cam = document.getElementById("cam");
        const faceMsg = document.getElementById("faceMsg");
        const faceStart = document.getElementById("faceStart");
        const instruction = document.getElementById("liveInstruction");
        const chipsEl = document.getElementById("liveChips");
        const checkEl = document.getElementById("livenessCheck");
        const ring = document.getElementById("ringProgress");

        const RING_LENGTH = 2 * Math.PI * 112;
        const LABELS = {center: "Look straight", right: "Turn right", left: "Turn left", blink: "Blink", final: "Hold still"};
        const INSTRUCTIONS = {
            center: "Look straight at the camera",
            right: "Slowly turn your head to the RIGHT ➡️",
            left: "Slowly turn your head to the LEFT ⬅️",
            blink: "Look straight and BLINK 👁️",
            final: "Look straight again"
        };

        let stream = null;
        let run = 0;
        let modelsPromise = null;
        let faceLocked = false;
        let enrollRedirect = null;   // set when the modal is used to SAVE a face (not to log in)

        const nextFrame = () => new Promise(r => requestAnimationFrame(r));

        function setRing(fraction) {
            ring.style.strokeDashoffset = RING_LENGTH * (1 - fraction);
        }

        function renderChips(stages, done) {
            chipsEl.innerHTML = stages.map((st, i) =>
                `<span class="live-chip ${i < done ? "done" : i === done ? "current" : ""}">${i < done ? "✓ " : ""}${LABELS[st]}</span>`
            ).join("");
        }

        function resetUi() {
            cam.className = "liveness-wrap";
            checkEl.hidden = true;
            setRing(0);
            chipsEl.innerHTML = "";
            instruction.textContent = "Get ready…";
        }

        /* ================= SERVER ================= */
        async function postJSON(body) {
            let res;
            try {
                res = await fetch("login.php", {
                    method: "POST",
                    headers: {"Content-Type": "application/json"},
                    body: JSON.stringify(body)
                });
            } catch {
                throw new Error("network");
            }
            const raw = await res.text();
            try {
                return JSON.parse(raw);
            } catch {
                console.error("login.php answered (HTTP " + res.status + "):", raw.slice(0, 600));
                return {success: false, message: "Server error (HTTP " + res.status + "). Check the PHP error log."};
            }
        }

        /* ================= MODELS (loaded in the background on page load) ================= */
        function loadModels() {
            if (!modelsPromise) {
                modelsPromise = (async () => {
                    MODEL_URL = await pickModelUrl();
                    try {
                        try {
                            await faceapi.tf.setBackend("webgl");
                            await faceapi.tf.ready();
                        } catch (b) {
                            console.warn("WebGL not available, using the default backend:", b);
                        }

                        await Promise.all([
                            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
                        ]);

                        // warm-up: the first run of each network is slow (GPU setup), so do it now
                        try {
                            const c = document.createElement("canvas");
                            c.width = c.height = 160;
                            c.getContext("2d").fillRect(0, 0, 160, 160);
                            await faceapi.detectSingleFace(c, FAST_OPT);
                            await faceapi.nets.faceLandmark68Net.detectLandmarks(c);
                            await faceapi.nets.faceRecognitionNet.computeFaceDescriptor(c);
                        } catch (w) {
                            console.warn("Warm-up skipped:", w);
                        }
                        return true;
                    } catch (err) {
                        console.error("Face model load failed:", err);
                        modelsPromise = null;
                        return false;
                    }
                })();
            }
            return modelsPromise;
        }

        window.addEventListener("load", loadModels);

        /* ================= CAMERA ================= */
        function stopCamera() {
            run++;
            if (stream) stream.getTracks().forEach(t => t.stop());
            stream = null;
            video.srcObject = null;
        }

        async function openCamera() {
            if (!window.isSecureContext) throw new Error("insecure");
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error("nocamera");
            }
            stream = await navigator.mediaDevices.getUserMedia({
                video: {facingMode: "user", width: {ideal: 480}, height: {ideal: 360}},
                audio: false
            });
            video.srcObject = stream;
            await video.play().catch(() => { });
            while (!video.videoWidth) await nextFrame();
        }

        /* ================= MOTION (LIVENESS) HELPERS ================= */
        const dist2d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

        function eyeRatio(p) {   // smaller = more closed
            return (dist2d(p[1], p[5]) + dist2d(p[2], p[4])) / (2 * dist2d(p[0], p[3]));
        }

        function measure(det) {
            const pts = det.landmarks.positions;
            const nose = pts[30], jawA = pts[0], jawB = pts[16];
            return {
                ratio: (nose.x - jawA.x) / (jawB.x - jawA.x),   // ~0.5 straight, <0.5 right, >0.5 left
                ear: (eyeRatio(pts.slice(36, 42)) + eyeRatio(pts.slice(42, 48))) / 2,
                box: det.detection.box
            };
        }

        /* null = the face is good enough to use, otherwise a hint for the person.
           strict (straight-on frames): clear, fully inside the frame, right size, centered.
           loose (turning / blinking): only needs to be a real detection. */
        function faceProblem(det, strict = true) {
            if (det.detection.score < (strict ? LIVENESS.minScore : LIVENESS.looseScore)) {
                return "Face not clear. Face the camera in good light.";
            }
            if (!strict) return null;

            const b = det.detection.box, vw = video.videoWidth, vh = video.videoHeight;
            const w = b.width / vw;
            const cx = (b.x + b.width / 2) / vw;
            const cy = (b.y + b.height / 2) / vh;

            if (b.x < 0 || b.y < 0 || b.x + b.width > vw || b.y + b.height > vh) return "Keep your whole face inside the circle.";
            if (w < 0.25) return "Move a little closer.";
            if (w > 0.8) return "Move a little farther.";
            if (cx < 0.3 || cx > 0.7 || cy < 0.25 || cy > 0.75) return "Center your face in the circle.";
            return null;
        }

        // tracks how still the face is between frames
        function makeSteadyTracker() {
            let last = null, count = 0;
            return {
                update(box) {
                    const vw = video.videoWidth;
                    if (last) {
                        const move = Math.hypot(
                            (box.x + box.width / 2) - (last.x + last.width / 2),
                            (box.y + box.height / 2) - (last.y + last.height / 2)
                        ) / vw;
                        const grow = Math.abs(box.width - last.width) / vw;
                        count = (move < LIVENESS.steadyMove && grow < LIVENESS.steadyMove) ? count + 1 : 0;
                    }
                    last = box;
                    return count;
                },
                reset() { last = null; count = 0; }
            };
        }

        // waits until check(measurements) is true. Frames with a bad / unclear face are ignored.
        async function waitFor(id, check, strict = true) {
            const t0 = performance.now();
            const steady = makeSteadyTracker();

            while (true) {
                if (id !== run) throw new Error("cancelled");
                checkIdent();
                if (performance.now() - t0 > LIVENESS.stageTimeout) throw new Error("timeout");

                const det = await faceapi.detectSingleFace(video, FAST_OPT).withFaceLandmarks();
                if (id !== run) throw new Error("cancelled");

                if (!det) {
                    steady.reset();
                    faceMsg.textContent = "No face detected. Stay inside the circle.";
                } else {
                    const problem = faceProblem(det, strict);
                    if (problem) {
                        steady.reset();
                        faceMsg.textContent = problem;
                    } else {
                        faceMsg.textContent = "";
                        const m = measure(det);
                        m.steadyCount = steady.update(det.detection.box);
                        if (check(m)) return m;
                    }
                }
                await nextFrame();
            }
        }

        // face print: only taken when the face is clear, straight AND held still
        async function captureDescriptor(id) {
            const t0 = performance.now();
            const steady = makeSteadyTracker();
            faceMsg.textContent = "Hold still…";

            while (performance.now() - t0 < LIVENESS.captureTimeout) {
                if (id !== run) throw new Error("cancelled");
                checkIdent();

                const quick = await faceapi.detectSingleFace(video, FAST_OPT).withFaceLandmarks();
                if (id !== run) throw new Error("cancelled");

                const problem = quick ? faceProblem(quick) : "No face detected. Stay inside the circle.";
                if (problem) {
                    steady.reset();
                    faceMsg.textContent = problem;
                    await nextFrame();
                    continue;
                }

                const m = measure(quick);
                const calm = steady.update(quick.detection.box);

                if (m.ratio < LIVENESS.centerMin || m.ratio > LIVENESS.centerMax) {
                    steady.reset();
                    faceMsg.textContent = "Look straight at the camera.";
                } else if (calm < LIVENESS.steadyFrames) {
                    faceMsg.textContent = "Hold still…";
                } else {
                    const full = await faceapi
                        .detectSingleFace(video, PRINT_OPT)
                        .withFaceLandmarks()
                        .withFaceDescriptor();
                    if (full && full.detection.score >= 0.7 && !faceProblem(full)) {
                        faceMsg.textContent = "";
                        return Array.from(full.descriptor);
                    }
                    steady.reset();
                }
                await nextFrame();
            }
            throw new Error("unsteady");
        }

        function makeCheck(stage) {
            let holdStart = 0, closed = false, closedAt = 0;
            const recentEar = [];

            switch (stage) {
                case "center":
                case "final":
                    return m => {
                        if (m.ratio < LIVENESS.centerMin || m.ratio > LIVENESS.centerMax || m.steadyCount < LIVENESS.steadyFrames) {
                            holdStart = 0;
                            return false;
                        }
                        if (!holdStart) holdStart = performance.now();
                        return performance.now() - holdStart >= LIVENESS.holdMs;
                    };
                case "right":
                    return m => m.ratio < LIVENESS.turnRight;
                case "left":
                    return m => m.ratio > LIVENESS.turnLeft;
                case "blink":
                    return m => {
                        if (m.ratio < 0.25 || m.ratio > 0.75) return false;
                        const now = performance.now();

                        if (recentEar.length < 4) {
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
                                recentEar.push(m.ear);
                                if (recentEar.length > 12) recentEar.shift();
                            }
                            return false;
                        }

                        if (m.ear > open * LIVENESS.blinkOpen) return true;
                        if (now - closedAt > 1500) closed = false;
                        return false;
                    };
            }
        }

        /* The server works out WHO it is while the person does the motion test. */
        let ident = null;   // {done, result, promise}

        function identError(r) {
            const e = new Error(r.netError ? "network" : r.locked ? "locked" : "denied");
            e.serverMessage = r.message;
            return e;
        }

        function checkIdent() {
            if (ident && ident.done && !ident.result.success) throw identError(ident.result);
        }

        function startIdentify(first) {
            ident = {done: false, result: null};
            ident.promise = postJSON({action: "face_identify", descriptor: compact(first)})
                .catch(() => ({success: false, netError: true}))
                .then(r => {
                    ident.done = true;
                    ident.result = r;
                    if (r.ms !== undefined) console.log("Face login: server identified you in " + r.ms + " ms");
                    return r;
                });
        }

        /* Short motion test: look straight -> ONE random move (turn right / turn left / blink)
           -> look straight again. The first face is sent to the server right away. */
        async function runLiveness(id) {
            const moves = ["right", "left", "blink"];
            const challenge = moves[Math.floor(Math.random() * moves.length)];
            const stages = ["center", challenge, "final"];
            let second = null;

            setRing(0);

            for (let i = 0; i < stages.length; i++) {
                const stage = stages[i];
                renderChips(stages, i);
                instruction.textContent = INSTRUCTIONS[stage];

                // straight-on stages need a clear, centered, steady face; turn / blink stages are looser
                const strict = stage === "center" || stage === "final";
                await waitFor(id, makeCheck(stage), strict);

                if (stage === "center") startIdentify(await captureDescriptor(id));
                if (stage === "final") second = await captureDescriptor(id);

                checkIdent();
                setRing((i + 1) / stages.length);
            }

            renderChips(stages, stages.length);
            return {second};
        }

        /* ================= "WELCOME" NOTIFICATION ================= */
        function showWelcome(name) {
            document.getElementById("welcomeText").textContent = "✅ Welcome, " + name + "!";
            bootstrap.Toast.getOrCreateInstance(document.getElementById("welcomeToast"), {delay: 1500}).show();
        }

        const compact = arr => arr.map(v => Math.round(v * 10000) / 10000);

        /* ================= FLOW: camera -> motion test -> SERVER decides ================= */
        async function startFlow() {
            if (faceLocked) {
                faceStart.disabled = true;
                faceMsg.textContent = "Too many failed attempts. Face login is locked.";
                return;
            }

            stopCamera();
            const id = ++run;

            ident = null;
            resetUi();
            faceStart.disabled = true;
            faceStart.textContent = "Scanning…";

            try {
                // open the camera and finish loading the models at the same time
                faceMsg.textContent = "Opening camera…";
                const [, modelsOk] = await Promise.all([openCamera(), loadModels()]);
                if (id !== run) {stopCamera(); return;}
                if (!modelsOk) throw new Error("models");

                cam.className = "liveness-wrap scanning";
                faceMsg.textContent = "";

                const {second} = await runLiveness(id);
                if (id !== run) return;

                stopCamera();
                const myId = run;

                instruction.textContent = "Verifying…";
                faceMsg.textContent = "Checking who you are…";

                // who it is was already worked out while you did the motion test
                const who = await ident.promise;
                if (myId !== run) return;
                if (!who.success) throw identError(who);

                const result = await postJSON({action: "face", descriptor2: compact(second)});
                if (myId !== run) return;

                if (!result.success) {
                    const err = new Error(result.locked ? "locked" : "denied");
                    err.serverMessage = result.message;
                    throw err;
                }

                cam.className = "liveness-wrap success";
                checkEl.hidden = false;
                instruction.textContent = "Verification complete!";
                faceMsg.textContent = "Face recognized!";
                showWelcome(result.name);
                setTimeout(() => (window.location.href = result.redirect), 500);

            } catch (e) {
                if (e.message === "cancelled" || id !== run) return;

                console.error("Face login error:", e);
                stopCamera();
                cam.className = "liveness-wrap error";
                instruction.textContent = "Verification failed";

                const name = e.name || "";
                faceMsg.textContent =
                    e.serverMessage ? e.serverMessage
                        : e.message === "timeout" ? "Time's up. Please try again and follow the instructions."
                            : e.message === "unsteady" ? "We couldn't get a steady, clear view. Hold still, face the camera, and try again."
                                : e.message === "noface" ? "We couldn't read your face. Try again in good lighting."
                                    : e.message === "models" ? "Face recognition could not load. Check your connection."
                                        : e.message === "network" ? "Could not reach the server. Please try again."
                                            : e.message === "insecure" ? "The camera only works on https:// or http://localhost. Open the site that way."
                                                : (e.message === "nocamera" || name === "NotFoundError" || name === "OverconstrainedError")
                                                    ? "No camera was found on this device."
                                                    : (name === "NotAllowedError" || name === "SecurityError")
                                                        ? "Camera access was blocked. Allow the camera in your browser, then try again."
                                                        : name === "NotReadableError" ? "The camera is being used by another app."
                                                            : "Could not open the camera. Please try again.";

                if (e.message === "locked") {
                    faceLocked = true;
                    faceStart.disabled = true;
                } else {
                    faceStart.disabled = false;
                    faceStart.textContent = "Try Again";
                }
            }
        }

        /* ================= SAVE OWN FACE FROM THE CAMERA (only employees with NO Valid ID photo, first login) ================= */
        function enrollLive(redirect) {
            enrollRedirect = redirect;
            resetUi();
            faceStart.style.display = "none";
            document.getElementById("faceTitle").textContent = "🍪 Save Your Face";
            faceMsg.textContent = "Opening camera…";
            faceModal.show();
        }

        async function runEnrollLive() {
            stopCamera();
            const id = ++run;
            try {
                const [, ok] = await Promise.all([openCamera(), loadModels()]);
                if (id !== run) {stopCamera(); return;}
                if (!ok) throw new Error("models");

                cam.className = "liveness-wrap scanning";
                instruction.textContent = "Save your face: look straight at the camera";
                const d = await captureDescriptor(id);
                stopCamera();

                const r = await postJSON({action: "enroll_live", descriptor: compact(d)});
                if (!r.success) throw new Error(r.message || "save");

                cam.className = "liveness-wrap success";
                checkEl.hidden = false;
                instruction.textContent = "Face saved!";
                faceMsg.textContent = "You can now log in with your face.";
                setTimeout(() => (window.location.href = enrollRedirect), 1200);
            } catch (e) {
                if (e.message === "cancelled" || id !== run) return;
                console.error("Face save error:", e);
                stopCamera();
                cam.className = "liveness-wrap error";
                instruction.textContent = "Face not saved";
                faceMsg.textContent = "Could not save your face. You can try again next login.";
                setTimeout(() => (window.location.href = enrollRedirect), 2500);
            }
        }

        /* ================= EVENTS ================= */
        document.getElementById("openFace").onclick = () => {
            enrollRedirect = null;
            resetUi();
            faceStart.style.display = "";
            document.getElementById("faceTitle").textContent = "🍪 Face Login";
            faceStart.textContent = "Scan My Face";
            faceStart.disabled = true;
            faceMsg.textContent = "Opening camera…";
            faceModal.show();
        };

        faceModalEl.addEventListener("shown.bs.modal", () => (enrollRedirect ? runEnrollLive() : startFlow()));
        faceModalEl.addEventListener("hidden.bs.modal", () => {
            stopCamera();
            if (enrollRedirect) window.location.href = enrollRedirect;   // closed early: just continue to the dashboard
        });
        window.addEventListener("pagehide", stopCamera);

        faceStart.onclick = startFlow;

        /* ================= SET UP OWN FACE FROM THE VALID ID =================
           Runs right after sign-up, and once after a password login when the saved face
           is missing or was made from the camera instead of the Valid ID. */
        async function enrollSelf() {
            if (!(await loadModels())) throw new Error("models");

            if (!faceapi.nets.ssdMobilenetv1.isLoaded) {
                await faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL);
            }

            // PHP only gives this account its OWN Valid ID photo from the uploads folder
            const img = await faceapi.fetchImage("login.php?myid=1");
            const det = await faceapi
                .detectSingleFace(img, new faceapi.SsdMobilenetv1Options({minConfidence: 0.5}))
                .withFaceLandmarks()
                .withFaceDescriptor();
            if (!det) throw new Error("No face found on the Valid ID photo.");

            const r = await postJSON({action: "enroll_self", descriptor: Array.from(det.descriptor)});
            if (!r.success) throw new Error(r.message || "Could not save the face.");
            return true;
        }

        /* ================= RIGHT AFTER SIGN-UP: save the face automatically =================
           signup.php redirects here; the face is read from the Valid ID in the background,
           so Face Login works immediately without logging in with a password first. */
        if (PENDING_FACE) {
            const faceOpenBtn = document.getElementById("openFace");
            const note = document.getElementById("login-error");
            faceOpenBtn.disabled = true;
            note.style.color = "#6f4529";
            note.textContent = "Setting up face login from your Valid ID…";

            window.addEventListener("load", async () => {
                try {
                    await enrollSelf();
                    note.textContent = "✅ Face login is ready. You can log in with your face now.";
                } catch (e) {
                    console.warn("Auto face setup failed:", e);
                    note.style.color = "";
                    note.textContent = "Face login setup failed (" + (e.message || "unknown") + "). Log in with your password once and it will retry.";
                }
                faceOpenBtn.disabled = false;
            });
        }
    </script>

</body>

</html>