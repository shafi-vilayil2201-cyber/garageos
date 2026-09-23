<?php

require_once __DIR__ . '/DamageImage.php';

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
    'front_left_headlight' => ['label' => 'Front left headlight', 'group' => 'Front'],
    'front_right_headlight' => ['label' => 'Front right headlight', 'group' => 'Front'],
    'front_grille' => ['label' => 'Front grille', 'group' => 'Front'],
    'windshield' => ['label' => 'Windshield', 'group' => 'Front'],
    // The unsuffixed keys remain valid for records created by the earlier
    // diagram, while the current hit-map uses explicit metal-panel keys.
    'front_left_door' => ['label' => 'Front left door metal', 'group' => 'Side'],
    'front_right_door' => ['label' => 'Front right door metal', 'group' => 'Side'],
    'rear_left_door' => ['label' => 'Rear left door metal', 'group' => 'Side'],
    'rear_right_door' => ['label' => 'Rear right door metal', 'group' => 'Side'],
    'front_left_door_metal' => ['label' => 'Front left door metal', 'group' => 'Side'],
    'front_right_door_metal' => ['label' => 'Front right door metal', 'group' => 'Side'],
    'rear_left_door_metal' => ['label' => 'Rear left door metal', 'group' => 'Side'],
    'rear_right_door_metal' => ['label' => 'Rear right door metal', 'group' => 'Side'],
    'rear_left_door_glass' => ['label' => 'Rear left door glass', 'group' => 'Side'],
    'rear_right_door_glass' => ['label' => 'Rear right door glass', 'group' => 'Side'],
    'front_left_door_glass' => ['label' => 'Front left door glass', 'group' => 'Side'],
    'front_right_door_glass' => ['label' => 'Front right door glass', 'group' => 'Side'],
    'rear_left_quarter_panel' => ['label' => 'Rear left quarter panel', 'group' => 'Rear'],
    'rear_right_quarter_panel' => ['label' => 'Rear right quarter panel', 'group' => 'Rear'],
    'rear_left_tail_light' => ['label' => 'Rear left tail light', 'group' => 'Rear'],
    'rear_right_tail_light' => ['label' => 'Rear right tail light', 'group' => 'Rear'],
    'rear_windshield' => ['label' => 'Rear windshield', 'group' => 'Rear'],
    'trunk' => ['label' => 'Trunk', 'group' => 'Rear'],
    'rear_hatch' => ['label' => 'Rear hatch / tailgate', 'group' => 'Rear'],
    'rear_bumper' => ['label' => 'Rear bumper', 'group' => 'Rear'],
    'rear_left_wheel' => ['label' => 'Rear left wheel', 'group' => 'Rear'],
    'rear_right_wheel' => ['label' => 'Rear right wheel', 'group' => 'Rear'],
    'left_side_skirt' => ['label' => 'Left side skirt', 'group' => 'Side'],
    'right_side_skirt' => ['label' => 'Right side skirt', 'group' => 'Side'],
    'fuel_door' => ['label' => 'Fuel door', 'group' => 'Side'],
    'roof' => ['label' => 'Roof', 'group' => 'Roof'],
    'rear_left_door_handle' => ['label' => 'Rear left door handle', 'group' => 'Side'],
    'rear_right_door_handle' => ['label' => 'Rear right door handle', 'group' => 'Side'],
    'front_left_door_handle' => ['label' => 'Front left door handle', 'group' => 'Side'],
    'front_right_door_handle' => ['label' => 'Front right door handle', 'group' => 'Side'],
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

// The browser posts one JSON array of {part_key, damage_type, x, y} —
// silently drops anything malformed rather than failing the whole job
// card, same tolerance the rest of this intake form already has for bad
// rows.
function parse_damage_marks(?string $json): array
{
    $decoded = json_decode($json ?: '', true);

    if (!is_array($decoded)) {
        return [];
    }

    $marks = [];
    $seen = [];

    foreach ($decoded as $mark) {
        if (!is_array($mark)) {
            continue;
        }

        $partKey = (string) ($mark['part_key'] ?? '');
        $damageType = (string) ($mark['damage_type'] ?? '');
        $x = (float) ($mark['x'] ?? -1);
        $y = (float) ($mark['y'] ?? -1);
        $uniqueKey = $partKey . ':' . $damageType;

        if (!vehicle_damage_part_is_valid($partKey)
            || !vehicle_damage_type_is_valid($damageType)
            || $x < 0 || $x > 560
            || $y < 0 || $y > 879
            || isset($seen[$uniqueKey])) {
            continue;
        }

        $seen[$uniqueKey] = true;
        $marks[] = [
            'part_key' => $partKey,
            'damage_type' => $damageType,
            'x' => round($x, 2),
            'y' => round($y, 2),
        ];
    }

    return $marks;
}

// Shared by intake (job-card-new.php) and the "Add/Edit vehicle
// condition" flow on job-card.php — the same function handles both a
// first-time save and a later correction, always replacing whatever
// marks and image already exist rather than appending to them (a
// technician fixing a mis-click isn't adding a second condition
// record). damage_recorded_at is set only the first time; every save
// after that stamps damage_updated_at instead, so the two dates shown
// together make clear both when this was originally captured and
// whether — and when — it was corrected since.
function save_vehicle_damage(PDO $pdo, int $jobCardId, ?string $marksJson, ?string $imageDataUrl): void
{
    $marks = parse_damage_marks($marksJson);

    $pdo->prepare("DELETE FROM job_card_damage_marks WHERE job_card_id = :job_card_id")
        ->execute(['job_card_id' => $jobCardId]);

    $statement = $pdo->prepare("
        INSERT INTO job_card_damage_marks (job_card_id, part_key, damage_type, x, y)
        VALUES (:job_card_id, :part_key, :damage_type, :x, :y)
    ");

    foreach ($marks as $mark) {
        $statement->execute([
            'job_card_id' => $jobCardId,
            'part_key' => $mark['part_key'],
            'damage_type' => $mark['damage_type'],
            'x' => $mark['x'],
            'y' => $mark['y'],
        ]);
    }

    $statement = $pdo->prepare("SELECT damage_recorded_at, damage_image_url FROM job_cards WHERE id = :id");
    $statement->execute(['id' => $jobCardId]);
    $existing = $statement->fetch(PDO::FETCH_ASSOC);

    // No marks left means the technician cleared the condition entirely —
    // the stale snapshot image would otherwise keep showing marks that no
    // longer exist in the structured data, so it's deleted rather than kept.
    if (!$marks) {
        delete_old_damage_image($existing['damage_image_url'] ?? null);
    }

    $damageImageUrl = $marks ? save_damage_image($jobCardId, $imageDataUrl) : null;
    $column = $existing['damage_recorded_at'] ? 'damage_updated_at' : 'damage_recorded_at';

    $pdo->prepare("
        UPDATE job_cards
        SET damage_image_url = :damage_image_url,
            {$column} = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute(['damage_image_url' => $damageImageUrl, 'id' => $jobCardId]);
}
