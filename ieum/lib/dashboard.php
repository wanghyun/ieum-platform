<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

if (defined('IEUM_PATH')) {
    require_once IEUM_PATH . '/lib/tuition.php';
    require_once IEUM_PATH . '/lib/character.php';
    require_once IEUM_PATH . '/lib/fitness.php';
    require_once IEUM_PATH . '/lib/promotion.php';
    require_once IEUM_PATH . '/lib/sms_queue.php';
    require_once IEUM_PATH . '/lib/attendance.php';
}

function ieum_dashboard_ensure_shortcut_table()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

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
    ", false);
}

function ieum_dashboard_ensure_today_task_table()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

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
            unique key uq_academy_task (academy_id, task_key),
            key idx_academy_sort (academy_id, is_active, sort_order)
        ) engine={$engine} default charset={$charset}
    ", false);
}

function ieum_dashboard_calendar_memo_table()
{
    return 'ieum_dashboard_calendar_memo';
}

function ieum_dashboard_ensure_calendar_memo_table()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

    sql_query("
        create table if not exists " . ieum_dashboard_calendar_memo_table() . " (
            memo_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            memo_date date not null,
            memo varchar(255) not null default '',
            alert_enabled tinyint(1) not null default 0,
            alert_offset_days tinyint unsigned not null default 0,
            alert_time char(5) not null default '',
            alert_scheduled_at datetime null,
            alert_sms_queue_ids text not null,
            created_by varchar(50) not null default '',
            updated_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (memo_id),
            unique key uq_academy_memo_date (academy_id, memo_date),
            key idx_academy_month (academy_id, memo_date)
        ) engine={$engine} default charset={$charset}
    ", false);

    $columns = array(
        'alert_enabled' => "alter table " . ieum_dashboard_calendar_memo_table() . " add alert_enabled tinyint(1) not null default 0 after memo",
        'alert_offset_days' => "alter table " . ieum_dashboard_calendar_memo_table() . " add alert_offset_days tinyint unsigned not null default 0 after alert_enabled",
        'alert_time' => "alter table " . ieum_dashboard_calendar_memo_table() . " add alert_time char(5) not null default '' after alert_offset_days",
        'alert_scheduled_at' => "alter table " . ieum_dashboard_calendar_memo_table() . " add alert_scheduled_at datetime null after alert_time",
        'alert_sms_queue_ids' => "alter table " . ieum_dashboard_calendar_memo_table() . " add alert_sms_queue_ids text not null after alert_scheduled_at",
    );

    foreach ($columns as $column => $sql) {
        $exists = sql_fetch("show columns from " . ieum_dashboard_calendar_memo_table() . " like '" . sql_escape_string($column) . "'", false);
        if (empty($exists['Field'])) {
            sql_query($sql, false);
        }
    }
}

function ieum_dashboard_ensure_memo_contact_column()
{
    $exists = sql_fetch("show columns from " . IEUM_ACADEMY_CONTACT_TABLE . " like 'sms_memo_alert'", false);
    if (empty($exists['Field'])) {
        sql_query("alter table " . IEUM_ACADEMY_CONTACT_TABLE . " add sms_memo_alert tinyint(1) not null default 1 after sms_system_alert", false);
    }
}

function ieum_dashboard_calendar_alert_source_key($academy_id, $memo_date)
{
    return 'dashboard_memo:' . (int) $academy_id . ':' . preg_replace('/[^0-9-]/', '', (string) $memo_date);
}

function ieum_dashboard_calendar_alert_scheduled_at($memo_date, $offset_days, $alert_time)
{
    $memo_date = trim((string) $memo_date);
    $offset_days = max(0, min(30, (int) $offset_days));
    $alert_time = trim((string) $alert_time);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $memo_date) || !preg_match('/^\d{2}:\d{2}$/', $alert_time)) {
        return '';
    }

    $ts = strtotime($memo_date . ' ' . $alert_time . ':00');
    if ($ts === false) {
        return '';
    }

    return date('Y-m-d H:i:s', strtotime('-' . $offset_days . ' day', $ts));
}

function ieum_dashboard_calendar_alert_message($academy_name, $memo_date, $memo)
{
    $academy_name = trim((string) $academy_name);
    $memo = trim((string) $memo);
    $date_label = date('n월 j일', strtotime($memo_date));

    if ($academy_name === '') {
        $academy_name = '아이이음';
    }

    return '[' . $academy_name . ' 메모] ' . $date_label . ' · ' . $memo;
}

function ieum_dashboard_queue_calendar_memo_alert($academy_id, $academy_name, $memo_date, $memo, $alert_enabled, $alert_offset_days, $alert_time)
{
    $result = array(
        'queued' => 0,
        'queue_ids' => array(),
        'canceled' => 0,
        'scheduled_at' => '',
        'skipped_past' => false,
    );

    $academy_id = (int) $academy_id;
    $memo = trim((string) $memo);
    $source_key = ieum_dashboard_calendar_alert_source_key($academy_id, $memo_date);
    $message_type = 'calendar_memo_alert';

    if (!function_exists('ieum_cancel_direct_sms_queue_by_source') || !function_exists('ieum_create_direct_sms_queue_once')) {
        return $result;
    }

    if (!$alert_enabled || $memo === '') {
        $result['canceled'] = ieum_cancel_direct_sms_queue_by_source($academy_id, $message_type, $source_key, '메모 알림이 해제되어 발송 전 자동 취소되었습니다.');
        return $result;
    }

    $scheduled_at = ieum_dashboard_calendar_alert_scheduled_at($memo_date, $alert_offset_days, $alert_time);
    $result['scheduled_at'] = $scheduled_at;
    if ($scheduled_at === '') {
        $result['canceled'] = ieum_cancel_direct_sms_queue_by_source($academy_id, $message_type, $source_key, '메모 알림 시간이 올바르지 않아 발송 전 자동 취소되었습니다.');
        return $result;
    }
    if (strtotime($scheduled_at) !== false && strtotime($scheduled_at) < strtotime(G5_TIME_YMDHIS)) {
        $result['skipped_past'] = true;
        $result['canceled'] = ieum_cancel_direct_sms_queue_by_source($academy_id, $message_type, $source_key, '메모 알림 시간이 지나 발송 전 자동 취소되었습니다.');
        return $result;
    }

    ieum_dashboard_ensure_memo_contact_column();

    $message = ieum_dashboard_calendar_alert_message($academy_name, $memo_date, $memo);
    $contacts = sql_query("
        select contact_phone
          from " . IEUM_ACADEMY_CONTACT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
           and sms_memo_alert = 1
           and contact_phone <> ''
      order by sort_order asc, contact_id asc
    ", false);

    $dedupe_date = substr($scheduled_at, 0, 10);
    while ($contact = sql_fetch_array($contacts)) {
        $sms_id = ieum_create_direct_sms_queue_once($academy_id, $contact['contact_phone'], $message, $message_type, 0, 0, $dedupe_date, $scheduled_at, $source_key);
        if ($sms_id) {
            $result['queue_ids'][] = $sms_id;
        }
    }

    $result['queued'] = count($result['queue_ids']);
    return $result;
}

function ieum_dashboard_save_calendar_memo($academy_id, $memo_date, $memo, $member_id = '', $alert_options = array(), $academy_name = '')
{
    $academy_id = (int) $academy_id;
    $memo_date = trim((string) $memo_date);
    $memo = trim((string) $memo);
    $alert_enabled = !empty($alert_options['alert_enabled']) ? 1 : 0;
    $alert_offset_days = isset($alert_options['alert_offset_days']) ? max(0, min(30, (int) $alert_options['alert_offset_days'])) : 0;
    $alert_time = isset($alert_options['alert_time']) ? trim((string) $alert_options['alert_time']) : '09:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $alert_time)) {
        $alert_time = '09:00';
    }
    $alert_scheduled_at = ieum_dashboard_calendar_alert_scheduled_at($memo_date, $alert_offset_days, $alert_time);

    if ($academy_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $memo_date) || strtotime($memo_date) === false) {
        return false;
    }

    ieum_dashboard_ensure_calendar_memo_table();

    $date_sql = sql_escape_string($memo_date);
    if ($memo === '') {
        if (function_exists('ieum_cancel_direct_sms_queue_by_source')) {
            ieum_cancel_direct_sms_queue_by_source($academy_id, 'calendar_memo_alert', ieum_dashboard_calendar_alert_source_key($academy_id, $memo_date), '메모가 삭제되어 발송 전 자동 취소되었습니다.');
        }
        sql_query("
            delete from " . ieum_dashboard_calendar_memo_table() . "
             where academy_id = '{$academy_id}'
               and memo_date = '{$date_sql}'
        ", false);
        return array('ok' => true, 'queued' => 0, 'queue_ids' => array(), 'canceled' => 0, 'scheduled_at' => '', 'skipped_past' => false);
    }

    $memo = function_exists('mb_substr') ? mb_substr($memo, 0, 255, 'UTF-8') : substr($memo, 0, 255);
    $memo_sql = sql_escape_string($memo);
    $member_sql = sql_escape_string(trim((string) $member_id));
    $alert_scheduled_sql = $alert_scheduled_at !== '' ? "'" . sql_escape_string($alert_scheduled_at) . "'" : "null";

    sql_query("
        insert into " . ieum_dashboard_calendar_memo_table() . "
            set academy_id = '{$academy_id}',
                memo_date = '{$date_sql}',
                memo = '{$memo_sql}',
                alert_enabled = '{$alert_enabled}',
                alert_offset_days = '{$alert_offset_days}',
                alert_time = '" . sql_escape_string($alert_time) . "',
                alert_scheduled_at = {$alert_scheduled_sql},
                alert_sms_queue_ids = '',
                created_by = '{$member_sql}',
                updated_by = '{$member_sql}',
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                memo = values(memo),
                alert_enabled = values(alert_enabled),
                alert_offset_days = values(alert_offset_days),
                alert_time = values(alert_time),
                alert_scheduled_at = values(alert_scheduled_at),
                updated_by = values(updated_by),
                updated_at = values(updated_at)
    ", false);

    $queue_result = ieum_dashboard_queue_calendar_memo_alert($academy_id, $academy_name, $memo_date, $memo, $alert_enabled, $alert_offset_days, $alert_time);
    if (!empty($queue_result['queue_ids'])) {
        sql_query("
            update " . ieum_dashboard_calendar_memo_table() . "
               set alert_sms_queue_ids = '" . sql_escape_string(implode(',', array_map('intval', $queue_result['queue_ids']))) . "'
             where academy_id = '{$academy_id}'
               and memo_date = '{$date_sql}'
        ", false);
    } else {
        sql_query("
            update " . ieum_dashboard_calendar_memo_table() . "
               set alert_sms_queue_ids = ''
             where academy_id = '{$academy_id}'
               and memo_date = '{$date_sql}'
        ", false);
    }

    $queue_result['ok'] = true;
    return $queue_result;
}

function ieum_dashboard_calendar_memo_rows($academy_id, $start_date, $end_date)
{
    $academy_id = (int) $academy_id;
    $rows = array();

    if ($academy_id <= 0) {
        return $rows;
    }

    ieum_dashboard_ensure_calendar_memo_table();

    $result = sql_query("
        select *
          from " . ieum_dashboard_calendar_memo_table() . "
         where academy_id = '{$academy_id}'
           and memo_date between '" . sql_escape_string($start_date) . "' and '" . sql_escape_string($end_date) . "'
      order by memo_date asc
    ", false);

    while ($row = sql_fetch_array($result)) {
        $rows[$row['memo_date']] = $row;
    }

    return $rows;
}

function ieum_dashboard_calendar_memos($academy_id, $start_date, $end_date)
{
    $memos = array();
    $rows = ieum_dashboard_calendar_memo_rows($academy_id, $start_date, $end_date);

    foreach ($rows as $date => $row) {
        $memos[$date] = $row['memo'];
    }

    return $memos;
}

function ieum_dashboard_ensure_auto_check_table()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

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
    ", false);
}

function ieum_dashboard_auto_check_not_resolved_sql($alias, $academy_id, $check_type, $target_key_expr, $target_date)
{
    $academy_id = (int) $academy_id;
    $alias = preg_replace('/[^a-z0-9_]/i', '', $alias);
    $check_type_sql = sql_escape_string($check_type);
    $target_date_sql = sql_escape_string($target_date);

    return " and not exists (
        select 1
          from " . IEUM_AUTO_CHECK_RESOLVE_TABLE . " {$alias}
         where {$alias}.academy_id = '{$academy_id}'
           and {$alias}.check_type = '{$check_type_sql}'
           and {$alias}.target_key = {$target_key_expr}
           and {$alias}.target_date = '{$target_date_sql}'
    ) ";
}

function ieum_dashboard_resolve_auto_check($academy_id, $check_type, $target_key, $target_date, $resolved_by = '', $memo = '')
{
    $academy_id = (int) $academy_id;
    $check_type = preg_replace('/[^0-9a-z_]/i', '', trim((string) $check_type));
    $target_key = trim((string) $target_key);
    $target_date = trim((string) $target_date);

    if ($academy_id <= 0 || $check_type === '' || $target_key === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
        return false;
    }

    ieum_dashboard_ensure_auto_check_table();

    sql_query("
        insert into " . IEUM_AUTO_CHECK_RESOLVE_TABLE . "
            set academy_id = '{$academy_id}',
                check_type = '" . sql_escape_string($check_type) . "',
                target_key = '" . sql_escape_string($target_key) . "',
                target_date = '" . sql_escape_string($target_date) . "',
                memo = '" . sql_escape_string($memo) . "',
                resolved_by = '" . sql_escape_string($resolved_by) . "',
                resolved_at = '" . G5_TIME_YMDHIS . "',
                created_at = '" . G5_TIME_YMDHIS . "',
                updated_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                memo = values(memo),
                resolved_by = values(resolved_by),
                resolved_at = values(resolved_at),
                updated_at = values(updated_at)
    ", false);

    return true;
}

function ieum_dashboard_today_task_catalog()
{
    return array(
        'missing_today' => array('label' => '미등원 확인'),
        'class_attendance' => array('label' => '수업 부별 출석'),
        'recent_attendance' => array('label' => '최근 등원'),
        'tuition_overdue' => array('label' => '수련비 미납'),
        'tuition_paid' => array('label' => '수련비 납부 확인'),
        'tuition_due_today' => array('label' => '납부 예정'),
        'tuition_notice_pending' => array('label' => '수련비 문자 예정'),
        'sms_failed' => array('label' => '문자 실패 확인'),
        'vehicle_notes' => array('label' => '차량 메모 확인'),
        'tablet_devices' => array('label' => '출석기 연결'),
        'student_care_notes' => array('label' => '아이들 메모 모아보기'),
        'long_absent' => array('label' => '장기 미등원 확인'),
        'no_guardian' => array('label' => '연락처 누락 확인'),
        'birthday' => array('label' => '생일 안부 문자'),
        'report_blocked' => array('label' => '리포트 발송 불가 확인'),
        'promotion_due' => array('label' => '승급 대상 확인'),
        'poomdan_due' => array('label' => '승품/단 대상 확인'),
        'character_input' => array('label' => '인성 입력'),
        'fitness_input' => array('label' => '체력 입력'),
        'monthly_close' => array('label' => '월말 리포트 마감'),
        'new_student' => array('label' => '학생 신규 등록'),
    );
}

function ieum_dashboard_default_today_task_keys()
{
    return array('missing_today', 'long_absent', 'tuition_overdue', 'sms_failed', 'student_care_notes', 'monthly_close');
}

function ieum_dashboard_get_today_task_keys($academy_id)
{
    $academy_id = (int) $academy_id;
    ieum_dashboard_ensure_today_task_table();

    $keys = array();
    $result = sql_query("
        select task_key
          from " . IEUM_DASHBOARD_TODAY_TASK_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
      order by sort_order asc, task_id asc
    ", false);

    while ($row = sql_fetch_array($result)) {
        $keys[] = $row['task_key'];
    }

    return $keys ? $keys : ieum_dashboard_default_today_task_keys();
}

function ieum_dashboard_save_today_tasks($academy_id, $keys)
{
    $academy_id = (int) $academy_id;
    $catalog = ieum_dashboard_today_task_catalog();
    $clean = array();

    foreach ((array) $keys as $key) {
        $key = trim($key);
        if (!isset($catalog[$key]) || isset($clean[$key])) {
            continue;
        }
        $clean[$key] = $key;
        if (count($clean) >= 20) {
            break;
        }
    }

    if (!$clean) {
        $clean = array_fill_keys(ieum_dashboard_default_today_task_keys(), true);
    }

    ieum_dashboard_ensure_today_task_table();
    sql_query("delete from " . IEUM_DASHBOARD_TODAY_TASK_TABLE . " where academy_id = '{$academy_id}'", false);

    $sort = 0;
    foreach (array_keys($clean) as $key) {
        $sort += 10;
        sql_query("
            insert into " . IEUM_DASHBOARD_TODAY_TASK_TABLE . "
                set academy_id = '{$academy_id}',
                    task_key = '" . sql_escape_string($key) . "',
                    sort_order = '{$sort}',
                    is_active = 1,
                    created_at = '" . G5_TIME_YMDHIS . "',
                    updated_at = '" . G5_TIME_YMDHIS . "'
        ", false);
    }
}

function ieum_dashboard_shortcut_catalog()
{
    return array(
        'attendance_today' => array('label' => '오늘 출석', 'desc' => '부별 출석과 미등원 처리', 'url' => IEUM_URL . '/admin/attendance_today.php'),
        'students' => array('label' => '원생 관리', 'desc' => '명부, 프로필, 관리 신호', 'url' => IEUM_URL . '/admin/students.php'),
        'tuition_payments' => array('label' => '수련비 관리', 'desc' => '미납, 납부 예정, 완납 확인', 'url' => IEUM_URL . '/admin/tuition_payments.php'),
        'sms' => array('label' => '문자 발송', 'desc' => '실패 문자와 발송 기록', 'url' => IEUM_URL . '/admin/sms_queue.php'),
        'contacts' => array('label' => '알림 담당자', 'desc' => '미등원, 차량, 메모 알림', 'url' => IEUM_URL . '/admin/contacts.php'),
        'vehicle_boarding' => array('label' => '차량 탑승', 'desc' => '탑승 확인과 특이사항', 'url' => IEUM_URL . '/admin/vehicle_boarding.php'),
        'character' => array('label' => '인성 입력', 'desc' => '부별 주간 인성 체크', 'url' => IEUM_URL . '/admin/character.php'),
        'character_report' => array('label' => '월간 인성', 'desc' => '학부모 리포트 확인', 'url' => IEUM_URL . '/admin/character_report.php'),
        'character_mission' => array('label' => '아이잘해 미션', 'desc' => '가정 실천 참여 체크', 'url' => IEUM_URL . '/admin/character_mission.php'),
        'monthly_close' => array('label' => '월말 마감', 'desc' => '인성/체력 리포트 준비', 'url' => IEUM_URL . '/admin/monthly_close.php'),
        'operations' => array('label' => '운영 지표', 'desc' => '신규, 휴관, 상담 신호', 'url' => IEUM_URL . '/admin/operations.php'),
        'growth_report' => array('label' => '원생 리포트', 'desc' => '월별 원생 흐름', 'url' => IEUM_URL . '/admin/growth_report.php'),
        'student_import' => array('label' => '엑셀 가져오기', 'desc' => '원생 명단 일괄 등록', 'url' => IEUM_URL . '/admin/student_import.php'),
        'groups' => array('label' => '부별 명단', 'desc' => '수업 부별 원생 확인', 'url' => IEUM_URL . '/admin/student_groups.php'),
        'promotion_targets' => array('label' => '승급 대상', 'desc' => '심사 준비 대상 확인', 'url' => IEUM_URL . '/admin/promotion_targets.php'),
        'promotion_poomdan_targets' => array('label' => '승품/단 대상', 'desc' => '협회 심사 안내 대상', 'url' => IEUM_URL . '/admin/promotion_poomdan_targets.php'),
        'promotion_exam_notices' => array('label' => '심사 안내문', 'desc' => '심사 안내 문자와 출력', 'url' => IEUM_URL . '/admin/promotion_exam_notices.php'),
        'promotion_belts_needed' => array('label' => '준비 띠', 'desc' => '승급 전 준비물 확인', 'url' => IEUM_URL . '/admin/promotion_belts_needed.php'),
        'promotion_certificates' => array('label' => '승급증 인쇄', 'desc' => '승급증 출력과 관리', 'url' => IEUM_URL . '/admin/promotion_certificates.php'),
        'family_billing' => array('label' => '형제/자매 청구', 'desc' => '가족 청구 묶음 관리', 'url' => IEUM_URL . '/admin/family_billing.php'),
        'sms_devices' => array('label' => '문자 발송폰', 'desc' => '발송폰 연결과 상태', 'url' => IEUM_URL . '/admin/sms_devices.php'),
        'character_growth' => array('label' => '장기 성장', 'desc' => '인성 변화 흐름 확인', 'url' => IEUM_URL . '/admin/character_growth_report.php'),
        'fitness' => array('label' => '체력 입력', 'desc' => '이번 달 체력 측정', 'url' => IEUM_URL . '/admin/fitness.php'),
        'fitness_reports' => array('label' => '체력 리포트', 'desc' => '체력 결과 확인과 발송', 'url' => IEUM_URL . '/admin/fitness_reports.php'),
        'fitness_standards' => array('label' => '체력 기준표', 'desc' => '평가 기준 관리', 'url' => IEUM_URL . '/admin/fitness_standards.php'),
        'vehicle_assignments' => array('label' => '차량 배정', 'desc' => '호차별 등원/하원 배정', 'url' => IEUM_URL . '/admin/vehicle_assignments.php'),
        'vehicle_monitor' => array('label' => '운행 관리', 'desc' => '차량 운행 흐름 확인', 'url' => IEUM_URL . '/admin/vehicle_monitor.php'),
        'vehicle_journal' => array('label' => '차량 일지', 'desc' => '운행 기록 확인', 'url' => IEUM_URL . '/admin/vehicle_journal.php'),
        'vehicles' => array('label' => '차량/노선 설정', 'desc' => '차량과 노선 관리', 'url' => IEUM_URL . '/admin/vehicles.php'),
        'programs' => array('label' => '프로그램 설정', 'desc' => '수업 프로그램 관리', 'url' => IEUM_URL . '/admin/programs.php'),
        'classes' => array('label' => '수업 시간표', 'desc' => '부별 시간표 관리', 'url' => IEUM_URL . '/admin/class_times.php'),
        'calendar' => array('label' => '수업일/휴관일', 'desc' => '운영일과 휴관일 관리', 'url' => IEUM_URL . '/admin/school_calendar.php'),
        'tuition' => array('label' => '수련비 정책', 'desc' => '청구 금액과 납부일', 'url' => IEUM_URL . '/admin/tuition.php'),
        'promotion_settings' => array('label' => '승급 설정', 'desc' => '승급 기준과 단계', 'url' => IEUM_URL . '/admin/promotion_settings.php'),
        'promotion_missions' => array('label' => '승급 미션', 'desc' => '승급 미션 관리', 'url' => IEUM_URL . '/admin/promotion_missions.php'),
        'sms_templates' => array('label' => '문자 템플릿', 'desc' => '자동 안내 문구 관리', 'url' => IEUM_URL . '/admin/sms_templates.php'),
        'tablet_devices' => array('label' => '앱 출석기', 'desc' => '앱 연결, 기기명, 해제', 'url' => IEUM_URL . '/admin/tablet_devices.php'),
    );
}

function ieum_dashboard_default_shortcut_keys()
{
    return array('attendance_today', 'students', 'tuition_payments', 'sms', 'monthly_close', 'contacts');
}

function ieum_dashboard_get_shortcut_keys($academy_id)
{
    $academy_id = (int) $academy_id;
    ieum_dashboard_ensure_shortcut_table();

    $keys = array();
    $result = sql_query("
        select shortcut_key
          from " . IEUM_DASHBOARD_SHORTCUT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
      order by sort_order asc, shortcut_id asc
    ", false);

    while ($row = sql_fetch_array($result)) {
        $keys[] = $row['shortcut_key'];
    }

    return $keys ? $keys : ieum_dashboard_default_shortcut_keys();
}

function ieum_dashboard_resolve_shortcuts($keys)
{
    $catalog = ieum_dashboard_shortcut_catalog();
    $items = array();

    foreach ($keys as $key) {
        if (!isset($catalog[$key])) {
            continue;
        }

        $items[$key] = $catalog[$key];
    }

    return $items;
}

function ieum_dashboard_save_shortcuts($academy_id, $keys)
{
    $academy_id = (int) $academy_id;
    $catalog = ieum_dashboard_shortcut_catalog();
    $clean = array();

    foreach ((array) $keys as $key) {
        $key = trim($key);
        if (!isset($catalog[$key]) || isset($clean[$key])) {
            continue;
        }
        $clean[$key] = $key;
        if (count($clean) >= 6) {
            break;
        }
    }

    if (!$clean) {
        $clean = array_fill_keys(ieum_dashboard_default_shortcut_keys(), true);
    }

    ieum_dashboard_ensure_shortcut_table();
    sql_query("delete from " . IEUM_DASHBOARD_SHORTCUT_TABLE . " where academy_id = '{$academy_id}'", false);

    $sort = 0;
    foreach (array_keys($clean) as $key) {
        $sort += 10;
        sql_query("
            insert into " . IEUM_DASHBOARD_SHORTCUT_TABLE . "
                set academy_id = '{$academy_id}',
                    shortcut_key = '" . sql_escape_string($key) . "',
                    sort_order = '{$sort}',
                    is_active = 1,
                    created_at = '" . G5_TIME_YMDHIS . "',
                    updated_at = '" . G5_TIME_YMDHIS . "'
        ", false);
    }
}

function ieum_dashboard_row_count($row, $key = 'cnt')
{
    return $row && isset($row[$key]) ? (int) $row[$key] : 0;
}

function ieum_dashboard_report_fitness_completed_count($academy_id, $report_month)
{
    $academy_id = (int) $academy_id;
    $report_month_sql = sql_escape_string($report_month);

    if (!function_exists('ieum_fitness_active_items') || !function_exists('ieum_fitness_metric_values') || !function_exists('ieum_fitness_merge_metric_values')) {
        $row = sql_fetch("
            select count(distinct student_id) as cnt
              from " . IEUM_REPORT_FITNESS_TABLE . "
             where academy_id = '{$academy_id}'
               and report_month = '{$report_month_sql}'
        ", false);
        return ieum_dashboard_row_count($row);
    }

    if (function_exists('ieum_fitness_ensure_table')) {
        ieum_fitness_ensure_table();
    }

    $students = array();
    $student_result = sql_query("
        select student_id
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
           and coalesce(fitness_report_enabled, 1) = 1
    ", false);
    while ($row = sql_fetch_array($student_result)) {
        $students[(int) $row['student_id']] = true;
    }

    if (!$students) {
        return 0;
    }

    $fitness_map = array();
    $fitness_result = sql_query("
        select *
          from " . IEUM_REPORT_FITNESS_TABLE . "
         where academy_id = '{$academy_id}'
           and report_month = '{$report_month_sql}'
    ", false);
    while ($row = sql_fetch_array($fitness_result)) {
        $sid = (int) $row['student_id'];
        if (isset($students[$sid])) {
            $fitness_map[$sid] = $row;
        }
    }

    $student_ids = array_keys($students);
    ieum_fitness_merge_metric_values($fitness_map, ieum_fitness_metric_values($academy_id, $report_month, $student_ids), $student_ids);

    $items = ieum_fitness_active_items($academy_id);
    $completed = 0;
    foreach ($students as $sid => $_enabled) {
        $fitness = isset($fitness_map[$sid]) ? $fitness_map[$sid] : array();
        $complete = count($items) > 0;
        foreach (array_keys($items) as $key) {
            if (!isset($fitness[$key]) || $fitness[$key] === null || $fitness[$key] === '') {
                $complete = false;
                break;
            }
        }
        if ($complete) {
            $completed++;
        }
    }

    return $completed;
}

function ieum_dashboard_promotion_summary($academy, $report_month, $target_date)
{
    $academy_id = isset($academy['academy_id']) ? (int) $academy['academy_id'] : 0;
    $summary = array('promotion_due' => 0, 'poomdan_due' => 0, 'belt_need' => 0);

    if ($academy_id <= 0 || !function_exists('ieum_promotion_status')) {
        return $summary;
    }

    if (function_exists('ieum_promotion_ensure_schema')) {
        ieum_promotion_ensure_schema();
    }

    $target_month = substr($target_date, 0, 7) . '-01';
    $result = sql_query("
        select s.*
          from " . IEUM_STUDENT_TABLE . " s
         where s.academy_id = '{$academy_id}'
           and s.is_active = 1
           and coalesce(s.promotion_enabled, 1) = 1
           " . ieum_dashboard_auto_check_not_resolved_sql('acr', $academy_id, 'promotion_due', 'cast(s.student_id as char)', $target_month) . "
    ", false);

    while ($student = sql_fetch_array($result)) {
        $status = ieum_promotion_status($academy, $student, $report_month);
        if (empty($status['due_this_month']) && empty($status['overdue'])) {
            continue;
        }
        if (!empty($status['is_poomdan_exam'])) {
            $summary['poomdan_due']++;
            continue;
        }
        $summary['promotion_due']++;
        if (!empty($status['next_rank']['belt'])) {
            $summary['belt_need']++;
        }
    }

    return $summary;
}

function ieum_dashboard_rail_summary($academy, $today = '')
{
    $academy_id = isset($academy['academy_id']) ? (int) $academy['academy_id'] : 0;
    if ($academy_id <= 0) {
        return array();
    }

    $today = $today && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) ? $today : G5_TIME_YMD;
    $today_sql = sql_escape_string($today);
    $now_ts = strtotime($today . ' 12:00:00');
    $report_month = date('Y-m', $now_ts);
    $billing_month = function_exists('ieum_tuition_billing_month') ? ieum_tuition_billing_month($now_ts) : $report_month;
    $auto_check_today = $today;

    ieum_dashboard_ensure_auto_check_table();
    if (function_exists('ieum_tuition_ensure_month')) {
        ieum_tuition_ensure_month($academy_id, $billing_month);
    }

    $today_day_filter_sql = function_exists('ieum_attendance_student_day_filter_sql') ? ieum_attendance_student_day_filter_sql($academy_id, $today, 's') : '';

    $missing_today = sql_fetch("
        select count(*) as cnt
          from " . IEUM_STUDENT_TABLE . " s
     left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id
           and a.student_id = s.student_id
           and a.attendance_date = '{$today_sql}'
         where s.academy_id = '{$academy_id}'
           and s.is_active = 1
           {$today_day_filter_sql}
           and a.attendance_id is null
    ", false);

    $sms_failed_open = sql_fetch("
        select count(*) as cnt
          from " . IEUM_SMS_QUEUE_TABLE . "
         where academy_id = '{$academy_id}'
           and status = 'failed'
           and date(created_at) = '{$today_sql}'
           " . ieum_dashboard_auto_check_not_resolved_sql('acr', $academy_id, 'sms_failed', 'cast(sms_id as char)', $auto_check_today) . "
    ", false);

    $tuition_settings = function_exists('ieum_tuition_get_settings') ? ieum_tuition_get_settings($academy_id) : array();
    $tuition_overdue_days = max(1, min(30, (int) (isset($tuition_settings['overdue_after_days']) ? $tuition_settings['overdue_after_days'] : 5)));
    $billing_month_sql = sql_escape_string($billing_month);
    $tuition = sql_fetch("
        select sum(case when p.status in ('unpaid', 'partial') and datediff('{$today_sql}', p.due_date) > '{$tuition_overdue_days}' then 1 else 0 end) as unpaid_over_count
          from " . IEUM_TUITION_PAYMENT_TABLE . " p
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id
           and s.academy_id = p.academy_id
           and s.is_active = 1
         where p.academy_id = '{$academy_id}'
           and p.billing_month = '{$billing_month_sql}'
    ", false);
    $tuition_notice_due_pending = sql_fetch("
        select count(*) as cnt
          from " . IEUM_TUITION_PAYMENT_TABLE . " p
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id
           and s.academy_id = p.academy_id
           and s.is_active = 1
         where p.academy_id = '{$academy_id}'
           and p.status in ('unpaid', 'partial')
           and p.due_date = '{$today_sql}'
           and (p.notice_sent_at is null or p.notice_sent_at < '{$today_sql} 00:00:00')
           " . ieum_dashboard_auto_check_not_resolved_sql('acr', $academy_id, 'tuition_notice', 'cast(p.payment_id as char)', $auto_check_today) . "
    ", false);
    $tuition_notice_overdue_pending = sql_fetch("
        select count(*) as cnt
          from " . IEUM_TUITION_PAYMENT_TABLE . " p
          join " . IEUM_STUDENT_TABLE . " s on s.student_id = p.student_id
           and s.academy_id = p.academy_id
           and s.is_active = 1
         where p.academy_id = '{$academy_id}'
           and p.status in ('unpaid', 'partial')
           and datediff('{$today_sql}', p.due_date) > '{$tuition_overdue_days}'
           and (p.notice_sent_at is null or p.notice_sent_at < '{$today_sql} 00:00:00')
           " . ieum_dashboard_auto_check_not_resolved_sql('acr', $academy_id, 'tuition_notice', 'cast(p.payment_id as char)', $auto_check_today) . "
    ", false);
    $tuition_notice_pending_count = (!empty($tuition_settings['due_notice_enabled']) ? ieum_dashboard_row_count($tuition_notice_due_pending) : 0) + (!empty($tuition_settings['overdue_notice_enabled']) ? ieum_dashboard_row_count($tuition_notice_overdue_pending) : 0);

    $vehicle_note_count = sql_fetch("
        select count(*) as cnt
          from " . IEUM_VEHICLE_BOARDING_TABLE . "
         where academy_id = '{$academy_id}'
           and journal_date = '{$today_sql}'
           and (note <> '' or status in ('missed', 'called'))
           and resolved_at is null
    ", false);

    $long_absent_count = sql_fetch("
        select count(*) as cnt
          from (
            select s.student_id,
                   max(a.attendance_date) as last_attendance,
                   coalesce(s.admission_date, date(s.created_at)) as base_date
              from " . IEUM_STUDENT_TABLE . " s
         left join " . IEUM_ATTENDANCE_TABLE . " a on a.academy_id = s.academy_id
               and a.student_id = s.student_id
             where s.academy_id = '{$academy_id}'
               and s.is_active = 1
               " . ieum_dashboard_auto_check_not_resolved_sql('acr', $academy_id, 'long_absent', 'cast(s.student_id as char)', $auto_check_today) . "
          group by s.student_id
            having (last_attendance is null and datediff('{$today_sql}', base_date) >= 14)
                or (last_attendance is not null and datediff('{$today_sql}', last_attendance) >= 14)
          ) t
    ", false);

    $report_month_start = $report_month . '-01';
    $report_month_end = date('Y-m-t', strtotime($report_month_start));
    $report_elapsed_weeks = max(1, min(5, (int) ceil((int) date('j', $now_ts) / 7)));
    if (function_exists('ieum_character_ensure_table')) {
        ieum_character_ensure_table();
    }
    $character_enabled = sql_fetch("
        select count(*) as cnt
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
           and coalesce(character_report_enabled, 1) = 1
    ", false);
    $character_ready = sql_fetch("
        select count(*) as cnt
          from (
                select student_id, count(distinct week_start) as week_count
                  from " . IEUM_REPORT_CHARACTER_TABLE . "
                 where academy_id = '{$academy_id}'
                   and week_start between '" . sql_escape_string($report_month_start) . "' and '" . sql_escape_string($report_month_end) . "'
              group by student_id
          ) x
         where x.week_count >= '{$report_elapsed_weeks}'
    ", false);

    $fitness_enabled = sql_fetch("
        select count(*) as cnt
          from " . IEUM_STUDENT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
           and coalesce(fitness_report_enabled, 1) = 1
    ", false);
    $fitness_completed_count = ieum_dashboard_report_fitness_completed_count($academy_id, $report_month);

    $promotion_summary = ieum_dashboard_promotion_summary($academy, $report_month, $today);

    $alert_count = ieum_dashboard_row_count($long_absent_count) + ieum_dashboard_row_count($tuition, 'unpaid_over_count') + ieum_dashboard_row_count($sms_failed_open) + ieum_dashboard_row_count($vehicle_note_count);
    $todo_count = ieum_dashboard_row_count($missing_today) + $tuition_notice_pending_count;
    $report_count = max(0, ieum_dashboard_row_count($character_enabled) - ieum_dashboard_row_count($character_ready)) + max(0, ieum_dashboard_row_count($fitness_enabled) - $fitness_completed_count);
    $prepare_count = (int) $promotion_summary['promotion_due'] + (int) $promotion_summary['poomdan_due'] + (int) $promotion_summary['belt_need'];

    return array(
        'alert' => array('label' => '알림', 'icon' => '!', 'count' => $alert_count, 'tone' => $alert_count > 0 ? 'danger' : 'ok', 'url' => IEUM_URL . '/dashboard.php#autoCheck'),
        'todo' => array('label' => '할 일', 'icon' => '✓', 'count' => $todo_count, 'tone' => $todo_count > 0 ? 'warn' : 'ok', 'url' => IEUM_URL . '/dashboard.php#todayTodo'),
        'report' => array('label' => '리포트', 'icon' => '▥', 'count' => $report_count, 'tone' => $report_count > 0 ? 'warn' : 'ok', 'url' => IEUM_URL . '/dashboard.php#reportClose'),
        'prepare' => array('label' => '준비', 'icon' => '◈', 'count' => $prepare_count, 'tone' => $prepare_count > 0 ? 'warn' : 'ok', 'url' => IEUM_URL . '/admin/promotion_belts_needed.php?month=' . urlencode($report_month)),
    );
}
