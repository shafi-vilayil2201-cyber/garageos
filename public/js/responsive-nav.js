// Layout chrome for the tablet collapse toggle and the mobile nav
// drawer — kept separate from modal.js since this controls page layout,
// not dialogs, even though the open/close interactions are similar.

// Opens the native date/month picker on the input right after a
// compact calendar-icon trigger button (Attendance, Payroll headers —
// see .header-date-trigger in app.css). showPicker() needs a real user
// gesture and isn't supported everywhere, so this always falls back to
// a plain focus(), which still opens the native picker on most mobile
// browsers even without showPicker().
function openDatePicker(button)
{
    const input = button.nextElementSibling;

    if (!input)
    {
        return;
    }

    try
    {
        if (input.showPicker)
        {
            input.showPicker();
            return;
        }
    } catch (error)
    {
        // Fall through to focus() below.
    }

    input.focus();
}


// Wrapped in DOMContentLoaded: this script is loaded from sidebar.php,
// which renders before topbar.php (and its hamburger button) in every
// page — without waiting, getElementById('hamburger-button') would run
// before that element exists in the DOM yet.
document.addEventListener('DOMContentLoaded', () =>
{
    const app = document.querySelector('.app');
    const hamburgerButton = document.getElementById('hamburger-button');
    const navBackdrop = document.getElementById('nav-backdrop');
    const collapseToggle = document.getElementById('sidebar-collapse-toggle');

    const SIDEBAR_COLLAPSED_KEY = 'garageos-sidebar-collapsed';


    function closeMobileNav()
    {
        if (app)
        {
            app.classList.remove('mobile-nav-open');
        }
    }


    function updateCollapseToggleLabel()
    {
        if (!app || !collapseToggle)
        {
            return;
        }

        const collapsed = app.classList.contains('sidebar-collapsed');

        collapseToggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }


    if (hamburgerButton && app)
    {
        hamburgerButton.addEventListener('click', () =>
        {
            app.classList.add('mobile-nav-open');
        });
    }

    if (navBackdrop)
    {
        navBackdrop.addEventListener('click', closeMobileNav);
    }

    document.addEventListener('keydown', event =>
    {
        if (event.key === 'Escape')
        {
            closeMobileNav();
        }
    });

    // The tablet icon-rail collapse concept doesn't apply on a phone —
    // it uses the off-canvas drawer instead. .force-mobile is set (see
    // sidebar.php) whenever the true device width is a phone's, even if
    // the layout viewport is spoofed wide (iOS Safari's per-site
    // "Request Desktop Website" toggle) and would otherwise still match
    // the tablet @media tier. Stripping/skipping a stale collapsed
    // preference here avoids the drawer opening as an icon-only rail.
    const isForceMobile = document.documentElement.classList.contains('force-mobile');

    if (isForceMobile && app)
    {
        app.classList.remove('sidebar-collapsed');
    }

    if (collapseToggle && app && !isForceMobile)
    {
        // Persisted across page loads — this is a multi-page app, not an
        // SPA, so in-memory JS state alone wouldn't survive navigation.
        // Wrapped in try/catch: some browsers (private-mode Safari, storage
        // disabled by policy) throw on access rather than returning null.
        try
        {
            if (localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1')
            {
                app.classList.add('sidebar-collapsed');
            }
        } catch (error)
        {
            // Collapse preference just won't persist — not worth failing over.
        }

        updateCollapseToggleLabel();

        collapseToggle.addEventListener('click', () =>
        {
            app.classList.toggle('sidebar-collapsed');
            updateCollapseToggleLabel();

            try
            {
                localStorage.setItem(
                    SIDEBAR_COLLAPSED_KEY,
                    app.classList.contains('sidebar-collapsed') ? '1' : '0'
                );
            } catch (error)
            {
                // Same as above — non-fatal if storage isn't available.
            }
        });
    }

    // Finance's expandable sub-nav (currently the only nested nav
    // group). The active group is already server-rendered expanded —
    // see $financeGroupActive in sidebar.php — so a stale "collapsed"
    // localStorage preference from browsing elsewhere must never hide
    // the links for the page actually in view.
    document.querySelectorAll('[data-nav-group]').forEach(toggle =>
    {
        const key = toggle.dataset.navGroup;
        const isActive = toggle.dataset.navGroupActive === 'true';
        const subitems = document.querySelector(`[data-nav-subitems="${key}"]`);

        if (!subitems)
        {
            return;
        }

        const storageKey = `garageos-nav-group-${key}`;

        function setExpanded(expanded)
        {
            subitems.hidden = !expanded;
            toggle.classList.toggle('expanded', expanded);
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }

        if (!isActive)
        {
            try
            {
                if (localStorage.getItem(storageKey) === '1')
                {
                    setExpanded(true);
                }
            } catch (error)
            {
                // Non-fatal — same as the sidebar-collapsed preference above.
            }
        }

        toggle.addEventListener('click', () =>
        {
            const expanded = subitems.hidden;
            setExpanded(expanded);

            try
            {
                localStorage.setItem(storageKey, expanded ? '1' : '0');
            } catch (error)
            {
                // Non-fatal.
            }
        });
    });
});
