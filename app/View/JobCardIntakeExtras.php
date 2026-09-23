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
// Just the accessories checklist form-field — its own function (rather
// than being inlined into job_card_intake_extras()) so the "Add/Edit
// accessories" modal on job-card.php can render the exact same block
// standalone, pre-checked with whatever's already recorded when this is
// an edit rather than a first-time add. No prefix needed:
// accessory_checklist_input()'s checkboxes have no ids to collide even
// if this ends up on the page twice.
function accessory_intake_block(array $checkedKeys = []): string
{
    ob_start();
    ?>
    <div class="form-field">
        <label>Accessories present</label>
        <?= accessory_checklist_input($checkedKeys) ?>
    </div>
    <?php
    return ob_get_clean();
}

// Just the clickable vehicle-condition map, parameterized by id prefix —
// same reasoning as accessory_intake_block(): reused standalone in the
// "Add/Edit vehicle condition" modal on job-card.php. $existingMarks
// seeds the interactive map when this is an edit — encoded as a data
// attribute damage-diagram.js reads on init, rather than a hidden input,
// since it's structured data for the JS, not a form field of its own.
function damage_intake_block(string $prefix, array $existingMarks = []): string
{
    $seedJson = htmlspecialchars(json_encode(array_map(fn(array $mark): array => [
        'part_key' => $mark['part_key'],
        'damage_type' => $mark['damage_type'],
        'x' => (float) $mark['x'],
        'y' => (float) $mark['y'],
    ], $existingMarks)), ENT_QUOTES);

    ob_start();
    ?>
    <div class="form-field">
        <label>Vehicle condition — select the exact part and damage type</label>
        <div class="damage-diagram-wrap" id="<?= $prefix ?>-damage-diagram-wrap" data-existing-marks='<?= $seedJson ?>'>
            <div class="damage-image-stage">
                <img src="/img/car-skeleton.png" class="damage-diagram-image" alt="Top view of vehicle">
                <svg
                    id="<?= $prefix ?>-damage-map"
                    class="damage-map"
                    viewBox="0 0 560 879"
                    preserveAspectRatio="none"
                    role="img"
                    aria-label="Select a vehicle part"
                ></svg>
                <div class="damage-mark-picker" id="<?= $prefix ?>-damage-picker" hidden>
                    <div class="damage-picker-title" id="<?= $prefix ?>-damage-picker-title"></div>
                    <div class="damage-picker-actions">
                        <button type="button" data-type="C">C <span>Cut</span></button>
                        <button type="button" data-type="D">D <span>Dent</span></button>
                        <button type="button" data-type="S">S <span>Scratch</span></button>
                    </div>
                </div>
            </div>
            <div class="damage-orientation"><span>FRONT</span><span>REAR</span></div>
            <div id="<?= $prefix ?>-damage-selection-list" class="damage-selection-list" aria-live="polite"></div>
        </div>
        <p class="result-meta">Click a highlighted part, choose C = Cut, D = Dent, or S = Scratch. The selected part and position are saved separately.</p>
        <input type="hidden" name="damage_image_data" id="<?= $prefix ?>-damage-image-data">
        <input type="hidden" name="damage_marks_json" id="<?= $prefix ?>-damage-marks-json">
    </div>
    <?php
    return ob_get_clean();
}

// Capture-only at intake — there is no flow here that pre-fills existing
// complaints/accessories/damage marks, since a fresh job card never has
// any yet. Split into wizard-step 2 (job details, always required-free)
// and wizard-step 3 (accessories + condition, explicitly skippable —
// see the note at the end of step 3). Both step divs render identically
// whether or not a wizard controller is initialized on the page: with no
// JS, they just sit in normal document flow one after another (see
// public/js/job-card-wizard.js and its opt-in initJobCardWizard() call).
function job_card_intake_extras(): string
{
    ob_start();
    ?>
    <div class="wizard-step" data-step="2">
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
    </div>

    <div class="wizard-step" data-step="3">
        <?= accessory_intake_block() ?>
        <?= damage_intake_block('jobcard') ?>
        <p class="result-meta wizard-skip-note"><?= icon('info', 14) ?> Both sections above are optional here — skip them now and add them later from the job card if you'd rather not hold up the customer.</p>
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

// A small "Recorded <date>" / "Updated <date>" meta line shown next to
// the accessories and vehicle-condition sections wherever they're
// displayed (job-card.php, job-card-print.php) — timestamp evidence of
// exactly when that section was first captured and, separately,
// whether it was corrected after the fact and when — so a customer
// can't be told a condition was noted at drop-off when the record shows
// otherwise, and a correction never overwrites the original evidence.
function recorded_at_label(?string $recordedAt, ?string $updatedAt = null): string
{
    if (!$recordedAt) {
        return '';
    }

    $html = '<span class="recorded-at-label">Recorded ' . htmlspecialchars(date('d M Y, h:i A', strtotime($recordedAt))) . '</span>';

    if ($updatedAt) {
        $html .= '<span class="recorded-at-label recorded-at-updated">Updated ' . htmlspecialchars(date('d M Y, h:i A', strtotime($updatedAt))) . '</span>';
    }

    return $html;
}
