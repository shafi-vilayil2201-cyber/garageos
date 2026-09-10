const searchInput = document.getElementById('vehicle-search');
const searchButton = document.getElementById('vehicle-search-button');
const resultsContainer = document.getElementById('vehicle-results');
const notFound = document.getElementById('vehicle-not-found');
const form = document.getElementById('job-card-form');


async function searchVehicles()
{
    const query = searchInput.value.trim();

    form.style.display = 'none';
    notFound.style.display = 'none';

    if (!query)
    {
        resultsContainer.innerHTML = '';
        return;
    }

    resultsContainer.innerHTML = `
        <div class="empty-state">
            Searching...
        </div>
    `;

    try
    {
        const response = await fetch(
            `/api/vehicles/search.php?q=${encodeURIComponent(query)}`
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
    document.getElementById('selected_vehicle_id').value = vehicle.id;
    document.getElementById('selected_customer_id').value = vehicle.customer_id;

    document.getElementById('selected_vehicle_label').textContent =
        `${vehicle.registration_no} — ${vehicle.make} ${vehicle.model}`;

    document.getElementById('selected_customer_label').textContent =
        `${vehicle.customer_name} · ${vehicle.customer_phone}`;

    resultsContainer.innerHTML = '';
    notFound.style.display = 'none';
    form.style.display = 'block';
}


function escapeHtml(value)
{
    const div = document.createElement('div');

    div.textContent = value;

    return div.innerHTML;
}


searchButton.addEventListener('click', searchVehicles);

searchInput.addEventListener('keydown', event =>
{
    if (event.key === 'Enter')
    {
        event.preventDefault();
        searchVehicles();
    }
});
