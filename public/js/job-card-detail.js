// ─── Part search (existing) ───

const partSearchInput = document.getElementById('part-search');
const partResults = document.getElementById('part-results');
const addPartForm = document.getElementById('add-part-form');

let partSearchAbortController = null;
let partSearchDebounceTimer = null;


async function searchParts()
{
    const query = partSearchInput.value.trim();

    addPartForm.style.display = 'none';

    if (!query)
    {
        partResults.innerHTML = '';
        return;
    }

    if (partSearchAbortController)
    {
        partSearchAbortController.abort();
    }

    partSearchAbortController = new AbortController();

    if (!partResults.children.length)
    {
        partResults.innerHTML = `
            <div class="empty-state">
                Searching...
            </div>
        `;
    }

    try
    {
        const response = await fetch(
            `/api/parts/search.php?q=${encodeURIComponent(query)}`,
            { signal: partSearchAbortController.signal }
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
        if (error.name === 'AbortError')
        {
            return;
        }

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

    // Labour charge/technician are per-addition, not per-part — reset them
    // so a value left over from adding a different part doesn't carry over.
    document.getElementById('part_labour_charge').value = 0;
    document.getElementById('part_technician_id').value = '';

    partResults.innerHTML = '';
    addPartForm.style.display = 'flex';
}


function escapeHtml(value)
{
    const div = document.createElement('div');

    div.textContent = value;

    return div.innerHTML;
}


if (partSearchInput)
{
    partSearchInput.addEventListener('input', () =>
    {
        clearTimeout(partSearchDebounceTimer);
        partSearchDebounceTimer = setTimeout(searchParts, 300);
    });

    partSearchInput.addEventListener('keydown', event =>
    {
        if (event.key === 'Enter')
        {
            event.preventDefault();
        }
    });
}


// ─── Service search (autocomplete with custom entry) ───

const serviceSearchInput = document.getElementById('service-search');
const serviceResults = document.getElementById('service-results');
const selectedServiceId = document.getElementById('selected_service_id');
const selectedCustomName = document.getElementById('selected_custom_name');
const serviceCustomPrice = document.getElementById('service_custom_price');
const addServiceForm = document.getElementById('add-service-form');

let serviceSearchAbortController = null;
let serviceSearchDebounceTimer = null;
let serviceIsPreset = false;


async function searchServices()
{
    const query = serviceSearchInput.value.trim();

    // If user edits the text after selecting a preset, clear the preset state
    if (serviceIsPreset)
    {
        serviceIsPreset = false;
        selectedServiceId.value = '';
        selectedCustomName.value = '';
    }

    if (!query)
    {
        serviceResults.innerHTML = '';
        serviceResults.classList.remove('open');
        selectedCustomName.value = '';
        return;
    }

    // Set custom name as user types (will be overridden if they pick a suggestion)
    selectedCustomName.value = query;

    if (serviceSearchAbortController)
    {
        serviceSearchAbortController.abort();
    }

    serviceSearchAbortController = new AbortController();

    try
    {
        const response = await fetch(
            `/api/services/search.php?q=${encodeURIComponent(query)}`,
            { signal: serviceSearchAbortController.signal }
        );

        const data = await response.json();

        if (!response.ok)
        {
            throw new Error(data.error || 'Search failed');
        }

        serviceResults.innerHTML = '';

        if (data.services.length === 0)
        {
            serviceResults.classList.remove('open');
            return;
        }

        data.services.forEach(service =>
        {
            const item = document.createElement('div');
            item.className = 'autocomplete-item';

            item.innerHTML = `
                <div class="autocomplete-item-name">${escapeHtml(service.name)}</div>
                <div class="autocomplete-item-price">₹${Number(service.standard_price).toFixed(2)}</div>
            `;

            item.addEventListener('click', () =>
            {
                selectService(service);
            });

            serviceResults.appendChild(item);
        });

        serviceResults.classList.add('open');

    } catch (error)
    {
        if (error.name === 'AbortError')
        {
            return;
        }

        console.error(error);
        serviceResults.innerHTML = '';
        serviceResults.classList.remove('open');
    }
}


function selectService(service)
{
    serviceIsPreset = true;

    serviceSearchInput.value = service.name;
    selectedServiceId.value = service.id;
    selectedCustomName.value = '';

    serviceCustomPrice.value = Number(service.standard_price).toFixed(2);

    serviceResults.innerHTML = '';
    serviceResults.classList.remove('open');

    serviceCustomPrice.focus();
}


if (serviceSearchInput)
{
    serviceSearchInput.addEventListener('input', () =>
    {
        clearTimeout(serviceSearchDebounceTimer);
        serviceSearchDebounceTimer = setTimeout(searchServices, 250);
    });

    serviceSearchInput.addEventListener('keydown', event =>
    {
        if (event.key === 'Enter')
        {
            event.preventDefault();
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', event =>
    {
        if (!event.target.closest('#service-results') && event.target !== serviceSearchInput)
        {
            serviceResults.innerHTML = '';
            serviceResults.classList.remove('open');
        }
    });

    // Before form submit, ensure custom_name is set for custom entries
    if (addServiceForm)
    {
        addServiceForm.addEventListener('submit', () =>
        {
            if (!selectedServiceId.value && serviceSearchInput.value.trim())
            {
                selectedCustomName.value = serviceSearchInput.value.trim();
            }
        });
    }
}
