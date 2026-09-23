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
