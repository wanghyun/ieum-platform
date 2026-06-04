package kr.ieum.smsgateway;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.text.InputType;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;

public class MainActivity extends Activity {
    static final String PREFS = "ieum_gateway";
    static final String KEY_BASE_URL = "base_url";
    static final String KEY_DEVICE_TOKEN = "device_token";
    static final String KEY_DEVICE_NAME = "device_name";
    static final String KEY_ACADEMY_NAME = "academy_name";
    static final String KEY_START = "start_time";
    static final String KEY_END = "end_time";
    static final String KEY_DAY_MODE = "day_mode";
    static final String KEY_INTERVAL = "interval_seconds";

    private static final int REQ_PERMISSIONS = 1001;
    private static final String APP_VERSION = "1.2.0";

    private EditText baseUrlInput;
    private EditText pairingCodeInput;
    private EditText deviceNameInput;
    private TextView academyText;
    private TextView statusText;
    private TextView scheduleText;
    private LinearLayout connectPanel;
    private Button startButton;
    private Button stopButton;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        buildUi();
        loadPrefs();
        requestNeededPermissions();
    }

    private void buildUi() {
        ScrollView scroll = new ScrollView(this);
        scroll.setBackgroundColor(0xfff3f6fb);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(18), dp(18), dp(18), dp(18));
        scroll.addView(root);

        TextView title = text("아이이음 문자 게이트웨이", 24, 0xff101828, true);
        root.addView(title);
        TextView subtitle = text("연결된 도장의 문자 큐를 정해진 시간에 자동으로 발송합니다.", 14, 0xff667085, false);
        subtitle.setPadding(0, dp(4), 0, dp(14));
        root.addView(subtitle);

        LinearLayout academyCard = card();
        academyText = text("도장 연결 필요", 20, 0xff1849a9, true);
        scheduleText = text("자동 발송 설정을 불러오는 중입니다.", 14, 0xff344054, false);
        scheduleText.setPadding(0, dp(8), 0, 0);
        academyCard.addView(academyText);
        academyCard.addView(scheduleText);
        root.addView(academyCard);

        connectPanel = card();
        connectPanel.addView(sectionTitle("도장 연결"));
        baseUrlInput = input("서버 주소", "http://192.168.0.81", InputType.TYPE_CLASS_TEXT);
        pairingCodeInput = input("연결 코드 6자리", "", InputType.TYPE_CLASS_NUMBER);
        deviceNameInput = input("기기명", "문자폰 1", InputType.TYPE_CLASS_TEXT);
        connectPanel.addView(baseUrlInput);
        connectPanel.addView(pairingCodeInput);
        connectPanel.addView(deviceNameInput);
        Button connectButton = button("도장 연결", true);
        connectPanel.addView(connectButton);
        root.addView(connectPanel);

        LinearLayout controlCard = card();
        controlCard.addView(sectionTitle("자동 발송"));
        TextView controlHint = text("시작하면 앱이 백그라운드에서 문자 대기열을 확인합니다. 요일과 시간은 도장 관리자 웹에서 변경합니다.", 14, 0xff667085, false);
        controlHint.setPadding(0, 0, 0, dp(12));
        controlCard.addView(controlHint);

        startButton = button("자동 발송 시작", true);
        stopButton = button("중지", false);
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setPadding(0, 0, 0, dp(10));
        row.addView(startButton, new LinearLayout.LayoutParams(0, dp(52), 2));
        LinearLayout.LayoutParams stopParams = new LinearLayout.LayoutParams(0, dp(52), 1);
        stopParams.setMargins(dp(10), 0, 0, 0);
        row.addView(stopButton, stopParams);
        controlCard.addView(row);

        Button resetButton = button("연결 초기화", false);
        controlCard.addView(resetButton);
        root.addView(controlCard);

        statusText = text("", 15, 0xff344054, false);
        statusText.setPadding(dp(4), dp(12), dp(4), dp(4));
        root.addView(statusText);

        connectButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                connectDevice();
            }
        });

        startButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                runService(GatewayService.ACTION_START, "자동 발송을 시작했습니다.");
            }
        });

        stopButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                Intent intent = new Intent(MainActivity.this, GatewayService.class);
                intent.setAction(GatewayService.ACTION_STOP);
                startService(intent);
                statusText.setText("자동 발송을 중지했습니다.");
            }
        });

        resetButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                getSharedPreferences(PREFS, MODE_PRIVATE).edit()
                        .remove(KEY_DEVICE_TOKEN)
                        .remove(KEY_ACADEMY_NAME)
                        .remove(KEY_DAY_MODE)
                        .apply();
                loadPrefs();
                statusText.setText("연결 정보를 초기화했습니다. 새 연결 코드를 입력해 주세요.");
            }
        });

        setContentView(scroll);
    }

    private LinearLayout card() {
        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setPadding(dp(18), dp(18), dp(18), dp(18));
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(Color.WHITE);
        bg.setCornerRadius(dp(18));
        bg.setStroke(dp(1), 0xffd7deea);
        layout.setBackground(bg);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
        );
        params.setMargins(0, 0, 0, dp(14));
        layout.setLayoutParams(params);
        return layout;
    }

    private TextView sectionTitle(String value) {
        TextView title = text(value, 17, 0xff101828, true);
        title.setPadding(0, 0, 0, dp(12));
        return title;
    }

    private TextView text(String value, int sp, int color, boolean bold) {
        TextView text = new TextView(this);
        text.setText(value);
        text.setTextSize(sp);
        text.setTextColor(color);
        if (bold) {
            text.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        }
        return text;
    }

    private EditText input(String hint, String defaultValue, int inputType) {
        EditText editText = new EditText(this);
        editText.setHint(hint);
        editText.setText(defaultValue);
        editText.setSingleLine(true);
        editText.setInputType(inputType);
        editText.setTextSize(16);
        editText.setPadding(dp(14), 0, dp(14), 0);
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(0xfffbfcfe);
        bg.setCornerRadius(dp(12));
        bg.setStroke(dp(1), 0xffcfd6df);
        editText.setBackground(bg);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dp(50)
        );
        params.setMargins(0, 0, 0, dp(10));
        editText.setLayoutParams(params);
        return editText;
    }

    private Button button(String text, boolean primary) {
        Button button = new Button(this);
        button.setText(text);
        button.setAllCaps(false);
        button.setTextSize(16);
        button.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        button.setTextColor(primary ? Color.WHITE : 0xff111827);
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(primary ? 0xff1d4ed8 : 0xffffffff);
        bg.setCornerRadius(dp(14));
        bg.setStroke(dp(1), primary ? 0xff1d4ed8 : 0xffcfd6df);
        button.setBackground(bg);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dp(52)
        );
        params.setMargins(0, 0, 0, dp(10));
        button.setLayoutParams(params);
        return button;
    }

    private void loadPrefs() {
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        baseUrlInput.setText(prefs.getString(KEY_BASE_URL, baseUrlInput.getText().toString()));
        deviceNameInput.setText(prefs.getString(KEY_DEVICE_NAME, deviceNameInput.getText().toString()));
        String academy = prefs.getString(KEY_ACADEMY_NAME, "");
        String token = prefs.getString(KEY_DEVICE_TOKEN, "");
        String dayMode = prefs.getString(KEY_DAY_MODE, "weekday");
        String start = prefs.getString(KEY_START, "10:00");
        String end = prefs.getString(KEY_END, "20:00");
        scheduleText.setText(dayModeLabel(dayMode) + " · " + start + " ~ " + end + "\n설정은 도장 관리자 웹에서 변경됩니다.");
        if (token.length() > 0 && academy.length() > 0) {
            academyText.setText(academy);
            connectPanel.setVisibility(View.GONE);
            statusText.setText("연결 완료. 자동 발송 시작 버튼을 누르면 운영 시간에 맞춰 동작합니다.");
        } else {
            academyText.setText("도장 연결 필요");
            connectPanel.setVisibility(View.VISIBLE);
            statusText.setText("관리자 웹에서 연결 코드를 만든 뒤 입력해 주세요.");
        }
    }

    private String dayModeLabel(String mode) {
        if ("everyday".equals(mode)) {
            return "매일";
        }
        if ("mon_sat".equals(mode)) {
            return "월~토";
        }
        return "평일(월~금)";
    }

    private void connectDevice() {
        saveBasicPrefs();
        final String code = pairingCodeInput.getText().toString().trim();
        if (code.length() < 4) {
            statusText.setText("연결 코드를 입력해 주세요.");
            return;
        }
        statusText.setText("도장을 연결하는 중입니다.");
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    String body = form(
                            "pairing_code", code,
                            "device_name", deviceNameInput.getText().toString().trim(),
                            "device_model", Build.MANUFACTURER + " " + Build.MODEL,
                            "app_version", APP_VERSION
                    );
                    JSONObject json = postJson(baseUrl() + "/ieum/api/sms/register_device.php", body);
                    if (!json.optBoolean("ok")) {
                        throw new Exception(json.optString("message", "연결에 실패했습니다."));
                    }
                    JSONObject data = json.getJSONObject("data");
                    saveRemoteSettings(data);
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            pairingCodeInput.setText("");
                            loadPrefs();
                        }
                    });
                } catch (final Exception e) {
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            statusText.setText("연결 오류: " + e.getMessage());
                        }
                    });
                }
            }
        }).start();
    }

    private void saveRemoteSettings(JSONObject data) {
        getSharedPreferences(PREFS, MODE_PRIVATE).edit()
                .putString(KEY_DEVICE_TOKEN, data.optString("device_token", ""))
                .putString(KEY_DEVICE_NAME, data.optString("device_name", deviceNameInput.getText().toString().trim()))
                .putString(KEY_ACADEMY_NAME, data.optString("academy_name", ""))
                .putString(KEY_START, data.optString("sms_start_time", "10:00"))
                .putString(KEY_END, data.optString("sms_end_time", "20:00"))
                .putString(KEY_DAY_MODE, data.optString("sms_day_mode", "weekday"))
                .putInt(KEY_INTERVAL, data.optInt("sms_poll_seconds", 30))
                .apply();
    }

    private void runService(String action, String message) {
        saveBasicPrefs();
        if (!isConnected()) {
            statusText.setText("먼저 도장 연결을 완료해 주세요.");
            return;
        }
        requestNeededPermissions();
        Intent intent = new Intent(MainActivity.this, GatewayService.class);
        intent.setAction(action);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(intent);
        } else {
            startService(intent);
        }
        statusText.setText(message);
    }

    private boolean isConnected() {
        return getSharedPreferences(PREFS, MODE_PRIVATE)
                .getString(KEY_DEVICE_TOKEN, "")
                .length() > 0;
    }

    private void saveBasicPrefs() {
        getSharedPreferences(PREFS, MODE_PRIVATE)
                .edit()
                .putString(KEY_BASE_URL, trimTrailingSlash(baseUrlInput.getText().toString().trim()))
                .putString(KEY_DEVICE_NAME, deviceNameInput.getText().toString().trim())
                .apply();
    }

    private String baseUrl() {
        return trimTrailingSlash(baseUrlInput.getText().toString().trim());
    }

    private String trimTrailingSlash(String value) {
        while (value.endsWith("/")) {
            value = value.substring(0, value.length() - 1);
        }
        return value;
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

    private void requestNeededPermissions() {
        if (Build.VERSION.SDK_INT >= 33) {
            requestPermissions(new String[]{
                    Manifest.permission.SEND_SMS,
                    Manifest.permission.POST_NOTIFICATIONS
            }, REQ_PERMISSIONS);
        } else if (Build.VERSION.SDK_INT >= 23
                && checkSelfPermission(Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.SEND_SMS}, REQ_PERMISSIONS);
        }
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density + 0.5f);
    }
}
