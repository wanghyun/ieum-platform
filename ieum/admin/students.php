<?php
$sub_menu = '950120';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/sms_queue.php';
require_once IEUM_PATH . '/lib/promotion.php';
require_once IEUM_PATH . '/lib/dashboard.php';
require_once IEUM_PATH . '/lib/attendance.php';

$g5['title'] = '아이이음 원생 관리';
$current_academy = ieum_require_academy_page();
$academy_id = (int) $current_academy['academy_id'];
ieum_promotion_ensure_schema();

$mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'list';
$student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
$embed_mode = isset($_GET['embed']) && $_GET['embed'] === '1';
$message = '';
$error = '';
$last_saved_student_id = 0;
$last_saved_student_name = '';

function ieum_student_clean_phone($phone)
{
    return preg_replace('/[^0-9+\-]/', '', trim($phone));
}

function ieum_student_photo_url($path)
{
    $path = trim((string) $path);
    return $path === '' ? '' : G5_URL . '/' . ltrim($path, '/');
}

function ieum_save_student_photo_upload($academy_id, $student_id)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    if (!$academy_id || !$student_id || empty($_FILES['student_photo_file']['name'])) {
        return '';
    }

    if (!is_uploaded_file($_FILES['student_photo_file']['tmp_name'])) {
        return '';
    }

    $allowed = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    );
    $mime = function_exists('mime_content_type') ? mime_content_type($_FILES['student_photo_file']['tmp_name']) : $_FILES['student_photo_file']['type'];
    if (!isset($allowed[$mime])) {
        return '';
    }

    if ((int) $_FILES['student_photo_file']['size'] > 5 * 1024 * 1024) {
        return '';
    }

    $dir = G5_DATA_PATH . '/ieum/student_photos';
    if (!is_dir($dir)) {
        @mkdir($dir, G5_DIR_PERMISSION, true);
        @chmod($dir, G5_DIR_PERMISSION);
    }

    $filename = 'academy' . $academy_id . '_student' . $student_id . '_' . date('YmdHis') . '.' . $allowed[$mime];
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($_FILES['student_photo_file']['tmp_name'], $target)) {
        return '';
    }
    @chmod($target, G5_FILE_PERMISSION);

    return 'data/ieum/student_photos/' . $filename;
}

function ieum_grade_options()
{
    return array(
        '' => '선택 안함',
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
}

function ieum_grade_label($value)
{
    $options = ieum_grade_options();
    return isset($options[$value]) ? $options[$value] : $value;
}

function ieum_student_duplicate_code_rows($academy_id, $student_code, $exclude_student_id = 0)
{
    $academy_id = (int) $academy_id;
    $student_code = trim((string) $student_code);
    $exclude_student_id = (int) $exclude_student_id;
    if ($academy_id <= 0 || $student_code === '') {
        return array();
    }

    $student_code_sql = sql_escape_string($student_code);
    $exclude_sql = $exclude_student_id > 0 ? " and student_id <> '{$exclude_student_id}' " : '';
    $result = sql_query("
        select student_id, student_code, student_name, birth_date, grade_group, class_time_id, is_active
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and student_code = '{$student_code_sql}'
           {$exclude_sql}
      order by is_active desc, student_name asc, student_id asc
         limit 8
    ", false);

    $rows = array();
    while ($row = sql_fetch_array($result)) {
        $rows[] = $row;
    }
    return $rows;
}

function ieum_grade_from_birth_date($birth_date, $base_time = null)
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

function ieum_refresh_student_auto_grades($academy_id)
{
    $academy_id = (int) $academy_id;
    $students = sql_query("
        select student_id, birth_date, grade_group
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
           and birth_date is not null
           and birth_date <> '0000-00-00'
    ", false);

    while ($student = sql_fetch_array($students)) {
        $grade_group = ieum_grade_from_birth_date($student['birth_date']);
        if ($grade_group !== '' && $grade_group !== $student['grade_group']) {
            sql_query("
                update " . IEUM_STUDENT_TABLE . "
                   set grade_group = '" . sql_escape_string($grade_group) . "',
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
                   and student_id = '" . (int) $student['student_id'] . "'
            ", false);
        }
    }
}

function ieum_attendance_week_type_options()
{
    return array('2' => '주 2회', '3' => '주 3회', '4' => '주 4회', '5' => '주 5회', 'custom' => '직접 선택');
}

function ieum_student_status_options()
{
    return array(
        'enrolled' => '재원',
        'trial' => '체험',
        'paused' => '휴관',
        'returned' => '복귀',
        'withdrawn' => '퇴관',
        'waiting' => '대기',
    );
}

function ieum_student_status_label($status)
{
    $options = ieum_student_status_options();
    return isset($options[$status]) ? $options[$status] : $status;
}

function ieum_student_quick_status_options()
{
    return array(
        'enrolled' => '재원',
        'paused' => '휴관',
        'withdrawn' => '퇴관',
    );
}

function ieum_student_status_active_value($status)
{
    return $status === 'withdrawn' ? 0 : 1;
}

function ieum_student_row_status_value($row)
{
    $status = isset($row['student_status']) ? trim((string) $row['student_status']) : '';
    if ($status !== '' && isset(ieum_student_status_options()[$status])) {
        return $status;
    }

    return !empty($row['is_active']) ? 'enrolled' : 'withdrawn';
}

function ieum_student_row_status_label($row)
{
    return ieum_student_status_label(ieum_student_row_status_value($row));
}

function ieum_enrollment_source_options()
{
    return array(
        '' => '선택 안함',
        'referral' => '지인 소개',
        'sibling' => '형제/자매',
        'sign_walkin' => '간판/지나가다',
        'naver_search' => '네이버 검색',
        'naver_place' => '네이버 플레이스',
        'blog_cafe' => '블로그/카페',
        'instagram' => '인스타그램',
        'youtube' => '유튜브',
        'school_promo' => '학교/유치원 홍보',
        'flyer' => '전단지',
        'event_trial' => '행사/체험수업',
        'etc' => '기타',
    );
}

function ieum_enrollment_source_label($source)
{
    $options = ieum_enrollment_source_options();
    return isset($options[$source]) ? $options[$source] : $source;
}

function ieum_log_student_status_change($academy_id, $student_id, $before_status, $after_status, $memo = '')
{
    global $member;

    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    if (!$academy_id || !$student_id || $before_status === $after_status) {
        return;
    }

    sql_query("
        insert into " . IEUM_STUDENT_STATUS_LOG_TABLE . "
            set academy_id = '{$academy_id}',
                student_id = '{$student_id}',
                before_status = '" . sql_escape_string($before_status) . "',
                after_status = '" . sql_escape_string($after_status) . "',
                changed_date = '" . G5_TIME_YMD . "',
                reason = '',
                memo = '" . sql_escape_string($memo) . "',
                created_by = '" . sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '') . "',
                created_at = '" . G5_TIME_YMDHIS . "'
    ");
}

function ieum_weekday_options()
{
    return array('mon' => '월', 'tue' => '화', 'wed' => '수', 'thu' => '목', 'fri' => '금');
}

function ieum_clean_attendance_days($days)
{
    $allowed = ieum_weekday_options();
    $clean = array();
    if (!is_array($days)) {
        $days = array();
    }
    foreach ($days as $day) {
        $day = trim($day);
        if (isset($allowed[$day]) && !in_array($day, $clean, true)) {
            $clean[] = $day;
        }
    }
    return implode(',', $clean);
}

function ieum_attendance_days_label($value)
{
    $options = ieum_weekday_options();
    $labels = array();
    foreach (explode(',', (string) $value) as $day) {
        $day = trim($day);
        if (isset($options[$day])) {
            $labels[] = $options[$day];
        }
    }
    return $labels ? implode(' ', $labels) : '미지정';
}

function ieum_fetch_student($student_id)
{
    $student_id = (int) $student_id;
    if (!$student_id) {
        return null;
    }

    $row = sql_fetch("
        select *
         from " . IEUM_STUDENT_TABLE . "
         where student_id = '{$student_id}'
           and academy_id = '" . (int) ieum_current_academy()['academy_id'] . "'
         limit 1
    ", false);

    return isset($row['student_id']) ? $row : null;
}

function ieum_fetch_guardians($academy_id, $student_id)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $rows = array();

    if (!$student_id) {
        return $rows;
    }

    $result = sql_query("
        select *
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and is_active = 1
      order by sort_order asc, guardian_id asc
    ", false);

    while ($row = sql_fetch_array($result)) {
        $rows[] = $row;
    }

    return $rows;
}

function ieum_student_care_sms_message($student_name, $message_type, $academy_id = 0)
{
    $student_name = trim($student_name);
    $template = ieum_student_care_sms_template($academy_id, $message_type);

    return ieum_student_care_sms_render($template, $student_name);
}

function ieum_student_care_sms_defaults()
{
    return array(
        'birthday' => array(
            'key' => 'student_care_birthday',
            'title' => '생일 축하 문자',
            'message' => '[아이이음] 이번 달은 {원생명} 원생의 생일이 있는 달입니다. 도장에서 따뜻하게 축하하고, 수업 중 컨디션도 세심하게 챙기겠습니다. 가정에서도 특별한 하루 보내시길 바랍니다.',
        ),
        'long_absent' => array(
            'key' => 'student_care_long_absent',
            'title' => '장기 미등원 안부 문자',
            'message' => '[아이이음] {원생명} 원생이 최근 수업에 보이지 않아 안부 확인차 연락드립니다. 일정이나 컨디션에 어려움이 있으면 편하게 알려 주세요. 다시 등원할 수 있도록 도장에서 함께 챙기겠습니다.',
        ),
        'student' => array(
            'key' => 'student_care_general',
            'title' => '원생 안부 문자',
            'message' => '[아이이음] {원생명} 원생 관련 안내드립니다. 확인 후 궁금한 점이 있으면 도장으로 편하게 연락 주세요.',
        ),
    );
}

function ieum_student_care_sms_default($message_type)
{
    $defaults = ieum_student_care_sms_defaults();
    return isset($defaults[$message_type]) ? $defaults[$message_type] : $defaults['student'];
}

function ieum_student_care_sms_template($academy_id, $message_type)
{
    $academy_id = (int) $academy_id;
    $default = ieum_student_care_sms_default($message_type);
    if ($academy_id <= 0) {
        return $default['message'];
    }

    $row = sql_fetch("
        select message
          from " . IEUM_SMS_TEMPLATE_TABLE . "
         where academy_id = '{$academy_id}'
           and template_key = '" . sql_escape_string($default['key']) . "'
           and is_active = 1
         limit 1
    ", false);

    return isset($row['message']) && trim((string) $row['message']) !== '' ? $row['message'] : $default['message'];
}

function ieum_student_care_sms_render($template, $student_name)
{
    $student_name = trim((string) $student_name);
    if ($student_name === '') {
        $student_name = '원생';
    }

    return str_replace('{원생명}', $student_name, (string) $template);
}

function ieum_student_care_sms_save_template($academy_id, $message_type, $message, $student_name = '')
{
    $academy_id = (int) $academy_id;
    $default = ieum_student_care_sms_default($message_type);
    $message = trim((string) $message);
    $student_name = trim((string) $student_name);
    if ($academy_id <= 0 || $message === '') {
        return false;
    }

    if ($student_name !== '') {
        $message = str_replace($student_name, '{원생명}', $message);
    }

    sql_query("
        insert into " . IEUM_SMS_TEMPLATE_TABLE . "
            set academy_id = '{$academy_id}',
                template_key = '" . sql_escape_string($default['key']) . "',
                title = '" . sql_escape_string($default['title']) . "',
                message = '" . sql_escape_string($message) . "',
                is_active = 1,
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                title = values(title),
                message = values(message),
                is_active = 1,
                updated_at = values(updated_at)
    ", false);

    return true;
}

function ieum_queue_student_care_sms($academy_id, $student_id, $message_type, $custom_message = '')
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $message_type = preg_replace('/[^0-9a-z_]/', '', trim($message_type));
    $student = sql_fetch("
        select student_id, student_name
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
         limit 1
    ", false);
    if (!$student || !isset($student['student_id'])) {
        return 0;
    }

    $custom_message = trim((string) $custom_message);
    $message = $custom_message !== '' ? $custom_message : ieum_student_care_sms_render(ieum_student_care_sms_template($academy_id, $message_type), $student['student_name']);
    $queue_type = $message_type === 'birthday' ? 'birthday_care' : ($message_type === 'long_absent' ? 'absent_care' : 'student_care');
    $phones = array();
    $guardians = sql_query("
        select guardian_phone
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and is_active = 1
           and guardian_phone <> ''
      order by is_primary desc, sort_order asc, guardian_id asc
    ", false);
    while ($guardian = sql_fetch_array($guardians)) {
        $phone = ieum_student_clean_phone($guardian['guardian_phone']);
        if ($phone !== '') {
            $phones[$phone] = true;
        }
    }

    $created = 0;
    foreach (array_keys($phones) as $phone) {
        if (ieum_create_direct_sms_queue($academy_id, $phone, $message, $queue_type, $student_id, 0)) {
            $created++;
        }
    }

    if ($created > 0 && $message !== '') {
        ieum_student_care_sms_save_template($academy_id, $message_type, $message, $student['student_name']);
    }

    return $created;
}

function ieum_fetch_vehicle_routes($academy_id)
{
    $academy_id = (int) $academy_id;
    $stops = array();
    $result = sql_query("
        select s.*, r.route_name, r.vehicle_label
          from " . IEUM_VEHICLE_STOP_TABLE . " s
     left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = s.route_id and r.academy_id = s.academy_id
         where s.academy_id = '{$academy_id}'
           and s.is_active = 1
      order by field(s.stop_type, 'pickup', 'dropoff'), r.sort_order asc, s.sort_order asc, s.stop_time asc, s.stop_name asc
    ", false);

    while ($row = sql_fetch_array($result)) {
        $stops[] = $row;
    }

    return $stops;
}

function ieum_fetch_vehicle_assignments($academy_id, $student_id)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $items = array(
        'pickup' => null,
        'dropoff' => null,
    );

    if (!$student_id) {
        return $items;
    }

    $result = sql_query("
        select sv.*, r.route_name, r.vehicle_label, st.stop_name, st.stop_time
          from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
     left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
     left join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
         where sv.academy_id = '{$academy_id}'
           and sv.student_id = '{$student_id}'
           and sv.is_active = 1
      order by sv.student_vehicle_id desc
    ", false);

    while ($row = sql_fetch_array($result)) {
        if ($row['ride_type'] === 'pickup' || $row['ride_type'] === 'dropoff') {
            $items[$row['ride_type']] = $row;
        }
    }

    return $items;
}

function ieum_students_promotion_rank_display($academy, $status, $rank = null)
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

    $rank_label = ieum_students_promotion_level_badge($poom_dan, $grade_level);

    return trim(($belt !== '' ? $belt . ' · ' : '') . $rank_label);
}

function ieum_students_promotion_level_badge($poom_dan, $grade_level)
{
    $poom_dan = max(0, min(4, (int) $poom_dan));
    $grade_level = max(0, min(99, (int) $grade_level));
    return $poom_dan . '품/단 ' . str_pad((string) $grade_level, 2, '0', STR_PAD_LEFT) . '급';
}

function ieum_save_vehicle_assignment($academy_id, $student_id, $ride_type, $enabled, $stop_id, $place_name, $contact_phone, $ride_days, $vehicle_memo)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $ride_type = $ride_type === 'dropoff' ? 'dropoff' : 'pickup';
    $enabled = $enabled ? 1 : 0;
    $stop_id = (int) $stop_id;
    $place_name = trim($place_name);
    $contact_phone = ieum_student_clean_phone($contact_phone);
    $ride_days = ieum_clean_attendance_days($ride_days);
    $vehicle_memo = trim($vehicle_memo);
    $ride_type_sql = sql_escape_string($ride_type);

    sql_query("
        update " . IEUM_STUDENT_VEHICLE_TABLE . "
           set is_active = 0,
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and ride_type = '{$ride_type_sql}'
    ");

    if (!$enabled) {
        return;
    }

    $route_id = 0;
    if ($stop_id) {
        $stop = sql_fetch("
            select route_id, stop_name
              from " . IEUM_VEHICLE_STOP_TABLE . "
             where academy_id = '{$academy_id}'
               and stop_id = '{$stop_id}'
               and stop_type = '{$ride_type_sql}'
               and is_active = 1
             limit 1
        ", false);
        if (isset($stop['route_id'])) {
            $route_id = (int) $stop['route_id'];
            if ($place_name === '') {
                $place_name = $stop['stop_name'];
            }
        }
    }

    $place_name_sql = sql_escape_string($place_name);
    $contact_phone_sql = sql_escape_string($contact_phone);
    $ride_days_sql = sql_escape_string($ride_days);
    $vehicle_memo_sql = sql_escape_string($vehicle_memo);
    sql_query("
        insert into " . IEUM_STUDENT_VEHICLE_TABLE . "
            set academy_id = '{$academy_id}',
                student_id = '{$student_id}',
                ride_type = '{$ride_type_sql}',
                route_id = '{$route_id}',
                stop_id = '{$stop_id}',
                place_name = '{$place_name_sql}',
                contact_phone = '{$contact_phone_sql}',
                ride_days = '{$ride_days_sql}',
                memo = '{$vehicle_memo_sql}',
                is_active = 1,
                created_at = '" . G5_TIME_YMDHIS . "'
    ");
}

function ieum_save_guardians($academy_id, $student_id, $names, $relations, $phones, $checkin_flags, $checkout_flags, $tuition_flags, $code_flags, $primary_flags)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;

    sql_query("
        update " . IEUM_STUDENT_GUARDIAN_TABLE . "
           set is_active = 0,
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
    ");

    $primary = array('name' => '', 'phone' => '');
    $count = max(count($names), count($phones));
    $sort = 0;
    $has_code = false;
    $has_primary = false;
    $has_requested_primary = false;
    foreach ($primary_flags as $flag) {
        if ($flag) {
            $has_requested_primary = true;
            break;
        }
    }

    for ($i = 0; $i < $count; $i++) {
        $name = isset($names[$i]) ? trim($names[$i]) : '';
        $relation = isset($relations[$i]) ? trim($relations[$i]) : '';
        $phone = isset($phones[$i]) ? ieum_student_clean_phone($phones[$i]) : '';
        if ($name === '' && $phone === '') {
            continue;
        }

        $sort++;
        $use_code = isset($code_flags[$i]) ? 1 : 0;
        $is_primary = isset($primary_flags[$i]) ? 1 : 0;
        if (!$has_requested_primary && !$has_primary) {
            $is_primary = 1;
        }
        if (!$has_code && $use_code) {
            $has_code = true;
        } elseif ($use_code) {
            $use_code = 0;
        }
        if (!$has_primary && $is_primary) {
            $has_primary = true;
            $primary = array('name' => $name, 'phone' => $phone);
        } elseif ($is_primary) {
            $is_primary = 0;
        }
        if ($primary['phone'] === '' && $phone !== '') {
            $primary = array('name' => $name, 'phone' => $phone);
        }

        $name_sql = sql_escape_string($name);
        $relation_sql = sql_escape_string($relation);
        $phone_sql = sql_escape_string($phone);
        $checkin = isset($checkin_flags[$i]) ? 1 : 0;
        $checkout = isset($checkout_flags[$i]) ? 1 : 0;
        $tuition = isset($tuition_flags[$i]) ? 1 : 0;

        sql_query("
            insert into " . IEUM_STUDENT_GUARDIAN_TABLE . "
                set academy_id = '{$academy_id}',
                    student_id = '{$student_id}',
                    guardian_name = '{$name_sql}',
                    guardian_relation = '{$relation_sql}',
                    guardian_phone = '{$phone_sql}',
                    sms_attendance = '{$checkin}',
                    sms_checkout = '{$checkout}',
                    sms_tuition = '{$tuition}',
                    use_for_student_code = '{$use_code}',
                    is_primary = '{$is_primary}',
                    sort_order = '{$sort}',
                    is_active = 1,
                    created_at = '" . G5_TIME_YMDHIS . "'
        ");
    }

    return $primary;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도하세요.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $post_student_id = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;

        if ($action === 'save') {
            $save_flow = isset($_POST['save_flow']) ? trim($_POST['save_flow']) : 'list';
            $student_code = isset($_POST['student_code']) ? preg_replace('/[^0-9]/', '', trim($_POST['student_code'])) : '';
            $student_name = isset($_POST['student_name']) ? trim($_POST['student_name']) : '';
            $student_phone = isset($_POST['student_phone']) ? ieum_student_clean_phone($_POST['student_phone']) : '';
            $birth_date = isset($_POST['birth_date']) ? preg_replace('/[^0-9-]/', '', trim($_POST['birth_date'])) : '';
            if ($birth_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
                $birth_date = '';
            } elseif ($birth_date !== '') {
                $birth_parts = explode('-', $birth_date);
                if (!checkdate((int) $birth_parts[1], (int) $birth_parts[2], (int) $birth_parts[0])) {
                    $birth_date = '';
                }
            }
            $school_name = isset($_POST['school_name']) ? trim($_POST['school_name']) : '';
            $program_code = isset($_POST['program_code']) ? ieum_program_code($_POST['program_code']) : '';
            $grade_group = isset($_POST['grade_group']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_POST['grade_group'])) : '';
            $auto_grade_group = ieum_grade_from_birth_date($birth_date);
            if ($auto_grade_group !== '') {
                $grade_group = $auto_grade_group;
            }
            $class_time_id = isset($_POST['class_time_id']) ? (int) $_POST['class_time_id'] : 0;
            $attendance_week_type = isset($_POST['attendance_week_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['attendance_week_type'])) : '5';
            if (!isset(ieum_attendance_week_type_options()[$attendance_week_type])) {
                $attendance_week_type = 'custom';
            }
            $attendance_days = ieum_clean_attendance_days(isset($_POST['attendance_days']) ? $_POST['attendance_days'] : array());
            $student_status = isset($_POST['student_status']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['student_status'])) : 'enrolled';
            if (!isset(ieum_student_status_options()[$student_status])) {
                $student_status = 'enrolled';
            }
            $enrollment_source = isset($_POST['enrollment_source']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['enrollment_source'])) : '';
            if (!isset(ieum_enrollment_source_options()[$enrollment_source])) {
                $enrollment_source = '';
            }
            $referrer_name = isset($_POST['referrer_name']) ? trim($_POST['referrer_name']) : '';
            $counseling_note = isset($_POST['counseling_note']) ? trim($_POST['counseling_note']) : '';
            $admission_date = isset($_POST['admission_date']) ? preg_replace('/[^0-9-]/', '', trim($_POST['admission_date'])) : '';
            if ($admission_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $admission_date)) {
                $admission_date = '';
            } elseif ($admission_date !== '') {
                $date_parts = explode('-', $admission_date);
                if (!checkdate((int) $date_parts[1], (int) $date_parts[2], (int) $date_parts[0])) {
                    $admission_date = '';
                }
            }
            $tuition_week_type = isset($_POST['tuition_week_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['tuition_week_type'])) : $attendance_week_type;
            if (!isset(ieum_attendance_week_type_options()[$tuition_week_type])) {
                $tuition_week_type = $attendance_week_type;
            }
            $tuition_amount = isset($_POST['tuition_amount']) ? max(0, (int) $_POST['tuition_amount']) : 0;
            $sibling_discount_enabled = isset($_POST['sibling_discount_enabled']) ? 1 : 0;
            $sibling_discount_amount = isset($_POST['sibling_discount_amount']) ? max(0, (int) $_POST['sibling_discount_amount']) : 0;
            if (!$sibling_discount_enabled) {
                $sibling_discount_amount = 0;
            }
            $tuition_due_day = isset($_POST['tuition_due_day']) ? (int) $_POST['tuition_due_day'] : 5;
            if ($tuition_due_day < 1) {
                $tuition_due_day = 1;
            } elseif ($tuition_due_day > 31) {
                $tuition_due_day = 31;
            }
            $tuition_note = isset($_POST['tuition_note']) ? trim($_POST['tuition_note']) : '';
            $promotion_enabled = isset($_POST['promotion_enabled']) ? 1 : 0;
            $current_belt = isset($_POST['current_belt']) ? trim($_POST['current_belt']) : '';
            if (function_exists('mb_substr')) {
                $current_belt = mb_substr($current_belt, 0, 80, 'UTF-8');
            } else {
                $current_belt = substr($current_belt, 0, 80);
            }
            $current_poom_dan = isset($_POST['current_poom_dan']) ? (int) $_POST['current_poom_dan'] : 0;
            if ($current_poom_dan < 0) {
                $current_poom_dan = 0;
            } elseif ($current_poom_dan > 4) {
                $current_poom_dan = 4;
            }
            $current_grade_level = isset($_POST['current_grade_level']) ? (int) $_POST['current_grade_level'] : 0;
            if ($current_grade_level < 0) {
                $current_grade_level = 0;
            } elseif ($current_grade_level > 18) {
                $current_grade_level = 18;
            }
            $last_promotion_date = isset($_POST['last_promotion_date']) ? preg_replace('/[^0-9-]/', '', trim($_POST['last_promotion_date'])) : '';
            if ($last_promotion_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_promotion_date)) {
                $last_promotion_date = '';
            } elseif ($last_promotion_date !== '') {
                $last_promotion_parts = explode('-', $last_promotion_date);
                if (!checkdate((int) $last_promotion_parts[1], (int) $last_promotion_parts[2], (int) $last_promotion_parts[0])) {
                    $last_promotion_date = '';
                }
            }
            $promotion_cycle_months = isset($_POST['promotion_cycle_months']) ? (int) $_POST['promotion_cycle_months'] : 0;
            if ($promotion_cycle_months < 0 || $promotion_cycle_months > 4) {
                $promotion_cycle_months = 0;
            }
            $promotion_memo = isset($_POST['promotion_memo']) ? trim($_POST['promotion_memo']) : '';
            $vehicle_pickup_enabled = isset($_POST['vehicle_pickup_enabled']) ? 1 : 0;
            $vehicle_pickup_stop_id = isset($_POST['vehicle_pickup_stop_id']) ? (int) $_POST['vehicle_pickup_stop_id'] : 0;
            $vehicle_pickup_place = isset($_POST['vehicle_pickup_place']) ? trim($_POST['vehicle_pickup_place']) : '';
            $vehicle_pickup_contact_phone = isset($_POST['vehicle_pickup_contact_phone']) ? ieum_student_clean_phone($_POST['vehicle_pickup_contact_phone']) : '';
            $vehicle_pickup_days = isset($_POST['vehicle_pickup_days']) ? $_POST['vehicle_pickup_days'] : array();
            $vehicle_pickup_memo = isset($_POST['vehicle_pickup_memo']) ? trim($_POST['vehicle_pickup_memo']) : '';
            $vehicle_dropoff_enabled = isset($_POST['vehicle_dropoff_enabled']) ? 1 : 0;
            $vehicle_dropoff_stop_id = isset($_POST['vehicle_dropoff_stop_id']) ? (int) $_POST['vehicle_dropoff_stop_id'] : 0;
            $vehicle_dropoff_place = isset($_POST['vehicle_dropoff_place']) ? trim($_POST['vehicle_dropoff_place']) : '';
            $vehicle_dropoff_contact_phone = isset($_POST['vehicle_dropoff_contact_phone']) ? ieum_student_clean_phone($_POST['vehicle_dropoff_contact_phone']) : '';
            $vehicle_dropoff_days = isset($_POST['vehicle_dropoff_days']) ? $_POST['vehicle_dropoff_days'] : array();
            $vehicle_dropoff_memo = isset($_POST['vehicle_dropoff_memo']) ? trim($_POST['vehicle_dropoff_memo']) : '';
            if ($vehicle_pickup_enabled && ieum_clean_attendance_days($vehicle_pickup_days) === '') {
                $vehicle_pickup_days = explode(',', $attendance_days);
            }
            if ($vehicle_dropoff_enabled && ieum_clean_attendance_days($vehicle_dropoff_days) === '') {
                $vehicle_dropoff_days = explode(',', $attendance_days);
            }
            $guardian_names = isset($_POST['guardian_name']) && is_array($_POST['guardian_name']) ? $_POST['guardian_name'] : array();
            $guardian_relations = isset($_POST['guardian_relation']) && is_array($_POST['guardian_relation']) ? $_POST['guardian_relation'] : array();
            $guardian_phones = isset($_POST['guardian_phone']) && is_array($_POST['guardian_phone']) ? $_POST['guardian_phone'] : array();
            $guardian_sms_attendance = isset($_POST['guardian_sms_attendance']) && is_array($_POST['guardian_sms_attendance']) ? $_POST['guardian_sms_attendance'] : array();
            $guardian_sms_checkout = isset($_POST['guardian_sms_checkout']) && is_array($_POST['guardian_sms_checkout']) ? $_POST['guardian_sms_checkout'] : array();
            $guardian_sms_tuition = isset($_POST['guardian_sms_tuition']) && is_array($_POST['guardian_sms_tuition']) ? $_POST['guardian_sms_tuition'] : array();
            $guardian_use_code = isset($_POST['guardian_use_code']) && is_array($_POST['guardian_use_code']) ? $_POST['guardian_use_code'] : array();
            $guardian_primary = isset($_POST['guardian_primary']) && is_array($_POST['guardian_primary']) ? $_POST['guardian_primary'] : array();
            $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $delete_photo = isset($_POST['delete_student_photo']) ? 1 : 0;
            $has_guardian_phone = false;
            $fallback_code = '';
            $fallback_code_index = null;
            foreach ($guardian_phones as $phone_index => $phone_value) {
                $phone_digits = preg_replace('/[^0-9]/', '', ieum_student_clean_phone($phone_value));
                if ($phone_digits !== '') {
                    $has_guardian_phone = true;
                    if (strlen($phone_digits) >= 4) {
                        if (isset($guardian_use_code[$phone_index])) {
                            $fallback_code = substr($phone_digits, -4);
                            $fallback_code_index = $phone_index;
                            break;
                        }
                        if ($fallback_code === '') {
                            $fallback_code = substr($phone_digits, -4);
                            $fallback_code_index = $phone_index;
                        }
                    }
                }
            }
            if ($student_code === '' && $fallback_code === '') {
                $student_phone_digits = preg_replace('/[^0-9]/', '', $student_phone);
                if (strlen($student_phone_digits) >= 4) {
                    $fallback_code = substr($student_phone_digits, -4);
                }
            }
            if ($student_code === '' && $fallback_code !== '') {
                $student_code = $fallback_code;
                if ($fallback_code_index !== null && !isset($guardian_use_code[$fallback_code_index])) {
                    $guardian_use_code[$fallback_code_index] = 1;
                }
            }

            if ($student_code === '') {
                $error = '원생번호를 입력하세요.';
            } elseif ($student_name === '') {
                $error = '원생명을 입력하세요.';
            } elseif ($attendance_days === '') {
                $error = '출석 요일을 1개 이상 선택하세요.';
            } elseif (!$has_guardian_phone) {
                $error = '보호자 연락처를 1개 이상 입력하세요.';
            } else {
                $student_code_sql = sql_escape_string($student_code);
                    $duplicate_code_rows = ieum_student_duplicate_code_rows($academy_id, $student_code, $post_student_id);
                    $student_name_sql = sql_escape_string($student_name);
                    $student_phone_sql = sql_escape_string($student_phone);
                    $birth_date_sql = sql_escape_string($birth_date);
                    $program_code_sql = sql_escape_string($program_code);
                    $school_name_sql = sql_escape_string($school_name);
                    $grade_group_sql = sql_escape_string($grade_group);
                    $attendance_week_type_sql = sql_escape_string($attendance_week_type);
                    $attendance_days_sql = sql_escape_string($attendance_days);
                    $student_status_sql = sql_escape_string($student_status);
                    $enrollment_source_sql = sql_escape_string($enrollment_source);
                    $referrer_name_sql = sql_escape_string($referrer_name);
                    $counseling_note_sql = sql_escape_string($counseling_note);
                    $admission_date_sql = sql_escape_string($admission_date);
                    $tuition_week_type_sql = sql_escape_string($tuition_week_type);
                    $tuition_note_sql = sql_escape_string($tuition_note);
                    $current_belt_sql = sql_escape_string($current_belt);
                    $last_promotion_date_sql = sql_escape_string($last_promotion_date);
                    $promotion_memo_sql = sql_escape_string($promotion_memo);
                    $vehicle_pickup_place_sql = sql_escape_string($vehicle_pickup_place);
                    $vehicle_dropoff_place_sql = sql_escape_string($vehicle_dropoff_place);
                    $memo_sql = sql_escape_string($memo);
                    $birth_date_set = $birth_date_sql === '' ? "birth_date = null" : "birth_date = '{$birth_date_sql}'";
                    $admission_set = $admission_date_sql === '' ? "admission_date = null" : "admission_date = '{$admission_date_sql}'";
                    $last_promotion_set = $last_promotion_date_sql === '' ? "last_promotion_date = null" : "last_promotion_date = '{$last_promotion_date_sql}'";

                    if ($post_student_id) {
                        $before_student = ieum_fetch_student($post_student_id);
                        $before_status = $before_student && isset($before_student['student_status']) ? $before_student['student_status'] : ($before_student && $before_student['is_active'] ? 'enrolled' : 'withdrawn');
                        sql_query("
                            update " . IEUM_STUDENT_TABLE . "
                               set student_code = '{$student_code_sql}',
                                   student_name = '{$student_name_sql}',
                                   student_phone = '{$student_phone_sql}',
                                   {$birth_date_set},
                                   program_code = '{$program_code_sql}',
                                   school_name = '{$school_name_sql}',
                                   grade_group = '{$grade_group_sql}',
                                   class_time_id = '{$class_time_id}',
                                   attendance_week_type = '{$attendance_week_type_sql}',
                                   attendance_days = '{$attendance_days_sql}',
                                   student_status = '{$student_status_sql}',
                                   enrollment_source = '{$enrollment_source_sql}',
                                   referrer_name = '{$referrer_name_sql}',
                                   counseling_note = '{$counseling_note_sql}',
                                   {$admission_set},
                                   tuition_week_type = '{$tuition_week_type_sql}',
                                   tuition_amount = '{$tuition_amount}',
                                   sibling_discount_enabled = '{$sibling_discount_enabled}',
                                   sibling_discount_amount = '{$sibling_discount_amount}',
                                   tuition_due_day = '{$tuition_due_day}',
                                   tuition_note = '{$tuition_note_sql}',
                                   promotion_enabled = '{$promotion_enabled}',
                                   current_belt = '{$current_belt_sql}',
                                   current_poom_dan = '{$current_poom_dan}',
                                   current_grade_level = '{$current_grade_level}',
                                   {$last_promotion_set},
                                   promotion_cycle_months = '{$promotion_cycle_months}',
                                   promotion_memo = '{$promotion_memo_sql}',
                                   vehicle_pickup_enabled = '{$vehicle_pickup_enabled}',
                                   vehicle_pickup_place = '{$vehicle_pickup_place_sql}',
                                   vehicle_dropoff_enabled = '{$vehicle_dropoff_enabled}',
                                   vehicle_dropoff_place = '{$vehicle_dropoff_place_sql}',
                                   memo = '{$memo_sql}',
                                   is_active = '{$is_active}',
                                   updated_at = '" . G5_TIME_YMDHIS . "'
                             where student_id = '{$post_student_id}'
                               and academy_id = '{$academy_id}'
                        ");
                        $saved_student_id = $post_student_id;
                        $last_saved_student_id = $saved_student_id;
                        $last_saved_student_name = $student_name;
                        ieum_log_student_status_change($academy_id, $saved_student_id, $before_status, $student_status, '원생 정보 수정');
                        $message = '원생 정보가 수정되었습니다.';
                    } else {
                        sql_query("
                            insert into " . IEUM_STUDENT_TABLE . "
                                set academy_id = '{$academy_id}',
                                    student_code = '{$student_code_sql}',
                                    student_name = '{$student_name_sql}',
                                    student_phone = '{$student_phone_sql}',
                                    {$birth_date_set},
                                    program_code = '{$program_code_sql}',
                                    school_name = '{$school_name_sql}',
                                    grade_group = '{$grade_group_sql}',
                                    class_time_id = '{$class_time_id}',
                                    attendance_week_type = '{$attendance_week_type_sql}',
                                    attendance_days = '{$attendance_days_sql}',
                                    student_status = '{$student_status_sql}',
                                    enrollment_source = '{$enrollment_source_sql}',
                                    referrer_name = '{$referrer_name_sql}',
                                    counseling_note = '{$counseling_note_sql}',
                                    {$admission_set},
                                    tuition_week_type = '{$tuition_week_type_sql}',
                                    tuition_amount = '{$tuition_amount}',
                                    sibling_discount_enabled = '{$sibling_discount_enabled}',
                                    sibling_discount_amount = '{$sibling_discount_amount}',
                                    tuition_due_day = '{$tuition_due_day}',
                                    tuition_note = '{$tuition_note_sql}',
                                    promotion_enabled = '{$promotion_enabled}',
                                    current_belt = '{$current_belt_sql}',
                                    current_poom_dan = '{$current_poom_dan}',
                                    current_grade_level = '{$current_grade_level}',
                                    {$last_promotion_set},
                                    promotion_cycle_months = '{$promotion_cycle_months}',
                                    promotion_memo = '{$promotion_memo_sql}',
                                    vehicle_pickup_enabled = '{$vehicle_pickup_enabled}',
                                    vehicle_pickup_place = '{$vehicle_pickup_place_sql}',
                                    vehicle_dropoff_enabled = '{$vehicle_dropoff_enabled}',
                                    vehicle_dropoff_place = '{$vehicle_dropoff_place_sql}',
                                    memo = '{$memo_sql}',
                                    is_active = '{$is_active}',
                                    created_at = '" . G5_TIME_YMDHIS . "'
                        ");
                        $saved_student_id = sql_insert_id();
                        $last_saved_student_id = $saved_student_id;
                        $last_saved_student_name = $student_name;
                        ieum_log_student_status_change($academy_id, $saved_student_id, '', $student_status, '원생 신규 등록');
                        $message = '원생이 등록되었습니다.';
                    }
                    $primary_guardian = ieum_save_guardians(
                        $academy_id,
                        $saved_student_id,
                        $guardian_names,
                        $guardian_relations,
                        $guardian_phones,
                        $guardian_sms_attendance,
                        $guardian_sms_checkout,
                        $guardian_sms_tuition,
                        $guardian_use_code,
                        $guardian_primary
                    );
                    sql_query("
                        update " . IEUM_STUDENT_TABLE . "
                           set parent_name = '" . sql_escape_string($primary_guardian['name']) . "',
                               parent_phone = '" . sql_escape_string($primary_guardian['phone']) . "'
                         where academy_id = '{$academy_id}'
                           and student_id = '{$saved_student_id}'
                    ");
                    ieum_save_vehicle_assignment($academy_id, $saved_student_id, 'pickup', $vehicle_pickup_enabled, $vehicle_pickup_stop_id, $vehicle_pickup_place, $vehicle_pickup_contact_phone, $vehicle_pickup_days, $vehicle_pickup_memo);
                    ieum_save_vehicle_assignment($academy_id, $saved_student_id, 'dropoff', $vehicle_dropoff_enabled, $vehicle_dropoff_stop_id, $vehicle_dropoff_place, $vehicle_dropoff_contact_phone, $vehicle_dropoff_days, $vehicle_dropoff_memo);
                    if ($delete_photo) {
                        sql_query("
                            update " . IEUM_STUDENT_TABLE . "
                               set student_photo = '',
                                   updated_at = '" . G5_TIME_YMDHIS . "'
                             where academy_id = '{$academy_id}'
                               and student_id = '{$saved_student_id}'
                        ");
                    }
                    $uploaded_photo = ieum_save_student_photo_upload($academy_id, $saved_student_id);
                    if ($uploaded_photo !== '') {
                        sql_query("
                            update " . IEUM_STUDENT_TABLE . "
                               set student_photo = '" . sql_escape_string($uploaded_photo) . "',
                                   updated_at = '" . G5_TIME_YMDHIS . "'
                             where academy_id = '{$academy_id}'
                               and student_id = '{$saved_student_id}'
                        ");
                    }
                    if (!empty($duplicate_code_rows)) {
                        $message .= ' 같은 원생번호를 쓰는 원생이 있어 출석기에서는 이름/생년월일로 선택하게 됩니다.';
                    }
                    if ($embed_mode && $saved_student_id > 0) {
                        $mode = 'form';
                        $student_id = $saved_student_id;
                    } elseif ($save_flow === 'new' && $post_student_id <= 0) {
                        $mode = 'form';
                        $student_id = 0;
                    } else {
                        $mode = 'list';
                        $student_id = 0;
                    }
            }
        } elseif ($action === 'change_status') {
            $target = ieum_fetch_student($post_student_id);
            $next_status = isset($_POST['quick_student_status']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['quick_student_status'])) : '';
            $quick_status_options = ieum_student_quick_status_options();
            if (!$target) {
                $error = '원생 정보를 찾을 수 없습니다.';
            } elseif (!isset($quick_status_options[$next_status])) {
                $error = '변경할 원생 상태를 선택하세요.';
            } else {
                $next_active = ieum_student_status_active_value($next_status);
                $before_status = ieum_student_row_status_value($target);
                sql_query("
                    update " . IEUM_STUDENT_TABLE . "
                       set is_active = '{$next_active}',
                           student_status = '" . sql_escape_string($next_status) . "',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where student_id = '{$post_student_id}'
                       and academy_id = '{$academy_id}'
                ");
                ieum_log_student_status_change($academy_id, $post_student_id, $before_status, $next_status, '원생 상태 빠른 변경');
                $message = get_text($target['student_name']) . ' 원생 상태를 ' . $quick_status_options[$next_status] . '으로 변경했습니다.';
            }
            $mode = 'list';
            $student_id = 0;
        } elseif ($action === 'toggle') {
            $target = ieum_fetch_student($post_student_id);
            if (!$target) {
                $error = '원생 정보를 찾을 수 없습니다.';
            } else {
                $next_active = $target['is_active'] ? 0 : 1;
                $next_status = $next_active ? 'enrolled' : 'withdrawn';
                $before_status = isset($target['student_status']) && $target['student_status'] !== '' ? $target['student_status'] : ($target['is_active'] ? 'enrolled' : 'withdrawn');
                sql_query("
                    update " . IEUM_STUDENT_TABLE . "
                       set is_active = '{$next_active}',
                           student_status = '{$next_status}',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where student_id = '{$post_student_id}'
                       and academy_id = '{$academy_id}'
                ");
                ieum_log_student_status_change($academy_id, $post_student_id, $before_status, $next_status, $next_active ? '재원 처리' : '퇴관 처리');
                $message = $next_active ? '원생 상태를 재원으로 변경했습니다.' : '원생 상태를 퇴관으로 변경했습니다.';
            }
            $mode = 'list';
            $student_id = 0;
        } elseif ($action === 'quick_counseling_note') {
            $target = ieum_fetch_student($post_student_id);
            $quick_note = isset($_POST['quick_counseling_note']) ? trim($_POST['quick_counseling_note']) : '';
            if (!$target) {
                $error = '원생 정보를 찾을 수 없습니다.';
            } elseif ($quick_note === '') {
                $error = '상담 메모를 입력하세요.';
            } else {
                sql_query("
                    update " . IEUM_STUDENT_TABLE . "
                       set counseling_note = '" . sql_escape_string($quick_note) . "',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where student_id = '{$post_student_id}'
                       and academy_id = '{$academy_id}'
                ");
                $message = get_text($target['student_name']) . ' 원생 상담 메모를 저장했습니다.';
            }
            $mode = 'list';
            $student_id = 0;
        } elseif ($action === 'queue_care_sms') {
            $target = ieum_fetch_student($post_student_id);
            $care_message_type = isset($_POST['care_message_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['care_message_type'])) : 'student';
            $care_message = isset($_POST['care_message']) ? trim((string) $_POST['care_message']) : '';
            if (function_exists('mb_substr')) {
                $care_message = mb_substr($care_message, 0, 500, 'UTF-8');
            } else {
                $care_message = substr($care_message, 0, 500);
            }
            if (!$target) {
                $error = '원생 정보를 찾을 수 없습니다.';
            } else {
                $created_count = ieum_queue_student_care_sms($academy_id, $post_student_id, $care_message_type, $care_message);
                if ($created_count > 0) {
                    $message = get_text($target['student_name']) . ' 원생 보호자 문자 발송을 ' . number_format($created_count) . '건 준비했습니다.';
                } else {
                    $error = get_text($target['student_name']) . ' 원생에게 문자를 받을 보호자 연락처가 없습니다.';
                }
            }
            $mode = 'list';
            $student_id = 0;
        } elseif ($action === 'resolve_care_signal') {
            $target = ieum_fetch_student($post_student_id);
            $care_signal_key = isset($_POST['care_signal_key']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['care_signal_key'])) : '';
            $resolve_type = ieum_student_signal_resolve_type($care_signal_key);
            if (!$target) {
                $error = '원생 정보를 찾을 수 없습니다.';
            } elseif ($resolve_type === '') {
                $error = '확인 처리할 관리 신호를 찾을 수 없습니다.';
            } else {
                ieum_dashboard_resolve_auto_check($academy_id, $resolve_type, (string) $post_student_id, G5_TIME_YMD, isset($member['mb_id']) ? $member['mb_id'] : '', '원생관리 오늘 확인 처리');
                $message = get_text($target['student_name']) . ' 원생의 관리 신호를 오늘 확인 처리했습니다.';
            }
            $mode = 'list';
            $student_id = 0;
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$student_shortcut_catalog = function_exists('ieum_dashboard_shortcut_catalog') ? ieum_dashboard_shortcut_catalog() : array();
$student_shortcut_keys = function_exists('ieum_dashboard_get_shortcut_keys') ? ieum_dashboard_get_shortcut_keys($academy_id) : array();
$student_default_shortcut_keys = function_exists('ieum_dashboard_default_shortcut_keys') ? ieum_dashboard_default_shortcut_keys() : array();
$editing = null;
if ($mode === 'form' && $student_id) {
    $editing = ieum_fetch_student($student_id);
    if (!$editing) {
        $mode = 'list';
        $error = '원생 정보를 찾을 수 없습니다.';
    }
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$program_options = ieum_program_options($academy_id, true);
$default_program_code = '';
foreach ($program_options as $program_option) {
    if ((int) $program_option['is_default'] === 1) {
        $default_program_code = $program_option['program_code'];
        break;
    }
}
if ($default_program_code === '' && isset($program_options[0])) {
    $default_program_code = $program_options[0]['program_code'];
}

$filter_program = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$filter_grade = isset($_GET['grade_group']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_GET['grade_group'])) : '';
$filter_class_time_raw = isset($_GET['class_time_id']) ? trim($_GET['class_time_id']) : '';
$filter_class_time_id = ctype_digit($filter_class_time_raw) ? (int) $filter_class_time_raw : 0;
$filter_insight = isset($_GET['insight']) ? preg_replace('/[^0-9a-z_]/', '', trim($_GET['insight'])) : '';
$filter_open_only = isset($_GET['open']) && $_GET['open'] === '1';
$page_size_options = array(10, 25, 50);
$page_size = isset($_GET['page_size']) && in_array((int) $_GET['page_size'], $page_size_options, true) ? (int) $_GET['page_size'] : 25;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$insight_options = array(
    '' => '전체 신호',
    'today_class' => '오늘 수업',
    'missing_today' => '오늘 미등원',
    'memo' => '아이들 메모',
    'birthday_month' => '이번 달 생일자',
    'birthday_week' => '7일 이내 생일',
    'long_absent' => '장기 미등원',
    'no_guardian' => '연락처 누락',
    'vehicle_unassigned' => '차량 미배정',
    'tuition_unpaid' => '수련비 미납',
    'report_blocked' => '리포트 발송 불가',
);
if (!isset($insight_options[$filter_insight])) {
    $filter_insight = '';
}
$q_sql = sql_escape_string($q);

function ieum_student_list_url($overrides = array())
{
    global $q, $filter_program, $filter_grade, $filter_class_time_raw, $filter_insight, $filter_open_only, $page_size;

    $params = array(
        'q' => $q,
        'program_code' => $filter_program,
        'grade_group' => $filter_grade,
        'class_time_id' => $filter_class_time_raw,
        'insight' => $filter_insight,
        'open' => $filter_open_only ? '1' : '',
        'page_size' => $page_size,
    );
    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    $query = http_build_query($params);
    return IEUM_URL . '/admin/students.php' . ($query ? '?' . $query : '');
}

function ieum_student_insight_where($alias, $insight, $today)
{
    $alias = preg_replace('/[^0-9A-Za-z_]/', '', $alias);
    if ($alias === '') {
        $alias = 's';
    }
    $today_sql = sql_escape_string($today);
    $academy_id = isset($GLOBALS['academy_id']) ? (int) $GLOBALS['academy_id'] : 0;
    $today_day_filter_sql = function_exists('ieum_attendance_student_day_filter_sql') ? ieum_attendance_student_day_filter_sql($academy_id, $today, $alias) : '';
    if ($insight === 'today_class') {
        return " and {$alias}.is_active = 1 {$today_day_filter_sql} ";
    }
    if ($insight === 'missing_today') {
        return " and {$alias}.is_active = 1
                 {$today_day_filter_sql}
                 and not exists (
                     select 1
                       from " . IEUM_ATTENDANCE_TABLE . " ia
                      where ia.academy_id = {$alias}.academy_id
                        and ia.student_id = {$alias}.student_id
                        and ia.attendance_date = '{$today_sql}'
                 ) ";
    }
    if ($insight === 'birthday_month') {
        return " and {$alias}.is_active = 1 and {$alias}.birth_date is not null and {$alias}.birth_date <> '0000-00-00' and month({$alias}.birth_date) = month('{$today_sql}') ";
    }
    if ($insight === 'birthday_week') {
        return " and {$alias}.is_active = 1 and {$alias}.birth_date is not null and {$alias}.birth_date <> '0000-00-00' and str_to_date(concat(year('{$today_sql}'), date_format({$alias}.birth_date, '-%m-%d')), '%Y-%m-%d') between '{$today_sql}' and date_add('{$today_sql}', interval 7 day) ";
    }
    if ($insight === 'memo') {
        return " and {$alias}.is_active = 1
                 and (
                     {$alias}.memo <> ''
                     or {$alias}.counseling_note <> ''
                     or {$alias}.promotion_memo <> ''
                     or {$alias}.tuition_note <> ''
                     or exists (
                         select 1
                           from " . IEUM_STUDENT_VEHICLE_TABLE . " ivm
                          where ivm.academy_id = {$alias}.academy_id
                            and ivm.student_id = {$alias}.student_id
                            and ivm.is_active = 1
                            and ivm.memo <> ''
                     )
                 ) ";
    }
    if ($insight === 'long_absent') {
        return " and {$alias}.is_active = 1
                 and coalesce({$alias}.admission_date, date({$alias}.created_at)) <= date_sub('{$today_sql}', interval 14 day)
                 and not exists (
                     select 1
                       from " . IEUM_ATTENDANCE_TABLE . " ia
                      where ia.academy_id = {$alias}.academy_id
                        and ia.student_id = {$alias}.student_id
                        and ia.attendance_date >= date_sub('{$today_sql}', interval 13 day)
                 ) ";
    }
    if ($insight === 'no_guardian') {
        return " and {$alias}.is_active = 1
                 and not exists (
                     select 1
                       from " . IEUM_STUDENT_GUARDIAN_TABLE . " ig
                      where ig.academy_id = {$alias}.academy_id
                        and ig.student_id = {$alias}.student_id
                        and ig.is_active = 1
                        and ig.guardian_phone <> ''
                 ) ";
    }
    if ($insight === 'vehicle_unassigned') {
        return " and {$alias}.is_active = 1
                 and not exists (
                     select 1
                       from " . IEUM_STUDENT_VEHICLE_TABLE . " iv
                      where iv.academy_id = {$alias}.academy_id
                        and iv.student_id = {$alias}.student_id
                        and iv.is_active = 1
                 ) ";
    }
    if ($insight === 'tuition_unpaid') {
        $billing_month = sql_escape_string(date('Y-m', strtotime($today)));
        return " and {$alias}.is_active = 1
                 and (
                     exists (
                         select 1
                           from " . IEUM_TUITION_PAYMENT_TABLE . " itp
                          where itp.academy_id = {$alias}.academy_id
                            and itp.student_id = {$alias}.student_id
                            and itp.billing_month = '{$billing_month}'
                            and itp.status <> 'paid'
                            and coalesce(itp.amount_due, 0) > coalesce(itp.amount_paid, 0)
                     )
                     or (
                         coalesce({$alias}.tuition_amount, 0) > 0
                         and not exists (
                             select 1
                               from " . IEUM_TUITION_PAYMENT_TABLE . " itp2
                              where itp2.academy_id = {$alias}.academy_id
                                and itp2.student_id = {$alias}.student_id
                                and itp2.billing_month = '{$billing_month}'
                         )
                     )
                 ) ";
    }
    if ($insight === 'report_blocked') {
        return " and {$alias}.is_active = 1
                 and (
                     {$alias}.birth_date is null
                     or {$alias}.birth_date = '0000-00-00'
                     or not exists (
                         select 1
                           from " . IEUM_STUDENT_GUARDIAN_TABLE . " ig
                          where ig.academy_id = {$alias}.academy_id
                            and ig.student_id = {$alias}.student_id
                            and ig.is_active = 1
                            and ig.guardian_phone <> ''
                     )
                 ) ";
    }

    return '';
}

function ieum_student_open_signal_where($alias, $insight, $today)
{
    global $academy_id;

    if (!function_exists('ieum_dashboard_auto_check_not_resolved_sql')) {
        return '';
    }
    $alias = preg_replace('/[^0-9A-Za-z_]/', '', $alias);
    if ($alias === '') {
        $alias = 's';
    }
    $map = array(
        'long_absent' => 'long_absent',
        'no_guardian' => 'no_guardian',
        'report_blocked' => 'report_blocked',
        'memo' => 'student_care_notes',
        'birthday_month' => 'birthday',
        'birthday_week' => 'birthday',
    );
    if (!isset($map[$insight])) {
        return '';
    }

    return ieum_dashboard_auto_check_not_resolved_sql(
        'acr_' . $alias . '_' . $insight,
        (int) $academy_id,
        $map[$insight],
        "cast({$alias}.student_id as char)",
        $today
    );
}

function ieum_student_days_from_last_care($row, $today)
{
    $base = '';
    if (!empty($row['last_attendance_date'])) {
        $base = $row['last_attendance_date'];
    } elseif (!empty($row['admission_date']) && $row['admission_date'] !== '0000-00-00') {
        $base = $row['admission_date'];
    } elseif (!empty($row['created_at'])) {
        $base = substr((string) $row['created_at'], 0, 10);
    }

    if ($base === '') {
        return 0;
    }

    $base_ts = strtotime(substr($base, 0, 10));
    $today_ts = strtotime($today);
    if ($base_ts === false || $today_ts === false || $today_ts < $base_ts) {
        return 0;
    }

    return (int) floor(($today_ts - $base_ts) / 86400);
}

function ieum_student_birthday_signal($birth_date, $today)
{
    if (!$birth_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date) || $birth_date === '0000-00-00') {
        return array('', false, false);
    }

    $today_ts = strtotime($today);
    $birthday_ts = strtotime(date('Y', $today_ts) . substr($birth_date, 4));
    if ($birthday_ts === false || $birthday_ts < $today_ts) {
        $birthday_ts = strtotime('+1 year', $birthday_ts);
    }
    $days = (int) floor(($birthday_ts - $today_ts) / 86400);
    $same_month = date('m', strtotime($birth_date)) === date('m', $today_ts);
    if ($days >= 0 && $days <= 7) {
        return array($days === 0 ? '생일 오늘' : '생일 D-' . $days, true, $same_month);
    }

    return array($same_month ? '이번달 생일' : '', false, $same_month);
}

function ieum_student_care_signals($row, $today)
{
    $signals = array();
    $add = function ($key, $label, $tone, $action) use (&$signals) {
        if (!isset($signals[$key])) {
            $signals[$key] = array('key' => $key, 'label' => $label, 'tone' => $tone, 'action' => $action);
        }
    };

    $is_active = !empty($row['is_active']);
    $guardian_missing = empty($row['guardian_summary']);
    $birth_missing = empty($row['birth_date']) || $row['birth_date'] === '0000-00-00';
    $tuition_due = max(0, (int) (isset($row['tuition_amount_due']) ? $row['tuition_amount_due'] : 0) - (int) (isset($row['tuition_amount_paid']) ? $row['tuition_amount_paid'] : 0));
    $tuition_status = isset($row['tuition_status']) ? (string) $row['tuition_status'] : '';
    $absent_days = ieum_student_days_from_last_care($row, $today);

    if ($is_active && $absent_days >= 14) {
        $add('long_absent', '장기 미등원 ' . number_format($absent_days) . '일', 'danger', '안부 연락 후 상담 메모를 남깁니다.');
    }
    if ($is_active && $tuition_due > 0 && $tuition_status !== 'paid') {
        $add('tuition_unpaid', '수련비 미납', 'danger', '납부 상태를 확인하고 필요하면 수련비 안내 문자를 보냅니다.');
    }
    if ($is_active && $guardian_missing) {
        $add('no_guardian', '연락처 누락', 'danger', '보호자 연락처와 문자 수신 대상을 보완합니다.');
    }
    if ($is_active && $birth_missing) {
        $add('report_blocked', '리포트 불가', 'danger', '생년월일을 보완해 학년과 리포트 발송 조건을 맞춥니다.');
    }

    list($birthday_label, $birthday_soon, $birthday_month) = ieum_student_birthday_signal(isset($row['birth_date']) ? $row['birth_date'] : '', $today);
    if ($is_active && $birthday_label !== '') {
        $add($birthday_soon ? 'birthday_week' : 'birthday_month', $birthday_label, $birthday_soon ? 'info' : 'warn', '생일 안부 문자 또는 도장 내 축하 준비를 확인합니다.');
    }

    if (!empty($row['vehicle_count'])) {
        $add('vehicle', '차량', 'info', '등원/하원 차량 정보와 탑승 메모를 확인합니다.');
    }
    if (!empty($row['memo'])) {
        $add('memo', '메모', 'warn', '원생 관리 메모를 확인합니다.');
    }
    if (!empty($row['counseling_note'])) {
        $add('counseling', '상담', 'warn', '상담 메모를 확인하고 다음 연락 여부를 정합니다.');
    }
    if (!empty($row['promotion_memo'])) {
        $add('promotion_memo', '승급 메모', 'warn', '승급 관련 메모를 확인합니다.');
    }
    if (!empty($row['tuition_note'])) {
        $add('tuition_note', '수련비 메모', 'warn', '수련비 예외사항을 확인합니다.');
    }
    if (!empty($row['vehicle_memo_count'])) {
        $add('vehicle_memo', '차량 메모', 'warn', '차량 특이사항을 확인합니다.');
    }

    $signals = array_values($signals);
    usort($signals, function ($a, $b) {
        $pa = ieum_student_care_signal_priority(isset($a['key']) ? $a['key'] : '');
        $pb = ieum_student_care_signal_priority(isset($b['key']) ? $b['key'] : '');
        if ($pa === $pb) {
            return strcmp(isset($a['label']) ? $a['label'] : '', isset($b['label']) ? $b['label'] : '');
        }
        return $pa < $pb ? -1 : 1;
    });

    return $signals;
}

function ieum_student_care_signal_priority($key)
{
    $map = array(
        'long_absent' => 10,
        'tuition_unpaid' => 20,
        'report_blocked' => 30,
        'no_guardian' => 35,
        'memo' => 40,
        'counseling' => 41,
        'promotion_memo' => 42,
        'tuition_note' => 43,
        'birthday_week' => 50,
        'birthday_month' => 55,
        'vehicle_memo' => 60,
        'vehicle' => 70,
    );

    return isset($map[$key]) ? $map[$key] : 999;
}

function ieum_student_row_care_priority($row, $today)
{
    $signals = ieum_student_care_signals($row, $today);
    if (!$signals) {
        return 999;
    }

    return ieum_student_care_signal_priority(isset($signals[0]['key']) ? $signals[0]['key'] : '');
}

function ieum_student_care_plan_text($signals)
{
    if (!$signals) {
        return '오늘 특별히 처리할 신호는 없습니다. 기본 정보와 출석 흐름만 확인하면 됩니다.';
    }

    $actions = array();
    foreach ($signals as $signal) {
        if (!empty($signal['action']) && !in_array($signal['action'], $actions, true)) {
            $actions[] = $signal['action'];
        }
    }

    return $actions ? implode("\n", $actions) : '신호를 확인하고 필요한 후속 처리를 진행합니다.';
}

function ieum_student_insight_action_help($insight)
{
    $map = array(
        'missing_today' => '오늘 출석 관리에서 출석 처리하거나 보호자 연락 후 상담 메모를 남깁니다.',
        'today_class' => '오늘 수업 대상만 확인합니다. 출석 처리는 오늘 출석 화면에서 이어갑니다.',
        'long_absent' => '안부 연락 후 상담 메모를 남기고 확인 처리하면 대시보드 오늘 알림에서 빠집니다.',
        'memo' => '원생 이름을 눌러 프로필의 메모와 다음 케어를 확인합니다.',
        'birthday_month' => '생일 축하 문자나 상담 메모로 챙긴 내용을 남깁니다.',
        'birthday_week' => '가까운 생일 원생을 미리 확인하고 생일 안내를 준비합니다.',
        'no_guardian' => '원생 수정에서 보호자 연락처를 보완하면 문자와 리포트 흐름이 정상화됩니다.',
        'vehicle_unassigned' => '차량 메뉴나 원생 수정에서 등원/하원 차량 배정을 보완합니다.',
        'tuition_unpaid' => '수련비 화면에서 납부 상태를 확인하고 필요하면 안내 문자를 보냅니다.',
        'report_blocked' => '생년월일과 보호자 연락처를 보완해야 리포트 발송이 가능합니다.',
    );

    return isset($map[$insight]) ? $map[$insight] : '';
}

function ieum_student_profile_status_text($row)
{
    $lines = array();
    $last_attendance = isset($row['last_attendance_date']) && $row['last_attendance_date']
        ? date('Y-m-d', strtotime($row['last_attendance_date']))
        : '출석 기록 없음';
    $lines[] = '최근 출석: ' . $last_attendance;

    $tuition_due = max(0, (int) (isset($row['tuition_amount_due']) ? $row['tuition_amount_due'] : 0) - (int) (isset($row['tuition_amount_paid']) ? $row['tuition_amount_paid'] : 0));
    if ($tuition_due > 0 && (!isset($row['tuition_status']) || $row['tuition_status'] !== 'paid')) {
        $lines[] = '수련비: 미납 ' . number_format($tuition_due) . '원';
    } elseif ((int) (isset($row['tuition_amount_due']) ? $row['tuition_amount_due'] : 0) > 0) {
        $lines[] = '수련비: 정상';
    } else {
        $lines[] = '수련비: 청구 정보 없음';
    }

    $lines[] = '보호자: ' . (!empty($row['guardian_summary']) ? '등록됨' : '연락처 보완 필요');
    $lines[] = '차량: ' . (!empty($row['vehicle_count']) ? '이용 중' : '이용 없음');

    return implode("\n", $lines);
}

function ieum_student_sms_flow_text($row, $internal_contact_count)
{
    $guardian_count = isset($row['guardian_sms_count']) ? (int) $row['guardian_sms_count'] : 0;
    $lines = array();
    $tuition_due = max(0, (int) (isset($row['tuition_amount_due']) ? $row['tuition_amount_due'] : 0) - (int) (isset($row['tuition_amount_paid']) ? $row['tuition_amount_paid'] : 0));
    $tuition_total = (int) (isset($row['tuition_amount_due']) ? $row['tuition_amount_due'] : 0);

    if ($guardian_count > 0) {
        $lines[] = '보호자 문자: 등록 보호자 ' . number_format($guardian_count) . '명에게 발송할 수 있습니다.';
    } else {
        $lines[] = '보호자 문자: 연락처를 먼저 보완해야 문자를 보낼 수 있습니다.';
    }

    if ($tuition_due > 0 && (!isset($row['tuition_status']) || $row['tuition_status'] !== 'paid')) {
        $lines[] = '수련비 안내: 이번 달 미납 ' . number_format($tuition_due) . '원을 확인하고, 수련비 화면에서 안내 문자를 준비합니다.';
    } elseif ($tuition_total > 0) {
        $lines[] = '수련비 안내: 이번 달 납부 상태가 정리되어 있습니다.';
    } else {
        $lines[] = '수련비 안내: 이번 달 청구 정보가 없으면 수련비 화면에서 먼저 청구를 등록합니다.';
    }

    $lines[] = '안부 문자: 생일, 장기 미등원, 안부 문자는 보호자에게 바로 보낼 수 있습니다.';
    if ($internal_contact_count > 0) {
        $lines[] = '내부 알림: 알림 담당자 ' . number_format((int) $internal_contact_count) . '명이 운영 메모와 시스템 알림을 받을 수 있습니다.';
    } else {
        $lines[] = '내부 알림: 알림 담당자를 등록하면 운영 메모와 시스템 알림을 받을 수 있습니다.';
    }

    return implode("\n", $lines);
}

function ieum_student_sms_status_label($status)
{
    $labels = array(
        'pending' => '발송 준비',
        'processing' => '발송 중',
        'sent' => '발송 완료',
        'failed' => '발송 실패',
        'canceled' => '발송 취소',
    );

    return isset($labels[$status]) ? $labels[$status] : ($status ?: '-');
}

function ieum_student_recent_sms_text($academy_id, $student_id)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $rows = array();
    $result = sql_query("
        select message_type, status, created_at, sent_at
          from " . IEUM_SMS_QUEUE_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
      order by created_at desc, sms_id desc
         limit 3
    ", false);
    while ($row = sql_fetch_array($result)) {
        $time = !empty($row['sent_at']) ? $row['sent_at'] : $row['created_at'];
        $rows[] = substr((string) $time, 0, 16) . ' · ' . ieum_student_sms_status_label($row['status']) . ' · ' . ($row['message_type'] ?: '문자');
    }

    return $rows ? implode("\n", $rows) : '최근 문자 발송 없음';
}

function ieum_student_auto_check_label($check_type)
{
    $labels = array(
        'long_absent' => '장기 미등원 확인',
        'sms_failed' => '문자 실패 확인',
        'tuition_notice' => '수련비 안내 확인',
        'tuition_overdue' => '수련비 확인',
        'promotion_due' => '승급 대상 확인',
        'report_blocked' => '리포트 조건 확인',
        'no_guardian' => '연락처 누락 확인',
        'student_care_notes' => '아이들 메모 확인',
        'birthday' => '생일 케어 확인',
        'vehicle_notes' => '차량 메모 확인',
    );

    return isset($labels[$check_type]) ? $labels[$check_type] : '업무 확인';
}

function ieum_student_recent_activity_text($academy_id, $student_id, $row)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $rows = array();

    if (defined('IEUM_AUTO_CHECK_RESOLVE_TABLE') && function_exists('ieum_dashboard_ensure_auto_check_table')) {
        ieum_dashboard_ensure_auto_check_table();
        $auto_result = sql_query("
            select check_type, target_date, memo, resolved_by, resolved_at
              from " . IEUM_AUTO_CHECK_RESOLVE_TABLE . "
             where academy_id = '{$academy_id}'
               and target_key = '" . sql_escape_string((string) $student_id) . "'
          order by resolved_at desc, resolve_id desc
             limit 4
        ", false);
        while ($auto = sql_fetch_array($auto_result)) {
            $label = ieum_student_auto_check_label($auto['check_type']);
            if (!empty($auto['memo'])) {
                $label .= ' · ' . $auto['memo'];
            }
            $date_label = !empty($auto['target_date']) ? '대상일 ' . $auto['target_date'] : '대상일 -';
            $rows[] = substr((string) $auto['resolved_at'], 0, 16) . ' · ' . $label . ' · ' . $date_label;
        }
    }
    $result = sql_query("
        select before_status, after_status, memo, created_at
          from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
      order by created_at desc, status_log_id desc
         limit 3
    ", false);
    while ($log = sql_fetch_array($result)) {
        $label = ieum_student_status_label($log['before_status']) . ' → ' . ieum_student_status_label($log['after_status']);
        if (!empty($log['memo'])) {
            $label .= ' · ' . $log['memo'];
        }
        $rows[] = substr((string) $log['created_at'], 0, 16) . ' · ' . $label;
    }

    if (!empty($row['counseling_note'])) {
        array_unshift($rows, '상담 메모: ' . $row['counseling_note']);
    }
    if (!empty($row['updated_at'])) {
        $rows[] = '최근 수정: ' . substr((string) $row['updated_at'], 0, 16);
    }

    return $rows ? implode("\n", array_slice($rows, 0, 4)) : '최근 처리 기록 없음';
}

function ieum_student_signal_resolve_type($signal_key)
{
    $map = array(
        'long_absent' => 'long_absent',
        'tuition_unpaid' => 'tuition_overdue',
        'tuition_note' => 'tuition_overdue',
        'report_blocked' => 'report_blocked',
        'no_guardian' => 'no_guardian',
        'memo' => 'student_care_notes',
        'counseling' => 'student_care_notes',
        'promotion_memo' => 'student_care_notes',
        'birthday_week' => 'birthday',
        'birthday_month' => 'birthday',
        'vehicle_memo' => 'vehicle_notes',
        'vehicle' => 'vehicle_notes',
    );

    return isset($map[$signal_key]) ? $map[$signal_key] : '';
}

ieum_refresh_student_auto_grades($academy_id);
$insight_where = ieum_student_insight_where('s', $filter_insight, G5_TIME_YMD);
$insight_where_ss = ieum_student_insight_where('ss', $filter_insight, G5_TIME_YMD);
if ($filter_open_only) {
    $insight_where .= ieum_student_open_signal_where('s', $filter_insight, G5_TIME_YMD);
    $insight_where_ss .= ieum_student_open_signal_where('ss', $filter_insight, G5_TIME_YMD);
}
$where = " where 1 ";
$where .= " and s.academy_id = '{$academy_id}' ";
if ($q !== '') {
    $where .= " and (s.student_code like '%{$q_sql}%' or s.student_name like '%{$q_sql}%' or s.student_phone like '%{$q_sql}%' or exists (select 1 from " . IEUM_STUDENT_GUARDIAN_TABLE . " g where g.student_id = s.student_id and g.is_active = 1 and (g.guardian_name like '%{$q_sql}%' or g.guardian_phone like '%{$q_sql}%'))) ";
}
if ($filter_program !== '') {
    $filter_program_sql = sql_escape_string($filter_program);
    $where .= " and s.program_code = '{$filter_program_sql}' ";
}
if ($filter_grade !== '') {
    $filter_grade_sql = sql_escape_string($filter_grade);
    $where .= " and s.grade_group = '{$filter_grade_sql}' ";
}
if ($filter_class_time_raw === 'unassigned') {
    $where .= " and s.class_time_id = 0 ";
} elseif ($filter_class_time_id) {
    $where .= " and s.class_time_id = '{$filter_class_time_id}' ";
}
$where .= $insight_where;

$count_where_no_class = " where s.academy_id = '{$academy_id}' and s.is_active = 1 ";
if ($q !== '') {
    $count_where_no_class .= " and (s.student_code like '%{$q_sql}%' or s.student_name like '%{$q_sql}%' or s.student_phone like '%{$q_sql}%' or exists (select 1 from " . IEUM_STUDENT_GUARDIAN_TABLE . " g where g.student_id = s.student_id and g.is_active = 1 and (g.guardian_name like '%{$q_sql}%' or g.guardian_phone like '%{$q_sql}%'))) ";
}
if ($filter_program !== '') {
    $count_where_no_class .= " and s.program_code = '{$filter_program_sql}' ";
}
if ($filter_grade !== '') {
    $count_where_no_class .= " and s.grade_group = '{$filter_grade_sql}' ";
}
$count_where_no_class .= $insight_where;

$count_where_no_program = " where s.academy_id = '{$academy_id}' and s.is_active = 1 ";
if ($q !== '') {
    $count_where_no_program .= " and (s.student_code like '%{$q_sql}%' or s.student_name like '%{$q_sql}%' or s.student_phone like '%{$q_sql}%' or exists (select 1 from " . IEUM_STUDENT_GUARDIAN_TABLE . " g where g.student_id = s.student_id and g.is_active = 1 and (g.guardian_name like '%{$q_sql}%' or g.guardian_phone like '%{$q_sql}%'))) ";
}
if ($filter_grade !== '') {
    $count_where_no_program .= " and s.grade_group = '{$filter_grade_sql}' ";
}
if ($filter_class_time_raw === 'unassigned') {
    $count_where_no_program .= " and s.class_time_id = 0 ";
} elseif ($filter_class_time_id) {
    $count_where_no_program .= " and s.class_time_id = '{$filter_class_time_id}' ";
}
$count_where_no_program .= $insight_where;

$billing_month = date('Y-m', strtotime(G5_TIME_YMD));
$billing_month_sql = sql_escape_string($billing_month);

$total = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . " s
      {$where}
", false);
$total_count = isset($total['cnt']) ? (int) $total['cnt'] : 0;
$total_pages = max(1, (int) ceil($total_count / $page_size));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $page_size;

$students = sql_query("
    select s.*, c.class_name, c.start_time,
           (select group_concat(concat(g.guardian_name, if(g.guardian_relation <> '', concat('(', g.guardian_relation, ')'), ''), ' ', g.guardian_phone, if(g.sms_attendance=1, ' 등원', ''), if(g.sms_checkout=1, ' 하원', ''), if(g.use_for_student_code=1, ' 번호', '')) order by g.sort_order asc separator '<br>')
              from " . IEUM_STUDENT_GUARDIAN_TABLE . " g
             where g.academy_id = s.academy_id
               and g.student_id = s.student_id
               and g.is_active = 1) as guardian_summary,
           (select count(*)
              from " . IEUM_STUDENT_GUARDIAN_TABLE . " g2
             where g2.academy_id = s.academy_id
               and g2.student_id = s.student_id
               and g2.is_active = 1
               and g2.guardian_phone <> '') as guardian_sms_count,
           (select group_concat(concat(if(sv.ride_type='pickup', '등원', '하원'), ' ', ifnull(st.stop_time, ''), ' ', ifnull(st.stop_name, sv.place_name), if(r.route_name is not null and r.route_name <> '', concat(' / ', r.route_name), ''), if(sv.memo <> '', concat(' - ', sv.memo), '')) order by field(sv.ride_type, 'pickup', 'dropoff') separator '<br>')
              from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
         left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
         left join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
             where sv.academy_id = s.academy_id
               and sv.student_id = s.student_id
               and sv.is_active = 1) as vehicle_summary,
           (select count(*)
              from " . IEUM_STUDENT_VEHICLE_TABLE . " sv2
             where sv2.academy_id = s.academy_id
               and sv2.student_id = s.student_id
               and sv2.is_active = 1) as vehicle_count,
           (select count(*)
              from " . IEUM_STUDENT_VEHICLE_TABLE . " sv3
             where sv3.academy_id = s.academy_id
               and sv3.student_id = s.student_id
               and sv3.is_active = 1
               and sv3.memo <> '') as vehicle_memo_count,
           (select max(a.attendance_date)
              from " . IEUM_ATTENDANCE_TABLE . " a
             where a.academy_id = s.academy_id
               and a.student_id = s.student_id) as last_attendance_date,
           coalesce(tp.status, '') as tuition_status,
           coalesce(tp.amount_due, 0) as tuition_amount_due,
           coalesce(tp.amount_paid, 0) as tuition_amount_paid
      from " . IEUM_STUDENT_TABLE . " s
  left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
  left join " . IEUM_TUITION_PAYMENT_TABLE . " tp on tp.academy_id = s.academy_id and tp.student_id = s.student_id and tp.billing_month = '{$billing_month_sql}'
       {$where}
   order by s.is_active desc, s.student_name asc, s.student_code asc
", false);

$all_student_rows = array();
while ($student_row = sql_fetch_array($students)) {
    $all_student_rows[] = $student_row;
}
usort($all_student_rows, function ($a, $b) {
    $pa = ieum_student_row_care_priority($a, G5_TIME_YMD);
    $pb = ieum_student_row_care_priority($b, G5_TIME_YMD);
    if ($pa !== $pb) {
        return $pa < $pb ? -1 : 1;
    }
    if ((int) $a['is_active'] !== (int) $b['is_active']) {
        return (int) $b['is_active'] <=> (int) $a['is_active'];
    }
    $name_compare = strcmp((string) $a['student_name'], (string) $b['student_name']);
    if ($name_compare !== 0) {
        return $name_compare;
    }
    return strcmp((string) $a['student_code'], (string) $b['student_code']);
});
$student_rows = array_slice($all_student_rows, $offset, $page_size);
$alert_contact_row = sql_fetch("
    select count(*) as cnt
      from " . IEUM_ACADEMY_CONTACT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and contact_phone <> ''
", false);
$alert_contact_count = isset($alert_contact_row['cnt']) ? (int) $alert_contact_row['cnt'] : 0;
$current_count = count($student_rows);
$page_first = $total_count > 0 ? $offset + 1 : 0;
$page_last = $total_count > 0 ? min($offset + $current_count, $total_count) : 0;

$class_times = sql_query("
    select *
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);

$class_options = array();
while ($class = sql_fetch_array($class_times)) {
    $class_options[] = $class;
}

$tuition_plans = sql_query("
    select *
      from " . IEUM_TUITION_PLAN_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by field(week_type, '2', '3', '4', '5', 'custom'), plan_id asc
", false);

$tuition_plan_options = array();
while ($plan = sql_fetch_array($tuition_plans)) {
    $tuition_plan_options[] = $plan;
}

$vehicle_stop_options = ieum_fetch_vehicle_routes($academy_id);
$vehicle_label_options = array();
foreach ($vehicle_stop_options as $stop_option) {
    $vehicle_label = isset($stop_option['vehicle_label']) ? trim($stop_option['vehicle_label']) : '';
    if ($vehicle_label !== '' && !in_array($vehicle_label, $vehicle_label_options, true)) {
        $vehicle_label_options[] = $vehicle_label;
    }
}

$total_all = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
", false);

$side_filter_where = " where s.academy_id = '{$academy_id}' and s.is_active = 1 ";
if ($q !== '') {
    $side_filter_where .= " and (s.student_code like '%{$q_sql}%' or s.student_name like '%{$q_sql}%' or s.student_phone like '%{$q_sql}%' or exists (select 1 from " . IEUM_STUDENT_GUARDIAN_TABLE . " g where g.student_id = s.student_id and g.is_active = 1 and (g.guardian_name like '%{$q_sql}%' or g.guardian_phone like '%{$q_sql}%'))) ";
}
if ($filter_program !== '') {
    $side_filter_where .= " and s.program_code = '{$filter_program_sql}' ";
}
if ($filter_grade !== '') {
    $side_filter_where .= " and s.grade_group = '{$filter_grade_sql}' ";
}
if ($filter_class_time_raw === 'unassigned') {
    $side_filter_where .= " and s.class_time_id = 0 ";
} elseif ($filter_class_time_id) {
    $side_filter_where .= " and s.class_time_id = '{$filter_class_time_id}' ";
}

$insight_counts = array();
foreach ($insight_options as $insight_value => $insight_label) {
    if ($insight_value === '') {
        continue;
    }
    $insight_count_where = $side_filter_where . ieum_student_insight_where('s', $insight_value, G5_TIME_YMD);
    $insight_count_row = sql_fetch("
        select count(*) as cnt
          from " . IEUM_STUDENT_TABLE . " s
          {$insight_count_where}
    ", false);
    $insight_counts[$insight_value] = isset($insight_count_row['cnt']) ? (int) $insight_count_row['cnt'] : 0;
}

$student_workflow_cards = array(
    array(
        'key' => 'missing_today',
        'label' => '오늘 미등원',
        'desc' => '출석 처리 또는 보호자 연락',
        'count' => isset($insight_counts['missing_today']) ? (int) $insight_counts['missing_today'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=missing_today',
        'action_label' => '출석 관리',
        'action_url' => IEUM_URL . '/admin/attendance_today.php',
        'tone' => 'danger',
    ),
    array(
        'key' => 'long_absent',
        'label' => '장기 미등원',
        'desc' => '안부 연락 후 오늘 확인',
        'count' => isset($insight_counts['long_absent']) ? (int) $insight_counts['long_absent'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=long_absent&open=1',
        'action_label' => '안부 처리',
        'action_url' => IEUM_URL . '/admin/students.php?insight=long_absent&open=1',
        'tone' => 'danger',
    ),
    array(
        'key' => 'student_care_notes',
        'label' => '아이들 메모',
        'desc' => '프로필에서 다음 케어 확인',
        'count' => isset($insight_counts['memo']) ? (int) $insight_counts['memo'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=memo',
        'action_label' => '메모 보기',
        'action_url' => IEUM_URL . '/admin/students.php?insight=memo',
        'tone' => 'warn',
    ),
    array(
        'key' => 'no_guardian',
        'label' => '연락처 누락',
        'desc' => '보호자 연락처 보완',
        'count' => isset($insight_counts['no_guardian']) ? (int) $insight_counts['no_guardian'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=no_guardian',
        'action_label' => '정보 수정',
        'action_url' => IEUM_URL . '/admin/students.php?insight=no_guardian',
        'tone' => 'warn',
    ),
    array(
        'key' => 'tuition_unpaid',
        'label' => '수련비 미납',
        'desc' => '납부 상태 확인과 안내 문자',
        'count' => isset($insight_counts['tuition_unpaid']) ? (int) $insight_counts['tuition_unpaid'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=tuition_unpaid',
        'action_label' => '수련비 화면',
        'action_url' => IEUM_URL . '/admin/tuition_payments.php?billing_month=' . urlencode($billing_month) . '&payment_filter=unpaid#paymentList',
        'tone' => 'danger',
    ),
    array(
        'key' => 'report_blocked',
        'label' => '리포트 발송 불가',
        'desc' => '생년월일 또는 연락처 보완',
        'count' => isset($insight_counts['report_blocked']) ? (int) $insight_counts['report_blocked'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=report_blocked',
        'action_label' => '정보 보완',
        'action_url' => IEUM_URL . '/admin/students.php?insight=report_blocked',
        'tone' => 'danger',
    ),
    array(
        'key' => 'birthday_week',
        'label' => '생일 케어',
        'desc' => '7일 이내 생일 안부',
        'count' => isset($insight_counts['birthday_week']) ? (int) $insight_counts['birthday_week'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=birthday_week',
        'action_label' => '문자 준비',
        'action_url' => IEUM_URL . '/admin/students.php?insight=birthday_week',
        'tone' => '',
    ),
    array(
        'key' => 'vehicle_unassigned',
        'label' => '차량 미배정',
        'desc' => '이용 원생 차량 정보 보완',
        'count' => isset($insight_counts['vehicle_unassigned']) ? (int) $insight_counts['vehicle_unassigned'] : 0,
        'unit' => '명',
        'url' => IEUM_URL . '/admin/students.php?insight=vehicle_unassigned',
        'action_label' => '차량 배정',
        'action_url' => IEUM_URL . '/admin/vehicle_assignments.php?assignment=none',
        'tone' => 'warn',
    ),
);

$unassigned_count = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . " s
      {$count_where_no_class}
       and s.class_time_id = 0
", false);

$program_counts = array();
$program_counts_result = sql_query("
    select program_code, count(*) as cnt
      from " . IEUM_STUDENT_TABLE . " s
      {$count_where_no_program}
  group by program_code
", false);
while ($program_count = sql_fetch_array($program_counts_result)) {
    $program_counts[$program_count['program_code']] = (int) $program_count['cnt'];
}

$class_counts_result = sql_query("
    select c.class_time_id, c.class_name, c.start_time, count(s.student_id) as cnt
      from " . IEUM_CLASS_TIME_TABLE . " c
 left join " . IEUM_STUDENT_TABLE . " s on s.class_time_id = c.class_time_id and s.academy_id = c.academy_id and s.student_id in (
           select ss.student_id
             from " . IEUM_STUDENT_TABLE . " ss
            where ss.academy_id = '{$academy_id}'
              and ss.is_active = 1
              " . ($q !== '' ? " and (ss.student_code like '%{$q_sql}%' or ss.student_name like '%{$q_sql}%' or ss.student_phone like '%{$q_sql}%' or exists (select 1 from " . IEUM_STUDENT_GUARDIAN_TABLE . " g where g.student_id = ss.student_id and g.is_active = 1 and (g.guardian_name like '%{$q_sql}%' or g.guardian_phone like '%{$q_sql}%'))) " : "") . "
              " . ($filter_program !== '' ? " and ss.program_code = '{$filter_program_sql}' " : "") . "
              " . ($filter_grade !== '' ? " and ss.grade_group = '{$filter_grade_sql}' " : "") . "
              {$insight_where_ss}
       )
     where c.academy_id = '{$academy_id}'
       and c.is_active = 1
  group by c.class_time_id
  order by c.sort_order asc, c.start_time asc
", false);
$class_counts = array();
while ($class_count = sql_fetch_array($class_counts_result)) {
    $class_counts[] = $class_count;
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}
.wrap{max-width:1920px;margin:0 auto;padding:28px 24px 42px}
.bar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap}
h1{margin:0;font-size:26px}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06)}
.notice{margin:0 0 14px;padding:12px 14px;border-radius:8px}
.ok{background:#eef9f1;color:#176b2c;border:1px solid #9bd3ad}
.err{background:#fdecec;color:#a4262c;border:1px solid #efb2b2}
.wrap.ajax-loading{opacity:.55;pointer-events:none;transition:opacity .15s ease}.ajax-status{display:none;margin:0 0 12px;padding:10px 12px;border-radius:8px;background:#eef2f7;color:#344054;font-weight:800}.ajax-status.show{display:block}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:700;cursor:pointer}
.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}
.btn.danger{background:#fff5f5;border-color:#f2b8b8;color:#a4262c}
.btn.muted{background:#f1f3f5}
form.inline{display:inline}
.search{display:flex;gap:8px;align-items:center}
.search input{height:38px;border:1px solid #cfd6df;border-radius:6px;padding:0 10px;min-width:260px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px}
th{background:#72829d;color:#fff}
td.left{text-align:left}
.inactive{color:#8a94a6;background:#fafafa}
.form-guide{max-width:760px;margin:0 0 16px;border:1px solid #d9dee7;border-radius:10px;background:#f8fbff;padding:14px}.form-guide strong{display:block;font-size:17px;margin-bottom:4px}.form-guide p{margin:0;color:#667085;line-height:1.45}.guide-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}.guide-card{border:1px solid #d9dee7;border-radius:8px;background:#fff;padding:10px}.guide-card b{display:block;color:#1769c2;margin-bottom:3px}.guide-card span{color:#667085;font-size:13px;line-height:1.35}.form-jump-bar{position:sticky;top:0;z-index:6;display:flex;gap:7px;flex-wrap:wrap;align-items:center;max-width:760px;margin:0 0 14px;padding:10px;border:1px solid #d9e5f8;border-radius:12px;background:rgba(255,255,255,.96);box-shadow:0 8px 18px rgba(15,23,42,.06)}.form-jump-bar strong{margin-right:4px;color:#344054;font-size:13px}.form-jump-bar a{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border:1px solid #d8dee9;border-radius:999px;background:#f8fafc;color:#344054;text-decoration:none;font-size:13px;font-weight:1000;padding:0 11px}.form-jump-bar a:hover{border-color:#1769c2;background:#eaf4ff;color:#1769c2}.form-summary-strip{max-width:760px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 14px}.form-summary-card{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:11px 12px}.form-summary-card span{display:block;color:#667085;font-size:12px;font-weight:1000;margin-bottom:4px}.form-summary-card strong{display:block;color:#111827;font-size:16px;line-height:1.35}.form-grid{display:grid;grid-template-columns:160px 1fr;gap:12px 16px;align-items:center;max-width:760px}.form-section-title{grid-column:1 / -1;border-top:1px solid #e2e8f0;padding-top:14px;margin-top:4px;font-size:15px;font-weight:1000;color:#1769c2;scroll-margin-top:74px}.form-section-title:first-child{border-top:0;padding-top:0}.optional-details{border:1px solid #d9dee7;border-radius:8px;background:#f8fafc}.optional-details summary{cursor:pointer;padding:12px 14px;font-weight:900;color:#1769c2}.optional-details summary::-webkit-details-marker{display:none}.optional-details-inner{padding:0 12px 12px}.required-hint{color:#a4262c;font-size:12px;font-weight:900;margin-left:4px}
label{font-weight:700}
input[type=text],select,textarea{width:100%;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}
textarea{min-height:82px;resize:vertical}
.actions{margin-top:18px;display:flex;gap:8px}
.count{color:#5b6472}
.student-head{align-items:flex-start}.student-head-main{min-width:0;flex:1 1 960px}.student-head-note{margin-top:6px;color:#667085;font-size:14px;line-height:1.45}.chip{display:inline-flex;align-items:center;gap:5px;background:#eef2f7;border:1px solid #d8dee9;border-radius:999px;padding:8px 11px;font-weight:900;color:#344054;text-decoration:none;line-height:1}.chip:hover{border-color:#9bb7df;background:#f8fbff}.chip.active{background:#1769c2;color:#fff;border-color:#1769c2}.chip-count{font-size:12px;opacity:.82}
.student-head-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.student-workspace{display:grid;grid-template-columns:1fr;gap:16px;align-items:start}.student-side{position:static;padding:16px;background:linear-gradient(135deg,#fff,#f8fbff)}.student-side-title{font-size:18px;font-weight:1000;margin:0 0 4px}.student-side-help{margin:0 0 12px;color:#667085;font-size:13px;line-height:1.45}.side-groups{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.side-group{border:1px solid #edf1f7;border-radius:12px;background:#fff;padding:12px;margin-top:0}.side-group summary{display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;list-style:none}.side-group summary::-webkit-details-marker{display:none}.side-group summary:after{content:'열기';border-radius:999px;background:#eef4ff;color:#1769c2;padding:3px 8px;font-size:11px;font-weight:1000}.side-group[open] summary:after{content:'접기';background:#1769c2;color:#fff}.side-group-title{font-size:13px;font-weight:1000;color:#1769c2;margin:0}.side-links{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px}.side-group summary + .side-links{margin-top:10px}.side-link{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #d8dee9;border-radius:10px;background:#fff;color:#111827;text-decoration:none;padding:10px 12px;font-weight:900}.side-link span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.side-link b{flex:0 0 auto;border-radius:999px;background:#eef2f7;color:#344054;padding:3px 8px;font-size:12px}.side-link:hover{border-color:#1769c2;background:#f4f8ff}.side-link.active{background:#1769c2;border-color:#1769c2;color:#fff}.side-link.active b{background:rgba(255,255,255,.2);color:#fff}.side-link.warn b{background:#fff6df;color:#9a5b00}.side-link.danger b{background:#fff1f1;color:#a4262c}.side-link.good b{background:#eef9f1;color:#176b2c}.side-link.active.warn b,.side-link.active.danger b,.side-link.active.good b{background:rgba(255,255,255,.2);color:#fff}.side-group.secondary{background:#fbfcff}.student-list-panel{min-width:0}.student-list-head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:14px;flex-wrap:wrap}.student-list-head h2{margin:0;font-size:20px}.student-list-head p{margin:4px 0 0;color:#667085;font-size:13px}.student-list-count{font-size:28px;font-weight:1000;color:#111827}.search{width:100%;display:grid!important;grid-template-columns:minmax(260px,1.5fr) repeat(4,minmax(120px,.7fr)) auto auto;gap:8px;align-items:center}.search input{min-width:0!important;width:100%}.search .btn{white-space:nowrap}.student-filter-note{border:1px solid #d9e5f8;border-radius:10px;background:#f8fbff;color:#475467;padding:10px 12px;margin:0 0 12px;font-size:13px;line-height:1.45}.list-page-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:12px 0;flex-wrap:wrap;color:#667085;font-size:13px}.list-page-bar strong{color:#111827}.pagination{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap;margin:14px 0 2px}.page-link{display:inline-flex;align-items:center;justify-content:center;min-width:36px;min-height:36px;border:1px solid #d8dee9;border-radius:9px;background:#fff;color:#344054;text-decoration:none;font-weight:900;padding:0 10px}.page-link:hover{border-color:#1769c2;background:#f4f8ff;color:#1769c2}.page-link.active{background:#1769c2;border-color:#1769c2;color:#fff}.page-link.disabled{opacity:.45;pointer-events:none;background:#f8fafc}
.guardian-list{display:grid;gap:10px}.guardian-row{display:grid;grid-template-columns:1fr .9fr 1.35fr repeat(4,auto);gap:8px;align-items:center;padding:10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}.guardian-row label{white-space:nowrap;font-weight:700;font-size:13px}.guardian-row .remove-guardian{min-width:42px}.weekday-control{display:grid;gap:10px}.weekday-presets{display:flex;gap:8px;flex-wrap:wrap}.preset-btn{min-height:36px;border:1px solid #cfd6df;border-radius:6px;background:#fff;padding:7px 12px;font-weight:800;cursor:pointer}.preset-btn.active{background:#1769c2;border-color:#1769c2;color:#fff}.weekday-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.weekday-card,.ride-day-card{position:relative;display:flex;align-items:center;justify-content:center;min-height:48px;border:1px solid #cfd6df;border-radius:8px;background:#fff;font-size:18px;font-weight:900;cursor:pointer}.weekday-card input,.ride-day-card input{position:absolute;opacity:0;pointer-events:none}.weekday-card.selected,.ride-day-card.selected{background:#1769c2;border-color:#1769c2;color:#fff}.weekday-help{color:#667085;font-size:13px}.date-selects{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}.tuition-box,.vehicle-box{display:grid;gap:8px}.tuition-row{display:grid;grid-template-columns:130px minmax(160px,1fr) 120px minmax(140px,1fr);gap:8px;align-items:center}.tuition-row.second{grid-template-columns:130px 150px 1fr}.money-field{display:grid;grid-template-columns:auto 1fr auto;align-items:center;border:1px solid #cfd6df;border-radius:6px;background:#fff;overflow:hidden}.money-field span,.money-field em{height:40px;display:flex;align-items:center;padding:0 10px;background:#f8fafc;color:#667085;font-style:normal;font-weight:900;white-space:nowrap}.money-field input{border:0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-radius:0;text-align:right;font-weight:800}.inline-check{display:flex;align-items:center;gap:6px;white-space:nowrap}.inline-check input{width:auto}.due-label{font-size:14px;color:#344054}.tuition-total{display:flex;align-items:center;justify-content:flex-end;border:1px solid #d9dee7;border-radius:8px;background:#f8fafc;padding:10px 12px;font-weight:900;color:#1769c2}.vehicle-tools{display:flex;gap:8px;flex-wrap:wrap}.vehicle-tools .btn{min-height:34px;padding:6px 10px;font-size:13px}.vehicle-row{display:grid;grid-template-columns:auto 90px 120px 1fr 1fr;gap:8px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:10px}.vehicle-row input[type=checkbox]{width:auto}.vehicle-row span{font-weight:900}.vehicle-memo,.vehicle-days{display:grid;grid-template-columns:90px 1fr;gap:8px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:10px 12px}.vehicle-contact{display:grid;grid-template-columns:90px 1fr 1fr;gap:8px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:10px 12px}.vehicle-memo span,.vehicle-contact span,.vehicle-days span{font-weight:900;color:#344054}.ride-day-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.ride-day-card{min-height:40px;font-size:15px}
.promotion-box{display:grid;gap:12px}.promotion-enabled{justify-content:flex-start;border:1px solid #d9e5f8;border-radius:10px;background:#f8fbff;padding:10px 12px;color:#1769c2;font-weight:1000}.promotion-rank-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.promotion-rank-grid label{display:grid;gap:6px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:10px}.promotion-rank-grid span{font-size:12px;color:#667085;font-weight:1000}.promotion-rank-grid input,.promotion-rank-grid select{height:42px}
.student-code-field,.student-phone-field{display:grid;gap:6px}.field-help{font-size:12px;color:#667085;line-height:1.45}.student-phone-action{display:grid;grid-template-columns:1fr auto;gap:8px}.student-phone-action .btn{min-height:42px;white-space:nowrap}.duplicate-alert{display:none;border:1px solid #facc15;background:#fffbeb;color:#7a4b00;border-radius:8px;padding:10px 12px;font-size:13px;line-height:1.55}.duplicate-alert.show{display:block}.duplicate-alert strong{display:block;color:#92400e;margin-bottom:4px}.duplicate-alert ul{margin:4px 0 0;padding-left:18px}.next-actions{border:1px solid #b7d4ff;background:#f4f8ff;border-radius:10px;padding:14px;margin:0 0 14px}.next-actions strong{display:block;margin-bottom:4px;font-size:16px}.next-actions p{margin:0 0 10px;color:#667085}.next-action-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px}.next-action-grid .btn{background:#fff}.photo-box{display:grid;grid-template-columns:112px 1fr;gap:14px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:12px}.photo-preview{width:112px;height:112px;border-radius:12px;object-fit:cover;background:#e5e7eb;border:1px solid #d8dee9}.photo-empty{width:112px;height:112px;border-radius:12px;background:#e5e7eb;color:#667085;display:flex;align-items:center;justify-content:center;font-weight:900}.photo-controls{display:grid;gap:8px}.photo-controls input[type=file]{width:100%;border:1px solid #cfd6df;border-radius:6px;background:#fff;padding:10px}.photo-controls label{font-size:13px;color:#344054}
.guardian-row{grid-template-columns:1fr!important;gap:12px!important}.guardian-fields{display:grid;grid-template-columns:1fr .75fr 1.1fr;gap:8px}.guardian-flags{display:flex;gap:8px;flex-wrap:wrap}.guardian-flag{display:inline-flex;align-items:center;gap:6px;border:1px solid #cfd6df;border-radius:999px;background:#fff;padding:8px 10px;font-size:13px;font-weight:900;color:#344054}.guardian-flag input{width:auto}.guardian-flag:has(input:checked){background:#eaf4ff;border-color:#1769c2;color:#1769c2}.guardian-actions{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.guardian-actions .guardian-flag{background:#f8fafc}.guardian-actions .btn{min-height:34px}.guardian-section-title{font-size:12px;font-weight:900;color:#667085;margin:0 0 6px}.guardian-groups{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:start}.guardian-main{display:flex;gap:8px;flex-wrap:wrap}
.care-actions{display:grid;gap:8px;min-width:190px}.care-actions summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:34px;border:1px solid #b7c7de;border-radius:8px;background:#f4f8ff;color:#1769c2;padding:6px 10px;font-weight:900}.care-actions summary::-webkit-details-marker{display:none}.care-actions summary:before{content:'+';display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#1769c2;color:#fff;font-size:13px;line-height:1}.care-actions[open] summary:before{content:'-';background:#344054}.care-actions form{display:grid;gap:6px}.care-actions input[type=text]{height:36px;padding:7px 9px;font-size:13px}.care-actions .btn{min-height:34px;padding:7px 9px;font-size:13px}.care-buttons{display:flex;gap:6px;flex-wrap:wrap}.care-buttons .btn{border-color:#d8dee9;background:#fff}.care-buttons .btn:hover,.care-actions summary:hover{border-color:#1769c2;background:#eaf4ff}.care-note{display:block;margin-top:4px;color:#667085;font-size:12px;line-height:1.35}
.student-table-wrap{overflow-x:auto}.student-table-wrap table{min-width:980px}.student-signal-list{display:flex;gap:6px;flex-wrap:wrap;justify-content:center}.muted-text{color:#98a2b3;font-weight:900}.name-link{border:0;background:transparent;color:#1769c2;font:inherit;font-weight:1000;padding:0;cursor:pointer}.name-link:hover{text-decoration:underline}.student-cards{display:none;gap:12px}.student-card{border:1px solid #d9dee7;border-radius:10px;background:#fff;padding:14px;box-shadow:0 8px 18px rgba(15,23,42,.05)}.student-card.inactive{background:#fafafa;color:#667085}.student-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.student-card-name{font-size:19px;font-weight:1000;color:#111827}.student-card-code{color:#667085;font-size:13px;margin-top:2px}.student-card-status{border-radius:999px;background:#eef2f7;color:#344054;padding:5px 9px;font-size:12px;font-weight:900;white-space:nowrap}.student-card-status.active{background:#eaf4ff;color:#1769c2}.student-card-badges{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 10px}.student-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef2f7;color:#344054;padding:5px 8px;font-size:12px;font-weight:900}.student-badge.good{background:#eef9f1;color:#176b2c}.student-badge.warn{background:#fff6df;color:#9a5b00}.student-badge.danger{background:#fff1f1;color:#a4262c}.student-badge.info{background:#eaf4ff;color:#1769c2}.student-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:10px}.student-card-field{border:1px solid #edf1f7;border-radius:8px;background:#f8fafc;padding:9px}.student-card-field strong{display:block;color:#667085;font-size:12px;margin-bottom:3px}.student-card-field span{font-weight:800;color:#111827}.student-card-more{border-top:1px solid #edf1f7;margin-top:10px;padding-top:10px}.student-card-more summary{cursor:pointer;display:flex;justify-content:center;border:1px solid #d8dee9;border-radius:8px;background:#f8fafc;padding:9px;font-weight:1000;color:#1769c2}.student-card-more summary::-webkit-details-marker{display:none}.student-card-section{border-top:1px solid #edf1f7;padding-top:10px;margin-top:10px}.student-card-more .student-card-section:first-of-type{border-top:0}.student-card-section strong{display:block;color:#344054;margin-bottom:5px}.student-card-empty{color:#98a2b3}.student-card-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}.student-card-actions .care-actions{flex:1 1 220px}.empty-card{border:1px dashed #cfd6df;border-radius:10px;background:#fff;padding:24px;text-align:center;color:#667085;font-weight:900}
.student-table-wrap td:last-child{min-width:132px}.student-table-wrap td:last-child .btn{min-height:34px;padding:6px 10px;font-size:13px}.student-table-wrap td:last-child .inline{display:inline-flex;margin:0 0 6px 4px}.student-table-wrap .care-actions{margin-top:6px}.student-table-wrap .care-actions summary{display:inline-flex;align-items:center;gap:4px;border:1px solid #d8dee9;border-radius:8px;background:#f8fafc;padding:6px 10px;font-size:13px;font-weight:1000;white-space:nowrap;color:#1769c2}.student-table-wrap .care-actions summary::before{content:'+';display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:999px;background:#1769c2;color:#fff;font-size:12px}.student-table-wrap .care-actions summary::-webkit-details-marker{display:none}
.detail-backdrop[hidden],.detail-modal[hidden],.edit-backdrop[hidden],.edit-modal[hidden],.care-sms-backdrop[hidden],.care-sms-modal[hidden]{display:none!important}.detail-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.36);z-index:80;opacity:0;transition:opacity .16s ease}.detail-backdrop.open{opacity:1}.detail-modal{position:fixed;top:50%;left:50%;transform:translate(-50%,-48%) scale(.98);width:min(760px,calc(100vw - 32px));max-height:calc(100vh - 48px);overflow:auto;background:#fff;border:1px solid #d9dee7;border-radius:18px;box-shadow:0 28px 80px rgba(15,23,42,.28);z-index:90;opacity:0;transition:opacity .16s ease,transform .16s ease}.detail-modal.open{opacity:1;transform:translate(-50%,-50%) scale(1)}.detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 22px;border-bottom:1px solid #edf1f7;background:linear-gradient(135deg,#f8fbff,#fff)}.detail-head h2{margin:0;font-size:26px}.detail-code{margin-top:4px;color:#667085;font-weight:900}.detail-close{border:0;background:#111827;color:#fff;border-radius:999px;width:36px;height:36px;font-size:22px;line-height:1;cursor:pointer}.detail-body{padding:20px 22px;display:grid;gap:16px}.detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.detail-field{border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:11px}.detail-field strong{display:block;color:#667085;font-size:12px;margin-bottom:4px}.detail-field span{font-weight:900;line-height:1.35}.detail-section{border:1px solid #e2e8f0;border-radius:12px;padding:14px;background:#fff}.detail-section h3{margin:0 0 8px;font-size:16px}.detail-section p{margin:0;white-space:pre-line;line-height:1.55;color:#344054}.detail-empty{color:#98a2b3!important}.detail-actions{display:flex;gap:8px;justify-content:flex-end;padding:16px 22px;border-top:1px solid #edf1f7;background:#f8fafc}.edit-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:100;opacity:0;transition:opacity .16s ease}.edit-backdrop.open{opacity:1}.edit-modal{position:fixed;inset:32px 54px;max-width:1280px;margin:0 auto;background:#fff;border:1px solid #d9dee7;border-radius:18px;box-shadow:0 28px 90px rgba(15,23,42,.32);z-index:101;display:flex;flex-direction:column;overflow:hidden;opacity:0;transform:translateY(12px) scale(.985);transition:opacity .16s ease,transform .16s ease}.edit-modal.open{opacity:1;transform:none}.edit-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid #edf1f7;background:#f8fafc}.edit-head h2{margin:0;font-size:19px}.edit-head span{display:block;margin-top:3px;color:#667085;font-size:13px;font-weight:800}.edit-close{border:0;background:#111827;color:#fff;border-radius:999px;width:36px;height:36px;font-size:22px;line-height:1;cursor:pointer}.edit-frame{width:100%;height:100%;border:0;flex:1;background:#fff}.care-sms-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:120;opacity:0;transition:opacity .16s ease}.care-sms-backdrop.open{opacity:1}.care-sms-modal{position:fixed;top:50%;left:50%;transform:translate(-50%,-47%) scale(.98);width:min(620px,calc(100vw - 32px));background:#fff;border:1px solid #d9dee7;border-radius:18px;box-shadow:0 28px 80px rgba(15,23,42,.3);z-index:121;opacity:0;transition:opacity .16s ease,transform .16s ease;overflow:hidden}.care-sms-modal.open{opacity:1;transform:translate(-50%,-50%) scale(1)}.care-sms-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:18px 20px;border-bottom:1px solid #edf1f7;background:linear-gradient(135deg,#f8fbff,#fff)}.care-sms-head h2{margin:0;font-size:20px}.care-sms-head p{margin:5px 0 0;color:#667085;font-size:13px;line-height:1.4}.care-sms-close{border:0;background:#111827;color:#fff;border-radius:999px;width:34px;height:34px;font-size:20px;cursor:pointer}.care-sms-body{padding:18px 20px}.care-sms-body label{display:block;font-weight:1000;margin-bottom:8px}.care-sms-body textarea{width:100%;min-height:150px;border:1px solid #cfd6df;border-radius:12px;padding:12px;font:inherit;line-height:1.55;resize:vertical}.care-sms-help{margin:8px 0 0;color:#667085;font-size:13px}.care-sms-actions{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid #edf1f7;background:#f8fafc}.embed-page .ieum-top,.embed-page .ieum-subnav-wrap,.embed-page .student-head,.embed-page #studentAjaxStatus{display:none!important}.embed-page .wrap{max-width:1180px;margin:0 auto;padding:18px 22px;background:#fff}.embed-page .panel{box-shadow:none;border:0;padding:0}.embed-page .form-guide,.embed-page .form-grid{max-width:1180px}
@media (max-width:1100px){.search{grid-template-columns:1fr 1fr}.search input{grid-column:1 / -1}.search .btn{width:100%}.side-groups{grid-template-columns:1fr 1fr}}
@media (max-width:820px){.student-table-wrap{display:none}.student-cards{display:grid}.panel{padding:16px}.side-links{grid-template-columns:1fr 1fr}}
@media (max-width:720px){.wrap{padding:20px 14px 34px}.form-grid,.guide-grid,.next-action-grid,.side-groups,.promotion-rank-grid{grid-template-columns:1fr}.search{grid-template-columns:1fr}.search input{min-width:0;width:100%}.bar{align-items:stretch}.btn{width:auto}table{font-size:13px}.tuition-row,.tuition-row.second,.vehicle-row,.vehicle-memo,.vehicle-contact,.vehicle-days,.guardian-row,.guardian-fields,.guardian-groups,.photo-box,.student-phone-action{grid-template-columns:1fr}.weekday-cards,.ride-day-cards{grid-template-columns:repeat(5,minmax(56px,1fr))}.money-field input{text-align:left}.student-card-grid,.detail-grid{grid-template-columns:1fr}.student-card-head{align-items:flex-start}.student-card-actions{display:grid}.student-card-actions .btn{width:100%}}
.student-page-tune .student-head{gap:18px;margin-bottom:14px}
.student-page-tune .student-head h1{font-size:30px;letter-spacing:0}
.student-page-tune .student-head-note{max-width:780px;font-size:15px;color:#475467}
.student-page-tune .student-workspace{gap:14px}
.student-page-tune .student-side{padding:14px 16px;border-radius:16px;background:#fff;box-shadow:none}
.student-page-tune .student-side-title{font-size:18px;margin-bottom:2px}
.student-page-tune .student-side-help{margin-bottom:10px;font-size:13px;color:#5b6472}
.student-page-tune .side-groups{grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.student-page-tune .side-group{border-color:#dfe6ef;background:#fbfcff;padding:10px 12px;border-radius:13px}
.student-page-tune .side-group summary:after{padding:2px 7px;font-size:10px}
.student-page-tune .side-links{grid-template-columns:repeat(auto-fit,minmax(132px,1fr));gap:7px}
.student-page-tune .side-link{min-height:38px;border-radius:11px;padding:8px 10px;font-size:14px}
.student-page-tune .side-link b{padding:2px 7px;font-size:11px}
.student-page-tune .student-list-panel{border-radius:18px;padding:16px}
.student-page-tune .student-list-head{align-items:center}
.student-page-tune .student-list-head h2{font-size:22px}
.student-page-tune .student-list-count{font-size:31px}
.student-page-tune .search{grid-template-columns:minmax(300px,1.5fr) repeat(4,minmax(126px,.7fr)) auto auto}
.student-page-tune .search input,.student-page-tune .search select{height:42px}
.student-page-tune .search .btn{min-height:42px}
.student-page-tune .student-table-wrap{border:1px solid #dfe6ef;border-radius:14px;overflow:auto;background:#fff}
.student-page-tune .student-table-wrap table{min-width:1280px}
.student-page-tune .student-table-wrap th{height:46px;background:#667893;font-size:14px}
.student-page-tune .student-table-wrap td{height:54px;background:#fff}
.student-page-tune .student-table-wrap tr:hover td{background:#f8fbff}
.student-page-tune .student-table-wrap td,.student-page-tune .student-table-wrap th{white-space:nowrap}
.student-page-tune .detail-modal{width:min(900px,calc(100vw - 48px))}
.student-page-tune .detail-head{position:sticky;top:0;z-index:2;align-items:center}
.student-page-tune .detail-head-tools{display:flex;align-items:center;gap:8px}
.student-page-tune .detail-head-tools .btn{min-height:36px;padding:7px 12px}
.student-page-tune .detail-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
.student-page-tune .edit-modal{inset:32px auto auto 50%;width:min(1040px,calc(100vw - 120px));height:calc(100vh - 64px);transform:translate(-50%,12px) scale(.985)}
.student-page-tune .edit-modal.open{transform:translate(-50%,0) scale(1)}
.student-page-tune.embed-page .wrap{max-width:960px;padding:18px 22px 34px}
.student-page-tune.embed-page .form-guide,.student-page-tune.embed-page .form-grid,.student-page-tune.embed-page .form-jump-bar,.student-page-tune.embed-page .form-summary-strip{max-width:940px}
.student-page-tune.embed-page .form-grid{grid-template-columns:140px minmax(0,1fr)}
.student-page-tune.embed-page .form-jump-bar{top:0}
.student-page-tune.embed-page .form-summary-strip{grid-template-columns:repeat(3,minmax(0,1fr))}
.student-page-tune.embed-page .promotion-rank-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
.student-page-tune.embed-page .optional-details-inner{padding-right:2px}
.student-page-tune.embed-page .form-guide{display:none!important}
.student-page-tune.embed-page .form-summary-strip{margin-top:0!important}
.student-page-tune.embed-page .form-jump-bar{position:sticky!important;top:0!important;z-index:3!important;background:#fff!important;border:1px solid #e5ebf3!important;border-radius:14px!important;padding:8px!important}
.student-page-tune.embed-page .form-grid{row-gap:12px!important}
.student-page-tune.embed-page .section-title{margin-top:18px!important}
@media (max-width:1320px){
    .student-page-tune .side-groups{grid-template-columns:repeat(2,minmax(0,1fr))}
    .student-page-tune .search{grid-template-columns:1fr 1fr 1fr}
    .student-page-tune .search input{grid-column:1/-1}
}
/* Final easy-mode pass: student page focuses on register, find, edit. */
.student-page-tune .wrap{
    max-width:1760px!important;
}
.student-page-tune .student-head h1{
    font-size:30px!important;
}
.student-page-tune .student-head-note{
    max-width:760px!important;
    color:#5b6472!important;
}
.student-page-tune .student-side,
.student-page-tune .student-list-panel{
    border-color:#dfe5ee!important;
    border-radius:18px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
.student-page-tune .student-side{
    background:#fff!important;
}
.student-page-tune .side-group{
    background:#fbfcfe!important;
    box-shadow:none!important;
}
.student-page-tune .side-link{
    box-shadow:none!important;
    background:#fff!important;
    border-color:#dfe5ee!important;
}
.student-page-tune .side-link.active{
    background:#2454c6!important;
    border-color:#2454c6!important;
}
.student-page-tune .student-list-head{
    align-items:center!important;
}
.student-page-tune .student-list-count{
    font-size:30px!important;
}
.student-page-tune .search{
    padding:12px!important;
    border:1px solid #e5ebf3!important;
    border-radius:16px!important;
    background:#fbfcfe!important;
}
.student-page-tune .student-table-wrap{
    box-shadow:none!important;
}
.student-page-tune .student-table-wrap th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
.student-page-tune .student-table-wrap td{
    border-color:#e5ebf3!important;
}
.student-page-tune .detail-modal{
    box-shadow:0 24px 70px rgba(15,23,42,.24)!important;
}
.student-page-tune .detail-head{
    background:#fff!important;
}
.student-page-tune .detail-field{
    background:#fbfcfe!important;
}
.student-page-tune .edit-modal{
    max-width:1120px!important;
    width:min(960px,calc(100vw - 120px))!important;
}
.student-page-tune .detail-modal{
    width:min(880px,calc(100vw - 72px))!important;
}
.student-page-tune .detail-body{
    background:#fff!important;
}
.student-page-tune .detail-section{
    border-color:#e5ebf3!important;
    box-shadow:none!important;
}
.student-page-tune .detail-field span{
    word-break:keep-all;
}
.student-page-tune.embed-page{
    background:#fff!important;
}
.student-page-tune.embed-page .wrap{
    max-width:960px!important;
    padding:16px 20px 28px!important;
}
.student-page-tune.embed-page .panel{
    padding:0!important;
}
.student-page-tune.embed-page .form-summary-strip{
    display:grid!important;
    grid-template-columns:repeat(3,minmax(0,1fr))!important;
    gap:8px!important;
}
.student-page-tune.embed-page .form-section-title{
    margin-top:8px!important;
    padding-top:14px!important;
}
.student-page-tune.embed-page .optional-details{
    border-radius:14px!important;
    background:#fbfcfe!important;
}
.student-page-tune.embed-page .optional-details summary{
    padding:13px 15px!important;
}
.student-page-tune.embed-page .promotion-rank-grid,
.student-page-tune.embed-page .date-selects,
.student-page-tune.embed-page .tuition-row,
.student-page-tune.embed-page .tuition-row.second{
    grid-template-columns:repeat(3,minmax(0,1fr))!important;
}
.student-page-tune.embed-page .tuition-row .inline-check,
.student-page-tune.embed-page .tuition-row.second .tuition-total{
    min-height:42px;
}
@media (max-width:900px){
    .student-page-tune .edit-modal{
        inset:20px auto auto 50%!important;
        width:calc(100vw - 40px)!important;
        height:calc(100vh - 40px)!important;
    }
    .student-page-tune.embed-page .form-grid,
    .student-page-tune.embed-page .promotion-rank-grid,
    .student-page-tune.embed-page .date-selects,
    .student-page-tune.embed-page .tuition-row,
    .student-page-tune.embed-page .tuition-row.second{
        grid-template-columns:1fr!important;
    }
}
/* 2026-05-30 quiet student desk pass: keep this page about finding and editing students. */
.student-page-tune .student-head{
    max-width:1900px!important;
    margin:0 auto 14px!important;
    padding:2px 0 0!important;
}
.student-page-tune .student-head h1{
    font-size:28px!important;
    margin-bottom:4px!important;
}
.student-page-tune .student-head-note{
    font-size:14px!important;
    line-height:1.5!important;
}
.student-page-tune .student-head-actions .btn.primary{
    min-height:44px!important;
    border-radius:12px!important;
    padding:0 18px!important;
}
.student-page-tune .student-workspace{
    max-width:1900px!important;
    margin:0 auto!important;
    gap:12px!important;
}
.student-page-tune .student-side{
    padding:14px!important;
    background:transparent!important;
    border:0!important;
    box-shadow:none!important;
}
.student-page-tune .student-side-title,
.student-page-tune .student-side-help{
    display:none!important;
}
.student-page-tune .side-groups{
    display:grid!important;
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
    gap:10px!important;
}
.student-page-tune .side-group{
    padding:0!important;
    border:1px solid #e4e9f1!important;
    border-radius:14px!important;
    background:#fff!important;
    overflow:hidden!important;
}
.student-page-tune .side-group summary{
    min-height:44px!important;
    padding:0 12px!important;
    background:#f7f9fc!important;
}
.student-page-tune .side-group summary:after{
    content:'보기'!important;
    background:#edf2f7!important;
    color:#475569!important;
    padding:4px 9px!important;
}
.student-page-tune .side-group[open] summary:after{
    content:'접기'!important;
    background:#2454c6!important;
    color:#fff!important;
}
.student-page-tune .side-group-title{
    color:#172033!important;
    font-size:14px!important;
}
.student-page-tune .side-links{
    padding:10px!important;
    grid-template-columns:repeat(auto-fit,minmax(148px,1fr))!important;
}
.student-page-tune .side-link{
    min-height:40px!important;
    border-radius:999px!important;
    padding:8px 11px!important;
    font-size:13px!important;
    color:#27364a!important;
}
.student-page-tune .student-list-panel{
    padding:18px!important;
    border-radius:20px!important;
    box-shadow:none!important;
}
.student-page-tune .student-list-head{
    padding-bottom:14px!important;
    border-bottom:1px solid #edf1f6!important;
    margin-bottom:14px!important;
}
.student-page-tune .student-list-head h2{
    font-size:22px!important;
}
.student-page-tune .student-list-head p{
    font-size:13px!important;
    color:#64748b!important;
}
.student-page-tune .student-list-count{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    min-width:96px!important;
    height:52px!important;
    border-radius:16px!important;
    background:#f5f8fc!important;
    color:#102033!important;
    font-size:28px!important;
}
.student-page-tune .student-list-panel > .bar{
    margin:0 0 12px!important;
}
.student-page-tune .search{
    padding:10px!important;
    border:1px solid #e6ebf2!important;
    border-radius:16px!important;
    background:#fff!important;
    box-shadow:none!important;
}
.student-page-tune .search input,
.student-page-tune .search select{
    height:42px!important;
    border-radius:11px!important;
    background:#fbfcfe!important;
}
.student-page-tune .list-page-bar{
    margin:6px 0 12px!important;
}
.student-page-tune .student-table-wrap{
    border-radius:16px!important;
    border-color:#e2e8f0!important;
}
.student-page-tune .student-table-wrap table{
    min-width:1180px!important;
}
.student-page-tune .student-table-wrap th{
    height:44px!important;
    background:#eef3f8!important;
    color:#243044!important;
    font-size:13px!important;
}
.student-page-tune .student-table-wrap td{
    height:56px!important;
    color:#172033!important;
}
.student-page-tune .student-table-wrap tbody tr:hover td{
    background:#f7fbff!important;
}
.student-page-tune .name-link{
    color:#1f5fbf!important;
    font-weight:1000!important;
}
.student-page-tune .student-signal-list{
    justify-content:flex-start!important;
}
.student-page-tune .student-badge{
    border-radius:999px!important;
    font-size:11px!important;
    padding:5px 8px!important;
}
.student-page-tune .care-actions summary{
    background:#fff!important;
    border-color:#d8e0eb!important;
}
.student-page-tune .detail-modal{
    border-radius:22px!important;
    width:min(920px,calc(100vw - 84px))!important;
}
.student-page-tune .detail-head{
    padding:22px 24px!important;
    background:#fff!important;
}
.student-page-tune .detail-body{
    padding:22px 24px!important;
}
.student-page-tune .detail-grid{
    gap:12px!important;
}
.student-page-tune .detail-field,
.student-page-tune .detail-section{
    border-radius:16px!important;
    background:#fbfcfe!important;
}
.student-page-tune .detail-section{
    padding:16px!important;
}
.student-page-tune .detail-flow-grid{
    display:grid!important;
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
    gap:12px!important;
}
.student-page-tune .detail-command-grid{
    display:grid!important;
    grid-template-columns:repeat(5,minmax(0,1fr))!important;
    gap:8px!important;
}
.student-page-tune .detail-command-link{
    display:grid!important;
    gap:3px!important;
    min-height:58px!important;
    align-content:center!important;
    border:1px solid #d8e0eb!important;
    border-radius:13px!important;
    background:#fff!important;
    color:#172033!important;
    padding:9px 10px!important;
    text-decoration:none!important;
}
.student-page-tune .detail-command-link strong{
    font-size:13px!important;
    line-height:1.25!important;
}
.student-page-tune .detail-command-link span{
    min-width:0!important;
    overflow:hidden!important;
    text-overflow:ellipsis!important;
    white-space:nowrap!important;
    color:#64748b!important;
    font-size:11px!important;
    font-weight:900!important;
}
.student-page-tune .detail-command-link:hover{
    border-color:#1769c2!important;
    background:#f4f8ff!important;
}
.student-page-tune .detail-mini-link{
    display:inline-flex!important;
    align-items:center!important;
    margin-top:10px!important;
    color:#1769c2!important;
    font-size:13px!important;
    font-weight:1000!important;
    text-decoration:none!important;
}
.student-page-tune .detail-mini-link:hover{
    text-decoration:underline!important;
}
@media (max-width:1320px){
    .student-page-tune .side-groups{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
    }
}
@media (max-width:820px){
    .student-page-tune .side-groups{
        grid-template-columns:1fr!important;
    }
    .student-page-tune .student-list-head{
        align-items:flex-start!important;
    }
    .student-page-tune .detail-flow-grid{
        grid-template-columns:1fr!important;
    }
    .student-page-tune .detail-command-grid{
        grid-template-columns:1fr 1fr!important;
    }
}
/* 2026-05-31 easy-mode pass: 원생관리는 등록/검색/수정만 먼저 보이게 정리 */
.student-page-tune .student-head{
    margin-bottom:10px!important;
}
.student-page-tune .student-head-note{
    max-width:640px!important;
    font-size:14px!important;
    color:#64748b!important;
}
.student-page-tune .student-flow-hub{
    max-width:1900px!important;
    margin:0 auto 12px!important;
    padding:16px!important;
    border-color:#dfe5ee!important;
    border-radius:18px!important;
    box-shadow:none!important;
}
.student-page-tune .student-flow-head{
    display:flex!important;
    align-items:flex-start!important;
    justify-content:space-between!important;
    gap:14px!important;
    margin-bottom:12px!important;
}
.student-page-tune .student-flow-head h2{
    margin:0!important;
    font-size:20px!important;
    color:#172033!important;
}
.student-page-tune .student-flow-head p{
    margin:4px 0 0!important;
    color:#64748b!important;
    font-size:13px!important;
    line-height:1.45!important;
}
.student-page-tune .student-flow-grid{
    display:grid!important;
    grid-template-columns:repeat(auto-fit,minmax(190px,1fr))!important;
    gap:8px!important;
}
.student-page-tune .student-flow-card{
    border:1px solid #e2e8f0!important;
    border-radius:12px!important;
    background:#fff!important;
    min-height:88px!important;
    overflow:hidden!important;
}
.student-page-tune .student-flow-card.warn{
    border-color:#f3cf8c!important;
    background:#fffaf0!important;
}
.student-page-tune .student-flow-card.danger{
    border-color:#f0b2b2!important;
    background:#fff7f7!important;
}
.student-page-tune .student-flow-main{
    display:grid!important;
    grid-template-columns:minmax(0,1fr) auto!important;
    gap:6px 10px!important;
    align-items:center!important;
    padding:10px 11px 7px!important;
    color:#172033!important;
    text-decoration:none!important;
}
.student-page-tune .student-flow-main span{
    grid-column:1 / -1!important;
    color:#64748b!important;
    font-size:12px!important;
    font-weight:900!important;
}
.student-page-tune .student-flow-main strong{
    min-width:0!important;
    overflow:hidden!important;
    text-overflow:ellipsis!important;
    white-space:nowrap!important;
    font-size:14px!important;
}
.student-page-tune .student-flow-main b{
    font-size:21px!important;
    color:#172033!important;
}
.student-page-tune .student-flow-main small{
    font-size:12px!important;
    margin-left:2px!important;
    color:#64748b!important;
}
.student-page-tune .student-flow-foot{
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    gap:8px!important;
    min-height:30px!important;
    padding:6px 11px!important;
    border-top:1px solid rgba(148,163,184,.22)!important;
}
.student-page-tune .student-flow-foot span{
    min-width:0!important;
    overflow:hidden!important;
    text-overflow:ellipsis!important;
    white-space:nowrap!important;
    color:#64748b!important;
    font-size:12px!important;
}
.student-page-tune .student-flow-foot a{
    flex:0 0 auto!important;
    color:#1769c2!important;
    font-size:12px!important;
    font-weight:1000!important;
    text-decoration:none!important;
}
.student-page-tune .student-flow-foot a:hover{
    text-decoration:underline!important;
}
.student-page-tune .student-side{
    margin-bottom:12px!important;
    padding:12px!important;
    background:#f8fafc!important;
}
.student-page-tune .side-groups{
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
    gap:8px!important;
}
.student-page-tune .side-group{
    min-height:auto!important;
    padding:9px 10px!important;
    background:#fff!important;
    border-color:#e2e8f0!important;
}
.student-page-tune .side-group.secondary:not([open]){
    opacity:.82;
}
.student-page-tune .side-group.secondary:not([open]) .side-links{
    display:none!important;
}
.student-page-tune .side-group summary{
    min-height:28px!important;
    font-size:13px!important;
}
.student-page-tune .side-links{
    grid-template-columns:repeat(auto-fit,minmax(116px,1fr))!important;
    gap:6px!important;
}
.student-page-tune .side-link{
    min-height:34px!important;
    padding:7px 9px!important;
    font-size:13px!important;
}
.student-page-tune .student-list-panel{
    padding:16px!important;
}
.student-page-tune .student-list-head p{
    display:none!important;
}
.student-page-tune .student-list-count{
    font-size:28px!important;
}
.student-page-tune .search{
    gap:8px!important;
}
.student-page-tune .student-table-wrap th{
    height:40px!important;
    background:#f3f6fb!important;
    color:#334155!important;
}
.student-page-tune .student-table-wrap td{
    height:48px!important;
}
.student-page-tune .detail-signal-list{
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
}
.student-page-tune .detail-signal-section{
    background:#fbfdff;
}
.student-page-tune .detail-signal-list .student-badge{
    font-size:13px;
}
@media (max-width:1320px){
    .student-page-tune .student-flow-grid{
        grid-template-columns:repeat(auto-fit,minmax(190px,1fr))!important;
    }
}
@media (max-width:820px){
    .student-page-tune .student-flow-head{
        display:grid!important;
    }
    .student-page-tune .student-flow-grid{
        grid-template-columns:1fr!important;
    }
}
/* Operation pass: make the student list read as name, lesson, guardian, signal, action. */
.student-page-tune .student-head-actions{
    align-items:center!important;
}
.student-page-tune .student-head-actions .btn{
    min-height:40px!important;
    border-radius:8px!important;
}
.student-page-tune .student-head-actions .btn.primary{
    min-height:42px!important;
}
.student-page-tune .student-flow-hub{
    border-radius:8px!important;
}
.student-page-tune .student-flow-grid{
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
}
.student-page-tune .student-flow-card{
    border-radius:8px!important;
}
.student-page-tune .student-flow-main{
    min-height:68px!important;
}
.student-page-tune .student-side{
    border-radius:8px!important;
}
.student-page-tune .side-group{
    border-radius:8px!important;
}
.student-page-tune .side-link{
    border-radius:6px!important;
}
.student-page-tune .student-list-panel{
    border-radius:8px!important;
}
.student-page-tune .student-list-head{
    margin-bottom:10px!important;
}
.student-page-tune .student-list-head h2{
    font-size:22px!important;
}
.student-page-tune .student-list-count{
    display:inline-flex!important;
    align-items:center!important;
    min-height:44px!important;
    border:1px solid #e2e8f0!important;
    border-radius:8px!important;
    background:#f8fafc!important;
    padding:0 14px!important;
    font-size:26px!important;
}
.student-page-tune .student-list-panel > .bar{
    margin:0 0 10px!important;
}
.student-page-tune .search{
    grid-template-columns:minmax(300px,1.35fr) repeat(4,minmax(116px,.62fr)) auto auto!important;
    padding:10px!important;
    border-radius:8px!important;
    background:#fff!important;
}
.student-page-tune .search input,
.student-page-tune .search select{
    height:38px!important;
}
.student-page-tune .list-page-bar{
    margin:8px 0!important;
}
.student-page-tune .student-filter-note{
    border-radius:8px!important;
    padding:9px 10px!important;
}
.student-page-tune .student-table-wrap{
    border-radius:8px!important;
}
.student-page-tune .student-table-wrap table{
    min-width:1040px!important;
}
.student-page-tune .student-table-wrap th{
    height:38px!important;
    text-align:left!important;
}
.student-page-tune .student-table-wrap th:nth-child(1),
.student-page-tune .student-table-wrap td:nth-child(1){
    width:13%!important;
}
.student-page-tune .student-table-wrap th:nth-child(2),
.student-page-tune .student-table-wrap td:nth-child(2){
    width:9%!important;
}
.student-page-tune .student-table-wrap th:nth-child(3),
.student-page-tune .student-table-wrap td:nth-child(3){
    width:12%!important;
}
.student-page-tune .student-table-wrap th:nth-child(4),
.student-page-tune .student-table-wrap td:nth-child(4){
    width:20%!important;
}
.student-page-tune .student-table-wrap th:nth-child(5),
.student-page-tune .student-table-wrap td:nth-child(5){
    width:30%!important;
}
.student-page-tune .student-table-wrap th:nth-child(6),
.student-page-tune .student-table-wrap td:nth-child(6){
    width:16%!important;
}
.student-page-tune .student-table-wrap td{
    height:auto!important;
    padding:10px 12px!important;
    vertical-align:top!important;
    line-height:1.4!important;
}
.student-page-tune .student-table-wrap td.student-row-actions{
    padding:8px!important;
}
.student-page-tune .student-table-wrap td.student-name-cell,
.student-page-tune .student-table-wrap td.student-rank-cell,
.student-page-tune .student-table-wrap td.student-class-cell,
.student-page-tune .student-table-wrap td.student-guardian-cell,
.student-page-tune .student-table-wrap td.student-signal-cell,
.student-page-tune .student-table-wrap td.student-row-actions{
    white-space:normal!important;
}
.student-page-tune .student-name-cell .name-link{
    color:#0f172a!important;
    font-size:16px!important;
    font-weight:1000!important;
}
.student-page-tune .student-subline,
.student-page-tune .student-class-cell span{
    display:block!important;
    margin-top:4px!important;
    color:#64748b!important;
    font-size:12px!important;
    font-weight:800!important;
}
.student-page-tune .student-class-cell strong{
    display:block!important;
    color:#0f172a!important;
    font-size:14px!important;
}
.student-page-tune .student-rank-cell{
    font-size:12px!important;
    color:#64748b!important;
}
.student-page-tune .student-rank-cell .student-belt-chip{
    display:inline-flex!important;
    align-items:center!important;
    max-width:100%!important;
    margin:0 0 5px!important;
    border:1px solid #cfe2f8!important;
    border-radius:999px!important;
    background:#eef6ff!important;
    color:#1769c2!important;
    padding:3px 8px!important;
    font-size:11px!important;
    font-weight:1000!important;
    line-height:1.35!important;
    white-space:nowrap!important;
    overflow:hidden!important;
    text-overflow:ellipsis!important;
}
.student-page-tune .student-rank-cell .student-rank-chip{
    display:block!important;
    color:#0f172a!important;
    font-size:13px!important;
    font-weight:1000!important;
    line-height:1.35!important;
    white-space:nowrap!important;
}
.student-page-tune .student-guardian-cell{
    color:#334155!important;
    font-size:13px!important;
    white-space:normal!important;
}
.student-page-tune .student-signal-list{
    display:flex!important;
    flex-wrap:wrap!important;
    align-content:flex-start!important;
    justify-content:flex-start!important;
    gap:5px!important;
    max-height:48px!important;
    overflow:hidden!important;
}
.student-page-tune .student-badge{
    border-radius:6px!important;
    padding:3px 6px!important;
    font-size:11px!important;
    line-height:1.35!important;
}
.student-page-tune .student-row-actions{
    min-width:0!important;
    text-align:left!important;
}
.student-page-tune .student-row-action-box{
    display:grid!important;
    grid-template-columns:52px minmax(78px,1fr) 42px!important;
    gap:5px 6px!important;
    align-items:start!important;
    justify-items:stretch!important;
    width:100%!important;
}
.student-page-tune .student-row-action-box > .btn,
.student-page-tune .student-row-action-box > form.inline .btn{
    min-height:34px!important;
    padding:6px 9px!important;
    margin:0!important;
    font-size:12px!important;
    border-radius:8px!important;
    font-weight:1000!important;
}
.student-page-tune .student-row-action-box > .student-edit-modal-open{
    min-width:0!important;
    width:100%!important;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    white-space:nowrap!important;
    background:#fff!important;
    border-color:#cfd8e3!important;
    color:#0f172a!important;
}
.student-page-tune .status-change-form{
    display:grid!important;
    grid-column:2 / -1!important;
    grid-template-columns:minmax(72px,1fr) 42px!important;
    gap:4px!important;
    align-items:center!important;
    margin:0!important;
    min-width:0!important;
}
.student-page-tune .status-change-form select{
    width:100%!important;
    height:34px!important;
    min-height:34px!important;
    border-radius:8px!important;
    border-color:#cfd8e3!important;
    padding:4px 18px 4px 8px!important;
    font-size:12px!important;
    font-weight:1000!important;
    background:#fff!important;
    color:#0f172a!important;
}
.student-page-tune .status-change-form .btn{
    margin:0!important;
    min-height:34px!important;
    padding:6px 6px!important;
    background:#f1f5f9!important;
    border-color:#cfd8e3!important;
    color:#0f172a!important;
}
.student-page-tune .student-row-action-box .care-actions{
    grid-column:1 / -1!important;
    margin:0!important;
    min-width:0!important;
    width:100%!important;
}
.student-page-tune .student-row-action-box .care-actions summary{
    width:100%!important;
    justify-content:center!important;
    min-height:30px!important;
    border-radius:8px!important;
    border:1px solid #cfd8e3!important;
    background:#f8fafc!important;
    padding:4px 8px!important;
    font-size:12px!important;
    color:#1769c2!important;
    box-shadow:none!important;
    white-space:nowrap!important;
}
.student-page-tune .student-row-action-box .care-actions[open]{
    grid-column:1 / -1!important;
    margin-top:2px!important;
    border:1px solid #dfe7f0!important;
    border-radius:10px!important;
    background:#fbfcfe!important;
    padding:7px!important;
}
.student-page-tune .student-row-action-box .care-actions[open] summary{
    margin-bottom:7px!important;
    background:#f4f8ff!important;
    border-color:#b7cce8!important;
}
.student-page-tune .student-row-action-box .care-actions form{
    display:grid!important;
    gap:6px!important;
}
.student-page-tune .student-row-action-box .care-actions[open] > form:first-of-type{
    grid-template-columns:minmax(0,1fr) 78px!important;
    gap:4px!important;
    align-items:center!important;
}
.student-page-tune .student-row-action-box .care-actions input[type=text]{
    height:34px!important;
    border-radius:8px!important;
    padding:6px 9px!important;
    font-size:12px!important;
}
.student-page-tune .student-row-action-box .care-actions .btn{
    width:100%!important;
    min-height:32px!important;
    border-radius:8px!important;
    font-size:12px!important;
    font-weight:1000!important;
}
.student-page-tune .student-row-action-box .care-actions[open] > form:first-of-type .btn{
    width:auto!important;
    padding-left:8px!important;
    padding-right:8px!important;
    white-space:nowrap!important;
}
.student-page-tune .student-row-action-box .care-buttons{
    display:grid!important;
    grid-template-columns:1fr 1fr!important;
    gap:6px!important;
}
.student-page-tune .student-row-action-box .care-buttons form{
    margin:0!important;
}
@media (max-width:1500px){
    .student-page-tune .student-flow-grid{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
    }
    .student-page-tune .search{
        grid-template-columns:minmax(280px,1fr) repeat(3,minmax(120px,.7fr)) auto!important;
    }
    .student-page-tune .search select[name="page_size"]{
        grid-column:auto!important;
    }
}
@media (max-width:900px){
    .student-page-tune .student-flow-grid,
    .student-page-tune .search{
        grid-template-columns:1fr!important;
    }
    .student-page-tune .student-head-actions{
        justify-content:flex-start!important;
    }
    .student-page-tune .student-card-actions .status-change-form{
        display:grid!important;
        grid-template-columns:1fr auto!important;
        width:100%!important;
    }
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-head{
    margin:0 0 16px!important;
    padding:0!important;
    border:0!important;
    background:transparent!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-head h1{
    margin:0!important;
    font-size:30px!important;
    line-height:1.2!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-head .count{
    margin-top:6px!important;
    color:#667085!important;
    font-size:13px!important;
    font-weight:800!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-head-note{
    max-width:920px!important;
    margin:8px 0 0!important;
    color:#667085!important;
    font-size:13px!important;
    line-height:1.55!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-head-actions .btn{
    min-height:36px!important;
    border-radius:6px!important;
    padding:7px 13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .panel,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-hub,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-list-panel{
    border:1px solid #e1e7ef!important;
    border-radius:8px!important;
    background:#fff!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-hub{
    padding:16px!important;
    margin-bottom:16px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-head{
    margin-bottom:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-head h2,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-list-head h2{
    font-size:18px!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-head p,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-list-head p{
    color:#667085!important;
    font-size:13px!important;
    line-height:1.45!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-grid{
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
    gap:10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-card{
    min-height:92px!important;
    border-radius:8px!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-main{
    padding:13px 14px 9px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-main strong{
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-main b{
    font-size:24px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-foot{
    min-height:34px!important;
    padding:0 12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-side{
    padding:14px!important;
    border-radius:8px!important;
    background:#fff!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .side-groups{
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .side-group{
    border-radius:8px!important;
    padding:10px!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .side-link{
    min-height:36px!important;
    border-radius:6px!important;
    padding:8px 10px!important;
    font-size:13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-list-panel{
    padding:16px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-list-head{
    margin-bottom:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-list-count{
    min-width:86px!important;
    min-height:44px!important;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    border:1px solid #dfe7f0!important;
    border-radius:6px!important;
    background:#fff!important;
    font-size:24px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-list-panel>.bar{
    margin:0 0 10px!important;
    padding:0!important;
    border:0!important;
    background:transparent!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .search{
    grid-template-columns:minmax(260px,1.25fr) 170px 150px 150px 104px auto auto!important;
    gap:7px!important;
    padding:8px!important;
    border:1px solid #e2e8f0!important;
    border-radius:6px!important;
    background:#f8fafc!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .search input,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .search select,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .search .btn{
    height:34px!important;
    min-height:34px!important;
    border-radius:5px!important;
    font-size:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .list-page-bar{
    margin:7px 0!important;
    font-size:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-filter-note{
    margin:0 0 8px!important;
    border-radius:6px!important;
    padding:8px 10px!important;
    font-size:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap{
    width:100%!important;
    border:1px solid #dfe6ef!important;
    border-radius:6px!important;
    background:#fff!important;
    overflow-x:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap table{
    width:100%!important;
    min-width:1120px!important;
    table-layout:fixed!important;
    border-collapse:collapse!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap th{
    height:34px!important;
    border-color:#dfe6ef!important;
    background:#f3f6fa!important;
    color:#334155!important;
    font-size:12px!important;
    font-weight:900!important;
    text-align:left!important;
    padding:7px 10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap td{
    height:auto!important;
    min-height:54px!important;
    border-color:#e7edf4!important;
    padding:9px 10px!important;
    background:#fff!important;
    vertical-align:top!important;
    line-height:1.38!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap tbody tr:hover td{
    background:#f8fbff!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap th:nth-child(1),
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap td:nth-child(1){width:13%!important}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap th:nth-child(2),
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap td:nth-child(2){width:9%!important}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap th:nth-child(3),
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap td:nth-child(3){width:12%!important}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap th:nth-child(4),
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap td:nth-child(4){width:20%!important}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap th:nth-child(5),
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap td:nth-child(5){width:31%!important}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap th:nth-child(6),
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap td:nth-child(6){width:15%!important}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-table-wrap td.student-row-actions{
    padding:7px 8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .name-link{
    color:#0f172a!important;
    font-size:15px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-subline{
    margin-top:4px!important;
    color:#64748b!important;
    font-size:12px!important;
    font-weight:800!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-belt-chip{
    border-radius:999px!important;
    padding:2px 7px!important;
    font-size:11px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-rank-chip{
    margin-top:5px!important;
    font-size:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-class-cell strong{
    font-size:13px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-class-cell span,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-guardian-cell{
    font-size:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-signal-list{
    display:flex!important;
    flex-wrap:wrap!important;
    justify-content:flex-start!important;
    align-content:flex-start!important;
    gap:5px!important;
    max-height:44px!important;
    overflow:hidden!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-badge{
    border-radius:5px!important;
    padding:3px 6px!important;
    font-size:11px!important;
    line-height:1.25!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-row-action-box{
    display:grid!important;
    grid-template-columns:48px minmax(80px,1fr) 40px!important;
    gap:5px!important;
    align-items:start!important;
    width:100%!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-row-action-box>.btn,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-row-action-box>form.inline .btn{
    min-height:31px!important;
    padding:5px 7px!important;
    border-radius:6px!important;
    font-size:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .status-change-form{
    grid-column:2 / -1!important;
    display:grid!important;
    grid-template-columns:minmax(70px,1fr) 40px!important;
    gap:5px!important;
    margin:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .status-change-form select{
    height:31px!important;
    min-height:31px!important;
    border-radius:6px!important;
    font-size:12px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-row-action-box .care-actions{
    grid-column:1 / -1!important;
    width:100%!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-row-action-box .care-actions summary{
    min-height:29px!important;
    border-radius:6px!important;
    font-size:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .detail-modal,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .edit-modal,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .care-sms-modal{
    border-radius:8px!important;
    box-shadow:0 18px 48px rgba(15,23,42,.22)!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .detail-modal{
    width:min(920px,calc(100vw - 48px))!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .detail-head,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .edit-head,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .care-sms-head{
    background:#fff!important;
    padding:16px 18px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .detail-head h2,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .edit-head h2,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .care-sms-head h2{
    font-size:20px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .detail-body{
    padding:16px 18px!important;
    gap:12px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .detail-grid{
    grid-template-columns:repeat(3,minmax(0,1fr))!important;
    gap:8px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .detail-field,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .detail-section{
    border-radius:6px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .edit-modal{
    top:28px!important;
    left:50%!important;
    right:auto!important;
    bottom:auto!important;
    width:min(1120px,calc(100vw - 80px))!important;
    height:calc(100vh - 56px)!important;
    max-width:none!important;
    transform:translateX(-50%)!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .edit-modal.open{
    transform:translateX(-50%)!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune.embed-page .form-guide,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune.embed-page .form-jump-bar,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune.embed-page .form-summary-strip,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune.embed-page .form-grid{
    max-width:1020px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .form-guide,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .form-jump-bar,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .form-summary-strip,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .form-grid{
    max-width:1120px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .form-guide{
    border-radius:8px!important;
    padding:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .form-grid{
    grid-template-columns:132px minmax(0,1fr)!important;
    gap:10px 14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .guardian-row,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .tuition-row,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .vehicle-row,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .vehicle-memo,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .vehicle-contact,
body.ieum-side-layout.ieum-dashboard-page.student-page-tune .vehicle-days{
    border-radius:6px!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark{
    --ieum-side-bg:#111827!important;
    background:#0f1724!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .side-sub,
body.ieum-dashboard-page.student-page-tune.ieum-dark .side-group,
body.ieum-dashboard-page.student-page-tune.ieum-dark .side-link,
body.ieum-dashboard-page.student-page-tune.ieum-dark .panel,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-hub,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-side,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-list-panel,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-table-wrap,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-card,
body.ieum-dashboard-page.student-page-tune.ieum-dark .detail-modal,
body.ieum-dashboard-page.student-page-tune.ieum-dark .edit-modal,
body.ieum-dashboard-page.student-page-tune.ieum-dark .care-sms-modal{
    background:#151f2e!important;
    border-color:#2c3a4f!important;
    color:#e5edf7!important;
    box-shadow:none!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .search,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-filter-note,
body.ieum-dashboard-page.student-page-tune.ieum-dark .detail-field,
body.ieum-dashboard-page.student-page-tune.ieum-dark .detail-section,
body.ieum-dashboard-page.student-page-tune.ieum-dark .form-guide,
body.ieum-dashboard-page.student-page-tune.ieum-dark .form-jump-bar,
body.ieum-dashboard-page.student-page-tune.ieum-dark .form-summary-card,
body.ieum-dashboard-page.student-page-tune.ieum-dark .guardian-row,
body.ieum-dashboard-page.student-page-tune.ieum-dark .tuition-total{
    background:#111827!important;
    border-color:#2c3a4f!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-table-wrap th{
    background:#1b2535!important;
    color:#d9e2ef!important;
    border-color:#2c3a4f!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-table-wrap td{
    background:#151f2e!important;
    color:#d9e2ef!important;
    border-color:#263244!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-table-wrap tbody tr:hover td{
    background:#192435!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark input,
body.ieum-dashboard-page.student-page-tune.ieum-dark select,
body.ieum-dashboard-page.student-page-tune.ieum-dark textarea,
body.ieum-dashboard-page.student-page-tune.ieum-dark .btn{
    background:#111827!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-list-count{
    background:#111827!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .side-group summary{
    background:#111827!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .side-group-title{
    color:#e5edf7!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .side-group summary:after{
    background:#223047!important;
    color:#cfe0f5!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .side-group[open] summary:after{
    background:#1f7dd9!important;
    color:#fff!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .care-actions summary,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-table-wrap .care-actions summary{
    background:#111827!important;
    border-color:#334155!important;
    color:#e5edf7!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .care-actions summary:before,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-table-wrap .care-actions summary:before{
    background:#1f7dd9!important;
    color:#fff!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .primary,
body.ieum-dashboard-page.student-page-tune.ieum-dark .btn.primary{
    background:#1f7dd9!important;
    border-color:#1f7dd9!important;
    color:#fff!important;
}
@media (max-width:1500px){
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-grid,
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .side-groups{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .search{
        grid-template-columns:minmax(240px,1fr) repeat(3,minmax(120px,.72fr)) auto!important;
    }
}
@media (max-width:820px){
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-flow-grid,
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .side-groups,
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .search{
        grid-template-columns:1fr!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-cards{
        grid-template-columns:1fr!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .detail-grid,
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .form-grid{
        grid-template-columns:1fr!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .dashboard-shell-help-group{
        display:none!important;
    }
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-head h1,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-head h2,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-list-head h2,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-list-count,
body.ieum-dashboard-page.student-page-tune.ieum-dark .name-link,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-rank-chip,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-class-cell strong,
body.ieum-dashboard-page.student-page-tune.ieum-dark .detail-head h2,
body.ieum-dashboard-page.student-page-tune.ieum-dark .edit-head h2,
body.ieum-dashboard-page.student-page-tune.ieum-dark .care-sms-head h2,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-card-name,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-card-field span,
body.ieum-dashboard-page.student-page-tune.ieum-dark .form-summary-card strong{
    color:#f8fafc!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-head .count,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-head-note,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-head p,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-list-head p,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-subline,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-class-cell span,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-guardian-cell,
body.ieum-dashboard-page.student-page-tune.ieum-dark .list-page-bar,
body.ieum-dashboard-page.student-page-tune.ieum-dark .detail-code,
body.ieum-dashboard-page.student-page-tune.ieum-dark .detail-section p,
body.ieum-dashboard-page.student-page-tune.ieum-dark .care-sms-help,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-card-code,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-card-field strong,
body.ieum-dashboard-page.student-page-tune.ieum-dark .form-summary-card span,
body.ieum-dashboard-page.student-page-tune.ieum-dark .weekday-help{
    color:#9aa8bb!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-card,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-card.warn,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-card.danger{
    background:#151f2e!important;
    border-color:#2c3a4f!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-card.warn{
    border-color:#654d2d!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-card.danger{
    border-color:#6b2c3d!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-main span,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-foot span{
    color:#9aa8bb!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-main strong,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-main b{
    color:#f8fafc!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-flow-foot{
    border-color:#263244!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-belt-chip{
    background:#0d3353!important;
    border-color:#1d5f91!important;
    color:#9bd7ff!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-badge{
    border:1px solid rgba(255,255,255,.08)!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-badge.good,
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-badge.info{
    background:#0d3353!important;
    color:#9bd7ff!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-badge.warn{
    background:#3a2c13!important;
    color:#ffd789!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-badge.danger{
    background:#3a1420!important;
    color:#ffb4c2!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .student-card-field{
    background:#111827!important;
    border-color:#2c3a4f!important;
}
body.ieum-dashboard-page.student-page-tune.ieum-dark .detail-actions,
body.ieum-dashboard-page.student-page-tune.ieum-dark .care-sms-actions{
    background:#111827!important;
    border-color:#2c3a4f!important;
}
@media (max-width:720px){
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card{
        padding:12px!important;
        border-radius:8px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card-head{
        gap:8px!important;
        margin-bottom:8px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card-name{
        font-size:17px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card-grid{
        grid-template-columns:repeat(2,minmax(0,1fr))!important;
        gap:7px!important;
        margin-bottom:8px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card-field{
        padding:8px!important;
        min-width:0!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card-field strong{
        font-size:11px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card-field span{
        display:block!important;
        min-width:0!important;
        overflow:hidden!important;
        text-overflow:ellipsis!important;
        white-space:nowrap!important;
        font-size:13px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card-field:nth-child(5){
        grid-column:1 / -1!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card-more{
        margin-top:8px!important;
        padding-top:8px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.student-page-tune .student-card-more summary{
        min-height:36px!important;
        padding:7px 9px!important;
    }
}
</style>
</head>
<body class="<?php echo $embed_mode ? 'embed-page' : 'ieum-side-layout ieum-dashboard-page'; ?> student-page-tune">
<?php if (!$embed_mode) { ?>
<?php echo ieum_admin_header('students', 'side'); ?>
<?php } ?>
<main class="wrap">
    <div id="studentAjaxStatus" class="ajax-status">목록을 불러오는 중입니다.</div>
    <div class="bar student-head">
        <div class="student-head-main">
            <h1>원생 관리</h1>
            <div class="count"><?php echo get_text($current_academy['academy_name']); ?></div>
            <p class="student-head-note">원생을 빠르게 등록하고, 이름을 눌러 상세를 확인한 뒤 팝업에서 바로 수정합니다. 수련비·차량·리포트 처리는 각 전용 화면에서 이어갑니다.</p>
        </div>
        <div class="student-head-actions">
            <?php if ($mode === 'form') { ?>
            <a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/students.php">목록</a>
            <?php } else { ?>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form">+ 원생 등록</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php">오늘 출석</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/student_import.php">엑셀 가져오기</a>
            <a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/student_groups.php">부별 명단</a>
            <?php } ?>
        </div>
    </div>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($last_saved_student_id > 0) { ?>
    <section class="next-actions">
        <strong><?php echo get_text($last_saved_student_name); ?> 원생 등록 다음 단계</strong>
        <p>앱 출석기에서 바로 사용할 수 있도록 사진/차량/수련비처럼 운영에 필요한 정보를 이어서 보강할 수 있습니다.</p>
        <div class="next-action-grid">
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form">다음 원생 등록</a>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/tablet_devices.php">앱 출석기 확인</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $last_saved_student_id; ?>">사진/정보 보강</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_assignments.php?assignment=none">차량 배정 확인</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php">수련비 확인</a>
        </div>
    </section>
    <?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>

    <?php if ($mode === 'form') {
        $form = $editing ?: array(
            'student_id' => 0,
            'student_code' => '',
            'student_name' => '',
            'student_phone' => '',
            'student_photo' => '',
            'birth_date' => '',
            'program_code' => $default_program_code,
            'school_name' => '',
            'grade_group' => '',
            'class_time_id' => 0,
            'attendance_week_type' => '5',
            'attendance_days' => 'mon,tue,wed,thu,fri',
            'admission_date' => '',
            'student_status' => 'enrolled',
            'enrollment_source' => '',
            'referrer_name' => '',
            'counseling_note' => '',
            'tuition_week_type' => '5',
            'tuition_amount' => 0,
            'sibling_discount_enabled' => 0,
            'sibling_discount_amount' => 0,
            'tuition_due_day' => 5,
            'tuition_note' => '',
            'promotion_enabled' => 1,
            'current_belt' => '',
            'current_poom_dan' => 0,
            'current_grade_level' => 0,
            'last_promotion_date' => '',
            'promotion_cycle_months' => 0,
            'promotion_memo' => '',
            'vehicle_pickup_enabled' => 0,
            'vehicle_pickup_stop_id' => 0,
            'vehicle_pickup_place' => '',
            'vehicle_pickup_contact_phone' => '',
            'vehicle_pickup_days' => '',
            'vehicle_pickup_memo' => '',
            'vehicle_dropoff_enabled' => 0,
            'vehicle_dropoff_stop_id' => 0,
            'vehicle_dropoff_place' => '',
            'vehicle_dropoff_contact_phone' => '',
            'vehicle_dropoff_days' => '',
            'vehicle_dropoff_memo' => '',
            'parent_name' => '',
            'parent_phone' => '',
            'memo' => '',
            'is_active' => 1,
        );
        $guardians = $editing ? ieum_fetch_guardians($academy_id, (int) $form['student_id']) : array();
        if (!$guardians) {
            $guardians[] = array(
                'guardian_name' => $form['parent_name'],
                'guardian_relation' => '',
                'guardian_phone' => $form['parent_phone'],
                'sms_attendance' => 1,
                'sms_checkout' => 0,
                'sms_tuition' => 1,
                'use_for_student_code' => 1,
                'is_primary' => 1,
            );
        }
        $vehicle_contact_options = array();
        if (!empty($form['student_phone'])) {
            $vehicle_contact_options[] = array('label' => '원생', 'phone' => $form['student_phone']);
        }
        foreach ($guardians as $guardian) {
            $guardian_phone = isset($guardian['guardian_phone']) ? trim($guardian['guardian_phone']) : '';
            if ($guardian_phone === '') {
                continue;
            }
            $guardian_label = isset($guardian['guardian_name']) && $guardian['guardian_name'] !== '' ? $guardian['guardian_name'] : '보호자';
            if (isset($guardian['guardian_relation']) && $guardian['guardian_relation'] !== '') {
                $guardian_label .= '(' . $guardian['guardian_relation'] . ')';
            }
            $vehicle_contact_options[] = array('label' => $guardian_label, 'phone' => $guardian_phone);
        }
        $vehicle_assignments = $editing ? ieum_fetch_vehicle_assignments($academy_id, (int) $form['student_id']) : array('pickup' => null, 'dropoff' => null);
        if ($vehicle_assignments['pickup']) {
            $form['vehicle_pickup_enabled'] = 1;
            $form['vehicle_pickup_stop_id'] = (int) $vehicle_assignments['pickup']['stop_id'];
            $form['vehicle_pickup_place'] = $vehicle_assignments['pickup']['place_name'];
            $form['vehicle_pickup_contact_phone'] = isset($vehicle_assignments['pickup']['contact_phone']) ? $vehicle_assignments['pickup']['contact_phone'] : '';
            $form['vehicle_pickup_days'] = isset($vehicle_assignments['pickup']['ride_days']) ? $vehicle_assignments['pickup']['ride_days'] : '';
            $form['vehicle_pickup_memo'] = isset($vehicle_assignments['pickup']['memo']) ? $vehicle_assignments['pickup']['memo'] : '';
        } elseif (!isset($form['vehicle_pickup_stop_id'])) {
            $form['vehicle_pickup_stop_id'] = 0;
        }
        if ($vehicle_assignments['dropoff']) {
            $form['vehicle_dropoff_enabled'] = 1;
            $form['vehicle_dropoff_stop_id'] = (int) $vehicle_assignments['dropoff']['stop_id'];
            $form['vehicle_dropoff_place'] = $vehicle_assignments['dropoff']['place_name'];
            $form['vehicle_dropoff_contact_phone'] = isset($vehicle_assignments['dropoff']['contact_phone']) ? $vehicle_assignments['dropoff']['contact_phone'] : '';
            $form['vehicle_dropoff_days'] = isset($vehicle_assignments['dropoff']['ride_days']) ? $vehicle_assignments['dropoff']['ride_days'] : '';
            $form['vehicle_dropoff_memo'] = isset($vehicle_assignments['dropoff']['memo']) ? $vehicle_assignments['dropoff']['memo'] : '';
        } elseif (!isset($form['vehicle_dropoff_stop_id'])) {
            $form['vehicle_dropoff_stop_id'] = 0;
        }
        $student_code_index = array();
        $code_result = sql_query("
            select student_id, student_code, student_name, birth_date, grade_group, is_active, student_status
              from " . IEUM_STUDENT_TABLE . "
             where academy_id = '{$academy_id}'
               and student_code <> ''
          order by student_code asc, is_active desc, student_name asc
        ", false);
        while ($code_row = sql_fetch_array($code_result)) {
            $code = (string) $code_row['student_code'];
            if (!isset($student_code_index[$code])) {
                $student_code_index[$code] = array();
            }
            $student_code_index[$code][] = array(
                'student_id' => (int) $code_row['student_id'],
                'name' => $code_row['student_name'],
                'birth_date' => $code_row['birth_date'],
                'grade' => ieum_grade_label($code_row['grade_group']),
                'active' => (int) $code_row['is_active'],
                'status_label' => ieum_student_row_status_label($code_row),
            );
        }
    ?>
    <section class="panel">
        <form method="post" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="student_id" value="<?php echo (int) $form['student_id']; ?>">
            <div class="form-guide">
                <strong><?php echo $form['student_id'] ? '원생 정보를 수정합니다.' : '처음 등록은 필수 정보만 넣고 저장해도 됩니다.'; ?></strong>
                <p>원생명과 보호자 연락처만 넣어도 원생번호가 연락처 뒷자리로 자동 확정됩니다. 차량, 사진, 세부 메모는 나중에 천천히 보강해도 됩니다.</p>
                <div class="guide-grid">
                    <div class="guide-card"><b>1. 필수</b><span>원생명 · 보호자 연락처</span></div>
                    <div class="guide-card"><b>2. 운영</b><span>수업 부 · 출석 요일 · 수련비</span></div>
                    <div class="guide-card"><b>3. 선택</b><span>사진 · 차량 · 상담 메모</span></div>
                </div>
            </div>
            <?php
            $summary_tuition = (int) (isset($form['tuition_amount']) ? $form['tuition_amount'] : 0);
            $summary_discount = !empty($form['sibling_discount_enabled']) ? (int) (isset($form['sibling_discount_amount']) ? $form['sibling_discount_amount'] : 0) : 0;
            $summary_bill = max(0, $summary_tuition - $summary_discount);
            $summary_rank = trim((string) (isset($form['current_belt']) ? $form['current_belt'] : ''));
            $summary_poom = (int) (isset($form['current_poom_dan']) ? $form['current_poom_dan'] : 0);
            $summary_grade = (int) (isset($form['current_grade_level']) ? $form['current_grade_level'] : 0);
            if ($summary_rank === '' && ($summary_poom > 0 || $summary_grade > 0)) {
                $summary_rank = '띠 미입력';
            }
            if ($summary_rank !== '') {
                $summary_rank .= ' · ' . $summary_poom . '품/단 ' . $summary_grade . '급';
            }
            $summary_vehicle = array();
            if (!empty($form['vehicle_pickup_enabled'])) {
                $summary_vehicle[] = '등원';
            }
            if (!empty($form['vehicle_dropoff_enabled'])) {
                $summary_vehicle[] = '하원';
            }
            ?>
            <nav class="form-jump-bar" aria-label="원생 정보 빠른 이동">
                <strong>바로 이동</strong>
                <a href="#studentFormRequired">필수</a>
                <a href="#studentFormBasic">기본</a>
                <a href="#studentFormPromotion">승급</a>
                <a href="#studentFormTuition">수련비</a>
                <a href="#studentFormVehicle">차량</a>
                <a href="#studentFormMemo">메모</a>
            </nav>
            <?php if ($form['student_id']) { ?>
            <div class="form-summary-strip" aria-label="현재 원생 핵심 정보">
                <div class="form-summary-card"><span>수련비</span><strong><?php echo $summary_bill > 0 ? number_format($summary_bill) . '원' : '미설정'; ?></strong></div>
                <div class="form-summary-card"><span>승급</span><strong><?php echo $summary_rank !== '' ? get_text($summary_rank) : '미설정'; ?></strong></div>
                <div class="form-summary-card"><span>차량</span><strong><?php echo $summary_vehicle ? get_text(implode(' · ', $summary_vehicle)) : '미이용'; ?></strong></div>
            </div>
            <?php } ?>
            <div class="form-grid">
                <div class="form-section-title" id="studentFormRequired">필수 정보</div>

                <label for="student_code">원생번호 <span class="required-hint">자동</span></label>
                <div class="student-code-field">
                    <input type="text" name="student_code" id="student_code" value="<?php echo get_text($form['student_code']); ?>" maxlength="8" inputmode="numeric">
                    <div class="field-help">보호자 연락처를 입력하면 뒷자리 4개가 자동으로 들어갑니다. 비워져 있어도 저장 시 연락처 뒷자리로 자동 확정됩니다.</div>
                    <div class="duplicate-alert" id="studentCodeDuplicateAlert" role="status" aria-live="polite"></div>
                </div>

                <label for="student_name">원생명 <span class="required-hint">필수</span></label>
                <input type="text" name="student_name" id="student_name" value="<?php echo get_text($form['student_name']); ?>" maxlength="50" required>

                <label for="student_phone">원생 연락처</label>
                <div class="student-phone-field">
                    <div class="student-phone-action">
                        <input type="text" name="student_phone" id="student_phone" value="<?php echo get_text(isset($form['student_phone']) ? $form['student_phone'] : ''); ?>" maxlength="13" inputmode="numeric" placeholder="원생 휴대폰이 있으면 입력">
                        <button type="button" class="btn muted" id="useStudentPhoneCode">원생번호로 사용</button>
                    </div>
                    <div class="field-help">입력 중 자동으로 하이픈이 붙습니다. 수동으로 하이픈을 넣지 않아도 됩니다.</div>
                </div>

                <label>보호자 <span class="required-hint">필수</span></label>
                <div class="guardian-list" id="guardianList">
                    <?php foreach ($guardians as $idx => $guardian) { ?>
                    <div class="guardian-row">
                        <div class="guardian-fields">
                            <input type="text" name="guardian_name[]" value="<?php echo get_text($guardian['guardian_name']); ?>" maxlength="50" placeholder="보호자명">
                            <input type="text" name="guardian_relation[]" value="<?php echo get_text(isset($guardian['guardian_relation']) ? $guardian['guardian_relation'] : ''); ?>" maxlength="30" placeholder="관계">
                            <input type="text" name="guardian_phone[]" value="<?php echo get_text($guardian['guardian_phone']); ?>" maxlength="13" inputmode="numeric" placeholder="010-0000-0000">
                        </div>
                        <div class="guardian-groups">
                            <div>
                                <div class="guardian-section-title">문자 수신</div>
                                <div class="guardian-flags">
                                    <label class="guardian-flag"><input type="checkbox" name="guardian_sms_attendance[<?php echo (int) $idx; ?>]" value="1" <?php echo !empty($guardian['sms_attendance']) ? 'checked' : ''; ?>> 등원</label>
                                    <label class="guardian-flag"><input type="checkbox" name="guardian_sms_checkout[<?php echo (int) $idx; ?>]" value="1" <?php echo !empty($guardian['sms_checkout']) ? 'checked' : ''; ?>> 하원</label>
                                    <label class="guardian-flag"><input type="checkbox" name="guardian_sms_tuition[<?php echo (int) $idx; ?>]" value="1" <?php echo !isset($guardian['sms_tuition']) || !empty($guardian['sms_tuition']) ? 'checked' : ''; ?>> 수련비</label>
                                </div>
                            </div>
                            <div>
                                <div class="guardian-section-title">원생번호 선택 (연락처 뒷자리)</div>
                                <div class="guardian-main">
                                    <label class="guardian-flag"><input type="checkbox" class="use-code" name="guardian_use_code[<?php echo (int) $idx; ?>]" value="1" <?php echo !empty($guardian['use_for_student_code']) ? 'checked' : ''; ?>> 원생번호로 사용</label>
                                    <label class="guardian-flag"><input type="checkbox" class="primary-guardian" name="guardian_primary[<?php echo (int) $idx; ?>]" value="1" <?php echo !empty($guardian['is_primary']) ? 'checked' : ''; ?>> 대표</label>
                                    <button type="button" class="btn muted remove-guardian">삭제</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                </div>

                <label></label>
                <button type="button" class="btn muted" id="addGuardian">+ 보호자 추가</button>

                <div class="form-section-title" id="studentFormBasic">원생 기본 설정</div>

                <label for="student_photo_file">원생 사진</label>
                <details class="optional-details" open>
                    <summary>등록된 사진은 앱 출석기 등원 완료 화면에 보입니다.</summary>
                    <div class="optional-details-inner photo-box">
                        <?php $student_photo_url = ieum_student_photo_url(isset($form['student_photo']) ? $form['student_photo'] : ''); ?>
                        <?php if ($student_photo_url !== '') { ?>
                        <img class="photo-preview" src="<?php echo get_text($student_photo_url); ?>" alt="">
                        <?php } else { ?>
                        <div class="photo-empty">사진 없음</div>
                        <?php } ?>
                        <div class="photo-controls">
                            <input type="file" name="student_photo_file" id="student_photo_file" accept="image/*">
                            <?php if ($student_photo_url !== '') { ?>
                            <label><input type="checkbox" name="delete_student_photo" value="1"> 현재 사진 삭제</label>
                            <?php } ?>
                            <div class="weekday-help">태블릿 등원 완료 화면에 표시됩니다. 정면 얼굴이 보이는 사진이 가장 좋습니다.</div>
                        </div>
                    </div>
                </details>
                <label for="birth_year">생년월일</label>
                <div class="date-selects">
                    <?php
                    $birth_value = isset($form['birth_date']) ? $form['birth_date'] : '';
                    $birth_valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_value);
                    $birth_year = $birth_valid ? (int) substr($birth_value, 0, 4) : 0;
                    $birth_month = $birth_valid ? (int) substr($birth_value, 5, 2) : 0;
                    $birth_day = $birth_valid ? (int) substr($birth_value, 8, 2) : 0;
                    $birth_current_year = (int) date('Y');
                    ?>
                    <input type="hidden" name="birth_date" id="birth_date" value="<?php echo get_text($birth_value); ?>">
                    <select id="birth_year" aria-label="생년">
                        <option value="">년도</option>
                        <?php for ($year = $birth_current_year - 2; $year >= $birth_current_year - 25; $year--) { ?>
                        <option value="<?php echo $year; ?>" <?php echo get_selected($birth_year, $year); ?>><?php echo $year; ?>년</option>
                        <?php } ?>
                    </select>
                    <select id="birth_month" aria-label="생월">
                        <option value="">월</option>
                        <?php for ($month = 1; $month <= 12; $month++) { ?>
                        <option value="<?php echo $month; ?>" <?php echo get_selected($birth_month, $month); ?>><?php echo $month; ?>월</option>
                        <?php } ?>
                    </select>
                    <select id="birth_day" aria-label="생일">
                        <option value="">일</option>
                        <?php for ($day = 1; $day <= 31; $day++) { ?>
                        <option value="<?php echo $day; ?>" <?php echo get_selected($birth_day, $day); ?>><?php echo $day; ?>일</option>
                        <?php } ?>
                    </select>
                </div>

                <label for="program_code">프로그램</label>
                <select name="program_code" id="program_code">
                    <?php foreach ($program_options as $program) { ?>
                    <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected(isset($form['program_code']) && $form['program_code'] !== '' ? $form['program_code'] : $default_program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
                    <?php } ?>
                </select>

                <label for="school_name">학교</label>
                <input type="text" name="school_name" id="school_name" value="<?php echo get_text(isset($form['school_name']) ? $form['school_name'] : ''); ?>" maxlength="100" placeholder="예: 아이이음초등학교">

                <label for="grade_group">학년/부</label>
                <select name="grade_group" id="grade_group">
                    <?php foreach (ieum_grade_options() as $value => $label) { ?>
                    <option value="<?php echo get_text($value); ?>" <?php echo get_selected($form['grade_group'], $value); ?>><?php echo get_text($label); ?></option>
                    <?php } ?>
                </select>

                <label for="class_time_id">수업 부</label>
                <select name="class_time_id" id="class_time_id">
                    <option value="0">선택 안함</option>
                    <?php foreach ($class_options as $class) { ?>
                    <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected((int) $form['class_time_id'], (int) $class['class_time_id']); ?>>
                        <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
                    </option>
                    <?php } ?>
                </select>

                <label>출석 요일</label>
                <div class="weekday-control">
                    <div style="display:grid;grid-template-columns:120px 1fr;gap:10px;align-items:center;margin-bottom:12px">
                        <label for="student_status">원생 상태</label>
                        <select name="student_status" id="student_status">
                            <?php foreach (ieum_student_status_options() as $value => $label) { ?>
                            <option value="<?php echo get_text($value); ?>" <?php echo get_selected(isset($form['student_status']) && $form['student_status'] !== '' ? $form['student_status'] : 'enrolled', $value); ?>><?php echo get_text($label); ?></option>
                            <?php } ?>
                        </select>
                        <label for="enrollment_source">입관 경로</label>
                        <select name="enrollment_source" id="enrollment_source">
                            <?php foreach (ieum_enrollment_source_options() as $value => $label) { ?>
                            <option value="<?php echo get_text($value); ?>" <?php echo get_selected(isset($form['enrollment_source']) ? $form['enrollment_source'] : '', $value); ?>><?php echo get_text($label); ?></option>
                            <?php } ?>
                        </select>
                        <label for="referrer_name">소개자/경로 메모</label>
                        <input type="text" name="referrer_name" id="referrer_name" value="<?php echo get_text(isset($form['referrer_name']) ? $form['referrer_name'] : ''); ?>" maxlength="80" placeholder="예: 김철수 보호자, 네이버 플레이스">
                        <label for="counseling_note">입관 상담 메모</label>
                        <input type="text" name="counseling_note" id="counseling_note" value="<?php echo get_text(isset($form['counseling_note']) ? $form['counseling_note'] : ''); ?>" maxlength="255" placeholder="예: 자신감 향상, 집중력 개선 희망">
                    </div>
                    <input type="hidden" name="attendance_week_type" id="attendance_week_type" value="<?php echo get_text($form['attendance_week_type'] ?: '5'); ?>">
                    <div class="weekday-presets">
                        <?php foreach (ieum_attendance_week_type_options() as $value => $label) { ?>
                        <button type="button" class="preset-btn <?php echo ($form['attendance_week_type'] ?: '5') === $value ? 'active' : ''; ?>" data-week-type="<?php echo get_text($value); ?>"><?php echo get_text($label); ?></button>
                        <?php } ?>
                    </div>
                    <div class="weekday-cards" id="weekdayCards">
                        <?php
                        $selected_days = explode(',', (string) ($form['attendance_days'] ?: 'mon,tue,wed,thu,fri'));
                        foreach (ieum_weekday_options() as $value => $label) {
                            $checked = in_array($value, $selected_days, true);
                        ?>
                        <label class="weekday-card <?php echo $checked ? 'selected' : ''; ?>">
                            <input type="checkbox" name="attendance_days[]" value="<?php echo get_text($value); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                            <?php echo get_text($label); ?>
                        </label>
                        <?php } ?>
                    </div>
                    <div class="weekday-help">선택된 요일에만 미등원 알림 대상이 됩니다. 주5회는 월~금이 자동 선택됩니다.</div>
                </div>

                <label>입관일</label>
                <div class="date-selects">
                    <?php
                    $admission_value = $form['admission_date'] ?: G5_TIME_YMD;
                    $admission_year = (int) substr($admission_value, 0, 4);
                    $admission_month = (int) substr($admission_value, 5, 2);
                    $admission_day = (int) substr($admission_value, 8, 2);
                    $current_year = (int) date('Y');
                    ?>
                    <input type="hidden" name="admission_date" id="admission_date" value="<?php echo get_text($admission_value); ?>">
                    <select id="admission_year" aria-label="입관 연도">
                        <?php for ($year = $current_year + 1; $year >= $current_year - 15; $year--) { ?>
                        <option value="<?php echo $year; ?>" <?php echo get_selected($admission_year, $year); ?>><?php echo $year; ?>년</option>
                        <?php } ?>
                    </select>
                    <select id="admission_month" aria-label="입관 월">
                        <?php for ($month = 1; $month <= 12; $month++) { ?>
                        <option value="<?php echo $month; ?>" <?php echo get_selected($admission_month, $month); ?>><?php echo $month; ?>월</option>
                        <?php } ?>
                    </select>
                    <select id="admission_day" aria-label="입관 일">
                        <?php for ($day = 1; $day <= 31; $day++) { ?>
                        <option value="<?php echo $day; ?>" <?php echo get_selected($admission_day, $day); ?>><?php echo $day; ?>일</option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-section-title" id="studentFormPromotion">승급 정보</div>

                <label>현재 띠 · 품/단 · 급</label>
                <details class="optional-details" open>
                    <summary>선택 입력입니다. 현재 띠와 0품/단 0급을 입력하면 승급 대상과 준비 띠 수량에 반영됩니다.</summary>
                    <div class="optional-details-inner promotion-box">
                        <label class="inline-check promotion-enabled">
                            <input type="checkbox" name="promotion_enabled" value="1" <?php echo !isset($form['promotion_enabled']) || !empty($form['promotion_enabled']) ? 'checked' : ''; ?>>
                            승급/승품 관리 사용
                        </label>
                        <div class="promotion-rank-grid">
                            <label>
                                <span>현재 띠</span>
                                <input type="text" name="current_belt" list="promotionBeltList" value="<?php echo get_text(isset($form['current_belt']) ? $form['current_belt'] : ''); ?>" maxlength="80" placeholder="예: 흰띠">
                            </label>
                            <label>
                                <span>품/단</span>
                                <select name="current_poom_dan">
                                    <?php for ($poom = 0; $poom <= 4; $poom++) { ?>
                                    <option value="<?php echo $poom; ?>" <?php echo get_selected((int) (isset($form['current_poom_dan']) ? $form['current_poom_dan'] : 0), $poom); ?>><?php echo $poom; ?>품/단</option>
                                    <?php } ?>
                                </select>
                            </label>
                            <label>
                                <span>급</span>
                                <select name="current_grade_level">
                                    <option value="0" <?php echo get_selected((int) (isset($form['current_grade_level']) ? $form['current_grade_level'] : 0), 0); ?>>0급</option>
                                    <?php for ($grade_no = 18; $grade_no >= 1; $grade_no--) { ?>
                                    <option value="<?php echo $grade_no; ?>" <?php echo get_selected((int) (isset($form['current_grade_level']) ? $form['current_grade_level'] : 0), $grade_no); ?>><?php echo $grade_no; ?>급</option>
                                    <?php } ?>
                                </select>
                            </label>
                        </div>
                        <div class="promotion-rank-grid">
                            <label>
                                <span>최근 승급일</span>
                                <input type="date" name="last_promotion_date" value="<?php echo get_text(isset($form['last_promotion_date']) && $form['last_promotion_date'] !== '0000-00-00' ? $form['last_promotion_date'] : ''); ?>">
                            </label>
                            <label>
                                <span>승급 주기</span>
                                <select name="promotion_cycle_months">
                                    <option value="0" <?php echo get_selected((int) (isset($form['promotion_cycle_months']) ? $form['promotion_cycle_months'] : 0), 0); ?>>도장 기본</option>
                                    <?php for ($cycle_month = 1; $cycle_month <= 4; $cycle_month++) { ?>
                                    <option value="<?php echo $cycle_month; ?>" <?php echo get_selected((int) (isset($form['promotion_cycle_months']) ? $form['promotion_cycle_months'] : 0), $cycle_month); ?>><?php echo $cycle_month; ?>개월</option>
                                    <?php } ?>
                                </select>
                            </label>
                            <label>
                                <span>승급 메모</span>
                                <input type="text" name="promotion_memo" value="<?php echo get_text(isset($form['promotion_memo']) ? $form['promotion_memo'] : ''); ?>" maxlength="255" placeholder="예: 품새 보강 필요">
                            </label>
                        </div>
                        <datalist id="promotionBeltList">
                            <?php foreach (ieum_promotion_belts($current_academy) as $belt_option) { ?>
                            <option value="<?php echo get_text($belt_option); ?>"></option>
                            <?php } ?>
                        </datalist>
                    </div>
                </details>

                <div class="form-section-title" id="studentFormTuition">운영 설정</div>

                <label>수련비</label>
                <div class="tuition-box">
                    <div class="tuition-row">
                        <select name="tuition_week_type" id="tuition_week_type">
                            <?php foreach (ieum_attendance_week_type_options() as $value => $label) { ?>
                            <option value="<?php echo get_text($value); ?>" <?php echo get_selected($form['tuition_week_type'] ?: $form['attendance_week_type'], $value); ?>><?php echo get_text($label); ?></option>
                            <?php } ?>
                        </select>
                        <label class="money-field" for="tuition_amount"><span>월</span><input type="number" name="tuition_amount" id="tuition_amount" value="<?php echo (int) $form['tuition_amount']; ?>" min="0" placeholder="수련비"><em>원</em></label>
                        <label class="inline-check"><input type="checkbox" name="sibling_discount_enabled" id="sibling_discount_enabled" value="1" <?php echo !empty($form['sibling_discount_enabled']) ? 'checked' : ''; ?>> 형제할인</label>
                        <label class="money-field" for="sibling_discount_amount"><span>할인</span><input type="number" name="sibling_discount_amount" id="sibling_discount_amount" value="<?php echo (int) $form['sibling_discount_amount']; ?>" min="0" placeholder="금액"><em>원</em></label>
                    </div>
                    <div class="tuition-row second">
                        <label class="due-label" for="tuition_due_day">매월 납부일</label>
                        <select name="tuition_due_day" id="tuition_due_day">
                            <?php for ($due_day = 1; $due_day <= 31; $due_day++) { ?>
                            <option value="<?php echo $due_day; ?>" <?php echo get_selected((int) ($form['tuition_due_day'] ?: 5), $due_day); ?>>매월 <?php echo $due_day; ?>일</option>
                            <?php } ?>
                        </select>
                        <div class="tuition-total" id="tuition_total">청구 예상 0원</div>
                    </div>
                    <input type="text" name="tuition_note" value="<?php echo get_text($form['tuition_note']); ?>" maxlength="255" placeholder="기타 할인/예외 사유">
                    <div class="weekday-help">주 횟수를 선택하면 수련비 정책이 자동 반영됩니다. 형제할인은 체크했을 때만 청구 예상 금액에서 차감됩니다.</div>
                </div>

                <label id="studentFormVehicle">차량 이용</label>
                <details class="optional-details" <?php echo !empty($form['vehicle_pickup_enabled']) || !empty($form['vehicle_dropoff_enabled']) ? 'open' : ''; ?>>
                    <summary>차량을 이용하는 원생만 설정합니다.</summary>
                    <div class="optional-details-inner vehicle-box">
                    <div class="vehicle-tools">
                        <button type="button" class="btn muted" id="copyPickupToDropoff">등원 설정을 하원에 복사</button>
                        <button type="button" class="btn muted" id="syncVehicleDays">출석 요일을 차량 요일에 적용</button>
                    </div>
                    <label class="vehicle-row">
                        <input type="checkbox" name="vehicle_pickup_enabled" id="vehicle_pickup_enabled" value="1" <?php echo !empty($form['vehicle_pickup_enabled']) ? 'checked' : ''; ?>>
                        <span>등원 차량</span>
                        <select id="vehicle_pickup_vehicle" class="vehicle-filter" data-target="vehicle_pickup_stop_id">
                            <option value="">전체 차량</option>
                            <?php foreach ($vehicle_label_options as $vehicle_label) { ?>
                            <option value="<?php echo get_text($vehicle_label); ?>"><?php echo get_text($vehicle_label); ?></option>
                            <?php } ?>
                        </select>
                        <select name="vehicle_pickup_stop_id" id="vehicle_pickup_stop_id">
                            <option value="0">노선 선택 안함</option>
                            <?php foreach ($vehicle_stop_options as $stop) { if ($stop['stop_type'] === 'dropoff') { continue; } ?>
                            <?php $stop_label = trim($stop['stop_time'] . ' ' . $stop['stop_name'] . (isset($stop['route_name']) && $stop['route_name'] !== '' ? ' / ' . $stop['route_name'] : '')); ?>
                            <option value="<?php echo (int) $stop['stop_id']; ?>" data-vehicle="<?php echo get_text(isset($stop['vehicle_label']) ? $stop['vehicle_label'] : ''); ?>" data-stop-name="<?php echo get_text($stop['stop_name']); ?>" <?php echo get_selected((int) $form['vehicle_pickup_stop_id'], (int) $stop['stop_id']); ?>><?php echo get_text($stop_label); ?></option>
                            <?php } ?>
                        </select>                        <input type="text" name="vehicle_pickup_place" id="vehicle_pickup_place" value="<?php echo get_text($form['vehicle_pickup_place']); ?>" maxlength="100" placeholder="예: 아이이음초등학교">
                    </label>
                    <label class="vehicle-memo">
                        <span>등원 메모</span>
                        <input type="text" name="vehicle_pickup_memo" id="vehicle_pickup_memo" value="<?php echo get_text(isset($form['vehicle_pickup_memo']) ? $form['vehicle_pickup_memo'] : ''); ?>" maxlength="255" placeholder="예: 정문 말고 후문, 금요일만 픽업 없음">
                    </label>
                    <label class="vehicle-contact">
                        <span>차량 연락처</span>
                        <select class="vehicle-contact-select" data-target="vehicle_pickup_contact_phone">
                            <option value="">직접 입력</option>
                            <?php foreach ($vehicle_contact_options as $contact_option) { ?>
                            <option value="<?php echo get_text($contact_option['phone']); ?>" <?php echo get_selected(isset($form['vehicle_pickup_contact_phone']) ? $form['vehicle_pickup_contact_phone'] : '', $contact_option['phone']); ?>><?php echo get_text($contact_option['label'] . ' ' . $contact_option['phone']); ?></option>
                            <?php } ?>
                        </select>
                        <input type="text" name="vehicle_pickup_contact_phone" id="vehicle_pickup_contact_phone" value="<?php echo get_text(isset($form['vehicle_pickup_contact_phone']) ? $form['vehicle_pickup_contact_phone'] : ''); ?>" maxlength="30" placeholder="일지에 표시할 연락처 1개">
                    </label>
                    <div class="vehicle-days">
                        <span>등원 요일</span>
                        <div class="ride-day-cards">
                            <?php
                            $pickup_days = explode(',', (string) (($form['vehicle_pickup_days'] ?: $form['attendance_days']) ?: 'mon,tue,wed,thu,fri'));
                            foreach (ieum_weekday_options() as $value => $label) {
                                $checked = in_array($value, $pickup_days, true);
                            ?>
                            <label class="ride-day-card <?php echo $checked ? 'selected' : ''; ?>"><input type="checkbox" name="vehicle_pickup_days[]" value="<?php echo get_text($value); ?>" <?php echo $checked ? 'checked' : ''; ?>><?php echo get_text($label); ?></label>
                            <?php } ?>
                        </div>
                    </div>
                    <label class="vehicle-row">
                        <input type="checkbox" name="vehicle_dropoff_enabled" id="vehicle_dropoff_enabled" value="1" <?php echo !empty($form['vehicle_dropoff_enabled']) ? 'checked' : ''; ?>>
                        <span>하원 차량</span>
                        <select id="vehicle_dropoff_vehicle" class="vehicle-filter" data-target="vehicle_dropoff_stop_id">
                            <option value="">전체 차량</option>
                            <?php foreach ($vehicle_label_options as $vehicle_label) { ?>
                            <option value="<?php echo get_text($vehicle_label); ?>"><?php echo get_text($vehicle_label); ?></option>
                            <?php } ?>
                        </select>
                        <select name="vehicle_dropoff_stop_id" id="vehicle_dropoff_stop_id">
                            <option value="0">노선 선택 안함</option>
                            <?php foreach ($vehicle_stop_options as $stop) { if ($stop['stop_type'] === 'pickup') { continue; } ?>
                            <?php $stop_label = trim($stop['stop_time'] . ' ' . $stop['stop_name'] . (isset($stop['route_name']) && $stop['route_name'] !== '' ? ' / ' . $stop['route_name'] : '')); ?>
                            <option value="<?php echo (int) $stop['stop_id']; ?>" data-vehicle="<?php echo get_text(isset($stop['vehicle_label']) ? $stop['vehicle_label'] : ''); ?>" data-stop-name="<?php echo get_text($stop['stop_name']); ?>" <?php echo get_selected((int) $form['vehicle_dropoff_stop_id'], (int) $stop['stop_id']); ?>><?php echo get_text($stop_label); ?></option>
                            <?php } ?>
                        </select>                        <input type="text" name="vehicle_dropoff_place" id="vehicle_dropoff_place" value="<?php echo get_text($form['vehicle_dropoff_place']); ?>" maxlength="100" placeholder="예: 아이이음 아파트 1004동">
                    </label>
                    <label class="vehicle-memo">
                        <span>하원 메모</span>
                        <input type="text" name="vehicle_dropoff_memo" id="vehicle_dropoff_memo" value="<?php echo get_text(isset($form['vehicle_dropoff_memo']) ? $form['vehicle_dropoff_memo'] : ''); ?>" maxlength="255" placeholder="예: 어학원 앞 하차, 보호자 확인 후 하차">
                    </label>
                    <label class="vehicle-contact">
                        <span>차량 연락처</span>
                        <select class="vehicle-contact-select" data-target="vehicle_dropoff_contact_phone">
                            <option value="">직접 입력</option>
                            <?php foreach ($vehicle_contact_options as $contact_option) { ?>
                            <option value="<?php echo get_text($contact_option['phone']); ?>" <?php echo get_selected(isset($form['vehicle_dropoff_contact_phone']) ? $form['vehicle_dropoff_contact_phone'] : '', $contact_option['phone']); ?>><?php echo get_text($contact_option['label'] . ' ' . $contact_option['phone']); ?></option>
                            <?php } ?>
                        </select>
                        <input type="text" name="vehicle_dropoff_contact_phone" id="vehicle_dropoff_contact_phone" value="<?php echo get_text(isset($form['vehicle_dropoff_contact_phone']) ? $form['vehicle_dropoff_contact_phone'] : ''); ?>" maxlength="30" placeholder="일지에 표시할 연락처 1개">
                    </label>
                    <div class="vehicle-days">
                        <span>하원 요일</span>
                        <div class="ride-day-cards">
                            <?php
                            $dropoff_days = explode(',', (string) (($form['vehicle_dropoff_days'] ?: $form['attendance_days']) ?: 'mon,tue,wed,thu,fri'));
                            foreach (ieum_weekday_options() as $value => $label) {
                                $checked = in_array($value, $dropoff_days, true);
                            ?>
                            <label class="ride-day-card <?php echo $checked ? 'selected' : ''; ?>"><input type="checkbox" name="vehicle_dropoff_days[]" value="<?php echo get_text($value); ?>" <?php echo $checked ? 'checked' : ''; ?>><?php echo get_text($label); ?></label>
                            <?php } ?>
                        </div>
                    </div>
                        <div class="weekday-help">등원/하원 위치를 따로 관리하면 차량표, 미탑승 확인, 하원 알림 문구에 활용할 수 있습니다.</div>
                    </div>
                </details>

                <div class="form-section-title" id="studentFormMemo">기타 관리</div>

                <label for="memo">메모</label>
                <textarea name="memo" id="memo" maxlength="255"><?php echo get_text($form['memo']); ?></textarea>

                <label for="is_active">사용 여부</label>
                <div><label><input type="checkbox" name="is_active" id="is_active" value="1" <?php echo $form['is_active'] ? 'checked' : ''; ?>> 사용</label></div>
            </div>
            <div class="actions">
                <?php if (!$form['student_id']) { ?>
                <button class="btn primary" type="submit" name="save_flow" value="new">저장 후 다음 등록</button>
                <button class="btn" type="submit" name="save_flow" value="list">저장 후 목록</button>
                <?php } else { ?>
                <button class="btn primary" type="submit" name="save_flow" value="list">수정 저장</button>
                <?php } ?>
                <a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/students.php">취소</a>
            </div>
        </form>
    </section>
    <?php } else { ?>
    <section class="panel student-flow-hub" aria-label="원생 업무 처리">
        <div class="student-flow-head">
            <div>
                <h2>오늘 확인할 원생</h2>
                <p>대시보드 카드에서 넘어온 일을 원생 목록, 프로필, 전용 처리 화면으로 바로 이어갑니다.</p>
            </div>
            <a class="btn muted" href="<?php echo IEUM_URL; ?>/dashboard.php">대시보드</a>
        </div>
        <div class="student-flow-grid">
            <?php foreach ($student_workflow_cards as $flow_card) {
                $flow_count = (int) $flow_card['count'];
                $flow_tone = $flow_count > 0 ? (string) $flow_card['tone'] : '';
            ?>
            <article class="student-flow-card <?php echo get_text($flow_tone); ?>">
                <a class="student-flow-main" href="<?php echo get_text($flow_card['url']); ?>">
                    <span><?php echo get_text($flow_card['desc']); ?></span>
                    <strong><?php echo get_text($flow_card['label']); ?></strong>
                    <b><?php echo number_format($flow_count); ?><small><?php echo get_text($flow_card['unit']); ?></small></b>
                </a>
                <div class="student-flow-foot">
                    <span><?php echo $flow_count > 0 ? '확인 필요' : '현재 안정'; ?></span>
                    <a href="<?php echo get_text($flow_card['action_url']); ?>"><?php echo get_text($flow_card['action_label']); ?></a>
                </div>
            </article>
            <?php } ?>
        </div>
    </section>
    <section class="student-workspace">
        <aside class="panel student-side" aria-label="원생 찾기">
            <h2 class="student-side-title">원생 찾기</h2>
            <p class="student-side-help">신규 등록, 검색, 수정에 필요한 기준만 남겼습니다. 수련비·차량·리포트는 전용 화면에서 처리합니다.</p>
            <div class="side-groups">
            <details class="side-group" <?php echo $filter_program !== '' ? 'open' : ''; ?>>
                <summary><span class="side-group-title">프로그램</span></summary>
                <div class="side-links">
                    <a class="side-link <?php echo ($filter_insight === '' && $filter_program === '' && $filter_class_time_raw === '' && $filter_grade === '' && $q === '') ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/students.php"><span>전체 원생</span><b><?php echo number_format((int) $total_all['cnt']); ?>명</b></a>
                    <?php foreach ($program_options as $program_option) {
                        $program_code = $program_option['program_code'];
                        $program_count = isset($program_counts[$program_code]) ? (int) $program_counts[$program_code] : 0;
                    ?>
                    <a class="side-link <?php echo $filter_program === $program_code ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('program_code' => $program_code))); ?>"><span><?php echo get_text($program_option['program_name']); ?></span><b><?php echo number_format($program_count); ?>명</b></a>
                    <?php } ?>
                </div>
            </details>
            <details class="side-group" <?php echo $filter_class_time_raw !== '' ? 'open' : ''; ?>>
                <summary><span class="side-group-title">수업 부</span></summary>
                <div class="side-links">
                    <a class="side-link <?php echo $filter_class_time_raw === 'unassigned' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('class_time_id' => 'unassigned'))); ?>"><span>미지정</span><b><?php echo number_format((int) $unassigned_count['cnt']); ?>명</b></a>
                    <?php foreach ($class_counts as $class_count) { ?>
                    <a class="side-link <?php echo $filter_class_time_id === (int) $class_count['class_time_id'] ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('class_time_id' => (int) $class_count['class_time_id']))); ?>"><span><?php echo get_text($class_count['class_name'] . ' ' . $class_count['start_time']); ?></span><b><?php echo number_format((int) $class_count['cnt']); ?>명</b></a>
                    <?php } ?>
                </div>
            </details>
            <details class="side-group secondary" <?php echo in_array($filter_insight, array('today_class', 'missing_today', 'long_absent'), true) ? 'open' : ''; ?>>
                <summary><span class="side-group-title">오늘 수업</span></summary>
                <div class="side-links">
                    <a class="side-link good <?php echo $filter_insight === 'today_class' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'today_class'))); ?>"><span>오늘 수업</span><b><?php echo number_format(isset($insight_counts['today_class']) ? $insight_counts['today_class'] : 0); ?>명</b></a>
                    <a class="side-link danger <?php echo $filter_insight === 'missing_today' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'missing_today'))); ?>"><span>오늘 미등원</span><b><?php echo number_format(isset($insight_counts['missing_today']) ? $insight_counts['missing_today'] : 0); ?>명</b></a>
                    <a class="side-link warn <?php echo $filter_insight === 'long_absent' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'long_absent'))); ?>"><span>장기 미등원</span><b><?php echo number_format(isset($insight_counts['long_absent']) ? $insight_counts['long_absent'] : 0); ?>명</b></a>
                </div>
            </details>
            <details class="side-group secondary" <?php echo in_array($filter_insight, array('memo', 'birthday_month', 'birthday_week', 'no_guardian', 'vehicle_unassigned', 'tuition_unpaid', 'report_blocked'), true) ? 'open' : ''; ?>>
                <summary><span class="side-group-title">자동 체크</span></summary>
                <div class="side-links">
                    <a class="side-link warn <?php echo $filter_insight === 'memo' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'memo'))); ?>"><span>아이들 메모</span><b><?php echo number_format(isset($insight_counts['memo']) ? $insight_counts['memo'] : 0); ?>명</b></a>
                    <a class="side-link <?php echo $filter_insight === 'birthday_month' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'birthday_month'))); ?>"><span>이번 달 생일자</span><b><?php echo number_format(isset($insight_counts['birthday_month']) ? $insight_counts['birthday_month'] : 0); ?>명</b></a>
                    <a class="side-link <?php echo $filter_insight === 'birthday_week' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'birthday_week'))); ?>"><span>7일 이내 생일</span><b><?php echo number_format(isset($insight_counts['birthday_week']) ? $insight_counts['birthday_week'] : 0); ?>명</b></a>
                    <a class="side-link warn <?php echo $filter_insight === 'no_guardian' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'no_guardian'))); ?>"><span>연락처 누락</span><b><?php echo number_format(isset($insight_counts['no_guardian']) ? $insight_counts['no_guardian'] : 0); ?>명</b></a>
                    <a class="side-link warn <?php echo $filter_insight === 'vehicle_unassigned' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'vehicle_unassigned'))); ?>"><span>차량 미배정</span><b><?php echo number_format(isset($insight_counts['vehicle_unassigned']) ? $insight_counts['vehicle_unassigned'] : 0); ?>명</b></a>
                    <a class="side-link danger <?php echo $filter_insight === 'tuition_unpaid' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'tuition_unpaid'))); ?>"><span>수련비 미납</span><b><?php echo number_format(isset($insight_counts['tuition_unpaid']) ? $insight_counts['tuition_unpaid'] : 0); ?>명</b></a>
                    <a class="side-link danger <?php echo $filter_insight === 'report_blocked' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => 'report_blocked'))); ?>"><span>리포트 발송 불가</span><b><?php echo number_format(isset($insight_counts['report_blocked']) ? $insight_counts['report_blocked'] : 0); ?>명</b></a>
                </div>
            </details>
            </div>
        </aside>
    <section class="panel student-list-panel">
        <div class="student-list-head">
            <div>
                <h2>원생 목록</h2>
                <p>장기 미등원, 수련비 미납, 리포트 불가, 아이들 메모, 생일, 차량 메모 순으로 관리가 필요한 원생을 먼저 보여줍니다.</p>
            </div>
            <div class="student-list-count"><?php echo number_format((int) $total['cnt']); ?>명</div>
        </div>
        <div class="bar">
            <form method="get" class="search">
                <input type="text" name="q" value="<?php echo get_text($q); ?>" placeholder="원생번호, 원생명, 보호자, 연락처 검색">
                <select name="program_code">
                    <option value="">전체 프로그램</option>
                    <?php foreach ($program_options as $program_option) { ?>
                    <option value="<?php echo get_text($program_option['program_code']); ?>" <?php echo get_selected($filter_program, $program_option['program_code']); ?>><?php echo get_text($program_option['program_name']); ?></option>
                    <?php } ?>
                </select>
                <select name="grade_group">
                    <?php foreach (ieum_grade_options() as $value => $label) { ?>
                    <option value="<?php echo get_text($value); ?>" <?php echo get_selected($filter_grade, $value); ?>><?php echo get_text($label); ?></option>
                    <?php } ?>
                </select>
                <select name="class_time_id">
                    <option value="">전체 부</option>
                    <option value="unassigned" <?php echo get_selected($filter_class_time_raw, 'unassigned'); ?>>미지정</option>
                    <?php foreach ($class_options as $class) { ?>
                    <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($filter_class_time_raw, (string) (int) $class['class_time_id']); ?>>
                        <?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?>
                    </option>
                    <?php } ?>
                </select>
                <?php if ($filter_insight !== '') { ?>
                <input type="hidden" name="insight" value="<?php echo get_text($filter_insight); ?>">
                <?php } ?>
                <?php if ($filter_open_only) { ?>
                <input type="hidden" name="open" value="1">
                <?php } ?>
                <select name="page_size" aria-label="페이지당 원생 수">
                    <?php foreach ($page_size_options as $size) { ?>
                    <option value="<?php echo (int) $size; ?>" <?php echo get_selected((string) $page_size, (string) $size); ?>><?php echo (int) $size; ?>명씩</option>
                    <?php } ?>
                </select>
                <button type="submit" class="btn">검색</button>
                <?php if ($q !== '' || $filter_program !== '' || $filter_grade !== '' || $filter_class_time_raw !== '' || $filter_insight !== '' || $filter_open_only) { ?><a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/students.php">전체</a><?php } ?>
            </form>
        </div>
        <div class="list-page-bar">
            <span>현재 <strong><?php echo number_format($page_first); ?>-<?php echo number_format($page_last); ?></strong>명 표시 / 전체 <strong><?php echo number_format($total_count); ?></strong>명</span>
            <span><?php echo number_format($page); ?> / <?php echo number_format($total_pages); ?> 페이지</span>
        </div>
        <?php if ($filter_insight !== '') { ?>
        <p class="student-filter-note">현재 <strong><?php echo get_text($insight_options[$filter_insight]); ?></strong><?php echo $filter_open_only ? ' 중 아직 확인 처리되지 않은 항목만' : ''; ?> 조건으로 원생 목록을 보고 있습니다.</p>
        <?php $insight_action_help = ieum_student_insight_action_help($filter_insight); ?>
        <?php if ($insight_action_help !== '') { ?><p class="student-filter-note secondary"><?php echo get_text($insight_action_help); ?></p><?php } ?>
        <?php } else { ?>
        <p class="student-filter-note">전체 목록도 관리 신호 우선순위로 정렬됩니다. 이름을 누르면 원생 프로필에서 다음 케어를 확인할 수 있습니다.</p>
        <?php } ?>
        <div class="student-table-wrap">
        <table>
            <thead>
            <tr>
                <th scope="col">원생</th>
                <th scope="col">품/단급</th>
                <th scope="col">수업</th>
                <th scope="col">보호자</th>
                <th scope="col">관리 신호</th>
                <th scope="col">바로 처리</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $i = 0;
            foreach ($student_rows as $row) {
                $i++;
                $row_class = $row['is_active'] ? '' : 'inactive';
                $last_attendance = isset($row['last_attendance_date']) ? $row['last_attendance_date'] : '';
                $last_attendance_label = $last_attendance ? date('Y-m-d', strtotime($last_attendance)) : '출석 기록 없음';
                $tuition_due = max(0, (int) $row['tuition_amount_due'] - (int) $row['tuition_amount_paid']);
                if ($tuition_due > 0 && $row['tuition_status'] !== 'paid') {
                    $tuition_summary = '미납 ' . number_format($tuition_due) . '원';
                } elseif ((int) $row['tuition_amount_due'] > 0) {
                    $tuition_summary = '정상 · 청구 ' . number_format((int) $row['tuition_amount_due']) . '원';
                } elseif ((int) $row['tuition_amount'] > 0) {
                    $tuition_summary = '월 ' . number_format((int) $row['tuition_amount']) . '원';
                } else {
                    $tuition_summary = '-';
                }
                $promotion_status = ieum_promotion_status($current_academy, $row, date('Y-m'));
                $promotion_next = isset($promotion_status['next_rank']) ? $promotion_status['next_rank'] : array('belt' => '', 'poom_dan' => 0, 'grade_level' => 0);
                $promotion_current_label = ieum_students_promotion_rank_display($current_academy, $promotion_status);
                $promotion_next_label = empty($promotion_status['enabled'])
                    ? '-'
                    : ieum_students_promotion_rank_display($current_academy, $promotion_status, $promotion_next);
                $promotion_current_belt = isset($promotion_status['belt']) ? trim((string) $promotion_status['belt']) : '';
                $promotion_current_poom_dan = isset($promotion_status['poom_dan']) ? (int) $promotion_status['poom_dan'] : 0;
                $promotion_current_grade_level = isset($promotion_status['grade_level']) ? (int) $promotion_status['grade_level'] : 0;
                $class_belt_label = '';
                $class_level_label = '';
                if (!empty($promotion_status['enabled'])) {
                    if ($promotion_current_belt === '' && function_exists('ieum_promotion_belt_for_grade')) {
                        $promotion_current_belt = ieum_promotion_belt_for_grade($current_academy, $promotion_current_grade_level, $promotion_current_poom_dan);
                    }
                    $class_belt_label = $promotion_current_belt !== '' ? $promotion_current_belt : '띠 미지정';
                    $class_level_label = ieum_students_promotion_level_badge($promotion_current_poom_dan, $promotion_current_grade_level);
                }
                $student_signals = ieum_student_care_signals($row, G5_TIME_YMD);
                $student_care_plan = ieum_student_care_plan_text($student_signals);
                $primary_signal_key = $student_signals && isset($student_signals[0]['key']) ? $student_signals[0]['key'] : '';
                $student_status_value = ieum_student_row_status_value($row);
                $student_status_label = ieum_student_status_label($student_status_value);
                $detail_payload = array(
                    'id' => (int) $row['student_id'],
                    'name' => $row['student_name'],
                    'code' => $row['student_code'],
                    'status' => $student_status_label,
                    'program' => ieum_program_label($academy_id, isset($row['program_code']) ? $row['program_code'] : ''),
                    'grade' => ieum_grade_label($row['grade_group']),
                    'classTime' => $row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '미지정',
                    'attendanceDays' => ieum_attendance_days_label(isset($row['attendance_days']) ? $row['attendance_days'] : ''),
                    'studentPhone' => isset($row['student_phone']) && $row['student_phone'] !== '' ? $row['student_phone'] : '-',
                    'lastAttendance' => $last_attendance_label,
                    'tuition' => $tuition_summary,
                    'school' => isset($row['school_name']) && $row['school_name'] !== '' ? $row['school_name'] : '-',
                    'admissionDate' => isset($row['admission_date']) && $row['admission_date'] !== '0000-00-00' && $row['admission_date'] !== '' ? $row['admission_date'] : '-',
                    'promotionCurrent' => $promotion_current_label !== '' ? $promotion_current_label : '미지정',
                    'promotionNext' => $promotion_next_label !== '' ? $promotion_next_label : '-',
                    'promotionNextDate' => !empty($promotion_status['next_date']) ? $promotion_status['next_date'] : '-',
                    'guardian' => $row['guardian_summary'] ? str_replace('<br>', "\n", $row['guardian_summary']) : '',
                    'vehicle' => $row['vehicle_summary'] ? str_replace('<br>', "\n", $row['vehicle_summary']) : '차량 이용 없음',
                    'memo' => $row['memo'],
                    'counselingNote' => isset($row['counseling_note']) ? $row['counseling_note'] : '',
                    'signals' => $student_signals,
                    'carePlan' => $student_care_plan,
                    'profileStatus' => ieum_student_profile_status_text($row),
                    'smsFlow' => ieum_student_sms_flow_text($row, $alert_contact_count),
                    'recentSms' => ieum_student_recent_sms_text($academy_id, (int) $row['student_id']),
                    'recentActivity' => ieum_student_recent_activity_text($academy_id, (int) $row['student_id'], $row),
                    'contactsUrl' => IEUM_URL . '/admin/contacts.php',
                    'attendanceUrl' => IEUM_URL . '/admin/attendance_today.php?class_time_id=' . (int) $row['class_time_id'] . '#classAttendance',
                    'tuitionUrl' => IEUM_URL . '/admin/tuition_payments.php?billing_month=' . urlencode($billing_month) . '&payment_filter=unpaid#paymentList',
                    'smsUrl' => IEUM_URL . '/admin/sms_queue.php?q=' . urlencode($row['student_code'] !== '' ? $row['student_code'] : $row['student_name']),
                    'reportUrl' => IEUM_URL . '/admin/monthly_close.php?month=' . urlencode($billing_month),
                    'vehicleUrl' => IEUM_URL . '/admin/vehicle_assignments.php',
                    'editUrl' => IEUM_URL . '/admin/students.php?mode=form&student_id=' . (int) $row['student_id'],
                );
                $detail_json = htmlspecialchars(json_encode($detail_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td class="left student-name-cell">
                    <button type="button" class="name-link student-detail-open" data-detail="<?php echo $detail_json; ?>" onclick="return window.ieumOpenStudentDetailFromButton ? window.ieumOpenStudentDetailFromButton(this) : true;"><?php echo get_text($row['student_name']); ?></button>
                    <span class="student-subline"><?php echo get_text($row['student_code']); ?> · <?php echo get_text(ieum_program_label($academy_id, isset($row['program_code']) ? $row['program_code'] : '')); ?> · <?php echo get_text($student_status_label); ?></span>
                </td>
                <td class="left student-rank-cell">
                    <?php if ($class_belt_label !== '' || $class_level_label !== '') { ?>
                    <?php if ($class_belt_label !== '') { ?><span class="student-belt-chip"><?php echo get_text($class_belt_label); ?></span><?php } ?>
                    <?php if ($class_level_label !== '') { ?><span class="student-rank-chip"><?php echo get_text($class_level_label); ?></span><?php } ?>
                    <?php } ?>
                </td>
                <td class="left student-class-cell">
                    <strong><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '미지정'); ?></strong>
                    <span><?php echo get_text(ieum_grade_label($row['grade_group'])); ?> · <?php echo get_text(ieum_attendance_days_label(isset($row['attendance_days']) ? $row['attendance_days'] : '')); ?></span>
                </td>
                <td class="left student-guardian-cell"><?php echo $row['guardian_summary'] ? nl2br(get_text(str_replace('<br>', "\n", $row['guardian_summary']))) : '<span class="muted-text">등록 없음</span>'; ?></td>
                <td class="left student-signal-cell">
                    <div class="student-signal-list">
                        <?php foreach ($student_signals as $signal) { ?><span class="student-badge <?php echo get_text($signal['tone']); ?>" title="<?php echo get_text($signal['action']); ?>"><?php echo get_text($signal['label']); ?></span><?php } ?>
                        <?php if (!$student_signals) { ?><span class="muted-text">-</span><?php } ?>
                    </div>
                </td>
                <td class="student-row-actions">
                    <div class="student-row-action-box">
                    <a class="btn student-edit-modal-open" data-student-name="<?php echo get_text($row['student_name']); ?>" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $row['student_id']; ?>" onclick="return window.ieumOpenStudentEditFromLink ? window.ieumOpenStudentEditFromLink(this) : true;">수정</a>
                    <form method="post" class="inline status-change-form" onsubmit="return confirm('선택한 상태로 변경할까요?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="change_status">
                        <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                        <select name="quick_student_status" aria-label="원생 상태">
                            <?php foreach (ieum_student_quick_status_options() as $status_value => $status_label) { ?>
                            <option value="<?php echo get_text($status_value); ?>" <?php echo get_selected($student_status_value, $status_value); ?>><?php echo get_text($status_label); ?></option>
                            <?php } ?>
                        </select>
                        <button type="submit" class="btn muted">적용</button>
                    </form>
                    <details class="care-actions">
                        <summary>빠른 처리</summary>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="quick_counseling_note">
                            <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                            <input type="text" name="quick_counseling_note" value="<?php echo get_text($row['counseling_note']); ?>" maxlength="255" placeholder="상담/확인 메모">
                            <button type="submit" class="btn muted">메모 저장</button>
                        </form>
                        <?php if ($primary_signal_key !== '') { ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="resolve_care_signal">
                            <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                            <input type="hidden" name="care_signal_key" value="<?php echo get_text($primary_signal_key); ?>">
                            <button type="submit" class="btn muted">오늘 확인 처리</button>
                        </form>
                        <?php } ?>
                        <div class="care-buttons">
                            <form method="post" class="quick-care-sms-form" data-care-title="생일 축하 문자" data-care-default="<?php echo get_text(ieum_student_care_sms_message($row['student_name'], 'birthday', $academy_id)); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="queue_care_sms">
                                <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                                <input type="hidden" name="care_message_type" value="birthday">
                                <input type="hidden" name="care_message" value="<?php echo get_text(ieum_student_care_sms_message($row['student_name'], 'birthday', $academy_id)); ?>">
                                <button type="submit" class="btn muted">생일 문자</button>
                            </form>
                            <form method="post" class="quick-care-sms-form" data-care-title="장기 미등원 안부 문자" data-care-default="<?php echo get_text(ieum_student_care_sms_message($row['student_name'], 'long_absent', $academy_id)); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="queue_care_sms">
                                <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                                <input type="hidden" name="care_message_type" value="long_absent">
                                <input type="hidden" name="care_message" value="<?php echo get_text(ieum_student_care_sms_message($row['student_name'], 'long_absent', $academy_id)); ?>">
                                <button type="submit" class="btn muted">안부 문자</button>
                            </form>
                        </div>
                    </details>
                    </div>
                </td>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?>
            <tr>
                <td colspan="6">등록된 원생이 없습니다.</td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <div class="student-cards">
            <?php foreach ($student_rows as $row) { ?>
            <?php
            $last_attendance = isset($row['last_attendance_date']) ? $row['last_attendance_date'] : '';
            $last_attendance_label = $last_attendance ? date('m/d', strtotime($last_attendance)) . ' 출석' : '출석 없음';
            $last_attendance_class = $last_attendance ? 'good' : 'warn';
            $vehicle_count = isset($row['vehicle_count']) ? (int) $row['vehicle_count'] : 0;
            $tuition_due = max(0, (int) $row['tuition_amount_due'] - (int) $row['tuition_amount_paid']);
            $tuition_badge_label = $tuition_due > 0 && $row['tuition_status'] !== 'paid' ? '미납 ' . number_format($tuition_due) . '원' : '수련비 정상';
            $tuition_badge_class = $tuition_due > 0 && $row['tuition_status'] !== 'paid' ? 'danger' : 'good';
            $student_signals = ieum_student_care_signals($row, G5_TIME_YMD);
            $primary_signal_key = $student_signals && isset($student_signals[0]['key']) ? $student_signals[0]['key'] : '';
            $student_status_value = ieum_student_row_status_value($row);
            $student_status_label = ieum_student_status_label($student_status_value);
            $card_promotion_status = ieum_promotion_status($current_academy, $row, date('Y-m'));
            $card_promotion_current = ieum_students_promotion_rank_display($current_academy, $card_promotion_status);
            ?>
            <article class="student-card <?php echo $row['is_active'] ? '' : 'inactive'; ?>">
                <div class="student-card-head">
                    <div>
                        <div class="student-card-name"><?php echo get_text($row['student_name']); ?></div>
                        <div class="student-card-code"><?php echo get_text($row['student_code']); ?> · <?php echo get_text(ieum_program_label($academy_id, isset($row['program_code']) ? $row['program_code'] : '')); ?></div>
                    </div>
                    <span class="student-card-status status-<?php echo get_text($student_status_value); ?> <?php echo $student_status_value === 'enrolled' ? 'active' : ''; ?>"><?php echo get_text($student_status_label); ?></span>
                </div>
                <div class="student-card-badges">
                    <?php foreach ($student_signals as $signal) { ?><span class="student-badge <?php echo get_text($signal['tone']); ?>" title="<?php echo get_text($signal['action']); ?>"><?php echo get_text($signal['label']); ?></span><?php } ?>
                    <?php if (!$student_signals) { ?><span class="student-badge <?php echo $last_attendance_class; ?>"><?php echo get_text($last_attendance_label); ?></span><span class="student-badge <?php echo $tuition_badge_class; ?>"><?php echo get_text($tuition_badge_label); ?></span><?php } ?>
                </div>
                <div class="student-card-grid">
                    <div class="student-card-field"><strong>품/단급</strong><span><?php echo get_text($card_promotion_current); ?></span></div>
                    <div class="student-card-field"><strong>학년/부</strong><span><?php echo get_text(ieum_grade_label($row['grade_group'])); ?></span></div>
                    <div class="student-card-field"><strong>수업 부</strong><span><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : '미지정'); ?></span></div>
                    <div class="student-card-field"><strong>출석 요일</strong><span><?php echo get_text(ieum_attendance_days_label(isset($row['attendance_days']) ? $row['attendance_days'] : '')); ?></span></div>
                    <div class="student-card-field"><strong>관리 메모</strong><span><?php echo get_text($row['memo'] ?: '-'); ?></span></div>
                </div>
                <details class="student-card-more">
                    <summary>상세/처리 열기</summary>
                <div class="student-card-section">
                    <strong>보호자</strong>
                    <div><?php echo $row['guardian_summary'] ? nl2br(get_text(str_replace('<br>', "\n", $row['guardian_summary']))) : '<span class="student-card-empty">등록 없음</span>'; ?></div>
                </div>
                <div class="student-card-section">
                    <strong>차량</strong>
                    <div><?php echo $row['vehicle_summary'] ? nl2br(get_text(str_replace('<br>', "\n", $row['vehicle_summary']))) : '<span class="student-card-empty">이용 없음</span>'; ?></div>
                </div>
                <?php if (!empty($row['counseling_note'])) { ?>
                <div class="student-card-section">
                    <strong>상담 메모</strong>
                    <div><?php echo get_text($row['counseling_note']); ?></div>
                </div>
                <?php } ?>
                <div class="student-card-section student-card-actions">
                    <a class="btn student-edit-modal-open" data-student-name="<?php echo get_text($row['student_name']); ?>" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $row['student_id']; ?>" onclick="return window.ieumOpenStudentEditFromLink ? window.ieumOpenStudentEditFromLink(this) : true;">수정</a>
                    <form method="post" class="inline status-change-form" onsubmit="return confirm('선택한 상태로 변경할까요?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="change_status">
                        <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                        <select name="quick_student_status" aria-label="원생 상태">
                            <?php foreach (ieum_student_quick_status_options() as $status_value => $status_label) { ?>
                            <option value="<?php echo get_text($status_value); ?>" <?php echo get_selected($student_status_value, $status_value); ?>><?php echo get_text($status_label); ?></option>
                            <?php } ?>
                        </select>
                        <button type="submit" class="btn muted">적용</button>
                    </form>
                    <details class="care-actions">
                        <summary>빠른 처리</summary>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="quick_counseling_note">
                            <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                            <input type="text" name="quick_counseling_note" value="<?php echo get_text($row['counseling_note']); ?>" maxlength="255" placeholder="상담/확인 메모">
                            <button type="submit" class="btn muted">메모 저장</button>
                        </form>
                        <?php if ($primary_signal_key !== '') { ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="resolve_care_signal">
                            <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                            <input type="hidden" name="care_signal_key" value="<?php echo get_text($primary_signal_key); ?>">
                            <button type="submit" class="btn muted">오늘 확인 처리</button>
                        </form>
                        <?php } ?>
                        <div class="care-buttons">
                            <form method="post" class="quick-care-sms-form" data-care-title="생일 축하 문자" data-care-default="<?php echo get_text(ieum_student_care_sms_message($row['student_name'], 'birthday', $academy_id)); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="queue_care_sms">
                                <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                                <input type="hidden" name="care_message_type" value="birthday">
                                <input type="hidden" name="care_message" value="<?php echo get_text(ieum_student_care_sms_message($row['student_name'], 'birthday', $academy_id)); ?>">
                                <button type="submit" class="btn muted">생일 문자</button>
                            </form>
                            <form method="post" class="quick-care-sms-form" data-care-title="장기 미등원 안부 문자" data-care-default="<?php echo get_text(ieum_student_care_sms_message($row['student_name'], 'long_absent', $academy_id)); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="queue_care_sms">
                                <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                                <input type="hidden" name="care_message_type" value="long_absent">
                                <input type="hidden" name="care_message" value="<?php echo get_text(ieum_student_care_sms_message($row['student_name'], 'long_absent', $academy_id)); ?>">
                                <button type="submit" class="btn muted">안부 문자</button>
                            </form>
                        </div>
                    </details>
                </div>
                </details>
            </article>
            <?php } ?>
            <?php if (!$student_rows) { ?>
            <div class="empty-card">등록된 원생이 없습니다.</div>
            <?php } ?>
        </div>
        <?php if ($total_pages > 1) { ?>
        <nav class="pagination" aria-label="원생 목록 페이지">
            <a class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('page' => max(1, $page - 1)))); ?>">이전</a>
            <?php
            $page_start = max(1, $page - 2);
            $page_end = min($total_pages, $page + 2);
            if ($page_start > 1) {
            ?>
            <a class="page-link" href="<?php echo get_text(ieum_student_list_url(array('page' => 1))); ?>">1</a>
            <?php if ($page_start > 2) { ?><span class="page-link disabled">...</span><?php } ?>
            <?php } ?>
            <?php for ($page_no = $page_start; $page_no <= $page_end; $page_no++) { ?>
            <a class="page-link <?php echo $page_no === $page ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('page' => $page_no))); ?>"><?php echo number_format($page_no); ?></a>
            <?php } ?>
            <?php if ($page_end < $total_pages) { ?>
            <?php if ($page_end < $total_pages - 1) { ?><span class="page-link disabled">...</span><?php } ?>
            <a class="page-link" href="<?php echo get_text(ieum_student_list_url(array('page' => $total_pages))); ?>"><?php echo number_format($total_pages); ?></a>
            <?php } ?>
            <a class="page-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('page' => min($total_pages, $page + 1)))); ?>">다음</a>
        </nav>
        <?php } ?>
        <div class="detail-backdrop" id="studentDetailBackdrop" hidden></div>
        <section class="detail-modal" id="studentDetailModal" aria-hidden="true" aria-labelledby="studentDetailTitle" role="dialog" hidden>
            <div class="detail-head">
                <div>
                    <h2 id="studentDetailTitle">원생 프로필</h2>
                    <div class="detail-code" id="studentDetailCode">-</div>
                </div>
                <div class="detail-head-tools">
                    <a class="btn primary student-edit-modal-open student-detail-edit-action" id="studentDetailEditTop" href="#" onclick="return window.ieumOpenStudentEditFromLink ? window.ieumOpenStudentEditFromLink(this) : true;">수정하기</a>
                    <button type="button" class="detail-close" id="studentDetailClose" aria-label="닫기">×</button>
                </div>
            </div>
            <div class="detail-body">
                <div class="detail-section detail-signal-section">
                    <h3>관리 신호</h3>
                    <div class="detail-signal-list" id="detailSignals"><span class="muted-text">특이 신호 없음</span></div>
                </div>
                <div class="detail-section">
                    <h3>다음 확인</h3>
                    <p id="detailCarePlan" class="detail-empty">오늘 특별히 처리할 신호는 없습니다.</p>
                </div>
                <div class="detail-section detail-command-section">
                    <h3>바로가기</h3>
                    <div class="detail-command-grid">
                        <a class="detail-command-link" id="detailAttendanceLink" href="<?php echo IEUM_URL; ?>/admin/attendance_today.php"><strong>출석 확인</strong><span>오늘 출석/미등원</span></a>
                        <a class="detail-command-link" id="detailTuitionLink" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php"><strong>수련비 확인</strong><span>납부 상태/안내</span></a>
                        <a class="detail-command-link" id="detailSmsLink" href="<?php echo IEUM_URL; ?>/admin/sms_queue.php"><strong>문자 발송 기록</strong><span>발송 결과 확인</span></a>
                        <a class="detail-command-link" id="detailReportLink" href="<?php echo IEUM_URL; ?>/admin/monthly_close.php"><strong>리포트</strong><span>월말 마감</span></a>
                        <a class="detail-command-link" id="detailVehicleLink" href="<?php echo IEUM_URL; ?>/admin/vehicle_assignments.php"><strong>차량</strong><span>배정/메모</span></a>
                    </div>
                </div>
                <div class="detail-flow-grid">
                    <div class="detail-section">
                        <h3>현재 확인 정보</h3>
                        <p id="detailProfileStatus" class="detail-empty">확인 정보를 불러올 수 없습니다.</p>
                    </div>
                    <div class="detail-section">
                        <h3>문자 발송 안내</h3>
                        <p id="detailSmsFlow" class="detail-empty">문자 발송 정보를 불러올 수 없습니다.</p>
                        <a class="detail-mini-link" id="detailContactsLink" href="<?php echo IEUM_URL; ?>/admin/contacts.php">알림 담당자 설정</a>
                    </div>
                </div>
                <div class="detail-flow-grid">
                    <div class="detail-section">
                        <h3>최근 문자 발송</h3>
                        <p id="detailRecentSms" class="detail-empty">최근 문자 발송 없음</p>
                    </div>
                    <div class="detail-section">
                        <h3>최근 처리 내역</h3>
                        <p id="detailRecentActivity" class="detail-empty">최근 처리 내역 없음</p>
                    </div>
                </div>
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
                    <span id="studentEditSub">저장 후 창을 닫으면 목록이 유지됩니다.</span>
                </div>
                <button type="button" class="edit-close" id="studentEditClose" aria-label="닫기">×</button>
            </div>
            <iframe class="edit-frame" id="studentEditFrame" title="원생 정보 수정"></iframe>
        </section>
        <div class="care-sms-backdrop" id="careSmsBackdrop" hidden></div>
        <section class="care-sms-modal" id="careSmsModal" aria-hidden="true" aria-labelledby="careSmsTitle" role="dialog" hidden>
            <div class="care-sms-head">
                <div>
                    <h2 id="careSmsTitle">문자 내용 확인</h2>
                    <p>보호자에게 보낼 문구를 확인하고, 필요한 부분만 고친 뒤 문자 발송을 진행합니다.</p>
                </div>
                <button type="button" class="care-sms-close" id="careSmsClose" aria-label="닫기">×</button>
            </div>
            <div class="care-sms-body">
                <label for="careSmsText">발송 문구</label>
                <textarea id="careSmsText" maxlength="500"></textarea>
                <p class="care-sms-help">수정한 문구는 다음에도 사용할 수 있게 저장됩니다. 원생 이름은 자동으로 바뀌어 적용됩니다.</p>
            </div>
            <div class="care-sms-actions">
                <button type="button" class="btn muted" id="careSmsCancel">취소</button>
                <button type="button" class="btn primary" id="careSmsSubmit">문자 발송</button>
            </div>
        </section>
    </section>
    </section>
    <?php } ?>
</main>
<?php
$student_shortcut_js_catalog = array();
foreach ($student_shortcut_catalog as $shortcut_key => $shortcut_item) {
    $student_shortcut_js_catalog[$shortcut_key] = array(
        'label' => isset($shortcut_item['label']) ? $shortcut_item['label'] : $shortcut_key,
        'desc' => isset($shortcut_item['desc']) ? $shortcut_item['desc'] : '',
        'url' => isset($shortcut_item['url']) ? $shortcut_item['url'] : '#',
    );
}
?>
<script>
(function() {
    var shortcutCatalog = <?php echo json_encode($student_shortcut_js_catalog, JSON_UNESCAPED_UNICODE); ?>;
    var currentShortcutKeys = <?php echo json_encode(array_values($student_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var defaultShortcutKeys = <?php echo json_encode(array_values($student_default_shortcut_keys), JSON_UNESCAPED_UNICODE); ?>;
    var shortcutMax = 6;
    var shortcutSaveUrl = <?php echo json_encode(IEUM_URL . '/dashboard.php', JSON_UNESCAPED_UNICODE); ?>;
    var shortcutCsrfToken = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
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
    document.querySelectorAll('.student-page-tune.ieum-dashboard-page .side-sub a').forEach(function(link) {
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

const tuitionPlans = <?php
$plan_js = array();
foreach ($tuition_plan_options as $plan) {
    if (!isset($plan_js[$plan['week_type']])) {
        $plan_js[$plan['week_type']] = array(
            'monthly_fee' => (int) $plan['monthly_fee'],
            'sibling_discount_amount' => (int) $plan['sibling_discount_amount'],
            'default_due_day' => (int) (isset($plan['default_due_day']) ? $plan['default_due_day'] : 5),
        );
    }
}
echo json_encode($plan_js);
?>;
const studentCodeIndex = <?php echo json_encode(isset($student_code_index) ? $student_code_index : array(), JSON_UNESCAPED_UNICODE); ?>;
const editingStudentId = <?php echo isset($form['student_id']) ? (int) $form['student_id'] : 0; ?>;
const addGuardian = document.getElementById('addGuardian');
const guardianList = document.getElementById('guardianList');
const studentCodeInput = document.getElementById('student_code');
const studentCodeDuplicateAlert = document.getElementById('studentCodeDuplicateAlert');
const studentPhoneInput = document.getElementById('student_phone');
const useStudentPhoneCode = document.getElementById('useStudentPhoneCode');
const weekTypeInput = document.getElementById('attendance_week_type');
const tuitionWeekType = document.getElementById('tuition_week_type');
const tuitionAmount = document.getElementById('tuition_amount');
const siblingDiscountEnabled = document.getElementById('sibling_discount_enabled');
const siblingDiscountAmount = document.getElementById('sibling_discount_amount');
const tuitionDueDay = document.getElementById('tuition_due_day');
const tuitionTotal = document.getElementById('tuition_total');
const birthDateInput = document.getElementById('birth_date');
const birthYearInput = document.getElementById('birth_year');
const birthMonthInput = document.getElementById('birth_month');
const birthDayInput = document.getElementById('birth_day');
const gradeGroupInput = document.getElementById('grade_group');
const admissionDate = document.getElementById('admission_date');
const admissionYear = document.getElementById('admission_year');
const admissionMonth = document.getElementById('admission_month');
const admissionDay = document.getElementById('admission_day');
const presetButtons = document.querySelectorAll('.preset-btn');
const weekdayCards = document.querySelectorAll('.weekday-card');
const weekdayInputs = document.querySelectorAll('#weekdayCards input[type="checkbox"]');
const rideDayCards = document.querySelectorAll('.ride-day-card');
function digitsOnly(value) {
    return (value || '').replace(/\D/g, '');
}
function formatKoreanPhone(value) {
    const digits = digitsOnly(value).slice(0, 11);
    if (digits.length <= 2) return digits;
    if (digits.startsWith('02')) {
        if (digits.length <= 5) return digits.slice(0, 2) + '-' + digits.slice(2);
        if (digits.length <= 9) return digits.slice(0, 2) + '-' + digits.slice(2, 5) + '-' + digits.slice(5);
        return digits.slice(0, 2) + '-' + digits.slice(2, 6) + '-' + digits.slice(6);
    }
    if (digits.length <= 3) return digits;
    if (digits.length <= 7) return digits.slice(0, 3) + '-' + digits.slice(3);
    return digits.slice(0, 3) + '-' + digits.slice(3, 7) + '-' + digits.slice(7);
}
function bindPhoneFormatter(input) {
    if (!input || input.dataset.phoneBound === '1') return;
    input.dataset.phoneBound = '1';
    input.setAttribute('inputmode', 'numeric');
    input.value = formatKoreanPhone(input.value);
    input.addEventListener('keydown', (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey) return;
        const allowedKeys = [
            'Backspace', 'Delete', 'Tab', 'Enter', 'Escape',
            'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
            'Home', 'End',
        ];
        if (allowedKeys.includes(event.key)) return;
        if (event.key.length === 1 && !/[0-9]/.test(event.key)) {
            event.preventDefault();
        }
    });
    input.addEventListener('paste', (event) => {
        event.preventDefault();
        const text = (event.clipboardData || window.clipboardData).getData('text') || '';
        input.value = formatKoreanPhone(text);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    input.addEventListener('input', () => {
        input.value = formatKoreanPhone(input.value);
    });
}
function bindDigitsOnlyInput(input, maxLength) {
    if (!input || input.dataset.digitsBound === '1') return;
    input.dataset.digitsBound = '1';
    input.setAttribute('inputmode', 'numeric');
    input.value = digitsOnly(input.value).slice(0, maxLength || 20);
    input.addEventListener('keydown', (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey) return;
        const allowedKeys = [
            'Backspace', 'Delete', 'Tab', 'Enter', 'Escape',
            'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
            'Home', 'End',
        ];
        if (allowedKeys.includes(event.key)) return;
        if (event.key.length === 1 && !/[0-9]/.test(event.key)) {
            event.preventDefault();
        }
    });
    input.addEventListener('paste', (event) => {
        event.preventDefault();
        const text = (event.clipboardData || window.clipboardData).getData('text') || '';
        input.value = digitsOnly(text).slice(0, maxLength || 20);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    input.addEventListener('input', () => {
        input.value = digitsOnly(input.value).slice(0, maxLength || 20);
    });
}
function syncWeekdayCards() {
    weekdayCards.forEach((card) => {
        const input = card.querySelector('input[type="checkbox"]');
        card.classList.toggle('selected', !!input && input.checked);
    });
}
function syncRideDayCards() {
    rideDayCards.forEach((card) => {
        const input = card.querySelector('input[type="checkbox"]');
        card.classList.toggle('selected', !!input && input.checked);
    });
}
function formatMoney(value) {
    return String(Math.max(0, Number(value) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '원';
}
function updateAdmissionDate() {
    if (!admissionDate || !admissionYear || !admissionMonth || !admissionDay) return;
    const y = admissionYear.value;
    const m = String(admissionMonth.value).padStart(2, '0');
    const d = String(admissionDay.value).padStart(2, '0');
    admissionDate.value = `${y}-${m}-${d}`;
}
function updateBirthDate() {
    if (!birthDateInput || !birthYearInput || !birthMonthInput || !birthDayInput) return;
    if (!birthYearInput.value || !birthMonthInput.value || !birthDayInput.value) {
        birthDateInput.value = '';
        return;
    }
    const y = birthYearInput.value;
    const m = String(birthMonthInput.value).padStart(2, '0');
    const d = String(birthDayInput.value).padStart(2, '0');
    birthDateInput.value = `${y}-${m}-${d}`;
}
function normalizeBirthDayOptions() {
    if (!birthYearInput || !birthMonthInput || !birthDayInput || !birthYearInput.value || !birthMonthInput.value) return;
    const maxDay = new Date(Number(birthYearInput.value), Number(birthMonthInput.value), 0).getDate();
    Array.from(birthDayInput.options).forEach((option) => {
        if (!option.value) return;
        option.disabled = Number(option.value) > maxDay;
    });
    if (birthDayInput.value && Number(birthDayInput.value) > maxDay) {
        birthDayInput.value = String(maxDay);
    }
}
function gradeFromBirthDate(value) {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return '';
    const now = new Date();
    let schoolYear = now.getFullYear();
    if (now.getMonth() + 1 < 3) schoolYear -= 1;
    const birthYear = Number(value.slice(0, 4));
    const gradeNumber = schoolYear - birthYear - 6;
    if (gradeNumber < 1) return 'kindergarten';
    if (gradeNumber <= 6) return `elementary_${gradeNumber}`;
    if (gradeNumber <= 9) return `middle_${gradeNumber - 6}`;
    if (gradeNumber <= 12) return `high_${gradeNumber - 9}`;
    return '';
}
function applyBirthGrade() {
    if (!birthDateInput || !gradeGroupInput) return;
    normalizeBirthDayOptions();
    updateBirthDate();
    const grade = gradeFromBirthDate(birthDateInput.value);
    if (grade) gradeGroupInput.value = grade;
}
function updateTuitionTotal() {
    if (!tuitionTotal) return;
    const amount = Number(tuitionAmount ? tuitionAmount.value : 0) || 0;
    const discount = siblingDiscountEnabled && siblingDiscountEnabled.checked ? (Number(siblingDiscountAmount ? siblingDiscountAmount.value : 0) || 0) : 0;
    const total = Math.max(0, amount - discount);
    tuitionTotal.textContent = `청구 예상 ${formatMoney(total)}`;
}
function applyTuitionPlan(type, force) {
    if (tuitionWeekType && tuitionWeekType.value !== type) {
        tuitionWeekType.value = type;
    }
    const plan = tuitionPlans[type];
    if (plan && tuitionAmount && (force || !tuitionAmount.value || tuitionAmount.value === '0')) {
        tuitionAmount.value = plan.monthly_fee;
    }
    if (plan && siblingDiscountAmount && siblingDiscountEnabled && siblingDiscountEnabled.checked && (force || !siblingDiscountAmount.value || siblingDiscountAmount.value === '0')) {
        siblingDiscountAmount.value = plan.sibling_discount_amount;
    }
    if (plan && tuitionDueDay && force) {
        tuitionDueDay.value = plan.default_due_day || 5;
    }
    updateTuitionTotal();
}
function setWeekType(type) {
    if (!weekTypeInput) return;
    weekTypeInput.value = type;
    presetButtons.forEach((button) => button.classList.toggle('active', button.dataset.weekType === type));
    if (type === '5') {
        weekdayInputs.forEach((input) => { input.checked = true; });
    } else if (type === '2' || type === '3' || type === '4') {
        weekdayInputs.forEach((input) => { input.checked = false; });
    }
    syncWeekdayCards();
    applyTuitionPlan(type, true);
}
presetButtons.forEach((button) => {
    button.addEventListener('click', () => setWeekType(button.dataset.weekType));
});
weekdayCards.forEach((card) => {
    card.addEventListener('click', () => {
        window.setTimeout(() => {
            if (weekTypeInput) {
                const type = weekTypeInput.value;
                if (type === '5') {
                    weekdayInputs.forEach((input) => { input.checked = true; });
                } else if (type === '2' || type === '3' || type === '4') {
                    const max = Number(type);
                    const checked = Array.from(weekdayInputs).filter((input) => input.checked);
                    if (checked.length > max) {
                        const input = card.querySelector('input[type="checkbox"]');
                        if (input) input.checked = false;
                    }
                }
            }
            syncWeekdayCards();
        }, 0);
    });
});
syncWeekdayCards();
rideDayCards.forEach((card) => {
    card.addEventListener('click', () => {
        window.setTimeout(syncRideDayCards, 0);
    });
});
syncRideDayCards();
if (admissionYear) admissionYear.addEventListener('change', updateAdmissionDate);
if (admissionMonth) admissionMonth.addEventListener('change', updateAdmissionDate);
if (admissionDay) admissionDay.addEventListener('change', updateAdmissionDate);
updateAdmissionDate();
if (tuitionWeekType) {
    tuitionWeekType.addEventListener('change', () => {
        applyTuitionPlan(tuitionWeekType.value, true);
    });
}
if (tuitionAmount) tuitionAmount.addEventListener('input', updateTuitionTotal);
if (siblingDiscountAmount) siblingDiscountAmount.addEventListener('input', updateTuitionTotal);
if (siblingDiscountEnabled) {
    siblingDiscountEnabled.addEventListener('change', () => {
        if (siblingDiscountEnabled.checked) {
            const plan = tuitionPlans[tuitionWeekType ? tuitionWeekType.value : ''];
            if (plan && siblingDiscountAmount && (!siblingDiscountAmount.value || siblingDiscountAmount.value === '0')) {
                siblingDiscountAmount.value = plan.sibling_discount_amount;
            }
        }
        updateTuitionTotal();
    });
}
updateTuitionTotal();
function renumberGuardians() {
    if (!guardianList) return;
    guardianList.querySelectorAll('.guardian-row').forEach((row, index) => {
        row.querySelectorAll('input[type="checkbox"]').forEach((input) => {
            input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
        });
    });
}
function setStudentCodeAutoSource(source) {
    if (!studentCodeInput) return;
    studentCodeInput.dataset.autoSource = source || '';
}
function canAutoUpdateStudentCode(source) {
    if (!studentCodeInput) return false;
    return studentCodeInput.value === '' || studentCodeInput.dataset.autoSource === source;
}
function applyStudentCodeFromDigits(digits, source) {
    if (!studentCodeInput || digits.length < 4 || !canAutoUpdateStudentCode(source)) return;
    studentCodeInput.value = digits.slice(-4);
    setStudentCodeAutoSource(source);
    updateStudentCodeDuplicateAlert();
}
function applyStudentCodeFromRow(row) {
    const phone = row.querySelector('input[name="guardian_phone[]"]');
    const digits = digitsOnly(phone ? phone.value : '');
    applyStudentCodeFromDigits(digits, 'guardian');
}
function applyStudentCodeFromStudentPhone() {
    const digits = digitsOnly(studentPhoneInput ? studentPhoneInput.value : '');
    applyStudentCodeFromDigits(digits, 'student');
}
function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}
function updateStudentCodeDuplicateAlert() {
    if (!studentCodeInput || !studentCodeDuplicateAlert) return;
    const code = studentCodeInput.value.trim();
    const rows = (studentCodeIndex[code] || []).filter((row) => Number(row.student_id) !== Number(editingStudentId));
    if (!code || rows.length === 0) {
        studentCodeDuplicateAlert.className = 'duplicate-alert';
        studentCodeDuplicateAlert.innerHTML = '';
        return;
    }
    const items = rows.slice(0, 5).map((row) => {
        const birth = row.birth_date && row.birth_date !== '0000-00-00' ? row.birth_date : '생년월일 미입력';
        const status = row.status_label || (Number(row.active) === 1 ? '재원' : '퇴관');
        return `<li>${escapeHtml(row.name)} · ${escapeHtml(birth)} · ${escapeHtml(row.grade)} · ${status}</li>`;
    }).join('');
    studentCodeDuplicateAlert.className = 'duplicate-alert show';
    studentCodeDuplicateAlert.innerHTML = `<strong>같은 원생번호를 사용하는 원생이 있습니다.</strong><div>저장은 가능하며, 출석기에서는 이름과 생년월일로 원생을 선택하게 됩니다.</div><ul>${items}</ul>`;
}
function bindGuardianRow(row) {
    const remove = row.querySelector('.remove-guardian');
    const useCode = row.querySelector('.use-code');
    const primary = row.querySelector('.primary-guardian');
    const guardianPhoneInput = row.querySelector('input[name="guardian_phone[]"]');
    bindPhoneFormatter(guardianPhoneInput);
    if (guardianPhoneInput) {
        guardianPhoneInput.addEventListener('input', () => {
            if (useCode && useCode.checked) {
                applyStudentCodeFromRow(row);
            }
        });
        if (useCode && useCode.checked) {
            applyStudentCodeFromRow(row);
        }
    }
    if (remove) {
        remove.addEventListener('click', () => {
            if (!guardianList || guardianList.querySelectorAll('.guardian-row').length <= 1) return;
            row.remove();
            renumberGuardians();
        });
    }
    if (useCode) {
        useCode.addEventListener('change', () => {
            if (useCode.checked && guardianList) {
                guardianList.querySelectorAll('.use-code').forEach((item) => {
                    if (item !== useCode) item.checked = false;
                });
                applyStudentCodeFromRow(row);
            }
        });
    }
    if (primary) {
        primary.addEventListener('change', () => {
            if (primary.checked && guardianList) {
                guardianList.querySelectorAll('.primary-guardian').forEach((item) => {
                    if (item !== primary) item.checked = false;
                });
            }
        });
    }
}
if (guardianList) {
    guardianList.querySelectorAll('.guardian-row').forEach(bindGuardianRow);
}
if (studentCodeInput) {
    bindDigitsOnlyInput(studentCodeInput, 8);
    studentCodeInput.addEventListener('input', () => {
        if (document.activeElement === studentCodeInput) {
            setStudentCodeAutoSource('');
        }
        updateStudentCodeDuplicateAlert();
    });
    updateStudentCodeDuplicateAlert();
}
if (addGuardian) {
    addGuardian.addEventListener('click', () => {
        const list = guardianList;
        const index = list.querySelectorAll('.guardian-row').length;
        const row = document.createElement('div');
        row.className = 'guardian-row';
        row.innerHTML = `
            <div class="guardian-fields">
                <input type="text" name="guardian_name[]" maxlength="50" placeholder="보호자명">
                <input type="text" name="guardian_relation[]" maxlength="30" placeholder="관계">
                <input type="text" name="guardian_phone[]" maxlength="13" inputmode="numeric" placeholder="010-0000-0000">
            </div>
            <div class="guardian-groups">
                <div>
                    <div class="guardian-section-title">문자 수신</div>
                    <div class="guardian-flags">
                        <label class="guardian-flag"><input type="checkbox" name="guardian_sms_attendance[${index}]" value="1" checked> 등원</label>
                        <label class="guardian-flag"><input type="checkbox" name="guardian_sms_checkout[${index}]" value="1"> 하원</label>
                        <label class="guardian-flag"><input type="checkbox" name="guardian_sms_tuition[${index}]" value="1" checked> 수련비</label>
                    </div>
                </div>
                <div>
                    <div class="guardian-section-title">원생번호 선택 (연락처 뒷자리)</div>
                    <div class="guardian-main">
                        <label class="guardian-flag"><input type="checkbox" class="use-code" name="guardian_use_code[${index}]" value="1"> 원생번호로 사용</label>
                        <label class="guardian-flag"><input type="checkbox" class="primary-guardian" name="guardian_primary[${index}]" value="1"> 대표</label>
                        <button type="button" class="btn muted remove-guardian">삭제</button>
                    </div>
                </div>
            </div>
        `;
        list.appendChild(row);
        bindGuardianRow(row);
    });
}
bindPhoneFormatter(studentPhoneInput);
if (useStudentPhoneCode) {
    useStudentPhoneCode.addEventListener('click', () => {
        setStudentCodeAutoSource('student');
        applyStudentCodeFromStudentPhone();
    });
}
if (studentPhoneInput) {
    studentPhoneInput.addEventListener('input', () => {
        if (document.activeElement === studentPhoneInput) {
            applyStudentCodeFromStudentPhone();
        }
    });
}
function syncVehicleFilter(filter) {
    const target = document.getElementById(filter.dataset.target);
    if (!target) return;
    const selected = target.options[target.selectedIndex];
    if (selected && selected.dataset.vehicle) {
        filter.value = selected.dataset.vehicle;
    }
    applyVehicleFilter(filter);
}
function applyVehicleFilter(filter) {
    const target = document.getElementById(filter.dataset.target);
    if (!target) return;
    const vehicle = filter.value;
    Array.from(target.options).forEach((option) => {
        if (!option.value) {
            option.hidden = false;
            option.disabled = false;
            return;
        }
        const matched = !vehicle || option.dataset.vehicle === vehicle;
        option.hidden = !matched;
        option.disabled = !matched;
    });
    const current = target.options[target.selectedIndex];
    if (current && current.disabled) {
        target.value = '0';
    }
}
function fillVehiclePlaceFromStop(select) {
    const map = {
        vehicle_pickup_stop_id: 'vehicle_pickup_place',
        vehicle_dropoff_stop_id: 'vehicle_dropoff_place',
    };
    const target = document.getElementById(map[select.id] || '');
    if (!target) return;
    const selected = select.options[select.selectedIndex];
    if (selected && selected.value && selected.dataset.stopName && !target.value.trim()) {
        target.value = selected.dataset.stopName;
    }
}
document.querySelectorAll('.vehicle-filter').forEach((filter) => {
    syncVehicleFilter(filter);
    filter.addEventListener('change', () => applyVehicleFilter(filter));
});
document.querySelectorAll('#vehicle_pickup_stop_id,#vehicle_dropoff_stop_id').forEach((select) => {
    select.addEventListener('change', () => fillVehiclePlaceFromStop(select));
});
const copyPickupToDropoff = document.getElementById('copyPickupToDropoff');
if (copyPickupToDropoff) {
    copyPickupToDropoff.addEventListener('click', () => {
        const pickupEnabled = document.getElementById('vehicle_pickup_enabled');
        const dropoffEnabled = document.getElementById('vehicle_dropoff_enabled');
        const pickupVehicle = document.getElementById('vehicle_pickup_vehicle');
        const dropoffVehicle = document.getElementById('vehicle_dropoff_vehicle');
        const pickupContact = document.getElementById('vehicle_pickup_contact_phone');
        const dropoffContact = document.getElementById('vehicle_dropoff_contact_phone');
        const pickupMemo = document.getElementById('vehicle_pickup_memo');
        const dropoffMemo = document.getElementById('vehicle_dropoff_memo');
        if (pickupEnabled && dropoffEnabled) dropoffEnabled.checked = pickupEnabled.checked;
        if (pickupVehicle && dropoffVehicle) {
            dropoffVehicle.value = pickupVehicle.value;
            applyVehicleFilter(dropoffVehicle);
        }
        if (pickupContact && dropoffContact && !dropoffContact.value.trim()) dropoffContact.value = pickupContact.value;
        if (pickupMemo && dropoffMemo && !dropoffMemo.value.trim()) dropoffMemo.value = pickupMemo.value;
        const pickupDays = document.querySelectorAll('input[name="vehicle_pickup_days[]"]');
        const dropoffDays = document.querySelectorAll('input[name="vehicle_dropoff_days[]"]');
        pickupDays.forEach((input, index) => {
            if (dropoffDays[index]) dropoffDays[index].checked = input.checked;
        });
        syncRideDayCards();
    });
}
const syncVehicleDays = document.getElementById('syncVehicleDays');
if (syncVehicleDays) {
    syncVehicleDays.addEventListener('click', () => {
        const attendanceChecked = Array.from(document.querySelectorAll('#weekdayCards input[type="checkbox"]')).filter((input) => input.checked).map((input) => input.value);
        ['vehicle_pickup_days[]', 'vehicle_dropoff_days[]'].forEach((name) => {
            document.querySelectorAll(`input[name="${name}"]`).forEach((input) => {
                input.checked = attendanceChecked.includes(input.value);
            });
        });
        syncRideDayCards();
    });
}
document.querySelectorAll('.vehicle-contact-select').forEach((select) => {
    select.addEventListener('change', () => {
        const target = document.getElementById(select.dataset.target);
        if (target && select.value) {
            target.value = formatKoreanPhone(select.value);
        }
    });
});
if (birthDateInput) {
    [birthYearInput, birthMonthInput, birthDayInput].forEach((input) => {
        if (input) input.addEventListener('change', applyBirthGrade);
    });
    applyBirthGrade();
}
bindPhoneFormatter(document.getElementById('student_phone'));
bindPhoneFormatter(document.getElementById('vehicle_pickup_contact_phone'));
bindPhoneFormatter(document.getElementById('vehicle_dropoff_contact_phone'));
function setDetailText(id, value, emptyText) {
    const el = document.getElementById(id);
    if (!el) return;
    const text = value && String(value).trim() ? String(value).trim() : (emptyText || '-');
    el.textContent = text;
    el.classList.toggle('detail-empty', !value || !String(value).trim());
}
function renderDetailSignals(signals) {
    const box = document.getElementById('detailSignals');
    if (!box) return;
    box.textContent = '';
    if (!Array.isArray(signals) || signals.length === 0) {
        const empty = document.createElement('span');
        empty.className = 'muted-text';
        empty.textContent = '특이 신호 없음';
        box.appendChild(empty);
        return;
    }
    signals.forEach((signal) => {
        const badge = document.createElement('span');
        badge.className = 'student-badge ' + (signal.tone || '');
        badge.textContent = signal.label || '-';
        if (signal.action) {
            badge.title = signal.action;
        }
        box.appendChild(badge);
    });
}
function setDetailLink(id, url) {
    const el = document.getElementById(id);
    if (!el || !url) return;
    el.href = url;
}
function openStudentDetail(detail) {
    const backdrop = document.getElementById('studentDetailBackdrop');
    const modal = document.getElementById('studentDetailModal');
    if (!backdrop || !modal || !detail) return;
    setDetailText('studentDetailTitle', (detail.name || '원생') + ' 원생 프로필');
    setDetailText('studentDetailCode', '원생번호 ' + (detail.code || '-'));
    setDetailText('detailStatus', detail.status);
    setDetailText('detailProgram', detail.program);
    setDetailText('detailGrade', detail.grade);
    setDetailText('detailClassTime', detail.classTime);
    setDetailText('detailAttendanceDays', detail.attendanceDays);
    setDetailText('detailLastAttendance', detail.lastAttendance);
    setDetailText('detailStudentPhone', detail.studentPhone);
    setDetailText('detailTuition', detail.tuition);
    setDetailText('detailAdmissionDate', detail.admissionDate);
    setDetailText('detailPromotionCurrent', detail.promotionCurrent);
    setDetailText('detailPromotionNext', detail.promotionNext);
    setDetailText('detailPromotionNextDate', detail.promotionNextDate);
    setDetailText('detailGuardian', detail.guardian, '등록 없음');
    setDetailText('detailVehicle', detail.vehicle, '이용 없음');
    setDetailText('detailMemo', detail.memo, '메모 없음');
    setDetailText('detailCounselingNote', detail.counselingNote, '상담 메모 없음');
    renderDetailSignals(detail.signals || []);
    setDetailText('detailCarePlan', detail.carePlan, '오늘 특별히 처리할 신호는 없습니다.');
    setDetailText('detailProfileStatus', detail.profileStatus, '확인 정보를 불러올 수 없습니다.');
    setDetailText('detailSmsFlow', detail.smsFlow, '문자 발송 정보를 불러올 수 없습니다.');
    setDetailText('detailRecentSms', detail.recentSms, '최근 문자 발송 없음');
    setDetailText('detailRecentActivity', detail.recentActivity, '최근 처리 내역 없음');
    const contactsLink = document.getElementById('detailContactsLink');
    if (contactsLink && detail.contactsUrl) {
        contactsLink.href = detail.contactsUrl;
    }
    setDetailLink('detailAttendanceLink', detail.attendanceUrl);
    setDetailLink('detailTuitionLink', detail.tuitionUrl);
    setDetailLink('detailSmsLink', detail.smsUrl);
    setDetailLink('detailReportLink', detail.reportUrl);
    setDetailLink('detailVehicleLink', detail.vehicleUrl);
    modal.dataset.editUrl = detail.editUrl || '#';
    modal.dataset.studentName = detail.name || '원생';
    document.querySelectorAll('.student-detail-edit-action').forEach((edit) => {
        edit.href = detail.editUrl || '#';
        edit.dataset.editUrl = detail.editUrl || '#';
        edit.dataset.studentName = detail.name || '원생';
    });
    backdrop.hidden = false;
    modal.hidden = false;
    requestAnimationFrame(() => {
        backdrop.classList.add('open');
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    });
}
window.ieumOpenStudentDetailFromButton = function (button) {
    if (!button) return true;
    try {
        openStudentDetail(JSON.parse(button.dataset.detail || '{}'));
    } catch (error) {
        console.error(error);
    }
    return false;
};
function closeStudentDetail() {
    const backdrop = document.getElementById('studentDetailBackdrop');
    const modal = document.getElementById('studentDetailModal');
    if (!backdrop || !modal) return;
    backdrop.classList.remove('open');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    window.setTimeout(() => {
        if (!backdrop.classList.contains('open')) backdrop.hidden = true;
        if (!modal.classList.contains('open')) modal.hidden = true;
    }, 180);
}
document.addEventListener('click', (event) => {
    const editLink = event.target.closest('.student-edit-modal-open');
    if (editLink && !document.body.classList.contains('embed-page')) {
        event.preventDefault();
        const detailModal = document.getElementById('studentDetailModal');
        const editUrl = editLink.dataset.editUrl || (detailModal ? detailModal.dataset.editUrl : '') || editLink.getAttribute('href') || '';
        const studentName = editLink.dataset.studentName || (detailModal ? detailModal.dataset.studentName : '') || '';
        openStudentEdit(editUrl, studentName);
        return;
    }
    const openButton = event.target.closest('.student-detail-open');
    if (openButton) {
        event.preventDefault();
        try {
            openStudentDetail(JSON.parse(openButton.dataset.detail || '{}'));
        } catch (error) {
            console.error(error);
        }
        return;
    }
    if (event.target.closest('#studentDetailClose, #studentDetailCloseBottom') || event.target.id === 'studentDetailBackdrop') {
        event.preventDefault();
        closeStudentDetail();
    }
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeStudentEdit();
        closeStudentDetail();
    }
});
let studentEditDirty = false;
function openStudentEdit(url, studentName) {
    closeStudentDetail();
    const backdrop = document.getElementById('studentEditBackdrop');
    const modal = document.getElementById('studentEditModal');
    const frame = document.getElementById('studentEditFrame');
    const title = document.getElementById('studentEditTitle');
    if (!backdrop || !modal || !frame) return;
    const target = new URL(url, window.location.href);
    target.searchParams.set('embed', '1');
    if (title) title.textContent = (studentName || '원생') + ' 정보 수정';
    studentEditDirty = false;
    frame.src = target.toString();
    backdrop.hidden = false;
    modal.hidden = false;
    requestAnimationFrame(() => {
        backdrop.classList.add('open');
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    });
    frame.onload = function () {
        try {
            const doc = frame.contentDocument;
            if (doc && doc.querySelector('.notice.ok')) studentEditDirty = true;
        } catch (error) {}
    };
}
window.ieumOpenStudentEditFromLink = function (link) {
    if (!link || document.body.classList.contains('embed-page')) return true;
    const detailModal = document.getElementById('studentDetailModal');
    const editUrl = link.dataset.editUrl || (detailModal ? detailModal.dataset.editUrl : '') || link.getAttribute('href') || '';
    const studentName = link.dataset.studentName || (detailModal ? detailModal.dataset.studentName : '') || '';
    openStudentEdit(editUrl, studentName);
    return false;
};
function closeStudentEdit() {
    const backdrop = document.getElementById('studentEditBackdrop');
    const modal = document.getElementById('studentEditModal');
    const frame = document.getElementById('studentEditFrame');
    if (!backdrop || !modal) return;
    backdrop.classList.remove('open');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    window.setTimeout(() => {
        if (!backdrop.classList.contains('open')) backdrop.hidden = true;
        if (!modal.classList.contains('open')) modal.hidden = true;
        if (frame) frame.src = 'about:blank';
        if (studentEditDirty) window.location.reload();
    }, 180);
}
document.addEventListener('click', (event) => {
    if (event.target.closest('#studentEditClose') || event.target.id === 'studentEditBackdrop') {
        event.preventDefault();
        closeStudentEdit();
    }
});
function initStudentAjaxList() {
    const main = document.querySelector('main.wrap');
    if (!main || main.dataset.studentAjaxBound === '1') return;
    main.dataset.studentAjaxBound = '1';

    const sameStudentListUrl = (url) => {
        const target = new URL(url, window.location.href);
        const current = new URL(window.location.href);
        return target.origin === current.origin
            && target.pathname === current.pathname
            && target.searchParams.get('mode') !== 'form';
    };
    const setLoading = (loading) => {
        const currentMain = document.querySelector('main.wrap');
        const status = document.getElementById('studentAjaxStatus');
        if (currentMain) currentMain.classList.toggle('ajax-loading', loading);
        if (status) status.classList.toggle('show', loading);
    };
    const bindDynamicControls = () => {
        const searchForm = document.querySelector('form.search');
        if (searchForm && searchForm.dataset.ajaxBound !== '1') {
            searchForm.dataset.ajaxBound = '1';
            searchForm.addEventListener('submit', (event) => {
                event.preventDefault();
                const url = new URL(searchForm.action || window.location.href, window.location.href);
                const formData = new FormData(searchForm);
                Array.from(url.searchParams.keys()).forEach((key) => url.searchParams.delete(key));
                formData.forEach((value, key) => {
                    if (String(value) !== '') url.searchParams.set(key, value);
                });
                loadStudentList(url.toString(), true);
            });
            searchForm.querySelectorAll('select').forEach((select) => {
                select.addEventListener('change', () => searchForm.requestSubmit());
            });
        }
    };
    const loadStudentList = async (url, pushState) => {
        if (!sameStudentListUrl(url)) {
            window.location.href = url;
            return;
        }
        try {
            setLoading(true);
            const response = await fetch(url, {
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextMain = doc.querySelector('main.wrap');
            const currentMain = document.querySelector('main.wrap');
            if (!nextMain || !currentMain) throw new Error('목록 영역을 찾을 수 없습니다.');
            currentMain.innerHTML = nextMain.innerHTML;
            document.title = doc.title || document.title;
            if (pushState) {
                window.history.pushState({studentAjax: true}, '', url);
            }
            bindDynamicControls();
        } catch (error) {
            console.error(error);
            window.location.href = url;
        } finally {
            setLoading(false);
        }
    };

    main.addEventListener('click', (event) => {
        const link = event.target.closest('a');
        if (!link) return;
        if (!link.classList.contains('chip') && !link.classList.contains('muted') && !link.classList.contains('side-link')) return;
        if (!sameStudentListUrl(link.href)) return;
        event.preventDefault();
        loadStudentList(link.href, true);
    });
    window.addEventListener('popstate', () => {
        loadStudentList(window.location.href, false);
    });
    bindDynamicControls();
}
initStudentAjaxList();

(function initQuickCareSmsModal() {
    let activeForm = null;
    const modal = () => document.getElementById('careSmsModal');
    const backdrop = () => document.getElementById('careSmsBackdrop');
    const textarea = () => document.getElementById('careSmsText');
    const title = () => document.getElementById('careSmsTitle');

    const openModal = (form) => {
        const currentModal = modal();
        const currentBackdrop = backdrop();
        const currentTextarea = textarea();
        const currentTitle = title();
        if (!currentModal || !currentBackdrop || !currentTextarea || !currentTitle) return false;
        activeForm = form;
        const input = form.querySelector('input[name="care_message"]');
        currentTitle.textContent = form.dataset.careTitle || '문자 내용 확인';
        currentTextarea.value = input && input.value ? input.value : (form.dataset.careDefault || '');
        currentBackdrop.hidden = false;
        currentModal.hidden = false;
        window.requestAnimationFrame(() => {
            currentBackdrop.classList.add('open');
            currentModal.classList.add('open');
            currentModal.setAttribute('aria-hidden', 'false');
            currentTextarea.focus();
        });
        return true;
    };

    const closeModal = () => {
        const currentModal = modal();
        const currentBackdrop = backdrop();
        if (!currentModal || !currentBackdrop) return;
        currentModal.classList.remove('open');
        currentBackdrop.classList.remove('open');
        currentModal.setAttribute('aria-hidden', 'true');
        window.setTimeout(() => {
            if (!currentModal.classList.contains('open')) currentModal.hidden = true;
            if (!currentBackdrop.classList.contains('open')) currentBackdrop.hidden = true;
        }, 170);
    };

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('.quick-care-sms-form');
        if (!form || form.dataset.careConfirmed === '1') return;
        event.preventDefault();
        openModal(form);
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('#careSmsClose') || event.target.closest('#careSmsCancel') || event.target === backdrop()) {
            activeForm = null;
            closeModal();
            return;
        }
        if (!event.target.closest('#careSmsSubmit')) return;
        if (!activeForm) {
            closeModal();
            return;
        }
        const input = activeForm.querySelector('input[name="care_message"]');
        if (input && textarea()) input.value = textarea().value.trim();
        activeForm.dataset.careConfirmed = '1';
        closeModal();
        activeForm.submit();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !modal() || modal().hidden) return;
        activeForm = null;
        closeModal();
    });
})();
</script>
<?php if (!$embed_mode) { ?>
<script src="<?php echo IEUM_URL; ?>/assets/students-list-modal.js?v=2026060302"></script>
<?php } ?>
</body>
</html>
