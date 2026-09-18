// Whole-row navigation for list tables — a <tr class="clickable"
// data-href="..."> anywhere navigates on click, except a click that
// lands on a link/button inside the row (e.g. Edit, Add vehicle),
// which keeps its own destination. Delegated on the table itself so
// rows swapped in later by live-table-search.js work with no extra
// wiring.

document.addEventListener('DOMContentLoaded', () =>
{
    document.querySelectorAll('table.data-table').forEach(table =>
    {
        table.addEventListener('click', event =>
        {
            if (event.target.closest('a, button'))
            {
                return;
            }

            const row = event.target.closest('tr.clickable');

            if (row && row.dataset.href)
            {
                window.location.href = row.dataset.href;
            }
        });
    });
});
