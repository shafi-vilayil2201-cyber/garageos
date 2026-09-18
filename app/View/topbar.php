<?php

// Expects $user to be set by the including page. $topbarTitle is no
// longer shown here — each page's own <h1 class="page-title"> in the
// body is the one place the page name is displayed now.
$initial = strtoupper(substr($user['name'], 0, 1));

?>
<header class="topbar">

    <div class="topbar-left">
        <button type="button" class="hamburger-button" id="hamburger-button" aria-label="Open navigation">
            <?= icon('menu', 18) ?>
        </button>
    </div>

    <?php if (user_can($user, 'customers.view')): ?>
        <div class="topbar-search" id="topbar-search">
            <button type="button" class="topbar-search-trigger" id="topbar-search-trigger" aria-label="Search customers" aria-expanded="false">
                <?= icon('search', 18) ?>
            </button>
            <input type="search" class="topbar-search-input" id="topbar-search-input" placeholder="Search customers..." autocomplete="off">
            <div class="topbar-search-results" id="topbar-search-results" hidden></div>
        </div>
    <?php endif; ?>

    <div class="user-menu">

        <div class="user-menu-name">
            <?= htmlspecialchars($user['name']) ?>
        </div>

        <div class="user-menu-trigger" id="user-menu-trigger">
            <button type="button" class="avatar" id="user-menu-button"
                    aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
                <?= htmlspecialchars($initial) ?>
            </button>

            <div class="user-menu-dropdown" id="user-menu-dropdown" hidden>
                <a href="/profile.php" class="user-menu-item"><?= icon('person', 15) ?> Profile</a>
                <?php if (user_can($user, 'settings.manage')): ?>
                    <a href="/settings.php" class="user-menu-item"><?= icon('settings', 15) ?> Settings</a>
                <?php endif; ?>
                <?php if (user_can($user, 'reports.view')): ?>
                    <a href="/reports.php" class="user-menu-item"><?= icon('chart', 15) ?> Reports</a>
                <?php endif; ?>
                <?php if (user_can($user, 'audit.view')): ?>
                    <a href="/audit-logs.php" class="user-menu-item"><?= icon('clipboard-list', 15) ?> Audit Logs</a>
                <?php endif; ?>
                <div class="user-menu-divider"></div>
                <a href="/logout.php" class="user-menu-item user-menu-item-danger"><?= icon('logout', 15) ?> Logout</a>
            </div>
        </div>

    </div>

</header>

<script src="/js/user-menu.js"></script>
<?php if (user_can($user, 'customers.view')): ?>
    <script src="/js/topbar-search.js"></script>
<?php endif; ?>
