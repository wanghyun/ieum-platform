<?php
$sub_menu = '950100';
if (!defined('G5_IS_ADMIN')) {
    define('G5_IS_ADMIN', true);
}
require_once './_common.php';
require_once G5_ADMIN_PATH . '/admin.lib.php';
require_once IEUM_PATH . '/lib/security.php';
require_once IEUM_PATH . '/lib/academy.php';

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
            gateway_token varchar(100) not null,
            service_status varchar(20) not null default 'active',
            sms_start_time char(5) not null default '10:00',
            sms_end_time char(5) not null default '20:00',
            sms_poll_seconds int unsigned not null default 30,
            promotion_interval_months tinyint unsigned not null default 3,
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
                gateway_token = 'ieum-local-gateway-token-2026',
                service_status = 'active',
                sms_start_time = '10:00',
                sms_end_time = '20:00',
                sms_poll_seconds = 30,
                promotion_interval_months = 3,
                is_active = 1,
                created_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                academy_name = values(academy_name),
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
            birth_date date null,
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
            tuition_due_day tinyint unsigned not null default 5,
            tuition_note varchar(255) not null default '',
            vehicle_pickup_enabled tinyint(1) not null default 0,
            vehicle_pickup_place varchar(100) not null default '',
            vehicle_dropoff_enabled tinyint(1) not null default 0,
            vehicle_dropoff_place varchar(100) not null default '',
            parent_name varchar(50) not null default '',
            parent_phone varchar(30) not null default '',
            memo varchar(255) not null default '',
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (student_id),
            unique key uq_academy_student_code (academy_id, student_code),
            key idx_active_name (academy_id, is_active, student_name)
        ) engine={$engine} default charset={$charset}
    ");

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
        create table if not exists " . IEUM_STUDENT_GUARDIAN_TABLE . " (
            guardian_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            guardian_name varchar(50) not null default '',
            guardian_relation varchar(30) not null default '',
            guardian_phone varchar(30) not null default '',
            sms_attendance tinyint(1) not null default 1,
            sms_checkout tinyint(1) not null default 0,
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
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (route_id),
            key idx_academy_type (academy_id, route_type, is_active, sort_order)
        ) engine={$engine} default charset={$charset}
    ");

    sql_query("
        create table if not exists " . IEUM_VEHICLE_STOP_TABLE . " (
            stop_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            route_id int unsigned not null default 0,
            stop_type varchar(20) not null,
            stop_name varchar(100) not null default '',
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
            status varchar(20) not null default 'pending',
            gateway_device varchar(100) not null default '',
            error_message varchar(255) not null default '',
            created_at datetime not null,
            sent_at datetime null,
            primary key (sms_id),
            key idx_status_created (academy_id, status, created_at),
            key idx_attendance_id (attendance_id),
            key idx_student_id (student_id)
        ) engine={$engine} default charset={$charset}
    ");

    ieum_install_add_column_if_missing(IEUM_CLASS_TIME_TABLE, 'absent_alert_enabled', 'tinyint(1) not null default 1');
    ieum_install_add_column_if_missing(IEUM_CLASS_TIME_TABLE, 'absent_alert_after_minutes', 'smallint unsigned not null default 10');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'attendance_week_type', "varchar(20) not null default '5'");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'student_phone', "varchar(30) not null default '' after student_name");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'birth_date', 'date null after student_phone');
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
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'tuition_due_day', 'tinyint unsigned not null default 5');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'tuition_note', "varchar(255) not null default ''");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'vehicle_pickup_enabled', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'vehicle_pickup_place', "varchar(100) not null default ''");
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'vehicle_dropoff_enabled', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_TABLE, 'vehicle_dropoff_place', "varchar(100) not null default ''");
    ieum_install_add_column_if_missing(IEUM_STUDENT_GUARDIAN_TABLE, 'guardian_relation', "varchar(30) not null default ''");
    ieum_install_add_column_if_missing(IEUM_STUDENT_GUARDIAN_TABLE, 'sms_checkout', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_GUARDIAN_TABLE, 'use_for_student_code', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_STUDENT_GUARDIAN_TABLE, 'is_primary', 'tinyint(1) not null default 0');
    ieum_install_add_column_if_missing(IEUM_SMS_QUEUE_TABLE, 'message_type', "varchar(30) not null default 'checkin' after message");

    sql_query("
        create table if not exists " . IEUM_ACADEMY_CONTACT_TABLE . " (
            contact_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            contact_name varchar(50) not null default '',
            contact_phone varchar(30) not null default '',
            sms_absent_alert tinyint(1) not null default 1,
            sms_system_alert tinyint(1) not null default 1,
            sort_order int unsigned not null default 0,
            is_active tinyint(1) not null default 1,
            created_at datetime not null,
            updated_at datetime null,
            primary key (contact_id),
            key idx_academy_active (academy_id, is_active),
            key idx_phone (contact_phone)
        ) engine={$engine} default charset={$charset}
    ");

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
            paid_at datetime null,
            created_at datetime not null,
            updated_at datetime null,
            primary key (payment_id),
            unique key uq_student_month (academy_id, student_id, billing_month),
            key idx_status_due (academy_id, status, due_date)
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
    <p>아이이음 1구간에 필요한 학생, 출석, 문자 큐 테이블을 생성합니다. 실제 문자 발송 기능은 포함하지 않습니다.</p>
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
