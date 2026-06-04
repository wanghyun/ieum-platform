<?php
require_once dirname(__DIR__, 2) . '/_common.php';
require_once IEUM_PATH . '/lib/vehicle_driver_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, 'POST 요청만 사용할 수 있습니다.', array(), 405);
}

$driver = ieum_vehicle_require_driver();
ieum_vehicle_api_ensure_tables();

$academy_id = (int) $driver['academy_id'];
$route_id = (int) $driver['route_id'];
$vehicle_label = $driver['vehicle_label'];
$journal_date = ieum_vehicle_api_param_date('journal_date', G5_TIME_YMD);
$ride_type = ieum_vehicle_api_param_ride_type();
$lat = (float) ieum_vehicle_api_value('lat', 0);
$lng = (float) ieum_vehicle_api_value('lng', 0);
$accuracy = ieum_vehicle_api_value('accuracy', '');

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
    ieum_json_response(false, '위치 좌표가 올바르지 않습니다.', array(), 400);
}

$run = ieum_vehicle_api_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label);
if (empty($run['run_id'])) {
    $driver_sql = sql_escape_string(ieum_vehicle_api_checked_by($driver));
    sql_query("
        insert into " . IEUM_VEHICLE_RUN_TABLE . "
            set academy_id = '{$academy_id}',
                journal_date = '" . sql_escape_string($journal_date) . "',
                ride_type = '" . sql_escape_string($ride_type) . "',
                route_id = '{$route_id}',
                vehicle_label = '" . sql_escape_string($vehicle_label) . "',
                driver_member_id = '{$driver_sql}',
                status = 'active',
                started_at = '" . G5_TIME_YMDHIS . "',
                created_at = '" . G5_TIME_YMDHIS . "'
    ");
    $run = ieum_vehicle_api_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label);
}

$accuracy_sql = $accuracy === '' ? 'null' : "'" . sql_escape_string((string) (float) $accuracy) . "'";
sql_query("
    insert into " . IEUM_VEHICLE_LOCATION_TABLE . "
        set run_id = '" . (int) $run['run_id'] . "',
            academy_id = '{$academy_id}',
            journal_date = '" . sql_escape_string($journal_date) . "',
            ride_type = '" . sql_escape_string($ride_type) . "',
            route_id = '{$route_id}',
            vehicle_label = '" . sql_escape_string($vehicle_label) . "',
            lat = '" . sql_escape_string((string) $lat) . "',
            lng = '" . sql_escape_string((string) $lng) . "',
            accuracy = {$accuracy_sql},
            recorded_at = '" . G5_TIME_YMDHIS . "',
            created_at = '" . G5_TIME_YMDHIS . "'
");

sql_query("
    update " . IEUM_VEHICLE_RUN_TABLE . "
       set last_lat = '" . sql_escape_string((string) $lat) . "',
           last_lng = '" . sql_escape_string((string) $lng) . "',
           last_location_at = '" . G5_TIME_YMDHIS . "',
           updated_at = '" . G5_TIME_YMDHIS . "'
     where run_id = '" . (int) $run['run_id'] . "'
       and academy_id = '{$academy_id}'
");

ieum_json_response(true, '차량 위치를 저장했습니다.', array(
    'run_id' => (int) $run['run_id'],
    'lat' => $lat,
    'lng' => $lng,
    'recorded_at' => G5_TIME_YMDHIS,
));
