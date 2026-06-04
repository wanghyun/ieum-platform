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

function ieum_sms_queue_ensure_schedule_columns()
{
    static $done = false;
    if ($done || !defined('IEUM_SMS_QUEUE_TABLE')) {
        return;
    }

    $columns = array(
        'source_key' => "alter table " . IEUM_SMS_QUEUE_TABLE . " add source_key varchar(100) not null default '' after message_type",
        'scheduled_at' => "alter table " . IEUM_SMS_QUEUE_TABLE . " add scheduled_at datetime null after status",
    );

    foreach ($columns as $column => $sql) {
        $exists = sql_fetch("show columns from " . IEUM_SMS_QUEUE_TABLE . " like '" . sql_escape_string($column) . "'", false);
        if (empty($exists['Field'])) {
            sql_query($sql, false);
        }
    }

    $indexes = array(
        'idx_pending_schedule' => "alter table " . IEUM_SMS_QUEUE_TABLE . " add key idx_pending_schedule (academy_id, status, scheduled_at, sms_id)",
        'idx_source_key' => "alter table " . IEUM_SMS_QUEUE_TABLE . " add key idx_source_key (academy_id, message_type, source_key)",
    );

    foreach ($indexes as $index => $sql) {
        $exists = sql_fetch("show index from " . IEUM_SMS_QUEUE_TABLE . " where Key_name = '" . sql_escape_string($index) . "'", false);
        if (empty($exists['Key_name'])) {
            sql_query($sql, false);
        }
    }

    $done = true;
}

function ieum_sms_queue_normalize_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '' || strtotime($value) === false) {
        return '';
    }

    return date('Y-m-d H:i:s', strtotime($value));
}

function ieum_build_attendance_sms_message($student_name, $checked_at)
{
    $time = date('H:i', strtotime($checked_at));
    return '[아이이음] ' . $student_name . ' 학생이 ' . $time . '에 등원했습니다.';
}

function ieum_create_sms_queue($student, $attendance_id, $message, $message_type = 'checkin')
{
    ieum_sms_queue_ensure_schedule_columns();

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

function ieum_create_direct_sms_queue($academy_id, $recipient_phone, $message, $message_type, $student_id = 0, $attendance_id = 0, $scheduled_at = '', $source_key = '')
{
    ieum_sms_queue_ensure_schedule_columns();

    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $attendance_id = (int) $attendance_id;
    $recipient_phone = trim($recipient_phone);
    $scheduled_at = ieum_sms_queue_normalize_datetime($scheduled_at);
    $source_key = trim((string) $source_key);

    if (!$academy_id || $recipient_phone === '' || trim($message) === '') {
        return 0;
    }

    $scheduled_sql = $scheduled_at !== '' ? "'" . sql_escape_string($scheduled_at) . "'" : "null";

    sql_query("
        insert into " . IEUM_SMS_QUEUE_TABLE . "
            set academy_id = '{$academy_id}',
                student_id = '{$student_id}',
                attendance_id = '{$attendance_id}',
                recipient_phone = '" . sql_escape_string($recipient_phone) . "',
                message = '" . sql_escape_string($message) . "',
                message_type = '" . sql_escape_string($message_type) . "',
                source_key = '" . sql_escape_string($source_key) . "',
                status = 'pending',
                scheduled_at = {$scheduled_sql},
                created_at = '" . G5_TIME_YMDHIS . "'
    ");

    return (int) sql_insert_id();
}

function ieum_create_direct_sms_queue_once($academy_id, $recipient_phone, $message, $message_type, $student_id = 0, $attendance_id = 0, $dedupe_date = '', $scheduled_at = '', $source_key = '')
{
    ieum_sms_queue_ensure_schedule_columns();

    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $attendance_id = (int) $attendance_id;
    $recipient_phone = trim($recipient_phone);
    $message = trim($message);
    $message_type = trim($message_type);
    $dedupe_date = preg_replace('/[^0-9-]/', '', (string) $dedupe_date);
    $scheduled_at = ieum_sms_queue_normalize_datetime($scheduled_at);
    $source_key = trim((string) $source_key);

    if (!$academy_id || $recipient_phone === '' || $message === '' || $message_type === '') {
        return 0;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dedupe_date)) {
        $dedupe_date = G5_TIME_YMD;
    }

    if ($source_key !== '') {
        $exists = sql_fetch("
            select sms_id, status
              from " . IEUM_SMS_QUEUE_TABLE . "
             where academy_id = '{$academy_id}'
               and recipient_phone = '" . sql_escape_string($recipient_phone) . "'
               and message_type = '" . sql_escape_string($message_type) . "'
               and source_key = '" . sql_escape_string($source_key) . "'
               and status in ('pending', 'processing', 'sent')
             limit 1
        ", false);

        if (!empty($exists['sms_id'])) {
            if ($exists['status'] === 'pending') {
                $scheduled_sql = $scheduled_at !== '' ? "'" . sql_escape_string($scheduled_at) . "'" : "null";
                sql_query("
                    update " . IEUM_SMS_QUEUE_TABLE . "
                       set message = '" . sql_escape_string($message) . "',
                           scheduled_at = {$scheduled_sql},
                           error_message = ''
                     where sms_id = '" . (int) $exists['sms_id'] . "'
                       and academy_id = '{$academy_id}'
                       and status = 'pending'
                ", false);
            }
            return (int) $exists['sms_id'];
        }
    }

    $exists = sql_fetch("
        select sms_id
          from " . IEUM_SMS_QUEUE_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and recipient_phone = '" . sql_escape_string($recipient_phone) . "'
           and message_type = '" . sql_escape_string($message_type) . "'
           and message = '" . sql_escape_string($message) . "'
           and status in ('pending', 'processing', 'sent')
           and left(created_at, 10) = '" . sql_escape_string($dedupe_date) . "'
         limit 1
    ", false);

    if (!empty($exists['sms_id'])) {
        return (int) $exists['sms_id'];
    }

    return ieum_create_direct_sms_queue($academy_id, $recipient_phone, $message, $message_type, $student_id, $attendance_id, $scheduled_at, $source_key);
}

function ieum_cancel_direct_sms_queue_by_source($academy_id, $message_type, $source_key, $reason = '')
{
    ieum_sms_queue_ensure_schedule_columns();

    $academy_id = (int) $academy_id;
    $message_type = trim((string) $message_type);
    $source_key = trim((string) $source_key);
    $reason = trim((string) $reason);

    if (!$academy_id || $message_type === '' || $source_key === '') {
        return 0;
    }
    if ($reason === '') {
        $reason = '예약 조건이 변경되어 발송 전 자동 취소되었습니다.';
    }

    sql_query("
        update " . IEUM_SMS_QUEUE_TABLE . "
           set status = 'canceled',
               error_message = '" . sql_escape_string($reason) . "'
         where academy_id = '{$academy_id}'
           and message_type = '" . sql_escape_string($message_type) . "'
           and source_key = '" . sql_escape_string($source_key) . "'
           and status = 'pending'
    ", false);

    $affected = sql_fetch("select row_count() as cnt", false);
    return isset($affected['cnt']) ? (int) $affected['cnt'] : 0;
}

function ieum_create_vehicle_alert_sms_queue($academy_id, $vehicle, $status_label, $note = '', $journal_date = '')
{
    $academy_id = (int) $academy_id;
    $student_id = isset($vehicle['student_id']) ? (int) $vehicle['student_id'] : 0;
    $student_name = isset($vehicle['student_name']) ? trim($vehicle['student_name']) : '';
    $vehicle_label = isset($vehicle['vehicle_label']) && trim($vehicle['vehicle_label']) !== '' ? trim($vehicle['vehicle_label']) : '차량';
    $route_name = isset($vehicle['route_name']) ? trim($vehicle['route_name']) : '';
    $note = trim((string) $note);
    $journal_date = preg_replace('/[^0-9-]/', '', (string) $journal_date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journal_date)) {
        $journal_date = G5_TIME_YMD;
    }

    if (!$academy_id || !$student_id || $student_name === '') {
        return array();
    }

    $route_label = trim($vehicle_label . ($route_name !== '' ? ' ' . $route_name : ''));
    $message = '[아이이음 차량] ' . $student_name . ' 학생 ' . $status_label;
    if ($route_label !== '') {
        $message .= ' · ' . $route_label;
    }
    if ($note !== '') {
        $message .= ' · 메모: ' . $note;
    }

    $vehicle_alert_column = sql_fetch("show columns from " . IEUM_ACADEMY_CONTACT_TABLE . " like 'sms_vehicle_alert'", false);
    $vehicle_alert_filter = !empty($vehicle_alert_column['Field']) ? 'and sms_vehicle_alert = 1' : 'and sms_system_alert = 1';

    $created_ids = array();
    $contacts = sql_query("
        select contact_phone
          from " . IEUM_ACADEMY_CONTACT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
           {$vehicle_alert_filter}
           and contact_phone <> ''
      order by sort_order asc, contact_id asc
    ", false);

    while ($contact = sql_fetch_array($contacts)) {
        $sms_id = ieum_create_direct_sms_queue_once($academy_id, $contact['contact_phone'], $message, 'vehicle_alert', $student_id, 0, $journal_date);
        if ($sms_id) {
            $created_ids[] = $sms_id;
        }
    }

    return $created_ids;
}

function ieum_cancel_pending_vehicle_alert_sms_queue($academy_id, $vehicle, $journal_date = '', $reason = '')
{
    $academy_id = (int) $academy_id;
    $student_id = isset($vehicle['student_id']) ? (int) $vehicle['student_id'] : 0;
    $journal_date = preg_replace('/[^0-9-]/', '', (string) $journal_date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journal_date)) {
        $journal_date = G5_TIME_YMD;
    }
    if (!$academy_id || !$student_id) {
        return 0;
    }

    $reason = trim((string) $reason);
    if ($reason === '') {
        $reason = '차량 상태가 정상으로 변경되어 발송 전 자동 취소되었습니다.';
    }

    sql_query("
        update " . IEUM_SMS_QUEUE_TABLE . "
           set status = 'canceled',
               error_message = '" . sql_escape_string($reason) . "'
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and message_type = 'vehicle_alert'
           and status = 'pending'
           and left(created_at, 10) = '" . sql_escape_string($journal_date) . "'
    ", false);

    $affected = sql_fetch("select row_count() as cnt", false);
    return isset($affected['cnt']) ? (int) $affected['cnt'] : 0;
}
