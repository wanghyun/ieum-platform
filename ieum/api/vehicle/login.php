<?php
require_once dirname(__DIR__, 2) . '/_common.php';
require_once IEUM_PATH . '/lib/vehicle_driver_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, 'POST 요청만 사용할 수 있습니다.', array(), 405);
}

ieum_vehicle_api_ensure_tables();

$academy_code = preg_replace('/[^0-9A-Za-z_-]/', '', trim((string) ieum_vehicle_api_value('academy_code', '')));
$route_id = (int) ieum_vehicle_api_value('route_id', 0);
$driver_pin = preg_replace('/[^0-9]/', '', trim((string) ieum_vehicle_api_value('driver_pin', '')));

if ($academy_code === '' || $route_id <= 0 || $driver_pin === '') {
    ieum_json_response(false, '도장 코드, 노선, 기사님 PIN을 입력해 주세요.', array(), 400);
}

$row = sql_fetch("
    select a.academy_id, a.academy_code, a.academy_name, a.service_status, a.is_active,
           r.route_id, r.route_name, r.vehicle_label, r.driver_name, r.driver_phone, r.driver_pin
      from " . IEUM_ACADEMY_TABLE . " a
      join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.academy_id = a.academy_id
     where a.academy_code = '" . sql_escape_string($academy_code) . "'
       and r.route_id = '{$route_id}'
       and r.is_active = 1
     limit 1
", false);

if (empty($row['academy_id']) || empty($row['is_active']) || $row['service_status'] !== 'active') {
    ieum_json_response(false, '사용 가능한 도장이 아닙니다.', array(), 404);
}

if ($row['driver_pin'] === '' || $driver_pin !== preg_replace('/[^0-9]/', '', (string) $row['driver_pin'])) {
    ieum_json_response(false, '기사님 PIN이 맞지 않습니다.', array(), 401);
}

$expires_at = strtotime('+30 days');
$token = ieum_vehicle_driver_token((int) $row['academy_id'], (int) $row['route_id'], $expires_at);

ieum_json_response(true, '기사님 로그인이 완료되었습니다.', array(
    'driver_token' => $token,
    'expires_at' => date('Y-m-d H:i:s', $expires_at),
    'academy' => array(
        'academy_id' => (int) $row['academy_id'],
        'academy_code' => $row['academy_code'],
        'academy_name' => $row['academy_name'],
    ),
    'route' => array(
        'route_id' => (int) $row['route_id'],
        'route_name' => $row['route_name'],
        'vehicle_label' => $row['vehicle_label'],
        'driver_name' => $row['driver_name'],
        'driver_phone' => $row['driver_phone'],
        'label' => trim(($row['vehicle_label'] ? $row['vehicle_label'] . ' · ' : '') . $row['route_name']),
    ),
));
