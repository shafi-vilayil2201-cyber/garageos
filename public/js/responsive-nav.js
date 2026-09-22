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
        // The default (collapsed unless explicitly opted out) is already
        // applied synchronously by the inline script at the top of
        // sidebar.php, before this deferred script even runs — doing it
        // there instead of here avoids a flash of the wide sidebar on
        // every page load. This block just keeps the toggle's own label
        // in sync with whatever state that script landed on, and wires
        // up persisting future clicks.
        updateCollapseToggleLabel();

        collapseToggle.addEventListener('click', () =>
        {
            app.classList.toggle('sidebar-collapsed');
            updateCollapseToggleLabel();
            document.getElementById('sidebar-tooltip')?.classList.remove('visible');

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

    // Collapsed-rail tooltip — only meaningful once .sidebar-collapsed
    // is active (labels are still in the DOM otherwise, just visible
    // inline, so a floating duplicate would be redundant/confusing).
    // Positioned via getBoundingClientRect() rather than a CSS ::after
    // because .sidebar's own overflow-y:auto forces overflow-x to auto
    // too, which would clip a tooltip positioned to poke out past a
    // 76px-wide rail — see the .sidebar-tooltip comment in app.css.
    const sidebarTooltip = document.getElementById('sidebar-tooltip');

    if (sidebarTooltip && app)
    {
        document.querySelectorAll('.nav-item').forEach(item =>
        {
            // A nav-group's own link (Finance) gets the richer flyout
            // below instead — a plain text tooltip on top of that would
            // just be two floating boxes fighting for the same corner.
            if (item.closest('.nav-group-header'))
            {
                return;
            }

            item.addEventListener('mouseenter', () =>
            {
                if (!app.classList.contains('sidebar-collapsed'))
                {
                    return;
                }

                const label = item.getAttribute('aria-label');

                if (!label)
                {
                    return;
                }

                const rect = item.getBoundingClientRect();

                sidebarTooltip.textContent = label;
                sidebarTooltip.style.left = `${rect.right + 12}px`;
                sidebarTooltip.style.top = `${rect.top + rect.height / 2}px`;
                sidebarTooltip.style.transform = 'translateY(-50%)';
                sidebarTooltip.classList.add('visible');
            });

            item.addEventListener('mouseleave', () =>
            {
                sidebarTooltip.classList.remove('visible');
            });
        });
    }

    // Collapsed-rail flyout for a nav group (currently just Finance) —
    // a single shared element (see #sidebar-flyout in sidebar.php),
    // populated by cloning the group's real link + real subitems
    // rather than keeping a second copy of that markup anywhere. Fixed
    // positioning for the same reason as #sidebar-tooltip: .sidebar's
    // own overflow-y:auto forces overflow-x to auto too, which would
    // clip anything positioned to poke out past the 76px rail.
    const sidebarFlyout = document.getElementById('sidebar-flyout');

    function closeSidebarFlyout()
    {
        if (sidebarFlyout)
        {
            sidebarFlyout.hidden = true;
        }
    }

    function openSidebarFlyout(groupLink, subitems)
    {
        if (!sidebarFlyout)
        {
            return;
        }

        sidebarFlyout.innerHTML = '';

        // Just the children — clicking the rail icon itself now navigates
        // straight to the group's own overview page like any other icon
        // (see the hover/click split below), so this flyout only needs
        // to offer the *other* destinations, not repeat that one too.
        const itemsClone = subitems.cloneNode(true);
        itemsClone.hidden = false;
        itemsClone.removeAttribute('data-nav-subitems');
        sidebarFlyout.appendChild(itemsClone);

        const rect = groupLink.getBoundingClientRect();

        sidebarFlyout.style.left = `${rect.right + 12}px`;
        sidebarFlyout.style.top = `${rect.top}px`;
        sidebarFlyout.hidden = false;

        // Clamp to the viewport after showing (so its real height is
        // known) — Finance sits near the bottom of the rail, and a
        // six-item list positioned top-aligned to it was running off
        // the bottom edge of the window uncorrected.
        const flyoutRect = sidebarFlyout.getBoundingClientRect();
        const margin = 12;
        let top = rect.top;

        if (top + flyoutRect.height > window.innerHeight - margin)
        {
            top = window.innerHeight - flyoutRect.height - margin;
        }

        if (top < margin)
        {
            top = margin;
        }

        sidebarFlyout.style.top = `${top}px`;
    }

    // A short grace period rather than closing the instant the pointer
    // leaves the icon — without it, the gap between the icon and the
    // flyout sitting 12px to its right (see openSidebarFlyout above) is
    // enough for a normal, slightly diagonal mouse movement to register
    // as "left" before it arrives, closing the flyout before anyone can
    // actually reach the links inside it.
    let flyoutCloseTimer = null;

    function scheduleSidebarFlyoutClose()
    {
        clearTimeout(flyoutCloseTimer);
        flyoutCloseTimer = setTimeout(closeSidebarFlyout, 200);
    }

    function cancelSidebarFlyoutClose()
    {
        clearTimeout(flyoutCloseTimer);
    }

    if (sidebarFlyout)
    {
        sidebarFlyout.addEventListener('mouseenter', cancelSidebarFlyoutClose);
        sidebarFlyout.addEventListener('mouseleave', scheduleSidebarFlyoutClose);

        document.addEventListener('keydown', event =>
        {
            if (event.key === 'Escape')
            {
                cancelSidebarFlyoutClose();
                closeSidebarFlyout();
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

        // On the icon-only rail, the toggle button above is hidden
        // (display:none — see app.css) and there's no room to show
        // subitems inline either way. Clicking the group's own icon
        // still means "open Finance" by default, same as every other
        // rail icon — that was the whole point of it being a real link
        // in the first place, and intercepting the click to show a menu
        // instead (an earlier version of this) meant the obvious, first
        // thing to try (just click it) didn't go anywhere. Hovering it
        // instead previews the other destinations in a flyout right
        // next to the icon (see sidebar-flyout below), without touching
        // the rail's own collapsed state or getting in the way of that
        // default click-to-open behaviour. Only kicks in while actually
        // collapsed — with the rail expanded the toggle button already
        // covers "see the group's children without leaving the page".
        const groupLink = toggle.closest('.nav-group-header')?.querySelector('.nav-item');

        if (groupLink && app)
        {
            groupLink.addEventListener('mouseenter', () =>
            {
                if (!app.classList.contains('sidebar-collapsed'))
                {
                    return;
                }

                cancelSidebarFlyoutClose();
                openSidebarFlyout(groupLink, subitems);
            });

            groupLink.addEventListener('mouseleave', scheduleSidebarFlyoutClose);
        }
    });

    // The sidebar (see .sidebar's overflow-y:auto in app.css) is its own
    // scroll container, separate from the page body. Every nav click is
    // a full page load in this multi-page app, so without this the
    // sidebar would snap back to the top on every navigation — forcing
    // a rescroll to reach anything below the fold (e.g. a lower item in
    // an expanded Finance group) after every single click. Restored
    // last, after the nav-group expand/collapse above has already
    // settled — expanding a group changes the sidebar's scrollable
    // height, and restoring against the wrong height would miss the
    // intended position.
    const sidebar = document.querySelector('.sidebar');
    const SIDEBAR_SCROLL_KEY = 'garageos-sidebar-scroll';

    if (sidebar)
    {
        try
        {
            const savedScroll = localStorage.getItem(SIDEBAR_SCROLL_KEY);

            if (savedScroll !== null)
            {
                sidebar.scrollTop = parseInt(savedScroll, 10);
            }
        } catch (error)
        {
            // Non-fatal — same as the preferences above.
        }

        let scrollSaveScheduled = false;

        sidebar.addEventListener('scroll', () =>
        {
            if (scrollSaveScheduled)
            {
                return;
            }

            scrollSaveScheduled = true;

            requestAnimationFrame(() =>
            {
                scrollSaveScheduled = false;

                try
                {
                    localStorage.setItem(SIDEBAR_SCROLL_KEY, String(sidebar.scrollTop));
                } catch (error)
                {
                    // Non-fatal.
                }
            });
        });
    }
});
