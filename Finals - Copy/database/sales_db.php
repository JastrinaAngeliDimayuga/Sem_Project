<?php
/* =====================================================================
   database/sales_db.php  -  Sales / Customer Orders API (PostgreSQL)

   Put this file in the database/ folder, next to inventory_db.php.
   Run sales_update.sql in pgAdmin first (it creates sales_orders and
   sales_order_lines).

   GET  ?action=dashboard            -> cookie products (with stock), today's
                                        numbers and the 20 latest orders
   GET  ?action=orders[&limit=N]     -> orders with their cookies (oldest first)
   POST {action:"create_order", customer, payment, lines:[{item_id, quantity}]}
                                     -> saves the order, lowers the stock of
                                        every cookie and writes a Stock out
                                        record for each one
   POST {action:"set_status", id, status:"Pending"|"Completed"}
   ===================================================================== */

session_start();
require_once __DIR__ . '/../signupdb.php';

date_default_timezone_set('Asia/Manila');

const DEBUG = false;
const LOW_STOCK_LEVEL = 10;                        // same level the dashboard used before
const ALLOWED_ROLES = ['admin', 'user'];           // 'user' = employee
const PAYMENTS = ['Cash', 'GCash'];
const STATUSES = ['Pending', 'Completed'];

class SalesError extends RuntimeException
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
    $raw = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function clean($value, int $max, string $label): string
{
    $v = is_scalar($value) ? trim((string) $value) : '';
    $v = preg_replace('/\s+/u', ' ', $v) ?? '';
    if (mb_strlen($v) > $max) {
        throw new SalesError($label . ' is too long (max ' . $max . ' characters).', 422);
    }
    return $v;
}

function numText($n): string
{
    $s = rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

function orderCode(int $id): string
{
    return '#' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

/* database rows -> the object the pages use */
function orderToClient(array $o, array $lines): array
{
    $dt = new DateTime((string) $o['ordered_local'], new DateTimeZone('Asia/Manila'));

    $out = [
        'id'       => (int) $o['id'],
        'customer' => $o['customer'],
        'payment'  => $o['payment'],
        'employee' => $o['employee'] ?? '',
        'discount' => (float) $o['discount'],
        'status'   => $o['status'],
        'items'    => (int) $o['items'],
        'total'    => (float) $o['total'],
        'date'     => $dt->format('Y-m-d'),
        'time'     => $dt->format('g:i A'),
        'iso'      => $dt->format('c'),
        'lines'    => [],
    ];

    foreach ($lines as $l) {
        $out['lines'][] = [
            'itemId' => $l['item_id'] === null ? null : (int) $l['item_id'],
            'name'   => $l['item_name'],
            'price'  => (float) $l['price'],
            'qty'    => (int) $l['quantity'],
        ];
    }
    return $out;
}

/* the latest $limit orders (or just one), oldest first, each with its cookies */
function loadOrders(PDO $pdo, int $limit, ?int $onlyId = null): array
{
    $sql = "SELECT o.*,
                   to_char(o.ordered_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') AS ordered_local
              FROM sales_orders o"
         . ($onlyId !== null ? ' WHERE o.id = :id' : '')
         . ' ORDER BY o.id DESC LIMIT :n';

    $st = $pdo->prepare($sql);
    if ($onlyId !== null) {
        $st->bindValue(':id', $onlyId, PDO::PARAM_INT);
    }
    $st->bindValue(':n', $limit, PDO::PARAM_INT);
    $st->execute();
    $orders = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));

    if (!$orders) {
        return [];
    }

    $ids = array_map(static fn($o) => (int) $o['id'], $orders);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM sales_order_lines WHERE order_id IN ($marks) ORDER BY id");
    $st->execute($ids);

    $byOrder = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $byOrder[(int) $l['order_id']][] = $l;
    }

    return array_map(
        static fn($o) => orderToClient($o, $byOrder[(int) $o['id']] ?? []),
        $orders
    );
}

/* cookie products = Products added in Inventory Management; stock = their quantity */
function loadProducts(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT id, name, price, unit, quantity
           FROM inventory_items
          WHERE type = 'Products'
          ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static fn($r) => [
        'id'       => (int) $r['id'],
        'name'     => $r['name'],
        'price'    => (float) ($r['price'] ?? 0),
        'unit'     => $r['unit'] ?? '',
        'quantity' => (float) $r['quantity'],
    ], $rows);
}

/* ================= access check ================= */

$role = (string) ($_SESSION['role'] ?? '');

/* name of the person recording the order (login.php may store it under different names) */
$who = '';
foreach (['employee_name', 'full_name', 'fullname', 'name', 'username', 'user_name', 'email'] as $key) {
    if (isset($_SESSION[$key]) && is_string($_SESSION[$key]) && trim($_SESSION[$key]) !== '') {
        $who = $_SESSION[$key];
        break;
    }
}
if ($who === '' && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    foreach (['full_name', 'name', 'username', 'email'] as $key) {
        if (isset($_SESSION['user'][$key]) && is_string($_SESSION['user'][$key]) && trim($_SESSION['user'][$key]) !== '') {
            $who = $_SESSION['user'][$key];
            break;
        }
    }
}
$who = mb_substr(trim($who), 0, 120);
if ($who === '') {
    $who = $role === 'admin' ? 'Admin' : 'Employee';
}

if ($role === '') {
    failWith('Please log in first.', 401);
}
if (!in_array($role, ALLOWED_ROLES, true)) {
    failWith('You are not allowed to use the sales records.', 403);
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
    $action = (string) ($_GET['action'] ?? 'dashboard');
}

$query = $_GET;

session_write_close();      // do not keep the session locked while the database works

/* ================= actions ================= */

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* ---------- DASHBOARD (products + today's numbers + latest orders) ---------- */
    if ($action === 'dashboard') {
        $products = loadProducts($pdo);

        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(total), 0) AS sales, COUNT(*) AS tx, COALESCE(SUM(items), 0) AS cookies
               FROM sales_orders
              WHERE (ordered_at AT TIME ZONE 'Asia/Manila')::date = :d"
        );
        $st->execute([':d' => date('Y-m-d')]);
        $today = $st->fetch(PDO::FETCH_ASSOC) ?: ['sales' => 0, 'tx' => 0, 'cookies' => 0];

        $low = 0;
        foreach ($products as $p) {
            if ($p['quantity'] <= LOW_STOCK_LEVEL) {
                $low++;
            }
        }

        respond([
            'success'  => true,
            'products' => $products,
            'stats'    => [
                'sales'   => (float) $today['sales'],
                'tx'      => (int) $today['tx'],
                'cookies' => (int) $today['cookies'],
                'low'     => $low,
            ],
            'recent'   => loadOrders($pdo, 20),
        ]);
    }

    /* ---------- ORDERS (for Transaction View) ---------- */
    if ($action === 'orders') {
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 1000;
        $limit = max(1, min(1000, $limit));
        respond(['success' => true, 'orders' => loadOrders($pdo, $limit)]);
    }

    /* ---------- CREATE ORDER ---------- */
    if ($action === 'create_order') {
        $customer = clean($in['customer'] ?? '', 120, 'The customer name');
        if ($customer === '') {
            $customer = 'Walk-in customer';
        }

        $payment = (string) ($in['payment'] ?? '');
        if (!in_array($payment, PAYMENTS, true)) {
            throw new SalesError('Choose a payment method.', 422);
        }

        $linesIn = is_array($in['lines'] ?? null) ? array_slice($in['lines'], 0, 100) : [];
        $wanted = [];                                   // item id => quantity (duplicates are added up)
        foreach ($linesIn as $l) {
            if (!is_array($l)) {
                continue;
            }
            $itemId = isset($l['item_id']) && is_numeric($l['item_id']) ? (int) $l['item_id'] : 0;
            $q = $l['quantity'] ?? null;
            if ($itemId <= 0 || !is_numeric($q) || (float) $q < 1 || (float) $q > 99999 || (float) $q != floor((float) $q)) {
                throw new SalesError('Every item needs a quantity of 1 or more.', 422);
            }
            $wanted[$itemId] = ($wanted[$itemId] ?? 0) + (int) $q;
        }
        if (!$wanted) {
            throw new SalesError('Add items to the order first.', 422);
        }
        ksort($wanted);                                 // lock the rows in the same order every time

        try {
            $pdo->beginTransaction();

            /* lock every cookie and check the stock before anything is saved */
            $products = [];
            $cookies = 0;
            $total = 0.0;
            $lockItem = $pdo->prepare('SELECT * FROM inventory_items WHERE id = :id FOR UPDATE');

            foreach ($wanted as $itemId => $qty) {
                $lockItem->execute([':id' => $itemId]);
                $p = $lockItem->fetch(PDO::FETCH_ASSOC);
                if (!$p || $p['type'] !== 'Products') {
                    throw new SalesError('A cookie in this order no longer exists. Please refresh the page.', 404);
                }
                $have = (float) $p['quantity'];
                if ($qty > $have) {
                    throw new SalesError(
                        $have > 0
                            ? 'Not enough stock. Only ' . numText($have) . ' of "' . $p['name'] . '" available.'
                            : 'No stock available for "' . $p['name'] . '".',
                        422
                    );
                }
                $products[$itemId] = $p;
                $cookies += $qty;
                $total += (float) ($p['price'] ?? 0) * $qty;     // the price always comes from the database
            }
            $total = round($total, 2);

            $st = $pdo->prepare(
                'INSERT INTO sales_orders (customer, payment, employee, discount, items, total, status)
                 VALUES (:customer, :payment, :employee, 0, :items, :total, :status)
              RETURNING id'
            );
            $st->execute([
                ':customer' => $customer, ':payment' => $payment, ':employee' => $who,
                ':items' => $cookies, ':total' => $total, ':status' => 'Pending',
            ]);
            $orderId = (int) $st->fetchColumn();

            $addLine = $pdo->prepare(
                'INSERT INTO sales_order_lines (order_id, item_id, item_name, price, quantity)
                 VALUES (:order, :item, :name, :price, :qty)'
            );
            $lower = $pdo->prepare('UPDATE inventory_items SET quantity = quantity - :q WHERE id = :id RETURNING quantity');
            $addRecord = $pdo->prepare(
                "INSERT INTO stock_records
                        (item_id, movement_type, inventory, item_name, quantity, unit,
                         supplier, remarks, date_received, recorded_by, balance_after)
                 VALUES (:item, 'OUT', 'cookies', :name, :qty, :unit,
                         :supplier, :remarks, :date, :by, :bal)"
            );

            foreach ($wanted as $itemId => $qty) {
                $p = $products[$itemId];

                $addLine->execute([
                    ':order' => $orderId, ':item' => $itemId, ':name' => $p['name'],
                    ':price' => (float) ($p['price'] ?? 0), ':qty' => $qty,
                ]);

                $lower->execute([':q' => $qty, ':id' => $itemId]);
                $left = (float) $lower->fetchColumn();

                // the sale also shows up as a Stock out in the Stocking Record
                $addRecord->execute([
                    ':item' => $itemId, ':name' => $p['name'], ':qty' => $qty, ':unit' => $p['unit'],
                    ':supplier' => 'Sale ' . orderCode($orderId),
                    ':remarks' => $customer, ':date' => date('Y-m-d'),
                    ':by' => $who, ':bal' => $left,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $saved = loadOrders($pdo, 1, $orderId);
        respond(['success' => true, 'order' => $saved[0] ?? null], 201);
    }

    /* ---------- SET STATUS (Pending / Completed) ---------- */
    if ($action === 'set_status') {
        $id = isset($in['id']) && is_numeric($in['id']) ? (int) $in['id'] : 0;
        $status = (string) ($in['status'] ?? '');
        if ($id <= 0) {
            throw new SalesError('Unknown order.', 422);
        }
        if (!in_array($status, STATUSES, true)) {
            throw new SalesError('Unknown status.', 422);
        }

        $st = $pdo->prepare(
            "UPDATE sales_orders
                SET status = :s1,
                    completed_at = CASE WHEN :s2 = 'Completed' THEN NOW() ELSE NULL END
              WHERE id = :id
          RETURNING id"
        );
        $st->execute([':s1' => $status, ':s2' => $status, ':id' => $id]);
        if (!$st->fetchColumn()) {
            throw new SalesError('That order no longer exists.', 404);
        }

        $saved = loadOrders($pdo, 1, $id);
        respond(['success' => true, 'order' => $saved[0] ?? null]);
    }

    failWith('Unknown action.', 400);

} catch (SalesError $e) {
    failWith($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 422);
} catch (PDOException $e) {
    error_log('sales_db db error: ' . $e->getMessage());
    $sqlState = (string) $e->getCode();

    if ($sqlState === '42P01') {
        failWith('A database table is missing. Run inventory_stock_update.sql and sales_update.sql in pgAdmin first.', 500);
    }
    if ($sqlState === '42703') {
        failWith('The database needs an update. Run inventory_stock_update.sql in pgAdmin first.', 500);
    }
    failWith(DEBUG ? 'Database error: ' . $e->getMessage() : 'Could not reach the database. Please try again later.', 500);
} catch (Throwable $e) {
    error_log('sales_db error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    failWith(DEBUG ? $e->getMessage() : 'Something went wrong. Please try again later.', 500);
}