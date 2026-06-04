<?php
$sub_menu = '950183';
require_once './_common.php';
require_once IEUM_PATH . '/lib/character.php';
require_once IEUM_PATH . '/lib/character_mission.php';
require_once IEUM_PATH . '/lib/tuition.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/fitness.php';

$g5['title'] = '아이이음 월말 루틴';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];

ieum_character_ensure_table();
ieum_character_mission_ensure_tables();
ieum_fitness_ensure_table();

function ieum_monthly_close_valid_month($month)
{
    return preg_match('/^\d{4}\-\d{2}$/', $month) ? $month : date('Y-m');
}

function ieum_monthly_close_month_label($month)
{
    $time = strtotime($month . '-01');
    return $time ? date('Y년 n월', $time) : $month;
}

function ieum_monthly_close_grade_label($value)
{
    $labels = array(
        '' => '미지정',
        'kindergarten' => '유치부',
        'elementary_1' => '초등 1학년',
        'elementary_2' => '초등 2학년',
        'elementary_3' => '초등 3학년',
        'elementary_4' => '초등 4학년',
        'elementary_5' => '초등 5학년',
        'elementary_6' => '초등 6학년',
        'middle_1' => '중등 1학년',
        'middle_2' => '중등 2학년',
        'middle_3' => '중등 3학년',
        'high_1' => '고등 1학년',
        'high_2' => '고등 2학년',
        'high_3' => '고등 3학년',
        'adult' => '성인부',
        'jump_rope' => '줄넘기',
    );

    return isset($labels[$value]) ? $labels[$value] : ($value ?: '미지정');
}

function ieum_monthly_close_expected_weeks($month)
{
    $month_start = $month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $end = $month_end;
    if ($month === date('Y-m') && G5_TIME_YMD < $month_end) {
        $end = G5_TIME_YMD;
    }

    $count = 0;
    for ($time = strtotime($month_start); $time <= strtotime($end); $time = strtotime('+1 day', $time)) {
        if ((int) date('N', $time) === 1) {
            $count++;
        }
    }

    return max(1, $count);
}

function ieum_monthly_close_primary_phone_count($academy_id, $student_id)
{
    $row = sql_fetch("
        select count(*) as cnt
          from " . IEUM_STUDENT_GUARDIAN_TABLE . "
         where academy_id = '" . (int) $academy_id . "'
           and student_id = '" . (int) $student_id . "'
           and is_active = 1
           and is_primary = 1
           and guardian_phone <> ''
    ", false);

    return isset($row['cnt']) ? (int) $row['cnt'] : 0;
}

function ieum_monthly_close_percent($value, $total)
{
    $total = (int) $total;
    if ($total <= 0) {
        return 0;
    }

    return (int) round(((int) $value / $total) * 100);
}

$month = isset($_GET['month']) ? ieum_monthly_close_valid_month(trim($_GET['month'])) : date('Y-m');
$issue = isset($_GET['issue']) ? preg_replace('/[^a-z_]/', '', trim($_GET['issue'])) : 'all';
if (!in_array($issue, array('all', 'critical', 'character', 'fitness', 'phone', 'mission', 'tuition', 'absent'), true)) {
    $issue = 'all';
}
$month_sql = sql_escape_string($month);
$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$prev_month = date('Y-m', strtotime($month_start . ' -1 month'));
$next_month = date('Y-m', strtotime($month_start . ' +1 month'));
$expected_weeks = ieum_monthly_close_expected_weeks($month);
$billing_month = $month;

ieum_tuition_ensure_month($academy_id, $billing_month);
$tuition_settings = ieum_tuition_get_settings($academy_id);
$overdue_days = max(1, min(30, (int) (isset($tuition_settings['overdue_after_days']) ? $tuition_settings['overdue_after_days'] : 5)));

$students = array();
$student_query = sql_query("
    select s.student_id, s.student_code, s.student_name, s.student_phone, s.birth_date,
           s.admission_date, s.attendance_days, s.program_code, s.grade_group, s.school_name,
           coalesce(s.fitness_report_enabled, 1) as fitness_report_enabled,
           c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
while ($row = sql_fetch_array($student_query)) {
    $students[] = $row;
}

$total_students = count($students);
$student_ids_for_fitness = array();
foreach ($students as $student_for_fitness) {
    $student_ids_for_fitness[] = (int) $student_for_fitness['student_id'];
}
$fitness_items = ieum_fitness_active_items($academy_id);
$fitness_map = array();
if ($student_ids_for_fitness) {
    $student_ids_sql_for_fitness = implode(',', array_map('intval', $student_ids_for_fitness));
    $fitness_result = sql_query("
        select *
          from " . IEUM_REPORT_FITNESS_TABLE . "
         where academy_id = '{$academy_id}'
           and report_month = '" . sql_escape_string($month) . "'
           and student_id in ({$student_ids_sql_for_fitness})
    ", false);
    while ($fitness_row = sql_fetch_array($fitness_result)) {
        $fitness_map[(int) $fitness_row['student_id']] = $fitness_row;
    }
    ieum_fitness_merge_metric_values($fitness_map, ieum_fitness_metric_values($academy_id, $month, $student_ids_for_fitness), $student_ids_for_fitness);
}
$summary = array(
    'report_ready' => 0,
    'report_send_ready' => 0,
    'fitness_enabled' => 0,
    'fitness_completed' => 0,
    'fitness_missing' => 0,
    'fitness_send_ready' => 0,
    'critical' => 0,
    'no_phone' => 0,
    'character_missing' => 0,
    'mission_done' => 0,
    'mission_wait' => 0,
    'tuition_unpaid' => 0,
    'tuition_overdue' => 0,
    'long_absent' => 0,
);
$class_summary = array();
$blockers = array();
$issue_counts = array(
    'all' => 0,
    'critical' => 0,
    'character' => 0,
    'fitness' => 0,
    'phone' => 0,
    'mission' => 0,
    'tuition' => 0,
    'absent' => 0,
);
$today = G5_TIME_YMD;
$long_absent_start = date('Y-m-d', strtotime($today . ' -14 days'));

foreach ($students as $student) {
    $student_id = (int) $student['student_id'];
    $class_label = trim((string) $student['class_name']) !== ''
        ? trim($student['class_name'] . ' ' . $student['start_time'])
        : '부 미지정';
    if (!isset($class_summary[$class_label])) {
        $class_summary[$class_label] = array(
            'total' => 0,
            'ready' => 0,
            'character_missing' => 0,
            'fitness_completed' => 0,
            'fitness_missing' => 0,
            'no_phone' => 0,
            'mission_done' => 0,
        );
    }
    $class_summary[$class_label]['total']++;

    $score = ieum_character_month_score($academy_id, $student, $month);
    $evaluated_weeks = isset($score['evaluated_weeks']) ? (int) $score['evaluated_weeks'] : 0;
    $character_missing = $evaluated_weeks < $expected_weeks;
    $phone_count = ieum_monthly_close_primary_phone_count($academy_id, $student_id);
    $fitness_enabled = !isset($student['fitness_report_enabled']) || (int) $student['fitness_report_enabled'] === 1;
    $fitness_completed = false;
    if ($fitness_enabled) {
        $fitness_row = isset($fitness_map[$student_id]) ? $fitness_map[$student_id] : array();
        foreach (array_keys($fitness_items) as $fitness_key) {
            if (isset($fitness_row[$fitness_key]) && $fitness_row[$fitness_key] !== null && $fitness_row[$fitness_key] !== '') {
                $fitness_completed = true;
                break;
            }
        }
    }
    $mission = ieum_character_mission_report($academy_id, $student_id, $month);
    $mission_done = $mission && !empty($mission['is_participated']);

    $payment = sql_fetch("
        select payment_id, due_date, amount_due, amount_paid, status
          from " . IEUM_TUITION_PAYMENT_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and billing_month = '{$month_sql}'
         limit 1
    ", false);
    $balance = isset($payment['amount_due']) ? max(0, (int) $payment['amount_due'] - (int) $payment['amount_paid']) : 0;
    $tuition_unpaid = isset($payment['status']) && $payment['status'] !== 'paid' && $balance > 0;
    $tuition_overdue = $tuition_unpaid
        && !empty($payment['due_date'])
        && $payment['due_date'] !== '0000-00-00'
        && (int) floor((strtotime($today) - strtotime($payment['due_date'])) / 86400) > $overdue_days;

    $attendance_row = sql_fetch("
        select count(*) as cnt
          from " . IEUM_ATTENDANCE_TABLE . "
         where academy_id = '{$academy_id}'
           and student_id = '{$student_id}'
           and attendance_date between '{$long_absent_start}' and '{$today}'
    ", false);
    $long_absent = isset($attendance_row['cnt']) && (int) $attendance_row['cnt'] <= 0;

    if ($character_missing) {
        $summary['character_missing']++;
        $class_summary[$class_label]['character_missing']++;
    }
    if ($fitness_enabled) {
        $summary['fitness_enabled']++;
        if ($fitness_completed) {
            $summary['fitness_completed']++;
            $class_summary[$class_label]['fitness_completed']++;
            if ($phone_count > 0) {
                $summary['fitness_send_ready']++;
            }
        } else {
            $summary['fitness_missing']++;
            $class_summary[$class_label]['fitness_missing']++;
        }
    }
    if ($phone_count > 0) {
        $summary['report_ready']++;
    } else {
        $summary['no_phone']++;
        $class_summary[$class_label]['no_phone']++;
    }
    if ($mission_done) {
        $summary['mission_done']++;
        $class_summary[$class_label]['mission_done']++;
    } else {
        $summary['mission_wait']++;
    }
    if ($tuition_unpaid) {
        $summary['tuition_unpaid']++;
    }
    if ($tuition_overdue) {
        $summary['tuition_overdue']++;
    }
    if ($long_absent) {
        $summary['long_absent']++;
    }

    $reasons = array();
    $flags = array();
    $priority = 100;
    if ($character_missing) {
        $reasons[] = '인성 입력 ' . $evaluated_weeks . '/' . $expected_weeks . '주';
        $flags['character'] = true;
        $priority = min($priority, 10);
    }
    if ($fitness_enabled && !$fitness_completed) {
        $reasons[] = '체력 측정 미완료';
        $flags['fitness'] = true;
        $priority = min($priority, 30);
    }
    if ($phone_count <= 0) {
        $reasons[] = '대표 보호자 연락처 없음';
        $flags['phone'] = true;
        $priority = min($priority, 5);
    }
    if (!$mission_done) {
        $reasons[] = '아이잘해 미참여';
        $flags['mission'] = true;
        $priority = min($priority, 60);
    }
    if ($tuition_overdue) {
        $reasons[] = '수련비 미납 ' . number_format($balance) . '원';
        $flags['tuition'] = true;
        $priority = min($priority, 20);
    } elseif ($tuition_unpaid) {
        $reasons[] = '수련비 미결제';
        $flags['tuition'] = true;
        $priority = min($priority, 40);
    }
    if ($long_absent) {
        $reasons[] = '14일 이상 출석 없음';
        $flags['absent'] = true;
        $priority = min($priority, 15);
    }

    $report_ready = $phone_count > 0 && !$character_missing;
    if ($report_ready) {
        $summary['report_send_ready']++;
        $class_summary[$class_label]['ready']++;
    }

    if ($reasons) {
        $is_critical = isset($flags['phone']) || isset($flags['character']) || ($tuition_overdue ? true : false) || isset($flags['absent']);
        $flags['all'] = true;
        if ($is_critical) {
            $flags['critical'] = true;
            $summary['critical']++;
        }
        foreach ($flags as $flag => $active) {
            if ($active && isset($issue_counts[$flag])) {
                $issue_counts[$flag]++;
            }
        }
        $blockers[] = array(
            'student' => $student,
            'class_label' => $class_label,
            'reasons' => $reasons,
            'flags' => $flags,
            'priority' => $priority,
        );
    }
}

usort($blockers, function ($a, $b) {
    if ($a['priority'] === $b['priority']) {
        return strcmp($a['student']['student_name'], $b['student']['student_name']);
    }
    return $a['priority'] - $b['priority'];
});

$filtered_blockers = array();
foreach ($blockers as $blocker) {
    if ($issue === 'all' || !empty($blocker['flags'][$issue])) {
        $filtered_blockers[] = $blocker;
    }
}

$ready_for_send = (int) $summary['report_send_ready'];
$report_send_ready_total = (int) $summary['report_send_ready'] + (int) $summary['fitness_send_ready'];
$close_score = 0;
if ($total_students > 0) {
    $report_target_total = max(1, $total_students + (int) $summary['fitness_enabled']);
    $report_percent = ieum_monthly_close_percent($report_send_ready_total, $report_target_total);
    $tuition_percent = ieum_monthly_close_percent($total_students - $summary['tuition_overdue'], $total_students);
    $mission_percent = ieum_monthly_close_percent($summary['mission_done'], $total_students);
    $close_score = (int) round(($report_percent * 0.5) + ($tuition_percent * 0.3) + ($mission_percent * 0.2));
}

$status_text = '점검 필요';
if ($close_score >= 90) {
    $status_text = '발송 준비';
} elseif ($close_score >= 70) {
    $status_text = '마감 진행 중';
}

$character_input_url = IEUM_URL . '/admin/character.php?' . http_build_query(array(
    'week_start' => $month . '-01',
));
$character_report_url = IEUM_URL . '/admin/character_report.php?' . http_build_query(array(
    'month' => $month,
));
$character_send_url = IEUM_URL . '/admin/character_report.php?' . http_build_query(array(
    'month' => $month,
    'report_status' => 'send_ready',
));
$character_print_url = IEUM_URL . '/admin/character_reports_print.php?' . http_build_query(array(
    'month' => $month,
));
$fitness_input_url = IEUM_URL . '/admin/fitness.php?' . http_build_query(array(
    'month' => $month,
));
$fitness_report_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query(array(
    'month' => $month,
));
$fitness_send_url = IEUM_URL . '/admin/fitness_reports.php?' . http_build_query(array(
    'month' => $month,
    'report_status' => 'send_ready',
));
$fitness_print_url = IEUM_URL . '/admin/fitness_reports_print.php?' . http_build_query(array(
    'month' => $month,
    'scope' => 'ready',
));
$student_phone_url = IEUM_URL . '/admin/students.php?' . http_build_query(array(
    'auto_check' => 'missing_phone',
));
$sms_queue_url = IEUM_URL . '/admin/sms_queue.php';

$blocker_preview = array_slice($filtered_blockers, 0, 40);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1900px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap}h1{margin:0;font-size:32px}.meta{color:#667085;margin-top:6px;line-height:1.45}.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:9px 13px;font-weight:900;cursor:pointer}.primary{background:#1947ba;border-color:#1947ba;color:#fff}.dark{background:#111827;border-color:#111827;color:#fff}input,select{border:1px solid #cfd6df;border-radius:8px;padding:9px;font-size:14px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.close-hero{display:grid;grid-template-columns:1.15fr .85fr;gap:16px;align-items:start}.score-card{background:linear-gradient(135deg,#1947ba,#2c2a25);color:#fff;border:0}.score-big{font-size:58px;font-weight:1000;line-height:1;margin-top:12px}.score-card p{color:#dbe6ff;line-height:1.6}.routine{display:grid;gap:10px}.routine-item{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fbfcff}.routine-no{width:38px;height:38px;border-radius:14px;background:#eef5ff;color:#1947ba;display:grid;place-items:center;font-weight:1000;font-size:22px}.routine-item strong{display:block}.routine-item span{display:block;color:#667085;font-size:13px;margin-top:2px}.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}.kpi{background:#fff;border:1px solid #d9dee7;border-radius:12px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.05)}.kpi span{display:block;color:#667085;font-size:13px;font-weight:900}.kpi strong{display:block;margin-top:7px;font-size:30px}.kpi small{display:block;margin-top:4px;color:#667085;font-size:12px;font-weight:800}.good{color:#087f5b}.warn{color:#9a5b00}.danger{color:#c92a2a}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.chips{display:flex;gap:6px;flex-wrap:wrap}.chip{display:inline-flex;border-radius:999px;background:#eef2f7;color:#344054;padding:5px 8px;font-size:12px;font-weight:900}.chip.warn{background:#fff4e6;color:#9a5b00}.chip.danger{background:#fdecec;color:#c92a2a}.chip.good{background:#e8f7ee;color:#087f5b}.empty{padding:28px;text-align:center;color:#667085}.progress{height:9px;border-radius:999px;background:#edf2f7;overflow:hidden;margin-top:10px}.progress span{display:block;height:100%;background:#1947ba;border-radius:999px}.note{background:#eef6ff;border:1px solid #cfe0ff;border-radius:12px;padding:14px;color:#173b7a;line-height:1.6;margin-top:18px}.issue-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px}.issue-tabs a{display:inline-flex;align-items:center;gap:6px;border:1px solid #d8dee9;border-radius:999px;background:#fff;color:#344054;text-decoration:none;padding:8px 11px;font-weight:900}.issue-tabs a.active{background:#1947ba;border-color:#1947ba;color:#fff}.issue-tabs b{font-size:12px;border-radius:999px;background:#eef2f7;color:#344054;padding:2px 7px}.issue-tabs a.active b{background:rgba(255,255,255,.22);color:#fff}.priority-high{background:#fffafa}.priority-mid{background:#fffdf5}.detail-panel summary{display:flex;align-items:center;justify-content:space-between;gap:12px;list-style:none;cursor:pointer}.detail-panel summary::-webkit-details-marker{display:none}.detail-panel summary h2{margin:0}.detail-panel summary span{color:#667085;font-size:13px;font-weight:900}.detail-panel[open] summary{margin-bottom:14px}.signal-board{grid-template-columns:1fr}.signal-board .routine{grid-template-columns:repeat(5,1fr)}.signal-board .routine-item{grid-template-columns:auto 1fr;align-items:start}.signal-board .routine-no{font-size:24px}@media(max-width:1200px){.signal-board .routine{grid-template-columns:1fr 1fr}}@media(max-width:900px){.close-hero,.grid{grid-template-columns:1fr}.kpis{grid-template-columns:1fr 1fr}.routine-item{grid-template-columns:auto 1fr}.routine-item .btn{grid-column:2}}@media(max-width:620px){.kpis,.signal-board .routine{grid-template-columns:1fr}h1{font-size:28px}}
.close-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:18px}.close-action-card{background:#fff;border:1px solid #d9dee7;border-radius:14px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.close-action-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:14px}.close-action-head h2{margin:0;font-size:20px}.state-pill{display:inline-flex;align-items:center;border-radius:999px;background:#eef2f7;color:#344054;padding:6px 10px;font-size:12px;font-weight:1000;white-space:nowrap}.state-pill.good{background:#e8f7ee;color:#087f5b}.state-pill.warn{background:#fff4e6;color:#9a5b00}.state-pill.danger{background:#fdecec;color:#c92a2a}.close-action-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}.close-action-metrics span{border:1px solid #e4eaf2;border-radius:10px;background:#fbfcff;padding:10px;color:#667085;font-size:12px;font-weight:900}.close-action-metrics b{display:block;color:#111827;font-size:21px;margin-top:4px}.close-action-buttons{display:flex;gap:8px;flex-wrap:wrap}.close-action-card p{margin:0 0 14px;color:#667085;line-height:1.55;font-size:13px}@media(max-width:1100px){.close-actions{grid-template-columns:1fr}}@media(max-width:620px){.close-action-metrics{grid-template-columns:1fr}}
#monthlyCloseIssues.is-loading{opacity:.65;pointer-events:none}
.signal-board .routine{grid-template-columns:repeat(2,minmax(280px,1fr))}
.signal-board .routine-item{min-height:86px;align-items:start}
.signal-board .routine-item strong,.signal-board .routine-item span{word-break:keep-all;overflow-wrap:normal;line-height:1.45}
@media(max-width:1300px){.kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.kpi{min-height:104px}}
@media(max-width:620px){.signal-board .routine{grid-template-columns:1fr}}

/* 2026-05-30 easy-mode tune: monthly close is a checklist, not a report poster. */
body{background:#eef2f7;color:#0f172a}
.wrap{max-width:1900px;margin:0 auto;padding:26px 28px 48px}
.hero{align-items:center;margin-bottom:16px}
.hero h1{font-size:30px;letter-spacing:0}
.meta{font-size:14px;color:#667085}
.filters .btn,.filters input{height:40px;border-radius:10px}
.panel,.kpi,.close-action-card{border:1px solid #dfe5ee;border-radius:14px;box-shadow:none;background:#fff}
.close-hero{grid-template-columns:minmax(360px,.72fr) minmax(560px,1.28fr);gap:16px;margin-top:8px}
.score-card{background:#fff;color:#0f172a;border:1px solid #dfe5ee}
.score-card h2{font-size:20px;margin:0;color:#0f172a}
.score-card p{color:#667085;font-size:14px;margin:12px 0 0}
.score-big{font-size:42px;margin-top:14px;color:#1947ba}
.progress{height:8px;background:#eef2f7}
.progress span{background:#1947ba}
.routine{gap:8px}
.routine-item{border:1px solid #e4eaf2;border-radius:12px;background:#fbfcff;padding:11px;box-shadow:none}
.routine-no{width:34px;height:34px;border-radius:12px;background:#f1f5f9;font-size:20px;color:#1947ba}
.routine-item strong{font-size:15px;color:#0f172a}
.routine-item span{font-size:12px;color:#667085}
.routine-item .btn{min-height:34px;padding:7px 11px;border-radius:9px;font-size:13px}
.kpis{grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:14px}
@media(max-width:1300px){.kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.kpi{min-height:104px}}
.kpi{padding:14px}
.kpi span{font-size:12px;color:#667085}
.kpi strong{font-size:26px;margin-top:4px}
.kpi small{font-size:11px;color:#667085}
.close-actions{gap:12px;margin-top:14px}
.close-action-card{padding:16px}
.close-action-head h2{font-size:18px}
.close-action-metrics span{background:#f8fafc;border-color:#e4eaf2}
.close-action-metrics b{font-size:19px}
.grid{gap:14px}
.detail-panel summary{padding:0}
.detail-panel[open] summary{margin-bottom:12px}
.note{background:#f8fafc;border-color:#dfe5ee;color:#334155}
th{background:#74839a}
.issue-tabs a{background:#fff;border-color:#dfe5ee}
.issue-tabs a:hover{border-color:#1947ba;color:#1947ba}
@media(max-width:1200px){.close-hero{grid-template-columns:1fr}.kpis{grid-template-columns:repeat(3,1fr)}}
@media(max-width:800px){.kpis{grid-template-columns:repeat(2,1fr)}.wrap{padding:20px 16px 40px}}
@media(max-width:560px){.kpis{grid-template-columns:1fr}.score-big{font-size:36px}}
</style>
<style>
/* Dashboard shell alignment: monthly close follows the dashboard navigation frame. */
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune{
    --ieum-side-width:260px;
    --ieum-top-height:64px;
    --ieum-rail-width:0px;
    background:#f5f7fb!important;
    color:#111827!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-side{
    width:260px!important;
    background:#fff!important;
    border-right:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-brand{
    display:flex!important;
    height:144px!important;
    min-height:144px!important;
    padding:0 28px!important;
    background:#fff!important;
    color:#0f172a!important;
    font-size:29px!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-brand-mark,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-profile,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-search{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-nav{
    padding:0 14px 24px!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-main-link,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-menu>summary{
    min-height:42px!important;
    border-radius:6px!important;
    padding:0 12px!important;
    color:#0f172a!important;
    font-size:15px!important;
    font-weight:900!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-main-link:hover,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-main-link.active,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-menu[open]>summary,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-menu>summary:hover{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-nav-label{
    gap:10px!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-nav-icon{
    width:18px!important;
    height:18px!important;
    color:#334155!important;
    opacity:1!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-sub{
    margin:2px 0 8px!important;
    padding:0 0 0 28px!important;
    background:transparent!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-sub a{
    min-height:34px!important;
    border-radius:6px!important;
    color:#475569!important;
    font-size:14px!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-sub a:hover,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .side-sub a.active{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-shell-top{
    left:260px!important;
    right:0!important;
    width:auto!important;
    height:64px!important;
    padding:0 40px!important;
    background:#fff!important;
    border-bottom:1px solid #e2e8f0!important;
    box-shadow:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-shell-link{
    flex:0 0 auto!important;
    color:#0f172a!important;
    font-weight:900!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-shell-link:before{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-shell-meta{
    margin-left:auto!important;
    color:#0f172a!important;
    font-size:13px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .dashboard-shell-meta-inner{
    display:flex!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:8px!important;
    white-space:nowrap!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .dashboard-shell-divider,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .dashboard-shell-help-dot{
    color:#94a3b8!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .dashboard-shell-support-link{
    color:#0f172a!important;
    text-decoration:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .dashboard-shell-support-link:hover,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-shell-link:hover{
    color:#1769c2!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .dashboard-shell-help-group{
    display:inline-flex!important;
    align-items:center!important;
    gap:4px!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-right-rail{
    display:none!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .wrap{
    margin:0 0 0 260px!important;
    padding:96px 40px 42px!important;
    max-width:none!important;
    width:auto!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune h1{
    margin:0!important;
    font-size:30px!important;
    font-weight:1000!important;
    letter-spacing:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .panel,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .kpi,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .close-action-card{
    border-radius:8px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.04)!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .btn{
    min-height:38px!important;
    border-radius:6px!important;
    font-size:14px!important;
    font-weight:900!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune th{
    background:#f3f6fb!important;
    color:#334155!important;
    border-color:#e2e8f0!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune td{
    border-color:#e5ebf3!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .close-hero{
    grid-template-columns:minmax(300px,.72fr) minmax(0,1.28fr)!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .close-hero>*,
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .grid>*{
    min-width:0!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .signal-board{
    grid-column:1 / -1!important;
}
body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .signal-board .routine{
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr))!important;
}
@media(max-width:980px){
    body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .wrap{
        margin:0!important;
        padding:86px 14px 34px!important;
    }
    body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .ieum-shell-top{
        left:0!important;
        right:0!important;
        padding:0 10px!important;
    }
}
@media(max-width:1180px){
    body.ieum-side-layout.ieum-dashboard-page.monthly-close-page-tune .close-hero{
        grid-template-columns:1fr!important;
    }
}
</style>
</head>
<body class="ieum-side-layout ieum-dashboard-page ieum-simple-page monthly-close-page-tune">
<?php echo ieum_admin_header('monthly_close', 'side'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>월말 루틴</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text(ieum_monthly_close_month_label($month)); ?> · 리포트 발송 전 막힌 것만 정리하는 화면</div>
        </div>
        <form class="filters" method="get">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/monthly_close.php?month=<?php echo get_text($prev_month); ?>">이전달</a>
            <input type="month" name="month" value="<?php echo get_text($month); ?>">
            <button class="btn primary" type="submit">조회</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/monthly_close.php?month=<?php echo get_text($next_month); ?>">다음달</a>
        </form>
    </section>

    <section class="close-hero">
        <article class="panel score-card">
            <span>이번 달 마감 준비율</span>
            <div class="score-big"><?php echo number_format($close_score); ?>%</div>
            <p><?php echo get_text($status_text); ?> 상태입니다. 이번 달 리포트, 수련비, 상담 신호 중 막힌 부분만 먼저 정리하면 됩니다.</p>
            <div class="progress"><span style="width:<?php echo (int) $close_score; ?>%"></span></div>
        </article>
        <article class="panel">
            <h2>오늘 누를 순서</h2>
            <div class="routine">
                <div class="routine-item"><div class="routine-no">1</div><div><strong>인성 입력 부족 확인</strong><span>부별 입력이 덜 된 원생을 먼저 정리합니다.</span></div><a class="btn" href="<?php echo IEUM_URL; ?>/admin/character.php?week_start=<?php echo get_text($month); ?>-01">열기</a></div>
                <div class="routine-item"><div class="routine-no">2</div><div><strong>보호자 연락처 확인</strong><span>대표 보호자 연락처가 있어야 리포트 링크를 보낼 수 있습니다.</span></div><a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>">열기</a></div>
                <div class="routine-item"><div class="routine-no">3</div><div><strong>아이잘해 미션 체크</strong><span>가정 실천 성공 여부를 빠르게 반영합니다.</span></div><a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_mission.php?month=<?php echo get_text($month); ?>">열기</a></div>
                <div class="routine-item"><div class="routine-no">4</div><div><strong>수련비/상담 신호 확인</strong><span>미납, 장기 미등원, 상담 필요 원생을 함께 봅니다.</span></div><a class="btn" href="<?php echo IEUM_URL; ?>/admin/tuition_payments.php?billing_month=<?php echo get_text($month); ?>">열기</a></div>
                <div class="routine-item"><div class="routine-no">5</div><div><strong>리포트 발송 준비</strong><span>발송 가능 원생만 선택해서 학부모 링크 문자를 준비합니다.</span></div><a class="btn dark" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>">발송 준비</a></div>
            </div>
        </article>
    </section>

    <section class="kpis" aria-label="월말 관리 요약">
        <article class="kpi"><span>원생</span><strong><?php echo number_format($total_students); ?>명</strong></article>
        <article class="kpi"><span>리포트 준비</span><strong class="good"><?php echo number_format($report_send_ready_total); ?>건</strong><small>인성 <?php echo number_format($summary['report_send_ready']); ?>명 · 체력 <?php echo number_format($summary['fitness_send_ready']); ?>명</small></article>
        <article class="kpi"><span>인성 입력 부족</span><strong class="<?php echo $summary['character_missing'] ? 'warn' : 'good'; ?>"><?php echo number_format($summary['character_missing']); ?>명</strong></article>
        <article class="kpi"><span>체력 측정 부족</span><strong class="<?php echo $summary['fitness_missing'] ? 'warn' : 'good'; ?>"><?php echo number_format($summary['fitness_missing']); ?>명</strong></article>
        <article class="kpi"><span>대표 보호자 없음</span><strong class="<?php echo $summary['no_phone'] ? 'danger' : 'good'; ?>"><?php echo number_format($summary['no_phone']); ?>명</strong></article>
        <article class="kpi"><span>아이잘해 성공</span><strong><?php echo number_format($summary['mission_done']); ?>명</strong></article>
        <article class="kpi"><span>수련비 미결제</span><strong class="<?php echo $summary['tuition_unpaid'] ? 'warn' : 'good'; ?>"><?php echo number_format($summary['tuition_unpaid']); ?>명</strong></article>
        <article class="kpi"><span>미납 <?php echo number_format($overdue_days); ?>일 초과</span><strong class="<?php echo $summary['tuition_overdue'] ? 'danger' : 'good'; ?>"><?php echo number_format($summary['tuition_overdue']); ?>명</strong></article>
        <article class="kpi"><span>장기 미등원</span><strong class="<?php echo $summary['long_absent'] ? 'danger' : 'good'; ?>"><?php echo number_format($summary['long_absent']); ?>명</strong></article>
        <article class="kpi"><span>우선 처리</span><strong class="<?php echo $summary['critical'] ? 'danger' : 'good'; ?>"><?php echo number_format($summary['critical']); ?>명</strong></article>
        <article class="kpi"><span>평가 기준</span><strong><?php echo number_format($expected_weeks); ?>주</strong></article>
    </section>

    <div class="note">
        월말 관리는 학부모에게 보여줄 문서를 꾸미는 화면이 아니라, 관장님이 이번 달 원생 관리가 어디까지 끝났는지 확인하는 운영 루틴입니다. 아래의 막힌 항목만 정리하면 리포트 발송과 상담 준비가 훨씬 가벼워집니다.
    </div>

    <section class="close-actions" aria-label="월말 리포트 실행">
        <article class="close-action-card">
            <div class="close-action-head">
                <h2>인성 리포트 마감</h2>
                <span class="state-pill <?php echo $summary['character_missing'] ? 'warn' : 'good'; ?>"><?php echo $summary['character_missing'] ? '입력 확인 필요' : '입력 완료'; ?></span>
            </div>
            <p>주간 인성 입력이 끝난 원생만 학부모용 인성리포트 인쇄와 링크 문자 발송 대상으로 잡습니다.</p>
            <div class="close-action-metrics">
                <span>입력 완료<b><?php echo number_format(max(0, $total_students - (int) $summary['character_missing'])); ?>명</b></span>
                <span>입력 부족<b><?php echo number_format($summary['character_missing']); ?>명</b></span>
                <span>발송 가능<b><?php echo number_format($summary['report_send_ready']); ?>명</b></span>
            </div>
            <div class="close-action-buttons">
                <a class="btn" href="<?php echo get_text($character_input_url); ?>">입력 정리</a>
                <a class="btn primary" href="<?php echo get_text($character_send_url); ?>">발송 대상</a>
                <a class="btn" target="_blank" rel="noopener" href="<?php echo get_text($character_print_url); ?>">전체 인쇄</a>
            </div>
        </article>
        <article class="close-action-card">
            <div class="close-action-head">
                <h2>체력 리포트 마감</h2>
                <span class="state-pill <?php echo $summary['fitness_missing'] ? 'warn' : 'good'; ?>"><?php echo $summary['fitness_missing'] ? '측정 확인 필요' : '측정 완료'; ?></span>
            </div>
            <p>이번 측정 주기 대상 중 실제 측정값이 입력된 원생만 체력리포트 발송/인쇄 대상으로 넘어갑니다.</p>
            <div class="close-action-metrics">
                <span>측정 대상<b><?php echo number_format($summary['fitness_enabled']); ?>명</b></span>
                <span>측정 완료<b><?php echo number_format($summary['fitness_completed']); ?>명</b></span>
                <span>발송 가능<b><?php echo number_format($summary['fitness_send_ready']); ?>명</b></span>
            </div>
            <div class="close-action-buttons">
                <a class="btn" href="<?php echo get_text($fitness_input_url); ?>">측정 입력</a>
                <a class="btn primary" href="<?php echo get_text($fitness_send_url); ?>">발송 대상</a>
                <a class="btn" target="_blank" rel="noopener" href="<?php echo get_text($fitness_print_url); ?>">전체 인쇄</a>
            </div>
        </article>
        <article class="close-action-card">
            <div class="close-action-head">
                <h2>보호자/문자 확인</h2>
                <span class="state-pill <?php echo $summary['no_phone'] ? 'danger' : 'good'; ?>"><?php echo $summary['no_phone'] ? '연락처 확인' : '발송 가능'; ?></span>
            </div>
            <p>대표 보호자 연락처가 없으면 리포트가 완성되어도 문자가 나가지 않습니다. 발송 전 마지막으로 문자 발송 준비 상태까지 확인합니다.</p>
            <div class="close-action-metrics">
                <span>대표 없음<b><?php echo number_format($summary['no_phone']); ?>명</b></span>
                <span>인성+체력 준비<b><?php echo number_format($report_send_ready_total); ?>건</b></span>
                <span>우선 처리<b><?php echo number_format($summary['critical']); ?>명</b></span>
            </div>
            <div class="close-action-buttons">
                <a class="btn" href="<?php echo get_text($student_phone_url); ?>">연락처 정리</a>
                <a class="btn" href="<?php echo get_text($character_report_url); ?>">인성 목록</a>
                <a class="btn" href="<?php echo get_text($fitness_report_url); ?>">체력 목록</a>
                <a class="btn dark" href="<?php echo get_text($sms_queue_url); ?>">문자 현황</a>
            </div>
        </article>
    </section>

    <section class="grid">
        <details class="panel detail-panel">
            <summary><h2>부별 마감 현황</h2><span>부별로 막힌 곳만 펼쳐서 확인</span></summary>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>부</th>
                            <th>원생</th>
                            <th>발송 가능</th>
                            <th>인성 부족</th>
                            <th>체력 완료</th>
                            <th>체력 부족</th>
                            <th>연락처 없음</th>
                            <th>미션 성공</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($class_summary as $class_label => $row) { ?>
                        <tr>
                            <td class="left"><?php echo get_text($class_label); ?></td>
                            <td><?php echo number_format((int) $row['total']); ?></td>
                            <td class="good"><?php echo number_format((int) $row['ready']); ?></td>
                            <td class="<?php echo $row['character_missing'] ? 'warn' : ''; ?>"><?php echo number_format((int) $row['character_missing']); ?></td>
                            <td class="good"><?php echo number_format((int) $row['fitness_completed']); ?></td>
                            <td class="<?php echo $row['fitness_missing'] ? 'warn' : ''; ?>"><?php echo number_format((int) $row['fitness_missing']); ?></td>
                            <td class="<?php echo $row['no_phone'] ? 'danger' : ''; ?>"><?php echo number_format((int) $row['no_phone']); ?></td>
                            <td><?php echo number_format((int) $row['mission_done']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </details>
        <article class="panel signal-board">
            <h2>이번 달 핵심 신호</h2>
            <div class="routine">
                <div class="routine-item"><div class="routine-no">!</div><div><strong>발송 불가 원인</strong><span>연락처 <?php echo number_format($summary['no_phone']); ?>명 · 인성 <?php echo number_format($summary['character_missing']); ?>명 · 체력 <?php echo number_format($summary['fitness_missing']); ?>명</span></div></div>
                <div class="routine-item"><div class="routine-no">체</div><div><strong>체력 리포트</strong><span>측정 완료 <?php echo number_format($summary['fitness_completed']); ?>명 · 발송 가능 <?php echo number_format($summary['fitness_send_ready']); ?>명</span></div></div>
                <div class="routine-item"><div class="routine-no">비</div><div><strong>수련비 관리</strong><span>미결제 <?php echo number_format($summary['tuition_unpaid']); ?>명 · <?php echo number_format($overdue_days); ?>일 초과 <?php echo number_format($summary['tuition_overdue']); ?>명</span></div></div>
                <div class="routine-item"><div class="routine-no">미</div><div><strong>아이잘해 미션</strong><span>성공 <?php echo number_format($summary['mission_done']); ?>명 · 미참여 <?php echo number_format($summary['mission_wait']); ?>명</span></div></div>
                <div class="routine-item"><div class="routine-no">상</div><div><strong>상담 신호</strong><span>최근 14일 이상 출석 기록 없음 <?php echo number_format($summary['long_absent']); ?>명</span></div></div>
            </div>
        </article>
    </section>

    <section class="panel" id="monthlyCloseIssues">
        <h2>먼저 정리할 원생</h2>
        <?php
        $issue_tabs = array(
            'all' => '전체',
            'critical' => '우선 처리',
            'character' => '인성 입력',
            'fitness' => '체력 측정',
            'phone' => '연락처',
            'mission' => '아이잘해',
            'tuition' => '수련비',
            'absent' => '장기 미등원',
        );
        ?>
        <div class="issue-tabs" aria-label="점검 유형 필터">
            <?php foreach ($issue_tabs as $key => $label) { ?>
                <a class="<?php echo $issue === $key ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/monthly_close.php?month=<?php echo get_text($month); ?>&amp;issue=<?php echo get_text($key); ?>">
                    <?php echo get_text($label); ?> <b><?php echo number_format(isset($issue_counts[$key]) ? (int) $issue_counts[$key] : 0); ?></b>
                </a>
            <?php } ?>
        </div>
        <?php if (!$blocker_preview) { ?>
            <div class="empty">이번 달 월말 관리에서 크게 막힌 항목이 없습니다. 리포트 발송 준비로 넘어가도 좋습니다.</div>
        <?php } else { ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>원생</th>
                            <th>부</th>
                            <th>학년/부</th>
                            <th>정리할 내용</th>
                            <th>바로가기</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($blocker_preview as $row) { $s = $row['student']; ?>
                        <tr class="<?php echo (int) $row['priority'] <= 20 ? 'priority-high' : 'priority-mid'; ?>">
                            <td class="left"><strong><?php echo get_text($s['student_name']); ?></strong> <span class="meta">(<?php echo get_text($s['student_code']); ?>)</span></td>
                            <td><?php echo get_text($row['class_label']); ?></td>
                            <td><?php echo get_text(ieum_monthly_close_grade_label($s['grade_group'])); ?></td>
                            <td class="left">
                                <div class="chips">
                                <?php foreach ($row['reasons'] as $reason) {
                                    $class = (strpos($reason, '없음') !== false || strpos($reason, '초과') !== false || strpos($reason, '14일') !== false) ? 'danger' : 'warn';
                                ?>
                                    <span class="chip <?php echo $class; ?>"><?php echo get_text($reason); ?></span>
                                <?php } ?>
                                </div>
                            </td>
                            <td>
                                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&amp;student_id=<?php echo (int) $s['student_id']; ?>">원생</a>
                                <a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>&amp;student_id=<?php echo (int) $s['student_id']; ?>">리포트</a>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </section>
</main>
<script>
(function(){
    var rootSelector = '.monthly-close-page-tune.ieum-dashboard-page';
    var brandText = document.querySelector(rootSelector + ' .side-brand span:last-child');
    if (brandText) {
        brandText.textContent = <?php echo json_encode($academy['academy_name']); ?>;
    }

    var homeLink = document.querySelector(rootSelector + ' .ieum-shell-link');
    if (homeLink) {
        homeLink.textContent = '아이이음 교육페이지';
    }

    var meta = document.querySelector(rootSelector + ' .ieum-shell-meta');
    if (meta) {
        var now = new Date();
        var hh = String(now.getHours()).padStart(2, '0');
        var mm = String(now.getMinutes()).padStart(2, '0');
        meta.innerHTML = ''
            + '<span class="dashboard-shell-meta-inner">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php">개발지원센터</a>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-help-group">'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#qna">Q&A</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#faq">자주하는 질문</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#contact">문의하기</a>'
            + '<span class="dashboard-shell-help-dot">·</span>'
            + '<a class="dashboard-shell-support-link" href="<?php echo IEUM_URL; ?>/admin/support.php#chatbot">AI 챗봇</a>'
            + '</span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span><?php echo get_text($academy['academy_name']); ?></span>'
            + '<span class="dashboard-shell-divider">|</span>'
            + '<span class="dashboard-shell-clock">' + hh + ':' + mm + '</span>'
            + '</span>';
    }
})();

(function () {
    const issues = document.getElementById('monthlyCloseIssues');
    if (!issues) return;

    issues.addEventListener('click', async (event) => {
        const link = event.target.closest('.issue-tabs a');
        if (!link) return;

        event.preventDefault();
        const previousTop = issues.getBoundingClientRect().top;
        const previousHeight = issues.offsetHeight;
        issues.style.minHeight = previousHeight + 'px';
        issues.classList.add('is-loading');

        try {
            const response = await fetch(link.href, {
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            });
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.getElementById('monthlyCloseIssues');
            if (!next) {
                window.location.href = link.href;
                return;
            }
            issues.innerHTML = next.innerHTML;
            history.pushState({monthlyCloseIssue: true}, '', link.href.replace('#monthlyCloseIssues', ''));
            window.scrollBy(0, issues.getBoundingClientRect().top - previousTop);
        } catch (error) {
            window.location.href = link.href;
        } finally {
            issues.classList.remove('is-loading');
            issues.style.minHeight = '';
        }
    });
})();
</script>
</body>
</html>
