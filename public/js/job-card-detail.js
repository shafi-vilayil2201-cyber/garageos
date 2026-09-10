const partSearchInput = document.getElementById('part-search');
const partSearchButton = document.getElementById('part-search-button');
const partResults = document.getElementById('part-results');
const addPartForm = document.getElementById('add-part-form');


async function searchParts()
{
    const query = partSearchInput.value.trim();

    addPartForm.style.display = 'none';

    if (!query)
    {
        partResults.innerHTML = '';
        return;
    }

    partResults.innerHTML = `
        <div class="empty-state">
            Searching...
        </div>
    `;

    try
    {
        const response = await fetch(
            `/api/parts/search.php?q=${encodeURIComponent(query)}`
        );

        const data = await response.json();

        if (!response.ok)
        {
            throw new Error(data.error || 'Search failed');
        }

        if (data.parts.length === 0)
        {
            partResults.innerHTML = `
                <div class="empty-state">
                    No parts found.
                </div>
            `;
            return;
        }

        partResults.innerHTML = '';

        data.parts.forEach(part =>
        {
            const result = document.createElement('div');

            result.className = 'search-result';

            result.innerHTML = `
                <div>
                    <strong>${escapeHtml(part.name)}</strong>
                    <div class="result-meta">SKU: ${escapeHtml(part.sku)} · ₹${Number(part.selling_price).toFixed(2)}</div>
                </div>
                <div class="result-meta">
                    ${Number(part.stock_quantity)} in stock
                </div>
            `;

            result.addEventListener('click', () =>
            {
                selectPart(part);
            });

            partResults.appendChild(result);
        });

    } catch (error)
    {
        console.error(error);

        partResults.innerHTML = `
            <div class="empty-state">
                Unable to search right now.
            </div>
        `;
    }
}


function selectPart(part)
{
    document.getElementById('selected_part_id').value = part.id;

    document.getElementById('selected_part_label').textContent =
        `${part.name} — ₹${Number(part.selling_price).toFixed(2)}`;

    document.getElementById('selected_part_stock').textContent =
        `${Number(part.stock_quantity)} in stock`;

    document.getElementById('part_quantity').max = part.stock_quantity;

    partResults.innerHTML = '';
    addPartForm.style.display = 'flex';
}


function escapeHtml(value)
{
    const div = document.createElement('div');

    div.textContent = value;

    return div.innerHTML;
}


if (partSearchButton)
{
    partSearchButton.addEventListener('click', searchParts);

    partSearchInput.addEventListener('keydown', event =>
    {
        if (event.key === 'Enter')
        {
            event.preventDefault();
            searchParts();
        }
    });
}
