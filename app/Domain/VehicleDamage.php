<?php

// Stable keys for the vehicle-condition diagram. The JavaScript hit-map uses
// the same keys; labels here are the server-side source of truth for reports.
const VEHICLE_DAMAGE_PARTS = [
    'front_bumper' => ['label' => 'Front bumper', 'group' => 'Front'],
    'bonnet' => ['label' => 'Bonnet', 'group' => 'Front'],
    'front_left_fender' => ['label' => 'Front left fender', 'group' => 'Front'],
    'front_right_fender' => ['label' => 'Front right fender', 'group' => 'Front'],
    'front_left_wheel' => ['label' => 'Front left wheel', 'group' => 'Front'],
    'front_right_wheel' => ['label' => 'Front right wheel', 'group' => 'Front'],
    'front_left_mirror' => ['label' => 'Front left mirror', 'group' => 'Front'],
    'front_right_mirror' => ['label' => 'Front right mirror', 'group' => 'Front'],
    'windshield' => ['label' => 'Windshield', 'group' => 'Front'],
    'front_left_door' => ['label' => 'Front left door', 'group' => 'Side'],
    'front_right_door' => ['label' => 'Front right door', 'group' => 'Side'],
    'rear_left_door' => ['label' => 'Rear left door', 'group' => 'Side'],
    'rear_right_door' => ['label' => 'Rear right door', 'group' => 'Side'],
    'rear_left_quarter_panel' => ['label' => 'Rear left quarter panel', 'group' => 'Rear'],
    'rear_right_quarter_panel' => ['label' => 'Rear right quarter panel', 'group' => 'Rear'],
    'rear_windshield' => ['label' => 'Rear windshield', 'group' => 'Rear'],
    'trunk' => ['label' => 'Trunk', 'group' => 'Rear'],
    'rear_bumper' => ['label' => 'Rear bumper', 'group' => 'Rear'],
    'rear_left_wheel' => ['label' => 'Rear left wheel', 'group' => 'Rear'],
    'rear_right_wheel' => ['label' => 'Rear right wheel', 'group' => 'Rear'],
    'roof' => ['label' => 'Roof', 'group' => 'Roof'],
];

const VEHICLE_DAMAGE_TYPES = [
    'C' => 'Cut',
    'D' => 'Dent',
    'S' => 'Scratch',
];

function vehicle_damage_part_is_valid(string $key): bool
{
    return isset(VEHICLE_DAMAGE_PARTS[$key]);
}

function vehicle_damage_type_is_valid(string $type): bool
{
    return isset(VEHICLE_DAMAGE_TYPES[$type]);
}

function damage_marks_display(array $marks): string
{
    if (!$marks) {
        return '';
    }

    $html = '<div class="damage-mark-list">';

    foreach ($marks as $mark) {
        $partKey = (string) ($mark['part_key'] ?? '');
        $type = (string) ($mark['damage_type'] ?? '');

        if (!vehicle_damage_part_is_valid($partKey) || !vehicle_damage_type_is_valid($type)) {
            continue;
        }

        $html .= '<div class="damage-mark-item">'
            . '<strong>' . htmlspecialchars(VEHICLE_DAMAGE_PARTS[$partKey]['label']) . '</strong>'
            . '<span>' . htmlspecialchars(VEHICLE_DAMAGE_TYPES[$type]) . '</span>'
            . '</div>';
    }

    return $html . '</div>';
}
