<?php

// Expects $topbarTitle and $user to be set by the including page.
$initial = strtoupper(substr($user['name'], 0, 1));

?>
<header class="topbar">

    <div class="topbar-title">
        <?= htmlspecialchars($topbarTitle ?? '') ?>
    </div>

    <div class="user-menu">

        <div>
            <?= htmlspecialchars($user['name']) ?>
        </div>

        <div class="avatar">
            <?= htmlspecialchars($initial) ?>
        </div>

        <a href="/logout.php" class="logout-link">Logout</a>

    </div>

</header>
