<?php

// Expects $activeNav and $user (with $user['permissions']) to be set by
// the including page.
$activeNav = $activeNav ?? '';

function nav_class(string $key, string $activeNav): string
{
    return $key === $activeNav ? 'nav-item active' : 'nav-item';
}

function nav_link(string $href, string $key, string $activeNav, string $iconName, string $label): void
{
    printf(
        '<a href="%s" class="%s"><span class="nav-icon">%s</span><span>%s</span></a>',
        htmlspecialchars($href),
        nav_class($key, $activeNav),
        icon($iconName),
        htmlspecialchars($label)
    );
}

// A section label only renders when at least one link inside it will be
// visible — an empty "Operations" heading with nothing under it is clutter,
// not navigation, for a restricted role like Technician.
$showWorkspace = user_can($user, 'dashboard.view') || user_can($user, 'job_cards.view') || user_can($user, 'parts.view');
$showOperations = user_can($user, 'customers.view') || user_can($user, 'vehicles.view') || user_can($user, 'purchases.view') || user_can($user, 'suppliers.view');
$showInsights = user_can($user, 'reports.view') || user_can($user, 'users.manage') || user_can($user, 'settings.manage');
$showHR = user_can($user, 'attendance.manage') || user_can($user, 'payroll.manage');

?>
<aside class="sidebar">

    <div class="brand">
        <div class="brand-name"><?= htmlspecialchars($user['organization_name'] ?? 'GarageOS') ?></div>
        <div class="brand-subtitle">Workshop management</div>
    </div>

    <nav>

        <?php if ($showWorkspace): ?>
            <div class="nav-section">

                <div class="nav-label">
                    Workspace
                </div>

                <?php if (user_can($user, 'dashboard.view')): ?>
                    <?php nav_link('/dashboard.php', 'dashboard', $activeNav, 'dashboard', 'Dashboard'); ?>
                <?php endif; ?>

                <?php if (user_can($user, 'job_cards.view')): ?>
                    <?php nav_link('/job-cards.php', 'job_cards', $activeNav, 'job-card', 'Job Cards'); ?>
                    <?php nav_link('/appointments.php', 'appointments', $activeNav, 'calendar', 'Appointments'); ?>
                    <?php nav_link('/reminders.php', 'reminders', $activeNav, 'bell', 'Reminders'); ?>
                <?php endif; ?>

                <?php if (user_can($user, 'parts.view')): ?>
                    <?php nav_link('/parts.php', 'parts', $activeNav, 'box', 'Parts'); ?>
                <?php endif; ?>

            </div>
        <?php endif; ?>

        <?php if ($showOperations): ?>
            <div class="nav-section">

                <div class="nav-label">
                    Operations
                </div>

                <?php if (user_can($user, 'customers.view')): ?>
                    <?php nav_link('/customers.php', 'customers', $activeNav, 'person', 'Customers'); ?>
                <?php endif; ?>

                <?php if (user_can($user, 'vehicles.view')): ?>
                    <?php nav_link('/vehicles.php', 'vehicles', $activeNav, 'car', 'Vehicles'); ?>
                <?php endif; ?>

                <?php if (user_can($user, 'purchases.view')): ?>
                    <?php nav_link('/purchases.php', 'purchases', $activeNav, 'truck', 'Purchases'); ?>
                <?php endif; ?>

                <?php if (user_can($user, 'suppliers.view')): ?>
                    <?php nav_link('/suppliers.php', 'suppliers', $activeNav, 'warehouse', 'Suppliers'); ?>
                <?php endif; ?>

            </div>
        <?php endif; ?>

        <?php if ($showHR): ?>
            <div class="nav-section">

                <div class="nav-label">
                    HR
                </div>

                <?php if (user_can($user, 'attendance.manage')): ?>
                    <?php nav_link('/attendance.php', 'attendance', $activeNav, 'calendar', 'Attendance'); ?>
                <?php endif; ?>

                <?php if (user_can($user, 'payroll.manage')): ?>
                    <?php nav_link('/payroll.php', 'payroll', $activeNav, 'wallet', 'Payroll'); ?>
                <?php endif; ?>

            </div>
        <?php endif; ?>

        <?php if ($showInsights): ?>
            <div class="nav-section">

                <div class="nav-label">
                    Insights
                </div>

                <?php if (user_can($user, 'reports.view')): ?>
                    <?php nav_link('/reports.php', 'reports', $activeNav, 'chart', 'Reports'); ?>
                <?php endif; ?>

                <?php if (user_can($user, 'users.manage')): ?>
                    <?php nav_link('/users.php', 'users', $activeNav, 'team', 'Users'); ?>
                <?php endif; ?>

                <?php if (user_can($user, 'settings.manage')): ?>
                    <?php nav_link('/settings.php', 'settings', $activeNav, 'settings', 'Settings'); ?>
                <?php endif; ?>

            </div>
        <?php endif; ?>

    </nav>

    <div class="sidebar-footer">
        Powered by GarageOS
    </div>

</aside>
