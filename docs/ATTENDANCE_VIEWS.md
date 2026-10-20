# Attendance & leave screens and the payroll summary (package 2)

Needs package 1 (loader) installed first.

## Install
1. Copy over the project. Overwrites: config/permissions.php, config/navigation.php, routes/web.php, EmployeeController,
   employees/show.blade.php, app/Payroll/SystemVariables.php (adds WORKED_HOURS) and app/Services/AttendanceLoadService.php
   (cancelled-leave detection improved, see below).
2. `php artisan db:seed --class=AccessControlSeeder`   (attendance.view, leave.view)
3. `php artisan optimize:clear`. No migration. Give roles attendance.view / leave.view.

## Screens
* Time & Attendance > Timesheets: daily rows with filters (dates, employee, scheme, absent / late / overtime / unapproved overtime / no longer in MiHCM).
* Leave > Requests: leave overlapping a range, with status, leave type treatment (no-pay / needs review) and cancelled flags.
* Employee page > Attendance and Leave tabs: one shared date range (up to 100 days, any dates), daily table, and a
  "Payroll preview" showing the formula variables for that range with warnings.
All of them respect scheme restrictions; the attendance data comes from the attendance database.

## How the summary is worked out (app/Payroll/AttendanceSummary.php)
Works for ANY range, so a pay run can call it once per window (pay period, overtime window, attendance-allowance window).
* WORKING_DAYS: days MiHCM expects the employee. PRESENT_DAYS: expected days present.
* ABSENT_DAYS: expected, not present, not covered by CONFIRMED leave (a half day counts 0.5).
* LWP_DAYS: expected days covered by CONFIRMED leave of a type marked no-pay. Half-day aware (start/end sessions).
  Only days MiHCM expects the employee count, so rest days inside a long leave are not deducted.
* Pending leave (P), and confirmed leave of a type not yet reviewed, are NEVER deducted: they appear as warnings.
* LATE_HOURS, WORKED_HOURS: decimal hours from minutes (4 decimals). WORKED_HOURS is new, for hourly pay.
* OT_HOURS / OT_DOUBLE_HOURS / OT_TRIPLE_HOURS: APPROVED overtime from days whose approval is done. The approved figure
  used comes from config attendance.ot_approved_source ('decimal' or 'text'); unapproved overtime is reported, not counted.
* Rows MiHCM stopped returning, and cancelled leave, are excluded and reported. Days with no data are reported, not guessed.
* Nothing here depends on the scheme: if Executive and above start getting overtime or attendance allowance, give them the
  components. The summary is the same for everyone.

## Multi-month leave
MiHCM lists a leave in every month it overlaps (26 Mar to 9 Apr is in both the 2026/03 and 2026/04 feeds). The loader now
judges any stored entry that overlaps a month it fetched, and was not returned, as cancelled (it used to judge only entries
starting in a fetched month). Loading only April therefore catches a cancelled March-to-April leave.

## Tests
tests/Unit/Attendance/AttendanceSummaryTest.php (plus the mapper tests): `php artisan test tests/Unit/Attendance`.
