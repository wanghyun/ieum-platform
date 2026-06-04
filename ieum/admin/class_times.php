<?php
$sub_menu = '950150';
require_once './_common.php';

$g5['title'] = '아이이음 수업 시간표';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $class_time_id = isset($_POST['class_time_id']) ? (int) $_POST['class_time_id'] : 0;

        if ($action === 'save') {
            $class_name = isset($_POST['class_name']) ? trim($_POST['class_name']) : '';
            $start_time = isset($_POST['start_time']) ? trim($_POST['start_time']) : '';
            $sort_order = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
            $absent_alert_enabled = isset($_POST['absent_alert_enabled']) ? 1 : 0;
            $absent_alert_after_minutes = isset($_POST['absent_alert_after_minutes']) ? (int) $_POST['absent_alert_after_minutes'] : 10;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if ($absent_alert_after_minutes < 1) {
                $absent_alert_after_minutes = 1;
            } elseif ($absent_alert_after_minutes > 120) {
                $absent_alert_after_minutes = 120;
            }

            if ($class_name === '') {
                $error = '부 이름을 입력하세요.';
            } elseif (!preg_match('/^\d{2}:\d{2}$/', $start_time)) {
                $error = '시간은 HH:mm 형식으로 입력하세요.';
            } else {
                $class_name_sql = sql_escape_string($class_name);
                $start_time_sql = sql_escape_string($start_time);
                if ($class_time_id) {
                    sql_query("
                        update " . IEUM_CLASS_TIME_TABLE . "
                           set class_name = '{$class_name_sql}',
                               start_time = '{$start_time_sql}',
                               sort_order = '{$sort_order}',
                               absent_alert_enabled = '{$absent_alert_enabled}',
                               absent_alert_after_minutes = '{$absent_alert_after_minutes}',
                               is_active = '{$is_active}',
                               updated_at = '" . G5_TIME_YMDHIS . "'
                         where class_time_id = '{$class_time_id}'
                           and academy_id = '{$academy_id}'
                    ");
                    $message = '수업 시간이 수정되었습니다.';
                } else {
                    sql_query("
                        insert into " . IEUM_CLASS_TIME_TABLE . "
                            set academy_id = '{$academy_id}',
                                class_name = '{$class_name_sql}',
                                start_time = '{$start_time_sql}',
                                sort_order = '{$sort_order}',
                                absent_alert_enabled = '{$absent_alert_enabled}',
                                absent_alert_after_minutes = '{$absent_alert_after_minutes}',
                                is_active = '{$is_active}',
                                created_at = '" . G5_TIME_YMDHIS . "'
                    ");
                    $message = '수업 시간이 등록되었습니다.';
                }
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$rows = sql_query("
    select *
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
  order by sort_order asc, start_time asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px;overflow-x:auto}
h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
input{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-items:center}.grid .primary{min-width:160px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}
table{width:100%;min-width:980px;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.class-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune h1{
    margin:0!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune .meta{
    margin:8px 0 18px!important;
    color:#64748b!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune .panel{
    border-color:#dfe5ee!important;
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune .grid{
    grid-template-columns:minmax(120px,1fr) 100px 80px 120px 92px 86px 74px!important;
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune input{
    min-height:40px!important;
    border-color:#cfd8e3!important;
    border-radius:6px!important;
    background:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune label{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    gap:6px!important;
    min-height:40px!important;
    font-weight:900!important;
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune label input{
    min-height:auto!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune .grid .primary{
    min-width:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune td{
    border-color:#e5ebf3!important;
}
body.ieum-side-layout.ieum-dashboard-page.class-page-tune table input{
    width:100%!important;
}
body.ieum-dashboard-page.class-page-tune.ieum-dark{
    background:#0f1724!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.class-page-tune.ieum-dark .panel{
    background:#151f2e!important;
    border-color:#2c3a4f!important;
    color:#e5edf7!important;
    box-shadow:none!important;
}
body.ieum-dashboard-page.class-page-tune.ieum-dark .meta{
    color:#9aa8bb!important;
}
body.ieum-dashboard-page.class-page-tune.ieum-dark th{
    background:#1b2535!important;
    color:#d9e2ef!important;
    border-color:#2c3a4f!important;
}
body.ieum-dashboard-page.class-page-tune.ieum-dark td{
    background:#151f2e!important;
    color:#d9e2ef!important;
    border-color:#263244!important;
}
body.ieum-dashboard-page.class-page-tune.ieum-dark input,
body.ieum-dashboard-page.class-page-tune.ieum-dark select,
body.ieum-dashboard-page.class-page-tune.ieum-dark textarea,
body.ieum-dashboard-page.class-page-tune.ieum-dark .btn{
    background:#111827!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.class-page-tune.ieum-dark .primary,
body.ieum-dashboard-page.class-page-tune.ieum-dark .btn.primary{
    background:#1f7dd9!important;
    border-color:#1f7dd9!important;
    color:#fff!important;
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.class-page-tune .grid{
        grid-template-columns:repeat(3,minmax(0,1fr))!important;
    }
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.class-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.class-page-tune .grid{
        grid-template-columns:1fr!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page class-page-tune">
<?php echo ieum_admin_header('classes', 'side'); ?>
<main class="wrap">
    <h1>수업 시간표</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 대시보드 오늘 수업과 미등원 알림 기준으로 사용합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel">
        <form method="post" class="grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save">
            <input type="text" name="class_name" placeholder="예: 1부" required>
            <input type="text" name="start_time" placeholder="14:10" required>
            <input type="number" name="sort_order" value="1" min="0">
            <label><input type="checkbox" name="absent_alert_enabled" value="1" checked> 미등원 알림</label>
            <input type="number" name="absent_alert_after_minutes" value="10" min="1" max="120" title="시작 후 알림 분">
            <label><input type="checkbox" name="is_active" value="1" checked> 사용</label>
            <button type="submit" class="btn primary">추가</button>
        </form>
    </section>

    <section class="panel">
        <table>
            <thead><tr><th>부 이름</th><th>시작 시간</th><th>정렬</th><th>미등원 알림</th><th>알림 기준</th><th>상태</th><th>수정</th></tr></thead>
            <tbody>
            <?php while ($row = sql_fetch_array($rows)) { ?>
            <tr>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="class_time_id" value="<?php echo (int) $row['class_time_id']; ?>">
                    <td><input type="text" name="class_name" value="<?php echo get_text($row['class_name']); ?>"></td>
                    <td><input type="text" name="start_time" value="<?php echo get_text($row['start_time']); ?>"></td>
                    <td><input type="number" name="sort_order" value="<?php echo (int) $row['sort_order']; ?>"></td>
                    <td><label><input type="checkbox" name="absent_alert_enabled" value="1" <?php echo !empty($row['absent_alert_enabled']) ? 'checked' : ''; ?>> 사용</label></td>
                    <td><input type="number" name="absent_alert_after_minutes" value="<?php echo (int) (isset($row['absent_alert_after_minutes']) ? $row['absent_alert_after_minutes'] : 10); ?>" min="1" max="120">분 후</td>
                    <td><label><input type="checkbox" name="is_active" value="1" <?php echo $row['is_active'] ? 'checked' : ''; ?>> 사용</label></td>
                    <td><button type="submit" class="btn">저장</button></td>
                </form>
            </tr>
            <?php } ?>
            </tbody>
        </table>
    </section>
</main>
<script type="text/plain" data-deprecated-shell-sync="common-ui-owned">
(function(){
    var rootSelector = '.class-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) {
        brandText.textContent = <?php echo json_encode($academy['academy_name']); ?>;
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
            + '<span><?php echo get_text($academy['academy_name']); ?></span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-clock">' + hh + ':' + mm + '</span>'
            + '</span>';
    }
})();
</script>
</body>
</html>
