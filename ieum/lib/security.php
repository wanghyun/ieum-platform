<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_require_login_json()
{
    global $is_member;

    if (!$is_member) {
        ieum_json_response(false, '로그인이 필요합니다.', array(), 401);
    }
}

function ieum_require_admin_page()
{
    global $is_admin;

    if (!$is_admin) {
        alert('관리자만 접근 가능합니다.');
    }
}

function ieum_new_csrf_token()
{
    if (function_exists('random_bytes')) {
        $bytes = random_bytes(16);
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(16);
    } else {
        $bytes = uniqid(mt_rand(), true);
    }

    $token = bin2hex($bytes);
    set_session('ieum_csrf_token', $token);
    return $token;
}

function ieum_verify_csrf_token($token)
{
    $saved = get_session('ieum_csrf_token');
    return $saved && $token && hash_equals($saved, $token);
}
