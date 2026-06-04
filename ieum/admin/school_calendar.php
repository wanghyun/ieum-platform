<?php
$sub_menu = '950155';
require_once './_common.php';
require_once IEUM_PATH . '/lib/attendance.php';

$g5['title'] = '아이이음 수업일 설정';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

function ieum_calendar_type_label($type)
{
    if ($type === 'makeup') {
        return '보충 수업';
    }

    return '재량 휴관';
}

function ieum_calendar_valid_month($month)
{
    return preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
}

$month = isset($_GET['month']) ? ieum_calendar_valid_month(trim($_GET['month'])) : date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $post_month = isset($_POST['month']) ? ieum_calendar_valid_month(trim($_POST['month'])) : $month;
        $month = $post_month;

        if ($action === 'save') {
            $calendar_date = isset($_POST['calendar_date']) ? trim($_POST['calendar_date']) : '';
            $day_type = isset($_POST['day_type']) ? trim($_POST['day_type']) : 'closed';
            $title = isset($_POST['title']) ? trim($_POST['title']) : '';
            $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calendar_date) || strtotime($calendar_date) === false) {
                $error = '날짜를 올바르게 입력하세요.';
            } elseif (!in_array($day_type, array('closed', 'makeup'), true)) {
                $error = '수업일 종류를 선택하세요.';
            } else {
                if ($title === '') {
                    $title = ieum_calendar_type_label($day_type);
                }

                $date_sql = sql_escape_string($calendar_date);
                $type_sql = sql_escape_string($day_type);
                $title_sql = sql_escape_string($title);
                $memo_sql = sql_escape_string($memo);

                sql_query("
                    insert into " . IEUM_ACADEMY_CALENDAR_TABLE . "
                        set academy_id = '{$academy_id}',
                            calendar_date = '{$date_sql}',
                            day_type = '{$type_sql}',
                            title = '{$title_sql}',
                            memo = '{$memo_sql}',
                            is_active = 1,
                            created_at = '" . G5_TIME_YMDHIS . "'
                    on duplicate key update
                            title = values(title),
                            memo = values(memo),
                            is_active = 1,
                            updated_at = '" . G5_TIME_YMDHIS . "'
                ");

                $message = ieum_calendar_type_label($day_type) . ' 일정이 저장되었습니다.';
            }
        } elseif ($action === 'delete') {
            $calendar_id = isset($_POST['calendar_id']) ? (int) $_POST['calendar_id'] : 0;
            if ($calendar_id > 0) {
                sql_query("
                    update " . IEUM_ACADEMY_CALENDAR_TABLE . "
                       set is_active = 0,
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where academy_id = '{$academy_id}'
                       and calendar_id = '{$calendar_id}'
                ");
                $message = '수업일 설정을 삭제했습니다.';
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$start_date = $month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));
$prev_month = date('Y-m', strtotime($start_date . ' -1 month'));
$next_month = date('Y-m', strtotime($start_date . ' +1 month'));

$rows = sql_query("
    select *
      from " . IEUM_ACADEMY_CALENDAR_TABLE . "
     where academy_id = '{$academy_id}'
       and calendar_date between '" . sql_escape_string($start_date) . "' and '" . sql_escape_string($end_date) . "'
       and is_active = 1
  order by calendar_date asc, field(day_type, 'closed', 'makeup'), calendar_id asc
", false);

$counts = array('closed' => 0, 'makeup' => 0);
$items = array();
if ($rows) {
    while ($row = sql_fetch_array($rows)) {
        $items[] = $row;
        if (isset($counts[$row['day_type']])) {
            $counts[$row['day_type']]++;
        }
    }
}
$public_holidays = function_exists('ieum_attendance_public_holiday_labels') ? ieum_attendance_public_holiday_labels($start_date, $end_date) : array();
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
.wrap{max-width:1900px;margin:28px auto;padding:0 20px}body.ieum-side-layout .wrap{max-width:1900px;margin:0;padding:28px 24px 44px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0}.summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}.summary-card{border:1px solid #d9dee7;border-radius:8px;padding:16px;background:#f8fafc}.summary-card span{display:block;color:#667085}.summary-card strong{font-size:30px}
input,select{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.form-grid{display:grid;grid-template-columns:160px 150px 1fr 1fr 90px;gap:8px;align-items:center}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.danger{color:#b42318;border-color:#f3b6b0}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}.left{text-align:left}.type-closed{color:#b42318;font-weight:800}.type-makeup{color:#087443;font-weight:800}.type-public{color:#1769c2;font-weight:800}.help{color:#667085;font-size:13px;margin-top:10px;line-height:1.5}
@media(max-width:900px){.summary{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}table{display:block;overflow-x:auto;white-space:nowrap}}
</style>
<style>
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune h1{
    margin:0!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .meta{
    margin:8px 0 16px!important;
    color:#64748b!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .toolbar{
    margin:0 0 18px!important;
    gap:7px!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .toolbar form{
    margin:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .summary{
    gap:12px!important;
    margin-bottom:18px!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .summary-card,
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .panel{
    border-color:#dfe5ee!important;
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .summary-card{
    background:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .summary-card strong{
    font-size:28px!important;
    line-height:1.1!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .form-grid{
    grid-template-columns:160px 150px minmax(220px,1fr) minmax(220px,1fr) 90px!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune input,
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune select{
    min-height:40px!important;
    border-color:#cfd8e3!important;
    border-radius:6px!important;
    background:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune td{
    border-color:#e5ebf3!important;
}
body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune table{
    min-width:760px!important;
}
@media(max-width:1280px){
    body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .form-grid{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .form-grid .btn{
        grid-column:1 / -1!important;
    }
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.calendar-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page ieum-simple-page calendar-page-tune">
<?php echo ieum_admin_header('calendar', 'side'); ?>
<main class="wrap">
    <h1>수업일 설정</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 재량 휴관은 정상 수업일에서 제외하고, 보충 수업은 정상 수업일에 포함합니다.</div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <div class="toolbar">
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/school_calendar.php?month=<?php echo get_text($prev_month); ?>">이전달</a>
        <form method="get" class="toolbar">
            <input type="month" name="month" value="<?php echo get_text($month); ?>">
            <button type="submit" class="btn primary">조회</button>
        </form>
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/school_calendar.php?month=<?php echo get_text($next_month); ?>">다음달</a>
    </div>

    <section class="summary">
        <article class="summary-card"><span>재량 휴관</span><strong><?php echo (int) $counts['closed']; ?>일</strong></article>
        <article class="summary-card"><span>보충 수업</span><strong><?php echo (int) $counts['makeup']; ?>일</strong></article>
        <article class="summary-card"><span>공휴일 자동 휴관</span><strong><?php echo count($public_holidays); ?>일</strong></article>
    </section>

    <section class="panel">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
            <input type="date" name="calendar_date" value="<?php echo get_text(G5_TIME_YMD); ?>" required>
            <select name="day_type">
                <option value="closed">재량 휴관</option>
                <option value="makeup">보충 수업</option>
            </select>
            <input type="text" name="title" placeholder="예: 도장 휴관 / 토요 보충수업">
            <input type="text" name="memo" placeholder="운영 메모">
            <button type="submit" class="btn primary">추가</button>
        </form>
        <div class="help">공휴일은 자동 휴관으로 적용합니다. 공휴일과 같은 날짜에 보충 수업을 등록해도 안전하게 휴관을 우선 적용합니다.</div>
    </section>

    <section class="panel">
        <h2>공휴일 자동 휴관</h2>
        <div class="help">공식 공휴일은 정상 수업일에서 자동 제외됩니다. 별도 보충 수업은 다른 날짜에 보충 수업으로 등록해 주세요.</div>
        <table>
            <thead><tr><th>날짜</th><th>구분</th><th>적용</th></tr></thead>
            <tbody>
            <?php foreach ($public_holidays as $holiday_date => $holiday_label) { ?>
            <tr>
                <td><?php echo get_text($holiday_date); ?></td>
                <td class="type-public"><?php echo get_text($holiday_label); ?></td>
                <td>정상 수업일에서 제외</td>
            </tr>
            <?php } ?>
            <?php if (!$public_holidays) { ?><tr><td colspan="3">이번 달 자동 공휴일이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2>도장 수업일 예외</h2>
        <table>
            <thead><tr><th>날짜</th><th>구분</th><th>제목</th><th>메모</th><th>관리</th></tr></thead>
            <tbody>
            <?php foreach ($items as $row) { ?>
            <tr>
                <td><?php echo get_text($row['calendar_date']); ?></td>
                <td class="<?php echo $row['day_type'] === 'makeup' ? 'type-makeup' : 'type-closed'; ?>"><?php echo get_text(ieum_calendar_type_label($row['day_type'])); ?></td>
                <td class="left"><?php echo get_text($row['title']); ?></td>
                <td class="left"><?php echo get_text($row['memo']); ?></td>
                <td>
                    <form method="post" onsubmit="return confirm('삭제할까요?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="month" value="<?php echo get_text($month); ?>">
                        <input type="hidden" name="calendar_id" value="<?php echo (int) $row['calendar_id']; ?>">
                        <button type="submit" class="btn danger">삭제</button>
                    </form>
                </td>
            </tr>
            <?php } ?>
            <?php if (!$items) { ?><tr><td colspan="5">등록된 수업일 예외가 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.calendar-page-tune.ieum-dashboard-page';
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
