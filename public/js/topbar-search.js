// Global customer search in the topbar — always visible on the left side,
// same debounce/AbortController/escapeHtml pattern as vehicle-intake.js's
// search-as-you-type box, but navigates to the customer's history page on
// click/Enter instead of filling form fields.

document.addEventListener('DOMContentLoaded', () =>
{
    const container = document.getElementById('topbar-search');
    const input = document.getElementById('topbar-search-input');
    const resultsContainer = document.getElementById('topbar-search-results');

    if (!container || !input || !resultsContainer)
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


    function hideResults()
    {
        resultsContainer.hidden = true;
        resultsContainer.innerHTML = '';
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
            hideResults();
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


    input.addEventListener('input', () =>
    {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(searchCustomers, 300);
    });

    input.addEventListener('focus', () =>
    {
        if (currentResults.length > 0)
        {
            resultsContainer.hidden = false;
        }
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
        } else if (event.key === 'Escape')
        {
            input.value = '';
            hideResults();
            input.blur();
        }
    });

    document.addEventListener('click', event =>
    {
        if (!resultsContainer.hidden && !container.contains(event.target))
        {
            resultsContainer.hidden = true;
        }
    });
});
