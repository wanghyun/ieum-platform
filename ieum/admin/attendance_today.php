<?php
$sub_menu = '950110';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/sms_queue.php';
require_once IEUM_PATH . '/lib/attendance.php';
require_once IEUM_PATH . '/lib/tuition.php';
require_once IEUM_PATH . '/lib/dashboard.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 오늘 출석';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

if (function_exists('ieum_promotion_ensure_schema')) {
    ieum_promotion_ensure_schema();
}

function ieum_today_grade_label($value)
{
    $labels = array(
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
        'adult' => '성인',
    );

    return isset($labels[$value]) ? $labels[$value] : ($value ?: '-');
}

function ieum_today_phone($value)
{
    $digits = preg_replace('/[^0-9]/', '', (string) $value);
    if (strlen($digits) === 11) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 4) . '-' . substr($digits, 7);
    }
    if (strlen($digits) === 10) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }
    return $value;
}

function ieum_today_phone_text($value)
{
    $value = (string) $value;
    $out = '';
    $digits = '';
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $char = $value[$i];
        if ($char >= '0' && $char <= '9') {
            $digits .= $char;
            continue;
        }
        if ($digits !== '') {
            $out .= (strlen($digits) >= 10 && strlen($digits) <= 11 && substr($digits, 0, 1) === '0')
                ? ieum_today_phone($digits)
                : $digits;
            $digits = '';
        }
        $out .= $char;
    }
    if ($digits !== '') {
        $out .= (strlen($digits) >= 10 && strlen($digits) <= 11 && substr($digits, 0, 1) === '0')
            ? ieum_today_phone($digits)
            : $digits;
    }

    return $out;
}

function ieum_today_attendance_days_label($value)
{
    $map = array(
        'mon' => '월',
        'tue' => '화',
        'wed' => '수',
        'thu' => '목',
        'fri' => '금',
        'sat' => '토',
        'sun' => '일',
    );
    $parts = array_filter(array_map('trim', explode(',', (string) $value)));
    $labels = array();
    foreach ($parts as $part) {
        if (isset($map[$part])) {
            $labels[] = $map[$part];
        }
    }

    return $labels ? implode(', ', $labels) : '-';
}

function ieum_today_student_status_label($value)
{
    $labels = array(
        'enrolled' => '재원',
        'paused' => '휴관',
        'left' => '퇴관',
    );

    return isset($labels[$value]) ? $labels[$value] : '사용';
}

function ieum_today_tuition_label($row)
{
    $amount_due = isset($row['tuition_amount_due']) ? (int) $row['tuition_amount_due'] : 0;
    $amount_paid = isset($row['tuition_amount_paid']) ? (int) $row['tuition_amount_paid'] : 0;
    $status = isset($row['tuition_status']) ? (string) $row['tuition_status'] : '';
    $student_amount = isset($row['tuition_amount']) ? (int) $row['tuition_amount'] : 0;
    $remain = max(0, $amount_due - $amount_paid);

    if ($amount_due <= 0) {
        return $student_amount > 0 ? '월 ' . number_format($student_amount) . '원' : '-';
    }
    if ($remain <= 0 || $status === 'paid') {
        return '정상 · 청구 ' . number_format($amount_due) . '원';
    }
    if ($amount_paid > 0) {
        return '미납 ' . number_format($remain) . '원 · 납부 ' . number_format($amount_paid) . '원';
    }

    return '미납 ' . number_format($remain) . '원';
}

function ieum_today_promotion_rank_display($academy, $status, $rank = null)
{
    if (empty($status['enabled'])) {
        return '승급/승품 제외';
    }

    $belt = '';
    $poom_dan = 0;
    $grade_level = 0;
    if (is_array($rank)) {
        $belt = isset($rank['belt']) ? trim((string) $rank['belt']) : '';
        $poom_dan = isset($rank['poom_dan']) ? (int) $rank['poom_dan'] : 0;
        $grade_level = isset($rank['grade_level']) ? (int) $rank['grade_level'] : 0;
    } else {
        $belt = isset($status['belt']) ? trim((string) $status['belt']) : '';
        $poom_dan = isset($status['poom_dan']) ? (int) $status['poom_dan'] : 0;
        $grade_level = isset($status['grade_level']) ? (int) $status['grade_level'] : 0;
    }

    if ($belt === '' && function_exists('ieum_promotion_belt_for_grade')) {
        $belt = ieum_promotion_belt_for_grade($academy, $grade_level, $poom_dan);
    }

    $rank_label = ($poom_dan <= 0 && $grade_level <= 0)
        ? '0품/단 0급'
        : ieum_promotion_full_rank_label($poom_dan, $grade_level);

    return trim(($belt !== '' ? $belt . ' ' : '') . $rank_label);
}

function ieum_today_student_detail_payload($academy_id, $row)
{
    $class_label = isset($row['class_name']) && $row['class_name']
        ? $row['class_name'] . ' ' . (isset($row['start_time']) ? $row['start_time'] : '')
        : '미지정';
    $guardian = isset($row['guardian_info']) ? ieum_today_phone_text(trim((string) $row['guardian_info'])) : '';
    $memo = isset($row['memo']) ? trim((string) $row['memo']) : '';
    $admission_date = isset($row['admission_date']) && $row['admission_date'] && $row['admission_date'] !== '0000-00-00'
        ? $row['admission_date']
        : '-';
    $academy = isset($GLOBALS['academy']) ? $GLOBALS['academy'] : array('academy_id' => $academy_id);
    $promotion_status = function_exists('ieum_promotion_status')
        ? ieum_promotion_status($academy, $row, date('Y-m'))
        : array('enabled' => false);
    $promotion_next = isset($promotion_status['next_rank']) ? $promotion_status['next_rank'] : array('belt' => '', 'poom_dan' => 0, 'grade_level' => 0);
    $promotion_current_label = ieum_today_promotion_rank_display($academy, $promotion_status);
    $promotion_next_label = empty($promotion_status['enabled']) ? '-' : ieum_today_promotion_rank_display($academy, $promotion_status, $promotion_next);
    $vehicle = isset($row['vehicle_summary']) ? trim(str_replace('<br>', "\n", (string) $row['vehicle_summary'])) : '';

    return array(
        'id' => isset($row['student_id']) ? (int) $row['student_id'] : 0,
        'name' => isset($row['student_name']) ? $row['student_name'] : '',
        'code' => isset($row['student_code']) ? $row['student_code'] : '',
        'status' => ieum_today_student_status_label(isset($row['student_status']) ? $row['student_status'] : 'enrolled'),
        'program' => ieum_program_label($academy_id, isset($row['program_code']) ? $row['program_code'] : ''),
        'grade' => ieum_today_grade_label(isset($row['grade_group']) ? $row['grade_group'] : ''),
        'classTime' => $class_label,
        'attendanceDays' => ieum_today_attendance_days_label(isset($row['attendance_days']) ? $row['attendance_days'] : ''),
        'lastAttendance' => isset($row['last_attendance_label']) ? $row['last_attendance_label'] : '-',
        'studentPhone' => isset($row['student_phone']) && $row['student_phone'] !== '' ? ieum_today_phone($row['student_phone']) : '-',
        'tuition' => ieum_today_tuition_label($row),
        'admissionDate' => $admission_date,
        'promotionCurrent' => $promotion_current_label !== '' ? $promotion_current_label : '미지정',
        'promotionNext' => $promotion_next_label !== '' ? $promotion_next_label : '-',
        'promotionNextDate' => !empty($promotion_status['next_date']) ? $promotion_status['next_date'] : '-',
        'guardian' => $guardian,
        'vehicle' => $vehicle !== '' ? $vehicle : '차량 이용 없음',
        'memo' => $memo,
        'counselingNote' => isset($row['counseling_note']) ? $row['counseling_note'] : '',
        'editUrl' => IEUM_URL . '/admin/students.php?mode=form&student_id=' . (isset($row['student_id']) ? (int) $row['student_id'] : 0),
    );
}

function ieum_today_result_message($saved)
{
    $student_name = isset($saved['student']['student_name']) ? $saved['student']['student_name'] : '';
    if ($saved['status'] === 'created') {
        return $student_name ? $student_name . ' 원생 등원 처리 완료' : '등원 처리 완료';
    }
    if ($saved['status'] === 'duplicate') {
        return $student_name ? $student_name . ' 원생은 이미 오늘 등원 처리되었습니다.' : '이미 오늘 등원 처리되었습니다.';
    }
    if ($saved['status'] === 'needs_selection') {
        return '같은 원생번호가 있습니다. 출석기에서는 생년월일로 원생을 선택해 주세요.';
    }
    if ($saved['status'] === 'not_found') {
        return '등록된 원생번호가 아닙니다.';
    }
    if ($saved['status'] === 'forbidden') {
        return '아이이음 사용 권한이 없습니다.';
    }
    return isset($saved['message']) ? $saved['message'] : '처리 중 오류가 발생했습니다.';
}

function ieum_today_boarding_status_label($status)
{
    $labels = array(
        'boarded' => '탑승',
        'missed' => '미탑승',
        'called' => '통화',
        'pending' => '대기',
    );

    return isset($labels[$status]) ? $labels[$status] : ($status ?: '확인');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '잘못된 요청입니다. 새로고침 후 다시 시도해 주세요.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : 'save_attendance';
        if ($action === 'resolve_vehicle_note') {
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
        } else {
            $student_code = isset($_POST['student_code']) ? $_POST['student_code'] : '';
            $post_today = G5_TIME_YMD;
            $post_context = ieum_attendance_day_context($academy_id, $post_today);
            if (!empty($post_context['is_closed'])) {
                $error = '오늘은 ' . (!empty($post_context['public_holiday_label']) ? $post_context['public_holiday_label'] : '도장 휴관일') . '이라 출석 처리할 수 없습니다.';
            } else {
                $saved = ieum_save_attendance_by_code($student_code, 'admin');
                if ($saved['status'] === 'created' || $saved['status'] === 'duplicate') {
                    $message = ieum_today_result_message($saved);
                } else {
                    $error = ieum_today_result_message($saved);
                }
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$attendance_shortcut_catalog = function_exists('ieum_dashboard_shortcut_catalog') ? ieum_dashboard_shortcut_catalog() : array();
$attendance_shortcut_keys = function_exists('ieum_dashboard_get_shortcut_keys') ? ieum_dashboard_get_shortcut_keys($academy_id) : array();
$attendance_default_shortcut_keys = function_exists('ieum_dashboard_default_shortcut_keys') ? ieum_dashboard_default_shortcut_keys() : array();
$today = isset($_GET['date']) ? preg_replace('/[^0-9-]/', '', $_GET['date']) : G5_TIME_YMD;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
    $today = G5_TIME_YMD;
}
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$today_sql = sql_escape_string($today);
$program_sql = sql_escape_string($program_code);
$billing_month = substr($today, 0, 7);

$today_context = function_exists('ieum_attendance_day_context') ? ieum_attendance_day_context($academy_id, $today) : array();
$today_day = isset($today_context['weekday']) ? $today_context['weekday'] : '';
$today_day_sql = sql_escape_string($today_day);
$today_fixed_holiday = isset($today_context['public_holiday_label']) ? $today_context['public_holiday_label'] : '';
$is_today_closed = !empty($today_context['is_closed']);
$is_today_makeup = !empty($today_context['is_makeup']);
$is_today_weekday_lesson = !$is_today_closed && !$is_today_makeup && $today_day !== '';
$today_lesson_label = isset($today_context['lesson_label']) ? $today_context['lesson_label'] : '수업 대상 없음';
$today_lesson_desc = isset($today_context['lesson_desc']) ? $today_context['lesson_desc'] : '오늘 수업 기준을 확인해 주세요.';

$program_options = ieum_program_options($academy_id, true);
$class_options = array();
$class_result = sql_query("
    select *
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);
while ($class = sql_fetch_array($class_result)) {
    $class_options[] = $class;
}

$student_filter_sql = '';
if ($program_code !== '') {
    $student_filter_sql .= " and s.program_code = '{$program_sql}' ";
}
if ($class_time_id) {
    $student_filter_sql .= " and s.class_time_id = '{$class_time_id}' ";
}

$class_student_day_filter_sql = function_exists('ieum_attendance_student_day_filter_sql') ? ieum_attendance_student_day_filter_sql($academy_id, $today, 's') : " and find_in_set('{$today_day_sql}', s.attendance_days) > 0 ";
$scheduled_filter_sql = $student_filter_sql . $class_student_day_filter_sql;

ieum_tuition_ensure_month($academy_id, $billing_month);
$tuition_settings = ieum_tuition_get_settings($academy_id);
$tuition_overdue_days = max(1, min(30, (int) (isset($tuition_settings['overdue_after_days']) ? $tuition_settings['overdue_after_days'] : 5)));

$attendance_count = sql_fetch("
    select count(distinct a.attendance_id) as cnt
      from " . IEUM_ATTENDANCE_TABLE . " a
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = a.student_id and s.academy_id = a.academy_id
     where a.academy_id = '{$academy_id}'
       and a.attendance_date = '{$today_sql}'
       {$student_filter_sql}
", false);

$scheduled_count = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . " s
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       {$scheduled_filter_sql}
", false);

$missing_count = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . " s
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       {$scheduled_filter_sql}
       and not exists (
           select 1
             from " . IEUM_ATTENDANCE_TABLE . " a
            where a.academy_id = s.academy_id
              and a.student_id = s.student_id
              and a.attendance_date = '{$today_sql}'
       )
", false);

$pending = sql_fetch("
    select count(*) as pending_count
      from " . IEUM_SMS_QUEUE_TABLE . "
     where academy_id = '{$academy_id}'
       and status = 'pending'
", false);

$failed_sms = sql_fetch("
    select count(*) as failed_count
      from " . IEUM_SMS_QUEUE_TABLE . "
     where academy_id = '{$academy_id}'
       and status = 'failed'
       and date(created_at) = '{$today_sql}'
", false);

$tuition_signal = sql_fetch("
    select
        sum(case when status in ('unpaid', 'partial') and due_date = '{$today_sql}' then 1 else 0 end) as due_today_count,
        sum(case when status in ('unpaid', 'partial') and datediff('{$today_sql}', due_date) > '{$tuition_overdue_days}' then 1 else 0 end) as overdue_count
      from " . IEUM_TUITION_PAYMENT_TABLE . "
     where academy_id = '{$academy_id}'
       and billing_month = '" . sql_escape_string($billing_month) . "'
", false);

$birthday_upcoming_count = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and birth_date is not null
       and birth_date <> '0000-00-00'
       and str_to_date(concat(year('{$today_sql}'), date_format(birth_date, '-%m-%d')), '%Y-%m-%d')
           between '{$today_sql}' and date_add('{$today_sql}', interval 7 day)
", false);

$vehicle_note_count = sql_fetch("
    select count(*) as cnt
      from " . IEUM_VEHICLE_BOARDING_TABLE . "
     where academy_id = '{$academy_id}'
       and journal_date = '{$today_sql}'
       and (note <> '' or status in ('missed', 'called'))
       and resolved_at is null
", false);

$vehicle_note_rows = sql_query("
    select bl.log_id, bl.status, bl.note, bl.checked_at, bl.ride_type,
           s.student_name, r.vehicle_label, r.route_name, st.stop_name, st.stop_time
      from " . IEUM_VEHICLE_BOARDING_TABLE . " bl
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = bl.student_id and s.academy_id = bl.academy_id
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = bl.route_id and r.academy_id = bl.academy_id
 left join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = bl.stop_id and st.academy_id = bl.academy_id
     where bl.academy_id = '{$academy_id}'
       and bl.journal_date = '{$today_sql}'
       and (bl.note <> '' or bl.status in ('missed', 'called'))
       and bl.resolved_at is null
  order by bl.checked_at desc, bl.log_id desc
     limit 6
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
        having (last_attendance is null and datediff('{$today_sql}', base_date) >= 14)
            or (last_attendance is not null and datediff('{$today_sql}', last_attendance) >= 14)
      ) t
", false);

$student_select = "
           s.*,
           c.class_name,
           c.start_time,
           coalesce(tp.amount_due, 0) as tuition_amount_due,
           coalesce(tp.amount_paid, 0) as tuition_amount_paid,
           coalesce(tp.status, '') as tuition_status,
           (select group_concat(concat(g.guardian_name, ' ', g.guardian_phone) order by g.is_primary desc, g.sort_order asc separator ', ')
              from " . IEUM_STUDENT_GUARDIAN_TABLE . " g
             where g.academy_id = s.academy_id
               and g.student_id = s.student_id
               and g.is_active = 1) as guardian_info,
           (select g.guardian_phone
              from " . IEUM_STUDENT_GUARDIAN_TABLE . " g
             where g.academy_id = s.academy_id
               and g.student_id = s.student_id
               and g.is_active = 1
          order by g.is_primary desc, g.sms_attendance desc, g.sort_order asc, g.guardian_id asc
             limit 1) as guardian_phone,
           (select group_concat(concat(if(sv.ride_type = 'pickup', '등원', '하원'), ' ', ifnull(st.stop_time, ''), ' ', ifnull(st.stop_name, sv.place_name), if(r.route_name is not null and r.route_name <> '', concat(' / ', r.route_name), ''), if(sv.memo <> '', concat(' - ', sv.memo), '')) order by field(sv.ride_type, 'pickup', 'dropoff') separator '<br>')
              from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
         left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
         left join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
             where sv.academy_id = s.academy_id
               and sv.student_id = s.student_id
               and sv.is_active = 1) as vehicle_summary
";

$attendance_rows = sql_query("
    select a.checked_at, a.input_source, sq.status as sms_status,
           {$student_select}
      from " . IEUM_ATTENDANCE_TABLE . " a
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = a.student_id and s.academy_id = a.academy_id
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_SMS_QUEUE_TABLE . " sq on sq.sms_id = a.sms_queue_id
 left join " . IEUM_TUITION_PAYMENT_TABLE . " tp on tp.academy_id = s.academy_id
       and tp.student_id = s.student_id
       and tp.billing_month = '{$billing_month}'
     where a.academy_id = '{$academy_id}'
       and a.attendance_date = '{$today_sql}'
       {$student_filter_sql}
  order by a.checked_at desc, a.attendance_id desc
     limit 200
", false);

$missing_rows = sql_query("
    select {$student_select}
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_TUITION_PAYMENT_TABLE . " tp on tp.academy_id = s.academy_id
       and tp.student_id = s.student_id
       and tp.billing_month = '{$billing_month}'
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       {$scheduled_filter_sql}
       and not exists (
           select 1
             from " . IEUM_ATTENDANCE_TABLE . " a
            where a.academy_id = s.academy_id
              and a.student_id = s.student_id
              and a.attendance_date = '{$today_sql}'
       )
  order by c.sort_order asc, c.start_time asc, s.student_name asc
     limit 300
", false);

$class_rows = sql_query("
    select c.class_time_id, c.class_name, c.start_time,
           count(distinct s.student_id) as expected_count,
           count(distinct a.student_id) as attended_count
      from " . IEUM_CLASS_TIME_TABLE . " c
 left join " . IEUM_STUDENT_TABLE . " s on s.class_time_id = c.class_time_id
       and s.academy_id = c.academy_id
       and s.is_active = 1
       " . ($program_code !== '' ? " and s.program_code = '{$program_sql}' " : "") . "
       {$class_student_day_filter_sql}
 left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id
       and a.student_id = s.student_id
       and a.attendance_date = '{$today_sql}'
     where c.academy_id = '{$academy_id}'
       and c.is_active = 1
  group by c.class_time_id
  order by c.sort_order asc, c.start_time asc
", false);

$attended = (int) $attendance_count['cnt'];
$scheduled = (int) $scheduled_count['cnt'];
$missing = (int) $missing_count['cnt'];
$attendance_rate = $scheduled > 0 ? round(($attended / $scheduled) * 100) : 0;
$sms_status_labels = array(
    'pending' => '대기',
    'processing' => '처리중',
    'sent' => '완료',
    'failed' => '실패',
    '' => '-',
);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
body{margin:0;background:#f3f6fa;color:#0f172a;font-family:Arial,"Noto Sans KR",sans-serif}.ieum-main{max-width:1900px;margin:0 auto;padding:24px 28px 44px}.bar{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px}.bar h1{margin:0;font-size:34px}.muted{color:#667085}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;font-weight:900;padding:8px 13px;cursor:pointer}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.btn.dark{background:#111827;border-color:#111827;color:#fff}.btn.muted{background:#f8fafc;color:#344054}.notice{padding:12px 14px;border-radius:10px;margin:0 0 12px;font-weight:900}.notice.ok{background:#eef9f1;border:1px solid #b7e4c0;color:#176b2c}.notice.err{background:#fff1f1;border:1px solid #ffb4b4;color:#a4262c}.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:18px;box-shadow:0 14px 34px rgba(15,23,42,.05)}.lesson-status{display:flex;justify-content:space-between;gap:14px;align-items:center;margin-bottom:16px;background:#f8fbff}.lesson-status strong{font-size:19px}.quick-check{display:grid;grid-template-columns:1.1fr .9fr;gap:16px;margin-bottom:16px}.quick-input{display:grid;gap:10px}.quick-input form{display:grid;grid-template-columns:1fr auto;gap:10px}.quick-input input{height:54px;border:2px solid #111827;border-radius:10px;padding:0 16px;font-size:26px;font-weight:1000;text-align:center}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.stat-card{border:1px solid #e2e8f0;border-radius:12px;padding:14px;background:#fff;text-decoration:none;color:#0f172a}.stat-card strong{display:block;font-size:30px;margin-top:5px}.stat-card.warn{border-color:#f59e0b;background:#fffaf0}.stat-card.danger{border-color:#ef4444;background:#fff5f5}.operation-signals{margin-bottom:16px}.signal-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.signal{border:1px solid #d9dee7;border-radius:12px;background:#fff;padding:13px;text-decoration:none;color:#0f172a}.signal em{font-style:normal;display:inline-flex;border-radius:999px;background:#eef2f7;color:#344054;padding:4px 8px;font-size:12px;font-weight:1000}.signal strong{display:block;margin:8px 0 2px;font-size:16px}.signal b{font-size:28px}.signal.warn{border-color:#f59e0b;background:#fffaf0}.signal.danger{border-color:#ef4444;background:#fff5f5}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px}.filters input,.filters select{height:40px;border:1px solid #cfd6df;border-radius:8px;padding:0 10px;font-size:14px}.class-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.class-chip{border:1px solid #d9dee7;border-radius:12px;background:#fff;text-decoration:none;color:#0f172a;padding:12px}.class-chip.active{border-color:#1769c2;background:#eaf4ff}.class-head{display:flex;justify-content:space-between;font-weight:1000}.class-meta{display:flex;justify-content:space-between;color:#667085;font-size:13px;margin:8px 0}.class-bar{height:8px;border-radius:999px;background:#edf2f7;overflow:hidden}.class-fill{height:100%;background:#1769c2}.grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(360px,.85fr);gap:16px}.section-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}.section-head h2{margin:0;font-size:22px}.section-head p{margin:5px 0 0;color:#667085;line-height:1.45}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px}th{background:#72829d;color:#fff}td.left{text-align:left}.student-link-button{border:0;background:transparent;color:#1769c2;font:inherit;font-weight:1000;cursor:pointer}.student-link-button:hover{text-decoration:underline}.status-sent{color:#008a31;font-weight:1000}.status-failed{color:#c1121f;font-weight:1000}.status-pending{color:#9a5b00;font-weight:1000}.missing-tools{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:10px}.missing-tools input{height:38px;border:1px solid #cfd6df;border-radius:8px;padding:0 10px;min-width:230px}.missing-class-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.missing-class-tabs button{border:1px solid #d8dee9;border-radius:999px;background:#fff;padding:7px 10px;font-weight:900;cursor:pointer}.missing-class-tabs button.active{background:#1769c2;border-color:#1769c2;color:#fff}.missing-list{display:grid;gap:10px;max-height:720px;overflow:auto;padding-right:4px}.missing-item{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:12px;display:grid;gap:8px}.missing-name{display:flex;justify-content:space-between;gap:10px;font-weight:1000}.missing-meta{color:#667085;line-height:1.45}.missing-actions{display:flex;gap:6px;flex-wrap:wrap}.is-filtered,.is-collapsed{display:none!important}.empty{border:1px dashed #cfd6df;border-radius:12px;background:#fff;padding:18px;text-align:center;color:#667085;font-weight:900}.vehicle-notes{margin:16px 0}.vehicle-list{display:grid;gap:8px}.vehicle-note{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff}.vehicle-note p{margin:2px 0 0;color:#667085}.detail-backdrop[hidden],.detail-modal[hidden],.edit-backdrop[hidden],.edit-modal[hidden]{display:none!important}.detail-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.36);z-index:80;opacity:0;transition:opacity .16s ease}.detail-backdrop.open{opacity:1}.detail-modal{position:fixed;top:50%;left:50%;transform:translate(-50%,-48%) scale(.98);width:min(760px,calc(100vw - 32px));max-height:calc(100vh - 48px);overflow:auto;background:#fff;border:1px solid #d9dee7;border-radius:18px;box-shadow:0 28px 80px rgba(15,23,42,.28);z-index:90;opacity:0;transition:opacity .16s ease,transform .16s ease}.detail-modal.open{opacity:1;transform:translate(-50%,-50%) scale(1)}.detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 22px;border-bottom:1px solid #edf1f7;background:linear-gradient(135deg,#f8fbff,#fff)}.detail-head h2{margin:0;font-size:26px}.detail-code{margin-top:4px;color:#667085;font-weight:900}.detail-close{border:0;background:#111827;color:#fff;border-radius:999px;width:36px;height:36px;font-size:22px;line-height:1;cursor:pointer}.detail-body{padding:20px 22px;display:grid;gap:16px}.detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.detail-field{border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:11px}.detail-field strong{display:block;color:#667085;font-size:12px;margin-bottom:4px}.detail-field span{font-weight:900;line-height:1.35}.detail-section{border:1px solid #e2e8f0;border-radius:12px;padding:14px;background:#fff}.detail-section h3{margin:0 0 8px;font-size:16px}.detail-section p{margin:0;white-space:pre-line;line-height:1.55;color:#344054}.detail-empty{color:#98a2b3!important}.detail-actions{display:flex;gap:8px;justify-content:flex-end;padding:16px 22px;border-top:1px solid #edf1f7;background:#f8fafc}.edit-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:100;opacity:0;transition:opacity .16s ease}.edit-backdrop.open{opacity:1}.edit-modal{position:fixed;inset:32px 54px;max-width:1280px;margin:0 auto;background:#fff;border:1px solid #d9dee7;border-radius:18px;box-shadow:0 28px 90px rgba(15,23,42,.32);z-index:101;display:flex;flex-direction:column;overflow:hidden;opacity:0;transform:translateY(12px) scale(.985);transition:opacity .16s ease,transform .16s ease}.edit-modal.open{opacity:1;transform:none}.edit-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid #edf1f7;background:#f8fafc}.edit-head h2{margin:0;font-size:19px}.edit-head span{display:block;margin-top:3px;color:#667085;font-size:13px;font-weight:800}.edit-close{border:0;background:#111827;color:#fff;border-radius:999px;width:36px;height:36px;font-size:22px;line-height:1;cursor:pointer}.edit-frame{width:100%;height:100%;border:0;flex:1;background:#fff}@media(max-width:1100px){.quick-check,.grid{grid-template-columns:1fr}.stats,.signal-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:720px){.ieum-main{padding:18px 14px}.quick-input form,.stats,.signal-grid,.detail-grid{grid-template-columns:1fr}.bar h1{font-size:28px}}
.attendance-page-tune .bar{margin-bottom:18px}
.attendance-page-tune .bar h1{font-size:30px;letter-spacing:0}
.attendance-page-tune .panel{border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.045)}
.attendance-page-tune .lesson-status{display:none}
.attendance-page-tune .closed-lesson-note{border:1px solid #f3cf8c;background:#fff8eb;color:#7a4a00;border-radius:12px;padding:12px 14px;font-weight:900;line-height:1.45}
.attendance-page-tune .quick-input input:disabled,.attendance-page-tune .quick-input button:disabled{opacity:.55;cursor:not-allowed}
.attendance-page-tune .quick-check{grid-template-columns:minmax(420px,.9fr) 1.1fr;gap:14px}
.attendance-page-tune .quick-input h2{margin:0;font-size:21px}
.attendance-page-tune .quick-input{padding:18px}
.attendance-page-tune .quick-input input{height:46px;font-size:21px}
.attendance-page-tune .quick-input .btn{min-height:46px}
.attendance-page-tune .quick-input p{margin:8px 0 0}
.attendance-page-tune .stats{grid-template-columns:repeat(4,minmax(0,1fr))}
.attendance-page-tune .stat-card{border-radius:16px;min-height:86px;padding:16px}
.attendance-page-tune .stat-card strong{font-size:28px}
.attendance-page-tune .operation-signals{display:none}
.attendance-page-tune .filters{padding:12px 14px;border-radius:18px}
.attendance-page-tune .class-strip{grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}
.attendance-page-tune .class-chip{border-radius:16px;padding:13px;background:#fbfcff}
.attendance-page-tune .grid{grid-template-columns:minmax(0,1.15fr) minmax(390px,.85fr)}
.attendance-page-tune .table-wrap{border:1px solid #dfe6ef;border-radius:14px;background:#fff}
.attendance-page-tune table{min-width:860px}
.attendance-page-tune th{height:44px;background:#667893}
.attendance-page-tune td{height:52px;background:#fff}
.attendance-page-tune tr:hover td{background:#f8fbff}
.attendance-page-tune .missing-list{max-height:640px}
.attendance-page-tune .missing-item{border-radius:16px;background:#fbfcff}
.attendance-page-tune .detail-modal{width:min(900px,calc(100vw - 48px))}
.attendance-page-tune .detail-head{position:sticky;top:0;z-index:2;align-items:center}
.attendance-page-tune .detail-head-tools{display:flex;align-items:center;gap:8px}
.attendance-page-tune .detail-head-tools .btn{min-height:36px;padding:7px 12px}
.attendance-page-tune .edit-modal{inset:32px auto auto 50%;width:min(1040px,calc(100vw - 120px));height:calc(100vh - 64px);transform:translate(-50%,12px) scale(.985)}
.attendance-page-tune .edit-modal.open{transform:translate(-50%,0) scale(1)}
@media(max-width:1320px){
    .attendance-page-tune .quick-check,.attendance-page-tune .grid{grid-template-columns:1fr}
}
.attendance-page-tune{background:#f6f8fb;color:#111827}
.attendance-page-tune .ieum-main{max-width:1900px}
.attendance-page-tune .bar{align-items:center;margin-bottom:18px}
.attendance-page-tune .bar h1{font-size:30px;font-weight:1000}
.attendance-page-tune .bar .muted{margin-top:6px}
.attendance-page-tune .panel{border-color:#dfe5ee;border-radius:18px;box-shadow:0 10px 24px rgba(15,23,42,.04)}
.attendance-page-tune .quick-check{grid-template-columns:minmax(460px,.82fr) minmax(520px,1fr);gap:12px;align-items:stretch}
.attendance-page-tune .quick-input{padding:18px;background:#fff}
.attendance-page-tune .quick-input h2{font-size:20px;margin:0}
.attendance-page-tune .quick-input form{margin-top:8px}
.attendance-page-tune .quick-input input{height:48px;border:1px solid #cfd6df;border-radius:12px;background:#f8fafc;font-size:18px;font-weight:900;text-align:left}
.attendance-page-tune .quick-input input:focus{outline:2px solid rgba(23,105,194,.14);border-color:#8fb4e5;background:#fff}
.attendance-page-tune .quick-input .btn{min-width:70px;height:48px;border-radius:12px}
.attendance-page-tune .quick-input span{display:block;margin-top:8px;font-size:13px}
.attendance-page-tune .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.attendance-page-tune .stat-card{min-height:auto;border-radius:18px;padding:16px 16px 14px;background:#fff;box-shadow:none}
.attendance-page-tune .stat-card span{color:#475569;font-weight:900;font-size:13px}
.attendance-page-tune .stat-card strong{font-size:29px;line-height:1.05;margin-top:8px}
.attendance-page-tune .stat-card.warn{background:#fffaf2;border-color:#fed7aa}
.attendance-page-tune .stat-card.danger{background:#fff7f7;border-color:#fecaca}
.attendance-page-tune .filters{background:#fff;border:1px solid #dfe5ee;box-shadow:none;margin-bottom:14px}
.attendance-page-tune .filters input,.attendance-page-tune .filters select{height:42px;border-radius:10px;background:#fff}
.attendance-page-tune #classAttendance{padding:18px;margin-bottom:14px!important}
.attendance-page-tune .section-head{margin-bottom:14px}
.attendance-page-tune .section-head h2{font-size:23px}
.attendance-page-tune .section-head p{font-size:14px}
.attendance-page-tune .section-head>.muted{white-space:nowrap;font-weight:900;line-height:1.2;text-align:right}
.attendance-page-tune .class-strip{grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
.attendance-page-tune .class-chip{border-radius:16px;padding:13px 14px;background:#fff;box-shadow:none}
.attendance-page-tune .class-chip.active{background:#f0f7ff;border-color:#1d70c9}
.attendance-page-tune .class-head{font-size:16px}
.attendance-page-tune .class-meta{font-size:12px}
.attendance-page-tune .class-bar{height:6px;background:#eef2f7}
.attendance-page-tune .grid{grid-template-columns:minmax(0,1fr) minmax(440px,.72fr);gap:14px}
.attendance-page-tune .table-wrap{border-radius:14px;border-color:#e2e8f0}
.attendance-page-tune table{min-width:760px}
.attendance-page-tune th{height:40px;background:#f3f6fb;color:#334155;border-color:#e2e8f0;font-size:13px}
.attendance-page-tune td{height:48px;border-color:#e5ebf3;font-size:14px}
.attendance-page-tune .missing-tools input{height:40px;border-radius:12px;background:#fff}
.attendance-page-tune .missing-class-tabs button{padding:8px 11px;background:#fff;border-radius:999px}
.attendance-page-tune .missing-list{max-height:600px;gap:8px}
.attendance-page-tune .missing-item{border-radius:16px;background:#fff;padding:13px;border-color:#e2e8f0}
.attendance-page-tune .missing-name{font-size:15px}
.attendance-page-tune .missing-meta{font-size:13px}
.attendance-page-tune .missing-actions .btn{min-height:36px;padding:7px 11px;border-radius:10px}
@media(max-width:1500px){
    .attendance-page-tune .class-strip{grid-template-columns:repeat(3,minmax(0,1fr))}
    .attendance-page-tune .quick-check{grid-template-columns:1fr}
}
@media(max-width:900px){
    .attendance-page-tune .stats,.attendance-page-tune .class-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
    .attendance-page-tune .grid{grid-template-columns:1fr}
}
/* Easy mode cleanup: daily attendance should read like one calm work desk. */
.attendance-page-tune{
    background:#f5f7fb;
}
.attendance-page-tune .ieum-main{
    max-width:1760px;
}
.attendance-page-tune .bar{
    padding:0 2px;
}
.attendance-page-tune .panel{
    border-color:#dfe5ee;
    border-radius:18px;
    box-shadow:0 10px 24px rgba(15,23,42,.04);
}
.attendance-page-tune .quick-check{
    grid-template-columns:minmax(420px,.72fr) minmax(640px,1fr);
}
.attendance-page-tune .quick-input{
    border-radius:18px;
}
.attendance-page-tune .stats{
    gap:10px;
}
.attendance-page-tune .stat-card{
    border-color:#dfe5ee;
    background:#fff;
}
.attendance-page-tune .stat-card.warn,
.attendance-page-tune .stat-card.danger{
    background:#fff;
}
.attendance-page-tune .stat-card.warn{
    border-left:4px solid #f59e0b;
}
.attendance-page-tune .stat-card.danger{
    border-left:4px solid #ef4444;
}
.attendance-page-tune .filters{
    padding:12px;
    border-radius:18px;
    background:#fff;
}
.attendance-page-tune #classAttendance{
    background:#fff;
}
.attendance-page-tune .class-strip{
    grid-template-columns:repeat(6,minmax(0,1fr));
}
.attendance-page-tune .class-chip{
    min-height:82px;
    display:grid;
    align-content:center;
}
.attendance-page-tune .grid{
    grid-template-columns:minmax(0,1fr) minmax(420px,.68fr);
}
.attendance-page-tune .table-wrap{
    overflow-x:auto;
}
.attendance-page-tune .table-wrap table{
    min-width:820px;
}
.attendance-page-tune .missing-list{
    max-height:560px;
}
.attendance-page-tune .missing-item{
    box-shadow:none;
}
@media(max-width:1500px){
    .attendance-page-tune .quick-check{
        grid-template-columns:1fr;
    }
    .attendance-page-tune .class-strip{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}
@media(max-width:980px){
    .attendance-page-tune .grid{
        grid-template-columns:1fr;
    }
}
/* Final easy-mode pass: keep attendance as a calm daily work board. */
.attendance-page-tune .ieum-main{
    max-width:1760px!important;
    padding-top:82px!important;
}
.attendance-page-tune .panel{
    border-color:#dfe5ee!important;
    border-radius:18px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
.attendance-page-tune .filters{
    box-shadow:none!important;
    padding:12px!important;
}
.attendance-page-tune #classAttendance{
    box-shadow:none!important;
}
.attendance-page-tune .class-strip{
    grid-template-columns:repeat(6,minmax(0,1fr))!important;
}
.attendance-page-tune .class-chip{
    box-shadow:none!important;
    background:#fff!important;
}
.attendance-page-tune .class-chip.active{
    background:#f0f7ff!important;
}
.attendance-page-tune .grid{
    grid-template-columns:minmax(0,1fr) minmax(430px,.62fr)!important;
    gap:14px!important;
}
.attendance-page-tune .table-wrap{
    box-shadow:none!important;
    border-color:#e2e8f0!important;
}
.attendance-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
.attendance-page-tune td{
    border-color:#e5ebf3!important;
}
.attendance-page-tune .missing-list{
    max-height:540px!important;
}
.attendance-page-tune .missing-item{
    box-shadow:none!important;
    background:#fff!important;
}
@media(max-width:1500px){
    .attendance-page-tune .grid{
        grid-template-columns:1fr!important;
    }
    .attendance-page-tune .class-strip{
        grid-template-columns:repeat(3,minmax(0,1fr))!important;
    }
}
/* 2026-05-30 calm daily pass: today attendance should feel like a simple work queue. */
.attendance-page-tune .ieum-main{
    max-width:1900px!important;
}
.attendance-page-tune .bar{
    margin-bottom:14px!important;
}
.attendance-page-tune .bar .filters{
    padding:0!important;
    margin:0!important;
    border:0!important;
    background:transparent!important;
}
.attendance-page-tune .quick-check{
    grid-template-columns:minmax(390px,.58fr) minmax(620px,1fr)!important;
    margin-bottom:18px!important;
}
.attendance-page-tune .quick-input{
    border:1px solid #dfe5ee!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
.attendance-page-tune .quick-input h2{
    font-size:22px!important;
}
.attendance-page-tune .stats{
    align-content:stretch!important;
}
.attendance-page-tune .stat-card{
    display:grid!important;
    align-content:center!important;
    min-height:106px!important;
    padding:16px 18px!important;
}
.attendance-page-tune .stat-card span{
    font-size:14px!important;
}
.attendance-page-tune .stat-card strong{
    font-size:34px!important;
}
.attendance-page-tune .stat-card.warn,
.attendance-page-tune .stat-card.danger{
    border-left-width:6px!important;
}
.attendance-page-tune #classAttendance{
    padding:0!important;
    margin:20px 0 18px!important;
    border:0!important;
    background:transparent!important;
}
.attendance-page-tune #classAttendance .section-head{
    margin:0 0 10px!important;
    padding:0 2px!important;
}
.attendance-page-tune #classAttendance .section-head h2{
    font-size:22px!important;
}
.attendance-page-tune .class-chip{
    min-height:92px!important;
    border-radius:18px!important;
    border-color:#dfe5ee!important;
    background:#fff!important;
    box-shadow:0 8px 18px rgba(15,23,42,.035)!important;
}
.attendance-page-tune .class-chip.active{
    border-color:#1d70c9!important;
    box-shadow:0 0 0 3px rgba(29,112,201,.12)!important;
}
.attendance-page-tune .grid{
    margin-top:4px!important;
}
.attendance-page-tune .grid>.panel{
    border-radius:20px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
.attendance-page-tune .missing-tools{
    padding:10px 12px!important;
    border:1px solid #e2e8f0!important;
    border-radius:14px!important;
    background:#f8fafc!important;
}
.attendance-page-tune .missing-class-tabs{
    margin:12px 0!important;
}
.attendance-page-tune .missing-list{
    max-height:620px!important;
}
.attendance-page-tune .missing-item{
    padding:14px!important;
    border-radius:18px!important;
}
.attendance-page-tune .missing-name span:first-child{
    font-size:16px!important;
}
.attendance-page-tune .missing-actions{
    gap:8px!important;
}
.attendance-page-tune .missing-actions .btn{
    border-radius:12px!important;
}
@media(max-width:1500px){
    .attendance-page-tune .quick-check{
        grid-template-columns:1fr!important;
    }
}
/* 2026-05-31 easy-mode pass: 오늘 출석은 입력, 미등원, 최근 등원만 선명하게 */
.attendance-page-tune .bar{
    margin-bottom:12px!important;
}
.attendance-page-tune .bar .muted{
    font-size:14px!important;
}
.attendance-page-tune .quick-check{
    grid-template-columns:minmax(360px,.55fr) minmax(560px,1fr)!important;
    margin-bottom:12px!important;
}
.attendance-page-tune .quick-input{
    padding:16px!important;
}
.attendance-page-tune .stats{
    gap:8px!important;
}
.attendance-page-tune .stat-card{
    min-height:80px!important;
    padding:13px 14px!important;
}
.attendance-page-tune .stat-card span{
    font-size:12px!important;
}
.attendance-page-tune .stat-card strong{
    font-size:27px!important;
}
.attendance-page-tune .filters{
    margin:0 0 12px!important;
    padding:10px 12px!important;
}
.attendance-page-tune #classAttendance{
    margin:0 0 14px!important;
    padding:14px!important;
}
.attendance-page-tune #classAttendance .section-head p{
    display:none!important;
}
.attendance-page-tune .class-strip{
    gap:8px!important;
}
.attendance-page-tune .class-chip{
    min-height:68px!important;
    padding:11px 12px!important;
}
.attendance-page-tune .class-head{
    font-size:15px!important;
}
.attendance-page-tune .grid{
    grid-template-columns:minmax(0,1fr) minmax(420px,.68fr)!important;
    gap:12px!important;
}
.attendance-page-tune .grid>.panel{
    padding:16px!important;
}
.attendance-page-tune .missing-list{
    max-height:500px!important;
}
.attendance-page-tune .missing-item{
    padding:11px 12px!important;
}
.attendance-page-tune .missing-actions .btn{
    min-height:34px!important;
    padding:6px 10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune #classAttendance{
    padding:16px!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune #classAttendance .section-head{
    margin-bottom:10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .class-strip{
    grid-template-columns:repeat(6,minmax(0,1fr))!important;
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .class-chip{
    min-height:68px!important;
    padding:10px!important;
    border-radius:12px!important;
    align-content:center!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .class-head{
    gap:4px!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .class-meta{
    display:grid!important;
    grid-template-columns:1fr!important;
    gap:2px!important;
    margin:6px 0!important;
    font-size:11px!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .class-bar{
    height:5px!important;
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .class-strip{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
    }
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .bar,
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .quick-check,
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune #classAttendance,
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .grid{
    max-width:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .quick-check{
    grid-template-columns:minmax(360px,.58fr) minmax(0,1fr)!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .stats{
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
}
@media(max-width:1500px){
    body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .quick-check{
        grid-template-columns:1fr!important;
    }
}
@media(max-width:1180px){
    body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .stats{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
    }
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark{
    --ieum-side-bg:#111827!important;
    background:#0f1724!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .side-sub,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .bar,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .panel,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .quick-input,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .stat-card,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .filters,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .table-wrap,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .missing-item,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .detail-modal,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .edit-modal{
    background:#151f2e!important;
    border-color:#2c3a4f!important;
    color:#e5edf7!important;
    box-shadow:none!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark th{
    background:#1b2535!important;
    color:#d9e2ef!important;
    border-color:#2c3a4f!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark td{
    background:#151f2e!important;
    color:#d9e2ef!important;
    border-color:#263244!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark input,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark select,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark textarea,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .btn{
    background:#111827!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .primary,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .btn.primary{
    background:#1f7dd9!important;
    border-color:#1f7dd9!important;
    color:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .filters .btn,
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .missing-actions .btn,
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .vehicle-note .btn{
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .stat-card,
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .class-chip,
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .missing-item,
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .vehicle-note{
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .student-link-button{
    color:#1769c2!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .closed-lesson-note{
    background:#2a2416!important;
    border-color:#6d5421!important;
    color:#ffd58a!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .stat-card.warn,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .signal.warn{
    background:#2a2416!important;
    border-color:#6d5421!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .stat-card.danger,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .signal.danger{
    background:#2b1820!important;
    border-color:#6f2d3b!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .class-chip.active{
    background:#10283d!important;
    border-color:#1c5b88!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .class-bar{
    background:#263244!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .class-fill{
    background:#1d7fe0!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .missing-tools,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .vehicle-note,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .empty,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .detail-head,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .detail-actions,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .edit-head,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .detail-field,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .detail-section{
    background:#111827!important;
    border-color:#2c3a4f!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark tr:hover td{
    background:#182335!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .btn.muted{
    background:#172132!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark input::placeholder{
    color:#9aa8bb!important;
    opacity:1!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .muted,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .missing-meta,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .vehicle-note p,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .detail-code,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .detail-field strong,
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .edit-head span{
    color:#9aa8bb!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .student-link-button{
    color:#8fd0ff!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .status-sent{
    color:#8ee0a8!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .status-failed{
    color:#ffb4c0!important;
}
body.ieum-dashboard-page.attendance-page-tune.ieum-dark .status-pending{
    color:#ffd58a!important;
}
@media(max-width:720px){
    body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .stats,
    body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .class-strip{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .quick-input form{
        grid-template-columns:1fr auto!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .missing-tools input{
        width:100%!important;
        min-width:0!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.attendance-page-tune .vehicle-note{
        align-items:flex-start!important;
        flex-direction:column!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page attendance-page-tune">
<?php echo ieum_admin_header('attendance', 'side'); ?>
<main class="ieum-main">
    <div class="bar">
        <div>
            <h1>오늘 출석</h1>
            <div class="muted"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($today); ?></div>
        </div>
        <div class="filters">
            <a href="<?php echo IEUM_URL; ?>/admin/students.php" class="btn">원생 관리</a>
            <a href="<?php echo IEUM_URL; ?>/admin/tablet_devices.php" class="btn primary">앱 출석기</a>
        </div>
    </div>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <section class="panel lesson-status">
        <div>
            <strong><?php echo get_text($today_lesson_label); ?></strong>
            <div class="muted"><?php echo get_text($today_lesson_desc); ?></div>
        </div>
        <div><strong><?php echo number_format($attendance_rate); ?>%</strong> <span class="muted">출석 흐름</span></div>
    </section>

    <section class="quick-check">
        <article class="panel quick-input">
            <h2>직접 출석 처리</h2>
            <?php if ($is_today_closed) { ?>
            <div class="closed-lesson-note"><?php echo get_text($today_lesson_label); ?>에는 출석 입력과 미등원 계산이 자동으로 멈춥니다. 실제 수업을 진행하는 날이면 수업일/휴관일에서 보충 수업으로 바꿔 주세요.</div>
            <?php } ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="text" name="student_code" id="student_code" placeholder="원생번호 입력" inputmode="numeric" pattern="[0-9]*" required <?php echo $is_today_closed ? 'disabled' : ''; ?>>
                <button type="submit" class="btn primary" <?php echo $is_today_closed ? 'disabled' : ''; ?>>등원</button>
            </form>
            <span class="muted">번호를 못 찍었거나 원생이 몰렸을 때 관리자가 바로 처리합니다.</span>
        </article>
        <article class="stats">
            <a class="stat-card" href="#attendanceRecords"><span>등원 완료</span><strong><?php echo number_format($attended); ?>명</strong></a>
            <a class="stat-card <?php echo $missing ? 'warn' : ''; ?>" href="#missingStudents"><span>미등원</span><strong><?php echo number_format($missing); ?>명</strong></a>
            <a class="stat-card" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php?status=pending"><span>문자 대기</span><strong><?php echo number_format((int) $pending['pending_count']); ?>건</strong></a>
            <a class="stat-card <?php echo (int) $failed_sms['failed_count'] ? 'danger' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php?status=failed"><span>문자 실패</span><strong><?php echo number_format((int) $failed_sms['failed_count']); ?>건</strong></a>
        </article>
    </section>

    <section class="panel operation-signals">
        <div class="section-head">
            <div>
                <h2>오늘 확인 신호</h2>
                <p>출석 화면에서 바로 챙겨야 할 연락, 수련비, 차량, 생일 신호만 모았습니다.</p>
            </div>
        </div>
        <div class="signal-grid">
            <a class="signal <?php echo $missing ? 'warn' : ''; ?>" href="#missingStudents"><em><?php echo $missing ? '확인 필요' : '정상'; ?></em><strong>미등원 확인</strong><b><?php echo number_format($missing); ?>명</b></a>
            <a class="signal <?php echo (int) $tuition_signal['overdue_count'] ? 'danger' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php"><em>수련비</em><strong>미납 초과</strong><b><?php echo number_format((int) $tuition_signal['overdue_count']); ?>명</b></a>
            <a class="signal" href="<?php echo IEUM_URL; ?>/dashboard.php#birthdays"><em>관계 관리</em><strong>7일 내 생일</strong><b><?php echo number_format((int) $birthday_upcoming_count['cnt']); ?>명</b></a>
            <a class="signal <?php echo (int) $vehicle_note_count['cnt'] ? 'warn' : ''; ?>" href="#vehicleNotes"><em>차량</em><strong>차량 메모</strong><b><?php echo number_format((int) $vehicle_note_count['cnt']); ?>건</b></a>
            <a class="signal <?php echo (int) $long_absent_count['cnt'] ? 'warn' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/students.php?auto_check=long_absent"><em>상담</em><strong>장기 미등원</strong><b><?php echo number_format((int) $long_absent_count['cnt']); ?>명</b></a>
        </div>
    </section>

    <form class="panel filters" method="get">
        <input type="date" name="date" value="<?php echo get_text($today); ?>">
        <select name="program_code">
            <option value="">전체 프로그램</option>
            <?php foreach ($program_options as $program) { ?>
            <option value="<?php echo get_text($program['program_code']); ?>" <?php echo $program_code === $program['program_code'] ? 'selected' : ''; ?>><?php echo get_text($program['program_name']); ?></option>
            <?php } ?>
        </select>
        <select name="class_time_id">
            <option value="0">전체 부</option>
            <?php foreach ($class_options as $class) { ?>
            <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo $class_time_id === (int) $class['class_time_id'] ? 'selected' : ''; ?>><?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn primary">조회</button>
        <a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">초기화</a>
    </form>

    <section class="panel" id="classAttendance" style="margin-bottom:16px">
        <div class="section-head">
            <div>
                <h2>부별 출석 흐름</h2>
                <p>부별 등원 인원과 미등원 인원을 가볍게 확인합니다.</p>
            </div>
        </div>
        <div class="class-strip">
            <?php $ci = 0; while ($class = sql_fetch_array($class_rows)) { $ci++; $expected = (int) $class['expected_count']; $done = (int) $class['attended_count']; $rate = $expected ? round(($done / $expected) * 100) : 0; ?>
            <a class="class-chip <?php echo $class_time_id === (int) $class['class_time_id'] ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php?date=<?php echo get_text($today); ?>&program_code=<?php echo get_text($program_code); ?>&class_time_id=<?php echo (int) $class['class_time_id']; ?>">
                <div class="class-head"><span><?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?></span><span><?php echo number_format($rate); ?>%</span></div>
                <div class="class-meta"><span>등원 <?php echo number_format($done); ?>명</span><span>미등원 <?php echo number_format(max(0, $expected - $done)); ?>명</span></div>
                <div class="class-bar"><div class="class-fill" style="width:<?php echo min(100, $rate); ?>%"></div></div>
            </a>
            <?php } ?>
            <?php if ($ci === 0) { ?><div class="empty">등록된 수업 부가 없습니다.</div><?php } ?>
        </div>
    </section>

    <?php if ((int) $vehicle_note_count['cnt'] > 0) { ?>
    <section class="panel vehicle-notes" id="vehicleNotes">
        <div class="section-head"><h2>오늘 차량 메모</h2></div>
        <div class="vehicle-list">
            <?php while ($note = sql_fetch_array($vehicle_note_rows)) { ?>
            <div class="vehicle-note">
                <div>
                    <strong><?php echo get_text($note['student_name']); ?> · <?php echo get_text(ieum_today_boarding_status_label($note['status'])); ?></strong>
                    <p><?php echo get_text(($note['vehicle_label'] ?: '') . ' ' . ($note['route_name'] ?: '') . ' ' . ($note['stop_time'] ?: '') . ' ' . ($note['stop_name'] ?: '')); ?></p>
                    <?php if ($note['note']) { ?><p><?php echo get_text($note['note']); ?></p><?php } ?>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="resolve_vehicle_note">
                    <input type="hidden" name="log_id" value="<?php echo (int) $note['log_id']; ?>">
                    <button type="submit" class="btn">확인</button>
                </form>
            </div>
            <?php } ?>
        </div>
    </section>
    <?php } ?>

    <section class="grid">
        <article class="panel">
            <div class="section-head" id="attendanceRecords">
                <div>
                    <h2>최근 등원</h2>
                    <p>원생 이름을 누르면 수련비, 승급, 차량 정보를 바로 확인합니다.</p>
                </div>
                <span class="muted">오늘 <?php echo number_format($attended); ?>명</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>시간</th><th>원생</th><th>프로그램</th><th>수업 부</th><th>보호자</th><th>입력</th><th>문자</th></tr></thead>
                    <tbody>
                    <?php $i = 0; while ($row = sql_fetch_array($attendance_rows)) { $i++; $sms = isset($row['sms_status']) ? $row['sms_status'] : ''; $row['last_attendance_label'] = '오늘 ' . substr($row['checked_at'], 11, 5) . ' 등원'; $detail_json = htmlspecialchars(json_encode(ieum_today_student_detail_payload($academy_id, $row), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>
                    <tr>
                        <td><?php echo get_text(substr($row['checked_at'], 11, 5)); ?></td>
                        <td class="left"><button type="button" class="student-link-button student-detail-open" data-detail="<?php echo $detail_json; ?>"><?php echo get_text($row['student_name']); ?></button> <span class="muted"><?php echo get_text($row['student_code']); ?></span></td>
                        <td><?php echo get_text(ieum_program_label($academy_id, $row['program_code'])); ?></td>
                        <td><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '-'); ?></td>
                        <td class="left"><?php echo get_text($row['guardian_info'] ? ieum_today_phone_text($row['guardian_info']) : '-'); ?></td>
                        <td><?php echo get_text($row['input_source']); ?></td>
                        <td class="status-<?php echo get_text($sms); ?>"><?php echo get_text(isset($sms_status_labels[$sms]) ? $sms_status_labels[$sms] : $sms); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if ($i === 0) { ?><tr><td colspan="7">오늘 등원 기록이 없습니다.</td></tr><?php } ?>
                    </tbody>
                </table>
            </div>
        </article>

        <aside class="panel" id="missingStudents">
            <div class="section-head">
                <div>
                    <h2>미등원 원생</h2>
                    <p>오늘 출석 요일인데 아직 등원하지 않은 원생입니다.</p>
                </div>
            </div>
            <div class="missing-tools">
                <span><strong id="missingVisibleCount"><?php echo number_format($missing); ?>명</strong> 표시 중</span>
                <input type="search" id="missingSearch" placeholder="이름, 원생번호, 보호자 검색" autocomplete="off">
            </div>
            <div class="missing-class-tabs" id="missingClassTabs" aria-label="미등원 부별 필터"></div>
            <div class="missing-list">
                <?php $m = 0; while ($row = sql_fetch_array($missing_rows)) { $m++; $missing_class_label = $row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '부 미지정'; $row['last_attendance_label'] = '오늘 미등원'; $missing_detail_json = htmlspecialchars(json_encode(ieum_today_student_detail_payload($academy_id, $row), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>
                <div class="missing-item" data-class-id="<?php echo (int) $row['class_time_id']; ?>" data-class-label="<?php echo get_text($missing_class_label); ?>">
                    <div class="missing-name"><span><?php echo get_text($row['student_name']); ?></span><span><?php echo get_text($row['student_code']); ?></span></div>
                    <div class="missing-meta">
                        <?php echo get_text(ieum_program_label($academy_id, $row['program_code'])); ?> ·
                        <?php echo get_text(ieum_today_grade_label($row['grade_group'])); ?> ·
                        <?php echo get_text($missing_class_label); ?><br>
                        <?php echo get_text($row['guardian_info'] ? ieum_today_phone_text($row['guardian_info']) : '보호자 연락처 없음'); ?>
                    </div>
                    <div class="missing-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="student_code" value="<?php echo get_text($row['student_code']); ?>">
                            <button type="submit" class="btn primary">등원 처리</button>
                        </form>
                        <button type="button" class="btn student-detail-open" data-detail="<?php echo $missing_detail_json; ?>">원생 정보</button>
                        <?php if ($row['student_phone']) { ?><a class="btn" href="tel:<?php echo get_text(preg_replace('/[^0-9]/', '', $row['student_phone'])); ?>">원생 <?php echo get_text(ieum_today_phone($row['student_phone'])); ?></a><?php } ?>
                        <?php if (!empty($row['guardian_phone'])) { ?><a class="btn" href="tel:<?php echo get_text(preg_replace('/[^0-9]/', '', $row['guardian_phone'])); ?>">보호자 <?php echo get_text(ieum_today_phone($row['guardian_phone'])); ?></a><?php } ?>
                    </div>
                </div>
                <?php } ?>
                <?php if ($m === 0) { ?><div class="empty">현재 조건의 미등원 원생이 없습니다.</div><?php } ?>
            </div>
        </aside>
    </section>
</main>

<?php
$attendance_shortcut_js_catalog = array();
foreach ($attendance_shortcut_catalog as $shortcut_key => $shortcut_item) {
    $attendance_shortcut_js_catalog[$shortcut_key] = array(
        'label' => isset($shortcut_item['label']) ? $shortcut_item['label'] : $shortcut_key,
        'desc' => isset($shortcut_item['desc']) ? $shortcut_item['desc'] : '',
        'url' => isset($shortcut_item['url']) ? $shortcut_item['url'] : '#',
    );
}
?>
<script type="text/plain" data-deprecated-shell-sync="common-ui-owned">
(function() {
    var academyName = <?php echo json_encode(isset($academy['academy_name']) ? $academy['academy_name'] : '아이이음', JSON_UNESCAPED_UNICODE); ?>;
    var rootSelector = '.attendance-page-tune.ieum-dashboard-page';
    var shortcutCatalog = <?php echo json_encode($attendance_shortcut_js_catalog, JSON_UNESCAPED_UNICODE); ?>;
    var currentShortcutKeys = <?php echo json_encode(array_values($attendance_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var defaultShortcutKeys = <?php echo json_encode(array_values($attendance_default_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var shortcutMax = 6;
    var shortcutSaveUrl = <?php echo json_encode(IEUM_URL . '/dashboard.php', JSON_UNESCAPED_UNICODE); ?>;
    var shortcutCsrfToken = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) {
        brandText.textContent = academyName;
    }
    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) {
        homeLink.textContent = '아이이음 교육페이지';
    }
    var meta = document.querySelector(rootSelector + ' .ieum-shell-meta');
    if (!meta) {
        return;
    }
    meta.textContent = '';
    var supportBaseUrl = <?php echo json_encode(IEUM_URL . '/admin/support.php', JSON_UNESCAPED_UNICODE); ?>;
    var metaInner = document.createElement('span');
    metaInner.className = 'dashboard-shell-meta-inner';
    var makeSupportLink = function(text, topic) {
        var link = document.createElement('a');
        link.className = 'dashboard-shell-support-link';
        link.href = supportBaseUrl + '?topic=' + encodeURIComponent(topic);
        link.textContent = text;
        return link;
    };
    var makeDivider = function() {
        var divider = document.createElement('span');
        divider.className = 'dashboard-shell-divider';
        divider.textContent = '|';
        return divider;
    };
    var helpGroup = document.createElement('span');
    helpGroup.className = 'dashboard-shell-help-group';
    [
        ['Q&A', 'qna'],
        ['자주하는 질문', 'faq'],
        ['문의하기', 'contact'],
        ['AI 챗봇', 'ai']
    ].forEach(function(item, index) {
        if (index > 0) {
            var dot = document.createElement('span');
            dot.className = 'dashboard-shell-help-dot';
            dot.textContent = '·';
            helpGroup.appendChild(dot);
        }
        helpGroup.appendChild(makeSupportLink(item[0], item[1]));
    });
    var academyText = document.createElement('span');
    academyText.textContent = academyName;
    var clockText = document.createElement('span');
    clockText.className = 'dashboard-shell-clock';
    var renderClock = function() {
        var now = new Date();
        clockText.textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    };
    metaInner.appendChild(makeSupportLink('개발지원센터', 'qna'));
    metaInner.appendChild(makeDivider());
    metaInner.appendChild(helpGroup);
    metaInner.appendChild(makeDivider());
    metaInner.appendChild(academyText);
    metaInner.appendChild(makeDivider());
    metaInner.appendChild(clockText);
    meta.appendChild(metaInner);
    renderClock();
    window.setInterval(renderClock, 30000);
})();
</script>
<script>
(function() {
    var rootSelector = '.attendance-page-tune.ieum-dashboard-page';
    var shortcutCatalog = <?php echo json_encode($attendance_shortcut_js_catalog, JSON_UNESCAPED_UNICODE); ?>;
    var currentShortcutKeys = <?php echo json_encode(array_values($attendance_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var defaultShortcutKeys = <?php echo json_encode(array_values($attendance_default_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var shortcutMax = 6;
    var shortcutSaveUrl = <?php echo json_encode(IEUM_URL . '/dashboard.php', JSON_UNESCAPED_UNICODE); ?>;
    var shortcutCsrfToken = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
    var metaInner = document.querySelector(rootSelector + ' .dashboard-shell-meta-inner');
    /*
     * The shared top theme toggle is owned by lib/ui.php.
     * Keep this old page-local block inert so favorite-star logic below still runs cleanly.
     *
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
        button.setAttribute('aria-label', isDark ? '\ub77c\uc774\ud2b8 \ubaa8\ub4dc\ub85c \ubcc0\uacbd' : '\ub2e4\ud06c \ubaa8\ub4dc\ub85c \ubcc0\uacbd');
        button.setAttribute('title', isDark ? '\ub77c\uc774\ud2b8 \ubaa8\ub4dc\ub85c \ubcc0\uacbd' : '\ub2e4\ud06c \ubaa8\ub4dc\ub85c \ubcc0\uacbd');
        button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + (isDark ? themeIconPaths.light : themeIconPaths.dark) + '</svg>';
    };
    if (metaInner && !metaInner.querySelector('.dashboard-theme-toggle')) {
        var aiHelpLink = metaInner.querySelector('a[href*="topic=ai"]');
        if (aiHelpLink) {
            aiHelpLink.textContent = 'AI \ucc57\ubd07';
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
    */
    var shortcutButtons = [];
    var shortcutToast = document.createElement('span');
    var shortcutToastTimer = null;
    var shortcutToastDefault = '<span>\ucd5c\ub300 6\uac1c\uae4c\uc9c0 \uc120\ud0dd\ud560 \uc218 \uc788\uc2b5\ub2c8\ub2e4.</span><span>\ubcf4\uc870\uc815\ubcf4- \uc5c5\ubb34\ubc14\ub85c\uac00\uae30\uc5d0\uc11c \ud655\uc778\ud558\uc138\uc694.</span>';
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
            button.textContent = active ? '\u2605' : '\u2606';
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.setAttribute('title', active ? '\uc5c5\ubb34 \ubc14\ub85c\uac00\uae30\uc5d0\uc11c \uc81c\uac70' : '\uc5c5\ubb34 \ubc14\ub85c\uac00\uae30\uc5d0 \ucd94\uac00');
            button.setAttribute('aria-label', (item.label || '\uba54\ub274') + (active ? ' \uc990\uaca8\ucc3e\uae30 \uc81c\uac70' : ' \uc990\uaca8\ucc3e\uae30 \ucd94\uac00'));
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
<div class="detail-backdrop" id="studentDetailBackdrop" hidden></div>
<section class="detail-modal" id="studentDetailModal" aria-hidden="true" aria-labelledby="studentDetailTitle" role="dialog" hidden>
    <div class="detail-head">
        <div>
            <h2 id="studentDetailTitle">원생 상세</h2>
            <div class="detail-code" id="studentDetailCode">-</div>
        </div>
        <div class="detail-head-tools">
            <a class="btn primary student-edit-modal-open student-detail-edit-action" id="studentDetailEditTop" href="#" onclick="return window.ieumOpenStudentEditFromLink ? window.ieumOpenStudentEditFromLink(this) : true;">수정하기</a>
            <button type="button" class="detail-close" id="studentDetailClose" aria-label="닫기">×</button>
        </div>
    </div>
    <div class="detail-body">
        <div class="detail-grid">
            <div class="detail-field"><strong>상태</strong><span id="detailStatus">-</span></div>
            <div class="detail-field"><strong>프로그램</strong><span id="detailProgram">-</span></div>
            <div class="detail-field"><strong>학년/부</strong><span id="detailGrade">-</span></div>
            <div class="detail-field"><strong>수업 부</strong><span id="detailClassTime">-</span></div>
            <div class="detail-field"><strong>출석 요일</strong><span id="detailAttendanceDays">-</span></div>
            <div class="detail-field"><strong>최근 출석</strong><span id="detailLastAttendance">-</span></div>
            <div class="detail-field"><strong>원생 연락처</strong><span id="detailStudentPhone">-</span></div>
            <div class="detail-field"><strong>수련비</strong><span id="detailTuition">-</span></div>
            <div class="detail-field"><strong>입관일</strong><span id="detailAdmissionDate">-</span></div>
        </div>
        <div class="detail-section">
            <h3>승급 정보</h3>
            <div class="detail-grid">
                <div class="detail-field"><strong>현재</strong><span id="detailPromotionCurrent">-</span></div>
                <div class="detail-field"><strong>다음</strong><span id="detailPromotionNext">-</span></div>
                <div class="detail-field"><strong>예정일</strong><span id="detailPromotionNextDate">-</span></div>
            </div>
        </div>
        <div class="detail-section"><h3>보호자</h3><p id="detailGuardian" class="detail-empty">등록 없음</p></div>
        <div class="detail-section"><h3>차량</h3><p id="detailVehicle" class="detail-empty">이용 없음</p></div>
        <div class="detail-section"><h3>메모</h3><p id="detailMemo" class="detail-empty">메모 없음</p></div>
        <div class="detail-section"><h3>상담 메모</h3><p id="detailCounselingNote" class="detail-empty">상담 메모 없음</p></div>
    </div>
    <div class="detail-actions">
        <a class="btn primary student-edit-modal-open student-detail-edit-action" id="studentDetailEdit" href="#" onclick="return window.ieumOpenStudentEditFromLink ? window.ieumOpenStudentEditFromLink(this) : true;">수정하기</a>
        <button type="button" class="btn muted" id="studentDetailCloseBottom">닫기</button>
    </div>
</section>
<div class="edit-backdrop" id="studentEditBackdrop" hidden></div>
<section class="edit-modal" id="studentEditModal" aria-hidden="true" aria-labelledby="studentEditTitle" role="dialog" hidden>
    <div class="edit-head">
        <div>
            <h2 id="studentEditTitle">원생 정보 수정</h2>
            <span>저장 후 창을 닫으면 오늘 출석 화면으로 돌아옵니다.</span>
        </div>
        <button type="button" class="edit-close" id="studentEditClose" aria-label="닫기">×</button>
    </div>
    <iframe class="edit-frame" id="studentEditFrame" title="원생 정보 수정"></iframe>
</section>
<script src="<?php echo IEUM_URL; ?>/assets/students-list-modal.js?v=2026060203"></script>
<script>
(function () {
    var quickInput = document.getElementById('student_code');
    if (quickInput) {
        quickInput.addEventListener('input', function () {
            var next = quickInput.value.replace(/[^0-9]/g, '');
            if (quickInput.value !== next) quickInput.value = next;
        });
        if (!window.location.hash) window.setTimeout(function () { quickInput.focus(); }, 120);
    }
})();
(function () {
    var limit = 12;
    var root = document.getElementById('missingStudents');
    if (!root) return;
    var list = root.querySelector('.missing-list');
    if (!list) return;
    var items = Array.prototype.slice.call(list.querySelectorAll('.missing-item'));
    var tabs = document.getElementById('missingClassTabs');
    var countLabel = document.getElementById('missingVisibleCount');
    var searchInput = document.getElementById('missingSearch');
    var currentClass = 'all';
    var expanded = false;
    var classMap = {};
    var classOrder = [];
    items.forEach(function (item) {
        item.setAttribute('data-search-text', item.textContent.replace(/\s+/g, ' ').toLowerCase());
        var classId = item.getAttribute('data-class-id') || '0';
        var classLabel = item.getAttribute('data-class-label') || '부 미지정';
        if (!classMap[classId]) {
            classMap[classId] = { label: classLabel, count: 0 };
            classOrder.push(classId);
        }
        classMap[classId].count += 1;
    });
    var row = document.createElement('div');
    row.className = 'missing-toggle-row';
    row.style.display = 'flex';
    row.style.justifyContent = 'center';
    row.style.marginTop = '10px';
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn';
    row.appendChild(button);
    list.parentNode.insertBefore(row, list.nextSibling);
    function makeTab(label, count, classId) {
        var tab = document.createElement('button');
        tab.type = 'button';
        tab.textContent = label + ' ' + count.toLocaleString() + '명';
        tab.setAttribute('data-class-id', classId);
        tab.addEventListener('click', function () {
            currentClass = classId;
            expanded = false;
            applyMissingView();
        });
        return tab;
    }
    if (tabs && classOrder.length > 1) {
        tabs.appendChild(makeTab('전체', items.length, 'all'));
        classOrder.forEach(function (classId) {
            tabs.appendChild(makeTab(classMap[classId].label, classMap[classId].count, classId));
        });
    }
    function applyMissingView() {
        var matched = [];
        var query = searchInput ? searchInput.value.replace(/\s+/g, ' ').trim().toLowerCase() : '';
        items.forEach(function (item) {
            var classMatched = currentClass === 'all' || item.getAttribute('data-class-id') === currentClass;
            var searchMatched = !query || (item.getAttribute('data-search-text') || '').indexOf(query) !== -1;
            var isMatched = classMatched && searchMatched;
            item.classList.toggle('is-filtered', !isMatched);
            item.classList.remove('is-collapsed');
            if (isMatched) matched.push(item);
        });
        matched.forEach(function (item, index) {
            item.classList.toggle('is-collapsed', !expanded && index >= limit);
        });
        if (countLabel) countLabel.textContent = matched.length.toLocaleString() + '명';
        if (tabs) {
            Array.prototype.forEach.call(tabs.querySelectorAll('button'), function (tab) {
                tab.classList.toggle('active', tab.getAttribute('data-class-id') === currentClass);
            });
        }
        var hiddenCount = Math.max(0, matched.length - limit);
        row.style.display = hiddenCount > 0 ? 'flex' : 'none';
        button.textContent = expanded ? '접기' : '나머지 ' + hiddenCount.toLocaleString() + '명 더 보기';
    }
    button.addEventListener('click', function () {
        expanded = !expanded;
        applyMissingView();
    });
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            expanded = false;
            applyMissingView();
        });
    }
    applyMissingView();
})();
</script>
</body>
</html>
