<?php
/* =====================================================================
   database/inventory_db.php  -  Inventory + Stock API (PostgreSQL)

   Put this file in the database/ folder (INVENTORY/database/).
   It uses the same db() connection as login.php (signupdb.php, one
   folder up in the project root).

   Run inventory_stock_update.sql in pgAdmin first (adds quantity, barcode
   and the stock_records table).

   GET  ?action=list                     -> all items (with their quantity)
   GET  ?action=stock                    -> all items + total in / total out
   GET  ?action=records[&q=&inv=&type=&from=&to=]  -> stock history (latest 200)
   POST {action:"save",   id:null|N, item:{...}}   -> add (id null) or edit (id N)
   POST {action:"delete", id:N}                    -> delete an item
   POST {action:"stock_move", item_id, movement_type:"IN"|"OUT", quantity,
         supplier, remarks, date_received}         -> restock / take out stock:
                                                    updates the item's quantity
                                                    and saves a history record
   POST {action:"import", items:[...]}             -> one-time move of the old
                                                      browser-stored items

   Photos are sent as data URLs, checked, and saved as files in
   uploads/inventory/ (project root). Only the file name goes into the
   database.
   ===================================================================== */

session_start();
require_once __DIR__ . '/../signupdb.php';       // this file is one folder deeper than the project root

date_default_timezone_set('Asia/Manila');

const DEBUG = false;

const UPLOAD_DIR = __DIR__ . '/../uploads/inventory';   // project-root uploads folder
const UPLOAD_URL = 'uploads/inventory/';                // relative to the pages in the project root
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

const TYPES = ['Ingredients', 'Products', 'Supplies'];

/* item type -> inventory key used by the stocking page */
const TYPE_TO_INV = [
    'Ingredients' => 'ingredients',
    'Products'    => 'cookies',
    'Supplies'    => 'supplies',
];

// who may use the API (session roles set by login.php: 'admin' or 'user' = employee)
const READ_ROLES   = ['admin', 'user'];
const WRITE_ROLES  = ['admin', 'user'];
const DELETE_ROLES = ['admin', 'user'];           // change to ['admin'] to let only the admin delete

class InvError extends RuntimeException
{
}

/* ================= helpers ================= */

function respond(array $data, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function failWith(string $message, int $code = 400): void
{
    respond(['success' => false, 'message' => $message], $code);
}

function body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 40 * 1024 * 1024);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/* trimmed single-line text, or an error when it is longer than $max */
function clean($value, int $max, string $label): string
{
    $v = is_scalar($value) ? trim((string) $value) : '';
    $v = preg_replace('/\s+/u', ' ', $v) ?? '';
    if (mb_strlen($v) > $max) {
        throw new InvError($label . ' is too long (max ' . $max . ' characters).', 422);
    }
    return $v;
}

function nullIfEmpty(string $v): ?string
{
    return $v === '' ? null : $v;
}

function validDate(string $s): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

/* number without useless zeros, for messages (5.00 -> 5) */
function numText($n): string
{
    $s = rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

/* checks and cleans the fields of one item (what is required depends on its type) */
function fieldsFrom(array $in, string $type): array
{
    /* the barcode may come under a few names (older pages used code / sku) */
    $hasBarcode = false;
    $barcodeRaw = '';
    foreach (['barcode', 'barCode', 'barcode_number', 'code', 'sku'] as $k) {
        if (array_key_exists($k, $in)) {
            $hasBarcode = true;
            $barcodeRaw = $in[$k];
            break;
        }
    }

    $f = [
        'name'       => clean($in['name'] ?? '', 120, 'The name'),
        'unit'       => clean($in['unit'] ?? '', 40, 'The unit'),
        'price'      => null,
        'category'   => null,
        'flavour'    => null,
        'texture'    => null,
        'expiration' => null,
        'barcode'    => nullIfEmpty(clean($barcodeRaw, 64, 'The barcode')),
        'hasBarcode' => $hasBarcode,
    ];

    if ($f['name'] === '') {
        throw new InvError('Please enter a name.', 422);
    }
    if ($f['unit'] === '') {
        throw new InvError('Please enter a unit.', 422);
    }
    if ($type === 'Supplies') {
        return $f;
    }

    $price = $in['price'] ?? null;
    if (!is_numeric($price) || (float) $price < 0 || (float) $price > 99999999.99) {
        throw new InvError('Please enter a valid price.', 422);
    }
    $f['price'] = round((float) $price, 2);

    $category = clean($in['category'] ?? '', 80, 'The category');
    if ($category === '') {
        throw new InvError('Please enter a category.', 422);
    }
    $f['category'] = $category;

    $f['texture'] = nullIfEmpty(clean($in['texture'] ?? ($in['spec'] ?? ''), 80, 'The texture'));

    if ($type === 'Products') {
        $f['flavour'] = nullIfEmpty(clean($in['flavour'] ?? '', 80, 'The flavour'));
    } else {
        $exp = trim((string) ($in['expiration'] ?? ''));
        if ($exp !== '') {
            if (!validDate($exp)) {
                throw new InvError('The expiration date is not valid.', 422);
            }
            $f['expiration'] = $exp;
        }
    }
    return $f;
}

function deleteFile(?string $file): void
{
    if ($file === null || $file === '') {
        return;
    }
    $path = UPLOAD_DIR . '/' . basename($file);
    if (is_file($path)) {
        @unlink($path);
    }
}

/* Works out the photo of an item. Returns [file name to store, old file to delete after saving].
     ''  / null            -> no photo (the old one is removed)
     data:image/...;base64 -> new photo, checked and saved as a file
     anything else         -> unchanged (a path the page already had) */
function storeImage($img, ?string $old): array
{
    if (!is_string($img) || trim($img) === '') {
        return [null, $old];
    }

    if (!preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $img, $m)) {
        return [$old, null];
    }

    $bin = base64_decode($m[2], true);
    if ($bin === false || $bin === '') {
        throw new InvError('The photo could not be read.', 422);
    }
    if (strlen($bin) > MAX_IMAGE_BYTES) {
        throw new InvError('The photo is too large (max 5 MB).', 422);
    }

    $info = @getimagesizefromstring($bin);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!isset(IMAGE_TYPES[$mime])) {
        throw new InvError('Only JPG, PNG or WEBP photos are allowed.', 422);
    }

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        throw new InvError('The uploads folder could not be created.', 500);
    }

    $file = bin2hex(random_bytes(12)) . '.' . IMAGE_TYPES[$mime];
    if (@file_put_contents(UPLOAD_DIR . '/' . $file, $bin) === false) {
        throw new InvError('The photo could not be saved. Check the uploads folder permissions.', 500);
    }
    return [$file, $old];
}

/* database row -> the object the page uses */
function toClient(array $r): array
{
    $file = (string) ($r['image_file'] ?? '');
    $item = [
        'id'         => (int) $r['id'],
        'type'       => $r['type'],
        'name'       => $r['name'],
        'unit'       => $r['unit'],
        'price'      => $r['price'] === null ? null : (float) $r['price'],
        'category'   => $r['category'] ?? '',
        'flavour'    => $r['flavour'] ?? '',
        'texture'    => $r['texture'] ?? '',
        'expiration' => $r['expiration'] ?? '',
        'barcode'    => $r['barcode'] ?? '',
        'quantity'   => (float) ($r['quantity'] ?? 0),
        'image'      => $file !== '' ? UPLOAD_URL . $file : '',
        'dateAdded'  => $r['date_added'],
    ];
    if (array_key_exists('total_in', $r)) {
        $item['totalIn']  = (float) $r['total_in'];
        $item['totalOut'] = (float) $r['total_out'];
    }
    if ($r['type'] === 'Products') {
        $item['orders'] = 0;          // kept so older pages that read this field do not break
    }
    return $item;
}

/* stock_records row -> the object the stocking page uses */
function recordToClient(array $r): array
{
    return [
        'id'            => (int) $r['id'],
        'item_id'       => $r['item_id'] === null ? null : (int) $r['item_id'],
        'movement_type' => $r['movement_type'],
        'inventory'     => $r['inventory'],
        'item_name'     => $r['item_name'],
        'quantity'      => (float) $r['quantity'],
        'unit'          => $r['unit'],
        'supplier'      => $r['supplier'] ?? '',
        'remarks'       => $r['remarks'] ?? '',
        'date_received' => $r['date_received'],
        'received_by'   => $r['recorded_by'] ?? '',
        'balance'       => (float) $r['balance_after'],
    ];
}

/* ================= access check ================= */

$role = (string) ($_SESSION['role'] ?? '');

/* the id of the logged-in person: login.php may store it under different names */
$uid = 0;
foreach (['user_id', 'id', 'employee_id', 'emp_id', 'userid', 'uid'] as $key) {
    if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key]) && (int) $_SESSION[$key] > 0) {
        $uid = (int) $_SESSION[$key];
        break;
    }
}
if ($uid === 0 && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    foreach (['id', 'user_id', 'employee_id'] as $key) {
        if (isset($_SESSION['user'][$key]) && is_numeric($_SESSION['user'][$key]) && (int) $_SESSION['user'][$key] > 0) {
            $uid = (int) $_SESSION['user'][$key];
            break;
        }
    }
}

$createdBy = ($role === 'admin' || $uid <= 0) ? null : $uid;

/* who is saving: role and name are stored right on the item / record, so the dashboard
   can always tell an admin from an employee, even when the id cannot be found */
$addedByRole = $role === 'admin' ? 'admin' : 'user';
$addedByName = '';
foreach (['full_name', 'fullname', 'name', 'employee_name', 'username', 'user_name', 'email'] as $key) {
    if (isset($_SESSION[$key]) && is_string($_SESSION[$key]) && trim($_SESSION[$key]) !== '') {
        $addedByName = $_SESSION[$key];
        break;
    }
}
if ($addedByName === '' && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    foreach (['full_name', 'name', 'username', 'email'] as $key) {
        if (isset($_SESSION['user'][$key]) && is_string($_SESSION['user'][$key]) && trim($_SESSION['user'][$key]) !== '') {
            $addedByName = $_SESSION['user'][$key];
            break;
        }
    }
}
$addedByName = mb_substr(trim($addedByName), 0, 120);

if ($role === '') {
    failWith('Please log in first.', 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$in = [];

if ($method === 'POST') {
    // a custom header cannot be sent by another website's form, so this blocks cross-site requests
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'fetch') {
        failWith('Bad request.', 400);
    }
    $in = body();
    $action = (string) ($in['action'] ?? '');
} else {
    $action = (string) ($_GET['action'] ?? 'list');
}

$query = $_GET;             // filters of the "records" action

session_write_close();      // do not keep the session locked while the database works

/* ================= actions ================= */

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // created_by points to users(id): drop it when that id is not in the users table
    if ($createdBy !== null) {
        try {
            $chk = $pdo->prepare('SELECT 1 FROM users WHERE id = :id');
            $chk->execute([':id' => $createdBy]);
            if (!$chk->fetchColumn()) {
                $createdBy = null;
            }
        } catch (Throwable $e) {
            $createdBy = null;
        }
    }

    /* ---------- LIST ---------- */
    if ($action === 'list') {
        if (!in_array($role, READ_ROLES, true)) {
            failWith('You are not allowed to view the inventory.', 403);
        }
        $rows = $pdo->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        respond(['success' => true, 'items' => array_map('toClient', $rows)]);
    }

    /* ---------- STOCK (items + total in / total out, for the Current stock tab) ---------- */
    if ($action === 'stock') {
        if (!in_array($role, READ_ROLES, true)) {
            failWith('You are not allowed to view the inventory.', 403);
        }
        $rows = $pdo->query(
            "SELECT i.*, COALESCE(s.total_in, 0) AS total_in, COALESCE(s.total_out, 0) AS total_out
               FROM inventory_items i
               LEFT JOIN (
                    SELECT item_id,
                           SUM(CASE WHEN movement_type = 'IN'  THEN quantity ELSE 0 END) AS total_in,
                           SUM(CASE WHEN movement_type = 'OUT' THEN quantity ELSE 0 END) AS total_out
                      FROM stock_records
                     WHERE item_id IS NOT NULL
                     GROUP BY item_id
               ) s ON s.item_id = i.id
              ORDER BY i.type, i.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        respond(['success' => true, 'items' => array_map('toClient', $rows)]);
    }

    /* ---------- RECORDS (stock history, with search and filters) ---------- */
    if ($action === 'records') {
        if (!in_array($role, READ_ROLES, true)) {
            failWith('You are not allowed to view the stock records.', 403);
        }

        $where = [];
        $params = [];

        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
            $where[] = '(item_name ILIKE :q1 OR COALESCE(supplier, \'\') ILIKE :q2)';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
        }

        $inv = (string) ($query['inv'] ?? '');
        if ($inv !== '') {
            if (!in_array($inv, array_values(TYPE_TO_INV), true)) {
                throw new InvError('Unknown inventory.', 422);
            }
            $where[] = 'inventory = :inv';
            $params[':inv'] = $inv;
        }

        $type = (string) ($query['type'] ?? '');
        if ($type !== '') {
            if (!in_array($type, ['IN', 'OUT'], true)) {
                throw new InvError('Unknown record type.', 422);
            }
            $where[] = 'movement_type = :mtype';
            $params[':mtype'] = $type;
        }

        $from = (string) ($query['from'] ?? '');
        if ($from !== '') {
            if (!validDate($from)) {
                throw new InvError('The "from" date is not valid.', 422);
            }
            $where[] = 'date_received >= :dfrom';
            $params[':dfrom'] = $from;
        }

        $to = (string) ($query['to'] ?? '');
        if ($to !== '') {
            if (!validDate($to)) {
                throw new InvError('The "to" date is not valid.', 422);
            }
            $where[] = 'date_received <= :dto';
            $params[':dto'] = $to;
        }

        $sql = 'SELECT * FROM stock_records'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY date_received DESC, id DESC LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        respond(['success' => true, 'records' => array_map('recordToClient', $rows)]);
    }

    /* ---------- STOCK MOVE (restock = IN, take out = OUT) ---------- */
    if ($action === 'stock_move') {
        if (!in_array($role, WRITE_ROLES, true)) {
            failWith('You are not allowed to change the inventory.', 403);
        }

        $itemId = isset($in['item_id']) && is_numeric($in['item_id']) ? (int) $in['item_id'] : 0;
        if ($itemId <= 0) {
            throw new InvError('Choose an item.', 422);
        }

        $move = strtoupper((string) ($in['movement_type'] ?? ''));
        if (!in_array($move, ['IN', 'OUT'], true)) {
            throw new InvError('Choose stock in or stock out.', 422);
        }

        $qty = $in['quantity'] ?? null;
        if (!is_numeric($qty) || (float) $qty < 1 || (float) $qty > 99999999 || (float) $qty != floor((float) $qty)) {
            throw new InvError('Enter the quantity as a whole number, 1 or more.', 422);
        }
        $qty = (int) $qty;

        $supplier = nullIfEmpty(clean($in['supplier'] ?? '', 100, 'Supplier / reason'));
        $remarks  = nullIfEmpty(clean($in['remarks'] ?? '', 255, 'Remarks'));

        $date = trim((string) ($in['date_received'] ?? ''));
        if ($date === '') {
            $date = date('Y-m-d');
        }
        if (!validDate($date) || $date > date('Y-m-d')) {
            throw new InvError('Enter a valid date that is not in the future.', 422);
        }

        $recorder = $addedByName !== '' ? $addedByName : ($role === 'admin' ? 'Admin' : 'Employee');

        try {
            $pdo->beginTransaction();

            // lock the row so two people stocking the same item cannot overwrite each other
            $st = $pdo->prepare('SELECT * FROM inventory_items WHERE id = :id FOR UPDATE');
            $st->execute([':id' => $itemId]);
            $existing = $st->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new InvError('That item no longer exists.', 404);
            }

            $current = (float) $existing['quantity'];
            $balance = $move === 'IN' ? $current + $qty : $current - $qty;

            if ($balance < 0) {
                throw new InvError(
                    $current > 0
                        ? 'Not enough stock. Only ' . numText($current) . ' ' . $existing['unit'] . ' of "' . $existing['name'] . '" available.'
                        : 'No stock available for "' . $existing['name'] . '".',
                    422
                );
            }

            $st = $pdo->prepare('UPDATE inventory_items SET quantity = :q WHERE id = :id RETURNING *');
            $st->execute([':q' => $balance, ':id' => $itemId]);
            $itemRow = $st->fetch(PDO::FETCH_ASSOC);

            $st = $pdo->prepare(
                'INSERT INTO stock_records
                        (item_id, movement_type, inventory, item_name, quantity, unit,
                         supplier, remarks, date_received, recorded_by, balance_after)
                 VALUES (:item, :mtype, :inv, :name, :qty, :unit,
                         :supplier, :remarks, :date, :by, :bal)
              RETURNING *'
            );
            $st->execute([
                ':item' => $itemId, ':mtype' => $move,
                ':inv' => TYPE_TO_INV[$existing['type']] ?? 'supplies',
                ':name' => $existing['name'], ':qty' => $qty, ':unit' => $existing['unit'],
                ':supplier' => $supplier, ':remarks' => $remarks, ':date' => $date,
                ':by' => $recorder, ':bal' => $balance,
            ]);
            $recordRow = $st->fetch(PDO::FETCH_ASSOC);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        respond([
            'success' => true,
            'item'    => toClient($itemRow),
            'record'  => recordToClient($recordRow),
            'balance' => (float) $itemRow['quantity'],
        ], 201);
    }

    /* ---------- SAVE (add or edit) ---------- */
    if ($action === 'save') {
        if (!in_array($role, WRITE_ROLES, true)) {
            failWith('You are not allowed to change the inventory.', 403);
        }

        $item = is_array($in['item'] ?? null) ? $in['item'] : [];
        $id = isset($in['id']) && is_numeric($in['id']) && (int) $in['id'] > 0 ? (int) $in['id'] : null;

        /* --- edit --- */
        if ($id !== null) {
            $newFile = null;
            $oldToDelete = null;

            try {
                $pdo->beginTransaction();

                $st = $pdo->prepare('SELECT * FROM inventory_items WHERE id = :id FOR UPDATE');
                $st->execute([':id' => $id]);
                $existing = $st->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    throw new InvError('That item no longer exists.', 404);
                }

                $type = $existing['type'];                 // the category of an item never changes
                $f = fieldsFrom($item, $type);

                // the barcode is only changed when the page sends it
                $barcode = $f['hasBarcode'] ? $f['barcode'] : ($existing['barcode'] ?? null);

                $imageFile = $existing['image_file'];
                if (array_key_exists('image', $item)) {
                    [$imageFile, $oldToDelete] = storeImage($item['image'], $existing['image_file']);
                    if ($imageFile !== $existing['image_file']) {
                        $newFile = $imageFile;
                    }
                }

                /* quantity is NOT changed here: it only changes through stock_move */
                $st = $pdo->prepare(
                    'UPDATE inventory_items
                        SET name = :name, unit = :unit, price = :price, category = :category,
                            flavour = :flavour, texture = :texture, expiration = :expiration,
                            barcode = :barcode, image_file = :image
                      WHERE id = :id
                  RETURNING *'
                );
                $st->execute([
                    ':name' => $f['name'], ':unit' => $f['unit'], ':price' => $f['price'],
                    ':category' => $f['category'], ':flavour' => $f['flavour'], ':texture' => $f['texture'],
                    ':expiration' => $f['expiration'], ':barcode' => $barcode,
                    ':image' => $imageFile, ':id' => $id,
                ]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                deleteFile($newFile);                      // do not leave an unused photo behind
                throw $e;
            }

            if ($oldToDelete !== null && $oldToDelete !== $row['image_file']) {
                deleteFile($oldToDelete);
            }
            respond(['success' => true, 'item' => toClient($row)]);
        }

        /* --- add --- */
        $type = (string) ($item['type'] ?? '');
        if (!in_array($type, TYPES, true)) {
            throw new InvError('Unknown item type.', 422);
        }
        $f = fieldsFrom($item, $type);
        [$newFile] = storeImage($item['image'] ?? '', null);

        try {
            $st = $pdo->prepare(
                'INSERT INTO inventory_items
                        (type, name, unit, price, category, flavour, texture, expiration, barcode,
                         image_file, date_added, created_by, added_by_role, added_by_name)
                 VALUES (:type, :name, :unit, :price, :category, :flavour, :texture, :expiration, :barcode,
                         :image, :added, :by, :arole, :aname)
              RETURNING *'
            );
            $st->execute([
                ':type' => $type, ':name' => $f['name'], ':unit' => $f['unit'], ':price' => $f['price'],
                ':category' => $f['category'], ':flavour' => $f['flavour'], ':texture' => $f['texture'],
                ':expiration' => $f['expiration'], ':barcode' => $f['barcode'], ':image' => $newFile,
                ':added' => date('Y-m-d'), ':by' => $createdBy,
                ':arole' => $addedByRole, ':aname' => nullIfEmpty($addedByName),
            ]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            deleteFile($newFile);
            throw $e;
        }
        respond(['success' => true, 'item' => toClient($row)], 201);
    }

    /* ---------- DELETE ---------- */
    if ($action === 'delete') {
        if (!in_array($role, DELETE_ROLES, true)) {
            failWith('You are not allowed to delete items.', 403);
        }
        $id = isset($in['id']) && is_numeric($in['id']) ? (int) $in['id'] : 0;
        if ($id <= 0) {
            throw new InvError('Unknown item.', 422);
        }

        $st = $pdo->prepare('DELETE FROM inventory_items WHERE id = :id RETURNING image_file');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvError('That item no longer exists.', 404);
        }
        deleteFile($row['image_file']);
        respond(['success' => true]);
    }

    /* ---------- IMPORT (one time: items the browser kept before the database existed) ---------- */
    if ($action === 'import') {
        if (!in_array($role, WRITE_ROLES, true)) {
            failWith('You are not allowed to change the inventory.', 403);
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn() > 0) {
            failWith('The inventory already has items, so nothing was imported.', 409);
        }

        $list = is_array($in['items'] ?? null) ? array_slice($in['items'], 0, 2000) : [];
        $imported = 0;
        $skipped = 0;
        $savedFiles = [];

        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare(
                'INSERT INTO inventory_items
                        (id, type, name, unit, price, category, flavour, texture, expiration, barcode,
                         image_file, date_added, created_by)
                 VALUES (:id, :type, :name, :unit, :price, :category, :flavour, :texture, :expiration, :barcode,
                         :image, :added, :by)
                 ON CONFLICT DO NOTHING'
            );

            foreach ($list as $item) {
                if (!is_array($item)) {
                    $skipped++;
                    continue;
                }
                try {
                    $type = (string) ($item['type'] ?? '');
                    if (!in_array($type, TYPES, true)) {
                        throw new InvError('type');
                    }
                    $f = fieldsFrom($item, $type);

                    $imageFile = null;
                    try {
                        [$imageFile] = storeImage($item['image'] ?? '', null);
                    } catch (InvError $e) {
                        $imageFile = null;                 // a bad photo must not block the item itself
                    }
                    if ($imageFile !== null) {
                        $savedFiles[] = $imageFile;
                    }

                    $itemId = isset($item['id']) && is_numeric($item['id']) && (int) $item['id'] > 0
                        ? (int) $item['id']
                        : null;
                    $added = (string) ($item['dateAdded'] ?? '');
                    if (!validDate($added)) {
                        $added = date('Y-m-d');
                    }

                    // ids are kept so the notifications and other pages still recognise every item
                    if ($itemId === null) {
                        $itemId = (int) $pdo->query("SELECT nextval(pg_get_serial_sequence('inventory_items','id'))")->fetchColumn();
                    }

                    $ins->execute([
                        ':id' => $itemId, ':type' => $type, ':name' => $f['name'], ':unit' => $f['unit'],
                        ':price' => $f['price'], ':category' => $f['category'], ':flavour' => $f['flavour'],
                        ':texture' => $f['texture'], ':expiration' => $f['expiration'], ':barcode' => $f['barcode'],
                        ':image' => $imageFile, ':added' => $added, ':by' => $createdBy,
                    ]);
                    $ins->rowCount() > 0 ? $imported++ : $skipped++;
                } catch (InvError $e) {
                    $skipped++;
                }
            }

            if ($imported > 0) {
                $pdo->query("SELECT setval(pg_get_serial_sequence('inventory_items','id'),
                                           (SELECT COALESCE(MAX(id), 1) FROM inventory_items))");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($savedFiles as $f) {
                deleteFile($f);
            }
            throw $e;
        }

        $rows = $pdo->query('SELECT * FROM inventory_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        respond([
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'items'    => array_map('toClient', $rows),
        ]);
    }

    failWith('Unknown action.', 400);

} catch (InvError $e) {
    failWith($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422);
} catch (PDOException $e) {
    error_log('inventory_db db error: ' . $e->getMessage());
    $sqlState = (string) $e->getCode();

    if ($sqlState === '23505') {
        failWith('An item with this name already exists in this category.', 409);
    }
    if ($sqlState === '42P01') {
        failWith('A database table is missing. Run inventory_schema.sql and inventory_stock_update.sql in pgAdmin first.', 500);
    }
    if ($sqlState === '42703') {
        failWith('The database needs an update. Run inventory_stock_update.sql in pgAdmin first.', 500);
    }
    failWith(DEBUG ? 'Database error: ' . $e->getMessage() : 'Could not reach the database. Please try again later.', 500);
} catch (Throwable $e) {
    error_log('inventory_db error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    failWith(DEBUG ? $e->getMessage() : 'Something went wrong. Please try again later.', 500);
}