<?php

require_once __DIR__ . '/../app/Auth/Auth.php';
require_once __DIR__ . '/../app/Security/Csrf.php';
require_once __DIR__ . '/../app/View/Pagination.php';
require_once __DIR__ . '/../app/Domain/Gst.php';

$pdo = require __DIR__ . '/../config/database.php';

$auth = new Auth($pdo);

$user = $auth->user();

if (!$user) {
    header('Location: /');
    exit;
}

// Service catalog is organization-wide configuration, same tier as
// branding and staff — gated the same way settings.php already is.
require_permission($user, 'settings.manage');

$organizationId = $user['organization_id'];

function generate_service_code(PDO $pdo, int $organizationId, string $name): string
{
    $base = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));

    if ($base === '') {
        $base = 'SERVICE';
    }

    $base = substr($base, 0, 40);

    $statement = $pdo->prepare("SELECT 1 FROM services WHERE organization_id = :organization_id AND code = :code");

    $code = $base;
    $suffix = 1;

    while (true) {
        $statement->execute(['organization_id' => $organizationId, 'code' => $code]);

        if (!$statement->fetch()) {
            return $code;
        }

        $suffix++;
        $code = $base . '_' . $suffix;
    }
}

$error = null;
$errorAction = null;
$editingServiceId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'settings.manage');

    $action = $_POST['action'] ?? 'create';
    $name = trim($_POST['name'] ?? '');
    $categoryId = (int) ($_POST['service_category_id'] ?? 0) ?: null;
    $standardPrice = (float) ($_POST['standard_price'] ?? 0);
    $estimatedMinutes = (int) ($_POST['estimated_minutes'] ?? 30);
    $taxRate = (float) ($_POST['tax_rate'] ?? 0);
    $sacCode = trim($_POST['sac_code'] ?? '');

    if ($action === 'update') {

        $editingServiceId = (int) ($_POST['service_id'] ?? 0);

        if ($name === '') {
            $error = 'Service name is required.';
            $errorAction = 'update';
        } else {
            $statement = $pdo->prepare("
                UPDATE services
                SET name = :name, service_category_id = :service_category_id,
                    standard_price = :standard_price, estimated_minutes = :estimated_minutes,
                    tax_rate = :tax_rate, sac_code = :sac_code,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND organization_id = :organization_id
            ");
            $statement->execute([
                'name' => $name,
                'service_category_id' => $categoryId,
                'standard_price' => $standardPrice,
                'estimated_minutes' => $estimatedMinutes,
                'tax_rate' => $taxRate,
                'sac_code' => $sacCode ?: null,
                'id' => $editingServiceId,
                'organization_id' => $organizationId
            ]);

            header('Location: /services.php');
            exit;
        }

    } else {

        if ($name === '') {
            $error = 'Service name is required.';
            $errorAction = 'create';
        } else {
            $code = generate_service_code($pdo, $organizationId, $name);

            $statement = $pdo->prepare("
                INSERT INTO services (organization_id, service_category_id, name, code, standard_price, estimated_minutes, tax_rate, sac_code)
                VALUES (:organization_id, :service_category_id, :name, :code, :standard_price, :estimated_minutes, :tax_rate, :sac_code)
            ");
            $statement->execute([
                'organization_id' => $organizationId,
                'service_category_id' => $categoryId,
                'name' => $name,
                'code' => $code,
                'standard_price' => $standardPrice,
                'estimated_minutes' => $estimatedMinutes,
                'tax_rate' => $taxRate,
                'sac_code' => $sacCode ?: null
            ]);

            header('Location: /services.php');
            exit;
        }
    }
}

$statement = $pdo->prepare("SELECT id, name FROM service_categories WHERE organization_id = :organization_id ORDER BY name");
$statement->execute(['organization_id' => $organizationId]);
$categories = $statement->fetchAll(PDO::FETCH_ASSOC);

$statement = $pdo->prepare("SELECT default_tax_rate FROM organizations WHERE id = :id");
$statement->execute(['id' => $organizationId]);
$defaultTaxRate = (float) $statement->fetchColumn();

$statement = $pdo->prepare("SELECT COUNT(*) FROM services WHERE organization_id = :organization_id");
$statement->execute(['organization_id' => $organizationId]);
$totalServices = (int) $statement->fetchColumn();
$page = paginate_page($totalServices);

$statement = $pdo->prepare("
    SELECT s.id, s.name, s.standard_price, s.estimated_minutes, s.tax_rate, s.sac_code, sc.name AS category_name
    FROM services s
    LEFT JOIN service_categories sc ON sc.id = s.service_category_id
    WHERE s.organization_id = :organization_id
    ORDER BY s.name
    LIMIT :limit OFFSET :offset
");
$statement->bindValue('organization_id', $organizationId);
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$services = $statement->fetchAll(PDO::FETCH_ASSOC);

$editingService = null;

if ($editingServiceId) {
    $statement = $pdo->prepare("
        SELECT id, name, service_category_id, standard_price, estimated_minutes, tax_rate, sac_code
        FROM services
        WHERE id = :id AND organization_id = :organization_id
    ");
    $statement->execute(['id' => $editingServiceId, 'organization_id' => $organizationId]);
    $editingService = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$editingService) {
        header('Location: /services.php');
        exit;
    }
}

$activeNav = 'settings';
$topbarTitle = 'Service Catalog';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Service Catalog</title>

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
                    <h1 class="page-title">Service Catalog</h1>
                    <p class="page-description">The labor services you offer, and what you charge for them.</p>
                </div>

                <button type="button" class="button" onclick="openModal('service-modal')"><?= icon('plus', 16) ?> Add Service</button>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('settings', 15) ?></span>
                        All services
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($services)): ?>
                        <div class="empty-state">
                            <?= icon('settings', 28) ?>
                            No services yet. Add your first one.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <tr>
                                    <th>Service</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Tax</th>
                                    <th>Est. time</th>
                                    <th></th>
                                </tr>
                                <?php foreach ($services as $service): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($service['name']) ?>
                                            <?php if ($service['sac_code']): ?>
                                                <div class="result-meta">SAC <?= htmlspecialchars($service['sac_code']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($service['category_name'] ?? '—') ?></td>
                                        <td class="num">₹<?= number_format($service['standard_price'], 2) ?></td>
                                        <td class="num"><?= number_format($service['tax_rate'], 0) ?>%</td>
                                        <td class="num"><?= (int) $service['estimated_minutes'] ?> min</td>
                                        <td>
                                            <a href="?edit=<?= (int) $service['id'] ?>" class="link-action"><?= icon('settings', 14) ?> Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <?= render_pagination($page, $totalServices) ?>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

<div class="modal-backdrop<?= $errorAction === 'create' ? ' open' : '' ?>" id="service-modal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-title">
                <span class="icon-badge"><?= icon('settings', 16) ?></span>
                Add Service
            </div>
            <button type="button" class="modal-close" data-close-modal="service-modal" aria-label="Close"><?= icon('x', 18) ?></button>
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
                        <label>Service name</label>
                        <input type="text" name="name" placeholder="e.g. Oil & filter change" required>
                    </div>
                    <div class="form-field">
                        <label>Category (optional)</label>
                        <select name="service_category_id">
                            <option value="">No category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>Standard price</label>
                        <input type="number" name="standard_price" step="0.01" min="0" value="0">
                    </div>
                    <div class="form-field">
                        <label>GST rate</label>
                        <select name="tax_rate">
                            <?= gst_rate_options($defaultTaxRate, true) ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>SAC code (optional)</label>
                        <input type="text" name="sac_code" placeholder="e.g. 998714">
                    </div>
                    <div class="form-field">
                        <label>Estimated time (minutes)</label>
                        <input type="number" name="estimated_minutes" min="1" value="30">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button"><?= icon('check', 16) ?> Save service</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-backdrop<?= $editingService ? ' open' : '' ?>" id="edit-service-modal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-title">
                <span class="icon-badge"><?= icon('settings', 16) ?></span>
                Edit Service
            </div>
            <button type="button" class="modal-close" data-close-modal="edit-service-modal" aria-label="Close"><?= icon('x', 18) ?></button>
        </div>
        <div class="modal-body">

            <?php if ($errorAction === 'update' && $error): ?>
                <div class="form-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($editingService): ?>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="service_id" value="<?= (int) $editingService['id'] ?>">
                    <div class="form-grid single">
                        <div class="form-field">
                            <label>Service name</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($editingService['name']) ?>" required>
                        </div>
                        <div class="form-field">
                            <label>Category (optional)</label>
                            <select name="service_category_id">
                                <option value="">No category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>" <?= (int) $editingService['service_category_id'] === (int) $category['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Standard price</label>
                            <input type="number" name="standard_price" step="0.01" min="0" value="<?= htmlspecialchars($editingService['standard_price']) ?>">
                        </div>
                        <div class="form-field">
                            <label>GST rate</label>
                            <select name="tax_rate">
                                <?= gst_rate_options((float) $editingService['tax_rate'], true) ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>SAC code (optional)</label>
                            <input type="text" name="sac_code" placeholder="e.g. 998714" value="<?= htmlspecialchars($editingService['sac_code'] ?? '') ?>">
                        </div>
                        <div class="form-field">
                            <label>Estimated time (minutes)</label>
                            <input type="number" name="estimated_minutes" min="1" value="<?= (int) $editingService['estimated_minutes'] ?>">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save changes</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="/js/modal.js"></script>

</body>
</html>
