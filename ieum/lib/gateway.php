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
    $token = ieum_gateway_request_token();
    $academy = ieum_get_academy_by_gateway_token($token);

    if (!$academy) {
        ieum_json_response(false, '문자 게이트웨이 인증에 실패했습니다.', array(), 401);
    }

    return $academy;
}
