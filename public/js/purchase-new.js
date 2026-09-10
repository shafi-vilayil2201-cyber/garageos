const lineItemsBody = document.getElementById('line-items');
const addLineButton = document.getElementById('add-line-button');

let rowCount = 0;


function partOptions()
{
    return window.GARAGEOS_PARTS.map(part =>
        `<option value="${part.id}" data-cost="${part.cost_price}">${escapeHtml(part.name)} (${escapeHtml(part.sku)})</option>`
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
            <input type="number" name="quantity[]" min="0.01" step="0.01" value="1" required>
        </td>
        <td style="width:130px;">
            <input type="number" name="unit_cost[]" class="cost-input" min="0" step="0.01" value="0" required>
        </td>
        <td style="width:60px;">
            <button type="button" class="button secondary remove-line">✕</button>
        </td>
    `;

    lineItemsBody.appendChild(row);

    const select = row.querySelector('.part-select');
    const costInput = row.querySelector('.cost-input');

    select.addEventListener('change', () =>
    {
        const selectedOption = select.options[select.selectedIndex];
        const cost = selectedOption.getAttribute('data-cost');

        if (cost !== null)
        {
            costInput.value = cost;
        }
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
