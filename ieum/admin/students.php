<?php
$sub_menu = '950120';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/sms_queue.php';

$g5['title'] = '아이이음 학생 관리';
$current_academy = ieum_require_academy_page();
$academy_id = (int) $current_academy['academy_id'];

$mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'list';
$student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
$message = '';
$error = '';

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

function ieum_student_care_sms_message($student_name, $message_type)
{
    $student_name = trim($student_name);
    if ($message_type === 'birthday') {
        return '[아이이음] 이번 달은 ' . $student_name . ' 학생의 생일이 있는 달입니다. 도장에서 따뜻하게 축하하고 챙기겠습니다.';
    }
    if ($message_type === 'long_absent') {
        return '[아이이음] ' . $student_name . ' 학생이 최근 수업에 보이지 않아 안부 확인차 연락드립니다. 편하실 때 도장으로 연락 부탁드립니다.';
    }

    return '[아이이음] ' . $student_name . ' 학생 관련 안내드립니다. 확인 후 도장으로 연락 부탁드립니다.';
}

function ieum_queue_student_care_sms($academy_id, $student_id, $message_type)
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

    $message = ieum_student_care_sms_message($student['student_name'], $message_type);
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
            $student_code = isset($_POST['student_code']) ? preg_replace('/[^0-9A-Za-z_-]/', '', trim($_POST['student_code'])) : '';
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
            foreach ($guardian_phones as $phone_value) {
                if (ieum_student_clean_phone($phone_value) !== '') {
                    $has_guardian_phone = true;
                    break;
                }
            }

            if ($student_code === '') {
                $error = '학생번호를 입력하세요.';
            } elseif ($student_name === '') {
                $error = '학생명을 입력하세요.';
            } elseif ($attendance_days === '') {
                $error = '출석 요일을 1개 이상 선택하세요.';
            } elseif (!$has_guardian_phone) {
                $error = '보호자 연락처를 1개 이상 입력하세요.';
            } else {
                $student_code_sql = sql_escape_string($student_code);
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
                    $vehicle_pickup_place_sql = sql_escape_string($vehicle_pickup_place);
                    $vehicle_dropoff_place_sql = sql_escape_string($vehicle_dropoff_place);
                    $memo_sql = sql_escape_string($memo);
                    $birth_date_set = $birth_date_sql === '' ? "birth_date = null" : "birth_date = '{$birth_date_sql}'";
                    $admission_set = $admission_date_sql === '' ? "admission_date = null" : "admission_date = '{$admission_date_sql}'";

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
                        ieum_log_student_status_change($academy_id, $saved_student_id, $before_status, $student_status, '학생 정보 수정');
                        $message = '학생 정보가 수정되었습니다.';
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
                                    vehicle_pickup_enabled = '{$vehicle_pickup_enabled}',
                                    vehicle_pickup_place = '{$vehicle_pickup_place_sql}',
                                    vehicle_dropoff_enabled = '{$vehicle_dropoff_enabled}',
                                    vehicle_dropoff_place = '{$vehicle_dropoff_place_sql}',
                                    memo = '{$memo_sql}',
                                    is_active = '{$is_active}',
                                    created_at = '" . G5_TIME_YMDHIS . "'
                        ");
                        $saved_student_id = sql_insert_id();
                        ieum_log_student_status_change($academy_id, $saved_student_id, '', $student_status, '학생 신규 등록');
                        $message = '학생이 등록되었습니다.';
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
                    $mode = 'list';
                    $student_id = 0;
            }
        } elseif ($action === 'toggle') {
            $target = ieum_fetch_student($post_student_id);
            if (!$target) {
                $error = '학생 정보를 찾을 수 없습니다.';
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
                ieum_log_student_status_change($academy_id, $post_student_id, $before_status, $next_status, $next_active ? '사용 상태 변경' : '사용중지 처리');
                $message = $next_active ? '학생을 사용 상태로 변경했습니다.' : '학생을 사용중지했습니다.';
            }
            $mode = 'list';
            $student_id = 0;
        } elseif ($action === 'quick_counseling_note') {
            $target = ieum_fetch_student($post_student_id);
            $quick_note = isset($_POST['quick_counseling_note']) ? trim($_POST['quick_counseling_note']) : '';
            if (!$target) {
                $error = '학생 정보를 찾을 수 없습니다.';
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
                $message = get_text($target['student_name']) . ' 학생 상담 메모를 저장했습니다.';
            }
            $mode = 'list';
            $student_id = 0;
        } elseif ($action === 'queue_care_sms') {
            $target = ieum_fetch_student($post_student_id);
            $care_message_type = isset($_POST['care_message_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['care_message_type'])) : 'student';
            if (!$target) {
                $error = '학생 정보를 찾을 수 없습니다.';
            } else {
                $created_count = ieum_queue_student_care_sms($academy_id, $post_student_id, $care_message_type);
                if ($created_count > 0) {
                    $message = get_text($target['student_name']) . ' 학생 보호자 문자 대기열을 ' . number_format($created_count) . '건 생성했습니다.';
                } else {
                    $error = get_text($target['student_name']) . ' 학생에게 문자를 받을 보호자 연락처가 없습니다.';
                }
            }
            $mode = 'list';
            $student_id = 0;
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$editing = null;
if ($mode === 'form' && $student_id) {
    $editing = ieum_fetch_student($student_id);
    if (!$editing) {
        $mode = 'list';
        $error = '학생 정보를 찾을 수 없습니다.';
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
$insight_options = array(
    '' => '전체 신호',
    'birthday_month' => '이번 달 생일자',
    'birthday_week' => '7일 이내 생일',
    'long_absent' => '장기 미등원',
);
if (!isset($insight_options[$filter_insight])) {
    $filter_insight = '';
}
$q_sql = sql_escape_string($q);

function ieum_student_list_url($overrides = array())
{
    global $q, $filter_program, $filter_grade, $filter_class_time_raw, $filter_insight;

    $params = array(
        'q' => $q,
        'program_code' => $filter_program,
        'grade_group' => $filter_grade,
        'class_time_id' => $filter_class_time_raw,
        'insight' => $filter_insight,
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
    if ($insight === 'birthday_month') {
        return " and {$alias}.is_active = 1 and {$alias}.birth_date is not null and {$alias}.birth_date <> '0000-00-00' and month({$alias}.birth_date) = month('{$today_sql}') ";
    }
    if ($insight === 'birthday_week') {
        return " and {$alias}.is_active = 1 and {$alias}.birth_date is not null and {$alias}.birth_date <> '0000-00-00' and str_to_date(concat(year('{$today_sql}'), date_format({$alias}.birth_date, '-%m-%d')), '%Y-%m-%d') between '{$today_sql}' and date_add('{$today_sql}', interval 7 day) ";
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

    return '';
}

ieum_refresh_student_auto_grades($academy_id);
$insight_where = ieum_student_insight_where('s', $filter_insight, G5_TIME_YMD);
$insight_where_ss = ieum_student_insight_where('ss', $filter_insight, G5_TIME_YMD);
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

$students = sql_query("
    select s.*, c.class_name, c.start_time,
           (select group_concat(concat(g.guardian_name, if(g.guardian_relation <> '', concat('(', g.guardian_relation, ')'), ''), ' ', g.guardian_phone, if(g.sms_attendance=1, ' 등원', ''), if(g.sms_checkout=1, ' 하원', ''), if(g.use_for_student_code=1, ' 번호', '')) order by g.sort_order asc separator '<br>')
              from " . IEUM_STUDENT_GUARDIAN_TABLE . " g
             where g.academy_id = s.academy_id
               and g.student_id = s.student_id
               and g.is_active = 1) as guardian_summary,
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

$student_rows = array();
while ($student_row = sql_fetch_array($students)) {
    $student_rows[] = $student_row;
}

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

$total = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . " s
      {$where}
", false);

$total_all = sql_fetch("
    select count(*) as cnt
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
", false);

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
.wrap{max-width:1180px;margin:28px auto;padding:0 20px}
.bar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap}
h1{margin:0;font-size:26px}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:22px;box-shadow:0 8px 20px rgba(15,23,42,.06)}
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
.form-grid{display:grid;grid-template-columns:160px 1fr;gap:12px 16px;align-items:center;max-width:760px}
label{font-weight:700}
input[type=text],select,textarea{width:100%;border:1px solid #cfd6df;border-radius:6px;padding:10px;font-size:15px}
textarea{min-height:82px;resize:vertical}
.actions{margin-top:18px;display:flex;gap:8px}
.count{color:#5b6472}
.summary{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 0}.summary-label{flex:0 0 100%;font-size:12px;font-weight:900;color:#667085;margin-top:4px}.chip{background:#eef2f7;border:1px solid #d8dee9;border-radius:999px;padding:6px 10px;font-weight:800;color:#344054;text-decoration:none}.chip.active{background:#1769c2;color:#fff;border-color:#1769c2}
.guardian-list{display:grid;gap:10px}.guardian-row{display:grid;grid-template-columns:1fr .9fr 1.35fr repeat(4,auto);gap:8px;align-items:center;padding:10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}.guardian-row label{white-space:nowrap;font-weight:700;font-size:13px}.guardian-row .remove-guardian{min-width:42px}.weekday-control{display:grid;gap:10px}.weekday-presets{display:flex;gap:8px;flex-wrap:wrap}.preset-btn{min-height:36px;border:1px solid #cfd6df;border-radius:6px;background:#fff;padding:7px 12px;font-weight:800;cursor:pointer}.preset-btn.active{background:#1769c2;border-color:#1769c2;color:#fff}.weekday-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.weekday-card,.ride-day-card{position:relative;display:flex;align-items:center;justify-content:center;min-height:48px;border:1px solid #cfd6df;border-radius:8px;background:#fff;font-size:18px;font-weight:900;cursor:pointer}.weekday-card input,.ride-day-card input{position:absolute;opacity:0;pointer-events:none}.weekday-card.selected,.ride-day-card.selected{background:#1769c2;border-color:#1769c2;color:#fff}.weekday-help{color:#667085;font-size:13px}.date-selects{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}.tuition-box,.vehicle-box{display:grid;gap:8px}.tuition-row{display:grid;grid-template-columns:130px minmax(160px,1fr) 120px minmax(140px,1fr);gap:8px;align-items:center}.tuition-row.second{grid-template-columns:130px 150px 1fr}.money-field{display:grid;grid-template-columns:auto 1fr auto;align-items:center;border:1px solid #cfd6df;border-radius:6px;background:#fff;overflow:hidden}.money-field span,.money-field em{height:40px;display:flex;align-items:center;padding:0 10px;background:#f8fafc;color:#667085;font-style:normal;font-weight:900;white-space:nowrap}.money-field input{border:0;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-radius:0;text-align:right;font-weight:800}.inline-check{display:flex;align-items:center;gap:6px;white-space:nowrap}.inline-check input{width:auto}.due-label{font-size:14px;color:#344054}.tuition-total{display:flex;align-items:center;justify-content:flex-end;border:1px solid #d9dee7;border-radius:8px;background:#f8fafc;padding:10px 12px;font-weight:900;color:#1769c2}.vehicle-tools{display:flex;gap:8px;flex-wrap:wrap}.vehicle-tools .btn{min-height:34px;padding:6px 10px;font-size:13px}.vehicle-row{display:grid;grid-template-columns:auto 90px 120px 1fr 1fr;gap:8px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:10px}.vehicle-row input[type=checkbox]{width:auto}.vehicle-row span{font-weight:900}.vehicle-memo,.vehicle-days{display:grid;grid-template-columns:90px 1fr;gap:8px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:10px 12px}.vehicle-contact{display:grid;grid-template-columns:90px 1fr 1fr;gap:8px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:10px 12px}.vehicle-memo span,.vehicle-contact span,.vehicle-days span{font-weight:900;color:#344054}.ride-day-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.ride-day-card{min-height:40px;font-size:15px}
.photo-box{display:grid;grid-template-columns:112px 1fr;gap:14px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:12px}.photo-preview{width:112px;height:112px;border-radius:12px;object-fit:cover;background:#e5e7eb;border:1px solid #d8dee9}.photo-empty{width:112px;height:112px;border-radius:12px;background:#e5e7eb;color:#667085;display:flex;align-items:center;justify-content:center;font-weight:900}.photo-controls{display:grid;gap:8px}.photo-controls input[type=file]{width:100%;border:1px solid #cfd6df;border-radius:6px;background:#fff;padding:10px}.photo-controls label{font-size:13px;color:#344054}
.guardian-row{grid-template-columns:1fr!important;gap:12px!important}.guardian-fields{display:grid;grid-template-columns:1fr .75fr 1.1fr;gap:8px}.guardian-flags{display:flex;gap:8px;flex-wrap:wrap}.guardian-flag{display:inline-flex;align-items:center;gap:6px;border:1px solid #cfd6df;border-radius:999px;background:#fff;padding:8px 10px;font-size:13px;font-weight:900;color:#344054}.guardian-flag input{width:auto}.guardian-flag:has(input:checked){background:#eaf4ff;border-color:#1769c2;color:#1769c2}.guardian-actions{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.guardian-actions .guardian-flag{background:#f8fafc}.guardian-actions .btn{min-height:34px}.guardian-section-title{font-size:12px;font-weight:900;color:#667085;margin:0 0 6px}.guardian-groups{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:start}.guardian-main{display:flex;gap:8px;flex-wrap:wrap}
.care-actions{display:grid;gap:8px;min-width:190px}.care-actions summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:34px;border:1px solid #b7c7de;border-radius:8px;background:#f4f8ff;color:#1769c2;padding:6px 10px;font-weight:900}.care-actions summary::-webkit-details-marker{display:none}.care-actions summary:before{content:'+';display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:#1769c2;color:#fff;font-size:13px;line-height:1}.care-actions[open] summary:before{content:'-';background:#344054}.care-actions form{display:grid;gap:6px}.care-actions input[type=text]{height:36px;padding:7px 9px;font-size:13px}.care-actions .btn{min-height:34px;padding:7px 9px;font-size:13px}.care-buttons{display:flex;gap:6px;flex-wrap:wrap}.care-buttons .btn{border-color:#d8dee9;background:#fff}.care-buttons .btn:hover,.care-actions summary:hover{border-color:#1769c2;background:#eaf4ff}.care-note{display:block;margin-top:4px;color:#667085;font-size:12px;line-height:1.35}
.student-table-wrap{overflow-x:auto}.student-cards{display:none;gap:12px}.student-card{border:1px solid #d9dee7;border-radius:10px;background:#fff;padding:14px;box-shadow:0 8px 18px rgba(15,23,42,.05)}.student-card.inactive{background:#fafafa;color:#667085}.student-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.student-card-name{font-size:19px;font-weight:1000;color:#111827}.student-card-code{color:#667085;font-size:13px;margin-top:2px}.student-card-status{border-radius:999px;background:#eef2f7;color:#344054;padding:5px 9px;font-size:12px;font-weight:900;white-space:nowrap}.student-card-status.active{background:#eaf4ff;color:#1769c2}.student-card-badges{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 10px}.student-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef2f7;color:#344054;padding:5px 8px;font-size:12px;font-weight:900}.student-badge.good{background:#eef9f1;color:#176b2c}.student-badge.warn{background:#fff6df;color:#9a5b00}.student-badge.danger{background:#fff1f1;color:#a4262c}.student-badge.info{background:#eaf4ff;color:#1769c2}.student-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:10px}.student-card-field{border:1px solid #edf1f7;border-radius:8px;background:#f8fafc;padding:9px}.student-card-field strong{display:block;color:#667085;font-size:12px;margin-bottom:3px}.student-card-field span{font-weight:800;color:#111827}.student-card-more{border-top:1px solid #edf1f7;margin-top:10px;padding-top:10px}.student-card-more summary{cursor:pointer;display:flex;justify-content:center;border:1px solid #d8dee9;border-radius:8px;background:#f8fafc;padding:9px;font-weight:1000;color:#1769c2}.student-card-more summary::-webkit-details-marker{display:none}.student-card-section{border-top:1px solid #edf1f7;padding-top:10px;margin-top:10px}.student-card-more .student-card-section:first-of-type{border-top:0}.student-card-section strong{display:block;color:#344054;margin-bottom:5px}.student-card-empty{color:#98a2b3}.student-card-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}.student-card-actions .care-actions{flex:1 1 220px}.empty-card{border:1px dashed #cfd6df;border-radius:10px;background:#fff;padding:24px;text-align:center;color:#667085;font-weight:900}
@media (max-width:980px){.student-table-wrap{display:none}.student-cards{display:grid}.panel{padding:16px}.search{display:grid;grid-template-columns:1fr 1fr;align-items:stretch}.search input{grid-column:1 / -1;min-width:0}.search .btn{width:100%}}
@media (max-width:720px){.form-grid{grid-template-columns:1fr}.search{grid-template-columns:1fr}.search input{min-width:0;width:100%}.bar{align-items:stretch}.btn{width:auto}table{font-size:13px}.tuition-row,.tuition-row.second,.vehicle-row,.vehicle-memo,.vehicle-contact,.vehicle-days,.guardian-row,.guardian-fields,.guardian-groups,.photo-box{grid-template-columns:1fr}.weekday-cards,.ride-day-cards{grid-template-columns:repeat(5,minmax(56px,1fr))}.money-field input{text-align:left}.student-card-grid{grid-template-columns:1fr}.student-card-head{align-items:flex-start}.student-card-actions{display:grid}.student-card-actions .btn{width:100%}}
</style>
</head>
<body>
<?php echo ieum_admin_header('students'); ?>
<?php echo ieum_admin_subnav('students'); ?>
<main class="wrap">
    <div id="studentAjaxStatus" class="ajax-status">목록을 불러오는 중입니다.</div>
    <div class="bar">
        <div>
            <h1>학생 관리</h1>
            <div class="count"><?php echo get_text($current_academy['academy_name']); ?></div>
            <div class="summary">
                <span class="summary-label">전체/프로그램</span>
                <a class="chip <?php echo ($filter_program === '' && $filter_class_time_raw === '' && $filter_grade === '' && $filter_insight === '' && $q === '') ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/students.php">전체 학생 <?php echo number_format((int) $total_all['cnt']); ?>명</a>
                <?php foreach ($program_options as $program_option) {
                    $program_code = $program_option['program_code'];
                    $program_count = isset($program_counts[$program_code]) ? (int) $program_counts[$program_code] : 0;
                ?>
                <a class="chip <?php echo $filter_program === $program_code ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('program_code' => $program_code))); ?>">
                    <?php echo get_text($program_option['program_name']); ?> <?php echo number_format($program_count); ?>명
                </a>
                <?php } ?>
                <span class="summary-label">수업 부</span>
                <a class="chip <?php echo $filter_class_time_raw === 'unassigned' ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('class_time_id' => 'unassigned'))); ?>">미지정 <?php echo number_format((int) $unassigned_count['cnt']); ?>명</a>
                <?php while ($class_count = sql_fetch_array($class_counts_result)) { ?>
                <a class="chip <?php echo $filter_class_time_id === (int) $class_count['class_time_id'] ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('class_time_id' => (int) $class_count['class_time_id']))); ?>">
                    <?php echo get_text($class_count['class_name'] . ' ' . $class_count['start_time']); ?> <?php echo number_format((int) $class_count['cnt']); ?>명
                </a>
                <?php } ?>
                <span class="summary-label">자동 체크</span>
                <?php foreach ($insight_options as $insight_value => $insight_label) {
                    if ($insight_value === '') {
                        continue;
                    }
                ?>
                <a class="chip <?php echo $filter_insight === $insight_value ? 'active' : ''; ?>" href="<?php echo get_text(ieum_student_list_url(array('insight' => $insight_value))); ?>">
                    <?php echo get_text($insight_label); ?>
                </a>
                <?php } ?>
            </div>
        </div>
        <div>
            <?php if ($mode === 'form') { ?>
            <a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/students.php">목록</a>
            <?php } else { ?>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form">학생 등록</a>
            <?php } ?>
        </div>
    </div>

    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
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
                'use_for_student_code' => 0,
                'is_primary' => 1,
            );
        }
        $vehicle_contact_options = array();
        if (!empty($form['student_phone'])) {
            $vehicle_contact_options[] = array('label' => '학생', 'phone' => $form['student_phone']);
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
    ?>
    <section class="panel">
        <form method="post" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="student_id" value="<?php echo (int) $form['student_id']; ?>">
            <div class="form-grid">
                <label for="student_code">학생번호</label>
                <input type="text" name="student_code" id="student_code" value="<?php echo get_text($form['student_code']); ?>" maxlength="20" required>

                <label for="student_name">학생명</label>
                <input type="text" name="student_name" id="student_name" value="<?php echo get_text($form['student_name']); ?>" maxlength="50" required>

                <label for="student_phone">학생 연락처</label>
                <input type="text" name="student_phone" id="student_phone" value="<?php echo get_text(isset($form['student_phone']) ? $form['student_phone'] : ''); ?>" maxlength="30" placeholder="학생 휴대폰이 있으면 입력">

                <label for="student_photo_file">학생 사진</label>
                <div class="photo-box">
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

                <label>차량 이용</label>
                <div class="vehicle-box">
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

                <label>보호자</label>
                <div class="guardian-list" id="guardianList">
                    <?php foreach ($guardians as $idx => $guardian) { ?>
                    <div class="guardian-row">
                        <div class="guardian-fields">
                            <input type="text" name="guardian_name[]" value="<?php echo get_text($guardian['guardian_name']); ?>" maxlength="50" placeholder="보호자명">
                            <input type="text" name="guardian_relation[]" value="<?php echo get_text(isset($guardian['guardian_relation']) ? $guardian['guardian_relation'] : ''); ?>" maxlength="30" placeholder="관계">
                            <input type="text" name="guardian_phone[]" value="<?php echo get_text($guardian['guardian_phone']); ?>" maxlength="30" placeholder="010-0000-0000">
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
                                <div class="guardian-section-title">관리</div>
                                <div class="guardian-main">
                                    <label class="guardian-flag"><input type="checkbox" class="use-code" name="guardian_use_code[<?php echo (int) $idx; ?>]" value="1" <?php echo !empty($guardian['use_for_student_code']) ? 'checked' : ''; ?>> 학생번호</label>
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

                <label for="memo">메모</label>
                <textarea name="memo" id="memo" maxlength="255"><?php echo get_text($form['memo']); ?></textarea>

                <label for="is_active">사용 여부</label>
                <div><label><input type="checkbox" name="is_active" id="is_active" value="1" <?php echo $form['is_active'] ? 'checked' : ''; ?>> 사용</label></div>
            </div>
            <div class="actions">
                <button class="btn primary" type="submit">저장</button>
                <a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/students.php">취소</a>
            </div>
        </form>
    </section>
    <?php } else { ?>
    <section class="panel">
        <div class="bar">
            <form method="get" class="search">
                <input type="text" name="q" value="<?php echo get_text($q); ?>" placeholder="학생번호, 학생명, 보호자, 연락처 검색">
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
                <select name="insight">
                    <?php foreach ($insight_options as $insight_value => $insight_label) { ?>
                    <option value="<?php echo get_text($insight_value); ?>" <?php echo get_selected($filter_insight, $insight_value); ?>><?php echo get_text($insight_label); ?></option>
                    <?php } ?>
                </select>
                <button type="submit" class="btn">검색</button>
                <?php if ($q !== '' || $filter_program !== '' || $filter_grade !== '' || $filter_class_time_raw !== '' || $filter_insight !== '') { ?><a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/students.php">전체</a><?php } ?>
            </form>
        </div>
        <?php if ($filter_insight !== '') { ?>
        <p class="notice ok">자동 체크 목록: <?php echo get_text($insight_options[$filter_insight]); ?> 학생만 보고 있습니다.</p>
        <?php } ?>
        <div class="student-table-wrap">
        <table>
            <thead>
            <tr>
                <th scope="col">상태</th>
                <th scope="col">학생번호</th>
                <th scope="col">학생명</th>
                <th scope="col">프로그램</th>
                <th scope="col">학년/부</th>
                <th scope="col">수업 부</th>
                <th scope="col">출석 요일</th>
                <th scope="col">보호자</th>
                <th scope="col">차량</th>
                <th scope="col">메모</th>
                <th scope="col">관리</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $i = 0;
            foreach ($student_rows as $row) {
                $i++;
                $row_class = $row['is_active'] ? '' : 'inactive';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td><?php echo $row['is_active'] ? '사용' : '중지'; ?></td>
                <td><?php echo get_text($row['student_code']); ?></td>
                <td><?php echo get_text($row['student_name']); ?></td>
                <td><?php echo get_text(ieum_program_label($academy_id, isset($row['program_code']) ? $row['program_code'] : '')); ?></td>
                <td><?php echo get_text(ieum_grade_label($row['grade_group'])); ?></td>
                <td><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : ''); ?></td>
                <td><?php echo get_text(ieum_attendance_days_label(isset($row['attendance_days']) ? $row['attendance_days'] : '')); ?></td>
                <td class="left"><?php echo $row['guardian_summary'] ? nl2br(get_text(str_replace('<br>', "\n", $row['guardian_summary']))) : ''; ?></td>
                <td class="left"><?php echo $row['vehicle_summary'] ? nl2br(get_text(str_replace('<br>', "\n", $row['vehicle_summary']))) : ''; ?></td>
                <td class="left">
                    <?php echo get_text($row['memo']); ?>
                    <?php if (!empty($row['counseling_note'])) { ?>
                    <span class="care-note">상담: <?php echo get_text($row['counseling_note']); ?></span>
                    <?php } ?>
                </td>
                <td>
                    <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $row['student_id']; ?>">수정</a>
                    <form method="post" class="inline" onsubmit="return confirm('<?php echo $row['is_active'] ? '이 학생을 사용중지할까요?' : '이 학생을 다시 사용 상태로 바꿀까요?'; ?>');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                        <button type="submit" class="btn <?php echo $row['is_active'] ? 'danger' : 'muted'; ?>"><?php echo $row['is_active'] ? '중지' : '사용'; ?></button>
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
                        <div class="care-buttons">
                            <form method="post" onsubmit="return confirm('생일 축하 안내 문자를 대기열에 넣을까요? Android 게이트웨이가 켜져 있으면 발송될 수 있습니다.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="queue_care_sms">
                                <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                                <input type="hidden" name="care_message_type" value="birthday">
                                <button type="submit" class="btn muted">생일 문자</button>
                            </form>
                            <form method="post" onsubmit="return confirm('장기 미등원 안부 문자를 대기열에 넣을까요? Android 게이트웨이가 켜져 있으면 발송될 수 있습니다.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="queue_care_sms">
                                <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                                <input type="hidden" name="care_message_type" value="long_absent">
                                <button type="submit" class="btn muted">안부 문자</button>
                            </form>
                        </div>
                    </details>
                </td>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?>
            <tr>
                <td colspan="11">등록된 학생이 없습니다.</td>
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
            ?>
            <article class="student-card <?php echo $row['is_active'] ? '' : 'inactive'; ?>">
                <div class="student-card-head">
                    <div>
                        <div class="student-card-name"><?php echo get_text($row['student_name']); ?></div>
                        <div class="student-card-code"><?php echo get_text($row['student_code']); ?> · <?php echo get_text(ieum_program_label($academy_id, isset($row['program_code']) ? $row['program_code'] : '')); ?></div>
                    </div>
                    <span class="student-card-status <?php echo $row['is_active'] ? 'active' : ''; ?>"><?php echo $row['is_active'] ? '사용' : '중지'; ?></span>
                </div>
                <div class="student-card-badges">
                    <span class="student-badge <?php echo $last_attendance_class; ?>"><?php echo get_text($last_attendance_label); ?></span>
                    <span class="student-badge <?php echo $tuition_badge_class; ?>"><?php echo get_text($tuition_badge_label); ?></span>
                    <?php if ($vehicle_count > 0) { ?><span class="student-badge info">차량 <?php echo number_format($vehicle_count); ?>건</span><?php } ?>
                    <?php if (!empty($row['counseling_note'])) { ?><span class="student-badge warn">상담 메모</span><?php } ?>
                </div>
                <div class="student-card-grid">
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
                    <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $row['student_id']; ?>">수정</a>
                    <form method="post" class="inline" onsubmit="return confirm('<?php echo $row['is_active'] ? '이 학생을 사용중지할까요?' : '이 학생을 다시 사용 상태로 바꿀까요?'; ?>');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                        <button type="submit" class="btn <?php echo $row['is_active'] ? 'danger' : 'muted'; ?>"><?php echo $row['is_active'] ? '중지' : '사용'; ?></button>
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
                        <div class="care-buttons">
                            <form method="post" onsubmit="return confirm('생일 축하 안내 문자를 대기열에 넣을까요? Android 게이트웨이가 켜져 있으면 발송될 수 있습니다.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="queue_care_sms">
                                <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                                <input type="hidden" name="care_message_type" value="birthday">
                                <button type="submit" class="btn muted">생일 문자</button>
                            </form>
                            <form method="post" onsubmit="return confirm('장기 미등원 안부 문자를 대기열에 넣을까요? Android 게이트웨이가 켜져 있으면 발송될 수 있습니다.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="queue_care_sms">
                                <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                                <input type="hidden" name="care_message_type" value="long_absent">
                                <button type="submit" class="btn muted">안부 문자</button>
                            </form>
                        </div>
                    </details>
                </div>
                </details>
            </article>
            <?php } ?>
            <?php if (!$student_rows) { ?>
            <div class="empty-card">등록된 학생이 없습니다.</div>
            <?php } ?>
        </div>
    </section>
    <?php } ?>
</main>
<script>
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
const addGuardian = document.getElementById('addGuardian');
const guardianList = document.getElementById('guardianList');
const studentCodeInput = document.getElementById('student_code');
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
    input.value = formatKoreanPhone(input.value);
    input.addEventListener('input', () => {
        input.value = formatKoreanPhone(input.value);
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
function applyStudentCodeFromRow(row) {
    const phone = row.querySelector('input[name="guardian_phone[]"]');
    const digits = digitsOnly(phone ? phone.value : '');
    if (digits.length >= 4 && studentCodeInput) {
        studentCodeInput.value = digits.slice(-4);
    }
}
function bindGuardianRow(row) {
    const remove = row.querySelector('.remove-guardian');
    const useCode = row.querySelector('.use-code');
    const primary = row.querySelector('.primary-guardian');
    bindPhoneFormatter(row.querySelector('input[name="guardian_phone[]"]'));
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
                <input type="text" name="guardian_phone[]" maxlength="30" placeholder="010-0000-0000">
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
                    <div class="guardian-section-title">관리</div>
                    <div class="guardian-main">
                        <label class="guardian-flag"><input type="checkbox" class="use-code" name="guardian_use_code[${index}]" value="1"> 학생번호</label>
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
        if (!link.classList.contains('chip') && !link.classList.contains('muted')) return;
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
</script>
</body>
</html>
