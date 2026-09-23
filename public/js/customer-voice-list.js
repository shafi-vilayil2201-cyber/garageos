// Repeatable numbered complaint list ("Customer Voice") — same array-
// field-name + add/remove-row pattern as addLine() in purchase-new.js,
// except the row numbers are user-visible here, so (unlike that file's
// silent rowCount) every add/remove renumbers the visible list to stay
// contiguous (1, 2, 3...) rather than leaving gaps after a removal.
function initCustomerVoiceList(prefix)
{
    const list = document.getElementById(`${prefix}-customer-voice-list`);
    const addButton = document.getElementById(`${prefix}-customer-voice-add`);

    if (!list || !addButton)
    {
        return;
    }

    function renumber()
    {
        list.querySelectorAll('.customer-voice-row').forEach((row, index) =>
        {
            row.querySelector('.customer-voice-index').textContent = `${index + 1}.`;
        });
    }

    function addRow()
    {
        const row = document.createElement('div');

        row.className = 'customer-voice-row';
        row.innerHTML = `
            <span class="customer-voice-index"></span>
            <input type="text" name="customer_voice[]" placeholder="e.g. Engine noise on start" maxlength="300">
            <button type="button" class="link-action remove-customer-voice" aria-label="Remove">✕</button>
        `;

        list.appendChild(row);

        row.querySelector('.remove-customer-voice').addEventListener('click', () =>
        {
            row.remove();
            renumber();
        });

        renumber();
    }

    addButton.addEventListener('click', addRow);

    addRow();
}
