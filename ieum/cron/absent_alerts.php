<?php
require_once dirname(__DIR__) . '/_common.php';
require_once IEUM_PATH . '/lib/sms_queue.php';

header('Content-Type: application/json; charset=utf-8');

$cron_token = defined('IEUM_CRON_TOKEN') ? IEUM_CRON_TOKEN : (defined('IEUM_SMS_GATEWAY_TOKEN') ? IEUM_SMS_GATEWAY_TOKEN : '');
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($cron_token === '' || !hash_equals($cron_token, $token)) {
    echo json_encode(array('success' => false, 'message' => 'invalid token'), JSON_UNESCAPED_UNICODE);
    exit;
}

$today = G5_TIME_YMD;
$now = strtotime(G5_TIME_YMDHIS);
$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
$weekday_map = array(1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun');
$today_weekday = isset($weekday_map[(int) date('N', $now)]) ? $weekday_map[(int) date('N', $now)] : '';
$today_weekday_sql = sql_escape_string($today_weekday);
$created = 0;
$checked = 0;
$details = array();
$closed_academies = array();

$classes = sql_query("
    select c.*, a.academy_name
      from " . IEUM_CLASS_TIME_TABLE . " c
      join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = c.academy_id
     where c.is_active = 1
       and c.absent_alert_enabled = 1
       and a.is_active = 1
       and a.service_status = 'active'
  order by c.academy_id asc, c.sort_order asc, c.start_time asc
", false);

while ($class = sql_fetch_array($classes)) {
    $checked++;
    $academy_id = (int) $class['academy_id'];
    $class_time_id = (int) $class['class_time_id'];

    if (!isset($closed_academies[$academy_id])) {
        $closed = sql_fetch("
            select calendar_id
              from " . IEUM_ACADEMY_CALENDAR_TABLE . "
             where academy_id = '{$academy_id}'
               and calendar_date = '{$today}'
               and day_type = 'closed'
               and is_active = 1
             limit 1
        ", false);
        $closed_academies[$academy_id] = isset($closed['calendar_id']);
    }

    if ($closed_academies[$academy_id]) {
        continue;
    }

    $minutes = (int) $class['absent_alert_after_minutes'];
    if ($minutes < 1) {
        $minutes = 10;
    }

    $target_time = date('Y-m-d H:i:s', strtotime($today . ' ' . $class['start_time'] . ' +' . $minutes . ' minutes'));
    if ($now < strtotime($target_time)) {
        continue;
    }

    $already = sql_fetch("
        select alert_log_id
          from " . IEUM_ABSENT_ALERT_LOG_TABLE . "
         where academy_id = '{$academy_id}'
           and class_time_id = '{$class_time_id}'
           and alert_date = '{$today}'
         limit 1
    ", false);
    if (isset($already['alert_log_id'])) {
        continue;
    }

    $missing_rows = sql_query("
        select s.student_id, s.student_name
          from " . IEUM_STUDENT_TABLE . " s
     left join " . IEUM_ATTENDANCE_TABLE . " atd on atd.academy_id = s.academy_id
           and atd.student_id = s.student_id
           and atd.attendance_date = '{$today}'
         where s.academy_id = '{$academy_id}'
           and s.class_time_id = '{$class_time_id}'
           and s.is_active = 1
           and find_in_set('{$today_weekday_sql}', s.attendance_days) > 0
           and atd.attendance_id is null
      order by s.student_name asc, s.student_code asc
    ", false);

    $student_ids = array();
    $student_names = array();
    while ($student = sql_fetch_array($missing_rows)) {
        $student_ids[] = (int) $student['student_id'];
        $student_names[] = $student['student_name'];
    }
    if (!$student_ids) {
        continue;
    }

    $contacts = sql_query("
        select contact_phone
          from " . IEUM_ACADEMY_CONTACT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
           and sms_absent_alert = 1
           and contact_phone <> ''
      order by sort_order asc, contact_id asc
    ", false);

    $sms_ids = array();
    $message = '[아이이음] ' . $class['class_name'] . ' 미등원: ' . implode(', ', $student_names);
    while ($contact = sql_fetch_array($contacts)) {
        $sms_id = $dry_run ? 0 : ieum_create_direct_sms_queue($academy_id, $contact['contact_phone'], $message, 'absent_alert');
        if ($sms_id) {
            $sms_ids[] = $sms_id;
        } elseif ($dry_run) {
            $sms_ids[] = 'dry_run';
        }
    }

    if (!$sms_ids) {
        continue;
    }

    if (!$dry_run) {
        sql_query("
            insert into " . IEUM_ABSENT_ALERT_LOG_TABLE . "
                set academy_id = '{$academy_id}',
                    class_time_id = '{$class_time_id}',
                    alert_date = '{$today}',
                    target_time = '{$target_time}',
                    student_ids = '" . sql_escape_string(implode(',', $student_ids)) . "',
                    sms_queue_ids = '" . sql_escape_string(implode(',', $sms_ids)) . "',
                    created_at = '" . G5_TIME_YMDHIS . "'
        ");
    }

    $created += $dry_run ? 0 : count($sms_ids);
    $details[] = array(
        'academy_id' => $academy_id,
        'class_time_id' => $class_time_id,
        'class_name' => $class['class_name'],
        'missing_students' => $student_names,
        'sms_queue_ids' => $sms_ids,
    );
}

echo json_encode(array(
    'success' => true,
    'dry_run' => $dry_run,
    'weekday' => $today_weekday,
    'checked_classes' => $checked,
    'created_sms' => $created,
    'details' => $details,
), JSON_UNESCAPED_UNICODE);
