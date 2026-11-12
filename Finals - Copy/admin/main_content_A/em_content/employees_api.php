<?php
// admin/em_content/employees_api.php
// JSON endpoint for the Employees Management page.
// Reads and writes the same PostgreSQL tables that signup.php fills:
// users, user_profiles, user_verifications, id_types.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail(string $message, int $code = 422): void
{
    respond(['success' => false, 'message' => $message], $code);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed.', 405);
}

/* ============================================================
   WHO IS SIGNED IN
   Used for the "Created By" value of accounts added by an admin.
   If your login stores the name under another session key, add
   that key to this list.
============================================================ */
function sessionUserName(array $session): string
{
    foreach (['username', 'user_name', 'admin_username', 'admin_name', 'name', 'user', 'admin', 'email'] as $key) {
        $value = $session[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return mb_substr(trim($value), 0, 100);
        }
        if (is_array($value)) {
            foreach (['username', 'name', 'email'] as $sub) {
                if (isset($value[$sub]) && is_string($value[$sub]) && trim($value[$sub]) !== '') {
                    return mb_substr(trim($value[$sub]), 0, 100);
                }
            }
        }
    }
    return 'Admin';
}

/* ============================================================
   ACCESS CHECK
   The Employees page stores a random token in the session when
   it is opened. Requests without that token are refused.
============================================================ */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$expectedToken = (string) ($_SESSION['em_token'] ?? '');
$sentToken = (string) ($_SERVER['HTTP_X_EM_TOKEN'] ?? '');
$creatorName = sessionUserName($_SESSION);
session_write_close();

if ($expectedToken === '' || !hash_equals($expectedToken, $sentToken)) {
    fail('Your session has expired. Please reload the page.', 403);
}

/* ============================================================
   DATABASE CONNECTION (your signupdb.php)
   The first path that exists is used. Edit this list if your
   signupdb.php lives somewhere else.
============================================================ */
$dbFile = null;
foreach ([
    __DIR__ . '/../../signupdb.php',
    __DIR__ . '/../signupdb.php',
    __DIR__ . '/signupdb.php',
    rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/Inventory/signupdb.php',
] as $candidate) {
    if (is_file($candidate)) {
        $dbFile = realpath($candidate);
        break;
    }
}
if ($dbFile === null) {
    fail('signupdb.php was not found. Check the path list at the top of employees_api.php.', 500);
}
require_once $dbFile;

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    fail('Invalid request.', 400);
}

/* ============================================================
   SCHEMA DETECTION
   Works with the columns your users table really has.
============================================================ */
function emConfig(PDO $pdo): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $st = $pdo->query(
        "SELECT column_name, data_type
           FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = 'users'"
    );
    $cols = [];
    foreach ($st->fetchAll() as $row) {
        $cols[strtolower($row['column_name'])] = strtolower($row['data_type']);
    }

    $textTypes = ['text', 'character varying', 'character'];

    $cfg = [
        'activeExpr' => '1',          // no status column: everyone counts as active
        'statusCol' => null,          // column that stores the status (null = not editable)
        'statusExpr' => null,         // SQL value used when writing the status
        'statusParam' => null,        // 'int' or 'text'
        'createdExpr' => 'NULL',
        'hasCreatedAt' => false,
        'createdByExpr' => 'NULL',
        'hasCreatedBy' => false,
        'roleWhere' => '',
    ];

    if (isset($cols['is_active'])) {
        $cfg['activeExpr'] = "CASE WHEN CAST(u.is_active AS TEXT) IN ('t','true','1') THEN 1 ELSE 0 END";
        if ($cols['is_active'] === 'boolean') {
            $cfg['statusCol'] = 'is_active';
            $cfg['statusExpr'] = '(CAST(:active AS INTEGER) = 1)';
            $cfg['statusParam'] = 'int';
        } elseif (in_array($cols['is_active'], ['smallint', 'integer', 'bigint'], true)) {
            $cfg['statusCol'] = 'is_active';
            $cfg['statusExpr'] = 'CAST(:active AS INTEGER)';
            $cfg['statusParam'] = 'int';
        }
    } elseif (isset($cols['status'])) {
        $cfg['activeExpr'] = "CASE WHEN LOWER(CAST(u.status AS TEXT)) = 'active' THEN 1 ELSE 0 END";
        if (in_array($cols['status'], $textTypes, true)) {
            $cfg['statusCol'] = 'status';
            $cfg['statusExpr'] = ':status';
            $cfg['statusParam'] = 'text';
        }
    }

    if (isset($cols['created_at'])) {
        $cfg['createdExpr'] = "to_char(u.created_at, 'YYYY-MM-DD')";
        $cfg['hasCreatedAt'] = true;
    }

    // created_by must be a text column (it stores the creator's username)
    if (isset($cols['created_by']) && in_array($cols['created_by'], $textTypes, true)) {
        $cfg['createdByExpr'] = 'u.created_by';
        $cfg['hasCreatedBy'] = true;
    }

    // never list or touch administrator accounts
    if (isset($cols['role'])) {
        $cfg['roleWhere'] = "LOWER(COALESCE(CAST(u.role AS TEXT), '')) NOT IN ('admin', 'administrator')";
    }

    return $cfg;
}

function fetchEmployees(PDO $pdo, array $cfg, ?int $id = null): array
{
    $conds = [];
    $params = [];

    if ($cfg['roleWhere'] !== '') {
        $conds[] = $cfg['roleWhere'];
    }
    if ($id !== null) {
        $conds[] = 'u.id = :id';
        $params[':id'] = $id;
    }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $sql = "SELECT u.id, u.username, u.email,
                   {$cfg['activeExpr']} AS is_active,
                   {$cfg['createdExpr']} AS created_at,
                   CAST({$cfg['createdByExpr']} AS TEXT) AS created_by,
                   p.first_name, p.middle_name, p.last_name, p.extension_name,
                   to_char(p.birth_date, 'YYYY-MM-DD') AS birth_date,
                   p.phone,
                   (SELECT t.name
                      FROM user_verifications v
                      JOIN id_types t ON t.id = v.id_type_id
                     WHERE v.user_id = u.id
                     LIMIT 1) AS id_type
              FROM users u
         LEFT JOIN user_profiles p ON p.user_id = u.id
              $where
          ORDER BY LOWER(COALESCE(p.last_name, u.username)), LOWER(COALESCE(p.first_name, ''))";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'] === 1;
    }
    unset($row);

    return $rows;
}

/** Stops with 404 when the employee does not exist (or is an administrator). */
function assertManageable(PDO $pdo, array $cfg, int $id): void
{
    $sql = 'SELECT 1 FROM users u WHERE u.id = :id'
        . ($cfg['roleWhere'] !== '' ? ' AND ' . $cfg['roleWhere'] : '');
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);

    if (!$st->fetchColumn()) {
        fail('Employee not found.', 404);
    }
}

function validBirthDate(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) {
        return false;
    }
    $today = new DateTime('today');
    return $dt <= $today && (int) $dt->diff($today)->y < 120;
}

function truthy($v): bool
{
    return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
}

/** Reads and validates the fields shared by "create" and "update". */
function readProfile(array $in): array
{
    $str = fn(string $k): string => trim((string) ($in[$k] ?? ''));

    $p = [
        'username' => $str('username'),
        'email' => $str('email'),
        'first' => $str('firstName'),
        'middle' => $str('middleName'),
        'last' => $str('lastName'),
        'ext' => $str('extension'),
        'birth' => $str('birthDate'),
        'phone' => $str('phone'),
    ];

    foreach (['username', 'email', 'first', 'last', 'birth', 'phone'] as $required) {
        if ($p[$required] === '') {
            fail('Please fill in all required fields.');
        }
    }
    foreach (['username', 'email', 'first', 'middle', 'last', 'ext', 'phone'] as $text) {
        if (mb_strlen($p[$text]) > 100) {
            fail('One of the fields is too long (100 characters maximum).');
        }
    }
    if (!filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
        fail('Invalid email address.');
    }
    if (!validBirthDate($p['birth'])) {
        fail('Invalid birth date.');
    }

    return $p;
}

/** Returns "" when the username and email are free, otherwise what is taken. */
function accountTakenMessage(PDO $pdo, string $username, string $email, ?int $exceptId = null): string
{
    $st = $pdo->prepare(
        'SELECT (LOWER(username) = LOWER(:u)) AS u_taken, (LOWER(email) = LOWER(:e)) AS e_taken
           FROM users
          WHERE id <> :id AND (LOWER(username) = LOWER(:u2) OR LOWER(email) = LOWER(:e2))'
    );
    $st->execute([
        ':u' => $username,
        ':e' => $email,
        ':id' => $exceptId ?? 0,
        ':u2' => $username,
        ':e2' => $email,
    ]);

    $userTaken = false;
    $emailTaken = false;
    foreach ($st->fetchAll() as $row) {
        $userTaken = $userTaken || truthy($row['u_taken']);
        $emailTaken = $emailTaken || truthy($row['e_taken']);
    }

    if ($userTaken) {
        return 'That username is already taken.';
    }
    if ($emailTaken) {
        return 'That email is already registered.';
    }
    return '';
}

function deleteUpload(string $dbFile, ?string $name): void
{
    if ($name === null || $name === '') {
        return;
    }
    $path = dirname($dbFile) . '/uploads/' . basename($name);
    if (is_file($path)) {
        @unlink($path);
    }
}

/* ============================================================
   ACTIONS
============================================================ */
try {
    $pdo = db();
    $cfg = emConfig($pdo);
    $nullIfEmpty = fn(string $v) => $v === '' ? null : $v;

    switch ((string) ($in['action'] ?? '')) {

        /* ----- list ----- */
        case 'list':
            respond([
                'success' => true,
                'employees' => fetchEmployees($pdo, $cfg),
                'meta' => [
                    'statusEditable' => $cfg['statusCol'] !== null,
                    'hasCreatedAt' => $cfg['hasCreatedAt'],
                    'hasCreatedBy' => $cfg['hasCreatedBy'],
                    'currentUser' => $creatorName,
                ],
            ]);

        /* ----- create (Add Employee) ----- */
        case 'create':
            $p = readProfile($in);

            $password = (string) ($in['newPassword'] ?? '');
            $confirm = (string) ($in['confirmPassword'] ?? '');
            if (strlen($password) < 8) {
                fail('Password must be at least 8 characters.');
            }
            if ($password !== $confirm) {
                fail('Passwords do not match.');
            }

            $taken = accountTakenMessage($pdo, $p['username'], $p['email']);
            if ($taken !== '') {
                fail($taken, 409);
            }

            try {
                $pdo->beginTransaction();

                // users (created_by and the status are only written when those columns exist)
                $cols = ['username', 'email', 'password_hash'];
                $vals = [':username', ':email', ':hash'];
                $params = [
                    ':username' => $p['username'],
                    ':email' => $p['email'],
                    ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ];

                if ($cfg['hasCreatedBy']) {
                    $cols[] = 'created_by';
                    $vals[] = ':created_by';
                    $params[':created_by'] = $creatorName;
                }
                if ($cfg['statusCol'] !== null && array_key_exists('isActive', $in)) {
                    $cols[] = $cfg['statusCol'];
                    $vals[] = $cfg['statusExpr'];
                    if ($cfg['statusParam'] === 'text') {
                        $params[':status'] = !empty($in['isActive']) ? 'active' : 'inactive';
                    } else {
                        $params[':active'] = !empty($in['isActive']) ? 1 : 0;
                    }
                }

                $st = $pdo->prepare(
                    'INSERT INTO users (' . implode(', ', $cols) . ')
                     VALUES (' . implode(', ', $vals) . ')
                     RETURNING id'
                );
                $st->execute($params);
                $newId = (int) $st->fetchColumn();

                // user_profiles
                $st = $pdo->prepare(
                    'INSERT INTO user_profiles
                        (user_id, first_name, middle_name, last_name, extension_name, birth_date, phone)
                     VALUES (:uid, :first, :middle, :last, :ext, :birth, :phone)'
                );
                $st->execute([
                    ':uid' => $newId,
                    ':first' => $p['first'],
                    ':middle' => $nullIfEmpty($p['middle']),
                    ':last' => $p['last'],
                    ':ext' => $nullIfEmpty($p['ext']),
                    ':birth' => $p['birth'],
                    ':phone' => $p['phone'],
                ]);

                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getCode() === '23505') {
                    fail('That username or email is already registered.', 409);
                }
                error_log('employees_api create error: ' . $e->getMessage());
                fail('Could not create the account. Please try again.', 500);
            }

            $rows = fetchEmployees($pdo, $cfg, $newId);
            respond(['success' => true, 'employee' => $rows[0] ?? null]);

        /* ----- update ----- */
        case 'update':
            $id = (int) ($in['id'] ?? 0);
            if ($id <= 0) {
                fail('Invalid employee.');
            }

            $p = readProfile($in);

            $newPassword = (string) ($in['newPassword'] ?? '');
            $confirm = (string) ($in['confirmPassword'] ?? '');
            $hash = null;
            if ($newPassword !== '' || $confirm !== '') {
                if (strlen($newPassword) < 8) {
                    fail('Password must be at least 8 characters.');
                }
                if ($newPassword !== $confirm) {
                    fail('Passwords do not match.');
                }
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            assertManageable($pdo, $cfg, $id);

            $taken = accountTakenMessage($pdo, $p['username'], $p['email'], $id);
            if ($taken !== '') {
                fail($taken, 409);
            }

            try {
                $pdo->beginTransaction();

                // users (created_by is never changed)
                $sets = ['username = :username', 'email = :email'];
                $params = [':username' => $p['username'], ':email' => $p['email'], ':id' => $id];

                if ($hash !== null) {
                    $sets[] = 'password_hash = :hash';
                    $params[':hash'] = $hash;
                }
                if ($cfg['statusCol'] !== null && array_key_exists('isActive', $in)) {
                    $sets[] = $cfg['statusCol'] . ' = ' . $cfg['statusExpr'];
                    if ($cfg['statusParam'] === 'text') {
                        $params[':status'] = !empty($in['isActive']) ? 'active' : 'inactive';
                    } else {
                        $params[':active'] = !empty($in['isActive']) ? 1 : 0;
                    }
                }

                $st = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
                $st->execute($params);

                // user_profiles (update, or create when the profile row is missing)
                $profile = [
                    ':first' => $p['first'],
                    ':middle' => $nullIfEmpty($p['middle']),
                    ':last' => $p['last'],
                    ':ext' => $nullIfEmpty($p['ext']),
                    ':birth' => $p['birth'],
                    ':phone' => $p['phone'],
                    ':uid' => $id,
                ];
                $st = $pdo->prepare(
                    'UPDATE user_profiles
                        SET first_name = :first, middle_name = :middle, last_name = :last,
                            extension_name = :ext, birth_date = :birth, phone = :phone
                      WHERE user_id = :uid'
                );
                $st->execute($profile);

                if ($st->rowCount() === 0) {
                    $st = $pdo->prepare(
                        'INSERT INTO user_profiles
                            (user_id, first_name, middle_name, last_name, extension_name, birth_date, phone)
                         VALUES (:uid, :first, :middle, :last, :ext, :birth, :phone)'
                    );
                    $st->execute($profile);
                }

                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getCode() === '23505') {
                    fail('That username or email is already registered.', 409);
                }
                error_log('employees_api update error: ' . $e->getMessage());
                fail('Could not save the changes. Please try again.', 500);
            }

            $rows = fetchEmployees($pdo, $cfg, $id);
            respond(['success' => true, 'employee' => $rows[0] ?? null]);

        /* ----- delete ----- */
        case 'delete':
            $id = (int) ($in['id'] ?? 0);
            if ($id <= 0) {
                fail('Invalid employee.');
            }
            assertManageable($pdo, $cfg, $id);

            // remember the uploaded photos so they can be removed after the delete
            $st = $pdo->prepare('SELECT valid_id_path, face_image_path FROM user_verifications WHERE user_id = :uid');
            $st->execute([':uid' => $id]);
            $files = $st->fetchAll();

            try {
                $pdo->beginTransaction();

                $pdo->prepare('DELETE FROM user_verifications WHERE user_id = :uid')->execute([':uid' => $id]);
                $pdo->prepare('DELETE FROM user_profiles WHERE user_id = :uid')->execute([':uid' => $id]);
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);

                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getCode() === '23503') {   // other tables still point to this user
                    fail('This employee is linked to other records and cannot be deleted. Set the account to Inactive instead.', 409);
                }
                error_log('employees_api delete error: ' . $e->getMessage());
                fail('Could not delete the employee. Please try again.', 500);
            }

            foreach ($files as $file) {
                deleteUpload($dbFile, $file['valid_id_path'] ?? null);
                deleteUpload($dbFile, $file['face_image_path'] ?? null);
            }

            respond(['success' => true, 'id' => $id]);

        default:
            fail('Unknown action.', 400);
    }
} catch (PDOException $e) {
    error_log('employees_api db error: ' . $e->getMessage());
    fail('Could not connect to the database. Please try again later.', 500);
}