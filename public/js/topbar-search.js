// Global customer search in the topbar — same debounce/AbortController/
// escapeHtml pattern as vehicle-intake.js's search-as-you-type box, but
// collapsed to an icon by default (like user-menu.js's anchored dropdown)
// and navigates to the customer's history page on click/Enter instead of
// filling form fields.

document.addEventListener('DOMContentLoaded', () =>
{
    const container = document.getElementById('topbar-search');
    const trigger = document.getElementById('topbar-search-trigger');
    const input = document.getElementById('topbar-search-input');
    const resultsContainer = document.getElementById('topbar-search-results');

    if (!container || !trigger || !input || !resultsContainer)
    {
        return;
    }

    let currentResults = [];


    function escapeHtml(value)
    {
        const div = document.createElement('div');

        div.textContent = value;

        return div.innerHTML;
    }


    function openSearch()
    {
        container.classList.add('open');
        trigger.setAttribute('aria-expanded', 'true');
        input.focus();
    }


    function closeSearch()
    {
        container.classList.remove('open');
        trigger.setAttribute('aria-expanded', 'false');
        resultsContainer.hidden = true;
        resultsContainer.innerHTML = '';
        input.value = '';
        currentResults = [];
    }


    function goToCustomer(customer)
    {
        window.location.href = `/customer.php?id=${customer.id}`;
    }


    let searchAbortController = null;
    let debounceTimer = null;

    async function searchCustomers()
    {
        const query = input.value.trim();

        if (!query)
        {
            resultsContainer.hidden = true;
            resultsContainer.innerHTML = '';
            currentResults = [];
            return;
        }

        if (searchAbortController)
        {
            searchAbortController.abort();
        }

        searchAbortController = new AbortController();

        resultsContainer.hidden = false;

        if (!resultsContainer.children.length)
        {
            resultsContainer.innerHTML = `
                <div class="empty-state">
                    Searching...
                </div>
            `;
        }

        try
        {
            const response = await fetch(
                `/api/customers/search.php?q=${encodeURIComponent(query)}`,
                { signal: searchAbortController.signal }
            );

            const data = await response.json();

            if (!response.ok)
            {
                throw new Error(data.error || 'Search failed');
            }

            currentResults = data.customers;

            if (currentResults.length === 0)
            {
                resultsContainer.innerHTML = `
                    <div class="empty-state">
                        No customers found.
                    </div>
                `;
                return;
            }

            resultsContainer.innerHTML = '';

            currentResults.forEach(customer =>
            {
                const result = document.createElement('div');

                result.className = 'search-result';

                result.innerHTML = `
                    <div>
                        <strong>${escapeHtml(customer.name)}</strong>
                        <div class="result-meta">
                            ${escapeHtml(customer.code)} · ${customer.vehicle_count} vehicle${customer.vehicle_count === 1 ? '' : 's'}
                        </div>
                    </div>
                    <div class="result-meta">
                        ${escapeHtml(customer.phone)}
                    </div>
                `;

                result.addEventListener('click', () =>
                {
                    goToCustomer(customer);
                });

                resultsContainer.appendChild(result);
            });

        } catch (error)
        {
            if (error.name === 'AbortError')
            {
                return;
            }

            console.error(error);
            resultsContainer.innerHTML = `
                <div class="empty-state">
                    Unable to search right now.
                </div>
            `;
        }
    }


    trigger.addEventListener('click', event =>
    {
        event.stopPropagation();

        if (container.classList.contains('open'))
        {
            closeSearch();
        } else
        {
            openSearch();
        }
    });

    input.addEventListener('input', () =>
    {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(searchCustomers, 300);
    });

    input.addEventListener('keydown', event =>
    {
        if (event.key === 'Enter')
        {
            event.preventDefault();

            if (currentResults.length === 1)
            {
                goToCustomer(currentResults[0]);
            }
        }
    });

    document.addEventListener('click', event =>
    {
        if (container.classList.contains('open') && !container.contains(event.target))
        {
            closeSearch();
        }
    });

    document.addEventListener('keydown', event =>
    {
        if (event.key === 'Escape' && container.classList.contains('open'))
        {
            closeSearch();
        }
    });
});
