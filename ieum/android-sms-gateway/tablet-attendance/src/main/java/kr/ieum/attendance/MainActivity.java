package kr.ieum.attendance;

import android.app.Activity;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.res.Configuration;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Color;
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
import android.widget.ProgressBar;
import android.widget.ScrollView;
import android.widget.TextView;

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

public class MainActivity extends Activity {
    private static final String PREFS = "ieum_tablet_attendance";
    private static final String KEY_BASE_URL = "base_url";
    private static final String KEY_TOKEN = "token";
    private static final String KEY_DEVICE = "device";
    private static final int BLUE = Color.rgb(25, 71, 186);
    private static final int CHARCOAL = Color.rgb(44, 42, 37);

    private EditText codeInput;
    private EditText baseUrlInput;
    private EditText tokenInput;
    private EditText deviceInput;
    private LinearLayout resultPanel;
    private LinearLayout keypad;
    private boolean busy = false;
    private int selectedStudentId = 0;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        requestWindowFeature(Window.FEATURE_NO_TITLE);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        buildUi();
    }

    @Override
    public void onConfigurationChanged(Configuration newConfig) {
        super.onConfigurationChanged(newConfig);
        buildUi();
    }

    private void buildUi() {
        hideSystemUi();
        boolean landscape = getResources().getConfiguration().orientation == Configuration.ORIENTATION_LANDSCAPE;

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(landscape ? LinearLayout.HORIZONTAL : LinearLayout.VERTICAL);
        root.setPadding(dp(10), dp(10), dp(10), dp(10));
        root.setBackgroundColor(Color.rgb(16, 24, 40));

        LinearLayout inputPanel = panel();
        inputPanel.setPadding(dp(16), dp(14), dp(16), dp(12));
        LinearLayout.LayoutParams inputParams = landscape
                ? new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, 54)
                : new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 76);
        root.addView(inputPanel, inputParams);

        resultPanel = panel();
        resultPanel.setGravity(Gravity.CENTER);
        resultPanel.setPadding(dp(16), dp(16), dp(16), dp(16));
        LinearLayout.LayoutParams resultParams = landscape
                ? new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, 46)
                : new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 24);
        if (landscape) {
            resultParams.leftMargin = dp(10);
        } else {
            resultParams.topMargin = dp(8);
        }
        root.addView(resultPanel, resultParams);

        TextView title = text("아이이음태권도", landscape ? 28 : 26, Color.rgb(7, 17, 36), true);
        inputPanel.addView(title);
        TextView guide = text("학생번호를 입력하고 등원 처리하세요.", landscape ? 14 : 13, Color.rgb(71, 84, 103), false);
        guide.setPadding(0, dp(4), 0, dp(10));
        inputPanel.addView(guide);

        codeInput = new EditText(this);
        codeInput.setSingleLine(true);
        codeInput.setTextSize(landscape ? 30 : 26);
        codeInput.setGravity(Gravity.CENTER);
        codeInput.setInputType(InputType.TYPE_CLASS_NUMBER);
        codeInput.setBackgroundColor(Color.WHITE);
        inputPanel.addView(codeInput, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, landscape ? dp(58) : dp(72)));

        keypad = new LinearLayout(this);
        keypad.setOrientation(LinearLayout.VERTICAL);
        LinearLayout.LayoutParams keypadParams = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 1);
        keypadParams.topMargin = dp(8);
        inputPanel.addView(keypad, keypadParams);
        addKeyRows(landscape);

        LinearLayout settings = new LinearLayout(this);
        settings.setOrientation(LinearLayout.HORIZONTAL);
        settings.setGravity(Gravity.CENTER_VERTICAL);
        settings.setPadding(0, dp(6), 0, 0);
        inputPanel.addView(settings, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT));

        baseUrlInput = smallInput("http://192.168.0.81");
        tokenInput = smallInput("ieum-local-gateway-token-2026");
        deviceInput = smallInput("tablet-attendance");
        loadPrefs();
        settings.addView(baseUrlInput, new LinearLayout.LayoutParams(0, dp(34), 2));
        settings.addView(tokenInput, new LinearLayout.LayoutParams(0, dp(34), 2));
        settings.addView(deviceInput, new LinearLayout.LayoutParams(0, dp(34), 1));
        Button save = smallButton("저장");
        settings.addView(save, new LinearLayout.LayoutParams(dp(64), dp(34)));
        save.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                savePrefs();
                showIdle();
            }
        });

        showIdle();
        setContentView(root);
    }

    private void addKeyRows(boolean landscape) {
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
            lineParams.topMargin = dp(5);
            keypad.addView(line, lineParams);
            for (String label : row) {
                Button b = keyButton(label, landscape);
                LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, "등원".equals(label) ? 2 : 1);
                params.leftMargin = dp(3);
                params.rightMargin = dp(3);
                line.addView(b, params);
            }
        }
    }

    private Button keyButton(final String label, boolean landscape) {
        Button b = new Button(this);
        b.setText(label);
        b.setTextSize(landscape ? 25 : 24);
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
            codeInput.setText("");
            selectedStudentId = 0;
            showIdle();
        } else if ("←".equals(label)) {
            String value = codeInput.getText().toString();
            if (value.length() > 0) {
                codeInput.setText(value.substring(0, value.length() - 1));
                codeInput.setSelection(codeInput.length());
            }
        } else {
            codeInput.append(label);
        }
    }

    private void submit() {
        if (busy) {
            return;
        }
        final String code = codeInput.getText().toString().trim();
        if (code.length() == 0) {
            showMessage("학생번호를 입력하세요.", "대기 중", false);
            return;
        }
        savePrefs();
        busy = true;
        showMessage("처리 중입니다.", "잠시만 기다려주세요.", false);

        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    String body = form(
                            "token", token(),
                            "device", device(),
                            "student_code", code,
                            "student_id", selectedStudentId > 0 ? String.valueOf(selectedStudentId) : ""
                    );
                    JSONObject json = postJson(baseUrl() + "/ieum/api/attendance/checkin.php", body);
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
                            showMessage("연결 오류", e.getMessage(), true);
                        }
                    });
                }
            }
        }).start();
    }

    private void handleResponse(JSONObject json) {
        try {
            if (!json.optBoolean("ok")) {
                showMessage("등원 처리 실패", json.optString("message"), true);
                return;
            }
            JSONObject data = json.optJSONObject("data");
            if (data == null) {
                showMessage("응답 오류", json.optString("message"), true);
                return;
            }
            if ("needs_selection".equals(data.optString("status"))) {
                showChoices(json.optString("message"), data.optJSONArray("students"));
                return;
            }
            showResult(data);
            if (!"duplicate".equals(data.optString("status"))) {
                codeInput.setText("");
                selectedStudentId = 0;
            }
        } catch (Exception e) {
            showMessage("응답 오류", e.getMessage(), true);
        }
    }

    private void showChoices(String message, JSONArray students) {
        resultPanel.removeAllViews();
        resultPanel.setGravity(Gravity.CENTER);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        resultPanel.addView(box, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT));
        TextView title = text(message, 18, Color.rgb(20, 108, 46), true);
        title.setGravity(Gravity.CENTER);
        box.addView(title);
        if (students == null) {
            return;
        }
        for (int i = 0; i < students.length(); i++) {
            final JSONObject student = students.optJSONObject(i);
            if (student == null) {
                continue;
            }
            Button b = new Button(this);
            b.setAllCaps(false);
            b.setText(student.optString("student_name") + "\n" + birthLabel(student.optString("birth_date")) + " · " + gradeLabel(student.optString("grade_group")));
            b.setTextSize(16);
            LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(72));
            p.topMargin = dp(8);
            box.addView(b, p);
            b.setOnClickListener(new View.OnClickListener() {
                @Override
                public void onClick(View view) {
                    selectedStudentId = student.optInt("student_id");
                    submit();
                }
            });
        }
    }

    private void showResult(JSONObject data) {
        resultPanel.removeAllViews();
        resultPanel.setGravity(Gravity.CENTER);
        boolean landscape = getResources().getConfiguration().orientation == Configuration.ORIENTATION_LANDSCAPE;
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(landscape ? LinearLayout.VERTICAL : LinearLayout.HORIZONTAL);
        card.setGravity(Gravity.CENTER);
        resultPanel.addView(card, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.MATCH_PARENT));

        String name = data.optString("student_name", "학생");
        String photoUrl = data.optString("photo_url", "");
        View photo = photoView(name, photoUrl, landscape ? dp(132) : dp(136));
        LinearLayout.LayoutParams photoParams = new LinearLayout.LayoutParams(landscape ? dp(132) : dp(170), landscape ? dp(132) : LinearLayout.LayoutParams.MATCH_PARENT);
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
            TextView levelText = text(level.optJSONObject("current") != null ? level.optJSONObject("current").optString("label") : "", landscape ? 22 : 14, Color.WHITE, true);
            levelText.setGravity(Gravity.CENTER);
            levelText.setBackgroundColor(parseColor(level.optJSONObject("current") != null ? level.optJSONObject("current").optString("color") : "", Color.rgb(179, 115, 54)));
            info.addView(levelText, new LinearLayout.LayoutParams(landscape ? dp(220) : dp(160), dp(42)));
            TextView next = text("다음 레벨까지 " + level.optInt("points_to_next") + "점", landscape ? 15 : 12, Color.rgb(52, 64, 84), true);
            next.setGravity(Gravity.CENTER);
            info.addView(next);
        }

        String status = data.optString("status");
        TextView nameText = text(name + ("duplicate".equals(status) ? " 이미 등원" : " 등원"), landscape ? 30 : 34, Color.rgb(16, 24, 40), true);
        nameText.setGravity(Gravity.CENTER);
        nameText.setPadding(0, dp(10), 0, dp(8));
        info.addView(nameText);

        JSONObject progress = data.optJSONObject("progress");
        if (progress != null) {
            int rate = progress.optInt("rate");
            TextView month = text("이번 달 수련 흐름 " + rate + "% · " + progress.optInt("attended_days") + "/" + progress.optInt("total_scheduled_days") + "일", landscape ? 18 : 16, Color.rgb(52, 64, 84), true);
            month.setGravity(Gravity.CENTER);
            info.addView(month);
            TextView elapsed = text("오늘까지 정상 수업일 기준 " + progress.optInt("attended_days") + "/" + progress.optInt("elapsed_scheduled_days") + "일 출석", landscape ? 18 : 16, Color.rgb(52, 64, 84), true);
            elapsed.setGravity(Gravity.CENTER);
            elapsed.setPadding(0, dp(12), 0, dp(10));
            info.addView(elapsed);
            TextView motivation = text(progress.optString("message"), landscape ? 20 : 18, Color.rgb(20, 108, 46), true);
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
        showMessage("대기 중", "학생번호 입력을 기다리고 있습니다.", false);
    }

    private void showMessage(String title, String body, boolean error) {
        resultPanel.removeAllViews();
        resultPanel.setGravity(Gravity.CENTER);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        resultPanel.addView(box, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.MATCH_PARENT));
        TextView t = text(title, 28, error ? Color.rgb(164, 38, 44) : Color.rgb(71, 84, 103), true);
        t.setGravity(Gravity.CENTER);
        box.addView(t);
        TextView b = text(body, 16, Color.rgb(102, 112, 133), false);
        b.setGravity(Gravity.CENTER);
        b.setPadding(0, dp(8), 0, 0);
        box.addView(b);
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

    private void loadPrefs() {
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        baseUrlInput.setText(prefs.getString(KEY_BASE_URL, baseUrlInput.getText().toString()));
        tokenInput.setText(prefs.getString(KEY_TOKEN, tokenInput.getText().toString()));
        deviceInput.setText(prefs.getString(KEY_DEVICE, deviceInput.getText().toString()));
    }

    private void savePrefs() {
        getSharedPreferences(PREFS, MODE_PRIVATE)
                .edit()
                .putString(KEY_BASE_URL, trimTrailingSlash(baseUrlInput.getText().toString().trim()))
                .putString(KEY_TOKEN, tokenInput.getText().toString().trim())
                .putString(KEY_DEVICE, deviceInput.getText().toString().trim())
                .apply();
    }

    private String baseUrl() {
        return trimTrailingSlash(baseUrlInput.getText().toString().trim());
    }

    private String token() {
        return tokenInput.getText().toString().trim();
    }

    private String device() {
        String value = deviceInput.getText().toString().trim();
        return value.length() > 0 ? value : "tablet-attendance";
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
            if (pairs[i + 1].length() == 0) {
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

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density + 0.5f);
    }
}
