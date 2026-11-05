<?php
session_start();

/* ---------- ACCESS CONTROL ----------
   Uncomment once your login page sets this session value.
if (!isset($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}
*/

/* ---------- DASHBOARD DATA ----------
   Replace these with real queries. Example with PDO:

   require 'config.php';  // defines $pdo
   $stats['ingredients'] = $pdo->query("SELECT COUNT(*) FROM ingredients")->fetchColumn();
   $stockItems = $pdo->query("SELECT name, quantity, unit, reorder_level FROM ingredients ORDER BY name")
                     ->fetchAll(PDO::FETCH_ASSOC);
*/
$stats = [
    'ingredients' => 0,
    'cookies'     => 0,
    'supplies'    => 0,
    'stocks'      => 0,
    'low_stocks'  => 0,
    'orders'      => 0,
];

/* Each item: name, quantity, unit, reorder_level */
$stockItems = [
    // ['name' => 'Flour', 'quantity' => 12, 'unit' => 'Packs', 'reorder_level' => 5],
];

function stockStatus(int $qty, int $reorder): array
{
    if ($qty <= 0)        return ['Out of Stock', 'status-out'];
    if ($qty <= $reorder) return ['Low Stock',    'status-low'];
    return ['In Stock', 'status-in'];
}

/* Inline SVG icons so the page works without any CDN */
function icon(string $name): string
{
    $paths = [
        'ingredients' => '<path d="M5 11h14l-1.2 8.4a2 2 0 0 1-2 1.6H8.2a2 2 0 0 1-2-1.6L5 11z"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'cookies'     => '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="9" r="1"/><circle cx="15" cy="10" r="1"/><circle cx="10" cy="15" r="1"/><circle cx="15" cy="15" r="1"/>',
        'supplies'    => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'stocks'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'low'         => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18h.01"/>',
        'orders'      => '<path d="M6 2l-2 4v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6l-2-4H6z"/><path d="M4 6h16"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($paths[$name] ?? '') . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Dashboard</title>
    <link rel="stylesheet" href="staff/assets/dashboard_e.css">
</head>
<body>

    <!-- PAGE HEADER -->
    <header class="page-header">
        <h1>Dashboard</h1>
    </header>

    <main class="content">
        <section class="panel">

            <!-- OVERVIEW CARDS -->
            <div class="stat-grid">
                <article class="stat-card">
                    <div class="stat-icon"><?= icon('ingredients') ?></div>
                    <div class="stat-text">
                        <span class="stat-number"><?= (int)$stats['ingredients'] ?></span>
                        <span class="stat-label">Total Ingredients</span>
                    </div>
                </article>

                <article class="stat-card">
                    <div class="stat-icon"><?= icon('cookies') ?></div>
                    <div class="stat-text">
                        <span class="stat-number"><?= (int)$stats['cookies'] ?></span>
                        <span class="stat-label">Total Cookies</span>
                    </div>
                </article>

                <article class="stat-card">
                    <div class="stat-icon"><?= icon('supplies') ?></div>
                    <div class="stat-text">
                        <span class="stat-number"><?= (int)$stats['supplies'] ?></span>
                        <span class="stat-label">Total Supplies</span>
                    </div>
                </article>

                <article class="stat-card stat-wide">
                    <div class="stat-icon"><?= icon('stocks') ?></div>
                    <div class="stat-text">
                        <span class="stat-number"><?= (int)$stats['stocks'] ?></span>
                        <span class="stat-label">Total Stocks</span>
                    </div>
                </article>

                <article class="stat-card stat-wide <?= $stats['low_stocks'] > 0 ? 'stat-alert' : '' ?>">
                    <div class="stat-icon"><?= icon('low') ?></div>
                    <div class="stat-text">
                        <span class="stat-number"><?= (int)$stats['low_stocks'] ?></span>
                        <span class="stat-label">Low Stocks</span>
                    </div>
                </article>

                <article class="stat-card stat-order">
                    <span class="stat-label">Total Cookie Order</span>
                    <span class="stat-number"><?= (int)$stats['orders'] ?></span>
                    <div class="stat-icon"><?= icon('orders') ?></div>
                </article>
            </div>

            <!-- STOCK TABLE -->
            <h2 class="section-title">Current stock</h2>

            <div class="table-wrap">
                <table class="stock-table">
                    <thead>
                        <tr>
                            <th>Specification</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stockItems)): ?>
                            <tr class="empty-row">
                                <td colspan="4">No stock items yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stockItems as $item):
                                [$label, $class] = stockStatus((int)$item['quantity'], (int)$item['reorder_level']); ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td><?= (int)$item['quantity'] ?></td>
                                    <td><?= htmlspecialchars($item['unit']) ?></td>
                                    <td><span class="status <?= $class ?>"><?= $label ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </section>
    </main>

</body>
</html>