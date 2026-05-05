# Android SMS Gateway Contract

The server never sends SMS directly. The Android phone reads queued messages,
sends SMS from the device, then reports the result back to the server.

## Token

Configured in:

```text
/ieum/config.php
```

Current local token:

```text
ieum-local-gateway-token-2026
```

Change this token before any real device is connected.

In production, each academy gets a unique gateway token. The phone can only
claim and update SMS rows that belong to the academy matched by that token.

## Recommended Flow

```text
1. POST /ieum/api/sms/claim.php
2. Android sends each claimed SMS
3. POST /ieum/api/sms/update.php per message
```

Use `claim.php` instead of `pending.php` for real sending. It changes messages
from `pending` to `processing`, which prevents duplicate sending when the app
retries or when two devices are connected.

## Claim Messages

```http
POST /ieum/api/sms/claim.php
Content-Type: application/x-www-form-urlencoded
```

Fields:

```text
token=ieum-local-gateway-token-2026
device=Galaxy-A-local
limit=10
```

## Update Result

```http
POST /ieum/api/sms/update.php
Content-Type: application/x-www-form-urlencoded
```

Success:

```text
token=ieum-local-gateway-token-2026
sms_id=1
status=sent
device=Galaxy-A-local
```

Failure:

```text
token=ieum-local-gateway-token-2026
sms_id=1
status=failed
device=Galaxy-A-local
error_message=SEND_SMS permission denied
```

To put a failed or stuck message back into the queue:

```text
status=pending
```

## Android Permissions

```xml
<uses-permission android:name="android.permission.SEND_SMS" />
<uses-permission android:name="android.permission.INTERNET" />
```

Android 6+ also requires runtime permission approval for `SEND_SMS`.

## Localhost Note

From a physical Android phone, `localhost` means the phone itself. Use the PC's
LAN IP instead, for example:

```text
http://192.168.0.10/ieum/api/sms/claim.php
```

The phone and PC must be on the same network, and Windows Firewall must allow
Apache inbound access.

## Operating Hours

The Android app stores academy-specific operating settings:

```text
Start time
End time
Poll seconds
```

The foreground service stays alive, but only claims SMS inside the configured
time window.
