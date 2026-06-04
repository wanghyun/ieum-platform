<?php
require_once dirname(__DIR__, 2) . '/_common.php';
require_once IEUM_PATH . '/lib/vehicle_driver_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, 'POST 요청만 사용할 수 있습니다.', array(), 405);
}

$driver = ieum_vehicle_require_driver();
ieum_vehicle_api_ensure_tables();

$journal_date = ieum_vehicle_api_param_date('journal_date', G5_TIME_YMD);
$ride_type = ieum_vehicle_api_param_ride_type();
$count = ieum_vehicle_api_bulk_boarding($driver, $journal_date, $ride_type);

ieum_json_response(true, '미확인 학생을 전원 탑승 처리했습니다.', array(
    'saved_count' => $count,
    'journal_date' => $journal_date,
    'ride_type' => $ride_type,
));
