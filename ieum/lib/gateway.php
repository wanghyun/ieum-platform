<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_gateway_request_token()
{
    $headers = function_exists('getallheaders') ? getallheaders() : array();
    $auth = '';

    foreach ($headers as $key => $value) {
        $lower = strtolower($key);
        if ($lower === 'authorization') {
            $auth = trim($value);
        } elseif ($lower === 'x-ieum-gateway-token') {
            return trim($value);
        }
    }

    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    if (isset($_REQUEST['token'])) {
        return trim($_REQUEST['token']);
    }

    return '';
}

function ieum_require_gateway_token()
{
    require_once IEUM_PATH . '/lib/academy.php';
    require_once IEUM_PATH . '/lib/sms_gateway_device.php';
    $token = ieum_gateway_request_token();
    $sms_device = ieum_sms_gateway_find_by_token($token);
    if ($sms_device) {
        if ((int) $sms_device['academy_active'] !== 1 || $sms_device['service_status'] !== 'active') {
            ieum_json_response(false, '사용 가능한 도장이 아닙니다.', array(), 403);
        }
        ieum_sms_gateway_touch((int) $sms_device['device_id']);
        return array(
            'academy_id' => (int) $sms_device['academy_id'],
            'academy_code' => $sms_device['academy_code'],
            'academy_name' => $sms_device['academy_name'],
            'sms_start_time' => $sms_device['sms_start_time'],
            'sms_end_time' => $sms_device['sms_end_time'],
            'sms_day_mode' => isset($sms_device['sms_day_mode']) ? $sms_device['sms_day_mode'] : 'weekday',
            'sms_poll_seconds' => $sms_device['sms_poll_seconds'],
            '_sms_gateway_device' => $sms_device,
        );
    }

    $academy = ieum_get_academy_by_gateway_token($token);

    if (!$academy) {
        require_once IEUM_PATH . '/lib/tablet_device.php';
        $academy = ieum_tablet_device_find_academy_by_token($token);
    }

    if (!$academy) {
        ieum_json_response(false, '문자 발송폰 인증에 실패했습니다.', array(), 401);
    }

    return $academy;
}
