<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

require_once IEUM_PATH . '/lib/sms_queue.php';

function ieum_absent_alert_weekday($time = null)
{
    $time = $time ? (int) $time : strtotime(G5_TIME_YMDHIS);
    $map = array(1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun');

    return isset($map[(int) date('N', $time)]) ? $map[(int) date('N', $time)] : '';
}

function ieum_absent_alert_is_closed_day($academy_id, $date)
{
    $academy_id = (int) $academy_id;
    $date_sql = sql_escape_string($date);
    $row = sql_fetch("
        select calendar_id
          from " . IEUM_ACADEMY_CALENDAR_TABLE . "
         where academy_id = '{$academy_id}'
           and calendar_date = '{$date_sql}'
           and day_type = 'closed'
           and is_active = 1
         limit 1
    ", false);

    return isset($row['calendar_id']);
}

function ieum_absent_alert_create_for_academy($academy_id, $dry_run = false)
{
    $academy_id = (int) $academy_id;
    $today = G5_TIME_YMD;
    $now = strtotime(G5_TIME_YMDHIS);
    $weekday = sql_escape_string(ieum_absent_alert_weekday($now));
    $created = 0;
    $classes_checked = 0;
    $details = array();

    if (ieum_absent_alert_is_closed_day($academy_id, $today)) {
        return array(
            'created_sms' => 0,
            'classes_checked' => 0,
            'details' => array(
                array('closed_day' => true, 'date' => $today),
            ),
        );
    }

    $classes = sql_query("
        select *
          from " . IEUM_CLASS_TIME_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
           and absent_alert_enabled = 1
      order by sort_order asc, start_time asc
    ", false);

    while ($class = sql_fetch_array($classes)) {
        $classes_checked++;
        $class_time_id = (int) $class['class_time_id'];
        $minutes = max(1, (int) $class['absent_alert_after_minutes']);
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
         left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id
               and a.student_id = s.student_id
               and a.attendance_date = '{$today}'
             where s.academy_id = '{$academy_id}'
               and s.class_time_id = '{$class_time_id}'
               and s.is_active = 1
               and find_in_set('{$weekday}', s.attendance_days) > 0
               and a.attendance_id is null
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
            if ($dry_run) {
                $sms_ids[] = 'dry_run';
                continue;
            }

            $sms_id = ieum_create_direct_sms_queue($academy_id, $contact['contact_phone'], $message, 'absent_alert');
            if ($sms_id) {
                $sms_ids[] = $sms_id;
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
            'class_time_id' => $class_time_id,
            'class_name' => $class['class_name'],
            'missing_students' => $student_names,
            'sms_queue_ids' => $sms_ids,
        );
    }

    return array(
        'created_sms' => $created,
        'classes_checked' => $classes_checked,
        'details' => $details,
    );
}
