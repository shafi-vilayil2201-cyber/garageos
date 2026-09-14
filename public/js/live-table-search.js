// Shared live-search behaviour for a paginated list table: debounced
// input, in-flight request cancellation (so a slow early response can
// never overwrite a newer one), and swapping the table body between the
// server-rendered page and live search results. Used by customers.php,
// vehicles.php and parts.php — each just describes its own endpoint and
// row markup.
function initLiveTableSearch(options)
{
    const {
        inputId,
        tbodyId,
        paginationId,
        endpoint,
        resultsKey,
        colspan,
        emptyMessage,
        renderRow
    } = options;

    const input = document.getElementById(inputId);
    const tbody = document.getElementById(tbodyId);
    const pagination = document.getElementById(paginationId);

    if (!input || !tbody)
    {
        return;
    }

    const originalRowsHtml = tbody.innerHTML;

    let abortController = null;
    let debounceTimer = null;


    function escapeHtml(value)
    {
        const div = document.createElement('div');

        div.textContent = value ?? '';

        return div.innerHTML;
    }


    async function search()
    {
        const query = input.value.trim();

        if (!query)
        {
            tbody.innerHTML = originalRowsHtml;

            if (pagination)
            {
                pagination.hidden = false;
            }

            return;
        }

        if (pagination)
        {
            pagination.hidden = true;
        }

        if (abortController)
        {
            abortController.abort();
        }

        abortController = new AbortController();

        try
        {
            const response = await fetch(
                `${endpoint}?q=${encodeURIComponent(query)}`,
                { signal: abortController.signal }
            );

            const data = await response.json();

            if (!response.ok)
            {
                throw new Error(data.error || 'Search failed');
            }

            const items = data[resultsKey] || [];

            if (items.length === 0)
            {
                tbody.innerHTML = `<tr><td colspan="${colspan}" class="empty-state">${escapeHtml(emptyMessage)}</td></tr>`;
                return;
            }

            tbody.innerHTML = items.map(item => renderRow(item, escapeHtml)).join('');

        } catch (error)
        {
            if (error.name === 'AbortError')
            {
                return;
            }

            console.error(error);

            tbody.innerHTML = `<tr><td colspan="${colspan}" class="empty-state">Unable to search right now.</td></tr>`;
        }
    }


    input.addEventListener('input', () =>
    {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(search, 300);
    });
}
