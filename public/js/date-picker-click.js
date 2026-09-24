// Native date/time/datetime-local inputs only open their picker when
// you hit the tiny calendar icon on the right — every other native
// input responds to a click anywhere in the field. Makes the whole
// field clickable the same way, via the standard showPicker() API.
// Loaded once from sidebar.php, so this applies everywhere in the app
// without each page wiring it up itself.
document.addEventListener('click', event => {
    const input = event.target.closest('input[type="date"], input[type="time"], input[type="datetime-local"]');

    if (!input || input.disabled || input.readOnly || typeof input.showPicker !== 'function') {
        return;
    }

    try {
        input.showPicker();
    } catch (error) {
        // Browsers can refuse showPicker() in some states (e.g. called
        // too soon after a previous one) — the native icon still works
        // as a fallback, so just skip the enhancement silently.
    }
});
