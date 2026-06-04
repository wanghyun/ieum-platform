<?php
$sub_menu = '950132';
require_once './_common.php';
require_once IEUM_PATH . '/lib/sms_gateway_device.php';
require_once IEUM_PATH . '/lib/dashboard.php';

$g5['title'] = '아이이음 문자 발송폰 관리';
$current_academy = ieum_require_academy_page();
$academy_id = (int) $current_academy['academy_id'];
$message = '';
$error = '';

ieum_sms_gateway_ensure_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    $action = isset($_POST['action']) ? preg_replace('/[^a-z_]/', '', $_POST['action']) : '';
    if (!ieum_verify_csrf_token($post_token)) {
        $error = '잘못된 요청입니다.';
    } elseif ($action === 'save_schedule') {
        $day_mode = isset($_POST['sms_day_mode']) ? trim($_POST['sms_day_mode']) : 'weekday';
        $day_options = ieum_sms_gateway_day_mode_options();
        if (!isset($day_options[$day_mode])) {
            $day_mode = 'weekday';
        }

        $sms_start_time = isset($_POST['sms_start_time']) ? trim($_POST['sms_start_time']) : '10:00';
        $sms_end_time = isset($_POST['sms_end_time']) ? trim($_POST['sms_end_time']) : '20:00';
        if (!ieum_sms_gateway_valid_time($sms_start_time) || !ieum_sms_gateway_valid_time($sms_end_time)) {
            $error = '자동 발송 시간을 다시 확인해 주세요.';
        } else {
            sql_query("
                update " . IEUM_ACADEMY_TABLE . "
                   set sms_day_mode = '" . sql_escape_string($day_mode) . "',
                       sms_start_time = '" . sql_escape_string($sms_start_time) . "',
                       sms_end_time = '" . sql_escape_string($sms_end_time) . "',
                       sms_poll_seconds = 30,
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
            ", false);
            $current_academy['sms_day_mode'] = $day_mode;
            $current_academy['sms_start_time'] = $sms_start_time;
            $current_academy['sms_end_time'] = $sms_end_time;
            $current_academy['sms_poll_seconds'] = 30;
            $message = '문자 자동 발송 시간이 저장되었습니다. 연결된 문자폰에 자동 적용됩니다.';
        }
    } elseif ($action === 'create_pairing') {
        $device_name = isset($_POST['device_name']) ? trim($_POST['device_name']) : '';
        $device = ieum_sms_gateway_create_pairing($academy_id, $device_name, isset($member['mb_id']) ? $member['mb_id'] : '');
        if ($device) {
            $message = '연결 코드 ' . $device['pairing_code'] . '가 생성되었습니다. 30분 안에 문자앱에 입력해 주세요.';
        } else {
            $error = '연결 코드를 생성하지 못했습니다.';
        }
    } elseif ($action === 'disable') {
        $device_id = isset($_POST['device_id']) ? (int) $_POST['device_id'] : 0;
        ieum_sms_gateway_disable($academy_id, $device_id);
        $message = '문자 발송폰 연결을 해제했습니다.';
    } else {
        $error = '잘못된 요청입니다.';
    }
}

$csrf_token = ieum_new_csrf_token();
$sms_device_shortcut_catalog = function_exists('ieum_dashboard_shortcut_catalog') ? ieum_dashboard_shortcut_catalog() : array();
$sms_device_shortcut_keys = function_exists('ieum_dashboard_get_shortcut_keys') ? ieum_dashboard_get_shortcut_keys($academy_id) : array();
$sms_device_default_shortcut_keys = function_exists('ieum_dashboard_default_shortcut_keys') ? ieum_dashboard_default_shortcut_keys() : array();
$day_options = ieum_sms_gateway_day_mode_options();
$sms_day_mode = isset($current_academy['sms_day_mode']) && isset($day_options[$current_academy['sms_day_mode']]) ? $current_academy['sms_day_mode'] : 'weekday';
$sms_start_time = isset($current_academy['sms_start_time']) && ieum_sms_gateway_valid_time($current_academy['sms_start_time']) ? $current_academy['sms_start_time'] : '10:00';
$sms_end_time = isset($current_academy['sms_end_time']) && ieum_sms_gateway_valid_time($current_academy['sms_end_time']) ? $current_academy['sms_end_time'] : '20:00';
$sms_app_path = IEUM_PATH . '/assets/apps/ieum-sms-gateway-debug.apk';
$sms_app_url = IEUM_URL . '/assets/apps/ieum-sms-gateway-debug.apk';
$sms_app_size = is_file($sms_app_path) ? filesize($sms_app_path) : 0;

$devices = sql_query("
    select *
      from " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
     where academy_id = '{$academy_id}'
  order by field(device_status, 'active', 'pending', 'disabled'), device_id desc
", false);

function ieum_sms_device_status_label($status)
{
    $labels = array(
        'active' => '연결됨',
        'pending' => '연결 대기',
        'disabled' => '해제됨',
    );
    return isset($labels[$status]) ? $labels[$status] : $status;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:none!important;margin:0 86px 0 248px!important;padding:84px 28px 42px!important}
.hero,.panel{background:#fff;border:1px solid #d9dee7;border-radius:18px;box-shadow:0 12px 28px rgba(15,23,42,.06)}
.hero{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:24px;margin-bottom:18px}
h1{margin:0;font-size:32px;letter-spacing:-.01em}.meta{color:#667085;margin-top:6px;line-height:1.5}
.panel{padding:22px;margin-bottom:18px}.grid{display:grid;grid-template-columns:420px 1fr;gap:18px;align-items:start}.form{display:grid;gap:10px}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}label{font-weight:900;color:#344054}
input,select{height:44px;border:1px solid #cfd6df;border-radius:12px;padding:0 12px;background:#fff}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:1px solid #cfd6df;border-radius:12px;background:#fff;color:#111827;text-decoration:none;padding:8px 13px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.dark{background:#101828;border-color:#101828;color:#fff}.danger{color:#a4262c;border-color:#efb2b2;background:#fff5f5}
.notice{padding:12px 14px;border-radius:12px;margin-bottom:14px;font-weight:800}.ok{background:#eef9f1;color:#176b2c;border:1px solid #9bd3ad}.err{background:#fdecec;color:#a4262c;border:1px solid #efb2b2}
.steps{display:grid;gap:10px;margin-top:14px}.step{border:1px solid #e4e9f2;border-radius:14px;padding:14px;background:#f8fbff}.step strong{display:block;margin-bottom:4px}.code{font-size:28px;font-weight:1000;letter-spacing:4px;color:#1769c2}
.table-wrap{overflow:auto;border:1px solid #d8dee9;border-radius:14px}table{width:100%;border-collapse:collapse;background:#fff;min-width:0;table-layout:fixed}th,td{border:1px solid #d8dee9;padding:10px 8px;text-align:center;font-size:13px;word-break:keep-all}th{background:#72829d;color:#fff}.left{text-align:left}.status-active{color:#176b2c;font-weight:1000}.status-pending{color:#9a5b00;font-weight:1000}.status-disabled{color:#667085;font-weight:1000}.muted{color:#667085;font-size:13px}.token{font-family:ui-monospace,Consolas,monospace;font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.device-note{display:block;margin-top:4px;color:#667085;font-size:12px;line-height:1.35}
.schedule-summary{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:10px 0 16px}.pill{border-radius:16px;background:#eef4ff;color:#1849a9;padding:14px 16px;font-weight:900}.hint{line-height:1.6;color:#667085;font-size:14px}.download-card{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center}.download-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.download-url{margin-top:10px;padding:10px 12px;border-radius:10px;background:#f8fafc;border:1px solid #e4e9f2;font-family:ui-monospace,Consolas,monospace;color:#344054;word-break:break-all}
.install-line{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;color:#475569;font-size:13px;font-weight:800}.install-line b{color:#111827}.install-line span{color:#94a3b8}
@media(max-width:1200px){.grid{grid-template-columns:1fr}}@media(max-width:760px){.wrap{margin:0 72px 0 0!important;padding:76px 14px 24px!important}.hero,.download-card{display:block}.form-row,.schedule-summary{grid-template-columns:1fr}.download-actions{justify-content:flex-start;margin-top:12px}}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .hero,
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .panel{
    border-radius:8px!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .hero{
    padding:0!important;
    border:0!important;
    background:transparent!important;
    margin-bottom:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune h1{
    margin:0!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune td{
    border-color:#e5ebf3!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .grid{
    grid-template-columns:minmax(300px,360px) minmax(0,1fr)!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .grid>*,
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .table-wrap{
    min-width:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .table-wrap{
    max-width:100%!important;
    overflow-x:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .panel{
    padding:18px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .step{
    padding:10px 12px!important;
    border-radius:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .install-line{
    margin-top:10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .download-url{
    padding:8px 10px!important;
    font-size:12px!important;
}
@media(max-width:1100px){
    body.ieum-side-layout.ieum-dashboard-page.sms-devices-page-tune .grid{
        grid-template-columns:1fr!important;
    }
}
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark{
    background:#0f1724!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark .hero,
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark .panel,
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark .step,
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark .pill,
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark .download-url,
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark .table-wrap{
    background:#151f2e!important;
    border-color:#2c3a4f!important;
    color:#e5edf7!important;
    box-shadow:none!important;
}
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark th{
    background:#1b2535!important;
    color:#d9e2ef!important;
    border-color:#2c3a4f!important;
}
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark td{
    background:#151f2e!important;
    color:#d9e2ef!important;
    border-color:#263244!important;
}
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark input,
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark select,
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark textarea,
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark .btn{
    background:#111827!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark .primary,
body.ieum-dashboard-page.sms-devices-page-tune.ieum-dark .btn.primary{
    background:#1f7dd9!important;
    border-color:#1f7dd9!important;
    color:#fff!important;
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page sms-devices-page-tune">
<?php echo ieum_admin_header('sms_devices', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>문자 발송폰 관리</h1>
            <div class="meta"><?php echo get_text($current_academy['academy_name']); ?> · 연결된 문자폰은 이 도장의 문자 발송만 처리합니다.</div>
        </div>
        <a class="btn dark" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php">발송현황 보기</a>
    </section>
    <?php if ($message) { ?><div class="notice ok"><?php echo get_text($message); ?></div><?php } ?>
    <?php if ($error) { ?><div class="notice err"><?php echo get_text($error); ?></div><?php } ?>

    <section class="panel">
        <div class="download-card">
            <div>
                <h2>문자 발송 앱 설치</h2>
                <div class="hint">각 도장은 이 화면에서 앱 파일을 내려받고 연결 코드로 자기 도장에만 연결합니다. 앱은 연결된 도장의 문자만 가져가며 실제 발송은 휴대폰에서 처리합니다.</div>
                <div class="download-url">휴대폰 설치 파일이 준비되어 있습니다.</div>
                <?php if ($sms_app_size > 0) { ?><div class="muted">Android 전용 · 문자 발송폰에 설치합니다.</div><?php } ?>
            </div>
            <div class="download-actions">
                <?php if ($sms_app_size > 0) { ?>
                    <a class="btn primary" href="<?php echo get_text($sms_app_url); ?>" download>앱 다운로드</a>
                <?php } else { ?>
                    <span class="notice err">앱 파일이 아직 준비되지 않았습니다.</span>
                <?php } ?>
                <a class="btn dark" href="#device_name">연결 코드 만들기</a>
            </div>
        </div>
        <div class="install-line">
            <b>설치 흐름</b><span>·</span> 앱 다운로드 <span>→</span> 앱 설치 허용 <span>→</span> 연결 코드 입력 <span>→</span> 자동 발송 확인
        </div>
    </section>

    <section class="grid">
        <div>
            <article class="panel">
                <h2>자동 발송 설정</h2>
                <div class="schedule-summary">
                    <span class="pill"><?php echo get_text(ieum_sms_gateway_day_mode_label($sms_day_mode)); ?></span>
                    <span class="pill"><?php echo get_text($sms_start_time . ' ~ ' . $sms_end_time); ?></span>
                </div>
                <form method="post" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_schedule">
                    <label for="sms_day_mode">자동 발송 요일</label>
                    <select name="sms_day_mode" id="sms_day_mode">
                        <?php foreach ($day_options as $value => $label) { ?>
                            <option value="<?php echo get_text($value); ?>" <?php echo $sms_day_mode === $value ? 'selected' : ''; ?>><?php echo get_text($label); ?></option>
                        <?php } ?>
                    </select>
                    <div class="form-row">
                        <div>
                            <label for="sms_start_time">자동 시작</label>
                            <input type="time" name="sms_start_time" id="sms_start_time" value="<?php echo get_text($sms_start_time); ?>" required>
                        </div>
                        <div>
                            <label for="sms_end_time">자동 종료</label>
                            <input type="time" name="sms_end_time" id="sms_end_time" value="<?php echo get_text($sms_end_time); ?>" required>
                        </div>
                    </div>
                    <button type="submit" class="btn primary">자동 설정 저장</button>
                    <div class="hint">도장별로 저장됩니다. 연결된 문자폰은 이 설정에 맞춰 정해진 시간에만 문자를 확인합니다.</div>
                </form>
            </article>

            <article class="panel">
                <h2>새 발송폰 연결</h2>
                <form method="post" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                    <input type="hidden" name="action" value="create_pairing">
                    <label for="device_name">기기 이름</label>
                    <input type="text" name="device_name" id="device_name" placeholder="예: 문자폰 1, 대표 발송폰" maxlength="80">
                    <button type="submit" class="btn primary">연결 코드 생성</button>
                </form>
                <div class="hint">연결 코드는 30분 동안 사용할 수 있습니다. 문자앱에 서버 주소와 6자리 코드를 입력하면 이 도장 문자만 가져갑니다.</div>
            </article>
        </div>

        <article class="panel">
            <h2>등록된 발송폰</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>상태</th><th>기기명</th><th>연결 코드</th><th>최근 확인</th><th>관리</th></tr>
                    </thead>
                    <tbody>
                    <?php $i = 0; while ($row = sql_fetch_array($devices)) { $i++; ?>
                        <tr>
                            <td class="status-<?php echo get_text($row['device_status']); ?>"><?php echo get_text(ieum_sms_device_status_label($row['device_status'])); ?></td>
                            <td class="left">
                                <?php echo get_text($row['device_name']); ?>
                                <span class="device-note"><?php echo get_text($row['device_model']); ?></span>
                            </td>
                            <td><?php echo $row['pairing_code'] ? '<span class="code">' . get_text($row['pairing_code']) . '</span><br><span class="muted">' . get_text($row['pairing_expires_at']) . ' 만료</span>' : '-'; ?></td>
                            <td class="left">
                                확인 <?php echo get_text($row['last_seen_at'] ?: '-'); ?>
                                <span class="device-note">발송 <?php echo get_text($row['last_sent_at'] ?: '-'); ?></span>
                                <?php if (!empty($row['last_error'])) { ?><span class="device-note danger">오류 <?php echo get_text($row['last_error']); ?></span><?php } ?>
                            </td>
                            <td>
                                <?php if ($row['device_status'] !== 'disabled') { ?>
                                <form method="post" onsubmit="return confirm('이 문자 발송폰 연결을 해제할까요?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo get_text($csrf_token); ?>">
                                    <input type="hidden" name="action" value="disable">
                                    <input type="hidden" name="device_id" value="<?php echo (int) $row['device_id']; ?>">
                                    <button type="submit" class="btn danger">해제</button>
                                </form>
                                <?php } else { echo '-'; } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    <?php if ($i === 0) { ?><tr><td colspan="5">등록된 문자 발송폰이 없습니다.</td></tr><?php } ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</main>
<?php
$sms_device_shortcut_js_catalog = array();
foreach ($sms_device_shortcut_catalog as $shortcut_key => $shortcut_item) {
    $sms_device_shortcut_js_catalog[$shortcut_key] = array(
        'label' => isset($shortcut_item['label']) ? $shortcut_item['label'] : $shortcut_key,
        'desc' => isset($shortcut_item['desc']) ? $shortcut_item['desc'] : '',
        'url' => isset($shortcut_item['url']) ? $shortcut_item['url'] : '#',
    );
}
?>
<script>
(function(){
    var rootSelector = '.sms-devices-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) {
        brandText.textContent = <?php echo json_encode($current_academy['academy_name']); ?>;
    }

    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) {
        homeLink.textContent = '아이이음 교육페이지';
    }

    var meta = document.querySelector(rootSelector + ' .ieum-shell-meta');
    if (meta) {
        var now = new Date();
        var hh = String(now.getHours()).padStart(2, '0');
        var mm = String(now.getMinutes()).padStart(2, '0');
        meta.innerHTML = ''
            + '<span class="dashboard-shell-meta-inner">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php">개발지원센터</a>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-help-group">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#qna">Q&A</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#faq">자주하는 질문</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#contact">문의하기</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#chatbot">AI 챗봇</a>'
            + '</span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span><?php echo get_text($current_academy['academy_name']); ?></span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-clock">' + hh + ':' + mm + '</span>'
            + '</span>';
    }
})();
</script>
<script>
(function(){
    var rootSelector = '.sms-devices-page-tune.ieum-dashboard-page';
    var shortcutCatalog = <?php echo json_encode($sms_device_shortcut_js_catalog, JSON_UNESCAPED_UNICODE); ?>;
    var currentShortcutKeys = <?php echo json_encode(array_values($sms_device_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var defaultShortcutKeys = <?php echo json_encode(array_values($sms_device_default_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var shortcutMax = 6;
    var shortcutSaveUrl = <?php echo json_encode(IEUM_URL . '/dashboard.php', JSON_UNESCAPED_UNICODE); ?>;
    var shortcutCsrfToken = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
    var metaInner = document.querySelector(rootSelector + ' .dashboard-shell-meta-inner');
    var themeIconPaths = {
        dark: '<path d="M20 14.2A7.5 7.5 0 0 1 9.8 4a8 8 0 1 0 10.2 10.2Z"/>',
        light: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="M4.9 4.9l1.4 1.4"/><path d="M17.7 17.7l1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="M4.9 19.1l1.4-1.4"/><path d="M17.7 6.3l1.4-1.4"/>'
    };
    var setThemeButton = function(button, theme) {
        var isDark = theme === 'dark';
        document.body.classList.toggle('ieum-dark', isDark);
        try {
            localStorage.setItem('ieumDashboardTheme', isDark ? 'dark' : 'light');
        } catch (error) {}
        button.setAttribute('aria-label', isDark ? '라이트 모드로 변경' : '다크 모드로 변경');
        button.setAttribute('title', isDark ? '라이트 모드로 변경' : '다크 모드로 변경');
        button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + (isDark ? themeIconPaths.light : themeIconPaths.dark) + '</svg>';
    };
    if (metaInner && !metaInner.querySelector('.dashboard-theme-toggle')) {
        var aiHelpLink = metaInner.querySelector('a[href*="#chatbot"], a[href*="topic=ai"]');
        if (aiHelpLink) {
            aiHelpLink.textContent = 'AI 챗봇';
        }
        var themeButton = document.createElement('button');
        themeButton.type = 'button';
        themeButton.className = 'dashboard-theme-toggle';
        metaInner.appendChild(themeButton);
        var savedTheme = '';
        try {
            savedTheme = localStorage.getItem('ieumDashboardTheme') || '';
        } catch (error) {}
        setThemeButton(themeButton, savedTheme === 'dark' || document.body.classList.contains('ieum-dark') ? 'dark' : 'light');
        themeButton.addEventListener('click', function() {
            setThemeButton(themeButton, document.body.classList.contains('ieum-dark') ? 'light' : 'dark');
        });
    }
    var shortcutButtons = [];
    var shortcutToast = document.createElement('span');
    var shortcutToastTimer = null;
    var shortcutToastDefault = '<span>최대 6개까지 선택할 수 있습니다.</span><span>보조정보- 업무바로가기에서 확인하세요.</span>';
    shortcutToast.className = 'side-favorite-toast';
    shortcutToast.innerHTML = shortcutToastDefault;
    document.body.appendChild(shortcutToast);
    var normalizeShortcutKeys = function(keys) {
        var seen = {};
        var normalized = [];
        (keys || []).forEach(function(key) {
            key = String(key || '');
            if (!shortcutCatalog[key] || seen[key]) {
                return;
            }
            seen[key] = true;
            normalized.push(key);
        });
        return normalized.slice(0, shortcutMax);
    };
    currentShortcutKeys = normalizeShortcutKeys(currentShortcutKeys);
    if (!currentShortcutKeys.length) {
        currentShortcutKeys = normalizeShortcutKeys(defaultShortcutKeys);
    }
    var shortcutPath = function(url) {
        try {
            var parsed = new URL(url, window.location.href);
            return parsed.pathname.replace(/\/+$/, '');
        } catch (error) {
            return String(url || '').split('?')[0].split('#')[0].replace(/\/+$/, '');
        }
    };
    var shortcutByPath = {};
    Object.keys(shortcutCatalog || {}).forEach(function(key) {
        var path = shortcutPath(shortcutCatalog[key].url);
        if (path && !shortcutByPath[path]) {
            shortcutByPath[path] = key;
        }
    });
    var showShortcutToast = function(button, message) {
        shortcutToast.innerHTML = message || shortcutToastDefault;
        var left = 214;
        var top = 160;
        if (button && button.getBoundingClientRect) {
            var rect = button.getBoundingClientRect();
            left = Math.max(12, rect.left - 166);
            top = Math.max(72, rect.top + rect.height / 2 - 15);
        }
        shortcutToast.style.left = left + 'px';
        shortcutToast.style.top = top + 'px';
        shortcutToast.classList.add('is-show');
        window.clearTimeout(shortcutToastTimer);
        shortcutToastTimer = window.setTimeout(function() {
            shortcutToast.classList.remove('is-show');
        }, 1900);
    };
    var updateShortcutButtons = function() {
        shortcutButtons.forEach(function(button) {
            var key = button.getAttribute('data-shortcut-key') || '';
            var item = shortcutCatalog[key] || {};
            var active = currentShortcutKeys.indexOf(key) !== -1;
            button.classList.toggle('is-active', active);
            button.textContent = active ? '★' : '☆';
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.setAttribute('title', active ? '업무 바로가기에서 제거' : '업무 바로가기에 추가');
            button.setAttribute('aria-label', (item.label || '메뉴') + (active ? ' 즐겨찾기 제거' : ' 즐겨찾기 추가'));
        });
    };
    var saveShortcutKeys = function(keys) {
        if (!window.fetch || !window.FormData || !shortcutSaveUrl) {
            return;
        }
        var formData = new FormData();
        formData.append('csrf_token', shortcutCsrfToken);
        formData.append('action', 'save_dashboard_shortcuts');
        keys.forEach(function(key) {
            formData.append('shortcut_keys[]', key);
        });
        fetch(shortcutSaveUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).catch(function() {});
    };
    var toggleShortcut = function(key, button) {
        if (!shortcutCatalog[key]) {
            return;
        }
        var nextKeys = currentShortcutKeys.slice();
        var index = nextKeys.indexOf(key);
        if (index === -1) {
            if (nextKeys.length >= shortcutMax) {
                showShortcutToast(button, '');
                return;
            }
            nextKeys.push(key);
        } else {
            nextKeys.splice(index, 1);
        }
        if (!nextKeys.length) {
            nextKeys = normalizeShortcutKeys(defaultShortcutKeys);
        }
        currentShortcutKeys = normalizeShortcutKeys(nextKeys);
        updateShortcutButtons();
        if (button) {
            button.classList.add('is-pulse');
            window.setTimeout(function() {
                button.classList.remove('is-pulse');
            }, 170);
        }
        saveShortcutKeys(currentShortcutKeys);
    };
    document.querySelectorAll(rootSelector + ' .side-sub a').forEach(function(link) {
        var key = shortcutByPath[shortcutPath(link.href)];
        if (!key || link.closest('.side-favorite-row')) {
            return;
        }
        var row = document.createElement('span');
        row.className = 'side-favorite-row';
        row.setAttribute('data-shortcut-key', key);
        link.parentNode.insertBefore(row, link);
        row.appendChild(link);
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'side-favorite-toggle';
        button.setAttribute('data-shortcut-key', key);
        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            toggleShortcut(key, button);
        });
        row.appendChild(button);
        shortcutButtons.push(button);
    });
    updateShortcutButtons();
})();
</script>
</body>
</html>
