<?php
$sub_menu = '950155';
require_once './_common.php';

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
.wrap{max-width:1120px;margin:28px auto;padding:0 20px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-bottom:18px}
h1{margin:0 0 8px;font-size:26px}.meta{color:#667085;margin-bottom:16px}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0}.summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:18px}.summary-card{border:1px solid #d9dee7;border-radius:8px;padding:16px;background:#f8fafc}.summary-card span{display:block;color:#667085}.summary-card strong{font-size:30px}
input,select{border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}.form-grid{display:grid;grid-template-columns:160px 150px 1fr 1fr 90px;gap:8px;align-items:center}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.danger{color:#b42318;border-color:#f3b6b0}
table{width:100%;border-collapse:collapse}th,td{border:1px solid #d8dee9;padding:10px;text-align:center}th{background:#72829d;color:#fff}.left{text-align:left}.type-closed{color:#b42318;font-weight:800}.type-makeup{color:#087443;font-weight:800}.help{color:#667085;font-size:13px;margin-top:10px;line-height:1.5}
@media(max-width:900px){.summary{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}table{display:block;overflow-x:auto;white-space:nowrap}}
</style>
</head>
<body>
<?php echo ieum_admin_header('calendar'); ?>
<?php echo ieum_admin_subnav('calendar'); ?>
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
        <div class="help">재량 휴관과 보충 수업이 같은 날짜에 함께 등록되면 안전하게 재량 휴관을 우선 적용합니다.</div>
    </section>

    <section class="panel">
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
</body>
</html>
