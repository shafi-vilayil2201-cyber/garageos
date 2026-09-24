<?php

// A one-shot, session-backed message read once by flash_render() on the
// page a redirect lands on, then discarded — the classic Post/Redirect/Get
// flash pattern. Rendered client-side as a toast (see public/js/toast.js)
// rather than a page banner, so it doesn't shift layout or need its own
// dismiss button, and never reappears on a plain page refresh.
//
// The session has to start here, at require-time, rather than lazily
// inside flash_render() — this file is required from Auth.php, the one
// thing every authenticated page loads before any output, but
// sidebar.php (where flash_render() actually runs) is required midway
// through the page's own HTML. A page that doesn't separately load
// Csrf.php (which starts the session itself) would already have sent
// output by then, and session_start() after that point doesn't just
// fail — it prints a "headers already sent" warning straight into the
// page, breaking the layout around it.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return sprintf(
        '<div id="flash-toast" data-message="%s" data-type="%s" hidden></div>',
        htmlspecialchars($flash['message'], ENT_QUOTES),
        htmlspecialchars($flash['type'], ENT_QUOTES)
    );
}
