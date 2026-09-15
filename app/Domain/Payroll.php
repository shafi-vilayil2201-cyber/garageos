<?php

// Monthly and daily-wage employees are paid on opposite defaults: a
// monthly employee is assumed paid in full unless a day is explicitly
// marked absent/half-day (an unmarked Sunday never docks pay), while a
// daily-wage employee earns nothing unless a day is explicitly marked
// present/half-day (that's how day-wage work actually gets paid).
//
// $user needs salary_type and salary_amount. $attendanceRows is a list of
// ['status' => ...] rows for the user's marked days within the period.
function calculate_payroll(array $user, array $attendanceRows, int $daysInMonth): array
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
        $netSalary = round($grossSalary - $deduction, 2);
    } else {
        $perDayRate = $daysInMonth > 0 ? $salaryAmount / $daysInMonth : 0.0;
        $grossSalary = $salaryAmount;
        $deduction = round($perDayRate * ($daysAbsent + 0.5 * $daysHalfDay), 2);
        $netSalary = round($grossSalary - $deduction, 2);
    }

    return [
        'days_present' => $daysPresent,
        'days_absent' => $daysAbsent,
        'days_half_day' => $daysHalfDay,
        'gross_salary' => round($grossSalary, 2),
        'deduction_amount' => $deduction,
        'net_salary' => $netSalary,
    ];
}
