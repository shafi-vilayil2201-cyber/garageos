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

// India's states and union territories — used to pin down both sides of
// the intra-state/inter-state comparison below. A free-text field here
// would let "Kerala" and "kerala" fail to match each other and silently
// produce the wrong GST split, so both the organization's and a
// customer's state are always chosen from this same fixed list.
const INDIAN_STATES = [
    'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar',
    'Chandigarh', 'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa',
    'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka',
    'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya',
    'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu',
    'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'
];

function state_options(?string $selected): string
{
    $html = '<option value="">Not set</option>';

    foreach (INDIAN_STATES as $state) {
        $isSelected = $selected === $state ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($state) . '" ' . $isSelected . '>' . htmlspecialchars($state) . '</option>';
    }

    return $html;
}

// Only inter-state when BOTH states are known and they differ — an
// unset state on either side means we genuinely don't know, so this
// stays false (the existing CGST+SGST default) rather than guessing.
function gst_is_inter_state(?string $organizationState, ?string $customerState): bool
{
    return $organizationState !== null && $customerState !== null && $organizationState !== $customerState;
}
