// Anchored floating menu on the topbar avatar — distinct from
// modal.js's centered backdrop dialogs and from responsive-nav.js's
// persistent inline nav-group toggle, since this needs outside-click
// and Escape to close, which neither of those patterns does.

document.addEventListener('DOMContentLoaded', () =>
{
    const button = document.getElementById('user-menu-button');
    const dropdown = document.getElementById('user-menu-dropdown');

    if (!button || !dropdown)
    {
        return;
    }

    function closeDropdown()
    {
        dropdown.hidden = true;
        button.setAttribute('aria-expanded', 'false');
    }

    function openDropdown()
    {
        dropdown.hidden = false;
        button.setAttribute('aria-expanded', 'true');
    }

    button.addEventListener('click', event =>
    {
        event.stopPropagation();

        if (dropdown.hidden)
        {
            openDropdown();
        } else
        {
            closeDropdown();
        }
    });

    document.addEventListener('click', event =>
    {
        if (!dropdown.hidden && !dropdown.contains(event.target) && event.target !== button)
        {
            closeDropdown();
        }
    });

    document.addEventListener('keydown', event =>
    {
        if (event.key === 'Escape' && !dropdown.hidden)
        {
            closeDropdown();
        }
    });
});
