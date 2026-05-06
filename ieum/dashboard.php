<?php
require_once './_common.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/academy.php';
require_once IEUM_PATH . '/lib/sms_queue.php';
require_once IEUM_PATH . '/lib/ui.php';

if (!$is_member) {
    goto_url(G5_BBS_URL . '/login.php?url=' . urlencode(IEUM_URL . '/dashboard.php'));
}

$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$today = G5_TIME_YMD;
$now_ts = strtotime(G5_TIME_YMDHIS);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } elseif ($action === 'resolve_vehicle_note') {
        $log_id = isset($_POST['log_id']) ? (int) $_POST['log_id'] : 0;
        $resolved_by_sql = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
        sql_query("
            update " . IEUM_VEHICLE_BOARDING_TABLE . "
               set resolved_by = '{$resolved_by_sql}',
                   resolved_at = '" . G5_TIME_YMDHIS . "',
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where academy_id = '{$academy_id}'
               and log_id = '{$log_id}'
        ");
        $message = '차량 메모를 확인 처리했습니다.';
    }
}

$csrf_token = ieum_new_csrf_token();
$weekday_map = array(1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun');
$weekday_label_map = array('mon' => '월', 'tue' => '화', 'wed' => '수', 'thu' => '목', 'fri' => '금', 'sat' => '토', 'sun' => '일');
$today_weekday = isset($weekday_map[(int) date('N', $now_ts)]) ? $weekday_map[(int) date('N', $now_ts)] : '';
$today_weekday_sql = sql_escape_string($today_weekday);
$today_label = isset($weekday_label_map[$today_weekday]) ? $weekday_label_map[$today_weekday] : '';

function ieum_dashboard_boarding_status_label($status)
{
    $labels = array(
        'boarded' => '탑승',
        'missed' => '미탑승',
        'called' => '보호자 통화',
    );

    return isset($labels[$status]) ? $labels[$status] : '미확인';
}

$student = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
", false);

$attendance = sql_fetch("
    select count(*) as cnt
      from " . IEUM_ATTENDANCE_TABLE . "
     where academy_id = '{$academy_id}'
       and attendance_date = '{$today}'
", false);

$expected_today = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and find_in_set('{$today_weekday_sql}', attendance_days) > 0
", false);

$missing_today = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id
       and a.student_id = s.student_id
       and a.attendance_date = '{$today}'
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and find_in_set('{$today_weekday_sql}', s.attendance_days) > 0
       and a.attendance_id is null
", false);

$sms_pending = sql_fetch("
    select count(*) as cnt
      from " . IEUM_SMS_QUEUE_TABLE . "
     where academy_id = '{$academy_id}'
       and status = 'pending'
", false);

$sms_failed = sql_fetch("
    select count(*) as cnt
      from " . IEUM_SMS_QUEUE_TABLE . "
     where academy_id = '{$academy_id}'
       and status = 'failed'
", false);

$billing_month = date('Y-m', $now_ts);
$tuition_paid = sql_fetch("
    select count(*) as cnt, coalesce(sum(amount_paid), 0) as amount
      from " . IEUM_TUITION_PAYMENT_TABLE . "
     where academy_id = '{$academy_id}'
       and billing_month = '{$billing_month}'
       and status = 'paid'
", false);

$tuition_unpaid_soon = sql_fetch("
    select count(*) as cnt
      from " . IEUM_TUITION_PAYMENT_TABLE . "
     where academy_id = '{$academy_id}'
       and billing_month = '{$billing_month}'
       and status in ('unpaid', 'partial')
       and datediff('{$today}', due_date) between 0 and 5
", false);

$tuition_unpaid_over = sql_fetch("
    select count(*) as cnt
      from " . IEUM_TUITION_PAYMENT_TABLE . "
     where academy_id = '{$academy_id}'
       and billing_month = '{$billing_month}'
       and status in ('unpaid', 'partial')
       and datediff('{$today}', due_date) > 5
", false);

$sms_sent = sql_fetch("
    select count(*) as cnt
      from " . IEUM_SMS_QUEUE_TABLE . "
     where academy_id = '{$academy_id}'
       and status = 'sent'
       and left(sent_at, 10) = '{$today}'
", false);

$recent = sql_query("
    select a.checked_at, s.student_code, s.student_name, q.status as sms_status
      from " . IEUM_ATTENDANCE_TABLE . " a
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = a.student_id
 left join " . IEUM_SMS_QUEUE_TABLE . " q on q.attendance_id = a.attendance_id
     where a.academy_id = '{$academy_id}'
       and a.attendance_date = '{$today}'
  order by a.checked_at desc
     limit 8
", false);

$missing_students = sql_query("
    select s.student_code, s.student_name, c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id
       and a.student_id = s.student_id
       and a.attendance_date = '{$today}'
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       and find_in_set('{$today_weekday_sql}', s.attendance_days) > 0
       and a.attendance_id is null
  order by c.sort_order asc, c.start_time asc, s.student_name asc
     limit 12
", false);

$class_today = sql_query("
    select c.class_time_id, c.class_name, c.start_time,
           count(s.student_id) as expected_count,
           sum(case when a.attendance_id is not null then 1 else 0 end) as attended_count
      from " . IEUM_CLASS_TIME_TABLE . " c
 left join " . IEUM_STUDENT_TABLE . " s on s.class_time_id = c.class_time_id
       and s.academy_id = c.academy_id
       and s.is_active = 1
       and find_in_set('{$today_weekday_sql}', s.attendance_days) > 0
 left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id
       and a.student_id = s.student_id
       and a.attendance_date = '{$today}'
     where c.academy_id = '{$academy_id}'
       and c.is_active = 1
  group by c.class_time_id
  order by c.sort_order asc, c.start_time asc
", false);

$vehicle_notes = sql_query("
    select bl.log_id, bl.status, bl.note, bl.checked_at, bl.resolved_at, s.student_name, r.vehicle_label, r.route_name, st.stop_name, st.stop_time
      from " . IEUM_VEHICLE_BOARDING_TABLE . " bl
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = bl.student_id and s.academy_id = bl.academy_id
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = bl.route_id and r.academy_id = bl.academy_id
 left join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = bl.stop_id and st.academy_id = bl.academy_id
     where bl.academy_id = '{$academy_id}'
       and bl.journal_date = '{$today}'
       and (bl.note <> '' or bl.status in ('missed', 'called'))
       and bl.resolved_at is null
  order by bl.checked_at desc, bl.log_id desc
     limit 8
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>아이이음 대시보드</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1180px;margin:28px auto;padding:0 20px}
.hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-end;margin-bottom:18px;flex-wrap:wrap}
h1{margin:0;font-size:30px}.meta{color:#5b6472;margin-top:6px}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:10px 14px;font-weight:800;cursor:pointer}
.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.tablet{background:#0f766e;border-color:#0f766e;color:#fff}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}
.label{font-size:14px;color:#667085}.num{font-size:34px;font-weight:900;margin-top:4px}.hint{color:#667085;font-size:13px;margin-top:6px}
.main{display:grid;grid-template-columns:1.35fr .95fr;gap:18px}.today-stack{display:grid;gap:18px}
table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px}th{background:#72829d;color:#fff}
.links{display:grid;gap:10px}.link-card{display:flex;justify-content:space-between;align-items:center;padding:14px;border:1px solid #d9dee7;border-radius:8px;text-decoration:none;color:#111827;background:#fff}.link-card strong{font-size:16px}.link-card span{color:#667085;font-size:13px}
.pending{color:#9a5b00;font-weight:800}.sent{color:#176b2c;font-weight:800}.failed{color:#a4262c;font-weight:800}.todo-list{display:grid;gap:10px}.todo{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #d9dee7;border-radius:8px;padding:12px;background:#fff}.todo strong{font-size:16px}.todo span{color:#667085;font-size:13px}.todo.warn{border-color:#f4c27a;background:#fffaf0}.todo.danger{border-color:#efb2b2;background:#fff5f5}.class-bars{display:grid;gap:10px}.class-row{display:grid;grid-template-columns:110px 1fr 70px;gap:10px;align-items:center}.bar-track{height:10px;background:#eef2f7;border-radius:999px;overflow:hidden}.bar-fill{height:100%;background:#1769c2;border-radius:999px}.vehicle-notes{display:grid;gap:10px}.vehicle-note{border:1px solid #d9dee7;border-radius:8px;background:#fff;padding:12px}.vehicle-note strong{display:block}.vehicle-note span{display:block;color:#667085;font-size:13px;margin-top:3px}.vehicle-note.missed{border-color:#efb2b2;background:#fff5f5}.vehicle-note.called{border-color:#f4c27a;background:#fffaf0}.vehicle-note-form{margin:0}.vehicle-note-form .btn{width:100%;margin-top:8px;min-height:34px;padding:6px 10px;background:#0f766e;border-color:#0f766e;color:#fff}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}
@media (max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}.main{grid-template-columns:1fr}.top{align-items:flex-start}.ieum-user{margin-left:0}.top a{margin-left:0}}
@media (max-width:520px){.grid{grid-template-columns:1fr}.actions .btn{width:100%}}
</style>
</head>
<body>
<?php echo ieum_admin_header('dashboard'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1><?php echo get_text($academy['academy_name']); ?></h1>
            <div class="meta"><?php echo get_text($academy['academy_code']); ?> · <?php echo get_text($member['mb_name'] ?: $member['mb_id']); ?> · <?php echo get_text($today . ' ' . $today_label . '요일'); ?></div>
        </div>
        <div class="actions">
            <a class="btn tablet" href="<?php echo IEUM_URL; ?>/kiosk.php?tablet=1">태블릿 모드</a>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form">학생 등록</a>
        </div>
    </section>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="grid">
        <article class="card"><div class="label">오늘 등원 예정</div><div class="num"><?php echo number_format((int) $expected_today['cnt']); ?></div><div class="hint">전체 사용 학생 <?php echo number_format((int) $student['cnt']); ?>명</div></article>
        <article class="card"><div class="label">오늘 출석</div><div class="num"><?php echo number_format((int) $attendance['cnt']); ?></div><div class="hint">예정 대비 출석</div></article>
        <article class="card"><div class="label">아직 미등원</div><div class="num"><?php echo number_format((int) $missing_today['cnt']); ?></div><div class="hint">선택 요일 기준</div></article>
        <article class="card"><div class="label">문자 대기 / 실패</div><div class="num"><?php echo number_format((int) $sms_pending['cnt']); ?> / <?php echo number_format((int) $sms_failed['cnt']); ?></div><div class="hint">오늘 발송 <?php echo number_format((int) $sms_sent['cnt']); ?>건</div></article>
    </section>

    <section class="grid">
        <article class="card"><div class="label"><?php echo get_text($billing_month); ?> 수련비 결제</div><div class="num"><?php echo number_format((int) $tuition_paid['cnt']); ?>명</div><div class="hint"><?php echo number_format((int) $tuition_paid['amount']); ?>원 입금 기록</div></article>
        <article class="card"><div class="label">미결제 5일 이하</div><div class="num"><?php echo number_format((int) $tuition_unpaid_soon['cnt']); ?></div><div class="hint">결제일 경과 0~5일</div></article>
        <article class="card"><div class="label">미결제 5일 이상</div><div class="num"><?php echo number_format((int) $tuition_unpaid_over['cnt']); ?></div><div class="hint">우선 확인 필요</div></article>
        <article class="card"><div class="label">수련비 정책</div><div class="num">설정</div><div class="hint"><a href="<?php echo IEUM_URL; ?>/admin/tuition.php">주 횟수별 금액 관리</a></div></article>
    </section>

    <section class="main">
        <section class="today-stack">
            <article class="card">
                <h2>오늘 할 일</h2>
                <div class="todo-list">
                    <a class="todo <?php echo (int) $missing_today['cnt'] ? 'warn' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">
                        <div><strong>미등원 확인</strong><span>오늘 출석 요일인데 아직 등원하지 않은 학생</span></div>
                        <strong><?php echo number_format((int) $missing_today['cnt']); ?>명</strong>
                    </a>
                    <a class="todo <?php echo (int) $sms_failed['cnt'] ? 'danger' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php?status=failed">
                        <div><strong>문자 실패 확인</strong><span>보호자 알림이 실패한 건</span></div>
                        <strong><?php echo number_format((int) $sms_failed['cnt']); ?>건</strong>
                    </a>
                    <a class="todo <?php echo (int) $sms_pending['cnt'] ? 'warn' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php?status=pending">
                        <div><strong>문자 대기 확인</strong><span>안드로이드 게이트웨이 발송 대기</span></div>
                        <strong><?php echo number_format((int) $sms_pending['cnt']); ?>건</strong>
                    </a>
                </div>
            </article>

            <article class="card">
                <h2>수업 부별 출석</h2>
                <div class="class-bars">
                    <?php $ci = 0; while ($class = sql_fetch_array($class_today)) { $ci++; $expected = (int) $class['expected_count']; $attended = (int) $class['attended_count']; $rate = $expected ? round(($attended / $expected) * 100) : 0; ?>
                    <div class="class-row">
                        <strong><?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?></strong>
                        <div class="bar-track"><div class="bar-fill" style="width:<?php echo (int) $rate; ?>%"></div></div>
                        <span><?php echo number_format($attended); ?>/<?php echo number_format($expected); ?></span>
                    </div>
                    <?php } ?>
                    <?php if ($ci === 0) { ?><div class="hint">등록된 수업 부가 없습니다.</div><?php } ?>
                </div>
            </article>

            <article class="card">
                <h2>오늘 차량 메모</h2>
                <div class="vehicle-notes">
                    <?php $vi = 0; while ($note = sql_fetch_array($vehicle_notes)) { $vi++; ?>
                    <div class="vehicle-note <?php echo get_text($note['status']); ?>">
                        <strong><?php echo get_text($note['student_name'] . ' · ' . ieum_dashboard_boarding_status_label($note['status'])); ?></strong>
                        <span><?php echo get_text(trim(($note['vehicle_label'] ?: '차량 미지정') . ' / ' . ($note['route_name'] ?: '노선 미지정') . ' / ' . ($note['stop_time'] ?: '') . ' ' . ($note['stop_name'] ?: ''))); ?></span>
                        <?php if ($note['note'] !== '') { ?><span><?php echo get_text($note['note']); ?></span><?php } ?>
                        <form method="post" class="vehicle-note-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="resolve_vehicle_note">
                            <input type="hidden" name="log_id" value="<?php echo (int) $note['log_id']; ?>">
                            <button type="submit" class="btn">확인</button>
                        </form>
                    </div>
                    <?php } ?>
                    <?php if ($vi === 0) { ?><div class="hint">오늘 기록된 차량 특이사항이 없습니다.</div><?php } ?>
                </div>
            </article>

            <article class="card">
                <h2>최근 등원</h2>
                <table>
                    <thead><tr><th scope="col">시간</th><th scope="col">번호</th><th scope="col">학생</th><th scope="col">문자</th></tr></thead>
                    <tbody>
                    <?php $i = 0; while ($row = sql_fetch_array($recent)) { $i++; $status = $row['sms_status'] ?: 'none'; ?>
                    <tr>
                        <td><?php echo get_text(substr($row['checked_at'], 11, 5)); ?></td>
                        <td><?php echo get_text($row['student_code']); ?></td>
                        <td><?php echo get_text($row['student_name']); ?></td>
                        <td class="<?php echo get_text($status); ?>"><?php echo get_text($status); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if ($i === 0) { ?><tr><td colspan="4">오늘 등원 기록이 없습니다.</td></tr><?php } ?>
                    </tbody>
                </table>
            </article>
        </section>

        <aside class="links">
            <article class="card">
                <h2>미등원 학생</h2>
                <table>
                    <thead><tr><th>부</th><th>학생</th></tr></thead>
                    <tbody>
                    <?php $mi = 0; while ($row = sql_fetch_array($missing_students)) { $mi++; ?>
                    <tr>
                        <td><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '미지정'); ?></td>
                        <td><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if ($mi === 0) { ?><tr><td colspan="2">현재 미등원 학생이 없습니다.</td></tr><?php } ?>
                    </tbody>
                </table>
            </article>
            <a class="link-card" href="<?php echo IEUM_URL; ?>/kiosk.php?tablet=1"><strong>태블릿 출석</strong><span>번호 입력 화면</span></a>
            <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/students.php"><strong>학생 관리</strong><span>등록/수정/중지</span></a>
            <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/student_groups.php"><strong>부별 학생</strong><span>수업/학년별 빠른 보기</span></a>
            <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/class_times.php"><strong>수업 시간표</strong><span>부별 시간 설정</span></a>
            <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/contacts.php"><strong>알림 담당자</strong><span>미등원 알림 수신</span></a>
            <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/tuition.php"><strong>수련비 설정</strong><span>주 횟수/형제 할인</span></a>
            <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php"><strong>출석 현황</strong><span>오늘 등원 확인</span></a>
            <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php"><strong>문자 큐</strong><span>발송 상태 확인</span></a>
        </aside>
    </section>
</main>
</body>
</html>
