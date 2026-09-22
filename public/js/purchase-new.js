const lineItemsBody = document.getElementById('line-items');
const addLineButton = document.getElementById('add-line-button');

const ICON_X = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

let rowCount = 0;


function partOptions()
{
    return window.GARAGEOS_PARTS.map(part =>
        `<option value="${part.id}" data-cost="${part.cost_price}" data-unit="${escapeHtml(part.unit_short)}">${escapeHtml(part.name)} (${escapeHtml(part.sku)})</option>`
    ).join('');
}


function addLine()
{
    rowCount += 1;

    const row = document.createElement('tr');

    row.innerHTML = `
        <td>
            <select name="part_id[]" class="part-select" required>
                <option value="">Select part</option>
                ${partOptions()}
            </select>
        </td>
        <td style="width:100px;">
            <input type="number" name="quantity[]" class="quantity-input" min="0.01" step="0.01" value="1" required>
            <div class="result-meta unit-hint"></div>
        </td>
        <td style="width:130px;">
            <input type="number" name="unit_cost[]" class="cost-input" min="0" step="0.01" value="0" required>
        </td>
        <td style="width:60px;">
            <button type="button" class="button secondary sm remove-line" title="Remove line" aria-label="Remove line">${ICON_X}</button>
        </td>
    `;

    lineItemsBody.appendChild(row);

    const select = row.querySelector('.part-select');
    const costInput = row.querySelector('.cost-input');
    const unitHint = row.querySelector('.unit-hint');

    select.addEventListener('change', () =>
    {
        const selectedOption = select.options[select.selectedIndex];
        const cost = selectedOption.getAttribute('data-cost');
        const unit = selectedOption.getAttribute('data-unit');

        if (cost !== null)
        {
            costInput.value = cost;
        }

        unitHint.textContent = unit || '';
    });

    row.querySelector('.remove-line').addEventListener('click', () =>
    {
        row.remove();
    });
}


function escapeHtml(value)
{
    const div = document.createElement('div');

    div.textContent = value;

    return div.innerHTML;
}


addLineButton.addEventListener('click', addLine);

addLine();
