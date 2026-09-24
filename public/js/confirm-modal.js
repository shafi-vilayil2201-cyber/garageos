// A single reusable "are you sure?" modal for any form on the page —
// the app-wide replacement for native confirm(). Opt in per form with:
//   <form data-confirm="Delete job card JC-0005?"
//         data-confirm-title="Delete job card?"
//         data-confirm-label="Delete"
//         data-confirm-variant="danger">
// data-confirm-variant is "danger" (default — red icon/button, for
// destructive actions) or "neutral" (primary-colour icon/button, for
// confirmations that aren't destructive, like restoring something).
// Reuses window.openModal/closeModal from modal.js, so this must load
// after it. Loaded on every authenticated page from sidebar.php.
(function ()
{
    const ICON_DANGER = '<path d="M10.4 3.6 1.9 18.3A1.7 1.7 0 0 0 3.4 21h17.2a1.7 1.7 0 0 0 1.5-2.7L13.6 3.6a1.7 1.7 0 0 0-3.2 0z"/><line x1="12" y1="9.5" x2="12" y2="13.5"/><circle cx="12" cy="16.7" r="0.15" fill="currentColor" stroke-width="1.4"/>';
    const ICON_NEUTRAL = '<circle cx="12" cy="12" r="9"/><polyline points="8 12.5 11 15.5 16 9"/>';
    const ICON_CLOSE = '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>';

    function svg(size, body)
    {
        return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
    }

    let pendingForm = null;
    let backdrop = null;

    function ensureModal()
    {
        if (backdrop)
        {
            return backdrop;
        }

        backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.id = 'confirm-modal';
        backdrop.innerHTML =
            '<div class="modal modal-narrow">' +
                '<div class="modal-header">' +
                    '<div class="modal-header-title">' +
                        '<span class="icon-badge" id="confirm-modal-icon"></span>' +
                        '<span id="confirm-modal-title">Are you sure?</span>' +
                    '</div>' +
                    '<button type="button" class="modal-close" data-close-modal="confirm-modal" aria-label="Close">' + svg(18, ICON_CLOSE) + '</button>' +
                '</div>' +
                '<div class="modal-body">' +
                    '<p class="result-meta" id="confirm-modal-message"></p>' +
                    '<div class="actions" style="justify-content:flex-end; margin-top:18px;">' +
                        '<button type="button" class="button secondary" data-close-modal="confirm-modal">Cancel</button>' +
                        '<button type="button" class="button" id="confirm-modal-confirm-button">Confirm</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        document.body.appendChild(backdrop);

        backdrop.querySelector('#confirm-modal-confirm-button').addEventListener('click', () =>
        {
            const form = pendingForm;
            pendingForm = null;
            closeModal('confirm-modal');

            if (form)
            {
                form.submit();
            }
        });

        return backdrop;
    }

    document.addEventListener('submit', event =>
    {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm)
        {
            return;
        }

        event.preventDefault();

        const modal = ensureModal();
        const isDanger = form.dataset.confirmVariant !== 'neutral';

        modal.querySelector('#confirm-modal-title').textContent = form.dataset.confirmTitle || 'Are you sure?';
        modal.querySelector('#confirm-modal-message').textContent = form.dataset.confirm;

        const icon = modal.querySelector('#confirm-modal-icon');
        icon.className = 'icon-badge' + (isDanger ? ' danger' : '');
        icon.innerHTML = svg(16, isDanger ? ICON_DANGER : ICON_NEUTRAL);

        const confirmButton = modal.querySelector('#confirm-modal-confirm-button');
        confirmButton.textContent = form.dataset.confirmLabel || 'Confirm';
        confirmButton.className = 'button' + (isDanger ? ' danger' : '');

        pendingForm = form;
        openModal('confirm-modal');
    });
})();
