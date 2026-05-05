package kr.ieum.smsgateway;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.telephony.SmsManager;

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
import java.util.Date;
import java.util.List;
import java.util.Locale;

public class GatewayService extends Service {
    static final String ACTION_START = "kr.ieum.smsgateway.START";
    static final String ACTION_STOP = "kr.ieum.smsgateway.STOP";
    static final String ACTION_RUN_ONCE = "kr.ieum.smsgateway.RUN_ONCE";

    private static final String CHANNEL_ID = "ieum_sms_gateway";
    private static final int NOTIFICATION_ID = 240504;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private boolean running = false;
    private String lastStatus = "대기 중";

    private final Runnable loop = new Runnable() {
        @Override
        public void run() {
            if (!running) {
                return;
            }
            if (isWithinOperatingHours()) {
                syncOnce();
            } else {
                updateStatus("운영 시간 밖 대기 중");
            }
            handler.postDelayed(this, intervalMillis());
        }
    };

    @Override
    public void onCreate() {
        super.onCreate();
        createChannel();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent != null ? intent.getAction() : ACTION_START;
        startForeground(NOTIFICATION_ID, notification("아이이음 문자 게이트웨이", lastStatus));

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
        updateStatus("서비스 실행 중");
        return START_STICKY;
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private void syncOnce() {
        updateStatus("문자 큐 확인 중");
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    List<SmsItem> items = claimMessages();
                    if (items.isEmpty()) {
                        updateStatus("대기 문자 없음");
                        return;
                    }
                    for (SmsItem item : items) {
                        sendOne(item);
                    }
                } catch (Exception e) {
                    updateStatus("오류: " + e.getMessage());
                }
            }
        }).start();
    }

    private List<SmsItem> claimMessages() throws Exception {
        String body = form(
                "token", token(),
                "device", device(),
                "limit", "10"
        );
        JSONObject json = postJson(baseUrl() + "/ieum/api/sms/claim.php", body);
        if (!json.optBoolean("ok")) {
            throw new Exception(json.optString("message"));
        }

        JSONArray arr = json.getJSONObject("data").getJSONArray("items");
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
            SmsManager.getDefault().sendTextMessage(item.phone, null, item.message, null, null);
            updateResult(item.smsId, "sent", "");
            updateStatus("발송 완료 #" + item.smsId);
        } catch (Exception e) {
            try {
                updateResult(item.smsId, "failed", e.getMessage());
            } catch (Exception ignored) {
            }
            updateStatus("발송 실패 #" + item.smsId + ": " + e.getMessage());
        }
    }

    private void updateResult(int smsId, String status, String errorMessage) throws Exception {
        String body = form(
                "token", token(),
                "sms_id", String.valueOf(smsId),
                "status", status,
                "device", device(),
                "error_message", errorMessage == null ? "" : errorMessage
        );
        JSONObject json = postJson(baseUrl() + "/ieum/api/sms/update.php", body);
        if (!json.optBoolean("ok")) {
            throw new Exception(json.optString("message"));
        }
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

    private String token() {
        return prefs().getString(MainActivity.KEY_TOKEN, "ieum-local-gateway-token-2026");
    }

    private String device() {
        return prefs().getString(MainActivity.KEY_DEVICE, "android-gateway");
    }

    private SharedPreferences prefs() {
        return getSharedPreferences(MainActivity.PREFS, MODE_PRIVATE);
    }

    private void updateStatus(String status) {
        lastStatus = status;
        NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        manager.notify(NOTIFICATION_ID, notification("아이이음 문자 게이트웨이", status));
    }

    private void createChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                    CHANNEL_ID,
                    "아이이음 문자 게이트웨이",
                    NotificationManager.IMPORTANCE_LOW
            );
            NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
            manager.createNotificationChannel(channel);
        }
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
}
