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
$action = preg_replace('/[^a-z_]/', '', trim((string) ieum_vehicle_api_value('action', 'start')));

if ($action !== 'start' && $action !== 'end') {
    ieum_json_response(false, '운행 시작 또는 종료만 처리할 수 있습니다.', array(), 400);
}

$active_run = ieum_vehicle_api_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label);
$date_sql = sql_escape_string($journal_date);
$ride_type_sql = sql_escape_string($ride_type);
$vehicle_label_sql = sql_escape_string($vehicle_label);
$driver_sql = sql_escape_string(ieum_vehicle_api_checked_by($driver));

if ($action === 'start') {
    if (empty($active_run['run_id'])) {
        sql_query("
            insert into " . IEUM_VEHICLE_RUN_TABLE . "
                set academy_id = '{$academy_id}',
                    journal_date = '{$date_sql}',
                    ride_type = '{$ride_type_sql}',
                    route_id = '{$route_id}',
                    vehicle_label = '{$vehicle_label_sql}',
                    driver_member_id = '{$driver_sql}',
                    status = 'active',
                    started_at = '" . G5_TIME_YMDHIS . "',
                    created_at = '" . G5_TIME_YMDHIS . "'
        ");
        $active_run = ieum_vehicle_api_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label);
    }
    ieum_json_response(true, '운행을 시작했습니다.', array('run' => ieum_vehicle_api_run_array($active_run)));
}

if (empty($active_run['run_id'])) {
    ieum_json_response(false, '종료할 운행 기록이 없습니다.', array(), 404);
}

sql_query("
    update " . IEUM_VEHICLE_RUN_TABLE . "
       set status = 'ended',
           ended_at = '" . G5_TIME_YMDHIS . "',
           updated_at = '" . G5_TIME_YMDHIS . "'
     where run_id = '" . (int) $active_run['run_id'] . "'
       and academy_id = '{$academy_id}'
");

$active_run['status'] = 'ended';
$active_run['ended_at'] = G5_TIME_YMDHIS;
ieum_json_response(true, '운행을 종료했습니다.', array('run' => ieum_vehicle_api_run_array($active_run)));
