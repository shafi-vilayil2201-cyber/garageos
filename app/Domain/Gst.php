<?php

// The single source of truth for which GST rates are legally valid slabs
// today (0/5/12/18/28). If these ever get restructured nationally, this
// is the only place that needs to change — every rate dropdown and
// validation check across the app (Settings, Parts, Services, Invoice)
// reads from here instead of repeating its own copy of the list.
const GST_RATES = [0, 5, 12, 18, 28];

function gst_rate_options(float $selectedRate, bool $zeroAsNoGst = false): string
{
    $html = '';

    foreach (GST_RATES as $rate) {
        $label = ($zeroAsNoGst && $rate === 0) ? 'No GST (0%)' : $rate . '%';
        $selected = $selectedRate === (float) $rate ? 'selected' : '';
        $html .= '<option value="' . $rate . '" ' . $selected . '>' . $label . '</option>';
    }

    return $html;
}

function gst_rate_is_valid(string $rate): bool
{
    return in_array($rate, array_map('strval', GST_RATES), true);
}
