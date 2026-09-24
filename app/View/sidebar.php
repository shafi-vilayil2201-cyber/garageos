<?php

require_once __DIR__ . '/../Support/Flash.php';

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
        '<a href="%s" class="%s" aria-label="%s"><span class="nav-icon">%s</span><span>%s</span></a>',
        htmlspecialchars($href),
        nav_class($key, $activeNav),
        htmlspecialchars($label),
        icon($iconName, 20),
        htmlspecialchars($label)
    );
}

// A section label only renders when at least one link inside it will be
// visible — an empty "Operations" heading with nothing under it is clutter,
// not navigation, for a restricted role like Technician.
$showWorkspace = user_can($user, 'dashboard.view') || user_can($user, 'job_cards.view') || user_can($user, 'parts.view');
$showOperations = user_can($user, 'customers.view') || user_can($user, 'vehicles.view') || user_can($user, 'suppliers.view');
$showHR = user_can($user, 'attendance.manage') || user_can($user, 'users.manage');

// Purchases and Payroll live here now, alongside the new Finance pages
// — the group shows if any one child would be visible, exactly like
// the $showX booleans above; each child link below is still
// independently permission-gated on its own.
$showFinance = user_can($user, 'finance.view') || user_can($user, 'payroll.manage') || user_can($user, 'purchases.view');
$financeChildKeys = ['finance_revenue', 'finance_expenses', 'finance_dues', 'finance_pnl', 'payroll', 'purchases'];
$financeGroupActive = $activeNav === 'finance_overview' || in_array($activeNav, $financeChildKeys, true);

?>
<script>
// iOS Safari has a per-site "Request Desktop Website" toggle (separate
// from Chrome's own per-site setting) that makes it ignore the
// viewport meta tag and report a spoofed, desktop-sized layout
// viewport — so a phone can match the tablet @media tier instead of
// mobile. screen.width is the true physical device width and isn't
// affected by that spoofing. Runs synchronously, before the sidebar
// markup below is parsed, so the correct tier applies from first
// paint instead of flashing the wrong one.
(function () {
    var isForceMobile = false;

    try {
        if (window.screen && window.screen.width <= 767) {
            document.documentElement.classList.add('force-mobile');
            isForceMobile = true;
        }
    } catch (error) {
        // Not worth failing over — worst case, the normal @media tiers apply.
    }

    // The icon-only rail (see the tablet/desktop media query in app.css)
    // is collapsed by default — opt out via the sidebar's own toggle,
    // not in. Applied synchronously and this early, same as force-mobile
    // above, so the sidebar never flashes wide-then-narrow on first
    // paint; responsive-nav.js (deferred to DOMContentLoaded) only wires
    // up the toggle button itself and persists future clicks. .app
    // already exists as a DOM node here even though this script runs
    // before </aside> — the including page opens <div class="app"> and
    // then requires this file, so the browser's streaming HTML parser
    // has already created that element by the time this executes.
    try {
        var app = document.querySelector('.app');
        var preference = localStorage.getItem('garageos-sidebar-collapsed');

        if (app && !isForceMobile && preference !== '0') {
            app.classList.add('sidebar-collapsed');
        }
    } catch (error) {
        // Storage unavailable — falls back to expanded, same as before.
    }
})();
</script>

<div class="nav-backdrop" id="nav-backdrop"></div>

<aside class="sidebar">

    <div class="brand">
        <?php if (!empty($user['organization_logo_url'])): ?>
            <img class="brand-logo" src="<?= htmlspecialchars($user['organization_logo_url']) ?>" alt="<?= htmlspecialchars($user['organization_name'] ?? 'GarageOS') ?>">
        <?php else: ?>
            <div class="brand-logo brand-logo-fallback">
                <?= icon('warehouse', 20) ?>
            </div>
        <?php endif; ?>
        <div class="brand-text">
            <div class="brand-name"><?= htmlspecialchars($user['organization_name'] ?? 'GarageOS') ?></div>
            <div class="brand-subtitle">Workshop management</div>
        </div>
    </div>

    <button type="button" class="sidebar-collapse-toggle" id="sidebar-collapse-toggle" aria-label="Collapse sidebar">
        <span class="nav-icon"><?= icon('chevron-left', 18) ?></span>
        <span>Collapse</span>
    </button>

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

                <?php if (user_can($user, 'users.manage')): ?>
                    <?php nav_link('/employees.php', 'employees', $activeNav, 'team', 'Employees'); ?>
                <?php endif; ?>

                <?php if (user_can($user, 'attendance.manage')): ?>
                    <?php nav_link('/attendance.php', 'attendance', $activeNav, 'calendar', 'Attendance'); ?>
                <?php endif; ?>

            </div>
        <?php endif; ?>

        <?php if ($showFinance): ?>
            <div class="nav-section">

                <div class="nav-label">
                    Finance
                </div>

                <div class="nav-group">
                    <div class="nav-group-header">
                        <a href="/finance.php" class="<?= nav_class('finance_overview', $activeNav) ?>" aria-label="Finance">
                            <span class="nav-icon"><?= icon('wallet', 20) ?></span>
                            <span>Finance</span>
                        </a>
                        <button type="button" class="nav-group-toggle<?= $financeGroupActive ? ' expanded' : '' ?>"
                                data-nav-group="finance" data-nav-group-active="<?= $financeGroupActive ? 'true' : 'false' ?>"
                                aria-expanded="<?= $financeGroupActive ? 'true' : 'false' ?>" aria-label="Toggle Finance links">
                            <?= icon('chevron-right', 15) ?>
                        </button>
                    </div>

                    <div class="nav-subitems" data-nav-subitems="finance"<?= $financeGroupActive ? '' : ' hidden' ?>>
                        <?php if (user_can($user, 'finance.view')): ?>
                            <?php nav_link('/finance-revenue.php', 'finance_revenue', $activeNav, 'trending-up', 'Revenue'); ?>
                            <?php nav_link('/finance-expenses.php', 'finance_expenses', $activeNav, 'receipt', 'Expenses'); ?>
                            <?php nav_link('/finance-dues.php', 'finance_dues', $activeNav, 'alert-triangle', 'Debt'); ?>
                            <?php nav_link('/finance-pnl.php', 'finance_pnl', $activeNav, 'chart', 'P&L'); ?>
                        <?php endif; ?>

                        <?php if (user_can($user, 'payroll.manage')): ?>
                            <?php nav_link('/payroll.php', 'payroll', $activeNav, 'team', 'Payroll'); ?>
                        <?php endif; ?>

                        <?php if (user_can($user, 'purchases.view')): ?>
                            <?php nav_link('/purchases.php', 'purchases', $activeNav, 'truck', 'Purchases'); ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        <?php endif; ?>

    </nav>

    <div class="sidebar-footer">
        Powered by GarageOS
    </div>

</aside>

<div class="sidebar-tooltip" id="sidebar-tooltip"></div>

<div class="sidebar-flyout" id="sidebar-flyout" hidden></div>

<?= flash_render() ?>

<script src="/js/responsive-nav.js"></script>
<script src="/js/modal.js"></script>
<script src="/js/toast.js"></script>
<script src="/js/confirm-modal.js"></script>
<script src="/js/date-picker-click.js"></script>
