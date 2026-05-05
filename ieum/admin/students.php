<?php
$sub_menu = '950120';
require_once './_common.php';

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
    );
}

function ieum_grade_label($value)
{
    $options = ieum_grade_options();
    return isset($options[$value]) ? $options[$value] : $value;
}

function ieum_attendance_week_type_options()
{
    return array('2' => '주 2회', '3' => '주 3회', '4' => '주 4회', '5' => '주 5회', 'custom' => '직접 선택');
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

function ieum_fetch_vehicle_routes($academy_id)
{
    $academy_id = (int) $academy_id;
    $routes = array();
    $result = sql_query("
        select *
          from " . IEUM_VEHICLE_ROUTE_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
      order by sort_order asc, route_name asc
    ", false);

    while ($row = sql_fetch_array($result)) {
        $routes[] = $row;
    }

    return $routes;
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
        select sv.*, r.route_name
          from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
     left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
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

function ieum_save_vehicle_assignment($academy_id, $student_id, $ride_type, $enabled, $route_id, $place_name)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $ride_type = $ride_type === 'dropoff' ? 'dropoff' : 'pickup';
    $enabled = $enabled ? 1 : 0;
    $route_id = (int) $route_id;
    $place_name = trim($place_name);
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

    $place_name_sql = sql_escape_string($place_name);
    sql_query("
        insert into " . IEUM_STUDENT_VEHICLE_TABLE . "
            set academy_id = '{$academy_id}',
                student_id = '{$student_id}',
                ride_type = '{$ride_type_sql}',
                route_id = '{$route_id}',
                place_name = '{$place_name_sql}',
                is_active = 1,
                created_at = '" . G5_TIME_YMDHIS . "'
    ");
}

function ieum_save_guardians($academy_id, $student_id, $names, $relations, $phones, $checkin_flags, $checkout_flags, $code_flags, $primary_flags)
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

        sql_query("
            insert into " . IEUM_STUDENT_GUARDIAN_TABLE . "
                set academy_id = '{$academy_id}',
                    student_id = '{$student_id}',
                    guardian_name = '{$name_sql}',
                    guardian_relation = '{$relation_sql}',
                    guardian_phone = '{$phone_sql}',
                    sms_attendance = '{$checkin}',
                    sms_checkout = '{$checkout}',
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
            $grade_group = isset($_POST['grade_group']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_POST['grade_group'])) : '';
            $class_time_id = isset($_POST['class_time_id']) ? (int) $_POST['class_time_id'] : 0;
            $attendance_week_type = isset($_POST['attendance_week_type']) ? preg_replace('/[^0-9a-z_]/', '', trim($_POST['attendance_week_type'])) : '5';
            if (!isset(ieum_attendance_week_type_options()[$attendance_week_type])) {
                $attendance_week_type = 'custom';
            }
            $attendance_days = ieum_clean_attendance_days(isset($_POST['attendance_days']) ? $_POST['attendance_days'] : array());
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
            $vehicle_pickup_route_id = isset($_POST['vehicle_pickup_route_id']) ? (int) $_POST['vehicle_pickup_route_id'] : 0;
            $vehicle_pickup_place = isset($_POST['vehicle_pickup_place']) ? trim($_POST['vehicle_pickup_place']) : '';
            $vehicle_dropoff_enabled = isset($_POST['vehicle_dropoff_enabled']) ? 1 : 0;
            $vehicle_dropoff_route_id = isset($_POST['vehicle_dropoff_route_id']) ? (int) $_POST['vehicle_dropoff_route_id'] : 0;
            $vehicle_dropoff_place = isset($_POST['vehicle_dropoff_place']) ? trim($_POST['vehicle_dropoff_place']) : '';
            $guardian_names = isset($_POST['guardian_name']) && is_array($_POST['guardian_name']) ? $_POST['guardian_name'] : array();
            $guardian_relations = isset($_POST['guardian_relation']) && is_array($_POST['guardian_relation']) ? $_POST['guardian_relation'] : array();
            $guardian_phones = isset($_POST['guardian_phone']) && is_array($_POST['guardian_phone']) ? $_POST['guardian_phone'] : array();
            $guardian_sms_attendance = isset($_POST['guardian_sms_attendance']) && is_array($_POST['guardian_sms_attendance']) ? $_POST['guardian_sms_attendance'] : array();
            $guardian_sms_checkout = isset($_POST['guardian_sms_checkout']) && is_array($_POST['guardian_sms_checkout']) ? $_POST['guardian_sms_checkout'] : array();
            $guardian_use_code = isset($_POST['guardian_use_code']) && is_array($_POST['guardian_use_code']) ? $_POST['guardian_use_code'] : array();
            $guardian_primary = isset($_POST['guardian_primary']) && is_array($_POST['guardian_primary']) ? $_POST['guardian_primary'] : array();
            $memo = isset($_POST['memo']) ? trim($_POST['memo']) : '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
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
                $duplicate_sql = "
                    select student_id
                      from " . IEUM_STUDENT_TABLE . "
                     where student_code = '{$student_code_sql}'
                       and academy_id = '{$academy_id}'
                ";
                if ($post_student_id) {
                    $duplicate_sql .= " and student_id <> '{$post_student_id}'";
                }
                $duplicate_sql .= " limit 1";
                $duplicate = sql_fetch($duplicate_sql, false);

                if (isset($duplicate['student_id'])) {
                    $error = '이미 사용 중인 학생번호입니다.';
                } else {
                    $student_name_sql = sql_escape_string($student_name);
                    $grade_group_sql = sql_escape_string($grade_group);
                    $attendance_week_type_sql = sql_escape_string($attendance_week_type);
                    $attendance_days_sql = sql_escape_string($attendance_days);
                    $admission_date_sql = sql_escape_string($admission_date);
                    $tuition_week_type_sql = sql_escape_string($tuition_week_type);
                    $tuition_note_sql = sql_escape_string($tuition_note);
                    $vehicle_pickup_place_sql = sql_escape_string($vehicle_pickup_place);
                    $vehicle_dropoff_place_sql = sql_escape_string($vehicle_dropoff_place);
                    $memo_sql = sql_escape_string($memo);
                    $admission_set = $admission_date_sql === '' ? "admission_date = null" : "admission_date = '{$admission_date_sql}'";

                    if ($post_student_id) {
                        sql_query("
                            update " . IEUM_STUDENT_TABLE . "
                               set student_code = '{$student_code_sql}',
                                   student_name = '{$student_name_sql}',
                                   grade_group = '{$grade_group_sql}',
                                   class_time_id = '{$class_time_id}',
                                   attendance_week_type = '{$attendance_week_type_sql}',
                                   attendance_days = '{$attendance_days_sql}',
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
                        $message = '학생 정보가 수정되었습니다.';
                    } else {
                        sql_query("
                            insert into " . IEUM_STUDENT_TABLE . "
                                set academy_id = '{$academy_id}',
                                    student_code = '{$student_code_sql}',
                                    student_name = '{$student_name_sql}',
                                    grade_group = '{$grade_group_sql}',
                                    class_time_id = '{$class_time_id}',
                                    attendance_week_type = '{$attendance_week_type_sql}',
                                    attendance_days = '{$attendance_days_sql}',
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
                    ieum_save_vehicle_assignment($academy_id, $saved_student_id, 'pickup', $vehicle_pickup_enabled, $vehicle_pickup_route_id, $vehicle_pickup_place);
                    ieum_save_vehicle_assignment($academy_id, $saved_student_id, 'dropoff', $vehicle_dropoff_enabled, $vehicle_dropoff_route_id, $vehicle_dropoff_place);
                    $mode = 'list';
                    $student_id = 0;
                }
            }
        } elseif ($action === 'toggle') {
            $target = ieum_fetch_student($post_student_id);
            if (!$target) {
                $error = '학생 정보를 찾을 수 없습니다.';
            } else {
                $next_active = $target['is_active'] ? 0 : 1;
                sql_query("
                    update " . IEUM_STUDENT_TABLE . "
                       set is_active = '{$next_active}',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where student_id = '{$post_student_id}'
                       and academy_id = '{$academy_id}'
                ");
                $message = $next_active ? '학생을 사용 상태로 변경했습니다.' : '학생을 사용중지했습니다.';
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
$filter_grade = isset($_GET['grade_group']) ? preg_replace('/[^0-9A-Za-z_]/', '', trim($_GET['grade_group'])) : '';
$filter_class_time_raw = isset($_GET['class_time_id']) ? trim($_GET['class_time_id']) : '';
$filter_class_time_id = ctype_digit($filter_class_time_raw) ? (int) $filter_class_time_raw : 0;
$q_sql = sql_escape_string($q);
$where = " where 1 ";
$where .= " and s.academy_id = '{$academy_id}' ";
if ($q !== '') {
    $where .= " and (s.student_code like '%{$q_sql}%' or s.student_name like '%{$q_sql}%' or exists (select 1 from " . IEUM_STUDENT_GUARDIAN_TABLE . " g where g.student_id = s.student_id and g.is_active = 1 and (g.guardian_name like '%{$q_sql}%' or g.guardian_phone like '%{$q_sql}%'))) ";
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

$students = sql_query("
    select s.*, c.class_name, c.start_time,
           (select group_concat(concat(g.guardian_name, if(g.guardian_relation <> '', concat('(', g.guardian_relation, ')'), ''), ' ', g.guardian_phone, if(g.sms_attendance=1, ' 등원', ''), if(g.sms_checkout=1, ' 하원', ''), if(g.use_for_student_code=1, ' 번호', '')) order by g.sort_order asc separator '<br>')
              from " . IEUM_STUDENT_GUARDIAN_TABLE . " g
             where g.academy_id = s.academy_id
               and g.student_id = s.student_id
               and g.is_active = 1) as guardian_summary,
           (select group_concat(concat(if(sv.ride_type='pickup', '등원', '하원'), ' ', ifnull(r.route_name, ''), if(sv.place_name <> '', concat(' ', sv.place_name), '')) order by field(sv.ride_type, 'pickup', 'dropoff') separator '<br>')
              from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
         left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
             where sv.academy_id = s.academy_id
               and sv.student_id = s.student_id
               and sv.is_active = 1) as vehicle_summary
     from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      {$where}
  order by s.is_active desc, s.student_name asc, s.student_code asc
", false);

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

$vehicle_route_options = ieum_fetch_vehicle_routes($academy_id);

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
      from " . IEUM_STUDENT_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and class_time_id = 0
", false);

$class_counts_result = sql_query("
    select c.class_time_id, c.class_name, c.start_time, count(s.student_id) as cnt
      from " . IEUM_CLASS_TIME_TABLE . " c
 left join " . IEUM_STUDENT_TABLE . " s on s.class_time_id = c.class_time_id and s.academy_id = c.academy_id and s.is_active = 1
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
.summary{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 0}.chip{background:#eef2f7;border:1px solid #d8dee9;border-radius:999px;padding:6px 10px;font-weight:800;color:#344054;text-decoration:none}.chip.active{background:#1769c2;color:#fff;border-color:#1769c2}
.guardian-list{display:grid;gap:10px}.guardian-row{display:grid;grid-template-columns:1fr .9fr 1.35fr repeat(4,auto);gap:8px;align-items:center;padding:10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}.guardian-row label{white-space:nowrap;font-weight:700;font-size:13px}.guardian-row .remove-guardian{min-width:42px}.weekday-control{display:grid;gap:10px}.weekday-presets{display:flex;gap:8px;flex-wrap:wrap}.preset-btn{min-height:36px;border:1px solid #cfd6df;border-radius:6px;background:#fff;padding:7px 12px;font-weight:800;cursor:pointer}.preset-btn.active{background:#1769c2;border-color:#1769c2;color:#fff}.weekday-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.weekday-card{position:relative;display:flex;align-items:center;justify-content:center;min-height:48px;border:1px solid #cfd6df;border-radius:8px;background:#fff;font-size:18px;font-weight:900;cursor:pointer}.weekday-card input{position:absolute;opacity:0;pointer-events:none}.weekday-card.selected{background:#1769c2;border-color:#1769c2;color:#fff}.weekday-help{color:#667085;font-size:13px}.date-selects{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}.tuition-box,.vehicle-box{display:grid;gap:8px}.tuition-row{display:grid;grid-template-columns:130px 1fr 120px 1fr;gap:8px;align-items:center}.tuition-row.second{grid-template-columns:130px 150px 1fr}.inline-check{display:flex;align-items:center;gap:6px;white-space:nowrap}.inline-check input{width:auto}.due-label{font-size:14px;color:#344054}.tuition-total{display:flex;align-items:center;justify-content:flex-end;border:1px solid #d9dee7;border-radius:8px;background:#f8fafc;padding:10px 12px;font-weight:900;color:#1769c2}.vehicle-row{display:grid;grid-template-columns:auto 90px 1fr 1fr;gap:8px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:10px}.vehicle-row input[type=checkbox]{width:auto}.vehicle-row span{font-weight:900}
@media (max-width:720px){.form-grid{grid-template-columns:1fr}.search input{min-width:0;width:100%}.search{width:100%;align-items:stretch}.bar{align-items:stretch}.btn{width:auto}table{font-size:13px}}
</style>
</head>
<body>
<?php echo ieum_admin_header('students'); ?>
<main class="wrap">
    <div class="bar">
        <div>
            <h1>학생 관리</h1>
            <div class="count"><?php echo get_text($current_academy['academy_name']); ?></div>
            <div class="summary">
                <a class="chip <?php echo ($filter_class_time_raw === '' && $filter_grade === '' && $q === '') ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/students.php">전체 학생 <?php echo number_format((int) $total_all['cnt']); ?>명</a>
                <a class="chip <?php echo $filter_class_time_raw === 'unassigned' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/students.php?class_time_id=unassigned">미지정 <?php echo number_format((int) $unassigned_count['cnt']); ?>명</a>
                <?php while ($class_count = sql_fetch_array($class_counts_result)) { ?>
                <a class="chip <?php echo $filter_class_time_id === (int) $class_count['class_time_id'] ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/students.php?class_time_id=<?php echo (int) $class_count['class_time_id']; ?>">
                    <?php echo get_text($class_count['class_name'] . ' ' . $class_count['start_time']); ?> <?php echo number_format((int) $class_count['cnt']); ?>명
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
            'grade_group' => '',
            'class_time_id' => 0,
            'attendance_week_type' => '5',
            'attendance_days' => 'mon,tue,wed,thu,fri',
            'admission_date' => '',
            'tuition_week_type' => '5',
            'tuition_amount' => 0,
            'sibling_discount_enabled' => 0,
            'sibling_discount_amount' => 0,
            'tuition_due_day' => 5,
            'tuition_note' => '',
            'vehicle_pickup_enabled' => 0,
            'vehicle_pickup_route_id' => 0,
            'vehicle_pickup_place' => '',
            'vehicle_dropoff_enabled' => 0,
            'vehicle_dropoff_route_id' => 0,
            'vehicle_dropoff_place' => '',
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
                'use_for_student_code' => 0,
                'is_primary' => 1,
            );
        }
        $vehicle_assignments = $editing ? ieum_fetch_vehicle_assignments($academy_id, (int) $form['student_id']) : array('pickup' => null, 'dropoff' => null);
        if ($vehicle_assignments['pickup']) {
            $form['vehicle_pickup_enabled'] = 1;
            $form['vehicle_pickup_route_id'] = (int) $vehicle_assignments['pickup']['route_id'];
            $form['vehicle_pickup_place'] = $vehicle_assignments['pickup']['place_name'];
        } elseif (!isset($form['vehicle_pickup_route_id'])) {
            $form['vehicle_pickup_route_id'] = 0;
        }
        if ($vehicle_assignments['dropoff']) {
            $form['vehicle_dropoff_enabled'] = 1;
            $form['vehicle_dropoff_route_id'] = (int) $vehicle_assignments['dropoff']['route_id'];
            $form['vehicle_dropoff_place'] = $vehicle_assignments['dropoff']['place_name'];
        } elseif (!isset($form['vehicle_dropoff_route_id'])) {
            $form['vehicle_dropoff_route_id'] = 0;
        }
    ?>
    <section class="panel">
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="student_id" value="<?php echo (int) $form['student_id']; ?>">
            <div class="form-grid">
                <label for="student_code">학생번호</label>
                <input type="text" name="student_code" id="student_code" value="<?php echo get_text($form['student_code']); ?>" maxlength="20" required>

                <label for="student_name">학생명</label>
                <input type="text" name="student_name" id="student_name" value="<?php echo get_text($form['student_name']); ?>" maxlength="50" required>

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
                        <input type="number" name="tuition_amount" id="tuition_amount" value="<?php echo (int) $form['tuition_amount']; ?>" min="0" placeholder="월 수련비">
                        <label class="inline-check"><input type="checkbox" name="sibling_discount_enabled" id="sibling_discount_enabled" value="1" <?php echo !empty($form['sibling_discount_enabled']) ? 'checked' : ''; ?>> 형제할인</label>
                        <input type="number" name="sibling_discount_amount" id="sibling_discount_amount" value="<?php echo (int) $form['sibling_discount_amount']; ?>" min="0" placeholder="할인금액">
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
                    <label class="vehicle-row">
                        <input type="checkbox" name="vehicle_pickup_enabled" id="vehicle_pickup_enabled" value="1" <?php echo !empty($form['vehicle_pickup_enabled']) ? 'checked' : ''; ?>>
                        <span>등원 차량</span>
                        <select name="vehicle_pickup_route_id" id="vehicle_pickup_route_id">
                            <option value="0">노선 선택 안함</option>
                            <?php foreach ($vehicle_route_options as $route) { if ($route['route_type'] === 'dropoff') { continue; } ?>
                            <option value="<?php echo (int) $route['route_id']; ?>" <?php echo get_selected((int) $form['vehicle_pickup_route_id'], (int) $route['route_id']); ?>><?php echo get_text($route['route_name']); ?></option>
                            <?php } ?>
                        </select>                        <input type="text" name="vehicle_pickup_place" id="vehicle_pickup_place" value="<?php echo get_text($form['vehicle_pickup_place']); ?>" maxlength="100" placeholder="예: 아이이음초등학교">
                    </label>
                    <label class="vehicle-row">
                        <input type="checkbox" name="vehicle_dropoff_enabled" id="vehicle_dropoff_enabled" value="1" <?php echo !empty($form['vehicle_dropoff_enabled']) ? 'checked' : ''; ?>>
                        <span>하원 차량</span>
                        <select name="vehicle_dropoff_route_id" id="vehicle_dropoff_route_id">
                            <option value="0">노선 선택 안함</option>
                            <?php foreach ($vehicle_route_options as $route) { if ($route['route_type'] === 'pickup') { continue; } ?>
                            <option value="<?php echo (int) $route['route_id']; ?>" <?php echo get_selected((int) $form['vehicle_dropoff_route_id'], (int) $route['route_id']); ?>><?php echo get_text($route['route_name']); ?></option>
                            <?php } ?>
                        </select>                        <input type="text" name="vehicle_dropoff_place" id="vehicle_dropoff_place" value="<?php echo get_text($form['vehicle_dropoff_place']); ?>" maxlength="100" placeholder="예: 아이이음 아파트 1004동">
                    </label>
                    <div class="weekday-help">등원/하원 위치를 따로 관리하면 차량표, 미탑승 확인, 하원 알림 문구에 활용할 수 있습니다.</div>
                </div>

                <label>보호자</label>
                <div class="guardian-list" id="guardianList">
                    <?php foreach ($guardians as $idx => $guardian) { ?>
                    <div class="guardian-row">
                        <input type="text" name="guardian_name[]" value="<?php echo get_text($guardian['guardian_name']); ?>" maxlength="50" placeholder="보호자명">
                        <input type="text" name="guardian_relation[]" value="<?php echo get_text(isset($guardian['guardian_relation']) ? $guardian['guardian_relation'] : ''); ?>" maxlength="30" placeholder="관계">
                        <input type="text" name="guardian_phone[]" value="<?php echo get_text($guardian['guardian_phone']); ?>" maxlength="30" placeholder="010-0000-0000">
                        <label><input type="checkbox" name="guardian_sms_attendance[<?php echo (int) $idx; ?>]" value="1" <?php echo !empty($guardian['sms_attendance']) ? 'checked' : ''; ?>> 등원문자</label>
                        <label><input type="checkbox" name="guardian_sms_checkout[<?php echo (int) $idx; ?>]" value="1" <?php echo !empty($guardian['sms_checkout']) ? 'checked' : ''; ?>> 하원문자</label>
                        <label><input type="checkbox" class="use-code" name="guardian_use_code[<?php echo (int) $idx; ?>]" value="1" <?php echo !empty($guardian['use_for_student_code']) ? 'checked' : ''; ?>> 학생번호 사용</label>
                        <label><input type="checkbox" class="primary-guardian" name="guardian_primary[<?php echo (int) $idx; ?>]" value="1" <?php echo !empty($guardian['is_primary']) ? 'checked' : ''; ?>> 대표</label>
                        <button type="button" class="btn muted remove-guardian">삭제</button>
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
                <button type="submit" class="btn">검색</button>
                <?php if ($q !== '' || $filter_grade !== '' || $filter_class_time_raw !== '') { ?><a class="btn muted" href="<?php echo IEUM_URL; ?>/admin/students.php">전체</a><?php } ?>
            </form>
        </div>
        <table>
            <thead>
            <tr>
                <th scope="col">상태</th>
                <th scope="col">학생번호</th>
                <th scope="col">학생명</th>
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
            while ($row = sql_fetch_array($students)) {
                $i++;
                $row_class = $row['is_active'] ? '' : 'inactive';
            ?>
            <tr class="<?php echo $row_class; ?>">
                <td><?php echo $row['is_active'] ? '사용' : '중지'; ?></td>
                <td><?php echo get_text($row['student_code']); ?></td>
                <td><?php echo get_text($row['student_name']); ?></td>
                <td><?php echo get_text(ieum_grade_label($row['grade_group'])); ?></td>
                <td><?php echo get_text($row['class_name'] ? $row['class_name'] . ' ' . $row['start_time'] : ''); ?></td>
                <td><?php echo get_text(ieum_attendance_days_label(isset($row['attendance_days']) ? $row['attendance_days'] : '')); ?></td>
                <td class="left"><?php echo $row['guardian_summary'] ? nl2br(get_text(str_replace('<br>', "\n", $row['guardian_summary']))) : ''; ?></td>
                <td class="left"><?php echo $row['vehicle_summary'] ? nl2br(get_text(str_replace('<br>', "\n", $row['vehicle_summary']))) : ''; ?></td>
                <td class="left"><?php echo get_text($row['memo']); ?></td>
                <td>
                    <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $row['student_id']; ?>">수정</a>
                    <form method="post" class="inline" onsubmit="return confirm('<?php echo $row['is_active'] ? '이 학생을 사용중지할까요?' : '이 학생을 다시 사용 상태로 바꿀까요?'; ?>');">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="student_id" value="<?php echo (int) $row['student_id']; ?>">
                        <button type="submit" class="btn <?php echo $row['is_active'] ? 'danger' : 'muted'; ?>"><?php echo $row['is_active'] ? '중지' : '사용'; ?></button>
                    </form>
                </td>
            </tr>
            <?php } ?>
            <?php if ($i === 0) { ?>
            <tr>
                <td colspan="9">등록된 학생이 없습니다.</td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
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
const admissionDate = document.getElementById('admission_date');
const admissionYear = document.getElementById('admission_year');
const admissionMonth = document.getElementById('admission_month');
const admissionDay = document.getElementById('admission_day');
const presetButtons = document.querySelectorAll('.preset-btn');
const weekdayCards = document.querySelectorAll('.weekday-card');
const weekdayInputs = document.querySelectorAll('#weekdayCards input[type="checkbox"]');
function digitsOnly(value) {
    return (value || '').replace(/\D/g, '');
}
function syncWeekdayCards() {
    weekdayCards.forEach((card) => {
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
            <input type="text" name="guardian_name[]" maxlength="50" placeholder="보호자명">
            <input type="text" name="guardian_relation[]" maxlength="30" placeholder="관계">
            <input type="text" name="guardian_phone[]" maxlength="30" placeholder="010-0000-0000">
            <label><input type="checkbox" name="guardian_sms_attendance[${index}]" value="1" checked> 등원문자</label>
            <label><input type="checkbox" name="guardian_sms_checkout[${index}]" value="1"> 하원문자</label>
            <label><input type="checkbox" class="use-code" name="guardian_use_code[${index}]" value="1"> 학생번호 사용</label>
            <label><input type="checkbox" class="primary-guardian" name="guardian_primary[${index}]" value="1"> 대표</label>
            <button type="button" class="btn muted remove-guardian">삭제</button>
        `;
        list.appendChild(row);
        bindGuardianRow(row);
    });
}
</script>
</body>
</html>
