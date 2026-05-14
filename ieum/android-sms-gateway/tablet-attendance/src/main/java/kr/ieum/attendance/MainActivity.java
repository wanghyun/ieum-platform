package kr.ieum.attendance;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.content.res.Configuration;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.os.Bundle;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import com.google.zxing.integration.android.IntentIntegrator;
import com.google.zxing.integration.android.IntentResult;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.UUID;

public class MainActivity extends Activity {
    private static final String PREFS = "ieum_tablet_attendance";
    private static final String KEY_BASE_URL = "base_url";
    private static final String KEY_TOKEN = "device_token";
    private static final String KEY_DEVICE = "device_name";
    private static final String KEY_DEVICE_UID = "device_uid";
    private static final String KEY_ACADEMY = "academy_name";
    private static final String KEY_ACADEMY_CODE = "academy_code";
    private static final String KEY_TABLET_PIN = "tablet_pin";
    private static final String KEY_ADMIN_PIN = "admin_pin";
    private static final int REQ_CAMERA_PERMISSION = 4101;
    private static final int BLUE = Color.rgb(35, 76, 185);
    private static final int CHARCOAL = Color.rgb(44, 42, 37);

    private EditText codeInput;
    private EditText baseUrlInput;
    private EditText academyCodeInput;
    private EditText tabletPinInput;
    private EditText pairingInput;
    private EditText deviceInput;
    private LinearLayout resultPanel;
    private LinearLayout keypad;
    private boolean busy = false;
    private int selectedStudentId = 0;
    private String deviceToken = "";
    private String academyName = "";
    private String academyCode = "";
    private String tabletPin = "";
    private String deviceUid = "";
    private String pendingCode = "";
    private JSONObject lastResultData = null;
    private String lastMessageTitle = "";
    private String lastMessageBody = "";
    private boolean lastMessageError = false;
    private boolean clearInputOnNextDigit = false;
    private final Handler uiHandler = new Handler(Looper.getMainLooper());
    private final Runnable returnToIdleRunnable = new Runnable() {
        @Override
        public void run() {
            lastResultData = null;
            clearInputOnNextDigit = false;
            selectedStudentId = 0;
            if (codeInput != null && codeInput.getText().toString().trim().length() == 0 && !busy) {
                showIdle();
            }
        }
    };
    private final Runnable immersiveRunnable = new Runnable() {
        @Override
        public void run() {
            hideSystemUi();
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        requestWindowFeature(Window.FEATURE_NO_TITLE);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        installImmersiveMode();
        ensureDeviceUid();
        loadStoredConnection();
        buildUi();
    }

    @Override
    protected void onResume() {
        super.onResume();
        hideSystemUi();
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) {
            hideSystemUi();
        }
    }

    @Override
    public void onBackPressed() {
        hideSystemUi();
        if (busy) {
            return;
        }
        if (codeInput != null && codeInput.getText().toString().length() > 0) {
            codeInput.setText("");
            pendingCode = "";
            selectedStudentId = 0;
            showIdle();
            return;
        }
        if (lastResultData != null || lastMessageTitle.length() > 0) {
            lastResultData = null;
            lastMessageTitle = "";
            lastMessageBody = "";
            lastMessageError = false;
            showIdle();
        }
    }

    @Override
    protected void onDestroy() {
        uiHandler.removeCallbacks(returnToIdleRunnable);
        uiHandler.removeCallbacks(immersiveRunnable);
        super.onDestroy();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        IntentResult result = IntentIntegrator.parseActivityResult(requestCode, resultCode, data);
        if (result != null) {
            if (result.getContents() != null) {
                handlePairingQr(result.getContents());
            }
            return;
        }
        super.onActivityResult(requestCode, resultCode, data);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == REQ_CAMERA_PERMISSION) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                openPairingQrScanner();
            } else {
                showMessage("카메라 권한 필요", "QR 연결을 사용하려면 카메라 권한을 허용해 주세요.", true);
            }
        }
    }

    @Override
    public void onConfigurationChanged(Configuration newConfig) {
        super.onConfigurationChanged(newConfig);
        rememberInput();
        buildUi();
    }

    private void buildUi() {
        hideSystemUi();
        boolean landscape = isLandscape();
        boolean phone = isPhone();
        boolean largeTablet = isLargeTablet();

        int outerPadding = phone ? 8 : 10;
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(landscape ? LinearLayout.HORIZONTAL : LinearLayout.VERTICAL);
        root.setPadding(dp(outerPadding), dp(outerPadding), dp(outerPadding), dp(outerPadding));
        root.setBackgroundColor(Color.rgb(16, 24, 40));

        LinearLayout inputPanel = panel();
        inputPanel.setPadding(dp(phone ? 12 : 16), dp(phone ? 10 : 14), dp(phone ? 12 : 16), dp(phone ? 10 : 12));
        int inputWeight = landscape ? (largeTablet ? 52 : (phone ? 62 : 56)) : (phone ? 68 : 62);
        int resultWeight = 100 - inputWeight;
        LinearLayout.LayoutParams inputParams = landscape
                ? new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, inputWeight)
                : new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, inputWeight);
        root.addView(inputPanel, inputParams);

        resultPanel = panel();
        resultPanel.setGravity(Gravity.CENTER);
        resultPanel.setPadding(dp(phone ? 12 : 16), dp(phone ? 12 : 16), dp(phone ? 12 : 16), dp(phone ? 12 : 16));
        LinearLayout.LayoutParams resultParams = landscape
                ? new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, resultWeight)
                : new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, resultWeight);
        if (landscape) {
            resultParams.leftMargin = dp(10);
        } else {
            resultParams.topMargin = dp(8);
        }
        root.addView(resultPanel, resultParams);

        TextView title = text(academyName.length() > 0 ? academyName : "아이이음출석기", landscape ? (phone ? 22 : 28) : (phone ? 25 : 26), Color.rgb(7, 17, 36), true);
        title.setOnLongClickListener(new View.OnLongClickListener() {
            @Override
            public boolean onLongClick(View view) {
                requestAdminPin();
                return true;
            }
        });
        inputPanel.addView(title);

        TextView guide = text("학생번호를 입력하고 등원 처리하세요.", landscape ? 14 : 13, Color.rgb(71, 84, 103), false);
        guide.setPadding(0, dp(4), 0, dp(landscape ? (phone ? 7 : 10) : 6));
        inputPanel.addView(guide);

        codeInput = new EditText(this);
        codeInput.setSingleLine(true);
        codeInput.setTextSize(landscape ? (phone ? 25 : 30) : 26);
        codeInput.setGravity(Gravity.CENTER);
        codeInput.setInputType(InputType.TYPE_CLASS_NUMBER);
        codeInput.setBackgroundColor(Color.WHITE);
        codeInput.setText(pendingCode);
        codeInput.setSelection(codeInput.length());
        inputPanel.addView(codeInput, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, landscape ? dp(phone ? 48 : 58) : dp(58)));

        keypad = new LinearLayout(this);
        keypad.setOrientation(LinearLayout.VERTICAL);
        LinearLayout.LayoutParams keypadParams = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 1);
        keypadParams.topMargin = dp(phone ? 6 : 8);
        inputPanel.addView(keypad, keypadParams);
        addKeyRows(landscape, phone);

        baseUrlInput = smallInput("http://192.168.0.81");
        academyCodeInput = smallInput("IEUMTKD001");
        tabletPinInput = smallInput("110022");
        tabletPinInput.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        pairingInput = smallInput("");
        pairingInput.setInputType(InputType.TYPE_CLASS_NUMBER);
        deviceInput = smallInput("입구 태블릿");
        loadPrefs();

        setContentView(root);
        restoreResultPanel();
    }

    private void addKeyRows(boolean landscape, boolean phone) {
        String[][] rows = {
                {"1", "2", "3"},
                {"4", "5", "6"},
                {"7", "8", "9"},
                {"지움", "0", "←"},
                {"등원", "초기화"}
        };
        for (String[] row : rows) {
            LinearLayout line = new LinearLayout(this);
            line.setOrientation(LinearLayout.HORIZONTAL);
            LinearLayout.LayoutParams lineParams = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 1);
            lineParams.topMargin = dp(phone ? 4 : 5);
            keypad.addView(line, lineParams);
            for (String label : row) {
                Button b = keyButton(label, landscape, phone);
                LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, "등원".equals(label) ? 2 : 1);
                params.leftMargin = dp(phone ? 2 : 3);
                params.rightMargin = dp(phone ? 2 : 3);
                line.addView(b, params);
            }
        }
    }

    private Button keyButton(final String label, boolean landscape, boolean phone) {
        Button b = new Button(this);
        b.setText(label);
        b.setTextSize(landscape ? (phone ? 21 : 25) : 23);
        b.setAllCaps(false);
        b.setTextColor(Color.rgb(2, 6, 23));
        if ("등원".equals(label)) {
            b.setTextColor(Color.WHITE);
            b.setBackgroundColor(BLUE);
        }
        b.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                handleKey(label);
            }
        });
        return b;
    }

    private void handleKey(String label) {
        if ("등원".equals(label)) {
            submit();
        } else if ("초기화".equals(label) || "지움".equals(label)) {
            cancelIdleTimer();
            codeInput.setText("");
            pendingCode = "";
            selectedStudentId = 0;
            lastResultData = null;
            clearInputOnNextDigit = false;
            showIdle();
        } else if ("←".equals(label)) {
            cancelIdleTimer();
            String value = codeInput.getText().toString();
            if (value.length() > 0) {
                codeInput.setText(value.substring(0, value.length() - 1));
                codeInput.setSelection(codeInput.length());
                pendingCode = codeInput.getText().toString();
            }
            clearInputOnNextDigit = false;
        } else {
            cancelIdleTimer();
            if (clearInputOnNextDigit) {
                codeInput.setText("");
                pendingCode = "";
                selectedStudentId = 0;
                clearInputOnNextDigit = false;
            }
            codeInput.append(label);
            pendingCode = codeInput.getText().toString();
        }
    }

    private void requestAdminPin() {
        final EditText pin = new EditText(this);
        pin.setSingleLine(true);
        pin.setGravity(Gravity.CENTER);
        pin.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        pin.setHint("관리자 PIN");
        new AlertDialog.Builder(this)
                .setTitle("관리자 설정")
                .setMessage("출석기 설정을 변경하려면 PIN을 입력하세요.")
                .setView(pin)
                .setNegativeButton("취소", null)
                .setPositiveButton("확인", (dialog, which) -> {
                    String saved = getSharedPreferences(PREFS, MODE_PRIVATE).getString(KEY_ADMIN_PIN, "110022");
                    if (saved.equals(pin.getText().toString().trim())) {
                        showSettingsDialog();
                    } else {
                        showMessage("PIN이 맞지 않습니다.", "관리자에게 확인해 주세요.", true);
                    }
                })
                .show();
    }

    private void showSettingsDialog() {
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        LinearLayout form = new LinearLayout(this);
        form.setOrientation(LinearLayout.VERTICAL);
        form.setPadding(dp(14), dp(8), dp(14), dp(6));

        LinearLayout statusCard = new LinearLayout(this);
        statusCard.setOrientation(LinearLayout.VERTICAL);
        statusCard.setPadding(dp(12), dp(10), dp(12), dp(10));
        statusCard.setBackgroundColor(deviceToken.length() > 0 ? Color.rgb(234, 247, 239) : Color.rgb(255, 246, 219));
        form.addView(statusCard, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT));

        TextView statusTitle = text(deviceToken.length() > 0 ? "연결됨" : "연결 필요", 17, deviceToken.length() > 0 ? Color.rgb(15, 122, 58) : Color.rgb(148, 98, 0), true);
        statusCard.addView(statusTitle);
        statusCard.addView(settingValue("도장", academyName.length() > 0 ? academyName : "아직 연결된 도장이 없습니다."));
        statusCard.addView(settingValue("기기", prefs.getString(KEY_DEVICE, "입구 태블릿")));
        statusCard.addView(settingValue("서버", prefs.getString(KEY_BASE_URL, "http://192.168.0.81")));

        final EditText server = dialogInput("서버 주소", prefs.getString(KEY_BASE_URL, "http://192.168.0.81"), InputType.TYPE_CLASS_TEXT);
        final EditText academyCodeField = dialogInput("도장 코드", prefs.getString(KEY_ACADEMY_CODE, "IEUMTKD001"), InputType.TYPE_CLASS_TEXT);
        final EditText tabletPinField = dialogInput("출석기 PIN", prefs.getString(KEY_TABLET_PIN, "110022"), InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        final EditText pairing = dialogInput("연결 코드 6자리", "", InputType.TYPE_CLASS_NUMBER);
        final EditText device = dialogInput("기기 이름", prefs.getString(KEY_DEVICE, "입구 태블릿"), InputType.TYPE_CLASS_TEXT);
        final EditText newPin = dialogInput("관리자 PIN 변경(선택)", "", InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        form.addView(settingLabel("기기 이름", device, "예: 입구 태블릿, 2층 출석기"));
        form.addView(settingLabel("서버 주소", server, "로컬 테스트는 http://192.168.0.81/ieum 형식입니다."));
        form.addView(settingLabel("도장 코드", academyCodeField, "도장 관리자 화면의 도장 코드와 일치해야 합니다."));
        form.addView(settingLabel("출석기 PIN", tabletPinField, "아이들이 알 수 없게 관리하세요."));
        form.addView(settingLabel("연결 코드", pairing, "QR 스캔을 쓰면 자동 입력됩니다."));
        form.addView(settingLabel("관리자 PIN 변경", newPin, "비워두면 기존 PIN을 유지합니다."));

        Button clearConnection = smallButton("현재 연결 해제");
        clearConnection.setTextSize(14);
        clearConnection.setBackgroundColor(Color.rgb(164, 38, 44));
        LinearLayout.LayoutParams clearParams = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(44));
        clearParams.topMargin = dp(12);
        form.addView(clearConnection, clearParams);

        android.widget.ScrollView scroll = new android.widget.ScrollView(this);
        scroll.addView(form);

        final AlertDialog[] dialogRef = new AlertDialog[1];

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle("출석기 설정")
                .setMessage("QR 재연결은 도장 관리자 화면의 출석기 관리에서 연결 코드를 만든 뒤 진행하세요.")
                .setView(scroll)
                .setNeutralButton("QR 스캔", (settingDialog, which) -> {
                    baseUrlInput.setText(normalizeBaseUrl(server.getText().toString().trim()));
                    academyCodeInput.setText(academyCodeField.getText().toString().trim());
                    tabletPinInput.setText(tabletPinField.getText().toString().trim());
                    deviceInput.setText(device.getText().toString().trim());
                    saveBasePrefs();
                    startPairingQrScan();
                })
                .setNegativeButton("닫기", null)
                .setPositiveButton("저장/연결", (settingDialog, which) -> {
                    baseUrlInput.setText(normalizeBaseUrl(server.getText().toString().trim()));
                    academyCodeInput.setText(academyCodeField.getText().toString().trim());
                    tabletPinInput.setText(tabletPinField.getText().toString().trim());
                    pairingInput.setText(pairing.getText().toString().trim());
                    deviceInput.setText(device.getText().toString().trim());
                    String pin = newPin.getText().toString().trim();
                    if (pin.length() >= 4) {
                        getSharedPreferences(PREFS, MODE_PRIVATE).edit().putString(KEY_ADMIN_PIN, pin).apply();
                    }
                    if (pairingInput.getText().toString().trim().length() == 6) {
                        registerDevice();
                    } else {
                        saveBasePrefs();
                        showIdle();
                    }
                })
                .create();
        dialogRef[0] = dialog;
        clearConnection.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                new AlertDialog.Builder(MainActivity.this)
                        .setTitle("연결을 해제할까요?")
                        .setMessage("이 태블릿의 저장된 도장 연결 정보가 삭제됩니다. 다시 사용하려면 QR로 재연결해야 합니다.")
                        .setNegativeButton("취소", null)
                        .setPositiveButton("해제", (confirmDialog, which) -> {
                            clearConnectionInfo();
                            if (dialogRef[0] != null) {
                                dialogRef[0].dismiss();
                            }
                            showUnpairedMessage();
                        })
                        .show();
            }
        });
        dialog.show();
    }

    private LinearLayout settingLabel(String label, EditText input, String hint) {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        LinearLayout.LayoutParams boxParams = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        boxParams.topMargin = dp(10);
        box.setLayoutParams(boxParams);
        TextView title = text(label, 13, Color.rgb(52, 64, 84), true);
        box.addView(title);
        box.addView(input, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(44)));
        TextView help = text(hint, 11, Color.rgb(102, 112, 133), false);
        help.setPadding(0, dp(3), 0, 0);
        box.addView(help);
        return box;
    }

    private TextView settingValue(String label, String value) {
        TextView row = text(label + "  " + value, 13, Color.rgb(52, 64, 84), false);
        row.setPadding(0, dp(4), 0, 0);
        return row;
    }

    private void clearConnectionInfo() {
        deviceToken = "";
        academyName = "";
        pairingInput.setText("");
        getSharedPreferences(PREFS, MODE_PRIVATE)
                .edit()
                .remove(KEY_TOKEN)
                .remove(KEY_ACADEMY)
                .apply();
        lastResultData = null;
        lastMessageTitle = "";
        lastMessageBody = "";
        clearInputOnNextDigit = true;
    }

    private void startPairingQrScan() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M
                && checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.CAMERA}, REQ_CAMERA_PERMISSION);
            return;
        }
        openPairingQrScanner();
    }

    private void openPairingQrScanner() {
        IntentIntegrator integrator = new IntentIntegrator(this);
        integrator.setCaptureActivity(QrScanActivity.class);
        integrator.setPrompt("관리자 화면의 출석기 연결 QR을 스캔하세요.");
        integrator.setBeepEnabled(false);
        integrator.setOrientationLocked(false);
        integrator.initiateScan();
    }

    private void handlePairingQr(String contents) {
        try {
            Uri uri = Uri.parse(contents);
            if (!"ieum-attendance".equals(uri.getScheme()) || !"pair".equals(uri.getHost())) {
                showMessage("QR 확인 필요", "아이이음 출석기 연결 QR이 아닙니다.", true);
                return;
            }
            String server = uri.getQueryParameter("server");
            String code = uri.getQueryParameter("academy_code");
            String pin = uri.getQueryParameter("tablet_pin");
            String pairing = uri.getQueryParameter("pairing_code");
            if (server == null || code == null || pin == null || pairing == null) {
                showMessage("QR 확인 필요", "연결 정보가 부족합니다. 관리자 화면에서 QR을 다시 생성해 주세요.", true);
                return;
            }
            baseUrlInput.setText(normalizeBaseUrl(server.trim()));
            academyCodeInput.setText(code.trim());
            tabletPinInput.setText(pin.trim());
            pairingInput.setText(pairing.trim());
            saveBasePrefs();
            registerDevice();
        } catch (Exception e) {
            showMessage("QR 확인 필요", "QR 정보를 읽을 수 없습니다.", true);
        }
    }

    private EditText dialogInput(String hint, String value, int inputType) {
        EditText input = new EditText(this);
        input.setSingleLine(true);
        input.setHint(hint);
        input.setText(value);
        input.setInputType(inputType);
        input.setTextSize(15);
        input.setPadding(dp(8), 0, dp(8), 0);
        return input;
    }

    private void registerDevice() {
        if (busy) {
            return;
        }
        final String pairing = pairingInput.getText().toString().trim();
        final String code = academyCodeInput.getText().toString().trim();
        final String pin = tabletPinInput.getText().toString().trim();
        if (code.length() == 0) {
            showMessage("도장 코드 필요", "관리자 화면의 도장 코드를 입력해 주세요.", true);
            return;
        }
        if (pin.length() < 4) {
            showMessage("출석기 PIN 필요", "관리자 화면의 출석기 PIN을 입력해 주세요.", true);
            return;
        }
        if (pairing.length() != 6) {
            showMessage("연결 코드 필요", "관리자 화면에서 만든 6자리 코드를 입력해 주세요.", true);
            return;
        }
        saveBasePrefs();
        busy = true;
        showMessage("출석기 연결 중", "도장 정보를 확인하고 있습니다.", false);

        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    String body = form(
                            "academy_code", code,
                            "tablet_pin", pin,
                            "pairing_code", pairing,
                            "device_uid", deviceUid,
                            "device_name", device()
                    );
                    final JSONObject json = postJson(apiUrl("/api/tablet/register.php"), body);
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            busy = false;
                            handleRegisterResponse(json);
                        }
                    });
                } catch (final Exception e) {
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            busy = false;
                            showMessage("연결 오류", friendlyConnectionError(e), true);
                        }
                    });
                }
            }
        }).start();
    }

    private void handleRegisterResponse(JSONObject json) {
        try {
            if (!json.optBoolean("ok")) {
                showMessage("출석기 연결 실패", json.optString("message"), true);
                return;
            }
            JSONObject data = json.optJSONObject("data");
            if (data == null) {
                showMessage("응답 오류", json.optString("message"), true);
                return;
            }
            deviceToken = data.optString("device_token");
            academyCode = data.optString("academy_code");
            academyName = data.optString("academy_name");
            deviceInput.setText(data.optString("device_name", device()));
            pairingInput.setText("");
            savePrefs();
            buildUi();
            uiHandler.removeCallbacks(returnToIdleRunnable);
            uiHandler.postDelayed(returnToIdleRunnable, 2500);
            showMessage("연결 완료", academyName + " 출석기로 사용할 수 있습니다.", false);
        } catch (Exception e) {
            showMessage("응답 오류", e.getMessage(), true);
        }
    }

    private void submit() {
        if (busy) {
            return;
        }
        final String code = codeInput.getText().toString().trim();
        cancelIdleTimer();
        pendingCode = code;
        clearInputOnNextDigit = false;
        if (code.length() == 0) {
            showMessage("학생번호를 입력하세요.", "대기 중", false);
            return;
        }
        if (deviceToken.length() == 0) {
            showUnpairedMessage();
            return;
        }
        saveBasePrefs();
        busy = true;
        showMessage("처리 중입니다.", "잠시만 기다려주세요.", false);

        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    String body = form(
                            "token", deviceToken,
                            "device", device(),
                            "student_code", code,
                            "student_id", selectedStudentId > 0 ? String.valueOf(selectedStudentId) : ""
                    );
                    final JSONObject json = postJson(apiUrl("/api/attendance/checkin.php"), body);
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            busy = false;
                            handleResponse(json);
                        }
                    });
                } catch (final Exception e) {
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            busy = false;
                            showMessage("연결 오류", friendlyConnectionError(e), true);
                        }
                    });
                }
            }
        }).start();
    }

    private void handleResponse(JSONObject json) {
        try {
            if (!json.optBoolean("ok")) {
                showTemporaryError("확인해 주세요", attendanceErrorMessage(json.optString("message")));
                return;
            }
            JSONObject data = json.optJSONObject("data");
            if (data == null) {
                showTemporaryError("다시 입력해 주세요", "출석 정보를 확인하지 못했습니다.");
                return;
            }
            if ("needs_selection".equals(data.optString("status"))) {
                showChoices(json.optString("message"), data.optJSONArray("students"));
                return;
            }
            showResult(data);
            scheduleIdleReturn();
            codeInput.setText("");
            pendingCode = "";
            selectedStudentId = 0;
            clearInputOnNextDigit = true;
        } catch (Exception e) {
            showTemporaryError("다시 입력해 주세요", "처리 중 오류가 발생했습니다.");
        }
    }

    private void showChoices(String message, JSONArray students) {
        cancelIdleTimer();
        lastResultData = null;
        lastMessageTitle = "";
        lastMessageBody = "";
        resultPanel.removeAllViews();
        resultPanel.setGravity(Gravity.TOP | Gravity.CENTER_HORIZONTAL);

        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        resultPanel.addView(scroll, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.MATCH_PARENT));

        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.TOP | Gravity.CENTER_HORIZONTAL);
        box.setPadding(dp(8), dp(8), dp(8), dp(8));
        scroll.addView(box, new ScrollView.LayoutParams(ScrollView.LayoutParams.MATCH_PARENT, ScrollView.LayoutParams.WRAP_CONTENT));

        TextView title = text("학생을 선택하세요", isPhone() ? 19 : 23, Color.rgb(16, 24, 40), true);
        title.setGravity(Gravity.CENTER);
        box.addView(title);

        TextView guide = text(message, isPhone() ? 13 : 15, Color.rgb(20, 108, 46), true);
        guide.setGravity(Gravity.CENTER);
        guide.setPadding(0, dp(6), 0, dp(8));
        box.addView(guide);

        if (students == null) {
            return;
        }
        for (int i = 0; i < students.length(); i++) {
            final JSONObject student = students.optJSONObject(i);
            if (student == null) {
                continue;
            }
            LinearLayout card = studentChoiceCard(student);
            LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, isPhone() ? dp(78) : dp(92));
            p.topMargin = dp(7);
            box.addView(card, p);
            card.setOnClickListener(new View.OnClickListener() {
                @Override
                public void onClick(View view) {
                    selectedStudentId = student.optInt("student_id");
                    submit();
                }
            });
        }
    }

    private LinearLayout studentChoiceCard(JSONObject student) {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.HORIZONTAL);
        card.setGravity(Gravity.CENTER_VERTICAL);
        card.setPadding(dp(10), dp(8), dp(10), dp(8));
        card.setBackground(roundedBg(Color.WHITE, Color.rgb(208, 218, 232), dp(14), dp(1)));

        String name = student.optString("student_name", "학생");
        int photoSize = isPhone() ? dp(50) : dp(62);
        View photo = photoView(name, student.optString("photo_url", ""), photoSize);
        LinearLayout.LayoutParams photoParams = new LinearLayout.LayoutParams(photoSize, photoSize);
        photoParams.rightMargin = dp(10);
        card.addView(photo, photoParams);

        LinearLayout info = new LinearLayout(this);
        info.setOrientation(LinearLayout.VERTICAL);
        info.setGravity(Gravity.CENTER_VERTICAL);
        card.addView(info, new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, 1));

        TextView nameText = text(name, isPhone() ? 19 : 22, Color.rgb(16, 24, 40), true);
        info.addView(nameText);

        String classLabel = student.optString("class_label", "");
        String detail = birthLabel(student.optString("birth_date")) + " · " + gradeLabel(student.optString("grade_group"));
        if (classLabel.length() > 0) {
            detail += " · " + classLabel;
        }
        TextView detailText = text(detail, isPhone() ? 12 : 14, Color.rgb(71, 84, 103), true);
        detailText.setPadding(0, dp(4), 0, 0);
        info.addView(detailText);

        TextView action = text("선택", isPhone() ? 13 : 15, Color.WHITE, true);
        action.setGravity(Gravity.CENTER);
        action.setBackground(roundedBg(BLUE, BLUE, dp(999), 0));
        LinearLayout.LayoutParams actionParams = new LinearLayout.LayoutParams(dp(isPhone() ? 52 : 64), dp(isPhone() ? 34 : 38));
        actionParams.leftMargin = dp(8);
        card.addView(action, actionParams);

        return card;
    }

    private void showResult(JSONObject data) {
        cancelIdleTimer();
        lastResultData = data;
        lastMessageTitle = "";
        lastMessageBody = "";
        resultPanel.removeAllViews();
        resultPanel.setGravity(Gravity.CENTER);
        boolean landscape = isLandscape();
        boolean phone = isPhone();
        boolean largeTablet = isLargeTablet();
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(landscape ? LinearLayout.VERTICAL : LinearLayout.HORIZONTAL);
        card.setGravity(Gravity.CENTER);
        resultPanel.addView(card, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.MATCH_PARENT));

        String name = data.optString("student_name", "학생");
        String photoUrl = data.optString("photo_url", "");
        int photoSize = landscape ? dp(largeTablet ? 180 : (phone ? 112 : 154)) : dp(phone ? 112 : 160);
        View photo = photoView(name, photoUrl, photoSize);
        LinearLayout.LayoutParams photoParams = new LinearLayout.LayoutParams(landscape ? photoSize : dp(phone ? 136 : 200), landscape ? photoSize : LinearLayout.LayoutParams.MATCH_PARENT);
        card.addView(photo, photoParams);

        LinearLayout info = new LinearLayout(this);
        info.setOrientation(LinearLayout.VERTICAL);
        info.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams infoParams = landscape
                ? new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
                : new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, 1);
        card.addView(info, infoParams);

        JSONObject level = data.optJSONObject("character_level");
        if (level != null) {
            JSONObject current = level.optJSONObject("current");
            TextView levelText = text(current != null ? current.optString("label") : "", landscape ? (phone ? 16 : 22) : 14, Color.WHITE, true);
            levelText.setGravity(Gravity.CENTER);
            levelText.setBackgroundColor(parseColor(current != null ? current.optString("color") : "", Color.rgb(179, 115, 54)));
            info.addView(levelText, new LinearLayout.LayoutParams(landscape ? dp(phone ? 150 : 220) : dp(150), dp(phone ? 36 : 42)));
            TextView next = text("다음 레벨까지 " + level.optInt("points_to_next") + "점", landscape ? (phone ? 12 : 15) : 12, Color.rgb(52, 64, 84), true);
            next.setGravity(Gravity.CENTER);
            info.addView(next);
        }

        String status = data.optString("status");
        TextView nameText = text(name + ("duplicate".equals(status) ? " 이미 등원" : " 등원"), landscape ? (phone ? 23 : 30) : 30, Color.rgb(16, 24, 40), true);
        nameText.setGravity(Gravity.CENTER);
        nameText.setPadding(0, dp(8), 0, dp(6));
        info.addView(nameText);

        JSONObject progress = data.optJSONObject("progress");
        if (progress != null) {
            int rate = progress.optInt("rate");
            TextView month = text("이번 달 수련 흐름 " + rate + "% · " + progress.optInt("attended_days") + "/" + progress.optInt("total_scheduled_days") + "일", landscape ? (phone ? 13 : 18) : 15, Color.rgb(52, 64, 84), true);
            month.setGravity(Gravity.CENTER);
            info.addView(month);
            TextView elapsed = text("오늘까지 정상 수업일 기준 " + progress.optInt("attended_days") + "/" + progress.optInt("elapsed_scheduled_days") + "일 출석", landscape ? (phone ? 13 : 18) : 15, Color.rgb(52, 64, 84), true);
            elapsed.setGravity(Gravity.CENTER);
            elapsed.setPadding(0, dp(8), 0, dp(8));
            info.addView(elapsed);
            TextView motivation = text(progress.optString("message"), landscape ? (phone ? 14 : 20) : 16, Color.rgb(20, 108, 46), true);
            motivation.setGravity(Gravity.CENTER);
            info.addView(motivation);
        }
    }

    private View photoView(String name, String url, int size) {
        FrameLayout frame = new FrameLayout(this);
        TextView fallback = text(name.length() > 0 ? name.substring(0, 1) : "?", 34, Color.rgb(52, 64, 84), true);
        fallback.setGravity(Gravity.CENTER);
        fallback.setBackgroundColor(Color.rgb(223, 232, 242));
        frame.addView(fallback, new FrameLayout.LayoutParams(size, size, Gravity.CENTER));
        if (url.length() > 0) {
            final ImageView image = new ImageView(this);
            image.setScaleType(ImageView.ScaleType.CENTER_CROP);
            frame.addView(image, new FrameLayout.LayoutParams(size, size, Gravity.CENTER));
            loadImage(url, image);
        }
        return frame;
    }

    private void loadImage(final String url, final ImageView image) {
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    InputStream stream = new URL(url).openStream();
                    final Bitmap bitmap = BitmapFactory.decodeStream(stream);
                    stream.close();
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            image.setImageBitmap(bitmap);
                        }
                    });
                } catch (Exception ignored) {
                }
            }
        }).start();
    }

    private void showIdle() {
        cancelIdleTimer();
        if (deviceToken.length() == 0) {
            showUnpairedMessage();
            return;
        }
        showMessage("대기 중", "학생번호 입력을 기다리고 있습니다.", false);
    }

    private void showUnpairedMessage() {
        resultPanel.removeAllViews();
        resultPanel.setGravity(Gravity.CENTER);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        resultPanel.addView(box, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.MATCH_PARENT));
        TextView title = text("출석기 연결 대기", 25, Color.rgb(71, 84, 103), true);
        title.setGravity(Gravity.CENTER);
        box.addView(title);
        TextView body = text("관리자 화면의 6자리 연결 코드가 필요합니다.", 15, Color.rgb(102, 112, 133), false);
        body.setGravity(Gravity.CENTER);
        body.setPadding(0, dp(8), 0, dp(12));
        box.addView(body);
        Button open = smallButton("관리자 설정");
        box.addView(open, new LinearLayout.LayoutParams(dp(150), dp(44)));
        open.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                requestAdminPin();
            }
        });
    }

    private void showMessage(String title, String body, boolean error) {
        lastMessageTitle = title;
        lastMessageBody = body;
        lastMessageError = error;
        if (!"처리 중입니다.".equals(title)) {
            lastResultData = null;
        }
        resultPanel.removeAllViews();
        resultPanel.setGravity(Gravity.CENTER);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        resultPanel.addView(box, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.MATCH_PARENT));
        TextView t = text(title, isPhone() ? 23 : 28, error ? Color.rgb(164, 38, 44) : Color.rgb(71, 84, 103), true);
        t.setGravity(Gravity.CENTER);
        box.addView(t);
        TextView b = text(body, isPhone() ? 14 : 16, Color.rgb(102, 112, 133), false);
        b.setGravity(Gravity.CENTER);
        b.setPadding(0, dp(8), 0, 0);
        box.addView(b);
    }

    private void showTemporaryError(String title, String body) {
        showMessage(title, body, true);
        codeInput.setText("");
        pendingCode = "";
        selectedStudentId = 0;
        clearInputOnNextDigit = true;
        uiHandler.removeCallbacks(returnToIdleRunnable);
        uiHandler.postDelayed(returnToIdleRunnable, 1500);
    }

    private String attendanceErrorMessage(String message) {
        if (message == null || message.trim().length() == 0) {
            return "학생번호를 다시 입력해 주세요.";
        }
        String normalized = message.trim();
        if (normalized.contains("등록된 학생번호가 아닙니다") || normalized.contains("학생번호")) {
            return "등록된 학생번호가 아닙니다.";
        }
        if (normalized.contains("사용 중지") || normalized.contains("중지")) {
            return "현재 사용 중지된 학생입니다.";
        }
        return normalized;
    }

    private void scheduleIdleReturn() {
        uiHandler.removeCallbacks(returnToIdleRunnable);
        uiHandler.postDelayed(returnToIdleRunnable, 10000);
    }

    private void cancelIdleTimer() {
        uiHandler.removeCallbacks(returnToIdleRunnable);
    }

    private LinearLayout panel() {
        LinearLayout panel = new LinearLayout(this);
        panel.setOrientation(LinearLayout.VERTICAL);
        panel.setBackgroundColor(Color.WHITE);
        return panel;
    }

    private TextView text(String value, int sp, int color, boolean bold) {
        TextView text = new TextView(this);
        text.setText(value);
        text.setTextSize(sp);
        text.setTextColor(color);
        if (bold) {
            text.setTypeface(null, 1);
        }
        return text;
    }

    private EditText smallInput(String fallback) {
        EditText editText = new EditText(this);
        editText.setSingleLine(true);
        editText.setTextSize(11);
        editText.setText(fallback);
        editText.setInputType(InputType.TYPE_CLASS_TEXT);
        return editText;
    }

    private Button smallButton(String label) {
        Button button = new Button(this);
        button.setAllCaps(false);
        button.setText(label);
        button.setTextSize(12);
        button.setTextColor(Color.WHITE);
        button.setBackgroundColor(CHARCOAL);
        return button;
    }

    private GradientDrawable roundedBg(int fillColor, int strokeColor, int radius, int strokeWidth) {
        GradientDrawable drawable = new GradientDrawable();
        drawable.setColor(fillColor);
        drawable.setCornerRadius(radius);
        if (strokeWidth > 0) {
            drawable.setStroke(strokeWidth, strokeColor);
        }
        return drawable;
    }

    private void ensureDeviceUid() {
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        deviceUid = prefs.getString(KEY_DEVICE_UID, "");
        if (deviceUid.length() == 0) {
            deviceUid = UUID.randomUUID().toString();
            prefs.edit().putString(KEY_DEVICE_UID, deviceUid).apply();
        }
    }

    private void loadStoredConnection() {
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        deviceToken = prefs.getString(KEY_TOKEN, "");
        academyName = prefs.getString(KEY_ACADEMY, "");
    }

    private void loadPrefs() {
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        baseUrlInput.setText(prefs.getString(KEY_BASE_URL, baseUrlInput.getText().toString()));
        deviceInput.setText(prefs.getString(KEY_DEVICE, deviceInput.getText().toString()));
        academyCodeInput.setText(prefs.getString(KEY_ACADEMY_CODE, academyCodeInput.getText().toString()));
        tabletPinInput.setText(prefs.getString(KEY_TABLET_PIN, tabletPinInput.getText().toString()));
        deviceToken = prefs.getString(KEY_TOKEN, "");
        academyName = prefs.getString(KEY_ACADEMY, "");
        academyCode = prefs.getString(KEY_ACADEMY_CODE, "");
        tabletPin = prefs.getString(KEY_TABLET_PIN, "");
    }

    private void saveBasePrefs() {
        getSharedPreferences(PREFS, MODE_PRIVATE)
                .edit()
                .putString(KEY_BASE_URL, normalizeBaseUrl(baseUrlInput.getText().toString().trim()))
                .putString(KEY_ACADEMY_CODE, academyCodeInput.getText().toString().trim())
                .putString(KEY_TABLET_PIN, tabletPinInput.getText().toString().trim())
                .putString(KEY_DEVICE, deviceInput.getText().toString().trim())
                .apply();
    }

    private void savePrefs() {
        getSharedPreferences(PREFS, MODE_PRIVATE)
                .edit()
                .putString(KEY_BASE_URL, normalizeBaseUrl(baseUrlInput.getText().toString().trim()))
                .putString(KEY_ACADEMY_CODE, academyCodeInput.getText().toString().trim())
                .putString(KEY_TABLET_PIN, tabletPinInput.getText().toString().trim())
                .putString(KEY_DEVICE, deviceInput.getText().toString().trim())
                .putString(KEY_TOKEN, deviceToken)
                .putString(KEY_ACADEMY, academyName)
                .putString(KEY_DEVICE_UID, deviceUid)
                .apply();
    }

    private String baseUrl() {
        return normalizeBaseUrl(baseUrlInput.getText().toString().trim());
    }

    private String apiUrl(String path) {
        String base = baseUrl();
        if (base.endsWith("/ieum")) {
            return base + path;
        }
        return base + "/ieum" + path;
    }

    private void rememberInput() {
        if (codeInput != null) {
            pendingCode = codeInput.getText().toString();
        }
    }

    private void restoreResultPanel() {
        if (busy) {
            showMessage("처리 중입니다.", "잠시만 기다려주세요.", false);
            return;
        }
        if (lastResultData != null) {
            showResult(lastResultData);
            return;
        }
        if (lastMessageTitle.length() > 0) {
            showMessage(lastMessageTitle, lastMessageBody, lastMessageError);
            return;
        }
        showIdle();
    }

    private String device() {
        String value = deviceInput.getText().toString().trim();
        return value.length() > 0 ? value : "입구 태블릿";
    }

    private String trimTrailingSlash(String value) {
        while (value.endsWith("/")) {
            value = value.substring(0, value.length() - 1);
        }
        return value;
    }

    private String normalizeBaseUrl(String value) {
        value = trimTrailingSlash(value);
        if (value.endsWith("/kiosk.php")) {
            value = value.substring(0, value.length() - "/kiosk.php".length());
        }
        if (value.endsWith("/admin/tablet.php")) {
            value = value.substring(0, value.length() - "/admin/tablet.php".length());
        }
        if (value.contains("/ieum/api/")) {
            value = value.substring(0, value.indexOf("/ieum/api/") + "/ieum".length());
        }
        return trimTrailingSlash(value);
    }

    private String friendlyConnectionError(Exception e) {
        String message = e.getMessage();
        if (message == null || message.trim().length() == 0) {
            return "서버에 연결하지 못했습니다. 와이파이와 서버 주소를 확인해 주세요.";
        }
        String lower = message.toLowerCase();
        if (lower.contains("localhost") || lower.contains("127.0.0.1")) {
            return "QR 서버 주소가 localhost로 되어 있습니다. 태블릿에서는 localhost가 PC가 아니라 태블릿 자신입니다. 관리자 화면의 서버 주소를 PC IP 또는 운영 도메인으로 바꾼 뒤 새 QR을 스캔해 주세요.";
        }
        if (lower.contains("failed to connect") || lower.contains("timed out") || lower.contains("timeout")) {
            return "서버에 연결하지 못했습니다. 태블릿 와이파이와 관리자 화면의 서버 주소를 확인해 주세요. 로컬 테스트는 http://192.168.0.81/ieum 형식으로 입력합니다.";
        }
        if (lower.contains("unexpected end") || lower.contains("json")) {
            return "서버 응답을 읽지 못했습니다. 관리자 화면의 서버 주소와 와이파이 연결을 확인해 주세요.";
        }
        return message;
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
        String text = readAll(stream).trim();
        conn.disconnect();
        if (!text.startsWith("{")) {
            throw new Exception("서버 주소를 확인해 주세요. 화면 주소가 아니라 서버 주소를 입력해야 합니다.");
        }
        return new JSONObject(text);
    }

    private String readAll(InputStream stream) throws Exception {
        BufferedReader reader = new BufferedReader(new InputStreamReader(stream, StandardCharsets.UTF_8));
        StringBuilder out = new StringBuilder();
        String line;
        while ((line = reader.readLine()) != null) {
            out.append(line);
        }
        reader.close();
        return out.toString();
    }

    private String form(String... pairs) throws Exception {
        StringBuilder out = new StringBuilder();
        for (int i = 0; i < pairs.length; i += 2) {
            if (pairs[i + 1] == null || pairs[i + 1].length() == 0) {
                continue;
            }
            if (out.length() > 0) {
                out.append('&');
            }
            out.append(URLEncoder.encode(pairs[i], "UTF-8"));
            out.append('=');
            out.append(URLEncoder.encode(pairs[i + 1], "UTF-8"));
        }
        return out.toString();
    }

    private String birthLabel(String value) {
        if (value == null || value.length() < 10 || "0000-00-00".equals(value)) {
            return "생년월일 미입력";
        }
        return value.substring(0, 4) + "년 " + value.substring(5, 7) + "월 " + value.substring(8, 10) + "일";
    }

    private String gradeLabel(String value) {
        if ("kindergarten".equals(value)) return "유치부";
        if ("elementary_1".equals(value)) return "초등 1학년";
        if ("elementary_2".equals(value)) return "초등 2학년";
        if ("elementary_3".equals(value)) return "초등 3학년";
        if ("elementary_4".equals(value)) return "초등 4학년";
        if ("elementary_5".equals(value)) return "초등 5학년";
        if ("elementary_6".equals(value)) return "초등 6학년";
        if ("middle_1".equals(value)) return "중등 1학년";
        if ("middle_2".equals(value)) return "중등 2학년";
        if ("middle_3".equals(value)) return "중등 3학년";
        if ("high_1".equals(value)) return "고등 1학년";
        if ("high_2".equals(value)) return "고등 2학년";
        if ("high_3".equals(value)) return "고등 3학년";
        return value == null ? "" : value;
    }

    private int parseColor(String color, int fallback) {
        try {
            return Color.parseColor(color);
        } catch (Exception e) {
            return fallback;
        }
    }

    private boolean isLandscape() {
        return getResources().getConfiguration().orientation == Configuration.ORIENTATION_LANDSCAPE;
    }

    private boolean isPhone() {
        return getResources().getConfiguration().smallestScreenWidthDp < 600;
    }

    private boolean isLargeTablet() {
        return getResources().getConfiguration().smallestScreenWidthDp >= 840;
    }

    private void hideSystemUi() {
        getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_FULLSCREEN
                        | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                        | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                        | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                        | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                        | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
        );
    }

    private void installImmersiveMode() {
        hideSystemUi();
        getWindow().getDecorView().setOnSystemUiVisibilityChangeListener(new View.OnSystemUiVisibilityChangeListener() {
            @Override
            public void onSystemUiVisibilityChange(int visibility) {
                uiHandler.removeCallbacks(immersiveRunnable);
                uiHandler.postDelayed(immersiveRunnable, 800);
            }
        });
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density + 0.5f);
    }
}
