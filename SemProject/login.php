<?php
/* ============================================================
   login.php  -  login page + server-side login check
   - Password login (admin + accounts saved by signup.php)
   - Face login with a SHORT motion test (look straight -> one random
     move: turn left / turn right / blink -> look straight)
   - Faces come from the "uploads" folder (next to this file):
        (1) the VALID ID photo of every user who signed up (user_verifications.valid_id_path)
        (2) hand-named photos:  juan.jpg / manager_maria.jpg
   - NO admin step needed: the first time an employee logs in with username + password,
     their own Valid ID photo (saved in uploads at sign-up) is read and saved for face login.
   Uses PostgreSQL through signupdb.php (db() must return a PDO).
   ============================================================ */

session_start();
require_once __DIR__ . '/signupdb.php';

const DEBUG = false;
const MAX_FAILS = 3;
const LOCK_SECONDS = 300;
const AFTER_LOGIN = 'staffdash.php';
const ADMIN_HOME = 'admindash.php';

// roles that can be enrolled for face login ('user' = people who signed up)
const FACE_ROLES = ['manager', 'employee', 'user'];
const FACE_MAX_DISTANCE = 0.5;    // lower = stricter
const FACE_MIN_GAP = 0.04;        // best match must beat a different person by this much
const FACE_SAME_PERSON = 0.55;    // the 2nd face (after the motion test) must also match
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
const ADMIN_PASSWORD = 'admin321';

/* ================= helpers ================= */

function jsonOut(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
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

// photo files that are already saved in face_profiles  (file name => true)
function enrolledFiles(): array
{
    try {
        $rows = db()->query('SELECT photo_file FROM face_profiles WHERE photo_file IS NOT NULL')
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($rows ?: [], true);
    } catch (PDOException $e) {
        return [];
    }
}

/* Every face that can be enrolled, read from the uploads folder.
   Built on the SERVER, so the browser cannot invent names, roles or files.
   1) users who signed up  -> role "user", Valid ID photo saved by signup.php
   2) hand-named photos    -> juan.jpg (employee), manager_maria.jpg (manager) */
function listEnrollFaces(): array
{
    $out = [];
    $used = [];
    $done = enrolledFiles();

    // 1) signed-up users (the Valid ID photo they uploaded at sign-up)
    try {
        $rows = db()->query(
            'SELECT u.id AS user_id, u.username, p.first_name, p.last_name, v.valid_id_path
               FROM users u
               JOIN user_verifications v ON v.user_id = u.id
          LEFT JOIN user_profiles p ON p.user_id = u.id
           ORDER BY v.user_id, v.valid_id_path'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $file = basename((string) $r['valid_id_path']);
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($file === '' || !isset(PHOTO_TYPES[$ext]) || !is_file(UPLOAD_DIR . '/' . $file)) {
                continue;
            }
            $full = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $out[] = [
                'file' => $file,
                'name' => $full !== '' ? $full : $r['username'],
                'role' => 'user',
                'username' => $r['username'],
                'user_id' => (int) $r['user_id'],
                'enrolled' => isset($done[$file]),
            ];
            $used[$file] = true;
        }
    } catch (PDOException $e) {
        error_log('list signup faces: ' . $e->getMessage());
    }

    // 2) hand-named photos in uploads (skip files signup.php made: id_xxx / face_xxx)
    if (is_dir(UPLOAD_DIR)) {
        foreach (scandir(UPLOAD_DIR) ?: [] as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!isset(PHOTO_TYPES[$ext]) || !is_file(UPLOAD_DIR . '/' . $file) || isset($used[$file])) {
                continue;
            }
            $base = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^(id|face)_[0-9a-f]{16}$/i', $base)) {
                continue;
            }
            $role = 'employee';
            $uname = $base;
            if (preg_match('/^(manager|employee)[_-](.+)$/i', $base, $m)) {
                $role = strtolower($m[1]);
                $uname = $m[2];
            }
            $out[] = [
                'file' => $file,
                'name' => $uname,
                'role' => $role,
                'username' => $uname,
                'user_id' => null,
                'enrolled' => isset($done[$file]),
            ];
        }
    }
    return $out;
}

/* All enrolled faces together with their account, in ONE query.
   user_id links a face to the users table; older rows fall back to the username. */
function loadFaceRows(): array
{
    $sql = 'SELECT f.id, f.name, f.role, f.username, f.descriptor,
                   u.id AS uid, u.username AS real_username
              FROM face_profiles f
         LEFT JOIN users u ON u.id = f.user_id
                          OR (f.user_id IS NULL AND LOWER(u.username) = LOWER(f.username))';

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

/* Does this account already have face data saved? */
function faceEnrolledFor(int $uid, string $username): bool
{
    try {
        $st = db()->prepare(
            'SELECT 1 FROM face_profiles
              WHERE user_id = :u OR (user_id IS NULL AND LOWER(username) = LOWER(:n))
              LIMIT 1'
        );
        $st->execute([':u' => $uid, ':n' => $username]);
        return $st->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;     // table/column not there yet -> not enrolled
    }
}

/* ================= GET routes (admin only) ================= */

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

// streams a photo from the uploads folder to the enroll page
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['enrollimg'])) {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit;
    }
    $file = basename((string) $_GET['enrollimg']);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $path = UPLOAD_DIR . '/' . $file;
    if (isset(PHOTO_TYPES[$ext]) && is_file($path)) {
        header('Content-Type: ' . PHOTO_TYPES[$ext]);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit;
}

// the logged-in employee's OWN Valid ID photo (so the browser can read the face once)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['myid'])) {
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if (($_SESSION['role'] ?? '') !== 'user' || $uid <= 0) {
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
    header('Content-Type: application/json; charset=utf-8');

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string) ($in['action'] ?? 'login');

    /* ---------- ENROLL (admin only, incremental) ---------- */
    if ($action === 'enroll') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            jsonOut(['success' => false, 'message' => 'Admin login required.'], 403);
        }

        $faces = $in['faces'] ?? [];
        if (!is_array($faces)) {
            $faces = [];
        }
        $rebuild = !empty($in['rebuild']);

        // name / role / username come from the server list, not from the browser
        $allowed = [];
        foreach (listEnrollFaces() as $e) {
            $allowed[$e['file']] = $e;
        }

        $saved = 0;
        try {
            $pdo = db();
            ensureFaceTable();
            $pdo->beginTransaction();

            // remove faces whose photo is gone (or everything when rebuilding)
            $del = $pdo->prepare('DELETE FROM face_profiles WHERE id = :i');
            $existing = $pdo->query('SELECT id, photo_file FROM face_profiles')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($existing as $r) {
                if ($rebuild || $r['photo_file'] === null || !isset($allowed[$r['photo_file']])) {
                    $del->execute([':i' => $r['id']]);
                }
            }

            $delFile = $pdo->prepare('DELETE FROM face_profiles WHERE photo_file = :f');
            $ins = $pdo->prepare(
                'INSERT INTO face_profiles (name, role, username, user_id, descriptor, photo, photo_mime, photo_file)
                 VALUES (:n, :r, :u, :uid, :d, :p, :m, :f)'
            );

            foreach ($faces as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $file = basename((string) ($f['file'] ?? ''));
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $path = UPLOAD_DIR . '/' . $file;

                if (
                    !isset($allowed[$file])
                    || !in_array($allowed[$file]['role'], FACE_ROLES, true)
                    || !validDescriptor($f['descriptor'] ?? null)
                    || !isset(PHOTO_TYPES[$ext]) || !is_file($path) || filesize($path) > MAX_PHOTO_BYTES
                ) {
                    continue;
                }

                $fp = fopen($path, 'rb');
                if ($fp === false) {
                    continue;
                }

                $info = $allowed[$file];
                $delFile->execute([':f' => $file]);

                $ins->bindValue(':n', $info['name']);
                $ins->bindValue(':r', $info['role']);
                $ins->bindValue(':u', $info['username'] !== '' ? $info['username'] : null);
                $ins->bindValue(':uid', $info['user_id'] ?? null, !empty($info['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $ins->bindValue(':d', json_encode(array_values($f['descriptor'])));
                $ins->bindParam(':p', $fp, PDO::PARAM_LOB);
                $ins->bindValue(':m', PHOTO_TYPES[$ext]);
                $ins->bindValue(':f', $file);
                $ins->execute();
                fclose($fp);
                $saved++;
            }

            $total = (int) $pdo->query('SELECT COUNT(*) FROM face_profiles')->fetchColumn();
            $pdo->commit();
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            dbFail($e);
        }

        jsonOut(['success' => true, 'saved' => $saved, 'total' => $total]);
    }

    /* ---------- ENROLL SELF (logged-in employee, from their OWN Valid ID) ---------- */
    if ($action === 'enroll_self') {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        if (($_SESSION['role'] ?? '') !== 'user' || $uid <= 0) {
            jsonOut(['success' => false, 'message' => 'Please log in first.'], 403);
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
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM face_profiles WHERE user_id = :u OR photo_file = :f')
                ->execute([':u' => $uid, ':f' => $file]);

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
            if (is_resource($fp)) {
                fclose($fp);
            }
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
       server works while the person does the motion test. */
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
                'message' => 'Face login is not set up yet. Log in once with your username and password and it is set up automatically from your Valid ID.'
            ], 409);
        }

        $list = array_values($best);
        usort($list, fn($a, $b) => $a['dist'] <=> $b['dist']);

        $first = $list[0];
        $secondDist = $list[1]['dist'] ?? INF;

        if ($first['dist'] > FACE_MAX_DISTANCE || ($secondDist - $first['dist']) < FACE_MIN_GAP) {
            failAndMaybeLock('Face not recognized.');
        }

        // the account name that will be shown: the real username from the users table
        $p = $first['row'];
        $display = !empty($p['real_username']) ? $p['real_username']
            : (!empty($p['username']) ? $p['username'] : $p['name']);

        // remembered on the SERVER only (the browser cannot change it)
        $_SESSION['face_candidate'] = [
            'ref' => $first['ref'],
            'user_id' => $p['uid'] !== null ? (int) $p['uid'] : null,
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

        // the face after the motion test must be the SAME person
        if (euclid($descriptor2, $cand['ref']) > FACE_SAME_PERSON) {
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

    // admin: if there are Valid ID photos that are not enrolled yet, read them now (automatic)
    if ($role === 'admin') {
        try {
            ensureFaceTable();
            foreach (listEnrollFaces() as $f) {
                if (!$f['enrolled']) {
                    $redirect = 'login.php?enroll=1&next=' . urlencode(ADMIN_HOME);
                    break;
                }
            }
        } catch (Throwable $e) {
            error_log('admin auto-enroll check: ' . $e->getMessage());
        }
    }

    // employee whose Valid ID face is not saved yet: the browser will read it now (one time)
    $enrollMe = false;
    if ($role === 'user' && $userId) {
        $enrollMe = userIdFile($userId) !== null && !faceEnrolledFor($userId, $username);
    }

    jsonOut(['success' => true, 'redirect' => $redirect, 'enroll_me' => $enrollMe]);
}

// admin: login.php?enroll=1 reads the uploads folder and saves the new faces
$enrollMode = (($_GET['enroll'] ?? '') === '1') && (($_SESSION['role'] ?? '') === 'admin');
$next = (($_GET['next'] ?? '') === ADMIN_HOME) ? ADMIN_HOME : '';   // only the admin dashboard is allowed
$uploadFaces = [];
if ($enrollMode) {
    try {
        ensureFaceTable();
    } catch (PDOException $e) {
        error_log('ensureFaceTable: ' . $e->getMessage());
    }
    $uploadFaces = listEnrollFaces();
}
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
        href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@400;500;600;700;800&family=Quicksand:wght@400;500;600;700&family=Saira:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="assets/style/login.css">

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

        .face-btn:disabled,
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

        /* ---- circular camera with progress ring ---- */
        .liveness-wrap {
            position: relative;
            width: 240px;
            height: 240px;
            margin: 6px auto 10px;
        }

        .liveness-ring {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
        }

        .ring-track {
            fill: none;
            stroke: #e8cdbd;
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
            transition: stroke-dashoffset .25s ease, stroke .3s;
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
        }

        .liveness-circle video {
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
            font-weight: 700;
            font-size: 1.05rem;
            margin: 6px 0 0;
            min-height: 1.5em;
            color: #6b4937;
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
            background: #fff4ed;
            color: #8a6a57;
        }

        .live-chip.current {
            background: #fff3cd;
            color: #664d03;
        }

        .live-chip.done {
            background: #d1e7dd;
            color: #0f5132;
        }

        #faceMsg {
            min-height: 1.6em;
            margin: 8px 0 14px;
            font-weight: 700;
            color: #6b4937;
        }

        .enroll-box {
            margin-top: 16px;
            padding: 12px;
            border: 1px dashed #80583d;
            border-radius: 10px;
            background: #fff4ed;
            color: #6b4937;
            font-size: 14px;
        }

        .enroll-box button {
            margin-top: 8px;
        }
    </style>
</head>

<body>

    <main class="login-box">

        <div class="cookie-panel" aria-hidden="true"></div>

        <section class="login-panel">

            <div class="brand">
                <h1>🍪 Login</h1>
                <p class="login-subtitle">Welcome back, sweet tooth!</p>
            </div>

            <form id="myForm" novalidate>

                <div class="input-box">
                    <input type="text" class="form-control" name="username" id="username"
                        placeholder="Username or Email" autocomplete="username">
                    <i class="fa-solid fa-circle-user user-icon"></i>
                </div>

                <div class="input-box">
                    <input type="password" class="form-control" name="password" id="password" placeholder="Password"
                        autocomplete="current-password">
                    <i class="fa-solid fa-lock pass-icon"></i>
                </div>

                <div class="forgot-wrapper">
                    <a href="forgotpassword.php" class="forgot">Forgot Password?</a>
                </div>

                <div id="login-error" role="alert"></div>

                <button type="submit" class="login-btn">Login</button>

                <div class="or-divider">or</div>
                <button type="button" class="face-btn" id="openFace">
                    <i class="fa-solid fa-face-smile"></i> Login with Face
                </button>

                <p class="signup-text">
                    Don't have an account yet?
                    <a href="signup.php" class="signup-link">Sign Up</a>
                </p>

            </form>

            <?php if ($enrollMode):
                $newCount = count(array_filter($uploadFaces, fn($f) => !$f['enrolled'])); ?>
                <div class="enroll-box">
                    <strong>Admin: enroll faces</strong><br>
                    Photos in <code>uploads</code>: <?= count($uploadFaces) ?>
                    (<?= $newCount ?> new).
                    Only new photos are processed, so this is quick. It starts by itself when there is something new.
                    <button type="button" class="face-btn" id="enrollBtn">Save new faces to database</button>
                    <button type="button" class="face-btn" id="rebuildBtn">Rebuild all faces</button>
                    <p id="enrollMsg" class="mb-0 mt-2"></p>
                    <?php if ($next): ?>
                        <p class="mb-0 mt-2"><a href="<?= htmlspecialchars($next) ?>">Skip to the dashboard</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

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
                    username: {required: "Please enter your username or email."},
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
                                if (result.enroll_me) {
                                    // first login: read the face on the employee's own Valid ID (one time only)
                                    $("#login-error").text("Setting up face login from your Valid ID (one time only)…");
                                    Promise.race([
                                        enrollSelf(),
                                        new Promise((_, rej) => setTimeout(() => rej(new Error("slow")), 25000))
                                    ])
                                        .catch(e => console.warn("Face setup skipped:", e))
                                        .finally(() => (window.location.href = result.redirect));
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

    <!-- Face recognition login (fast motion test) -->
    <script>
        /* =====================================================================
           WHO CAN LOG IN WITH THEIR FACE
           The photos live in the "uploads" folder (next to login.php):
           - everyone who signed up (the Valid ID photo they uploaded)
           - hand-named photos:  juan.jpg / manager_maria.jpg
           Admin: log in, open  login.php?enroll=1  (new photos are saved
           automatically, old ones are skipped).
           ===================================================================== */
        const UPLOAD_FACES = <?= json_encode($uploadFaces, JSON_UNESCAPED_SLASHES) ?>;
        const NEXT_URL = <?= json_encode($next) ?>;   // where the admin goes after the automatic enroll

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
            stageTimeout: 12000
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
            try {
                return await res.json();
            } catch {
                return {success: false, message: "Server error. Check the PHP error log."};
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

        // waits until check(measurements) is true; shows hints when the face is badly placed
        async function waitFor(id, check) {
            const t0 = performance.now();

            while (true) {
                if (id !== run) throw new Error("cancelled");
                checkIdent();
                if (performance.now() - t0 > LIVENESS.stageTimeout) throw new Error("timeout");

                const det = await faceapi.detectSingleFace(video, FAST_OPT).withFaceLandmarks();
                if (id !== run) throw new Error("cancelled");

                if (!det) {
                    faceMsg.textContent = "No face detected. Stay inside the circle.";
                } else {
                    const m = measure(det);
                    const w = m.box.width / video.videoWidth;
                    const cx = (m.box.x + m.box.width / 2) / video.videoWidth;

                    if (w < 0.2) faceMsg.textContent = "Move a little closer.";
                    else if (w > 0.85) faceMsg.textContent = "Move a little farther.";
                    else if (cx < 0.2 || cx > 0.8) faceMsg.textContent = "Center your face in the circle.";
                    else {
                        faceMsg.textContent = "";
                        if (check(m)) return m;
                    }
                }
                await nextFrame();
            }
        }

        // face print of the person currently looking at the camera
        async function captureDescriptor(id) {
            for (let tries = 0; tries < 10; tries++) {
                if (id !== run) throw new Error("cancelled");
                const full = await faceapi
                    .detectSingleFace(video, PRINT_OPT)
                    .withFaceLandmarks()
                    .withFaceDescriptor();
                if (full && full.detection.score >= 0.55) return Array.from(full.descriptor);
                await nextFrame();
            }
            throw new Error("noface");
        }

        function makeCheck(stage) {
            let holdStart = 0, closed = false, closedAt = 0;
            const recentEar = [];

            switch (stage) {
                case "center":
                case "final":
                    return m => {
                        if (m.ratio < LIVENESS.centerMin || m.ratio > LIVENESS.centerMax) {
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

                await waitFor(id, makeCheck(stage));

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

        /* ================= EVENTS ================= */
        document.getElementById("openFace").onclick = () => {
            resetUi();
            faceStart.textContent = "Scan My Face";
            faceStart.disabled = true;
            faceMsg.textContent = "Opening camera…";
            faceModal.show();
        };

        faceModalEl.addEventListener("shown.bs.modal", startFlow);
        faceModalEl.addEventListener("hidden.bs.modal", stopCamera);
        window.addEventListener("pagehide", stopCamera);

        faceStart.onclick = startFlow;

        /* ================= EMPLOYEE: SET UP OWN FACE FROM THE VALID ID (automatic) ================= */
        async function enrollSelf() {
            if (!(await loadModels())) throw new Error("models");

            if (!faceapi.nets.ssdMobilenetv1.isLoaded) {
                await faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL);
            }

            // PHP only gives this employee their OWN Valid ID photo from the uploads folder
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

        /* ================= ADMIN: ENROLL FACES (login.php?enroll=1) ================= */
        const enrollBtn = document.getElementById("enrollBtn");
        const rebuildBtn = document.getElementById("rebuildBtn");

        async function enroll(rebuild) {
            const msg = document.getElementById("enrollMsg");
            enrollBtn.disabled = rebuildBtn.disabled = true;
            msg.textContent = "Loading face recognition…";

            try {
                // only photos that are not saved yet (everything when rebuilding)
                const todo = UPLOAD_FACES.filter(p => rebuild || !p.enrolled);

                const faces = [], skipped = [];

                if (todo.length) {
                    if (!(await loadModels())) throw new Error("Face models could not load. Check your connection.");

                    // the more accurate detector is only needed here, for the still photos
                    if (!faceapi.nets.ssdMobilenetv1.isLoaded) {
                        await faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL);
                    }

                    let n = 0;
                    for (const p of todo) {
                        msg.textContent = "Reading " + p.file + " (" + (++n) + "/" + todo.length + ")…";
                        try {
                            // PHP streams the picture from the uploads folder (admin only)
                            const img = await faceapi.fetchImage("login.php?enrollimg=" + encodeURIComponent(p.file));
                            const det = await faceapi
                                .detectSingleFace(img, new faceapi.SsdMobilenetv1Options({minConfidence: 0.5}))
                                .withFaceLandmarks()
                                .withFaceDescriptor();
                            if (det) {
                                faces.push({file: p.file, descriptor: Array.from(det.descriptor)});
                            } else {
                                skipped.push(p.file + " (no face found)");
                            }
                        } catch {
                            skipped.push(p.file + " (cannot load)");
                        }
                    }
                }

                msg.textContent = "Saving to the database…";
                const r = await postJSON({action: "enroll", faces, rebuild});
                if (r.success) {
                    msg.textContent = "Saved " + r.saved + " new face(s). " + r.total + " face(s) enrolled in total."
                        + (skipped.length ? " Skipped: " + skipped.join(", ") : "");
                    UPLOAD_FACES.forEach(p => {
                        if (faces.some(f => f.file === p.file)) p.enrolled = true;
                    });
                    // automatic run after the admin logged in: continue to the dashboard
                    if (NEXT_URL && !skipped.length) {
                        msg.textContent += " Opening the dashboard…";
                        setTimeout(() => (window.location.href = NEXT_URL), 1500);
                    }
                } else {
                    msg.textContent = r.message || "Could not save the faces.";
                }
            } catch (e) {
                msg.textContent = e.message === "network" ? "Could not reach the server." : (e.message || "Enrollment failed.");
            }

            enrollBtn.disabled = rebuildBtn.disabled = false;
        }

        if (enrollBtn) {
            enrollBtn.onclick = () => enroll(false);
            rebuildBtn.onclick = () => enroll(true);

            // start by itself when there are new photos
            if (UPLOAD_FACES.some(p => !p.enrolled)) {
                window.addEventListener("load", () => enroll(false));
            }
        }
    </script>

</body>

</html>