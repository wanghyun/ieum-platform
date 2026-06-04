<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_sms_gateway_random_token($bytes = 24)
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($bytes));
    }
    return md5(uniqid('ieum_sms_gateway', true)) . md5(mt_rand());
}

function ieum_sms_gateway_random_pairing_code()
{
    return (string) mt_rand(100000, 999999);
}

function ieum_sms_gateway_ensure_table()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    sql_query("
        create table if not exists " . IEUM_SMS_GATEWAY_DEVICE_TABLE . " (
            device_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            device_name varchar(80) not null default '',
            device_token varchar(100) not null default '',
            pairing_code varchar(12) not null default '',
            pairing_expires_at datetime null,
            device_model varchar(120) not null default '',
            app_version varchar(40) not null default '',
            device_status varchar(20) not null default 'pending',
            is_primary tinyint(1) not null default 0,
            last_seen_at datetime null,
            last_claim_at datetime null,
            last_sent_at datetime null,
            last_error varchar(255) not null default '',
            created_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (device_id),
            unique key uq_device_token (device_token),
            key idx_academy_status (academy_id, device_status),
            key idx_pairing_code (pairing_code, pairing_expires_at)
        ) engine={$engine} default charset={$charset}
    ", false);

    ieum_sms_gateway_ensure_academy_schedule_columns();
}

function ieum_sms_gateway_ensure_academy_schedule_columns()
{
    $schema_sql = sql_escape_string(G5_MYSQL_DB);
    $table_sql = sql_escape_string(IEUM_ACADEMY_TABLE);
    $exists = sql_fetch("
        select count(*) as cnt
          from information_schema.COLUMNS
         where TABLE_SCHEMA = '{$schema_sql}'
           and TABLE_NAME = '{$table_sql}'
           and COLUMN_NAME = 'sms_day_mode'
    ", false);

    if (!(int) $exists['cnt']) {
        sql_query("alter table " . IEUM_ACADEMY_TABLE . " add column sms_day_mode varchar(20) not null default 'weekday' after sms_end_time", false);
    }
}

function ieum_sms_gateway_day_mode_options()
{
    return array(
        'weekday' => '평일(월~금)',
        'mon_sat' => '월~토',
        'everyday' => '매일',
    );
}

function ieum_sms_gateway_day_mode_label($mode)
{
    $options = ieum_sms_gateway_day_mode_options();
    return isset($options[$mode]) ? $options[$mode] : $options['weekday'];
}

function ieum_sms_gateway_valid_time($value)
{
    if (!preg_match('/^\d{2}:\d{2}$/', (string) $value)) {
        return false;
    }
    list($hour, $minute) = array_map('intval', explode(':', $value));
    return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
}

function ieum_sms_gateway_create_pairing($academy_id, $device_name = '', $created_by = '')
{
    $academy_id = (int) $academy_id;
    if ($academy_id <= 0) {
        return false;
    }

    ieum_sms_gateway_ensure_table();

    $device_name = trim((string) $device_name);
    if ($device_name === '') {
        $device_name = '문자 발송폰';
    }

    $pairing_code = '';
    for ($i = 0; $i < 10; $i++) {
        $pairing_code = ieum_sms_gateway_random_pairing_code();
        $exists = sql_fetch("
            select device_id
              from " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
             where pairing_code = '" . sql_escape_string($pairing_code) . "'
               and pairing_expires_at > '" . G5_TIME_YMDHIS . "'
               and device_status = 'pending'
             limit 1
        ", false);
        if (!isset($exists['device_id'])) {
            break;
        }
        $pairing_code = '';
    }

    if ($pairing_code === '') {
        return false;
    }

    $device_token = ieum_sms_gateway_random_token();
    $expires_at = date('Y-m-d H:i:s', strtotime(G5_TIME_YMDHIS . ' +30 minutes'));

    sql_query("
        insert into " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
            set academy_id = '{$academy_id}',
                device_name = '" . sql_escape_string($device_name) . "',
                device_token = '" . sql_escape_string($device_token) . "',
                pairing_code = '" . sql_escape_string($pairing_code) . "',
                pairing_expires_at = '" . sql_escape_string($expires_at) . "',
                device_status = 'pending',
                created_by = '" . sql_escape_string($created_by) . "',
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
    ", false);

    return sql_fetch("
        select *
          from " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
         where device_token = '" . sql_escape_string($device_token) . "'
         limit 1
    ", false);
}

function ieum_sms_gateway_register_by_code($pairing_code, $device_name = '', $device_model = '', $app_version = '')
{
    ieum_sms_gateway_ensure_table();

    $pairing_code = preg_replace('/[^0-9]/', '', (string) $pairing_code);
    if ($pairing_code === '') {
        return array(false, null, '연결 코드를 입력하세요.');
    }

    $device = sql_fetch("
        select d.*, a.academy_code, a.academy_name, a.sms_start_time, a.sms_end_time, a.sms_day_mode, a.sms_poll_seconds, a.is_active as academy_active, a.service_status
          from " . IEUM_SMS_GATEWAY_DEVICE_TABLE . " d
          join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = d.academy_id
         where d.pairing_code = '" . sql_escape_string($pairing_code) . "'
           and d.pairing_expires_at >= '" . G5_TIME_YMDHIS . "'
           and d.device_status = 'pending'
         order by d.device_id desc
         limit 1
    ", false);

    if (!isset($device['device_id'])) {
        return array(false, null, '연결 코드가 만료되었거나 올바르지 않습니다.');
    }
    if ((int) $device['academy_active'] !== 1 || $device['service_status'] !== 'active') {
        return array(false, null, '사용 가능한 도장이 아닙니다.');
    }

    $device_name = trim((string) $device_name);
    if ($device_name === '') {
        $device_name = $device['device_name'];
    }

    sql_query("
        update " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
           set device_name = '" . sql_escape_string($device_name) . "',
               device_model = '" . sql_escape_string($device_model) . "',
               app_version = '" . sql_escape_string($app_version) . "',
               device_status = 'active',
               pairing_code = '',
               pairing_expires_at = null,
               last_seen_at = '" . G5_TIME_YMDHIS . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where device_id = '" . (int) $device['device_id'] . "'
    ", false);

    $device = ieum_sms_gateway_find_by_token($device['device_token']);
    return array(true, $device, '문자 발송폰이 연결되었습니다.');
}

function ieum_sms_gateway_find_by_token($token)
{
    ieum_sms_gateway_ensure_table();
    $token = trim((string) $token);
    if ($token === '') {
        return null;
    }

    $row = sql_fetch("
        select d.*, a.academy_code, a.academy_name, a.sms_start_time, a.sms_end_time, a.sms_day_mode, a.sms_poll_seconds, a.is_active as academy_active, a.service_status
          from " . IEUM_SMS_GATEWAY_DEVICE_TABLE . " d
          join " . IEUM_ACADEMY_TABLE . " a on a.academy_id = d.academy_id
         where d.device_token = '" . sql_escape_string($token) . "'
           and d.device_status = 'active'
         limit 1
    ", false);

    return isset($row['device_id']) ? $row : null;
}

function ieum_sms_gateway_touch($device_id, $field = 'last_seen_at', $error = '')
{
    $device_id = (int) $device_id;
    if ($device_id <= 0) {
        return;
    }

    $allowed = array('last_seen_at', 'last_claim_at', 'last_sent_at');
    if (!in_array($field, $allowed, true)) {
        $field = 'last_seen_at';
    }

    sql_query("
        update " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
           set {$field} = '" . G5_TIME_YMDHIS . "',
               last_error = '" . sql_escape_string($error) . "',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where device_id = '{$device_id}'
    ", false);
}

function ieum_sms_gateway_disable($academy_id, $device_id)
{
    ieum_sms_gateway_ensure_table();
    sql_query("
        update " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
           set device_status = 'disabled',
               updated_at = '" . G5_TIME_YMDHIS . "'
         where academy_id = '" . (int) $academy_id . "'
           and device_id = '" . (int) $device_id . "'
    ", false);
}
