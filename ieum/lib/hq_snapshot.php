<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_hq_snapshot_table_exists($table)
{
    $table_sql = sql_escape_string($table);
    $schema_sql = sql_escape_string(G5_MYSQL_DB);
    $row = sql_fetch("
        select count(*) as cnt
          from information_schema.TABLES
         where TABLE_SCHEMA = '{$schema_sql}'
           and TABLE_NAME = '{$table_sql}'
    ", false);

    return !empty($row['cnt']);
}

function ieum_hq_snapshot_ensure_tables()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    sql_query("
        create table if not exists " . IEUM_HQ_DAILY_SNAPSHOT_TABLE . " (
            snapshot_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            snapshot_date date not null,
            snapshot_month char(7) not null default '',
            academy_name varchar(120) not null default '',
            academy_code varchar(60) not null default '',
            mb_id varchar(50) not null default '',
            service_status varchar(30) not null default 'active',
            is_active tinyint(1) not null default 1,
            total_count int unsigned not null default 0,
            active_count int unsigned not null default 0,
            new_count int unsigned not null default 0,
            paused_count int unsigned not null default 0,
            paused_out_count int unsigned not null default 0,
            returned_count int unsigned not null default 0,
            withdrawn_count int unsigned not null default 0,
            current_paused_count int unsigned not null default 0,
            current_withdrawn_count int unsigned not null default 0,
            character_enabled int unsigned not null default 0,
            character_entered int unsigned not null default 0,
            fitness_enabled int unsigned not null default 0,
            fitness_entered int unsigned not null default 0,
            vehicle_assigned int unsigned not null default 0,
            boarding_today int unsigned not null default 0,
            boarding_month int unsigned not null default 0,
            sms_device_count int unsigned not null default 0,
            tablet_device_count int unsigned not null default 0,
            merchant_map_id int unsigned not null default 0,
            merchant_status varchar(30) not null default '',
            bill_count int unsigned not null default 0,
            paid_count int unsigned not null default 0,
            waiting_count int unsigned not null default 0,
            failed_count int unsigned not null default 0,
            bill_amount int unsigned not null default 0,
            alerts text null,
            generated_by varchar(30) not null default 'auto',
            generated_at datetime not null,
            primary key (snapshot_id),
            unique key uq_hq_snapshot_day (academy_id, snapshot_date),
            key idx_snapshot_month (snapshot_month),
            key idx_generated_at (generated_at)
        ) engine={$engine} default charset={$charset}
    ", false);
}

function ieum_hq_snapshot_count($sql)
{
    $row = sql_fetch($sql, false);
    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}

function ieum_hq_snapshot_month_bounds($month)
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));

    return array($month, $start, $end);
}

function ieum_hq_snapshot_compute_academy($academy, $month = '', $snapshot_date = '')
{
    list($month, $month_start, $month_end) = ieum_hq_snapshot_month_bounds($month ?: date('Y-m'));
    $snapshot_date = $snapshot_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshot_date) ? $snapshot_date : G5_TIME_YMD;
    $academy_id = (int) $academy['academy_id'];
    $month_sql = sql_escape_string($month);
    $month_start_sql = sql_escape_string($month_start);
    $month_end_sql = sql_escape_string($month_end);
    $snapshot_date_sql = sql_escape_string($snapshot_date);

    $has_sms_devices = ieum_hq_snapshot_table_exists(IEUM_SMS_GATEWAY_DEVICE_TABLE);
    $has_tablet_devices = ieum_hq_snapshot_table_exists(IEUM_TABLET_DEVICE_TABLE);
    $has_boarding = ieum_hq_snapshot_table_exists(IEUM_VEHICLE_BOARDING_TABLE);
    $has_status_logs = ieum_hq_snapshot_table_exists(IEUM_STUDENT_STATUS_LOG_TABLE);

    $student = sql_fetch("
        select count(*) as total_count,
               sum(case when is_active = 1 and student_status in ('enrolled','trial','returned','') then 1 else 0 end) as active_count,
               sum(case when is_active = 1 and character_report_enabled = 1 then 1 else 0 end) as character_enabled_count,
               sum(case when is_active = 1 and fitness_report_enabled = 1 then 1 else 0 end) as fitness_enabled_count,
               sum(case when student_status = 'paused' then 1 else 0 end) as paused_count,
               sum(case when student_status = 'withdrawn' then 1 else 0 end) as withdrawn_count,
               sum(case when admission_date between '{$month_start_sql}' and '{$month_end_sql}' then 1 else 0 end) as new_count,
               sum(case when vehicle_pickup_enabled = 1 or vehicle_dropoff_enabled = 1 then 1 else 0 end) as vehicle_assigned_count
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
    ", false);

    $flow = array('paused_in_count' => 0, 'paused_out_count' => 0, 'returned_count' => 0, 'withdrawn_count' => 0);
    if ($has_status_logs) {
        $paused_in = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and after_status = 'paused'
               and changed_date between '{$month_start_sql}' and '{$month_end_sql}'
        ", false);
        $paused_out = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and before_status = 'paused'
               and after_status <> 'paused'
               and changed_date between '{$month_start_sql}' and '{$month_end_sql}'
        ", false);
        $returned = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and before_status = 'paused'
               and after_status in ('returned','enrolled')
               and changed_date between '{$month_start_sql}' and '{$month_end_sql}'
        ", false);
        $withdrawn = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_STUDENT_STATUS_LOG_TABLE . "
             where academy_id = '{$academy_id}'
               and after_status = 'withdrawn'
               and changed_date between '{$month_start_sql}' and '{$month_end_sql}'
        ", false);
        $flow['paused_in_count'] = (int) $paused_in['cnt'];
        $flow['paused_out_count'] = (int) $paused_out['cnt'];
        $flow['returned_count'] = (int) $returned['cnt'];
        $flow['withdrawn_count'] = (int) $withdrawn['cnt'];
    }

    $character = sql_fetch("
        select count(distinct student_id) as entered_count
          from " . IEUM_REPORT_CHARACTER_TABLE . "
         where academy_id = '{$academy_id}'
           and report_month = '{$month_sql}'
    ", false);

    $fitness = sql_fetch("
        select count(distinct student_id) as entered_count
          from " . IEUM_REPORT_FITNESS_TABLE . "
         where academy_id = '{$academy_id}'
           and report_month = '{$month_sql}'
    ", false);

    $merchant = sql_fetch("
        select *
          from " . IEUM_PAYMINT_MERCHANT_TABLE . "
         where academy_id = '{$academy_id}'
         limit 1
    ", false);

    $bill = sql_fetch("
        select count(*) as bill_count,
               sum(case when appr_state = 'F' or status = 'paid' then 1 else 0 end) as paid_count,
               sum(case when appr_state = 'W' and status not in ('failed','destroyed','paid') then 1 else 0 end) as waiting_count,
               sum(case when status in ('failed','destroyed') then 1 else 0 end) as failed_count,
               coalesce(sum(bill_amount), 0) as bill_amount
          from " . IEUM_PAYMINT_BILL_TABLE . "
         where academy_id = '{$academy_id}'
           and billing_month = '{$month_sql}'
    ", false);

    $sms_device_count = 0;
    if ($has_sms_devices) {
        $sms_device_count = ieum_hq_snapshot_count("
            select count(*) as cnt
              from " . IEUM_SMS_GATEWAY_DEVICE_TABLE . "
             where academy_id = '{$academy_id}'
               and device_status = 'active'
        ");
    }

    $tablet_device_count = 0;
    if ($has_tablet_devices) {
        $tablet_device_count = ieum_hq_snapshot_count("
            select count(*) as cnt
              from " . IEUM_TABLET_DEVICE_TABLE . "
             where academy_id = '{$academy_id}'
               and status = 'active'
        ");
    }

    $boarding_today = 0;
    $boarding_month = 0;
    if ($has_boarding) {
        $boarding_today = ieum_hq_snapshot_count("
            select count(*) as cnt
              from " . IEUM_VEHICLE_BOARDING_TABLE . "
             where academy_id = '{$academy_id}'
               and journal_date = '{$snapshot_date_sql}'
        ");
        $boarding_month = ieum_hq_snapshot_count("
            select count(*) as cnt
              from " . IEUM_VEHICLE_BOARDING_TABLE . "
             where academy_id = '{$academy_id}'
               and journal_date between '{$month_start_sql}' and '{$month_end_sql}'
        ");
    }

    $active_count = (int) $student['active_count'];
    $character_enabled = (int) $student['character_enabled_count'];
    $fitness_enabled = (int) $student['fitness_enabled_count'];
    $character_entered = (int) $character['entered_count'];
    $fitness_entered = (int) $fitness['entered_count'];
    $vehicle_assigned = (int) $student['vehicle_assigned_count'];
    $failed_count = (int) $bill['failed_count'];

    $alerts = array();
    if ((int) $academy['is_active'] !== 1 || $academy['service_status'] !== 'active') {
        $alerts[] = '서비스 확인';
    }
    if ($active_count > 0 && $sms_device_count === 0) {
        $alerts[] = '문자폰 미연결';
    }
    if ($active_count > 0 && $tablet_device_count === 0) {
        $alerts[] = '출석기 미연결';
    }
    if ($vehicle_assigned > 0 && $boarding_today === 0) {
        $alerts[] = '오늘 차량기록 없음';
    }
    if ($failed_count > 0) {
        $alerts[] = '청구 실패';
    }
    if ($active_count > 0 && $character_enabled === 0) {
        $alerts[] = '인성 미사용';
    } elseif ($character_enabled > 0 && $character_entered === 0) {
        $alerts[] = '인성 입력 없음';
    }
    if ($active_count > 0 && $fitness_enabled === 0) {
        $alerts[] = '체력 미사용';
    } elseif ($fitness_enabled > 0 && $fitness_entered === 0) {
        $alerts[] = '체력 입력 없음';
    }

    return array(
        'academy' => $academy,
        'snapshot_date' => $snapshot_date,
        'snapshot_month' => $month,
        'active_count' => $active_count,
        'total_count' => (int) $student['total_count'],
        'new_count' => (int) $student['new_count'],
        'paused_count' => (int) $flow['paused_in_count'],
        'paused_out_count' => (int) $flow['paused_out_count'],
        'returned_count' => (int) $flow['returned_count'],
        'withdrawn_count' => (int) $flow['withdrawn_count'],
        'current_paused_count' => (int) $student['paused_count'],
        'current_withdrawn_count' => (int) $student['withdrawn_count'],
        'character_enabled' => $character_enabled,
        'character_entered' => $character_entered,
        'fitness_enabled' => $fitness_enabled,
        'fitness_entered' => $fitness_entered,
        'vehicle_assigned' => $vehicle_assigned,
        'boarding_today' => $boarding_today,
        'boarding_month' => $boarding_month,
        'sms_device_count' => $sms_device_count,
        'tablet_device_count' => $tablet_device_count,
        'merchant' => $merchant,
        'bill_count' => (int) $bill['bill_count'],
        'paid_count' => (int) $bill['paid_count'],
        'waiting_count' => (int) $bill['waiting_count'],
        'failed_count' => $failed_count,
        'bill_amount' => (int) $bill['bill_amount'],
        'alerts' => $alerts,
    );
}

function ieum_hq_snapshot_save_row($row, $generated_by = 'auto')
{
    ieum_hq_snapshot_ensure_tables();

    $academy = $row['academy'];
    $academy_id = (int) $academy['academy_id'];
    $snapshot_date = sql_escape_string($row['snapshot_date']);
    $snapshot_month = sql_escape_string($row['snapshot_month']);
    $academy_name = sql_escape_string($academy['academy_name']);
    $academy_code = sql_escape_string($academy['academy_code']);
    $mb_id = sql_escape_string($academy['mb_id']);
    $service_status = sql_escape_string($academy['service_status']);
    $merchant = isset($row['merchant']) && is_array($row['merchant']) ? $row['merchant'] : array();
    $merchant_map_id = !empty($merchant['merchant_map_id']) ? (int) $merchant['merchant_map_id'] : 0;
    $merchant_status = isset($merchant['mapping_status']) ? sql_escape_string($merchant['mapping_status']) : '';
    $alerts = sql_escape_string(json_encode($row['alerts'], JSON_UNESCAPED_UNICODE));
    $generated_by_sql = sql_escape_string($generated_by);
    $now_sql = sql_escape_string(G5_TIME_YMDHIS);

    sql_query("
        insert into " . IEUM_HQ_DAILY_SNAPSHOT_TABLE . " set
            academy_id = '{$academy_id}',
            snapshot_date = '{$snapshot_date}',
            snapshot_month = '{$snapshot_month}',
            academy_name = '{$academy_name}',
            academy_code = '{$academy_code}',
            mb_id = '{$mb_id}',
            service_status = '{$service_status}',
            is_active = '" . (int) $academy['is_active'] . "',
            total_count = '" . (int) $row['total_count'] . "',
            active_count = '" . (int) $row['active_count'] . "',
            new_count = '" . (int) $row['new_count'] . "',
            paused_count = '" . (int) $row['paused_count'] . "',
            paused_out_count = '" . (int) $row['paused_out_count'] . "',
            returned_count = '" . (int) $row['returned_count'] . "',
            withdrawn_count = '" . (int) $row['withdrawn_count'] . "',
            current_paused_count = '" . (int) $row['current_paused_count'] . "',
            current_withdrawn_count = '" . (int) $row['current_withdrawn_count'] . "',
            character_enabled = '" . (int) $row['character_enabled'] . "',
            character_entered = '" . (int) $row['character_entered'] . "',
            fitness_enabled = '" . (int) $row['fitness_enabled'] . "',
            fitness_entered = '" . (int) $row['fitness_entered'] . "',
            vehicle_assigned = '" . (int) $row['vehicle_assigned'] . "',
            boarding_today = '" . (int) $row['boarding_today'] . "',
            boarding_month = '" . (int) $row['boarding_month'] . "',
            sms_device_count = '" . (int) $row['sms_device_count'] . "',
            tablet_device_count = '" . (int) $row['tablet_device_count'] . "',
            merchant_map_id = '{$merchant_map_id}',
            merchant_status = '{$merchant_status}',
            bill_count = '" . (int) $row['bill_count'] . "',
            paid_count = '" . (int) $row['paid_count'] . "',
            waiting_count = '" . (int) $row['waiting_count'] . "',
            failed_count = '" . (int) $row['failed_count'] . "',
            bill_amount = '" . (int) $row['bill_amount'] . "',
            alerts = '{$alerts}',
            generated_by = '{$generated_by_sql}',
            generated_at = '{$now_sql}'
        on duplicate key update
            academy_name = values(academy_name),
            academy_code = values(academy_code),
            mb_id = values(mb_id),
            service_status = values(service_status),
            is_active = values(is_active),
            total_count = values(total_count),
            active_count = values(active_count),
            new_count = values(new_count),
            paused_count = values(paused_count),
            paused_out_count = values(paused_out_count),
            returned_count = values(returned_count),
            withdrawn_count = values(withdrawn_count),
            current_paused_count = values(current_paused_count),
            current_withdrawn_count = values(current_withdrawn_count),
            character_enabled = values(character_enabled),
            character_entered = values(character_entered),
            fitness_enabled = values(fitness_enabled),
            fitness_entered = values(fitness_entered),
            vehicle_assigned = values(vehicle_assigned),
            boarding_today = values(boarding_today),
            boarding_month = values(boarding_month),
            sms_device_count = values(sms_device_count),
            tablet_device_count = values(tablet_device_count),
            merchant_map_id = values(merchant_map_id),
            merchant_status = values(merchant_status),
            bill_count = values(bill_count),
            paid_count = values(paid_count),
            waiting_count = values(waiting_count),
            failed_count = values(failed_count),
            bill_amount = values(bill_amount),
            alerts = values(alerts),
            generated_by = values(generated_by),
            generated_at = values(generated_at)
    ", false);
}

function ieum_hq_snapshot_refresh_all($month = '', $snapshot_date = '', $generated_by = 'auto')
{
    ieum_hq_snapshot_ensure_tables();
    if (function_exists('ieum_paymint_ensure_tables')) {
        ieum_paymint_ensure_tables();
    }

    list($month, , ) = ieum_hq_snapshot_month_bounds($month ?: date('Y-m'));
    $snapshot_date = $snapshot_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshot_date) ? $snapshot_date : G5_TIME_YMD;
    $result = sql_query("
        select *
          from " . IEUM_ACADEMY_TABLE . "
      order by is_active desc, academy_name asc, academy_id asc
    ", false);

    $count = 0;
    while ($academy = sql_fetch_array($result)) {
        $row = ieum_hq_snapshot_compute_academy($academy, $month, $snapshot_date);
        ieum_hq_snapshot_save_row($row, $generated_by);
        $count++;
    }

    return array(
        'success' => true,
        'month' => $month,
        'snapshot_date' => $snapshot_date,
        'count' => $count,
        'generated_by' => $generated_by,
        'generated_at' => G5_TIME_YMDHIS,
    );
}

function ieum_hq_snapshot_latest_generated_at($month = '', $snapshot_date = '')
{
    ieum_hq_snapshot_ensure_tables();
    list($month, , ) = ieum_hq_snapshot_month_bounds($month ?: date('Y-m'));
    $snapshot_date = $snapshot_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshot_date) ? $snapshot_date : G5_TIME_YMD;
    $month_sql = sql_escape_string($month);
    $snapshot_date_sql = sql_escape_string($snapshot_date);

    $row = sql_fetch("
        select max(generated_at) as generated_at, count(*) as cnt
          from " . IEUM_HQ_DAILY_SNAPSHOT_TABLE . "
         where snapshot_month = '{$month_sql}'
           and snapshot_date = '{$snapshot_date_sql}'
    ", false);

    return array(
        'generated_at' => isset($row['generated_at']) ? $row['generated_at'] : '',
        'count' => isset($row['cnt']) ? (int) $row['cnt'] : 0,
    );
}

function ieum_hq_snapshot_row_from_db($snapshot)
{
    $alerts = array();
    if (!empty($snapshot['alerts'])) {
        $decoded = json_decode($snapshot['alerts'], true);
        if (is_array($decoded)) {
            $alerts = $decoded;
        }
    }

    return array(
        'academy' => array(
            'academy_id' => (int) $snapshot['academy_id'],
            'academy_name' => $snapshot['academy_name'],
            'academy_code' => $snapshot['academy_code'],
            'mb_id' => $snapshot['mb_id'],
            'service_status' => $snapshot['service_status'],
            'is_active' => (int) $snapshot['is_active'],
        ),
        'active_count' => (int) $snapshot['active_count'],
        'total_count' => (int) $snapshot['total_count'],
        'new_count' => (int) $snapshot['new_count'],
        'paused_count' => (int) $snapshot['paused_count'],
        'paused_out_count' => (int) $snapshot['paused_out_count'],
        'returned_count' => (int) $snapshot['returned_count'],
        'withdrawn_count' => (int) $snapshot['withdrawn_count'],
        'current_paused_count' => (int) $snapshot['current_paused_count'],
        'current_withdrawn_count' => (int) $snapshot['current_withdrawn_count'],
        'character_enabled' => (int) $snapshot['character_enabled'],
        'character_entered' => (int) $snapshot['character_entered'],
        'fitness_enabled' => (int) $snapshot['fitness_enabled'],
        'fitness_entered' => (int) $snapshot['fitness_entered'],
        'vehicle_assigned' => (int) $snapshot['vehicle_assigned'],
        'boarding_today' => (int) $snapshot['boarding_today'],
        'boarding_month' => (int) $snapshot['boarding_month'],
        'sms_device_count' => (int) $snapshot['sms_device_count'],
        'tablet_device_count' => (int) $snapshot['tablet_device_count'],
        'merchant' => array(
            'merchant_map_id' => (int) $snapshot['merchant_map_id'],
            'mapping_status' => $snapshot['merchant_status'],
        ),
        'bill_count' => (int) $snapshot['bill_count'],
        'paid_count' => (int) $snapshot['paid_count'],
        'waiting_count' => (int) $snapshot['waiting_count'],
        'failed_count' => (int) $snapshot['failed_count'],
        'bill_amount' => (int) $snapshot['bill_amount'],
        'alerts' => $alerts,
    );
}

function ieum_hq_snapshot_load_rows($month = '', $snapshot_date = '')
{
    ieum_hq_snapshot_ensure_tables();
    list($month, , ) = ieum_hq_snapshot_month_bounds($month ?: date('Y-m'));
    $snapshot_date = $snapshot_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshot_date) ? $snapshot_date : G5_TIME_YMD;
    $month_sql = sql_escape_string($month);
    $snapshot_date_sql = sql_escape_string($snapshot_date);

    $rows = array();
    $result = sql_query("
        select *
          from " . IEUM_HQ_DAILY_SNAPSHOT_TABLE . "
         where snapshot_month = '{$month_sql}'
           and snapshot_date = '{$snapshot_date_sql}'
      order by is_active desc, academy_name asc, academy_id asc
    ", false);
    while ($snapshot = sql_fetch_array($result)) {
        $rows[] = ieum_hq_snapshot_row_from_db($snapshot);
    }

    return $rows;
}
