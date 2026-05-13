<?php
require_once './_common.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/academy.php';
require_once IEUM_PATH . '/lib/sms_queue.php';
require_once IEUM_PATH . '/lib/ui.php';
require_once IEUM_PATH . '/lib/tuition.php';
require_once IEUM_PATH . '/lib/absent_alert.php';
require_once IEUM_PATH . '/lib/dashboard.php';

if (!$is_member) {
    goto_url(G5_BBS_URL . '/login.php?url=' . urlencode(IEUM_URL . '/dashboard.php'));
}

$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$today = G5_TIME_YMD;
$now_ts = strtotime(G5_TIME_YMDHIS);
$billing_month = ieum_tuition_billing_month($now_ts);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } elseif ($action === 'resolve_vehicle_note') {
        $log_id = isset($_POST['log_id']) ? (int) $_POST['log_id'] : 0;
        $resolved_by = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
        sql_query("
            update " . IEUM_VEHICLE_BOARDING_TABLE . "
               set resolved_by = '{$resolved_by}',
                   resolved_at = '" . G5_TIME_YMDHIS . "',
                   updated_at = '" . G5_TIME_YMDHIS . "'
             where academy_id = '{$academy_id}'
               and log_id = '{$log_id}'
        ");
        $message = '차량 메모를 확인 처리했습니다.';
    } elseif ($action === 'run_absent_alerts') {
        $result = ieum_absent_alert_create_for_academy($academy_id, false);
        if ((int) $result['created_sms'] > 0) {
            $message = '미등원 알림 문자 큐 ' . number_format((int) $result['created_sms']) . '건을 생성했습니다.';
        } else {
            $message = '현재 새로 생성할 미등원 알림이 없습니다. 이미 발송되었거나 알림 시간이 아직 지나지 않았을 수 있습니다.';
        }
    } elseif ($action === 'save_dashboard_shortcuts') {
        $shortcut_keys = isset($_POST['shortcut_keys']) && is_array($_POST['shortcut_keys']) ? $_POST['shortcut_keys'] : array();
        ieum_dashboard_save_shortcuts($academy_id, $shortcut_keys);
        $message = '대시보드 바로가기를 저장했습니다.';
    }
}

$csrf_token = ieum_new_csrf_token();
ieum_tuition_ensure_month($academy_id, $billing_month);
$dashboard_shortcut_catalog = ieum_dashboard_shortcut_catalog();
$dashboard_shortcut_keys = ieum_dashboard_get_shortcut_keys($academy_id);
$dashboard_shortcuts = ieum_dashboard_resolve_shortcuts($dashboard_shortcut_keys);

$weekday_map = array(1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun');
$weekday_label_map = array('mon' => '월', 'tue' => '화', 'wed' => '수', 'thu' => '목', 'fri' => '금', 'sat' => '토', 'sun' => '일');
$today_weekday = isset($weekday_map[(int) date('N', $now_ts)]) ? $weekday_map[(int) date('N', $now_ts)] : '';
$today_weekday_sql = sql_escape_string($today_weekday);
$today_label = isset($weekday_label_map[$today_weekday]) ? $weekday_label_map[$today_weekday] : '';

function ieum_dashboard_grade_label($value)
{
    $labels = array(
        '' => '미지정',
        'kindergarten' => '유치부',
        'elementary_1' => '초등 1학년',
        'elementary_2' => '초등 2학년',
        'elementary_3' => '초등 3학년',
        'elementary_4' => '초등 4학년',
        'elementary_5' => '초등 5학년',
        'elementary_6' => '초등 6학년',
        'middle_1' => '중등 1학년',
        'middle_2' => '중등 2학년',
        'middle_3' => '중등 3학년',
        'high_1' => '고등 1학년',
        'high_2' => '고등 2학년',
        'high_3' => '고등 3학년',
        'adult' => '성인부',
        'jump_rope' => '줄넘기부',
    );

    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_dashboard_grade_from_birth_date($birth_date, $base_time = null)
{
    if (!$birth_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        return '';
    }

    $time = $base_time ? (int) $base_time : strtotime(G5_TIME_YMDHIS);
    $school_year = (int) date('Y', $time);
    if ((int) date('n', $time) < 3) {
        $school_year--;
    }

    $birth_year = (int) substr($birth_date, 0, 4);
    $grade_number = $school_year - $birth_year - 6;
    if ($grade_number < 1) {
        return 'kindergarten';
    }
    if ($grade_number <= 6) {
        return 'elementary_' . $grade_number;
    }
    if ($grade_number <= 9) {
        return 'middle_' . ($grade_number - 6);
    }
    if ($grade_number <= 12) {
        return 'high_' . ($grade_number - 9);
    }

    return '';
}

function ieum_dashboard_boarding_status_label($status)
{
    $labels = array(
        'boarded' => '탑승',
        'missed' => '미탑승',
        'called' => '보호자 통화',
        'self' => '개별 이동',
    );

    return isset($labels[$status]) ? $labels[$status] : '미확인';
}

$student = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
", false);

$tablet_devices = sql_fetch("
    select count(*) as cnt
      from " . IEUM_TABLET_DEVICE_TABLE . "
     where academy_id = '{$academy_id}'
       and status = 'active'
", false);

$total_attendance = sql_fetch("
    select count(*) as cnt
      from " . IEUM_ATTENDANCE_TABLE . "
     where academy_id = '{$academy_id}'
", false);

$show_onboarding_flow = (int) $student['cnt'] === 0 || (int) $tablet_devices['cnt'] === 0 || (int) $total_attendance['cnt'] === 0;

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

$sms = sql_fetch("
    select
        sum(case when status = 'pending' then 1 else 0 end) as pending_count,
        sum(case when status = 'failed' then 1 else 0 end) as failed_count,
        sum(case when status = 'sent' and left(sent_at, 10) = '{$today}' then 1 else 0 end) as sent_today_count
      from " . IEUM_SMS_QUEUE_TABLE . "
     where academy_id = '{$academy_id}'
", false);

$tuition = sql_fetch("
    select
        count(*) as total_count,
        sum(case when status = 'paid' then 1 else 0 end) as paid_count,
        coalesce(sum(case when status = 'paid' then amount_paid else 0 end), 0) as paid_amount,
        sum(case when status in ('unpaid', 'partial') and due_date = '{$today}' then 1 else 0 end) as due_today_count,
        sum(case when status in ('unpaid', 'partial') and datediff('{$today}', due_date) between 0 and 5 then 1 else 0 end) as unpaid_soon_count,
        sum(case when status in ('unpaid', 'partial') and datediff('{$today}', due_date) > 5 then 1 else 0 end) as unpaid_over_count
      from " . IEUM_TUITION_PAYMENT_TABLE . "
     where academy_id = '{$academy_id}'
       and billing_month = '" . sql_escape_string($billing_month) . "'
", false);

$tuition_settings = ieum_tuition_get_settings($academy_id);
$tuition_notice_due_pending = sql_fetch("
    select count(*) as cnt
      from " . IEUM_TUITION_PAYMENT_TABLE . "
     where academy_id = '{$academy_id}'
       and status in ('unpaid', 'partial')
       and due_date = '{$today}'
       and (notice_sent_at is null or notice_sent_at < '{$today} 00:00:00')
", false);
$tuition_notice_overdue_pending = sql_fetch("
    select count(*) as cnt
      from " . IEUM_TUITION_PAYMENT_TABLE . "
     where academy_id = '{$academy_id}'
       and status in ('unpaid', 'partial')
       and datediff('{$today}', due_date) > '" . (int) $tuition_settings['overdue_after_days'] . "'
       and (notice_sent_at is null or notice_sent_at < '{$today} 00:00:00')
", false);
$tuition_notice_pending_count = (!empty($tuition_settings['due_notice_enabled']) ? (int) $tuition_notice_due_pending['cnt'] : 0) + (!empty($tuition_settings['overdue_notice_enabled']) ? (int) $tuition_notice_overdue_pending['cnt'] : 0);

$vehicle_note_count = sql_fetch("
    select count(*) as cnt
      from " . IEUM_VEHICLE_BOARDING_TABLE . "
     where academy_id = '{$academy_id}'
       and journal_date = '{$today}'
       and (note <> '' or status in ('missed', 'called'))
       and resolved_at is null
", false);

$vehicle_note_summary = sql_fetch("
    select
        sum(case when status = 'missed' then 1 else 0 end) as missed_count,
        sum(case when status = 'called' then 1 else 0 end) as called_count,
        sum(case when status = 'self' then 1 else 0 end) as self_count,
        sum(case when note <> '' then 1 else 0 end) as memo_count
      from " . IEUM_VEHICLE_BOARDING_TABLE . "
     where academy_id = '{$academy_id}'
       and journal_date = '{$today}'
       and (note <> '' or status in ('missed', 'called', 'self'))
       and resolved_at is null
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
    select bl.log_id, bl.status, bl.note, bl.checked_at, bl.ride_type, s.student_name, r.vehicle_label, r.route_name, st.stop_name, st.stop_time
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

$birthday_month_count = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and birth_date is not null
       and birth_date <> '0000-00-00'
       and month(birth_date) = month('{$today}')
", false);

$birthday_upcoming_count = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and birth_date is not null
       and birth_date <> '0000-00-00'
       and str_to_date(concat(year('{$today}'), date_format(birth_date, '-%m-%d')), '%Y-%m-%d')
           between '{$today}' and date_add('{$today}', interval 7 day)
", false);

$long_absent_count = sql_fetch("
    select count(*) as cnt
      from (
        select s.student_id,
               max(a.attendance_date) as last_attendance,
               coalesce(s.admission_date, date(s.created_at)) as base_date
          from " . IEUM_STUDENT_TABLE . " s
     left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id
           and a.student_id = s.student_id
         where s.academy_id = '{$academy_id}'
           and s.is_active = 1
      group by s.student_id
        having (last_attendance is null and datediff('{$today}', base_date) >= 14)
            or (last_attendance is not null and datediff('{$today}', last_attendance) >= 14)
      ) t
", false);

$long_absent_students = sql_query("
    select s.student_name, s.student_code, c.class_name, c.start_time,
           max(a.attendance_date) as last_attendance,
           coalesce(s.admission_date, date(s.created_at)) as base_date
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id
       and a.student_id = s.student_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
  group by s.student_id
    having (last_attendance is null and datediff('{$today}', base_date) >= 14)
        or (last_attendance is not null and datediff('{$today}', last_attendance) >= 14)
  order by coalesce(last_attendance, base_date) asc, s.student_name asc
     limit 8
", false);

$birthday_students = sql_query("
    select student_name, student_code, birth_date, school_name, grade_group
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and birth_date is not null
       and birth_date <> '0000-00-00'
       and month(birth_date) = month('{$today}')
  order by day(birth_date) asc, student_name asc
     limit 12
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>아이이음 대시보드</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-end;margin-bottom:18px;flex-wrap:wrap}h1{margin:0;font-size:30px}h2{margin:0 0 14px;font-size:20px}.meta{color:#5b6472;margin-top:6px}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:9px 13px;font-weight:800;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.tablet{background:#0f766e;border-color:#0f766e;color:#fff}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}.card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;box-shadow:0 8px 20px rgba(15,23,42,.06)}.label{font-size:14px;color:#667085}.num{font-size:34px;font-weight:900;margin-top:4px}.hint{color:#667085;font-size:13px;margin-top:6px}.main{display:grid;grid-template-columns:1.35fr .95fr;gap:18px}.today-stack{display:grid;gap:18px}table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px}th{background:#72829d;color:#fff}.links{display:grid;gap:12px}.link-section{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:14px;box-shadow:0 8px 20px rgba(15,23,42,.05)}.link-section h2{font-size:17px;margin-bottom:10px}.link-list{display:grid;gap:8px}.link-card{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:12px;border:1px solid #d9dee7;border-radius:8px;text-decoration:none;color:#111827;background:#fff}.link-card:hover{background:#f8fbff;border-color:#b9c9e4}.link-card strong{font-size:16px}.link-card span{color:#667085;font-size:13px;text-align:right}.pending{color:#9a5b00;font-weight:800}.sent{color:#176b2c;font-weight:800}.failed{color:#a4262c;font-weight:800}.todo-list{display:grid;gap:10px}.todo{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #d9dee7;border-radius:8px;padding:12px;background:#fff;color:#111827;text-decoration:none}.todo strong{font-size:16px}.todo span{color:#667085;font-size:13px}.todo.warn{border-color:#f4c27a;background:#fffaf0}.todo.danger{border-color:#efb2b2;background:#fff5f5}.todo-form{margin:0}.class-bars{display:grid;gap:10px}.class-row{display:grid;grid-template-columns:120px 1fr 70px;gap:10px;align-items:center}.bar-track{height:10px;background:#eef2f7;border-radius:999px;overflow:hidden}.bar-fill{height:100%;background:#1769c2;border-radius:999px}.vehicle-notes{display:grid;gap:10px}.vehicle-note{border:1px solid #d9dee7;border-radius:8px;background:#fff;padding:12px}.vehicle-note strong{display:block}.vehicle-note span{display:block;color:#667085;font-size:13px;margin-top:3px}.vehicle-note.missed{border-color:#efb2b2;background:#fff5f5}.vehicle-note.called{border-color:#f4c27a;background:#fffaf0}.vehicle-note-form{margin:0}.vehicle-note-form .btn{width:100%;margin-top:8px;min-height:34px;padding:6px 10px;background:#0f766e;border-color:#0f766e;color:#fff}.notice{padding:12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}@media (max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}.main{grid-template-columns:1fr}.ieum-user{margin-left:0}}@media (max-width:520px){.grid{grid-template-columns:1fr}.actions .btn{width:100%}.link-card{align-items:flex-start;flex-direction:column}.link-card span{text-align:left}}
</style>
<style>
.tuition-overview{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:center;margin-bottom:18px}.tuition-pill{border:1px solid #d9dee7;border-radius:8px;background:#fff;padding:14px}.tuition-pill strong{display:block;font-size:24px}.tuition-pill span{display:block;color:#667085;font-size:13px;margin-top:4px}.tuition-pill.warn{border-color:#f4c27a;background:#fffaf0}.tuition-pill.danger{border-color:#efb2b2;background:#fff5f5}.tuition-actions{display:grid;gap:8px}@media(max-width:900px){.tuition-overview{grid-template-columns:1fr 1fr}.tuition-actions{grid-column:1 / -1}}@media(max-width:520px){.tuition-overview{grid-template-columns:1fr}}
</style>
<style>
.vehicle-overview{display:grid;grid-template-columns:repeat(4,1fr) auto;gap:10px;align-items:center;margin-bottom:18px}.vehicle-pill{border:1px solid #d9dee7;border-radius:8px;background:#fff;padding:13px}.vehicle-pill strong{display:block;font-size:24px}.vehicle-pill span{display:block;color:#667085;font-size:13px;margin-top:4px}.vehicle-pill.warn{border-color:#f4c27a;background:#fffaf0}.vehicle-pill.danger{border-color:#efb2b2;background:#fff5f5}.vehicle-actions{display:grid;gap:8px}.vehicle-note-head{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}.vehicle-note-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef2f7;color:#344054;padding:4px 7px;font-size:12px;font-weight:900;white-space:nowrap}.vehicle-note-meta{display:grid;gap:3px;margin-top:8px}.vehicle-note .memo-line{color:#111827;font-weight:800}.vehicle-note.self{border-color:#bfdbfe;background:#f7fbff}@media(max-width:900px){.vehicle-overview{grid-template-columns:1fr 1fr}.vehicle-actions{grid-column:1/-1}}@media(max-width:520px){.vehicle-overview{grid-template-columns:1fr}.vehicle-note-head{display:grid}}
</style>
<style>
.daily-focus{display:grid;grid-template-columns:1.15fr 1fr;gap:16px;margin-bottom:18px}.focus-panel{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.focus-panel h2{margin-bottom:6px}.focus-copy{color:#667085;margin:0 0 14px;line-height:1.45}.focus-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.focus-action{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #d9dee7;border-radius:10px;background:#fff;color:#111827;text-decoration:none;padding:14px}.focus-action:hover{border-color:#9bb7df;background:#f8fbff}.focus-action strong{display:block;font-size:17px}.focus-action span{display:block;color:#667085;font-size:13px;margin-top:4px}.focus-count{font-size:24px;font-weight:900;white-space:nowrap}.focus-action.warn{border-color:#f4c27a;background:#fffaf0}.focus-action.danger{border-color:#efb2b2;background:#fff5f5}.start-lane{display:grid;gap:8px}.start-step{display:grid;grid-template-columns:34px 1fr auto;gap:10px;align-items:center;border:1px solid #d9dee7;border-radius:10px;background:#fff;color:#111827;text-decoration:none;padding:12px}.start-step:hover{border-color:#9bb7df;background:#f8fbff}.step-no{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:999px;background:#1769c2;color:#fff;font-weight:900}.start-step strong{display:block}.start-step span{display:block;color:#667085;font-size:13px;margin-top:3px}.step-go{color:#1769c2;font-weight:900}.shortcut-settings{margin-top:10px;border:1px solid #d9dee7;border-radius:10px;background:#f8fafc}.shortcut-settings summary{cursor:pointer;padding:10px 12px;font-weight:900;color:#1769c2}.shortcut-settings summary::-webkit-details-marker{display:none}.shortcut-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:0 12px 12px}.shortcut-option{display:flex;gap:7px;align-items:center;border:1px solid #d9dee7;border-radius:999px;background:#fff;padding:8px 10px;font-size:13px;font-weight:800}.shortcut-option input{width:auto}.shortcut-help{grid-column:1/-1;color:#667085;font-size:12px;line-height:1.4}.shortcut-settings .btn{grid-column:1/-1;width:100%}@media(max-width:900px){.daily-focus{grid-template-columns:1fr}.focus-actions{grid-template-columns:1fr 1fr}}@media(max-width:520px){.focus-actions{grid-template-columns:1fr}.start-step{grid-template-columns:30px 1fr}.step-go{display:none}.shortcut-options{grid-template-columns:1fr}}
</style>
<style>
.auto-check{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06);margin-bottom:18px}.auto-check-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-end;margin-bottom:14px;flex-wrap:wrap}.auto-check-head h2{margin-bottom:4px}.auto-check-head p{margin:0;color:#667085;line-height:1.45}.auto-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.auto-card{border:1px solid #d9dee7;border-radius:10px;padding:14px;background:#fff;color:#111827;text-decoration:none}.auto-card:hover{border-color:#9bb7df;background:#f8fbff}.auto-card.warn{border-color:#f4c27a;background:#fffaf0}.auto-card.danger{border-color:#efb2b2;background:#fff5f5}.auto-label{color:#667085;font-size:13px;font-weight:900}.auto-number{display:block;font-size:30px;font-weight:1000;margin:4px 0}.auto-card p{margin:0;color:#475467;font-size:13px;line-height:1.45}.auto-list{display:grid;gap:7px;margin-top:12px}.auto-list-row{display:flex;justify-content:space-between;gap:10px;border-top:1px solid #edf1f7;padding-top:7px;font-size:13px}.auto-list-row strong{font-size:14px}.auto-list-row span{color:#667085;text-align:right}.auto-empty{color:#667085;font-size:13px;margin-top:10px}@media(max-width:900px){.auto-grid{grid-template-columns:1fr 1fr}}@media(max-width:560px){.auto-grid{grid-template-columns:1fr}.auto-list-row{display:grid}.auto-list-row span{text-align:left}}
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

    <section class="daily-focus">
        <article class="focus-panel">
            <h2>오늘 운영 체크</h2>
            <p class="focus-copy">관장님이 매일 먼저 보면 되는 항목입니다. 위험한 것부터 확인하고 바로 처리하면 됩니다.</p>
            <div class="focus-actions">
                <a class="focus-action <?php echo (int) $missing_today['cnt'] ? 'warn' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">
                    <div><strong>미등원 확인</strong><span>오늘 등원 예정인데 아직 안 온 학생</span></div>
                    <span class="focus-count"><?php echo number_format((int) $missing_today['cnt']); ?>명</span>
                </a>
                <a class="focus-action <?php echo (int) $tuition['unpaid_over_count'] ? 'danger' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php">
                    <div><strong>수련비 미결제</strong><span><?php echo (int) $tuition_settings['overdue_after_days']; ?>일 초과 관리 대상</span></div>
                    <span class="focus-count"><?php echo number_format((int) $tuition['unpaid_over_count']); ?>명</span>
                </a>
                <a class="focus-action <?php echo (int) $sms['failed_count'] ? 'danger' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php?status=failed">
                    <div><strong>문자 실패</strong><span>보호자 안내 실패 확인</span></div>
                    <span class="focus-count"><?php echo number_format((int) $sms['failed_count']); ?>건</span>
                </a>
                <a class="focus-action <?php echo (int) $vehicle_note_count['cnt'] ? 'warn' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php">
                    <div><strong>차량 메모</strong><span>기사님 탑승확인 특이사항</span></div>
                    <span class="focus-count"><?php echo number_format((int) $vehicle_note_count['cnt']); ?>건</span>
                </a>
            </div>
        </article>
        <article class="focus-panel">
            <h2><?php echo $show_onboarding_flow ? '처음 쓰는 도장 흐름' : '자주 쓰는 바로가기'; ?></h2>
            <p class="focus-copy"><?php echo $show_onboarding_flow ? '처음엔 가볍게 시작하고, 필요한 만큼만 깊게 들어가면 됩니다.' : '초기 세팅이 끝난 뒤에는 매일 쓰는 화면만 빠르게 열면 됩니다.'; ?></p>
            <div class="start-lane">
                <?php if ($show_onboarding_flow) { ?>
                <a class="start-step" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form"><span class="step-no">1</span><div><strong>학생 등록</strong><span>학생번호와 보호자 연락처부터 입력</span></div><span class="step-go">열기</span></a>
                <a class="start-step" href="<?php echo IEUM_URL; ?>/admin/tablet_devices.php"><span class="step-no">2</span><div><strong>출석기 연결</strong><span>도장 코드와 PIN으로 태블릿 연결</span></div><span class="step-go">열기</span></a>
                <a class="start-step" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php"><span class="step-no">3</span><div><strong>오늘 출석 확인</strong><span>등원, 미등원, 문자 상태 확인</span></div><span class="step-go">열기</span></a>
                <?php } else { ?>
                <?php $shortcut_no = 0; foreach ($dashboard_shortcuts as $shortcut) { $shortcut_no++; ?>
                <a class="start-step" href="<?php echo $shortcut['url']; ?>"><span class="step-no"><?php echo $shortcut_no; ?></span><div><strong><?php echo get_text($shortcut['label']); ?></strong><span><?php echo get_text($shortcut['desc']); ?></span></div><span class="step-go">열기</span></a>
                <?php } ?>
                <?php } ?>
            </div>
            <?php if (!$show_onboarding_flow) { ?>
            <details class="shortcut-settings">
                <summary>자주 쓰는 바로가기 설정</summary>
                <form method="post" class="shortcut-options">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="save_dashboard_shortcuts">
                    <?php foreach ($dashboard_shortcut_catalog as $shortcut_key => $shortcut) { ?>
                    <label class="shortcut-option">
                        <input type="checkbox" name="shortcut_keys[]" value="<?php echo get_text($shortcut_key); ?>" <?php echo in_array($shortcut_key, $dashboard_shortcut_keys, true) ? 'checked' : ''; ?>>
                        <?php echo get_text($shortcut['label']); ?>
                    </label>
                    <?php } ?>
                    <div class="shortcut-help">최대 8개까지 대시보드 상단에 표시됩니다. 처음엔 기본값으로 시작하고, 도장 운영 방식에 맞게 바꾸면 됩니다.</div>
                    <button type="submit" class="btn primary">바로가기 저장</button>
                </form>
            </details>
            <?php } ?>
        </article>
    </section>

    <section class="auto-check">
        <div class="auto-check-head">
            <div>
                <h2>아이이음 자동 체크</h2>
                <p>관장님이 직접 기억하지 않아도 미리 챙길 일을 알려드립니다.</p>
            </div>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/operations.php">운영 지표 보기</a>
        </div>
        <div class="auto-grid">
            <a class="auto-card <?php echo (int) $birthday_month_count['cnt'] ? 'warn' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/students.php?insight=birthday_month">
                <span class="auto-label">이번 달 생일자</span>
                <strong class="auto-number"><?php echo number_format((int) $birthday_month_count['cnt']); ?>명</strong>
                <p>앞으로 7일 안에 생일인 학생은 <?php echo number_format((int) $birthday_upcoming_count['cnt']); ?>명입니다.</p>
            </a>
            <a class="auto-card <?php echo (int) $long_absent_count['cnt'] ? 'danger' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/students.php?insight=long_absent">
                <span class="auto-label">장기 미등원 신호</span>
                <strong class="auto-number"><?php echo number_format((int) $long_absent_count['cnt']); ?>명</strong>
                <p>최근 14일 이상 출석 기록이 없어 상담 확인이 필요한 학생입니다.</p>
            </a>
            <a class="auto-card <?php echo $tuition_notice_pending_count ? 'warn' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/sms_templates.php">
                <span class="auto-label">오늘 자동 안내 예정</span>
                <strong class="auto-number"><?php echo number_format($tuition_notice_pending_count); ?>건</strong>
                <p>수련비 납부일/미납 기준에 따라 문자 발송 대상이 잡힌 건수입니다.</p>
            </a>
        </div>
        <div class="auto-list">
            <?php $lai = 0; while ($row = sql_fetch_array($long_absent_students)) { $lai++; ?>
            <?php
            $last_base = $row['last_attendance'] ?: $row['base_date'];
            $absent_days = $last_base ? max(0, floor((strtotime($today) - strtotime($last_base)) / 86400)) : 0;
            ?>
            <div class="auto-list-row">
                <strong><?php echo get_text($row['student_name'] . ' (' . $row['student_code'] . ')'); ?></strong>
                <span><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '부 미지정'); ?> · <?php echo $row['last_attendance'] ? '최근 출석 ' . get_text($row['last_attendance']) : '출석 기록 없음'; ?> · <?php echo number_format($absent_days); ?>일</span>
            </div>
            <?php } ?>
            <?php if ($lai === 0) { ?><div class="auto-empty">장기 미등원 신호가 없습니다.</div><?php } ?>
        </div>
    </section>

    <section class="grid">
        <article class="card"><div class="label">오늘 등원 예정</div><div class="num"><?php echo number_format((int) $expected_today['cnt']); ?></div><div class="hint">전체 사용 학생 <?php echo number_format((int) $student['cnt']); ?>명</div></article>
        <article class="card"><div class="label">오늘 출석</div><div class="num"><?php echo number_format((int) $attendance['cnt']); ?></div><div class="hint">예정 대비 출석</div></article>
        <article class="card"><div class="label">아직 미등원</div><div class="num"><?php echo number_format((int) $missing_today['cnt']); ?></div><div class="hint">선택 요일 기준</div></article>
        <article class="card"><div class="label">문자 대기 / 실패</div><div class="num"><?php echo number_format((int) $sms['pending_count']); ?> / <?php echo number_format((int) $sms['failed_count']); ?></div><div class="hint">오늘 발송 <?php echo number_format((int) $sms['sent_today_count']); ?>건</div></article>
    </section>

    <section class="grid">
        <article class="card"><div class="label"><?php echo get_text($billing_month); ?> 수련비 결제</div><div class="num"><?php echo number_format((int) $tuition['paid_count']); ?>명</div><div class="hint"><?php echo number_format((int) $tuition['paid_amount']); ?>원 입금 기록</div></article>
        <article class="card"><div class="label">오늘 납부 예정</div><div class="num"><?php echo number_format((int) $tuition['due_today_count']); ?></div><div class="hint">오늘 결제일인 학생</div></article>
        <article class="card"><div class="label">미결제 5일 이하</div><div class="num"><?php echo number_format((int) $tuition['unpaid_soon_count']); ?></div><div class="hint">결제일 경과 0~5일</div></article>
        <article class="card"><div class="label">미납 <?php echo (int) $tuition_settings['overdue_after_days']; ?>일 초과</div><div class="num"><?php echo number_format((int) $tuition['unpaid_over_count']); ?></div><div class="hint">관리자 확인 필요</div></article>
        <article class="card"><div class="label">오늘 수련비 문자 예정</div><div class="num"><?php echo number_format($tuition_notice_pending_count); ?></div><div class="hint">납부일/미납 자동문자</div></article>
    </section>

    <section class="tuition-overview">
        <article class="tuition-pill <?php echo (int) $tuition['due_today_count'] ? 'warn' : ''; ?>">
            <strong><?php echo number_format((int) $tuition['due_today_count']); ?>명</strong>
            <span>오늘 납부일 학생</span>
        </article>
        <article class="tuition-pill <?php echo (int) $tuition['unpaid_over_count'] ? 'danger' : ''; ?>">
            <strong><?php echo number_format((int) $tuition['unpaid_over_count']); ?>명</strong>
            <span>미납 <?php echo (int) $tuition_settings['overdue_after_days']; ?>일 초과</span>
        </article>
        <article class="tuition-pill <?php echo $tuition_notice_pending_count ? 'warn' : ''; ?>">
            <strong><?php echo number_format($tuition_notice_pending_count); ?>건</strong>
            <span>오늘 문자 발송 예정</span>
        </article>
        <div class="tuition-actions">
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php">수련비 처리</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/sms_templates.php">문구/자동발송 설정</a>
        </div>
    </section>

    <section class="vehicle-overview">
        <article class="vehicle-pill <?php echo (int) $vehicle_note_summary['missed_count'] ? 'danger' : ''; ?>">
            <strong><?php echo number_format((int) $vehicle_note_summary['missed_count']); ?>건</strong>
            <span>미탑승</span>
        </article>
        <article class="vehicle-pill <?php echo (int) $vehicle_note_summary['called_count'] ? 'warn' : ''; ?>">
            <strong><?php echo number_format((int) $vehicle_note_summary['called_count']); ?>건</strong>
            <span>보호자 통화</span>
        </article>
        <article class="vehicle-pill">
            <strong><?php echo number_format((int) $vehicle_note_summary['self_count']); ?>건</strong>
            <span>개별 이동</span>
        </article>
        <article class="vehicle-pill <?php echo (int) $vehicle_note_summary['memo_count'] ? 'warn' : ''; ?>">
            <strong><?php echo number_format((int) $vehicle_note_summary['memo_count']); ?>건</strong>
            <span>차량 메모</span>
        </article>
        <div class="vehicle-actions">
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php">탑승 확인</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_journal.php">차량 일지</a>
        </div>
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
                    <form method="post" class="todo todo-form <?php echo (int) $missing_today['cnt'] ? 'warn' : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="run_absent_alerts">
                        <div><strong>미등원 알림 생성</strong><span>수업 시작 후 설정 시간이 지난 반만 관리자 문자 큐 생성</span></div>
                        <button type="submit" class="btn">실행</button>
                    </form>
                    <a class="todo <?php echo (int) $vehicle_note_count['cnt'] ? 'warn' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php">
                        <div><strong>차량 메모 확인</strong><span>기사님 탑승확인에서 올라온 특이사항</span></div>
                        <strong><?php echo number_format((int) $vehicle_note_count['cnt']); ?>건</strong>
                    </a>
                    <a class="todo <?php echo (int) $sms['failed_count'] ? 'danger' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php?status=failed">
                        <div><strong>문자 실패 확인</strong><span>보호자 알림 중 실패한 건</span></div>
                        <strong><?php echo number_format((int) $sms['failed_count']); ?>건</strong>
                    </a>
                    <a class="todo <?php echo (int) $tuition['unpaid_over_count'] ? 'danger' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php">
                        <div><strong>수련비 미결제 확인</strong><span><?php echo (int) $tuition_settings['overdue_after_days']; ?>일 초과 미결제 학생</span></div>
                        <strong><?php echo number_format((int) $tuition['unpaid_over_count']); ?>명</strong>
                    </a>
                    <a class="todo <?php echo $tuition_notice_pending_count ? 'warn' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/sms_templates.php">
                        <div><strong>수련비 문자 예정</strong><span>오늘 자동 안내 대상. 설정은 문자 템플릿에서 조정</span></div>
                        <strong><?php echo number_format($tuition_notice_pending_count); ?>건</strong>
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
                        <div class="vehicle-note-head">
                            <strong><?php echo get_text($note['student_name']); ?></strong>
                            <span class="vehicle-note-badge"><?php echo get_text(ieum_dashboard_boarding_status_label($note['status'])); ?></span>
                        </div>
                        <div class="vehicle-note-meta">
                            <span><?php echo get_text(trim(($note['vehicle_label'] ?: '차량 미지정') . ' / ' . ($note['route_name'] ?: '노선 미지정'))); ?></span>
                            <span><?php echo get_text(($note['ride_type'] === 'dropoff' ? '하원' : '등원') . ' · ' . trim(($note['stop_time'] ?: '') . ' ' . ($note['stop_name'] ?: '')) . ($note['checked_at'] ? ' · ' . substr($note['checked_at'], 11, 5) : '')); ?></span>
                            <?php if ($note['note'] !== '') { ?><span class="memo-line"><?php echo get_text($note['note']); ?></span><?php } ?>
                        </div>
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
            <article class="card">
                <h2>이번 달 생일자</h2>
                <table>
                    <thead><tr><th>생일</th><th>학생</th><th>학교</th></tr></thead>
                    <tbody>
                    <?php $bi = 0; while ($row = sql_fetch_array($birthday_students)) { $bi++; ?>
                    <tr>
                        <td><?php echo get_text(date('m-d', strtotime($row['birth_date']))); ?></td>
                        <?php $birthday_grade = ieum_dashboard_grade_from_birth_date($row['birth_date']) ?: $row['grade_group']; ?>
                        <td><?php echo get_text($row['student_name'] . ' (' . ieum_dashboard_grade_label($birthday_grade) . ')'); ?></td>
                        <td><?php echo get_text($row['school_name'] ?: '-'); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if ($bi === 0) { ?><tr><td colspan="3">이번 달 생일자가 없습니다.</td></tr><?php } ?>
                    </tbody>
                </table>
            </article>
            <section class="link-section">
                <h2>학원 운영</h2>
                <div class="link-list">
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/operations.php"><strong>운영 지표</strong><span>신규/휴관/상담 신호</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/growth_report.php"><strong>원생 리포트</strong><span>월별 원생 흐름</span></a>
                </div>
            </section>
            <section class="link-section">
                <h2>학생/출석</h2>
                <div class="link-list">
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/students.php"><strong>학생 관리</strong><span>등록/수정/중지</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/student_groups.php"><strong>부별 학생</strong><span>수업 부별 확인</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php"><strong>오늘 출석</strong><span>등원 현황 확인</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/tablet_devices.php"><strong>출석기 관리</strong><span>앱 연결/해제</span></a>
                </div>
            </section>
            <section class="link-section">
                <h2>수련비/문자</h2>
                <div class="link-list">
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php"><strong>수련비 납부</strong><span>월별 결제/미결제</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php"><strong>문자 큐</strong><span>발송 상태 확인</span></a>
                </div>
            </section>
            <section class="link-section">
                <h2>인성 리포트</h2>
                <div class="link-list">
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/character.php"><strong>인성 입력</strong><span>부별 주간 입력</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/character_mission.php"><strong>아이잘해 미션</strong><span>가정 실천 체크</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/character_report.php"><strong>월간 인성</strong><span>학부모 리포트</span></a>
                </div>
            </section>
            <section class="link-section">
                <h2>차량</h2>
                <div class="link-list">
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/vehicles.php"><strong>차량 관리</strong><span>호차/노선/정류장</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/vehicle_assignments.php"><strong>배정 현황</strong><span>호차별 학생 배정</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php"><strong>탑승 확인</strong><span>기사님 모바일 기록</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/vehicle_journal.php"><strong>차량 일지</strong><span>인쇄용 운행표</span></a>
                </div>
            </section>
            <section class="link-section">
                <h2>학원 설정</h2>
                <div class="link-list">
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/programs.php"><strong>프로그램 설정</strong><span>태권도/합기도/줄넘기</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/class_times.php"><strong>수업 시간표</strong><span>부별 수업 시간</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/school_calendar.php"><strong>수업일 설정</strong><span>휴관/보충수업</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/tuition.php"><strong>수련비 정책</strong><span>주 횟수별 금액</span></a>
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/admin/sms_templates.php"><strong>문자 템플릿</strong><span>안내 문구 설정</span></a>
                </div>
            </section>
            <?php if ($is_admin === 'super') { ?>
            <section class="link-section">
                <h2>본사 관리</h2>
                <div class="link-list">
                    <a class="link-card" href="<?php echo IEUM_URL; ?>/project_status.php"><strong>프로젝트 진행</strong><span>작업 요청과 결과 확인</span></a>
                </div>
            </section>
            <?php } ?>
        </aside>
    </section>
</main>
</body>
</html>
