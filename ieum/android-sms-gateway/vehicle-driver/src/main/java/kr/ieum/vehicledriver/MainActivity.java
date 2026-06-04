package kr.ieum.vehicledriver;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.location.Location;
import android.location.LocationManager;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.InputType;
import android.text.TextUtils;
import android.view.Gravity;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.view.inputmethod.InputMethodManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
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
import java.util.HashMap;

public class MainActivity extends Activity {
    private static final String PREFS = "ieum_vehicle_driver";
    private static final String KEY_BASE_URL = "base_url";
    private static final String KEY_ACADEMY_CODE = "academy_code";
    private static final String KEY_TOKEN = "driver_token";
    private static final String KEY_ROUTE_ID = "route_id";
    private static final String KEY_ROUTE_LABEL = "route_label";
    private static final int BLUE = Color.rgb(35, 76, 185);
    private static final int NAVY = Color.rgb(16, 24, 40);
    private static final int GREEN = Color.rgb(5, 150, 105);
    private static final int RED = Color.rgb(220, 38, 38);
    private static final int AMBER = Color.rgb(217, 119, 6);
    private static final int GRAY = Color.rgb(100, 116, 139);
    private static final int REQ_LOCATION = 5101;
    private static final long ROSTER_REFRESH_MS = 30000;

    private SharedPreferences prefs;
    private String baseUrl = "http://192.168.0.81";
    private String academyCode = "IEUMTKD001";
    private String driverToken = "";
    private int routeId = 0;
    private String routeLabel = "";
    private String rideType = "all";
    private LinearLayout root;
    private TextView statusText;
    private LinearLayout content;
    private boolean busy = false;
    private boolean runActive = false;
    private Button runButton;
    private Handler locationHandler = new Handler(Looper.getMainLooper());
    private Runnable locationTicker;
    private Handler rosterHandler = new Handler(Looper.getMainLooper());
    private Runnable rosterTicker;
    private ScrollView rosterScroll;
    private LinearLayout rosterList;
    private JSONArray currentStops = new JSONArray();
    private HashMap<Integer, View> stopAnchors = new HashMap<>();
    private int focusedStopId = 0;
    private long lastAutoFocusAt = 0;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        requestWindowFeature(Window.FEATURE_NO_TITLE);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        loadPrefs();
        if (driverToken.length() > 0) {
            buildBoard();
        } else {
            buildLogin();
        }
    }

    @Override
    protected void onDestroy() {
        stopRosterTicker();
        stopLocationTicker();
        super.onDestroy();
    }

    private void loadPrefs() {
        baseUrl = prefs.getString(KEY_BASE_URL, baseUrl);
        academyCode = prefs.getString(KEY_ACADEMY_CODE, academyCode);
        driverToken = prefs.getString(KEY_TOKEN, "");
        routeId = prefs.getInt(KEY_ROUTE_ID, 0);
        routeLabel = prefs.getString(KEY_ROUTE_LABEL, "");
    }

    private void savePrefs() {
        prefs.edit()
                .putString(KEY_BASE_URL, baseUrl)
                .putString(KEY_ACADEMY_CODE, academyCode)
                .putString(KEY_TOKEN, driverToken)
                .putInt(KEY_ROUTE_ID, routeId)
                .putString(KEY_ROUTE_LABEL, routeLabel)
                .apply();
    }

    private void buildBase(String title) {
        root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(Color.rgb(244, 247, 251));
        root.setPadding(dp(14), dp(12), dp(14), dp(12));
        root.setFocusableInTouchMode(true);
        root.requestFocus();
        setContentView(root);

        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.CENTER_VERTICAL);
        root.addView(header, new LinearLayout.LayoutParams(-1, -2));

        TextView titleView = text(title, 24, NAVY, true);
        header.addView(titleView, new LinearLayout.LayoutParams(0, -2, 1));

        statusText = text("", 13, GRAY, false);
        statusText.setGravity(Gravity.RIGHT);
        header.addView(statusText, new LinearLayout.LayoutParams(0, -2, 1));

        content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        LinearLayout.LayoutParams cp = new LinearLayout.LayoutParams(-1, 0, 1);
        cp.topMargin = dp(10);
        root.addView(content, cp);
    }

    private void buildLogin() {
        buildBase("아이이음 차량기사");
        statusText.setText("기사님 로그인");
        boolean landscape = getResources().getConfiguration().orientation == android.content.res.Configuration.ORIENTATION_LANDSCAPE;

        final EditText baseInput = input(baseUrl, "서버 주소");
        final EditText codeInput = input(academyCode, "도장 코드");
        final EditText pinInput = input("", "기사님 PIN");
        pinInput.setInputType(InputType.TYPE_CLASS_NUMBER | InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        pinInput.setSelectAllOnFocus(true);

        final LinearLayout routeBox = new LinearLayout(this);
        routeBox.setOrientation(LinearLayout.VERTICAL);

        Button routeButton = primaryButton("차량 노선 불러오기");
        Button loginButton = primaryButton("로그인");

        if (landscape) {
            LinearLayout grid = new LinearLayout(this);
            grid.setOrientation(LinearLayout.HORIZONTAL);
            content.addView(grid, new LinearLayout.LayoutParams(-1, -2));

            LinearLayout left = new LinearLayout(this);
            left.setOrientation(LinearLayout.VERTICAL);
            LinearLayout right = new LinearLayout(this);
            right.setOrientation(LinearLayout.VERTICAL);
            LinearLayout.LayoutParams col = new LinearLayout.LayoutParams(0, -2, 1);
            col.setMargins(0, 0, dp(8), 0);
            grid.addView(left, col);
            LinearLayout.LayoutParams col2 = new LinearLayout.LayoutParams(0, -2, 1);
            col2.setMargins(dp(8), 0, 0, 0);
            grid.addView(right, col2);

            left.addView(label("서버 주소"));
            left.addView(baseInput);
            left.addView(label("도장 코드"));
            left.addView(codeInput);
            left.addView(routeButton);
            left.addView(routeBox);

            right.addView(label("기사님 PIN"));
            right.addView(pinInput);
            right.addView(loginButton);
            TextView help = text("처음 연결할 때만 입력합니다. 이후에는 기사님 앱이 노선을 기억합니다.", 14, GRAY, false);
            help.setPadding(0, dp(12), 0, 0);
            right.addView(help);
        } else {
            content.addView(label("서버 주소"));
            content.addView(baseInput);
            content.addView(label("도장 코드"));
            content.addView(codeInput);
            content.addView(routeButton);
            content.addView(routeBox);
            content.addView(label("기사님 PIN"));
            content.addView(pinInput);
            content.addView(loginButton);
        }
        root.requestFocus();
        hideKeyboard();

        routeButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                hideKeyboard();
                baseUrl = stripSlash(baseInput.getText().toString());
                academyCode = codeInput.getText().toString().trim();
                routeBox.removeAllViews();
                setStatus("노선 조회 중...");
                apiGet("/ieum/api/vehicle/routes.php?academy_code=" + enc(academyCode), new ApiCallback() {
                    @Override
                    public void done(JSONObject response) throws Exception {
                        JSONArray routes = response.getJSONObject("data").getJSONArray("routes");
                        if (routes.length() == 0) {
                            setStatus("기사 PIN이 설정된 노선이 없습니다.");
                            return;
                        }
                        routeBox.addView(label("차량 노선 선택"));
                        for (int i = 0; i < routes.length(); i++) {
                            final JSONObject route = routes.getJSONObject(i);
                            Button btn = secondaryButton(route.getString("label"));
                            btn.setOnClickListener(new View.OnClickListener() {
                                @Override
                                public void onClick(View view) {
                                    routeId = route.optInt("route_id");
                                    routeLabel = route.optString("label");
                                    setStatus(routeLabel + " 선택");
                                }
                            });
                            routeBox.addView(btn);
                            if (i == 0 && routeId <= 0) {
                                routeId = route.optInt("route_id");
                                routeLabel = route.optString("label");
                            }
                        }
                        setStatus(routeLabel.length() > 0 ? routeLabel + " 선택" : "노선을 선택하세요.");
                    }
                });
            }
        });

        loginButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                hideKeyboard();
                baseUrl = stripSlash(baseInput.getText().toString());
                academyCode = codeInput.getText().toString().trim();
                String pin = pinInput.getText().toString().trim();
                if (routeId <= 0) {
                    setStatus("차량 노선을 먼저 선택하세요.");
                    return;
                }
                setStatus("로그인 중...");
                apiPost("/ieum/api/vehicle/login.php", "academy_code=" + enc(academyCode) + "&route_id=" + routeId + "&driver_pin=" + enc(pin), new ApiCallback() {
                    @Override
                    public void done(JSONObject response) throws Exception {
                        JSONObject data = response.getJSONObject("data");
                        JSONObject route = data.getJSONObject("route");
                        driverToken = data.getString("driver_token");
                        routeId = route.getInt("route_id");
                        routeLabel = route.optString("label");
                        savePrefs();
                        buildBoard();
                    }
                });
            }
        });
    }

    private void buildBoard() {
        buildBase(routeLabel.length() > 0 ? routeLabel : "차량 탑승 확인");

        LinearLayout actions = new LinearLayout(this);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        actions.setGravity(Gravity.CENTER_VERTICAL);
        content.addView(actions);

        final Button allButton = secondaryButton("전체");
        final Button pickupButton = secondaryButton("등원");
        final Button dropoffButton = secondaryButton("하원");
        Button refreshButton = secondaryButton("새로고침");
        runButton = primaryButton("운행 시작");
        Button logoutButton = secondaryButton("로그아웃");
        compactTopButton(allButton);
        compactTopButton(pickupButton);
        compactTopButton(dropoffButton);
        compactTopButton(refreshButton);
        compactTopButton(runButton);
        compactTopButton(logoutButton);
        actions.addView(allButton, actionLayout());
        actions.addView(pickupButton, actionLayout());
        actions.addView(dropoffButton, actionLayout());
        actions.addView(refreshButton, actionLayout());
        actions.addView(runButton, actionLayout());
        actions.addView(logoutButton, actionLayout());

        final LinearLayout list = new LinearLayout(this);
        list.setOrientation(LinearLayout.VERTICAL);
        rosterList = list;
        rosterScroll = new ScrollView(this);
        rosterScroll.addView(list);
        LinearLayout.LayoutParams sp = new LinearLayout.LayoutParams(-1, 0, 1);
        sp.topMargin = dp(10);
        content.addView(rosterScroll, sp);

        View.OnClickListener typeClick = new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                rideType = view == dropoffButton ? "dropoff" : (view == pickupButton ? "pickup" : "all");
                focusedStopId = 0;
                loadRoster(list);
            }
        };
        allButton.setOnClickListener(typeClick);
        pickupButton.setOnClickListener(typeClick);
        dropoffButton.setOnClickListener(typeClick);
        refreshButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                loadRoster(list);
            }
        });
        runButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                final String action = runActive ? "end" : "start";
                apiPost("/ieum/api/vehicle/run.php", "action=" + enc(action) + "&ride_type=all", new ApiCallback() {
                    @Override
                    public void done(JSONObject response) {
                        runActive = "start".equals(action);
                        updateRunButton();
                        if (runActive) {
                            setStatus("운행 시작");
                            startLocationTicker();
                        } else {
                            setStatus("운행 종료");
                            stopLocationTicker();
                        }
                        loadRoster(list);
                    }
                });
            }
        });
        logoutButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                driverToken = "";
                savePrefs();
                stopRosterTicker();
                stopLocationTicker();
                buildLogin();
            }
        });

        loadRoster(list);
        startRosterTicker();
    }

    private void loadRoster(final LinearLayout list) {
        loadRoster(list, false);
    }

    private void loadRoster(final LinearLayout list, final boolean silent) {
        final int lastScrollY = rosterScroll == null ? 0 : rosterScroll.getScrollY();
        if (!silent) {
            list.removeAllViews();
            setStatus("명단 조회 중...");
        }
        apiGet("/ieum/api/vehicle/route.php?ride_type=" + enc(rideType), new ApiCallback() {
            @Override
            public void done(JSONObject response) throws Exception {
                list.removeAllViews();
                JSONObject data = response.getJSONObject("data");
                JSONObject run = data.optJSONObject("run");
                runActive = run != null && "active".equals(run.optString("status"));
                updateRunButton();
                if (runActive) {
                    startLocationTicker();
                } else {
                    stopLocationTicker();
                }
                JSONObject summary = data.getJSONObject("summary");
                LinearLayout summaryBox = row();
                summaryBox.addView(stat("대상", summary.optInt("total")));
                summaryBox.addView(stat("미확인", summary.optInt("unchecked")));
                summaryBox.addView(stat("탑승", summary.optInt("boarded")));
                summaryBox.addView(stat("미탑승", summary.optInt("missed")));
                list.addView(summaryBox);
                final int unchecked = summary.optInt("unchecked");
                Button bulk = primaryButton(unchecked > 0 ? "미확인 전원 탑승" : "미확인 학생 없음");
                bulk.setEnabled(unchecked > 0);
                bulk.setAlpha(unchecked > 0 ? 1.0f : 0.45f);
                list.addView(bulk);
                bulk.setOnClickListener(new View.OnClickListener() {
                    @Override
                    public void onClick(View view) {
                        apiPost("/ieum/api/vehicle/bulk_boarding.php", "ride_type=" + enc(rideType), new ApiCallback() {
                            @Override
                            public void done(JSONObject response) {
                                loadRoster(list);
                            }
                        });
                    }
                });

                JSONArray stops = data.getJSONArray("stops");
                currentStops = stops;
                stopAnchors.clear();
                for (int i = 0; i < stops.length(); i++) {
                    JSONObject stop = stops.getJSONObject(i);
                    String stopRideType = stop.optString("ride_type", stop.optString("stop_type"));
                    String stopLabel = "dropoff".equals(stopRideType) ? "하차" : "픽업";
                    TextView stopTitle = text(stop.optString("stop_time") + " [" + stopLabel + "] " + stop.optString("stop_name"), 18, NAVY, true);
                    stopTitle.setPadding(dp(10), dp(12), dp(10), dp(8));
                    int stopId = stop.optInt("stop_id");
                    if (stopId == focusedStopId) {
                        stopTitle.setBackground(rounded(Color.rgb(219, 234, 254), Color.rgb(59, 130, 246), 12));
                        stopTitle.setText("현재 위치 근처 · " + stopTitle.getText());
                    }
                    list.addView(stopTitle);
                    stopAnchors.put(stopId, stopTitle);
                    JSONArray students = stop.getJSONArray("students");
                    LinearLayout cardRow = null;
                    for (int j = 0; j < students.length(); j++) {
                        if (j % 2 == 0) {
                            cardRow = new LinearLayout(MainActivity.this);
                            cardRow.setOrientation(LinearLayout.HORIZONTAL);
                            list.addView(cardRow);
                        }
                        if (cardRow != null) {
                            cardRow.addView(studentCard(students.getJSONObject(j), list), cardLayout(j % 2 == 0));
                        }
                    }
                    if (students.length() % 2 == 1 && cardRow != null) {
                        View spacer = new View(MainActivity.this);
                        cardRow.addView(spacer, cardLayout(false));
                    }
                }
                if (focusedStopId > 0) {
                    scrollToFocusedStop();
                } else if (silent && rosterScroll != null) {
                    rosterScroll.post(new Runnable() {
                        @Override
                        public void run() {
                            rosterScroll.scrollTo(0, lastScrollY);
                        }
                    });
                }
                setStatus((rideType.equals("all") ? "오늘 운행" : (rideType.equals("pickup") ? "등원" : "하원")) + " " + summary.optInt("total") + "명");
            }
        });
    }

    private View studentCard(final JSONObject student, final LinearLayout list) {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setPadding(dp(6), dp(7), dp(6), dp(7));
        GradientDrawable bg = rounded(Color.WHITE, Color.rgb(214, 226, 240), 12);
        String status = student.optString("status");
        if ("boarded".equals(status)) bg = rounded(Color.rgb(236, 253, 245), Color.rgb(134, 239, 172), 12);
        if ("missed".equals(status)) bg = rounded(Color.rgb(254, 242, 242), Color.rgb(252, 165, 165), 12);
        if ("called".equals(status)) bg = rounded(Color.rgb(255, 251, 235), Color.rgb(252, 211, 77), 12);
        card.setBackground(bg);
        LinearLayout top = new LinearLayout(this);
        top.setOrientation(LinearLayout.HORIZONTAL);
        top.setGravity(Gravity.CENTER_VERTICAL);
        card.addView(top);

        ImageView photo = new ImageView(this);
        photo.setScaleType(ImageView.ScaleType.CENTER_CROP);
        photo.setBackground(rounded(Color.rgb(229, 231, 235), Color.TRANSPARENT, 40));
        top.addView(photo, new LinearLayout.LayoutParams(dp(68), dp(68)));
        loadImage(photo, student.optString("photo_url"));

        LinearLayout info = new LinearLayout(this);
        info.setOrientation(LinearLayout.VERTICAL);
        info.setPadding(dp(6), 0, 0, 0);
        top.addView(info, new LinearLayout.LayoutParams(0, -2, 1));
        String cardRideType = student.optString("ride_type", rideType);
        String cardRideLabel = "dropoff".equals(cardRideType) ? "하차" : "픽업";
        TextView rideBadge = compactText(cardRideLabel, 9, "dropoff".equals(cardRideType) ? AMBER : BLUE, true);
        rideBadge.setTextColor(Color.WHITE);
        rideBadge.setGravity(Gravity.CENTER);
        rideBadge.setBackground(rounded("dropoff".equals(cardRideType) ? AMBER : BLUE, Color.TRANSPARENT, 18));
        LinearLayout.LayoutParams badgeLp = new LinearLayout.LayoutParams(dp(42), dp(20));
        badgeLp.bottomMargin = dp(2);
        info.addView(rideBadge, badgeLp);
        info.addView(compactText(student.optString("student_name"), 13, NAVY, true));
        String meta = student.optString("grade_label");
        String phone = student.optString("contact_phone");
        if (phone.length() > 0) {
            meta += " · " + phone;
        }
        info.addView(compactText(meta, 9, GRAY, false));
        String memo = student.optString("memo");
        if (memo.length() > 0) {
            info.addView(compactText(memo, 9, AMBER, true));
        }

        LinearLayout buttonRows = new LinearLayout(this);
        buttonRows.setOrientation(LinearLayout.VERTICAL);
        buttonRows.setPadding(0, dp(5), 0, 0);
        card.addView(buttonRows);

        LinearLayout buttons1 = row();
        LinearLayout buttons2 = row();
        buttonRows.addView(buttons1);
        LinearLayout.LayoutParams row2 = new LinearLayout.LayoutParams(-1, -2);
        row2.topMargin = dp(3);
        buttonRows.addView(buttons2, row2);
        buttons1.addView(actionButton("dropoff".equals(cardRideType) ? "하차" : "탑승", GREEN, student, "boarded", list));
        buttons1.addView(actionButton("dropoff".equals(cardRideType) ? "미하차" : "미탑", RED, student, "missed", list));
        buttons2.addView(actionButton("통화", AMBER, student, "called", list));
        buttons2.addView(actionButton("개별", GRAY, student, "self", list));

        photo.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                showMemoDialog(student, list);
            }
        });
        return card;
    }

    private Button actionButton(String label, int color, final JSONObject student, final String status, final LinearLayout list) {
        Button button = new Button(this);
        button.setText(label);
        button.setTextColor(Color.WHITE);
        button.setTextSize(9);
        button.setTypeface(Typeface.DEFAULT_BOLD);
        button.setBackground(rounded(color, Color.TRANSPARENT, 8));
        button.setSingleLine(true);
        button.setIncludeFontPadding(false);
        LinearLayout.LayoutParams bp = new LinearLayout.LayoutParams(0, dp(26), 1);
        bp.setMargins(dp(1), 0, dp(1), 0);
        button.setLayoutParams(bp);
        button.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View view) {
                if ("called".equals(status)) {
                    openDial(student.optString("contact_phone"));
                }
                saveBoarding(student.optInt("student_vehicle_id"), status, "", list);
            }
        });
        return button;
    }

    private void openDial(String phone) {
        String digits = phone == null ? "" : phone.replaceAll("[^0-9+]", "");
        if (digits.length() == 0) return;
        try {
            Intent intent = new Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + digits));
            startActivity(intent);
        } catch (Exception ignored) {
            setStatus("전화 앱을 열 수 없습니다.");
        }
    }

    private void updateRunButton() {
        if (runButton == null) return;
        runButton.setText(runActive ? "운행 종료" : "운행 시작");
        runButton.setBackground(rounded(runActive ? RED : BLUE, Color.TRANSPARENT, 8));
    }

    private void startLocationTicker() {
        stopLocationTicker();
        sendLocationOnce();
        locationTicker = new Runnable() {
            @Override
            public void run() {
                if (!runActive) return;
                sendLocationOnce();
                locationHandler.postDelayed(this, 60000);
            }
        };
        locationHandler.postDelayed(locationTicker, 60000);
    }

    private void stopLocationTicker() {
        if (locationTicker != null) {
            locationHandler.removeCallbacks(locationTicker);
            locationTicker = null;
        }
    }

    private void startRosterTicker() {
        stopRosterTicker();
        rosterTicker = new Runnable() {
            @Override
            public void run() {
                if (driverToken.length() > 0 && rosterList != null && !busy) {
                    loadRoster(rosterList, true);
                }
                rosterHandler.postDelayed(this, ROSTER_REFRESH_MS);
            }
        };
        rosterHandler.postDelayed(rosterTicker, ROSTER_REFRESH_MS);
    }

    private void stopRosterTicker() {
        if (rosterTicker != null) {
            rosterHandler.removeCallbacks(rosterTicker);
            rosterTicker = null;
        }
    }

    private void showMemoDialog(final JSONObject student, final LinearLayout list) {
        final EditText input = new EditText(this);
        input.setMinLines(3);
        input.setHint("기사님 메모");
        input.setText(student.optString("boarding_note"));
        new AlertDialog.Builder(this)
                .setTitle(student.optString("student_name") + " 메모")
                .setView(input)
                .setPositiveButton("저장", (dialog, which) -> saveBoarding(student.optInt("student_vehicle_id"), student.optString("status", "called"), input.getText().toString(), list))
                .setNegativeButton("취소", null)
                .show();
    }

    private void saveBoarding(int studentVehicleId, String status, String note, final LinearLayout list) {
        String body = "student_vehicle_id=" + studentVehicleId + "&status=" + enc(status) + "&note=" + enc(note);
        apiPost("/ieum/api/vehicle/boarding.php", body, new ApiCallback() {
            @Override
            public void done(JSONObject response) {
                loadRoster(list);
            }
        });
    }

    private void sendLocationOnce() {
        if (checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION}, REQ_LOCATION);
            return;
        }
        try {
            LocationManager manager = (LocationManager) getSystemService(Context.LOCATION_SERVICE);
            Location location = null;
            if (manager != null) {
                location = manager.getLastKnownLocation(LocationManager.GPS_PROVIDER);
                if (location == null) {
                    location = manager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER);
                }
            }
            if (location == null) {
                setStatus("위치 확인 대기");
                return;
            }
            focusNearbyStop(location);
            String body = "ride_type=" + enc(rideType) + "&lat=" + location.getLatitude() + "&lng=" + location.getLongitude() + "&accuracy=" + location.getAccuracy();
            apiPost("/ieum/api/vehicle/location.php", body, new ApiCallback() {
                @Override
                public void done(JSONObject response) {
                    if (focusedStopId <= 0) {
                        setStatus("위치 전송 완료");
                    }
                }
            });
        } catch (Exception e) {
            setStatus("위치 전송 실패");
        }
    }

    private void focusNearbyStop(Location location) {
        if (currentStops == null || currentStops.length() == 0 || location == null) return;
        long now = System.currentTimeMillis();
        if (now - lastAutoFocusAt < 15000) return;

        int bestStopId = 0;
        String bestStopName = "";
        float bestDistance = 999999f;
        for (int i = 0; i < currentStops.length(); i++) {
            JSONObject stop = currentStops.optJSONObject(i);
            if (stop == null || stop.isNull("map_lat") || stop.isNull("map_lng")) continue;
            if (!isTimeNear(stop.optString("stop_time"))) continue;

            float[] results = new float[1];
            Location.distanceBetween(
                    location.getLatitude(),
                    location.getLongitude(),
                    stop.optDouble("map_lat"),
                    stop.optDouble("map_lng"),
                    results
            );
            if (results[0] <= 50f && results[0] < bestDistance) {
                bestDistance = results[0];
                bestStopId = stop.optInt("stop_id");
                bestStopName = stop.optString("stop_time") + " " + stop.optString("stop_name");
            }
        }

        if (bestStopId > 0) {
            focusedStopId = bestStopId;
            lastAutoFocusAt = now;
            setStatus("정류장 50m 접근 · " + bestStopName);
            scrollToFocusedStop();
        }
    }

    private boolean isTimeNear(String hhmmss) {
        if (hhmmss == null || hhmmss.length() < 5) return true;
        try {
            int stopMinutes = Integer.parseInt(hhmmss.substring(0, 2)) * 60 + Integer.parseInt(hhmmss.substring(3, 5));
            java.util.Calendar cal = java.util.Calendar.getInstance();
            int nowMinutes = cal.get(java.util.Calendar.HOUR_OF_DAY) * 60 + cal.get(java.util.Calendar.MINUTE);
            return Math.abs(nowMinutes - stopMinutes) <= 30;
        } catch (Exception ignored) {
            return true;
        }
    }

    private void scrollToFocusedStop() {
        if (focusedStopId <= 0 || rosterScroll == null) return;
        final View target = stopAnchors.get(focusedStopId);
        if (target == null) return;
        rosterScroll.post(new Runnable() {
            @Override
            public void run() {
                rosterScroll.smoothScrollTo(0, Math.max(0, target.getTop() - dp(8)));
            }
        });
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == REQ_LOCATION) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                sendLocationOnce();
            } else {
                setStatus("위치 권한이 필요합니다.");
            }
        }
    }

    private void apiGet(String path, ApiCallback callback) {
        request("GET", path, "", callback);
    }

    private void apiPost(String path, String body, ApiCallback callback) {
        request("POST", path, body, callback);
    }

    private void request(final String method, final String path, final String body, final ApiCallback callback) {
        if (busy) return;
        busy = true;
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    URL url = new URL(baseUrl + path);
                    HttpURLConnection conn = (HttpURLConnection) url.openConnection();
                    conn.setConnectTimeout(10000);
                    conn.setReadTimeout(10000);
                    conn.setRequestMethod(method);
                    conn.setRequestProperty("Accept", "application/json");
                    if (driverToken.length() > 0) {
                        conn.setRequestProperty("Authorization", "Bearer " + driverToken);
                    }
                    if ("POST".equals(method)) {
                        conn.setDoOutput(true);
                        conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=utf-8");
                        OutputStream os = conn.getOutputStream();
                        os.write(body.getBytes(StandardCharsets.UTF_8));
                        os.close();
                    }
                    int code = conn.getResponseCode();
                    InputStream is = code >= 200 && code < 400 ? conn.getInputStream() : conn.getErrorStream();
                    String responseText = readAll(is);
                    final JSONObject response = new JSONObject(responseText);
                    final boolean ok = response.optBoolean("ok", false);
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            busy = false;
                            if (!ok) {
                                setStatus(response.optString("message", "요청 실패"));
                                return;
                            }
                            try {
                                callback.done(response);
                            } catch (Exception e) {
                                setStatus("응답 처리 오류");
                            }
                        }
                    });
                } catch (final Exception e) {
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            busy = false;
                            setStatus("연결 오류: " + e.getMessage());
                        }
                    });
                }
            }
        }).start();
    }

    private String readAll(InputStream is) throws Exception {
        if (is == null) return "";
        BufferedReader reader = new BufferedReader(new InputStreamReader(is, StandardCharsets.UTF_8));
        StringBuilder sb = new StringBuilder();
        String line;
        while ((line = reader.readLine()) != null) {
            sb.append(line);
        }
        return sb.toString();
    }

    private void loadImage(final ImageView view, final String url) {
        if (url == null || url.length() == 0) {
            view.setImageResource(android.R.drawable.sym_def_app_icon);
            return;
        }
        new Thread(new Runnable() {
            @Override
            public void run() {
                try {
                    InputStream is = new URL(url).openStream();
                    final Bitmap bmp = BitmapFactory.decodeStream(is);
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            view.setImageBitmap(bmp);
                        }
                    });
                } catch (Exception e) {
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            view.setImageResource(android.R.drawable.sym_def_app_icon);
                        }
                    });
                }
            }
        }).start();
    }

    private TextView label(String value) {
        TextView view = text(value, 14, NAVY, true);
        view.setPadding(0, dp(12), 0, dp(5));
        return view;
    }

    private EditText input(String value, String hint) {
        EditText input = new EditText(this);
        input.setText(value);
        input.setHint(hint);
        input.setTextSize(16);
        input.setSingleLine(true);
        input.setPadding(dp(12), 0, dp(12), 0);
        input.setBackground(rounded(Color.WHITE, Color.rgb(203, 213, 225), 8));
        input.setLayoutParams(new LinearLayout.LayoutParams(-1, dp(50)));
        return input;
    }

    private void hideKeyboard() {
        try {
            View view = getCurrentFocus();
            if (view != null) {
                InputMethodManager imm = (InputMethodManager) getSystemService(Context.INPUT_METHOD_SERVICE);
                if (imm != null) {
                    imm.hideSoftInputFromWindow(view.getWindowToken(), 0);
                }
                view.clearFocus();
            }
        } catch (Exception ignored) {
        }
    }

    private LinearLayout.LayoutParams actionLayout() {
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, dp(48), 1);
        lp.setMargins(dp(2), 0, dp(2), 0);
        return lp;
    }

    private void compactTopButton(Button button) {
        button.setTextSize(13);
        button.setSingleLine(true);
        button.setIncludeFontPadding(false);
        button.setPadding(dp(2), 0, dp(2), 0);
    }

    private LinearLayout.LayoutParams cardLayout(boolean left) {
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, -2, 1);
        lp.setMargins(left ? 0 : dp(4), dp(4), left ? dp(4) : 0, dp(6));
        return lp;
    }

    private TextView text(String value, int size, int color, boolean bold) {
        TextView view = new TextView(this);
        view.setText(value);
        view.setTextSize(size);
        view.setTextColor(color);
        if (bold) view.setTypeface(Typeface.DEFAULT_BOLD);
        return view;
    }

    private TextView compactText(String value, int size, int color, boolean bold) {
        TextView view = text(value, size, color, bold);
        view.setSingleLine(true);
        view.setEllipsize(TextUtils.TruncateAt.END);
        view.setIncludeFontPadding(false);
        return view;
    }

    private Button primaryButton(String value) {
        Button btn = new Button(this);
        btn.setText(value);
        btn.setTextColor(Color.WHITE);
        btn.setTextSize(15);
        btn.setTypeface(Typeface.DEFAULT_BOLD);
        btn.setSingleLine(true);
        btn.setIncludeFontPadding(false);
        btn.setBackground(rounded(BLUE, Color.TRANSPARENT, 8));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, dp(52));
        lp.topMargin = dp(10);
        btn.setLayoutParams(lp);
        return btn;
    }

    private Button secondaryButton(String value) {
        Button btn = new Button(this);
        btn.setText(value);
        btn.setTextColor(NAVY);
        btn.setTextSize(14);
        btn.setTypeface(Typeface.DEFAULT_BOLD);
        btn.setSingleLine(true);
        btn.setIncludeFontPadding(false);
        btn.setBackground(rounded(Color.WHITE, Color.rgb(203, 213, 225), 8));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, dp(48));
        lp.topMargin = dp(8);
        btn.setLayoutParams(lp);
        return btn;
    }

    private LinearLayout row() {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);
        return row;
    }

    private TextView stat(String label, int count) {
        TextView v = text(label + "\n" + count + "명", 15, NAVY, true);
        v.setGravity(Gravity.CENTER);
        v.setBackground(rounded(Color.WHITE, Color.rgb(214, 226, 240), 10));
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, dp(64), 1);
        lp.setMargins(dp(3), dp(3), dp(3), dp(6));
        v.setLayoutParams(lp);
        return v;
    }

    private GradientDrawable rounded(int color, int stroke, int radius) {
        GradientDrawable d = new GradientDrawable();
        d.setColor(color);
        d.setCornerRadius(dp(radius));
        if (stroke != Color.TRANSPARENT) d.setStroke(dp(1), stroke);
        return d;
    }

    private void setStatus(String text) {
        if (statusText != null) statusText.setText(text);
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density + 0.5f);
    }

    private String stripSlash(String value) {
        value = value.trim();
        while (value.endsWith("/")) value = value.substring(0, value.length() - 1);
        return value;
    }

    private String enc(String value) {
        try {
            return URLEncoder.encode(value == null ? "" : value, "UTF-8");
        } catch (Exception e) {
            return "";
        }
    }

    interface ApiCallback {
        void done(JSONObject response) throws Exception;
    }
}
