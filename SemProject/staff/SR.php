<?php
session_start();

/* ---------- ACCESS CONTROL ----------
   Uncomment once your login page sets this session value.
if (!isset($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}
*/

/* ---------- DATABASE ----------
   Create config.php next to this file and make it define a PDO connection:

   <?php
   $pdo = new PDO('mysql:host=localhost;dbname=your_db;charset=utf8mb4', 'user', 'pass', [
       PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
   ]);

   Table used by this page:

   CREATE TABLE stocking_records (
       id            INT AUTO_INCREMENT PRIMARY KEY,
       item_name     VARCHAR(100)  NOT NULL,
       quantity      DECIMAL(10,2) NOT NULL,
       unit          VARCHAR(30)   NOT NULL,
       supplier      VARCHAR(100)  NULL,
       date_received DATE          NOT NULL,
       received_by   VARCHAR(100)  NOT NULL,
       remarks       VARCHAR(255)  NULL,
       created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );
*/
$pdo = null;
if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}

$employeeName = $_SESSION['employee_name'] ?? 'Employee';
$units        = ['Packs', 'Pieces', 'Boxes', 'Bottles', 'Kilograms', 'Grams', 'Liters', 'Milliliters'];
$today        = date('Y-m-d');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ---------- ADD A RECORD ---------- */
$errors = [];
$old = ['item_name' => '', 'quantity' => '', 'unit' => '', 'supplier' => '', 'date_received' => $today, 'remarks' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        foreach ($old as $key => $_) {
            $old[$key] = trim((string)($_POST[$key] ?? ''));
        }

        $dateObj = DateTime::createFromFormat('Y-m-d', $old['date_received']);
        $dateOk  = $dateObj && $dateObj->format('Y-m-d') === $old['date_received'];

        if ($old['item_name'] === '' || mb_strlen($old['item_name']) > 100) {
            $errors[] = 'Enter an item name (up to 100 characters).';
        }
        if (!is_numeric($old['quantity']) || (float)$old['quantity'] <= 0 || (float)$old['quantity'] > 99999999) {
            $errors[] = 'Enter a quantity greater than zero.';
        }
        if (!in_array($old['unit'], $units, true)) {
            $errors[] = 'Choose a unit.';
        }
        if (mb_strlen($old['supplier']) > 100) {
            $errors[] = 'Supplier must be 100 characters or fewer.';
        }
        if (!$dateOk || $old['date_received'] > $today) {
            $errors[] = 'Enter a valid date received that is not in the future.';
        }
        if (mb_strlen($old['remarks']) > 255) {
            $errors[] = 'Remarks must be 255 characters or fewer.';
        }

        if (!$errors && !$pdo) {
            $errors[] = 'No database connection found. Create config.php that defines $pdo.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO stocking_records (item_name, quantity, unit, supplier, date_received, received_by, remarks)
                     VALUES (:item, :qty, :unit, :supplier, :date, :by, :remarks)'
                );
                $stmt->execute([
                    ':item'     => $old['item_name'],
                    ':qty'      => (float)$old['quantity'],
                    ':unit'     => $old['unit'],
                    ':supplier' => $old['supplier'] !== '' ? $old['supplier'] : null,
                    ':date'     => $old['date_received'],
                    ':by'       => $employeeName,
                    ':remarks'  => $old['remarks'] !== '' ? $old['remarks'] : null,
                ]);
                $_SESSION['flash'] = 'Stock record added.';
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            } catch (PDOException $e) {
                $errors[] = 'The record could not be saved. Please try again.';
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- FILTERS + RECORD LIST ---------- */
function validDate(string $d): string
{
    $o = DateTime::createFromFormat('Y-m-d', $d);
    return ($o && $o->format('Y-m-d') === $d) ? $d : '';
}

$search = trim((string)($_GET['q'] ?? ''));
$from   = validDate((string)($_GET['from'] ?? ''));
$to     = validDate((string)($_GET['to'] ?? ''));
$isFiltered = ($search !== '' || $from !== '' || $to !== '');

$records = [];
if ($pdo) {
    $where  = [];
    $params = [];
    if ($search !== '') {
        $where[]            = '(item_name LIKE :q OR supplier LIKE :q)';
        $params[':q']       = '%' . $search . '%';
    }
    if ($from !== '') {
        $where[]            = 'date_received >= :from';
        $params[':from']    = $from;
    }
    if ($to !== '') {
        $where[]            = 'date_received <= :to';
        $params[':to']      = $to;
    }

    $sql = 'SELECT id, item_name, quantity, unit, supplier, date_received, received_by, remarks
            FROM stocking_records'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY date_received DESC, id DESC LIMIT 200';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();
    } catch (PDOException $e) {
        $errors[] = 'The records could not be loaded.';
    }
}

function fmtQty($n): string
{
    return rtrim(rtrim(number_format((float)$n, 2, '.', ','), '0'), '.');
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stocking Record</title>
    <link rel="stylesheet" href="staff/assets/sr.css">
</head>
<body class="sr-body">

<div class="sr-page">

    <!-- PAGE HEADER -->
    <header class="sr-page-header">
        <h1>Stocking Record</h1>
    </header>

    <main class="sr-content">

        <?php if ($flash): ?>
            <div class="sr-alert sr-alert-success" role="status"><?= e($flash) ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="sr-alert sr-alert-error" role="alert">
                <strong>Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="sr-panel">

            <!-- ADD RECORD FORM -->
            <h2 class="sr-section-title">Add stock</h2>

            <form class="sr-record-form" method="post" action="">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

                <div class="sr-field sr-field-item">
                    <label for="item_name">Item name</label>
                    <input type="text" id="item_name" name="item_name" maxlength="100" required
                           value="<?= e($old['item_name']) ?>">
                </div>

                <div class="sr-field">
                    <label for="quantity">Quantity</label>
                    <input type="number" id="quantity" name="quantity" min="0.01" step="0.01" required
                           value="<?= e($old['quantity']) ?>">
                </div>

                <div class="sr-field">
                    <label for="unit">Unit</label>
                    <select id="unit" name="unit" required>
                        <option value="">Choose unit</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= e($u) ?>" <?= $old['unit'] === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sr-field">
                    <label for="date_received">Date received</label>
                    <input type="date" id="date_received" name="date_received" max="<?= e($today) ?>" required
                           value="<?= e($old['date_received']) ?>">
                </div>

                <div class="sr-field sr-field-supplier">
                    <label for="supplier">Supplier <span class="sr-optional">(optional)</span></label>
                    <input type="text" id="supplier" name="supplier" maxlength="100"
                           value="<?= e($old['supplier']) ?>">
                </div>

                <div class="sr-field sr-field-remarks">
                    <label for="remarks">Remarks <span class="sr-optional">(optional)</span></label>
                    <input type="text" id="remarks" name="remarks" maxlength="255"
                           value="<?= e($old['remarks']) ?>">
                </div>

                <div class="sr-form-actions">
                    <button type="submit" class="sr-btn">Add record</button>
                </div>
            </form>

            <!-- SEARCH + DATE FILTER -->
            <h2 class="sr-section-title">Records</h2>

            <form class="sr-filter-bar" method="get" action="">
                <div class="sr-field sr-field-search">
                    <label for="q">Search</label>
                    <input type="search" id="q" name="q" placeholder="Item or supplier" value="<?= e($search) ?>">
                </div>
                <div class="sr-field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?= e($from) ?>">
                </div>
                <div class="sr-field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?= e($to) ?>">
                </div>
                <div class="sr-filter-actions">
                    <button type="submit" class="sr-btn">Filter</button>
                    <?php if ($isFiltered): ?>
                        <a href="<?= e(strtok($_SERVER['REQUEST_URI'], '?')) ?>" class="sr-btn sr-btn-light">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- RECORDS TABLE -->
            <div class="sr-table-wrap">
                <table class="sr-record-table">
                    <thead>
                        <tr>
                            <th>Date received</th>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Supplier</th>
                            <th>Received by</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$records): ?>
                            <tr class="sr-empty-row">
                                <td colspan="7">
                                    <?= $isFiltered ? 'No records match your search.' : 'No stock records yet. Add your first one above.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $r): ?>
                                <tr>
                                    <td><?= e(date('M d, Y', strtotime($r['date_received']))) ?></td>
                                    <td class="sr-item-cell"><?= e($r['item_name']) ?></td>
                                    <td><span class="sr-qty-badge">+<?= e(fmtQty($r['quantity'])) ?></span></td>
                                    <td><?= e($r['unit']) ?></td>
                                    <td><?= $r['supplier'] !== null && $r['supplier'] !== '' ? e($r['supplier']) : '<span class="sr-muted">None</span>' ?></td>
                                    <td><?= e($r['received_by']) ?></td>
                                    <td><?= $r['remarks'] !== null && $r['remarks'] !== '' ? e($r['remarks']) : '<span class="sr-muted">None</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </section>
    </main>

</div>

</body>
</html>