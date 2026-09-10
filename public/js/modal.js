function openModal(id)
{
    const backdrop = document.getElementById(id);

    if (!backdrop)
    {
        return;
    }

    backdrop.classList.add('open');

    const firstField = backdrop.querySelector('input, select, textarea');

    if (firstField)
    {
        firstField.focus();
    }
}


function closeModal(id)
{
    const backdrop = document.getElementById(id);

    if (backdrop)
    {
        backdrop.classList.remove('open');
    }
}


document.addEventListener('click', event =>
{
    if (event.target.classList.contains('modal-backdrop'))
    {
        event.target.classList.remove('open');
    }

    const closeTrigger = event.target.closest('[data-close-modal]');

    if (closeTrigger)
    {
        closeModal(closeTrigger.getAttribute('data-close-modal'));
    }
});


document.addEventListener('keydown', event =>
{
    if (event.key === 'Escape')
    {
        document.querySelectorAll('.modal-backdrop.open').forEach(backdrop =>
        {
            backdrop.classList.remove('open');
        });
    }
});
