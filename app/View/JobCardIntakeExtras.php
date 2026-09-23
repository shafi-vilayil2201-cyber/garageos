<?php

require_once __DIR__ . '/Icons.php';
require_once __DIR__ . '/../Domain/Accessory.php';
require_once __DIR__ . '/../Domain/VehicleDamage.php';

// The job-card-specific fields spliced into vehicle_intake_form()'s
// $extraFieldsHtml slot — used identically by public/job-card-new.php
// (both its search-a-vehicle path and its "shortcut from a customer
// page" path, which share the same $extraFields string) and
// public/job-cards.php's "New Job Card" modal. Hardcodes the "jobcard"
// id prefix rather than taking it as a parameter, matching the existing
// convention: every current caller of vehicle_intake_form() already
// passes 'jobcard' as its own $prefix, so the ids here already line up
// with both call sites without needing to thread a parameter through.
//
// Capture-only — there is no flow anywhere yet that reopens a job card
// to edit its complaints/accessories/damage marks after creation, so
// none of this pre-fills existing data (there isn't any to pre-fill).
function job_card_intake_extras(): string
{
    ob_start();
    ?>
    <div class="form-grid single">
        <div class="form-field">
            <label for="jobcard-odometer_in">Odometer reading (km)</label>
            <input type="number" id="jobcard-odometer_in" name="odometer_in" min="0">
        </div>
        <div class="form-field">
            <label for="jobcard-promised_at">Promised delivery (optional)</label>
            <input type="datetime-local" id="jobcard-promised_at" name="promised_at">
        </div>
        <div class="form-field">
            <label for="jobcard-advance_amount">Advance amount (₹, optional)</label>
            <input type="number" id="jobcard-advance_amount" name="advance_amount" step="0.01" min="0">
        </div>
    </div>

    <div class="form-field">
        <label>Customer voice — complaints, in the customer's own words</label>
        <div id="jobcard-customer-voice-list"></div>
        <button type="button" class="button secondary sm" id="jobcard-customer-voice-add"><?= icon('plus', 14) ?> Add complaint</button>
    </div>

    <div class="form-field">
        <label>Accessories present</label>
        <?= accessory_checklist_input() ?>
    </div>

    <div class="form-field">
        <label>Vehicle condition — select the exact part and damage type</label>
        <div class="damage-diagram-wrap" id="jobcard-damage-diagram-wrap">
            <div class="damage-image-stage">
                <img src="/img/car-skeleton.png" class="damage-diagram-image" alt="Top view of vehicle">
                <svg
                    id="jobcard-damage-map"
                    class="damage-map"
                    viewBox="0 0 560 879"
                    preserveAspectRatio="none"
                    role="img"
                    aria-label="Select a vehicle part"
                ></svg>
                <div class="damage-mark-picker" id="jobcard-damage-picker" hidden>
                    <div class="damage-picker-title" id="jobcard-damage-picker-title"></div>
                    <div class="damage-picker-actions">
                        <button type="button" data-type="C">C <span>Cut</span></button>
                        <button type="button" data-type="D">D <span>Dent</span></button>
                        <button type="button" data-type="S">S <span>Scratch</span></button>
                    </div>
                </div>
            </div>
            <div class="damage-orientation"><span>REAR</span><span>FRONT</span></div>
            <div id="jobcard-damage-selection-list" class="damage-selection-list" aria-live="polite"></div>
        </div>
        <p class="result-meta">Click a highlighted part, choose C = Cut, D = Dent, or S = Scratch. The selected part and position are saved separately.</p>
        <input type="hidden" name="damage_image_data" id="jobcard-damage-image-data">
        <input type="hidden" name="damage_marks_json" id="jobcard-damage-marks-json">
    </div>
    <?php
    return ob_get_clean();
}

function damage_image_display(?string $damageImageUrl): string
{
    if (!$damageImageUrl) {
        return '';
    }

    return '<img class="damage-image-readonly" src="' . htmlspecialchars($damageImageUrl) . '" alt="Vehicle condition at intake">';
}
