// Turns the New Job Card form into a 3-step wizard (Vehicle → Job details →
// Condition) purely by toggling `hidden` on the [data-step] fragments that
// vehicle_intake_form() and job_card_intake_extras() already render — no
// step here is a separate page or AJAX call, it's all one form, so nothing
// entered on an earlier step is ever lost moving forward or back.
//
// Scoped globally (not by prefix) because only one wizard-enabled form is
// ever on a page at a time today; the id-prefixed nav buttons still make
// this a no-op everywhere else (initJobCardWizard() bails out if they're
// missing), same guard style as every other init*() in this app.
function initJobCardWizard(prefix)
{
    const progress = document.getElementById(`${prefix}-wizard-progress`);
    const backButton = document.getElementById(`${prefix}-wizard-back`);
    const nextButton = document.getElementById(`${prefix}-wizard-next`);
    const submitButton = document.getElementById(`${prefix}-wizard-submit`);

    if (!progress || !backButton || !nextButton || !submitButton)
    {
        return;
    }

    const stepElements = [...document.querySelectorAll('[data-step]')];
    const stepNumbers = [...new Set(stepElements.map(el => Number(el.dataset.step)))].sort((a, b) => a - b);

    let current = stepNumbers[0];
    let errorEl = null;

    function elementsForStep(step)
    {
        return stepElements.filter(el => Number(el.dataset.step) === step);
    }

    function clearError()
    {
        if (errorEl)
        {
            errorEl.remove();
            errorEl = null;
        }
    }

    function showError(message)
    {
        clearError();
        errorEl = document.createElement('div');
        errorEl.className = 'wizard-step-error';
        errorEl.textContent = message;
        nextButton.insertAdjacentElement('beforebegin', errorEl);
    }

    function showStep(step)
    {
        current = step;
        clearError();

        stepNumbers.forEach(number =>
        {
            elementsForStep(number).forEach(el => { el.hidden = number !== step; });
        });

        progress.querySelectorAll('.wizard-progress-step').forEach(el =>
        {
            const stepNumber = Number(el.dataset.step);
            el.classList.toggle('active', stepNumber === step);
            el.classList.toggle('done', stepNumber < step);
        });

        const isFirst = step === stepNumbers[0];
        const isLast = step === stepNumbers[stepNumbers.length - 1];

        backButton.hidden = isFirst;
        nextButton.hidden = isLast;
        submitButton.hidden = !isLast;
    }

    // Nothing here is a security boundary — the server validates fully on
    // submit regardless — this is only to stop step 2/3 filling out a job
    // detail attached to a vehicle that was never actually chosen.
    function vehicleIsChosen()
    {
        const modeInput = document.getElementById(`${prefix}-form_mode`);
        const vehicleIdInput = document.getElementById(`${prefix}-selected_vehicle_id`);

        if (modeInput && modeInput.value === 'new')
        {
            const requiredFieldIds = ['new_customer_name', 'new_customer_phone', 'new_registration_no', 'new_make', 'new_model'];

            return requiredFieldIds.every(id =>
            {
                const field = document.getElementById(`${prefix}-${id}`);
                return field && field.value.trim() !== '';
            });
        }

        return !!(vehicleIdInput && vehicleIdInput.value);
    }

    nextButton.addEventListener('click', () =>
    {
        if (current === stepNumbers[0] && !vehicleIsChosen())
        {
            showError('Select an existing vehicle above, or add a new customer & vehicle, before continuing.');
            return;
        }

        const index = stepNumbers.indexOf(current);

        if (index < stepNumbers.length - 1)
        {
            showStep(stepNumbers[index + 1]);
        }
    });

    backButton.addEventListener('click', () =>
    {
        const index = stepNumbers.indexOf(current);

        if (index > 0)
        {
            showStep(stepNumbers[index - 1]);
        }
    });

    showStep(stepNumbers[0]);
}
