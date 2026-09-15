function initVehicleIntake(prefix)
{
    const el = id => document.getElementById(`${prefix}-${id}`);

    const searchInput = el('vehicle-search');
    const resultsContainer = el('vehicle-results');
    const notFound = el('vehicle-not-found');
    const addNewButton = el('add-new-button');
    const form = el('form');
    const formMode = el('form_mode');
    const selectedSummary = el('selected-summary');
    const newCustomerVehicle = el('new-customer-vehicle');
    const modelSearchInput = el('vehicle-model-search');
    const modelResultsContainer = el('vehicle-model-results');

    if (!form)
    {
        return;
    }

    const newFieldIds = [
        'new_customer_name',
        'new_customer_phone',
        'new_registration_no',
        'new_make',
        'new_model'
    ];


    function escapeHtml(value)
    {
        const div = document.createElement('div');

        div.textContent = value;

        return div.innerHTML;
    }


    function setNewFieldsRequired(required)
    {
        newFieldIds.forEach(id =>
        {
            el(id).required = required;
        });
    }


    let searchAbortController = null;
    let debounceTimer = null;

    async function searchVehicles()
    {
        const query = searchInput.value.trim();

        notFound.style.display = 'none';

        if (!query)
        {
            resultsContainer.innerHTML = '';
            return;
        }

        if (searchAbortController)
        {
            searchAbortController.abort();
        }

        searchAbortController = new AbortController();

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
                `/api/vehicles/search.php?q=${encodeURIComponent(query)}`,
                { signal: searchAbortController.signal }
            );

            const data = await response.json();

            if (!response.ok)
            {
                throw new Error(data.error || 'Search failed');
            }

            if (data.vehicles.length === 0)
            {
                resultsContainer.innerHTML = '';
                notFound.style.display = 'block';
                return;
            }

            resultsContainer.innerHTML = '';

            data.vehicles.forEach(vehicle =>
            {
                const result = document.createElement('div');

                result.className = 'search-result';

                result.innerHTML = `
                    <div>
                        <strong>${escapeHtml(vehicle.registration_no)}</strong>
                        <div class="result-meta">
                            ${escapeHtml(vehicle.make)} ${escapeHtml(vehicle.model)} · ${escapeHtml(vehicle.customer_name)}
                        </div>
                    </div>
                    <div class="result-meta">
                        ${escapeHtml(vehicle.customer_phone)}
                    </div>
                `;

                result.addEventListener('click', () =>
                {
                    selectVehicle(vehicle);
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


    function selectVehicle(vehicle)
    {
        formMode.value = 'existing';

        el('selected_vehicle_id').value = vehicle.id;
        el('selected_customer_id').value = vehicle.customer_id;

        el('selected_vehicle_label').textContent =
            `${vehicle.registration_no} — ${vehicle.make} ${vehicle.model}`;

        el('selected_customer_label').textContent =
            `${vehicle.customer_name} · ${vehicle.customer_phone}`;

        resultsContainer.innerHTML = '';
        notFound.style.display = 'none';

        selectedSummary.style.display = 'flex';
        newCustomerVehicle.style.display = 'none';
        setNewFieldsRequired(false);

        form.style.display = 'block';
    }


    function startNewCustomerVehicle()
    {
        formMode.value = 'new';

        el('selected_vehicle_id').value = '';
        el('selected_customer_id').value = '';

        const query = searchInput.value.trim();

        if (query)
        {
            if (/[a-zA-Z]/.test(query))
            {
                el('new_registration_no').value = query.toUpperCase();
            } else
            {
                el('new_customer_phone').value = query;
            }
        }

        resultsContainer.innerHTML = '';
        notFound.style.display = 'none';

        selectedSummary.style.display = 'none';
        newCustomerVehicle.style.display = 'block';
        setNewFieldsRequired(true);

        form.style.display = 'block';

        el('new_customer_name').focus();
    }


    searchInput.addEventListener('input', () =>
    {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(searchVehicles, 300);
    });

    searchInput.addEventListener('keydown', event =>
    {
        if (event.key === 'Enter')
        {
            event.preventDefault();
        }
    });

    addNewButton.addEventListener('click', startNewCustomerVehicle);


    // "Find a car" — suggests Make/Model from the org's own vehicle
    // history plus a built-in common-makes list (see
    // api/vehicle-models/search.php), so free-typing Make/Model below
    // stays the fallback rather than the only option.
    if (modelSearchInput && modelResultsContainer)
    {
        let modelSearchAbortController = null;
        let modelDebounceTimer = null;

        async function searchVehicleModels()
        {
            const query = modelSearchInput.value.trim();

            if (!query)
            {
                modelResultsContainer.innerHTML = '';
                return;
            }

            if (modelSearchAbortController)
            {
                modelSearchAbortController.abort();
            }

            modelSearchAbortController = new AbortController();

            try
            {
                const response = await fetch(
                    `/api/vehicle-models/search.php?q=${encodeURIComponent(query)}`,
                    { signal: modelSearchAbortController.signal }
                );

                const data = await response.json();

                if (!response.ok)
                {
                    throw new Error(data.error || 'Search failed');
                }

                modelResultsContainer.innerHTML = '';

                data.models.forEach(model =>
                {
                    const result = document.createElement('div');

                    result.className = 'search-result';
                    result.innerHTML = `<div><strong>${escapeHtml(model.make)} ${escapeHtml(model.model)}</strong></div>`;

                    result.addEventListener('click', () =>
                    {
                        el('new_make').value = model.make;
                        el('new_model').value = model.model;

                        modelSearchInput.value = '';
                        modelResultsContainer.innerHTML = '';
                    });

                    modelResultsContainer.appendChild(result);
                });

            } catch (error)
            {
                if (error.name === 'AbortError')
                {
                    return;
                }

                console.error(error);
            }
        }

        modelSearchInput.addEventListener('input', () =>
        {
            clearTimeout(modelDebounceTimer);
            modelDebounceTimer = setTimeout(searchVehicleModels, 300);
        });

        modelSearchInput.addEventListener('keydown', event =>
        {
            if (event.key === 'Enter')
            {
                event.preventDefault();
            }
        });
    }
}
