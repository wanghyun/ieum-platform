<?php
if (!defined('_GNUBOARD_')) {
    exit;
}

require_once IEUM_PATH . '/lib/attendance.php';

function ieum_character_ensure_table()
{
    sql_query("
        create table if not exists " . IEUM_REPORT_CHARACTER_TABLE . " (
            character_id int unsigned not null auto_increment,
            academy_id int unsigned not null,
            student_id int unsigned not null,
            week_start date not null,
            courtesy tinyint unsigned not null default 4,
            focus tinyint unsigned not null default 4,
            confidence tinyint unsigned not null default 4,
            consideration tinyint unsigned not null default 4,
            memo varchar(255) not null default '',
            created_by varchar(50) not null default '',
            created_at datetime not null,
            updated_at datetime null,
            primary key (character_id),
            unique key uq_character_week (academy_id, student_id, week_start),
            key idx_academy_week (academy_id, week_start)
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
