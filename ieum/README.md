# Ieum Automation Platform

This folder contains the first automation section only.

## Section 1

- `install.php`: creates the local Ieum tables.
- `kiosk.php`: tablet-friendly attendance input.
- `save_attendance_by_code.php`: JSON attendance API.
- `admin/attendance_today.php`: admin attendance dashboard.

The server does not send SMS directly. It only creates rows in `ieum_sms_queue`
with `pending` status for a future Android SMS gateway.
