<?php
require_once './_common.php';

header('Content-Type: application/json; charset=utf-8');

$driver_session = isset($_SESSION['ieum_vehicle_driver']) && is_array($_SESSION['ieum_vehicle_driver']) ? $_SESSION['ieum_vehicle_driver'] : array();
if (!empty($driver_session['academy_id']) && !empty($driver_session['route_id']) && !empty($driver_session['expires_at']) && strtotime($driver_session['expires_at']) >= strtotime(G5_TIME_YMDHIS)) {
    $academy = sql_fetch("
        select *
          from " . IEUM_ACADEMY_TABLE . "
         where academy_id = '" . (int) $driver_session['academy_id'] . "'
           and is_active = 1
         limit 1
    ", false);
    if (empty($academy['academy_id'])) {
        ieum_driver_json(false, '기사님 접속이 만료되었습니다. 다시 로그인해 주세요.');
    }
} else {
    $academy = ieum_require_academy_page();
}
$academy_id = (int) $academy['academy_id'];

function ieum_driver_json($success, $message, $extra = array())
{
    echo json_encode(array_merge(array(
        'success' => $success,
        'message' => $message,
    ), $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function ieum_driver_ensure_tables()
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_driver_json(false, '잘못된 요청입니다.');
}

$csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
if (!ieum_verify_csrf_token($csrf_token)) {
    ieum_driver_json(false, '보안 토큰이 올바르지 않습니다.');
}

ieum_driver_ensure_tables();

$journal_date = isset($_POST['journal_date']) ? preg_replace('/[^0-9-]/', '', trim($_POST['journal_date'])) : G5_TIME_YMD;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journal_date)) {
    $journal_date = G5_TIME_YMD;
}
$ride_type = isset($_POST['ride_type']) ? preg_replace('/[^a-z]/', '', trim($_POST['ride_type'])) : 'pickup';
if ($ride_type !== 'pickup' && $ride_type !== 'dropoff') {
    $ride_type = 'pickup';
}
$route_id = isset($_POST['route_id']) ? (int) $_POST['route_id'] : 0;
$vehicle_label = isset($_POST['vehicle_label']) ? trim($_POST['vehicle_label']) : '';
if (!empty($driver_session['route_id'])) {
    $route_id = (int) $driver_session['route_id'];
    $vehicle_label = isset($driver_session['vehicle_label']) ? (string) $driver_session['vehicle_label'] : $vehicle_label;
}
$lat = isset($_POST['lat']) ? (float) $_POST['lat'] : 0;
$lng = isset($_POST['lng']) ? (float) $_POST['lng'] : 0;
$accuracy = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? (float) $_POST['accuracy'] : null;

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) {
    ieum_driver_json(false, '위치 값이 올바르지 않습니다.');
}

$date_sql = sql_escape_string($journal_date);
$ride_type_sql = sql_escape_string($ride_type);
$vehicle_label_sql = sql_escape_string($vehicle_label);

$run = sql_fetch("
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

if (!isset($run['run_id'])) {
    ieum_driver_json(false, '운행 시작 후 위치를 기록할 수 있습니다.');
}

$run_id = (int) $run['run_id'];
$lat_sql = sql_escape_string(number_format($lat, 7, '.', ''));
$lng_sql = sql_escape_string(number_format($lng, 7, '.', ''));
$accuracy_sql = $accuracy === null ? 'null' : "'" . sql_escape_string(number_format($accuracy, 2, '.', '')) . "'";

sql_query("
    insert into " . IEUM_VEHICLE_LOCATION_TABLE . "
        set run_id = '{$run_id}',
            academy_id = '{$academy_id}',
            journal_date = '{$date_sql}',
            ride_type = '{$ride_type_sql}',
            route_id = '{$route_id}',
            vehicle_label = '{$vehicle_label_sql}',
            lat = '{$lat_sql}',
            lng = '{$lng_sql}',
            accuracy = {$accuracy_sql},
            recorded_at = '" . G5_TIME_YMDHIS . "',
            created_at = '" . G5_TIME_YMDHIS . "'
");

sql_query("
    update " . IEUM_VEHICLE_RUN_TABLE . "
       set last_lat = '{$lat_sql}',
           last_lng = '{$lng_sql}',
           last_location_at = '" . G5_TIME_YMDHIS . "',
           updated_at = '" . G5_TIME_YMDHIS . "'
     where run_id = '{$run_id}'
       and academy_id = '{$academy_id}'
");

ieum_driver_json(true, '위치를 저장했습니다.', array(
    'recorded_at' => date('H:i', strtotime(G5_TIME_YMDHIS)),
));
