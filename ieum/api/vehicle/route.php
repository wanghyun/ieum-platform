<?php
require_once dirname(__DIR__, 2) . '/_common.php';
require_once IEUM_PATH . '/lib/vehicle_driver_api.php';

$driver = ieum_vehicle_require_driver();
ieum_vehicle_api_ensure_tables();

$academy_id = (int) $driver['academy_id'];
$route_id = (int) $driver['route_id'];
$vehicle_label = $driver['vehicle_label'];
$journal_date = ieum_vehicle_api_param_date('journal_date', G5_TIME_YMD);
$ride_type = ieum_vehicle_api_param_ride_type();

list($summary, $stops) = ieum_vehicle_api_roster($driver, $journal_date, $ride_type);
$active_run = ieum_vehicle_api_active_run($academy_id, $journal_date, $ride_type, $route_id, $vehicle_label);

ieum_json_response(true, '오늘 차량 노선 조회 완료', array(
    'journal_date' => $journal_date,
    'ride_type' => $ride_type,
    'academy' => array(
        'academy_id' => $academy_id,
        'academy_code' => $driver['academy_code'],
        'academy_name' => $driver['academy_name'],
    ),
    'route' => array(
        'route_id' => $route_id,
        'route_name' => $driver['route_name'],
        'vehicle_label' => $vehicle_label,
        'driver_name' => $driver['driver_name'],
        'driver_phone' => $driver['driver_phone'],
    ),
    'run' => ieum_vehicle_api_run_array($active_run),
    'summary' => $summary,
    'stops' => $stops,
));
