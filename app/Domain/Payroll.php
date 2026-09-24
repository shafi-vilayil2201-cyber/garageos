<?php

// Monthly and daily-wage employees are paid on opposite defaults: a
// monthly employee is assumed paid in full unless a day is explicitly
// marked absent/half-day (an unmarked Sunday never docks pay), while a
// daily-wage employee earns nothing unless a day is explicitly marked
// present/half-day (that's how day-wage work actually gets paid).
//
// $user needs salary_type and salary_amount. $attendanceRows is a list of
// ['status' => ...] rows for the user's marked days within the period.
// $advanceAmount is any outstanding salary advance to recover in full
// this run (see salary_advances / outstanding_advance_for_user()) — the
// deduction is never capped to what's left of net, so an advance larger
// than one period's pay shows up as a visibly negative net rather than
// silently vanishing.
function calculate_payroll(array $user, array $attendanceRows, int $daysInPeriod, float $advanceAmount = 0.0): array
{
    $daysAbsent = 0.0;
    $daysHalfDay = 0.0;
    $daysPresent = 0.0;

    foreach ($attendanceRows as $row) {
        if ($row['status'] === 'absent') {
            $daysAbsent += 1;
        } elseif ($row['status'] === 'half_day') {
            $daysHalfDay += 1;
        } elseif ($row['status'] === 'present') {
            $daysPresent += 1;
        }
    }

    $salaryAmount = (float) $user['salary_amount'];

    if ($user['salary_type'] === 'daily_wage') {
        // Gross treats a half-day as a full day worked (what they'd earn
        // if it hadn't been docked), so it reads the same way a monthly
        // employee's row does — Deduction is the half-day discount,
        // Net = Gross - Deduction — instead of a flat ₹0.00 that looks
        // like nothing was calculated.
        $grossSalary = round($salaryAmount * ($daysPresent + $daysHalfDay), 2);
        $deduction = round($salaryAmount * 0.5 * $daysHalfDay, 2);
    } else {
        $perDayRate = $daysInPeriod > 0 ? $salaryAmount / $daysInPeriod : 0.0;
        $grossSalary = $salaryAmount;
        $deduction = round($perDayRate * ($daysAbsent + 0.5 * $daysHalfDay), 2);
    }

    $advanceDeducted = round($advanceAmount, 2);
    $netSalary = round($grossSalary - $deduction - $advanceDeducted, 2);

    return [
        'days_present' => $daysPresent,
        'days_absent' => $daysAbsent,
        'days_half_day' => $daysHalfDay,
        'gross_salary' => round($grossSalary, 2),
        'deduction_amount' => $deduction,
        'advance_deducted' => $advanceDeducted,
        'net_salary' => $netSalary,
    ];
}

// The D-of-month period (per this employee's own salary_pay_day) that
// contains $referenceDate — e.g. pay day 10, reference 2026-09-05 falls
// in the period that started 2026-08-10; reference 2026-09-15 falls in
// the period that started 2026-09-10. A NULL salary_pay_day (nobody's
// set a custom cycle) behaves as pay day 1, which is exactly the old
// calendar-month behaviour every already-generated payroll_run used.
function payroll_period_start_for(int $payDay, string $referenceDate): string
{
    $reference = new DateTime($referenceDate);
    $day = (int) $reference->format('j');

    $start = new DateTime($reference->format('Y-m-') . str_pad((string) $payDay, 2, '0', STR_PAD_LEFT));

    if ($day < $payDay) {
        $start->modify('-1 month');
    }

    return $start->format('Y-m-d');
}

// Exclusive end of the period starting $periodStart — always exactly
// one month later, so "days in period" is just the real number of
// calendar days between the two, whatever that period's actual month
// lengths are.
function payroll_period_end(string $periodStart): string
{
    return (new DateTime($periodStart))->modify('+1 month')->format('Y-m-d');
}

// A period starting on the 1st reads as "August 2026" (unchanged from
// before this file supported custom cycles at all); any other start
// day reads as its actual date range, since "August 2026" would be
// misleading for a cycle that doesn't line up with the calendar month.
function payroll_period_label(string $periodStart): string
{
    $start = new DateTime($periodStart);

    if ((int) $start->format('j') === 1) {
        return $start->format('F Y');
    }

    $end = (new DateTime(payroll_period_end($periodStart)))->modify('-1 day');

    return $start->format('d M Y') . ' – ' . $end->format('d M Y');
}

// Every period for this employee, from their very first one (the pay-
// day-aligned period containing $joinedAt) through today, that has
// fully elapsed (period end <= today) and isn't in $generatedPeriods
// yet — so a manager who's behind by two cycles sees both individually,
// not just the latest one. Returns [['start' => Y-m-d, 'end' => Y-m-d], ...]
// oldest first.
function pending_payroll_periods(int $payDay, string $joinedAt, array $generatedPeriods, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $periods = [];
    $cursor = payroll_period_start_for($payDay, $joinedAt);

    while (true) {
        $end = payroll_period_end($cursor);

        if ($end > $today) {
            break;
        }

        if (!in_array($cursor, $generatedPeriods, true)) {
            $periods[] = ['start' => $cursor, 'end' => $end];
        }

        $cursor = $end;
    }

    return $periods;
}
