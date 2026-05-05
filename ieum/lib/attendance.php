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
            'attendance' => $already,
        );
    }

    $message = ieum_build_attendance_sms_message($student['student_name'], $now);
    $sms_ids = ieum_create_sms_queue($student, $attendance_id, $message, 'checkin');

    return array(
        'status' => 'created',
        'message' => $student['student_name'] . ' 학생 등원 처리 완료',
        'student' => $student,
        'attendance_id' => $attendance_id,
        'sms_queue_ids' => $sms_ids,
        'sms_message' => $message,
    );
}
