package kr.ieum.smsgateway;

import android.Manifest;
import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.text.InputType;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

public class MainActivity extends Activity {
    static final String PREFS = "ieum_gateway";
    static final String KEY_BASE_URL = "base_url";
    static final String KEY_TOKEN = "token";
    static final String KEY_DEVICE = "device";
    static final String KEY_START = "start_time";
    static final String KEY_END = "end_time";
    static final String KEY_INTERVAL = "interval_seconds";

    private static final int REQ_PERMISSIONS = 1001;

    private EditText baseUrlInput;
    private EditText tokenInput;
    private EditText deviceInput;
    private EditText startInput;
    private EditText endInput;
    private EditText intervalInput;
    private TextView statusText;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        buildUi();
        loadPrefs();
        requestNeededPermissions();
    }

    private void buildUi() {
        ScrollView scroll = new ScrollView(this);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(36, 36, 36, 36);
        scroll.addView(root);

        TextView title = new TextView(this);
        title.setText("아이이음 문자 게이트웨이");
        title.setTextSize(24);
        title.setTextColor(0xff111827);
        title.setPadding(0, 0, 0, 20);
        root.addView(title);

        baseUrlInput = input("서버 주소", "http://192.168.0.81");
        tokenInput = input("도장 API 토큰", "ieum-local-gateway-token-2026");
        deviceInput = input("기기명", "android-gateway");
        startInput = input("운영 시작 시간 HH:mm", "10:00");
        endInput = input("운영 종료 시간 HH:mm", "20:00");
        intervalInput = input("확인 주기 초", "30");
        intervalInput.setInputType(InputType.TYPE_CLASS_NUMBER);

        root.addView(baseUrlInput);
        root.addView(tokenInput);
        root.addView(deviceInput);
        root.addView(startInput);
        root.addView(endInput);
        root.addView(intervalInput);

        LinearLayout buttons = new LinearLayout(this);
        buttons.setOrientation(LinearLayout.HORIZONTAL);
        buttons.setPadding(0, 20, 0, 20);
        root.addView(buttons);

        Button startButton = button("서비스 시작");
        Button stopButton = button("서비스 중지");
        Button onceButton = button("1회 실행");
        buttons.addView(startButton);
        buttons.addView(stopButton);
        buttons.addView(onceButton);

        statusText = new TextView(this);
        statusText.setTextSize(16);
        statusText.setTextColor(0xff344054);
        statusText.setText("대기 중");
        statusText.setPadding(0, 8, 0, 0);
        root.addView(statusText);

        startButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                savePrefs();
                requestNeededPermissions();
                Intent intent = new Intent(MainActivity.this, GatewayService.class);
                intent.setAction(GatewayService.ACTION_START);
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    startForegroundService(intent);
                } else {
                    startService(intent);
                }
                statusText.setText("서비스 시작 요청 완료");
            }
        });

        stopButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                Intent intent = new Intent(MainActivity.this, GatewayService.class);
                intent.setAction(GatewayService.ACTION_STOP);
                startService(intent);
                statusText.setText("서비스 중지 요청 완료");
            }
        });

        onceButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                savePrefs();
                requestNeededPermissions();
                Intent intent = new Intent(MainActivity.this, GatewayService.class);
                intent.setAction(GatewayService.ACTION_RUN_ONCE);
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    startForegroundService(intent);
                } else {
                    startService(intent);
                }
                statusText.setText("1회 실행 요청 완료");
            }
        });

        setContentView(scroll);
    }

    private EditText input(String hint, String defaultValue) {
        EditText editText = new EditText(this);
        editText.setHint(hint);
        editText.setText(defaultValue);
        editText.setSingleLine(true);
        editText.setInputType(InputType.TYPE_CLASS_TEXT);
        editText.setPadding(0, 10, 0, 10);
        return editText;
    }

    private Button button(String text) {
        Button button = new Button(this);
        button.setText(text);
        button.setAllCaps(false);
        return button;
    }

    private void loadPrefs() {
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        baseUrlInput.setText(prefs.getString(KEY_BASE_URL, baseUrlInput.getText().toString()));
        tokenInput.setText(prefs.getString(KEY_TOKEN, tokenInput.getText().toString()));
        deviceInput.setText(prefs.getString(KEY_DEVICE, deviceInput.getText().toString()));
        startInput.setText(prefs.getString(KEY_START, startInput.getText().toString()));
        endInput.setText(prefs.getString(KEY_END, endInput.getText().toString()));
        intervalInput.setText(String.valueOf(prefs.getInt(KEY_INTERVAL, 30)));
    }

    private void savePrefs() {
        int interval = parseInt(intervalInput.getText().toString(), 30);
        if (interval < 15) {
            interval = 15;
        }
        getSharedPreferences(PREFS, MODE_PRIVATE)
                .edit()
                .putString(KEY_BASE_URL, trimTrailingSlash(baseUrlInput.getText().toString().trim()))
                .putString(KEY_TOKEN, tokenInput.getText().toString().trim())
                .putString(KEY_DEVICE, deviceInput.getText().toString().trim())
                .putString(KEY_START, startInput.getText().toString().trim())
                .putString(KEY_END, endInput.getText().toString().trim())
                .putInt(KEY_INTERVAL, interval)
                .apply();
    }

    private String trimTrailingSlash(String value) {
        while (value.endsWith("/")) {
            value = value.substring(0, value.length() - 1);
        }
        return value;
    }

    private int parseInt(String value, int fallback) {
        try {
            return Integer.parseInt(value);
        } catch (Exception e) {
            return fallback;
        }
    }

    private void requestNeededPermissions() {
        if (Build.VERSION.SDK_INT >= 33) {
            requestPermissions(new String[]{
                    Manifest.permission.SEND_SMS,
                    Manifest.permission.POST_NOTIFICATIONS
            }, REQ_PERMISSIONS);
        } else if (checkSelfPermission(Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.SEND_SMS}, REQ_PERMISSIONS);
        }
    }
}
