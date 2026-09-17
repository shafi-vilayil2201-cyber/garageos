<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/Pagination.php';
require_once __DIR__ . '/../app/Domain/Gst.php';
require_once __DIR__ . '/../app/Domain/Audit.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

require_permission($user, 'parts.view');

$organizationId = $user['organization_id'];
$branchId = $user['branch_id'];
$canManageParts = user_can($user, 'parts.manage');

$error = null;
$errorAction = null;
$editingPartId = (int) ($_GET['edit'] ?? 0);
$adjustingPartId = (int) ($_GET['adjust'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'parts.manage');

    $action = $_POST['action'] ?? 'create';

    if ($action === 'update') {

        $editingPartId = (int) ($_POST['part_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $costPrice = (float) ($_POST['cost_price'] ?? 0);
        $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
        $reorderLevel = (float) ($_POST['reorder_level'] ?? 0);
        $taxRate = (float) ($_POST['tax_rate'] ?? 0);
        $hsnCode = trim($_POST['hsn_code'] ?? '');

        if ($name === '' || $sku === '') {
            $error = 'Name and SKU are required.';
            $errorAction = 'update';
        } else {
            try {
                $statement = $pdo->prepare("
                    UPDATE parts
                    SET name = :name, sku = :sku, cost_price = :cost_price,
                        selling_price = :selling_price, reorder_level = :reorder_level,
                        tax_rate = :tax_rate, hsn_code = :hsn_code, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id AND organization_id = :organization_id
                ");
                $statement->execute([
                    'name' => $name,
                    'sku' => $sku,
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'reorder_level' => $reorderLevel,
                    'tax_rate' => $taxRate,
                    'hsn_code' => $hsnCode ?: null,
                    'id' => $editingPartId,
                    'organization_id' => $organizationId
                ]);

                if ($statement->rowCount() > 0) {
                    log_audit_event($pdo, $user, 'update', 'part', $editingPartId, "Updated part '$name'");
                }

                header('Location: /parts.php');
                exit;

            } catch (Throwable $e) {
                $error = 'That SKU already exists.';
                $errorAction = 'update';
            }
        }

    } elseif ($action === 'create') {

        $name = trim($_POST['name'] ?? '');
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $costPrice = (float) ($_POST['cost_price'] ?? 0);
        $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
        $openingStock = (float) ($_POST['opening_stock'] ?? 0);
        $reorderLevel = (float) ($_POST['reorder_level'] ?? 0);
        $taxRate = (float) ($_POST['tax_rate'] ?? 0);
        $hsnCode = trim($_POST['hsn_code'] ?? '');

        if ($name === '' || $sku === '') {
            $error = 'Name and SKU are required.';
            $errorAction = 'create';
        } else {

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare("
                    INSERT INTO parts (organization_id, name, sku, cost_price, selling_price, reorder_level, tax_rate, hsn_code)
                    VALUES (:organization_id, :name, :sku, :cost_price, :selling_price, :reorder_level, :tax_rate, :hsn_code)
                    RETURNING id
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'name' => $name,
                    'sku' => $sku,
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'reorder_level' => $reorderLevel,
                    'tax_rate' => $taxRate,
                    'hsn_code' => $hsnCode ?: null
                ]);
                $partId = $statement->fetchColumn();

                $statement = $pdo->prepare("
                    INSERT INTO inventory (part_id, branch_id, quantity)
                    VALUES (:part_id, :branch_id, :quantity)
                ");
                $statement->execute([
                    'part_id' => $partId,
                    'branch_id' => $branchId,
                    'quantity' => $openingStock
                ]);

                if ($openingStock > 0) {
                    $statement = $pdo->prepare("
                        INSERT INTO inventory_movements (organization_id, branch_id, part_id, quantity, direction, reason, created_by)
                        VALUES (:organization_id, :branch_id, :part_id, :quantity, 'in', 'opening_stock', :created_by)
                    ");
                    $statement->execute([
                        'organization_id' => $organizationId,
                        'branch_id' => $branchId,
                        'part_id' => $partId,
                        'quantity' => $openingStock,
                        'created_by' => $user['id']
                    ]);
                }

                log_audit_event($pdo, $user, 'create', 'part', (int) $partId, "Created part '$name'");

                $pdo->commit();

                header('Location: /parts.php');
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'That SKU already exists.';
                $errorAction = 'create';
            }
        }

    } elseif ($action === 'adjust_stock') {

        $adjustPartId = (int) ($_POST['part_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $quantity = (float) ($_POST['quantity'] ?? 0);

        // One dropdown, three real-world cases — each maps to a
        // (direction, reason) pair for inventory_movements. 'reference_type'
        // stays NULL: this is a manual correction, not tied to a purchase
        // or job card, the same way opening_stock movements have no
        // reference either.
        $types = [
            'found' => ['direction' => 'in', 'reason' => 'adjustment'],
            'lower' => ['direction' => 'out', 'reason' => 'adjustment'],
            'damage' => ['direction' => 'out', 'reason' => 'damage'],
        ];

        if ($quantity <= 0 || !isset($types[$type])) {
            $error = 'Choose a reason and enter a quantity greater than zero.';
            $errorAction = 'adjust_stock';
        } else {

            $direction = $types[$type]['direction'];
            $reason = $types[$type]['reason'];

            $pdo->beginTransaction();

            try {
                $statement = $pdo->prepare("
                    SELECT quantity FROM inventory
                    WHERE part_id = :part_id AND branch_id = :branch_id
                    FOR UPDATE
                ");
                $statement->execute(['part_id' => $adjustPartId, 'branch_id' => $branchId]);
                $available = (float) $statement->fetchColumn();

                if ($direction === 'out' && $available < $quantity) {
                    throw new RuntimeException('Not enough stock to remove that much.');
                }

                $statement = $pdo->prepare("
                    UPDATE inventory
                    SET quantity = quantity " . ($direction === 'in' ? '+' : '-') . " :quantity, updated_at = CURRENT_TIMESTAMP
                    WHERE part_id = :part_id AND branch_id = :branch_id
                ");
                $statement->execute([
                    'quantity' => $quantity,
                    'part_id' => $adjustPartId,
                    'branch_id' => $branchId
                ]);

                $statement = $pdo->prepare("
                    INSERT INTO inventory_movements (organization_id, branch_id, part_id, quantity, direction, reason, created_by)
                    VALUES (:organization_id, :branch_id, :part_id, :quantity, :direction, :reason, :created_by)
                ");
                $statement->execute([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'part_id' => $adjustPartId,
                    'quantity' => $quantity,
                    'direction' => $direction,
                    'reason' => $reason,
                    'created_by' => $user['id']
                ]);

                $statement = $pdo->prepare("SELECT name FROM parts WHERE id = :id");
                $statement->execute(['id' => $adjustPartId]);
                $adjustedPartName = $statement->fetchColumn();
                $sign = $direction === 'in' ? '+' : '-';

                log_audit_event(
                    $pdo, $user, 'update', 'inventory', $adjustPartId,
                    "Adjusted stock for '$adjustedPartName' by {$sign}{$quantity} ($reason)"
                );

                $pdo->commit();

                header('Location: /parts.php');
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
                $errorAction = 'adjust_stock';
            }
        }
    }
}

$statement = $pdo->prepare("SELECT default_tax_rate FROM organizations WHERE id = :id");
$statement->execute(['id' => $organizationId]);
$defaultTaxRate = (float) $statement->fetchColumn();

$statement = $pdo->prepare("SELECT COUNT(*) FROM parts WHERE organization_id = :organization_id");
$statement->execute(['organization_id' => $organizationId]);
$totalParts = (int) $statement->fetchColumn();
$page = paginate_page($totalParts);

$statement = $pdo->prepare("
    SELECT p.id, p.name, p.sku, p.selling_price, p.reorder_level, p.tax_rate, p.hsn_code,
           COALESCE(i.quantity, 0) AS stock_quantity
    FROM parts p
    LEFT JOIN inventory i ON i.part_id = p.id AND i.branch_id = :branch_id
    WHERE p.organization_id = :organization_id
    ORDER BY p.name
    LIMIT :limit OFFSET :offset
");
$statement->bindValue('organization_id', $organizationId);
$statement->bindValue('branch_id', $branchId);
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$parts = $statement->fetchAll(PDO::FETCH_ASSOC);

$editingPart = null;

if ($editingPartId) {
    $statement = $pdo->prepare("
        SELECT id, name, sku, cost_price, selling_price, reorder_level, tax_rate, hsn_code
        FROM parts
        WHERE id = :id AND organization_id = :organization_id
    ");
    $statement->execute(['id' => $editingPartId, 'organization_id' => $organizationId]);
    $editingPart = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$editingPart) {
        header('Location: /parts.php');
        exit;
    }
}

$adjustingPart = null;

if ($adjustingPartId) {
    $statement = $pdo->prepare("
        SELECT p.id, p.name, COALESCE(i.quantity, 0) AS stock_quantity
        FROM parts p
        LEFT JOIN inventory i ON i.part_id = p.id AND i.branch_id = :branch_id
        WHERE p.id = :id AND p.organization_id = :organization_id
    ");
    $statement->execute(['id' => $adjustingPartId, 'branch_id' => $branchId, 'organization_id' => $organizationId]);
    $adjustingPart = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$adjustingPart) {
        header('Location: /parts.php');
        exit;
    }
}

$activeNav = 'parts';
$topbarTitle = 'Parts';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Parts</title>

    <link rel="stylesheet" href="/css/app.css">
    <?= favicon_tag($user['organization_logo_url'] ?? null) ?>
</head>
<body>

<div class="app">

    <?php require __DIR__ . '/../app/View/sidebar.php'; ?>

    <main class="main">

        <?php require __DIR__ . '/../app/View/topbar.php'; ?>

        <section class="page">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Parts</h1>
                    <p class="page-description">Stock on hand at this branch.</p>
                </div>

                <?php if ($canManageParts): ?>
                    <button type="button" class="button" onclick="openModal('part-modal')"><?= icon('plus', 16) ?> Add Part</button>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('box', 15) ?></span>
                        Inventory
                    </div>
                    <?php if (!empty($parts)): ?>
                        <input type="search" id="part-filter" class="header-search" placeholder="Search by name or SKU..." autocomplete="off">
                    <?php endif; ?>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($parts)): ?>
                        <div class="empty-state">
                            <?= icon('box', 28) ?>
                            No parts yet. Add your first one.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr><th>Part</th><th>SKU</th><th>Price</th><th>Tax</th><th>Stock</th><th></th></tr>
                                </thead>
                                <tbody id="parts-tbody">
                                <?php foreach ($parts as $part): ?>
                                    <?php $low = $part['stock_quantity'] <= $part['reorder_level']; ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($part['name']) ?>
                                            <?php if ($part['hsn_code']): ?>
                                                <div class="result-meta">HSN <?= htmlspecialchars($part['hsn_code']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($part['sku']) ?></td>
                                        <td class="num">₹<?= number_format($part['selling_price'], 2) ?></td>
                                        <td class="num"><?= number_format($part['tax_rate'], 0) ?>%</td>
                                        <td class="num">
                                            <span class="badge <?= $low ? 'badge-on_hold' : 'badge-ready' ?>">
                                                <?php if ($low): ?><?= icon('alert-triangle', 12) ?><?php endif; ?>
                                                <?= rtrim(rtrim(number_format($part['stock_quantity'], 2), '0'), '.') ?>
                                                <?= $low ? ' — reorder' : '' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($canManageParts): ?>
                                                <div class="row-actions">
                                                    <a href="?adjust=<?= (int) $part['id'] ?>" class="link-action"><?= icon('box', 14) ?> Adjust stock</a>
                                                    <a href="?edit=<?= (int) $part['id'] ?>" class="link-action"><?= icon('settings', 14) ?> Edit</a>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="parts-pagination"><?= render_pagination($page, $totalParts) ?></div>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

<?php if ($canManageParts): ?>

    <div class="modal-backdrop<?= $errorAction === 'create' ? ' open' : '' ?>" id="part-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('box', 16) ?></span>
                    Add Part
                </div>
                <button type="button" class="modal-close" data-close-modal="part-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'create' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Name</label>
                            <input type="text" name="name" required>
                        </div>
                        <div class="form-field">
                            <label>SKU</label>
                            <input type="text" name="sku" required>
                        </div>
                        <div class="form-field">
                            <label>Cost price</label>
                            <input type="number" name="cost_price" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-field">
                            <label>Selling price</label>
                            <input type="number" name="selling_price" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-field">
                            <label>GST rate</label>
                            <select name="tax_rate">
                                <?= gst_rate_options($defaultTaxRate, true) ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>HSN code (optional)</label>
                            <input type="text" name="hsn_code" placeholder="e.g. 8708">
                        </div>
                        <div class="form-field">
                            <label>Opening stock</label>
                            <input type="number" name="opening_stock" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-field">
                            <label>Reorder level</label>
                            <input type="number" name="reorder_level" step="0.01" min="0" value="0">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save part</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-backdrop<?= $editingPart ? ' open' : '' ?>" id="edit-part-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('box', 16) ?></span>
                    Edit Part
                </div>
                <button type="button" class="modal-close" data-close-modal="edit-part-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'update' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($editingPart): ?>
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="part_id" value="<?= (int) $editingPart['id'] ?>">
                        <div class="form-grid single">
                            <div class="form-field">
                                <label>Name</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($editingPart['name']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>SKU</label>
                                <input type="text" name="sku" value="<?= htmlspecialchars($editingPart['sku']) ?>" required>
                            </div>
                            <div class="form-field">
                                <label>Cost price</label>
                                <input type="number" name="cost_price" step="0.01" min="0" value="<?= htmlspecialchars($editingPart['cost_price']) ?>">
                            </div>
                            <div class="form-field">
                                <label>Selling price</label>
                                <input type="number" name="selling_price" step="0.01" min="0" value="<?= htmlspecialchars($editingPart['selling_price']) ?>">
                            </div>
                            <div class="form-field">
                                <label>GST rate</label>
                                <select name="tax_rate">
                                    <?= gst_rate_options((float) $editingPart['tax_rate'], true) ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>HSN code (optional)</label>
                                <input type="text" name="hsn_code" placeholder="e.g. 8708" value="<?= htmlspecialchars($editingPart['hsn_code'] ?? '') ?>">
                            </div>
                            <div class="form-field">
                                <label>Reorder level</label>
                                <input type="number" name="reorder_level" step="0.01" min="0" value="<?= htmlspecialchars($editingPart['reorder_level']) ?>">
                            </div>
                        </div>
                        <p class="page-description">Current stock isn't edited here — use Purchases to record new stock coming in, or "Adjust stock" for a count correction or damage/loss.</p>
                        <div class="form-actions">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Save changes</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal-backdrop<?= $adjustingPart ? ' open' : '' ?>" id="adjust-stock-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('box', 16) ?></span>
                    Adjust Stock
                </div>
                <button type="button" class="modal-close" data-close-modal="adjust-stock-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'adjust_stock' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($adjustingPart): ?>
                    <p class="page-description">
                        <strong><?= htmlspecialchars($adjustingPart['name']) ?></strong> —
                        currently <?= rtrim(rtrim(number_format($adjustingPart['stock_quantity'], 2), '0'), '.') ?> in stock.
                    </p>
                    <form method="POST" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="adjust_stock">
                        <input type="hidden" name="part_id" value="<?= (int) $adjustingPart['id'] ?>">
                        <div class="form-grid single">
                            <div class="form-field">
                                <label>Reason</label>
                                <select name="type" required>
                                    <option value="">Select a reason</option>
                                    <option value="found">Found extra stock (count correction)</option>
                                    <option value="lower">Stock count is lower (correction)</option>
                                    <option value="damage">Damage / loss</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Quantity</label>
                                <input type="number" name="quantity" step="0.01" min="0.01" required>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="button"><?= icon('check', 16) ?> Save adjustment</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="/js/modal.js"></script>

<?php endif; ?>

<?php if (!empty($parts)): ?>

    <script src="/js/live-table-search.js"></script>
    <script>
        const canManageParts = <?= json_encode($canManageParts) ?>;
        const editIcon = <?= json_encode(icon('settings', 14)) ?>;
        const adjustIcon = <?= json_encode(icon('box', 14)) ?>;
        const warningIcon = <?= json_encode(icon('alert-triangle', 12)) ?>;

        function trimTrailingZeros(value)
        {
            return Number(value).toFixed(2).replace(/\.?0+$/, '');
        }

        initLiveTableSearch({
            inputId: 'part-filter',
            tbodyId: 'parts-tbody',
            paginationId: 'parts-pagination',
            endpoint: '/api/parts/search.php',
            resultsKey: 'parts',
            colspan: 6,
            emptyMessage: 'No parts found.',
            renderRow: (part, escapeHtml) =>
            {
                const low = Number(part.stock_quantity) <= Number(part.reorder_level);

                return `
                    <tr>
                        <td>
                            ${escapeHtml(part.name)}
                            ${part.hsn_code ? `<div class="result-meta">HSN ${escapeHtml(part.hsn_code)}</div>` : ''}
                        </td>
                        <td>${escapeHtml(part.sku)}</td>
                        <td class="num">₹${Number(part.selling_price).toFixed(2)}</td>
                        <td class="num">${Number(part.tax_rate).toFixed(0)}%</td>
                        <td class="num">
                            <span class="badge ${low ? 'badge-on_hold' : 'badge-ready'}">
                                ${low ? warningIcon : ''}${trimTrailingZeros(part.stock_quantity)}${low ? ' — reorder' : ''}
                            </span>
                        </td>
                        <td>
                            ${canManageParts ? `<div class="row-actions"><a href="?adjust=${part.id}" class="link-action">${adjustIcon} Adjust stock</a><a href="?edit=${part.id}" class="link-action">${editIcon} Edit</a></div>` : ''}
                        </td>
                    </tr>
                `;
            }
        });
    </script>

<?php endif; ?>

</body>
</html>
