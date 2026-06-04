<?php
$sub_menu = '950148';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 승급 대상 인쇄';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

function ieum_promotion_print_grade_label($value)
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
        'jump_rope' => '줄넘기부',
    );
    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_promotion_print_status_label($status)
{
    if (empty($status['enabled'])) {
        return '승급 제외';
    }
    if (!empty($status['overdue'])) {
        return '기간 지남';
    }
    if (!empty($status['due_this_month'])) {
        return '이번 달';
    }
    return '예정';
}

$month = isset($_GET['month']) ? preg_replace('/[^0-9-]/', '', trim($_GET['month'])) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$program_code = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$belt_filter = isset($_GET['belt']) ? trim((string) $_GET['belt']) : '';
$belt_filter = preg_replace('/[<>"\']/', '', $belt_filter);
$status_filter = isset($_GET['status']) ? preg_replace('/[^0-9a-z_]/i', '', $_GET['status']) : 'due';
if (!in_array($status_filter, array('due', 'overdue', 'all'), true)) {
    $status_filter = 'due';
}

$belts = ieum_promotion_belts($academy);
$belt_order_map = array();
foreach ($belts as $belt_index => $belt_name) {
    $belt_order_map[trim((string) $belt_name)] = (int) $belt_index;
}
if ($belt_filter !== '' && !in_array($belt_filter, $belts, true)) {
    $belt_filter = '';
}

$where = " where s.academy_id = '{$academy_id}' and s.is_active = 1 ";
if ($program_code !== '') {
    $where .= " and s.program_code = '" . sql_escape_string($program_code) . "' ";
}
if ($class_time_id > 0) {
    $where .= " and s.class_time_id = '{$class_time_id}' ";
}

$rows = array();
$belt_need_counts = array();
$counts = array('target' => 0, 'overdue' => 0, 'month' => 0, 'excluded' => 0);
$result = sql_query("
    select s.*, c.class_name, c.start_time, c.sort_order
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      {$where}
  order by c.sort_order asc, c.start_time asc, s.student_name asc, s.student_code asc
", false);

while ($student = sql_fetch_array($result)) {
    $status = ieum_promotion_status($academy, $student, $month);
    if (!empty($status['is_poomdan_exam'])) {
        continue;
    }
    if (!$status['enabled']) {
        $counts['excluded']++;
    }
    if ($status['enabled'] && ($status['due_this_month'] || $status['overdue'])) {
        $counts['target']++;
        if ($status['overdue']) {
            $counts['overdue']++;
        } else {
            $counts['month']++;
        }
        $need_belt = isset($status['next_rank']['belt']) ? $status['next_rank']['belt'] : '';
        if ($need_belt !== '') {
            if (!isset($belt_need_counts[$need_belt])) {
                $belt_need_counts[$need_belt] = 0;
            }
            $belt_need_counts[$need_belt]++;
        }
    }

    $include = false;
    if ($status_filter === 'all') {
        $include = $status['enabled'];
    } elseif ($status_filter === 'overdue') {
        $include = $status['overdue'];
    } else {
        $include = $status['enabled'] && ($status['due_this_month'] || $status['overdue']);
    }
    if ($include) {
        $student['_promotion_status'] = $status;
        $student['_belt_order'] = isset($belt_order_map[$status['belt']]) ? (int) $belt_order_map[$status['belt']] : 999;
        if ($belt_filter !== '' && $status['belt'] !== $belt_filter) {
            continue;
        }
        $rows[] = $student;
    }
}

usort($rows, function ($a, $b) {
    $a_class = isset($a['sort_order']) ? (int) $a['sort_order'] : 999;
    $b_class = isset($b['sort_order']) ? (int) $b['sort_order'] : 999;
    if ($a_class !== $b_class) {
        return $a_class < $b_class ? -1 : 1;
    }
    if ((string) $a['start_time'] !== (string) $b['start_time']) {
        return strcmp((string) $a['start_time'], (string) $b['start_time']);
    }
    if ((int) $a['_belt_order'] !== (int) $b['_belt_order']) {
        return (int) $a['_belt_order'] < (int) $b['_belt_order'] ? -1 : 1;
    }
    if ((int) $a['current_grade_level'] !== (int) $b['current_grade_level']) {
        return (int) $a['current_grade_level'] > (int) $b['current_grade_level'] ? -1 : 1;
    }
    return strcmp((string) $a['student_name'], (string) $b['student_name']);
});
uksort($belt_need_counts, function ($a, $b) use ($belt_order_map) {
    $ai = isset($belt_order_map[$a]) ? (int) $belt_order_map[$a] : 999;
    $bi = isset($belt_order_map[$b]) ? (int) $belt_order_map[$b] : 999;
    if ($ai === $bi) {
        return strcmp($a, $b);
    }
    return $ai < $bi ? -1 : 1;
});
$print_counts = array('target' => count($rows), 'overdue' => 0, 'month' => 0, 'excluded' => 0);
$print_belt_need_counts = array();
foreach ($rows as $print_row) {
    $print_status = $print_row['_promotion_status'];
    if (empty($print_status['enabled'])) {
        $print_counts['excluded']++;
    }
    if (!empty($print_status['overdue'])) {
        $print_counts['overdue']++;
    } elseif (!empty($print_status['due_this_month'])) {
        $print_counts['month']++;
    }
    if (!empty($print_status['enabled']) && (!empty($print_status['due_this_month']) || !empty($print_status['overdue']))) {
        $print_need_belt = isset($print_status['next_rank']['belt']) ? $print_status['next_rank']['belt'] : '';
        if ($print_need_belt !== '') {
            if (!isset($print_belt_need_counts[$print_need_belt])) {
                $print_belt_need_counts[$print_need_belt] = 0;
            }
            $print_belt_need_counts[$print_need_belt]++;
        }
    }
}
uksort($print_belt_need_counts, function ($a, $b) use ($belt_order_map) {
    $ai = isset($belt_order_map[$a]) ? (int) $belt_order_map[$a] : 999;
    $bi = isset($belt_order_map[$b]) ? (int) $belt_order_map[$b] : 999;
    if ($ai === $bi) {
        return strcmp($a, $b);
    }
    return $ai < $bi ? -1 : 1;
});
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#0f172a;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:10mm;box-shadow:0 12px 36px rgba(15,23,42,.16)}.toolbar{width:210mm;margin:18px auto 0;display:flex;gap:8px;justify-content:flex-end}.btn{border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:9px 13px;font-weight:900;color:#0f172a;text-decoration:none;cursor:pointer}.primary{background:#1f63c5;border-color:#1f63c5;color:#fff}.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;border-bottom:3px solid #14213d;padding-bottom:10px}.brand{font-size:13px;color:#475569;font-weight:800}.title{margin:5px 0 0;font-size:26px;letter-spacing:-.01em}.month{font-size:18px;font-weight:1000;color:#1f63c5}.meta{margin-top:6px;color:#64748b}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin:12px 0}.summary-card{border:1px solid #dbe3ef;border-radius:10px;padding:8px 10px;background:#f8fafc}.summary-card span{display:block;color:#64748b;font-size:11px}.summary-card b{font-size:22px}.section-title{margin:14px 0 7px;font-size:16px}.belt-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}.belt{border:1px solid #f3c56f;background:#fff8e7;border-radius:9px;padding:8px 10px;display:flex;justify-content:space-between;font-weight:900}.belt b{color:#9a5b00}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #d7dee9;padding:6px 5px;text-align:center;font-size:11.5px;vertical-align:middle}th{background:#72829d;color:#fff;font-weight:900}.left{text-align:left}.check{width:24px}.name{width:104px}.grade{width:104px}.rank{width:104px}.date{width:74px}.state{width:66px}.memo{width:auto}.muted{color:#64748b}.class-row td{background:#14213d;color:#fff;text-align:left;font-weight:1000;font-size:13px;padding:7px 9px}.belt-row td{background:#eef6ff;color:#174ea6;text-align:left;font-weight:1000;padding:6px 9px}.state-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:4px 7px;background:#eef6ff;color:#1d4ed8;font-weight:900}.state-pill.overdue{background:#fff1f2;color:#be123c}.footer{margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px}.note-box{min-height:48px;border:1px solid #d7dee9;border-radius:10px;padding:10px;color:#64748b;font-size:12px}.sign{display:flex;gap:10px;justify-content:flex-end;align-items:center;color:#64748b}.sign span{display:inline-block;min-width:90px;border-bottom:1px solid #94a3b8;padding-bottom:6px;text-align:center;color:#0f172a}
@page{size:A4 portrait;margin:10mm}@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact}html,body{width:210mm;background:#fff}.toolbar{display:none}.page{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none}.summary-card,.belt,.note-box{break-inside:avoid}tr{break-inside:avoid}a{color:inherit;text-decoration:none}}
</style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_targets.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'status' => $status_filter, 'belt' => $belt_filter)); ?>">대상 화면으로</a>
    <button class="btn primary" type="button" onclick="window.print()">인쇄</button>
</div>
<main class="page">
    <header class="head">
        <div>
            <div class="brand">아이이음 승급 심사 준비</div>
            <h1 class="title">승급 대상 명단</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($month); ?></div>
        </div>
        <div class="month"><?php echo date('Y년 n월', strtotime($month . '-01')); ?></div>
    </header>

    <section class="summary">
        <div class="summary-card"><span>인쇄 대상</span><b><?php echo number_format((int) $print_counts['target']); ?>명</b></div>
        <div class="summary-card"><span>기간 지남</span><b><?php echo number_format((int) $print_counts['overdue']); ?>명</b></div>
        <div class="summary-card"><span>이번 달 대상</span><b><?php echo number_format((int) $print_counts['month']); ?>명</b></div>
        <div class="summary-card"><span>승급 제외</span><b><?php echo number_format((int) $print_counts['excluded']); ?>명</b></div>
    </section>

    <h2 class="section-title">준비할 띠 수량</h2>
    <section class="belt-grid">
        <?php if ($print_belt_need_counts) { ?>
            <?php foreach ($print_belt_need_counts as $belt_name => $belt_count) { ?>
            <div class="belt"><span><?php echo get_text($belt_name); ?></span><b><?php echo number_format((int) $belt_count); ?>개</b></div>
            <?php } ?>
        <?php } else { ?>
            <div class="belt"><span>준비할 띠</span><b>없음</b></div>
        <?php } ?>
    </section>

    <h2 class="section-title">심사 체크리스트</h2>
    <table>
        <thead>
        <tr>
            <th class="check">확인</th>
            <th class="name">학생</th>
            <th class="grade">학년/부</th>
            <th class="rank">현재</th>
            <th class="rank">승급 후</th>
            <th class="date">예정일</th>
            <th class="state">상태</th>
            <th class="memo">메모</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows) { ?>
        <tr><td colspan="8">조건에 맞는 승급 대상자가 없습니다.</td></tr>
        <?php } ?>
        <?php
        $last_class_label = null;
        $last_belt_label = null;
        foreach ($rows as $row) {
            $status = $row['_promotion_status'];
            $next_rank = isset($status['next_rank']) ? $status['next_rank'] : array('belt' => '', 'poom_dan' => 0, 'grade_level' => 0);
            $class_label = trim((isset($row['class_name']) ? $row['class_name'] : '') . ' ' . (isset($row['start_time']) ? $row['start_time'] : ''));
            $current_label = ($status['belt'] !== '' ? $status['belt'] . ' ' : '') . ieum_promotion_rank_label($status['poom_dan'], $status['grade_level']);
            $next_label = ($next_rank['belt'] !== '' ? $next_rank['belt'] . ' ' : '') . ieum_promotion_rank_label($next_rank['poom_dan'], $next_rank['grade_level']);
            $state_label = ieum_promotion_print_status_label($status);
            $class_display = $class_label !== '' ? $class_label : '부 미지정';
            $belt_display = $status['belt'] !== '' ? $status['belt'] : '띠 미지정';
        ?>
        <?php if ($last_class_label !== $class_display) {
            $last_class_label = $class_display;
            $last_belt_label = null;
        ?>
        <tr class="class-row"><td colspan="8"><?php echo get_text($class_display); ?></td></tr>
        <?php } ?>
        <?php if ($last_belt_label !== $belt_display) {
            $last_belt_label = $belt_display;
        ?>
        <tr class="belt-row"><td colspan="8"><?php echo get_text($belt_display); ?></td></tr>
        <?php } ?>
        <tr>
            <td>□</td>
            <td class="left"><strong><?php echo get_text($row['student_name']); ?></strong><br><span class="muted"><?php echo get_text($row['student_code']); ?></span></td>
            <td><?php echo get_text(ieum_promotion_print_grade_label($row['grade_group'])); ?><br><span class="muted"><?php echo get_text($class_display); ?></span></td>
            <td><?php echo get_text($current_label); ?></td>
            <td><strong><?php echo get_text($next_label); ?></strong></td>
            <td><?php echo get_text($status['next_date'] !== '' ? $status['next_date'] : '-'); ?></td>
            <td><span class="state-pill <?php echo !empty($status['overdue']) ? 'overdue' : ''; ?>"><?php echo get_text($state_label); ?></span></td>
            <td></td>
        </tr>
        <?php } ?>
        </tbody>
    </table>

    <section class="footer">
        <div class="note-box">심사 메모</div>
        <div class="sign">담당 지도진 <span></span> 확인 <span></span></div>
    </section>
</main>
</body>
</html>
