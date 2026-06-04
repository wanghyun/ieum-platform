<?php
$sub_menu = '950149';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';
require_once IEUM_PATH . '/lib/promotion.php';

$g5['title'] = '아이이음 승급 미션표';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
ieum_promotion_ensure_schema();

function ieum_promotion_mission_grade_label($value)
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
$print_mode = isset($_GET['print_mode']) ? preg_replace('/[^0-9a-z_]/i', '', $_GET['print_mode']) : 'detail';
if (!in_array($print_mode, array('detail', 'simple'), true)) {
    $print_mode = 'detail';
}
$mission_program = $program_code !== '' ? $program_code : 'taekwondo';
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

$groups = array();
$counts = array('target' => 0, 'overdue' => 0, 'month' => 0);
$result = sql_query("
    select s.*, c.class_name, c.start_time, c.sort_order
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      {$where}
  order by c.sort_order asc, c.start_time asc, s.current_grade_level desc, s.student_name asc, s.student_code asc
", false);
while ($student = sql_fetch_array($result)) {
    $status = ieum_promotion_status($academy, $student, $month);
    if (!empty($status['is_poomdan_exam'])) {
        continue;
    }
    $include = false;
    if ($status_filter === 'all') {
        $include = $status['enabled'];
    } elseif ($status_filter === 'overdue') {
        $include = $status['overdue'];
    } else {
        $include = $status['enabled'] && ($status['due_this_month'] || $status['overdue']);
    }
    if (!$include) {
        continue;
    }
    $student['_promotion_status'] = $status;
    $class_label = trim((isset($student['class_name']) ? $student['class_name'] : '') . ' ' . (isset($student['start_time']) ? $student['start_time'] : ''));
    if ($class_label === '') {
        $class_label = '부 미지정';
    }
    $belt_label = $status['belt'] !== '' ? $status['belt'] : '띠 미지정';
    if ($belt_filter !== '' && $belt_label !== $belt_filter) {
        continue;
    }
    $counts['target']++;
    if ($status['overdue']) {
        $counts['overdue']++;
    } else {
        $counts['month']++;
    }
    $group_key = $class_label . '|' . $belt_label . '|' . $status['poom_dan'];
    if (!isset($groups[$group_key])) {
        $belt_order = isset($belt_order_map[$belt_label]) ? (int) $belt_order_map[$belt_label] : 999;
        $groups[$group_key] = array(
            'class_label' => $class_label,
            'class_sort' => isset($student['sort_order']) ? (int) $student['sort_order'] : 999,
            'class_time' => isset($student['start_time']) ? (string) $student['start_time'] : '',
            'belt_label' => $belt_label,
            'belt_order' => $belt_order,
            'poom_dan' => (int) $status['poom_dan'],
            'rows' => array(),
            'missions' => ieum_promotion_missions_for_belt($academy_id, $mission_program, $belt_label, $status['poom_dan']),
        );
    }
    $groups[$group_key]['rows'][] = $student;
}
uasort($groups, function ($a, $b) {
    if ($a['class_sort'] !== $b['class_sort']) {
        return $a['class_sort'] < $b['class_sort'] ? -1 : 1;
    }
    if ($a['class_time'] !== $b['class_time']) {
        return strcmp($a['class_time'], $b['class_time']);
    }
    if ($a['belt_order'] !== $b['belt_order']) {
        return $a['belt_order'] < $b['belt_order'] ? -1 : 1;
    }
    if ($a['poom_dan'] !== $b['poom_dan']) {
        return $a['poom_dan'] < $b['poom_dan'] ? -1 : 1;
    }
    return strcmp($a['belt_label'], $b['belt_label']);
});
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#0f172a;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.toolbar{max-width:297mm;margin:14px auto 0;padding:0 8px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.btn{border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:9px 13px;font-weight:900;color:#0f172a;text-decoration:none;cursor:pointer}.primary{background:#1f63c5;border-color:#1f63c5;color:#fff}.page{width:297mm;min-height:210mm;margin:14px auto;background:#fff;padding:9mm;box-shadow:0 12px 36px rgba(15,23,42,.16)}.head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;border-bottom:3px solid #14213d;padding-bottom:10px;margin-bottom:12px}.brand{font-size:13px;color:#475569;font-weight:900}.title{margin:4px 0 0;font-size:26px}.meta{color:#64748b;margin-top:4px}.month{font-size:18px;font-weight:1000;color:#1f63c5}.summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}.chip{display:inline-flex;gap:6px;align-items:center;border:1px solid #d7dee9;border-radius:999px;background:#f8fafc;padding:6px 10px;font-size:12px;font-weight:900}.class-divider{break-after:avoid;margin:16px 0 8px;padding:9px 12px;border:1px solid #cbd5e1;border-left:6px solid #1f63c5;border-radius:10px;background:#f8fbff;font-size:18px;font-weight:1000;color:#0f172a}.class-divider:first-of-type{margin-top:6px}.group{break-inside:avoid;margin-top:10px}.group-title{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#15204a;color:#fff;border-radius:10px 10px 0 0;padding:8px 12px;font-weight:1000}.group-title small{font-weight:800;color:#d8e2ff}.mission-note{border:1px solid #d7dee9;border-top:0;background:#f8fbff;padding:7px 10px;font-size:10.5px;color:#475569}.mission-note b{color:#0f172a}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #d7dee9;text-align:center;vertical-align:middle;font-size:10.5px;padding:5px 4px;word-break:keep-all}th{background:#72829d;color:#fff;font-weight:900}.student{width:116px;text-align:left}.grade{width:86px}.rank{width:86px}.memo{width:100px}.mission-head{background:#eef2ff;color:#1e3a8a}.check-cell{height:28px}.empty{border:1px dashed #cbd5e1;border-radius:4px;display:inline-block;width:18px;height:18px}.simple-table th,.simple-table td{font-size:12px;padding:7px 6px}.footer{display:flex;justify-content:space-between;align-items:center;margin-top:10px;color:#64748b;font-size:11px}.sign span{display:inline-block;min-width:90px;border-bottom:1px solid #94a3b8;margin-left:10px;color:#0f172a;text-align:center;padding-bottom:5px}.no-data{padding:28px;text-align:center;border:1px solid #d7dee9;border-radius:12px;background:#fff}
@page{size:A4 landscape;margin:8mm}@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact}html,body{width:297mm;background:#fff}.toolbar{display:none}.page{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none;page-break-after:always}.page:last-child{page-break-after:auto}.group{break-inside:avoid}tr{break-inside:avoid}a{color:inherit;text-decoration:none}}
</style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_targets.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'status' => $status_filter, 'belt' => $belt_filter)); ?>">대상 화면으로</a>
    <a class="btn" href="<?php echo IEUM_URL; ?>/admin/promotion_missions.php?<?php echo http_build_query(array('program_code' => $mission_program)); ?>">미션 설정</a>
    <a class="btn <?php echo $print_mode === 'simple' ? 'primary' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/promotion_missions_print.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'status' => $status_filter, 'belt' => $belt_filter, 'print_mode' => 'simple')); ?>">간단형</a>
    <a class="btn <?php echo $print_mode === 'detail' ? 'primary' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/promotion_missions_print.php?<?php echo http_build_query(array('month' => $month, 'program_code' => $program_code, 'class_time_id' => $class_time_id, 'status' => $status_filter, 'belt' => $belt_filter, 'print_mode' => 'detail')); ?>">상세형</a>
    <button class="btn primary" type="button" onclick="window.print()">인쇄</button>
</div>

<main class="page">
    <header class="head">
        <div>
            <div class="brand">아이이음 승급심사 준비</div>
            <h1 class="title">부별 승급 미션표</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($month); ?></div>
            <div class="summary">
                <span class="chip">대상 <?php echo number_format((int) $counts['target']); ?>명</span>
                <span class="chip">기간 지남 <?php echo number_format((int) $counts['overdue']); ?>명</span>
                <span class="chip">이번 달 <?php echo number_format((int) $counts['month']); ?>명</span>
            </div>
        </div>
        <div class="month"><?php echo date('Y년 n월', strtotime($month . '-01')); ?></div>
    </header>

    <?php if (!$groups) { ?>
        <div class="no-data">조건에 맞는 승급 미션 대상자가 없습니다.</div>
    <?php } ?>

    <?php
    $last_class_label = null;
    foreach ($groups as $group) {
        if ($last_class_label !== $group['class_label']) {
            $last_class_label = $group['class_label'];
    ?>
    <div class="class-divider"><?php echo get_text($group['class_label']); ?></div>
    <?php
        }
        $missions = $group['missions'];
        $detail_items = array();
        $detail_groups = array();
        $detail_limit = 18;
        $category_caps = array('stance' => 7, 'kick' => 8, 'breaking' => 3);
        foreach ($missions as $mission) {
            $group_items = array();
            $category = isset($mission['category']) ? $mission['category'] : '';
            $cap = isset($category_caps[$category]) ? $category_caps[$category] : 6;
            foreach (array_slice($mission['items'], 0, $cap) as $item) {
                if (count($detail_items) >= $detail_limit) {
                    break;
                }
                $detail_items[] = array('label' => $mission['category_label'], 'item' => $item);
                $group_items[] = $item;
            }
            if ($group_items) {
                $detail_groups[] = array('label' => $mission['category_label'], 'items' => $group_items);
            }
        }
        if (!$detail_items) {
            $detail_items[] = array('label' => '미션', 'item' => '확인');
            $detail_groups[] = array('label' => '미션', 'items' => array('확인'));
        }
    ?>
    <section class="group">
        <div class="group-title">
            <span><?php echo get_text($group['belt_label']); ?> 승급 미션</span>
            <small><?php echo number_format(count($group['rows'])); ?>명</small>
        </div>
        <div class="mission-note">
            <?php foreach ($missions as $mission) { ?>
                <b><?php echo get_text($mission['category_label']); ?></b> <?php echo get_text(implode(', ', $mission['items'])); ?>　
            <?php } ?>
        </div>
        <?php if ($print_mode === 'simple') { ?>
        <table class="simple-table">
            <thead><tr><th class="student">학생</th><th class="grade">학년/번호</th><th class="rank">현재</th><?php foreach ($missions as $mission) { ?><th><?php echo get_text($mission['category_label']); ?></th><?php } ?><th class="memo">메모</th></tr></thead>
            <tbody>
            <?php foreach ($group['rows'] as $row) {
                $status = $row['_promotion_status'];
                $rank_label = ($status['belt'] !== '' ? $status['belt'] . ' ' : '') . ieum_promotion_rank_label($status['poom_dan'], $status['grade_level']);
            ?>
            <tr>
                <td class="student"><strong><?php echo get_text($row['student_name']); ?></strong></td>
                <td><?php echo get_text(ieum_promotion_mission_grade_label($row['grade_group'])); ?><br><?php echo get_text($row['student_code']); ?></td>
                <td><?php echo get_text($rank_label); ?></td>
                <?php foreach ($missions as $mission) { ?><td class="check-cell"><span class="empty"></span></td><?php } ?>
                <td></td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php } else { ?>
        <table>
            <thead>
            <tr>
                <th rowspan="2" class="student">학생</th>
                <th rowspan="2" class="grade">학년/번호</th>
                <th rowspan="2" class="rank">현재</th>
                <?php foreach ($detail_groups as $group_item) { ?><th class="mission-head" colspan="<?php echo count($group_item['items']); ?>"><?php echo get_text($group_item['label']); ?></th><?php } ?>
                <th rowspan="2" class="memo">메모</th>
            </tr>
            <tr>
                <?php foreach ($detail_items as $item) { ?><th><?php echo get_text($item['item']); ?></th><?php } ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($group['rows'] as $row) {
                $status = $row['_promotion_status'];
                $rank_label = ($status['belt'] !== '' ? $status['belt'] . ' ' : '') . ieum_promotion_rank_label($status['poom_dan'], $status['grade_level']);
            ?>
            <tr>
                <td class="student"><strong><?php echo get_text($row['student_name']); ?></strong></td>
                <td><?php echo get_text(ieum_promotion_mission_grade_label($row['grade_group'])); ?><br><?php echo get_text($row['student_code']); ?></td>
                <td><?php echo get_text($rank_label); ?></td>
                <?php foreach ($detail_items as $item) { ?><td class="check-cell"></td><?php } ?>
                <td></td>
            </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php } ?>
    </section>
    <?php } ?>
    <footer class="footer">
        <span>통과는 O, 보완은 △ 또는 메모로 표시합니다.</span>
        <span class="sign">지도진 <span></span> 확인 <span></span></span>
    </footer>
</main>
</body>
</html>
