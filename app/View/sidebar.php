<?php

// Expects $activeNav to be set by the including page.
$activeNav = $activeNav ?? '';

function nav_class(string $key, string $activeNav): string
{
    return $key === $activeNav ? 'nav-item active' : 'nav-item';
}

?>
<aside class="sidebar">

    <div class="brand">
        <div class="brand-name">GarageOS</div>
        <div class="brand-subtitle">Workshop management</div>
    </div>

    <nav>

        <div class="nav-section">

            <div class="nav-label">
                Workspace
            </div>

            <a href="/dashboard.php" class="<?= nav_class('dashboard', $activeNav) ?>">
                <span>▦</span>
                <span>Dashboard</span>
            </a>

            <a href="/job-cards.php" class="<?= nav_class('job_cards', $activeNav) ?>">
                <span>▣</span>
                <span>Job Cards</span>
            </a>

            <a href="/parts.php" class="<?= nav_class('parts', $activeNav) ?>">
                <span>◫</span>
                <span>Parts</span>
            </a>

        </div>

        <div class="nav-section">

            <div class="nav-label">
                Operations
            </div>

            <a href="/customers.php" class="<?= nav_class('customers', $activeNav) ?>">
                <span>☺</span>
                <span>Customers</span>
            </a>

            <a href="/vehicles.php" class="<?= nav_class('vehicles', $activeNav) ?>">
                <span>▭</span>
                <span>Vehicles</span>
            </a>

            <a href="/purchases.php" class="<?= nav_class('purchases', $activeNav) ?>">
                <span>↗</span>
                <span>Purchases</span>
            </a>

            <a href="/suppliers.php" class="<?= nav_class('suppliers', $activeNav) ?>">
                <span>♧</span>
                <span>Suppliers</span>
            </a>

        </div>

        <div class="nav-section">

            <div class="nav-label">
                Insights
            </div>

            <a href="/reports.php" class="<?= nav_class('reports', $activeNav) ?>">
                <span>▥</span>
                <span>Reports</span>
            </a>

            <a href="/settings.php" class="<?= nav_class('settings', $activeNav) ?>">
                <span>⚙</span>
                <span>Settings</span>
            </a>

        </div>

    </nav>

</aside>
