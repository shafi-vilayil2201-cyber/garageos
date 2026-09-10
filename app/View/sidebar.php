<?php

// Expects $activeNav and $user (with $user['permissions']) to be set by
// the including page.
$activeNav = $activeNav ?? '';

function nav_class(string $key, string $activeNav): string
{
    return $key === $activeNav ? 'nav-item active' : 'nav-item';
}

?>
<aside class="sidebar">

    <div class="brand">
        <div class="brand-name"><?= htmlspecialchars($user['organization_name'] ?? 'GarageOS') ?></div>
        <div class="brand-subtitle">Workshop management</div>
    </div>

    <nav>

        <div class="nav-section">

            <div class="nav-label">
                Workspace
            </div>

            <?php if (user_can($user, 'dashboard.view')): ?>
                <a href="/dashboard.php" class="<?= nav_class('dashboard', $activeNav) ?>">
                    <span>▦</span>
                    <span>Dashboard</span>
                </a>
            <?php endif; ?>

            <?php if (user_can($user, 'job_cards.view')): ?>
                <a href="/job-cards.php" class="<?= nav_class('job_cards', $activeNav) ?>">
                    <span>▣</span>
                    <span>Job Cards</span>
                </a>

                <a href="/appointments.php" class="<?= nav_class('appointments', $activeNav) ?>">
                    <span>▤</span>
                    <span>Appointments</span>
                </a>

                <a href="/reminders.php" class="<?= nav_class('reminders', $activeNav) ?>">
                    <span>◔</span>
                    <span>Reminders</span>
                </a>
            <?php endif; ?>

            <?php if (user_can($user, 'parts.view')): ?>
                <a href="/parts.php" class="<?= nav_class('parts', $activeNav) ?>">
                    <span>◫</span>
                    <span>Parts</span>
                </a>
            <?php endif; ?>

        </div>

        <div class="nav-section">

            <div class="nav-label">
                Operations
            </div>

            <?php if (user_can($user, 'customers.view')): ?>
                <a href="/customers.php" class="<?= nav_class('customers', $activeNav) ?>">
                    <span>☺</span>
                    <span>Customers</span>
                </a>
            <?php endif; ?>

            <?php if (user_can($user, 'vehicles.view')): ?>
                <a href="/vehicles.php" class="<?= nav_class('vehicles', $activeNav) ?>">
                    <span>▭</span>
                    <span>Vehicles</span>
                </a>
            <?php endif; ?>

            <?php if (user_can($user, 'purchases.view')): ?>
                <a href="/purchases.php" class="<?= nav_class('purchases', $activeNav) ?>">
                    <span>↗</span>
                    <span>Purchases</span>
                </a>
            <?php endif; ?>

            <?php if (user_can($user, 'suppliers.view')): ?>
                <a href="/suppliers.php" class="<?= nav_class('suppliers', $activeNav) ?>">
                    <span>♧</span>
                    <span>Suppliers</span>
                </a>
            <?php endif; ?>

        </div>

        <div class="nav-section">

            <div class="nav-label">
                Insights
            </div>

            <?php if (user_can($user, 'reports.view')): ?>
                <a href="/reports.php" class="<?= nav_class('reports', $activeNav) ?>">
                    <span>▥</span>
                    <span>Reports</span>
                </a>
            <?php endif; ?>

            <?php if (user_can($user, 'users.manage')): ?>
                <a href="/users.php" class="<?= nav_class('users', $activeNav) ?>">
                    <span>♟</span>
                    <span>Users</span>
                </a>
            <?php endif; ?>

            <?php if (user_can($user, 'settings.manage')): ?>
                <a href="/settings.php" class="<?= nav_class('settings', $activeNav) ?>">
                    <span>⚙</span>
                    <span>Settings</span>
                </a>
            <?php endif; ?>

        </div>

    </nav>

    <div class="sidebar-footer">
        Powered by GarageOS
    </div>

</aside>
