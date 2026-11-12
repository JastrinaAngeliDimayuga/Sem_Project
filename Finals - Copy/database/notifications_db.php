<?php
/* =====================================================================
   database/notifications_db.php  -  Notifications API (PostgreSQL)

   Put this file in the database/ folder, next to inventory_db.php.
   Run notifications_update.sql in pgAdmin first (it creates the small
   table that remembers what was read or deleted).

   The notifications are built from the database itself, so they are
   always up to date and the same for every browser:
     stock in / stock out  <- stock_records (Stocking Record, barcode scans
                              and sales all write here)
     new item              <- inventory_items (Inventory Management)
     low stock / no stock  <- inventory_items.quantity (items that were
                              stocked at least once)
     expiring / expired    <- ingredients with an expiration date

   GET  ?action=list
   POST {action:"mark_read",     id}
   POST {action:"mark_all_read"}
   POST {action:"delete",        id}
   POST {action:"clear_all"}

   Only the admin can use it.
   ===================================================================== */

session_start();
require_once __DIR__ . '/../signupdb.php';

date_default_timezone_set('Asia/Manila');

const DEBUG = false;

const DAYS_BACK   = 30;      // stock movements and new items of the last 30 days are shown
const MAX_SHOWN   = 200;     // most notifications shown at once
const EXPIRY_DAYS = 30;      // ingredients expiring within this many days are shown

/* an item is "low" when its quantity is at or below this level */
const LOW_STOCK = ['Products' => 10, 'Ingredients' => 10, 'Supplies' => 10];

const INV_LABELS = ['ingredients' => 'Ingredients', 'cookies' => 'Cookies', 'supplies' => 'Supplies'];

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
    $raw = file_get_contents('php://input', false, null, 0, 1024 * 1024);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function numText($n): string
{
    $s = rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

function peso($n): string
{
    return '₱' . number_format((float) $n, 2);
}

/* "2026-11-11 14:30:00" (Philippine time) -> [ISO text with offset, unix time] */
function localTime(string $local): array
{
    $dt = new DateTime($local, new DateTimeZone('Asia/Manila'));
    return [$dt->format('c'), $dt->getTimestamp()];
}

function pgBool($v): bool
{
    return in_array($v, [true, 't', 'true', '1', 1], true);
}

/* runs the first query that works (a later one is only used when a column is missing) */
function tryRows(PDO $pdo, array $sqls): array
{
    foreach ($sqls as $sql) {
        try {
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '42703') {
                throw $e;
            }
        }
    }
    return [];
}

/* every notification the database can make right now (not yet filtered by read / deleted) */
function buildNotifications(PDO $pdo): array
{
    $events = [];
    $days   = (int) DAYS_BACK;
    $tzSql  = "AT TIME ZONE 'Asia/Manila'";

    /* ---- stock in / stock out ---- */
    $rows = $pdo->query(
        "SELECT r.id, r.movement_type, r.inventory, r.item_name, r.quantity, r.unit, r.supplier,
                r.recorded_by, r.balance_after,
                to_char(r.created_at $tzSql, 'YYYY-MM-DD HH24:MI:SS') AS at_local
           FROM stock_records r
          WHERE r.created_at >= NOW() - INTERVAL '$days days'
          ORDER BY r.id DESC
          LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $in   = $r['movement_type'] === 'IN';
        $unit = (string) $r['unit'];
        $inv  = INV_LABELS[$r['inventory']] ?? (string) $r['inventory'];

        $desc = ($in ? '+' : '-') . numText($r['quantity']) . ' ' . $unit . ' of ' . $r['item_name'] . ' (' . $inv . '). '
              . 'Current stock: ' . numText($r['balance_after']) . ' ' . $unit . '.';
        if (trim((string) $r['supplier']) !== '') {
            $desc .= ($in ? ' Supplier: ' : ' Reason: ') . trim((string) $r['supplier']) . '.';
        }
        if (trim((string) $r['recorded_by']) !== '') {
            $desc .= ' Recorded by ' . trim((string) $r['recorded_by']) . '.';
        }

        [$iso, $ts] = localTime($r['at_local']);
        $events[] = [
            'id'          => 'stock:' . $r['id'],
            'type'        => $in ? 'stock_in' : 'stock_out',
            'title'       => ($in ? 'Stock in: ' : 'Stock out: ') . $r['item_name'],
            'description' => $desc,
            'date'        => $iso,
            'ts'          => $ts,
        ];
    }

    /* ---- new items ---- */
    $rows = tryRows($pdo, [
        "SELECT i.id, i.type, i.name, i.unit, i.price, i.category, i.added_by_name, i.added_by_role,
                to_char(i.created_at $tzSql, 'YYYY-MM-DD HH24:MI:SS') AS at_local
           FROM inventory_items i
          WHERE i.created_at >= NOW() - INTERVAL '$days days'
          ORDER BY i.id DESC
          LIMIT 100",
        "SELECT i.id, i.type, i.name, i.unit, i.price, i.category, i.added_by_name, i.added_by_role,
                to_char(i.date_added::timestamp, 'YYYY-MM-DD HH24:MI:SS') AS at_local
           FROM inventory_items i
          WHERE i.date_added >= CURRENT_DATE - $days
          ORDER BY i.id DESC
          LIMIT 100",
    ]);

    foreach ($rows as $r) {
        $desc = $r['type'] . ' item added.';
        if (trim((string) $r['category']) !== '') {
            $desc .= ' Category: ' . $r['category'] . '.';
        }
        if (trim((string) $r['unit']) !== '') {
            $desc .= ' Unit: ' . $r['unit'] . '.';
        }
        if ($r['price'] !== null) {
            $desc .= ' Price: ' . peso($r['price']) . '.';
        }
        $by = trim((string) ($r['added_by_name'] ?? ''));
        if ($by !== '') {
            $desc .= ' Added by ' . $by . ($r['added_by_role'] === 'admin' ? ' (Admin).' : ' (Employee).');
        }

        [$iso, $ts] = localTime($r['at_local']);
        $events[] = [
            'id'          => 'item:' . $r['id'],
            'type'        => 'new_item',
            'title'       => 'New item added: ' . $r['name'],
            'description' => $desc,
            'date'        => $iso,
            'ts'          => $ts,
        ];
    }

    /* ---- low stock / out of stock (only items that were stocked at least once) ---- */
    $case = 'CASE i.type';
    foreach (LOW_STOCK as $type => $level) {
        $case .= " WHEN '" . $type . "' THEN " . (int) $level;
    }
    $case .= ' ELSE 10 END';

    $rows = $pdo->query(
        "SELECT i.id, i.type, i.name, i.unit, i.quantity,
                m.last_id, to_char(m.last_at $tzSql, 'YYYY-MM-DD HH24:MI:SS') AS at_local
           FROM inventory_items i
           JOIN (SELECT item_id, MAX(id) AS last_id, MAX(created_at) AS last_at
                   FROM stock_records
                  WHERE item_id IS NOT NULL
                  GROUP BY item_id) m ON m.item_id = i.id
          WHERE i.quantity <= $case
          ORDER BY i.quantity ASC, i.name ASC
          LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $qty  = (float) $r['quantity'];
        $unit = (string) $r['unit'];
        $out  = $qty <= 0;

        [$iso, $ts] = localTime($r['at_local']);
        $events[] = [
            // a new stock movement while the item is still low makes a fresh (unread) notification
            'id'          => 'low:' . $r['id'] . ':' . $r['last_id'],
            'type'        => 'low_stock',
            'title'       => ($out ? 'Out of stock: ' : 'Low stock: ') . $r['name'],
            'description' => $out
                ? $r['name'] . ' (' . $r['type'] . ') has run out. Restock as soon as possible.'
                : 'Only ' . numText($qty) . ' ' . $unit . ' left of ' . $r['name'] . ' (' . $r['type'] . '). Restock soon.',
            'date'        => $iso,
            'ts'          => $ts,
        ];
    }

    /* ---- ingredients that are expired or close to expiring ---- */
    $rows = $pdo->query(
        "SELECT i.id, i.name, i.unit, i.expiration, (i.expiration - CURRENT_DATE) AS days_left
           FROM inventory_items i
          WHERE i.type = 'Ingredients' AND i.expiration IS NOT NULL
            AND i.expiration <= CURRENT_DATE + " . (int) EXPIRY_DAYS . "
          ORDER BY i.expiration ASC
          LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $left   = (int) $r['days_left'];
        $expiry = date('M j, Y', strtotime($r['expiration']));

        if ($left < 0) {
            $title = 'Expired: ' . $r['name'];
            $desc  = $r['name'] . ' expired on ' . $expiry . '. Do not use it.';
            $when  = $r['expiration'];                    // shown at its expiry date
        } else {
            $title = $left === 0 ? 'Expires today: ' . $r['name'] : 'Expiring soon: ' . $r['name'];
            $desc  = $left === 0
                ? $r['name'] . ' expires today (' . $expiry . ').'
                : $r['name'] . ' expires in ' . $left . ' day' . ($left === 1 ? '' : 's') . ' (' . $expiry . ').';
            $when  = date('Y-m-d');
        }

        [$iso, $ts] = localTime($when . ' 00:00:00');
        $events[] = [
            'id'          => 'exp:' . $r['id'] . ':' . $r['expiration'],
            'type'        => 'system',
            'title'       => $title,
            'description' => $desc,
            'date'        => $iso,
            'ts'          => $ts,
        ];
    }

    return $events;
}

/* read / deleted flags for the given notification ids: id => [is_read, is_deleted] */
function loadState(PDO $pdo, array $keys): array
{
    if (!$keys) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare("SELECT notif_key, is_read, is_deleted FROM notification_state WHERE notif_key IN ($marks)");
    $st->execute(array_values($keys));

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['notif_key']] = [pgBool($r['is_read']), pgBool($r['is_deleted'])];
    }
    return $out;
}

function saveState(PDO $pdo, array $keys, bool $deleted): void
{
    if (!$keys) {
        return;
    }
    $st = $pdo->prepare(
        'INSERT INTO notification_state (notif_key, is_read, is_deleted)
         VALUES (:k, TRUE, :d)
         ON CONFLICT (notif_key) DO UPDATE
            SET is_read = TRUE,
                is_deleted = notification_state.is_deleted OR EXCLUDED.is_deleted,
                updated_at = NOW()'
    );

    $pdo->beginTransaction();
    try {
        foreach ($keys as $k) {
            $st->bindValue(':k', $k);
            $st->bindValue(':d', $deleted, PDO::PARAM_BOOL);
            $st->execute();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function validKey($id): ?string
{
    $id = is_string($id) ? trim($id) : '';
    return preg_match('/^(stock|item|low|exp):[0-9A-Za-z:\-]{1,60}$/', $id) ? $id : null;
}

/* ================= access check ================= */

$role = (string) ($_SESSION['role'] ?? '');

if ($role === '') {
    failWith('Please log in first.', 401);
}
if ($role !== 'admin') {
    failWith('Only the admin can view notifications.', 403);
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

session_write_close();      // do not keep the session locked while the database works

/* ================= actions ================= */

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* ---------- LIST ---------- */
    if ($action === 'list') {
        $events = buildNotifications($pdo);
        $state  = loadState($pdo, array_column($events, 'id'));

        $shown = [];
        foreach ($events as $ev) {
            [$isRead, $isDeleted] = $state[$ev['id']] ?? [false, false];
            if ($isDeleted) {
                continue;
            }
            $ev['is_read'] = $isRead;
            $shown[] = $ev;
        }

        usort($shown, static function ($a, $b) {
            return $b['ts'] <=> $a['ts'] ?: strcmp($b['id'], $a['id']);
        });
        $shown = array_slice($shown, 0, MAX_SHOWN);

        foreach ($shown as &$s) {
            unset($s['ts']);
        }
        unset($s);

        respond(['success' => true, 'notifications' => $shown]);
    }

    /* ---------- MARK ONE AS READ / DELETE ONE ---------- */
    if ($action === 'mark_read' || $action === 'delete') {
        $key = validKey($in['id'] ?? '');
        if ($key === null) {
            failWith('Unknown notification.', 422);
        }
        saveState($pdo, [$key], $action === 'delete');
        respond(['success' => true]);
    }

    /* ---------- MARK ALL AS READ / CLEAR ALL ---------- */
    if ($action === 'mark_all_read' || $action === 'clear_all') {
        $keys = array_column(buildNotifications($pdo), 'id');
        saveState($pdo, $keys, $action === 'clear_all');
        respond(['success' => true]);
    }

    failWith('Unknown action.', 400);

} catch (PDOException $e) {
    error_log('notifications_db db error: ' . $e->getMessage());
    $sqlState = (string) $e->getCode();

    if ($sqlState === '42P01') {
        failWith('A database table is missing. Run inventory_stock_update.sql and notifications_update.sql in pgAdmin first.', 500);
    }
    if ($sqlState === '42703') {
        failWith('The database needs an update. Run inventory_stock_update.sql in pgAdmin first.', 500);
    }
    failWith(DEBUG ? 'Database error: ' . $e->getMessage() : 'Could not reach the database. Please try again later.', 500);
} catch (Throwable $e) {
    error_log('notifications_db error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    failWith(DEBUG ? $e->getMessage() : 'Something went wrong. Please try again later.', 500);
}