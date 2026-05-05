# Ieum Android SMS Gateway

Native Android app for the Ieum attendance SMS queue.

## What It Does

1. Calls `/ieum/api/sms/claim.php`.
2. Sends each claimed SMS from the Android phone.
3. Calls `/ieum/api/sms/update.php` with `sent` or `failed`.

The server never sends SMS directly.

## Open In Android Studio

Open this folder:

```text
D:\ieum-v1\EB-4_7_10_package\ieum\android-sms-gateway
```

Android Studio will download the Android Gradle Plugin if needed.

## App Settings

Set these values inside the app:

```text
Server URL: http://YOUR_PC_LAN_IP
Token: ieum-local-gateway-token-2026
Device: Galaxy-A-local
Start time: 10:00
End time: 20:00
Poll seconds: 30
```

Do not use `localhost` on a physical Android phone. `localhost` means the phone
itself. Use the PC LAN IP, for example:

```text
http://192.168.0.10
```

## Required Phone Permission

Allow SMS permission when the app asks.

## Local Network Checklist

- Phone and PC are on the same Wi-Fi network.
- Laragon Apache is running.
- Windows Firewall allows inbound Apache traffic.
- Browser on the phone can open `http://YOUR_PC_LAN_IP/ieum/api/sms/pending.php?token=ieum-local-gateway-token-2026`.

## Operation

- Tap `1회 실행` to process once.
- Tap `서비스 시작` to run a foreground service.
- Tap `서비스 중지` to stop the foreground service.
- The service checks the queue only within the configured operating hours.
- Keep the phone's battery optimization disabled for this app during operation.
