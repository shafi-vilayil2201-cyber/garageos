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

require_permission($user, 'customers.view');

$organizationId = $user['organization_id'];
$canManageCustomers = user_can($user, 'customers.manage');

$error = null;
$errorAction = null;
$editingCustomerId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();
    require_permission($user, 'customers.manage');

    $action = $_POST['action'] ?? 'create';
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gstin = strtoupper(trim($_POST['gstin'] ?? ''));
    $state = trim($_POST['state'] ?? '');

    if ($action === 'update') {

        $editingCustomerId = (int) ($_POST['customer_id'] ?? 0);

        if ($name === '' || $phone === '') {
            $error = 'Name and phone are required.';
            $errorAction = 'update';
        } elseif ($state !== '' && !in_array($state, INDIAN_STATES, true)) {
            $error = 'Choose a valid state.';
            $errorAction = 'update';
        } else {
            $statement = $pdo->prepare("
                UPDATE customers
                SET name = :name, phone = :phone, email = :email, address = :address, gstin = :gstin, state = :state, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND organization_id = :organization_id
            ");
            $statement->execute([
                'name' => $name,
                'phone' => $phone,
                'email' => $email ?: null,
                'address' => $address ?: null,
                'gstin' => $gstin ?: null,
                'state' => $state ?: null,
                'id' => $editingCustomerId,
                'organization_id' => $organizationId
            ]);

            if ($statement->rowCount() > 0) {
                log_audit_event($pdo, $user, 'update', 'customer', $editingCustomerId, "Updated customer $name");
            }

            header('Location: /customers.php');
            exit;
        }

    } else {

        if ($name === '' || $phone === '') {
            $error = 'Name and phone are required.';
            $errorAction = 'create';
        } else {

            $statement = $pdo->prepare("
                SELECT COALESCE(MAX(CAST(SUBSTRING(code FROM 6) AS INT)), 0) + 1
                FROM customers
                WHERE organization_id = :organization_id
            ");
            $statement->execute(['organization_id' => $organizationId]);
            $nextNumber = (int) $statement->fetchColumn();
            $code = 'CUST-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            $statement = $pdo->prepare("
                INSERT INTO customers (organization_id, name, code, phone, email, address)
                VALUES (:organization_id, :name, :code, :phone, :email, :address)
                RETURNING id
            ");

            $statement->execute([
                'organization_id' => $organizationId,
                'name' => $name,
                'code' => $code,
                'phone' => $phone,
                'email' => $email ?: null,
                'address' => $address ?: null
            ]);
            $newCustomerId = (int) $statement->fetchColumn();

            log_audit_event($pdo, $user, 'create', 'customer', $newCustomerId, "Created customer $name");

            header('Location: /customers.php');
            exit;
        }
    }
}

$statement = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE organization_id = :organization_id");
$statement->execute(['organization_id' => $organizationId]);
$totalCustomers = (int) $statement->fetchColumn();
$page = paginate_page($totalCustomers);

$statement = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.code,
        c.phone,
        c.email,
        (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id) AS vehicle_count
    FROM customers c
    WHERE c.organization_id = :organization_id
    ORDER BY c.created_at DESC
    LIMIT :limit OFFSET :offset
");
$statement->bindValue('organization_id', $organizationId);
$statement->bindValue('limit', PAGINATION_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue('offset', paginate_offset($page), PDO::PARAM_INT);
$statement->execute();
$customers = $statement->fetchAll(PDO::FETCH_ASSOC);

$editingCustomer = null;

if ($editingCustomerId) {
    $statement = $pdo->prepare("
        SELECT id, name, phone, email, address, gstin, state
        FROM customers
        WHERE id = :id AND organization_id = :organization_id
    ");
    $statement->execute(['id' => $editingCustomerId, 'organization_id' => $organizationId]);
    $editingCustomer = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$editingCustomer) {
        header('Location: /customers.php');
        exit;
    }
}

$activeNav = 'customers';
$topbarTitle = 'Customers';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GarageOS — Customers</title>

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
                    <h1 class="page-title">Customers</h1>
                    <p class="page-description">Everyone who's ever brought a vehicle to your workshop.</p>
                </div>

                <?php if ($canManageCustomers): ?>
                    <button type="button" class="button" onclick="openModal('customer-modal')"><?= icon('plus', 16) ?> Add Customer</button>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-header-title">
                        <span class="icon-badge"><?= icon('person', 15) ?></span>
                        All customers
                    </div>
                    <?php if (!empty($customers)): ?>
                        <input type="search" id="customer-filter" class="header-search" placeholder="Search by name or phone..." autocomplete="off">
                    <?php endif; ?>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($customers)): ?>
                        <div class="empty-state">
                            <?= icon('person', 28) ?>
                            No customers yet. Add your first one.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Vehicles</th>
                                        <th></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="customers-tbody">
                                <?php foreach ($customers as $customer): ?>
                                    <tr class="clickable" data-href="/customer.php?id=<?= (int) $customer['id'] ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($customer['name']) ?></strong>
                                            <div class="result-meta"><?= htmlspecialchars($customer['code']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($customer['phone']) ?></td>
                                        <td class="num"><?= (int) $customer['vehicle_count'] ?></td>
                                        <td>
                                            <a href="/vehicles.php?customer_id=<?= (int) $customer['id'] ?>" class="link-action">
                                                <?= icon('plus', 14) ?> Add vehicle
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($canManageCustomers): ?>
                                                <a href="?edit=<?= (int) $customer['id'] ?>" class="link-action"><?= icon('settings', 14) ?> Edit</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="customers-pagination"><?= render_pagination($page, $totalCustomers) ?></div>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

<?php if ($canManageCustomers): ?>

    <div class="modal-backdrop<?= $errorAction === 'create' ? ' open' : '' ?>" id="customer-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('person', 16) ?></span>
                    Add Customer
                </div>
                <button type="button" class="modal-close" data-close-modal="customer-modal" aria-label="Close"><?= icon('x', 18) ?></button>
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
                            <label for="name">Full name</label>
                            <input type="text" id="name" name="name" required>
                        </div>

                        <div class="form-field">
                            <label for="phone">Phone</label>
                            <input type="tel" id="phone" name="phone" required>
                        </div>

                        <div class="form-field">
                            <label for="email">Email (optional)</label>
                            <input type="email" id="email" name="email">
                        </div>

                        <div class="form-field">
                            <label for="address">Address (optional)</label>
                            <textarea id="address" name="address"></textarea>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="button"><?= icon('check', 16) ?> Save customer</button>
                    </div>

                </form>

            </div>
        </div>
    </div>

    <div class="modal-backdrop<?= $editingCustomer ? ' open' : '' ?>" id="edit-customer-modal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-title">
                    <span class="icon-badge"><?= icon('person', 16) ?></span>
                    Edit Customer
                </div>
                <button type="button" class="modal-close" data-close-modal="edit-customer-modal" aria-label="Close"><?= icon('x', 18) ?></button>
            </div>
            <div class="modal-body">

                <?php if ($errorAction === 'update' && $error): ?>
                    <div class="form-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($editingCustomer): ?>
                    <form method="POST" action="">

                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="customer_id" value="<?= (int) $editingCustomer['id'] ?>">

                        <div class="form-grid single">

                            <div class="form-field">
                                <label>Full name</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($editingCustomer['name']) ?>" required>
                            </div>

                            <div class="form-field">
                                <label>Phone</label>
                                <input type="tel" name="phone" value="<?= htmlspecialchars($editingCustomer['phone']) ?>" required>
                            </div>

                            <div class="form-field">
                                <label>Email (optional)</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($editingCustomer['email'] ?? '') ?>">
                            </div>

                            <div class="form-field">
                                <label>Address (optional)</label>
                                <textarea name="address"><?= htmlspecialchars($editingCustomer['address'] ?? '') ?></textarea>
                            </div>

                            <div class="form-field">
                                <label>GSTIN (optional)</label>
                                <input type="text" name="gstin" placeholder="For B2B invoices" value="<?= htmlspecialchars($editingCustomer['gstin'] ?? '') ?>">
                            </div>

                            <div class="form-field">
                                <label>State (optional)</label>
                                <select name="state">
                                    <?= state_options($editingCustomer['state'] ?? null) ?>
                                </select>
                                <p class="result-meta" style="margin-top:6px;">Used to show GST as CGST+SGST or IGST on invoices — leave unset if unsure.</p>
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

<?php endif; ?>

<?php if (!empty($customers)): ?>

    <script src="/js/live-table-search.js"></script>
    <script src="/js/clickable-rows.js"></script>
    <script>
        const canManageCustomers = <?= json_encode($canManageCustomers) ?>;
        const plusIcon = <?= json_encode(icon('plus', 14)) ?>;
        const editIcon = <?= json_encode(icon('settings', 14)) ?>;

        initLiveTableSearch({
            inputId: 'customer-filter',
            tbodyId: 'customers-tbody',
            paginationId: 'customers-pagination',
            endpoint: '/api/customers/search.php',
            resultsKey: 'customers',
            colspan: 5,
            emptyMessage: 'No customers found.',
            renderRow: (customer, escapeHtml) => `
                <tr class="clickable" data-href="/customer.php?id=${customer.id}">
                    <td>
                        <strong>${escapeHtml(customer.name)}</strong>
                        <div class="result-meta">${escapeHtml(customer.code)}</div>
                    </td>
                    <td>${escapeHtml(customer.phone)}</td>
                    <td class="num">${Number(customer.vehicle_count)}</td>
                    <td>
                        <a href="/vehicles.php?customer_id=${customer.id}" class="link-action">${plusIcon} Add vehicle</a>
                    </td>
                    <td>
                        ${canManageCustomers ? `<a href="?edit=${customer.id}" class="link-action">${editIcon} Edit</a>` : ''}
                    </td>
                </tr>
            `
        });
    </script>

<?php endif; ?>

</body>
</html>
