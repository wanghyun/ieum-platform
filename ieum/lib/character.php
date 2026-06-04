<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

require_once IEUM_PATH . '/lib/attendance.php';

function ieum_character_add_column_if_missing($table, $column, $definition)
{
    $table_sql = preg_replace('/[^0-9a-zA-Z_]/', '', $table);
    $column_sql = preg_replace('/[^0-9a-zA-Z_]/', '', $column);
    if ($table_sql === '' || $column_sql === '') {
        return;
    }
    $exists = sql_fetch("
        show columns from {$table_sql} like '{$column_sql}'
    ", false);
    if (!isset($exists['Field'])) {
        sql_query("alter table {$table_sql} add {$column_sql} {$definition}", false);
    }
}

function ieum_character_ensure_table()
{
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
        ) engine=InnoDB default charset=utf8
    ");

    sql_query("alter table " . IEUM_REPORT_CHARACTER_TABLE . " modify courtesy tinyint unsigned not null default 3", false);
    sql_query("alter table " . IEUM_REPORT_CHARACTER_TABLE . " modify focus tinyint unsigned not null default 3", false);
    sql_query("alter table " . IEUM_REPORT_CHARACTER_TABLE . " modify confidence tinyint unsigned not null default 3", false);
    sql_query("alter table " . IEUM_REPORT_CHARACTER_TABLE . " modify consideration tinyint unsigned not null default 3", false);
    sql_query("alter table " . IEUM_REPORT_CHARACTER_TABLE . " add special_score tinyint unsigned not null default 0 after consideration", false);
    sql_query("alter table " . IEUM_REPORT_CHARACTER_TABLE . " add special_reason varchar(80) not null default '' after special_score", false);
    ieum_character_add_column_if_missing(IEUM_STUDENT_TABLE, 'gender', "varchar(10) not null default 'all' after birth_date");
    ieum_character_add_column_if_missing(IEUM_STUDENT_TABLE, 'character_report_enabled', "tinyint(1) not null default 1 after gender");
    ieum_character_add_column_if_missing(IEUM_STUDENT_TABLE, 'fitness_report_enabled', "tinyint(1) not null default 1 after character_report_enabled");

    sql_query("
        create table if not exists " . IEUM_CHARACTER_SPECIAL_TABLE . " (
            special_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            praise_date date not null,
            praise_type varchar(30) not null default 'attitude',
            score tinyint unsigned not null default 1,
            memo varchar(255) not null default '',
            created_by varchar(50) not null default '',
            created_at datetime not null,
            primary key (special_id),
            key idx_special_month (academy_id, praise_date),
            key idx_special_student (academy_id, student_id, praise_date)
        ) engine=InnoDB default charset=utf8
    ");
}

function ieum_character_score_options($selected)
{
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<option value="' . $i . '"' . get_selected((int) $selected, $i) . '>' . $i . '점</option>';
    }
    return $html;
}

function ieum_character_attendance_score($rate)
{
    $rate = (int) $rate;
    if ($rate >= 90) {
        return 20;
    }
    if ($rate >= 80) {
        return 18;
    }
    if ($rate >= 70) {
        return 16;
    }
    if ($rate >= 60) {
        return 14;
    }
    return 12;
}

function ieum_character_special_score_options($selected)
{
    $selected = (int) $selected;
    $options = array(
        0 => '없음',
        1 => '+1 칭찬',
        3 => '+3 특별 성장',
        5 => '+5 이번 달 하이라이트',
    );
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . (int) $value . '"' . get_selected($selected, (int) $value) . '>' . get_text($label) . '</option>';
    }
    return $html;
}

function ieum_character_special_reason_options($selected)
{
    $selected = trim((string) $selected);
    $reasons = array(
        '' => '사유 선택',
        'leadership' => '리더십',
        'kindness' => '배려 실천',
        'challenge' => '도전 성공',
        'promise' => '약속 실천',
        'attitude' => '수업 태도',
        'home_mission' => '가정 미션',
    );
    $html = '';
    foreach ($reasons as $value => $label) {
        $html .= '<option value="' . get_text($value) . '"' . get_selected($selected, $value) . '>' . get_text($label) . '</option>';
    }
    return $html;
}

function ieum_character_special_reason_label($reason)
{
    $reason = trim((string) $reason);
    $labels = array(
        'leadership' => '리더십',
        'kindness' => '배려 실천',
        'challenge' => '도전 성공',
        'promise' => '약속 실천',
        'attitude' => '수업 태도',
        'home_mission' => '가정 미션',
    );

    return isset($labels[$reason]) ? $labels[$reason] : '';
}

function ieum_character_special_add($academy_id, $student_id, $praise_date, $praise_type, $score, $memo, $created_by = '')
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $score = max(1, min(5, (int) $score));
    $praise_date = preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $praise_date) ? $praise_date : G5_TIME_YMD;
    $praise_type = trim((string) $praise_type);
    if (ieum_character_special_reason_label($praise_type) === '') {
        $praise_type = 'attitude';
    }
    if ($academy_id <= 0 || $student_id <= 0) {
        return false;
    }

    sql_query("
        insert into " . IEUM_CHARACTER_SPECIAL_TABLE . "
            set academy_id = '{$academy_id}',
                student_id = '{$student_id}',
                praise_date = '" . sql_escape_string($praise_date) . "',
                praise_type = '" . sql_escape_string($praise_type) . "',
                score = '{$score}',
                memo = '" . sql_escape_string(trim((string) $memo)) . "',
                created_by = '" . sql_escape_string(trim((string) $created_by)) . "',
                created_at = '" . G5_TIME_YMDHIS . "'
    ");
    return true;
}

function ieum_character_special_month_summary($academy_id, $student_id, $month)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student_id;
    $month = preg_match('/^\d{4}\-\d{2}$/', $month) ? $month : date('Y-m', strtotime(G5_TIME_YMD));
    $month_start = $month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $result = sql_query("
        select praise_date, praise_type, score, memo
          from " . IEUM_CHARACTER_SPECIAL_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and praise_date between '{$month_start}' and '{$month_end}'
      order by praise_date asc, special_id asc
    ", false);

    $raw_score = 0;
    $reasons = array();
    $logs = array();
    while ($row = sql_fetch_array($result)) {
        $row_score = max(1, min(5, (int) $row['score']));
        $raw_score += $row_score;
        $label = ieum_character_special_reason_label($row['praise_type']);
        if ($label !== '' && !in_array($label, $reasons, true)) {
            $reasons[] = $label;
        }
        $logs[] = array(
            'date' => $row['praise_date'],
            'type' => $row['praise_type'],
            'label' => $label,
            'score' => $row_score,
            'memo' => $row['memo'],
        );
    }

    return array(
        'score' => min(5, $raw_score),
        'raw_score' => $raw_score,
        'count' => count($logs),
        'reasons' => $reasons,
        'logs' => $logs,
    );
}

function ieum_character_component_score($average)
{
    if ($average <= 0) {
        return 0;
    }

    return (int) round(($average / 5) * 20);
}

function ieum_character_month_score($academy_id, $student, $month)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $student['student_id'];
    $month = preg_match('/^\d{4}\-\d{2}$/', $month) ? $month : date('Y-m', strtotime(G5_TIME_YMD));
    $month_start = $month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $today = G5_TIME_YMD;
    if ($month === date('Y-m', strtotime($today)) && $today < $month_end) {
        $attendance_end = $today;
    } else {
        $attendance_end = $month_end;
    }

    $admission_date = isset($student['admission_date']) ? trim($student['admission_date']) : '';
    $base_date = $month_start;
    $is_first_month = false;
    if ($admission_date !== '' && $admission_date !== '0000-00-00' && strtotime($admission_date) !== false) {
        if ($admission_date > $month_start && $admission_date <= $month_end) {
            $base_date = $admission_date;
            $is_first_month = true;
        } elseif (substr($admission_date, 0, 7) === $month) {
            $is_first_month = true;
        } elseif ($admission_date > $month_end) {
            return array(
                'is_first_month' => false,
                'is_before_admission' => true,
                'base_date' => $admission_date,
                'evaluated_weeks' => 0,
                'components' => array(),
                'attendance' => array('rate' => 0, 'score' => 0, 'attended_days' => 0, 'scheduled_days' => 0),
                'special' => array('score' => 0, 'reasons' => array(), 'label' => ''),
                'base_total_score' => 0,
                'total_score' => 0,
                'message' => '입관 전 월입니다.',
            );
        }
    }

    $week_rows = sql_query("
        select week_start, courtesy, focus, confidence, consideration
          from " . IEUM_REPORT_CHARACTER_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and week_start between '{$month_start}' and '{$month_end}'
      order by week_start asc
    ", false);

    $sums = array('courtesy' => 0, 'focus' => 0, 'confidence' => 0, 'consideration' => 0);
    $counts = array('courtesy' => 0, 'focus' => 0, 'confidence' => 0, 'consideration' => 0);
    $evaluated_weeks = array();
    $special_score_sum = 0;
    $special_reasons = array();
    while ($row = sql_fetch_array($week_rows)) {
        $week_start = $row['week_start'];
        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
        if ($week_end < $base_date) {
            continue;
        }
        $evaluated_weeks[$week_start] = true;
        foreach ($sums as $key => $value) {
            $score = max(1, min(5, (int) $row[$key]));
            $sums[$key] += $score;
            $counts[$key]++;
        }
    }
    $special_log = ieum_character_special_month_summary($academy_id, $student_id, $month);
    if (!empty($special_log['raw_score'])) {
        $special_score_sum += (int) $special_log['raw_score'];
        foreach ($special_log['reasons'] as $reason_label) {
            if ($reason_label !== '' && !in_array($reason_label, $special_reasons, true)) {
                $special_reasons[] = $reason_label;
            }
        }
    }

    $labels = array(
        'courtesy' => '예절',
        'focus' => '집중력',
        'confidence' => '자신감',
        'consideration' => '배려심',
    );
    $components = array();
    $total = 0;
    foreach ($sums as $key => $sum) {
        $average = $counts[$key] > 0 ? round($sum / $counts[$key], 2) : 0;
        $score = ieum_character_component_score($average);
        $components[$key] = array(
            'label' => $labels[$key],
            'average' => $average,
            'score' => $score,
            'weeks' => $counts[$key],
        );
        $total += $score;
    }

    $attendance_days = isset($student['attendance_days']) ? $student['attendance_days'] : 'mon,tue,wed,thu,fri';
    $scheduled_dates = ieum_attendance_scheduled_dates($attendance_days, $base_date, $attendance_end, $academy_id);
    $scheduled_days = count($scheduled_dates);
    $attended_days = 0;
    if ($scheduled_days > 0) {
        $attendance_rows = sql_query("
            select distinct attendance_date
              from " . IEUM_ATTENDANCE_TABLE . "
             where academy_id = '{$academy_id}'
               and student_id = '{$student_id}'
               and attendance_date between '{$base_date}' and '{$attendance_end}'
        ", false);
        while ($attendance = sql_fetch_array($attendance_rows)) {
            if (isset($scheduled_dates[$attendance['attendance_date']])) {
                $attended_days++;
            }
        }
    }
    $rate = $scheduled_days > 0 ? (int) round(($attended_days / $scheduled_days) * 100) : 0;
    $attendance_score = ieum_character_attendance_score($rate);
    $total += $attendance_score;
    $base_total = $total;
    $special_bonus = min(5, max(0, (int) $special_score_sum));
    $total += $special_bonus;

    return array(
        'is_first_month' => $is_first_month,
        'is_before_admission' => false,
        'base_date' => $base_date,
        'evaluated_weeks' => count($evaluated_weeks),
        'components' => $components,
        'attendance' => array(
            'label' => '성실',
            'rate' => $rate,
            'score' => $attendance_score,
            'attended_days' => $attended_days,
            'scheduled_days' => $scheduled_days,
        ),
        'special' => array(
            'score' => $special_bonus,
            'raw_score' => max(0, (int) $special_score_sum),
            'count' => isset($special_log['count']) ? (int) $special_log['count'] : 0,
            'logs' => isset($special_log['logs']) ? $special_log['logs'] : array(),
            'reasons' => $special_reasons,
            'label' => $special_bonus > 0 ? '지도진 특별 칭찬' : '',
        ),
        'base_total_score' => min(100, max(0, $base_total)),
        'total_score' => min(100, max(0, $total)),
        'message' => $is_first_month ? '입관 첫 달은 입관 후 기록만 반영하는 적응 기간 리포트입니다.' : '입력된 주차와 정상 수업일 기준으로 자동 계산했습니다.',
    );
}

function ieum_character_level_label($score)
{
    $score = (int) $score;
    if ($score >= 18) {
        return '매우 좋음';
    }
    if ($score >= 15) {
        return '안정적';
    }
    if ($score >= 12) {
        return '성장 중';
    }
    return '관찰 중';
}

function ieum_character_total_stage($total_score)
{
    $total_score = (int) $total_score;
    if ($total_score >= 90) {
        return '아주 안정적';
    }
    if ($total_score >= 80) {
        return '안정 성장';
    }
    if ($total_score >= 70) {
        return '성장 중';
    }
    return '적응/관찰 중';
}

function ieum_character_component_values($score)
{
    $items = array();
    foreach ($score['components'] as $key => $component) {
        $items[$key] = array(
            'label' => $component['label'],
            'score' => (int) $component['score'],
            'level' => ieum_character_level_label((int) $component['score']),
        );
    }
    $items['attendance'] = array(
        'label' => '성실',
        'score' => (int) $score['attendance']['score'],
        'level' => ieum_character_level_label((int) $score['attendance']['score']),
    );

    return $items;
}

function ieum_character_parent_comment($student_name, $score)
{
    $items = ieum_character_component_values($score);
    $best = null;
    $watch = null;
    foreach ($items as $item) {
        if ($best === null || $item['score'] > $best['score']) {
            $best = $item;
        }
        if ($watch === null || $item['score'] < $watch['score']) {
            $watch = $item;
        }
    }

    $stage = ieum_character_total_stage($score['total_score']);
    $prefix = !empty($score['is_first_month'])
        ? '이번 달은 입관 후 적응 기간으로, 입관일 이후의 수업 참여와 인성 기록만 반영했습니다. '
        : '';

    $comment = $prefix . '이번 달 ' . $student_name . ' 학생의 인성 성장 흐름은 "' . $stage . '" 단계로 보입니다. ';
    if ($best) {
        $comment .= '특히 ' . $best['label'] . ' 영역에서 좋은 모습을 보여주었습니다. ';
    }
    if ($watch && $best && $watch['label'] !== $best['label']) {
        $comment .= $watch['label'] . ' 영역은 다음 달에도 수업 안에서 자연스럽게 살펴보겠습니다. ';
    }
    $comment .= '앞으로도 작은 성장을 꾸준히 확인하며 긍정적인 수련 습관을 만들어가겠습니다.';

    return $comment;
}
