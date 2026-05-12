<?php
$sub_menu = '950182';
require_once './_common.php';
require_once IEUM_PATH . '/lib/character.php';
require_once IEUM_PATH . '/lib/character_mission.php';
require_once IEUM_PATH . '/lib/character_level.php';
require_once IEUM_PATH . '/lib/program.php';

$g5['title'] = '아이이음 장기 인성 성장 리포트';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_character_ensure_table();
ieum_character_mission_ensure_tables();
ieum_character_level_ensure_table();

function ieum_growth_report_grade_label($value)
{
    $labels = array(
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

    return isset($labels[$value]) ? $labels[$value] : ($value ?: '-');
}

function ieum_growth_report_months($end_month, $period)
{
    $period = max(3, min(12, (int) $period));
    $end_ts = strtotime($end_month . '-01');
    $months = array();
    for ($i = $period - 1; $i >= 0; $i--) {
        $months[] = date('Y-m', strtotime('-' . $i . ' month', $end_ts));
    }
    return $months;
}

function ieum_growth_report_stage_text($period)
{
    if ((int) $period >= 12) {
        return array('title' => '1년 성장 포트폴리오', 'desc' => '한 해 동안 쌓인 인성, 출석, 가정 실천 기록을 학부모 상담과 장기 재등록 상담에 활용합니다.');
    }
    if ((int) $period >= 6) {
        return array('title' => '6개월 성장 상담', 'desc' => '반년 동안의 수련 흐름과 아이잘해 미션 참여를 보며 다음 목표를 정리합니다.');
    }
    return array('title' => '분기 성장 상담', 'desc' => '최근 3개월의 변화만 빠르게 확인해 관장님 상담과 학부모 피드백에 활용합니다.');
}

function ieum_growth_report_average($values)
{
    $values = array_values(array_filter($values, function ($value) {
        return $value !== null;
    }));
    if (!$values) {
        return 0;
    }
    return round(array_sum($values) / count($values), 1);
}

$month = isset($_GET['month']) ? preg_replace('/[^0-9\-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}\-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$period = isset($_GET['period']) ? (int) $_GET['period'] : 3;
if (!in_array($period, array(3, 6, 12), true)) {
    $period = 3;
}
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$program_options = ieum_program_options($academy_id, true);
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$student_id = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
$program_filter_sql = '';
if ($program_code !== '') {
    $program_sql = sql_escape_string($program_code);
    $program_filter_sql = " and s.program_code = '{$program_sql}' ";
}
$class_filter_sql = $class_time_id ? " and s.class_time_id = '{$class_time_id}' " : "";

$class_options = array();
$classes = sql_query("
    select class_time_id, class_name, start_time
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc
", false);
while ($class = sql_fetch_array($classes)) {
    $class_options[] = $class;
}

$student_options = array();
$student_rows = sql_query("
    select s.student_id, s.student_code, s.student_name, s.student_photo,
           s.birth_date, s.admission_date, s.attendance_days, s.program_code, s.grade_group, s.school_name,
           c.class_name, c.start_time
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
     where s.academy_id = '{$academy_id}'
       and s.is_active = 1
       {$program_filter_sql}
       {$class_filter_sql}
  order by c.sort_order asc, c.start_time asc, s.student_name asc
", false);
while ($row = sql_fetch_array($student_rows)) {
    $student_options[] = $row;
}

if (!$student_id && $student_options) {
    $student_id = (int) $student_options[0]['student_id'];
}

$student = null;
foreach ($student_options as $option) {
    if ((int) $option['student_id'] === $student_id) {
        $student = $option;
        break;
    }
}

$months = ieum_growth_report_months($month, $period);
$student_summaries = array();
foreach ($student_options as $option) {
    $option_scores = array();
    $option_attendance = array();
    $option_missions = 0;
    $option_first = null;
    $option_last = null;
    foreach ($months as $target_month) {
        $option_score = ieum_character_month_score($academy_id, $option, $target_month);
        if (empty($option_score['is_before_admission'])) {
            $score_value = (int) $option_score['total_score'];
            $option_scores[] = $score_value;
            if ($option_first === null) {
                $option_first = $score_value;
            }
            $option_last = $score_value;
            $option_attendance[] = (int) $option_score['attendance']['rate'];
        }
        $option_mission = ieum_character_mission_report($academy_id, (int) $option['student_id'], $target_month);
        if ($option_mission && $option_mission['is_participated']) {
            $option_missions++;
        }
    }
    $student_summaries[] = array(
        'student' => $option,
        'avg_score' => ieum_growth_report_average($option_scores),
        'avg_attendance' => ieum_growth_report_average($option_attendance),
        'delta' => ($option_first !== null && $option_last !== null) ? $option_last - $option_first : 0,
        'mission_success' => $option_missions,
    );
}
$rows = array();
$score_values = array();
$attendance_values = array();
$mission_success = 0;
$first_score = null;
$last_score = null;
$level_summary = null;
$level_current = null;
$best_component = null;
$watch_component = null;

if ($student) {
    foreach ($months as $target_month) {
        $score = ieum_character_month_score($academy_id, $student, $target_month);
        $mission = ieum_character_mission_report($academy_id, (int) $student['student_id'], $target_month);
        $level_summary = ieum_character_level_sync_snapshot($academy_id, $student, $target_month);
        $level = $level_summary['current']['level'];
        $items = empty($score['components']) ? array() : ieum_character_component_values($score);
        foreach ($items as $item) {
            if ($best_component === null || (int) $item['score'] > (int) $best_component['score']) {
                $best_component = $item;
            }
            if ($watch_component === null || (int) $item['score'] < (int) $watch_component['score']) {
                $watch_component = $item;
            }
        }
        $monthly_score = empty($score['is_before_admission']) ? (int) $score['total_score'] : null;
        if ($monthly_score !== null) {
            if ($first_score === null) {
                $first_score = $monthly_score;
            }
            $last_score = $monthly_score;
            $score_values[] = $monthly_score;
            $attendance_values[] = (int) $score['attendance']['rate'];
        }
        if ($mission && $mission['is_participated']) {
            $mission_success++;
        }
        $rows[] = array(
            'month' => $target_month,
            'score' => $score,
            'mission' => $mission,
            'level' => $level,
            'monthly_score' => $monthly_score,
            'attendance_rate' => empty($score['is_before_admission']) ? (int) $score['attendance']['rate'] : null,
        );
    }
    $level_current = $level_summary ? $level_summary['current']['level'] : null;
}

$stage = ieum_growth_report_stage_text($period);
$avg_score = ieum_growth_report_average($score_values);
$avg_attendance = ieum_growth_report_average($attendance_values);
$score_delta = ($first_score !== null && $last_score !== null) ? $last_score - $first_score : 0;
$photo_url = $student && !empty($student['student_photo']) ? G5_URL . '/' . ltrim($student['student_photo'], '/') : '';
$class_label = $student ? trim(($student['class_name'] ?: '미지정') . ' ' . ($student['start_time'] ?: '')) : '';
$grade_label = $student ? ieum_growth_report_grade_label($student['grade_group']) : '';
$school_label = $student && $student['school_name'] !== '' ? $student['school_name'] : '학교 미입력';
$period_label = $months ? $months[0] . ' ~ ' . $months[count($months) - 1] : $month;
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.wrap.is-loading{opacity:.55;pointer-events:none}.hero{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap}h1{margin:0;font-size:30px}h2{margin:0 0 14px;font-size:20px}.meta{color:#667085;margin-top:6px;line-height:1.45}.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:16px 0}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:8px 12px;font-weight:900;cursor:pointer}.primary{background:#1947ba;border-color:#1947ba;color:#fff}input,select{border:1px solid #cfd6df;border-radius:6px;padding:9px;font-size:14px}.panel{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:20px;box-shadow:0 8px 20px rgba(15,23,42,.06);margin-top:18px}.cover{background:linear-gradient(135deg,#1947ba,#2c2a25);border:0;color:#fff;display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center}.profile{display:flex;gap:14px;align-items:center}.avatar{width:82px;height:82px;border-radius:22px;object-fit:cover;background:#e8eef7;border:3px solid rgba(255,255,255,.75)}.avatar-empty{display:flex;align-items:center;justify-content:center;color:#667085;font-weight:900}.cover .meta{color:#dbe6ff}.cover h1{font-size:32px}.level-emblem{min-width:150px;border-radius:24px;background:rgba(255,255,255,.14);padding:20px;text-align:center;box-shadow:inset 0 0 0 4px rgba(255,255,255,.14)}.level-emblem strong{display:block;font-size:24px}.level-emblem span{display:block;margin-top:6px;font-weight:900;color:#dbe6ff}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.kpi{border:1px solid #d9dee7;border-radius:10px;padding:16px;background:#fbfcff}.kpi span{display:block;color:#667085;font-weight:900;font-size:13px}.kpi strong{display:block;margin-top:6px;font-size:28px}.up{color:#087f5b}.down{color:#c92a2a}.grid{display:grid;grid-template-columns:1.2fr .8fr;gap:18px}.timeline{display:grid;gap:10px}.month-row{display:grid;grid-template-columns:78px 1fr 95px;gap:10px;align-items:center}.bar{height:13px;background:#eef2f7;border-radius:999px;overflow:hidden}.fill{height:100%;border-radius:999px;background:#1947ba}.month-row strong{font-size:14px}.month-row span{text-align:right;font-weight:900}.badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px}.badge{display:inline-flex;border-radius:999px;background:#eef2f7;color:#344054;padding:4px 8px;font-size:12px;font-weight:900}.badge.ok{background:#e8f7ee;color:#087f5b}.badge.wait{background:#fff4e6;color:#9a5b00}.story{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;line-height:1.75}.story strong{color:#1947ba}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;background:#fff}th,td{border:1px solid #d8dee9;padding:9px;text-align:center;font-size:14px}th{background:#72829d;color:#fff}.left{text-align:left}.print-note{color:#667085;font-size:12px;line-height:1.5}.student-summary table{min-width:820px}.student-summary tr.active{background:#eef6ff}.mini{display:block;color:#667085;font-size:12px;margin-top:3px}.pill{display:inline-flex;border-radius:999px;padding:4px 8px;background:#eef2f7;color:#344054;font-size:12px;font-weight:900}.pill.up{background:#e8f7ee;color:#087f5b}.pill.down{background:#fdecec;color:#c92a2a}@media(max-width:900px){.cover,.grid{grid-template-columns:1fr}.kpis{grid-template-columns:repeat(2,1fr)}.ieum-user{margin-left:0}.month-row{grid-template-columns:66px 1fr 70px}}@media print{.top,.filters,.print-hide,.student-summary{display:none}.wrap{max-width:none;margin:0;padding:0}.panel{box-shadow:none;border-color:#aaa;break-inside:avoid}.cover{color:#fff}.grid{grid-template-columns:1.2fr .8fr}body{background:#fff}}
</style>
</head>
<body>
<?php echo ieum_admin_header('character_growth'); ?>
<?php echo ieum_admin_subnav('character_growth'); ?>
<main class="wrap">
    <section class="hero">
        <div>
            <h1>장기 인성 성장 리포트</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 3개월 상담, 6개월 재등록 상담, 1년 성장 포트폴리오</div>
        </div>
        <button type="button" class="btn print-hide" onclick="window.print()">인쇄</button>
    </section>

    <form method="get" class="filters">
        <input type="month" name="month" value="<?php echo get_text($month); ?>">
        <select name="period">
            <option value="3" <?php echo get_selected($period, 3); ?>>최근 3개월</option>
            <option value="6" <?php echo get_selected($period, 6); ?>>최근 6개월</option>
            <option value="12" <?php echo get_selected($period, 12); ?>>최근 12개월</option>
        </select>
        <select name="program_code" onchange="this.form.student_id.value='0'">
            <option value="">전체 프로그램</option>
            <?php foreach ($program_options as $program) { ?>
            <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($program_code, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
            <?php } ?>
        </select>
        <select name="class_time_id" onchange="this.form.student_id.value='0'">
            <option value="0">전체 부</option>
            <?php foreach ($class_options as $class) { ?>
            <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($class_time_id, (int) $class['class_time_id']); ?>><?php echo get_text($class['class_name'] . ' ' . $class['start_time']); ?></option>
            <?php } ?>
        </select>
        <select name="student_id">
            <?php foreach ($student_options as $option) { ?>
            <option value="<?php echo (int) $option['student_id']; ?>" <?php echo get_selected($student_id, (int) $option['student_id']); ?>><?php echo get_text($option['student_name'] . ' (' . $option['student_code'] . ')'); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn primary">조회</button>
        <?php if ($student) { ?>
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_report.php?month=<?php echo get_text($month); ?>&amp;program_code=<?php echo get_text($program_code); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>&amp;student_id=<?php echo (int) $student_id; ?>">월간 보기</a>
        <?php } ?>
    </form>

    <section class="panel student-summary print-hide">
        <div class="hero">
            <div>
                <h2>학생별 장기 흐름</h2>
                <div class="meta">선택한 기간의 평균, 변화량, 아이잘해 참여를 먼저 보고 상담 대상 학생을 고릅니다.</div>
            </div>
            <span class="badge"><?php echo number_format(count($student_summaries)); ?>명</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>학생</th><th>프로그램</th><th>부</th><th>평균 상태</th><th>변화</th><th>출석 흐름</th><th>아이잘해</th><th>관리</th></tr>
                </thead>
                <tbody>
                <?php foreach ($student_summaries as $summary) {
                    $option = $summary['student'];
                    $delta = (int) $summary['delta'];
                ?>
                <tr class="<?php echo (int) $option['student_id'] === $student_id ? 'active' : ''; ?>">
                    <td class="left"><strong><?php echo get_text($option['student_name']); ?></strong><span class="mini"><?php echo get_text($option['student_code'] . ' · ' . ieum_growth_report_grade_label($option['grade_group'])); ?></span></td>
                    <td><?php echo get_text(ieum_program_label($academy_id, isset($option['program_code']) ? $option['program_code'] : '')); ?></td>
                    <td><?php echo get_text(trim(($option['class_name'] ?: '미지정') . ' ' . ($option['start_time'] ?: ''))); ?></td>
                    <td><strong><?php echo number_format($summary['avg_score'], 1); ?></strong></td>
                    <td><span class="pill <?php echo $delta >= 0 ? 'up' : 'down'; ?>"><?php echo $delta >= 0 ? '+' : ''; ?><?php echo number_format($delta); ?></span></td>
                    <td><?php echo number_format($summary['avg_attendance'], 1); ?>%</td>
                    <td><?php echo number_format((int) $summary['mission_success']); ?>/<?php echo number_format(count($months)); ?></td>
                    <td><a class="btn" href="<?php echo IEUM_URL; ?>/admin/character_growth_report.php?month=<?php echo get_text($month); ?>&amp;period=<?php echo (int) $period; ?>&amp;program_code=<?php echo get_text($program_code); ?>&amp;class_time_id=<?php echo (int) $class_time_id; ?>&amp;student_id=<?php echo (int) $option['student_id']; ?>">보기</a></td>
                </tr>
                <?php } ?>
                <?php if (!$student_summaries) { ?><tr><td colspan="8">조건에 맞는 학생이 없습니다.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if (!$student) { ?>
    <section class="panel">리포트를 볼 학생이 없습니다.</section>
    <?php } else { ?>
    <section class="panel cover">
        <div class="profile">
            <?php if ($photo_url !== '') { ?>
            <img class="avatar" src="<?php echo get_text($photo_url); ?>" alt="">
            <?php } else { ?>
            <div class="avatar avatar-empty">사진</div>
            <?php } ?>
            <div>
                <h1><?php echo get_text($student['student_name']); ?> 성장 리포트</h1>
                <div class="meta"><?php echo get_text($stage['title']); ?> · <?php echo get_text($period_label); ?><br><?php echo get_text($class_label); ?> · <?php echo get_text($school_label); ?> · <?php echo get_text($grade_label); ?></div>
            </div>
        </div>
        <?php if ($level_current) { ?>
        <div class="level-emblem" style="background:<?php echo get_text($level_current['current']['color']); ?>">
            <strong><?php echo get_text($level_current['current']['tier_label']); ?></strong>
            <span><?php echo number_format((int) $level_current['current']['rank']); ?>단계 · 누적 <?php echo number_format((int) $level_current['points']); ?>점</span>
        </div>
        <?php } ?>
    </section>

    <section class="panel">
        <h2><?php echo get_text($stage['title']); ?> 핵심 요약</h2>
        <p class="meta"><?php echo get_text($stage['desc']); ?></p>
        <div class="kpis">
            <div class="kpi"><span>평균 인성 흐름</span><strong><?php echo number_format($avg_score, 1); ?></strong></div>
            <div class="kpi"><span>점수 변화</span><strong class="<?php echo $score_delta >= 0 ? 'up' : 'down'; ?>"><?php echo $score_delta >= 0 ? '+' : ''; ?><?php echo number_format($score_delta); ?></strong></div>
            <div class="kpi"><span>평균 출석 흐름</span><strong><?php echo number_format($avg_attendance, 1); ?>%</strong></div>
            <div class="kpi"><span>아이잘해 성공</span><strong><?php echo number_format($mission_success); ?>/<?php echo number_format(count($rows)); ?></strong></div>
        </div>
    </section>

    <section class="grid">
        <article class="panel">
            <h2>월별 성장 흐름</h2>
            <div class="timeline">
                <?php foreach ($rows as $row) {
                    $value = $row['monthly_score'] === null ? 0 : (int) $row['monthly_score'];
                    $width = max(0, min(100, $value));
                ?>
                <div>
                    <div class="month-row">
                        <strong><?php echo get_text(substr($row['month'], 5, 2)); ?>월</strong>
                        <div class="bar"><div class="fill" style="width:<?php echo $width; ?>%"></div></div>
                        <span><?php echo $row['monthly_score'] === null ? '-' : number_format($value); ?>점</span>
                    </div>
                    <div class="badges">
                        <span class="badge"><?php echo get_text($row['level']['current']['label']); ?></span>
                        <span class="badge <?php echo $row['mission']['is_participated'] ? 'ok' : 'wait'; ?>"><?php echo get_text($row['mission']['status_label']); ?></span>
                        <span class="badge">출석 <?php echo $row['attendance_rate'] === null ? '-' : number_format((int) $row['attendance_rate']) . '%'; ?></span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </article>
        <aside class="panel">
            <h2>상담 포인트</h2>
            <div class="story">
                <?php if ($score_delta > 0) { ?>
                <p><strong>좋은 흐름:</strong> 시작 월보다 최근 월의 인성 흐름이 <?php echo number_format($score_delta); ?>점 좋아졌습니다. 아이가 수업 규칙과 도장 분위기에 적응하며 성장 포인트를 쌓고 있습니다.</p>
                <?php } elseif ($score_delta < 0) { ?>
                <p><strong>관찰 필요:</strong> 최근 월의 흐름이 시작 월보다 낮아졌습니다. 점수 자체보다 수업 참여 리듬, 피로도, 결석 이유를 함께 확인하면 좋습니다.</p>
                <?php } else { ?>
                <p><strong>안정 흐름:</strong> 기간 동안 큰 흔들림 없이 일정한 흐름을 유지하고 있습니다. 다음 목표를 작게 정해 성취감을 만들어주면 좋습니다.</p>
                <?php } ?>
                <p><strong>강점 영역:</strong> <?php echo get_text($best_component ? $best_component['label'] : '-'); ?> 영역이 가장 눈에 띕니다.</p>
                <p><strong>다음 관찰:</strong> <?php echo get_text($watch_component ? $watch_component['label'] : '-'); ?> 영역은 다음 상담 때 부드럽게 확인하면 좋습니다.</p>
                <p><strong>아이잘해:</strong> 가정 미션은 <?php echo number_format($mission_success); ?>회 성공했습니다. 도장 수업과 가정 실천이 연결될수록 리포트의 설득력이 커집니다.</p>
            </div>
        </aside>
    </section>

    <section class="panel">
        <h2>월별 상세</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>월</th>
                        <th>인성 흐름</th>
                        <th>출석</th>
                        <th>아이잘해</th>
                        <th>성장 포인트</th>
                        <th>레벨</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) { ?>
                    <tr>
                        <td><?php echo get_text($row['month']); ?></td>
                        <td><?php echo $row['monthly_score'] === null ? '-' : number_format((int) $row['monthly_score']) . '점'; ?></td>
                        <td><?php echo $row['attendance_rate'] === null ? '-' : number_format((int) $row['attendance_rate']) . '%'; ?></td>
                        <td><?php echo get_text($row['mission']['status_label']); ?></td>
                        <td><?php echo number_format((int) $row['level']['points']); ?>점</td>
                        <td><?php echo get_text($row['level']['current']['label']); ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="print-note">이 화면은 상담용 내부 리포트입니다. 학부모 공유용 디자인은 월간 리포트 안정화 후 6개월/12개월 버전으로 따로 다듬는 것을 추천합니다.</p>
    </section>
    <?php } ?>
</main>
<script>
(function () {
    const main = document.querySelector('main.wrap');
    if (!main) return;
    const loadView = async (url, push) => {
        main.classList.add('is-loading');
        try {
            const response = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'});
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.querySelector('main.wrap');
            if (!next) {
                window.location.href = url;
                return;
            }
            main.innerHTML = next.innerHTML;
            if (push) history.pushState({ieumAjax: true}, '', url);
        } catch (error) {
            window.location.href = url;
        } finally {
            main.classList.remove('is-loading');
        }
    };
    main.addEventListener('submit', (event) => {
        const form = event.target.closest('form.filters');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        const url = form.action || window.location.pathname;
        loadView(url + '?' + new URLSearchParams(new FormData(form)).toString(), true);
    });
    main.addEventListener('change', (event) => {
        const control = event.target.closest('form.filters input, form.filters select');
        if (!control) return;
        const form = control.form;
        if (!form) return;
        const url = form.action || window.location.pathname;
        loadView(url + '?' + new URLSearchParams(new FormData(form)).toString(), true);
    });
    main.addEventListener('click', (event) => {
        const link = event.target.closest('.student-summary a.btn');
        if (!link || link.target) return;
        event.preventDefault();
        loadView(link.href, true);
    });
    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
</body>
</html>
