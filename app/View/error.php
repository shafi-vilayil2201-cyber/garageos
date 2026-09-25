<?php
// Error page template — rendered by ErrorHandler.php's exception handler.
// Receives $e (the Throwable) and $isDev (bool) from the calling scope.
// Completely standalone: no sidebar, no Auth, no database queries — if
// the error happened *because* one of those failed, depending on them
// here would just throw another exception and produce a blank 500.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GarageOS — Something went wrong</title>
    <style>
        :root {
            --bg: #f4f3f1;
            --surface: #ffffff;
            --border: #e5e3dd;
            --text: #1c1a17;
            --muted: #6b6660;
            --primary: #c2660a;
            --primary-dark: #9c5108;
            --primary-soft: #f7e6d3;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
            --radius: 12px;
            --shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.45;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }

        .error-container {
            max-width: 520px;
            width: 100%;
            text-align: center;
        }

        .error-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--danger-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .error-icon svg {
            width: 28px;
            height: 28px;
            stroke: var(--danger);
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .error-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 36px 32px;
        }

        .error-title {
            font-size: 20px;
            font-weight: 600;
            margin: 0 0 8px;
        }

        .error-message {
            color: var(--muted);
            font-size: 14.5px;
            margin: 0 0 24px;
        }

        .error-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .error-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: background 0.15s;
        }

        .error-button-primary {
            background: var(--primary);
            color: #fff;
        }

        .error-button-primary:hover {
            background: var(--primary-dark);
        }

        .error-button-secondary {
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .error-button-secondary:hover {
            background: var(--border);
        }

        .error-button svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .error-details {
            margin-top: 20px;
            text-align: left;
            background: #1c1917;
            color: #e7e5e4;
            border-radius: 8px;
            padding: 16px 18px;
            font-size: 12.5px;
            font-family: "SF Mono", "Cascadia Code", Consolas, monospace;
            overflow-x: auto;
            line-height: 1.6;
        }

        .error-details-label {
            color: var(--danger);
            font-weight: 600;
        }

        .error-details-class {
            color: #fbbf24;
        }

        .error-details-file {
            color: #6b6660;
        }

        .error-trace {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #333;
            white-space: pre-wrap;
            word-break: break-all;
            color: #a8a29e;
            max-height: 300px;
            overflow-y: auto;
        }

        .error-footer {
            margin-top: 20px;
            font-size: 12.5px;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-card">
            <div class="error-icon">
                <svg viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            </div>

            <h1 class="error-title">Something went wrong</h1>
            <p class="error-message">We hit an unexpected error processing your request. This has been logged and we'll look into it. Please try again — if the problem persists, contact support.</p>

            <div class="error-actions">
                <a href="javascript:location.reload()" class="error-button error-button-primary">
                    <svg viewBox="0 0 24 24"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 105.64-10.36L3 10"/></svg>
                    Try again
                </a>
                <a href="/dashboard.php" class="error-button error-button-secondary">
                    <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    Go to Dashboard
                </a>
            </div>

            <?php if ($isDev): ?>
                <div class="error-details">
                    <div>
                        <span class="error-details-label">Error:</span>
                        <span class="error-details-class"><?= htmlspecialchars(get_class($e)) ?></span>
                    </div>
                    <div style="margin-top:4px;">
                        <?= htmlspecialchars($e->getMessage()) ?>
                    </div>
                    <div class="error-details-file" style="margin-top:8px;">
                        <?= htmlspecialchars($e->getFile()) ?>:<?= $e->getLine() ?>
                    </div>
                    <div class="error-trace"><?= htmlspecialchars($e->getTraceAsString()) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <p class="error-footer">
            Powered by GarageOS
            <?php if ($isDev): ?>
                · APP_ENV = development
            <?php endif; ?>
        </p>
    </div>
</body>
</html>
