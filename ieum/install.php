<?php
$sub_menu = '950100';
if (!defined('G5_IS_ADMIN')) {
    define('G5_IS_ADMIN', true);
}
require_once './_common.php';
require_once G5_ADMIN_PATH . '/admin.lib.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/academy.php';
require_once IEUM_PATH . '/lib/program.php';

ieum_require_admin_page();

if ($is_admin !== 'super') {
    alert('최고관리자만 설치할 수 있습니다.');
}

$g5['title'] = '아이이음 1구간 DB 설치';

$installed = false;
$run_token = ieum_new_csrf_token();

function ieum_install_add_column_if_missing($table, $column, $definition)
{
    $table_sql = sql_escape_string($table);
    $column_sql = sql_escape_string($column);
    $schema_sql = sql_escape_string(G5_MYSQL_DB);
    $exists = sql_fetch("
        select count(*) as cnt
          from information_schema.COLUMNS
         where TABLE_SCHEMA = '{$schema_sql}'
           and TABLE_NAME = '{$table_sql}'
           and COLUMN_NAME = '{$column_sql}'
    ", false);

    if (!(int) $exists['cnt']) {
        sql_query("alter table {$table} add column {$column} {$definition}");
    }
}

function ieum_install_drop_index_if_exists($table, $index)
{
    $table_sql = sql_escape_string($table);
    $index_sql = sql_escape_string($index);
    $schema_sql = sql_escape_string(G5_MYSQL_DB);
    $exists = sql_fetch("
        select count(*) as cnt
          from information_schema.STATISTICS
         where TABLE_SCHEMA = '{$schema_sql}'
           and TABLE_NAME = '{$table_sql}'
           and INDEX_NAME = '{$index_sql}'
    ", false);

    if ((int) $exists['cnt']) {
        sql_query("alter table {$table} drop index {$index}");
    }
}

function ieum_install_add_index_if_missing($table, $index, $definition)
{
    $table_sql = sql_escape_string($table);
    $index_sql = sql_escape_string($index);
    $schema_sql = sql_escape_string(G5_MYSQL_DB);
    $exists = sql_fetch("
        select count(*) as cnt
          from information_schema.STATISTICS
         where TABLE_SCHEMA = '{$schema_sql}'
           and TABLE_NAME = '{$table_sql}'
           and INDEX_NAME = '{$index_sql}'
    ", false);

    if (!(int) $exists['cnt']) {
        sql_query("alter table {$table} add index {$index} {$definition}");
    }
}

if (isset($_GET['run']) && $_GET['run'] === '1') {
    $token = isset($_GET['ieum_token']) ? trim($_GET['ieum_token']) : '';
    if (!ieum_verify_csrf_token($token)) {
        alert('아이이음 설치 토큰이 올바르지 않습니다.');
    }

    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    sql_query("
        create table if not exists " . IEUM_ACADEMY_TABLE . " (
            academy_id int unsigned not null auto_increment,
            mb_id varchar(50) not null default '',
            academy_code varchar(30) not null,
            academy_name varchar(100) not null,
            tablet_pin varchar(20) not null default '110022',
            gateway_token varchar(100) not null,
            service_status varchar(20) not null default 'active',
            sms_start_time char(5) not null default '10:00',
            sms_end_time char(5) not null default '20:00',
            sms_day_mode varchar(20) not null default 'weekday',
            sms_poll_seconds int unsigned not null default 30,
            promotion_interval_months tinyint unsigned not null default 3,
            promotion_belts text null,
            promotion_belt_ranges text null,
            promotion_notice_days smallint unsigned not null default 31,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (academy_id),
            unique key uq_academy_code (academy_code),
            unique key uq_gateway_token (gateway_token),
            key idx_mb_id (mb_id),
            key idx_service_status (service_status)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        insert into " . IEUM_ACADEMY_TABLE . "
            set academy_id = 1,
                mb_id = 'admin',
                academy_code = 'LOCAL001',
                academy_name = '아이이음 테스트 도장',
                tablet_pin = '110022',
                gateway_token = 'ieum-local-gateway-token-2026',
                service_status = 'active',
                sms_start_time = '10:00',
                sms_end_time = '20:00',
                sms_day_mode = 'weekday',
                sms_poll_seconds = 30,
                promotion_interval_months = 3,
                is_active = 1,
                created_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                academy_name = values(academy_name),
                tablet_pin = values(tablet_pin),
                gateway_token = values(gateway_token),
                updated_at = '" . G5_TIME_YMDHIS . "'
    ");

    sql_query("
        create table if not exists " . IEUM_STUDENT_TABLE . " (
            student_id int unsigned not null auto_increment,
            academy_id int unsigned not null default 1,
            student_code varchar(20) not null,
            student_name varchar(50) not null,
            student_phone varchar(30) not null default '',
            student_photo varchar(255) not null default '',
            birth_date date null,
            program_code varchar(50) not null default '',
            school_name varchar(100) not null default '',
            grade_group varchar(20) not null default '',
            class_time_id int unsigned not null default 0,
            attendance_week_type varchar(20) not null default '5',
            attendance_days varchar(50) not null default 'mon,tue,wed,thu,fri',
            admission_date date null,
            student_status varchar(20) not null default 'enrolled',
            enrollment_source varchar(50) not null default '',
            referrer_name varchar(80) not null default '',
            counseling_note varchar(255) not null default '',
            tuition_week_type varchar(20) not null default '5',
            tuition_amount int unsigned not null default 0,
            sibling_discount_enabled tinyint(1) not null default 0,
            sibling_discount_amount int unsigned not null default 0,
            family_billing_enabled tinyint(1) not null default 0,
            family_billing_key varchar(80) not null default '',
            family_billing_label varchar(80) not null default '',
            family_billing_primary tinyint(1) not null default 0,
            tuition_due_day tinyint unsigned not null default 5,
            tuition_note varchar(255) not null default '',
            vehicle_pickup_enabled tinyint(1) not null default 0,
            vehicle_pickup_place varchar(100) not null default '',
            vehicle_dropoff_enabled tinyint(1) not null default 0,
            vehicle_dropoff_place varchar(100) not null default '',
            promotion_enabled tinyint(1) not null default 1,
            current_belt varchar(80) not null default '',
            current_poom_dan tinyint unsigned not null default 0,
            current_grade_level tinyint unsigned not null default 0,
            last_promotion_date date null,
            promotion_cycle_months tinyint unsigned not null default 0,
            promotion_memo varchar(255) not null default '',
            parent_name varchar(50) not null default '',
            parent_phone varchar(30) not null default '',
            memo varchar(255) not null default '',
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (student_id),
            key idx_academy_student_code (academy_id, student_code),
            key idx_active_name (academy_id, is_active, student_name)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_ACADEMY_PROGRAM_TABLE . " (
            program_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            program_code varchar(50) not null,
            program_name varchar(80) not null,
            is_default tinyint(1) not null default 0,
            is_active tinyint(1) not null default 1,
            sort_order int unsigned not null default 0,
            created_at datetime not null,
            updated_at datetime null,
            primary key (program_id),
            unique key uq_academy_program (academy_id, program_code),
            key idx_academy_sort (academy_id, is_active, sort_order)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_seed_default_programs(1);

    sql_query("
        create table if not exists " . IEUM_CLASS_TIME_TABLE . " (
            class_time_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            class_name varchar(50) not null,
            start_time char(5) not null,
            sort_order int unsigned not null default 0,
            absent_alert_enabled tinyint(1) not null default 1,
            absent_alert_after_minutes smallint unsigned not null default 10,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (class_time_id),
            key idx_academy_sort (academy_id, sort_order, start_time)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_ACADEMY_CALENDAR_TABLE . " (
            calendar_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            calendar_date date not null,
            day_type varchar(20) not null default 'closed',
            title varchar(100) not null default '',
            memo varchar(255) not null default '',
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (calendar_id),
            unique key uq_academy_date_type (academy_id, calendar_date, day_type),
            key idx_academy_date (academy_id, calendar_date, is_active)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_DASHBOARD_SHORTCUT_TABLE . " (
            shortcut_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            shortcut_key varchar(50) not null,
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (shortcut_id),
            unique key uq_academy_shortcut (academy_id, shortcut_key),
            key idx_academy_sort (academy_id, is_active, sort_order)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_DASHBOARD_TODAY_TASK_TABLE . " (
            task_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            task_key varchar(50) not null,
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (task_id),
            unique key uq_academy_today_task (academy_id, task_key),
            key idx_academy_sort (academy_id, is_active, sort_order)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_AUTO_CHECK_RESOLVE_TABLE . " (
            resolve_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            check_type varchar(50) not null,
            target_key varchar(80) not null,
            target_date date not null,
            memo varchar(255) not null default '',
            resolved_by varchar(50) not null default '',
            resolved_at datetime not null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (resolve_id),
            unique key uq_auto_check_target (academy_id, check_type, target_key, target_date),
            key idx_academy_type_date (academy_id, check_type, target_date)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_IMPORT_MAPPING_TABLE . " (
            mapping_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            mapping_type varchar(30) not null,
            source_hash char(32) not null,
            source_value varchar(190) not null default '',
            target_value varchar(190) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (mapping_id),
            unique key uq_academy_import_mapping (academy_id, mapping_type, source_hash),
            key idx_academy_mapping_type (academy_id, mapping_type)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_IMPORT_LOG_TABLE . " (
            import_log_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            filename varchar(190) not null default '',
            insert_count int unsigned not null default 0,
            update_count int unsigned not null default 0,
            skip_count int unsigned not null default 0,
            warning_count int unsigned not null default 0,
            created_by varchar(50) not null default '',
            created_at datetime not null,
            primary key (import_log_id),
            key idx_academy_created (academy_id, created_at)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_TABLET_DEVICE_TABLE . " (
            device_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            device_name varchar(80) not null default '',
            pairing_code char(6) not null default '',
            device_uid varchar(100) not null default '',
            device_token varchar(100) not null default '',
            status varchar(20) not null default 'pending',
            expires_at datetime null,
            paired_at datetime null,
            last_seen_at datetime null,
            created_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (device_id),
            key idx_pairing_code (pairing_code, status, expires_at),
            key idx_device_token (device_token),
            key idx_academy_status (academy_id, status, created_at),
            key idx_device_uid (device_uid)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_HQ_BILLING_WALLET_TABLE . " (
            wallet_id tinyint unsigned not null,
            balance_amount int not null default 0,
            total_charged int not null default 0,
            total_used int not null default 0,
            created_at datetime not null,
            updated_at datetime null,
            primary key (wallet_id)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_HQ_BILLING_WALLET_LOG_TABLE . " (
            log_id int unsigned not null auto_increment,
            wallet_id tinyint unsigned not null default 1,
            academy_id int unsigned not null default 0,
            payment_id int unsigned not null default 0,
            log_type varchar(30) not null default '',
            amount int not null default 0,
            balance_after int not null default 0,
            description varchar(255) not null default '',
            created_by varchar(50) not null default '',
            created_at datetime not null,
            primary key (log_id),
            key idx_wallet_created (wallet_id, created_at),
            key idx_academy_created (academy_id, created_at),
            key idx_payment (payment_id)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_BILLING_SEND_LOG_TABLE . " (
            send_log_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            payment_id int unsigned not null default 0,
            student_id int unsigned not null default 0,
            provider varchar(50) not null default '',
            external_bill_id varchar(100) not null default '',
            recipient_phone varchar(30) not null default '',
            send_type varchar(30) not null default 'tuition_bill',
            bill_amount int unsigned not null default 0,
            current_amount int unsigned not null default 0,
            arrears_amount int unsigned not null default 0,
            arrears_months varchar(120) not null default '',
            send_fee int unsigned not null default 0,
            status varchar(30) not null default '',
            error_message varchar(255) not null default '',
            created_at datetime not null,
            primary key (send_log_id),
            key idx_academy_created (academy_id, created_at),
            key idx_payment (payment_id),
            key idx_provider_bill (provider, external_bill_id)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_BILLING_SEND_LOG_TABLE, 'bill_amount', 'int unsigned not null default 0 after send_type');
    ieum_install_add_column_if_missing(IEUM_BILLING_SEND_LOG_TABLE, 'current_amount', 'int unsigned not null default 0 after bill_amount');
    ieum_install_add_column_if_missing(IEUM_BILLING_SEND_LOG_TABLE, 'arrears_amount', 'int unsigned not null default 0 after current_amount');
    ieum_install_add_column_if_missing(IEUM_BILLING_SEND_LOG_TABLE, 'arrears_months', "varchar(120) not null default '' after arrears_amount");

    sql_query("
        create table if not exists " . IEUM_PAYMINT_SETTING_TABLE . " (
            setting_id tinyint unsigned not null default 1,
            environment varchar(20) not null default 'sandbox',
            partner_mode varchar(30) not null default 'partner_managed',
            partner_member_id varchar(80) not null default '',
            partner_merchant_id varchar(80) not null default '',
            api_key varchar(160) not null default '',
            api_secret varchar(160) not null default '',
            callback_token varchar(120) not null default '',
            default_send_type varchar(20) not null default 'TALK',
            use_url_mode_for_test tinyint(1) not null default 1,
            prevent_duplicate_bill tinyint(1) not null default 1,
            exclude_paid_students tinyint(1) not null default 1,
            include_arrears_default tinyint(1) not null default 1,
            resend_cooldown_hours smallint unsigned not null default 24,
            low_balance_threshold int unsigned not null default 5000,
            monthly_send_cap_per_academy int unsigned not null default 0,
            bill_expire_days tinyint unsigned not null default 7,
            memo varchar(255) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (setting_id)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        insert into " . IEUM_PAYMINT_SETTING_TABLE . "
            set setting_id = 1,
                environment = 'sandbox',
                partner_mode = 'partner_managed',
                callback_token = '" . sql_escape_string(md5(uniqid('paymint', true))) . "',
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                updated_at = updated_at
    ");

    sql_query("
        create table if not exists " . IEUM_PAYMINT_MERCHANT_TABLE . " (
            merchant_map_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            member_id varchar(80) not null default '',
            merchant_id varchar(80) not null default '',
            business_number varchar(30) not null default '',
            mapping_status varchar(30) not null default 'pending',
            callback_payload mediumtext null,
            mapped_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (merchant_map_id),
            unique key uq_academy (academy_id),
            key idx_member_merchant (member_id, merchant_id),
            key idx_status (mapping_status)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_PAYMINT_BILL_TABLE . " (
            paymint_bill_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            payment_id int unsigned not null default 0,
            student_id int unsigned not null default 0,
            billing_month char(7) not null default '',
            bill_id varchar(20) not null default '',
            paymint_hash varchar(160) not null default '',
            short_url varchar(255) not null default '',
            send_type varchar(20) not null default 'TALK',
            product_name varchar(120) not null default '',
            bill_amount int unsigned not null default 0,
            current_amount int unsigned not null default 0,
            arrears_amount int unsigned not null default 0,
            arrears_months varchar(120) not null default '',
            recipient_name varchar(80) not null default '',
            recipient_phone varchar(30) not null default '',
            appr_state char(1) not null default 'W',
            status varchar(30) not null default 'created',
            sent_at datetime null,
            paid_at datetime null,
            canceled_at datetime null,
            destroyed_at datetime null,
            expires_at datetime null,
            last_error varchar(255) not null default '',
            raw_response mediumtext null,
            created_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (paymint_bill_id),
            unique key uq_bill_id (bill_id),
            key idx_payment (payment_id),
            key idx_student_month (academy_id, student_id, billing_month),
            key idx_status_created (status, created_at)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_PAYMINT_BILL_LOG_TABLE . " (
            bill_log_id int unsigned not null auto_increment,
            paymint_bill_id int unsigned not null default 0,
            academy_id int unsigned not null default 0,
            payment_id int unsigned not null default 0,
            log_type varchar(30) not null default '',
            message varchar(255) not null default '',
            payload mediumtext null,
            created_at datetime not null,
            primary key (bill_log_id),
            key idx_bill (paymint_bill_id),
            key idx_academy_created (academy_id, created_at),
            key idx_payment (payment_id)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_PAYMINT_CALLBACK_TABLE . " (
            callback_id int unsigned not null auto_increment,
            callback_type varchar(30) not null default '',
            bill_id varchar(20) not null default '',
            academy_id int unsigned not null default 0,
            payment_id int unsigned not null default 0,
            appr_state char(1) not null default '',
            payload mediumtext null,
            result_code varchar(20) not null default '',
            result_message varchar(255) not null default '',
            received_at datetime not null,
            primary key (callback_id),
            key idx_bill_id (bill_id),
            key idx_type_received (callback_type, received_at),
            key idx_academy_received (academy_id, received_at)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_PAYMINT_USAGE_MONTHLY_TABLE . " (
            usage_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            usage_month char(7) not null,
            bill_send_count int unsigned not null default 0,
            paid_count int unsigned not null default 0,
            failed_count int unsigned not null default 0,
            destroyed_count int unsigned not null default 0,
            bill_amount int unsigned not null default 0,
            updated_at datetime null,
            primary key (usage_id),
            unique key uq_academy_month (academy_id, usage_month)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_STUDENT_GUARDIAN_TABLE . " (
            guardian_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            guardian_name varchar(50) not null default '',
            guardian_relation varchar(30) not null default '',
            guardian_phone varchar(30) not null default '',
            sms_attendance tinyint(1) not null default 1,
            sms_checkout tinyint(1) not null default 0,
            sms_tuition tinyint(1) not null default 1,
            use_for_student_code tinyint(1) not null default 0,
            is_primary tinyint(1) not null default 0,
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (guardian_id),
            key idx_student_active (academy_id, student_id, is_active),
            key idx_phone (guardian_phone)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_VEHICLE_ROUTE_TABLE . " (
            route_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            route_type varchar(20) not null default 'both',
            route_name varchar(80) not null default '',
            vehicle_label varchar(50) not null default '',
            driver_name varchar(50) not null default '',
            driver_phone varchar(30) not null default '',
            driver_pin varchar(20) not null default '',
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (route_id),
            key idx_academy_type (academy_id, route_type, is_active, sort_order)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_VEHICLE_ROUTE_TABLE, 'driver_pin', "varchar(20) not null default '' after driver_phone");

    sql_query("
        create table if not exists " . IEUM_VEHICLE_STOP_TABLE . " (
            stop_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            route_id int unsigned not null default 0,
            stop_type varchar(20) not null,
            stop_name varchar(100) not null default '',
            stop_address varchar(160) not null default '',
            map_lat decimal(10,7) null,
            map_lng decimal(10,7) null,
            map_url varchar(255) not null default '',
            stop_time char(5) not null default '00:00',
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (stop_id),
            key idx_academy_type_time (academy_id, stop_type, is_active, stop_time),
            key idx_route_sort (academy_id, route_id, sort_order)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_STUDENT_VEHICLE_TABLE . " (
            student_vehicle_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            ride_type varchar(20) not null,
            route_id int unsigned not null default 0,
            stop_id int unsigned not null default 0,
            place_name varchar(100) not null default '',
            contact_phone varchar(30) not null default '',
            ride_days varchar(50) not null default '',
            memo varchar(255) not null default '',
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (student_vehicle_id),
            key idx_student_type (academy_id, student_id, ride_type, is_active),
            key idx_stop (academy_id, stop_id, is_active),
            key idx_route (academy_id, route_id, is_active)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_STUDENT_VEHICLE_TABLE, 'stop_id', 'int unsigned not null default 0 after route_id');
    ieum_install_add_column_if_missing(IEUM_STUDENT_VEHICLE_TABLE, 'contact_phone', "varchar(30) not null default '' after place_name");
    ieum_install_add_column_if_missing(IEUM_STUDENT_VEHICLE_TABLE, 'ride_days', "varchar(50) not null default '' after contact_phone");

    sql_query("
        create table if not exists " . IEUM_VEHICLE_BOARDING_TABLE . " (
            log_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            journal_date date not null,
            student_vehicle_id int unsigned not null,
            student_id int unsigned not null,
            ride_type varchar(20) not null,
            route_id int unsigned not null default 0,
            stop_id int unsigned not null default 0,
            status varchar(30) not null default '',
            note varchar(255) not null default '',
            checked_by varchar(50) not null default '',
            checked_at datetime null,
            resolved_by varchar(50) not null default '',
            resolved_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (log_id),
            unique key uq_vehicle_day (academy_id, journal_date, student_vehicle_id),
            key idx_journal_status (academy_id, journal_date, status),
            key idx_route_day (academy_id, journal_date, route_id, ride_type)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_VEHICLE_BOARDING_TABLE, 'resolved_by', "varchar(50) not null default '' after checked_at");
    ieum_install_add_column_if_missing(IEUM_VEHICLE_BOARDING_TABLE, 'resolved_at', 'datetime null after resolved_by');

    sql_query("
        create table if not exists " . IEUM_VEHICLE_RUN_TABLE . " (
            run_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            journal_date date not null,
            ride_type varchar(20) not null,
            route_id int unsigned not null default 0,
            vehicle_label varchar(50) not null default '',
            driver_member_id varchar(50) not null default '',
            status varchar(20) not null default 'active',
            started_at datetime not null,
            ended_at datetime null,
            last_lat decimal(10,7) null,
            last_lng decimal(10,7) null,
            last_location_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (run_id),
            key idx_active_run (academy_id, journal_date, ride_type, route_id, vehicle_label, status),
            key idx_driver_day (academy_id, journal_date, driver_member_id)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_VEHICLE_LOCATION_TABLE . " (
            location_id int unsigned not null auto_increment,
            run_id int unsigned not null,
            academy_id int unsigned not null,
            journal_date date not null,
            ride_type varchar(20) not null,
            route_id int unsigned not null default 0,
            vehicle_label varchar(50) not null default '',
            lat decimal(10,7) not null,
            lng decimal(10,7) not null,
            accuracy decimal(10,2) null,
            recorded_at datetime not null,
            created_at datetime not null,
            primary key (location_id),
            key idx_run_time (run_id, recorded_at),
            key idx_academy_day (academy_id, journal_date, vehicle_label, ride_type)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_ATTENDANCE_TABLE . " (
            attendance_id int unsigned not null auto_increment,
            academy_id int unsigned not null default 1,
            student_id int unsigned not null,
            attendance_date date not null,
            checked_at datetime not null,
            input_source varchar(20) not null default 'kiosk',
            created_by varchar(50) not null default '',
            created_at datetime not null,
            primary key (attendance_id),
            unique key uq_academy_student_date (academy_id, student_id, attendance_date),
            key idx_attendance_date (academy_id, attendance_date),
            key idx_checked_at (checked_at)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_SMS_QUEUE_TABLE . " (
            sms_id int unsigned not null auto_increment,
            academy_id int unsigned not null default 1,
            student_id int unsigned not null,
            attendance_id int unsigned not null default 0,
            recipient_phone varchar(30) not null,
            message text not null,
            message_type varchar(30) not null default 'checkin',
            source_key varchar(100) not null default '',
            status varchar(20) not null default 'pending',
            scheduled_at datetime null,
            gateway_device varchar(100) not null default '',
            error_message varchar(255) not null default '',
            created_at datetime not null,
            sent_at datetime null,
            primary key (sms_id),
            key idx_status_created (academy_id, status, created_at),
            key idx_pending_schedule (academy_id, status, scheduled_at, sms_id),
            key idx_source_key (academy_id, message_type, source_key),
            key idx_attendance_id (attendance_id),
            key idx_student_id (student_id)
        ) engine={$engine} default charset={$charset}
    ");

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
    ");

    sql_query("
        create table if not exists " . IEUM_REPORT_CHARACTER_TABLE . " (
            character_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            week_start date not null,
            courtesy tinyint unsigned not null default 3,
            focus tinyint unsigned not null default 3,
            confidence tinyint unsigned not null default 3,
            consideration tinyint unsigned not null default 3,
            special_score tinyint unsigned not null default 0,
            special_reason varchar(80) not null default '',
            memo varchar(255) not null default '',
            created_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (character_id),
            unique key uq_character_week (academy_id, student_id, week_start),
            key idx_academy_week (academy_id, week_start)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_REPORT_CHARACTER_TABLE, 'memo', "varchar(255) not null default ''");
    ieum_install_add_column_if_missing(IEUM_REPORT_CHARACTER_TABLE, 'special_score', "tinyint unsigned not null default 0");
    ieum_install_add_column_if_missing(IEUM_REPORT_CHARACTER_TABLE, 'special_reason', "varchar(80) not null default ''");

    sql_query("
        create table if not exists " . IEUM_REPORT_FITNESS_TABLE . " (
            fitness_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            report_month char(7) not null,
            height_cm decimal(5,1) null,
            weight_kg decimal(5,1) null,
            jump_rope int unsigned null,
            shuttle_run decimal(6,2) null,
            push_up int unsigned null,
            sit_up int unsigned null,
            long_jump decimal(6,1) null,
            flexibility decimal(6,1) null,
            memo varchar(255) not null default '',
            created_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (fitness_id),
            unique key uq_fitness_month (academy_id, student_id, report_month),
            key idx_academy_month (academy_id, report_month)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_REPORT_FITNESS_TABLE, 'height_cm', "decimal(5,1) null after report_month");
    ieum_install_add_column_if_missing(IEUM_REPORT_FITNESS_TABLE, 'weight_kg', "decimal(5,1) null after height_cm");

    sql_query("
        create table if not exists " . IEUM_FITNESS_STANDARD_TABLE . " (
            fitness_standard_id int unsigned not null auto_increment,
            academy_id int unsigned not null default 0,
            grade_group varchar(20) not null default '',
            gender varchar(10) not null default 'all',
            metric_key varchar(40) not null,
            level_no tinyint unsigned not null,
            min_value decimal(8,2) null,
            max_value decimal(8,2) null,
            score_min tinyint unsigned not null default 0,
            score_max tinyint unsigned not null default 0,
            label varchar(50) not null default '',
            source varchar(100) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (fitness_standard_id),
            unique key uq_fitness_standard (academy_id, grade_group, gender, metric_key, level_no),
            key idx_lookup (academy_id, grade_group, gender, metric_key)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_FITNESS_SETTING_TABLE . " (
            academy_id int unsigned not null,
            cycle_months tinyint unsigned not null default 1,
            report_send_enabled tinyint(1) not null default 0,
            report_send_day tinyint unsigned not null default 25,
            custom_metric_enabled tinyint(1) not null default 0,
            memo varchar(255) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (academy_id)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_FITNESS_METRIC_TABLE . " (
            metric_id int unsigned not null auto_increment,
            academy_id int unsigned not null default 0,
            metric_key varchar(40) not null,
            metric_label varchar(80) not null,
            unit varchar(20) not null default '',
            metric_type varchar(20) not null default 'higher',
            is_body tinyint(1) not null default 0,
            is_score_item tinyint(1) not null default 1,
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (metric_id),
            unique key uq_fitness_metric (academy_id, metric_key),
            key idx_fitness_metric_order (academy_id, is_active, sort_order)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_FITNESS_VALUE_TABLE . " (
            value_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            report_month char(7) not null,
            metric_key varchar(40) not null,
            metric_value decimal(10,2) null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (value_id),
            unique key uq_fitness_metric_value (academy_id, student_id, report_month, metric_key),
            key idx_metric_month (academy_id, report_month, metric_key)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_CHARACTER_MISSION_TABLE . " (
            mission_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            mission_month char(7) not null,
            mission_title varchar(100) not null default '',
            mission_theme varchar(80) not null default '',
            guide_url varchar(255) not null default '',
            guide_summary text null,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (mission_id),
            unique key uq_academy_month (academy_id, mission_month),
            key idx_academy_active (academy_id, is_active, mission_month)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_CHARACTER_MISSION_STUDENT_TABLE . " (
            mission_student_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            mission_id int unsigned not null default 0,
            student_id int unsigned not null,
            mission_month char(7) not null,
            participation_status varchar(20) not null default 'none',
            proof_memo varchar(255) not null default '',
            proof_url varchar(255) not null default '',
            bonus_score tinyint unsigned not null default 0,
            checked_by varchar(50) not null default '',
            checked_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (mission_student_id),
            unique key uq_student_month (academy_id, student_id, mission_month),
            key idx_mission (academy_id, mission_id, participation_status)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_CHARACTER_LEVEL_TABLE . " (
            snapshot_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            snapshot_month char(7) not null,
            monthly_score smallint unsigned not null default 0,
            mission_bonus tinyint unsigned not null default 0,
            growth_points smallint unsigned not null default 0,
            cumulative_points int unsigned not null default 0,
            level_key varchar(30) not null default '',
            level_label varchar(50) not null default '',
            next_level_label varchar(50) not null default '',
            points_to_next int unsigned not null default 0,
            updated_at datetime not null,
            primary key (snapshot_id),
            unique key uq_character_level_month (academy_id, student_id, snapshot_month),
            key idx_academy_level (academy_id, snapshot_month, level_key)
        ) engine={$engine} default charset={$charset}
    ");

    ieum_install_add_column_if_missing(IEUM_CLASS_TIME_TABLE, 'absent_alert_enabled', 'tinyint(1) not null default 1');
    ieum_install_add_column_if_missing(IEUM_CLASS_TIME_TABLE, 'absent_alert_after_minutes', 'smallint unsigned not null default 10');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'attendance_week_type', "varchar(20) not null default '5'");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'student_phone', "varchar(30) not null default '' after student_name");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'student_photo', "varchar(255) not null default '' after student_phone");
    ieum_install_drop_index_if_exists(IEUM_STUDENT_TABLE, 'uq_academy_student_code');
    ieum_install_add_index_if_missing(IEUM_STUDENT_TABLE, 'idx_academy_student_code', '(academy_id, student_code)');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'birth_date', 'date null after student_phone');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'program_code', "varchar(50) not null default '' after birth_date");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'school_name', "varchar(100) not null default '' after birth_date");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'attendance_days', "varchar(50) not null default 'mon,tue,wed,thu,fri'");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'admission_date', 'date null');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'student_status', "varchar(20) not null default 'enrolled' after admission_date");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'enrollment_source', "varchar(50) not null default '' after student_status");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'referrer_name', "varchar(80) not null default '' after enrollment_source");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'counseling_note', "varchar(255) not null default '' after referrer_name");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'tuition_week_type', "varchar(20) not null default '5'");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'tuition_amount', 'int unsigned not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'sibling_discount_enabled', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'sibling_discount_amount', 'int unsigned not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'family_billing_enabled', 'tinyint(1) not null default 0 after sibling_discount_amount');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'family_billing_key', "varchar(80) not null default '' after family_billing_enabled");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'family_billing_label', "varchar(80) not null default '' after family_billing_key");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'family_billing_primary', 'tinyint(1) not null default 0 after family_billing_label');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'tuition_due_day', 'tinyint unsigned not null default 5');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'tuition_note', "varchar(255) not null default ''");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'vehicle_pickup_enabled', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'vehicle_pickup_place', "varchar(100) not null default ''");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'vehicle_dropoff_enabled', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'vehicle_dropoff_place', "varchar(100) not null default ''");
    ieum_install_add_column_if_missing(IEUM_STUDENT_GUARDIAN_TABLE, 'guardian_relation', "varchar(30) not null default ''");
    ieum_install_add_column_if_missing(IEUM_STUDENT_GUARDIAN_TABLE, 'sms_checkout', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_GUARDIAN_TABLE, 'sms_tuition', 'tinyint(1) not null default 1 after sms_checkout');
    ieum_install_add_column_if_missing(IEUM_STUDENT_GUARDIAN_TABLE, 'use_for_student_code', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_GUARDIAN_TABLE, 'is_primary', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_SMS_QUEUE_TABLE, 'message_type', "varchar(30) not null default 'checkin' after message");
    ieum_install_add_column_if_missing(IEUM_SMS_QUEUE_TABLE, 'source_key', "varchar(100) not null default '' after message_type");
    ieum_install_add_column_if_missing(IEUM_SMS_QUEUE_TABLE, 'scheduled_at', "datetime null after status");
    ieum_install_add_index_if_missing(IEUM_SMS_QUEUE_TABLE, 'idx_pending_schedule', '(academy_id, status, scheduled_at, sms_id)');
    ieum_install_add_index_if_missing(IEUM_SMS_QUEUE_TABLE, 'idx_source_key', '(academy_id, message_type, source_key)');
    sql_query("
        create table if not exists " . IEUM_ACADEMY_CONTACT_TABLE . " (
            contact_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            contact_name varchar(50) not null default '',
            contact_phone varchar(30) not null default '',
            sms_absent_alert tinyint(1) not null default 1,
            sms_vehicle_alert tinyint(1) not null default 1,
            sms_system_alert tinyint(1) not null default 1,
            sms_memo_alert tinyint(1) not null default 1,
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (contact_id),
            key idx_academy_active (academy_id, is_active),
            key idx_phone (contact_phone)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_ACADEMY_CONTACT_TABLE, 'sms_vehicle_alert', 'tinyint(1) not null default 1 after sms_absent_alert');
    ieum_install_add_column_if_missing(IEUM_ACADEMY_CONTACT_TABLE, 'sms_memo_alert', 'tinyint(1) not null default 1 after sms_system_alert');

    sql_query("
        create table if not exists " . IEUM_ABSENT_ALERT_LOG_TABLE . " (
            alert_log_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            class_time_id int unsigned not null,
            alert_date date not null,
            target_time datetime not null,
            student_ids text not null,
            sms_queue_ids text not null,
            created_at datetime not null,
            primary key (alert_log_id),
            unique key uq_academy_class_date (academy_id, class_time_id, alert_date)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_PROJECT_TASK_TABLE . " (
            task_id int unsigned not null auto_increment,
            title varchar(150) not null default '',
            instruction text not null,
            status varchar(20) not null default 'requested',
            priority tinyint unsigned not null default 3,
            result_summary text not null,
            result_detail text not null,
            created_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            completed_at datetime null,
            primary key (task_id),
            key idx_status_priority (status, priority, task_id)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_STUDENT_STATUS_LOG_TABLE . " (
            status_log_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            before_status varchar(20) not null default '',
            after_status varchar(20) not null default '',
            changed_date date not null,
            reason varchar(100) not null default '',
            memo varchar(255) not null default '',
            created_by varchar(50) not null default '',
            created_at datetime not null,
            primary key (status_log_id),
            key idx_academy_date (academy_id, changed_date),
            key idx_student_date (academy_id, student_id, changed_date)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_TUITION_PLAN_TABLE . " (
            plan_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            week_type varchar(20) not null default '5',
            plan_name varchar(50) not null default '',
            monthly_fee int unsigned not null default 0,
            sibling_discount_amount int unsigned not null default 0,
            default_due_day tinyint unsigned not null default 5,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (plan_id),
            key idx_academy_week (academy_id, week_type, is_active)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_TUITION_PLAN_TABLE, 'default_due_day', 'tinyint unsigned not null default 5');

    sql_query("
        create table if not exists " . IEUM_TUITION_PAYMENT_TABLE . " (
            payment_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            billing_month char(7) not null,
            due_date date not null,
            amount_due int unsigned not null default 0,
            amount_paid int unsigned not null default 0,
            status varchar(20) not null default 'unpaid',
            memo varchar(255) not null default '',
            notice_sent_at datetime null,
            notice_count smallint unsigned not null default 0,
            payment_provider varchar(50) not null default '',
            external_bill_id varchar(100) not null default '',
            payment_link varchar(255) not null default '',
            provider_status varchar(30) not null default '',
            bill_sent_at datetime null,
            bill_send_count int unsigned not null default 0,
            bill_send_fee_total int unsigned not null default 0,
            bill_auto_send_enabled tinyint(1) not null default 1,
            paid_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (payment_id),
            unique key uq_student_month (academy_id, student_id, billing_month),
            key idx_status_due (academy_id, status, due_date)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'notice_sent_at', 'datetime null after memo');
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'notice_count', 'smallint unsigned not null default 0 after notice_sent_at');
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'payment_provider', "varchar(50) not null default '' after notice_count");
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'external_bill_id', "varchar(100) not null default '' after payment_provider");
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'payment_link', "varchar(255) not null default '' after external_bill_id");
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'provider_status', "varchar(30) not null default '' after payment_link");
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'bill_sent_at', 'datetime null after provider_status');
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'bill_send_count', 'int unsigned not null default 0 after bill_sent_at');
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'bill_send_fee_total', 'int unsigned not null default 0 after bill_send_count');
    ieum_install_add_column_if_missing(IEUM_TUITION_PAYMENT_TABLE, 'bill_auto_send_enabled', 'tinyint(1) not null default 1 after bill_send_fee_total');
    ieum_install_add_column_if_missing(IEUM_ACADEMY_TABLE, 'tablet_pin', "varchar(20) not null default '110022' after academy_name");
    ieum_install_add_column_if_missing(IEUM_ACADEMY_TABLE, 'sms_day_mode', "varchar(20) not null default 'weekday' after sms_end_time");
    ieum_install_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_belts', 'text null after promotion_interval_months');
    ieum_install_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_belt_ranges', 'text null after promotion_belts');
    ieum_install_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_notice_days', 'smallint unsigned not null default 31 after promotion_belts');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'promotion_enabled', 'tinyint(1) not null default 1 after vehicle_dropoff_place');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'current_belt', "varchar(80) not null default '' after promotion_enabled");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'current_poom_dan', 'tinyint unsigned not null default 0 after current_belt');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'current_grade_level', 'tinyint unsigned not null default 0 after current_poom_dan');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'last_promotion_date', 'date null after current_grade_level');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'promotion_cycle_months', 'tinyint unsigned not null default 0 after last_promotion_date');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'promotion_memo', "varchar(255) not null default '' after promotion_cycle_months");

    sql_query("
        create table if not exists " . IEUM_SMS_TEMPLATE_TABLE . " (
            template_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            template_key varchar(50) not null,
            title varchar(100) not null default '',
            message text not null,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (template_id),
            unique key uq_academy_template (academy_id, template_key)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_TUITION_SETTING_TABLE . " (
            academy_id int unsigned not null,
            due_notice_enabled tinyint(1) not null default 1,
            overdue_notice_enabled tinyint(1) not null default 0,
            overdue_after_days tinyint unsigned not null default 5,
            bill_auto_send_enabled tinyint(1) not null default 0,
            bill_auto_send_day tinyint unsigned not null default 5,
            bill_auto_send_scope varchar(20) not null default 'all',
            bill_auto_include_arrears tinyint(1) not null default 1,
            updated_at datetime null,
            primary key (academy_id)
        ) engine={$engine} default charset={$charset}
    ");
    ieum_install_add_column_if_missing(IEUM_TUITION_SETTING_TABLE, 'bill_auto_send_enabled', 'tinyint(1) not null default 0 after overdue_after_days');
    ieum_install_add_column_if_missing(IEUM_TUITION_SETTING_TABLE, 'bill_auto_send_day', 'tinyint unsigned not null default 5 after bill_auto_send_enabled');
    ieum_install_add_column_if_missing(IEUM_TUITION_SETTING_TABLE, 'bill_auto_send_scope', "varchar(20) not null default 'all' after bill_auto_send_day");
    ieum_install_add_column_if_missing(IEUM_TUITION_SETTING_TABLE, 'bill_auto_include_arrears', 'tinyint(1) not null default 1 after bill_auto_send_scope');

    sql_query("
        create table if not exists " . IEUM_MAP_SETTING_TABLE . " (
            academy_id int unsigned not null,
            provider varchar(30) not null default 'naver',
            use_dynamic_map tinyint(1) not null default 0,
            use_geocoding tinyint(1) not null default 0,
            use_directions tinyint(1) not null default 0,
            naver_client_id varchar(120) not null default '',
            naver_client_secret varchar(160) not null default '',
            naver_web_service_url varchar(255) not null default '',
            memo varchar(255) not null default '',
            updated_at datetime null,
            primary key (academy_id)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        insert into " . IEUM_STUDENT_TABLE . "
            set academy_id = 1,
                student_code = '1001',
                student_name = '테스트학생',
                parent_name = '테스트보호자',
                parent_phone = '01000000000',
                memo = '로컬 테스트용 샘플입니다.',
                is_active = 1,
                created_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                updated_at = '" . G5_TIME_YMDHIS . "'
    ");

    $installed = true;
    $run_token = ieum_new_csrf_token();
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:880px;margin:48px auto;padding:0 20px}
.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:28px;box-shadow:0 10px 24px rgba(15,23,42,.08)}
h1{margin:0 0 18px;font-size:26px}
p{line-height:1.6}
code{background:#f1f3f5;border-radius:4px;padding:2px 6px}
.ok{border:1px solid #9bd3ad;background:#eef9f1;border-radius:8px;padding:12px 14px;margin:16px 0;color:#176b2c}
.desc{border:1px solid #e2e8f0;background:#f8fafc;border-radius:8px;padding:14px;margin:16px 0}
.btn{display:inline-block;margin-top:14px;background:#8a0f3f;color:#fff;text-decoration:none;border-radius:6px;padding:11px 16px;font-weight:700}
ul{line-height:1.9}
</style>
</head>
<body>
<main class="wrap">
<section class="panel">
<h1>아이이음 1구간 DB 설치</h1>

<div class="local_desc01 local_desc">
    <p>아이이음 1구간에 필요한 학생, 출석, 문자 발송 테이블을 생성합니다. 실제 문자 발송 기능은 포함하지 않습니다.</p>
</div>

<?php if ($installed) { ?>
<div class="local_desc01 local_desc" style="border-color:#2f9e44">
    <p><strong>설치 완료:</strong> 테스트 학생번호 <code>1001</code>이 준비되었습니다.</p>
</div>
<?php } ?>

<p>
    <a class="btn" href="<?php echo IEUM_URL; ?>/install.php?run=1&amp;ieum_token=<?php echo $run_token; ?>">DB 생성 / 업데이트</a>
</p>

<section>
    <h2 class="h2_frm">생성 테이블</h2>
    <ul>
        <li><code><?php echo IEUM_STUDENT_TABLE; ?></code></li>
        <li><code><?php echo IEUM_ATTENDANCE_TABLE; ?></code></li>
        <li><code><?php echo IEUM_SMS_QUEUE_TABLE; ?></code></li>
        <li><code><?php echo IEUM_ACADEMY_CALENDAR_TABLE; ?></code></li>
    </ul>
</section>

</section>
</main>
</body>
</html>
