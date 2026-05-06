<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

require_once IEUM_PATH . '/lib/academy.php';

function ieum_normalize_student_code($code)
{
    return preg_replace('/[^0-9A-Za-z_-]/', '', trim($code));
}

function ieum_find_active_student_by_code($code, $academy_id = null)
{
    if ($academy_id === null) {
        $academy = ieum_current_academy();
        $academy_id = $academy ? (int) $academy['academy_id'] : ieum_default_academy_id();
    }

    $code = sql_escape_string(ieum_normalize_student_code($code));
    if ($code === '') {
        return null;
    }

    $row = sql_fetch("
        select *
         from " . IEUM_STUDENT_TABLE . "
         where student_code = '{$code}'
           and academy_id = '{$academy_id}'
           and is_active = 1
         limit 1
    ", false);

    return isset($row['student_id']) ? $row : null;
}

function ieum_get_today_attendance($student_id, $academy_id = null)
{
    if ($academy_id === null) {
        $academy = ieum_current_academy();
        $academy_id = $academy ? (int) $academy['academy_id'] : ieum_default_academy_id();
    }

    $student_id = (int) $student_id;
    $today = G5_TIME_YMD;

    $row = sql_fetch("
        select *
         from " . IEUM_ATTENDANCE_TABLE . "
         where student_id = '{$student_id}'
           and academy_id = '{$academy_id}'
           and attendance_date = '{$today}'
         limit 1
    ", false);

    return isset($row['attendance_id']) ? $row : null;
}

function ieum_attendance_count_scheduled_days($days_csv, $start_date, $end_date)
{
    $days = array_filter(array_map('trim', explode(',', (string) $days_csv)));
    if (!$days) {
        $days = array('mon', 'tue', 'wed', 'thu', 'fri');
    }

    $weekday_map = array(1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun');
    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);
    $count = 0;

    for ($ts = $start_ts; $ts <= $end_ts; $ts = strtotime('+1 day', $ts)) {
        $weekday = isset($weekday_map[(int) date('N', $ts)]) ? $weekday_map[(int) date('N', $ts)] : '';
        if ($weekday && in_array($weekday, $days, true)) {
            $count++;
        }
    }

    return $count;
}

function ieum_attendance_month_progress($student, $academy_id)
{
    $student_id = (int) $student['student_id'];
    $today = G5_TIME_YMD;
    $month_start = date('Y-m-01', strtotime($today));
    $month_end = date('Y-m-t', strtotime($today));
    $attendance_days = isset($student['attendance_days']) ? $student['attendance_days'] : 'mon,tue,wed,thu,fri';
    $elapsed_days = ieum_attendance_count_scheduled_days($attendance_days, $month_start, $today);
    $total_days = ieum_attendance_count_scheduled_days($attendance_days, $month_start, $month_end);

    $row = sql_fetch("
        select count(distinct attendance_date) as cnt
          from " . IEUM_ATTENDANCE_TABLE . "
         where academy_id = '" . (int) $academy_id . "'
           and student_id = '{$student_id}'
           and attendance_date between '{$month_start}' and '{$today}'
    ", false);

    $attended_days = isset($row['cnt']) ? (int) $row['cnt'] : 0;
    $rate = $elapsed_days > 0 ? (int) round(($attended_days / $elapsed_days) * 100) : 0;

    if ($elapsed_days > 0 && $attended_days >= $elapsed_days) {
        $message = '이번 달 개근 진행 중입니다. 오늘도 멋지게 이어갔어요!';
    } elseif ($rate >= 80) {
        $message = '좋은 흐름입니다. 이번 달 출석 리듬을 계속 지켜봐요!';
    } elseif ($attended_days > 0) {
        $message = '오늘 등원으로 다시 흐름을 만들었습니다. 다음 수업도 이어가요!';
    } else {
        $message = '이번 달 첫 등원입니다. 오늘부터 차근차근 시작해요!';
    }

    return array(
        'attended_days' => $attended_days,
        'elapsed_scheduled_days' => $elapsed_days,
        'total_scheduled_days' => $total_days,
        'rate' => min(100, max(0, $rate)),
        'message' => $message,
    );
}

function ieum_save_attendance_by_code($student_code, $input_source)
{
    global $member;

    $academy = ieum_current_academy();
    if (!$academy) {
        return array(
            'status' => 'forbidden',
            'message' => '아이리포트 사용 권한이 없습니다.',
        );
    }

    $academy_id = (int) $academy['academy_id'];
    $student = ieum_find_active_student_by_code($student_code, $academy_id);
    if (!$student) {
        return array(
            'status' => 'not_found',
            'message' => '등록된 학생번호가 아닙니다.',
        );
    }

    $already = ieum_get_today_attendance($student['student_id'], $academy_id);
    if ($already) {
        return array(
            'status' => 'duplicate',
            'message' => $student['student_name'] . ' 학생은 이미 오늘 등원 처리되었습니다.',
            'student' => $student,
            'progress' => ieum_attendance_month_progress($student, $academy_id),
            'attendance' => $already,
        );
    }

    $student_id = (int) $student['student_id'];
    $mb_id = isset($member['mb_id']) ? sql_escape_string($member['mb_id']) : '';
    $source = sql_escape_string($input_source);
    $today = G5_TIME_YMD;
    $now = G5_TIME_YMDHIS;

    sql_query("
        insert ignore into " . IEUM_ATTENDANCE_TABLE . "
            set academy_id = '{$academy_id}',
                student_id = '{$student_id}',
                attendance_date = '{$today}',
                checked_at = '{$now}',
                input_source = '{$source}',
                created_by = '{$mb_id}',
                created_at = '{$now}'
    ", false);

    $attendance_id = sql_insert_id();
    if (!$attendance_id) {
        $already = ieum_get_today_attendance($student['student_id'], $academy_id);
        return array(
            'status' => 'duplicate',
            'message' => $student['student_name'] . ' 학생은 이미 오늘 등원 처리되었습니다.',
            'student' => $student,
            'progress' => ieum_attendance_month_progress($student, $academy_id),
            'attendance' => $already,
        );
    }

    $message = ieum_build_attendance_sms_message($student['student_name'], $now);
    $sms_ids = ieum_create_sms_queue($student, $attendance_id, $message, 'checkin');

    return array(
        'status' => 'created',
        'message' => $student['student_name'] . ' 학생 등원 처리 완료',
        'student' => $student,
        'progress' => ieum_attendance_month_progress($student, $academy_id),
        'attendance_id' => $attendance_id,
        'sms_queue_ids' => $sms_ids,
        'sms_message' => $message,
    );
}
