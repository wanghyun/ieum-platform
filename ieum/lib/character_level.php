<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

require_once IEUM_PATH . '/lib/character.php';
require_once IEUM_PATH . '/lib/character_mission.php';

function ieum_character_level_ensure_table()
{
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
        ) engine=InnoDB default charset=utf8
    ", false);
}

function ieum_character_level_steps()
{
    static $steps = null;
    if ($steps !== null) {
        return $steps;
    }

    $tiers = array(
        array('key' => 'bronze', 'label' => '브론즈', 'color' => '#a66a35'),
        array('key' => 'silver', 'label' => '실버', 'color' => '#8b98a8'),
        array('key' => 'gold', 'label' => '골드', 'color' => '#d99b22'),
        array('key' => 'platinum', 'label' => '플래티넘', 'color' => '#2f9eaa'),
        array('key' => 'diamond', 'label' => '다이아', 'color' => '#4361ee'),
        array('key' => 'master', 'label' => '마스터', 'color' => '#2c2a25'),
    );

    $steps = array();
    $threshold = 0;
    foreach ($tiers as $tier) {
        for ($rank = 1; $rank <= 3; $rank++) {
            $steps[] = array(
                'key' => $tier['key'] . '_' . $rank,
                'tier_key' => $tier['key'],
                'tier_label' => $tier['label'],
                'rank' => $rank,
                'label' => $tier['label'] . ' ' . $rank . '단계',
                'threshold' => $threshold,
                'color' => $tier['color'],
            );
            $threshold += 120;
        }
    }

    return $steps;
}

function ieum_character_level_from_points($points)
{
    $points = max(0, (int) $points);
    $steps = ieum_character_level_steps();
    $current = $steps[0];
    $next = null;

    foreach ($steps as $index => $step) {
        if ($points >= (int) $step['threshold']) {
            $current = $step;
            $next = isset($steps[$index + 1]) ? $steps[$index + 1] : null;
        }
    }

    $current_threshold = (int) $current['threshold'];
    $next_threshold = $next ? (int) $next['threshold'] : $current_threshold;
    $span = $next ? max(1, $next_threshold - $current_threshold) : 1;
    $points_in_level = max(0, $points - $current_threshold);
    $progress_rate = $next ? min(100, (int) round(($points_in_level / $span) * 100)) : 100;

    return array(
        'current' => $current,
        'next' => $next,
        'points' => $points,
        'points_in_level' => $points_in_level,
        'points_to_next' => $next ? max(0, $next_threshold - $points) : 0,
        'progress_rate' => $progress_rate,
        'next_label' => $next ? $next['label'] : '최고 단계',
    );
}

function ieum_character_level_months($student, $month)
{
    $month = preg_match('/^\d{4}\-\d{2}$/', $month) ? $month : date('Y-m', strtotime(G5_TIME_YMD));
    $start_month = substr($month, 0, 7);
    $admission_date = isset($student['admission_date']) ? trim($student['admission_date']) : '';
    if ($admission_date !== '' && $admission_date !== '0000-00-00' && strtotime($admission_date) !== false) {
        $start_month = substr($admission_date, 0, 7);
    } else {
        $first = sql_fetch("
            select min(week_start) as first_week
              from " . IEUM_REPORT_CHARACTER_TABLE . "
             where academy_id = '" . (int) $student['academy_id'] . "'
               and student_id = '" . (int) $student['student_id'] . "'
        ", false);
        if ($first && !empty($first['first_week'])) {
            $start_month = substr($first['first_week'], 0, 7);
        }
    }

    $months = array();
    $cursor = strtotime($start_month . '-01');
    $end = strtotime($month . '-01');
    while ($cursor !== false && $cursor <= $end) {
        $months[] = date('Y-m', $cursor);
        $cursor = strtotime('+1 month', $cursor);
    }

    return $months;
}

function ieum_character_level_summary($academy_id, $student, $month)
{
    ieum_character_ensure_table();
    ieum_character_mission_ensure_tables();

    $academy_id = (int) $academy_id;
    $student['academy_id'] = $academy_id;
    $months = ieum_character_level_months($student, $month);
    $history = array();
    $cumulative = 0;

    foreach ($months as $target_month) {
        $score = ieum_character_month_score($academy_id, $student, $target_month);
        if (!empty($score['is_before_admission'])) {
            continue;
        }

        $mission = ieum_character_mission_report($academy_id, (int) $student['student_id'], $target_month);
        $monthly_score = isset($score['total_score']) ? (int) $score['total_score'] : 0;
        $mission_bonus = $mission ? (int) $mission['bonus_score'] : 0;
        $growth_points = max(0, $monthly_score + $mission_bonus);
        $cumulative += $growth_points;
        $level = ieum_character_level_from_points($cumulative);

        $history[] = array(
            'month' => $target_month,
            'monthly_score' => $monthly_score,
            'mission_bonus' => $mission_bonus,
            'growth_points' => $growth_points,
            'cumulative_points' => $cumulative,
            'level' => $level,
        );
    }

    $current = $history ? $history[count($history) - 1] : array(
        'month' => $month,
        'monthly_score' => 0,
        'mission_bonus' => 0,
        'growth_points' => 0,
        'cumulative_points' => 0,
        'level' => ieum_character_level_from_points(0),
    );

    return array(
        'current' => $current,
        'history' => $history,
        'months_count' => count($history),
    );
}

function ieum_character_level_sync_snapshot($academy_id, $student, $month)
{
    ieum_character_level_ensure_table();
    $summary = ieum_character_level_summary($academy_id, $student, $month);
    $current = $summary['current'];
    $level = $current['level'];
    $now = G5_TIME_YMDHIS;

    sql_query("
        insert into " . IEUM_CHARACTER_LEVEL_TABLE . "
            set academy_id = '" . (int) $academy_id . "',
                student_id = '" . (int) $student['student_id'] . "',
                snapshot_month = '" . sql_escape_string($month) . "',
                monthly_score = '" . (int) $current['monthly_score'] . "',
                mission_bonus = '" . (int) $current['mission_bonus'] . "',
                growth_points = '" . (int) $current['growth_points'] . "',
                cumulative_points = '" . (int) $current['cumulative_points'] . "',
                level_key = '" . sql_escape_string($level['current']['key']) . "',
                level_label = '" . sql_escape_string($level['current']['label']) . "',
                next_level_label = '" . sql_escape_string($level['next_label']) . "',
                points_to_next = '" . (int) $level['points_to_next'] . "',
                updated_at = '{$now}'
        on duplicate key update
                monthly_score = values(monthly_score),
                mission_bonus = values(mission_bonus),
                growth_points = values(growth_points),
                cumulative_points = values(cumulative_points),
                level_key = values(level_key),
                level_label = values(level_label),
                next_level_label = values(next_level_label),
                points_to_next = values(points_to_next),
                updated_at = values(updated_at)
    ", false);

    return $summary;
}

function ieum_character_level_message($student_name, $level)
{
    $name = trim((string) $student_name);
    if ($name === '') {
        $name = '우리 아이';
    }
    if ((int) $level['points_to_next'] <= 0) {
        return $name . '의 인성이 최고 단계까지 자랐습니다. 꾸준한 실천이 멋진 기록이 되었습니다.';
    }

    return $name . '의 인성이 자라고 있습니다. 다음 레벨까지 ' . number_format((int) $level['points_to_next']) . '점 남았어요.';
}
