package kr.ieum.smsgateway;

import android.app.Activity;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.telephony.SmsManager;
import android.util.Log;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.Collections;
import java.util.Date;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

public class GatewayService extends Service {
    static final String ACTION_START = "kr.ieum.smsgateway.START";
    static final String ACTION_STOP = "kr.ieum.smsgateway.STOP";
    static final String ACTION_RUN_ONCE = "kr.ieum.smsgateway.RUN_ONCE";

    private static final String ACTION_SMS_SENT = "kr.ieum.smsgateway.SMS_SENT";
    private static final String CHANNEL_ID = "ieum_sms_gateway";
    private static final String TAG = "IeumSmsGateway";
    private static final int NOTIFICATION_ID = 240504;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Map<Integer, PendingSmsResult> pendingSms = Collections.synchronizedMap(new HashMap<Integer, PendingSmsResult>());
    private boolean running = false;
    private boolean sentReceiverRegistered = false;
    private String lastStatus = "Waiting";

    private final BroadcastReceiver sentReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (intent == null || !ACTION_SMS_SENT.equals(intent.getAction())) {
                return;
            }
            handleSentResult(
                    intent.getIntExtra("sms_id", 0),
                    intent.getIntExtra("part", 0),
                    getResultCode()
            );
        }
    };

    private final Runnable loop = new Runnable() {
        @Override
        public void run() {
            if (!running) {
                return;
            }
            if (isWithinOperatingDays() && isWithinOperatingHours()) {
                syncOnce();
            } else {
                updateStatus("Outside operating hours");
            }
            handler.postDelayed(this, intervalMillis());
        }
    };

    @Override
    public void onCreate() {
        super.onCreate();
        createChannel();
        registerSentReceiver();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent != null ? intent.getAction() : ACTION_START;
        startForeground(NOTIFICATION_ID, notification("Ieum SMS Gateway", lastStatus));

        if (ACTION_STOP.equals(action)) {
            running = false;
            handler.removeCallbacks(loop);
            stopForeground(true);
            stopSelf();
            return START_NOT_STICKY;
        }

        if (ACTION_RUN_ONCE.equals(action)) {
            syncOnce();
            return START_NOT_STICKY;
        }

        running = true;
        handler.removeCallbacks(loop);
        handler.post(loop);
        updateStatus("Auto sending is running");
        return START_STICKY;
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacks(loop);
        if (sentReceiverRegistered) {
            unregisterReceiver(sentReceiver);
            sentReceiverRegistered = false;
        }
        super.onDestroy();
    }

    private void syncOnce() {
        updateStatus("Checking queue");
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    List<SmsItem> items = claimMessages();
                    if (items.isEmpty()) {
                        updateStatus("No queued SMS");
                        return;
                    }
                    for (SmsItem item : items) {
                        sendOne(item);
                    }
                } catch (Exception e) {
                    updateStatus("Error: " + e.getMessage());
                }
            }
        }).start();
    }

    private List<SmsItem> claimMessages() throws Exception {
        String body = form(
                "token", deviceToken(),
                "device", deviceName(),
                "limit", "10"
        );
        JSONObject json = postJson(baseUrl() + "/ieum/api/sms/claim.php", body);
        if (!json.optBoolean("ok")) {
            throw new Exception(json.optString("message", "Failed to claim SMS queue"));
        }

        JSONObject data = json.getJSONObject("data");
        updateSettings(data);
        JSONArray arr = data.getJSONArray("items");
        List<SmsItem> items = new ArrayList<>();
        for (int i = 0; i < arr.length(); i++) {
            JSONObject row = arr.getJSONObject(i);
            SmsItem item = new SmsItem();
            item.smsId = row.getInt("sms_id");
            item.phone = row.getString("recipient_phone");
            item.message = row.getString("message");
            items.add(item);
        }
        return items;
    }

    private void sendOne(SmsItem item) {
        try {
            if (!isValidKoreanMobile(item.phone)) {
                throw new IllegalArgumentException("invalid_phone_number");
            }

            SmsManager manager = SmsManager.getDefault();
            ArrayList<String> parts = manager.divideMessage(item.message);
            if (parts == null || parts.isEmpty()) {
                parts = new ArrayList<>();
                parts.add(item.message);
            }

            pendingSms.put(item.smsId, new PendingSmsResult(parts.size()));
            if (parts.size() == 1) {
                manager.sendTextMessage(item.phone, null, item.message, sentIntent(item.smsId, 0), null);
            } else {
                ArrayList<PendingIntent> sentIntents = new ArrayList<>();
                for (int i = 0; i < parts.size(); i++) {
                    sentIntents.add(sentIntent(item.smsId, i));
                }
                manager.sendMultipartTextMessage(item.phone, null, parts, sentIntents, null);
            }
            scheduleSendTimeout(item.smsId);
            updateStatus("SMS send requested #" + item.smsId);
        } catch (Exception e) {
            pendingSms.remove(item.smsId);
            try {
                updateResult(item.smsId, "failed", e.getMessage());
            } catch (Exception ignored) {
            }
            updateStatus("SMS send failed #" + item.smsId + ": " + e.getMessage());
        }
    }

    private boolean isValidKoreanMobile(String phone) {
        if (phone == null) {
            return false;
        }
        String digits = phone.replaceAll("[^0-9]", "");
        return digits.matches("01[016789][0-9]{7,8}");
    }

    private PendingIntent sentIntent(int smsId, int part) {
        Intent intent = new Intent(ACTION_SMS_SENT);
        intent.setPackage(getPackageName());
        intent.putExtra("sms_id", smsId);
        intent.putExtra("part", part);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            flags |= PendingIntent.FLAG_IMMUTABLE;
        }
        return PendingIntent.getBroadcast(this, smsId * 100 + part, intent, flags);
    }

    private void scheduleSendTimeout(final int smsId) {
        handler.postDelayed(new Runnable() {
            @Override
            public void run() {
                PendingSmsResult result = pendingSms.get(smsId);
                if (result == null) {
                    return;
                }
                pendingSms.remove(smsId);
                updateResultAsync(smsId, "failed", "sms_send_timeout", "SMS send timeout #" + smsId);
            }
        }, 60000L);
    }

    private void handleSentResult(int smsId, int part, int resultCode) {
        PendingSmsResult result = pendingSms.get(smsId);
        if (smsId <= 0 || result == null) {
            return;
        }

        boolean done = result.record(resultCode == Activity.RESULT_OK, part, smsErrorLabel(resultCode));
        if (!done) {
            return;
        }

        pendingSms.remove(smsId);
        if (result.failedParts > 0) {
            updateResultAsync(smsId, "failed", result.errorSummary(), "SMS send failed #" + smsId + ": " + result.errorSummary());
        } else {
            updateResultAsync(smsId, "sent", "", "SMS sent #" + smsId);
        }
    }

    private String smsErrorLabel(int resultCode) {
        if (resultCode == Activity.RESULT_OK) {
            return "";
        }
        if (resultCode == SmsManager.RESULT_ERROR_GENERIC_FAILURE) {
            return "generic_failure";
        }
        if (resultCode == SmsManager.RESULT_ERROR_NO_SERVICE) {
            return "no_service";
        }
        if (resultCode == SmsManager.RESULT_ERROR_NULL_PDU) {
            return "null_pdu";
        }
        if (resultCode == SmsManager.RESULT_ERROR_RADIO_OFF) {
            return "radio_off";
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && resultCode == SmsManager.RESULT_ERROR_LIMIT_EXCEEDED) {
            return "limit_exceeded";
        }
        return "result_" + resultCode;
    }

    private void updateResult(int smsId, String status, String errorMessage) throws Exception {
        String body = form(
                "token", deviceToken(),
                "sms_id", String.valueOf(smsId),
                "status", status,
                "device", deviceName(),
                "error_message", errorMessage == null ? "" : errorMessage
        );
        JSONObject json = postJson(baseUrl() + "/ieum/api/sms/update.php", body);
        if (!json.optBoolean("ok")) {
            throw new Exception(json.optString("message", "Failed to update SMS result"));
        }
    }

    private void updateResultAsync(final int smsId, final String status, final String errorMessage, final String successStatus) {
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    updateResult(smsId, status, errorMessage);
                    updateStatus(successStatus);
                } catch (Exception e) {
                    String message = e.getMessage();
                    if (message == null || message.length() == 0) {
                        message = e.getClass().getSimpleName();
                    }
                    updateStatus("SMS result update failed #" + smsId + ": " + message);
                }
            }
        }).start();
    }

    private void updateSettings(JSONObject data) {
        SharedPreferences.Editor editor = prefs().edit();
        if (data.has("academy_name")) {
            editor.putString(MainActivity.KEY_ACADEMY_NAME, data.optString("academy_name", ""));
        }
        if (data.has("sms_start_time")) {
            editor.putString(MainActivity.KEY_START, data.optString("sms_start_time", "10:00"));
        }
        if (data.has("sms_end_time")) {
            editor.putString(MainActivity.KEY_END, data.optString("sms_end_time", "20:00"));
        }
        if (data.has("sms_day_mode")) {
            editor.putString(MainActivity.KEY_DAY_MODE, data.optString("sms_day_mode", "weekday"));
        }
        if (data.has("sms_poll_seconds")) {
            editor.putInt(MainActivity.KEY_INTERVAL, Math.max(15, data.optInt("sms_poll_seconds", 30)));
        }
        editor.apply();
    }

    private JSONObject postJson(String url, String body) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setRequestMethod("POST");
        conn.setConnectTimeout(10000);
        conn.setReadTimeout(10000);
        conn.setDoOutput(true);
        conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
        byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
        conn.setFixedLengthStreamingMode(bytes.length);
        OutputStream os = conn.getOutputStream();
        os.write(bytes);
        os.close();

        int code = conn.getResponseCode();
        InputStream stream = code >= 200 && code < 300 ? conn.getInputStream() : conn.getErrorStream();
        String text = readAll(stream);
        conn.disconnect();
        return new JSONObject(text);
    }

    private String readAll(InputStream stream) throws Exception {
        BufferedReader reader = new BufferedReader(new InputStreamReader(stream, StandardCharsets.UTF_8));
        StringBuilder out = new StringBuilder();
        String line;
        while ((line = reader.readLine()) != null) {
            out.append(line);
        }
        return out.toString();
    }

    private String form(String... pairs) throws Exception {
        StringBuilder out = new StringBuilder();
        for (int i = 0; i < pairs.length; i += 2) {
            if (i > 0) {
                out.append('&');
            }
            out.append(URLEncoder.encode(pairs[i], "UTF-8"));
            out.append('=');
            out.append(URLEncoder.encode(pairs[i + 1], "UTF-8"));
        }
        return out.toString();
    }

    private boolean isWithinOperatingHours() {
        int now = minutes(new SimpleDateFormat("HH:mm", Locale.KOREA).format(new Date()));
        int start = minutes(prefs().getString(MainActivity.KEY_START, "10:00"));
        int end = minutes(prefs().getString(MainActivity.KEY_END, "20:00"));
        if (start == end) {
            return true;
        }
        if (start < end) {
            return now >= start && now < end;
        }
        return now >= start || now < end;
    }

    private boolean isWithinOperatingDays() {
        Calendar calendar = Calendar.getInstance(Locale.KOREA);
        int day = calendar.get(Calendar.DAY_OF_WEEK);
        String mode = prefs().getString(MainActivity.KEY_DAY_MODE, "weekday");
        if ("everyday".equals(mode)) {
            return true;
        }
        if ("mon_sat".equals(mode)) {
            return day != Calendar.SUNDAY;
        }
        return day != Calendar.SATURDAY && day != Calendar.SUNDAY;
    }

    private int minutes(String value) {
        try {
            String[] parts = value.split(":");
            return Integer.parseInt(parts[0]) * 60 + Integer.parseInt(parts[1]);
        } catch (Exception e) {
            return 0;
        }
    }

    private long intervalMillis() {
        int seconds = prefs().getInt(MainActivity.KEY_INTERVAL, 30);
        if (seconds < 15) {
            seconds = 15;
        }
        return seconds * 1000L;
    }

    private String baseUrl() {
        return prefs().getString(MainActivity.KEY_BASE_URL, "http://192.168.0.81");
    }

    private String deviceToken() throws Exception {
        String token = prefs().getString(MainActivity.KEY_DEVICE_TOKEN, "");
        if (token.length() == 0) {
            throw new Exception("SMS gateway is not connected");
        }
        return token;
    }

    private String deviceName() {
        return prefs().getString(MainActivity.KEY_DEVICE_NAME, "SMS phone 1");
    }

    private SharedPreferences prefs() {
        return getSharedPreferences(MainActivity.PREFS, MODE_PRIVATE);
    }

    private void updateStatus(String status) {
        lastStatus = status;
        Log.d(TAG, status);
        NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        manager.notify(NOTIFICATION_ID, notification("Ieum SMS Gateway", status));
    }

    private void createChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                    CHANNEL_ID,
                    "Ieum SMS Gateway",
                    NotificationManager.IMPORTANCE_LOW
            );
            NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
            manager.createNotificationChannel(channel);
        }
    }

    private void registerSentReceiver() {
        IntentFilter filter = new IntentFilter(ACTION_SMS_SENT);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(sentReceiver, filter, Context.RECEIVER_NOT_EXPORTED);
        } else {
            registerReceiver(sentReceiver, filter);
        }
        sentReceiverRegistered = true;
    }

    private Notification notification(String title, String text) {
        Notification.Builder builder = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                ? new Notification.Builder(this, CHANNEL_ID)
                : new Notification.Builder(this);
        return builder
                .setContentTitle(title)
                .setContentText(text)
                .setSmallIcon(android.R.drawable.stat_notify_sync)
                .setOngoing(running)
                .build();
    }

    private static class SmsItem {
        int smsId;
        String phone;
        String message;
    }

    private static class PendingSmsResult {
        final int totalParts;
        int completedParts;
        int failedParts;
        final List<String> errors = new ArrayList<>();

        PendingSmsResult(int totalParts) {
            this.totalParts = Math.max(1, totalParts);
        }

        synchronized boolean record(boolean ok, int part, String error) {
            completedParts++;
            if (!ok) {
                failedParts++;
                errors.add("part " + (part + 1) + ": " + error);
            }
            return completedParts >= totalParts;
        }

        synchronized String errorSummary() {
            if (errors.isEmpty()) {
                return "unknown_sms_error";
            }
            StringBuilder out = new StringBuilder();
            for (int i = 0; i < errors.size(); i++) {
                if (i > 0) {
                    out.append(", ");
                }
                out.append(errors.get(i));
            }
            return out.toString();
        }
    }
}
