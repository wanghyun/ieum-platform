<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

$dashboard_h = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$dashboard_count = function ($row, $key = 'cnt') {
    return ($row && isset($row[$key])) ? (int) $row[$key] : 0;
};
$dashboard_money = function ($value) {
    return number_format((int) $value) . '원';
};
$dashboard_rows = function ($result) {
    $rows = array();
    if ($result) {
        while ($row = sql_fetch_array($result)) {
            $rows[] = $row;
        }
    }
    return $rows;
};

$report_month = date('Y-m', $now_ts);
$report_month_start = $report_month . '-01';
$report_month_end = date('Y-m-t', strtotime($report_month_start));
$elapsed_weeks = max(1, min(5, (int) ceil((int) date('j', $now_ts) / 7)));

$recent_rows = $dashboard_rows($recent);
$missing_rows = $dashboard_rows($missing_students);
$class_rows = $dashboard_rows($class_today);
$dashboard_today_closed = !empty($is_today_closed);
$dashboard_today_lesson_label = isset($today_lesson_label) ? (string) $today_lesson_label : '';
$dashboard_today_lesson_desc = isset($today_lesson_desc) ? (string) $today_lesson_desc : '';
if ($dashboard_today_closed) {
    $class_rows = array();
}
$quick_attendance_class_rows = array();
$quick_attendance_default_class_id = 0;
foreach ($class_rows as $quick_row) {
    $quick_expected = isset($quick_row['expected_count']) ? (int) $quick_row['expected_count'] : 0;
    $quick_attended = isset($quick_row['attended_count']) ? (int) $quick_row['attended_count'] : 0;
    $quick_missing = max(0, $quick_expected - $quick_attended);
    $quick_class_id = isset($quick_row['class_time_id']) ? (int) $quick_row['class_time_id'] : 0;
    $quick_attendance_class_rows[] = array(
        'class_time_id' => $quick_class_id,
        'class_name' => isset($quick_row['class_name']) ? $quick_row['class_name'] : '',
        'start_time' => isset($quick_row['start_time']) ? $quick_row['start_time'] : '',
        'expected_count' => $quick_expected,
        'attended_count' => $quick_attended,
        'missing_count' => $quick_missing,
    );
    if ($quick_attendance_default_class_id <= 0 && $quick_missing > 0) {
        $quick_attendance_default_class_id = $quick_class_id;
    }
}
if ($quick_attendance_default_class_id <= 0 && !empty($quick_attendance_class_rows[0]['class_time_id'])) {
    $quick_attendance_default_class_id = (int) $quick_attendance_class_rows[0]['class_time_id'];
}
$vehicle_note_rows = $dashboard_rows($vehicle_notes);
$long_absent_rows = $dashboard_rows($long_absent_students);
$birthday_rows = $dashboard_rows($birthday_students);

$student_care_count = $dashboard_count(sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and (
            memo <> ''
         or counseling_note <> ''
         or promotion_memo <> ''
         or tuition_note <> ''
         or exists (
             select 1
               from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
              where sv.academy_id = " . IEUM_STUDENT_TABLE . ".academy_id
                and sv.student_id = " . IEUM_STUDENT_TABLE . ".student_id
                and sv.is_active = 1
                and sv.memo <> ''
         )
       )
", false));

$calendar_month = isset($_GET['calendar_month']) ? trim((string) $_GET['calendar_month']) : $report_month;
if (!preg_match('/^\d{4}-\d{2}$/', $calendar_month) || strtotime($calendar_month . '-01') === false) {
    $calendar_month = $report_month;
}
$calendar_month_start = $calendar_month . '-01';
$calendar_month_end = date('Y-m-t', strtotime($calendar_month_start));
$calendar_month_compact_label = date('Y.m', strtotime($calendar_month_start));
$calendar_prev_month = date('Y-m', strtotime($calendar_month_start . ' -1 month'));
$calendar_next_month = date('Y-m', strtotime($calendar_month_start . ' +1 month'));
$dashboard_calendar_url = function ($month) {
    return IEUM_URL . '/dashboard.php?calendar_month=' . urlencode($month) . '#calendarSchedule';
};
$calendar_first_weekday = (int) date('w', strtotime($calendar_month_start));
$calendar_days_in_month = (int) date('t', strtotime($calendar_month_start));
$calendar_selected_date = ($today >= $calendar_month_start && $today <= $calendar_month_end) ? $today : $calendar_month_start;
$calendar_post_date = isset($_POST['memo_date']) ? trim((string) $_POST['memo_date']) : '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $calendar_post_date) && $calendar_post_date >= $calendar_month_start && $calendar_post_date <= $calendar_month_end) {
    $calendar_selected_date = $calendar_post_date;
}
$calendar_memo_rows = function_exists('ieum_dashboard_calendar_memo_rows') ? ieum_dashboard_calendar_memo_rows($academy_id, $calendar_month_start, $calendar_month_end) : array();
$calendar_memos = array();
foreach ($calendar_memo_rows as $memo_date_key => $memo_row) {
    $calendar_memos[$memo_date_key] = isset($memo_row['memo']) ? $memo_row['memo'] : '';
}
$academy_lesson_weekdays = array();
$academy_lesson_day_rows = sql_query("
    select attendance_days
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and attendance_days <> ''
", false);
while ($lesson_day_row = sql_fetch_array($academy_lesson_day_rows)) {
    foreach (array_filter(array_map('trim', explode(',', (string) $lesson_day_row['attendance_days']))) as $lesson_day_key) {
        if (in_array($lesson_day_key, array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'), true)) {
            $academy_lesson_weekdays[$lesson_day_key] = true;
        }
    }
}
if (!$academy_lesson_weekdays) {
    $academy_lesson_weekdays = array('mon' => true, 'tue' => true, 'wed' => true, 'thu' => true, 'fri' => true);
}
$calendar_weekday_keys = array(0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat');
$calendar_marks = array();
$calendar_mark_rows = sql_query("
    select calendar_date, day_type, title, memo
      from " . IEUM_ACADEMY_CALENDAR_TABLE . "
     where academy_id = '{$academy_id}'
       and calendar_date between '" . sql_escape_string($calendar_month_start) . "' and '" . sql_escape_string($calendar_month_end) . "'
       and is_active = 1
  order by calendar_date asc, field(day_type, 'closed', 'makeup'), calendar_id asc
", false);
while ($row = sql_fetch_array($calendar_mark_rows)) {
    if (!isset($calendar_marks[$row['calendar_date']])) {
        $calendar_marks[$row['calendar_date']] = array();
    }
    $calendar_marks[$row['calendar_date']][] = $row;
}
if (function_exists('ieum_attendance_public_holiday_labels')) {
    foreach (ieum_attendance_public_holiday_labels($calendar_month_start, $calendar_month_end) as $holiday_date => $holiday_label) {
        if (!isset($calendar_marks[$holiday_date])) {
            $calendar_marks[$holiday_date] = array();
        }

        $already_closed = false;
        foreach ($calendar_marks[$holiday_date] as $holiday_mark) {
            if (isset($holiday_mark['day_type']) && $holiday_mark['day_type'] === 'closed') {
                $already_closed = true;
                break;
            }
        }
        if (!$already_closed) {
            array_unshift($calendar_marks[$holiday_date], array(
                'calendar_date' => $holiday_date,
                'day_type' => 'closed',
                'title' => $holiday_label,
                'memo' => '공휴일',
                'source' => 'public_holiday',
            ));
        }
    }
}

if (!function_exists('ieum_dashboard_calendar_tag_label')) {
    function ieum_dashboard_calendar_tag_label($mark)
    {
        $type = isset($mark['day_type']) ? (string) $mark['day_type'] : '';
        if ($type === 'makeup') {
            return '보충';
        }

        $source = isset($mark['source']) ? (string) $mark['source'] : '';
        $memo = isset($mark['memo']) ? (string) $mark['memo'] : '';
        $title = isset($mark['title']) ? trim((string) $mark['title']) : '';
        if ($source === 'public_holiday' || $memo === '공휴일') {
            $short_labels = array(
                '제9회 전국동시지방선거일' => '선거일',
                '삼일절 대체공휴일' => '삼일절',
                '부처님오신날 대체공휴일' => '대체휴일',
                '광복절 대체공휴일' => '광복절',
                '개천절 대체공휴일' => '개천절',
                '설날 연휴' => '설연휴',
                '추석 연휴' => '추석연휴',
            );
            if (isset($short_labels[$title])) {
                return $short_labels[$title];
            }
            if ($title !== '') {
                return function_exists('mb_substr') ? mb_substr($title, 0, 4, 'UTF-8') : substr($title, 0, 8);
            }
        }

        return '휴관';
    }
}

if (!function_exists('ieum_dashboard_calendar_tag_class')) {
    function ieum_dashboard_calendar_tag_class($mark)
    {
        if (isset($mark['day_type']) && $mark['day_type'] === 'makeup') {
            return 'makeup';
        }

        $source = isset($mark['source']) ? (string) $mark['source'] : '';
        $memo = isset($mark['memo']) ? (string) $mark['memo'] : '';
        return ($source === 'public_holiday' || $memo === '공휴일') ? 'public' : 'closed';
    }
}

$dashboard_today_closed_title = $dashboard_today_lesson_label !== '' ? $dashboard_today_lesson_label : '오늘은 휴관일입니다.';
$dashboard_today_closed_desc = $dashboard_today_lesson_desc !== '' ? $dashboard_today_lesson_desc : '정상 수업과 미등원 계산에서 제외됩니다.';
if ($dashboard_today_closed) {
    $dashboard_today_public_holiday = isset($today_public_holiday_label) ? trim((string) $today_public_holiday_label) : '';
    if ($dashboard_today_public_holiday !== '') {
        $dashboard_today_short_label = ieum_dashboard_calendar_tag_label(array(
            'day_type' => 'closed',
            'title' => $dashboard_today_public_holiday,
            'memo' => '공휴일',
            'source' => 'public_holiday',
        ));
        $dashboard_today_closed_title = $dashboard_today_short_label . ' 휴무';
        $dashboard_today_closed_desc = '공휴일로 등록되어 오늘 수업과 미등원 계산에서 제외됩니다.';
    } elseif ($dashboard_today_lesson_label === '') {
        $dashboard_today_closed_title = '오늘은 도장 휴관일입니다.';
    }
    $today_class_now_label = $dashboard_today_closed_title;
}
$selected_calendar_memo = isset($calendar_memo_rows[$calendar_selected_date]) ? $calendar_memo_rows[$calendar_selected_date] : array();
$selected_alert_time = isset($selected_calendar_memo['alert_time']) && preg_match('/^\d{2}:\d{2}$/', $selected_calendar_memo['alert_time']) ? $selected_calendar_memo['alert_time'] : '09:00';
$selected_alert_hour_24 = (int) substr($selected_alert_time, 0, 2);
$selected_alert_period = $selected_alert_hour_24 >= 12 ? 'pm' : 'am';
$selected_alert_hour = $selected_alert_hour_24 >= 12 ? $selected_alert_hour_24 - 12 : $selected_alert_hour_24;
$calendar_alert_offsets = array(
    0 => '당일',
    1 => '하루 전',
    2 => '2일 전',
    3 => '3일 전',
    7 => '1주 전',
);

$promotion_summary = function_exists('ieum_dashboard_promotion_summary')
    ? ieum_dashboard_promotion_summary($academy, $report_month, $today)
    : array('promotion_due' => 0, 'poomdan_due' => 0, 'belt_need' => 0);

$character_enabled = $dashboard_count(sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and coalesce(character_report_enabled, 1) = 1
", false));
$character_ready = $dashboard_count(sql_fetch("
    select count(*) as cnt
      from (
        select s.student_id, count(distinct r.week_start) as week_count
          from " . IEUM_STUDENT_TABLE . " s
     left join " . IEUM_REPORT_CHARACTER_TABLE . " r on r.academy_id = s.academy_id
           and r.student_id = s.student_id
           and r.week_start between '{$report_month_start}' and '{$report_month_end}'
         where s.academy_id = '{$academy_id}'
           and s.is_active = 1
           and coalesce(s.character_report_enabled, 1) = 1
      group by s.student_id
        having week_count >= '{$elapsed_weeks}'
      ) t
", false));
$fitness_enabled = $dashboard_count(sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and coalesce(fitness_report_enabled, 1) = 1
", false));
$fitness_ready = function_exists('ieum_dashboard_report_fitness_completed_count')
    ? (int) ieum_dashboard_report_fitness_completed_count($academy_id, $report_month)
    : 0;
$print_ready = max(0, $character_ready + $fitness_ready);
$print_total = max(0, $character_enabled + $fitness_enabled);

$today_task_catalog = function_exists('ieum_dashboard_today_task_catalog') ? ieum_dashboard_today_task_catalog() : array();
$today_task_keys = function_exists('ieum_dashboard_get_today_task_keys') ? ieum_dashboard_get_today_task_keys($academy_id) : array();
$shortcut_keys = array_slice((array) $dashboard_shortcut_keys, 0, 6);
$quick_shortcuts = ieum_dashboard_resolve_shortcuts($shortcut_keys);

$task_cards = array(
    'missing_today' => array(
        'icon' => 'alarm',
        'label' => '미등원 확인',
        'desc' => '등원 전 원생',
        'count' => $dashboard_count($missing_today),
        'unit' => '명',
        'url' => IEUM_URL . '/admin/attendance_today.php?view=missing#missingStudents',
        'tone' => 'warn',
    ),
    'class_attendance' => array(
        'icon' => 'bars',
        'label' => '수업 부별 출석',
        'desc' => '부별 등원 흐름을 바로 확인',
        'count' => $dashboard_count($attendance) . '/' . $dashboard_count($expected_today),
        'unit' => '명',
        'url' => IEUM_URL . '/admin/attendance_today.php#classAttendance',
        'tone' => 'warn',
    ),
    'recent_attendance' => array(
        'icon' => 'clock',
        'label' => '최근 등원',
        'desc' => '방금 들어온 원생과 문자 상태 확인',
        'count' => $dashboard_count($attendance),
        'unit' => '명',
        'url' => IEUM_URL . '/admin/attendance_today.php#recentAttendance',
        'tone' => '',
    ),
    'tuition_overdue' => array(
        'icon' => 'card',
        'label' => '수련비 미납',
        'desc' => '미납 원생 확인',
        'count' => isset($tuition['unpaid_count']) ? (int) $tuition['unpaid_count'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/tuition_payments.php?billing_month=' . urlencode($billing_month) . '&payment_filter=unpaid#paymentList',
        'tone' => !empty($tuition['unpaid_count']) ? 'danger' : '',
    ),
    'tuition_paid' => array(
        'icon' => 'card',
        'label' => '수련비 납부 확인',
        'desc' => '납부자 확인',
        'count' => isset($tuition['paid_count']) ? (int) $tuition['paid_count'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/tuition_payments.php?billing_month=' . urlencode($billing_month) . '&payment_filter=paid#paymentList',
        'tone' => '',
    ),
    'tuition_due_today' => array(
        'icon' => 'card',
        'label' => '납부 예정',
        'desc' => '다가오는 납부일',
        'count' => isset($tuition['due_upcoming_count']) ? (int) $tuition['due_upcoming_count'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/tuition_payments.php?billing_month=' . urlencode($billing_month) . '&payment_filter=due_upcoming#paymentList',
        'tone' => !empty($tuition['due_upcoming_count']) ? 'warn' : '',
    ),
    'tuition_notice_pending' => array(
        'icon' => 'mail',
        'label' => '수련비 문자 예정',
        'desc' => '자동 안내 대상',
        'count' => $tuition_notice_pending_count,
        'unit' => '건',
        'url' => IEUM_URL . '/admin/sms_templates.php',
        'tone' => '',
    ),
    'sms_failed' => array(
        'icon' => 'mail',
        'label' => '문자 실패 확인',
        'desc' => '실패 문자 확인',
        'count' => isset($sms['failed_count']) ? (int) $sms['failed_count'] : 0,
        'unit' => '건',
        'url' => IEUM_URL . '/admin/sms_queue.php?status=failed',
        'tone' => '',
    ),
    'vehicle_notes' => array(
        'icon' => 'bus',
        'label' => '차량 메모 확인',
        'desc' => '차량 특이사항',
        'count' => $dashboard_count($vehicle_note_count),
        'unit' => '건',
        'url' => IEUM_URL . '/admin/vehicle_boarding.php',
        'tone' => '',
    ),
    'tablet_devices' => array(
        'icon' => 'tablet',
        'label' => '출석기 연결',
        'desc' => '태블릿 출석기 QR 연결 상태',
        'count' => $dashboard_count($tablet_devices),
        'unit' => '대',
        'url' => IEUM_URL . '/admin/tablet_devices.php',
        'tone' => '',
    ),
    'student_care_notes' => array(
        'icon' => 'person',
        'label' => '아이들 메모',
        'desc' => '메모 모아보기',
        'count' => $student_care_count,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=memo',
        'tone' => '',
    ),
    'long_absent' => array(
        'icon' => 'alarm',
        'label' => '장기 미등원',
        'desc' => '안부 연락 대상',
        'count' => $dashboard_count($long_absent_count),
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=long_absent&open=1',
        'tone' => 'danger',
    ),
    'no_guardian' => array(
        'icon' => 'person',
        'label' => '연락처 누락',
        'desc' => '보호자 정보 보완',
        'count' => $dashboard_count($no_guardian_count),
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=no_guardian',
        'tone' => 'warn',
    ),
    'birthday' => array(
        'icon' => 'cake',
        'label' => '생일 챙기기',
        'desc' => '생일 안부',
        'count' => $dashboard_count($birthday_upcoming_count),
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=birthday_month',
        'tone' => '',
    ),
    'report_blocked' => array(
        'icon' => 'report',
        'label' => '리포트 발송 불가',
        'desc' => '생년월일/연락처 보완',
        'count' => $dashboard_count($report_blocked_count),
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=report_blocked',
        'tone' => 'danger',
    ),
    'promotion_due' => array(
        'icon' => 'medal',
        'label' => '승급 대상',
        'desc' => '심사 준비',
        'count' => isset($promotion_summary['promotion_due']) ? (int) $promotion_summary['promotion_due'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/promotion_targets.php',
        'tone' => '',
    ),
    'poomdan_due' => array(
        'icon' => 'ribbon',
        'label' => '승품/단 대상',
        'desc' => '협회 심사 안내가 필요한 원생',
        'count' => isset($promotion_summary['poomdan_due']) ? (int) $promotion_summary['poomdan_due'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/promotion_poomdan_targets.php',
        'tone' => '',
    ),
    'character_input' => array(
        'icon' => 'leaf',
        'label' => '인성 입력',
        'desc' => '부별 주간 인성 체크',
        'count' => $character_ready . '/' . $character_enabled,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/character.php',
        'tone' => '',
    ),
    'fitness_input' => array(
        'icon' => 'run',
        'label' => '체력 입력',
        'desc' => '이번 달 체력 측정 입력',
        'count' => $fitness_ready . '/' . $fitness_enabled,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/fitness.php',
        'tone' => '',
    ),
    'monthly_close' => array(
        'icon' => 'report',
        'label' => '월말 마감',
        'desc' => '인성+체력 리포트 준비',
        'count' => $print_ready . '/' . $print_total,
        'unit' => '건',
        'url' => IEUM_URL . '/admin/monthly_close.php?month=' . urlencode($report_month),
        'tone' => '',
    ),
    'new_student' => array(
        'icon' => 'person',
        'label' => '원생 등록',
        'desc' => '신규 원생 빠른 등록',
        'count' => isset($student['cnt']) ? (int) $student['cnt'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?mode=form',
        'tone' => '',
    ),
);

$shortcut_badges = array(
    'attendance_today' => '출석 ' . number_format($dashboard_count($attendance)) . '/' . number_format($dashboard_count($expected_today)),
    'students' => '원생 ' . number_format(isset($student['cnt']) ? (int) $student['cnt'] : 0) . '명',
    'tuition_payments' => '미납 ' . number_format(isset($tuition['unpaid_count']) ? (int) $tuition['unpaid_count'] : 0) . '명',
    'sms' => '실패 ' . number_format(isset($sms['failed_count']) ? (int) $sms['failed_count'] : 0) . '건',
    'monthly_close' => '마감 ' . number_format($print_ready) . '/' . number_format($print_total),
    'contacts' => '알림 설정',
    'vehicle_boarding' => '메모 ' . number_format($dashboard_count($vehicle_note_count)) . '건',
    'tablet_devices' => '연결 ' . number_format($dashboard_count($tablet_devices)) . '대',
    'promotion_targets' => '대상 ' . number_format(isset($promotion_summary['promotion_due']) ? (int) $promotion_summary['promotion_due'] : 0) . '명',
    'character' => '인성 ' . number_format($character_ready) . '/' . number_format($character_enabled),
    'character_report' => '인성 ' . number_format($character_ready) . '/' . number_format($character_enabled),
    'fitness' => '체력 ' . number_format($fitness_ready) . '/' . number_format($fitness_enabled),
    'fitness_reports' => '체력 ' . number_format($fitness_ready) . '/' . number_format($fitness_enabled),
);

if (!$today_task_keys) {
    $today_task_keys = function_exists('ieum_dashboard_default_today_task_keys')
        ? ieum_dashboard_default_today_task_keys()
        : array('missing_today', 'long_absent', 'tuition_overdue', 'sms_failed', 'student_care_notes', 'monthly_close');
}
$summary_allowed_keys = array('missing_today', 'vehicle_notes', 'student_care_notes', 'long_absent', 'birthday', 'no_guardian', 'report_blocked', 'tuition_overdue', 'tuition_paid', 'tuition_due_today', 'tuition_notice_pending', 'sms_failed', 'monthly_close', 'tablet_devices', 'promotion_due');
$summary_task_keys = array_values(array_intersect($today_task_keys, $summary_allowed_keys));
if ((in_array('birthday', $today_task_keys, true) || in_array('promotion_due', $today_task_keys, true)) && !in_array('student_care_notes', $summary_task_keys, true)) {
    $summary_task_keys[] = 'student_care_notes';
}
if (!$summary_task_keys) {
    $summary_task_keys = array('missing_today', 'sms_failed', 'vehicle_notes', 'monthly_close');
}
$settings_task_keys = $summary_task_keys;
foreach ($summary_allowed_keys as $settings_key) {
    if (!in_array($settings_key, $settings_task_keys, true)) {
        $settings_task_keys[] = $settings_key;
    }
}
$summary_resolve_labels = array(
    'long_absent' => '오늘 확인',
    'sms_failed' => '오늘 확인',
    'vehicle_notes' => '메모 확인',
    'tuition_notice_pending' => '문자 확인',
    'promotion_due' => '오늘 확인',
);
$dashboard_now_minutes = ((int) date('G', $now_ts) * 60) + (int) date('i', $now_ts);
$class_timeline_status = function ($start_time) use ($dashboard_now_minutes) {
    if (!preg_match('/^(\d{1,2}):(\d{2})/', (string) $start_time, $match)) {
        return array('label' => '시간 확인', 'class' => 'upcoming');
    }

    $start_minutes = ((int) $match[1] * 60) + (int) $match[2];
    if ($dashboard_now_minutes < $start_minutes - 20) {
        return array('label' => '준비 전', 'class' => 'upcoming');
    }
    if ($dashboard_now_minutes <= $start_minutes + 55) {
        return array('label' => '진행 중', 'class' => 'now');
    }

    return array('label' => '마감', 'class' => 'done');
};

$today_class_expected_total = 0;
$today_class_attended_total = 0;
$today_class_missing_total = 0;
$today_class_now_label = '';
foreach ($class_rows as $summary_class_row) {
    $summary_expected = isset($summary_class_row['expected_count']) ? (int) $summary_class_row['expected_count'] : 0;
    $summary_attended = isset($summary_class_row['attended_count']) ? (int) $summary_class_row['attended_count'] : 0;
    $today_class_expected_total += $summary_expected;
    $today_class_attended_total += $summary_attended;
    $today_class_missing_total += max(0, $summary_expected - $summary_attended);
    if ($today_class_now_label === '') {
        $summary_status = $class_timeline_status(isset($summary_class_row['start_time']) ? $summary_class_row['start_time'] : '');
        if (isset($summary_status['class']) && $summary_status['class'] === 'now') {
            $today_class_now_label = trim(substr((string) $summary_class_row['start_time'], 0, 5) . ' ' . (string) $summary_class_row['class_name']);
        }
    }
}
if ($today_class_now_label === '') {
    $today_class_now_label = $today_class_missing_total > 0 ? '확인 필요' : '정리됨';
}
if ($dashboard_today_closed) {
    $today_class_now_label = $dashboard_today_lesson_label !== '' ? $dashboard_today_lesson_label : '휴관';
}

$icon_svg = function ($name) {
    $paths = array(
        'alarm' => '<path d="M7 8a5 5 0 0 1 10 0v4l1.5 2H5.5L7 12V8Z"/><path d="M10 17h4"/><path d="M5 4 3.5 2.5"/><path d="M19 4l1.5-1.5"/>',
        'bars' => '<path d="M5 19V9"/><path d="M12 19V5"/><path d="M19 19v-7"/>',
        'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>',
        'card' => '<rect x="3.5" y="6" width="17" height="12" rx="2"/><path d="M4 10h16"/>',
        'mail' => '<rect x="4" y="6" width="16" height="12" rx="2"/><path d="m5 8 7 5 7-5"/>',
        'bus' => '<rect x="4" y="5" width="16" height="12" rx="2"/><path d="M7 17v2"/><path d="M17 17v2"/><path d="M4 10h16"/><path d="M8 14h.01"/><path d="M16 14h.01"/>',
        'tablet' => '<rect x="7" y="3.5" width="10" height="17" rx="2"/><path d="M11 17h2"/>',
        'cake' => '<path d="M7 10h10v9H7z"/><path d="M7 14h10"/><path d="M12 7v3"/><path d="M10 7h4"/><path d="M12 4c1 1 1 2 0 3-1-1-1-2 0-3Z"/>',
        'medal' => '<path d="m8 3 4 6 4-6"/><circle cx="12" cy="14" r="5"/><path d="m10.5 14 1 1 2-2"/>',
        'ribbon' => '<path d="M8 4h8v8a4 4 0 0 1-8 0V4Z"/><path d="m8 13-2 7 6-3 6 3-2-7"/>',
        'leaf' => '<path d="M20 4c-8 0-13 4-13 10a5 5 0 0 0 8 4c3-2 5-7 5-14Z"/><path d="M7 20c2-5 6-8 11-10"/>',
        'run' => '<circle cx="13" cy="4.5" r="2"/><path d="m5 20 4-5"/><path d="m14 20-2-5-3-2 2-5 4 2 2 4"/><path d="m7 9 4-1"/>',
        'report' => '<path d="M6 3.5h9l3 3V20H6Z"/><path d="M14 3.5V8h4"/><path d="M9 13h6"/><path d="M9 16h4"/>',
        'person' => '<circle cx="12" cy="8" r="4"/><path d="M5 20c1.5-4 12.5-4 14 0"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2 2-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V20h-2.8v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1-2-2 .1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H4v-2.8h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 2-2 .1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5V4h2.8v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1 2 2-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.1v2.8h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
    );
    $path = isset($paths[$name]) ? $paths[$name] : $paths['report'];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $path . '</svg>';
};
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>아이이음 관리자</title>
<style>
*{box-sizing:border-box}
body.ieum-side-layout.ieum-dashboard-page{background:#f4f6f9!important;color:#1f2937;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.dashboard-simple{display:grid;gap:22px;min-height:calc(100vh - 96px)}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.dash-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin:0}
.dash-kicker{margin:0 0 5px;color:#697386;font-size:12px;font-weight:600}
.dash-title{margin:0;font-size:22px;letter-spacing:0;color:#050b13;font-weight:800}
.dash-desc{display:none}
.dash-actions{display:flex;gap:8px;flex-wrap:wrap}
.dash-btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;border:1px solid #cfd8e3;border-radius:5px;background:#fff;color:#243142;text-decoration:none;padding:0 13px;font-size:13px;font-weight:700}
.dash-btn.primary{background:#1583e9;border-color:#1583e9;color:#fff}
.dash-btn.dark{background:#243142;border-color:#243142;color:#fff}
.dash-notice{border-radius:5px;padding:12px 14px;font-weight:700}
.dash-notice.ok{background:#effaf3;color:#176b2c;border:1px solid #bfe7ca}
.dash-notice.err{background:#fff1f1;color:#b42318;border:1px solid #f3b6b6}
.dash-panel{background:#fff;border:1px solid #e8edf3;border-radius:5px;box-shadow:0 6px 16px rgba(35,43,58,.035);padding:20px}
.dash-panel.is-highlight{outline:2px solid #1583e9;box-shadow:0 0 0 5px rgba(21,131,233,.10),0 7px 18px rgba(35,43,58,.05)}
.dash-panel-head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:16px}
.dash-panel h2{margin:0;font-size:16px;letter-spacing:0;color:#243142;font-weight:800}
.dash-panel p{margin:5px 0 0;color:#697386;line-height:1.45;font-size:13px}
.summary-panel{position:relative;background:transparent;border:0;box-shadow:none;padding:0}
.summary-panel .dash-panel-head{display:none}
.alert-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:10px}
.alert-head h2{margin:0;font-size:15px;color:#243142}
.alert-head p{margin:3px 0 0;color:#697386;font-size:12px}
.task-order-status{display:inline-flex;align-items:center;min-height:20px;margin-left:8px;color:#697386;font-size:12px;font-weight:700;vertical-align:middle}
.task-order-status.is-saving{color:#1583e9}
.task-order-status.is-saved{color:#176b2c}
.task-order-status.is-error{color:#b42318}
.today-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.task-card{position:relative;display:grid;grid-template-columns:32px minmax(0,1fr) auto;gap:8px;align-items:center;min-height:78px;border:1px solid #e8edf3;border-radius:5px;background:#fff;color:#243142;text-decoration:none;padding:13px 14px;transition:border-color .14s ease,box-shadow .14s ease,background .14s ease,transform .18s ease,opacity .14s ease}
.task-card:hover{border-color:#c8d6e6;box-shadow:0 9px 20px rgba(35,43,58,.06)}
.task-link{display:contents;color:inherit;text-decoration:none}
.task-complete-form{grid-column:2 / 4;display:flex;justify-content:flex-start;margin:-2px 0 0}
.task-complete-button{display:inline-flex;align-items:center;justify-content:center;min-height:24px;border:1px solid #cfd8e3;border-radius:999px;background:#fff;color:#1769c2;padding:0 9px;font-size:11px;font-weight:900;cursor:pointer;line-height:1}
.task-complete-button:hover{border-color:#1769c2;background:#f4f8ff}
.today-grid.is-sortable .task-card{cursor:grab;user-select:none;-webkit-user-drag:none}
.today-grid.is-sortable .task-card:active{cursor:grabbing}
.task-card.is-dragging{opacity:.18}
.task-card.is-drag-over{outline:2px solid #1583e9;outline-offset:2px}
.task-drag-ghost{position:fixed!important;z-index:2000!important;pointer-events:none!important;margin:0!important;opacity:.98!important;transform:scale(1.025);box-shadow:0 18px 38px rgba(35,43,58,.18)!important;border-color:#9ecff6!important;cursor:grabbing!important;transition:none!important}
.task-drag-ghost .task-drag-handle{opacity:1}
body.is-task-dragging{cursor:grabbing!important}
.task-drag-handle{position:absolute;right:6px;top:5px;color:#98a2b3;font-size:12px;font-weight:900;letter-spacing:1px;line-height:1;opacity:0;transition:opacity .14s ease}
.task-card:hover .task-drag-handle,.task-card:focus-visible .task-drag-handle{opacity:1}
.task-card.warn{background:#eef8ff;border-color:#d9edf9}
.task-card.danger{background:#fff7f7;border-color:#efd3d3}
.task-icon{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:4px;background:transparent;color:#3d4b60}
.task-icon svg,.mini-icon svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-width:1.75;stroke-linecap:round;stroke-linejoin:round}
.task-text{min-width:0}
.task-text strong{display:block;font-size:13px;line-height:1.25;color:#4b5563;font-weight:800;white-space:normal;word-break:keep-all}
.task-text span{display:block;margin-top:4px;color:#1583e9;font-size:12px;line-height:1.25;white-space:normal;word-break:keep-all}
.task-number{font-size:22px;font-weight:700;text-align:right;white-space:nowrap;color:#4b5563}
.task-number small{font-size:13px;margin-left:2px}
.settings-box{position:relative}
.settings-trigger{display:flex!important;align-items:center!important;justify-content:center!important;width:38px!important;height:38px!important;min-height:38px!important;border:1px solid #cfd8e3!important;border-radius:50%!important;background:#fff!important;color:#243142!important;padding:0!important;box-shadow:0 6px 14px rgba(35,43,58,.04)}
.settings-trigger svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.summary-panel .settings-inner{position:absolute;right:0;top:46px;z-index:20;width:min(560px,calc(100vw - 340px));background:#fff;border:1px solid #dfe7f0;border-radius:5px;box-shadow:0 18px 42px rgba(35,43,58,.16);padding:16px}
.schedule-section{display:block}
.schedule-board{display:grid;grid-template-columns:minmax(0,1.28fr) minmax(390px,.92fr);gap:18px;align-items:stretch}
.schedule-card{margin:0;height:100%}
.month-panel{display:flex;flex-direction:column;min-width:0}
.month-head{display:flex;align-items:center;justify-content:flex-start;gap:12px;margin-bottom:10px}
.month-help{color:#697386;font-size:12px;font-weight:700;white-space:nowrap}
.month-nav{display:flex;align-items:center;gap:4px;white-space:nowrap}
.month-nav a,.month-nav button{display:inline-flex;align-items:center;justify-content:center;min-height:24px;border:1px solid #c4ccd8;border-radius:2px;background:#f8fafc;color:#4b5563;text-decoration:none;padding:0 7px;font-family:inherit;font-size:12px;font-weight:800;line-height:1;cursor:pointer}
.month-nav a:hover,.month-nav button:hover{border-color:#9bb7df;background:#fff;color:#1583e9}
.month-nav .month-arrow{width:24px;padding:0;font-size:18px;font-weight:700;color:#7b8491}
.month-nav .month-current{min-width:100px;border-color:transparent;background:transparent;color:#3d4253;font-size:18px;font-weight:900;padding:0 4px}
.month-nav .month-current:after{content:'▼';margin-left:5px;color:#6b7280;font-size:9px;line-height:1}
.month-picker-wrap{position:relative;display:inline-flex;align-items:center}
.month-picker-input{position:absolute;left:50%;top:100%;width:1px;height:1px;opacity:0;pointer-events:none}
.month-picker-popover{position:absolute;left:0;top:calc(100% + 6px);z-index:30;display:grid;grid-template-columns:92px 74px 48px;gap:6px;align-items:center;width:max-content;border:1px solid #d7e0eb;border-radius:5px;background:#fff;box-shadow:0 14px 32px rgba(35,43,58,.16);padding:10px}
.month-picker-popover[hidden]{display:none}
.month-picker-popover select{min-height:32px;border:1px solid #cfd8e3;border-radius:5px;background:#fff;color:#243142;padding:0 8px;font-size:12px;font-weight:800}
.month-picker-popover button{min-height:32px;border-radius:5px;background:#1583e9;border-color:#1583e9;color:#fff;font-size:12px;font-weight:800;padding:0 10px}
.month-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));grid-template-rows:18px repeat(6,52px);gap:5px}
.month-weekday{text-align:center;color:#697386;font-size:12px;font-weight:800;padding-bottom:4px}
.month-day{position:relative;display:flex;flex-direction:column;justify-content:space-between;gap:4px;min-height:0;height:52px;width:100%;border:1px solid #e8edf3;border-radius:5px;background:#fff;color:#243142;text-align:left;padding:7px;cursor:pointer;transition:.14s ease}
.month-day:hover{border-color:#b9cbe2;background:#fbfdff}
.month-day.is-empty{border:1px solid transparent;background:transparent;box-shadow:none;pointer-events:none}
.month-day.is-today{border-color:#1583e9;background:#f5fbff}
.month-day.is-selected{border-color:#1583e9;background:#eef8ff;box-shadow:0 0 0 3px rgba(21,131,233,.12)}
.month-day.has-memo:not(.is-selected){border-color:#efd99f;background:#fffdf5}
.month-day.is-default-closed:not(.is-selected):not(.has-memo){background:#fafbfc;color:#697386}
.month-day-num{font-size:13px;font-weight:900}
.month-tags{display:flex;align-items:flex-end;flex-wrap:wrap;gap:3px;min-height:16px}
.month-tags i{display:inline-flex;align-items:center;border-radius:999px;background:#eef3fb;color:#697386;font-style:normal;font-size:10px;font-weight:900;line-height:1;padding:2px 5px}
.month-tags i.closed{background:#fff0f0;color:#b42318}
.month-tags i.makeup{background:#effaf3;color:#176b2c}
.month-tags i.public{background:#fff7e6;color:#a15c00}
.month-tags i.default-closed{background:#f1f3f5;color:#7b8491}
.month-tags i.memo{max-width:100%;background:#eef8ff;color:#1583e9;overflow:hidden;text-overflow:ellipsis}
.calendar-memo-form{display:grid;grid-template-columns:98px minmax(54px,1fr) 44px auto;gap:4px;align-items:center;min-width:0;border-top:1px solid #e8edf3;margin-top:12px;padding-top:10px}
.calendar-memo-form.is-syncing{border-top-color:#1583e9}
.calendar-memo-form input,.calendar-memo-form select{min-height:32px;border:1px solid #cfd8e3;border-radius:5px;background:#fff;color:#243142;padding:0 5px;font-size:10.5px}
.calendar-memo-form .dash-btn{width:auto;min-height:32px;justify-content:center;padding:0 7px;font-size:10.5px;white-space:nowrap}
.memo-alert-controls{display:flex;align-items:center;gap:4px;min-width:0;border:0;border-radius:0;background:transparent;padding:0}
.memo-alert-check{display:inline-flex;align-items:center;gap:3px;color:#243142;font-size:10.5px;font-weight:800;white-space:nowrap}
.memo-alert-check input{min-height:0;width:auto}
.memo-alert-controls span{display:none;color:#697386;font-size:12px;font-weight:800;white-space:nowrap}
.memo-alert-fields{display:grid;grid-template-columns:44px 40px 44px;gap:3px;min-width:0}
.today-class-panel{display:flex;flex-direction:column;min-width:0}
.today-class-head{display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid #e8edf3;padding-bottom:9px;margin-bottom:10px}
.today-class-head h3{margin:0;font-size:15px;color:#243142}
.today-class-head a{color:#1583e9;font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap}
.today-class-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin-bottom:10px}
.today-class-stat{display:flex;align-items:center;justify-content:space-between;gap:4px;min-width:0;min-height:36px;border:1px solid #e8edf3;border-radius:5px;background:#f8fafc;padding:0 6px}
.today-class-stat span{flex:0 0 auto;color:#697386;font-size:9.5px;font-weight:900;white-space:nowrap}
.today-class-stat b{min-width:0;color:#243142;font-size:12px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.today-class-stat.warn b{color:#b42318}
.timeline-table-head{display:grid;grid-template-columns:58px minmax(50px,1fr) 64px 76px 86px;gap:8px;border-bottom:1px solid #edf1f6;padding:0 0 6px;color:#94a3b8;font-size:11px;font-weight:900}
.timeline-table-head{grid-template-columns:50px 48px 58px minmax(58px,1fr) minmax(64px,1fr);gap:6px}
.timeline-table-head span:nth-child(3){text-align:center}
.timeline-table-head span:nth-child(n+4){text-align:right}
.timeline-list{display:grid;gap:0}
.timeline-row{display:grid;grid-template-columns:50px 48px 58px minmax(58px,1fr) minmax(64px,1fr);gap:6px;align-items:center;min-height:46px;border:0;border-bottom:1px solid #edf1f6;border-radius:0;background:transparent;color:#243142;text-decoration:none;padding:7px 0}
.timeline-row:hover{background:#fbfdff}
.timeline-row:last-child{border-bottom:0}
.timeline-row.is-now{background:#f7fbff}
.timeline-row.has-missing .timeline-missing{border-radius:0;background:transparent;padding:0}
.timeline-time{font-size:13px;font-weight:900;color:#111827}
.timeline-body{display:block;min-width:0}
.timeline-body strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:14px;line-height:1.15;color:#243142}
.timeline-status{display:inline-flex;align-items:center;justify-content:center;justify-self:center;width:max-content;max-width:100%;border-radius:999px;background:#eef3fb;color:#697386;padding:2px 6px;font-size:9.5px;font-weight:900;line-height:1.2;white-space:nowrap}
.timeline-status.now{background:#e8f5ff;color:#1574c4}
.timeline-status.done{background:#f1f3f5;color:#7b8491}
.timeline-count,.timeline-missing{display:inline-flex;align-items:baseline;justify-content:flex-end;gap:4px;min-width:0;text-align:right;color:#697386;font-size:10.5px;font-weight:800;line-height:1;white-space:nowrap}
.timeline-count b,.timeline-missing b{display:inline;color:#243142;font-size:12.5px}
.timeline-count span,.timeline-missing span{display:inline;margin:0}
.timeline-missing.warn b{color:#b42318}
.timeline-missing.ok b{color:#697386}
.quick-attendance-panel{min-width:0;border-top:1px solid #e8edf3;margin-top:auto;padding-top:10px}
.today-closed-text{display:grid;gap:5px;margin-top:4px;border:1px solid #e8edf3;border-radius:5px;background:#f8fafc;text-align:left}
.today-closed-text strong{color:#243142;font-size:14px}
.today-closed-text span{color:#697386;font-size:12px;font-weight:700;line-height:1.45}
.quick-attendance-closed{display:flex;align-items:center;min-height:34px;border:1px solid #e8edf3;border-radius:5px;background:#f8fafc;color:#697386;padding:0 10px;font-size:12px;font-weight:800}
.quick-attendance-form{display:grid;grid-template-columns:44px 74px minmax(76px,1fr) 86px 36px;gap:3px;align-items:center}
.quick-attendance-title{font-size:10px;font-weight:900;color:#243142;white-space:nowrap}
.quick-attendance-label{color:#697386;font-size:12px;font-weight:900;white-space:nowrap}
.quick-attendance-form select{min-height:30px;border:1px solid #cfd8e3;border-radius:5px;background:#fff;color:#243142;padding:0 4px;font-size:9px}
.quick-attendance-form select[name="quick_class_time_id"]{grid-column:auto}
.quick-attendance-target{grid-column:auto;display:inline-flex;align-items:center;justify-content:center;min-width:0;min-height:30px;border-radius:5px;background:#f8fafc;color:#243142;border:1px solid #e8edf3;padding:2px 4px;font-size:8.8px;font-weight:900;line-height:1;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.quick-attendance-target b{margin-left:4px}
.quick-attendance-options{grid-column:auto;display:flex;align-items:center;justify-content:center;gap:4px;min-width:0;color:#243142;font-size:8.8px;font-weight:800;white-space:nowrap}
.quick-attendance-options label{display:inline-flex;align-items:center;gap:2px;min-width:0}
.quick-attendance-options input{width:11px;height:11px;margin:0;flex:0 0 auto}
.quick-attendance-form .dash-btn{grid-column:auto;grid-row:auto;align-self:stretch;min-height:30px;padding:0 5px;font-size:9.5px}
.three-grid{display:grid;grid-template-columns:1.05fr 1fr 1fr;gap:24px}
.mini-card{border:1px solid #e8edf3;border-radius:5px;padding:18px 20px;background:#fff}
.mini-card.warn{background:#fffaf0;border-color:#f2dcac}
.mini-card.danger{background:#fff5f5;border-color:#efc4c4}
.mini-title{display:flex;align-items:center;justify-content:space-between;gap:10px;color:#697386;font-size:13px;font-weight:800}
.mini-icon{display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:4px;background:#f3f6fa;color:#3d4b60}
.mini-count{display:block;margin:10px 0 6px;font-size:28px;font-weight:800;color:#243142}
.mini-card p{font-size:13px}
.mini-list{display:grid;gap:0;margin-top:14px;border-top:1px solid #e8edf3}
.mini-row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #e8edf3;padding:10px 0;font-size:13px;text-decoration:none;color:#243142}
.mini-row strong{font-size:13px;font-weight:700}
.mini-row span{color:#697386;text-align:right}
.progress-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px}
.progress-card{border:1px solid #e8edf3;border-radius:5px;background:#fff;padding:20px;text-decoration:none;color:#243142}
.progress-card strong{display:block;color:#697386;font-size:13px}
.progress-card b{display:block;margin-top:8px;font-size:28px;font-weight:800}
.progress-track{height:7px;background:#edf2f7;border-radius:999px;overflow:hidden;margin-top:14px}
.progress-fill{height:100%;background:#1583e9;border-radius:999px}
.lower-grid{display:grid;grid-template-columns:1fr 1.15fr 1fr;gap:24px;align-items:stretch}
.class-flow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.class-chip{border:1px solid #e8edf3;border-radius:5px;padding:14px;background:#fff;text-decoration:none;color:#243142}
.class-chip strong{display:flex;justify-content:space-between;gap:8px;font-size:14px}
.class-chip span{display:block;margin-top:6px;color:#697386;font-size:12px}
.class-bar{height:7px;background:#edf2f7;border-radius:999px;overflow:hidden;margin-top:10px}
.class-bar i{display:block;height:100%;background:#1583e9;border-radius:999px}
.table-clean{width:100%;border-collapse:separate;border-spacing:0;border:1px solid #e8edf3;border-radius:5px;overflow:hidden;background:#fff}
.table-clean th,.table-clean td{padding:12px;border-bottom:1px solid #e5eaf1;text-align:left;font-size:14px}
.table-clean th{background:#f8fafc;color:#475569;font-weight:1000}
.table-clean tr:last-child td{border-bottom:0}
.empty-text{color:#667085;text-align:center;padding:18px!important}
.sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.settings-option{display:flex;align-items:center;gap:8px;border:1px solid #e8edf3;border-radius:5px;background:#fff;padding:10px 12px;font-size:13px;font-weight:700}
.settings-option input{width:auto}
details.settings-box{border:1px solid #e8edf3;border-radius:5px;background:#f8fafc;padding:0;margin-top:12px}
details.settings-box summary{cursor:pointer;padding:13px 15px;font-size:13px;font-weight:800;color:#1583e9;list-style:none}
details.settings-box summary::-webkit-details-marker{display:none}
.settings-inner{padding:0 16px 16px}
.settings-help{margin:10px 0;color:#667085;font-size:13px}
.settings-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.settings-actions .dash-btn{width:auto;margin-top:12px}
.settings-save{width:100%;margin-top:12px}
.summary-panel .settings-box{border:0!important;background:transparent!important;padding:0!important;margin:0!important}
.summary-panel .settings-help{margin:10px 0 0}
.recent-actions{display:grid;gap:10px}
.recent-action{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #e8edf3;border-radius:5px;padding:12px 14px;color:#243142;text-decoration:none;background:#fff}
.recent-action:hover{background:#f8fbff;border-color:#9bb7df}
.recent-action strong{display:block}
.recent-action span{display:block;color:#667085;font-size:13px;margin-top:3px}
.pill{display:inline-flex;align-items:center;border-radius:999px;background:#eef3fb;color:#1583e9;padding:5px 9px;font-size:12px;font-weight:800}
.utility-links{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.utility-link{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 10px;align-content:start;min-height:74px;border:1px solid #e8edf3;border-radius:5px;background:#fff;color:#243142;text-decoration:none;padding:12px 13px;transition:border-color .14s ease,background .14s ease,transform .14s ease}
.utility-link:hover{border-color:#9bb7df;background:#f8fbff}
.utility-link strong{grid-column:1;font-size:13px;font-weight:900;line-height:1.25}
.utility-link span{grid-column:1;color:#697386;font-size:12px;font-weight:700;line-height:1.35;word-break:keep-all}
.utility-link b{grid-column:2;grid-row:1 / span 2;align-self:center;justify-self:end;border-radius:999px;background:#eef8ff;color:#1583e9;padding:3px 8px;font-size:11px;font-weight:900;white-space:nowrap}
.shortcut-favorite-status{display:inline-block;margin-left:6px;color:#1583e9;font-size:12px;font-weight:800}
.shortcut-favorite-status.is-error{color:#b42318}
.shortcut-favorite-status:empty{display:none}
.notice-list{display:grid}
.notice-row{display:grid;grid-template-columns:minmax(0,1fr) 92px;gap:12px;align-items:center;min-height:34px;border-bottom:1px solid #e8edf3;color:#243142;text-decoration:none;font-size:13px}
.notice-row:last-child{border-bottom:0}
.notice-row strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px}
.notice-row span{text-align:right;color:#697386;font-size:12px}
.support-card{height:100%;display:grid;align-content:start;text-align:left;color:#697386}
.support-links{display:grid;gap:8px;width:100%;margin-top:14px}
.support-link{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:42px;border:1px solid #e8edf3;border-radius:5px;color:#243142;text-decoration:none;font-size:13px;font-weight:800;padding:0 12px}
.support-link:hover{border-color:#9bb7df;background:#f8fbff}
.support-link span{color:#1583e9;font-size:12px;font-weight:800}
.support-note{margin:12px 0 0;color:#697386;font-size:12px;line-height:1.45}
.secondary-drawer{display:block}
.secondary-drawer>summary{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:56px;border:1px solid #e8edf3;border-radius:5px;background:#fff;box-shadow:0 6px 16px rgba(35,43,58,.035);padding:0 18px;cursor:pointer;list-style:none;color:#243142}
.secondary-drawer>summary::-webkit-details-marker{display:none}
.secondary-drawer>summary strong{display:block;font-size:15px}
.secondary-drawer>summary small{display:block;margin-top:3px;color:#697386;font-size:12px;font-weight:700}
.secondary-meta{display:inline-flex;align-items:center;min-height:28px;border-radius:999px;background:#eef3fb;color:#1583e9;padding:0 10px;font-size:12px;font-weight:900;white-space:nowrap}
.secondary-body{display:grid;gap:22px;margin-top:18px}
.dashboard-footer{margin:8px 0 0 260px;padding:16px 40px;border-top:1px solid #d8e0ea;background:#fff;color:#697386;font-size:13px}
.dashboard-footer-inner{display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap}
.dashboard-footer-brand{font-weight:800;color:#243142}
.dashboard-footer-links{display:flex;gap:28px;flex-wrap:wrap}
.dashboard-footer a{color:#1583e9;text-decoration:none;font-weight:700}
body.ieum-dashboard-page.ieum-dark{background:#0f1724!important;color:#d9e2ef!important}
body.ieum-dashboard-page.ieum-dark .dashboard-footer{background:#111827!important;border-color:#263244!important;color:#9aa8bb!important}
body.ieum-dashboard-page.ieum-dark .dashboard-footer-brand{color:#e5edf7!important}
body.ieum-dashboard-page.ieum-dark .dashboard-footer-links{color:#9aa8bb!important}
body.ieum-dashboard-page.ieum-dark .shortcut-favorite-status{color:#8fd0ff!important}
body.ieum-dashboard-page.ieum-dark .shortcut-favorite-status.is-error{color:#ffb4c0!important}
body.ieum-dashboard-page.ieum-dark .dash-panel,body.ieum-dashboard-page.ieum-dark .task-card,body.ieum-dashboard-page.ieum-dark .mini-card,body.ieum-dashboard-page.ieum-dark .progress-card,body.ieum-dashboard-page.ieum-dark .class-chip,body.ieum-dashboard-page.ieum-dark .table-clean,body.ieum-dashboard-page.ieum-dark .recent-action,body.ieum-dashboard-page.ieum-dark .utility-link,body.ieum-dashboard-page.ieum-dark .support-link,body.ieum-dashboard-page.ieum-dark .timeline-row,body.ieum-dashboard-page.ieum-dark .today-class-stat,body.ieum-dashboard-page.ieum-dark .month-day,body.ieum-dashboard-page.ieum-dark .calendar-memo-form input,body.ieum-dashboard-page.ieum-dark .calendar-memo-form select,body.ieum-dashboard-page.ieum-dark .memo-alert-controls,body.ieum-dashboard-page.ieum-dark .quick-attendance-form select,body.ieum-dashboard-page.ieum-dark .quick-attendance-target,body.ieum-dashboard-page.ieum-dark .secondary-drawer>summary,body.ieum-dashboard-page.ieum-dark .settings-trigger,body.ieum-dashboard-page.ieum-dark .summary-panel .settings-inner,body.ieum-dashboard-page.ieum-dark .settings-option,body.ieum-dashboard-page.ieum-dark details.settings-box,body.ieum-dashboard-page.ieum-dark .month-picker-popover{background:#151f2e!important;border-color:#2c3a4f!important;color:#e5edf7!important;box-shadow:none!important}
body.ieum-dashboard-page.ieum-dark .summary-panel{background:transparent!important;border:0!important}
body.ieum-dashboard-page.ieum-dark .task-card.warn,body.ieum-dashboard-page.ieum-dark .month-day.is-today,body.ieum-dashboard-page.ieum-dark .month-day.is-selected,body.ieum-dashboard-page.ieum-dark .timeline-row.is-now{background:#10283d!important;border-color:#1c5b88!important}
body.ieum-dashboard-page.ieum-dark .task-card.danger,body.ieum-dashboard-page.ieum-dark .mini-card.danger{background:#2b1820!important;border-color:#6f2d3b!important}
body.ieum-dashboard-page.ieum-dark .mini-card.warn,body.ieum-dashboard-page.ieum-dark .month-day.has-memo:not(.is-selected){background:#2a2416!important;border-color:#6d5421!important}
body.ieum-dashboard-page.ieum-dark .dash-title,body.ieum-dashboard-page.ieum-dark .dash-panel h2,body.ieum-dashboard-page.ieum-dark .alert-head h2,body.ieum-dashboard-page.ieum-dark .task-text strong,body.ieum-dashboard-page.ieum-dark .task-number,body.ieum-dashboard-page.ieum-dark .mini-count,body.ieum-dashboard-page.ieum-dark .progress-card,body.ieum-dashboard-page.ieum-dark .class-chip strong,body.ieum-dashboard-page.ieum-dark .utility-link strong,body.ieum-dashboard-page.ieum-dark .support-link,body.ieum-dashboard-page.ieum-dark .timeline-body strong,body.ieum-dashboard-page.ieum-dark .timeline-time,body.ieum-dashboard-page.ieum-dark .timeline-count b,body.ieum-dashboard-page.ieum-dark .timeline-missing b,body.ieum-dashboard-page.ieum-dark .quick-attendance-title,body.ieum-dashboard-page.ieum-dark .today-class-head h3,body.ieum-dashboard-page.ieum-dark .today-class-stat b,body.ieum-dashboard-page.ieum-dark .month-day,body.ieum-dashboard-page.ieum-dark .calendar-memo-form input,body.ieum-dashboard-page.ieum-dark .calendar-memo-form select{color:#f8fafc!important}
body.ieum-dashboard-page.ieum-dark .dash-desc,body.ieum-dashboard-page.ieum-dark .dash-kicker,body.ieum-dashboard-page.ieum-dark .dash-panel p,body.ieum-dashboard-page.ieum-dark .alert-head p,body.ieum-dashboard-page.ieum-dark .task-text span,body.ieum-dashboard-page.ieum-dark .mini-row span,body.ieum-dashboard-page.ieum-dark .class-chip span,body.ieum-dashboard-page.ieum-dark .recent-action span,body.ieum-dashboard-page.ieum-dark .utility-link span,body.ieum-dashboard-page.ieum-dark .support-note,body.ieum-dashboard-page.ieum-dark .timeline-count,body.ieum-dashboard-page.ieum-dark .timeline-missing,body.ieum-dashboard-page.ieum-dark .quick-attendance-label,body.ieum-dashboard-page.ieum-dark .today-class-stat span,body.ieum-dashboard-page.ieum-dark .month-help,body.ieum-dashboard-page.ieum-dark .month-weekday,body.ieum-dashboard-page.ieum-dark .secondary-drawer>summary small,body.ieum-dashboard-page.ieum-dark .settings-help,body.ieum-dashboard-page.ieum-dark .notice-row span{color:#9aa8bb!important}
body.ieum-dashboard-page.ieum-dark .memo-alert-check,body.ieum-dashboard-page.ieum-dark .quick-attendance-options,body.ieum-dashboard-page.ieum-dark .quick-attendance-options label{color:#e5edf7!important}
body.ieum-dashboard-page.ieum-dark .memo-alert-check input,body.ieum-dashboard-page.ieum-dark .quick-attendance-options input{accent-color:#1d7fe0!important}
body.ieum-dashboard-page.ieum-dark .calendar-memo-form input::placeholder{color:#b8c4d6!important;opacity:1!important}
body.ieum-dashboard-page.ieum-dark .task-icon,body.ieum-dashboard-page.ieum-dark .mini-icon{color:#aeb8cb!important;background:#1a2434!important}
body.ieum-dashboard-page.ieum-dark .task-complete-button{background:#111827!important;border-color:#2c3a4f!important;color:#8fd0ff!important}
body.ieum-dashboard-page.ieum-dark .dash-btn,body.ieum-dashboard-page.ieum-dark .month-nav a,body.ieum-dashboard-page.ieum-dark .month-nav button{background:#172132!important;border-color:#334155!important;color:#e5edf7!important}
body.ieum-dashboard-page.ieum-dark .dash-btn.primary{background:#1d7fe0!important;border-color:#1d7fe0!important;color:#fff!important}
body.ieum-dashboard-page.ieum-dark .dash-btn.dark{background:#0b1220!important;border-color:#334155!important}
body.ieum-dashboard-page.ieum-dark .month-nav .month-current{background:transparent!important;border-color:transparent!important;color:#f8fafc!important}
body.ieum-dashboard-page.ieum-dark .month-day.is-empty{background:transparent!important;border-color:transparent!important}
body.ieum-dashboard-page.ieum-dark .month-tags i,body.ieum-dashboard-page.ieum-dark .timeline-status,body.ieum-dashboard-page.ieum-dark .pill,body.ieum-dashboard-page.ieum-dark .secondary-meta{background:#1c2d44!important;color:#8fd0ff!important}
body.ieum-dashboard-page.ieum-dark .timeline-missing.warn b{color:#ffb4c0!important}
body.ieum-dashboard-page.ieum-dark .today-class-stat.warn b{color:#ffb4c0!important}
body.ieum-dashboard-page.ieum-dark .timeline-missing.ok b{color:#9aa8bb!important}
body.ieum-dashboard-page.ieum-dark .timeline-row.has-missing .timeline-missing{background:transparent!important}
body.ieum-dashboard-page.ieum-dark .today-closed-text,body.ieum-dashboard-page.ieum-dark .quick-attendance-closed{background:#151f2e!important;border-color:#2c3a4f!important;color:#9aa8bb!important}
body.ieum-dashboard-page.ieum-dark .today-closed-text strong{color:#f8fafc!important}
body.ieum-dashboard-page.ieum-dark .today-closed-text span{color:#9aa8bb!important}
body.ieum-dashboard-page.ieum-dark .month-tags i.closed{background:#3a1f27!important;color:#ffb4c0!important}
body.ieum-dashboard-page.ieum-dark .month-tags i.makeup{background:#183425!important;color:#8ee0a8!important}
body.ieum-dashboard-page.ieum-dark .month-tags i.public{background:#3b2f16!important;color:#ffd58a!important}
body.ieum-dashboard-page.ieum-dark .month-tags i.default-closed{background:#202a38!important;color:#9aa8bb!important}
body.ieum-dashboard-page.ieum-dark .calendar-memo-form,body.ieum-dashboard-page.ieum-dark .today-class-head,body.ieum-dashboard-page.ieum-dark .timeline-table-head,body.ieum-dashboard-page.ieum-dark .timeline-row,body.ieum-dashboard-page.ieum-dark .today-class-stat,body.ieum-dashboard-page.ieum-dark .quick-attendance-panel,body.ieum-dashboard-page.ieum-dark .mini-list,body.ieum-dashboard-page.ieum-dark .mini-row,body.ieum-dashboard-page.ieum-dark .notice-row,body.ieum-dashboard-page.ieum-dark .utility-link,body.ieum-dashboard-page.ieum-dark .support-link{border-color:#2c3a4f!important}
body.ieum-dashboard-page.ieum-dark .table-clean th{background:#1a2434!important;color:#cbd5e1!important}
body.ieum-dashboard-page.ieum-dark .table-clean td{border-color:#2c3a4f!important}
body.ieum-dashboard-page.ieum-dark .dashboard-footer a{color:#8fd0ff!important}
body.ieum-dashboard-page.ieum-dark .calendar-memo-form input[type="date"],
body.ieum-dashboard-page.ieum-dark .calendar-memo-form input[type="text"],
body.ieum-dashboard-page.ieum-dark .calendar-memo-form select,
body.ieum-dashboard-page.ieum-dark .quick-attendance-form select{
    background:#111827!important;
    border-color:#334155!important;
    color:#f8fafc!important;
}
body.ieum-dashboard-page.ieum-dark .calendar-memo-form input[type="text"]::placeholder{
    color:#b8c4d6!important;
    opacity:1!important;
}
body.ieum-dashboard-page.ieum-dark .memo-alert-check,
body.ieum-dashboard-page.ieum-dark .memo-alert-check *,
body.ieum-dashboard-page.ieum-dark .quick-attendance-options,
body.ieum-dashboard-page.ieum-dark .quick-attendance-options label,
body.ieum-dashboard-page.ieum-dark .quick-attendance-options *{
    color:#f8fafc!important;
}
body.ieum-dashboard-page.ieum-dark .memo-alert-check input,
body.ieum-dashboard-page.ieum-dark .quick-attendance-options input{
    accent-color:#1d7fe0!important;
}
@media(max-width:1280px){.today-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.three-grid,.progress-list{grid-template-columns:1fr 1fr}.lower-grid{grid-template-columns:1fr}.class-flow{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(min-width:1500px){.schedule-board{grid-template-columns:minmax(760px,1.38fr) minmax(560px,1fr);gap:22px}.calendar-memo-form{grid-template-columns:112px minmax(180px,1fr) 54px auto;gap:6px}.calendar-memo-form input,.calendar-memo-form select{min-height:34px;font-size:12px}.calendar-memo-form .dash-btn{min-height:34px;font-size:12px}.memo-alert-check{font-size:12px}.memo-alert-fields{grid-template-columns:54px 48px 54px;gap:4px}.today-class-stats{gap:8px}.today-class-stat{min-height:38px;padding:0 10px}.timeline-table-head,.timeline-row{grid-template-columns:62px 72px 70px 88px 94px;gap:10px}.timeline-count b,.timeline-missing b{font-size:13px}.quick-attendance-form{grid-template-columns:58px 100px minmax(190px,1fr) 136px 54px;gap:6px}.quick-attendance-title{font-size:12px}.quick-attendance-form select{min-height:34px;font-size:12px}.quick-attendance-target{min-height:34px;font-size:11.5px;padding:0 8px}.quick-attendance-options{font-size:11px;gap:8px}.quick-attendance-options input{width:13px;height:13px}.quick-attendance-form .dash-btn{min-height:34px;font-size:12px;padding:0 10px}}
@media(max-width:1040px){.schedule-board{grid-template-columns:1fr}.today-class-panel{border-top:0;padding-top:0}}
@media(max-width:820px){body.ieum-side-layout.ieum-dashboard-page .wrap{margin:0!important;padding:86px 14px 0!important}.dash-hero,.dash-panel-head,.secondary-drawer>summary{display:block}.dash-actions{margin-top:12px}.today-grid,.three-grid,.progress-list,.settings-grid,.schedule-board,.utility-links{grid-template-columns:1fr}.summary-panel .settings-inner{width:calc(100vw - 28px);right:-2px}.task-card{grid-template-columns:42px 1fr}.task-number{grid-column:2;text-align:left}.class-flow{grid-template-columns:1fr}.month-head{align-items:flex-start;flex-direction:column}.month-nav{width:auto}.month-calendar{grid-template-rows:18px repeat(6,46px)}.month-day{height:46px;padding:6px}.calendar-memo-form{grid-template-columns:1fr}.calendar-memo-form .dash-btn{width:100%}.memo-alert-controls{display:grid;grid-template-columns:1fr;align-items:stretch;gap:7px;border:1px solid #e8edf3;border-radius:5px;background:#f8fafc;padding:9px 10px}.memo-alert-controls span{display:block}.memo-alert-fields{grid-template-columns:1fr;gap:7px}.today-class-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.quick-attendance-panel{margin-top:12px}.quick-attendance-form{grid-template-columns:1fr}.quick-attendance-target{justify-content:flex-start}.quick-attendance-options{display:grid;gap:7px}.quick-attendance-form .dash-btn{width:100%}.timeline-table-head{display:none}.timeline-row{grid-template-columns:64px 1fr}.timeline-count{grid-column:2;text-align:left}.secondary-meta{margin-top:8px}.dashboard-footer{margin:8px 0 0;padding:16px 14px}}
@media(max-width:820px){.timeline-table-head{display:none}.timeline-row{grid-template-columns:64px minmax(0,1fr) auto;grid-template-areas:"time body status" "time count missing"}.timeline-time{grid-area:time}.timeline-body{grid-area:body}.timeline-status{grid-area:status;justify-self:end}.timeline-count{grid-area:count;text-align:left}.timeline-missing{grid-area:missing;text-align:right}}
@media(max-width:820px){.today-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:10px}.task-card{grid-template-columns:26px minmax(0,1fr)!important;align-items:start;align-content:start;min-height:104px;padding:11px 10px}.task-icon{width:26px;height:26px}.task-icon svg,.mini-icon svg{width:20px;height:20px}.task-text strong{font-size:12px;line-height:1.25}.task-text span{font-size:11px;line-height:1.25;margin-top:3px}.task-number{grid-column:1 / -1!important;text-align:left;font-size:20px;line-height:1.1;margin-top:3px}.task-number small{font-size:11px}.task-complete-form{grid-column:1 / -1;margin:0}.task-complete-button{min-height:22px;padding:0 8px;font-size:10px}.quick-attendance-form{grid-template-columns:50px minmax(76px,1fr) 86px 40px!important;gap:6px!important}.quick-attendance-title{grid-column:1;grid-row:1;font-size:11px}.quick-attendance-form select[name="quick_class_time_id"]{grid-column:2 / 5;grid-row:1;min-height:34px;font-size:12px;padding:0 7px}.quick-attendance-target{grid-column:1 / 3;grid-row:2;justify-content:flex-start;min-height:34px;padding:0 7px;font-size:10px}.quick-attendance-options{grid-column:3;grid-row:2;display:flex!important;align-items:center;justify-content:center;gap:5px!important;font-size:10px;white-space:nowrap}.quick-attendance-options label{display:inline-flex;align-items:center;gap:2px}.quick-attendance-options input{width:12px;height:12px}.quick-attendance-form .dash-btn{grid-column:4;grid-row:2;width:auto!important;min-height:34px;padding:0 6px;font-size:10px}}
@media(max-width:820px){.quick-attendance-form{grid-template-columns:48px minmax(64px,1fr) 96px 40px!important}.quick-attendance-options{color:#243142!important;font-weight:900}.quick-attendance-options input{accent-color:#1583e9}body.ieum-dashboard-page.ieum-dark .quick-attendance-options{color:#e5edf7!important}}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page">
<script>
(function(){
    try {
        if (localStorage.getItem('ieumDashboardTheme') === 'dark') {
            document.body.classList.add('ieum-dark');
        }
    } catch (error) {}
})();
</script>
<?php echo ieum_admin_header('dashboard', 'side'); ?>
<main class="wrap dashboard-simple">
    <section class="dash-hero">
        <div>
            <p class="dash-kicker"><?php echo $dashboard_h(isset($academy['academy_code']) ? $academy['academy_code'] : ''); ?> · <?php echo $dashboard_h(isset($academy['academy_name']) ? $academy['academy_name'] : ''); ?></p>
            <h1 class="dash-title">오늘 도장 현황 (<?php echo $dashboard_h(date('n월 j일', $now_ts) . ' ' . $today_label . '요일'); ?>)</h1>
            <p class="dash-desc">지금 바로 확인할 업무만 먼저 모았습니다. 자세한 목록과 설정은 각 업무 화면에서 이어갑니다.</p>
        </div>
        <div class="dash-actions">
            <a class="dash-btn" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">오늘 출석</a>
            <a class="dash-btn primary" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form">원생 등록</a>
        </div>
    </section>

    <?php if ($message !== '') { ?><div class="dash-notice ok"><?php echo $dashboard_h($message); ?></div><?php } ?>
    <?php if ($error !== '') { ?><div class="dash-notice err"><?php echo $dashboard_h($error); ?></div><?php } ?>

    <section class="dash-panel summary-panel">
        <div class="dash-panel-head">
            <div>
                <h2>오늘 핵심 현황</h2>
                <p>매일 반복해서 확인하는 핵심 업무입니다.</p>
            </div>
            <a class="dash-btn" href="#todayTaskSettings">구성 변경</a>
        </div>
        <div class="alert-head">
            <div>
                <h2>오늘 알림</h2>
                <p>선택한 알림만 첫 화면에 표시합니다. 오늘 확인은 오늘만 숨기며, 내일도 조건이 같으면 다시 표시됩니다. <span class="task-order-status" data-task-order-status aria-live="polite">카드를 드래그해 순서를 바꿀 수 있습니다.</span></p>
            </div>
            <details class="settings-box" id="todayTaskSettings">
                <summary class="settings-trigger" title="알림 설정">
                    <?php echo $icon_svg('gear'); ?>
                    <span class="sr-only">알림 설정</span>
                </summary>
                <form class="settings-inner" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $dashboard_h($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_dashboard_today_tasks">
                    <div class="settings-grid" data-task-settings-grid>
                        <?php foreach ($settings_task_keys as $key) {
                            if (!isset($task_cards[$key])) {
                                continue;
                            }
                            $card = $task_cards[$key];
                            $is_task_active = in_array($key, $summary_task_keys, true);
                        ?>
                        <label class="settings-option" data-task-key="<?php echo $dashboard_h($key); ?>">
                            <input type="checkbox" name="task_keys[]" value="<?php echo $dashboard_h($key); ?>" <?php echo $is_task_active ? 'checked' : ''; ?>>
                            <?php echo $dashboard_h($card['label']); ?>
                        </label>
                        <?php } ?>
                    </div>
                    <p class="settings-help">기본값은 미등원, 장기 미등원, 수련비 미납, 문자 실패, 아이들 메모, 월말 마감입니다. 순서는 카드에서 바로 드래그해 바꿀 수 있고, 작은 확인 버튼은 오늘 알림에서만 숨기는 처리입니다. 실제 조건이 해결되지 않으면 다음날 다시 표시됩니다.</p>
                    <div class="settings-actions">
                        <button class="dash-btn" type="submit" name="task_preset" value="default">기본값 적용</button>
                        <button class="dash-btn primary settings-save" type="submit">알림 적용</button>
                    </div>
                </form>
            </details>
        </div>
        <div class="today-grid is-sortable" data-today-task-grid>
            <?php
            $shown_tasks = 0;
            foreach ($summary_task_keys as $task_key) {
                if (!isset($task_cards[$task_key])) {
                    continue;
                }
                $card = $task_cards[$task_key];
                $raw_count = isset($card['count']) ? $card['count'] : 0;
                $can_resolve_task = isset($summary_resolve_labels[$task_key]) && is_numeric($raw_count) && (int) $raw_count > 0;
                $shown_tasks++;
            ?>
            <div class="task-card <?php echo $dashboard_h($card['tone']); ?> <?php echo $can_resolve_task ? 'has-complete' : ''; ?>" draggable="false" data-task-key="<?php echo $dashboard_h($task_key); ?>" title="드래그해서 순서 변경">
                <span class="task-drag-handle" aria-hidden="true">⋮⋮</span>
                <a class="task-link" href="<?php echo $dashboard_h($card['url']); ?>">
                    <span class="task-icon"><?php echo $icon_svg($card['icon']); ?></span>
                    <span class="task-text"><strong><?php echo $dashboard_h($card['label']); ?></strong><span><?php echo $dashboard_h($card['desc']); ?></span></span>
                    <span class="task-number"><?php echo $dashboard_h($card['count']); ?><small><?php echo $dashboard_h($card['unit']); ?></small></span>
                </a>
                <?php if ($can_resolve_task) { ?>
                <form class="task-complete-form" method="post" data-task-complete-form>
                    <input type="hidden" name="csrf_token" value="<?php echo $dashboard_h($csrf_token); ?>">
                    <input type="hidden" name="action" value="resolve_dashboard_task">
                    <input type="hidden" name="task_key" value="<?php echo $dashboard_h($task_key); ?>">
                    <button class="task-complete-button" type="submit" title="오늘 알림에서 확인 처리" aria-label="<?php echo $dashboard_h($card['label'] . ' 오늘 확인 처리'); ?>"><?php echo $dashboard_h($summary_resolve_labels[$task_key]); ?></button>
                </form>
                <?php } ?>
            </div>
            <?php } ?>
            <?php if ($shown_tasks === 0) { ?>
            <div class="task-card"><span class="task-icon"><?php echo $icon_svg('report'); ?></span><span class="task-text"><strong>표시할 업무가 없습니다.</strong><span>오늘 할 일 구성을 다시 선택해 주세요.</span></span></div>
            <?php } ?>
        </div>
    </section>

    <section class="schedule-section" id="calendarSchedule">
        <div class="schedule-board">
            <article class="dash-panel schedule-card month-panel">
                <div class="dash-panel-head">
            <div>
                <h2>월간 스케줄</h2>
                <p>수업일 예외와 날짜별 운영 메모를 함께 봅니다.</p>
            </div>
            <a class="dash-btn" href="<?php echo IEUM_URL; ?>/admin/school_calendar.php?month=<?php echo urlencode($calendar_month); ?>">수업일 설정</a>
                </div>
                <div class="month-head">
                    <div class="month-nav" aria-label="달력 월 이동">
                        <a class="month-today" href="<?php echo $dashboard_h($dashboard_calendar_url($report_month)); ?>">오늘</a>
                        <a class="month-arrow" href="<?php echo $dashboard_h($dashboard_calendar_url($calendar_prev_month)); ?>" aria-label="지난달">‹</a>
                        <span class="month-picker-wrap">
                            <button class="month-current" type="button" aria-label="연월 선택" data-calendar-month-trigger><?php echo $dashboard_h($calendar_month_compact_label); ?></button>
                            <input class="month-picker-input" type="month" value="<?php echo $dashboard_h($calendar_month); ?>" aria-label="연월 선택" data-calendar-month-input data-calendar-url="<?php echo $dashboard_h(IEUM_URL . '/dashboard.php'); ?>">
                            <span class="month-picker-popover" data-calendar-month-popover hidden>
                                <select data-calendar-year aria-label="연도 선택"></select>
                                <select data-calendar-month-select aria-label="월 선택"></select>
                                <button type="button" data-calendar-month-apply>이동</button>
                            </span>
                        </span>
                        <a class="month-arrow" href="<?php echo $dashboard_h($dashboard_calendar_url($calendar_next_month)); ?>" aria-label="다음달">›</a>
                    </div>
                    <span class="month-help">메모 날짜를 눌러 수정합니다.</span>
                </div>
                <div class="month-calendar">
                    <?php foreach (array('일', '월', '화', '수', '목', '금', '토') as $weekday) { ?>
                    <div class="month-weekday"><?php echo $dashboard_h($weekday); ?></div>
                    <?php } ?>
                    <?php for ($blank = 0; $blank < $calendar_first_weekday; $blank++) { ?>
                    <div class="month-day is-empty"></div>
                    <?php } ?>
                    <?php for ($day = 1; $day <= $calendar_days_in_month; $day++) {
                        $date_value = $calendar_month . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                        $memo_row = isset($calendar_memo_rows[$date_value]) ? $calendar_memo_rows[$date_value] : array();
                        $memo_value = isset($memo_row['memo']) ? $memo_row['memo'] : '';
                        $memo_preview = $memo_value !== '' ? (function_exists('mb_substr') ? mb_substr($memo_value, 0, 4, 'UTF-8') : substr($memo_value, 0, 8)) : '';
                        $day_alert_time = isset($memo_row['alert_time']) && preg_match('/^\d{2}:\d{2}$/', $memo_row['alert_time']) ? $memo_row['alert_time'] : '09:00';
                        $day_alert_hour_24 = (int) substr($day_alert_time, 0, 2);
                        $day_alert_period = $day_alert_hour_24 >= 12 ? 'pm' : 'am';
                        $day_alert_hour = $day_alert_hour_24 >= 12 ? $day_alert_hour_24 - 12 : $day_alert_hour_24;
                        $marks = isset($calendar_marks[$date_value]) ? $calendar_marks[$date_value] : array();
                        $day_week = (int) date('w', strtotime($date_value));
                        $day_week_key = isset($calendar_weekday_keys[$day_week]) ? $calendar_weekday_keys[$day_week] : '';
                        $is_default_closed_day = $day_week_key !== '' && empty($academy_lesson_weekdays[$day_week_key]) && !$marks;
                        $day_class = $date_value === $today ? ' is-today' : '';
                        if ($date_value === $calendar_selected_date) {
                            $day_class .= ' is-selected';
                        }
                        if ($memo_value !== '') {
                            $day_class .= ' has-memo';
                        }
                        if ($is_default_closed_day) {
                            $day_class .= ' is-default-closed';
                        }
                    ?>
                    <button class="month-day<?php echo $day_class; ?>" type="button" data-date="<?php echo $dashboard_h($date_value); ?>" data-memo="<?php echo $dashboard_h($memo_value); ?>" data-alert-enabled="<?php echo !empty($memo_row['alert_enabled']) ? '1' : '0'; ?>" data-alert-offset="<?php echo isset($memo_row['alert_offset_days']) ? (int) $memo_row['alert_offset_days'] : 0; ?>" data-alert-period="<?php echo $dashboard_h($day_alert_period); ?>" data-alert-hour="<?php echo (int) $day_alert_hour; ?>">
                        <span class="month-day-num"><?php echo (int) $day; ?></span>
                        <span class="month-tags">
                            <?php foreach (array_slice($marks, 0, 2) as $mark) {
                                $mark_label = ieum_dashboard_calendar_tag_label($mark);
                                $mark_class = ieum_dashboard_calendar_tag_class($mark);
                            ?>
                            <i class="<?php echo $dashboard_h($mark_class); ?>"><?php echo $dashboard_h($mark_label); ?></i>
                            <?php } ?>
                            <?php if ($is_default_closed_day) { ?><i class="default-closed">휴무</i><?php } ?>
                            <?php if ($memo_preview !== '') { ?><i class="memo" title="<?php echo $dashboard_h($memo_value); ?>"><?php echo $dashboard_h($memo_preview); ?></i><?php } ?>
                        </span>
                    </button>
                    <?php } ?>
                    <?php for ($blank = $calendar_first_weekday + $calendar_days_in_month; $blank < 42; $blank++) { ?>
                    <div class="month-day is-empty"></div>
                    <?php } ?>
                </div>
                <form class="calendar-memo-form" method="post" action="<?php echo $dashboard_h($dashboard_calendar_url($calendar_month)); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $dashboard_h($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_dashboard_calendar_memo">
                    <input type="date" name="memo_date" value="<?php echo $dashboard_h($calendar_selected_date); ?>">
                    <input type="text" name="calendar_memo" value="<?php echo $dashboard_h(isset($calendar_memos[$calendar_selected_date]) ? $calendar_memos[$calendar_selected_date] : ''); ?>" placeholder="예: 공개수업 준비, 상담 메모">
                    <button class="dash-btn primary" type="submit">저장</button>
                    <div class="memo-alert-controls">
                        <label class="memo-alert-check"><input type="checkbox" name="alert_enabled" value="1" <?php echo !empty($selected_calendar_memo['alert_enabled']) ? 'checked' : ''; ?>> 문자</label>
                        <span>알림 시점</span>
                        <div class="memo-alert-fields">
                            <select name="alert_offset_days">
                                <?php foreach ($calendar_alert_offsets as $offset_value => $offset_label) { ?>
                                <option value="<?php echo (int) $offset_value; ?>" <?php echo ((int) (isset($selected_calendar_memo['alert_offset_days']) ? $selected_calendar_memo['alert_offset_days'] : 0) === (int) $offset_value) ? 'selected' : ''; ?>><?php echo $dashboard_h($offset_label); ?></option>
                                <?php } ?>
                            </select>
                            <select name="alert_period">
                                <option value="am" <?php echo $selected_alert_period === 'am' ? 'selected' : ''; ?>>오전</option>
                                <option value="pm" <?php echo $selected_alert_period === 'pm' ? 'selected' : ''; ?>>오후</option>
                            </select>
                            <select name="alert_hour">
                                <?php for ($hour = 0; $hour <= 11; $hour++) { ?>
                                <option value="<?php echo (int) $hour; ?>" <?php echo $selected_alert_hour === $hour ? 'selected' : ''; ?>><?php echo str_pad((string) $hour, 2, '0', STR_PAD_LEFT); ?>시</option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </form>
            </article>

            <article class="dash-panel schedule-card today-class-panel">
                <div class="today-class-head">
                    <h3>오늘 수업</h3>
                    <a href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">출석 관리</a>
                </div>
                <div class="today-class-stats" aria-label="오늘 수업 요약">
                    <span class="today-class-stat"><span>예정</span><b><?php echo number_format($today_class_expected_total); ?>명</b></span>
                    <span class="today-class-stat"><span>출석</span><b><?php echo number_format($today_class_attended_total); ?>명</b></span>
                    <span class="today-class-stat <?php echo $today_class_missing_total > 0 ? 'warn' : ''; ?>"><span>미등원</span><b><?php echo number_format($today_class_missing_total); ?>명</b></span>
                    <span class="today-class-stat"><span>현재</span><b><?php echo $dashboard_h($today_class_now_label); ?></b></span>
                </div>
                <?php if (!$dashboard_today_closed) { ?>
                <div class="timeline-table-head">
                    <span>시간</span>
                    <span>수업</span>
                    <span>상태</span>
                    <span>출석</span>
                    <span>미등원</span>
                </div>
                <?php } ?>
                <div class="timeline-list">
                    <?php if ($dashboard_today_closed) { ?>
                    <div class="empty-text today-closed-text">
                        <strong><?php echo $dashboard_h($dashboard_today_closed_title); ?></strong>
                        <span><?php echo $dashboard_h($dashboard_today_closed_desc); ?></span>
                    </div>
                    <?php } else { ?>
                    <?php foreach (array_slice($class_rows, 0, 6) as $row) {
                        $expected = (int) $row['expected_count'];
                        $attended = (int) $row['attended_count'];
                        $missing = max(0, $expected - $attended);
                        $status = $class_timeline_status($row['start_time']);
                    ?>
                    <a class="timeline-row is-<?php echo $dashboard_h($status['class']); ?> <?php echo $missing > 0 ? 'has-missing' : 'is-clear'; ?>" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php?class_time_id=<?php echo (int) $row['class_time_id']; ?>#classAttendance">
                        <span class="timeline-time"><?php echo $dashboard_h(substr((string) $row['start_time'], 0, 5)); ?></span>
                        <span class="timeline-body">
                            <strong><?php echo $dashboard_h($row['class_name']); ?></strong>
                        </span>
                        <span class="timeline-status <?php echo $dashboard_h($status['class']); ?>"><?php echo $dashboard_h($status['label']); ?></span>
                        <span class="timeline-count"><b><?php echo number_format($attended); ?>/<?php echo number_format($expected); ?></b><span class="sr-only">출석</span></span>
                        <span class="timeline-missing <?php echo $missing > 0 ? 'warn' : 'ok'; ?>"><b><?php echo number_format($missing); ?>명</b><span class="sr-only">미등원</span></span>
                    </a>
                    <?php } ?>
                    <?php if (!$class_rows) { ?><div class="empty-text">등록된 수업 부가 없습니다.</div><?php } ?>
                    <?php } ?>
                </div>
                <div class="quick-attendance-panel">
                <?php if ($dashboard_today_closed) { ?>
                <div class="quick-attendance-closed"><?php echo $dashboard_h($dashboard_today_closed_title); ?>에는 빠른 출석 처리를 사용할 수 없습니다.</div>
                <?php } else { ?>
                <form class="quick-attendance-form" method="post" action="<?php echo $dashboard_h($dashboard_calendar_url($calendar_month)); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $dashboard_h($csrf_token); ?>">
                    <input type="hidden" name="action" value="bulk_attendance_class">
                    <strong class="quick-attendance-title">출석 처리</strong>
                    <select name="quick_class_time_id" aria-label="부 선택">
                        <?php foreach ($quick_attendance_class_rows as $quick_class) {
                            $quick_class_id = (int) $quick_class['class_time_id'];
                            $quick_label = trim(substr((string) $quick_class['start_time'], 0, 5) . ' ' . (string) $quick_class['class_name']);
                            if ($quick_label === '') {
                                $quick_label = '부 선택';
                            }
                        ?>
                        <option value="<?php echo $quick_class_id; ?>" data-missing="<?php echo (int) $quick_class['missing_count']; ?>" data-label="<?php echo $dashboard_h($quick_label); ?>" <?php echo $quick_attendance_default_class_id === $quick_class_id ? 'selected' : ''; ?>><?php echo $dashboard_h($quick_label); ?></option>
                        <?php } ?>
                    </select>
                    <span class="quick-attendance-target">미등원 전체 출석 처리 <b><?php
                        $quick_default_missing = 0;
                        foreach ($quick_attendance_class_rows as $quick_class) {
                            if ((int) $quick_class['class_time_id'] === $quick_attendance_default_class_id) {
                                $quick_default_missing = (int) $quick_class['missing_count'];
                                break;
                            }
                        }
                        echo number_format($quick_default_missing);
                    ?>명</b></span>
                    <div class="quick-attendance-options">
                        <label><input type="radio" name="attendance_alert_mode" value="send" checked> 알림 발송</label>
                        <label><input type="radio" name="attendance_alert_mode" value="skip"> 미발송</label>
                    </div>
                    <button class="dash-btn primary" type="submit" <?php echo $quick_attendance_class_rows ? '' : 'disabled'; ?>>확인</button>
                </form>
                <?php } ?>
            </div>
            </article>
        </div>
    </section>

    <details class="secondary-drawer">
        <summary>
            <span><strong>보조 정보</strong><small>월말 마감, 업무 바로가기, 최근 처리, 지원센터</small></span>
            <span class="secondary-meta">월말 <?php echo number_format($print_ready); ?>/<?php echo number_format($print_total); ?>건</span>
        </summary>
        <div class="secondary-body">
    <section class="dash-panel">
        <div class="dash-panel-head">
            <div>
                <h2>이번 달 마감</h2>
                <p>인성/체력 리포트는 입력 완료 대상을 먼저 확인하고, 준비된 원생만 인쇄하거나 링크 문자로 발송합니다.</p>
            </div>
            <div class="dash-actions">
                <a class="dash-btn" href="<?php echo IEUM_URL; ?>/admin/monthly_close.php?month=<?php echo urlencode($report_month); ?>">월말 관리</a>
                <a class="dash-btn dark" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo urlencode($report_month); ?>">인성 마감</a>
                <a class="dash-btn dark" href="<?php echo IEUM_URL; ?>/admin/fitness_reports.php?month=<?php echo urlencode($report_month); ?>">체력 마감</a>
            </div>
        </div>
        <div class="progress-list">
            <a class="progress-card" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo urlencode($report_month); ?>">
                <strong>인성 입력</strong>
                <b><?php echo number_format($character_ready); ?>/<?php echo number_format($character_enabled); ?>명</b>
                <div class="progress-track"><div class="progress-fill" style="width:<?php echo $character_enabled > 0 ? min(100, round($character_ready / $character_enabled * 100)) : 0; ?>%"></div></div>
            </a>
            <a class="progress-card" href="<?php echo IEUM_URL; ?>/admin/fitness_reports.php?month=<?php echo urlencode($report_month); ?>">
                <strong>체력 측정</strong>
                <b><?php echo number_format($fitness_ready); ?>/<?php echo number_format($fitness_enabled); ?>명</b>
                <div class="progress-track"><div class="progress-fill" style="width:<?php echo $fitness_enabled > 0 ? min(100, round($fitness_ready / $fitness_enabled * 100)) : 0; ?>%"></div></div>
            </a>
            <a class="progress-card" href="<?php echo IEUM_URL; ?>/admin/monthly_close.php?month=<?php echo urlencode($report_month); ?>">
                <strong>발송/인쇄 준비</strong>
                <b><?php echo number_format($print_ready); ?>/<?php echo number_format($print_total); ?>건</b>
                <div class="progress-track"><div class="progress-fill" style="width:<?php echo $print_total > 0 ? min(100, round($print_ready / $print_total * 100)) : 0; ?>%"></div></div>
            </a>
        </div>
    </section>

    <section class="lower-grid">
        <article class="dash-panel">
            <div class="dash-panel-head">
                <div>
                    <h2>업무 바로가기</h2>
                    <p>왼쪽 메뉴에서 별표한 항목이 여기에 모입니다. 최대 6개까지 선택할 수 있습니다.<span class="shortcut-favorite-status" data-shortcut-status aria-live="polite"></span></p>
                </div>
            </div>
            <div class="utility-links" data-shortcut-list>
                <?php foreach ($quick_shortcuts as $shortcut_key => $shortcut) {
                    $shortcut_badge = isset($shortcut_badges[$shortcut_key]) ? $shortcut_badges[$shortcut_key] : '열기';
                ?>
                <a class="utility-link" href="<?php echo $dashboard_h($shortcut['url']); ?>" data-shortcut-key="<?php echo $dashboard_h($shortcut_key); ?>">
                    <strong><?php echo $dashboard_h($shortcut['label']); ?></strong>
                    <span><?php echo $dashboard_h($shortcut['desc']); ?></span>
                    <b><?php echo $dashboard_h($shortcut_badge); ?></b>
                </a>
                <?php } ?>
            </div>
        </article>

        <article class="dash-panel">
            <div class="dash-panel-head">
                <div>
                    <h2>최근 처리 내역</h2>
                    <p>방금 처리된 등원과 차량 메모를 확인합니다.</p>
                </div>
                <a class="dash-btn" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php#recentAttendance">전체 보기</a>
            </div>
            <div class="recent-actions">
                <?php foreach (array_slice($recent_rows, 0, 5) as $row) { ?>
                <a class="recent-action" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php#recentAttendance">
                    <span><strong><?php echo $dashboard_h($row['student_name']); ?></strong><span><?php echo $dashboard_h(substr($row['checked_at'], 11, 5)); ?> · 문자 <?php echo $dashboard_h($row['sms_status'] ? $row['sms_status'] : '-'); ?></span></span>
                    <b class="pill"><?php echo $dashboard_h($row['student_code']); ?></b>
                </a>
                <?php } ?>
                <?php foreach (array_slice($vehicle_note_rows, 0, 3) as $row) { ?>
                <a class="recent-action" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php">
                    <span><strong><?php echo $dashboard_h($row['student_name']); ?> 차량 메모</strong><span><?php echo $dashboard_h($row['note'] ? $row['note'] : $row['status']); ?></span></span>
                    <b class="pill">차량</b>
                </a>
                <?php } ?>
                <?php if (!$recent_rows && !$vehicle_note_rows) { ?>
                <div class="empty-text">오늘 처리 내역이 아직 없습니다.</div>
                <?php } ?>
            </div>
        </article>

        <article class="dash-panel">
            <div class="support-card">
                <div class="dash-panel-head">
                    <div>
                        <h2>지원센터</h2>
                        <p>막히는 부분은 바로 확인하고 문의합니다.</p>
                    </div>
                </div>
                <div class="support-links">
                    <a class="support-link" href="<?php echo IEUM_URL; ?>/admin/support.php?topic=ai">AI 챗봇 안내 <span>질문</span></a>
                    <a class="support-link" href="<?php echo IEUM_URL; ?>/admin/support.php?topic=qna">Q&A <span>보기</span></a>
                    <a class="support-link" href="<?php echo IEUM_URL; ?>/admin/support.php?topic=faq">자주하는 질문 <span>보기</span></a>
                    <a class="support-link" href="<?php echo IEUM_URL; ?>/admin/support.php?topic=contact">문의하기 <span>열기</span></a>
                    <a class="support-link" href="<?php echo IEUM_URL; ?>/admin/sms_devices.php">문자 발송폰 <span>설치</span></a>
                    <a class="support-link" href="<?php echo IEUM_URL; ?>/admin/contacts.php">알림 담당자 <span>설정</span></a>
                </div>
                <p class="support-note">필요한 길은 이 보조 정보와 왼쪽 메뉴에만 남겨 화면 흐름을 단순하게 유지합니다.</p>
            </div>
        </article>
    </section>
        </div>
    </details>
</main>
<footer class="dashboard-footer">
    <div class="dashboard-footer-inner">
        <div class="dashboard-footer-brand"><?php echo $dashboard_h(isset($academy['academy_name']) ? $academy['academy_name'] : '아이이음'); ?></div>
        <div class="dashboard-footer-links">
            <span>운영상담 · 1544-8660</span>
            <span>일반상담 · 1544-8667</span>
            <span>E-mail · help@ieum.local</span>
            <a href="<?php echo IEUM_URL; ?>/admin/contacts.php">제휴문의</a>
        </div>
    </div>
</footer>
<?php
$shortcut_js_catalog = array();
foreach ($dashboard_shortcut_catalog as $shortcut_key => $shortcut_item) {
    $shortcut_js_catalog[$shortcut_key] = array(
        'label' => isset($shortcut_item['label']) ? $shortcut_item['label'] : $shortcut_key,
        'desc' => isset($shortcut_item['desc']) ? $shortcut_item['desc'] : '',
        'url' => isset($shortcut_item['url']) ? $shortcut_item['url'] : '#',
        'badge' => isset($shortcut_badges[$shortcut_key]) ? $shortcut_badges[$shortcut_key] : '열기',
    );
}
?>
<script>
(function(){
    var shortcutCatalog = <?php echo json_encode($shortcut_js_catalog, JSON_UNESCAPED_UNICODE); ?>;
    var currentShortcutKeys = <?php echo json_encode(array_values($shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var defaultShortcutKeys = <?php echo json_encode(ieum_dashboard_default_shortcut_keys(), JSON_UNESCAPED_UNICODE); ?>;
    var shortcutMax = 6;
    var shortcutCsrfToken = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
    var menuIconPaths = {
        home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/><path d="M9.5 20v-6h5v6"/>',
        school: '<path d="M4 10 12 6l8 4-8 4-8-4Z"/><path d="M6.5 12v4.5c1.5 1.2 3.3 1.8 5.5 1.8s4-.6 5.5-1.8V12"/>',
        users: '<path d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4"/><path d="M12 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19 18c0-1.6-1-3-2.4-3.6"/><path d="M17 7.2a2.4 2.4 0 0 1 0 4.6"/>',
        card: '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/>',
        leaf: '<path d="M5 19c9 0 14-5 14-14-9 0-14 5-14 14Z"/><path d="M5 19 15 9"/>',
        activity: '<path d="M4 13h4l2-7 4 12 2-5h4"/>',
        bus: '<rect x="4" y="5" width="16" height="12" rx="2"/><path d="M7 17v2"/><path d="M17 17v2"/><path d="M4 10h16"/><path d="M8 14h.01"/><path d="M16 14h.01"/>',
        settings: '<circle cx="12" cy="12" r="3"/><path d="M12 3v3"/><path d="M12 18v3"/><path d="M3 12h3"/><path d="M18 12h3"/><path d="m5.6 5.6 2.1 2.1"/><path d="m16.3 16.3 2.1 2.1"/><path d="m18.4 5.6-2.1 2.1"/><path d="m7.7 16.3-2.1 2.1"/>',
        chart: '<path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-3"/>',
        check: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="m8 12 2.7 2.7L16 9.4"/>',
        trend: '<path d="M4 17 9 12l3 3 7-8"/><path d="M14 7h5v5"/>',
        upload: '<path d="M12 16V5"/><path d="m8 9 4-4 4 4"/><path d="M5 19h14"/>',
        list: '<path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
        clock: '<circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>',
        medal: '<circle cx="12" cy="9" r="4"/><path d="M9.5 13 8 21l4-2 4 2-1.5-8"/>',
        doc: '<path d="M6 3.5h8l4 4V20H6Z"/><path d="M14 3.5V8h4"/><path d="M9 13h6"/><path d="M9 16h4"/>',
        phone: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
        mail: '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="m4 8 8 6 8-6"/>',
        pen: '<path d="m4 20 4.2-1 10-10a2.1 2.1 0 0 0-3-3l-10 10L4 20Z"/><path d="m14 6 4 4"/>',
        target: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v3"/><path d="M22 12h-3"/><path d="M12 22v-3"/><path d="M2 12h3"/>',
        map: '<path d="M9 18 3 21V6l6-3 6 3 6-3v15l-6 3-6-3Z"/><path d="M9 3v15"/><path d="M15 6v15"/>',
        pin: '<path d="M12 21s6-5.2 6-11a6 6 0 0 0-12 0c0 5.8 6 11 6 11Z"/><circle cx="12" cy="10" r="2"/>',
        tag: '<path d="M20 12 12 20 4 12V4h8l8 8Z"/><path d="M8 8h.01"/>',
        calendar: '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M4 10h16"/>',
        bell: '<path d="M18 16v-5a6 6 0 0 0-12 0v5l-2 2h16l-2-2Z"/><path d="M10 20h4"/>'
    };
    var menuIconMap = {
        '🏠 메인': ['home', '메인'],
        '🏫 학원 운영': ['school', '학원 운영'],
        '👥 원생/출석': ['users', '원생/출석'],
        '💳 수련비/문자': ['card', '수련비/문자'],
        '🌱 인성 리포트': ['leaf', '인성 리포트'],
        '💪 체력 리포트': ['activity', '체력 리포트'],
        '🚌 차량': ['bus', '차량'],
        '⚙️ 학원 설정': ['settings', '학원 설정'],
        '📊 운영 지표': ['chart', '운영 지표'],
        '✅ 월말 루틴': ['check', '월말 루틴'],
        '📈 원생 리포트': ['trend', '원생 리포트'],
        '👥 원생 관리': ['users', '원생 관리'],
        '📥 엑셀 가져오기': ['upload', '엑셀 가져오기'],
        '🧾 부별 명단': ['list', '부별 명단'],
        '🕒 오늘 출석': ['clock', '오늘 출석'],
        '🎖️ 승급 대상': ['medal', '승급 대상'],
        '🥋 승품/단 대상': ['medal', '승품/단 대상'],
        '📨 심사 안내문': ['mail', '심사 안내문'],
        '🎗️ 준비 띠': ['tag', '준비 띠'],
        '📜 승급증 인쇄': ['doc', '승급증 인쇄'],
        '📱 앱 출석기': ['phone', '앱 출석기'],
        '💰 수련비 납부': ['card', '수련비 납부'],
        '👨‍👩‍👧 형제/자매 청구': ['users', '형제/자매 청구'],
        '💬 문자 발송현황': ['mail', '문자 발송현황'],
        '📲 문자 발송폰': ['phone', '문자 발송폰'],
        '✍️ 인성 입력': ['pen', '인성 입력'],
        '🏡 아이잘해 미션': ['target', '아이잘해 미션'],
        '📄 월간 인성': ['doc', '월간 인성'],
        '🌳 장기 성장': ['trend', '장기 성장'],
        '✍️ 체력 입력': ['pen', '체력 입력'],
        '📄 체력 리포트': ['doc', '체력 리포트'],
        '📏 체력 기준표': ['list', '체력 기준표'],
        '✅ 오늘 탑승': ['check', '오늘 탑승'],
        '🧭 차량 배정': ['map', '차량 배정'],
        '📍 운행 관리': ['pin', '운행 관리'],
        '🖨️ 차량 일지': ['doc', '차량 일지'],
        '⚙️ 차량/노선 설정': ['settings', '차량/노선 설정'],
        '🏷️ 프로그램 설정': ['tag', '프로그램 설정'],
        '⏰ 수업 시간표': ['clock', '수업 시간표'],
        '📅 수업일/휴관일': ['calendar', '수업일/휴관일'],
        '💰 수련비 정책': ['card', '수련비 정책'],
        '🎖️ 승급 설정': ['medal', '승급 설정'],
        '🥋 승급 미션': ['target', '승급 미션'],
        '💬 문자 템플릿': ['mail', '문자 템플릿'],
        '🔔 알림 담당자': ['bell', '알림 담당자']
    };
    var applyMenuIcon = function(node) {
        var text = node.textContent.trim();
        var item = menuIconMap[text];
        if (!item) {
            return;
        }
        var iconName = item[0];
        var labelText = item[1];
        var icon = document.createElement('span');
        icon.className = 'side-nav-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<svg viewBox="0 0 24 24">' + (menuIconPaths[iconName] || menuIconPaths.doc) + '</svg>';
        var label = document.createElement('span');
        label.className = 'side-nav-label';
        label.textContent = labelText;
        node.textContent = '';
        node.appendChild(icon);
        node.appendChild(label);
    };
    document.querySelectorAll('.ieum-dashboard-page .side-main-link span,.ieum-dashboard-page .side-menu summary span,.ieum-dashboard-page .side-sub a').forEach(applyMenuIcon);
    document.querySelectorAll('.ieum-dashboard-page .side-main-link,.ieum-dashboard-page .side-menu>summary,.ieum-dashboard-page .side-sub a').forEach(function(node){
        node.style.letterSpacing = '0';
    });
    var shortcutList = document.querySelector('[data-shortcut-list]');
    var shortcutStatus = document.querySelector('[data-shortcut-status]');
    var shortcutButtons = [];
    var shortcutToast = document.createElement('span');
    var shortcutToastTimer = null;
    shortcutToast.className = 'side-favorite-toast';
    shortcutToast.innerHTML = '<span>최대 6개까지 선택할 수 있습니다.</span><span>보조정보-업무 바로가기에서 확인하세요.</span>';
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
    Object.keys(shortcutCatalog).forEach(function(key) {
        var path = shortcutPath(shortcutCatalog[key].url);
        if (path && !shortcutByPath[path]) {
            shortcutByPath[path] = key;
        }
    });
    var setShortcutStatus = function(text, state) {
        if (!shortcutStatus) {
            return;
        }
        shortcutStatus.classList.remove('is-error');
        if (state) {
            shortcutStatus.classList.add(state);
        }
        shortcutStatus.textContent = text || '';
        if (text) {
            window.clearTimeout(setShortcutStatus.timer);
            setShortcutStatus.timer = window.setTimeout(function(){
                shortcutStatus.textContent = '';
                shortcutStatus.classList.remove('is-error');
            }, 2200);
        }
    };
    var showShortcutToast = function(button, text) {
        if (!shortcutToast) {
            return;
        }
        shortcutToast.innerHTML = text || '<span>최대 6개까지 선택할 수 있습니다.</span><span>보조정보-업무 바로가기에서 확인하세요.</span>';
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
        shortcutToastTimer = window.setTimeout(function(){
            shortcutToast.classList.remove('is-show');
        }, 1900);
    };
    var renderShortcutList = function() {
        if (!shortcutList) {
            return;
        }
        shortcutList.innerHTML = '';
        currentShortcutKeys.forEach(function(key) {
            var item = shortcutCatalog[key];
            if (!item) {
                return;
            }
            var link = document.createElement('a');
            link.className = 'utility-link';
            link.href = item.url;
            link.setAttribute('data-shortcut-key', key);
            var title = document.createElement('strong');
            title.textContent = item.label;
            var desc = document.createElement('span');
            desc.textContent = item.desc;
            var badge = document.createElement('b');
            badge.textContent = item.badge || '열기';
            link.appendChild(title);
            link.appendChild(desc);
            link.appendChild(badge);
            shortcutList.appendChild(link);
        });
    };
    var updateShortcutButtons = function() {
        shortcutButtons.forEach(function(button) {
            var key = button.getAttribute('data-shortcut-key') || '';
            var active = currentShortcutKeys.indexOf(key) !== -1;
            button.classList.toggle('is-active', active);
            button.textContent = active ? '★' : '☆';
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.setAttribute('title', active ? '업무 바로가기에서 제거' : '업무 바로가기에 추가');
            button.setAttribute('aria-label', (shortcutCatalog[key] ? shortcutCatalog[key].label : '메뉴') + (active ? ' 즐겨찾기 제거' : ' 즐겨찾기 추가'));
        });
    };
    var saveShortcutKeys = function(keys) {
        if (!window.fetch || !window.FormData) {
            return;
        }
        var formData = new FormData();
        formData.append('csrf_token', shortcutCsrfToken);
        formData.append('action', 'save_dashboard_shortcuts');
        keys.forEach(function(key) {
            formData.append('shortcut_keys[]', key);
        });
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('shortcut save failed');
            }
            setShortcutStatus('저장됨', '');
        }).catch(function() {
            setShortcutStatus('저장 실패', 'is-error');
        });
    };
    var toggleShortcut = function(key, button) {
        if (!shortcutCatalog[key]) {
            return;
        }
        var nextKeys = currentShortcutKeys.slice();
        var index = nextKeys.indexOf(key);
        if (index === -1) {
            if (nextKeys.length >= shortcutMax) {
                setShortcutStatus('최대 6개', 'is-error');
                showShortcutToast(button, '');
                return;
            }
            nextKeys.push(key);
        } else {
            nextKeys.splice(index, 1);
        }
        if (!nextKeys.length) {
            nextKeys = normalizeShortcutKeys(defaultShortcutKeys);
            setShortcutStatus('기본값 복구', '');
        }
        currentShortcutKeys = normalizeShortcutKeys(nextKeys);
        renderShortcutList();
        updateShortcutButtons();
        if (button) {
            button.classList.add('is-pulse');
            window.setTimeout(function(){
                button.classList.remove('is-pulse');
            }, 170);
        }
        saveShortcutKeys(currentShortcutKeys);
    };
    document.querySelectorAll('.ieum-dashboard-page .side-sub a').forEach(function(link) {
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
    renderShortcutList();
    updateShortcutButtons();
    var todayTaskGrid = document.querySelector('[data-today-task-grid]');
    var taskOrderStatus = document.querySelector('[data-task-order-status]');
    var taskSettingsForm = document.querySelector('#todayTaskSettings form');
    var taskSettingsGrid = document.querySelector('[data-task-settings-grid]');
    var draggedTaskCard = null;
    var taskDragMoved = false;
    var suppressTaskClick = false;
    var taskOrderStatusTimer = null;
    var getTaskOrder = function(){
        if (!todayTaskGrid) {
            return [];
        }
        return Array.prototype.slice.call(todayTaskGrid.querySelectorAll('.task-card[data-task-key]')).map(function(card){
            return card.getAttribute('data-task-key') || '';
        }).filter(Boolean);
    };
    var setTaskOrderStatus = function(text, state) {
        if (!taskOrderStatus) {
            return;
        }
        taskOrderStatus.classList.remove('is-saving', 'is-saved', 'is-error');
        if (state) {
            taskOrderStatus.classList.add(state);
        }
        taskOrderStatus.textContent = text;
        window.clearTimeout(taskOrderStatusTimer);
        if (state === 'is-saved') {
            taskOrderStatusTimer = window.setTimeout(function(){
                taskOrderStatus.classList.remove('is-saved');
                taskOrderStatus.textContent = '카드를 드래그해 순서를 바꿀 수 있습니다.';
            }, 2400);
        }
    };
    var syncTaskSettingsOrder = function(order) {
        if (!taskSettingsGrid || !order.length) {
            return;
        }
        var options = Array.prototype.slice.call(taskSettingsGrid.querySelectorAll('.settings-option[data-task-key]'));
        var optionByKey = {};
        options.forEach(function(option){
            optionByKey[option.getAttribute('data-task-key') || ''] = option;
        });
        order.forEach(function(key){
            if (optionByKey[key]) {
                taskSettingsGrid.appendChild(optionByKey[key]);
            }
        });
        options.forEach(function(option){
            if (!order.includes(option.getAttribute('data-task-key') || '')) {
                taskSettingsGrid.appendChild(option);
            }
        });
    };
    var saveTaskOrder = function() {
        var order = getTaskOrder();
        if (!order.length || !taskSettingsForm) {
            return;
        }
        syncTaskSettingsOrder(order);
        if (!window.fetch || !window.FormData) {
            taskSettingsForm.submit();
            return;
        }
        var formData = new FormData();
        var tokenInput = taskSettingsForm.querySelector('input[name="csrf_token"]');
        formData.append('csrf_token', tokenInput ? tokenInput.value : '');
        formData.append('action', 'save_dashboard_today_tasks');
        order.forEach(function(key){
            formData.append('task_keys[]', key);
        });
        setTaskOrderStatus('순서 저장 중...', 'is-saving');
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(function(response){
            if (!response.ok) {
                throw new Error('save failed');
            }
            setTaskOrderStatus('순서가 저장되었습니다.', 'is-saved');
        }).catch(function(){
            setTaskOrderStatus('저장하지 못했습니다. 설정에서 다시 저장해 주세요.', 'is-error');
        });
    };
    if (todayTaskGrid) {
        var taskDragStartX = 0;
        var taskDragStartY = 0;
        var taskDragOffsetX = 0;
        var taskDragOffsetY = 0;
        var taskDragActive = false;
        var taskDragGhost = null;
        var getTaskCardRectMap = function() {
            var map = {};
            Array.prototype.slice.call(todayTaskGrid.querySelectorAll('.task-card[data-task-key]')).forEach(function(card){
                var key = card.getAttribute('data-task-key') || '';
                if (!key) {
                    return;
                }
                var rect = card.getBoundingClientRect();
                map[key] = { left: rect.left, top: rect.top };
            });
            return map;
        };
        var animateTaskReorder = function(beforeRects) {
            Array.prototype.slice.call(todayTaskGrid.querySelectorAll('.task-card[data-task-key]')).forEach(function(card){
                if (card === draggedTaskCard) {
                    return;
                }
                var key = card.getAttribute('data-task-key') || '';
                var before = beforeRects[key];
                if (!before) {
                    return;
                }
                var after = card.getBoundingClientRect();
                var dx = before.left - after.left;
                var dy = before.top - after.top;
                if (Math.abs(dx) < 1 && Math.abs(dy) < 1) {
                    return;
                }
                card.style.transition = 'none';
                card.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
                card.offsetWidth;
                window.requestAnimationFrame(function(){
                    card.style.transition = 'transform .18s ease';
                    card.style.transform = '';
                    window.setTimeout(function(){
                        card.style.transition = '';
                    }, 210);
                });
            });
        };
        var updateTaskGhost = function(clientX, clientY) {
            if (!taskDragGhost) {
                return;
            }
            taskDragGhost.style.left = (clientX - taskDragOffsetX) + 'px';
            taskDragGhost.style.top = (clientY - taskDragOffsetY) + 'px';
        };
        var createTaskGhost = function(clientX, clientY) {
            if (!draggedTaskCard || taskDragGhost) {
                return;
            }
            var rect = draggedTaskCard.getBoundingClientRect();
            taskDragGhost = draggedTaskCard.cloneNode(true);
            taskDragGhost.classList.remove('is-dragging');
            taskDragGhost.classList.add('task-drag-ghost');
            taskDragGhost.removeAttribute('href');
            taskDragGhost.setAttribute('aria-hidden', 'true');
            taskDragGhost.style.width = rect.width + 'px';
            taskDragGhost.style.height = rect.height + 'px';
            document.body.appendChild(taskDragGhost);
            updateTaskGhost(clientX, clientY);
        };
        var removeTaskGhost = function() {
            if (taskDragGhost && taskDragGhost.parentNode) {
                taskDragGhost.parentNode.removeChild(taskDragGhost);
            }
            taskDragGhost = null;
        };
        var startTaskDrag = function(card, event) {
            if (typeof event.button === 'number' && event.button > 0) {
                return;
            }
            var rect = card.getBoundingClientRect();
            draggedTaskCard = card;
            taskDragMoved = false;
            taskDragActive = false;
            taskDragStartX = event.clientX;
            taskDragStartY = event.clientY;
            taskDragOffsetX = event.clientX - rect.left;
            taskDragOffsetY = event.clientY - rect.top;
        };
        var moveTaskCardAt = function(clientX, clientY) {
            if (!draggedTaskCard) {
                return;
            }
            var target = document.elementFromPoint(clientX, clientY);
            var overCard = target ? target.closest('[data-today-task-grid] .task-card[data-task-key]') : null;
            if (!overCard || overCard === draggedTaskCard) {
                return;
            }
            var beforeRects = getTaskCardRectMap();
            var rect = overCard.getBoundingClientRect();
            var insertAfter = clientY > rect.top + rect.height / 2 || clientX > rect.left + rect.width / 2;
            todayTaskGrid.insertBefore(draggedTaskCard, insertAfter ? overCard.nextSibling : overCard);
            animateTaskReorder(beforeRects);
            taskDragMoved = true;
        };
        var handleTaskDragMove = function(event) {
            if (!draggedTaskCard) {
                return;
            }
            var dx = Math.abs(event.clientX - taskDragStartX);
            var dy = Math.abs(event.clientY - taskDragStartY);
            if (!taskDragActive && dx + dy < 7) {
                return;
            }
            if (!taskDragActive) {
                taskDragActive = true;
                suppressTaskClick = true;
                draggedTaskCard.classList.add('is-dragging');
                document.body.classList.add('is-task-dragging');
                createTaskGhost(event.clientX, event.clientY);
            }
            event.preventDefault();
            updateTaskGhost(event.clientX, event.clientY);
            moveTaskCardAt(event.clientX, event.clientY);
        };
        var finishTaskDrag = function() {
            if (!draggedTaskCard) {
                return;
            }
            draggedTaskCard.classList.remove('is-dragging');
            document.body.classList.remove('is-task-dragging');
            removeTaskGhost();
            if (taskDragMoved) {
                saveTaskOrder();
            }
            draggedTaskCard = null;
            taskDragActive = false;
            taskDragMoved = false;
            window.setTimeout(function(){
                suppressTaskClick = false;
            }, 140);
        };
        Array.prototype.slice.call(todayTaskGrid.querySelectorAll('.task-card[data-task-key]')).forEach(function(card){
            card.draggable = false;
            card.setAttribute('draggable', 'false');
            card.addEventListener('dragstart', function(event){
                event.preventDefault();
            });
            card.addEventListener('pointerdown', function(event){
                startTaskDrag(card, event);
            });
            card.addEventListener('mousedown', function(event){
                startTaskDrag(card, event);
            });
            card.addEventListener('click', function(event){
                if (suppressTaskClick) {
                    event.preventDefault();
                }
            });
        });
        document.addEventListener('pointermove', handleTaskDragMove, { passive: false });
        document.addEventListener('mousemove', handleTaskDragMove, { passive: false });
        document.addEventListener('pointerup', finishTaskDrag);
        document.addEventListener('mouseup', finishTaskDrag);
        syncTaskSettingsOrder(getTaskOrder());
    }
    var monthPickerTrigger = document.querySelector('[data-calendar-month-trigger]');
    var monthPickerInput = document.querySelector('[data-calendar-month-input]');
    var monthPickerPopover = document.querySelector('[data-calendar-month-popover]');
    var monthYearSelect = document.querySelector('[data-calendar-year]');
    var monthSelect = document.querySelector('[data-calendar-month-select]');
    var monthApplyButton = document.querySelector('[data-calendar-month-apply]');
    if (monthPickerTrigger && monthPickerInput && monthPickerPopover && monthYearSelect && monthSelect && monthApplyButton) {
        var currentMonthValue = monthPickerInput.value || '';
        var currentYear = /^\d{4}-\d{2}$/.test(currentMonthValue) ? parseInt(currentMonthValue.slice(0, 4), 10) : new Date().getFullYear();
        var currentMonth = /^\d{4}-\d{2}$/.test(currentMonthValue) ? parseInt(currentMonthValue.slice(5, 7), 10) : (new Date().getMonth() + 1);
        var goCalendarMonth = function(selectedMonth){
            if (!/^\d{4}-\d{2}$/.test(selectedMonth)) {
                return;
            }
            var targetUrl = new URL(monthPickerInput.getAttribute('data-calendar-url') || window.location.pathname, window.location.href);
            targetUrl.searchParams.set('calendar_month', selectedMonth);
            targetUrl.hash = 'calendarSchedule';
            window.location.href = targetUrl.toString();
        };
        for (var year = currentYear - 5; year <= currentYear + 5; year++) {
            var yearOption = document.createElement('option');
            yearOption.value = String(year);
            yearOption.textContent = String(year) + '년';
            yearOption.selected = year === currentYear;
            monthYearSelect.appendChild(yearOption);
        }
        for (var month = 1; month <= 12; month++) {
            var monthOption = document.createElement('option');
            monthOption.value = String(month).padStart(2, '0');
            monthOption.textContent = String(month) + '월';
            monthOption.selected = month === currentMonth;
            monthSelect.appendChild(monthOption);
        }
        monthPickerTrigger.setAttribute('aria-expanded', 'false');
        monthPickerTrigger.addEventListener('click', function(event){
            event.preventDefault();
            var willOpen = monthPickerPopover.hasAttribute('hidden');
            monthPickerPopover.toggleAttribute('hidden', !willOpen);
            monthPickerTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) {
                monthYearSelect.focus();
            }
        });
        monthApplyButton.addEventListener('click', function(){
            goCalendarMonth(monthYearSelect.value + '-' + monthSelect.value);
        });
        monthPickerInput.addEventListener('change', function(){
            goCalendarMonth(monthPickerInput.value || '');
        });
        document.addEventListener('click', function(event){
            if (!monthPickerPopover.hasAttribute('hidden') && !event.target.closest('.month-picker-wrap')) {
                monthPickerPopover.setAttribute('hidden', '');
                monthPickerTrigger.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function(event){
            if (event.key === 'Escape' && !monthPickerPopover.hasAttribute('hidden')) {
                monthPickerPopover.setAttribute('hidden', '');
                monthPickerTrigger.setAttribute('aria-expanded', 'false');
                monthPickerTrigger.focus();
            }
        });
    }
    var memoDateInput = document.querySelector('.calendar-memo-form input[name="memo_date"]');
    var memoTextInput = document.querySelector('.calendar-memo-form input[name="calendar_memo"]');
    var memoAlertInput = document.querySelector('.calendar-memo-form input[name="alert_enabled"]');
    var memoAlertOffset = document.querySelector('.calendar-memo-form select[name="alert_offset_days"]');
    var memoAlertPeriod = document.querySelector('.calendar-memo-form select[name="alert_period"]');
    var memoAlertHour = document.querySelector('.calendar-memo-form select[name="alert_hour"]');
    var memoForm = document.querySelector('.calendar-memo-form');
    var memoSyncTimer = null;
    document.querySelectorAll('.month-day[data-date]').forEach(function(button){
        button.addEventListener('click', function(){
            document.querySelectorAll('.month-day.is-selected').forEach(function(day){
                day.classList.remove('is-selected');
            });
            button.classList.add('is-selected');
            if (memoForm) {
                memoForm.classList.add('is-syncing');
                if (memoSyncTimer) {
                    window.clearTimeout(memoSyncTimer);
                }
                memoSyncTimer = window.setTimeout(function(){
                    memoForm.classList.remove('is-syncing');
                }, 650);
            }
            if (memoDateInput) {
                memoDateInput.value = button.getAttribute('data-date') || '';
            }
            if (memoTextInput) {
                memoTextInput.value = button.getAttribute('data-memo') || '';
                memoTextInput.focus();
            }
            if (memoAlertInput) {
                memoAlertInput.checked = button.getAttribute('data-alert-enabled') === '1';
            }
            if (memoAlertOffset) {
                memoAlertOffset.value = button.getAttribute('data-alert-offset') || '0';
            }
            if (memoAlertPeriod) {
                memoAlertPeriod.value = button.getAttribute('data-alert-period') || 'am';
            }
            if (memoAlertHour) {
                memoAlertHour.value = button.getAttribute('data-alert-hour') || '9';
            }
        });
    });
    var quickAttendanceForm = document.querySelector('.quick-attendance-form');
    if (quickAttendanceForm) {
        var quickClassSelect = quickAttendanceForm.querySelector('select[name="quick_class_time_id"]');
        var quickTarget = quickAttendanceForm.querySelector('.quick-attendance-target b');
        var updateQuickAttendanceTarget = function(){
            if (!quickClassSelect || !quickTarget) {
                return;
            }
            var option = quickClassSelect.options[quickClassSelect.selectedIndex];
            var missing = option ? parseInt(option.getAttribute('data-missing') || '0', 10) : 0;
            quickTarget.textContent = new Intl.NumberFormat('ko-KR').format(Math.max(0, missing)) + '명';
        };
        if (quickClassSelect) {
            quickClassSelect.addEventListener('change', updateQuickAttendanceTarget);
            updateQuickAttendanceTarget();
        }
        quickAttendanceForm.addEventListener('submit', function(event){
            if (!quickClassSelect || quickClassSelect.options.length === 0) {
                event.preventDefault();
                alert('처리할 수업 부가 없습니다.');
                return;
            }
            var option = quickClassSelect.options[quickClassSelect.selectedIndex];
            var missing = option ? parseInt(option.getAttribute('data-missing') || '0', 10) : 0;
            var classLabel = option ? (option.getAttribute('data-label') || option.textContent.trim()) : '';
            var mode = quickAttendanceForm.querySelector('input[name="attendance_alert_mode"]:checked');
            var sendAlert = !mode || mode.value !== 'skip';
            if (missing <= 0) {
                event.preventDefault();
                alert('선택한 부에는 처리할 미등원 원생이 없습니다.');
                return;
            }
            var summary = classLabel + '\n미등원 ' + new Intl.NumberFormat('ko-KR').format(missing) + '명을 출석 처리합니다.\n알림: ' + (sendAlert ? '발송' : '미발송');
            if (!confirm(summary + '\n\n진행할까요?')) {
                event.preventDefault();
            }
        });
    }
})();
</script>
</body>
</html>
