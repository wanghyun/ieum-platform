<?php
require_once dirname(dirname(__DIR__)) . '/_common.php';
require_once IEUM_PATH . '/lib/response.php';
require_once IEUM_PATH . '/lib/sms_gateway_device.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, '허용되지 않는 요청입니다.', array(), 405);
}

$pairing_code = isset($_POST['pairing_code']) ? $_POST['pairing_code'] : '';
$device_name = isset($_POST['device_name']) ? $_POST['device_name'] : '';
$device_model = isset($_POST['device_model']) ? $_POST['device_model'] : '';
$app_version = isset($_POST['app_version']) ? $_POST['app_version'] : '';

list($ok, $device, $message) = ieum_sms_gateway_register_by_code($pairing_code, $device_name, $device_model, $app_version);
if (!$ok) {
    ieum_json_response(false, $message, array(), 422);
}

ieum_json_response(true, $message, array(
    'device_id' => (int) $device['device_id'],
    'device_token' => $device['device_token'],
    'device_name' => $device['device_name'],
    'academy_id' => (int) $device['academy_id'],
    'academy_code' => $device['academy_code'],
    'academy_name' => $device['academy_name'],
    'sms_start_time' => $device['sms_start_time'],
    'sms_end_time' => $device['sms_end_time'],
    'sms_day_mode' => isset($device['sms_day_mode']) ? $device['sms_day_mode'] : 'weekday',
    'sms_poll_seconds' => (int) $device['sms_poll_seconds'],
));
