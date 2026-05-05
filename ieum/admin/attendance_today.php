<?php
$sub_menu = '950110';
require_once './_common.php';
require_once IEUM_PATH . '/lib/sms_queue.php';
require_once IEUM_PATH . '/lib/attendance.php';

$g5['title'] = '아이이음 오늘 출석 현황';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $student_code = isset($_POST['student_code']) ? $_POST['student_code'] : '';
        $saved = ieum_save_attendance_by_code($student_code, 'admin');
        if ($saved['status'] === 'created' || $saved['status'] === 'duplicate') {
            $message = $saved['message'];
        } else {
            $error = $saved['message'];
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$today = isset($_GET['date']) ? preg_replace('/[^0-9-]/', '', $_GET['date']) : G5_TIME_YMD;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
    $today = G5_TIME_YMD;
}
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$today_sql = sql_escape_string($today);

$class_times = sql_query("
    select *
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);

$class_filter_sql = $class_time_id ? " and s.class_time_id = '{$class_time_id}' " : "";

$summary = sql_fetch("
    select count(*) as attendance_count
      from " . IEUM_ATTENDANCE_TABLE . " a
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = a.student_id
     where a.academy_id = '{$academy_id}'
       and a.attendance_date = '{$today_sql}'
       {$class_filter_sql}
", false);

$pending = sql_fetch("
    select count(*) as pending_count
      from " . IEUM_SMS_QUEUE_TABLE . "
     where academy_id = '{$academy_id}'
       and status = 'pending'
", false);

$result = sql_query("
    select a.*, s.student_code, s.student_name, s.parent_name, s.parent_phone, s.grade_group,
           c.class_name, c.start_time, q.sms_id, q.status as sms_status
      from " . IEUM_ATTENDANCE_TABLE . " a
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = a.student_id
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id
 left join " . IEUM_SMS_QUEUE_TABLE . " q on q.attendance_id = a.attendance_id
     where a.attendance_date = '{$today_sql}'
       and a.academy_id = '{$academy_id}'
       {$class_filter_sql}
  order by a.checked_at desc
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
.wrap{max-width:1280px;margin:28px auto;padding:0 20px}.bar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:16px}
h1{margin:0;font-size:26px}.meta{color:#667085}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}.card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}
.label{color:#667085}.num{font-size:30px;font-weight:900}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;margin-bottom:18px}.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
input,select{height:38px;border:1px solid #cfd6df;border-radius:6px;padding:0 10px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}
table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px}th{background:#72829d;color:#fff}.pending{color:#9a5b00;font-weight:800}.sent{color:#176b2c;font-weight:800}
@media(max-width:800px){.cards{grid-template-columns:1fr}table{display:block;overflow-x:auto;white-space:nowrap}}
</style>
</head>
<body>
<?php echo ieum_admin_header('attendance'); ?>
<main class="wrap">
    <div class="bar">
        <div>
            <h1>오늘 출석 현황</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($today); ?></div>
        </div>
        <a href="<?php echo IEUM_URL; ?>/kiosk.php?tablet=1" class="btn primary" target="_blank" rel="noopener">태블릿 모드</a>
    </div>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="cards">
        <article class="card"><div class="label">출석</div><div class="num"><?php echo number_format((int) $summary['attendance_count']); ?></div></article>
        <article class="card"><div class="label">문자 대기</div><div class="num"><?php echo number_format((int) $pending['pending_count']); ?></div></article>
        <article class="card"><div class="label">조회 부</div><div class="num"><?php echo $class_time_id ? '선택' : '전체'; ?></div></article>
    </section>

    <section class="panel">
        <form method="post" class="filters">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <label for="student_code">직접 등원</label>
            <input type="text" name="student_code" id="student_code" placeholder="학생번호" required>
            <button type="submit" class="btn primary">등원 처리</button>
        </form>
    </section>

    <section class="panel">
        <form method="get" class="filters">
            <input type="date" name="date" value="<?php echo get_text($today); ?>">
            <select name="class_time_id">
                <option value="0">전체 부</option>
                <?php while ($class = sql_fetch_array($class_times)) { ?>
                <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>>
                    <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
                </option>
                <?php } ?>
            </select>
            <button type="submit" class="btn">조회</button>
        </form>
    </section>

    <section class="panel">
        <table>
            <thead><tr><th>시간</th><th>학생번호</th><th>학생명</th><th>수업 부</th><th>보호자</th><th>연락처</th><th>입력</th><th>문자</th></tr></thead>
            <tbody>
            <?php $i = 0; while ($row = sql_fetch_array($result)) { $i++; $sms = $row['sms_status'] ?: 'none'; ?>
            <tr>
                <td><?php echo get_text($row['checked_at']); ?></td>
                <td><?php echo get_text($row['student_code']); ?></td>
                <td><?php echo get_text($row['student_name']); ?></td>
                <td><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : ''); ?></td>
                <td><?php echo get_text($row['parent_name']); ?></td>
                <td><?php echo get_text(ieum_mask_phone($row['parent_phone'])); ?></td>
                <td><?php echo get_text($row['input_source']); ?></td>
                <td class="<?php echo get_text($sms); ?>"><?php echo get_text($sms); ?></td>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?><tr><td colspan="8">출석 기록이 없습니다.</td></tr><?php } ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
