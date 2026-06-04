<?php
require_once dirname(__DIR__, 2) . '/_common.php';
require_once IEUM_PATH . '/lib/vehicle_driver_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, 'POST 요청만 사용할 수 있습니다.', array(), 405);
}

$driver = ieum_vehicle_require_driver();
ieum_vehicle_api_ensure_tables();

$journal_date = ieum_vehicle_api_param_date('journal_date', G5_TIME_YMD);
$student_vehicle_id = (int) ieum_vehicle_api_value('student_vehicle_id', 0);
$status = preg_replace('/[^a-z]/', '', trim((string) ieum_vehicle_api_value('status', '')));
$note = trim((string) ieum_vehicle_api_value('note', ''));

if ($student_vehicle_id <= 0) {
    ieum_json_response(false, '학생 차량 배정 번호가 필요합니다.', array(), 400);
}

$saved = ieum_vehicle_api_save_boarding($driver, $journal_date, $student_vehicle_id, $status, $note);
ieum_json_response(true, '탑승 상태를 저장했습니다.', array('boarding' => $saved));
