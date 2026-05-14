<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

require_once IEUM_PATH . '/lib/academy.php';
require_once IEUM_PATH . '/lib/sms_queue.php';

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

function ieum_find_active_students_by_code($code, $academy_id = null)
{
    if ($academy_id === null) {
        $academy = ieum_current_academy();
        $academy_id = $academy ? (int) $academy['academy_id'] : ieum_default_academy_id();
    }

    $code = sql_escape_string(ieum_normalize_student_code($code));
    $students = array();
    if ($code === '') {
        return $students;
    }

    $rows = sql_query("
        select *
         from " . IEUM_STUDENT_TABLE . "
         where student_code = '{$code}'
           and academy_id = '" . (int) $academy_id . "'
           and is_active = 1
      order by student_name asc, birth_date asc, student_id asc
    ", false);

    if (!$rows) {
        return $students;
    }

    while ($row = sql_fetch_array($rows)) {
        $students[] = $row;
    }

    return $students;
}

function ieum_find_active_student_by_id($student_id, $academy_id)
{
    $student_id = (int) $student_id;
    $academy_id = (int) $academy_id;
    if (!$student_id || !$academy_id) {
        return null;
    }

    $row = sql_fetch("
        select *
         from " . IEUM_STUDENT_TABLE . "
         where student_id = '{$student_id}'
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

function ieum_attendance_calendar_marks($academy_id, $start_date, $end_date)
{
    $marks = array();
    $academy_id = (int) $academy_id;
    $start_sql = sql_escape_string($start_date);
    $end_sql = sql_escape_string($end_date);

    $rows = sql_query("
        select calendar_date, day_type
          from " . IEUM_ACADEMY_CALENDAR_TABLE . "
         where academy_id = '{$academy_id}'
           and calendar_date between '{$start_sql}' and '{$end_sql}'
           and is_active = 1
    ", false);

    if (!$rows) {
        return $marks;
    }

    while ($row = sql_fetch_array($rows)) {
        $date = $row['calendar_date'];
        if (!isset($marks[$date])) {
            $marks[$date] = array('closed' => false, 'makeup' => false);
        }
        if ($row['day_type'] === 'closed') {
            $marks[$date]['closed'] = true;
        } elseif ($row['day_type'] === 'makeup') {
            $marks[$date]['makeup'] = true;
        }
    }

    return $marks;
}

function ieum_attendance_fixed_public_holiday_label($date)
{
    $mmdd = substr($date, 5, 5);
    $labels = array(
        '01-01' => '신정',
        '03-01' => '삼일절',
        '05-05' => '어린이날',
        '06-06' => '현충일',
        '08-15' => '광복절',
        '10-03' => '개천절',
        '10-09' => '한글날',
        '12-25' => '성탄절',
    );

    return isset($labels[$mmdd]) ? $labels[$mmdd] : '';
}

function ieum_attendance_scheduled_dates($days_csv, $start_date, $end_date, $academy_id = 0)
{
    $days = array_filter(array_map('trim', explode(',', (string) $days_csv)));
    if (!$days) {
        $days = array('mon', 'tue', 'wed', 'thu', 'fri');
    }

    $weekday_map = array(1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun');
    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);
    $dates = array();

    if (!$start_ts || !$end_ts || $start_ts > $end_ts) {
        return $dates;
    }

    $calendar_marks = $academy_id ? ieum_attendance_calendar_marks($academy_id, $start_date, $end_date) : array();

    for ($ts = $start_ts; $ts <= $end_ts; $ts = strtotime('+1 day', $ts)) {
        $date = date('Y-m-d', $ts);
        $weekday = isset($weekday_map[(int) date('N', $ts)]) ? $weekday_map[(int) date('N', $ts)] : '';
        $is_scheduled = $weekday && in_array($weekday, $days, true);
        $mark = isset($calendar_marks[$date]) ? $calendar_marks[$date] : null;
        $is_fixed_holiday = ieum_attendance_fixed_public_holiday_label($date) !== '';

        if ($mark && !empty($mark['closed'])) {
            $is_scheduled = false;
        } elseif ($mark && !empty($mark['makeup'])) {
            $is_scheduled = true;
        } elseif ($is_fixed_holiday) {
            $is_scheduled = false;
        }

        if ($is_scheduled) {
            $dates[$date] = true;
        }
    }

    return $dates;
}

function ieum_attendance_count_scheduled_days($days_csv, $start_date, $end_date, $academy_id = 0)
{
    return count(ieum_attendance_scheduled_dates($days_csv, $start_date, $end_date, $academy_id));
}

function ieum_attendance_public_file_url($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $scheme = 'http';
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] !== '') {
        $scheme = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https' ? 'https' : 'http';
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = '';
    if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && $_SERVER['HTTP_X_FORWARDED_HOST'] !== '') {
        $host = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
    } elseif (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        $host = trim($_SERVER['HTTP_HOST']);
    }

    if ($host === '') {
        return G5_URL . '/' . ltrim($path, '/');
    }

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function ieum_attendance_month_progress($student, $academy_id)
{
    $student_id = (int) $student['student_id'];
    $today = G5_TIME_YMD;
    $month_start = date('Y-m-01', strtotime($today));
    $month_end = date('Y-m-t', strtotime($today));
    $attendance_days = isset($student['attendance_days']) ? $student['attendance_days'] : 'mon,tue,wed,thu,fri';
    $start_date = $month_start;
    $admission_date = isset($student['admission_date']) ? trim($student['admission_date']) : '';

    if ($admission_date !== '' && $admission_date !== '0000-00-00' && strtotime($admission_date) !== false && $admission_date > $month_start) {
        $start_date = $admission_date;
    }

    $elapsed_dates = ieum_attendance_scheduled_dates($attendance_days, $start_date, $today, $academy_id);
    $total_dates = ieum_attendance_scheduled_dates($attendance_days, $start_date, $month_end, $academy_id);
    $elapsed_days = count($elapsed_dates);
    $total_days = count($total_dates);

    $attended_days = 0;
    $attendance_rows = sql_query("
        select distinct attendance_date
          from " . IEUM_ATTENDANCE_TABLE . "
         where academy_id = '" . (int) $academy_id . "'
           and student_id = '{$student_id}'
           and attendance_date between '{$start_date}' and '{$today}'
    ", false);

    if ($attendance_rows) {
        while ($attendance_row = sql_fetch_array($attendance_rows)) {
            if (isset($elapsed_dates[$attendance_row['attendance_date']])) {
                $attended_days++;
            }
        }
    }
    $rate = $elapsed_days > 0 ? (int) round(($attended_days / $elapsed_days) * 100) : 0;

    if ($elapsed_days > 0 && $attended_days >= $elapsed_days) {
        $message = '이번 달 수련 흐름이 아주 좋아요. 오늘도 잘 이어갔습니다!';
    } elseif ($rate >= 80) {
        $message = '좋은 흐름입니다. 다음 수업도 편하게 이어가요.';
    } elseif ($attended_days > 0) {
        $message = '오늘 등원으로 다시 리듬을 만들었어요. 한 번씩 이어가면 됩니다.';
    } else {
        $message = '이번 달 첫 등원입니다. 오늘부터 차근차근 시작해요!';
    }

    return array(
        'attended_days' => $attended_days,
        'elapsed_scheduled_days' => $elapsed_days,
        'total_scheduled_days' => $total_days,
        'rate' => min(100, max(0, $rate)),
        'message' => $message,
        'base_date' => $start_date,
    );
}

function ieum_attendance_student_choice($student)
{
    $class_label = '';
    if (isset($student['class_time_id']) && (int) $student['class_time_id'] > 0) {
        $class = sql_fetch("
            select class_name, start_time
              from " . IEUM_CLASS_TIME_TABLE . "
             where academy_id = '" . (int) $student['academy_id'] . "'
               and class_time_id = '" . (int) $student['class_time_id'] . "'
             limit 1
        ", false);
        if (isset($class['class_name'])) {
            $class_label = trim($class['class_name'] . ' ' . substr($class['start_time'], 0, 5));
        }
    }

    return array(
        'student_id' => (int) $student['student_id'],
        'student_name' => $student['student_name'],
        'birth_date' => isset($student['birth_date']) ? $student['birth_date'] : '',
        'grade_group' => isset($student['grade_group']) ? $student['grade_group'] : '',
        'class_label' => $class_label,
        'school_name' => isset($student['school_name']) ? $student['school_name'] : '',
        'photo_url' => isset($student['student_photo']) && $student['student_photo'] !== '' ? ieum_attendance_public_file_url($student['student_photo']) : '',
    );
}

function ieum_save_attendance_by_code($student_code, $input_source, $selected_student_id = 0, $academy_override = null, $created_by = '')
{
    global $member;

    $academy = $academy_override ? $academy_override : ieum_current_academy();
    if (!$academy) {
        return array(
            'status' => 'forbidden',
            'message' => '아이리포트 사용 권한이 없습니다.',
        );
    }

    $academy_id = (int) $academy['academy_id'];
    $selected_student_id = (int) $selected_student_id;
    if ($selected_student_id) {
        $student = ieum_find_active_student_by_id($selected_student_id, $academy_id);
        if (!$student || ieum_normalize_student_code($student['student_code']) !== ieum_normalize_student_code($student_code)) {
            return array(
                'status' => 'not_found',
                'message' => '선택한 학생 정보를 확인할 수 없습니다.',
            );
        }
    } else {
        $matches = ieum_find_active_students_by_code($student_code, $academy_id);
        if (count($matches) > 1) {
            $choices = array();
            foreach ($matches as $match) {
                $choices[] = ieum_attendance_student_choice($match);
            }

            return array(
                'status' => 'needs_selection',
                'message' => '같은 학생번호가 있습니다. 생년월일을 확인하고 학생을 선택하세요.',
                'students' => $choices,
            );
        }
        $student = $matches ? $matches[0] : null;
    }

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
    $source = sql_escape_string($input_source);
    $today = G5_TIME_YMD;
    $now = G5_TIME_YMDHIS;
    if ($created_by === '') {
        $created_by = isset($member['mb_id']) ? $member['mb_id'] : '';
    }
    $mb_id = sql_escape_string($created_by);
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
