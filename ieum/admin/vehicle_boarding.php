<?php
$sub_menu = '950182';
require_once './_common.php';
require_once IEUM_PATH . '/lib/sms_queue.php';

$g5['title'] = '아이이음 차량 탑승 확인';
$driver_session = isset($_SESSION['ieum_vehicle_driver']) && is_array($_SESSION['ieum_vehicle_driver']) ? $_SESSION['ieum_vehicle_driver'] : array();
$is_driver_mode = false;
if (!empty($driver_session['academy_id']) && !empty($driver_session['route_id']) && !empty($driver_session['expires_at']) && strtotime($driver_session['expires_at']) >= strtotime(G5_TIME_YMDHIS)) {
    $is_driver_mode = true;
    $academy = sql_fetch("
        select *
          from " . IEUM_ACADEMY_TABLE . "
         where academy_id = '" . (int) $driver_session['academy_id'] . "'
           and is_active = 1
         limit 1
    ", false);
    if (empty($academy['academy_id'])) {
        unset($_SESSION['ieum_vehicle_driver']);
        goto_url(IEUM_URL . '/admin/vehicle_driver_login.php');
    }
} else {
    if (!empty($driver_session)) {
        unset($_SESSION['ieum_vehicle_driver']);
    }
    $academy = ieum_require_academy_page();
}
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

$journal_date = isset($_REQUEST['journal_date']) ? preg_replace('/[^0-9-]/', '', trim($_REQUEST['journal_date'])) : G5_TIME_YMD;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journal_date)) {
    $journal_date = G5_TIME_YMD;
}
$ride_type = isset($_REQUEST['ride_type']) ? preg_replace('/[^a-z]/', '', trim($_REQUEST['ride_type'])) : 'pickup';
if ($ride_type !== 'pickup' && $ride_type !== 'dropoff') {
    $ride_type = 'pickup';
}
$route_id = isset($_REQUEST['route_id']) ? (int) $_REQUEST['route_id'] : 0;
$vehicle_label = isset($_REQUEST['vehicle_label']) ? trim($_REQUEST['vehicle_label']) : '';
if ($is_driver_mode) {
    $route_id = (int) $driver_session['route_id'];
    $vehicle_label = isset($driver_session['vehicle_label']) ? (string) $driver_session['vehicle_label'] : '';
}
$weekday_keys = array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
$weekday = $weekday_keys[(int) date('w', strtotime($journal_date))];
$status_labels = array(
    'boarded' => '탑승',
    'missed' => '미탑승',
    'called' => '보호자 통화',
    'self' => '개별 이동',
);

function ieum_boarding_grade_label($value)
{
    $labels = array(
        'kindergarten' => '유치부',
        'elementary_1' => '초등/1학년',
        'elementary_2' => '초등/2학년',
        'elementary_3' => '초등/3학년',
        'elementary_4' => '초등/4학년',
        'elementary_5' => '초등/5학년',
        'elementary_6' => '초등/6학년',
        'middle_1' => '중등/1학년',
        'middle_2' => '중등/2학년',
        'middle_3' => '중등/3학년',
        'high_1' => '고등/1학년',
        'high_2' => '고등/2학년',
        'high_3' => '고등/3학년',
        'adult' => '성인',
    );

    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_boarding_student_photo_url($path)
{
    $path = trim((string) $path);
    return $path === '' ? '' : G5_URL . '/' . ltrim($path, '/');
}

function ieum_boarding_ensure_stop_location_columns()
{
    $columns = array(
        'stop_address' => "alter table " . IEUM_VEHICLE_STOP_TABLE . " add stop_address varchar(160) not null default '' after stop_name",
        'map_lat' => "alter table " . IEUM_VEHICLE_STOP_TABLE . " add map_lat decimal(10,7) null after stop_address",
        'map_lng' => "alter table " . IEUM_VEHICLE_STOP_TABLE . " add map_lng decimal(10,7) null after map_lat",
        'map_url' => "alter table " . IEUM_VEHICLE_STOP_TABLE . " add map_url varchar(255) not null default '' after map_lng",
    );
    foreach ($columns as $column => $sql) {
        $exists = sql_fetch("show columns from " . IEUM_VEHICLE_STOP_TABLE . " like '" . sql_escape_string($column) . "'", false);
        if (empty($exists['Field'])) {
            sql_query($sql, false);
        }
    }
}

function ieum_boarding_stop_map_href($row)
{
    $map_url = isset($row['map_url']) ? trim($row['map_url']) : '';
    if ($map_url !== '') {
        return $map_url;
    }
    $query = isset($row['stop_address']) && trim($row['stop_address']) !== '' ? trim($row['stop_address']) : (isset($row['stop_name']) ? trim($row['stop_name']) : '');
    return $query !== '' ? 'https://map.naver.com/v5/search/' . rawurlencode($query) : '';
}

ieum_boarding_ensure_stop_location_columns();

function ieum_boarding_ensure_driver_tables()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    sql_query("
        create table if not exists " . IEUM_VEHICLE_RUN_TABLE . " (
            run_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            journal_date date not null,
            ride_type varchar(20) not null,
            route_id int unsigned not null default 0,
            vehicle_label varchar(50) not null default '',
            driver_member_id varchar(50) not null default '',
            status varchar(20) not null default 'active',
            started_at datetime not null,
            ended_at datetime null,
            last_lat decimal(10,7) null,
            last_lng decimal(10,7) null,
            last_location_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (run_id),
            key idx_active_run (academy_id, journal_date, ride_type, route_id, vehicle_label, status),
            key idx_driver_day (academy_id, journal_date, driver_member_id)
        ) engine={$engine} default charset={$charset}
    ", false);

    sql_query("
        create table if not exists " . IEUM_VEHICLE_LOCATION_TABLE . " (
            location_id int unsigned not null auto_increment,
            run_id int unsigned not null,
            academy_id int unsigned not null,
            journal_date date not null,
            ride_type varchar(20) not null,
            route_id int unsigned not null default 0,
            vehicle_label varchar(50) not null default '',
            lat decimal(10,7) not null,
            lng decimal(10,7) not null,
            accuracy decimal(10,2) null,
            recorded_at datetime not null,
            created_at datetime not null,
            primary key (location_id),
            key idx_run_time (run_id, recorded_at),
            key idx_academy_day (academy_id, journal_date, vehicle_label, ride_type)
        ) engine={$engine} default charset={$charset}
    ", false);
}

function ieum_boarding_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label)
{
    $academy_id = (int) $academy_id;
    $route_id = (int) $route_id;
    $date_sql = sql_escape_string($journal_date);
    $ride_type_sql = sql_escape_string($ride_type);
    $vehicle_label_sql = sql_escape_string($vehicle_label);

    return sql_fetch("
        select *
          from " . IEUM_VEHICLE_RUN_TABLE . "
         where academy_id = '{$academy_id}'
           and journal_date = '{$date_sql}'
           and ride_type = '{$ride_type_sql}'
           and route_id = '{$route_id}'
           and vehicle_label = '{$vehicle_label_sql}'
           and status = 'active'
      order by run_id desc
         limit 1
    ", false);
}

function ieum_boarding_run_label($ride_type, $vehicle_label, $route_id)
{
    $parts = array();
    $parts[] = $ride_type === 'dropoff' ? '하원' : '등원';
    if ($vehicle_label !== '') {
        $parts[] = $vehicle_label;
    }
    if ((int) $route_id > 0) {
        $parts[] = '선택 노선';
    }
    return implode(' · ', $parts);
}

ieum_boarding_ensure_driver_tables();

function ieum_create_vehicle_alert_queue($academy_id, $vehicle, $status_label, $note)
{
    return ieum_create_vehicle_alert_sms_queue((int) $academy_id, $vehicle, $status_label, $note, G5_TIME_YMD);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : 'save_status';
        $student_vehicle_id = isset($_POST['student_vehicle_id']) ? (int) $_POST['student_vehicle_id'] : 0;
        $status = isset($_POST['status']) ? preg_replace('/[^a-z]/', '', trim($_POST['status'])) : '';
        $note = isset($_POST['note']) ? trim($_POST['note']) : '';
        if ($action === 'bulk_boarded') {
            $checked_by_value = $is_driver_mode ? ('driver:' . (isset($driver_session['driver_name']) && $driver_session['driver_name'] !== '' ? $driver_session['driver_name'] : $driver_session['route_id'])) : (isset($member['mb_id']) ? $member['mb_id'] : '');
            $checked_by_sql = sql_escape_string($checked_by_value);
            $bulk_where = " sv.academy_id = '{$academy_id}' and sv.is_active = 1 and sv.ride_type = '" . sql_escape_string($ride_type) . "' and st.is_active = 1 and s.is_active = 1 and (sv.ride_days = '' or find_in_set('" . sql_escape_string($weekday) . "', sv.ride_days)) ";
            if ($route_id) {
                $bulk_where .= " and sv.route_id = '{$route_id}' ";
            }
            if ($vehicle_label !== '') {
                $bulk_where .= " and r.vehicle_label = '" . sql_escape_string($vehicle_label) . "' ";
            }
            $bulk_rows = sql_query("
                select sv.student_vehicle_id, sv.student_id, sv.ride_type, sv.route_id, sv.stop_id
                  from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
                  join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
                  join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
             left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
             left join " . IEUM_VEHICLE_BOARDING_TABLE . " bl on bl.academy_id = sv.academy_id and bl.student_vehicle_id = sv.student_vehicle_id and bl.journal_date = '" . sql_escape_string($journal_date) . "'
                 where {$bulk_where}
                   and bl.log_id is null
            ", false);
            $bulk_count = 0;
            while ($bulk = sql_fetch_array($bulk_rows)) {
                sql_query("
                    insert into " . IEUM_VEHICLE_BOARDING_TABLE . "
                        set academy_id = '{$academy_id}',
                            journal_date = '" . sql_escape_string($journal_date) . "',
                            student_vehicle_id = '" . (int) $bulk['student_vehicle_id'] . "',
                            student_id = '" . (int) $bulk['student_id'] . "',
                            ride_type = '" . sql_escape_string($bulk['ride_type']) . "',
                            route_id = '" . (int) $bulk['route_id'] . "',
                            stop_id = '" . (int) $bulk['stop_id'] . "',
                            status = 'boarded',
                            note = '',
                            checked_by = '{$checked_by_sql}',
                            checked_at = '" . G5_TIME_YMDHIS . "',
                            resolved_by = '',
                            resolved_at = null,
                            created_at = '" . G5_TIME_YMDHIS . "'
                    on duplicate key update
                            status = 'boarded',
                            note = '',
                            checked_by = '{$checked_by_sql}',
                            checked_at = '" . G5_TIME_YMDHIS . "',
                            resolved_by = '',
                            resolved_at = null,
                            updated_at = '" . G5_TIME_YMDHIS . "'
                ");
                $bulk_count++;
            }
            $message = '미확인 원생 ' . number_format($bulk_count) . '명을 탑승 처리했습니다.';
        } elseif ($action === 'start_run') {
            if ($route_id <= 0 && $vehicle_label === '') {
                $error = '운행을 시작하려면 호차 또는 노선을 먼저 선택해 주세요.';
            } else {
                $member_id_value = $is_driver_mode ? ('driver:' . (isset($driver_session['driver_name']) && $driver_session['driver_name'] !== '' ? $driver_session['driver_name'] : $driver_session['route_id'])) : (isset($member['mb_id']) ? $member['mb_id'] : '');
                $member_id_sql = sql_escape_string($member_id_value);
                $date_sql = sql_escape_string($journal_date);
                $ride_type_sql = sql_escape_string($ride_type);
                $vehicle_label_sql = sql_escape_string($vehicle_label);
                $active_run = ieum_boarding_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label);
                if (!isset($active_run['run_id'])) {
                    sql_query("
                        insert into " . IEUM_VEHICLE_RUN_TABLE . "
                            set academy_id = '{$academy_id}',
                                journal_date = '{$date_sql}',
                                ride_type = '{$ride_type_sql}',
                                route_id = '{$route_id}',
                                vehicle_label = '{$vehicle_label_sql}',
                                driver_member_id = '{$member_id_sql}',
                                status = 'active',
                                started_at = '" . G5_TIME_YMDHIS . "',
                                created_at = '" . G5_TIME_YMDHIS . "'
                    ");
                }
                $message = '차량 운행을 시작했습니다.';
            }
        } elseif ($action === 'end_run') {
            $active_run = ieum_boarding_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label);
            if (!isset($active_run['run_id'])) {
                $error = '종료할 운행 기록이 없습니다.';
            } else {
                sql_query("
                    update " . IEUM_VEHICLE_RUN_TABLE . "
                       set status = 'ended',
                           ended_at = '" . G5_TIME_YMDHIS . "',
                           updated_at = '" . G5_TIME_YMDHIS . "'
                     where run_id = '" . (int) $active_run['run_id'] . "'
                       and academy_id = '{$academy_id}'
                ");
                $message = '차량 운행을 종료했습니다.';
            }
        } elseif ($action === 'resolve') {
            $resolved_by_sql = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
            sql_query("
                update " . IEUM_VEHICLE_BOARDING_TABLE . "
                   set resolved_by = '{$resolved_by_sql}',
                       resolved_at = '" . G5_TIME_YMDHIS . "',
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
                   and journal_date = '" . sql_escape_string($journal_date) . "'
                   and student_vehicle_id = '{$student_vehicle_id}'
            ");
            $message = '차량 특이사항을 처리완료했습니다.';
        } elseif (!isset($status_labels[$status])) {
            $error = '탑승 상태를 선택하세요.';
        } else {
            $vehicle = sql_fetch("
                select sv.*, s.student_name, r.route_name, r.vehicle_label
                  from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
                  join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
             left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
                 where sv.academy_id = '{$academy_id}'
                   and sv.student_vehicle_id = '{$student_vehicle_id}'
                   and sv.is_active = 1
                 limit 1
            ", false);
            if (!isset($vehicle['student_vehicle_id'])) {
                $error = '차량 배정 정보를 찾을 수 없습니다.';
            } else {
                $status_sql = sql_escape_string($status);
                $note_sql = sql_escape_string($note);
                $checked_by_value = $is_driver_mode ? ('driver:' . (isset($driver_session['driver_name']) && $driver_session['driver_name'] !== '' ? $driver_session['driver_name'] : $driver_session['route_id'])) : (isset($member['mb_id']) ? $member['mb_id'] : '');
                $checked_by_sql = sql_escape_string($checked_by_value);
                $ride_type_sql = sql_escape_string($vehicle['ride_type']);
                $before = sql_fetch("
                    select status, note
                      from " . IEUM_VEHICLE_BOARDING_TABLE . "
                     where academy_id = '{$academy_id}'
                       and journal_date = '" . sql_escape_string($journal_date) . "'
                       and student_vehicle_id = '{$student_vehicle_id}'
                     limit 1
                ", false);
                sql_query("
                    insert into " . IEUM_VEHICLE_BOARDING_TABLE . "
                        set academy_id = '{$academy_id}',
                            journal_date = '" . sql_escape_string($journal_date) . "',
                            student_vehicle_id = '{$student_vehicle_id}',
                            student_id = '" . (int) $vehicle['student_id'] . "',
                            ride_type = '{$ride_type_sql}',
                            route_id = '" . (int) $vehicle['route_id'] . "',
                            stop_id = '" . (int) $vehicle['stop_id'] . "',
                            status = '{$status_sql}',
                            note = '{$note_sql}',
                            checked_by = '{$checked_by_sql}',
                            checked_at = '" . G5_TIME_YMDHIS . "',
                            resolved_by = '',
                            resolved_at = null,
                            created_at = '" . G5_TIME_YMDHIS . "'
                    on duplicate key update
                            status = '{$status_sql}',
                            note = '{$note_sql}',
                            checked_by = '{$checked_by_sql}',
                            checked_at = '" . G5_TIME_YMDHIS . "',
                            resolved_by = '',
                            resolved_at = null,
                            updated_at = '" . G5_TIME_YMDHIS . "'
                ");
                $needs_alert = ($status === 'missed' || $status === 'called' || trim((string) $note) !== '');
                $is_new_alert = $needs_alert && (!isset($before['status']) || $before['status'] !== $status || $before['note'] !== $note);
                if ($is_new_alert) {
                    ieum_create_vehicle_alert_queue($academy_id, $vehicle, $status_labels[$status], $note);
                } elseif (!$needs_alert && isset($before['status']) && ($before['status'] === 'missed' || $before['status'] === 'called' || trim((string) $before['note']) !== '')) {
                    ieum_cancel_pending_vehicle_alert_sms_queue($academy_id, $vehicle, $journal_date);
                }
                $message = '탑승 확인이 저장되었습니다.';
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$weekday_sql = sql_escape_string($weekday);
$where = " sv.academy_id = '{$academy_id}' and sv.is_active = 1 and sv.ride_type = '" . sql_escape_string($ride_type) . "' and st.is_active = 1 and s.is_active = 1 and (sv.ride_days = '' or find_in_set('{$weekday_sql}', sv.ride_days)) ";
if ($route_id) {
    $where .= " and sv.route_id = '{$route_id}' ";
}
if ($vehicle_label !== '') {
    $where .= " and r.vehicle_label = '" . sql_escape_string($vehicle_label) . "' ";
}

$vehicle_labels = sql_query("
    select distinct vehicle_label
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and vehicle_label <> ''
  order by vehicle_label asc
", false);
$vehicle_label_rows = array();
while ($vehicle = sql_fetch_array($vehicle_labels)) {
    $vehicle_label_rows[] = $vehicle;
}

$route_where = " academy_id = '{$academy_id}' and is_active = 1 ";
if ($vehicle_label !== '') {
    $route_where .= " and vehicle_label = '" . sql_escape_string($vehicle_label) . "' ";
}
$routes = sql_query("
    select *
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where {$route_where}
  order by sort_order asc, route_name asc
", false);

$rows = sql_query("
    select sv.student_vehicle_id, sv.ride_type, sv.place_name, sv.contact_phone, sv.memo as vehicle_memo,
           s.student_name, s.grade_group, s.student_photo, s.memo as student_memo,
           c.class_name, c.start_time as class_start_time,
            st.stop_name, st.stop_address, st.map_url, st.map_lat, st.map_lng, st.stop_time,
           r.route_name, r.vehicle_label, r.driver_name, r.driver_phone,
           bl.status as boarding_status, bl.note as boarding_note, bl.checked_at, bl.resolved_at
      from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
 left join " . IEUM_VEHICLE_BOARDING_TABLE . " bl on bl.academy_id = sv.academy_id and bl.student_vehicle_id = sv.student_vehicle_id and bl.journal_date = '" . sql_escape_string($journal_date) . "'
     where {$where}
  order by r.sort_order asc, st.sort_order asc, st.stop_time asc, st.stop_name asc, s.student_name asc
", false);

$boarding_rows = array();
$boarding_summary = array(
    'total' => 0,
    'boarded' => 0,
    'missed' => 0,
    'called' => 0,
    'self' => 0,
    'unchecked' => 0,
    'resolved' => 0,
);
while ($row = sql_fetch_array($rows)) {
    $boarding_rows[] = $row;
    $boarding_summary['total']++;
    $status_key = isset($row['boarding_status']) ? $row['boarding_status'] : '';
    if ($status_key !== '' && isset($boarding_summary[$status_key])) {
        $boarding_summary[$status_key]++;
    } else {
        $boarding_summary['unchecked']++;
    }
    if (!empty($row['resolved_at'])) {
        $boarding_summary['resolved']++;
    }
}
$active_run = ieum_boarding_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label);
$run_is_active = isset($active_run['run_id']);
$run_can_start = ($route_id > 0 || $vehicle_label !== '');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1900px;margin:18px auto;padding:0 14px}h1{margin:0 0 6px;font-size:24px}.meta{color:#667085;margin-bottom:14px}.filter{display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:8px;margin-bottom:12px}.filter input,.filter select,.note{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:10px;font-size:15px}.btn{border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:10px 12px;font-weight:900;cursor:pointer}.btn:disabled{background:#e5e7eb!important;border-color:#d1d5db!important;color:#94a3b8!important;cursor:not-allowed}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.danger{background:#b42318;border-color:#b42318;color:#fff}.resolve{background:#0f766e;border-color:#0f766e;color:#fff}.notice{padding:10px 12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.run-panel{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:12px;margin-bottom:12px;box-shadow:0 4px 12px rgba(15,23,42,.05)}.run-title{font-size:17px;font-weight:900}.run-title small{display:block;margin-top:3px;color:#667085;font-size:12px}.run-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.run-state{display:inline-flex;align-items:center;border-radius:999px;background:#eef2f7;color:#344054;padding:5px 9px;font-size:12px;font-weight:900}.run-state.active{background:#e8f7ee;color:#176b2c}.location-state{color:#667085;font-size:12px;font-weight:800}.quick-vehicles{display:flex;gap:8px;overflow-x:auto;margin:0 0 12px;padding-bottom:2px}.quick-vehicles a{flex:0 0 auto;display:inline-flex;align-items:center;min-height:34px;border:1px solid #d9dee7;border-radius:999px;background:#fff;color:#344054;text-decoration:none;padding:7px 12px;font-weight:900}.quick-vehicles a.active{background:#1769c2;border-color:#1769c2;color:#fff}.summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-bottom:12px}.summary-card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:10px}.summary-card span{display:block;color:#667085;font-size:12px;font-weight:800}.summary-card strong{display:block;margin-top:3px;font-size:22px}.route-head{margin:16px 0 8px;padding:11px 12px;background:#101a42;color:#fff;border-radius:10px;font-weight:900}.route-head small{display:block;margin-top:3px;color:#cbd5e1;font-size:12px}.stop{margin:10px 0 8px;padding:8px 10px;background:#e8edf5;color:#111827;border-radius:8px;font-weight:900}.card{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:12px;margin-bottom:10px;box-shadow:0 4px 12px rgba(15,23,42,.05)}.card.resolved{opacity:.72}.student{display:flex;justify-content:space-between;gap:10px;font-size:18px;font-weight:900}.student small{font-size:13px;color:#667085}.info{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:6px;color:#344054;font-size:14px}.phone{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#eef5ff;color:#1769c2;padding:6px 10px;font-weight:900;white-space:nowrap;text-decoration:none}.memo{margin-top:6px;color:#667085;font-size:13px}.quick-notes{display:flex;gap:6px;overflow-x:auto;margin:9px 0 0;padding-bottom:2px}.quick-note{flex:0 0 auto;border:1px solid #d9dee7;border-radius:999px;background:#fff;padding:6px 9px;font-size:12px;font-weight:900;cursor:pointer}.actions{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin-top:10px}.actions button{min-height:42px}.status{display:inline-flex;margin-top:8px;padding:5px 8px;border-radius:999px;background:#eef2f7;color:#344054;font-size:12px;font-weight:900}.status.boarded{background:#e8f7ee;color:#176b2c}.status.missed{background:#fdecec;color:#a4262c}.status.called{background:#fff4df;color:#915c00}.status.self{background:#eef5ff;color:#1769c2}.empty{padding:28px;text-align:center;color:#667085;background:#fff;border:1px solid #d9dee7;border-radius:10px}@media(max-width:760px){.filter{grid-template-columns:1fr 1fr}.filter .primary{grid-column:1/-1}.run-panel{grid-template-columns:1fr}.run-actions{justify-content:stretch}.run-actions .btn{flex:1}.summary{grid-template-columns:repeat(3,minmax(0,1fr))}.info{grid-template-columns:1fr}.actions{grid-template-columns:1fr 1fr}.top{display:none}.wrap{margin-top:12px}}
.stop .map-link{display:inline-flex;margin-left:6px;padding:2px 7px;border-radius:999px;background:#dbeafe;color:#1769c2;text-decoration:none;font-size:12px;font-weight:900}.stop-address{display:block;margin-top:3px;color:#667085;font-size:12px;font-weight:700}
body.driver-mode{background:#eef3f9}.driver-mode .wrap{max-width:1900px;margin:0 auto;padding:10px}.driver-mode h1{font-size:22px}.driver-mode .meta{font-size:13px;margin-bottom:8px}.driver-mode .run-panel{position:sticky;top:0;z-index:5;border-radius:14px;padding:10px}.driver-mode .run-title{font-size:15px}.driver-mode .run-actions .btn{min-height:38px;padding:8px 10px}.driver-mode .summary{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.driver-mode .summary-card{padding:8px;text-align:center}.driver-mode .summary-card strong{font-size:20px}.driver-bulk{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:8px 0 10px}.driver-bulk .btn{min-height:44px}.driver-mode .route-head{clear:both;margin:12px 0 6px;border-radius:10px;padding:9px 10px}.driver-mode .stop{clear:both;margin:8px 0 7px;padding:7px 9px}.driver-mode .card.driver-card{float:left;width:calc(50% - 6px);vertical-align:top;margin:0 3px 8px}.driver-card{padding:8px;min-height:220px}.driver-card-form{height:100%;display:grid;grid-template-rows:auto 1fr auto;gap:7px}.driver-main{display:grid;grid-template-columns:58px 1fr;gap:8px;align-items:center}.driver-photo{width:58px;height:70px;border:0;border-radius:8px;overflow:hidden;background:#dbe4f0;color:#334155;font-weight:900;display:flex;align-items:center;justify-content:center;padding:0;cursor:pointer}.driver-photo img{width:100%;height:100%;object-fit:cover}.driver-name{font-size:16px;font-weight:950;line-height:1.15;word-break:keep-all}.driver-sub{margin-top:3px;color:#667085;font-size:12px;line-height:1.3}.driver-status{display:inline-flex;margin-top:5px;padding:3px 7px;border-radius:999px;background:#eef2f7;color:#344054;font-size:11px;font-weight:900}.driver-status.boarded{background:#e8f7ee;color:#176b2c}.driver-status.missed{background:#fdecec;color:#a4262c}.driver-status.called{background:#fff4df;color:#915c00}.driver-status.self{background:#eef5ff;color:#1769c2}.driver-note-row{display:none}.driver-card.note-open .driver-note-row{display:block}.driver-note-row .note{font-size:13px;padding:8px}.driver-note-row .quick-notes{margin-top:6px}.driver-actions{display:grid;grid-template-columns:1fr 1fr;gap:6px}.driver-actions .btn{min-height:40px;padding:8px 6px;font-size:13px}.driver-actions .boarded{grid-column:1/-1;background:#1769c2;border-color:#1769c2;color:#fff}.driver-actions .missed{background:#fff;border-color:#f5b4b4;color:#a4262c}.driver-actions .called{background:#fff8eb;border-color:#f4c26b;color:#7a4b00}.driver-phone{display:inline-flex;margin-top:4px;color:#1769c2;font-size:12px;font-weight:900;text-decoration:none}.driver-resolve{margin-top:6px}.driver-resolve .btn{width:100%;min-height:36px}.driver-mode .empty{clear:both;padding:20px}.driver-mode .quick-vehicles{margin-bottom:8px}@media(max-width:380px){.driver-mode .wrap{padding:8px}.driver-mode .card.driver-card{width:calc(50% - 5px);margin:0 2px 7px}.driver-card{padding:7px}.driver-main{grid-template-columns:52px 1fr}.driver-photo{width:52px;height:64px}.driver-name{font-size:15px}.driver-actions .btn{font-size:12px}}
.driver-mode .run-panel{grid-template-columns:minmax(0,1fr) auto;gap:8px}.driver-mode .run-title small{line-height:1.35}.driver-mode .location-state{margin-top:3px;color:#475467}.driver-mode .quick-note{padding:6px 8px;font-size:12px}.driver-note-row .quick-notes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));overflow:visible}.driver-note-row .quick-note{width:100%;white-space:nowrap}.driver-mode .card.driver-card.note-open{min-height:0}.driver-mode .driver-actions .btn{border-radius:8px}.driver-mode .driver-actions .boarded{font-size:14px}.driver-card.driver-boarded{border-color:#8fd3a8;background:#f7fff9}.driver-card.driver-missed{border-color:#f0a8a8;background:#fffafa}.driver-card.driver-called{border-color:#f1c46b;background:#fffaf0}.driver-card.driver-boarded .driver-photo{box-shadow:0 0 0 3px #bbf7d0}.driver-card.driver-missed .driver-photo{box-shadow:0 0 0 3px #fecaca}.driver-card.driver-called .driver-photo{box-shadow:0 0 0 3px #fed7aa}.driver-card.driver-boarded .driver-actions .boarded{background:#15803d;border-color:#15803d}.driver-mini-hint{display:block;margin-top:3px;color:#98a2b3;font-size:11px;font-weight:800}@media(max-width:520px){.driver-mode .wrap{max-width:none}.driver-mode h1{font-size:20px}.driver-mode .run-panel{position:static}.driver-mode .summary-card span{font-size:11px}.driver-mode .summary-card strong{font-size:18px}.driver-mode .card.driver-card{width:calc(50% - 5px);margin:0 2px 7px}.driver-card{min-height:210px}.driver-actions .btn{min-height:38px}}@media(max-width:340px){.driver-main{grid-template-columns:48px 1fr}.driver-photo{width:48px;height:60px}.driver-sub{font-size:11px}.driver-name{font-size:14px}.driver-actions .btn{font-size:12px;padding-left:4px;padding-right:4px}}
</style>
<style>
body.vehicle-boarding-page-tune .wrap{max-width:none!important;margin:0 86px 0 248px!important;padding:84px 28px 42px!important}
body.vehicle-boarding-page-tune .top,
body.vehicle-boarding-page-tune .ieum-subnav-wrap{display:none!important}
body.vehicle-boarding-page-tune .card,
body.vehicle-boarding-page-tune .run-panel,
body.vehicle-boarding-page-tune .summary-card{border-radius:16px;border-color:#dbe3ef;box-shadow:0 12px 28px rgba(15,23,42,.06)}
body.vehicle-boarding-page-tune .route-head{border-radius:14px}
@media(max-width:1100px){body.vehicle-boarding-page-tune .wrap{margin:0 74px 0 0!important;padding:84px 18px 32px!important}}
body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    --ieum-shell-top:#fff;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune .side-brand{height:144px!important;padding:0 28px!important;align-items:center!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important}
body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune .side-search,
body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune .ieum-right-rail{display:none!important}
.vehicle-boarding-page-tune .side-nav{padding:0 14px 22px!important}
.vehicle-boarding-page-tune .side-nav a{border-radius:8px!important;color:#0f172a!important}
.vehicle-boarding-page-tune .side-nav a.active{background:#f1f5f9!important;color:#0f172a!important}
.vehicle-boarding-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    height:64px!important;
    background:#fff!important;
    border-bottom:1px solid #eef2f7!important;
    color:#0f172a!important;
    box-shadow:none!important;
}
.vehicle-boarding-page-tune .ieum-shell-link,
.vehicle-boarding-page-tune .dashboard-shell-support-link{color:#0f172a!important;text-decoration:none!important;font-weight:800!important}
.vehicle-boarding-page-tune .ieum-shell-link::before{display:none!important}
.vehicle-boarding-page-tune .ieum-shell-meta{color:#0f172a!important}
.vehicle-boarding-page-tune .dashboard-shell-meta-inner{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.vehicle-boarding-page-tune .dashboard-shell-divider,
.vehicle-boarding-page-tune .dashboard-shell-help-dot{color:#94a3b8}
body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune .wrap{
    max-width:none!important;
    width:auto!important;
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune{--ieum-side-width:0px}
    .vehicle-boarding-page-tune .ieum-shell-top{left:0!important}
    body.ieum-side-layout.ieum-dashboard-page.vehicle-boarding-page-tune .wrap{margin-left:0!important;padding:88px 16px 32px!important}
}
</style>
</head>
<body class="<?php echo $is_driver_mode ? 'driver-mode' : 'ieum-side-layout ieum-dashboard-page vehicle-boarding-page-tune'; ?>">
<?php if (!$is_driver_mode) { ?>
<?php echo ieum_admin_header('boarding', 'side'); ?>
<?php } ?>
<main class="wrap">
    <h1>차량 탑승 확인</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($journal_date); ?></div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
    <?php if ($is_driver_mode) { ?>
    <nav class="quick-vehicles" aria-label="기사님 접속">
        <a class="active" href="#"><?php echo get_text(trim(($vehicle_label !== '' ? $vehicle_label . ' · ' : '') . (isset($driver_session['route_name']) ? $driver_session['route_name'] : '노선'))); ?></a>
        <a href="<?php echo IEUM_URL; ?>/admin/vehicle_driver_login.php?logout=1">접속 종료</a>
    </nav>
    <?php } ?>
    <?php if (!$is_driver_mode) { ?>
    <form method="get" class="filter">
        <input type="date" name="journal_date" value="<?php echo get_text($journal_date); ?>">
        <select name="ride_type">
            <option value="pickup" <?php echo get_selected($ride_type, 'pickup'); ?>>등원</option>
            <option value="dropoff" <?php echo get_selected($ride_type, 'dropoff'); ?>>하원</option>
        </select>
        <select name="vehicle_label" onchange="this.form.route_id.value='0'; this.form.submit();">
            <option value="">전체 호차</option>
            <?php foreach ($vehicle_label_rows as $vehicle) { ?>
            <option value="<?php echo get_text($vehicle['vehicle_label']); ?>" <?php echo get_selected($vehicle_label, $vehicle['vehicle_label']); ?>><?php echo get_text($vehicle['vehicle_label']); ?></option>
            <?php } ?>
        </select>
        <select name="route_id">
            <option value="0">전체 노선</option>
            <?php while ($route = sql_fetch_array($routes)) { ?>
            <option value="<?php echo (int) $route['route_id']; ?>" <?php echo get_selected($route_id, (int) $route['route_id']); ?>><?php echo get_text(trim(($route['vehicle_label'] ? $route['vehicle_label'] . ' · ' : '') . $route['route_name'])); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn primary">조회</button>
    </form>
    <?php } ?>
    <section class="run-panel" data-run-active="<?php echo $run_is_active ? '1' : '0'; ?>" data-location-url="<?php echo IEUM_URL; ?>/admin/vehicle_driver_location.php">
        <div>
            <div class="run-title">
                <?php echo get_text(ieum_boarding_run_label($ride_type, $vehicle_label, $route_id)); ?>
                <span class="run-state <?php echo $run_is_active ? 'active' : ''; ?>"><?php echo $run_is_active ? '운행 중' : '운행 대기'; ?></span>
                <small>
                    <?php if ($run_is_active) { ?>
                    시작 <?php echo get_text(substr($active_run['started_at'], 11, 5)); ?><?php echo !empty($active_run['last_location_at']) ? ' · 위치 ' . get_text(substr($active_run['last_location_at'], 11, 5)) : ' · 위치 대기'; ?>
                    <?php } elseif ($run_can_start) { ?>
                    운행 시작을 누르면 위치 기록이 시작됩니다. 권한 요청이 나오면 허용해 주세요.
                    <?php } else { ?>
                    먼저 호차 또는 노선을 선택하면 운행을 시작할 수 있습니다.
                    <?php } ?>
                </small>
            </div>
            <div class="location-state" id="locationState">운행 시작 전입니다.</div>
        </div>
        <form method="post" class="run-actions">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
            <input type="hidden" name="ride_type" value="<?php echo get_text($ride_type); ?>">
            <input type="hidden" name="vehicle_label" value="<?php echo get_text($vehicle_label); ?>">
            <input type="hidden" name="route_id" value="<?php echo (int) $route_id; ?>">
            <?php if ($run_is_active) { ?>
            <?php if (!$is_driver_mode) { ?>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_monitor.php?journal_date=<?php echo get_text($journal_date); ?>">운행 관제</a>
            <?php } ?>
            <button class="btn danger" type="submit" name="action" value="end_run">운행 종료</button>
            <?php } else { ?>
            <?php if (!$is_driver_mode) { ?>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_monitor.php?journal_date=<?php echo get_text($journal_date); ?>">운행 관제</a>
            <?php } ?>
            <button class="btn primary" type="submit" name="action" value="start_run" <?php echo $run_can_start ? '' : 'disabled'; ?>>운행 시작</button>
            <?php } ?>
        </form>
    </section>
    <?php if ($is_driver_mode) { ?>
    <form method="post" class="driver-bulk">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
        <input type="hidden" name="ride_type" value="<?php echo get_text($ride_type); ?>">
        <input type="hidden" name="vehicle_label" value="<?php echo get_text($vehicle_label); ?>">
        <input type="hidden" name="route_id" value="<?php echo (int) $route_id; ?>">
        <button class="btn primary" type="submit" name="action" value="bulk_boarded" <?php echo $boarding_summary['unchecked'] > 0 ? '' : 'disabled'; ?>>미확인 전원 탑승</button>
        <?php $driver_phone_link = isset($driver_session['driver_phone']) ? preg_replace('/[^0-9+]/', '', $driver_session['driver_phone']) : ''; ?>
        <?php if ($driver_phone_link !== '') { ?>
        <a class="btn" href="tel:<?php echo get_text($driver_phone_link); ?>">기사 연락</a>
        <?php } else { ?>
        <button class="btn" type="button" disabled>기사 연락처 없음</button>
        <?php } ?>
    </form>
    <?php } ?>
    <?php if (!$is_driver_mode) { ?>
    <nav class="quick-vehicles" aria-label="호차 빠른 선택">
        <a class="<?php echo $vehicle_label === '' ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php?journal_date=<?php echo get_text($journal_date); ?>&amp;ride_type=<?php echo get_text($ride_type); ?>">전체 호차</a>
        <?php foreach ($vehicle_label_rows as $vehicle) { ?>
        <a class="<?php echo $vehicle_label === $vehicle['vehicle_label'] ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/vehicle_boarding.php?journal_date=<?php echo get_text($journal_date); ?>&amp;ride_type=<?php echo get_text($ride_type); ?>&amp;vehicle_label=<?php echo urlencode($vehicle['vehicle_label']); ?>"><?php echo get_text($vehicle['vehicle_label']); ?></a>
        <?php } ?>
    </nav>
    <?php } ?>
    <section class="summary">
        <article class="summary-card"><span>대상</span><strong><?php echo number_format((int) $boarding_summary['total']); ?></strong></article>
        <article class="summary-card"><span>미확인</span><strong><?php echo number_format((int) $boarding_summary['unchecked']); ?></strong></article>
        <article class="summary-card"><span>탑승</span><strong><?php echo number_format((int) $boarding_summary['boarded']); ?></strong></article>
        <article class="summary-card"><span>미탑승</span><strong><?php echo number_format((int) $boarding_summary['missed']); ?></strong></article>
        <article class="summary-card"><span>통화</span><strong><?php echo number_format((int) $boarding_summary['called']); ?></strong></article>
        <article class="summary-card"><span>개별 이동</span><strong><?php echo number_format((int) $boarding_summary['self']); ?></strong></article>
    </section>
    <?php
    $current_route = '';
    $current_stop = '';
    $has_rows = false;
    foreach ($boarding_rows as $row) {
        $has_rows = true;
        $route_key = trim(($row['vehicle_label'] ?: '') . '|' . ($row['route_name'] ?: ''));
        if ($current_route !== $route_key) {
            $current_route = $route_key;
            $current_stop = '';
            $route_title = trim(($row['vehicle_label'] ?: '차량 미지정') . ' · ' . ($row['route_name'] ?: '노선 미지정'));
            $driver = trim(($row['driver_name'] ?: '') . ' ' . ($row['driver_phone'] ?: ''));
            echo '<div class="route-head">' . get_text($route_title) . ($driver !== '' ? '<small>' . get_text($driver) . '</small>' : '') . '</div>';
        }
        $stop_key = $row['stop_time'] . '|' . $row['stop_name'];
        if ($current_stop !== $stop_key) {
            $current_stop = $stop_key;
            $map_href = ieum_boarding_stop_map_href($row);
            echo '<div class="stop">' . get_text($row['stop_time'] . ' ' . $row['stop_name']);
            if (!empty($row['stop_address'])) {
                echo '<span class="stop-address">' . get_text($row['stop_address']) . '</span>';
            }
            if ($map_href !== '') {
                echo '<a class="map-link" href="' . get_text($map_href) . '" target="_blank" rel="noopener">지도</a>';
            }
            echo '</div>';
        }
        $status = isset($row['boarding_status']) ? $row['boarding_status'] : '';
        $status_label = isset($status_labels[$status]) ? $status_labels[$status] : '미확인';
        $needs_resolve = ($status === 'missed' || $status === 'called' || (isset($row['boarding_note']) && trim($row['boarding_note']) !== '')) && empty($row['resolved_at']);
        $memo = trim(($row['vehicle_memo'] ?: $row['place_name']) . ($row['student_memo'] ? ' / ' . $row['student_memo'] : ''));
        if ($is_driver_mode) {
            $photo_url = ieum_boarding_student_photo_url(isset($row['student_photo']) ? $row['student_photo'] : '');
            $student_initial = function_exists('mb_substr') ? mb_substr($row['student_name'], 0, 1, 'UTF-8') : substr($row['student_name'], 0, 1);
            $class_label = trim(($row['class_name'] ?: '') . ' ' . ($row['class_start_time'] ?: ''));
            $stop_label = trim(($row['stop_time'] ?: '') . ' ' . ($row['stop_name'] ?: ''));
    ?>
    <section class="card driver-card driver-<?php echo get_text($status !== '' ? $status : 'unchecked'); ?> <?php echo empty($row['resolved_at']) ? '' : 'resolved'; ?>">
        <form method="post" class="driver-card-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
            <input type="hidden" name="ride_type" value="<?php echo get_text($ride_type); ?>">
            <input type="hidden" name="vehicle_label" value="<?php echo get_text($vehicle_label); ?>">
            <input type="hidden" name="route_id" value="<?php echo (int) $route_id; ?>">
            <input type="hidden" name="student_vehicle_id" value="<?php echo (int) $row['student_vehicle_id']; ?>">
            <div class="driver-main">
                <button class="driver-photo driver-note-toggle" type="button" aria-label="메모 입력">
                    <?php if ($photo_url !== '') { ?><img src="<?php echo get_text($photo_url); ?>" alt=""><?php } else { ?><?php echo get_text($student_initial ?: '사진'); ?><?php } ?>
                </button>
                <div>
                    <div class="driver-name"><?php echo get_text($row['student_name']); ?></div>
                    <div class="driver-sub"><?php echo get_text(ieum_boarding_grade_label($row['grade_group'])); ?><?php echo $class_label !== '' ? ' · ' . get_text($class_label) : ''; ?></div>
                    <?php if ($stop_label !== '') { ?><div class="driver-sub"><?php echo get_text($stop_label); ?></div><?php } ?>
                    <?php if (trim($row['contact_phone']) !== '') { ?><a class="driver-phone" href="tel:<?php echo get_text(preg_replace('/[^0-9+]/', '', $row['contact_phone'])); ?>"><?php echo get_text($row['contact_phone']); ?></a><?php } ?>
                    <span class="driver-status <?php echo get_text($status); ?>"><?php echo get_text($status_label . ($row['checked_at'] ? ' · ' . substr($row['checked_at'], 11, 5) : '')); ?></span>
                    <span class="driver-mini-hint">사진 터치: 메모</span>
                </div>
            </div>
            <div class="driver-note-row">
                <input class="note" type="text" name="note" value="<?php echo get_text(isset($row['boarding_note']) ? $row['boarding_note'] : ''); ?>" placeholder="메모: 안 보임, 통화 완료, 장소 변경">
                <div class="quick-notes">
                    <button class="quick-note" type="button" data-note="안 보임">안 보임</button>
                    <button class="quick-note" type="button" data-note="보호자 통화 완료">통화 완료</button>
                    <button class="quick-note" type="button" data-note="오늘 결석">결석</button>
                    <button class="quick-note" type="button" data-note="장소 변경">장소 변경</button>
                </div>
                <?php if ($memo !== '') { ?><div class="memo"><?php echo get_text($memo); ?></div><?php } ?>
            </div>
            <div class="driver-actions">
                <button class="btn boarded" type="submit" name="status" value="boarded">탑승</button>
                <button class="btn missed" type="submit" name="status" value="missed">미탑승</button>
                <button class="btn called" type="submit" name="status" value="called">통화</button>
                <button class="btn" type="submit" name="status" value="self">개별</button>
            </div>
        </form>
        <?php if ($needs_resolve) { ?>
        <form method="post" class="driver-resolve">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
            <input type="hidden" name="ride_type" value="<?php echo get_text($ride_type); ?>">
            <input type="hidden" name="vehicle_label" value="<?php echo get_text($vehicle_label); ?>">
            <input type="hidden" name="route_id" value="<?php echo (int) $route_id; ?>">
            <input type="hidden" name="student_vehicle_id" value="<?php echo (int) $row['student_vehicle_id']; ?>">
            <button class="btn resolve" type="submit">관리자 확인 완료</button>
        </form>
        <?php } ?>
    </section>
    <?php
            continue;
        }
    ?>
    <section class="card <?php echo empty($row['resolved_at']) ? '' : 'resolved'; ?>">
        <div class="student"><span><?php echo get_text($row['student_name']); ?></span><small><?php echo get_text(ieum_boarding_grade_label($row['grade_group'])); ?></small></div>
        <div class="info">
            <span><?php echo get_text(trim(($row['class_name'] ?: '') . ' ' . ($row['class_start_time'] ?: ''))); ?></span>
            <?php if (trim($row['contact_phone']) !== '') { ?>
            <a class="phone" href="tel:<?php echo get_text(preg_replace('/[^0-9+]/', '', $row['contact_phone'])); ?>"><?php echo get_text($row['contact_phone']); ?></a>
            <?php } else { ?>
            <span class="phone">연락처 없음</span>
            <?php } ?>
        </div>
        <?php if ($memo !== '') { ?><div class="memo"><?php echo get_text($memo); ?></div><?php } ?>
        <span class="status <?php echo get_text($status); ?>"><?php echo get_text($status_label . ($row['checked_at'] ? ' · ' . substr($row['checked_at'], 11, 5) : '')); ?></span>
        <?php if (!empty($row['resolved_at'])) { ?><span class="status boarded">처리완료 · <?php echo get_text(substr($row['resolved_at'], 11, 5)); ?></span><?php } ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
            <input type="hidden" name="ride_type" value="<?php echo get_text($ride_type); ?>">
            <input type="hidden" name="vehicle_label" value="<?php echo get_text($vehicle_label); ?>">
            <input type="hidden" name="route_id" value="<?php echo (int) $route_id; ?>">
            <input type="hidden" name="student_vehicle_id" value="<?php echo (int) $row['student_vehicle_id']; ?>">
            <input class="note" type="text" name="note" value="<?php echo get_text(isset($row['boarding_note']) ? $row['boarding_note'] : ''); ?>" placeholder="메모: 연락 대기, 다음 차 탑승 등">
            <div class="quick-notes">
                <button class="quick-note" type="button" data-note="안 보임">안 보임</button>
                <button class="quick-note" type="button" data-note="보호자 통화 완료">보호자 통화</button>
                <button class="quick-note" type="button" data-note="오늘 결석">오늘 결석</button>
                <button class="quick-note" type="button" data-note="직접 등원">직접 등원</button>
                <button class="quick-note" type="button" data-note="장소 변경">장소 변경</button>
            </div>
            <div class="actions">
                <button class="btn primary" type="submit" name="status" value="boarded">탑승</button>
                <button class="btn" type="submit" name="status" value="missed">미탑승</button>
                <button class="btn" type="submit" name="status" value="called">보호자 통화</button>
                <button class="btn" type="submit" name="status" value="self">개별 이동</button>
            </div>
        </form>
        <?php if ($needs_resolve) { ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
            <input type="hidden" name="ride_type" value="<?php echo get_text($ride_type); ?>">
            <input type="hidden" name="vehicle_label" value="<?php echo get_text($vehicle_label); ?>">
            <input type="hidden" name="route_id" value="<?php echo (int) $route_id; ?>">
            <input type="hidden" name="student_vehicle_id" value="<?php echo (int) $row['student_vehicle_id']; ?>">
            <button class="btn resolve" type="submit">특이사항 처리완료</button>
        </form>
        <?php } ?>
    </section>
    <?php } ?>
    <?php if (!$has_rows) { ?><div class="empty">오늘 조건에 맞는 차량 이용 원생이 없습니다.</div><?php } ?>
</main>
<?php if (!$is_driver_mode) { ?>
<script>
(function(){
    var rootSelector = '.vehicle-boarding-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) brandText.textContent = <?php echo json_encode($academy['academy_name']); ?>;
    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) homeLink.textContent = '아이이음 교육페이지';
    var meta = document.querySelector(rootSelector + ' .ieum-shell-meta');
    if (meta) {
        var now = new Date();
        var hh = String(now.getHours()).padStart(2, '0');
        var mm = String(now.getMinutes()).padStart(2, '0');
        meta.innerHTML = ''
            + '<span class="dashboard-shell-meta-inner">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php">개발지원센터</a>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-help-group">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#qna">Q&A</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#faq">자주하는 질문</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#contact">문의하기</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#chatbot">AI 챗봇</a>'
            + '</span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span><?php echo get_text($academy['academy_name']); ?></span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-clock">' + hh + ':' + mm + '</span>'
            + '</span>';
    }
})();
</script>
<?php } ?>
<script>
(function () {
    const runPanel = document.querySelector('.run-panel');
    const locationState = document.getElementById('locationState');
    const runActive = runPanel && runPanel.dataset.runActive === '1';
    const locationUrl = runPanel ? runPanel.dataset.locationUrl : '';
    const csrfToken = <?php echo json_encode($csrf_token); ?>;
    const runPayload = {
        journal_date: <?php echo json_encode($journal_date); ?>,
        ride_type: <?php echo json_encode($ride_type); ?>,
        route_id: <?php echo (int) $route_id; ?>,
        vehicle_label: <?php echo json_encode($vehicle_label); ?>
    };

    document.querySelectorAll('.quick-note').forEach((button) => {
        button.addEventListener('click', () => {
            const form = button.closest('form');
            const note = form ? form.querySelector('input[name="note"]') : null;
            if (!note) return;
            note.value = button.dataset.note || '';
            note.focus();
        });
    });

    document.querySelectorAll('.driver-note-toggle').forEach((button) => {
        button.addEventListener('click', () => {
            const card = button.closest('.driver-card');
            if (!card) return;
            document.querySelectorAll('.driver-card.note-open').forEach((openCard) => {
                if (openCard !== card) {
                    openCard.classList.remove('note-open');
                }
            });
            card.classList.toggle('note-open');
            const note = card.querySelector('input[name="note"]');
            if (note && card.classList.contains('note-open')) {
                note.focus();
            }
        });
    });

    function setLocationState(text) {
        if (locationState) {
            locationState.textContent = text;
        }
    }

    function sendLocation(position) {
        if (!locationUrl || !position || !position.coords) return;
        const body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('journal_date', runPayload.journal_date);
        body.set('ride_type', runPayload.ride_type);
        body.set('route_id', String(runPayload.route_id));
        body.set('vehicle_label', runPayload.vehicle_label);
        body.set('lat', String(position.coords.latitude));
        body.set('lng', String(position.coords.longitude));
        body.set('accuracy', String(position.coords.accuracy || ''));
        fetch(locationUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then((response) => response.json()).then((data) => {
            if (data && data.success) {
                setLocationState('현재 위치 저장됨 · ' + (data.recorded_at || ''));
            } else {
                setLocationState((data && data.message) ? data.message : '위치 저장 실패');
            }
        }).catch(() => {
            setLocationState('위치 저장 연결 오류');
        });
    }

    if (runActive && navigator.geolocation) {
        setLocationState('위치 권한 확인 중입니다.');
        navigator.geolocation.watchPosition(sendLocation, () => {
            setLocationState('위치 권한이 꺼져 있습니다. 브라우저에서 위치를 허용해 주세요.');
        }, {
            enableHighAccuracy: true,
            maximumAge: 15000,
            timeout: 10000
        });
    } else if (runActive) {
        setLocationState('이 기기에서는 위치 기록을 지원하지 않습니다.');
    }
})();
</script>
</body>
</html>
