# Attendance & leave loader (package 1: data, loader, load history, leave types)

## Install
1. Create the database and a user (it needs INSERT, SELECT, UPDATE, DELETE; the audit database is insert-only, so do not share it):
       CREATE DATABASE payroll_attendance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
       CREATE USER 'attendance_app'@'%' IDENTIFIED BY '...';
       GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_attendance.* TO 'attendance_app'@'%';
   For the first `php artisan migrate`, run it with an account that can CREATE tables (like the audit database).
2. Append `.env.additions` to `.env` and fill in the DB_ATTENDANCE_* values. (Leave ATTENDANCE_DB_CONNECTION empty to keep
   everything in the main database.)
3. Copy over the project. Overwrites config/database.php (adds the `attendance` connection), config/permissions.php,
   config/navigation.php, routes/web.php and app/Services/Mihcm/MihcmClient.php (existing methods are unchanged).
4. `php artisan migrate`   (creates the tables on the attendance connection)
5. `php artisan db:seed --class=AccessControlSeeder`   (attendance.load, attendance.load-log, leave-types.manage)
6. `php artisan optimize:clear`, and restart the queue worker.

## Use
Time & Attendance > Load data: pick a date range and what to load. It runs in the background and opens a result page.
Same thing from the terminal: `php artisan attendance:load --from=2026-08-01 --to=2026-09-10 --scope=both [--queue]`.
Nothing is scheduled. The pay run will call the same loader for the windows it needs.
Leave > Leave types: mark each type paid or no-pay (new types start as "needs review").

## Rules
* A load never makes data final (MiHCM does not say what is final). It shows what still needs attention: unapproved overtime,
  pending leave, unreviewed leave types, employees with no data, unmatched numbers, rows that vanished, rows that changed.
* Fetch everything first, then apply in one transaction: a failed fetch changes nothing. At most 100 days per load.
* Rows MiHCM stops returning are flagged and kept (never deleted). Cancelled leave disappears from the feed, so it is treated
  as cancelled and flagged. Only entries that START in a fetched month are judged missing.
* Only confirmed leave (C) may ever be deducted; pending (P) is stored and flagged. Leave reason and remark are not stored.
* Work, late and overtime are stored in minutes. Both approved-overtime values are stored (config attendance.ot_approved_source
  picks the one payroll will use; the MiHCM sample contradicts itself, so confirm with MiHCM).
* The database is separate: no joins or foreign keys to the main database. Visibility by scheme is applied by passing the
  visible employee ids. Later, the pay run keeps a summary in the main database, so payslips never depend on this data.
* Unmatched employee numbers are reported, never guessed. If the attendance feed uses numbers without leading zeros, set
  ATTENDANCE_EMPLOYEE_NO_PAD; if the number is in textCardNumber, set ATTENDANCE_EMPLOYEE_NO_FIELD.

## Assumptions to confirm with MiHCM
`startDate`/`endDate` are inclusive and `YYYY-MM-DD`; the attendance employee number is `number`; `late` is not used (the
`lateString` HH:MM text is); `leave_Amount` / `shortLeaveAmount` are informational only (payroll uses the leave API);
the monthly leave endpoint lists a multi-month leave under its start month; the meaning of `dayType`, `shiftCode` and `status`.
These are all in app/Services/Mihcm/AttendanceMapper.php and LeaveMapper.php.

## Tests
tests/Unit/Attendance (mappers, from the MiHCM sample): `php artisan test tests/Unit/Attendance`.
