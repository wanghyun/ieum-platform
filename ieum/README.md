# Ieum Automation Platform

This folder contains the first automation section only.

## Section 1

- `install.php`: creates the local Ieum tables.
- `kiosk.php`: legacy web attendance input.
- `save_attendance_by_code.php`: JSON attendance API.
- `api/attendance/checkin.php`: token-based attendance API for the native tablet app.
- `admin/attendance_today.php`: admin attendance dashboard.

The server does not send SMS directly. It only creates rows in `ieum_sms_queue`
with `pending` status for a future Android SMS gateway.

## Tablet direction

Attendance tablets are moving to a native Android app direction. The web kiosk
remains as a fallback/admin test page, but the production tablet flow should use:

- Android app module: `android-sms-gateway/tablet-attendance`
- Server API: `POST /ieum/api/attendance/checkin.php`
- Token: each academy `gateway_token`

The SMS gateway app and the tablet attendance app are separate APKs because they
run on different devices and need different permissions and UX.
