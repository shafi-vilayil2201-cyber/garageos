<?php

require_once __DIR__ . '/../Security/Csrf.php';
require_once __DIR__ . '/Icons.php';

// The "find this vehicle, or add a new customer + vehicle" block shared by
// job card intake and appointment booking. Both paths — search and add-new
// — are visible from the first render; neither is hidden behind a failed
// search. $prefix namespaces every element id so the same form can appear
// more than once on a page (e.g. a dashboard with both a job-card modal
// and an appointment modal). $extraFieldsHtml is the caller's own
// job-card-specific or appointment-specific fields, inserted after the
// vehicle is chosen/entered and before the submit button.
function vehicle_intake_form(
    string $prefix,
    string $formAction,
    string $extraFieldsHtml,
    string $submitIcon,
    string $submitLabel
): string {

    ob_start();

    ?>
    <div class="search-row">
        <input
            type="search"
            id="<?= $prefix ?>-vehicle-search"
            placeholder="Registration no. or customer phone..."
            autocomplete="off"
        >
    </div>

    <div class="intake-add-new-row">
        <button type="button" class="button secondary" id="<?= $prefix ?>-add-new-button"><?= icon('plus', 16) ?> Add new customer &amp; vehicle</button>
    </div>

    <div id="<?= $prefix ?>-vehicle-results"></div>

    <div id="<?= $prefix ?>-vehicle-not-found" style="display:none; margin-bottom:16px;">
        <p class="page-description">No match found — use "Add new customer &amp; vehicle" above.</p>
    </div>

    <form method="POST" action="<?= htmlspecialchars($formAction) ?>" id="<?= $prefix ?>-form" style="display:none;">

        <?= csrf_field() ?>

        <input type="hidden" name="mode" id="<?= $prefix ?>-form_mode" value="existing">
        <input type="hidden" name="vehicle_id" id="<?= $prefix ?>-selected_vehicle_id">
        <input type="hidden" name="customer_id" id="<?= $prefix ?>-selected_customer_id">

        <div class="selected-summary" id="<?= $prefix ?>-selected-summary">
            <span class="icon-badge"><?= icon('car', 16) ?></span>
            <div>
                <strong id="<?= $prefix ?>-selected_vehicle_label"></strong>
                <div class="result-meta" id="<?= $prefix ?>-selected_customer_label"></div>
            </div>
        </div>

        <div id="<?= $prefix ?>-new-customer-vehicle" style="display:none; margin-bottom:16px;">

            <div class="form-field" style="margin-bottom:14px;">
                <label for="<?= $prefix ?>-vehicle-model-search">Find a car <span class="result-meta">— fills Make/Model below (e.g. "Swift", "Hyundai Creta")</span></label>
                <input
                    type="search"
                    id="<?= $prefix ?>-vehicle-model-search"
                    placeholder="Start typing a make or model..."
                    autocomplete="off"
                >
                <div id="<?= $prefix ?>-vehicle-model-results"></div>
            </div>

            <div class="form-grid">
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_customer_name">Customer name</label>
                    <input type="text" id="<?= $prefix ?>-new_customer_name" name="new_customer_name">
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_customer_phone">Customer phone</label>
                    <input type="tel" id="<?= $prefix ?>-new_customer_phone" name="new_customer_phone">
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_customer_email">Customer email (optional)</label>
                    <input type="email" id="<?= $prefix ?>-new_customer_email" name="new_customer_email">
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_customer_address">Customer address (optional)</label>
                    <input type="text" id="<?= $prefix ?>-new_customer_address" name="new_customer_address">
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_registration_no">Registration number</label>
                    <input type="text" id="<?= $prefix ?>-new_registration_no" name="new_registration_no" placeholder="KL-14-AB-1234">
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_fuel_type">Fuel type</label>
                    <select id="<?= $prefix ?>-new_fuel_type" name="new_fuel_type">
                        <option value="petrol">Petrol</option>
                        <option value="diesel">Diesel</option>
                        <option value="ev">EV</option>
                        <option value="hybrid">Hybrid</option>
                        <option value="cng">CNG</option>
                    </select>
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_make">Make</label>
                    <input type="text" id="<?= $prefix ?>-new_make" name="new_make" placeholder="Maruti Suzuki">
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_model">Model</label>
                    <input type="text" id="<?= $prefix ?>-new_model" name="new_model" placeholder="Swift">
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_year">Year (optional)</label>
                    <input type="number" id="<?= $prefix ?>-new_year" name="new_year" min="1980" max="2100">
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_color">Color (optional)</label>
                    <input type="text" id="<?= $prefix ?>-new_color" name="new_color" placeholder="White">
                </div>
                <div class="form-field">
                    <label for="<?= $prefix ?>-new_vin">VIN (optional)</label>
                    <input type="text" id="<?= $prefix ?>-new_vin" name="new_vin">
                </div>
            </div>
        </div>

        <?= $extraFieldsHtml ?>

        <div class="form-actions">
            <button type="submit" class="button"><?= icon($submitIcon, 16) ?> <?= htmlspecialchars($submitLabel) ?></button>
        </div>

    </form>
    <?php

    return ob_get_clean();
}
