<?php

// Expects $topbarTitle and $user to be set by the including page.
$initial = strtoupper(substr($user['name'], 0, 1));

?>
<header class="topbar">

    <div class="topbar-left">
        <button type="button" class="hamburger-button" id="hamburger-button" aria-label="Open navigation">
            <?= icon('menu', 18) ?>
        </button>

        <div class="topbar-title">
            <?= htmlspecialchars($topbarTitle ?? '') ?>
        </div>
    </div>

    <div class="user-menu">

        <div class="user-menu-name">
            <?= htmlspecialchars($user['name']) ?>
        </div>

        <div class="avatar">
            <?= htmlspecialchars($initial) ?>
        </div>

        <a href="/logout.php" class="logout-link"><?= icon('logout', 15) ?> <span>Logout</span></a>

    </div>

</header>
