<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

function ieum_fitness_add_column_if_missing($table, $column, $definition)
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

function ieum_fitness_ensure_table()
{
    $engine = defined('G5_DB_ENGINE') && G5_DB_ENGINE ? G5_DB_ENGINE : 'InnoDB';
    $charset = defined('G5_DB_CHARSET') && G5_DB_CHARSET ? G5_DB_CHARSET : 'utf8';

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
    ", false);

    ieum_fitness_add_column_if_missing(IEUM_REPORT_FITNESS_TABLE, 'height_cm', 'decimal(5,1) null after report_month');
    ieum_fitness_add_column_if_missing(IEUM_REPORT_FITNESS_TABLE, 'weight_kg', 'decimal(5,1) null after height_cm');
    ieum_fitness_add_column_if_missing(IEUM_STUDENT_TABLE, 'gender', "varchar(10) not null default 'all' after birth_date");
    ieum_fitness_add_column_if_missing(IEUM_STUDENT_TABLE, 'character_report_enabled', "tinyint(1) not null default 1 after gender");
    ieum_fitness_add_column_if_missing(IEUM_STUDENT_TABLE, 'fitness_report_enabled', "tinyint(1) not null default 1 after character_report_enabled");

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
    ", false);

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
    ", false);

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
    ", false);

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
    ", false);

    ieum_fitness_seed_default_standards();
    ieum_fitness_seed_default_metrics();
}

function ieum_fitness_cycle_options()
{
    return array(
        1 => '매월',
        2 => '2개월마다',
        3 => '3개월마다',
        4 => '4개월마다',
        6 => '6개월마다',
        12 => '1년에 1회',
    );
}

function ieum_fitness_setting_defaults()
{
    return array(
        'academy_id' => 0,
        'cycle_months' => 1,
        'report_send_enabled' => 0,
        'report_send_day' => 25,
        'custom_metric_enabled' => 0,
        'memo' => '',
    );
}

function ieum_fitness_settings($academy_id)
{
    $academy_id = (int) $academy_id;
    $defaults = ieum_fitness_setting_defaults();
    if (!$academy_id) {
        return $defaults;
    }

    $row = sql_fetch("
        select *
          from " . IEUM_FITNESS_SETTING_TABLE . "
         where academy_id = '{$academy_id}'
         limit 1
    ", false);
    if (!$row) {
        $defaults['academy_id'] = $academy_id;
        return $defaults;
    }

    return array_merge($defaults, $row);
}

function ieum_fitness_save_settings($academy_id, $data)
{
    $academy_id = (int) $academy_id;
    if (!$academy_id) {
        return;
    }
    $cycle_options = ieum_fitness_cycle_options();
    $cycle_months = isset($data['cycle_months']) ? (int) $data['cycle_months'] : 1;
    if (!isset($cycle_options[$cycle_months])) {
        $cycle_months = 1;
    }
    $send_day = isset($data['report_send_day']) ? (int) $data['report_send_day'] : 25;
    $send_day = max(1, min(31, $send_day));
    $report_send_enabled = !empty($data['report_send_enabled']) ? 1 : 0;
    $custom_metric_enabled = !empty($data['custom_metric_enabled']) ? 1 : 0;
    $memo = isset($data['memo']) ? trim((string) $data['memo']) : '';

    sql_query("
        insert into " . IEUM_FITNESS_SETTING_TABLE . "
            set academy_id = '{$academy_id}',
                cycle_months = '{$cycle_months}',
                report_send_enabled = '{$report_send_enabled}',
                report_send_day = '{$send_day}',
                custom_metric_enabled = '{$custom_metric_enabled}',
                memo = '" . sql_escape_string($memo) . "',
                created_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                cycle_months = values(cycle_months),
                report_send_enabled = values(report_send_enabled),
                report_send_day = values(report_send_day),
                custom_metric_enabled = values(custom_metric_enabled),
                memo = values(memo),
                updated_at = '" . G5_TIME_YMDHIS . "'
    ");
}

function ieum_fitness_grade_groups()
{
    return array(
        'age_6',
        'age_7',
        'kindergarten',
        'elementary_1',
        'elementary_2',
        'elementary_3',
        'elementary_4',
        'elementary_5',
        'elementary_6',
        'middle_1',
        'middle_2',
        'middle_3',
        'high_1',
        'high_2',
        'high_3',
        'adult',
        'jump_rope',
        '',
    );
}

function ieum_fitness_items()
{
    return array(
        'jump_rope' => array('label' => '줄넘기', 'unit' => '회', 'target' => 200, 'type' => 'higher', 'step' => 10),
        'shuttle_run' => array('label' => '왕복오래달리기(셔틀런)', 'unit' => '회', 'target' => 80, 'type' => 'higher', 'step' => 1),
        'push_up' => array('label' => '팔굽혀펴기', 'unit' => '회', 'target' => 50, 'type' => 'higher', 'step' => 1),
        'sit_up' => array('label' => '윗몸일으키기', 'unit' => '회', 'target' => 60, 'type' => 'higher', 'step' => 1),
        'long_jump' => array('label' => '제자리멀리뛰기', 'unit' => 'cm', 'target' => 180, 'type' => 'higher', 'step' => 5),
        'flexibility' => array('label' => '유연성', 'unit' => 'cm', 'target' => 25, 'type' => 'higher', 'step' => 1),
    );
}

function ieum_fitness_metric_rows($academy_id, $include_inactive = false)
{
    ieum_fitness_seed_default_metrics();

    $academy_id = (int) $academy_id;
    $items = array();
    $order = 10;
    foreach (ieum_fitness_items() as $key => $item) {
        $item['metric_key'] = $key;
        $item['sort_order'] = $order;
        $item['is_active'] = 1;
        $item['is_body'] = 0;
        $item['is_score_item'] = 1;
        $items[$key] = $item;
        $order += 10;
    }

    $academy_filter = $academy_id ? "0,{$academy_id}" : "0";
    $result = sql_query("
        select *
          from " . IEUM_FITNESS_METRIC_TABLE . "
         where academy_id in ({$academy_filter})
      order by academy_id asc, sort_order asc, metric_id asc
    ", false);
    while ($row = sql_fetch_array($result)) {
        $key = isset($row['metric_key']) ? $row['metric_key'] : '';
        if ($key === '') {
            continue;
        }
        if (!isset($items[$key])) {
            if ((int) $row['academy_id'] !== $academy_id || $academy_id <= 0) {
                continue;
            }
            $items[$key] = array(
                'label' => $row['metric_label'],
                'unit' => $row['unit'],
                'target' => 10,
                'type' => $row['metric_type'] !== '' ? $row['metric_type'] : 'higher',
                'step' => 1,
                'metric_key' => $key,
                'sort_order' => (int) $row['sort_order'],
                'is_active' => (int) $row['is_active'],
                'is_body' => (int) $row['is_body'],
                'is_score_item' => (int) $row['is_score_item'],
                'is_custom' => 1,
            );
            continue;
        }
        $items[$key]['label'] = $row['metric_label'] !== '' ? $row['metric_label'] : $items[$key]['label'];
        $items[$key]['unit'] = $row['unit'];
        $items[$key]['type'] = $row['metric_type'] !== '' ? $row['metric_type'] : $items[$key]['type'];
        $items[$key]['sort_order'] = (int) $row['sort_order'];
        $items[$key]['is_active'] = (int) $row['is_active'];
        $items[$key]['is_body'] = (int) $row['is_body'];
        $items[$key]['is_score_item'] = (int) $row['is_score_item'];
        $items[$key]['is_custom'] = empty($items[$key]['is_custom']) ? 0 : 1;
    }

    uasort($items, function ($a, $b) {
        $sort_a = isset($a['sort_order']) ? (int) $a['sort_order'] : 0;
        $sort_b = isset($b['sort_order']) ? (int) $b['sort_order'] : 0;
        if ($sort_a === $sort_b) {
            return strcmp($a['metric_key'], $b['metric_key']);
        }
        return $sort_a < $sort_b ? -1 : 1;
    });

    if (!$include_inactive) {
        foreach ($items as $key => $item) {
            if (empty($item['is_active'])) {
                unset($items[$key]);
            }
        }
    }

    return $items;
}

function ieum_fitness_active_items($academy_id)
{
    $items = ieum_fitness_metric_rows($academy_id, false);
    return $items ? $items : ieum_fitness_items();
}

function ieum_fitness_save_metric_settings($academy_id, $metrics)
{
    $academy_id = (int) $academy_id;
    if (!$academy_id || !is_array($metrics)) {
        return;
    }

    $current_metrics = ieum_fitness_metric_rows($academy_id, true);
    foreach ($current_metrics as $key => $default) {
        if (!preg_match('/^[0-9A-Za-z_]+$/', $key)) {
            continue;
        }
        $row = isset($metrics[$key]) && is_array($metrics[$key]) ? $metrics[$key] : array();
        $label = isset($row['label']) ? trim((string) $row['label']) : $default['label'];
        if ($label === '') {
            $label = $default['label'];
        }
        $unit = isset($row['unit']) ? trim((string) $row['unit']) : $default['unit'];
        if ($unit === '') {
            $unit = $default['unit'];
        }
        $metric_type = isset($row['metric_type']) ? trim((string) $row['metric_type']) : (isset($default['type']) ? $default['type'] : 'higher');
        if (!in_array($metric_type, array('higher', 'lower'), true)) {
            $metric_type = isset($default['type']) ? $default['type'] : 'higher';
        }
        $sort_order = isset($row['sort_order']) ? (int) $row['sort_order'] : 10;
        $sort_order = max(1, min(999, $sort_order));
        $is_active = !empty($row['is_active']) ? 1 : 0;

        sql_query("
            insert into " . IEUM_FITNESS_METRIC_TABLE . "
                set academy_id = '{$academy_id}',
                    metric_key = '" . sql_escape_string($key) . "',
                    metric_label = '" . sql_escape_string($label) . "',
                    unit = '" . sql_escape_string($unit) . "',
                    metric_type = '" . sql_escape_string($metric_type) . "',
                    is_body = 0,
                    is_score_item = 1,
                    sort_order = '{$sort_order}',
                    is_active = '{$is_active}',
                    created_at = '" . G5_TIME_YMDHIS . "'
            on duplicate key update
                    metric_label = values(metric_label),
                    unit = values(unit),
                    metric_type = values(metric_type),
                    sort_order = values(sort_order),
                    is_active = values(is_active),
                    updated_at = '" . G5_TIME_YMDHIS . "'
        ");
    }

    $new_label = isset($metrics['_new']['label']) ? trim((string) $metrics['_new']['label']) : '';
    if ($new_label !== '') {
        $base_key = preg_replace('/[^0-9a-z_]/', '_', strtolower($new_label));
        $base_key = trim($base_key, '_');
        if ($base_key === '') {
            $base_key = 'metric';
        }
        $metric_key = 'custom_' . substr($base_key, 0, 24);
        $exists = sql_fetch("
            select metric_id
              from " . IEUM_FITNESS_METRIC_TABLE . "
             where academy_id = '{$academy_id}'
               and metric_key = '" . sql_escape_string($metric_key) . "'
             limit 1
        ", false);
        if ($exists) {
            $metric_key .= '_' . time();
        }
        $new_unit = isset($metrics['_new']['unit']) ? trim((string) $metrics['_new']['unit']) : '';
        $new_type = isset($metrics['_new']['metric_type']) ? trim((string) $metrics['_new']['metric_type']) : 'higher';
        if (!in_array($new_type, array('higher', 'lower'), true)) {
            $new_type = 'higher';
        }
        $new_sort = isset($metrics['_new']['sort_order']) ? (int) $metrics['_new']['sort_order'] : 90;
        $new_sort = max(1, min(999, $new_sort));
        sql_query("
            insert into " . IEUM_FITNESS_METRIC_TABLE . "
                set academy_id = '{$academy_id}',
                    metric_key = '" . sql_escape_string($metric_key) . "',
                    metric_label = '" . sql_escape_string($new_label) . "',
                    unit = '" . sql_escape_string($new_unit) . "',
                    metric_type = '" . sql_escape_string($new_type) . "',
                    is_body = 0,
                    is_score_item = 1,
                    sort_order = '{$new_sort}',
                    is_active = 1,
                    created_at = '" . G5_TIME_YMDHIS . "'
        ");
    }
}

function ieum_fitness_metric_options($academy_id = 0)
{
    return array_merge(
        array(
            'all' => array('label' => '전체 항목', 'unit' => '', 'step' => 1),
            'body' => array('label' => '키/몸무게/BMI', 'unit' => '', 'step' => 1),
        ),
        ieum_fitness_active_items($academy_id)
    );
}

function ieum_fitness_metric_help()
{
    return array(
        'body' => array(
            'title' => '키/몸무게/BMI',
            'summary' => '키와 몸무게를 입력하면 BMI가 자동 계산됩니다. 체력 점수에는 포함하지 않고 성장 참고 정보로만 사용합니다.',
            'method' => '키는 신발을 벗고 cm 단위로, 몸무게는 가벼운 복장 기준 kg 단위로 입력합니다.',
        ),
        'jump_rope' => array(
            'title' => '줄넘기',
            'summary' => '정해진 시간 안에 성공한 횟수를 입력합니다.',
            'method' => '도장 기준 시간(예: 1분)을 정하고 걸리거나 멈춘 횟수는 제외한 성공 횟수만 기록합니다.',
        ),
        'shuttle_run' => array(
            'title' => '왕복오래달리기(셔틀런)',
            'summary' => '15m 또는 20m 왕복오래달리기에서 성공한 횟수를 입력합니다. 높을수록 좋은 기록입니다.',
            'method' => '도장 기준 거리와 음원 기준을 정하고, 신호에 맞춰 성공한 왕복 횟수만 기록합니다.',
        ),
        'push_up' => array(
            'title' => '팔굽혀펴기',
            'summary' => '자세가 유지된 상태에서 성공한 횟수를 입력합니다.',
            'method' => '허리와 무릎 자세가 무너지지 않고 팔꿈치가 충분히 굽혀진 동작만 성공으로 기록합니다.',
        ),
        'sit_up' => array(
            'title' => '윗몸일으키기',
            'summary' => '정해진 시간 안에 정확히 수행한 횟수를 입력합니다.',
            'method' => '도장 기준 시간(예: 1분)을 정하고 상체가 기준선까지 올라온 횟수만 기록합니다.',
        ),
        'long_jump' => array(
            'title' => '제자리멀리뛰기',
            'summary' => '제자리에서 점프한 거리를 cm 단위로 입력합니다.',
            'method' => '출발선을 밟지 않고 양발로 점프한 뒤, 출발선에서 가장 가까운 착지 지점까지의 거리를 기록합니다.',
        ),
        'flexibility' => array(
            'title' => '유연성',
            'summary' => '앉아 앞으로 굽히기 등 도장 기준 측정값을 cm 단위로 입력합니다.',
            'method' => '반동을 주지 않고 천천히 밀어낸 최종 위치를 기록합니다. 마이너스 값도 입력할 수 있습니다.',
        ),
    );
}

function ieum_fitness_body_averages()
{
    return array(
        'male' => array(
            'label' => '남학생',
            'rows' => array(
                'elementary_1' => array('grade' => '초1', 'height' => 122.2, 'weight' => 24.3),
                'elementary_2' => array('grade' => '초2', 'height' => 127.8, 'weight' => 27.5),
                'elementary_3' => array('grade' => '초3', 'height' => 132.8, 'weight' => 31.5),
                'elementary_4' => array('grade' => '초4', 'height' => 138.8, 'weight' => 36.1),
                'elementary_5' => array('grade' => '초5', 'height' => 146.7, 'weight' => 41.3),
                'elementary_6' => array('grade' => '초6', 'height' => 154.4, 'weight' => 45.6),
            ),
        ),
        'female' => array(
            'label' => '여학생',
            'rows' => array(
                'elementary_1' => array('grade' => '초1', 'height' => 121.8, 'weight' => 24.4),
                'elementary_2' => array('grade' => '초2', 'height' => 128.6, 'weight' => 27.2),
                'elementary_3' => array('grade' => '초3', 'height' => 132.2, 'weight' => 32.2),
                'elementary_4' => array('grade' => '초4', 'height' => 136.4, 'weight' => 35.4),
                'elementary_5' => array('grade' => '초5', 'height' => 144.3, 'weight' => 39.9),
                'elementary_6' => array('grade' => '초6', 'height' => 149.7, 'weight' => 43.3),
            ),
        ),
    );
}

function ieum_fitness_default_bands($item)
{
    $target = max(1, (float) $item['target']);
    $type = isset($item['type']) ? $item['type'] : 'higher';
    $bands = array();

    if ($type === 'lower') {
        $worst = isset($item['worst']) ? (float) $item['worst'] : $target * 2;
        $gap = max(1, $worst - $target);
        $bands[] = array(1, null, $target, 80, 100);
        $bands[] = array(2, $target + 0.01, $target + ($gap * 0.25), 60, 79);
        $bands[] = array(3, $target + ($gap * 0.25) + 0.01, $target + ($gap * 0.5), 40, 59);
        $bands[] = array(4, $target + ($gap * 0.5) + 0.01, $target + ($gap * 0.75), 20, 39);
        $bands[] = array(5, $target + ($gap * 0.75) + 0.01, null, 0, 19);
        return $bands;
    }

    $bands[] = array(1, $target * 0.9, null, 80, 100);
    $bands[] = array(2, $target * 0.75, ($target * 0.9) - 0.01, 60, 79);
    $bands[] = array(3, $target * 0.55, ($target * 0.75) - 0.01, 40, 59);
    $bands[] = array(4, $target * 0.35, ($target * 0.55) - 0.01, 20, 39);
    $bands[] = array(5, null, ($target * 0.35) - 0.01, 0, 19);
    return $bands;
}

function ieum_fitness_seed_default_metrics()
{
    $exists = sql_fetch("
        select count(*) as cnt
          from " . IEUM_FITNESS_METRIC_TABLE . "
         where academy_id = 0
    ", false);
    if (!empty($exists['cnt'])) {
        return;
    }

    $order = 10;
    foreach (ieum_fitness_items() as $key => $item) {
        sql_query("
            insert into " . IEUM_FITNESS_METRIC_TABLE . "
                set academy_id = 0,
                    metric_key = '" . sql_escape_string($key) . "',
                    metric_label = '" . sql_escape_string($item['label']) . "',
                    unit = '" . sql_escape_string($item['unit']) . "',
                    metric_type = '" . sql_escape_string(isset($item['type']) ? $item['type'] : 'higher') . "',
                    is_body = 0,
                    is_score_item = 1,
                    sort_order = '{$order}',
                    is_active = 1,
                    created_at = '" . G5_TIME_YMDHIS . "'
        ", false);
        $order += 10;
    }
}

function ieum_fitness_stage_factor($grade_group)
{
    $factors = array(
        '' => 1.0,
        'age_6' => 0.42,
        'age_7' => 0.50,
        'kindergarten' => 0.46,
        'elementary_1' => 0.55,
        'elementary_2' => 0.62,
        'elementary_3' => 0.70,
        'elementary_4' => 0.78,
        'elementary_5' => 0.88,
        'elementary_6' => 0.98,
        'middle_1' => 1.04,
        'middle_2' => 1.10,
        'middle_3' => 1.16,
        'high_1' => 1.18,
        'high_2' => 1.22,
        'high_3' => 1.25,
        'adult' => 1.0,
        'jump_rope' => 1.0,
    );
    return isset($factors[$grade_group]) ? (float) $factors[$grade_group] : 1.0;
}

function ieum_fitness_gender_factor($metric_key, $gender)
{
    if ($gender === 'all') {
        return 1.0;
    }
    if ($metric_key === 'flexibility') {
        return $gender === 'female' ? 1.08 : 0.95;
    }
    if (in_array($metric_key, array('push_up', 'long_jump', 'shuttle_run'), true)) {
        return $gender === 'male' ? 1.06 : 0.92;
    }
    return $gender === 'male' ? 1.03 : 0.97;
}

function ieum_fitness_metric_target_profiles()
{
    return array(
        'age_6' => array('jump_rope' => 45, 'shuttle_run' => 18, 'push_up' => 6, 'sit_up' => 12, 'long_jump' => 95, 'flexibility' => 8),
        'age_7' => array('jump_rope' => 55, 'shuttle_run' => 22, 'push_up' => 8, 'sit_up' => 14, 'long_jump' => 105, 'flexibility' => 9),
        'kindergarten' => array('jump_rope' => 50, 'shuttle_run' => 20, 'push_up' => 7, 'sit_up' => 13, 'long_jump' => 100, 'flexibility' => 8),
        'elementary_1' => array('jump_rope' => 70, 'shuttle_run' => 26, 'push_up' => 9, 'sit_up' => 18, 'long_jump' => 115, 'flexibility' => 10),
        'elementary_2' => array('jump_rope' => 85, 'shuttle_run' => 30, 'push_up' => 11, 'sit_up' => 21, 'long_jump' => 125, 'flexibility' => 11),
        'elementary_3' => array('jump_rope' => 105, 'shuttle_run' => 34, 'push_up' => 14, 'sit_up' => 25, 'long_jump' => 138, 'flexibility' => 12),
        'elementary_4' => array('jump_rope' => 125, 'shuttle_run' => 39, 'push_up' => 17, 'sit_up' => 29, 'long_jump' => 150, 'flexibility' => 13),
        'elementary_5' => array('jump_rope' => 150, 'shuttle_run' => 45, 'push_up' => 21, 'sit_up' => 34, 'long_jump' => 165, 'flexibility' => 14),
        'elementary_6' => array('jump_rope' => 175, 'shuttle_run' => 52, 'push_up' => 25, 'sit_up' => 39, 'long_jump' => 180, 'flexibility' => 15),
        'middle_1' => array('jump_rope' => 190, 'shuttle_run' => 58, 'push_up' => 29, 'sit_up' => 43, 'long_jump' => 195, 'flexibility' => 16),
        'middle_2' => array('jump_rope' => 205, 'shuttle_run' => 64, 'push_up' => 33, 'sit_up' => 47, 'long_jump' => 205, 'flexibility' => 17),
        'middle_3' => array('jump_rope' => 220, 'shuttle_run' => 70, 'push_up' => 37, 'sit_up' => 51, 'long_jump' => 215, 'flexibility' => 18),
        'high_1' => array('jump_rope' => 230, 'shuttle_run' => 74, 'push_up' => 40, 'sit_up' => 54, 'long_jump' => 225, 'flexibility' => 18),
        'high_2' => array('jump_rope' => 240, 'shuttle_run' => 78, 'push_up' => 43, 'sit_up' => 57, 'long_jump' => 232, 'flexibility' => 19),
        'high_3' => array('jump_rope' => 250, 'shuttle_run' => 82, 'push_up' => 46, 'sit_up' => 60, 'long_jump' => 240, 'flexibility' => 19),
        'adult' => array('jump_rope' => 180, 'shuttle_run' => 55, 'push_up' => 28, 'sit_up' => 40, 'long_jump' => 190, 'flexibility' => 14),
        'jump_rope' => array('jump_rope' => 220, 'shuttle_run' => 55, 'push_up' => 24, 'sit_up' => 38, 'long_jump' => 175, 'flexibility' => 15),
        '' => array('jump_rope' => 160, 'shuttle_run' => 50, 'push_up' => 24, 'sit_up' => 36, 'long_jump' => 170, 'flexibility' => 14),
    );
}

function ieum_fitness_metric_target($metric_key, $item, $grade_group, $gender)
{
    $profiles = ieum_fitness_metric_target_profiles();
    $profile = isset($profiles[$grade_group]) ? $profiles[$grade_group] : $profiles[''];
    $target = isset($profile[$metric_key]) ? (float) $profile[$metric_key] : (float) $item['target'];
    $target *= ieum_fitness_gender_factor($metric_key, $gender);
    return $metric_key === 'flexibility' ? max(6, $target) : max(1, $target);
}

function ieum_fitness_default_bands_for_context($metric_key, $item, $grade_group, $gender)
{
    $item['target'] = ieum_fitness_metric_target($metric_key, $item, $grade_group, $gender);
    return ieum_fitness_default_bands($item);
}

function ieum_fitness_bmi_default_ranges($grade_group, $gender)
{
    $age_map = array(
        'age_6' => 6, 'kindergarten' => 6,
        'age_7' => 7, 'elementary_1' => 7,
        'elementary_2' => 8,
        'elementary_3' => 9,
        'elementary_4' => 10,
        'elementary_5' => 11,
        'elementary_6' => 12,
        'middle_1' => 13,
        'middle_2' => 14,
        'middle_3' => 15,
        'high_1' => 16,
        'high_2' => 17,
        'high_3' => 18,
    );
    $age = isset($age_map[$grade_group]) ? $age_map[$grade_group] : 12;
    $gender_shift = $gender === 'female' ? -0.1 : 0.1;
    $normal_min = 13.7 + (($age - 6) * 0.18) + $gender_shift;
    $normal_max = 17.6 + (($age - 6) * 0.45) + ($gender === 'male' ? 0.2 : 0.0);
    $over_max = $normal_max + 1.8;
    $obese_min = $over_max + 0.01;

    return array(
        array(1, round($normal_min, 1), round($normal_max, 1), 80, 100, '정상체중'),
        array(2, null, round($normal_min - 0.01, 2), 60, 79, '저체중'),
        array(3, round($normal_max + 0.01, 2), round($over_max, 1), 60, 79, '과체중'),
        array(4, $obese_min, null, 40, 59, '비만'),
        array(5, null, null, 0, 0, '기준 확인'),
    );
}

function ieum_fitness_bmi_public_label($label)
{
    $map = array(
        '성장 균형' => '정상체중',
        '표준 범위' => '정상체중',
        '마른 편' => '저체중',
        '관리 관찰' => '과체중',
        '상담 권장' => '비만',
    );

    return isset($map[$label]) ? $map[$label] : $label;
}

function ieum_fitness_seed_standard_row($academy_id, $grade_group, $gender, $metric_key, $level_no, $min, $max, $score_min, $score_max, $label, $source)
{
    $grade_sql = sql_escape_string($grade_group);
    $gender_sql = sql_escape_string($gender);
    $metric_sql = sql_escape_string($metric_key);
    $min_sql = $min === null ? 'null' : "'" . round((float) $min, 2) . "'";
    $max_sql = $max === null ? 'null' : "'" . round((float) $max, 2) . "'";
    $label_sql = sql_escape_string($label);
    $source_sql = sql_escape_string($source);

    sql_query("
        insert into " . IEUM_FITNESS_STANDARD_TABLE . "
            set academy_id = '" . (int) $academy_id . "',
                grade_group = '{$grade_sql}',
                gender = '{$gender_sql}',
                metric_key = '{$metric_sql}',
                level_no = '" . (int) $level_no . "',
                min_value = {$min_sql},
                max_value = {$max_sql},
                score_min = '" . (int) $score_min . "',
                score_max = '" . (int) $score_max . "',
                label = '{$label_sql}',
                source = '{$source_sql}',
                created_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                min_value = values(min_value),
                max_value = values(max_value),
                score_min = values(score_min),
                score_max = values(score_max),
                label = values(label),
                source = values(source),
                updated_at = '" . G5_TIME_YMDHIS . "'
    ", false);
}

function ieum_fitness_seed_default_standards()
{
    static $seeded = false;
    if ($seeded) {
        return;
    }
    $seeded = true;

    $items = ieum_fitness_items();
    $grades = ieum_fitness_grade_groups();
    $default_source = '아이이음 2026 연령·성별 체력 기준';
    $bmi_source = '질병관리청 성장도표 흐름 참고';
    $expected_count = count($grades) * 3 * (count($items) + 1) * 5;
    $current = sql_fetch("
        select count(*) as cnt,
               sum(case when source in ('" . sql_escape_string($default_source) . "', '" . sql_escape_string($bmi_source) . "') then 1 else 0 end) as version_cnt
          from " . IEUM_FITNESS_STANDARD_TABLE . "
         where academy_id = 0
    ", false);
    if ($current && (int) $current['cnt'] >= $expected_count && (int) $current['version_cnt'] >= $expected_count) {
        return;
    }

    foreach ($grades as $grade_group) {
        foreach ($items as $metric_key => $item) {
            foreach (array('all', 'male', 'female') as $gender) {
                foreach (ieum_fitness_default_bands_for_context($metric_key, $item, $grade_group, $gender) as $band) {
                    list($level_no, $min, $max, $score_min, $score_max) = $band;
                    ieum_fitness_seed_standard_row(0, $grade_group, $gender, $metric_key, $level_no, $min, $max, $score_min, $score_max, $level_no . '단계', $default_source);
                }
            }
        }
        foreach (array('all', 'male', 'female') as $gender) {
            foreach (ieum_fitness_bmi_default_ranges($grade_group, $gender) as $band) {
                list($level_no, $min, $max, $score_min, $score_max, $label) = $band;
                ieum_fitness_seed_standard_row(0, $grade_group, $gender, 'bmi', $level_no, $min, $max, $score_min, $score_max, $label, $bmi_source);
            }
        }
    }
}

function ieum_fitness_standard_matches($standard, $value)
{
    $value = (float) $value;
    if ($standard['min_value'] !== null && $standard['min_value'] !== '' && $value < (float) $standard['min_value']) {
        return false;
    }
    if ($standard['max_value'] !== null && $standard['max_value'] !== '' && $value > (float) $standard['max_value']) {
        return false;
    }
    return true;
}

function ieum_fitness_standard_rows($academy_id, $grade_group, $gender, $metric_key)
{
    static $standard_cache = array();

    $academy_id = (int) $academy_id;
    $grade_group = sql_escape_string($grade_group);
    $gender = $gender ? sql_escape_string($gender) : 'all';
    $metric_key = sql_escape_string($metric_key);
    $academy_ids = array($academy_id, 0);
    $grade_groups = array($grade_group, '');
    $genders = array($gender, 'all');

    foreach ($academy_ids as $aid) {
        foreach ($grade_groups as $grade) {
            foreach ($genders as $sex) {
                $cache_key = (int) $aid . '|' . $grade . '|' . $sex . '|' . $metric_key;
                if (!array_key_exists($cache_key, $standard_cache)) {
                    $standard_cache[$cache_key] = array();
                    $result = sql_query("
                        select *
                          from " . IEUM_FITNESS_STANDARD_TABLE . "
                         where academy_id = '" . (int) $aid . "'
                           and grade_group = '{$grade}'
                           and gender = '{$sex}'
                           and metric_key = '{$metric_key}'
                      order by level_no asc
                    ", false);
                    while ($row = sql_fetch_array($result)) {
                        if ($metric_key === 'bmi') {
                            $row['label'] = ieum_fitness_bmi_public_label($row['label']);
                        }
                        $standard_cache[$cache_key][] = $row;
                    }
                }
                if ($standard_cache[$cache_key]) {
                    return $standard_cache[$cache_key];
                }
            }
        }
    }

    return array();
}

function ieum_fitness_standard_level($academy_id, $grade_group, $gender, $metric_key, $value)
{
    if ($value === null || $value === '') {
        return array(
            'level_no' => null,
            'label' => '미입력',
            'score_range' => '',
            'score_min' => null,
            'score_max' => null,
            'score_mid' => null,
            'item_score' => null,
            'source' => '',
        );
    }

    foreach (ieum_fitness_standard_rows($academy_id, $grade_group, $gender, $metric_key) as $standard) {
        if (ieum_fitness_standard_matches($standard, $value)) {
            $score_min = (int) $standard['score_min'];
            $score_max = (int) $standard['score_max'];
            $score_mid = ($score_min + $score_max) / 2;
            return array(
                'level_no' => (int) $standard['level_no'],
                'label' => $standard['label'] ?: ((int) $standard['level_no'] . '등급'),
                'score_range' => $score_min . '~' . $score_max,
                'score_min' => $score_min,
                'score_max' => $score_max,
                'score_mid' => round($score_mid, 1),
                'item_score' => round(max(0, min(10, $score_mid / 10)), 1),
                'source' => $standard['source'],
            );
        }
    }

    return array(
        'level_no' => null,
        'label' => '기본환산',
        'score_range' => '',
        'score_min' => null,
        'score_max' => null,
        'score_mid' => null,
        'item_score' => null,
        'source' => '아이이음 기본 환산',
    );
}

function ieum_fitness_age_on_month($birth_date, $month = '')
{
    if (!$birth_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        return null;
    }
    if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    try {
        $birth = new DateTime($birth_date);
        $base = new DateTime($month . '-01');
        $base->modify('last day of this month');
    } catch (Exception $e) {
        return null;
    }

    return (int) $birth->diff($base)->y;
}

function ieum_fitness_context_grade_group($student, $month = '')
{
    $age = ieum_fitness_age_on_month(isset($student['birth_date']) ? $student['birth_date'] : '', $month);
    if ($age === 6) {
        return 'age_6';
    }
    if ($age === 7) {
        return 'age_7';
    }
    return isset($student['grade_group']) ? (string) $student['grade_group'] : '';
}

function ieum_fitness_gender($student)
{
    $gender = isset($student['gender']) ? trim((string) $student['gender']) : 'all';
    return in_array($gender, array('male', 'female', 'all'), true) ? $gender : 'all';
}

function ieum_fitness_standard_context_label($student, $month = '')
{
    $labels = ieum_fitness_grade_labels();
    $genders = ieum_fitness_gender_options();
    $grade = ieum_fitness_context_grade_group($student, $month);
    $gender = ieum_fitness_gender($student);
    $grade_label = isset($labels[$grade]) ? $labels[$grade] : ($grade ?: '전체 기본');
    $gender_label = isset($genders[$gender]) ? $genders[$gender] : '공통';
    return $grade_label . ' · ' . $gender_label . ' 기준';
}

function ieum_fitness_grade_labels()
{
    return array(
        '' => '전체 기본',
        'age_6' => '6세',
        'age_7' => '7세',
        'kindergarten' => '유치부',
        'elementary_1' => '초등/1학년',
        'elementary_2' => '초등/2학년',
        'elementary_3' => '초등/3학년',
        'elementary_4' => '초등/4학년',
        'elementary_5' => '초등/5학년',
        'elementary_6' => '초등/6학년',
        'middle_1' => '중등/1학년',
        'middle_2' => '중등/2학년',
        'middle_3' => '중등/3학년',
        'high_1' => '고등/1학년',
        'high_2' => '고등/2학년',
        'high_3' => '고등/3학년',
        'adult' => '성인부',
        'jump_rope' => '줄넘기부',
    );
}

function ieum_fitness_gender_options()
{
    return array(
        'all' => '공통',
        'male' => '남학생',
        'female' => '여학생',
    );
}

function ieum_fitness_standard_metrics($academy_id = 0)
{
    return array_merge(
        ieum_fitness_metric_rows($academy_id, true),
        array(
            'bmi' => array('label' => 'BMI 성장 균형', 'unit' => '', 'target' => 0, 'type' => 'range', 'step' => 0.1),
        )
    );
}

function ieum_fitness_score_one($key, $value)
{
    $items = ieum_fitness_items();
    if (!isset($items[$key]) || $value === null || $value === '') {
        return 0;
    }

    $value = (float) $value;
    $item = $items[$key];
    if ($item['type'] === 'lower') {
        $target = (float) $item['target'];
        $worst = isset($item['worst']) ? (float) $item['worst'] : $target * 2;
        if ($value <= $target) {
            return 10;
        }
        if ($value >= $worst) {
            return 0;
        }
        return round((($worst - $value) / ($worst - $target)) * 10, 1);
    }

    $target = max(1, (float) $item['target']);
    return round(max(0, min(10, ($value / $target) * 10)), 1);
}

function ieum_fitness_score_metric_value($item, $value)
{
    if ($value === null || $value === '') {
        return 0;
    }

    $value = (float) $value;
    $type = isset($item['type']) ? $item['type'] : 'higher';
    if ($type === 'lower') {
        $target = isset($item['target']) ? max(1, (float) $item['target']) : 10;
        $worst = isset($item['worst']) ? max($target + 1, (float) $item['worst']) : $target * 2;
        if ($value <= $target) {
            return 10;
        }
        if ($value >= $worst) {
            return 0;
        }
        return round((($worst - $value) / ($worst - $target)) * 10, 1);
    }

    $target = isset($item['target']) ? max(1, (float) $item['target']) : 10;
    return round(max(0, min(10, ($value / $target) * 10)), 1);
}

function ieum_fitness_public_level_label($level)
{
    $level_no = is_array($level) && isset($level['level_no']) ? (int) $level['level_no'] : 0;
    if ($level_no <= 0) {
        return '기록 대기';
    }
    if ($level_no === 1) {
        return '우수';
    }
    if ($level_no === 2) {
        return '양호';
    }
    if ($level_no === 3) {
        return '보통';
    }
    if ($level_no === 4) {
        return '관찰';
    }
    return '보강 필요';
}

function ieum_fitness_public_level_note($level)
{
    $level_no = is_array($level) && isset($level['level_no']) ? (int) $level['level_no'] : 0;
    if ($level_no <= 0) {
        return '측정값을 입력하면 자동으로 해석됩니다.';
    }
    if ($level_no === 1) {
        return '동일 기준군에서 상위 수행 구간에 해당합니다.';
    }
    if ($level_no === 2) {
        return '동일 기준군에서 안정적인 수행 구간에 해당합니다.';
    }
    if ($level_no === 3) {
        return '기본 수행은 가능하며 항목별 개선 여지가 있습니다.';
    }
    if ($level_no === 4) {
        return '자세와 반복 수행 안정성을 함께 관찰할 필요가 있습니다.';
    }
    return '기초 수행 능력부터 단계적으로 보강할 필요가 있습니다.';
}

function ieum_fitness_bmi($height_cm, $weight_kg)
{
    $height_cm = (float) $height_cm;
    $weight_kg = (float) $weight_kg;
    if ($height_cm <= 0 || $weight_kg <= 0) {
        return null;
    }

    $height_m = $height_cm / 100;
    return round($weight_kg / ($height_m * $height_m), 1);
}

function ieum_fitness_bmi_result($bmi, $student = array(), $academy_id = 0, $month = '')
{
    if ($bmi === null || $bmi === '') {
        return array('label' => '미입력', 'level' => null, 'source' => '', 'context' => '');
    }
    $bmi = (float) $bmi;
    $grade_group = ieum_fitness_context_grade_group($student, $month);
    $gender = ieum_fitness_gender($student);
    $level = ieum_fitness_standard_level($academy_id, $grade_group, $gender, 'bmi', $bmi);
    if (!empty($level['level_no'])) {
        return array(
            'label' => ieum_fitness_bmi_public_label($level['label']),
            'level' => $level,
            'source' => $level['source'],
            'context' => ieum_fitness_standard_context_label($student, $month),
        );
    }

    if ($bmi < 18.5) {
        $label = '저체중';
    } elseif ($bmi < 23) {
        $label = '정상체중';
    } elseif ($bmi < 25) {
        $label = '과체중';
    } else {
        $label = '비만';
    }
    return array('label' => $label, 'level' => null, 'source' => '성인형 기본 참고', 'context' => '');
}

function ieum_fitness_bmi_label($bmi, $student = array(), $academy_id = 0, $month = '')
{
    $result = ieum_fitness_bmi_result($bmi, $student, $academy_id, $month);
    return $result['label'];
}

function ieum_fitness_bmi_bar($bmi, $student = array(), $academy_id = 0, $month = '')
{
    $grade_group = ieum_fitness_context_grade_group($student, $month);
    $gender = ieum_fitness_gender($student);
    $standards = ieum_fitness_standard_rows($academy_id, $grade_group, $gender, 'bmi');

    $zones = array(
        '저체중' => array('label' => '저체중', 'min' => null, 'max' => null, 'class' => 'under'),
        '정상체중' => array('label' => '정상체중', 'min' => null, 'max' => null, 'class' => 'normal'),
        '과체중' => array('label' => '과체중', 'min' => null, 'max' => null, 'class' => 'over'),
        '비만' => array('label' => '비만', 'min' => null, 'max' => null, 'class' => 'obese'),
    );

    foreach ($standards as $row) {
        $label = ieum_fitness_bmi_public_label($row['label']);
        if (!isset($zones[$label])) {
            continue;
        }
        $zones[$label]['min'] = $row['min_value'] === null || $row['min_value'] === '' ? null : (float) $row['min_value'];
        $zones[$label]['max'] = $row['max_value'] === null || $row['max_value'] === '' ? null : (float) $row['max_value'];
    }

    $normal_min = $zones['정상체중']['min'] !== null ? $zones['정상체중']['min'] : 14.0;
    $normal_max = $zones['정상체중']['max'] !== null ? $zones['정상체중']['max'] : 20.0;
    $over_max = $zones['과체중']['max'] !== null ? $zones['과체중']['max'] : ($normal_max + 2.0);
    $scale_min = max(10, floor($normal_min - 3));
    $scale_max = ceil($over_max + 4);

    $zones['저체중']['min'] = $scale_min;
    if ($zones['저체중']['max'] === null) {
        $zones['저체중']['max'] = max($scale_min, $normal_min - 0.01);
    }
    if ($zones['정상체중']['min'] === null) {
        $zones['정상체중']['min'] = $normal_min;
    }
    if ($zones['정상체중']['max'] === null) {
        $zones['정상체중']['max'] = $normal_max;
    }
    if ($zones['과체중']['min'] === null) {
        $zones['과체중']['min'] = $normal_max + 0.01;
    }
    if ($zones['과체중']['max'] === null) {
        $zones['과체중']['max'] = $over_max;
    }
    if ($zones['비만']['min'] === null) {
        $zones['비만']['min'] = $over_max + 0.01;
    }
    $zones['비만']['max'] = $scale_max;

    $range = max(1, $scale_max - $scale_min);
    $segments = array();
    foreach (array('저체중', '정상체중', '과체중', '비만') as $label) {
        $zone = $zones[$label];
        $start = max($scale_min, min($scale_max, (float) $zone['min']));
        $end = max($scale_min, min($scale_max, (float) $zone['max']));
        $segments[] = array(
            'label' => $zone['label'],
            'class' => $zone['class'],
            'width' => round(max(0.5, (($end - $start) / $range) * 100), 3),
            'min' => $zone['min'],
            'max' => $zone['max'],
        );
    }

    $position = null;
    if ($bmi !== null && $bmi !== '') {
        $position = round(max(0, min(100, (((float) $bmi - $scale_min) / $range) * 100)), 2);
    }

    return array(
        'segments' => $segments,
        'position' => $position,
        'scale_min' => $scale_min,
        'scale_max' => $scale_max,
        'normal_min' => $zones['정상체중']['min'],
        'normal_max' => $zones['정상체중']['max'],
    );
}

function ieum_fitness_calculate($row, $student = array(), $academy_id = 0, $month = '')
{
    $items = $academy_id ? ieum_fitness_active_items($academy_id) : ieum_fitness_items();
    $scores = array();
    $levels = array();
    $sum = 0;
    if ($month === '' && isset($row['report_month'])) {
        $month = $row['report_month'];
    }
    $grade_group = ieum_fitness_context_grade_group($student, $month);
    $gender = ieum_fitness_gender($student);
    foreach ($items as $key => $item) {
        $value = isset($row[$key]) ? $row[$key] : null;
        $level = ieum_fitness_standard_level($academy_id, $grade_group, $gender, $key, $value);
        $score = $level['item_score'] !== null ? (float) $level['item_score'] : ieum_fitness_score_metric_value($item, $value);
        $scores[$key] = $score;
        $levels[$key] = $level;
        $sum += $score;
    }
    $item_count = count($items);
    $normalized_sum = $item_count ? ($sum * (6 / $item_count)) : 0;

    $bmi = ieum_fitness_bmi(isset($row['height_cm']) ? $row['height_cm'] : null, isset($row['weight_kg']) ? $row['weight_kg'] : null);
    $bmi_result = ieum_fitness_bmi_result($bmi, $student, $academy_id, $month);

    return array(
        'base' => 40,
        'item_scores' => $scores,
        'item_levels' => $levels,
        'item_total' => round($normalized_sum, 1),
        'raw_item_total' => round($sum, 1),
        'active_item_count' => $item_count,
        'total' => round(min(100, 40 + $normalized_sum), 1),
        'bmi' => $bmi,
        'bmi_label' => $bmi_result['label'],
        'bmi_level' => $bmi_result['level'],
        'bmi_source' => $bmi_result['source'],
        'standard_context' => ieum_fitness_standard_context_label($student, $month),
    );
}

function ieum_fitness_sql_number_or_null($value, $is_int = false)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'null';
    }
    if ($is_int) {
        return "'" . max(0, (int) preg_replace('/[^0-9]/', '', $value)) . "'";
    }
    $value = preg_replace('/[^0-9\.\-]/', '', $value);
    if ($value === '' || $value === '-' || $value === '.') {
        return 'null';
    }
    return "'" . (float) $value . "'";
}

function ieum_fitness_keep_or_sql($data, $key, $current, $is_int = false)
{
    if (!array_key_exists($key, $data)) {
        return $current === null || $current === '' ? 'null' : "'" . sql_escape_string($current) . "'";
    }

    return ieum_fitness_sql_number_or_null($data[$key], $is_int);
}

function ieum_fitness_metric_values($academy_id, $month, $student_ids = array())
{
    $academy_id = (int) $academy_id;
    $month_sql = sql_escape_string($month);
    $student_filter = '';
    if ($student_ids) {
        $student_filter = " and student_id in (" . implode(',', array_map('intval', $student_ids)) . ") ";
    }
    $result = sql_query("
        select student_id, metric_key, metric_value
          from " . IEUM_FITNESS_VALUE_TABLE . "
         where academy_id = '{$academy_id}'
           and report_month = '{$month_sql}'
           {$student_filter}
    ", false);

    $values = array();
    while ($row = sql_fetch_array($result)) {
        $sid = (int) $row['student_id'];
        if (!isset($values[$sid])) {
            $values[$sid] = array();
        }
        $values[$sid][$row['metric_key']] = $row['metric_value'];
    }
    return $values;
}

function ieum_fitness_merge_metric_values(&$fitness_map, $metric_values, $student_ids = array())
{
    foreach ($student_ids as $sid) {
        $sid = (int) $sid;
        if (!isset($fitness_map[$sid]) || !is_array($fitness_map[$sid])) {
            $fitness_map[$sid] = array();
        }
        if (!empty($metric_values[$sid])) {
            foreach ($metric_values[$sid] as $key => $value) {
                $fitness_map[$sid][$key] = $value;
            }
        }
    }
}

function ieum_fitness_save_metric_values($academy_id, $student_id, $month, $data)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $month_sql = sql_escape_string($month);
    $fixed_keys = array('height_cm', 'weight_kg', 'jump_rope', 'shuttle_run', 'push_up', 'sit_up', 'long_jump', 'flexibility', 'memo');
    $items = ieum_fitness_active_items($academy_id);

    foreach ($items as $key => $item) {
        if (in_array($key, $fixed_keys, true) || !array_key_exists($key, $data)) {
            continue;
        }
        $value_sql = ieum_fitness_sql_number_or_null($data[$key]);
        sql_query("
            insert into " . IEUM_FITNESS_VALUE_TABLE . "
                set academy_id = '{$academy_id}',
                    student_id = '{$student_id}',
                    report_month = '{$month_sql}',
                    metric_key = '" . sql_escape_string($key) . "',
                    metric_value = {$value_sql},
                    created_at = '" . G5_TIME_YMDHIS . "'
            on duplicate key update
                    metric_value = values(metric_value),
                    updated_at = '" . G5_TIME_YMDHIS . "'
        ");
    }
}

function ieum_fitness_save($academy_id, $student_id, $month, $data, $member_id)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $month_sql = sql_escape_string($month);
    $created_by = sql_escape_string($member_id);

    $current = sql_fetch("
        select *
          from " . IEUM_REPORT_FITNESS_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and report_month = '{$month_sql}'
         limit 1
    ", false);
    if (!$current) {
        $current = array();
    }

    $memo = array_key_exists('memo', $data) ? sql_escape_string(trim($data['memo'])) : sql_escape_string(isset($current['memo']) ? $current['memo'] : '');
    $height_cm = ieum_fitness_keep_or_sql($data, 'height_cm', isset($current['height_cm']) ? $current['height_cm'] : null);
    $weight_kg = ieum_fitness_keep_or_sql($data, 'weight_kg', isset($current['weight_kg']) ? $current['weight_kg'] : null);
    $jump_rope = ieum_fitness_keep_or_sql($data, 'jump_rope', isset($current['jump_rope']) ? $current['jump_rope'] : null, true);
    $shuttle_run = ieum_fitness_keep_or_sql($data, 'shuttle_run', isset($current['shuttle_run']) ? $current['shuttle_run'] : null);
    $push_up = ieum_fitness_keep_or_sql($data, 'push_up', isset($current['push_up']) ? $current['push_up'] : null, true);
    $sit_up = ieum_fitness_keep_or_sql($data, 'sit_up', isset($current['sit_up']) ? $current['sit_up'] : null, true);
    $long_jump = ieum_fitness_keep_or_sql($data, 'long_jump', isset($current['long_jump']) ? $current['long_jump'] : null);
    $flexibility = ieum_fitness_keep_or_sql($data, 'flexibility', isset($current['flexibility']) ? $current['flexibility'] : null);

    sql_query("
        insert into " . IEUM_REPORT_FITNESS_TABLE . "
            set academy_id = '{$academy_id}',
                student_id = '{$student_id}',
                report_month = '{$month_sql}',
                height_cm = {$height_cm},
                weight_kg = {$weight_kg},
                jump_rope = {$jump_rope},
                shuttle_run = {$shuttle_run},
                push_up = {$push_up},
                sit_up = {$sit_up},
                long_jump = {$long_jump},
                flexibility = {$flexibility},
                memo = '{$memo}',
                created_by = '{$created_by}',
                created_at = '" . G5_TIME_YMDHIS . "'
        on duplicate key update
                height_cm = values(height_cm),
                weight_kg = values(weight_kg),
                jump_rope = values(jump_rope),
                shuttle_run = values(shuttle_run),
                push_up = values(push_up),
                sit_up = values(sit_up),
                long_jump = values(long_jump),
                flexibility = values(flexibility),
                memo = values(memo),
                updated_at = '" . G5_TIME_YMDHIS . "'
    ");

    ieum_fitness_save_metric_values($academy_id, $student_id, $month, $data);
}
