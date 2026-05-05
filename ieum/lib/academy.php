<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_default_academy_id()
{
    return 1;
}

function ieum_get_default_academy()
{
    $academy_id = ieum_default_academy_id();
    $row = sql_fetch("
        select *
          from " . IEUM_ACADEMY_TABLE . "
         where academy_id = '{$academy_id}'
         limit 1
    ", false);

    return isset($row['academy_id']) ? $row : null;
}

function ieum_get_academy_by_gateway_token($token)
{
    $token_sql = sql_escape_string(trim($token));
    if ($token_sql === '') {
        return null;
    }

    $row = sql_fetch("
        select *
          from " . IEUM_ACADEMY_TABLE . "
         where gateway_token = '{$token_sql}'
           and is_active = 1
           and service_status = 'active'
         limit 1
    ", false);

    return isset($row['academy_id']) ? $row : null;
}

function ieum_get_member_academy($mb_id)
{
    $mb_id_sql = sql_escape_string(trim($mb_id));
    if ($mb_id_sql === '') {
        return null;
    }

    $row = sql_fetch("
        select *
          from " . IEUM_ACADEMY_TABLE . "
         where mb_id = '{$mb_id_sql}'
           and is_active = 1
           and service_status = 'active'
         limit 1
    ", false);

    return isset($row['academy_id']) ? $row : null;
}

function ieum_current_academy()
{
    global $is_admin, $member;

    if ($is_admin === 'super') {
        $academy = ieum_get_default_academy();
        if ($academy) {
            return $academy;
        }
    }

    return ieum_get_member_academy(isset($member['mb_id']) ? $member['mb_id'] : '');
}

function ieum_require_academy_page()
{
    $academy = ieum_current_academy();
    if (!$academy) {
        alert('아이리포트 사용 권한이 없습니다. 본사 관리자에게 문의하세요.');
    }

    return $academy;
}

function ieum_require_head_admin_page()
{
    global $is_admin;

    if ($is_admin !== 'super') {
        alert('본사 최고관리자만 접근 가능합니다.');
    }
}
