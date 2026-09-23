<?php

// The fixed accessories checklist shown at vehicle intake — what's
// physically in the car at drop-off, ticked for accountability. Fixed
// and curated rather than free text, same reasoning as PART_UNITS in
// PartUnit.php: a consistent key here is what job_card_accessories
// actually stores, so the checklist can't silently drift into typo'd
// variants across job cards.
const ACCESSORIES = [
    'jack_handle' => 'Jack & Handle',
    'seat_covers' => 'Seat Covers',
    'antenna' => 'Antenna',
    'cd_magazine' => 'CD Magazine',
    'alloys' => 'Alloys',
    'mud_flap' => 'Mud Flap',
    'lighter' => 'Lighter',
    'autocop' => 'Autocop',
    'wheel_cap' => 'Wheel Cap',
    'tool_kit' => 'Tool Kit',
    'remote' => 'Remote',
    'stereo_remote_panel' => 'Stereo Remote Panel',
    'all_valuables_removed' => 'All Valuables Removed',
    'spare_wheel' => 'Spare Wheel',
    'battery' => 'Battery',
    'notes' => 'Notes',
    'aux_cable' => 'AUX Cable',
    'cd' => 'CD',
    'charger' => 'Charger',
    'dashboard_idol' => 'Dashboard Idol',
    'mats' => 'Mats',
    'pendrive' => 'Pendrive',
    'perfume' => 'Perfume',
    'reflector' => 'Reflector',
];

function accessory_is_valid(string $key): bool
{
    return isset(ACCESSORIES[$key]);
}

// Shared by intake (job-card-new.php) and the "Add/Edit accessories"
// flow on job-card.php — the same function handles both a first-time
// save and a later correction, always replacing whatever's there rather
// than appending to it (a technician fixing a mis-tick isn't adding a
// second record). accessories_recorded_at is set only the first time;
// every save after that stamps accessories_updated_at instead, so the
// two dates displayed together show both when this was originally
// captured and whether — and when — it was corrected since.
function save_accessories(PDO $pdo, int $jobCardId, array $keys): void
{
    $validKeys = array_values(array_unique(array_filter($keys, 'accessory_is_valid')));

    $pdo->prepare("DELETE FROM job_card_accessories WHERE job_card_id = :job_card_id")
        ->execute(['job_card_id' => $jobCardId]);

    $statement = $pdo->prepare("
        INSERT INTO job_card_accessories (job_card_id, accessory_key)
        VALUES (:job_card_id, :accessory_key)
    ");

    foreach ($validKeys as $key) {
        $statement->execute(['job_card_id' => $jobCardId, 'accessory_key' => $key]);
    }

    $statement = $pdo->prepare("SELECT accessories_recorded_at FROM job_cards WHERE id = :id");
    $statement->execute(['id' => $jobCardId]);
    $column = $statement->fetchColumn() ? 'accessories_updated_at' : 'accessories_recorded_at';

    $pdo->prepare("
        UPDATE job_cards
        SET {$column} = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute(['id' => $jobCardId]);
}

// Renders the fixed checklist as a 2-column grid of checkboxes, each
// named accessories[] — used at intake, where $checkedKeys is empty
// (nothing ticked yet).
function accessory_checklist_input(array $checkedKeys = []): string
{
    $html = '<div class="checkbox-grid">';

    foreach (ACCESSORIES as $key => $label) {
        $checked = in_array($key, $checkedKeys, true) ? 'checked' : '';
        $html .= '<label class="checkbox-grid-item">'
            . '<input type="checkbox" name="accessories[]" value="' . htmlspecialchars($key) . '" ' . $checked . '>'
            . htmlspecialchars($label)
            . '</label>';
    }

    $html .= '</div>';

    return $html;
}

// Renders the same fixed checklist read-only (job card detail + print),
// showing every item with a checked/unchecked glyph rather than only the
// ticked ones — matches the "show everything, tick what applies" look
// of the printed reference form.
function accessory_checklist_display(array $checkedKeys): string
{
    $html = '<div class="checkbox-grid checkbox-grid-readonly">';

    foreach (ACCESSORIES as $key => $label) {
        $isChecked = in_array($key, $checkedKeys, true);
        $glyph = $isChecked ? '☑' : '☐';
        $class = $isChecked ? 'checkbox-grid-item checked' : 'checkbox-grid-item';
        $html .= '<div class="' . $class . '">' . $glyph . ' ' . htmlspecialchars($label) . '</div>';
    }

    $html .= '</div>';

    return $html;
}
