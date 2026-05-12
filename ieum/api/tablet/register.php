<?php
require_once dirname(__DIR__, 2) . '/_common.php';
require_once IEUM_PATH . '/lib/response.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/tablet_device.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieum_json_response(false, 'POST 요청만 사용할 수 있습니다.', array(), 405);
}

$pairing_code = isset($_POST['pairing_code']) ? preg_replace('/[^0-9]/', '', $_POST['pairing_code']) : '';
$academy_code = isset($_POST['academy_code']) ? preg_replace('/[^0-9A-Za-z_-]/', '', trim($_POST['academy_code'])) : '';
$tablet_pin = isset($_POST['tablet_pin']) ? preg_replace('/[^0-9]/', '', trim($_POST['tablet_pin'])) : '';
$device_uid = isset($_POST['device_uid']) ? trim($_POST['device_uid']) : '';
$device_name = isset($_POST['device_name']) ? trim($_POST['device_name']) : '';

if ($academy_code === '') {
    ieum_json_response(false, '도장 코드를 입력해 주세요.', array(), 400);
}

if (!preg_match('/^\d{4,8}$/', $tablet_pin)) {
    ieum_json_response(false, '출석기 PIN을 입력해 주세요.', array(), 400);
}

if (!preg_match('/^\d{6}$/', $pairing_code)) {
    ieum_json_response(false, '6자리 연결 코드를 입력해 주세요.', array(), 400);
}

if ($device_uid === '') {
    ieum_json_response(false, '기기 식별값이 없습니다. 앱을 다시 실행해 주세요.', array(), 400);
}

$device = ieum_tablet_device_pair($pairing_code, $device_uid, $device_name, $academy_code, $tablet_pin);
if (!$device) {
    ieum_json_response(false, '도장 코드, PIN, 연결 코드를 확인해 주세요. 연결 코드는 만료되면 새로 발급해야 합니다.', array(), 404);
}

ieum_json_response(true, '출석기가 연결되었습니다.', array(
    'academy_id' => (int) $device['academy_id'],
    'academy_code' => $device['academy_code'],
    'academy_name' => $device['academy_name'],
    'device_id' => (int) $device['device_id'],
    'device_name' => $device['device_name'],
    'device_token' => $device['device_token'],
));
