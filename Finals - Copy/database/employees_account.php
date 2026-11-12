<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/* ---------------------------------------------------------------
   1) Connect to the SAME database that sign-up and login use.
   signupdb.php (project root) gives us db(), a PDO connection.
   This file lives in /database, so the root is one folder up.
---------------------------------------------------------------- */
if (!function_exists('db')) {
    foreach ([__DIR__ . '/../signupdb.php', __DIR__ . '/signupdb.php', __DIR__ . '/../../signupdb.php'] as $file) {
        if (is_file($file)) {
            try { require_once $file; } catch (Throwable $e) { error_log('employees_account: ' . $e->getMessage()); }
            break;
        }
    }
}

$empPdo = null;
if (function_exists('db')) {
    try { $empPdo = db(); } catch (Throwable $e) { error_log('employees_account db(): ' . $e->getMessage()); }
}

/* Older setups: fall back to a $conn (mysqli) / $pdo that the including page already made,
   or try the usual connection file names. */
if (!$empPdo && !(isset($conn) && $conn instanceof mysqli) && !(isset($pdo) && $pdo instanceof PDO)) {
    foreach (['db.php', 'config.php', 'connection.php', 'conn.php', 'database.php'] as $file) {
        foreach ([__DIR__ . '/' . $file, __DIR__ . '/../' . $file, __DIR__ . '/../../' . $file] as $candidate) {
            if (is_file($candidate)) {
                try { require_once $candidate; } catch (Throwable $e) {}
                if ((isset($conn) && $conn instanceof mysqli) || (isset($pdo) && $pdo instanceof PDO)) {
                    break 2;
                }
            }
        }
    }
}
if (!$empPdo && isset($pdo) && $pdo instanceof PDO) {
    $empPdo = $pdo;
}
$empConn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!function_exists('fetchEmployeeRow')) {
    /* Runs one prepared query on whichever connection exists and returns the first row (or null) */
    function fetchEmployeeRow($conn, $pdo, string $sql, string $types, $param)
    {
        try {
            if ($pdo instanceof PDO) {
                $stmt = $pdo->prepare($sql);
                if ($stmt && $stmt->execute([$param])) {
                    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                return null;
            }
            if ($conn instanceof mysqli) {
                $stmt = $conn->prepare($sql);
                if (!$stmt) { return null; }
                $stmt->bind_param($types, $param);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                return $row;
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }
}

/* ---------------------------------------------------------------
   2) Who is logged in? login.php stores user_id + username in the session.
   This file only READS the session. It never writes the name or
   role back, so it can't affect the admin pages.
---------------------------------------------------------------- */
$employeeName    = '';
$employeeRole    = '';
$employeePicture = '';

$sessionName = '';
foreach (['username', 'user_name', 'employee_name'] as $key) {
    if (!empty($_SESSION[$key])) {
        $sessionName = (string) $_SESSION[$key];
        break;
    }
}

$sessionId = 0;
foreach (['user_id', 'id', 'employee_id'] as $key) {
    if (!empty($_SESSION[$key])) {
        $sessionId = (int) $_SESSION[$key];
        break;
    }
}

/* ---------------------------------------------------------------
   3) Read the sign-up account from the `users` table.
   Looks up by id first, then by username (face login can have no id).
   Each query is tried with fewer columns, in case `role` or
   `profile_picture` doesn't exist in your table.
---------------------------------------------------------------- */
$employeeRow = null;
$lookups = [];
if ($sessionId > 0)      { $lookups[] = ['id = ?', 'i', $sessionId]; }
if ($sessionName !== '') { $lookups[] = ['LOWER(username) = LOWER(?)', 's', $sessionName]; }

foreach ($lookups as [$where, $types, $param]) {
    foreach ([
        "SELECT username, role, profile_picture FROM users WHERE $where LIMIT 1",
        "SELECT username, role FROM users WHERE $where LIMIT 1",
        "SELECT username FROM users WHERE $where LIMIT 1",
    ] as $sql) {
        $employeeRow = fetchEmployeeRow($empConn, $empPdo, $sql, $types, $param);
        if ($employeeRow) { break 2; }
    }
}

if ($employeeRow) {
    $employeeName = (string) ($employeeRow['username'] ?? '');
    $employeeRole = (string) ($employeeRow['role'] ?? '');

    // only accept a real image data URL, so nothing else can end up in the <img src>
    $pic = (string) ($employeeRow['profile_picture'] ?? '');
    if ($pic !== '' && preg_match('#^data:image/(?:jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $pic)) {
        $employeePicture = $pic;
    }
}

// "user" is just the internal login type for signed-up people: show it as Employee
$roleLabel = strtolower(trim($employeeRole));
if ($roleLabel === '' || $roleLabel === 'user') {
    $roleLabel = 'Employee';
} else {
    $roleLabel = ucfirst($roleLabel);
}

return [
    'username' => $employeeName !== '' ? $employeeName : ($sessionName !== '' ? $sessionName : 'Username'),
    'role'     => $roleLabel,
    'picture'  => $employeePicture,
];