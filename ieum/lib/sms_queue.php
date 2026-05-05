<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_mask_phone($phone)
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 7) {
        return '';
    }

    return substr($digits, 0, 3) . '****' . substr($digits, -4);
}

function ieum_build_attendance_sms_message($student_name, $checked_at)
{
    $time = date('H:i', strtotime($checked_at));
    return '[아이이음] ' . $student_name . ' 학생이 ' . $time . '에 등원했습니다.';
}

function ieum_create_sms_queue($student, $attendance_id, $message, $message_type = 'checkin')
{
    $student_id = (int) $student['student_id'];
    $academy_id = isset($student['academy_id']) ? (int) $student['academy_id'] : 1;
    $attendance_id = (int) $attendance_id;
    $message_sql = sql_escape_string($message);
    $message_type_sql = sql_escape_string($message_type);
    $created_ids = array();

    $guardian_sms_column = $message_type === 'checkout' ? 'sms_checkout' : 'sms_attendance';

    $guardians = sql_query("
        select guardian_phone
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and is_active = 1
           and {$guardian_sms_column} = 1
           and guardian_phone <> ''
      order by sort_order asc, guardian_id asc
    ", false);

    while ($guardian = sql_fetch_array($guardians)) {
        $phone_sql = sql_escape_string(trim($guardian['guardian_phone']));
        if ($phone_sql === '') {
            continue;
        }

        sql_query("
            insert into " . IEUM_SMS_QUEUE_TABLE . "
                set academy_id = '{$academy_id}',
                    student_id = '{$student_id}',
                    attendance_id = '{$attendance_id}',
                    recipient_phone = '{$phone_sql}',
                    message = '{$message_sql}',
                    message_type = '{$message_type_sql}',
                    status = 'pending',
                    created_at = '" . G5_TIME_YMDHIS . "'
        ");
        $created_ids[] = sql_insert_id();
    }

    return $created_ids;
}

function ieum_create_direct_sms_queue($academy_id, $recipient_phone, $message, $message_type, $student_id = 0, $attendance_id = 0)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $attendance_id = (int) $attendance_id;
    $recipient_phone = trim($recipient_phone);

    if (!$academy_id || $recipient_phone === '' || trim($message) === '') {
        return 0;
    }

    sql_query("
        insert into " . IEUM_SMS_QUEUE_TABLE . "
            set academy_id = '{$academy_id}',
                student_id = '{$student_id}',
                attendance_id = '{$attendance_id}',
                recipient_phone = '" . sql_escape_string($recipient_phone) . "',
                message = '" . sql_escape_string($message) . "',
                message_type = '" . sql_escape_string($message_type) . "',
                status = 'pending',
                created_at = '" . G5_TIME_YMDHIS . "'
    ");

    return (int) sql_insert_id();
}
