<?php

// Projects when a vehicle is next due for service, using the same
// "whichever comes first" rule most garages already use in practice:
// due at (last service + interval_km) or (last service + interval_months),
// whichever date is sooner.
//
// $history is that vehicle's past job cards with a recorded odometer
// reading, as [['date' => 'Y-m-d', 'odometer' => int], ...] in any order
// — real average km/day is derived from the earliest and latest reading,
// which is more accurate (and needs no extra data entry from staff) than
// asking anyone to estimate it. With only one reading, there's nothing to
// average yet, so the projection falls back to the time-based rule alone
// rather than guessing a km/day figure.
function calculate_next_service_due(array $history, int $intervalKm, int $intervalMonths): ?string
{
    $history = array_values(array_filter($history, fn($row) => $row['odometer'] !== null));

    if (empty($history)) {
        return null;
    }

    usort($history, fn($a, $b) => strcmp($a['date'], $b['date']));

    $latest = end($history);
    $earliest = $history[0];

    $byTime = date('Y-m-d', strtotime($latest['date'] . " +{$intervalMonths} months"));

    $byKm = null;

    if (count($history) > 1 && $latest['odometer'] > $earliest['odometer']) {
        $daysElapsed = (strtotime($latest['date']) - strtotime($earliest['date'])) / 86400;
        $kmElapsed = $latest['odometer'] - $earliest['odometer'];

        if ($daysElapsed > 0) {
            $avgKmPerDay = $kmElapsed / $daysElapsed;
            $daysToNextService = (int) ceil($intervalKm / $avgKmPerDay);
            $byKm = date('Y-m-d', strtotime($latest['date'] . " +{$daysToNextService} days"));
        }
    }

    if ($byKm === null) {
        return $byTime;
    }

    return min($byTime, $byKm);
}
