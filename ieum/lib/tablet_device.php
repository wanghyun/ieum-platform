<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_tablet_device_table_exists()
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    $schema_sql = sql_escape_string(G5_MYSQL_DB);
    $table_sql = sql_escape_string(IEUM_TABLET_DEVICE_TABLE);
    $row = sql_fetch("
        select count(*) as cnt
          from information_schema.TABLES
         where TABLE_SCHEMA = '{$schema_sql}'
           and TABLE_NAME = '{$table_sql}'
    ", false);

    $exists = (int) $row['cnt'] > 0;
    return $exists;
}

function ieum_tablet_device_random_token()
{
    if (function_exists('random_bytes')) {
        return 'tablet_' . bin2hex(random_bytes(24));
    }

    return 'tablet_' . hash('sha256', uniqid('', true) . mt_rand());
}

function ieum_tablet_device_generate_pairing_code()
{
    for ($i = 0; $i < 30; $i++) {
        $code = str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $code_sql = sql_escape_string($code);
        $row = sql_fetch("
            select count(*) as cnt
              from " . IEUM_TABLET_DEVICE_TABLE . "
             where pairing_code = '{$code_sql}'
               and status = 'pending'
               and expires_at >= '" . G5_TIME_YMDHIS . "'
        ", false);

        if (!(int) $row['cnt']) {
            return $code;
        }
    }

    return str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

function ieum_tablet_device_create_pairing($academy_id, $device_name, $created_by = '')
{
    $academy_id = (int) $academy_id;
    $device_name = trim($device_name);
    if ($device_name === '') {
        $device_name = '출석 태블릿';
    }

    $code = ieum_tablet_device_generate_pairing_code();
    $expires_at = date('Y-m-d H:i:s', strtotime(G5_TIME_YMDHIS . ' +30 minutes'));
    $device_name_sql = sql_escape_string($device_name);
    $code_sql = sql_escape_string($code);
    $created_by_sql = sql_escape_string($created_by);

    sql_query("
        insert into " . IEUM_TABLET_DEVICE_TABLE . "
            (academy_id, device_name, pairing_code, device_uid, device_token, status, expires_at, paired_at, last_seen_at, created_by, created_at, updated_at)
        values
            ('{$academy_id}', '{$device_name_sql}', '{$code_sql}', '', '', 'pending', '{$expires_at}', null, null, '{$created_by_sql}', '" . G5_TIME_YMDHIS . "', null)
    ");

    return sql_insert_id();
}

function ieum_tablet_device_pair($pairing_code, $device_uid, $device_name, $academy_code = '', $tablet_pin = '')
{
    if (!ieum_tablet_device_table_exists()) {
        return null;
    }

    $pairing_code = preg_replace('/[^0-9]/', '', trim($pairing_code));
    $device_uid = trim($device_uid);
    $device_name = trim($device_name);
    $academy_code = preg_replace('/[^0-9A-Za-z_-]/', '', trim($academy_code));
    $tablet_pin = preg_replace('/[^0-9]/', '', trim($tablet_pin));
    if ($pairing_code === '' || $device_uid === '') {
        return null;
    }

    $code_sql = sql_escape_string($pairing_code);
    $academy_where = '';
    if ($academy_code !== '') {
        $academy_code_sql = sql_escape_string($academy_code);
        $academy_where = " and a.academy_code = '{$academy_code_sql}'";
    }
    $row = sql_fetch("
        select d.*, a.academy_code, a.academy_name, a.tablet_pin, a.service_status, a.is_active as academy_active
          from " . IEUM_TABLET_DEVICE_TABLE . " d
          join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = d.academy_id
         where d.pairing_code = '{$code_sql}'
           and d.status = 'pending'
           and d.expires_at >= '" . G5_TIME_YMDHIS . "'
           {$academy_where}
         order by d.device_id desc
         limit 1
    ", false);

    if (!$row || (int) $row['academy_active'] !== 1 || $row['service_status'] !== 'active') {
        return null;
    }
    if ($tablet_pin === '' || $tablet_pin !== preg_replace('/[^0-9]/', '', (string) $row['tablet_pin'])) {
        return null;
    }

    if ($device_name === '') {
        $device_name = $row['device_name'] !== '' ? $row['device_name'] : '출석 태블릿';
    }

    $token = ieum_tablet_device_random_token();
    $device_id = (int) $row['device_id'];
    $academy_id = (int) $row['academy_id'];
    $device_uid_sql = sql_escape_string($device_uid);
    $device_name_sql = sql_escape_string($device_name);
    $token_sql = sql_escape_string($token);

    sql_query("
        update " . IEUM_TABLET_DEVICE_TABLE . "
           set status = 'revoked',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '{$academy_id}'
           and device_uid = '{$device_uid_sql}'
           and status = 'active'
           and device_id <> '{$device_id}'
    ", false);

    sql_query("
        update " . IEUM_TABLET_DEVICE_TABLE . "
           set device_uid = '{$device_uid_sql}',
               device_name = '{$device_name_sql}',
               device_token = '{$token_sql}',
               status = 'active',
               paired_at = '" . G5_TIME_YMDHIS . "',
               last_seen_at = '" . G5_TIME_YMDHIS . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where device_id = '{$device_id}'
    ");

    $row['device_uid'] = $device_uid;
    $row['device_name'] = $device_name;
    $row['device_token'] = $token;
    $row['status'] = 'active';
    return $row;
}

function ieum_tablet_device_find_academy_by_token($token)
{
    if (!ieum_tablet_device_table_exists()) {
        return null;
    }

    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $token_sql = sql_escape_string($token);
    $row = sql_fetch("
        select a.*, d.device_id, d.device_name as tablet_device_name
          from " . IEUM_TABLET_DEVICE_TABLE . " d
          join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = d.academy_id
         where d.device_token = '{$token_sql}'
           and d.status = 'active'
           and a.service_status = 'active'
           and a.is_active = 1
         limit 1
    ", false);

    if ($row) {
        $device_id = (int) $row['device_id'];
        sql_query("
            update " . IEUM_TABLET_DEVICE_TABLE . "
               set last_seen_at = '" . G5_TIME_YMDHIS . "'
             where device_id = '{$device_id}'
        ", false);
    }

    return $row ? $row : null;
}

function ieum_tablet_device_status_label($status, $expires_at = '')
{
    if ($status === 'pending' && $expires_at !== '' && strtotime($expires_at) < strtotime(G5_TIME_YMDHIS)) {
        return '만료됨';
    }

    $labels = array(
        'pending' => 'QR 대기',
        'active' => '연결됨',
        'revoked' => '해제됨',
    );

    return isset($labels[$status]) ? $labels[$status] : $status;
}
