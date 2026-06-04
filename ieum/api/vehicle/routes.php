<?php
require_once dirname(__DIR__, 2) . '/_common.php';
require_once IEUM_PATH . '/lib/vehicle_driver_api.php';

ieum_vehicle_api_ensure_tables();

$academy_code = preg_replace('/[^0-9A-Za-z_-]/', '', trim((string) ieum_vehicle_api_value('academy_code', '')));
if ($academy_code === '') {
    ieum_json_response(false, '도장 코드를 입력해 주세요.', array(), 400);
}

$academy = sql_fetch("
    select academy_id, academy_code, academy_name, service_status, is_active
      from " . IEUM_ACADEMY_TABLE . "
     where academy_code = '" . sql_escape_string($academy_code) . "'
     limit 1
", false);

if (empty($academy['academy_id']) || empty($academy['is_active']) || $academy['service_status'] !== 'active') {
    ieum_json_response(false, '사용 가능한 도장이 아닙니다.', array(), 404);
}

$routes = array();
$result = sql_query("
    select route_id, route_name, vehicle_label, driver_name, driver_phone
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where academy_id = '" . (int) $academy['academy_id'] . "'
       and is_active = 1
       and driver_pin <> ''
  order by sort_order asc, route_name asc
", false);

while ($row = sql_fetch_array($result)) {
    $routes[] = array(
        'route_id' => (int) $row['route_id'],
        'route_name' => $row['route_name'],
        'vehicle_label' => $row['vehicle_label'],
        'driver_name' => $row['driver_name'],
        'driver_phone' => $row['driver_phone'],
        'label' => trim(($row['vehicle_label'] ? $row['vehicle_label'] . ' · ' : '') . $row['route_name'] . ($row['driver_name'] ? ' · ' . $row['driver_name'] : '')),
    );
}

ieum_json_response(true, '차량 노선 조회 완료', array(
    'academy' => array(
        'academy_id' => (int) $academy['academy_id'],
        'academy_code' => $academy['academy_code'],
        'academy_name' => $academy['academy_name'],
    ),
    'routes' => $routes,
));
