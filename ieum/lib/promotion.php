<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_promotion_add_column_if_missing($table, $column, $definition)
{
    $table = preg_replace('/[^0-9A-Za-z_]/', '', (string) $table);
    $column = preg_replace('/[^0-9A-Za-z_]/', '', (string) $column);
    if ($table === '' || $column === '') {
        return;
    }

    $exists = sql_fetch("show columns from {$table} like '{$column}'", false);
    if (!isset($exists['Field'])) {
        sql_query("alter table {$table} add {$column} {$definition}", false);
    }
}

function ieum_promotion_ensure_schema()
{
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_interval_months', 'tinyint unsigned not null default 3');
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_belts', 'text null');
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_belt_ranges', 'text null');
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_notice_days', 'smallint unsigned not null default 31');
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_certificate_template', "varchar(30) not null default 'official'");
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_certificate_no_rule', "varchar(30) not null default 'ieum'");
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_certificate_no_prefix', "varchar(60) not null default ''");
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_certificate_seal_path', "varchar(255) not null default ''");
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_notice_template', 'text null');
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_poomdan_notice_template', 'text null');
    ieum_promotion_add_column_if_missing(IEUM_ACADEMY_TABLE, 'promotion_payment_account', "varchar(255) not null default ''");

    ieum_promotion_add_column_if_missing(IEUM_STUDENT_TABLE, 'promotion_enabled', 'tinyint(1) not null default 1');
    ieum_promotion_add_column_if_missing(IEUM_STUDENT_TABLE, 'current_belt', "varchar(80) not null default ''");
    ieum_promotion_add_column_if_missing(IEUM_STUDENT_TABLE, 'current_poom_dan', 'tinyint unsigned not null default 0');
    ieum_promotion_add_column_if_missing(IEUM_STUDENT_TABLE, 'current_grade_level', 'tinyint unsigned not null default 0');
    ieum_promotion_add_column_if_missing(IEUM_STUDENT_TABLE, 'last_promotion_date', 'date null');
    ieum_promotion_add_column_if_missing(IEUM_STUDENT_TABLE, 'promotion_cycle_months', 'tinyint unsigned not null default 0');
    ieum_promotion_add_column_if_missing(IEUM_STUDENT_TABLE, 'promotion_memo', "varchar(255) not null default ''");

    sql_query("
        create table if not exists " . IEUM_PROMOTION_LOG_TABLE . " (
            log_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            from_belt varchar(80) not null default '',
            from_poom_dan tinyint unsigned not null default 0,
            from_grade_level tinyint unsigned not null default 0,
            to_belt varchar(80) not null default '',
            to_poom_dan tinyint unsigned not null default 0,
            to_grade_level tinyint unsigned not null default 0,
            promoted_at date not null,
            promoted_by varchar(50) not null default '',
            created_at datetime not null,
            primary key (log_id),
            key idx_academy_student (academy_id, student_id),
            key idx_academy_promoted_at (academy_id, promoted_at)
        ) default charset=utf8
    ", false);
    ieum_promotion_add_column_if_missing(IEUM_PROMOTION_LOG_TABLE, 'certificate_printed_at', 'datetime null');
    ieum_promotion_add_column_if_missing(IEUM_PROMOTION_LOG_TABLE, 'certificate_printed_by', "varchar(50) not null default ''");

    sql_query("
        create table if not exists " . IEUM_PROMOTION_MISSION_TABLE . " (
            mission_id int unsigned not null auto_increment,
            academy_id int unsigned not null default 0,
            program_code varchar(30) not null default 'taekwondo',
            belt_name varchar(80) not null default '',
            category varchar(40) not null default '',
            category_label varchar(80) not null default '',
            mission_items text null,
            sort_order int not null default 0,
            is_active tinyint(1) not null default 1,
            updated_at datetime null,
            primary key (mission_id),
            unique key uq_academy_belt_category (academy_id, program_code, belt_name, category),
            key idx_academy_program_belt (academy_id, program_code, belt_name)
        ) default charset=utf8
    ", false);

    if (defined('IEUM_PROMOTION_EXAM_FEE_TABLE')) {
        sql_query("
            create table if not exists " . IEUM_PROMOTION_EXAM_FEE_TABLE . " (
                fee_id int unsigned not null auto_increment,
                academy_id int unsigned not null,
                exam_type varchar(20) not null default 'promotion',
                target_poom_dan tinyint unsigned not null default 0,
                target_grade_level tinyint unsigned not null default 0,
                target_belt varchar(80) not null default '',
                fee_amount int unsigned not null default 0,
                title varchar(120) not null default '',
                memo varchar(255) not null default '',
                is_active tinyint(1) not null default 1,
                created_at datetime null,
                updated_at datetime null,
                primary key (fee_id),
                unique key uq_academy_exam_rank (academy_id, exam_type, target_poom_dan, target_grade_level, target_belt),
                key idx_academy_exam (academy_id, exam_type, is_active)
            ) default charset=utf8
        ", false);
    }

    if (defined('IEUM_PROMOTION_NOTICE_LOG_TABLE')) {
        sql_query("
            create table if not exists " . IEUM_PROMOTION_NOTICE_LOG_TABLE . " (
                notice_id int unsigned not null auto_increment,
                academy_id int unsigned not null,
                student_id int unsigned not null,
                exam_type varchar(20) not null default 'promotion',
                notice_month char(7) not null default '',
                target_label varchar(80) not null default '',
                fee_amount int unsigned not null default 0,
                message text null,
                sms_queue_ids varchar(255) not null default '',
                sent_by varchar(50) not null default '',
                sent_at datetime not null,
                primary key (notice_id),
                key idx_academy_month (academy_id, notice_month, exam_type),
                key idx_student_month (academy_id, student_id, notice_month, exam_type)
            ) default charset=utf8
        ", false);
    }
}

function ieum_promotion_default_belts()
{
    return array('흰띠', '노란띠', '초록띠', '파란띠', '빨간띠', '품띠');
}

function ieum_promotion_parse_belts($text)
{
    $items = preg_split('/[\r\n,]+/u', (string) $text);
    $belts = array();
    foreach ($items as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        if (!in_array($item, $belts, true)) {
            $belts[] = $item;
        }
    }
    return $belts ? $belts : ieum_promotion_default_belts();
}

function ieum_promotion_belts($academy)
{
    $raw = isset($academy['promotion_belts']) ? $academy['promotion_belts'] : '';
    return ieum_promotion_parse_belts($raw);
}

function ieum_promotion_default_belt_ranges()
{
    return array(
        array('belt' => '흰띠', 'from' => 18, 'to' => 16),
        array('belt' => '노란띠', 'from' => 15, 'to' => 13),
        array('belt' => '초록띠', 'from' => 12, 'to' => 10),
        array('belt' => '파란띠', 'from' => 9, 'to' => 7),
        array('belt' => '빨간띠', 'from' => 6, 'to' => 4),
        array('belt' => '품띠', 'from' => 3, 'to' => 1),
    );
}

function ieum_promotion_format_belt_ranges($ranges)
{
    $lines = array();
    foreach ((array) $ranges as $range) {
        if (!isset($range['belt'], $range['from'], $range['to'])) {
            continue;
        }
        $lines[] = $range['belt'] . '|' . (int) $range['from'] . '|' . (int) $range['to'];
    }
    return implode("\n", $lines);
}

function ieum_promotion_parse_belt_ranges($text)
{
    $rows = preg_split('/\r\n|\r|\n/u', (string) $text);
    $ranges = array();
    foreach ($rows as $row) {
        $row = trim($row);
        if ($row === '') {
            continue;
        }
        $parts = preg_split('/[|,\t]+/u', $row);
        if (count($parts) < 3) {
            continue;
        }
        $belt = trim($parts[0]);
        $from = (int) trim($parts[1]);
        $to = (int) trim($parts[2]);
        if ($belt === '') {
            continue;
        }
        if ($from < 1 || $from > 18 || $to < 1 || $to > 18) {
            continue;
        }
        $ranges[] = array(
            'belt' => $belt,
            'from' => max($from, $to),
            'to' => min($from, $to),
        );
    }
    return $ranges ? $ranges : ieum_promotion_default_belt_ranges();
}

function ieum_promotion_belt_ranges($academy)
{
    $raw = isset($academy['promotion_belt_ranges']) ? $academy['promotion_belt_ranges'] : '';
    return ieum_promotion_parse_belt_ranges($raw);
}

function ieum_promotion_belt_for_grade($academy, $grade_level, $poom_dan = 0)
{
    $grade_level = (int) $grade_level;
    $poom_dan = (int) $poom_dan;
    $ranges = ieum_promotion_belt_ranges($academy);
    if ($grade_level <= 0 && $poom_dan > 0) {
        $last = end($ranges);
        return isset($last['belt']) ? $last['belt'] : '품띠';
    }
    foreach ($ranges as $range) {
        if ($grade_level <= (int) $range['from'] && $grade_level >= (int) $range['to']) {
            return $range['belt'];
        }
    }
    return '';
}

function ieum_promotion_is_poomdan_gate($student)
{
    $grade_level = isset($student['current_grade_level']) ? (int) $student['current_grade_level'] : 0;
    $poom_dan = isset($student['current_poom_dan']) ? (int) $student['current_poom_dan'] : 0;
    return $grade_level === 1 && $poom_dan < 4;
}

function ieum_promotion_exam_type($student)
{
    return ieum_promotion_is_poomdan_gate($student) ? 'poomdan' : 'promotion';
}

function ieum_promotion_full_rank_label($poom_dan, $grade_level)
{
    $poom_dan = (int) $poom_dan;
    $grade_level = (int) $grade_level;
    $parts = array();
    if ($poom_dan > 0) {
        $parts[] = min(4, $poom_dan) . '품/단';
    }
    if ($grade_level > 0) {
        $parts[] = $grade_level . '급';
    }
    return $parts ? implode(' ', $parts) : '미지정';
}

function ieum_promotion_next_poomdan_rank($academy, $student)
{
    $current_poom_dan = isset($student['current_poom_dan']) ? (int) $student['current_poom_dan'] : 0;
    $next_poom_dan = min(4, max(0, $current_poom_dan) + 1);
    $next_grade = 18;
    $next_belt = ieum_promotion_belt_for_grade($academy, 0, $next_poom_dan);
    if ($next_belt === '') {
        $next_belt = '품띠';
    }

    return array(
        'belt' => $next_belt,
        'poom_dan' => $next_poom_dan,
        'grade_level' => $next_grade,
        'exam_label' => $next_poom_dan . '품/단',
        'post_pass_label' => ieum_promotion_full_rank_label($next_poom_dan, $next_grade),
    );
}

function ieum_promotion_next_rank($academy, $student)
{
    $current_grade = isset($student['current_grade_level']) ? (int) $student['current_grade_level'] : 0;
    $current_poom_dan = isset($student['current_poom_dan']) ? (int) $student['current_poom_dan'] : 0;

    if (ieum_promotion_is_poomdan_gate($student)) {
        return ieum_promotion_next_poomdan_rank($academy, $student);
    }

    if ($current_grade > 1) {
        $next_grade = $current_grade - 1;
        $next_poom_dan = $current_poom_dan;
    } else {
        $next_grade = $current_grade > 0 ? $current_grade : 18;
        $next_poom_dan = $current_poom_dan;
    }

    $next_belt = ieum_promotion_belt_for_grade($academy, $next_grade, $next_poom_dan);
    if ($next_belt === '') {
        $next_belt = ieum_promotion_next_belt($academy, isset($student['current_belt']) ? $student['current_belt'] : '');
    }

    return array(
        'belt' => $next_belt,
        'poom_dan' => $next_poom_dan,
        'grade_level' => $next_grade,
    );
}

function ieum_promotion_rank_label($poom_dan, $grade_level)
{
    $poom_dan = (int) $poom_dan;
    $grade_level = (int) $grade_level;
    if ($grade_level > 0) {
        return $grade_level . '급';
    }
    if ($poom_dan > 0) {
        return min(4, $poom_dan) . '품/단';
    }
    return '미지정';
}

function ieum_promotion_cycle_months($academy, $student = null)
{
    $student_cycle = $student && isset($student['promotion_cycle_months']) ? (int) $student['promotion_cycle_months'] : 0;
    if ($student_cycle >= 1 && $student_cycle <= 4) {
        return $student_cycle;
    }

    $academy_cycle = isset($academy['promotion_interval_months']) ? (int) $academy['promotion_interval_months'] : 3;
    if ($academy_cycle < 1 || $academy_cycle > 4) {
        return 3;
    }
    return $academy_cycle;
}

function ieum_promotion_exam_type_label($exam_type)
{
    return $exam_type === 'poomdan' ? '승품/단 심사' : '승급 심사';
}

function ieum_promotion_notice_default_templates()
{
    return array(
        'promotion' => "[아이이음] {student_name} 원생은 {academy_name} {exam_name} 대상입니다.\n현재: {current_rank}\n심사: {target_rank}\n심사 예정일: {exam_date}\n심사비: {fee_amount}원\n입금 계좌: {payment_account}\n자세한 안내는 도장 공지를 확인해 주세요.",
        'poomdan' => "[아이이음] {student_name} 원생은 협회 {exam_name} 대상입니다.\n현재: {current_rank}\n심사: {target_rank}\n합격 후: {post_pass_rank}\n심사 예정일: {exam_date}\n심사비: {fee_amount}원\n입금 계좌: {payment_account}\n승품/단 심사는 협회 기준으로 진행됩니다.",
    );
}

function ieum_promotion_notice_template_display($template)
{
    return str_replace(
        array('{student_name} 학생은', '{student_name} 학생이', ' 학생은 ', ' 학생이 '),
        array('{student_name} 원생은', '{student_name} 원생이', ' 원생은 ', ' 원생이 '),
        (string) $template
    );
}

function ieum_promotion_payment_account($academy, $fallback = true)
{
    $account = isset($academy['promotion_payment_account']) ? trim((string) $academy['promotion_payment_account']) : '';
    if ($account === '' && $fallback) {
        return '별도 안내';
    }
    return $account;
}

function ieum_promotion_notice_template($academy, $exam_type)
{
    $exam_type = $exam_type === 'poomdan' ? 'poomdan' : 'promotion';
    $defaults = ieum_promotion_notice_default_templates();
    $column = $exam_type === 'poomdan' ? 'promotion_poomdan_notice_template' : 'promotion_notice_template';
    $template = isset($academy[$column]) ? trim((string) $academy[$column]) : '';
    return ieum_promotion_notice_template_display($template !== '' ? $template : $defaults[$exam_type]);
}

function ieum_promotion_rank_display($academy, $rank)
{
    $belt = isset($rank['belt']) ? trim((string) $rank['belt']) : '';
    $label = ieum_promotion_full_rank_label(isset($rank['poom_dan']) ? $rank['poom_dan'] : 0, isset($rank['grade_level']) ? $rank['grade_level'] : 0);
    return trim(($belt !== '' ? $belt . ' ' : '') . $label);
}

function ieum_promotion_student_current_rank_display($student)
{
    return trim((isset($student['current_belt']) && $student['current_belt'] !== '' ? $student['current_belt'] . ' ' : '') . ieum_promotion_full_rank_label(isset($student['current_poom_dan']) ? $student['current_poom_dan'] : 0, isset($student['current_grade_level']) ? $student['current_grade_level'] : 0));
}

function ieum_promotion_fee_key($exam_type, $rank)
{
    $exam_type = $exam_type === 'poomdan' ? 'poomdan' : 'promotion';
    return $exam_type . '|' . (int) (isset($rank['poom_dan']) ? $rank['poom_dan'] : 0) . '|' . (int) (isset($rank['grade_level']) ? $rank['grade_level'] : 0) . '|' . trim((string) (isset($rank['belt']) ? $rank['belt'] : ''));
}

function ieum_promotion_get_fee_map($academy_id)
{
    $academy_id = (int) $academy_id;
    $map = array();
    if (!$academy_id || !defined('IEUM_PROMOTION_EXAM_FEE_TABLE')) {
        return $map;
    }
    $result = sql_query("
        select *
          from " . IEUM_PROMOTION_EXAM_FEE_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
    ", false);
    while ($row = sql_fetch_array($result)) {
        $map[ieum_promotion_fee_key($row['exam_type'], array(
            'belt' => $row['target_belt'],
            'poom_dan' => (int) $row['target_poom_dan'],
            'grade_level' => (int) $row['target_grade_level'],
        ))] = $row;
    }
    return $map;
}

function ieum_promotion_get_fee($academy_id, $exam_type, $rank)
{
    $map = ieum_promotion_get_fee_map($academy_id);
    $key = ieum_promotion_fee_key($exam_type, $rank);
    if (isset($map[$key])) {
        return $map[$key];
    }
    $fallback_key = ieum_promotion_fee_key($exam_type, array(
        'belt' => '',
        'poom_dan' => isset($rank['poom_dan']) ? (int) $rank['poom_dan'] : 0,
        'grade_level' => isset($rank['grade_level']) ? (int) $rank['grade_level'] : 0,
    ));
    return isset($map[$fallback_key]) ? $map[$fallback_key] : null;
}

function ieum_promotion_fee_setting_rows($academy)
{
    $rows = array();
    for ($grade = 18; $grade >= 1; $grade--) {
        $belt = ieum_promotion_belt_for_grade($academy, $grade, 0);
        $rows[] = array(
            'exam_type' => 'promotion',
            'poom_dan' => 0,
            'grade_level' => $grade,
            'belt' => $belt,
            'label' => trim(($belt !== '' ? $belt . ' ' : '') . $grade . '급'),
        );
    }
    for ($poom = 1; $poom <= 4; $poom++) {
        $rows[] = array(
            'exam_type' => 'poomdan',
            'poom_dan' => $poom,
            'grade_level' => 18,
            'belt' => '',
            'label' => $poom . '품/단 심사',
        );
    }
    return $rows;
}

function ieum_promotion_save_fee_rows($academy_id, $fee_rows)
{
    $academy_id = (int) $academy_id;
    if (!$academy_id || !defined('IEUM_PROMOTION_EXAM_FEE_TABLE') || !is_array($fee_rows)) {
        return 0;
    }

    $saved = 0;
    foreach ($fee_rows as $fee_row) {
        $row_exam_type = isset($fee_row['exam_type']) && $fee_row['exam_type'] === 'poomdan' ? 'poomdan' : 'promotion';
        $target_poom_dan = isset($fee_row['poom_dan']) ? (int) $fee_row['poom_dan'] : 0;
        $target_grade_level = isset($fee_row['grade_level']) ? (int) $fee_row['grade_level'] : 0;
        $target_belt = isset($fee_row['belt']) ? trim((string) $fee_row['belt']) : '';
        $fee_amount = isset($fee_row['amount']) ? (int) preg_replace('/[^0-9]/', '', (string) $fee_row['amount']) : 0;
        $title = isset($fee_row['title']) ? trim((string) $fee_row['title']) : '';

        if ($target_poom_dan < 0 || $target_poom_dan > 4 || $target_grade_level < 0 || $target_grade_level > 18) {
            continue;
        }

        if ($fee_amount <= 0) {
            sql_query("
                update " . IEUM_PROMOTION_EXAM_FEE_TABLE . "
                   set is_active = 0,
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
                   and exam_type = '" . sql_escape_string($row_exam_type) . "'
                   and target_poom_dan = '{$target_poom_dan}'
                   and target_grade_level = '{$target_grade_level}'
                   and target_belt = '" . sql_escape_string($target_belt) . "'
            ", false);
            continue;
        }

        sql_query("
            insert into " . IEUM_PROMOTION_EXAM_FEE_TABLE . "
               set academy_id = '{$academy_id}',
                   exam_type = '" . sql_escape_string($row_exam_type) . "',
                   target_poom_dan = '{$target_poom_dan}',
                   target_grade_level = '{$target_grade_level}',
                   target_belt = '" . sql_escape_string($target_belt) . "',
                   fee_amount = '{$fee_amount}',
                   title = '" . sql_escape_string($title) . "',
                   is_active = 1,
                   created_at = '" . G5_TIME_YMDHIS . "',
                   updated_at = '" . G5_TIME_YMDHIS . "'
            on duplicate key update
                   fee_amount = values(fee_amount),
                   title = values(title),
                   is_active = 1,
                   updated_at = values(updated_at)
        ");
        $saved++;
    }

    return $saved;
}

function ieum_promotion_notice_recipients($academy_id, $student_id)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $phones = array();
    $result = sql_query("
        select guardian_phone
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and is_active = 1
           and sms_tuition = 1
           and guardian_phone <> ''
      order by is_primary desc, sort_order asc, guardian_id asc
    ", false);
    while ($row = sql_fetch_array($result)) {
        $phone = trim((string) $row['guardian_phone']);
        if ($phone !== '' && !in_array($phone, $phones, true)) {
            $phones[] = $phone;
        }
    }
    if (!$phones) {
        $student = sql_fetch("
            select parent_phone
              from " . IEUM_STUDENT_TABLE . "
             where academy_id = '{$academy_id}'
               and student_id = '{$student_id}'
             limit 1
        ", false);
        if (!empty($student['parent_phone'])) {
            $phones[] = trim((string) $student['parent_phone']);
        }
    }
    return $phones;
}

function ieum_promotion_render_notice_message($academy, $student, $status, $fee_amount = 0)
{
    $exam_type = !empty($status['is_poomdan_exam']) ? 'poomdan' : 'promotion';
    $next_rank = isset($status['next_rank']) ? $status['next_rank'] : array();
    $template = ieum_promotion_notice_template($academy, $exam_type);
    if (strpos($template, '{payment_account}') === false) {
        $template = rtrim($template) . "\n입금 계좌: {payment_account}";
    }
    $target_label = isset($next_rank['exam_label']) ? $next_rank['exam_label'] : ieum_promotion_rank_display($academy, $next_rank);
    $post_pass_label = isset($next_rank['post_pass_label']) ? $next_rank['post_pass_label'] : ieum_promotion_rank_display($academy, $next_rank);
    $tokens = array(
        '{academy_name}' => isset($academy['academy_name']) ? $academy['academy_name'] : '',
        '{student_name}' => isset($student['student_name']) ? $student['student_name'] : '',
        '{student_code}' => isset($student['student_code']) ? $student['student_code'] : '',
        '{exam_name}' => ieum_promotion_exam_type_label($exam_type),
        '{current_rank}' => ieum_promotion_student_current_rank_display($student),
        '{target_rank}' => $target_label,
        '{post_pass_rank}' => $post_pass_label,
        '{exam_date}' => isset($status['next_date']) && $status['next_date'] !== '' ? $status['next_date'] : '별도 안내',
        '{fee_amount}' => number_format((int) $fee_amount),
        '{payment_account}' => ieum_promotion_payment_account($academy),
    );
    return strtr($template, $tokens);
}

function ieum_promotion_create_notice_queue($academy, $student, $status, $fee_amount, $sent_by = '', $notice_month = '')
{
    $academy_id = (int) $academy['academy_id'];
    $student_id = (int) $student['student_id'];
    $exam_type = !empty($status['is_poomdan_exam']) ? 'poomdan' : 'promotion';
    $notice_month = preg_match('/^\d{4}-\d{2}$/', (string) $notice_month) ? $notice_month : date('Y-m');
    $message = ieum_promotion_render_notice_message($academy, $student, $status, $fee_amount);
    $recipients = ieum_promotion_notice_recipients($academy_id, $student_id);
    $queue_ids = array();
    foreach ($recipients as $phone) {
        $sms_id = ieum_create_direct_sms_queue($academy_id, $phone, $message, 'promotion_' . $exam_type, $student_id, 0);
        if ($sms_id) {
            $queue_ids[] = $sms_id;
        }
    }
    if ($queue_ids && defined('IEUM_PROMOTION_NOTICE_LOG_TABLE')) {
        $next_rank = isset($status['next_rank']) ? $status['next_rank'] : array();
        $target_label = isset($next_rank['exam_label']) ? $next_rank['exam_label'] : ieum_promotion_rank_display($academy, $next_rank);
        sql_query("
            insert into " . IEUM_PROMOTION_NOTICE_LOG_TABLE . "
               set academy_id = '{$academy_id}',
                   student_id = '{$student_id}',
                   exam_type = '" . sql_escape_string($exam_type) . "',
                   notice_month = '" . sql_escape_string($notice_month) . "',
                   target_label = '" . sql_escape_string($target_label) . "',
                   fee_amount = '" . (int) $fee_amount . "',
                   message = '" . sql_escape_string($message) . "',
                   sms_queue_ids = '" . sql_escape_string(implode(',', $queue_ids)) . "',
                   sent_by = '" . sql_escape_string($sent_by) . "',
                   sent_at = '" . G5_TIME_YMDHIS . "'
        ", false);
    }
    return array('queue_ids' => $queue_ids, 'message' => $message, 'recipients' => $recipients);
}

function ieum_promotion_base_date($student)
{
    foreach (array('last_promotion_date', 'admission_date') as $key) {
        if (!empty($student[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $student[$key])) {
            return $student[$key];
        }
    }
    if (!empty($student['created_at']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $student['created_at'])) {
        return substr($student['created_at'], 0, 10);
    }
    return '';
}

function ieum_promotion_next_date($academy, $student)
{
    $base = ieum_promotion_base_date($student);
    if ($base === '') {
        return '';
    }
    $cycle = ieum_promotion_cycle_months($academy, $student);
    $next = strtotime($base . ' +' . $cycle . ' months');
    return $next ? date('Y-m-d', $next) : '';
}

function ieum_promotion_next_belt($academy, $current_belt)
{
    $belts = ieum_promotion_belts($academy);
    $current_belt = trim((string) $current_belt);
    if ($current_belt === '') {
        return isset($belts[0]) ? $belts[0] : '';
    }
    $idx = array_search($current_belt, $belts, true);
    if ($idx === false) {
        return $current_belt;
    }
    return isset($belts[$idx + 1]) ? $belts[$idx + 1] : $current_belt;
}

function ieum_promotion_poom_dan_label($value)
{
    $value = (int) $value;
    if ($value <= 0) {
        return '0품/단';
    }
    if ($value > 4) {
        $value = 4;
    }
    return $value . '품/단';
}

function ieum_promotion_grade_label($value)
{
    $value = (int) $value;
    if ($value < 0) {
        $value = 0;
    } elseif ($value > 18) {
        $value = 18;
    }
    return $value . '급';
}

function ieum_promotion_certificate_template($academy)
{
    $template = isset($academy['promotion_certificate_template']) ? trim((string) $academy['promotion_certificate_template']) : 'official';
    $allowed = array('official', 'classic', 'clean');
    return in_array($template, $allowed, true) ? $template : 'official';
}

function ieum_promotion_certificate_no_rule($academy)
{
    $rule = isset($academy['promotion_certificate_no_rule']) ? trim((string) $academy['promotion_certificate_no_rule']) : 'ieum';
    $allowed = array('ieum', 'academy_month', 'custom_prefix');
    return in_array($rule, $allowed, true) ? $rule : 'ieum';
}

function ieum_promotion_certificate_no_prefix($academy)
{
    $prefix = isset($academy['promotion_certificate_no_prefix']) ? trim((string) $academy['promotion_certificate_no_prefix']) : '';
    $prefix = preg_replace('/[\r\n\t]+/u', ' ', $prefix);
    return trim($prefix);
}

function ieum_promotion_certificate_seal_url($academy)
{
    $path = isset($academy['promotion_certificate_seal_path']) ? trim((string) $academy['promotion_certificate_seal_path']) : '';
    if ($path === '') {
        return '';
    }
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (strpos($path, '..') !== false) {
        return '';
    }
    if (!is_file(G5_DATA_PATH . '/' . $path)) {
        return '';
    }
    return G5_DATA_URL . '/' . $path;
}

function ieum_promotion_certificate_no($academy_id, $log_id, $promoted_at, $academy = null)
{
    $academy_id = (int) $academy_id;
    $log_id = (int) $log_id;
    $promoted_month = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $promoted_at) ? date('Ym', strtotime($promoted_at)) : date('Ym');
    $serial = str_pad((string) $log_id, 5, '0', STR_PAD_LEFT);

    if (is_array($academy)) {
        $rule = ieum_promotion_certificate_no_rule($academy);
        $prefix = ieum_promotion_certificate_no_prefix($academy);
        if ($rule === 'academy_month') {
            $academy_code = isset($academy['academy_code']) && trim((string) $academy['academy_code']) !== '' ? trim((string) $academy['academy_code']) : 'AC' . str_pad((string) $academy_id, 3, '0', STR_PAD_LEFT);
            return $academy_code . '-' . $promoted_month . '-' . $serial;
        }
        if ($rule === 'custom_prefix') {
            $prefix = $prefix !== '' ? $prefix : 'IEUM';
            return $prefix . '-' . $promoted_month . '-' . $serial;
        }
    }

    return 'IEUM-' . $promoted_month . '-' . str_pad((string) $academy_id, 3, '0', STR_PAD_LEFT) . '-' . $serial;
}

function ieum_promotion_status($academy, $student, $month = '')
{
    $enabled = !isset($student['promotion_enabled']) || (int) $student['promotion_enabled'] === 1;
    $next_date = ieum_promotion_next_date($academy, $student);
    $today = date('Y-m-d');
    $month = preg_match('/^\d{4}-\d{2}$/', (string) $month) ? $month : date('Y-m');
    $month_start = $month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $due_this_month = $enabled && $next_date !== '' && $next_date >= $month_start && $next_date <= $month_end;
    $overdue = $enabled && $next_date !== '' && $next_date < $today;
    $days_left = null;
    if ($enabled && $next_date !== '') {
        $days_left = (int) floor((strtotime($next_date) - strtotime($today)) / 86400);
    }

    $exam_type = ieum_promotion_exam_type($student);
    return array(
        'enabled' => $enabled,
        'exam_type' => $exam_type,
        'is_poomdan_exam' => $exam_type === 'poomdan',
        'is_promotion_exam' => $exam_type === 'promotion',
        'belt' => isset($student['current_belt']) && $student['current_belt'] !== '' ? $student['current_belt'] : '',
        'poom_dan' => isset($student['current_poom_dan']) ? (int) $student['current_poom_dan'] : 0,
        'grade_level' => isset($student['current_grade_level']) ? (int) $student['current_grade_level'] : 0,
        'next_rank' => ieum_promotion_next_rank($academy, $student),
        'cycle_months' => ieum_promotion_cycle_months($academy, $student),
        'base_date' => ieum_promotion_base_date($student),
        'next_date' => $next_date,
        'due_this_month' => $due_this_month,
        'overdue' => $overdue,
        'days_left' => $days_left,
    );
}

function ieum_promotion_record_log($academy_id, $student, $next_rank, $promoted_at, $promoted_by = '')
{
    $academy_id = (int) $academy_id;
    $student_id = isset($student['student_id']) ? (int) $student['student_id'] : 0;
    if ($academy_id <= 0 || $student_id <= 0 || !defined('IEUM_PROMOTION_LOG_TABLE')) {
        return;
    }

    $from_belt = isset($student['current_belt']) ? $student['current_belt'] : '';
    $from_poom_dan = isset($student['current_poom_dan']) ? (int) $student['current_poom_dan'] : 0;
    $from_grade_level = isset($student['current_grade_level']) ? (int) $student['current_grade_level'] : 0;
    $to_belt = isset($next_rank['belt']) ? $next_rank['belt'] : '';
    $to_poom_dan = isset($next_rank['poom_dan']) ? (int) $next_rank['poom_dan'] : 0;
    $to_grade_level = isset($next_rank['grade_level']) ? (int) $next_rank['grade_level'] : 0;

    sql_query("
        insert into " . IEUM_PROMOTION_LOG_TABLE . "
           set academy_id = '{$academy_id}',
               student_id = '{$student_id}',
               from_belt = '" . sql_escape_string($from_belt) . "',
               from_poom_dan = '{$from_poom_dan}',
               from_grade_level = '{$from_grade_level}',
               to_belt = '" . sql_escape_string($to_belt) . "',
               to_poom_dan = '{$to_poom_dan}',
               to_grade_level = '{$to_grade_level}',
               promoted_at = '" . sql_escape_string($promoted_at) . "',
               promoted_by = '" . sql_escape_string($promoted_by) . "',
               created_at = '" . G5_TIME_YMDHIS . "'
    ", false);
}

function ieum_promotion_mission_default_rows()
{
    $stance_common = "모아서기\n나란히 서기\n주춤서기";
    $stance_front = $stance_common . "\n앞서기\n앞굽이";
    $stance_back = $stance_front . "\n뒷굽이";
    $stance_full = $stance_back . "\n범서기\n학다리 서기\n꼬아서기";

    $kick_white = '뻗어 올리기';
    $kick_yellow = $kick_white . "\n찍기";
    $kick_orange = $kick_yellow . "\n앞차기 몸통\n앞차기 얼굴";
    $kick_green = $kick_yellow . "\n앞차기 중단\n앞차기 상단\n돌려차기 중단\n돌려차기 상단";
    $kick_purple = $kick_green . "\n옆차기";
    $kick_blue = $kick_purple . "\n뒤차기";
    $kick_brown = $kick_blue . "\n뒤 후려차기";
    $kick_red = $kick_brown . "\n뛰어 앞차기";
    $kick_poom = $kick_red . "\n연결 발차기";

    $by_belt = array(
        '흰띠' => array($stance_common, $kick_white, '주먹 격파'),
        '노란띠' => array($stance_front, $kick_yellow, '앞차기 몸통 발차기'),
        '주황띠' => array($stance_back, $kick_orange, '앞차기 얼굴 발차기'),
        '초록띠' => array($stance_full, $kick_green, '돌려차기 몸통 발차기'),
        '보라띠' => array($stance_full, $kick_purple, '돌려차기 얼굴 발차기'),
        '파란띠' => array($stance_full, $kick_blue, '옆차기'),
        '밤띠' => array($stance_full, $kick_brown, '뒤차기'),
        '빨간띠' => array($stance_full, $kick_red, '뛰어 앞차기'),
        '품띠' => array($stance_full, $kick_poom, '뛰어 옆차기 또는 돌개차기'),
        '1품' => array($stance_full, $kick_poom, '뛰어 옆차기 또는 돌개차기'),
        '2품' => array($stance_full, $kick_poom, '뛰어 옆차기 또는 돌개차기'),
        '3품' => array($stance_full, $kick_poom, '뛰어 옆차기 또는 돌개차기'),
        '4품' => array($stance_full, $kick_poom, '뛰어 옆차기 또는 돌개차기'),
    );

    $rows = array();
    foreach ($by_belt as $belt_name => $sets) {
        $rows[] = array('belt_name' => $belt_name, 'category' => 'stance', 'category_label' => '기본서기', 'mission_items' => $sets[0], 'sort_order' => 10);
        $rows[] = array('belt_name' => $belt_name, 'category' => 'kick', 'category_label' => '발차기', 'mission_items' => $sets[1], 'sort_order' => 20);
        $rows[] = array('belt_name' => $belt_name, 'category' => 'breaking', 'category_label' => '격파', 'mission_items' => $sets[2], 'sort_order' => 30);
    }
    return $rows;
}
function ieum_promotion_mission_belt_key($belt_name, $poom_dan = 0)
{
    $belt_name = trim((string) $belt_name);
    $poom_dan = (int) $poom_dan;
    if ($poom_dan > 0) {
        return min(4, $poom_dan) . '품';
    }
    $aliases = array(
        '파랑띠' => '파란띠',
        '빨강띠' => '빨간띠',
    );
    return isset($aliases[$belt_name]) ? $aliases[$belt_name] : $belt_name;
}
function ieum_promotion_mission_split_items($text)
{
    $parts = preg_split('/\r\n|\r|\n|,/u', (string) $text);
    $items = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $items[] = $part;
        }
    }
    return $items;
}

function ieum_promotion_seed_default_missions($academy_id = 0, $program_code = 'taekwondo', $force = false)
{
    $academy_id = (int) $academy_id;
    $program_code = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $program_code);
    if ($program_code === '') {
        $program_code = 'taekwondo';
    }
    if (!$force) {
        $exists = sql_fetch("
            select mission_id
              from " . IEUM_PROMOTION_MISSION_TABLE . "
             where academy_id = '{$academy_id}'
               and program_code = '" . sql_escape_string($program_code) . "'
             limit 1
        ", false);
        if (isset($exists['mission_id'])) {
            return;
        }
    }

    if ($force) {
        sql_query("
            delete from " . IEUM_PROMOTION_MISSION_TABLE . "
             where academy_id = '{$academy_id}'
               and program_code = '" . sql_escape_string($program_code) . "'
        ", false);
    }

    foreach (ieum_promotion_mission_default_rows() as $row) {
        sql_query("
            insert into " . IEUM_PROMOTION_MISSION_TABLE . "
               set academy_id = '{$academy_id}',
                   program_code = '" . sql_escape_string($program_code) . "',
                   belt_name = '" . sql_escape_string($row['belt_name']) . "',
                   category = '" . sql_escape_string($row['category']) . "',
                   category_label = '" . sql_escape_string($row['category_label']) . "',
                   mission_items = '" . sql_escape_string($row['mission_items']) . "',
                   sort_order = '" . (int) $row['sort_order'] . "',
                   is_active = 1,
                   updated_at = '" . G5_TIME_YMDHIS . "'
        ", false);
    }
}

function ieum_promotion_missions_for_belt($academy_id, $program_code, $belt_name, $poom_dan = 0)
{
    $academy_id = (int) $academy_id;
    $program_code = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $program_code);
    if ($program_code === '') {
        $program_code = 'taekwondo';
    }
    $belt_key = ieum_promotion_mission_belt_key($belt_name, $poom_dan);
    ieum_promotion_seed_default_missions(0, $program_code, false);

    $rows = array();
    foreach (array($academy_id, 0) as $scope_academy_id) {
        $rows = array();
        $result = sql_query("
            select *
              from " . IEUM_PROMOTION_MISSION_TABLE . "
             where academy_id = '" . (int) $scope_academy_id . "'
               and program_code = '" . sql_escape_string($program_code) . "'
               and belt_name = '" . sql_escape_string($belt_key) . "'
               and is_active = 1
          order by sort_order asc, mission_id asc
        ", false);
        while ($row = sql_fetch_array($result)) {
            $row['items'] = ieum_promotion_mission_split_items($row['mission_items']);
            if ($row['items']) {
                $rows[] = $row;
            }
        }
        if ($rows) {
            return $rows;
        }
    }

    foreach (ieum_promotion_mission_default_rows() as $row) {
        if ($row['belt_name'] !== $belt_key) {
            continue;
        }
        $row['items'] = ieum_promotion_mission_split_items($row['mission_items']);
        $rows[] = $row;
    }
    return $rows;
}



