<?php

// The single source of truth for which units a part can be tracked in —
// mirrors app/Domain/Gst.php's GST_RATES pattern. Fixed and curated
// rather than free text, so "L" / "ltr" / "litre" can't end up as three
// different values scattered across parts (which would silently break
// any future reporting that groups or matches on unit).
//
// 'whole' marks units that only make sense as whole numbers (you can't
// have half a spark plug) — job-card.php's add_part handler enforces
// this server-side. Everything else (litres, ml, kg, g) is fractional,
// which is the whole point of this feature: oil needs 0.5 / 1.5 etc.
const PART_UNITS = [
    'pcs'  => ['label' => 'Pieces (pcs)', 'short' => 'pcs', 'whole' => true],
    'set'  => ['label' => 'Set', 'short' => 'set', 'whole' => true],
    'pair' => ['label' => 'Pair', 'short' => 'pair', 'whole' => true],
    'box'  => ['label' => 'Box', 'short' => 'box', 'whole' => true],
    'ltr'  => ['label' => 'Litre (L)', 'short' => 'L', 'whole' => false],
    'ml'   => ['label' => 'Millilitre (ml)', 'short' => 'ml', 'whole' => false],
    'kg'   => ['label' => 'Kilogram (kg)', 'short' => 'kg', 'whole' => false],
    'g'    => ['label' => 'Gram (g)', 'short' => 'g', 'whole' => false],
];

function part_unit_options(string $selected): string
{
    $html = '';

    foreach (PART_UNITS as $code => $unit) {
        $isSelected = $selected === $code ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($code) . '" ' . $isSelected . '>' . htmlspecialchars($unit['label']) . '</option>';
    }

    return $html;
}

function part_unit_is_valid(string $unit): bool
{
    return isset(PART_UNITS[$unit]);
}

// Falls back to the raw code itself for any legacy/unrecognized value
// already sitting in the database, rather than showing nothing.
function part_unit_short(?string $unit): string
{
    return PART_UNITS[$unit]['short'] ?? ($unit ?: 'pcs');
}

function part_unit_is_whole(?string $unit): bool
{
    return PART_UNITS[$unit]['whole'] ?? true;
}
